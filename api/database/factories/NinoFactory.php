<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Guarderia;
use App\Models\Nino;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fábrica de niños.
 *
 * @extends Factory<Nino>
 */
class NinoFactory extends Factory
{
    protected $model = Nino::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $apellido = $this->faker->lastName();

        return [
            'tenant_id' => Guarderia::factory(),
            'nombres' => $this->faker->firstName(),
            'apellidos' => $apellido,
            // Entre uno y seis años: el rango de un centro infantil. La fecha
            // exacta la carga el centro; acá sólo tiene que ser una fecha
            // pasada y creíble.
            'fecha_nacimiento' => now()->subYears($this->faker->numberBetween(1, 6))->subDays($this->faker->numberBetween(0, 364))->toDateString(),
            'sexo' => $this->faker->randomElement(['femenino', 'masculino']),
            'rut' => null,
            'activo' => true,
        ];
    }

    public function de(Guarderia $guarderia): self
    {
        return $this->state(fn (): array => ['tenant_id' => $guarderia->id]);
    }

    /**
     * Un niño SIN ficha médica, para probar el aviso del panel.
     */
    public function sinFichaMedica(): self
    {
        return $this->state(fn (): array => []);
    }
}