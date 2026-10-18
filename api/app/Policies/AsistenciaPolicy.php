<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EstadoAsistencia;
use App\Enums\RolGuarderia;
use App\Models\Asistencia;
use App\Models\Usuario;
use App\Policies\Concerns\RespondeNoEncontrado;

/**
 * Permisos sobre la asistencia.
 *
 * LOS TRES ROLES OPERAN
 * --------------------
 * Educadora, administrativo y dirección registran ingresos y salidas. Es la
 * puerta: tiene que poder abrirla quien esté ahí, sin importar el turno.
 *
 * LO QUE ES DE DIRECCIÓN
 * ---------------------
 * MARCAR UNA AUSENCIA CON MOTIVO, y sobre todo la ausencia sin aviso. Una
 * ausencia es una afirmación sobre el niño y sobre su familia, y conviene que
 * quede escrita por alguien que responde por el centro.
 *
 * Corregir una asistencia YA CERRADA es de dirección, por la misma razón que
 * los registros de retiro no se editan: la primera versión de un hecho es la
 * que se cita.
 */
class AsistenciaPolicy
{
    use RespondeNoEncontrado;

    public function ver(Usuario $usuario, Asistencia $asistencia): \Illuminate\Auth\Access\Response
    {
        if ($asistencia->tenant_id !== $usuario->tenant_id) {
            return $this->noEncontrado();
        }

        return \Illuminate\Auth\Access\Response::allow();
    }

    /**
     * Registrar ingreso y salida.
     */
    public function registrar(Usuario $usuario, string $tenantId): \Illuminate\Auth\Access\Response
    {
        if ($tenantId !== $usuario->tenant_id) {
            return $this->noEncontrado();
        }

        if (! $usuario->rol->operaPuertas()) {
            return $this->noEncontrado('Este rol no registra ingresos ni salidas.');
        }

        return \Illuminate\Auth\Access\Response::allow();
    }

    /**
     * Marcar una ausencia.
     *
     * Exige un motivo, y el motivo lo pone quien escribe. "No vino" sin motivo
     * no es un dato, es una casilla.
     */
    public function marcarAusencia(Usuario $usuario, string $tenantId): \Illuminate\Auth\Access\Response
    {
        if ($tenantId !== $usuario->tenant_id) {
            return $this->noEncontrado();
        }

        if ($usuario->rol !== RolGuarderia::Direccion) {
            return $this->noEncontrado(
                'Marcar una ausencia es de la dirección del centro. '
                . 'Si el niño no llegó y nadie avisó, avise a dirección y registre la ausencia desde ahí.',
            );
        }

        return \Illuminate\Auth\Access\Response::allow();
    }

    /**
     * Corregir una asistencia que ya tiene salida.
     */
    public function corregirCierre(Usuario $usuario, Asistencia $asistencia): \Illuminate\Auth\Access\Response
    {
        if ($asistencia->tenant_id !== $usuario->tenant_id) {
            return $this->noEncontrado();
        }

        if ($asistencia->estado === EstadoAsistencia::Presente) {
            return \Illuminate\Auth\Access\Response::allow();
        }

        if ($usuario->rol !== RolGuarderia::Direccion) {
            return $this->noEncontrado(
                'Una asistencia ya cerrada solo la corrige la dirección del centro. '
                . 'La primera versión de un hecho es la que se cita después.',
            );
        }

        return \Illuminate\Auth\Access\Response::allow();
    }
}