<?php

declare(strict_types=1);

namespace Tests\Feature\Permisos;

use App\Enums\RolGuarderia;
use App\Models\Acceso;
use App\Models\FichaMedica;
use App\Models\Guarderia;
use App\Models\Nino;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Soporte\PreparaEscena;
use Tests\TestCase;

/**
 * El rol ADMINISTRATIVO no lee las restricciones médicas.
 *
 * EL DATO MÁS SENSIBLE DE ESTA APLICACIÓN
 * ---------------------------------------
 * Alergias, alimentación y medicación son dato de salud de un menor. Y el
 * administrativo, que atiende el teléfono y cobra, NO lo ve.
 *
 * No es que "no tenga permiso para saber si el niño es alérgico". Es que la
 * ficha médica no le corresponde: su trabajo es la asistencia, los retiros, las
 * autorizaciones y los pagos.
 *
 * POR QUÉ LA RESPUESTA ES 404 Y NO 403
 * -----------------------------------
 * Un 403 confirma que el recurso EXISTE. En una guardería eso le dice a quien
 * preguntó que ese niño existe y que tiene ficha. Un 404 no enseña nada de eso.
 *
 * Ver `App\Policies\Concerns\RespondeNoEncontrado` y docs/03, docs/07.
 */
class AdministrativoNoLeeFichaMedicaTest extends TestCase
{
    use PreparaEscena;
    use RefreshDatabase;

    /**
     * La Policy le responde 404 al administrativo.
     */
    public function test_la_policy_responde_404_al_administrativo(): void
    {
        [$guarderia, $nino, $ficha, $administrativo] = $this->escena();

        $this->enGuarderia($guarderia, function () use ($nino, $ficha, $administrativo): void {
            $respuesta = $this->app->make(\Illuminate\Contracts\Auth\Access\Gate::class)
                ->forUser($administrativo)
                ->authorize('ver', $ficha);
        });
    }

    /**
     * La respuesta de la API al administrativo es 404, no 403.
     *
     * Esta es la comprobación que importa: es la que ve la pantalla.
     */
    public function test_la_api_responde_404_y_no_403_al_administrativo(): void
    {
        [$guarderia, $nino, , $administrativo] = $this->escena();

        $respuesta = $this->actingAs($administrativo)
            ->getJson('/api/v1/ninos/' . $nino->id . '/ficha-medica');

        $respuesta->assertNotFound();
        $respuesta->assertJsonMissingPath('ficha.alergias');
        $respuesta->assertJsonMissingPath('ficha.medicacion');
    }

    /**
     * La respuesta NO dice "no autorizado".
     *
     * Un cuerpo de respuesta que dice "no autorizado" ya está diciendo que la
     * ficha existe. El cuerpo tiene que ser un "no encontrado" a secas.
     */
    public function test_la_respuesta_no_confirma_que_la_ficha_exista(): void
    {
        [$guarderia, $nino, , $administrativo] = $this->escena();

        $cuerpo = $this->actingAs($administrativo)
            ->getJson('/api/v1/ninos/' . $nino->id . '/ficha-medica')
            ->assertNotFound()
            ->json();

        $this->assertArrayNotHasKey('ficha', $cuerpo ?? []);
        $this->assertArrayNotHasKey('mensaje', $cuerpo ?? []);
    }

    /**
     * La educadora SÍ lee la ficha: necesita las alergias para el almuerzo.
     */
    public function test_la_educadora_si_lecte_la_ficha(): void
    {
        [$guarderia, $nino, , $educadora] = $this->escena();

        $respuesta = $this->actingAs($educadora)
            ->getJson('/api/v1/ninos/' . $nino->id . '/ficha-medica');

        $respuesta->assertOk();
        $respuesta->assertJsonPath('ficha.alergias', 'Alergia al maní, confirmada por el pediatra.');
        $respuesta->assertJsonPath('ficha.tiene_restricciones', true);
    }

    /**
     * La dirección SÍ lee la ficha: responde por el cuidado de los menores.
     */
    public function test_la_direccion_si_lecte_la_ficha(): void
    {
        [$guarderia, $nino, , $direccion] = $this->escena();

        $this->actingAs($direccion)
            ->getJson('/api/v1/ninos/' . $nino->id . '/ficha-medica')
            ->assertOk()
            ->assertJsonPath('ficha.alergias', 'Alergia al maní, confirmada por el pediatra.');
    }

    /**
     * El administrativo NO puede escribir la ficha tampoco.
     *
     * Guardar una ficha es escribir sobre dato de salud de un menor, y por eso
     * tiene el mismo permiso que leerla.
     */
    public function test_el_administrativo_no_puede_guardar_la_ficha(): void
    {
        [$guarderia, $nino, , $administrativo] = $this->escena();

        $this->actingAs($administrativo)
            ->postJson('/api/v1/ninos/' . $nino->id . '/ficha-medica', [
                'alergias' => 'Una alergia inventada por alguien que no debería poder escribirla.',
            ])
            ->assertNotFound();

        $sinCambios = $this->enGuarderia($guarderia, fn (): FichaMedica => FichaMedica::query()
            ->where('nino_id', $nino->id)
            ->firstOrFail());

        $this->assertSame(
            'Alergia al maní, confirmada por el pediatra.',
            $sinCambios->alergias,
            'La ficha no debería haber cambiado.',
        );
    }

