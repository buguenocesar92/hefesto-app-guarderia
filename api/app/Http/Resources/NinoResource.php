<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Nino;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un niño, en una lista.
 *
 * LO QUE NO TRAE, Y ES LO IMPORTANTE
 * ----------------------------------
 * No trae alergias, ni alimentación, ni medicación. Nada de la ficha médica.
 *
 * `tiene_ficha_medica` es un sí o no, y es a propósito: la pantalla de grupo
 * necesita saber que hay una ficha, no qué dice. Saber que existe y poder
 * abrirla son dos cosas distintas, y sólo la segunda requiere permiso.
 *
 * Si alguna vez aparece `alergias` en este recurso, eso es un defecto. La
 * ficha médica se abre por su propia ruta, con su middleware y su bitácora.
 *
 * @property-read Nino $resource
 */
class NinoResource extends JsonResource
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
            'fecha_nacimiento' => $this->fecha_nacimiento?->toDateString(),
            'edad' => $this->edad(),
            'sexo' => $this->sexo,
            'rut' => $this->rut,
            'activo' => $this->activo,

            // Un sí o un no. Nunca el contenido de la ficha.
            'tiene_ficha_medica' => $this->fichaMedica()->exists(),

            'responsables' => $this->whenLoaded('responsables', fn (): array => $this->responsables->map(fn ($responsable): array => [
                'id' => $responsable->id,
                'nombre' => $responsable->nombreCompleto(),
                'parentesco' => $responsable->pivot->parentesco,
                'es_responsable_legal' => (bool) $responsable->pivot->es_responsable_legal,
                'documento' => $responsable->documentoLegible(),
            ])->values()->all()),
        ];
    }
}