<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RolGuarderia;
use App\Models\Guarderia;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fábrica de usuarios de la guardería.
 *
 * @extends Factory<Usuario>
 */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = $this->faker->firstName();
        $apellido = $this->faker->lastName();

        return [
            'tenant_id' => Guarderia::factory(),
            'sub' => (string) Str::uuid(),
            'correo' => Str::slug($nombre . '.' . $apellido, '.') . '@ejemplo.local',
            'nombres' => $nombre,
            'apellidos' => $apellido,
            'rol' => $this->faker->randomElement([
                RolGuarderia::Educadora->value,
                RolGuarderia::Administrativo->value,
                RolGuarderia::Direccion->value,
            ]),
            'activo' => true,
            'ultimo_ingreso_en' => now(),
            'rol_actualizado_en' => now(),
        ];
    }

    public function educadora(): self
    {
        return $this->state(fn (): array => ['rol' => RolGuarderia::Educadora]);
    }

    public function administrativo(): self
    {
        return $this->state(fn (): array => ['rol' => RolGuarderia::Administrativo]);
    }

    public function direccion(): self
    {
        return $this->state(fn (): array => ['rol' => RolGuarderia::Direccion]);
    }

    public function desactivado(): self
    {
        return $this->state(fn (): array => ['activo' => false]);
    }

    /**
     * En una guardería concreta, que es lo que hace una instalación con varios
     * centros.
     */
    public function de(Guarderia $guarderia): self
    {
        return $this->state(fn (): array => ['tenant_id' => $guarderia->id]);
    }
}