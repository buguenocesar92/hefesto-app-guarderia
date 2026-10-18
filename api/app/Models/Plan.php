<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlaGuarderia;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un plan de mensualidad que ofrece la guardería.
 *
 * LO QUE NO HAY ACÁ
 * -----------------
 * No hay tarifas por omisión. No hay "plan básico" de $180.000 ni ningún monto
 * que este software decida. El precio lo carga la guardería, porque es de ella y
 * cambia.
 *
 * Las mensualidades de esta instalación NO fueron verificadas contra ninguna
 * lista de precios: no se conoce el monto que cobra cada centro, y escribir uno
 * sería inventar. Ver docs/05.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $nombre
 * @property int $precio_clp
 * @property int|null $dias_cobro
 * @property string|null $descripcion
 * @property bool $activo
 * @property \Illuminate\Support\Carbon $creado_en
 */
class Plan extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlaGuarderia;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'planes';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'nombre',
        'precio_clp',
        'dias_cobro',
        'descripcion',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'precio_clp' => 'integer',
            'dias_cobro' => 'integer',
            'activo' => 'boolean',
            'creado_en' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Matricula, $this>
     */
    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class, 'plan_id');
    }
}