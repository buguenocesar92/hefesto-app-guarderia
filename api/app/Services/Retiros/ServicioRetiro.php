<?php

declare(strict_types=1);

namespace App\Services\Retiros;

use App\Enums\EstadoAsistencia;
use App\Enums\EstadoAutorizacion;
use App\Exceptions\DomainException;
use App\Models\Asistencia;
use App\Models\AutorizacionRetiro;
use App\Models\Nino;
use App\Models\Responsable;
use App\Models\Retiro;
use App\Models\Usuario;
use App\Services\Autorizaciones\EvaluadorDeAutorizacion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * El retiro de un niño. La función más importante de esta aplicación.
 *
 * LA REGLA QUE RESUELVE
 * ---------------------
 * Solo un responsable AUTORIZADO puede retirar a un niño. Nunca "un adulto que
 * viene a buscarlo", nunca "alguien que conoce al niño".
 *
 * Y el registro se escribe SIEMPRE, también cuando el retiro se rechaza.
 *
 * POR QUÉ SE REGISTRA EL RECHAZO
 * -------------------------------
 * Porque el caso raro es el que más importa. Si alguien llega a la puerta y no
 * está autorizado, dentro de tres meses nadie va a acordarse de eso. El registro
 * es lo que permite reconstruir qué pasó: quién llegó, a qué hora, con qué
 * documento y qué se le dijo.
 *
 * Por eso el rechazo NO es un error que se devuelve y se descarta. Se escribe
 * una fila en `retiros` con `resultado = rechazado` y un motivo, y DESPUÉS se
 * lanza la excepción que le va a llegar a la pantalla.
 *
 * POR QUÉ EL RECHAZO NO COMPARTE TRANSACCIÓN
 * -----------------------------------------
 * Si el rechazo se escribiera dentro de la transacción que después se revierte,
 * el registro del rechazo desaparecería junto con el error. Por eso
 * `escribirRechazo()` abre su propia transacción.
 */
final class ServicioRetiro
{
    public function __construct(private readonly EvaluadorDeAutorizacion $evaluador) {}

    /**
     * Registra un retiro autorizado y cierra la asistencia del día.
     *
     * @throws DomainException
     */
    public function registrar(
        Nino $nino,
        Responsable $responsable,
        Usuario $operador,
        ?CarbonImmutable $ocurridoEn = null,
        ?int $autorizacionId = null,
        ?string $observacion = null,
    ): Retiro {
        $ocurridoEn ??= CarbonImmutable::now();

        // La evaluación va PRIMERO y por separado del guardado. Si no puede
        // procederse, `evaluar()` escribe el registro del rechazo y lanza la
        // excepción; nunca se llega a la transacción de escritura del retiro.
        $this->evaluar($nino, $responsable, $operador, $ocurridoEn);

        $autorizacion = $autorizacionId !== null
            ? AutorizacionRetiro::query()->findOrFail($autorizacionId)
            : $this->evaluador->autorizacionDeHoy($nino, $responsable, $ocurridoEn);

        // `evaluar()` ya comprobó que hay una autorización vigente, así que acá
        // nunca debería ser nulo. Si lo fuera, es que venció ENTRE la evaluación
        // y el guardado: se registra el rechazo y se corta.
        if ($autorizacion === null || ! $this->evaluador->permiteRetiro($autorizacion, $ocurridoEn)) {
            $this->escribirRechazo(
                $nino,
                $responsable,
                $operador,
                $ocurridoEn,
                'La autorización dejó de servir durante el registro del retiro.',
            );

            throw DomainException::retiroSinAutorizacion($responsable->nombreCompleto(), $nino->nombreCompleto());
        }

        $asistencia = Asistencia::query()
            ->where('nino_id', $nino->id)
            ->whereDate('fecha', $ocurridoEn->toDateString())
            ->first();

        if ($asistencia === null) {
            $this->escribirRechazo(
                $nino,
                $responsable,
                $operador,
                $ocurridoEn,
                'El niño no tiene ingreso registrado para esa fecha.',
            );

            throw DomainException::retiroDeNinoNoPresente($nino->nombreCompleto());
        }

        if ($asistencia->estado === EstadoAsistencia::Retirado) {
            $this->escribirRechazo(
                $nino,
                $responsable,
                $operador,
                $ocurridoEn,
                'El niño ya tenía un retiro registrado para esa fecha.',
            );

            throw DomainException::retiroDuplicado($nino->nombreCompleto());
        }

        return DB::transaction(function () use ($nino, $responsable, $operador, $ocurridoEn, $autorizacion, $asistencia, $observacion): Retiro {
            $retiro = Retiro::create([
                'nino_id' => $nino->id,
                'responsable_id' => $responsable->id,
                'autorizacion_retiro_id' => $autorizacion->id,
                'asistencia_id' => $asistencia->id,
                'ocurrido_en' => $ocurridoEn,
                'documento_tipo' => $responsable->documento_tipo->value,
                'documento_numero' => $responsable->documento_numero,
                'resultado' => 'autorizado',
                'motivo_rechazo' => null,
                'observacion' => $observacion,
                'registrado_por' => $operador->id,
            ]);

            $asistencia->estado = EstadoAsistencia::Retirado;
            $asistencia->egreso_en = $ocurridoEn;
            $asistencia->save();

            return $retiro;
        }, 3);
    }

