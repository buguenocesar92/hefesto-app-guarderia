<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AutorizacionRetiro;
use App\Models\Guarderia;
use App\Services\Tenancy\AplicaContextoTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * El aviso de que una autorización está por vencer.
 *
 * EL AVISO OCURRE ANTES, NO DESPUÉS
 * ---------------------------------
 * "Avise antes de que expire" es la diferencia entre un sistema que sirve y uno
 * que avisa para justificar un rechazo. Esta aplicación hace lo primero.
 *
 * LO QUE ESTE JOB NO HACE: ENVIAR CORREO
 * ---------------------------------------
 * NO hay correo saliente implementado, y no hay una fila en la base de datos
 * que diga "este mensaje se envió". Escribir eso sería afirmar una capacidad
 * que no existe.
 *
 * Lo que sí hace, y es real: escribe en el registro del servidor, con la lista
 * completa de quién, qué niño y en cuántos días. El centro la ve en
 * `guarderia:autorizaciones-por-vencer` y en el panel.
 *
 * Para agregar el envío de correo hay que escribir el transporte, la plantilla
 * y la cola, y probarlo. Está anotado como pendiente en docs/10.
 *
 * Ver docs/03 y docs/07.
 */
class AvisarAutorizacionesPorVencer implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $guarderiaId) {}

    public function handle(AplicaContextoTenant $contexto): void
    {
        $contexto->para($this->guarderiaId, function (): void {
            $guarderia = Guarderia::find($this->guarderiaId);

            if ($guarderia === null) {
                return;
            }

            $dias = (int) config('guarderia.autorizaciones.dias_aviso_vencimiento', 15);
            $hoy = now();

            $porVencer = AutorizacionRetiro::query()
                ->with(['nino', 'responsable'])
                ->where('estado', 'vigente')
                ->whereNotNull('vigente_hasta')
                ->whereDate('vigente_hasta', '<=', $hoy->copy()->addDays($dias)->toDateString())
                ->get();

            $evaluador = app(\App\Services\Autorizaciones\EvaluadorDeAutorizacion::class);

            $avisables = $porVencer->filter(fn (AutorizacionRetiro $a): bool => $evaluador->entraEnVentanaDeAviso($a, $hoy));

            if ($avisables->isEmpty()) {
                Log::info('Revisión de autorizaciones: no hay ninguna por vencer.', [
                    'guarderia' => $guarderia->slug,
                ]);

                return;
            }

            // Sin correo saliente: se deja la lista completa en el registro, que
            // es lo que el centro puede leer hoy. Ver la nota de la clase.
            foreach ($avisables as $autorizacion) {
                Log::warning('Autorización de retiro por vencer.', [
                    'guarderia' => $guarderia->slug,
                    'nino' => $autorizacion->nino?->nombreCompleto(),
                    'responsable' => $autorizacion->responsable?->nombreCompleto(),
                    'vigente_hasta' => $autorizacion->vigente_hasta?->toDateString(),
                    'dias_para_vencer' => $evaluador->diasParaVencer($autorizacion, $hoy),
                ]);
            }

            Log::info('Revisión de autorizaciones completada.', [
                'guarderia' => $guarderia->slug,
                'por_vencer' => $avisables->count(),
                'correo_enviado' => false,
                'nota' => 'Esta version no envia correo. La lista queda en el registro del servidor.',
            ]);
        });
    }
}