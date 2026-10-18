<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Incidencia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una incidencia.
 *
 * TRAE LAS DOS PARTES SIEMPRE
 * --------------------------
 * `descripcion` y `accion_tomada`. Una incidencia sin acción deja registro del
 * problema y no de la respuesta, y con la respuesta no se sostiene una
 * conversación con la familia.
 *
 * @property-read Incidencia $resource
 */
class IncidenciaResource extends JsonResource
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
            'categoria' => $this->categoria,
            'gravedad' => $this->gravedad->value,
            'gravedad_etiqueta' => $this->gravedad->etiqueta(),
            'requiere_direccion' => $this->gravedad->requiereDireccion(),
            'ocurrido_en' => $this->ocurrido_en?->toIso8601String(),
            'descripcion' => $this->descripcion,
            'accion_tomada' => $this->accion_tomada,
            'informada_a' => $this->whenLoaded('informadoA', fn (): ?array => $this->informadoA === null ? null : [
                'id' => $this->informadoA->id,
                'nombre' => $this->informadoA->nombreCompleto(),
            ]),
            'requiere_seguimiento' => $this->requiere_seguimiento,
            'autora' => $this->whenLoaded('autora', fn (): ?string => $this->autora?->nombreCompleto()),
            'creado_en' => $this->creado_en?->toIso8601String(),
        ];
    }
}