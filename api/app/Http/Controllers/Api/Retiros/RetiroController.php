<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Retiros;

use App\Enums\EstadoAutorizacion;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarRetiroRequest;
use App\Http\Resources\RetiroResource;
use App\Models\AutorizacionRetiro;
use App\Models\Nino;
use App\Models\Responsable;
use App\Models\Retiro;
use App\Models\Usuario;
use App\Policies\NinoPolicy;
use App\Policies\RetiroPolicy;
use App\Services\Autorizaciones\EvaluadorDeAutorizacion;
use App\Services\Retiros\ServicioRetiro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * El registro de retiros. La pantalla más importante de la aplicación.
 *
 * LO QUE VE LA PERSONA QUE OPERA ESTA PANTALLA
 * ---------------------------------------------
 * Al niño, al responsable autorizado y la hora. Y si la autorización está
 * vencida, el sistema lo dice EN GRANDE.
 *
 * Esa última parte no es un detalle de estilo. La pantalla de retiro se usa de
 * pie, con un adulto al lado, y a veces con más de uno esperando. Un "vence el
 * 12 de marzo" en letra chica, al pie de un formulario, se pasa inadvertido. Un
 * recuadro rojo del tamaño de la pantalla no.
 *
 * POR QUÉ EL RECHAZO TAMBIÉN SE ESCRIBE
 * ------------------------------------
 * Un intento rechazado es información, y es de la familia y del centro. Por
 * eso el registro se escribe siempre, y por eso el rechazo responde 422 con un
 * motivo que explica qué pasó. Ver `App\Services\Retiros\ServicioRetiro`.
 *
 * Ver docs/02 y docs/07.
 */
class RetiroController extends Controller
{
    public function __construct(private readonly ServicioRetiro $servicio) {}

    /**
     * El registro de retiros de un niño.
     *
     * Es el historial de un niño concreto, no la bitácora completa de la
     * guardería. Esa es de dirección: ver `bitacora()`.
     */
    public function index(Request $peticion, Nino $nino): JsonResponse
    {
        Gate::authorize([NinoPolicy::class, 'ver'], [$nino]);
        Gate::authorize([RetiroPolicy::class, 'verDelNino'], [$nino]);

        $retiros = Retiro::query()
            ->where('nino_id', $nino->id)
            ->orderByDesc('ocurrido_en')
            ->limit(200)
            ->get();

        return response()->json([
            'retiros' => RetiroResource::collection($retiros)->resolve($peticion),
        ]);
    }

    /**
     * La bitácora completa de la guardería, con los intentos rechazados.
     *
     * Dice a qué familias se les ha rechazado retirar a su niño. Es de
     * DIRECCIÓN, y no porque el dato sea secreto, sino porque es delicado sobre
     * la relación del centro con cada familia.
     */
    public function bitacora(Request $peticion): JsonResponse
    {
        $usuario = $this->usuario($peticion);

        Gate::authorize([RetiroPolicy::class, 'verBitacora'], [$usuario]);

        $consulta = Retiro::query()->orderByDesc('ocurrido_en');

        if ($peticion->filled('resultado')) {
            $consulta->where('resultado', $peticion->query('resultado'));
        }

        return response()->json([
            'retiros' => RetiroResource::collection($consulta->limit(500)->get())->resolve($peticion),
        ]);
    }

