<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RolGuarderia;
use App\Models\Concerns\PerteneceAlaGuarderia;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

/**
 * Una persona que trabaja en la guardería.
 *
 * IMPLEMENTA `Authenticatable`
 * ---------------------------
 * Para que Laravel la pueda usar en `actingAs()` y para que `Gate::forUser()`
 * la reciba. El modelo de autenticación es ESTE, no `users`: la tabla se llama
 * `usuarios` y una tabla `users` en un sistema que maneja menores sería una
 * fuente de confusión innecesaria. Ver `config/auth.php`.
 *
 * EL ROL SE LEE DEL USUARIO, NO DEL GRUPO DE AUTHENTIK, EN CADA PETICIÓN
 * --------------------------------------------------------------------
 * `rol` es una columna de esta tabla, y NO se recalcula en cada ingreso. Si se
 * recalculara, dejar de estar en un equipo de Authentik desactivaría a la
 * persona, y con el tiempo nadie se acuerda de a qué equipos dejó a la gente.
 *
 * Por eso hay `rol_actualizado_en`: la columna dice cuándo se revisó el rol a
 * mano. Si la dirección de una guardería cambia un rol en Authentik y no
 * actualiza esta tabla, acá no se refleja; y el aviso de `rol_actualizado_en`
 * permite detectarlo. Es una decisión consciente, no un olvido.
 *
 * Ver docs/03 y docs/07.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $sub
 * @property string $correo
 * @property string $nombres
 * @property string $apellidos
 * @property RolGuarderia $rol
 * @property bool $activo
 * @property \Illuminate\Support\Carbon|null $ultimo_ingreso_en
 * @property \Illuminate\Support\Carbon $rol_actualizado_en
 * @property \Illuminate\Support\Carbon $creado_en
 */
class Usuario extends Model implements Authenticatable
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlaGuarderia;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'usuarios';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'sub',
        'correo',
        'nombres',
        'apellidos',
        'rol',
        'activo',
        'ultimo_ingreso_en',
        'rol_actualizado_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rol' => RolGuarderia::class,
            'activo' => 'boolean',
            'ultimo_ingreso_en' => 'datetime',
            'rol_actualizado_en' => 'datetime',
            'creado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Guarderia, $this>
     */
    public function guarderia(): BelongsTo
    {
        return $this->belongsTo(Guarderia::class, 'tenant_id');
    }

    /**
     * @return HasMany<Retiro, $this>
     */
    public function retirosRegistrados(): HasMany
    {
        return $this->hasMany(Retiro::class, 'registrado_por');
    }

    /**
     * @return HasMany<Acceso, $this>
     */
    public function accesos(): HasMany
    {
        return $this->hasMany(Acceso::class, 'usuario_id');
    }

    public function nombreCompleto(): string
    {
        return trim($this->nombres . ' ' . $this->apellidos);
    }

    /**
     * Por qué esta persona no puede ver algo, o `null` si sí puede.
     *
     * El motivo de la pantalla vacía con explicación está en
     * `RolGuarderia::accesoAFichaMedica()`.
     */
    public function motivoDeFaltaDeAcceso(string $capacidad): ?string
    {
        return match ($capacidad) {
            'ficha_medica' => $this->rol->abreFichasMedicas()
                ? null
                : $this->rol->accesoAFichaMedica(),
            'pagos' => $this->rol->vePagos()
                ? null
                : 'El rol ' . $this->rol->etiqueta() . ' no ve los pagos del centro. Esa es una tarea del personal administrativo.',
            'bitacora' => $this->rol === RolGuarderia::Direccion
                ? null
                : 'La bitácora de accesos es para la dirección del centro.',
            default => null,
        };
    }
}