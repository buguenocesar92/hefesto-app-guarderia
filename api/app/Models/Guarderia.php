<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlaGuarderia;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La guardería: el inquilino de esta instalación multi-tenant.
 *
 * POR QUÉ ESTA TABLA NO LLEVA ROW LEVEL SECURITY
 * ---------------------------------------------
 * Porque se consulta ANTES del ingreso, cuando todavía no hay sesión y por lo
 * tanto no hay `app.guarderia_id` que poner. Es la excepción consciente del
 * patrón, y no un descuido.
 *
 * Para que esa excepción no se convierta en una fuga, antes del ingreso la
 * aplicación NO lee esta tabla cruda: lee `vw_guarderias_publicas`, que expone
 * TRES columnas (`id`, `nombre`, `slug`) y solo de las guarderías activas. No
 * expone RUT, dirección, correo ni teléfono.
 *
 * Ver docs/03 y `database/migrations/*_crear_vista_guarderias_publicas.php`.
 *
 * @property string $id
 * @property string $nombre
 * @property string $slug
 * @property string|null $razon_social
 * @property string|null $comuna
 * @property string|null $direccion
 * @property string|null $telefono
 * @property string|null $correo
 * @property bool $activo
 * @property \Illuminate\Support\Carbon $creado_en
 */
class Guarderia extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlaGuarderia;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'guarderias';

    /** @var list<string> */
    protected $fillable = [
        'nombre',
        'slug',
        'razon_social',
        'comuna',
        'direccion',
        'telefono',
        'correo',
        'activo',
    ];

    /** @var list<string> */
    protected $hidden = [
        'razon_social',
        'direccion',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'creado_en' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Nino, $this>
     */
    public function ninos(): HasMany
    {
        return $this->hasMany(Nino::class, 'tenant_id');
    }

    /**
     * @return HasMany<Usuario, $this>
     */
    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'tenant_id');
    }

    /**
     * @return HasMany<Plan, $this>
     */
    public function planes(): HasMany
    {
        return $this->hasMany(Plan::class, 'tenant_id');
    }
}