<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\RolGuarderia;
use App\Models\Usuario;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja pasar sólo a la dirección del centro.
 *
 * PARA QUÉ, SI YA HAY POLICIES
 * ---------------------------
 * Para los caminos que no pasan por un modelo: la bitácora de accesos y la
 * lista de usuarios del centro.
 *
 * Una `Policy` decide sobre una fila. Estas dos rutas son tablas completas de
 * la guardería, sin un `$modelo` al que preguntarle. Acá se comprueba el rol
 * explícitamente, y el motivo del rechazo se escribe en el mensaje para que la
 * interfaz pueda explicarlo en vez de mostrar un vacío.
 *
 * EL ROL, NO EL PERMISO EN SESIÓN
 * ------------------------------
 * Se compara contra el rol leído de la base, no contra lo que quedó guardado en
 * la sesión. Si alguien cambió de rol y la sesión sigue abierta, el cambio tiene
 * que notarse en la petición siguiente.
 */
class SoloDireccion
{
    public function handle(Request $peticion, Closure $siguiente): Response
    {
        $usuario = $peticion->attributes->get('usuario');

        if (! $usuario instanceof Usuario || $usuario->rol !== RolGuarderia::Direccion) {
            return response()->json([
                'mensaje' => 'No encontrado.',
                'detalle' => 'Esta pantalla es para la dirección del centro. Consulte a quien dirija el centro si necesita ver esta información.',
            ], 404);
        }

        return $siguiente($peticion);
    }
}