<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Http\Controllers\Publico\GuarderiasPublicasController;
use App\Models\Guarderia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Que la vista pública se use DE VERDAD, y no sólo exista.
 *
 * POR QUÉ ESTA PRUEBA EXISTE
 * --------------------------
 * En aplicaciones anteriores de Hefesto se creó `vw_<entidad>s_publicas` en la
 * migración, se documentó que la aplicación la consultaba, y nunca se consultó:
 * el controlador leía la tabla entera.
 *
 * Consecuencia: antes del ingreso se leía `guarderias` completa, con razón
 * social, dirección, teléfono y correo de centros con los que la persona no
 * tiene nada que ver.
 *
 * Esta prueba falla si alguien cambia el controlador para leer la tabla. Es una
 * prueba sobre el CÓDIGO, no sobre el comportamiento: por eso lee el archivo
 * fuente en vez de llamar a la API.
 *
 * Y hay una segunda comprobación, de comportamiento: la vista expone tres
 * columnas y sólo las activas.
 *
 * Ver docs/03.
 */
class VistaPublicaSeUsaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * El controlador consulta la VISTA, no la tabla.
     *
     * Se lee el archivo fuente y no se hace una petición: el fallo que hay que
     * detectar es que alguien escriba `guarderias` en vez de
     * `vw_guarderias_publicas`, y eso no se ve desde la respuesta.
     */
    public function test_el_controlador_consulta_la_vista_y_no_la_tabla(): void
    {
        $ruta = __DIR__ . '/../../../app/Http/Controllers/Publico/GuarderiasPublicasController.php';

        $this->assertFileExists($ruta, 'No existe el controlador de guarderías públicas.');

        $contenido = file_get_contents($ruta);

        $this->assertIsString($contenido);

        $contenido = (string) $contenido;

        $this->assertStringContainsString(
            'vw_guarderias_publicas',
            $contenido,
            'El controlador tiene que consultar la vista pública.',
        );

        // Se permite la palabra "guarderias" dentro del nombre de la vista, que
        // es lo que se busca, pero NO se permite `from('guarderias')` ni
        // `->table('guarderias')`: eso es leer la tabla entera.
        $this->assertDoesNotMatchRegularExpression(
            '/(?:DB::table|->table|from)\s*\(\s*[\'"]guarderias[\'"]/i',
            $contenido,
            'El controlador está leyendo la tabla `guarderias` completa. '
            . 'Antes del ingreso sólo se permite leer `vw_guarderias_publicas`.',
        );
    }

    /**
     * La vista expone exactamente TRES columnas.
     */
    public function test_la_vista_expone_tres_columnas(): void
    {
        $filas = DB::select(<<<'SQL'
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = 'vw_guarderias_publicas'
            ORDER BY ordinal_position
        SQL);

        $nombres = array_map(static fn ($fila): string => (string) $fila->column_name, $filas);

        $this->assertSame(
            ['id', 'nombre', 'slug'],
            $nombres,
            'La vista pública debe exponer exactamente id, nombre y slug. '
            . 'No expone razón social, dirección, correo ni teléfono, y no debe empezar a hacerlo.',
        );
    }

    /**
     * La vista NO expone razón social, dirección, teléfono ni correo.
     *
     * Es la misma comprobación que la anterior, escrita al revés, para que el
     * fallo se lea claro si alguien agrega una columna.
     */
    public function test_la_vista_no_expone_datos_de_contacto(): void
    {
        $prohibidas = ['razon_social', 'direccion', 'telefono', 'correo'];

        $filas = DB::select(<<<'SQL'
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = 'vw_guarderias_publicas'
        SQL);

        foreach ($filas as $fila) {
            $this->assertNotContains(
                $fila->column_name,
                $prohibidas,
                'La vista pública no debe exponer datos de contacto de la guardería.',
            );
        }
    }

    /**
     * La vista sólo trae guarderías ACTIVAS.
     */
    public function test_la_vista_solo_trae_guarderias_activas(): void
    {
        $activa = Guarderia::factory()->create();
        $inactiva = Guarderia::factory()->inactiva()->create();

        $ids = DB::table('vw_guarderias_publicas')->pluck('id')->all();

        $this->assertContains($activa->id, $ids, 'La guardería activa debería aparecer en la vista.');
        $this->assertNotContains($inactiva->id, $ids, 'Una guardería inactiva no debe aparecer en el selector de ingreso.');
    }

    /**
     * La ruta devuelve lo que trae la vista, no la tabla.
     */
    public function test_la_ruta_devuelve_las_columnas_de_la_vista(): void
    {
        Guarderia::factory()->create(['nombre' => 'Guardería visible', 'slug' => 'visible']);

        $respuesta = $this->getJson('/api/v1/guarderias');

        $respuesta->assertOk();

        $guarderias = $respuesta->json('guarderias');

        $this->assertIsArray($guarderias);
        $this->assertCount(1, $guarderias);

        // Sólo tres claves. Si aparece una cuarta, se está leyendo la tabla.
        foreach ($guarderias[0] as $clave => $valor) {
            $this->assertContains(
                $clave,
                ['id', 'nombre', 'slug'],
                sprintf('La respuesta trae la columna "%s", que la vista pública no expone.', $clave),
            );
        }
    }
}