    /**
     * Evalúa si el retiro puede procederse.
     *
     * Si no puede, escribe el registro del rechazo y lanza la excepción. Nunca
     * devuelve un valor: o pasa, o no pasa.
     *
     * @throws DomainException
     */
    public function evaluar(
        Nino $nino,
        Responsable $responsable,
        ?Usuario $operador,
        CarbonImmutable $ocurridoEn,
    ): AutorizacionRetiro {
        try {
            $autorizacion = $this->evaluador->autorizacionDeHoy($nino, $responsable, $ocurridoEn);
        } catch (Throwable $error) {
            // Si la consulta de autorización falla, no se puede saber si la
            // persona está autorizada. En ese caso NO se deja pasar: un fallo de
            // base de datos no es una autorización.
            $this->escribirRechazo(
                $nino,
                $responsable,
                $operador,
                $ocurridoEn,
                'No se pudo verificar la autorización por un error del sistema. Se registró el intento y no se autorizó el retiro.',
            );

            report($error);

            throw DomainException::retiroSinAutorizacion($responsable->nombreCompleto(), $nino->nombreCompleto());
        }

        if ($autorizacion === null) {
            $this->escribirRechazo(
                $nino,
                $responsable,
                $operador,
                $ocurridoEn,
                'No existe ninguna autorización registrada a nombre de este responsable para este niño.',
            );

            throw DomainException::retiroSinAutorizacion($responsable->nombreCompleto(), $nino->nombreCompleto());
        }

        $estado = $this->evaluador->estadoEn($autorizacion, $ocurridoEn);

        if ($estado->permiteRetiro()) {
            return $autorizacion;
        }

        $motivo = match ($estado) {
            EstadoAutorizacion::Vencida => sprintf(
                'La autorización venció el %s.',
                $autorizacion->vigente_hasta?->format('d/m/Y') ?? 'fecha no informada',
            ),
            EstadoAutorizacion::Anulada => sprintf(
                'La autorización fue anulada: %s',
                $autorizacion->motivo_anulacion ?? 'sin motivo registrado',
            ),
            default => 'La autorización no está vigente.',
        };

        $this->escribirRechazo($nino, $responsable, $operador, $ocurridoEn, $motivo);

        throw match ($estado) {
            EstadoAutorizacion::Vencida => DomainException::retiroConAutorizacionVencida(
                $responsable->nombreCompleto(),
                $autorizacion->vigente_hasta?->format('d/m/Y') ?? 'fecha no informada',
            ),
            EstadoAutorizacion::Anulada => DomainException::retiroConAutorizacionAnulada(
                $responsable->nombreCompleto(),
                $autorizacion->motivo_anulacion ?? 'sin motivo registrado',
            ),
            default => DomainException::retiroSinAutorizacion($responsable->nombreCompleto(), $nino->nombreCompleto()),
        };
    }

    /**
     * Escribe el registro de un intento de retiro rechazado.
     *
     * Va en su propia transacción y no propaga errores: un registro fallido no
     * debe tapar el motivo real del rechazo, que es lo que la pantalla necesita
     * mostrar.
     */
    private function escribirRechazo(
        Nino $nino,
        Responsable $responsable,
        ?Usuario $operador,
        CarbonImmutable $ocurridoEn,
        string $motivo,
    ): void {
        try {
            DB::transaction(function () use ($nino, $responsable, $operador, $ocurridoEn, $motivo): void {
                Retiro::create([
                    'nino_id' => $nino->id,
                    'responsable_id' => $responsable->id,
                    'autorizacion_retiro_id' => null,
                    'asistencia_id' => null,
                    'ocurrido_en' => $ocurridoEn,
                    'documento_tipo' => $responsable->documento_tipo->value,
                    'documento_numero' => $responsable->documento_numero,
                    'resultado' => 'rechazado',
                    'motivo_rechazo' => $motivo,
                    'observacion' => null,
                    'registrado_por' => $operador?->id,
                ]);
            });
        } catch (Throwable $error) {
            // Se silencia a propósito: lo que se muestra en pantalla sigue
            // siendo el motivo real, y el problema de escritura queda en el log
            // del servidor.
            report($error);
        }
    }
}