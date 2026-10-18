<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use Illuminate\Auth\Access\Response;

/**
 * Traduce un rechazo de Policy a 404.
 *
 * POR QUÉ 404 Y NO 403
 * --------------------
 * Un 403 dice "esto existe y tú no puedes". Un 404 dice "esto no existe".
 *
 * En una guardería esa diferencia importa de verdad, y no por buena práctica:
 *
 *   La ficha médica de un niño, la autorización de un responsable y el
 *   registro de retiro de un menor son datos que existen. Si alguien que no
 *   debería verlos recibe un 403, aprende que ese niño existe, que esa ficha
 *   tiene contenido y que hay una autorización. Un 404 no enseña nada de eso.
 *
 *   En el registro de retiros, el 403 revelaría además que hubo un intento de
 *   retiro que fue denegado, y a quién le correspondía. Esa es exactamente la
 *   información que el registro guarda para proteger al menor, y no para
 *   mostrársela a quien preguntó.
 *
 * `bootstrap/app.php` traduce `AuthorizationException` a 404 en las rutas de
 * la API. Este trait es la mitad del contrato: deja escrito en cada Policy por
 * qué se responde de esa forma, y da un motivo que la interfaz puede mostrar
 * cuando QUIERE mostrarlo.
 *
 * CUANDO SÍ SE MUESTRA EL MOTIVO
 * -----------------------------
 * Cuando la pantalla se le muestra a alguien que de verdad tiene derecho a
 * saber por qué. Una administrativa que pide un pago y no tiene permiso ve un
 * motivo; una pegada en la puerta ve un 404 sin explicación. Ver docs/03.
 */
trait RespondeNoEncontrado
{
    protected function noEncontrado(?string $motivo = null): Response
    {
        return Response::denyWithStatus(404, $motivo);
    }
}