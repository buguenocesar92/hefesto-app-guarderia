<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rol de la persona dentro de la guardería.
 *
 * LA DIFERENCIA QUE DEFINE EL PRODUCTO
 * ------------------------------------
 * Los tres roles están escalonados, y cada escalón PUEDE y NO PUEDE. No es
 * que uno tenga menos permisos que otro: es que cada uno tiene un trabajo
 * distinto, y el de un rol no incluye el del siguiente.
 *
 *   Educadora    Necesita ver las alergias para servir el almuerzo. Ve la ficha
 *                médica. NO ve los pagos: no es su trabajo y el dato no le
 *                sirve para nada.
 *
 *   Administrativo  Ve la asistencia, los retiros, las autorizaciones y los
 *                pagos: es quien atiende el teléfono y cobra. NO ve la ficha
 *                médica. No es que "no pueda saber si el niño es alérgico",
 *                es que la ficha médica no le corresponde.
 *
 *   Dirección     Administra el centro. Es la responsable legal del cuidado de
 *                los menores, y por eso sí abre la ficha médica. También es la
 *                única que ve la bitácora de accesos.
 *
 * EL PUNTO QUE NO SE NEGOCIA
 * --------------------------
 * `Administrativo` NO tiene un campo booleano que le diga "ve ficha médica" o
 * "no ve ficha médica". El permiso sale del rol, con `match`, y no de un campo
 * que alguien pueda cambiar en una fila. Ver `App\Services\Permisos\
 * CapacidadesDeUsuario` y docs/03.
 */
enum RolGuarderia: string
{
    /** Atende a los niños. Ve ficha médica. No ve pagos. */
    case Educadora = 'educadora';

    /** Asistencia, retiros, autorizaciones y pagos. No ve ficha médica. */
    case Administrativo = 'administrativo';

    /** Dirección del centro. Administra, abre ficha médica y ve la bitácora. */
    case Direccion = 'direccion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Educadora => 'Educadora',
            self::Administrativo => 'Administrativo',
            self::Direccion => 'Dirección',
        };
    }

    /**
     * Qué hace este rol con la ficha médica de un menor.
     *
     * Se devuelve como texto para que la interfaz lo muestre. La idea es que la
     * pantalla diga por qué NO se ve algo, y no solo que no se ve. Una pantalla
     * vacía sin explicación hace que la persona piense que el sistema falló.
     */
    public function accesoAFichaMedica(): string
    {
        return match ($this) {
            self::Educadora => 'Puede abrir la ficha médica de los niños: necesita conocer las alergias y la indicación de alimentación antes de servir el almuerzo.',
            self::Administrativo => 'No puede abrir la ficha médica. Ve la asistencia, los retiros y los pagos, que es su trabajo; las alergias y la medicación de un menor no le corresponden.',
            self::Direccion => 'Puede abrir la ficha médica de cualquier niño del centro. La dirección responde por el cuidado de los menores.',
        };
    }

    /**
     * Si este rol abre fichas médicas, en general.
     *
     * Esto NO reemplaza a la Policy: la Policy decide sobre un niño concreto.
     * Esto es lo que la interfaz usa para no ofrecer una pantalla que va a dar
     * 404.
     */
    public function abreFichasMedicas(): bool
    {
        return match ($this) {
            self::Educadora, self::Direccion => true,
            self::Administrativo => false,
        };
    }

    /**
     * Si este rol ve el dinero del centro.
     */
    public function vePagos(): bool
    {
        return match ($this) {
            self::Educadora => false,
            self::Administrativo, self::Direccion => true,
        };
    }

    /**
     * Si este rol registra ingresos, salidas y retiros.
     */
    public function operaPuertas(): bool
    {
        return match ($this) {
            self::Educadora, self::Administrativo, self::Direccion => true,
        };
    }

    /**
     * Equipo en Authentik que otorga este rol.
     *
     * El equipo trae el nombre del producto porque Authentik es compartido por
     * todas las instalaciones de Hefesto. Sin el nombre, una educadora de una
     * guardería sería educadora en todas.
     *
     * La comparación en el ingreso es insensible a mayúsculas: Authentik
     * entrega los equipos tal como se escribieron en su consola.
     */
    public function equipoAuthentik(string $grupo = 'Guarderia'): string
    {
        return match ($this) {
            self::Educadora => 'Educadoras de ' . $grupo,
            self::Administrativo => 'Administrativos de ' . $grupo,
            self::Direccion => 'Direccion de ' . $grupo,
        };
    }
}