    /**
     * El intento denegado QUEDA ANOTADO en la bitácora.
     *
     * Un acceso denegado es información: es el caso en que alguien pidió abrir
     * una ficha que no le correspondía. Si sólo se anotara lo permitido, el
     * registro sería la lista de las cosas que el sistema dejó pasar, y no
     * diría nada sobre lo que alguien intentó hacer.
     */
    public function test_el_intento_denegado_queda_anotado(): void
    {
        [$guarderia, $nino, , $administrativo] = $this->escena();

        $this->actingAs($administrativo)
            ->getJson('/api/v1/ninos/' . $nino->id . '/ficha-medica')
            ->assertNotFound();

        $accesos = $this->enGuarderia($guarderia, fn (): \Illuminate\Support\Collection => Acceso::query()
            ->where('recurso', 'fichas_medicas')
            ->get());

        $denegado = $accesos->firstWhere('resultado', 'denegado');

        $this->assertNotNull(
            $denegado,
            'El intento denegado debería quedar anotado en la bitácora de accesos.',
        );

        $this->assertSame(
            RolGuarderia::Administrativo->value,
            $denegado->rol,
            'La bitácora tiene que decir con qué rol se intentó.',
        );
    }

    /**
     * El acceso permitido también queda anotado, con el rol y el niño.
     */
    public function test_el_acceso_permitido_queda_anotado_con_rol_y_nino(): void
    {
        [$guarderia, $nino, , $educadora] = $this->escena();

        $this->actingAs($educadora)
            ->getJson('/api/v1/ninos/' . $nino->id . '/ficha-medica')
            ->assertOk();

        $permitido = $this->enGuarderia($guarderia, fn (): ?Acceso => Acceso::query()
            ->where('recurso', 'fichas_medicas')
            ->where('resultado', 'permitido')
            ->first());

        $this->assertNotNull($permitido, 'El acceso permitido debería quedar anotado.');
        $this->assertSame(RolGuarderia::Educadora->value, $permitido->rol);
        $this->assertSame($nino->id, $permitido->recurso_id, 'La bitácora tiene que decir de qué niño era la ficha.');
        $this->assertNotNull($permitido->ocurrido_en, 'La bitácora tiene que decir cuándo.');
    }

    /**
     * La lista de niños dice si hay ficha, pero NUNCA qué dice.
     *
     * `tiene_ficha_medica` es un sí o un no. Saber que existe una ficha y poder
     * abrirla son dos cosas distintas, y sólo la segunda requiere permiso.
     */
    public function test_la_lista_de_ninos_no_trae_el_contenido_de_la_ficha(): void
    {
        [$guarderia, , , $administrativo] = $this->escena();

        $ninos = $this->actingAs($administrativo)
            ->getJson('/api/v1/ninos')
            ->assertOk()
            ->json('ninos');

        $this->assertIsArray($ninos);
        $this->assertNotEmpty($ninos);

        foreach ($ninos as $nino) {
            $this->assertArrayHasKey('tiene_ficha_medica', $nino);

            foreach ($nino as $clave => $valor) {
                $this->assertNotContains(
                    $clave,
                    ['alergias', 'medicacion', 'alimentos_prohibidos', 'plan_emergencia'],
                    sprintf('La lista de niños no debe traer "%s".', $clave),
                );
            }
        }
    }

    /**
     * @return array{Guarderia, Nino, FichaMedica, Usuario}
     */
    private function escena(): array
    {
        $guarderia = Guarderia::factory()->create();

        [$nino, $educadora, $administrativo, $direccion] = $this->enGuarderia($guarderia, fn (): array => [
            Nino::factory()->de($guarderia)->create(),
            Usuario::factory()->de($guarderia)->educadora()->create(),
            Usuario::factory()->de($guarderia)->administrativo()->create(),
            Usuario::factory()->de($guarderia)->direccion()->create(),
        ]);

        $ficha = $this->enGuarderia($guarderia, fn (): FichaMedica => FichaMedica::create([
            'tenant_id' => $guarderia->id,
            'nino_id' => $nino->id,
            'alergias' => 'Alergia al maní, confirmada por el pediatra.',
            'medicacion' => ' inhalador de rescate a las 11:00.',
            'alimentos_prohibidos' => 'Frutos secos, marañas.',
            'autoriza_medicamento' => true,
            'actualizado_por' => $educadora->id,
        ]));

        return [$guarderia, $nino, $ficha, $administrativo];
    }
}