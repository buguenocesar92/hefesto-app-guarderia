<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Ninos;

use App\Http\Controllers\Controller;
use App\Http\Resources\NinoResource;
use App\Models\Nino;
use App\Policies\NinoPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Los niños de la guardería.
 *
 * LO QUE ESTA PANTALLA NO MUESTRA
 * -------------------------------
 * La ficha médica. No la lista, ni el resumen, ni una alerta. La ficha se abre
 * por su propia ruta, con su middleware y su bitácora.
 *
 * `NinoResource` trae `tiene_ficha_medica`, que es un sí o un no. Saber que
 * existe una ficha y poder abrirla son dos cosas distintas.
 *
 * Ver docs/03.
 */
class NinoController extends Controller
{
    public function index(Request $peticion): JsonResponse
    {
        $usuario = $this->usuario($peticion);

        $consulta = Nino::query()->with('responsables');

        if ($peticion->filled('buscar')) {
            $buscar = trim((string) $peticion->query('buscar'));

            // Se busca por apellido y por nombre, juntos. En la puerta se
            // conoce el apellido; en la ficha se conoce el nombre.
            $consulta->where(function ($c) use ($buscar): void {
                $c->where('apellidos', 'ilike', '%' . $buscar . '%')
                    ->orWhere('nombres', 'ilike', '%' . $buscar . '%');
            });
        }

        if ($peticion->boolean('solo_activos')) {
            $consulta->where('activo', true);
        }

        return response()->json([
            'ninos' => NinoResource::collection($consulta->orderBy('apellidos')->limit(200)->get())->resolve($peticion),
            'guarderia_id' => $usuario->tenant_id,
        ]);
    }

    public function show(Request $peticion, Nino $nino): JsonResponse
    {
        Gate::authorize([NinoPolicy::class, 'ver'], [$nino]);

        $nino->load('responsables');

        return response()->json([
            'nino' => (new NinoResource($nino))->resolve($peticion),
        ]);
    }

    /**
     * El niño con su autorización, para la pantalla de retiro.
     *
     * Trae los responsables CON su autorización vigente. Es lo que la pantalla
     * necesita para poder decir "AUTORIZADO" o "VENCIDA" antes de que alguien
     * intente.
     */
    public function paraRetiro(Request $peticion, Nino $nino): JsonResponse
    {
        Gate::authorize([NinoPolicy::class, 'ver'], [$nino]);

        $nino->load(['responsables.autorizaciones']);

        $evaluador = app(\App\Services\Autorizaciones\EvaluadorDeAutorizacion::class);

        $responsables = $nino->responsables->map(function ($responsable) use ($evaluador): array {
            $autorizacion = $evaluador->autorizacionDeHoy($nino, $responsable);

            return [
                'id' => $responsable->id,
                'nombre' => $responsable->nombreCompleto(),
                'parentesco' => $responsable->pivot->parentesco,
                'es_responsable_legal' => (bool) $responsable->pivot->es_responsable_legal,
                'documento' => $responsable->documentoLegible(),
                'autorizacion_id' => $autorizacion?->id,
                'estado' => $autorizacion?->estadoEfectivo()->value ?? 'no_autorizado',
                'vigente_hasta' => $autorizacion?->vigente_hasta?->toDateString(),
                'dias_para_vencer' => $autorizacion?->diasParaVencer(),
                'permite_retiro' => $autorizacion !== null && $evaluador->permiteRetiro($autorizacion),
            ];
        })->values();

        return response()->json([
            'nino' => [
                'id' => $nino->id,
                'nombre' => $nino->nombreCompleto(),
                'fecha_nacimiento' => $nino->fecha_nacimiento?->toDateString(),
                'edad' => $nino->edad(),
            ],
            'responsables' => $responsables,
            'hora' => now()->format('H:i'),
        ]);
    }

    private function usuario(Request $peticion): \App\Models\Usuario
    {
        $usuario = $peticion->attributes->get('usuario');

        abort_unless($usuario instanceof \App\Models\Usuario, 401);

        return $usuario;
    }
}