    /**
     * El estado de una autorización HOY, antes de intentar retirar.
     *
     * Esto es lo que permite que la pantalla diga "VENCIDA" en grande ANTES de
     * que alguien intente. La pantalla no adivina la vigencia: la pregunta.
     *
     * Es la ruta que la pantalla de retiro consulta al elegir responsable.
     */
    public function estadoDeAutorizacion(Request $peticion, Nino $nino, Responsable $responsable): JsonResponse
    {
        Gate::authorize([NinoPolicy::class, 'ver'], [$nino]);
        Gate::authorize([RetiroPolicy::class, 'verDelNino'], [$nino]);

        $autorizacion = app(EvaluadorDeAutorizacion::class)
            ->autorizacionDeHoy($nino, $responsable);

        if ($autorizacion === null) {
            return response()->json([
                'estado' => 'no_autorizado',
                'permite_retiro' => false,
                'titulo' => 'SIN AUTORIZACIÓN',
                'detalle' => sprintf(
                    '%s no está autorizado para retirar a %s. No hay ninguna autorización registrada a su nombre.',
                    $responsable->nombreCompleto(),
                    $nino->nombreCompleto(),
                ),
            ]);
        }

        $estado = $autorizacion->estadoEfectivo();

        return response()->json([
            'estado' => $estado->value,
            'permite_retiro' => $estado->permiteRetiro(),
            'vigente_hasta' => $autorizacion->vigente_hasta?->toDateString(),
            'dias_para_vencer' => $autorizacion->diasParaVencer(),
            'por_vencer' => $autorizacion->porVencer(),
            'titulo' => $estado->permiteRetiro() ? 'AUTORIZADO' : strtoupper($estado->etiqueta()),
            'detalle' => $estado->permiteRetiro()
                ? $this->detalleDeAutorizado($autorizacion)
                : $this->detalleDeRechazo($estado, $autorizacion),
        ]);
    }

    /**
     * Registra un retiro.
     *
     * Si la autorización no sirve, el servicio escribe el registro del rechazo
     * y lanza la excepción de dominio. La respuesta es 422 con el motivo, y el
     * registro queda escrito. Ver `bootstrap/app.php`.
     */
    public function store(RegistrarRetiroRequest $peticion, Nino $nino): JsonResponse
    {
        $usuario = $this->usuario($peticion);

        Gate::authorize([NinoPolicy::class, 'ver'], [$nino]);
        Gate::authorize([RetiroPolicy::class, 'registrar'], [$usuario]);

        $responsable = Responsable::query()->findOrFail($peticion->integer('responsable_id'));

        $retiro = $this->servicio->registrar(
            $nino,
            $responsable,
            $usuario,
            now(),
            $peticion->has('autorizacion_id') ? $peticion->integer('autorizacion_id') : null,
            $peticion->input('observacion'),
        );

        return response()->json([
            'retiro' => (new RetiroResource($retiro))->resolve($peticion),
        ], 201);
    }

    private function detalleDeAutorizado(AutorizacionRetiro $autorizacion): string
    {
        if ($autorizacion->vigente_hasta === null) {
            return 'Autorización vigente, sin fecha de vencimiento.';
        }

        $dias = $autorizacion->diasParaVencer();

        $texto = sprintf(
            'Autorización vigente hasta el %s.',
            $autorizacion->vigente_hasta->format('d/m/Y'),
        );

        // El aviso ANTES de que expire. Y cuando ya entra en la ventana, en
        // grande, porque es el momento en que hay que hacer algo.
        if ($autorizacion->porVencer() && $dias !== null) {
            $texto .= sprintf(' Vence en %d días: conviene renovarla.', $dias);
        }

        return $texto;
    }

    private function detalleDeRechazo(EstadoAutorizacion $estado, AutorizacionRetiro $autorizacion): string
    {
        return match ($estado) {
            EstadoAutorizacion::Vencida => sprintf(
                'La autorización venció el %s. No puede retirar al niño hasta que un adulto responsable renueve la autorización.',
                $autorizacion->vigente_hasta?->format('d/m/Y') ?? 'fecha no informada',
            ),
            EstadoAutorizacion::Anulada => sprintf(
                'La autorización fue anulada: %s',
                $autorizacion->motivo_anulacion ?? 'sin motivo registrado',
            ),
            default => 'La autorización no está vigente.',
        };
    }

    private function usuario(Request $peticion): Usuario
    {
        $usuario = $peticion->attributes->get('usuario');

        abort_unless($usuario instanceof Usuario, 401);

        return $usuario;
    }
}