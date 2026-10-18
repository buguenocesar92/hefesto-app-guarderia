<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel;

use App\Enums\EstadoAsistencia;
use App\Enums\EstadoAutorizacion;
use App\Http\Controllers\Controller;
use App\Models\Asistencia;
use App\Models\AutorizacionRetiro;
use App\Models\Incidencia;
use App\Models\Nino;
use App\Models\Retiro;
use App\Models\Usuario;
use App\Services\Autorizaciones\EvaluadorDeAutorizacion;
use App\Services\Permisos\CapacidadesDeUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El panel de la guardería: lo que hay que mirar hoy.
 *
 * LO QUE ESTÁ Y LO QUE NO
 * -----------------------
 * El panel muestra RETROSpectivas y PENDIENTES. Deliberadamente NO hay ninguna
 * alerta de alergias ni de medicación.
 *
 * La razón es la separación entre "hay algo pendiente" y "esto es un dato de
 * salud". Un panel que se usa de pie, todas las mañanas, no es el lugar donde
 * vive el dato sensible de un menor, y ponerlo ahí lo haría visible a quien
 * pase por detrás.
 *
 * Lo que sí va: cuántos niños están, cuántos no llegaron sin aviso, cuántas
 * autorizaciones están por vencer, y qué incidencias quedaron con seguimiento.
 *
 * Ver docs/02 y docs/07.
 */
class PanelController extends Controller
{
    public function __construct(private readonly EvaluadorDeAutorizacion $evaluador) {}

    public function index(Request $peticion): JsonResponse
    {
        $usuario = $this->usuario($peticion);
        $capacidades = CapacidadesDeUsuario::de($usuario);

        $hoy = now()->toDateString();

        $asistencias = Asistencia::query()->whereDate('fecha', $hoy)->get();

        $conteo = static fn (EstadoAsistencia $estado): int => $asistencias->filter(
            static fn (Asistencia $a): bool => $a->estado === $estado
        )->count();

        $sinAviso = $asistencias->filter(
            static fn (Asistencia $a): bool => $a->estado === EstadoAsistencia::Ausente
                && ($a->motivo_ausencia === null || trim($a->motivo_ausencia) === '' || $a->avisado_por === null)
        );

        // Se cuenta la ventana de aviso con el evaluador, no comparando fechas a
        // mano: la misma regla que usa la pantalla de retiro.
        $diasAviso = (int) config('guarderia.autorizaciones.dias_aviso_vencimiento', 15);

        $porVencer = AutorizacionRetiro::query()
            ->where('estado', EstadoAutorizacion::Vigente->value)
            ->whereNotNull('vigente_hasta')
            ->whereDate('vigente_hasta', '<=', now()->addDays($diasAviso)->toDateString())
            ->get()
            ->filter(fn (AutorizacionRetiro $a): bool => $this->evaluador->entraEnVentanaDeAviso($a));

        return response()->json([
            'fecha' => $hoy,
            'capacidades' => $capacidades->aArreglo(),

            'hoy' => [
                'ninos_en_el_centro' => Nino::query()->where('activo', true)->count(),
                'presentes' => $conteo(EstadoAsistencia::Presente),
                'salidas_temporales' => $conteo(EstadoAsistencia::SalidaTemporal),
                'retirados' => $conteo(EstadoAsistencia::Retirado),
                'ausentes' => $conteo(EstadoAsistencia::Ausente),
                'ausentes_sin_aviso' => $sinAviso->count(),

                // Los nombres, porque un número no hace que nadie llame a la
                // familia: hay que ver QUIÉN no avisó.
                'ausentes_sin_aviso_detalle' => $sinAviso->map(static fn (Asistencia $a): array => [
                    'nino_id' => $a->nino_id,
                    'nombre' => $a->nino?->nombreCompleto() ?? 'Niño no identificado',
                ])->values()->all(),
            ],

            'pendientes' => [
                'autorizaciones_por_vencer' => $porVencer->count(),
                'dias_anticipacion_del_aviso' => $diasAviso,
                'incidencias_con_seguimiento' => Incidencia::query()
                    ->where('requiere_seguimiento', true)
                    ->count(),
                'retiros_rechazados_hoy' => Retiro::query()
                    ->whereDate('ocurrido_en', $hoy)
                    ->where('resultado', 'rechazado')
                    ->count(),
            ],
        ]);
    }

    private function usuario(Request $peticion): Usuario
    {
        $usuario = $peticion->attributes->get('usuario');

        abort_unless($usuario instanceof Usuario, 401);

        return $usuario;
    }
}