<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPago;
use App\Models\Concerns\PerteneceAlaGuarderia;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La matrícula de un niño en un plan.
 *
 * EL SALDO NO ES UNA COLUMNA
 * -------------------------
 * No hay campo `saldo`. El saldo se calcula: precio del plan menos la suma de
 * los pagos no anulados.
 *
 * La razón es que un saldo guardado es un saldo que se desactualiza. Alguien
 * registra un pago, se le olvida actualizar el saldo, y el sistema muestra que
 * se debe una mensualidad que ya se pagó. Un saldo calculado no puede quedar
 * viejo.
 *
 * Ver `saldoEnPesos()` y docs/02.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $nino_id
 * @property string $plan_id
 * @property \Illuminate\Support\Carbon $inicia_en
 * @property \Illuminate\Support\Carbon|null $termina_en
 * @property bool $activa
 * @property string $registrada_por
 * @property \Illuminate\Support\Carbon $creado_en
 */
class Matricula extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlaGuarderia;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'matriculas';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'nino_id',
        'plan_id',
        'inicia_en',
        'termina_en',
        'activa',
        'registrada_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'inicia_en' => 'date',
            'termina_en' => 'date',
            'activa' => 'boolean',
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
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /**
     * @return HasMany<Pago, $this>
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'matricula_id');
    }

    /**
     * Los pagos que suman al saldo.
     *
     * Un pago anulado no suma, pero sigue ahí y se puede ver. Anular no es
     * borrar.
     */
    public function pagosRegistrados(): HasMany
    {
        return $this->pagos()->where('estado', EstadoPago::Registrado->value);
    }

    /**
     * La suma de los pagos válidos.
     */
    public function totalPagado(): int
    {
        return (int) $this->pagos()
            ->where('estado', EstadoPago::Registrado->value)
            ->sum('monto_clp');
    }

    /**
     * El saldo en pesos: precio del plan menos lo pagado.
     *
     * Positivo es lo que se debe. Negativo es dinero a favor de la familia, que
     * también es un caso real y no se recorta a cero.
     */
    public function saldoEnPesos(): int
    {
        $precio = $this->plan !== null ? $this->plan->precio_clp : 0;

        return $precio - $this->totalPagado();
    }

    /**
     * El saldo listo para mostrar, con el signo explícito.
     */
    public function saldoLegible(): string
    {
        $saldo = $this->saldoEnPesos();

        if ($saldo > 0) {
            return 'Debe $' . number_format($saldo, 0, ',', '.');
        }

        if ($saldo < 0) {
            return 'Saldo a favor $' . number_format(abs($saldo), 0, ',', '.');
        }

        return 'Al día';
    }
}