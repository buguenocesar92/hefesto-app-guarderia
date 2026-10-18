<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoAutorizacion;
use App\Models\Concerns\PerteneceAlaGuarderia;
use App\Services\Autorizaciones\EvaluadorDeAutorizacion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La autorización de un responsable para retirar a un niño.
 *
 * POR QUÉ ESTA TABLA EXISTE Y POR QUÉ NO ES UNA FECHA EN `RESPONSABLES`
 * ---------------------------------------------------------------------
 * Porque la autorización tiene fecha de vencimiento y esa fecha es lo que hace
 * que el sistema pueda decir "no".
 *
 * Con una autorización permanente, o con una autorización sin fecha, el sistema
 * no puede rechazar a nadie: una vez que la abuela quedó autorizada, queda
 * para siempre, y la abducted de una mañana de junio no tiene forma de ser
 * detectada por el software.
 *
 * Con fecha, hay un momento en que la autorización deja de servir, y ese
 * momento está escrito, se ve en pantalla grande y avisa ANTES de llegar.
 *
 * ANULAR NO ES ELIMINAR
 * ---------------------
 * ---------------------
 * Una autorización anulada conserva la fila, con `motivo_anulacion` y
 * `anulada_por`. Una autorización que se borró no se puede citar después de una
 * conversación con la familia, y este es un registro de responsabilidad.
 *
 * Ver docs/02, docs/03 y docs/07.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $nino_id
 * @property string $responsable_id
 * @property \Illuminate\Support\Carbon|null $vigente_desde
 * @property \Illuminate\Support\Carbon|null $vigente_hasta
 * @property string|null $documento_tipo
 * @property string|null $documento_numero
 * @property EstadoAutorizacion $estado
 * @property string|null $motivo_anulacion
 * @property string|null $anulada_por
 * @property \Illuminate\Support\Carbon|null $anulada_en
 * @property string $registrada_por
 * @property \Illuminate\Support\Carbon $creado_en
 */
class AutorizacionRetiro extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlaGuarderia;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'autorizaciones_retiros';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'nino_id',
        'responsable_id',
        'vigente_desde',
        'vigente_hasta',
        'documento_tipo',
        'documento_numero',
        'estado',
        'motivo_anulacion',
        'anulada_por',
        'anulada_en',
        'registrada_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'estado' => EstadoAutorizacion::class,
            'anulada_en' => 'datetime',
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
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Responsable::class, 'responsable_id');
    }

    /**
     * @return HasMany<Retiro, $this>
     */
    public function retiros(): HasMany
    {
        return $this->hasMany(Retiro::class, 'autorizacion_retiro_id');
    }

    /**
     * El estado efectivo HOY, que combina el guardado y la fecha.
     */
    public function estadoEfectivo(): EstadoAutorizacion
    {
        return app(EvaluadorDeAutorizacion::class)->estadoEn($this);
    }

    /**
     * Si con esta autorización se puede retirar al niño hoy.
     */
    public function vigente(): bool
    {
        return app(EvaluadorDeAutorizacion::class)->permiteRetiro($this);
    }

    /**
     * Si esta autorización entra en la ventana de aviso de vencimiento.
     *
     * Es lo que hace que el sistema avise ANTES de que expire, y no cuando ya
     * pasó. Ver `config/guarderia.php`.
     */
    public function porVencer(): bool
    {
        return app(EvaluadorDeAutorizacion::class)->entraEnVentanaDeAviso($this);
    }

    /**
     * Los días que faltan para que venza, en negativo si ya venció.
     */
    public function diasParaVencer(): ?int
    {
        return app(EvaluadorDeAutorizacion::class)->diasParaVencer($this);
    }

    /**
     * Si esta autorización nunca venció.
     *
     * `vigente_hasta` es NULL cuando el adulto responsable decidió que esa
     * autorización no tiene fecha de término. Ese caso existe y no es un
     * olvido: es una decisión del centro, y por eso el enum no lo llama
     * "indefinida" a secas.
     */
    public function sinFechaDeVencimiento(): bool
    {
        return $this->vigente_hasta === null;
    }
}