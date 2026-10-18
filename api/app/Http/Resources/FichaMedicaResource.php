<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FichaMedica;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La ficha médica de un niño, completa.
 *
 * CUANDO SE USA Y CUÁNDO NO
 * -------------------------
 * Este recurso NO aparece en ninguna lista de niños, ni en el resumen del
 * panel, ni en el registro de retiros. Existe únicamente para la ruta que lleva
 * el middleware `medica.auditada`.
 *
 * Si alguna vez aparece en otro lado, eso es un defecto y no una mejora: la
 * protección de esta ficha depende de que nadie la incruste en una pantalla
 * general.
 *
 * `autoriza_medicamento` viaja como lo que es: la declaración del adulto
 * responsable, no un permiso del personal.
 *
 * @property-read FichaMedica $resource
 */
class FichaMedicaResource extends JsonResource
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
            'alergias' => $this->alergias,
            'alimentacion' => $this->alimentacion,
            'alimentos_prohibidos' => $this->alimentos_prohibidos,
            'medicacion' => $this->medicacion,
            'plan_emergencia' => $this->plan_emergencia,
            'contacto_emergencia_nombre' => $this->contacto_emergencia_nombre,
            'contacto_emergencia_telefono' => $this->contacto_emergencia_telefono,
            'autoriza_medicamento' => $this->autoriza_medicamento,
            'tiene_restricciones' => $this->tieneRestricciones(),
            'actualizado_en' => $this->actualizado_en?->toIso8601String(),
        ];
    }
}