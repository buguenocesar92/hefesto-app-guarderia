<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlaGuarderia;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un niño del centro.
 *
 * LO QUE NO ESTÁ ACÁ, Y POR QUÉ
 * ------------------------------
 * Las alergias, la alimentación y la medicación NO están en esta tabla. Están en
 * `fichas_medicas`, que es una tabla aparte con Row Level Security aparte.
 *
 * La razón es directa: esta tabla se lista en pantalla de niño, se consulta
 * desde el panel y la lee el rol administrativo. Si las alergias estuvieran acá,
 * la ficha médica dejaría de estar protegida el día que alguien escriba una
 * consulta a `ninos` para la pantalla, y la protección depende de que nadie
 * haga eso.
 *
 * En cambio `fichas_medicas` se abre por una ruta propia, con un middleware
 * propio que anota el acceso, y no aparece en ninguna lista de niños.
 *
 * Ver docs/03 y docs/07.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $nombres
 * @property string $apellidos
 * @property \Illuminate\Support\Carbon $fecha_nacimiento
 * @property string|null $sexo
 * @property string|null $rut
 * @property bool $activo
 * @property \Illuminate\Support\Carbon $creado_en
 */
class Nino extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlaGuarderia;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'ninos';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'nombres',
        'apellidos',
        'fecha_nacimiento',
        'sexo',
        'rut',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'activo' => 'boolean',
            'creado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Responsable, $this>
     */
    public function responsables(): BelongsToMany
    {
        return $this->belongsToMany(Responsable::class, 'responsable_nino', 'nino_id', 'responsable_id')
            ->withPivot('parentesco', 'es_responsable_legal')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Asistencia, $this>
     */
    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class, 'nino_id');
    }

    /**
     * @return HasMany<Retiro, $this>
     */
    public function retiros(): HasMany
    {
        return $this->hasMany(Retiro::class, 'nino_id');
    }

    /**
     * @return HasMany<AutorizacionRetiro, $this>
     */
    public function autorizaciones(): HasMany
    {
        return $this->hasMany(AutorizacionRetiro::class, 'nino_id');
    }

    /**
     * @return HasOne<FichaMedica, $this>
     */
    public function fichaMedica(): HasOne
    {
        return $this->hasOne(FichaMedica::class, 'nino_id');
    }

    /**
     * @return HasMany<Incidencia, $this>
     */
    public function incidencias(): HasMany
    {
        return $this->hasMany(Incidencia::class, 'nino_id');
    }

    /**
     * @return HasMany<Matricula, $this>
     */
    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class, 'nino_id');
    }

    public function nombreCompleto(): string
    {
        return trim($this->nombres . ' ' . $this->apellidos);
    }

    /**
     * La edad en años cumplidos, a la fecha de hoy.
     */
    public function edad(): int
    {
        return (int) $this->fecha_nacimiento->startOfDay()->diffInYears(now()->startOfDay());
    }

    /**
     * Si la ficha médica está al día.
     *
     * Lo usa el panel: un niño sin ficha es un niño al que no se le puede
     * servir el almuerzo con seguridad, y eso tiene que verse antes de las once
     * de la mañana, no en el momento de servirlo.
     */
    public function tieneFichaMedica(): bool
    {
        return $this->fichaMedica()->exists();
    }
}