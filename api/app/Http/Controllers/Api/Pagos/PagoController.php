<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Pagos;

use App\Enums\EstadoPago;
use App\Http\Controllers\Controller;
use App\Http\Resources\MatriculaResource;
use App\Http\Resources\PagoResource;
use App\Models\Matricula;
use App\Models\Nino;
use App\Models\Pago;
use App\Models\Usuario;
use App\Policies\NinoPolicy;
use App\Policies\PagoPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Pagos y matrículas.
 *
 * QUIÉN LLEGA ACÁ
 * --------------
 * Administrativo y dirección. La educadora no ve pagos: no necesita saber
 * cuánto paga una familia para servir el almuerzo.
 *
 * EL SALDO NO ESTÁ GUARDADO
 * -------------------------
 * Sale de `Matricula::saldoEnPesos()`: precio del plan menos los pagos NO
 * anulados. No hay columna de saldo, y hay una razón.
 *
 * Ver docs/02.
 */
class PagoController extends Controller
{
    /**
     * Las matrículas con su saldo, y los pagos de cada una.
     */
    public function index(Request $peticion): JsonResponse
    {
        $usuario = $this->usuario($peticion);

        Gate::authorize([PagoPolicy::class, 'crear'], [$usuario]);

        $consulta = Matricula::query()->with(['plan', 'nino', 'pagos']);

        if ($peticion->filled('nino_id')) {
            $consulta->where('nino_id', $peticion->query('nino_id'));
        }

        if ($peticion->boolean('solo_activas')) {
            $consulta->where('activa', true);
        }

        $matriculas = $consulta->orderByDesc('creado_en')->limit(200)->get();

        return response()->json([
            'matriculas' => MatriculaResource::collection($matriculas)->resolve($peticion),
            'total_adeudado_clp' => $matriculas->sum(
                static fn (Matricula $m): int => max(0, $m->saldoEnPesos())
            ),
        ]);
    }

    /**
     * Los pagos de un niño, con su saldo.
     */
    public function deNino(Request $peticion, Nino $nino): JsonResponse
    {
        $usuario = $this->usuario($peticion);

        Gate::authorize([NinoPolicy::class, 'ver'], [$nino]);
        Gate::authorize([PagoPolicy::class, 'crear'], [$usuario]);

        $matriculas = Matricula::query()
            ->with(['plan', 'pagos'])
            ->where('nino_id', $nino->id)
            ->orderByDesc('inicia_en')
            ->get();

        return response()->json([
            'nino' => [
                'id' => $nino->id,
                'nombre' => $nino->nombreCompleto(),
            ],
            'matriculas' => MatriculaResource::collection($matriculas)->resolve($peticion),
            'pagos' => PagoResource::collection(
                $matriculas->flatMap(static fn (Matricula $m) => $m->pagos)->sortByDesc('pagado_en')
            )->resolve($peticion),
        ]);
    }

    /**
     * Registra un pago.
     */
    public function store(Request $peticion): JsonResponse
    {
        $usuario = $this->usuario($peticion);

        Gate::authorize([PagoPolicy::class, 'crear'], [$usuario]);

        $matricula = Matricula::query()->findOrFail($peticion->input('matricula_id'));

        $monto = $peticion->integer('monto_clp');

        if ($monto <= 0) {
            return response()->json([
                'mensaje' => 'El monto del pago debe ser mayor que cero.',
                'campo' => 'monto_clp',
            ], 422);
        }

        $pago = Pago::create([
            'matricula_id' => $matricula->id,
            'monto_clp' => $monto,
            'pagado_en' => $peticion->input('pagado_en', now()->toDateString()),
            'metodo' => $peticion->input('metodo'),
            'comprobante' => $peticion->input('comprobante'),
            'estado' => EstadoPago::Registrado,
            'registrado_por' => $usuario->id,
        ]);

        return response()->json([
            'pago' => (new PagoResource($pago))->resolve($peticion),
            'saldo_legible' => $matricula->fresh()->load('plan')->saldoLegible(),
        ], 201);
    }

    /**
     * Anula un pago.
     *
     * Anular, no borrar. La fila queda con su motivo y con quién lo anuló.
     *
     * Es de DIRECCIÓN: registrar un pago es trabajo de todos los que operan;
     * quitar un pago del saldo deshace un hecho económico y lo revisa quien
     * responde por el centro.
     */
    public function anular(Request $peticion, Pago $pago): JsonResponse
    {
        Gate::authorize([PagoPolicy::class, 'anular'], [$pago]);

        $usuario = $this->usuario($peticion);

        $motivo = trim((string) $peticion->input('motivo_anulacion', ''));

        if ($motivo === '') {
            return response()->json([
                'mensaje' => 'Anular un pago necesita un motivo escrito.',
                'campo' => 'motivo_anulacion',
            ], 422);
        }

        $pago->estado = EstadoPago::Anulado;
        $pago->motivo_anulacion = $motivo;
        $pago->anulado_por = $usuario->id;
        $pago->anulado_en = now();
        $pago->save();

        return response()->json([
            'pago' => (new PagoResource($pago->fresh()))->resolve($peticion),
            'mensaje' => 'Pago anulado. Sigue en el registro con su motivo.',
        ]);
    }

    private function usuario(Request $peticion): Usuario
    {
        $usuario = $peticion->attributes->get('usuario');

        abort_unless($usuario instanceof Usuario, 401);

        return $usuario;
    }
}