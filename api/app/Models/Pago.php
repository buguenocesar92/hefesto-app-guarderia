<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPago;
use App\Models\Concerns\PerteneceAlaGuarderia;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un pago de mensualidad.
 *
 * UN PAGO ANULADO CONSERVA LA FILA
 * -------------------------------
 * Un pago no se borra: se anula, con motivo y con quién lo anuló. Borrar un pago
 * deja el mismo hueco que borrar una autorización, y el saldo de una familia
 * deja de poder reconstruirse.
 *
 * `monto_clp` es un entero de pesos. No hay columnas de centavo porque en esta
 * aplicación el monto se registra como lo declara la persona, y agregar
 * decimales sería inventar una precisión que nadie pidió.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $matricula_id
 * @property int $monto_clp
 * @property \Illuminate\Support\Carbon $pagado_en
 * @property string|null $metodo
 * @property string|null $comprobante
 * @property EstadoPago $estado
 * @property string|null $motivo_anulacion
 * @property string|null $anulado_por
 * @property \Illuminate\Support\Carbon|null $anulado_en
 * @property string $registrado_por
 * @property \Illuminate\Support\Carbon $creado_en
 */
class Pago extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlaGuarderia;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'pagos';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'matricula_id',
        'monto_clp',
        'pagado_en',
        'metodo',
        'comprobante',
        'estado',
        'motivo_anulacion',
        'anulado_por',
        'anulado_en',
        'registrado_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto_clp' => 'integer',
            'pagado_en' => 'date',
            'estado' => EstadoPago::class,
            'anulado_en' => 'datetime',
            'creado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Matricula, $this>
     */
    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class, 'matricula_id');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function anulador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'anulado_por');
    }
}