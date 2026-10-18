<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Función de contexto y políticas de Row Level Security.
 *
 * EL MECANISMO, EN TRES PASOS
 * --------------------------
 * 1. Una función `SECURITY INVOKER` que lee `current_setting`. Es
 *    `INVOKER` y no `DEFINER` a propósito: si fuera `DEFINER`, la función
 *    correría con los privilegios de quien la creó —el dueño del esquema, con
 *    `BYPASSRLS` si fuera superusuario— y las políticas se volverían
 *    decorativas.
 *
 * 2. Un contexto por conexión, fijado con `set_config('app.guarderia_id', …,
 *    true)`. El `true` es lo que hace que el valor sea de alcance de TRANSACCIÓN.
 *    Ver `App\Services\Tenancy\ContextoTenant`.
 *
 * 3. Una política por tabla, con `USING` Y `WITH CHECK`. El `USING` filtra lo
 *    que se lee y lo que se modifica; el `WITH CHECK` impide INSERTAR filas de
 *    otra guardería. Sin `WITH CHECK`, se puede leer lo propio y escribir lo
 *    ajeno.
 *
 * POR QUÉ `FORCE ROW LEVEL SECURITY`
 * ---------------------------------
 * En PostgreSQL, el dueño de una tabla NO está sujeto a las políticas de su
 * propia tabla. Con laRow Level Security activada y sin `FORCE`, `guarderia_app`
 * —que es el dueño del esquema— leería todas las filas saltándose todas las
 * políticas.
 *
 * Con `FORCE`, el dueño también está sujeto. Dueño pero no superusuario: por eso
 * `guarderia_app` se crea con `NOSUPERUSER` y `NOBYPASSRLS` explícitos. Un
 * superusuario SIEMPRE salta las políticas de Row Level Security, incluso con
 * `FORCE`: por eso el rol de arranque del servidor de base de datos es otro, y
 * por eso la aplicación nunca lo usa.
 *
 * LA FUNCIÓN FALLA CERRADO
 * -----------------------
 * `current_setting('app.guarderia_id', true)` devuelve NULL si no hay contexto.
 * Un casteo a `uuid` de NULL es NULL, y `tenant_id = NULL` no es verdadero: la
 * fila no aparece. Eso es fallar cerrado sin lanzar una excepción, y es lo
 * correcto para una política.
 *
 * La función `guarderia_fallar_sin_contexto()` sí lanza excepción, y es para
 * el camino inverso: cuando una operación NECESITA una guardería y no la tiene.
 * Una conexión de respaldo que se conecta a la base sin ejecutar
 * `set_config` fallaría con un error de autenticación en vez de un "no
 * encontrado". Ver docs/03.
 *
 * ESTA MIGRACIÓN ES IDEMPOTENTE
 * -----------------------------
 * Se puede volver a correr sin romper nada: todas las sentencias son
 * `CREATE OR REPLACE` o `DROP POLICY IF EXISTS`.
 */
