<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\EstadoAutorizacion;
use App\Models\AutorizacionRetiro;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La autorización de retiro, tal como la ve la pantalla.
 *
 * LO QUE TRAE Y POR QUÉ
 * ---------------------
 * `estado` y `permite_retiro` salen del EVALUADOR, no de la columna guardada.
 * La fecha manda: una fila marcada vigente con la fecha pasada está vencida, y
 * la pantalla tiene que decir eso.
 *
 * `por_vencer` y `dias_para_vencer` son lo que permite el aviso ANTES de que
 * expire. Un sistema que avisa cuando ya venció no está avisando: está
 * dejando constancia. Ver `App\Services\Autorizaciones\EvaluadorDeAutorizacion`.
 *
 * @property-read AutorizacionRetiro $resource
 */
class AutorizacionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        $estado = $this->estadoEfectivo();

        return [
            'id' => $this->id,
            'nino_id' => $this->nino_id,
            'nino' => $this->whenLoaded('nino', fn (): array => [
                'id' => $this->nino->id,
                'nombre' => $this->nino->nombreCompleto(),
            ]),
            'responsable_id' => $this->responsable_id,
            'responsable' => $this->whenLoaded('responsable', fn (): array => [
                'id' => $this->responsable->id,
                'nombre' => $this->responsable->nombreCompleto(),
                'documento' => $this->responsable->documentoLegible(),
            ]),
            'vigente_desde' => $this->vigente_desde?->toDateString(),
            'vigente_hasta' => $this->vigente_hasta?->toDateString(),
            'estado' => $estado->value,
            'estado_etiqueta' => $estado->etiqueta(),
            'permite_retiro' => $estado === EstadoAutorizacion::Vigente,
            'dias_para_vencer' => $this->diasParaVencer(),
            'por_vencer' => $this->porVencer(),
            'documento_tipo' => $this->documento_tipo,
            'documento_numero' => $this->documento_numero,
            'motivo_anulacion' => $this->motivo_anulacion,
            'anulada_en' => $this->anulada_en?->toIso8601String(),
            'creado_en' => $this->creado_en?->toIso8601String(),
        ];
    }
}