<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Acceso;
use App\Models\Usuario;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Anota cada apertura de ficha médica, permitida o denegada.
 *
 * QUÉ ES ESTO
 * -----------
 * Un acceso denegado es información: es el caso en que alguien pidió abrir la
 * ficha médica de un niño y no le correspondía. Si sólo se anotara lo permitido,
 * el registro sería la lista de las cosas que el sistema dejó pasar, y no
 * diría nada sobre lo que alguien intentó hacer.
 *
 * POR QUÉ ESTÁ EN UN MIDDLEWARE Y NO EN EL CONTROLADOR
 * ---------------------------------------------------
 * Porque el registro tiene que estar en el camino de TODA lectura y toda
 * escritura de la ficha, incluidas las que se agreguen después. Si estuviera en
 * el controlador, la próxima ruta nueva se olvidaría de anotarlo, y el olvido
 * es silencioso: el registro simplemente deja de tener un dato.
 *
 * Y el middleware anota el DENEGADO con el motivo, que el controlador no llega
 * a ver porque la excepción corta antes.
 *
 * CÓMO SE SABE QUÉ NIÑO SE ESTÁ VIENDO
 * ------------------------------------
 * Del parámetro de la ruta, `nino`. Se anota el identificador del niño y no el
 * de la ficha, porque la ficha se crea junto al niño y es más estable.
 *
 * Ver docs/03 y docs/07.
 */
class AuditarFichaMedica
{
    public function handle(Request $peticion, Closure $siguiente): Response
    {
        $usuario = $peticion->attributes->get('usuario');

        if (! $usuario instanceof Usuario) {
            return response()->json([
                'mensaje' => 'No encontrado.',
            ], 404);
        }

        $recursoId = $peticion->route('nino');
        $accion = $this->accion($peticion);

        try {
            $respuesta = $siguiente($peticion);
        } catch (Throwable $error) {
            // Un rechazo de Policy se registra como denegado, con el motivo del
            // rechazo, no con el de un fallo del sistema.
            $this->anotar(
                $usuario,
                'fichas_medicas',
                is_string($recursoId) ? $recursoId : null,
                $accion,
                'denegado',
                $error->getMessage(),
                $peticion,
            );

            throw $error;
        }

        $this->anotar(
            $usuario,
            'fichas_medicas',
            is_string($recursoId) ? $recursoId : null,
            $accion,
            $respuesta->getStatusCode() < 400 ? 'permitido' : 'denegado',
            $respuesta->getStatusCode() < 400 ? null : 'La API respondió ' . $respuesta->getStatusCode() . '.',
            $peticion,
        );

        return $respuesta;
    }

    /**
     * Qué se hizo con la ficha, en palabras del registro.
     */
    private function accion(Request $peticion): string
    {
        return match ($peticion->method()) {
            'GET' => 'leer',
            'POST' => 'crear',
            'PUT', 'PATCH' => 'actualizar',
            default => 'otra',
        };
    }

    /**
     * Escribe una fila en `accesos`.
     *
     * La escritura va envuelta: si la bitácora falla, la lectura de la ficha no
     * tiene por qué caer. Perder un registro es malo; perder el servicio porque
     * no se pudo anotar quién lo consultó es peor.
     */
    private function anotar(
        Usuario $usuario,
        string $recurso,
        ?string $recursoId,
        string $accion,
        string $resultado,
        ?string $motivo,
        Request $peticion,
    ): void {
        try {
            Acceso::create([
                'tenant_id' => $usuario->tenant_id,
                'usuario_id' => $usuario->id,
                'rol' => $usuario->rol->value,
                'recurso' => $recurso,
                'recurso_id' => $recursoId,
                'accion' => $accion,
                'resultado' => $resultado,
                'motivo' => $motivo === null ? null : mb_substr($motivo, 0, 240),
                'ruta' => mb_substr($peticion->path(), 0, 200),
                'direccion_ip' => $peticion->ip(),
                'ocurrido_en' => now(),
            ]);
        } catch (Throwable $error) {
            Log::error('No se pudo anotar el acceso a la ficha médica.', [
                'usuario_id' => $usuario->id,
                'nino_id' => $recursoId,
                'accion' => $accion,
                'resultado' => $resultado,
                'error' => $error->getMessage(),
            ]);
        }
    }
}