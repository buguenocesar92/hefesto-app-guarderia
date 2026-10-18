<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AutorizacionRetiro;
use App\Models\Usuario;
use App\Policies\Concerns\RespondeNoEncontrado;
use App\Services\Autorizaciones\EvaluadorDeAutorizacion;

/**
 * Permisos sobre las autorizaciones de retiro.
 *
 * LA REGLA QUE RESUELVE ESTA APLICACIÓN
 * -------------------------------------
 * Solo un responsable AUTORIZADO puede retirar a un niño. Eso no lo resuelve
 * esta Policy: lo resuelve `App\Services\Retiros\ServicioRetiro`, en el
 * registro del retiro.
 *
 * Esta Policy responde otra pregunta, distinta y complementaria: QUIÉN PUEDE
 * MODIFICAR la lista de autorizados. Y esa pregunta también tiene una sola
 * respuesta útil.
 *
 * DANDO DE ALTA UN AUTORIZADO ES DANDO CONTROL SOBRE UN MENOR
 * ---------------------------------------------------------
 * Quien autoriza a una persona a retirar a un niño le está dando, en la
 * práctica, acceso a ese niño. Por eso la alta y la renovación son de
 * DIRECCIÓN, y no de la persona que atiende el teléfono.
 *
 * `Educadora` y `Administrativo` pueden LEER las autorizaciones —lo necesitan
 * para explicar un rechazo en la puerta— pero no pueden crearlas. La lectura no
 * expone dato de salud: expone nombre de responsable, documento y fecha, que es
 * justo lo que hay que poder mostrar para responder una pregunta.
 */
class AutorizacionPolicy
{
    use RespondeNoEncontrado;

    public function ver(Usuario $usuario, AutorizacionRetiro $autorizacion): \Illuminate\Auth\Access\Response
    {
        if ($autorizacion->tenant_id !== $usuario->tenant_id) {
            return $this->noEncontrado();
        }

        return \Illuminate\Auth\Access\Response::allow();
    }

    /**
     * Registrar una autorización nueva, o renovar una vencida.
     *
     * Es de DIRECCIÓN, y no por desconfianza: es la única forma de que quede
     * anotado QUIÉN decidió que esa persona podía llevarse a ese niño.
     */
    public function crear(Usuario $usuario, string $tenantId): \Illuminate\Auth\Access\Response
    {
        if ($tenantId !== $usuario->tenant_id) {
            return $this->noEncontrado();
        }

        if ($usuario->rol !== \App\Enums\RolGuarderia::Direccion) {
            return $this->noEncontrado(
                'Solo la dirección del centro puede registrar una autorización de retiro. '
                . 'Quien atiende el teléfono puede verlas para responder una pregunta en la puerta, pero no puede darlas de alta.',
            );
        }

        return \Illuminate\Auth\Access\Response::allow();
    }

    /**
     * Anular una autorización.
     *
     * Anular, no borrar. Una autorización anulada conserva su fila y su motivo,
     * y el rechazo posterior puede explicar exactamente qué pasó.
     */
    public function anular(Usuario $usuario, AutorizacionRetiro $autorizacion): \Illuminate\Auth\Access\Response
    {
        if ($autorizacion->tenant_id !== $usuario->tenant_id) {
            return $this->noEncontrado();
        }

        if ($usuario->rol !== \App\Enums\RolGuarderia::Direccion) {
            return $this->noEncontrado(
                'Solo la dirección del centro puede anular una autorización de retiro.',
            );
        }

        return \Illuminate\Auth\Access\Response::allow();
    }

    /**
     * Ver el estado real de la autorización en la fecha de hoy.
     *
     * Se separa del permiso a propósito: el permiso es de rol, el estado es de
     * fecha. Que el rol del usuario no cambie la vigencia de un documento.
     */
    public function estadoEfectivo(Usuario $usuario, AutorizacionRetiro $autorizacion): EstadoAutorizacionLectura
    {
        if ($autorizacion->tenant_id !== $usuario->tenant_id) {
            return EstadoAutorizacionLectura::NoEncontrada;
        }

        return EstadoAutorizacionLectura::desde(
            app(EvaluadorDeAutorizacion::class)->estadoEn($autorizacion),
        );
    }
}