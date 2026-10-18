<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoDocumento;
use App\Models\Concerns\PerteneceAlaGuarderia;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un adulto responsable de uno o más niños.
 *
 * LA RELACIÓN CON EL NIÑO ES MUCHOS A MUCHOS, A PROPÓSITO
 * -----------------------------------------------------
 * Porque una misma persona es responsable de varios niños —hermanos, sobrinos,
 * primos— y cada uno tiene sus propias autorizaciones, con sus propios plazos.
 *
 * Eso NO se puede modelar con un campo `responsable_id` en `ninos`. Con ese
 * campo, la hermana wouldn't pueda estar autorizada para uno y no para el otro,
 * y eso es exactamente el caso que el registro de retiro tiene que distinguir.
 *
 * `es_responsable_legal` sí es un campo: es un hecho del vínculo familiar, no
 * del permiso. La organización legal del menor y su dirección no cambian según
 * el niño. Lo que cambia es la autorización de retiro, y esa vive en
 * `autorizaciones_retiros`, con su fecha.
 *
 * Ver docs/02.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $nombres
 * @property string $apellidos
 * @property TipoDocumento $documento_tipo
 * @property string|null $documento_numero
 * @property string|null $telefono
 * @property string|null $correo
 * @property string|null $direccion
 * @property bool $activo
 * @property \Illuminate\Support\Carbon $creado_en
 */
class Responsable extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlaGuarderia;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'responsables';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'nombres',
        'apellidos',
        'documento_tipo',
        'documento_numero',
        'telefono',
        'correo',
        'direccion',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'documento_tipo' => TipoDocumento::class,
            'activo' => 'boolean',
            'creado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Nino, $this>
     */
    public function ninos(): BelongsToMany
    {
        return $this->belongsToMany(Nino::class, 'responsable_nino', 'responsable_id', 'nino_id')
            ->withPivot('parentesco', 'es_responsable_legal')
            ->withTimestamps();
    }

    /**
     * @return HasMany<AutorizacionRetiro, $this>
     */
    public function autorizaciones(): HasMany
    {
        return $this->hasMany(AutorizacionRetiro::class, 'responsable_id');
    }

    /**
     * @return HasMany<Retiro, $this>
     */
    public function retiros(): HasMany
    {
        return $this->hasMany(Retiro::class, 'responsable_id');
    }

    public function nombreCompleto(): string
    {
        return trim($this->nombres . ' ' . $this->apellidos);
    }

    /**
     * El documento con el que se identifica en la puerta.
     *
     * Se muestra en la pantalla de retiro al lado de la hora: si alguien llega
     * sin documento, la pantalla tiene que decirlo, porque es el caso en que la
     * identificación depende de otra cosa.
     */
    public function documentoLegible(): string
    {
        if ($this->documento_tipo === TipoDocumento::SinDocumento) {
            return 'Sin documento';
        }

        return trim($this->documento_tipo->etiqueta() . ': ' . ($this->documento_numero ?? 'sin número'));
    }
}