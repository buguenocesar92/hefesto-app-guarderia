<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Matricula;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una matrícula con su saldo.
 *
 * EL SALDO VIENE CALCULADO
 * -----------------------
 * `saldo_clp` y `saldo_legible` salen de `Matricula::saldoEnPesos()`, que suma
 * los pagos NO anulados y los resta del precio del plan.
 *
 * No hay una columna de saldo en la base, y esa es la decisión: un saldo
 * guardado se desactualiza en cuanto alguien registra un pago y no lo
 * actualiza, y después el sistema muestra que se debe una mensualidad que ya se
 * pagó.
 *
 * @property-read Matricula $resource
 */
class MatriculaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        return [
            'id' => $this->id,
            'nino_id' => $this->nino_id,
            'plan_id' => $this->plan_id,
            'plan' => $this->whenLoaded('plan', fn (): ?array => $this->plan === null ? null : [
                'id' => $this->plan->id,
                'nombre' => $this->plan->nombre,
                'precio_clp' => $this->plan->precio_clp,
            ]),
            'inicia_en' => $this->inicia_en?->toDateString(),
            'termina_en' => $this->termina_en?->toDateString(),
            'activa' => $this->activa,
            'total_pagado' => $this->totalPagado(),
            'saldo_clp' => $this->saldoEnPesos(),
            'saldo_legible' => $this->saldoLegible(),
            'creado_en' => $this->creado_en?->toIso8601String(),
        ];
    }
}