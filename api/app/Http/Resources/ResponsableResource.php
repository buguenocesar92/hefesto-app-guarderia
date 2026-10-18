<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Responsable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un responsable, con el estado de sus autorizaciones.
 *
 * POR QUÉ `puede_retirar_hoy` ES UN DATO DE ESTA PANTALLA
 * ------------------------------------------------------
 * Porque la pregunta "¿este adulto puede llevarse a este niño hoy?" se hace en
 * la puerta, con la persona al lado, y la respuesta tiene que estar a la vista
 * antes de que alguien la pregunte.
 *
 * Y es un dato CALCULADO, con la fecha de hoy. No es un campo de la fila, y no
 * es un booleano que alguien pueda poner en `true`: sale del evaluador, que
 * combina el estado guardado y la fecha.
 *
 * Un campo `puede_retirar` en la base sería un campo que se desactualiza, y un
 * campo que dice que un adulto puede llevarse a un niño es de los que no pueden
 * estar equivocados.
 *
 * @property-read Responsable $resource
 */
class ResponsableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        return [
            'id' => $this->id,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'nombre' => $this->nombreCompleto(),
            'documento_tipo' => $this->documento_tipo->value,
            'documento_etiqueta' => $this->documento_tipo->etiqueta(),
            'documento_numero' => $this->documento_numero,
            'documento' => $this->documentoLegible(),
            'telefono' => $this->telefono,
            'correo' => $this->correo,
            'activo' => $this->activo,

            'ninos' => $this->whenLoaded('ninos', fn (): array => $this->ninos->map(fn ($nino): array => [
                'id' => $nino->id,
                'nombre' => $nino->nombreCompleto(),
                'parentesco' => $nino->pivot->parentesco,
                'es_responsable_legal' => (bool) $nino->pivot->es_responsable_legal,
            ])->values()->all()),

            // El estado de cada autorización vigente de este responsable.
            // Calculado con la fecha de hoy, nunca leído de una columna.
            'autorizaciones' => $this->whenLoaded('autorizaciones', fn (): array => $this->autorizaciones->map(fn ($autorizacion): array => [
                'nino_id' => $autorizacion->nino_id,
                'estado' => $autorizacion->estadoEfectivo()->value,
                'vigente_hasta' => $autorizacion->vigente_hasta?->toDateString(),
                'dias_para_vencer' => $autorizacion->diasParaVencer(),
                'permite_retiro' => $autorizacion->vigente(),
            ])->values()->all()),
        ];
    }
}