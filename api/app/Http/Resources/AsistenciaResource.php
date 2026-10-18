<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Asistencia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La asistencia de un niño un día.
 *
 * LO QUE HACE VISIBLE LA AUSENCIA SIN AVISO
 * -----------------------------------------
 * `sin_aviso` es un booleano DERIVADO, no guardado: es `estado = ausente` y
 * `motivo_ausencia` vacío o `avisado_por` nulo.
 *
 * Existe porque "no vino y avisaron" y "no vino y nadie avisó" son dos
 * situaciones distintas, y la segunda es la que el sistema tiene que poner
 * delante de alguien a las nueve de la mañana.
 *
 * @property-read Asistencia $resource
 */
class AsistenciaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        return [
            'id' => $this->id,
            'nino_id' => $this->nino_id,
            'fecha' => $this->fecha?->toDateString(),
            'estado' => $this->estado->value,
            'estado_etiqueta' => $this->estado->etiqueta(),
            'ingreso_en' => $this->ingreso_en?->toIso8601String(),
            'egreso_en' => $this->egreso_en?->toIso8601String(),
            'estuvo_en_el_centro' => $this->estado->estuvoEnElCentro(),
            'motivo_ausencia' => $this->motivo_ausencia,
            'aviso' => $this->whenLoaded('avisador', fn (): ?array => $this->avisador === null ? null : [
                'id' => $this->avisador->id,
                'nombre' => $this->avisador->nombreCompleto(),
            ]),
            'sin_aviso' => $this->sinAviso(),
            'observacion' => $this->observacion,

            // Una asistencia cerrada sin registro de retiro es un hueco que
            // alguien tendría que reconstruir a mano. Se muestra.
            'sin_registro_de_retiro' => $this->sinRegistroDeRetiro(),
        ];
    }

    private function sinAviso(): bool
    {
        if ($this->estado->value !== 'ausente') {
            return false;
        }

        return $this->motivo_ausencia === null
            || trim($this->motivo_ausencia) === ''
            || $this->avisado_por === null;
    }
}