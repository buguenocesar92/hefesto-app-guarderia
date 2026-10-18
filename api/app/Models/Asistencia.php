<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoAsistencia;
use App\Models\Concerns\PerteneceAlaGuarderia;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * La asistencia de un niño un día.
 *
 * UNA FILA POR DÍA, Y CON RESTRICCIÓN ÚNICA
 * ----------------------------------------
 * `unico_nino_fecha` impide que un niño tenga dos asistencias el mismo día. Sin
 * esa restricción, un doble clic en "registrar ingreso" crea dos filas, y la
 * pantalla muestra que el niño entró dos veces.
 *
 * LA AUSENCIA EXIGE MOTIVO Y AVISO
 * -------------------------------
 * `estado = ausente` obliga a `motivo_ausencia` y a `avisado_por`. Esa es la
 * diferencia entre "no vino y avisaron" y "no vino y nadie avisó", y la segunda
 * es la que el sistema tiene que hacer visible.
 *
 * La restricción se escribe en SQL, no en un `if` de PHP: una validación de
 * aplicación se puede saltar con una consulta directa, y esta fila es un dato
 * que se factura y se consulta.
 *
 * Ver docs/02.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $nino_id
 * @property \Illuminate\Support\Carbon $fecha
 * @property \Illuminate\Support\Carbon|null $ingreso_en
 * @property \Illuminate\Support\Carbon|null $egreso_en
 * @property EstadoAsistencia $estado
 * @property string|null $motivo_ausencia
 * @property string|null $avisado_por
 * @property string|null $observacion
 * @property string $registrado_por
 * @property \Illuminate\Support\Carbon $creado_en
 */
class Asistencia extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlaGuarderia;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'asistencias';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'nino_id',
        'fecha',
        'ingreso_en',
        'egreso_en',
        'estado',
        'motivo_ausencia',
        'avisado_por',
        'observacion',
        'registrado_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'ingreso_en' => 'datetime',
            'egreso_en' => 'datetime',
            'estado' => EstadoAsistencia::class,
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
    public function avisador(): BelongsTo
    {
        return $this->belongsTo(Responsable::class, 'avisado_por');
    }

    /**
     * @return HasOne<Retiro, $this>
     */
    public function retiro(): HasOne
    {
        return $this->hasOne(Retiro::class, 'asistencia_id');
    }

    /**
     * Si a esta asistencia le falta el registro de retiro.
     *
     * Un niño retirado sin registro de retiro es una fila que alguien tendría
     * que reconstruir a mano, y eso es exactamente el escenario que el registro
     * de retiros viene a evitar.
     */
    public function sinRegistroDeRetiro(): bool
    {
        return $this->estado === EstadoAsistencia::Retirado && $this->retiro()->doesntExist();
    }
}