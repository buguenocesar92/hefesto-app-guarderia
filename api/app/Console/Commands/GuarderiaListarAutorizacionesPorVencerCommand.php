<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AutorizacionRetiro;
use App\Services\Tenancy\AplicaContextoTenant;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Console\Command;

/**
 * Las autorizaciones que están por vencer.
 *
 * PARA QUÉ ESTE COMANDO
 * --------------------
 * El sistema tiene que avisar ANTES de que una autorización expire, no cuando ya
 * pasó.
 *
 * "Avisa que venció" y "avisa que va a vencer" son cosas distintas. La primera es
 * el aviso de un rechazo que ya se produjo; la segunda es lo que permite renovar
 * a tiempo. Esta aplicación hace la segunda.
 *
 * POR QUÉ ITERA POR GUARDERÍA
 * ----------------------------
 * Porque el aviso es de cada centro, y el contexto de guardería hay que fijarlo
 * explícitamente: en consola no hay sesión de la que sacarlo.
 *
 * LO QUE NO HACE
 * --------------
 * No envía correo. La lista sale por pantalla y queda en el registro del
 * servidor. Escribir "notificación enviada" sin transporte sería afirmar una
 * capacidad que no existe. Ver `App\Jobs\AvisarAutorizacionesPorVencer`.
 *
 * Ver docs/04.
 */
class GuarderiaListarAutorizacionesPorVencerCommand extends Command
{
    protected $signature = 'guarderia:autorizaciones-por-vencer
                            {--dias= : Días de antelación. Por omisión, la ventana de configuración.}
                            {--guarderia= : Limitar a una guardería, por identificador.}';

    protected $description = 'Lista las autorizaciones de retiro que entran en la ventana de aviso de vencimiento.';

    public function handle(ContextoTenant $contexto, AplicaContextoTenant $aplicador): int
    {
        $dias = $this->option('dias') !== null
            ? (int) $this->option('dias')
            : (int) config('guarderia.autorizaciones.dias_aviso_vencimiento', 15);

        $this->line(sprintf('Buscando autorizaciones que vencen en los próximos %d días.', $dias));
        $this->newLine();

        $evaluador = app(\App\Services\Autorizaciones\EvaluadorDeAutorizacion::class);
        $total = 0;

        foreach (\App\Models\Guarderia::query()->where('activo', true)->get() as $guarderia) {
            if ($this->option('guarderia') !== null && $guarderia->id !== $this->option('guarderia')) {
                continue;
            }

            $encontradas = $aplicador->para($guarderia->id, function () use ($evaluador): array {
                return AutorizacionRetiro::query()
                    ->with(['nino', 'responsable'])
                    ->where('estado', 'vigente')
                    ->whereNotNull('vigente_hasta')
                    ->whereDate('vigente_hasta', '<=', now()->addDays($dias)->toDateString())
                    ->orderBy('vigente_hasta')
                    ->get()
                    ->filter(fn (AutorizacionRetiro $a): bool => $evaluador->entraEnVentanaDeAviso($a))
                    ->map(fn (AutorizacionRetiro $a): array => [
                        'guarderia' => $guarderia->nombre,
                        'nino' => $a->nino?->nombreCompleto() ?? 'Niño no identificado',
                        'responsable' => $a->responsable?->nombreCompleto() ?? 'Responsable no identificado',
                        'vigente_hasta' => $a->vigente_hasta?->format('d/m/Y'),
                        'dias' => $evaluador->diasParaVencer($a),
                    ])
                    ->values()
                    ->all();
            });

            if ($encontradas === []) {
                continue;
            }

            $total += count($encontradas);

            $this->info($guarderia->nombre);
            $this->table(
                ['Niño', 'Responsable', 'Vence', 'Días'],
                $encontradas,
            );
            $this->newLine();
        }

        if ($total === 0) {
            $this->info('No hay autorizaciones por vencer en esta ventana.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('Hay %d autorizaciones por vencer. Revise con la familia.', $total));
        $this->line('Esta versión no envía correo: la lista queda aquí y en el registro del servidor.');

        return self::SUCCESS;
    }
}