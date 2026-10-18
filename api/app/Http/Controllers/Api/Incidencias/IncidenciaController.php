<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Incidencias;

use App\Enums\GravedadIncidencia;
use App\Http\Controllers\Controller;
use App\Http\Resources\IncidenciaResource;
use App\Models\Incidencia;
use App\Models\Nino;
use App\Models\Usuario;
use App\Policies\NinoPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * El registro de incidencias.
 *
 * LAS DOS PARTES SON OBLIGATORIAS
 * ------------------------------
 * `descripcion` y `accion_tomada`. Los mínimos salen de
 * `config('guarderia.incidencias')` y no de un número escrito acá, para que el
 * centro pueda ajustarlos sin que el código cambie.
 *
 * NO HAY RUTA DE BORRADO
 * ----------------------
 * Y no es una omisión pendiente. La base lo impone: el disparador
 * `*_bloquear_borrado_de_registros*` rechaza el `DELETE`, aunque alguien
 * escriba con un cliente de SQL.
 *
 * Un registro que se puede borrar no sostiene una conversación con la familia
 * tres meses después.
 *
 * Ver docs/07.
 */
class IncidenciaController extends Controller
{
    /**
     * Las incidencias de un niño.
     */
    public function index(Request $peticion, Nino $nino): JsonResponse
    {
        Gate::authorize([NinoPolicy::class, 'ver'], [$nino]);

        $consulta = Incidencia::query()->with(['nino', 'autora', 'informadoA'])
            ->where('nino_id', $nino->id);

        if ($peticion->filled('gravedad')) {
            $consulta->where('gravedad', $peticion->query('gravedad'));
        }

        return response()->json([
            'incidencias' => IncidenciaResource::collection(
                $consulta->orderByDesc('ocurrido_en')->limit(200)->get()
            )->resolve($peticion),
        ]);
    }

    /**
     * Las incidencias del centro, para la dirección.
     */
    public function delCentro(Request $peticion): JsonResponse
    {
        $usuario = $this->usuario($peticion);

        Gate::authorize([NinoPolicy::class, 'crear'], [$usuario->tenant_id]);

        $consulta = Incidencia::query()->with(['nino', 'autora'])->orderByDesc('ocurrido_en');

        if ($peticion->boolean('requiere_seguimiento')) {
            $consulta->where('requiere_seguimiento', true);
        }

        if ($peticion->filled('gravedad')) {
            $consulta->where('gravedad', $peticion->query('gravedad'));
        }

        $incidencias = $consulta->limit(300)->get();

        return response()->json([
            'incidencias' => IncidenciaResource::collection($incidencias)->resolve($peticion),
            'criticas' => $incidencias->filter(
                static fn (Incidencia $i): bool => $i->gravedad === GravedadIncidencia::Critica
            )->count(),
            'requieren_seguimiento' => $incidencias->where('requiere_seguimiento', true)->count(),
        ]);
    }

    /**
     * Registra una incidencia.
     */
    public function store(Request $peticion, Nino $nino): JsonResponse
    {
        $usuario = $this->usuario($peticion);

        Gate::authorize([NinoPolicy::class, 'ver'], [$nino]);

        $descripcion = trim((string) $peticion->input('descripcion', ''));
        $accion = trim((string) $peticion->input('accion_tomada', ''));

        $minimoDescripcion = (int) config('guarderia.incidencias.descripcion_minimo_caracteres', 20);
        $minimoAccion = (int) config('guarderia.incidencias.accion_minimo_caracteres', 10);

        $errores = [];

        if (mb_strlen($descripcion) < $minimoDescripcion) {
            $errores['descripcion'] = sprintf(
                'Describa qué pasó con al menos %d caracteres. Sin detalle, el registro no sirve después.',
                $minimoDescripcion,
            );
        }

        if (mb_strlen($accion) < $minimoAccion) {
            $errores['accion_tomada'] = sprintf(
                'Describa qué se hizo con al menos %d caracteres. Una incidencia sin acción deja registro del problema y no de la respuesta.',
                $minimoAccion,
            );
        }

        if ($errores !== []) {
            return response()->json([
                'mensaje' => 'Revise los campos marcados.',
                'errores' => $errores,
            ], 422);
        }

        $incidencia = Incidencia::create([
            'nino_id' => $nino->id,
            'categoria' => $peticion->input('categoria'),
            'gravedad' => $peticion->input('gravedad', GravedadIncidencia::Menor->value),
            'ocurrido_en' => $peticion->input('ocurrido_en', now()->toIso8601String()),
            'descripcion' => $descripcion,
            'accion_tomada' => $accion,
            'informada_a' => $peticion->input('informada_a'),
            'requiere_seguimiento' => $peticion->boolean('requiere_seguimiento'),
            'registrada_por' => $usuario->id,
        ]);

        return response()->json([
            'incidencia' => (new IncidenciaResource($incidencia->load(['nino', 'autora'])))->resolve($peticion),
        ], 201);
    }

    private function usuario(Request $peticion): Usuario
    {
        $usuario = $peticion->attributes->get('usuario');

        abort_unless($usuario instanceof Usuario, 401);

        return $usuario;
    }
}