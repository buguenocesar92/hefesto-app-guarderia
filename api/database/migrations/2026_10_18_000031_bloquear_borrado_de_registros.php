<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lo que no se puede borrar,impuesto por la base y no por la aplicación.
 *
 * LA REGLA
 * --------
 * Cuatro tablas de esta aplicación no admiten `DELETE`: `retiros`,
 * `autorizaciones_retiros`, `incidencias` y `accesos`.
 *
 * Y no es que la aplicación no tenga ruta de borrado. Es que la base lo
 * rechaza, aunque alguien escriba con un cliente de SQL.
 *
 * POR QUÉ EN LA BASE Y NO EN EL SERVICIO
 * --------------------------------------
 * Porque un servicio se puede saltar: una llave ajena, un script de migración,
 * una consola de administración. Un disparador no.
 *
 * Esto importa de verdad acá y no por purismo. Un registro de retiro es la
 * respuesta a "quién se llevó a este niño y a qué hora", y esa pregunta se hace
 * meses después de un incidente. Una incidencia es lo que sostiene una
 * conversación difícil con la familia. La bitácora dice quién abrió la ficha
 * médica de un menor. Y una autorización borrada es una autorización que ya no
 * se puede citar.
 *
 * Si todo eso se puede borrar, el sistema registra cosas que no sirven para
 * nada.
 *
 * LO QUE SÍ SE PUEDE HACER
 * ------------------------
 * Anular una autorización, con motivo y con quién anuló. Anular un pago, con
 * motivo y con quién anuló. Marcar una incidencia como atendida. Todo eso deja
 * rastro y no borra nada.
 *
 * LAS FILAS DEL HISTORIAL
 * ----------------------
 * Un permiso de retiro puede tener autorización y esa autorización puede
 * quedar obsoleta. Eso no se borra: se marca como inactiva, con fecha, y sigue
 * siendo consultable.
 */
return new class extends Migration
{
    /**
     * Tablas sin borrado. Cada una tiene su propio mensaje.
     *
     * @var array<string, string>
     */
    private const TABLAS_SIN_BORRADO = [
        'retiros' => 'El registro de retiros no se borra. Un retiro rechazado se conserva justamente porque es el caso que hay que poder reconstruir. Si la informacion estaba mal, se deja el registro y se corrige el dato equivocado.',
        'autorizaciones_retiros' => 'Una autorizacion no se borra. Se anula, escribiendo el motivo y quien la anulo, y el motivo queda a la vista de quien intente retirar al nino.',
        'incidencias' => 'Las incidencias no se borran. Es el registro que sostiene una conversacion con la familia. Si el registro esta equivocado, se corrige y queda anotado que se corrigio.',
        'accesos' => 'La bitacora de accesos no se borra. Si alguien abrio una ficha que no le correspondia, ese intento es informacion: por eso tambien se anotan los denegados.',
    ];

    public function up(): void
    {
        foreach (self::TABLAS_SIN_BORRADO as $tabla => $mensaje) {
            $funcion = $tabla . '_sin_borrado';

            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION public.{$funcion}()
                RETURNS trigger AS \$\$
                BEGIN
                    RAISE EXCEPTION
                        '{$mensaje}'
                        USING ERRCODE = 'restrict_violation',
                              HINT = 'Esta tabla no admite DELETE por diseno del sistema. Ver docs/07.';
                END;
                \$\$ LANGUAGE plpgsql;
            SQL);

            DB::unprepared(<<<SQL
                CREATE TRIGGER {$tabla}_no_se_borra
                BEFORE DELETE ON public.{$tabla}
                FOR EACH ROW
                EXECUTE FUNCTION {$funcion}()
            SQL);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLAS_SIN_BORRADO) as $tabla) {
            DB::unprepared('DROP TRIGGER IF EXISTS ' . $tabla . '_no_se_borra ON public.' . $tabla);
            DB::unprepared('DROP FUNCTION IF EXISTS public.' . $tabla . '_sin_borrado()');
        }
    }
};