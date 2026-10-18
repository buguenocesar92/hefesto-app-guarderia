<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Autorizaciones;

use App\Enums\EstadoAutorizacion;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AutorizacionResource;
use App\Models\AutorizacionRetiro;
use App\Models\Nino;
use App\Models\Responsable;
use App\Models\Usuario;
use App\Policies\AutorizacionPolicy;
use App\Policies\NinoPolicy;
use App\Services\Autorizaciones\EvaluadorDeAutorizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Las autorizaciones de retiro.
 *
 * DANDO DE ALTA UN AUTORIZADO ES DANDO CONTROL SOBRE UN MENOR
 * ---------------------------------------------------------
 * Quien autoriza a una persona a retirar a un niño le está dando, en la
 * práctica, acceso a ese niño. Por eso el alta y la renovación son de DIRECCIÓN.
 *
 * Educadora y administrativo PUEDEN leerlas —lo necesitan para explicar un
 * rechazo en la puerta— pero no pueden darlas de alta. La lectura no expone
 * dato de salud: expone nombre, documento y fecha.
 *
 * LA VIGENCIA NO SE INVENTA
 * -------------------------
 * `vigente_hasta` ADMITE NULO. Hay centros donde la autorización de un
 * progenitor legal no vence, y eso es una decisión del centro. Lo que no existe
 * es un plazo escrito por omisión sin fuente: la aplicación no pone ninguno.
 *
 * Ver docs/03 y docs/07.
 */
class AutorizacionController extends Controller
{
    /**
     * Las autorizaciones de un niño.
     */
    public function index(Request $peticion, Nino $nino): JsonResponse
    {
        Gate::authorize([NinoPolicy::class, 'ver'], [$nino]);

        $autorizaciones = AutorizacionRetiro::query()
            ->with('responsable')
            ->where('nino_id', $nino->id)
            ->orderByRaw('vigente_hasta DESC NULLS LAST')
            ->get();

        return response()->json([
            'autorizaciones' => AutorizacionResource::collection($autorizaciones)->resolve($peticion),
        ]);
    }

    /**
     * Las que están por vencer, en toda la guardería.
     *
     * Es la lista que el sistema necesita para avisar ANTES de que expiren.
     * Con `dias`, se puede acotar la ventana; sin el parámetro, usa la de
     * `config('guarderia.autorizaciones.dias_aviso_vencimiento')`.
     */
    public function porVencer(Request $peticion): JsonResponse
    {
        $usuario = $this->usuario($peticion);

        Gate::authorize([NinoPolicy::class, 'crear'], [$usuario->tenant_id]);

        $dias = $peticion->has('dias')
            ? $peticion->integer('dias')
            : (int) config('guarderia.autorizaciones.dias_aviso_vencimiento', 15);

        $evaluador = app(EvaluadorDeAutorizacion::class);

        $candidatas = AutorizacionRetiro::query()
            ->with(['nino', 'responsable'])
            ->where('estado', EstadoAutorizacion::Vigente->value)
            ->whereNotNull('vigente_hasta')
            ->whereDate('vigente_hasta', '<=', now()->addDays($dias)->toDateString())
            ->orderBy('vigente_hasta')
            ->get();

        $porVencer = $candidatas->filter(fn (AutorizacionRetiro $a): bool => $evaluador->entraEnVentanaDeAviso($a));

        return response()->json([
            'dias_aviso' => $dias,
            'total' => $porVencer->count(),

            // El total de CANDIDATAS contra las que están en ventana: una
            // diferencia grande entre los dos significa que hay muchas
            // vencidas de más y que la ventana se está corriendo.
            'autorizaciones' => AutorizacionResource::collection($porVencer)->resolve($peticion),
            'mensaje' => $porVencer->isEmpty()
                ? 'No hay autorizaciones por vencer en esta ventana.'
                : sprintf('Hay %d autorizaciones por vencer en los próximos %d días.', $porVencer->count(), $dias),
        ]);
    }

    /**
     * Da de alta una autorización, o renueva una vencida.
     */
    public function store(Request $peticion, Nino $nino): JsonResponse
    {
        $usuario = $this->usuario($peticion);

        Gate::authorize([NinoPolicy::class, 'ver'], [$nino]);
        Gate::authorize([AutorizacionPolicy::class, 'crear'], [$usuario]);

        $responsable = Responsable::query()->findOrFail($peticion->input('responsable_id'));

        $vigenteDesde = $peticion->input('vigente_desde');
        $vigenteHasta = $peticion->input('vigente_hasta');

        if (is_string($vigenteDesde) && is_string($vigenteHasta) && $vigenteHasta < $vigenteDesde) {
            throw DomainException::vigenciaInvalida();
        }

        $autorizacion = AutorizacionRetiro::create([
            'nino_id' => $nino->id,
            'responsable_id' => $responsable->id,
            'vigente_desde' => $vigenteDesde,
            'vigente_hasta' => $vigenteHasta,
            'documento_tipo' => $responsable->documento_tipo->value,
            'documento_numero' => $responsable->documento_numero,
            'estado' => EstadoAutorizacion::Vigente,
            'registrada_por' => $usuario->id,
        ]);

        return response()->json([
            'autorizacion' => (new AutorizacionResource($autorizacion->load('responsable')))->resolve($peticion),
        ], 201);
    }

    /**
     * Anula una autorización.
     *
     * Anular, NO borrar. La fila queda con su motivo y con quién la anuló, y el
     * rechazo posterior puede explicar exactamente qué pasó.
     */
    public function anular(Request $peticion, AutorizacionRetiro $autorizacion): JsonResponse
    {
        $usuario = $this->usuario($peticion);

        Gate::authorize([AutorizacionPolicy::class, 'anular'], [$autorizacion]);

        $motivo = trim((string) $peticion->input('motivo_anulacion', ''));

        if ($motivo === '') {
            return response()->json([
                'mensaje' => 'Anular una autorización necesita un motivo escrito.',
                'campo' => 'motivo_anulacion',
            ], 422);
        }

        $autorizacion->estado = EstadoAutorizacion::Anulada;
        $autorizacion->motivo_anulacion = $motivo;
        $autorizacion->anulada_por = $usuario->id;
        $autorizacion->anulada_en = now();
        $autorizacion->save();

        return response()->json([
            'autorizacion' => (new AutorizacionResource($autorizacion->fresh()->load('responsable')))->resolve($peticion),
            'mensaje' => 'Autorización anulada. Sigue en el registro con su motivo.',
        ]);
    }

    private function usuario(Request $peticion): Usuario
    {
        $usuario = $peticion->attributes->get('usuario');

        abort_unless($usuario instanceof Usuario, 401);

        return $usuario;
    }
}