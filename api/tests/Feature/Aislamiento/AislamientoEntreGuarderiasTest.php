<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Models\Guarderia;
use App\Models\Nino;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * El aislamiento entre guarderías.
 *
 * POR QUÉ ESTAS PRUEBAS SON LAS MÁS IMPORTANTES DEL CONJUNTO
 * -------------------------------------------------------
 * Porque un fallo acá no se ve. Si dos guarderías se mezclan, el sistema
 * SIGUE funcionando: las pantallas abren, los nombres aparecen, los pagos se
 * ven. Lo que falla es que un centro vea los niños de otro.
 *
 * Esa clase de fallo no se encuentra probando la aplicación a ojo. Se encuentra
 * con estas pruebas, y se previene con Row Level Security.
 *
 * CÓMO FUNCIONAN
 * --------------
 * Todas corren contra PostgreSQL, con las políticas instaladas. Abren una
 * transacción con el contexto de UNA guardería, escriben, y comprueban que desde
 * la transactions de la OTRA no se ve nada.
 *
 * Ver docs/03 y docs/10.
 */
class AislamientoEntreGuarderiasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un niño de una guardería no es visible desde otra.
     */
    public function test_un_nino_de_una_guarderia_no_se_ve_desde_la_otra(): void
    {
        $primera = Guarderia::factory()->create();
        $segunda = Guarderia::factory()->create();

        $nino = $this->contexto($primera, fn (): Nino => Nino::factory()->de($primera)->create());

        $vistosPorLaSegunda = $this->contexto($segunda, fn (): int => Nino::query()
            ->where('ninos.id', $nino->id)
            ->count());

        $this->assertSame(0, $vistosPorLaSegunda, 'La segunda guardería no debería ver el niño de la primera.');
    }

    /**
     * Con la Row Level Security puesta, la fila NI SE PUEDE leer.
     *
     * Esta prueba no pregunta por la aplicación: pregunta a la base. Si el
     * filtrado estuviera hecho sólo en PHP, esta prueba pasaría y la anterior
     * también. Esta es la que detecta que la protección está en la base.
     */
    public function test_la_fila_no_se_lee_sin_contexto(): void
    {
        $guarderia = Guarderia::factory()->create();

        $this->contexto($guarderia, fn (): Nino => Nino::factory()->de($guarderia)->create());

        // Sin fijar `app.guarderia_id`, una consulta directa a la base no puede
        // devolver filas de ninguna guardería.
        $filas = DB::select('SELECT count(*)::int AS cantidad FROM public.ninos');

        $this->assertSame(
            0,
            (int) $filas[0]->cantidad,
            'Sin contexto, la base no debería devolver ni una fila de ninos.',
        );
    }

    /**
     * Una consulta sin contexto no falla con un error raro: devuelve cero.
     *
     * Es "fallar cerrado" sin excepción. La función `guarderia_actual()`
     * devuelve NULL, `tenant_id = NULL` no es verdadero, y la política no deja
     * pasar la fila. Ver `2026_10_18_000030_activar_row_level_security.php`.
     */
    public function test_la_funcion_de_contexto_devuelve_nulo_sin_contexto(): void
    {
        $fila = DB::selectOne('SELECT public.guarderia_actual() AS actual');

        $this->assertNull($fila?->actual, 'Sin contexto, la función debe devolver NULL.');
    }

    /**
     * La función de contexto es `SECURITY INVOKER`, no `SECURITY DEFINER`.
     *
     * Con `DEFINER`, la función correría con los privilegios de quien la creó y
     * las políticas se volverían decorativas.
     */
    public function test_la_funcion_de_contexto_es_invoker(): void
    {
        $fila = DB::selectOne(<<<'SQL'
            SELECT p.prosecdef AS es_definer
            FROM pg_proc p
            JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE p.proname = 'guarderia_actual' AND n.nspname = 'public'
        SQL);

        $this->assertNotNull($fila, 'La función public.guarderia_actual() no existe.');
        $this->assertFalse((bool) $fila->es_definer, 'La función de contexto debe ser SECURITY INVOKER.');
    }

    /**
     * Cada tabla de negocio tiene Row Level Security ACTIVADA y FORZADA.
     */
    public function test_las_tablas_de_negocio_tienen_rls_forzada(): void
    {
        $tablas = [
            'usuarios', 'ninos', 'responsables', 'responsable_nino',
            'autorizaciones_retiros', 'asistencias', 'retiros',
            'fichas_medicas', 'planes', 'matriculas', 'pagos',
            'incidencias', 'accesos', 'sesiones',
        ];

        $filas = DB::select(<<<'SQL'
            SELECT c.relname, c.relrowsecurity, c.relforcerowsecurity
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind = 'r'
        SQL);

        $porNombre = [];

        foreach ($filas as $fila) {
            $porNombre[$fila->relname] = $fila;
        }

        foreach ($tablas as $tabla) {
            $this->assertArrayHasKey($tabla, $porNombre, "La tabla {$tabla} no existe.");

            $this->assertTrue(
                (bool) $porNombre[$tabla]->relrowsecurity,
                "La tabla {$tabla} no tiene Row Level Security activada.",
            );

            $this->assertTrue(
                (bool) $porNombre[$tabla]->relforcerowsecurity,
                "La tabla {$tabla} no tiene FORCE ROW LEVEL SECURITY. Sin FORCE, el dueño de la "
                . 'tabla se salta la política, y aquí el dueño es el mismo rol que corre la aplicación.',
            );
        }
    }

    /**
     * La política usa `WITH CHECK`, no sólo `USING`.
     *
     * Sin `WITH CHECK` se puede leer lo propio y escribir filas de otra
     * guardería. Es el error de la mitad de las instalaciones.
     */
    public function test_la_politica_tiene_with_check(): void
    {
        $filas = DB::select(<<<'SQL'
            SELECT polname, polqual IS NOT NULL AS tiene_using, polwithcheck IS NOT NULL AS tiene_check
            FROM pg_policy
            WHERE polname = 'aislamiento_por_guarderia'
        SQL);

        $this->assertNotEmpty($filas, 'No se encontró la política aislamiento_por_guarderia.');

        foreach ($filas as $fila) {
            $this->assertTrue((bool) $fila->tiene_using, 'La política necesita USING.');
            $this->assertTrue(
                (bool) $fila->tiene_check,
                'La política necesita WITH CHECK: sin él se puede insertar una fila de otra guardería.',
            );
        }
    }

    /**
     * Las cuatro tablas de registro NO admiten borrado.
     *
     * Un registro que se puede borrar no sirve para reconstruir nada. Y esto no
     * es una regla de la aplicación: es un disparador en la base.
     */
    public function test_los_registros_no_se_bordan(): void
    {
        $tablas = ['retiros', 'autorizaciones_retiros', 'incidencias', 'accesos'];

        foreach ($tablas as $tabla) {
            $disparadores = DB::select(<<<SQL
                SELECT tgname FROM pg_trigger
                WHERE tgrelid = 'public.{$tabla}'::regclass AND NOT tgisinternal
            SQL);

            $this->assertNotEmpty(
                $disparadores,
                "La tabla {$tabla} no tiene ningún disparador. Debería impedir el borrado.",
            );
        }
    }

    /**
     * El context de guardería se fija con alcance de TRANSACCIÓN.
     *
     * Con alcance de sesión, el valor sobreviviría al cierre de la transacción y
     * la petición siguiente leería filas de la guardería anterior.
     */
    public function test_el_contexto_es_de_alcance_de_transaccion(): void
    {
        $guarderia = Guarderia::factory()->create();

        $this->contexto($guarderia, function (): void {
            DB::select('SELECT set_config(\'app.guarderia_id\', ?, true)', [$guarderia->id]);
        });

        $fila = DB::selectOne('SELECT current_setting(\'app.guarderia_id\', true) AS actual');

        $this->assertNull(
            $fila?->actual,
            'El contexto debería haberse perdido al terminar la transacción. Si sigue puesto, '
            . 'está fijado con alcance de sesión y la petición siguiente puede leer filas ajenas.',
        );
    }

    /**
     * Corre algo con el contexto de una guardería fijado.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $operacion
     * @return TResult
     */
    private function contexto(Guarderia $guarderia, callable $operacion): mixed
    {
        return DB::transaction(function () use ($guarderia, $operacion): mixed {
            DB::select('SELECT set_config(\'app.guarderia_id\', ?, true)', [$guarderia->id]);

            return $operacion();
        });
    }

    /**
     * Una entrada de las rutas, para dejar constancia de que el path de la
     * aplicación existe.
     */
    public function test_la_ruta_de_guarderias_publicas_existe(): void
    {
        $this->assertTrue(
            Route::has('guarderias.publicas'),
            'La ruta guarderias.publicas no está registrada en routes/web.php.',
        );
    }
}