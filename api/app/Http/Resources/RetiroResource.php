<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Retiro;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El registro de un retiro, tal como lo ve la pantalla.
 *
 * LO QUE ESTÁ CONGELADO Y POR QUÉ
 * -------------------------------
 * `responsable_nombre` y `responsable_documento` salen del texto congelado en el
 * momento del retiro cuando el responsable ya no está, y del responsable
 * cuando sí está.
 *
 * Y hay una razón para que el texto congelado exista: el registro de lo que
 * pasó a las cuatro de la tarde de ese día no puede cambiar porque mañana
 * alguien corrija la ficha del responsable. Ver
 * `database/migrations/*_crear_tabla_retiros.php`.
 *
 * `resultado` y `motivo_rechazo` van siempre. Un rechazo sin motivo escrito es
 * un rechazo que no sirve de nada después.
 *
 * @property-read Retiro $resource
 */
class RetiroResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        return [
            'id' => $this->id,
            'nino_id' => $this->nino_id,
            'nino' => $this->whenLoaded('nino', fn (): array => [
                'id' => $this->nino->id,
                'nombre' => $this->nino->nombreCompleto(),
            ]),
            'responsable_nombre' => $this->responsable !== null
                ? $this->responsable->nombreCompleto()
                : ($this->responsable_nombre_texto ?? 'Responsable no identificado'),
            'responsable_documento' => $this->responsable !== null
                ? $this->responsable->documentoLegible()
                : ($this->responsable_documento_texto ?? 'Sin documento registrado'),
            'autorizacion_id' => $this->autorizacion_retiro_id,
            'asistencia_id' => $this->asistencia_id,

            // La hora exacta. La pantalla de retiro la muestra siempre, porque
            // "quién lo retiró y cuándo" es la primera pregunta que se hace.
            'ocurrido_en' => $this->ocurrido_en?->toIso8601String(),
            'hora' => $this->ocurrido_en?->format('H:i'),

            'resultado' => $this->resultado,
            'autorizado' => $this->fueAutorizado(),
            'motivo_rechazo' => $this->motivo_rechazo,
            'observacion' => $this->observacion,

            'operador' => $this->whenLoaded('operador', fn (): ?string => $this->operador?->nombreCompleto()),
            'creado_en' => $this->creado_en?->toIso8601String(),
        ];
    }
}