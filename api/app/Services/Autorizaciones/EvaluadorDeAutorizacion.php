<?php

declare(strict_types=1);

namespace App\Services\Autorizaciones;

use App\Enums\EstadoAutorizacion;
use App\Models\AutorizacionRetiro;
use App\Models\Nino;
use App\Models\Responsable;
use Carbon\CarbonImmutable;

/**
 * Dice si una autorización sirve HOY, y por qué.
 *
 * POR QUÉ ESTA CLASE EXISTE Y NO UN `if` EN EL CONTROLADOR
 * -------------------------------------------------------
 * Porque esta es la pregunta más importante que responde el sistema, y porque
 * tiene tres respuestas distintas que NO son la misma:
 *
 *   No hay autorización        El responsable no está autorizado para ese niño.
 *   Está vencida                Estuvo autorizado y el plazo pasó.
 *   Está anulada                Estuvo autorizado y alguien la dejó sin efecto.
 *
 * Si las tres se pueden resumir como "no autorizado", la pantalla no puede
 * explicar a la familia qué pasó. Y esa explicación, en la puerta, es el
 * problema entero.
 *
 * VERIFICACIÓN DE LA FECHA
 * -----------------------
 * Se comprueba contra la fecha del servidor, no contra la del navegador: una
 * pantalla con el reloj desfasado no puede decidir quién se lleva a un niño.
 *
 * El estado guardado en la fila se combina con la fecha, no se usa solo. Una
 * autorización marcada vigente con la fecha pasada está vencida, y el sistema
 * lo trata como vencida.
 */
final class EvaluadorDeAutorizacion
{
    /**
     * El estado efectivo de la autorización en la fecha de hoy.
     *
     * Devuelve el estado guardado, salvo que la fecha haya pasado: en ese caso
     * devuelve `Vencida`, y da igual lo que diga la fila.
     */
    public function estadoEn(AutorizacionRetiro $autorizacion, CarbonImmutable $hoy): EstadoAutorizacion
    {
        if ($autorizacion->estado === EstadoAutorizacion::Anulada) {
            return EstadoAutorizacion::Anulada;
        }

        if ($autorizacion->vigente_hasta !== null && $hoy->greaterThan($autorizacion->vigente_hasta->endOfDay())) {
            return EstadoAutorizacion::Vencida;
        }

        // Una autorización que todavía no empieza no sirve para el retiro de
        // hoy, pero NO está vencida: es un caso distinto y el mensaje lo dice.
        if ($autorizacion->vigente_desde !== null && $hoy->lessThan($autorizacion->vigente_desde->startOfDay())) {
            return EstadoAutorizacion::Vencida;
        }

        return $autorizacion->estado;
    }

    /**
     * Si la autorización sirve para retirar hoy.
     */
    public function permiteRetiro(AutorizacionRetiro $autorizacion, ?CarbonImmutable $hoy = null): bool
    {
        $hoy ??= CarbonImmutable::now();

        return $this->estadoEn($autorizacion, $hoy) === EstadoAutorizacion::Vigente;
    }

    /**
     * La autorización que autoriza a este responsable a retirar a este niño
     * hoy, si existe.
     *
     * Devuelve la autorización aunque esté vencida o anulada: el que llama
     * necesita mostrarla para poder explicar el rechazo. Si devolviera `null`
     * cuando no sirve, la pantalla no podría decir "estaba vencida desde el
     * 12 de marzo".
     *
     * Cuando hay más de una autorización —una vieja vencida y una nueva
     * vigente, que es lo normal al renovar— devuelve la que está vigente. La
     * nueva es la que manda.
     */
    public function autorizacionDeHoy(Nino $nino, Responsable $responsable, ?CarbonImmutable $hoy = null): ?AutorizacionRetiro
    {
        $hoy ??= CarbonImmutable::now();

        $autorizaciones = AutorizacionRetiro::query()
            ->where('nino_id', $nino->id)
            ->where('responsable_id', $responsable->id)
            ->orderByRaw('vigente_hasta DESC NULLS LAST')
            ->get();

        $vigente = null;

        foreach ($autorizaciones as $autorizacion) {
            $estado = $this->estadoEn($autorizacion, $hoy);

            if ($estado === EstadoAutorizacion::Vigente) {
                $vigente = $autorizacion;
            }
        }

        if ($vigente !== null) {
            return $vigente;
        }

        // Ninguna vigente: se devuelve la última conocida, que es la que explica
        // por qué se rechaza.
        return $autorizaciones->first();
    }

    /**
     * Los días que faltan para que venza. Negativo si ya venció.
     */
    public function diasParaVencer(AutorizacionRetiro $autorizacion, ?CarbonImmutable $hoy = null): ?int
    {
        $hoy ??= CarbonImmutable::now();

        if ($autorizacion->vigente_hasta === null) {
            return null;
        }

        return (int) $hoy->startOfDay()->diffInDays($autorizacion->vigente_hasta->startOfDay(), false);
    }

    /**
     * Si la autorización entra en la ventana de aviso.
     *
     * La ventana sale de `config('guarderia.autorizaciones.dias_aviso_vencimiento')`
     * y es una ayuda de operación, no un plazo legal. Ver docs/05.
     */
    public function entraEnVentanaDeAviso(AutorizacionRetiro $autorizacion, ?CarbonImmutable $hoy = null): bool
    {
        $dias = $this->diasParaVencer($autorizacion, $hoy);

        if ($dias === null || $dias < 0) {
            return false;
        }

        $margen = (int) config('guarderia.autorizaciones.dias_aviso_vencimiento', 15);

        return $dias <= $margen;
    }
}