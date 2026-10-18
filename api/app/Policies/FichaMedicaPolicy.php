<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FichaMedica;
use App\Models\Usuario;
use App\Policies\Concerns\RespondeNoEncontrado;
use App\Services\Permisos\CapacidadesDeUsuario;

/**
 * La Policy más importante de esta aplicación después de la de retiro.
 *
 * LA FICHA MÉDICA ES DATO DE SALUD DE UN MENOR
 * ------------------------------------------
 * Es el dato más sensible que hay acá, y por eso esta Policy tiene dos capas:
 * el ROL decide, y la GUARDERÍA también.
 *
 * EL ROL DECIDE CON `match`, NO CON UN CAMPO
 * ------------------------------------------
 * No existe un `ve_ficha_medica` en ninguna tabla. El permiso sale del rol, en
 * `RolGuarderia::abreFichasMedicas()`.
 *
 * Eso no es purismo. Un campo booleano por usuario es un campo que alguien
 * puede cambiar sin querer, que nadie recuerda auditar y que un día vale `true`
 * para una administrativa porque alguien lo probó. Con roles escalonados, el
 * permiso es una consecuencia del rol y se puede razonar sin mirar ninguna
 * fila.
 *
 * POR QUÉ `Administrativo` NO ENTRA
 * ---------------------------------
 * Porque ve la asistencia, los retiros, las autorizaciones y los pagos: es
 * quien atiende el teléfono y cobra. Eso es su trabajo.
 *
 * Y las alergias y la medicación de un menor NO son su trabajo. No es que no
 * "pueda saber" si el niño es alérgico: es que la ficha médica no le
 * corresponde, y el sistema lo hace explícito en vez de esconderlo.
 */
class FichaMedicaPolicy
{
    use RespondeNoEncontrado;

    /**
     * Ver la ficha de un niño.
     */
    public function ver(Usuario $usuario, FichaMedica $ficha): \Illuminate\Auth\Access\Response
    {
        // PRIMERO el inquilino. La fila puede estar en la base y ser de otra
        // guardería; en ese caso la respuesta es 404 igual, pero por una razón
        // distinta y la comprobación no depende del rol.
        if ($ficha->tenant_id !== $usuario->tenant_id) {
            return $this->noEncontrado();
        }

        $capacidades = CapacidadesDeUsuario::de($usuario);

        if (! $capacidades->abreFichasMedicas()) {
            return $this->noEncontrado(
                'El rol ' . $usuario->rol->etiqueta() . ' no abre fichas médicas. '
                . $usuario->rol->accesoAFichaMedica(),
            );
        }

        return \Illuminate\Auth\Access\Response::allow();
    }

    /**
     * Crear la ficha de un niño.
     *
     * Mismo criterio que ver: si no se puede ver, no se puede crear.
     */
    public function crear(Usuario $usuario, string $tenantId): \Illuminate\Auth\Access\Response
    {
        if ($tenantId !== $usuario->tenant_id) {
            return $this->noEncontrado();
        }

        if (! CapacidadesDeUsuario::de($usuario)->abreFichasMedicas()) {
            return $this->noEncontrado(
                'El rol ' . $usuario->rol->etiqueta() . ' no abre fichas médicas. '
                . $usuario->rol->accesoAFichaMedica(),
            );
        }

        return \Illuminate\Auth\Access\Response::allow();
    }

    /**
     * Guardar cambios en la ficha.
     *
     * Ver y guardar son el mismo permiso acá, y no por simplicidad: guardar una
     * ficha médica es escribir sobre dato de salud de un menor, y quien puede
     * escribir es quien tiene que poder leerla.
     */
    public function actualizar(Usuario $usuario, FichaMedica $ficha): \Illuminate\Auth\Access\Response
    {
        return $this->ver($usuario, $ficha);
    }
}