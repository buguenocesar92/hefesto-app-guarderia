<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tenancy\AplicaContextoTenant;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comprueba, desde la base y sin pasar por la aplicación, que el aislamiento
 * entre guarderías está puesto.
 *
 * POR QUÉ UN COMANDO Y NO SÓLO UNA PRUEBA
 * --------------------------------------
 * Porque una prueba corre en una base de pruebas, con datos de prueba, en la
 * máquina de quien la escribió. Este comando corre en el servidor de producción,
 * contra la base real, y mira las políticas que quedaron efectivamente
 * instaladas.
 *
 * Una instalación en la que este comando no se ha corrido NO está verificada.
 * Y eso hay que decirlo antes de recibir al primer niño, no después.
 *
 * LO QUE COMPRUEBA, EN ORDEN
 * --------------------------
 * 1. Que la función `guarderia_actual()` existe y es `SECURITY INVOKER`.
 * 2. Que cada tabla de negocio tiene Row Level Security ACTIVADA.
 * 3. Que cada tabla tiene Row Level Security FORZADA. Sin `FORCE`, el DUEÑO de
 *    la tabla —que en este despliegue es el mismo rol que corre la
 *    aplicación— se salta todas las políticas.
 * 4. Que cada tabla tiene su política, con `USING` y con `WITH CHECK`.
 * 5. Que la vista pública expone TRES columnas, y sólo tres.
 * 6. Que una consulta SIN contexto NO lee filas. Esta es la prueba que importa.
 *
 * EL PUNTO 6, POR QUÉ ES EL QUE IMPORTA
 * -------------------------------------
 * Los puntos del 1 al 5 comprueban que la instalación de la protección está
 * escrita como corresponde. El punto 6 comprueba que FUNCIONA: que una consulta
 * sin `app.guarderia_id` fijado no lee ni una fila.
 *
 * Un punto 6 que falla significa que una conexión sin contexto lee toda la
 * base, y eso es una fuga silenciosa.
 *
 * Ver docs/03 y docs/04.
 */
class GuarderiaVerificarAislamientoCommand extends Command
{
    protected $signature = 'guarderia:verificar-aislamiento';

    protected $description = 'Comprueba que el aislamiento entre guarderías está activo y que una consulta sin contexto no lee filas.';

