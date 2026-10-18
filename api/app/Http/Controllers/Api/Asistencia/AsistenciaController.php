<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Asistencia;

use App\Enums\EstadoAsistencia;
use App\Http\Controllers\Controller;
use App\Http\Resources\AsistenciaResource;
use App\Models\Asistencia;
use App\Models\Nino;
use App\Models\Responsable;
use App\Models\Usuario;
use App\Policies\AsistenciaPolicy;
use App\Policies\NinoPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * La asistencia del día.
 *
 * LO QUE HACE VISIBLE "NO LLEGÓ Y NADIE AVISÓ"
 * ---------------------------------------------
 * La lista del día trae `sin_aviso` calculado. El sistema no esconde la
 * ausencia: la pone adelante, con el nombre del niño y un motivo para mirarla.
 *
 * Y marcar una ausencia exige un motivo escrito, en el formulario y en una
 * restricción de la base. Un niño que no llega y nadie avisa es una situación
 * que hay que mirar a las nueve de la mañana, no un dato que se descubre en el
 * mes.
 *
 * Ver docs/02.
 */
class AsistenciaController extends Controller
{
    /**
     * La asistencia de una fecha.
     */
    public function index(Request $peticion): JsonResponse
    {
        $usuario = $this->usuario($peticion);

        Gate::authorize([AsistenciaPolicy::class, 'registrar'], [$usuario]);

        $fecha = (string) $peticion->query('fecha', now()->toDateString());

        $consulta = Asistencia::query()->with(['nino', 'avisador'])->whereDate('fecha', $fecha);

        if ($peticion->filled('estado')) {
            $consulta->where('estado', $peticion->query('estado'));
        }

        $asistencias = $consulta->orderBy('estado')->get();

        $contar = static fn (EstadoAsistencia $estado): int => $asistencias->filter(
            static fn (Asistencia $a): bool => $a->estado === $estado
        )->count();

        $sinAviso = $asistencias->filter(
            static fn (Asistencia $a): bool => $a->estado === EstadoAsistencia::Ausente
                && ($a->motivo_ausencia === null || trim($a->motivo_ausencia) === '' || $a->avisado_por === null)
        )->count();

        return response()->json([
            'fecha' => $fecha,
            'asistencias' => AsistenciaResource::collection($asistencias)->resolve($peticion),

            // El conteo va aparte y en texto: es lo que la persona mira primero
            // al abrir la pantalla.
            'resumen' => [
                'total' => $asistencias->count(),
                'presentes' => $contar(EstadoAsistencia::Presente),
                'salidas_temporales' => $contar(EstadoAsistencia::SalidaTemporal),
                'retirados' => $contar(EstadoAsistencia::Retirado),
                'ausentes' => $contar(EstadoAsistencia::Ausente),
                'sin_aviso' => $sinAviso,
            ],
        ]);
    }

    /**
     * Registra el ingreso de un niño.
     *
     * Si ya hay una fila para hoy, la devuelve con la fila existente y un aviso,
     * en vez de crear una segunda. La restricción `unico_nino_fecha` lo impide
     * de todos modos; esto es para que el mensaje sea legible.
     */
    public function registrarIngreso(Request $peticion, Nino $nino): JsonResponse
    {
        $usuario = $this->usuario($peticion);

        Gate::authorize([NinoPolicy::class, 'ver'], [$nino]);
        Gate::authorize([AsistenciaPolicy::class, 'registrar'], [$usuario]);

        $hoy = now()->toDateString();

        $existente = Asistencia::query()
            ->where('nino_id', $nino->id)
            ->whereDate('fecha', $hoy)
            ->first();

        if ($existente !== null) {
            return response()->json([
                'asistencia' => (new AsistenciaResource($existente->load('nino')))->resolve($peticion),
                'mensaje' => $nino->nombreCompleto() . ' ya tiene ingreso registrado hoy.',
            ], 200);
        }

        $asistencia = Asistencia::create([
            'nino_id' => $nino->id,
            'fecha' => $hoy,
            'ingreso_en' => now(),
            'estado' => EstadoAsistencia::Presente,
            'registrado_por' => $usuario->id,
        ]);

        return response()->json([
            'asistencia' => (new AsistenciaResource($asistencia->load('nino')))->resolve($peticion),
        ], 201);
    }

