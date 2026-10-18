<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TipoDocumento;
use App\Models\Guarderia;
use App\Models\Responsable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fábrica de responsables.
 *
 * @extends Factory<Responsable>
 */
class ResponsableFactory extends Factory
{
    protected $model = Responsable::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = $this->faker->firstName();
        $apellido = $this->faker->lastName();

        return [
            'tenant_id' => Guarderia::factory(),
            'nombres' => $nombre,
            'apellidos' => $apellido,
            'documento_tipo' => TipoDocumento::Rut,
            'documento_numero' => $this->faker->numerify('########-#'),
            'telefono' => $this->faker->numerify('+569########'),
            'correo' => Str::slug($nombre . '.' . $apellido, '.') . '@ejemplo.local',
            'direccion' => null,
            'activo' => true,
        ];
    }

    public function de(Guarderia $guarderia): self
    {
        return $this->state(fn (): array => ['tenant_id' => $guarderia->id]);
    }

    /**
     * Un responsable SIN documento, que es un caso real en la puerta.
     */
    public function sinDocumento(): self
    {
        return $this->state(fn (): array => [
            'documento_tipo' => TipoDocumento::SinDocumento,
            'documento_numero' => null,
        ]);
    }
}
