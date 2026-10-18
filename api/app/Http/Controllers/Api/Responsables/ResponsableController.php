<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Responsables;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponsableResource;
use App\Models\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Los responsables.
 *
 * LO QUE HACE DIFERENTE A ESTA PANTALLA
 * -------------------------------------
 * No es una lista más: es parte de la pantalla de "¿quién puede retirar a este
 * niño?".
 *
 * Por eso `ResponsableResource` trae el estado CALCULADO de cada autorización
 * con la fecha de hoy. No es un booleano guardado que alguien pueda dejar en
 * `true`: sale de `EvaluadorDeAutorizacion`, que combina el estado guardado y
 * la fecha.
 *
 * CUANDO SE BUSCA POR UN NIÑO CONCRETO
 * ------------------------------------
 * Con `?nino_id=`, la lista se acota a sus responsables. La pantalla de retiro
 * lo usa así: primero se elige el niño, después se ofrece quién puede ir a
 * buscarlo.
 *
 * Ver docs/02 y docs/03.
 */
class ResponsableController extends Controller
{
    /**
     * La lista de responsables, con el estado de sus autorizaciones.
     */
    public function index(Request $peticion): JsonResponse
    {
        $consulta = Responsable::query()->with(['ninos', 'autorizaciones']);

        if ($peticion->filled('nino_id')) {
            $ninoId = (string) $peticion->query('nino_id');
            $consulta->whereHas('ninos', fn ($c) => $c->where('ninos.id', $ninoId));
        }

        if ($peticion->filled('buscar')) {
            $buscar = trim((string) $peticion->query('buscar'));

            // Por apellido y por número de documento. En la puerta, quien llega
            // dice el nombre; en el archivo, se busca por RUT.
            $consulta->where(function ($c) use ($buscar): void {
                $c->where('apellidos', 'ilike', '%' . $buscar . '%')
                    ->orWhere('nombres', 'ilike', '%' . $buscar . '%')
                    ->orWhere('documento_numero', 'ilike', '%' . $buscar . '%');
            });
        }

        if ($peticion->boolean('solo_activos')) {
            $consulta->where('activo', true);
        }

        return response()->json([
            'responsables' => ResponsableResource::collection(
                $consulta->orderBy('apellidos')->limit(200)->get()
            )->resolve($peticion),
        ]);
    }

    /**
     * Un responsable, con sus niños y el estado de sus autorizaciones.
     */
    public function show(Request $peticion, Responsable $responsable): JsonResponse
    {
        $responsable->load(['ninos', 'autorizaciones']);

        return response()->json([
            'responsable' => (new ResponsableResource($responsable))->resolve($peticion),
        ]);
    }
}