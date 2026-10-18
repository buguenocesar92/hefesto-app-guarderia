<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Pago;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un pago.
 *
 * UN PAGO ANULADO SIGUE SIENDO UN PAGO
 * ------------------------------------
 * Viaja con su estado, su motivo y quién lo anuló. Anular no es borrar, y el
 * recurso no lo esconde: si se ocultaran los anulados, el saldo de una familia
 * dejaría de poder reconstruirse.
 *
 * @property-read Pago $resource
 */
class PagoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        return [
            'id' => $this->id,
            'matricula_id' => $this->matricula_id,
            'monto_clp' => $this->monto_clp,
            'monto_legible' => '$' . number_format($this->monto_clp, 0, ',', '.'),
            'pagado_en' => $this->pagado_en?->toDateString(),
            'metodo' => $this->metodo,
            'comprobante' => $this->comprobante,
            'estado' => $this->estado->value,
            'estado_etiqueta' => $this->estado->etiqueta(),
            'suma_al_saldo' => $this->estado->sumaAlSaldo(),
            'motivo_anulacion' => $this->motivo_anulacion,
            'anulado_en' => $this->anulado_en?->toIso8601String(),
            'creado_en' => $this->creado_en?->toIso8601String(),
        ];
    }
}