return new class extends Migration
{
    /**
     * Tablas de negocio: llevan `tenant_id` y por lo tanto Row Level Security
     * con la política estricta de inquilino.
     *
     * `sesiones` NO está acá: tiene su propia política, y el motivo está
     * escrito en `activarPoliticaDeSesiones()`.
     *
     * @var list<string>
     */
    private const TABLAS_CON_TENANT = [
        'usuarios',
        'ninos',
        'responsables',
        'responsable_nino',
        'autorizaciones_retiros',
        'asistencias',
        'retiros',
        'fichas_medicas',
        'planes',
        'matriculas',
        'pagos',
        'incidencias',
        'accesos',
    ];

    public function up(): void
    {
        $this->crearFuncionDeContexto();
        $this->crearFuncionDeFalloCerrado();
        $this->activarPoliticas();
        $this->activarPoliticaDeSesiones();
    }

    public function down(): void
    {
        foreach (self::TABLAS_CON_TENANT as $tabla) {
            DB::unprepared('DROP POLICY IF EXISTS aislamiento_por_guarderia ON public.' . $tabla);
            DB::unprepared('ALTER TABLE public.' . $tabla . ' NO FORCE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE public.' . $tabla . ' DISABLE ROW LEVEL SECURITY');
        }

        DB::unprepared('DROP POLICY IF EXISTS sesiones_aislamiento ON public.sesiones');
        DB::unprepared('ALTER TABLE public.sesiones NO FORCE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE public.sesiones DISABLE ROW LEVEL SECURITY');

        DB::unprepared('DROP FUNCTION IF EXISTS public.guarderia_actual()');
        DB::unprepared('DROP FUNCTION IF EXISTS public.guarderia_fallar_sin_contexto()');
    }

    /**
     * La función que lee el contexto de la conexión.
     */
    private function crearFuncionDeContexto(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.guarderia_actual()
            RETURNS uuid
            LANGUAGE sql
            STABLE
            SECURITY INVOKER
            AS $$
                SELECT current_setting('app.guarderia_id', true)::uuid
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON FUNCTION public.guarderia_actual() IS
            'Devuelve el identificador de guarderia de la transaccion en curso, o NULL si no hay contexto. Con NULL, tenant_id = NULL no es verdadero y la politica no deja pasar ninguna fila: fallo cerrado.';
        SQL);
    }

    /**
     * La función que falla cerrado con excepción.
     *
     * Para el camino inverso al de `guarderia_actual()`: cuando una operación
     * NECESITA una guardería y no la tiene. Sin esta función, el error que
     * aparecería sería un 401 o un "no encontrado" que no explica nada.
     */
    private function crearFuncionDeFalloCerrado(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.guarderia_fallar_sin_contexto()
            RETURNS uuid
            LANGUAGE plpgsql
            STABLE
            SECURITY INVOKER
            AS $$
            DECLARE
                contexto uuid;
            BEGIN
                contexto := current_setting('app.guarderia_id', true)::uuid;

                IF contexto IS NULL THEN
                    RAISE EXCEPTION
                        'No hay contexto de guarderia en esta transaccion (app.guarderia_id). La operacion se rechaza en vez de ejecutarse sobre todas las guarderias.'
                        USING ERRCODE = '42501';
                END IF;

                RETURN contexto;
            END;
            $$;
        SQL);
    }

    /**
     * Activa Row Level Security con `FORCE` y crea la política de cada tabla.
     */
    private function activarPoliticas(): void
    {
        foreach (self::TABLAS_CON_TENANT as $tabla) {
            // Se elimina la política antes por si la migración se vuelve a correr
            // sobre una base que ya la tiene.
            DB::unprepared('DROP POLICY IF EXISTS aislamiento_por_guarderia ON public.' . $tabla);

            DB::unprepared('ALTER TABLE public.' . $tabla . ' ENABLE ROW LEVEL SECURITY');

            // `FORCE` sujeta al DUEÑO de la tabla a la política. Sin esto, el
            // dueño —`guarderia_app`— leería todas las filas.
            DB::unprepared('ALTER TABLE public.' . $tabla . ' FORCE ROW LEVEL SECURITY');

            // Política ESTRICTA. Sin la cláusula `tenant_id IS NULL OR ...`: esa
            // cláusula dejaría ver las filas sin inquilino dentro de una
            // guardería cualquiera, y una fila sin inquilino es una fila que no
            // se sabe de quién es.
            DB::unprepared(<<<SQL
                CREATE POLICY aislamiento_por_guarderia
                ON public.{$tabla}
                FOR ALL
                TO PUBLIC
                USING (tenant_id = public.guarderia_actual())
                WITH CHECK (tenant_id = public.guarderia_actual())
            SQL);
        }
    }

    /**
     * La política de `sesiones`, que es distinta y tiene que serlo.
     *
     * EL PROBLEMA QUE RESUELVE
     * ------------------------
     * `sesiones` se lee ANTES de que exista contexto: es la lectura que
     * RESUELVE el contexto. La fila de sesión dice a qué guardería pertenece la
     * persona, y con eso se fija `app.guarderia_id` para todo lo demás.
     *
     * Si `sesiones` usara la política estricta, el orden sería imposible: la
     * primera lectura de la petición no encontraría su propia fila de sesión,
     * porque `guarderia_actual()` todavía no está fijado y devuelve NULL.
     *
     * LO QUE SE ADMITE, Y LO QUE NO
     * -----------------------------
     * Se permite leer una fila de sesión CUANDO NO HAY CONTEXTO. Con contexto ya
     * fijado, vuelve a la política estricta: una guardería establecida no ve
     * sesiones de otra.
     *
     * El riesgo residual es real y está anotado: quien tenga un `id` de sesión
     * ajeno puede leer esa fila. Un `id` de sesión es un identificador aleatorio
     * de 40 caracteres, es la misma cookie que ya debe presentar para usarla, y
     * la fila contiene el sujeto de Authentik, no datos de menores. Está
     * anotado en docs/03, sección "Riesgos que siguen abiertos".
     *
     * Lo que NO se permite nunca es escribir una sesión de otra guardería desde
     * una guardería ya establecida.
     */
    private function activarPoliticaDeSesiones(): void
    {
        DB::unprepared('DROP POLICY IF EXISTS sesiones_aislamiento ON public.sesiones');

        DB::unprepared('ALTER TABLE public.sesiones ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE public.sesiones FORCE ROW LEVEL SECURITY');

        DB::unprepared(<<<'SQL'
            CREATE POLICY sesiones_aislamiento
            ON public.sesiones
            FOR ALL
            TO PUBLIC
            USING (
                public.guarderia_actual() IS NULL
                OR tenant_id IS NULL
                OR tenant_id = public.guarderia_actual()
            )
            WITH CHECK (
                public.guarderia_actual() IS NULL
                OR tenant_id IS NULL
                OR tenant_id = public.guarderia_actual()
            )
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE public.sesiones IS
            'Politica distinta a la del resto de las tablas: se lee ANTES de que exista contexto, porque es la lectura que resuelve el contexto. Sin contexto se puede leer; con contexto, la politica estricta se aplica otra vez. Ver docs/03, "Riesgos que siguen abiertos".';
        SQL);
    }
};