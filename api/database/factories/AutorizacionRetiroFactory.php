<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoAutorizacion;
use App\Enums\TipoDocumento;
use App\Models\AutorizacionRetiro;
use App\Models\Guarderia;
use App\Models\Nino;
use App\Models\Responsable;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fábrica de autorizaciones de retiro.
 *
 * LA VIGENCIA POR OMISIÓN SON 90 DÍAS
 * ------------------------------------
 * Es un valor de PRUEBA, no un valor del producto. Las autorizaciones reales las
 * crea la dirección del centro con la fecha que corresponda.
 *
 * Y no hay ningún plazo legal escrito en esta aplicación. Este 90 es un número
 * para que las pruebas tengan una fecha futura estable; no significa nada fuera
 * de las pruebas.
 *
 * @extends Factory<AutorizacionRetiro>
 */
class AutorizacionRetiroFactory extends Factory
{
    protected $model = AutorizacionRetiro::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Guarderia::factory(),
            'nino_id' => Nino::factory(),
            'responsable_id' => Responsable::factory(),
            'vigente_desde' => now()->subMonth()->toDateString(),
            'vigente_hasta' => now()->addDays(90)->toDateString(),
            'documento_tipo' => TipoDocumento::Rut,
            'documento_numero' => $this->faker->numerify('########-#'),
            'estado' => EstadoAutorizacion::Vigente,
            'motivo_anulacion' => null,
            'anulada_por' => null,
            'anulada_en' => null,
            'registrada_por' => Usuario::factory()->state(fn (): array => [
                'rol' => \App\Enums\RolGuarderia::Direccion->value,
            ]),
        ];
    }

    /**
     * Una autorización que ya venció hace un mes.
     *
     * Es el caso que la pantalla de retiro tiene que marcar EN GRANDE.
     */
    public function vencida(): self
    {
        return $this->state(fn (): array => [
            'vigente_desde' => now()->subMonths(7)->toDateString(),
            'vigente_hasta' => now()->subMonth()->toDateString(),
        ]);
    }

    /**
     * Una autorización que vence en pocos días: la ventana de aviso.
     */
    public function porVencer(): self
    {
        return $this->state(fn (): array => [
            'vigente_desde' => now()->subYear()->toDateString(),
            'vigente_hasta' => now()->addDays(5)->toDateString(),
        ]);
    }

    public function anulada(string $motivo = 'La familia lo pidió por teléfono.'): self
    {
        return $this->state(fn (): array => [
            'estado' => EstadoAutorizacion::Anulada,
            'motivo_anulacion' => $motivo,
            'anulada_en' => now(),
        ]);
    }

    /**
     * Una autorización sin fecha de vencimiento.
     *
     * Existe porque hay centros donde la autorización de un progenitor legal no
     * vence. Es una decisión del centro, y por eso la columna admite nulo.
     */
    public function sinVencimiento(): self
    {
        return $this->state(fn (): array => ['vigente_hasta' => null]);
    }
}