    /**
     * Registra una salida temporal.
     */
    public function registrarSalidaTemporal(Request $peticion, Nino $nino): JsonResponse
    {
        $usuario = $this->usuario($peticion);

        Gate::authorize([NinoPolicy::class, 'ver'], [$nino]);
        Gate::authorize([AsistenciaPolicy::class, 'registrar'], [$usuario]);

        $asistencia = $this->asistenciaDeHoy($nino);

        if ($asistencia === null) {
            return response()->json([
                'mensaje' => $nino->nombreCompleto() . ' no tiene ingreso registrado hoy.',
            ], 422);
        }

        if ($asistencia->egreso_en !== null) {
            return response()->json([
                'mensaje' => $nino->nombreCompleto() . ' ya tiene salida registrada hoy.',
            ], 422);
        }

        $asistencia->estado = EstadoAsistencia::SalidaTemporal;
        $asistencia->egreso_en = now();
        $asistencia->observacion = $peticion->input('observacion', $asistencia->observacion);
        $asistencia->save();

        return response()->json([
            'asistencia' => (new AsistenciaResource($asistencia->fresh()->load('nino')))->resolve($peticion),
        ]);
    }

    /**
     * Marca una ausencia, con motivo y con quién avisó.
     *
     * Es de DIRECCIÓN. Y el motivo es obligatorio en el formulario y en la
     * base: `asistencia_ausente_exige_motivo`.
     */
    public function marcarAusencia(Request $peticion, Nino $nino): JsonResponse
    {
        $usuario = $this->usuario($peticion);

        Gate::authorize([NinoPolicy::class, 'ver'], [$nino]);
        Gate::authorize([AsistenciaPolicy::class, 'marcarAusencia'], [$usuario]);

        $motivo = trim((string) $peticion->input('motivo_ausencia', ''));

        if ($motivo === '') {
            return response()->json([
                'mensaje' => 'Una ausencia necesita un motivo escrito.',
                'campo' => 'motivo_ausencia',
            ], 422);
        }

        $avisadoPor = $peticion->input('avisado_por');

        if ($avisadoPor !== null && Responsable::query()->find($avisadoPor) === null) {
            return response()->json([
                'mensaje' => 'El responsable que habría avisado no existe.',
                'campo' => 'avisado_por',
            ], 422);
        }

        $asistencia = $this->asistenciaDeHoy($nino);

        if ($asistencia !== null) {
            return response()->json([
                'mensaje' => $nino->nombreCompleto() . ' tiene asistencia registrada hoy. '
                    . 'Corrija la fila existente en lugar de crear otra.',
            ], 422);
        }

        $ausencia = Asistencia::create([
            'nino_id' => $nino->id,
            'fecha' => now()->toDateString(),
            'ingreso_en' => null,
            'egreso_en' => null,
            'estado' => EstadoAsistencia::Ausente,
            'motivo_ausencia' => $motivo,
            'avisado_por' => $avisadoPor,
            'observacion' => $peticion->input('observacion'),
            'registrado_por' => $usuario->id,
        ]);

        return response()->json([
            'asistencia' => (new AsistenciaResource($ausencia->load(['nino', 'avisador'])))->resolve($peticion),
        ], 201);
    }

    private function asistenciaDeHoy(Nino $nino): ?Asistencia
    {
        return Asistencia::query()
            ->where('nino_id', $nino->id)
            ->whereDate('fecha', now()->toDateString())
            ->first();
    }

    private function usuario(Request $peticion): Usuario
    {
        $usuario = $peticion->attributes->get('usuario');

        abort_unless($usuario instanceof Usuario, 401);

        return $usuario;
    }
}