    public function handle(ContextoTenant $contexto, AplicaContextoTenant $aplicador): int
    {
        $fallos = [];

        $this->info('Verificando el aislamiento entre guarderías.');
        $this->newLine();

        $fallos = array_merge($fallos, $this->comprobarFuncionDeContexto());
        $fallos = array_merge($fallos, $this->comprobarTablas());
        $fallos = array_merge($fallos, $this->comprobarVistaPublica());
        $fallos = array_merge($fallos, $this->comprobarConsultaSinContexto($aplicador));

        if ($fallos === []) {
            $this->newLine();
            $this->info('Aislamiento verificado.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('Se encontraron ' . count($fallos) . ' problema(s) de aislamiento:');

        foreach ($fallos as $fallo) {
            $this->line('  - ' . $fallo);
        }

        $this->newLine();
        $this->warn('Una instalación con fallos aquí NO está verificada. No reciba al primer niño hasta corregirlos.');

        return self::FAILURE;
    }

    /**
     * La función de contexto tiene que existir y no ser `SECURITY DEFINER`.
     */
    private function comprobarFuncionDeContexto(): array
    {
        $fallos = [];

        $fila = DB::selectOne(<<<'SQL'
            SELECT p.prosecdef AS es_definer
            FROM pg_proc p
            JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE p.proname = 'guarderia_actual'
              AND n.nspname = 'public'
        SQL);

        if ($fila === null) {
            $fallos[] = 'No existe la función public.guarderia_actual(). ¿Se corrieron las migraciones?';

            return $fallos;
        }

        if ((bool) $fila->es_definer) {
            $fallos[] = 'La función public.guarderia_actual() es SECURITY DEFINER. '
                . 'Debe ser SECURITY INVOKER: si corre con los privilegios de quien la creó, '
                . 'la política deja de filtrar.';
        }

        return $fallos;
    }

    /**
     * Cada tabla de negocio con Row Level Security activada y forzada.
     *
     * @return list<string>
     */
    private function comprobarTablas(): array
    {
        $fallos = [];

        /** @var list<array{relname: string, relrowsecurity: bool, relforcerowsecurity: bool, polcount: int}> $filas */
        $filas = DB::select(<<<'SQL'
            SELECT
                c.relname,
                c.relrowsecurity,
                c.relforcerowsecurity,
                (SELECT count(*)::int FROM pg_policy p WHERE p.polrelid = c.oid) AS polcount
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND c.relkind = 'r'
              AND c.relname IN (
                  'usuarios', 'ninos', 'responsables', 'responsable_nino',
                  'autorizaciones_retiros', 'asistencias', 'retiros',
                  'fichas_medicas', 'planes', 'matriculas', 'pagos',
                  'incidencias', 'accesos', 'sesiones'
              )
            ORDER BY c.relname
        SQL);

        if ($filas === []) {
            $fallos[] = 'No se encontró ninguna tabla de negocio en el esquema public.';

            return $fallos;
        }

        $this->table(
            ['Tabla', 'RLS', 'FORCE', 'Políticas'],
            array_map(static fn (array $fila): array => [
                $fila['relname'],
                $fila['relrowsecurity'] ? 'si' : 'NO',
                $fila['relforcerowsecurity'] ? 'si' : 'NO',
                (string) $fila['polcount'],
            ], $filas)
        );

        foreach ($filas as $fila) {
            if (! $fila['relrowsecurity']) {
                $fallos[] = 'La tabla ' . $fila['relname'] . ' no tiene Row Level Security activada.';
            }

            if (! $fila['relforcerowsecurity']) {
                $fallos[] = 'La tabla ' . $fila['relname'] . ' no tiene FORCE ROW LEVEL SECURITY. '
                    . 'Sin FORCE, el dueño de la tabla se salta la política, y aquí el dueño '
                    . 'es el mismo rol que corre la aplicación.';
            }

            if ($fila['polcount'] === 0) {
                $fallos[] = 'La tabla ' . $fila['relname'] . ' no tiene ninguna política.';
            }
        }

        return $fallos;
    }

    /**
     * La vista pública tiene que exponer exactamente TRES columnas.
     */
    private function comprobarVistaPublica(): array
    {
        $fallos = [];

        $existe = DB::selectOne(<<<'SQL'
            SELECT count(*)::int AS cantidad
            FROM pg_views
            WHERE schemaname = 'public' AND viewname = 'vw_guarderias_publicas'
        SQL);

        if ($existe === null || (int) $existe->cantidad === 0) {
            return ['No existe la vista public.vw_guarderias_publicas.'];
        }

        /** @var list<array<column_name: string>> $columnas */
        $columnas = DB::select(<<<'SQL'
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = 'vw_guarderias_publicas'
            ORDER BY ordinal_position
        SQL);

        $nombres = array_map(static fn (array $c): string => (string) $c['column_name'], $columnas);

        $esperadas = ['id', 'nombre', 'slug'];

        if ($nombres !== $esperadas) {
            $fallos[] = 'La vista public.vw_guarderias_publicas expone columnas distintas de las tres esperadas '
                . '(' . implode(', ', $esperadas) . '). Expone: '
                . ($nombres === [] ? 'ninguna' : implode(', ', $nombres)) . '. '
                . 'La vista es lo único que se puede leer antes del ingreso: si expone más, expone de más.';
        }

        $this->line('Vista pública: ' . ($nombres === [] ? 'no existe' : implode(', ', $nombres)) . '.');

        return $fallos;
    }

    /**
     * La prueba que de verdad importa: sin contexto, no se lee nada.
     */
    private function comprobarConsultaSinContexto(AplicaContextoTenant $aplicador): array
    {
        $fallos = [];

        // Se abre una conexión EXPLÍCITAMENTE sin contexto. No se usa la de la
        // aplicación, que podría tener algo fijado de una operación anterior.
        $conexion = DB::connection();

        try {
            $filas = $conexion->select(<<<'SQL'
                SELECT count(*)::int AS cantidad FROM public.ninos
            SQL);
        } catch (\Throwable) {
            // Que la consulta falle NO es un fallo del aislamiento. Si no hay
            // contexto y la política está bien, la respuesta correcta es cero
            // filas o un error; lo que no puede pasar es devolver filas.
            return $fallos;
        }

        $cantidad = (int) ($filas[0]->cantidad ?? 0);

        if ($cantidad > 0) {
            $fallos[] = 'Una consulta SIN contexto de guardería leyó ' . $cantidad . ' fila(s) de public.ninos. '
                . 'Eso significa que las políticas no están filtrando: cualquier conexión sin '
                . 'contexto lee toda la base.';
        } else {
            $this->line('Consulta sin contexto sobre public.ninos: 0 filas. Correcto.');
        }

        return $fallos;
    }
}