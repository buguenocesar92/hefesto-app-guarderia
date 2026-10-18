<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Guarderia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fábrica de guarderías.
 *
 * @extends Factory<Guarderia>
 */
class GuarderiaFactory extends Factory
{
    protected $model = Guarderia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = $this->faker->company();

        return [
            'nombre' => $nombre,
            // El `slug` tiene que ser ÚNICO y en minúsculas con guiones: es lo
            // que la gente escribe en el selector de ingreso.
            'slug' => Str::slug($nombre) . '-' . Str::lower(Str::random(4)),
            'razon_social' => null,
            'comuna' => $this->faker->randomElement([
                'Providencia',
                'Las Condes',
                'Ñuñoa',
                'Maipú',
                'Viña del Mar',
                'Valparaíso',
            ]),
            'direccion' => $this->faker->streetAddress(),
            'telefono' => $this->faker->numerify('+562########'),
            'correo' => Str::slug($nombre) . '@ejemplo.local',
            'activo' => true,
        ];
    }

    /**
     * Una guardería inactiva.
     *
     * Una guardería inactiva NO aparece en `vw_guarderias_publicas`, y por eso
     * no aparece en el selector de ingreso. Es una de las cosas que la vista
     * pública protege además de esconder columnas.
     */
    public function inactiva(): self
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}