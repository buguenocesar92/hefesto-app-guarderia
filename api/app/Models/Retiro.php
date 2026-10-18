<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlaGuarderia;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El registro de quién retiró a un niño, y a qué hora.
 *
 * LA FILA MÁS IMPORTANTE DE TODA ESTA APLICACIÓN
 * ---------------------------------------------
 * Un retiro de un menor es un hecho de responsabilidad. Cuando alguien llega
 * por un niño, quedan tres cosas que responder: quién fue, a qué hora, y con
 * qué autorización.
 *
 * ESA TABLA ESCRIBE LOS DOS RESULTADOS
 * ------------------------------------
 * `resultado` es `autorizado` o `rechazado`, y se escriben LOS DOS.
 *
 * El rechazo se registra porque el caso raro es el que más importa. Si alguien
 * llega a la puerta y no está autorizado, dentro de tres meses nadie se va a
 * acordar de eso. Lo que permite reconstruirlo es esta fila.
 *
 * El `documento_tipo` y el `documento_numero` se COPIAN a esta fila, aunque
 * estén en `responsables`. No es duplicación sin propósito: la ficha del
 * responsable puede cambiar, y el registro de lo que pasó a las cuatro de la
 * tarde de ese día no puede cambiar con ella. Un registro que cambia cuando
 * cambian los datos de la persona no sirve para reconstruir nada.
 *
 * `responsable_id` puede ser NULL cuando el responsable no existe en el
 * sistema —alguien que llega a la puerta y no tiene ficha—. En ese caso el
 * nombre y el documento quedan en `responsable_nombre_texto` y
 * `responsable_documento_texto`, que son de sólo lectura.
 *
 * Ver docs/02, docs/07 y `App\Services\Retiros\ServicioRetiro`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $nino_id
 * @property string|null $responsable_id
 * @property string|null $responsable_nombre_texto
 * @property string|null $responsable_documento_texto
 * @property string|null $autorizacion_retiro_id
 * @property string|null $asistencia_id
 * @property \Illuminate\Support\Carbon $ocurrido_en
 * @property string|null $documento_tipo
 * @property string|null $documento_numero
 * @property string $resultado
 * @property string|null $motivo_rechazo
 * @property string|null $observacion
 * @property string|null $registrado_por
 * @property \Illuminate\Support\Carbon $creado_en
 */
class Retiro extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlaGuarderia;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'retiros';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'nino_id',
        'responsable_id',
        'responsable_nombre_texto',
        'responsable_documento_texto',
        'autorizacion_retiro_id',
        'asistencia_id',
        'ocurrido_en',
        'documento_tipo',
        'documento_numero',
        'resultado',
        'motivo_rechazo',
        'observacion',
        'registrado_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ocurrido_en' => 'datetime',
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
     * @return BelongsTo<AutorizacionRetiro, $this>
     */
    public function autorizacion(): BelongsTo
    {
        return $this->belongsTo(AutorizacionRetiro::class, 'autorizacion_retiro_id');
    }

    /**
     * @return BelongsTo<Asistencia, $this>
     */
    public function asistencia(): BelongsTo
    {
        return $this->belongsTo(Asistencia::class, 'asistencia_id');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function operador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por');
    }

    public function fueAutorizado(): bool
    {
        return $this->resultado === 'autorizado';
    }

    public function fueRechazado(): bool
    {
        return $this->resultado === 'rechazado';
    }

    /**
     * El nombre del responsable que aparece en pantalla.
     *
     * Si el responsable se eliminó del sistema después, el texto congelado es lo
     * que queda. Es la razón de que ese campo exista.
     */
    public function nombreDelResponsable(): string
    {
        if ($this->responsable !== null) {
            return $this->responsable->nombreCompleto();
        }

        return $this->responsable_nombre_texto ?? 'Responsable no identificado';
    }
}