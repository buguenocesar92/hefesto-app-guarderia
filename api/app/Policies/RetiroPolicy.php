<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Incidencia;
use App\Models\Retiro;
use App\Models\Usuario;
use App\Policies\Concerns\RespondeNoEncontrado;

/**
 * Permisos sobre el registro de retiros.
 *
 * LEER EL REGISTRO COMPLETO ES DE DIRECCIÓN
 * -----------------------------------------
 * `Educadora` y `Administrativo` ven el historial de retiros de un niño, que es
 * lo que necesitan para atender la puerta. El REGISTRO COMPLETO de la
 * guardería, con los intentos rechazados de todos los niños, es de dirección.
 *
 * LA RAZÓN
 * --------
 * Un registro de intentos rechazados dice a qué familias se les ha rechazado
 * retirar a su niño. Eso es información delicada sobre la relación del centro
 * con cada familia, y no tiene que estar al alcance de quien atiende el turno.
 *
 * Por eso hay dos Methods distintos: `verDelNino` para el historial de un niño
 * concreto, y `verBitacora` para el registro de la guardería.
 */
class RetiroPolicy
{
    use RespondeNoEncontrado;

    /**
     * Ver el historial de retiros de un niño.
     */
    public function verDelNino(Usuario $usuario, string $tenantId): \Illuminate\Auth\Access\Response
    {
        if ($tenantId !== $usuario->tenant_id) {
            return $this->noEncontrado();
        }

        return \Illuminate\Auth\Access\Response::allow();
    }

    /**
     * Registrar un retiro.
     *
     * Todos los que operan la puerta. El PERMISO no dice si el retiro va a
     * autorizarse: eso lo decide la autorización, y es otra cosa.
     */
    public function registrar(Usuario $usuario, string $tenantId): \Illuminate\Auth\Access\Response
    {
        if ($tenantId !== $usuario->tenant_id) {
            return $this->noEncontrado();
        }

        if (! $usuario->rol->operaPuertas()) {
            return $this->noEncontrado('Este rol no registra retiros.');
        }

        return \Illuminate\Auth\Access\Response::allow();
    }

    /**
     * Ver la bitácora de retiros de toda la guardería, con los rechazados.
     */
    public function verBitacora(Usuario $usuario, string $tenantId): \Illuminate\Auth\Access\Response
    {
        if ($tenantId !== $usuario->tenant_id) {
            return $this->noEncontrado();
        }

        if ($usuario->rol !== \App\Enums\RolGuarderia::Direccion) {
            return $this->noEncontrado(
                'La bitácora completa de retiros, con los intentos rechazados de todos los niños, es para la dirección del centro.',
            );
        }

        return \Illuminate\Auth\Access\Response::allow();
    }
}