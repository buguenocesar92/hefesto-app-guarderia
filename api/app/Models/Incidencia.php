<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GravedadIncidencia;
use App\Models\Concerns\PerteneceAlaGuarderia;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una incidencia: qué pasó, cuándo y qué se hizo.
 *
 * POR QUÉ ESTE REGISTRO EXISTE
 * -----------------------------
 * Porque es lo que sostiene una conversación difícil con la familia tres meses
 * después. Sin registro, la conversación es "me parece que en noviembre pasó
 * algo", y con registro es "el 14 de noviembre, a las once, una educadora
 * registró esto y se hizo aquello".
 *
 * LAS DOS PARTES OBLIGATORIAS
 * ---------------------------
 * `descripcion` y `accion_tomada` son obligatorias y tienen largo mínimo. Una
 * incidencia sin acción tomada deja registro del problema y no de la
 * respuesta, y una conversación con la familia sin la respuesta es una
 * conversación mala.
 *
 * NO SE BORRAN
 * ------------
 * En esta versión no hay ruta de borrado de incidencias, y no es una omisión
 * que se complete más adelante. Ver docs/07.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $nino_id
 * @property string|null $categoria
 * @property GravedadIncidencia $gravedad
 * @property \Illuminate\Support\Carbon $ocurrido_en
 * @property string $descripcion
 * @property string $accion_tomada
 * @property string|null $informada_a
 * @property bool $requiere_seguimiento
 * @property string $registrada_por
 * @property \Illuminate\Support\Carbon $creado_en
 */
class Incidencia extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlaGuarderia;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'incidencias';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'nino_id',
        'categoria',
        'gravedad',
        'ocurrido_en',
        'descripcion',
        'accion_tomada',
        'informada_a',
        'requiere_seguimiento',
        'registrada_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ocurrido_en' => 'datetime',
            'gravedad' => GravedadIncidencia::class,
            'requiere_seguimiento' => 'boolean',
            'creado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Nino, $this>
     */
    public function nino(): BelongsTo
    {
        return $this->belongsTo(Nino::class, 'nino_id');
    }

    /**
     * @return BelongsTo<Responsable, $this>
     */
    public function informadoA(): BelongsTo
    {
        return $this->belongsTo(Responsable::class, 'informada_a');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function autora(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrada_por');
    }
}