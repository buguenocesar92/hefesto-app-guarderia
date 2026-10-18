<?php

declare(strict_types=1);

namespace Tests\Soporte;

use App\Enums\EstadoAsistencia;
use App\Enums\EstadoAutorizacion;
use App\Models\Asistencia;
use App\Models\AutorizacionRetiro;
use App\Models\Guarderia;
use App\Models\Nino;
use App\Models\Responsable;
use App\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Monta y desmonta escenas con el contexto de guardería puesto.
 *
 * POR QUÉ EXISTE
 * --------------
 * Todas las pruebas de esta aplicación necesitan lo mismo: abrir una
 * transacción con `app.guarderia_id` fijado, porque las tablas tienen Row Level
 * Security y sin contexto no se puede escribir ni leer nada.
 *
 * Y hay una trampa que esta clase evita: el contexto tiene ALCANCE DE
 * TRANSACCIÓN. Si una prueba abre la transacción y escribe fuera, el primer
 * `assert` ve cero filas y la falla dice algo que no es lo que pasó.
 */
trait PreparaEscena
{
    /**
     * Corre algo con el contexto de la guardería fijado.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $operacion
     * @return TResult
     */
    protected function enGuarderia(Guarderia $guarderia, callable $operacion): mixed
    {
        return DB::transaction(function () use ($guarderia, $operacion): mixed {
            DB::select('SELECT set_config(\'app.guarderia_id\', ?, true)', [$guarderia->id]);

            return $operacion();
        });
    }

    /**
     * Una autorización vigente, creada dentro del contexto de la guardería.
     *
     * LA VIGENCIA ES UN VALOR DE PRUEBA
     * ---------------------------------
     * Treinta días desde hoy. No es un plazo del producto ni legal: es una fecha
     * futura estable para que la prueba no dependa del día en que se corra.
     */
    protected function crearAutorizacionVigiente(
        Guarderia $guarderia,
        Nino $nino,
        Responsable $responsable,
        Usuario $registradaPor,
        ?CarbonImmutable $vigenteHasta = null,
    ): AutorizacionRetiro {
        return $this->enGuarderia($guarderia, function () use ($nino, $responsable, $registradaPor, $vigenteHasta): AutorizacionRetiro {
            return AutorizacionRetiro::create([
                'tenant_id' => $nino->tenant_id,
                'nino_id' => $nino->id,
                'responsable_id' => $responsable->id,
                'vigente_desde' => CarbonImmutable::now()->subDay()->toDateString(),
                'vigente_hasta' => ($vigenteHasta ?? CarbonImmutable::now()->addDays(30))->toDateString(),
                'documento_tipo' => $responsable->documento_tipo->value,
                'documento_numero' => $responsable->documento_numero,
                'estado' => EstadoAutorizacion::Vigente,
                'registrada_por' => $registradaPor->id,
            ]);
        });
    }

    /**
     * Una autorización que ya venció, con la fecha en el pasado.
     */
    protected function crearAutorizacionVencida(
        Guarderia $guarderia,
        Nino $nino,
        Responsable $responsable,
        Usuario $registradaPor,
        ?CarbonImmutable $vencioEl = null,
    ): AutorizacionRetiro {
        return $this->enGuarderia($guarderia, function () use ($nino, $responsable, $registradaPor, $vencioEl): AutorizacionRetiro {
            return AutorizacionRetiro::create([
                'tenant_id' => $nino->tenant_id,
                'nino_id' => $nino->id,
                'responsable_id' => $responsable->id,
                'vigente_desde' => CarbonImmutable::now()->subYear()->toDateString(),
                'vigente_hasta' => ($vencioEl ?? CarbonImmutable::now()->subMonth())->toDateString(),
                'documento_tipo' => $responsable->documento_tipo->value,
                'documento_numero' => $responsable->documento_numero,
                'estado' => EstadoAutorizacion::Vigente,
                'registrada_por' => $registradaPor->id,
            ]);
        });
    }

    /**
     * Registra el ingreso de un niño para HOY.
     */
    protected function darIngreso(Guarderia $guarderia, Nino $nino, Usuario $operador): Asistencia
    {
        return $this->enGuarderia($guarderia, function () use ($nino, $operador): Asistencia {
            return Asistencia::create([
                'tenant_id' => $nino->tenant_id,
                'nino_id' => $nino->id,
                'fecha' => CarbonImmutable::now()->toDateString(),
                'ingreso_en' => now(),
                'estado' => EstadoAsistencia::Presente,
                'registrado_por' => $operador->id,
            ]);
        });
    }

    /**
     * Todos los retiros de un niño, dentro del contexto.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Retiro>
     */
    protected function retirosDe(Guarderia $guarderia, Nino $nino): \Illuminate\Support\Collection
    {
        return $this->enGuarderia($guarderia, fn (): \Illuminate\Support\Collection => \App\Models\Retiro::query()
            ->where('nino_id', $nino->id)
            ->orderBy('ocurrido_en')
            ->get());
    }
}