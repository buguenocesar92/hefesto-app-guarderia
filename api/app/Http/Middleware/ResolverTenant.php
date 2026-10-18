<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Usuario;
use App\Services\Tenancy\ContextoTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Envuelve la petición en una transacción con la guardería ya fijada.
 *
 * POR QUÉ UNA TRANSACCIÓN Y NO UN `set_config` SUELTO
 * --------------------------------------------------
 * Porque `set_config(..., true)` tiene alcance de TRANSACCIÓN. Si se llamara
 * fuera de una transacción, el valor se perdería al terminar la siguiente, y la
 * petición se quedaría sin contexto a mitad de camino.
 *
 * Y si se llamara con alcance de SESIÓN —el otro valor posible—, el valor
 * sobreviviría al cierre de la transacción y la petición siguiente leería filas
 * de la guardería anterior. Ver `App\Services\Tenancy\ContextoTenant`.
 *
 * EL ORDEN DE LAS TRES COSAS
 * --------------------------
 * 1. Se resuelve la sesión para saber QUIÉN es.
 * 2. Se resuelve la guardería desde el token de sesión.
 * 3. Se abre la transacción con el contexto fijado.
 *
 * La guardería se saca de la sesión y no de una variable de entorno, porque en
 * una instalación compartida por varias guarderías el `APP_GUARDERIA_ID` sería
 * uno solo y todas las guarderías se mezclarían.
 */
class ResolverTenant
{
    public function __construct(private readonly ContextoTenant $contexto) {}

    public function handle(Request $peticion, Closure $siguiente): Response
    {
        // 1. ¿Quién es?
        $sub = $peticion->session()->get('usuario_sub');
        $guarderiaId = $peticion->session()->get('guarderia_id');

        // Las rutas públicas de la API y las de ingreso no tienen sesión, y no
        // la necesitan. Se dejan pasar sin contexto.
        if ($sub === null || $guarderiaId === null) {
            return $siguiente($peticion);
        }

        // 2. El usuario tiene que estar activo y ser de esa guardería. Si
        //    desactivaron a la persona mientras tenía la sesión abierta, esta
        //    petición es la primera en enterarse.
        $usuario = Usuario::query()
            ->where('tenant_id', $guarderiaId)
            ->where('sub', $sub)
            ->first();

        if ($usuario === null || ! $usuario->activo) {
            $peticion->session()->forget('usuario_sub');
            $peticion->session()->forget('guarderia_id');
            $peticion->session()->forget('capacidades');

            return response()->json([
                'mensaje' => 'La sesión ya no es válida. Vuelva a ingresar.',
            ], 401);
        }

        // La sesión guarda el rol con el que entró la persona, para que la
        // interfaz pueda pintar los menús sin una consulta extra. El permiso de
        // verdad lo vuelve a preguntar la Policy en cada ruta, contra el rol que
        // está en la base ahora.
        $peticion->session()->put('rol', $usuario->rol->value);
        $peticion->session()->put('nombre_usuario', $usuario->nombreCompleto());

        $peticion->attributes->set('usuario', $usuario);

        // 3. Todo lo que viene ahora corre con el contexto fijado.
        return $this->contexto->enTransaccionDe($guarderiaId, function () use ($peticion, $siguiente, $usuario): Response {
            DB::select('SELECT set_config(\'app.guarderia_id\', ?, true)', [$usuario->guarderia_id]);

            $respuesta = $siguiente($peticion);

            $usuario->forceFill(['ultimo_ingreso_en' => now()])->saveQuietly();

            return $respuesta;
        });
    }
}