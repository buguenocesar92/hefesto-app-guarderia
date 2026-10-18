<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Nino;
use App\Models\Responsable;
use App\Models\Usuario;
use App\Policies\Concerns\RespondeNoEncontrado;

/**
 * Permisos sobre niños y responsables.
 *
 * POR QUÉ LOS ROLES QUE ATIENDEN LA PUERTA PUEDEN LEER TODO ESTO
 * --------------------------------------------------------------
 * Porque atender la puerta ES su trabajo. Quien atiende la puerta tiene que ver
 * al niño y a sus responsables para poder responder, y no hay forma de hacer su
 * turno sin verlos.
 *
 * La información que SÍ está restringida en este dominio es la ficha médica, y
 * esa tiene su propia Policy. Ver `FichaMedicaPolicy`.
 *
 * LO QUE ESTOS MODELOS NO CONTIENEN
 * ---------------------------------
 * Ni `ninos` ni `responsables` tienen dato de salud. Por eso esta Policy no
 * necesita preguntar por el rol para leerlos: no hay nada sensible ahí.
 */
class NinoPolicy
{
    use RespondeNoEncontrado;

    public function ver(Usuario $usuario, Nino $nino): \Illuminate\Auth\Access\Response
    {
        if ($nino->tenant_id !== $usuario->tenant_id) {
            return $this->noEncontrado();
        }

        return \Illuminate\Auth\Access\Response::allow();
    }

    /**
     * Crear un niño.
     *
     * Lo hace la DIRECCIÓN o el ADMINISTRATIVO. Una educadora no matricula a
     * nadie: recibe a los niños que ya están.
     */
    public function crear(Usuario $usuario, string $tenantId): \Illuminate\Auth\Access\Response
    {
        if ($tenantId !== $usuario->tenant_id) {
            return $this->noEncontrado();
        }

        if ($usuario->rol === \App\Enums\RolGuarderia::Educadora) {
            return $this->noEncontrado(
                'El rol Educadora no matricula niños. El alta y la matrícula son de administración y dirección.',
            );
        }

        return \Illuminate\Auth\Access\Response::allow();
    }

    public function actualizar(Usuario $usuario, Nino $nino): \Illuminate\Auth\Access\Response
    {
        if ($nino->tenant_id !== $usuario->tenant_id) {
            return $this->noEncontrado();
        }

        if ($usuario->rol === \App\Enums\RolGuarderia::Educadora) {
            return $this->noEncontrado(
                'El rol Educadora no modifica la ficha del niño. Los cambios de datos son de administración y dirección.',
            );
        }

        return \Illuminate\Auth\Access\Response::allow();
    }
}