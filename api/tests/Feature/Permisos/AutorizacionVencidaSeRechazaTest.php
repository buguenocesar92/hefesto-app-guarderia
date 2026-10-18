<?php

declare(strict_types=1);

namespace Tests\Feature\Permisos;

use App\Enums\EstadoAsistencia;
use App\Enums\EstadoAutorizacion;
use App\Models\Asistencia;
use App\Models\AutorizacionRetiro;
use App\Models\Guarderia;
use App\Models\Nino;
use App\Models\Responsable;
use App\Models\Usuario;
use App\Services\Autorizaciones\EvaluadorDeAutorizacion;
use App\Services\Retiros\ServicioRetiro;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Soporte\PreparaEscena;
use Tests\TestCase;

/**
 * Una autorización VENCIDA se rechaza.
 *
 * POR QUÉ MERECE SU PROPIA PRUEBA
 * -------------------------------
 * Porque "no hay autorización" y "la autorización venció" son el mismo `false`
 * y NO son la misma cosa.
 *
 * En la puerta, la diferencia es de minutos: "usted nunca ha estado autorizada"
 * y "usted estaba autorizada y el papel se venció el 12 de marzo" llevan a
 * conversaciones completamente distintas con la familia.
 *
 * Y es la diferencia entre una falla del centro y una falla de la familia. Por
 * eso el motivo del rechazo tiene que decir la FECHA en que venció.
 *
 * LA PRUEBA DEL LÍMITE EXACTO
 * --------------------------
 * La autorización vence al FINAL del día de `vigente_hasta`. Un día antes de esa
 * fecha, el retiro tiene que funcionar; en esa fecha, también; al día siguiente,
 * no. Esa frontera es la que se prueba, porque es donde un `>` mal puesto
 * rompe todo.
 *
 * Ver docs/03 y docs/07.
 */
class AutorizacionVencidaSeRechazaTest extends TestCase
{
    use PreparaEscena;
    use RefreshDatabase;

    /**
     * Una autorización vencida NO permite retirar.
     */
    public function test_una_autorizacion_vencida_no_permite_retirar(): void
    {
        [$guarderia, $nino, $responsable, $operador] = $this->escena();

        $this->crearAutorizacionVencida($guarderia, $nino, $responsable, $operador);

        $this->intentarRetiro($guarderia, $nino, $responsable, $operador);

        $retiros = $this->retirosDe($guarderia, $nino);

        $this->assertSame(
            0,
            $retiros->where('resultado', 'autorizado')->count(),
            'Una autorización vencida no puede autorizar un retiro.',
        );

        $rechazo = $retiros->firstWhere('resultado', 'rechazado');

        $this->assertNotNull($rechazo, 'El intento debería quedar registrado como rechazado.');

        // El motivo dice la FECHA, no sólo "vencida". Esa fecha es lo que
        // permite reconstruir después qué pasó.
        $motivo = (string) $rechazo->motivo_rechazo;

        $this->assertStringContainsString(
            'venció',
            $motivo,
            'El motivo del rechazo debería decir que la autorización venció.',
        );
        $this->assertStringContainsString(
            CarbonImmutable::now()->subMonth()->format('Y'),
            $motivo,
            'El motivo debería incluir la fecha de vencimiento.',
        );
    }

    /**
     * El evaluador marca la autorización como VENCIDA, aunque la fila diga
     * "vigente".
     *
     * ESTA ES LA PRUEBA QUE IMPORTA DE VERDAD
     * ---------------------------------------
     * El estado guardado y la fecha se pueden desincronizar: alguien renueva una
     * autorización y actualiza la fecha, pero la fila dice "vigente" de todos
     * modos; o al revés.
     *
     * La fecha MANDA. Si el evaluador confiara en el estado guardado, una
     * autorización con la fecha pasada seguiría autorizando.
     */
    public function test_el_estado_guardado_no_anula_la_fecha_de_vencimiento(): void
    {
        $guarderia = Guarderia::factory()->create();

        [$nino, $responsable, $operador] = $this->enGuarderia($guarderia, fn (): array => [
            Nino::factory()->de($guarderia)->create(),
            Responsable::factory()->de($guarderia)->create(),
            Usuario::factory()->de($guarderia)->direccion()->create(),
        ]);

        $autorizacion = $this->crearAutorizacionVencida($guarderia, $nino, $responsable, $operador);

        $this->assertSame(
            EstadoAutorizacion::Vigente,
            $autorizacion->estado,
            'La fila dice vigente a propósito: es lo que hay que contradecir.',
        );

        $estado = $this->enGuarderia($guarderia, fn (): EstadoAutorizacion => $autorizacion->estadoEfectivo());

        $this->assertSame(
            EstadoAutorizacion::Vencida,
            $estado,
            'La fecha de vencimiento manda sobre el estado guardado en la fila.',
        );

        $this->assertFalse($estado->permiteRetiro(), 'Una autorización vencida no puede permitir retiro.');
    }

    /**
     * El último día de vigencia todavía sirve; al día siguiente, no.
     */
    public function test_el_ultimo_dia_sirve_y_al_siguiente_no(): void
    {
        $guarderia = Guarderia::factory()->create();

        [$nino, $responsable, $operador] = $this->enGuarderia($guarderia, fn (): array => [
            Nino::factory()->de($guarderia)->create(),
            Responsable::factory()->de($guarderia)->create(),
            Usuario::factory()->de($guarderia)->direccion()->create(),
        ]);

        $evaluador = $this->enGuarderia($guarderia, fn (): EvaluadorDeAutorizacion => app(EvaluadorDeAutorizacion::class));

        $hoy = CarbonImmutable::now();

        // La autorización vence HOY. Hoy todavía sirve.
        $venceHoy = $this->enGuarderia($guarderia, fn (): AutorizacionRetiro => AutorizacionRetiro::create([
            'tenant_id' => $guarderia->id,
            'nino_id' => $nino->id,
            'responsable_id' => $responsable->id,
            'vigente_desde' => $hoy->subMonth()->toDateString(),
            'vigente_hasta' => $hoy->toDateString(),
            'estado' => EstadoAutorizacion::Vigente,
            'registrada_por' => $operador->id,
        ]));

        $this->assertTrue(
            $evaluador->permiteRetiro($venceHoy, $hoy),
            'El último día de vigencia tiene que servir: la fecha vence al final del día.',
        );

        // Misma autorización, un día después: ya no.
        $this->assertFalse(
            $evaluador->permiteRetiro($venceHoy, $hoy->addDay()),
            'Al día siguiente de la fecha de vencimiento, la autorización no sirve.',
        );
    }

    /**
     * Una autorización ANULADA tampoco sirve, y el motivo lo dice.
     */
    public function test_una_autorizacion_anulada_no_permite_retirar(): void
    {
        [$guarderia, $nino, $responsable, $operador] = $this->escena();

        $autorizacion = $this->crearAutorizacionVigiente($guarderia, $nino, $responsable, $operador);

        $this->enGuarderia($guarderia, function () use ($autorizacion, $operador): void {
            $autorizacion->estado = EstadoAutorizacion::Anulada;
            $autorizacion->motivo_anulacion = 'La familia lo pidió por teléfono el mes pasado.';
            $autorizacion->anulada_por = $operador->id;
            $autorizacion->anulada_en = now();
            $autorizacion->save();
        });

        $this->intentarRetiro($guarderia, $nino, $responsable, $operador);

        $retiros = $this->retirosDe($guarderia, $nino);

        $this->assertSame(0, $retiros->where('resultado', 'autorizado')->count());

        $rechazo = $retiros->firstWhere('resultado', 'rechazado');

        $this->assertNotNull($rechazo);
        $this->assertStringContainsString(
            'anulada',
            (string) $rechazo->motivo_rechazo,
            'El motivo debería decir que la autorización fue anulada.',
        );
    }

    /**
     * Una autorización SIN fecha de vencimiento sí sirve, para siempre.
     *
     * Existe porque hay centros donde la autorización de un progenitor legal no
     * vence, y eso es una decisión del centro. Por eso `vigente_hasta` admite
     * nulo y el sistema no pone ningún plazo inventado.
     */
    public function test_una_autorizacion_sin_fecha_sigue_sirviendo(): void
    {
        [$guarderia, $nino, $responsable, $operador] = $this->escena();

        $this->enGuarderia($guarderia, function () use ($guarderia, $nino, $responsable, $operador): void {
            AutorizacionRetiro::create([
                'tenant_id' => $guarderia->id,
                'nino_id' => $nino->id,
                'responsable_id' => $responsable->id,
                'vigente_desde' => now()->subYear()->toDateString(),
                'vigente_hasta' => null,
                'estado' => EstadoAutorizacion::Vigente,
                'registrada_por' => $operador->id,
            ]);
        });

        $this->intentarRetiro($guarderia, $nino, $responsable, $operador);

        $retiros = $this->retirosDe($guarderia, $nino);

        $this->assertSame(
            1,
            $retiros->where('resultado', 'autorizado')->count(),
            'Una autorización sin fecha de vencimiento debería seguir autorizando.',
        );
    }

    /**
     * El aviso ocurre ANTES de que venza, no cuando ya venció.
     */
    public function test_el_aviso_ocurre_antes_del_vencimiento(): void
    {
        $guarderia = Guarderia::factory()->create();

        [$nino, $responsable, $operador] = $this->enGuarderia($guarderia, fn (): array => [
            Nino::factory()->de($guarderia)->create(),
            Responsable::factory()->de($guarderia)->create(),
            Usuario::factory()->de($guarderia)->direccion()->create(),
        ]);

        $evaluador = $this->enGuarderia($guarderia, fn (): EvaluadorDeAutorizacion => app(EvaluadorDeAutorizacion::class));
        $hoy = CarbonImmutable::now();

        // Vence en cinco días: dentro de la ventana de aviso de quince.
        $porVencer = $this->enGuarderia($guarderia, fn (): AutorizacionRetiro => AutorizacionRetiro::create([
            'tenant_id' => $guarderia->id,
            'nino_id' => $nino->id,
            'responsable_id' => $responsable->id,
            'vigente_desde' => $hoy->subYear()->toDateString(),
            'vigente_hasta' => $hoy->addDays(5)->toDateString(),
            'estado' => EstadoAutorizacion::Vigente,
            'registrada_por' => $operador->id,
        ]));

        $this->assertTrue(
            $evaluador->entraEnVentanaDeAviso($porVencer, $hoy),
            'Una autorización que vence en cinco días tiene que entrar en la ventana de aviso.',
        );
        $this->assertSame(5, $evaluador->diasParaVencer($porVencer, $hoy));

        // Vence en dos meses: todavía no toca avisar.
        $masAdelante = $this->enGuarderia($guarderia, fn (): AutorizacionRetiro => AutorizacionRetiro::create([
            'tenant_id' => $guarderia->id,
            'nino_id' => $nino->id,
            'responsable_id' => $responsable->id,
            'vigente_desde' => $hoy->subYear()->toDateString(),
            'vigente_hasta' => $hoy->addMonths(2)->toDateString(),
            'estado' => EstadoAutorizacion::Vigente,
            'registrada_por' => $operador->id,
        ]));

        $this->assertFalse(
            $evaluador->entraEnVentanaDeAviso($masAdelante, $hoy),
            'Una autorización que vence en dos meses no debería avisar todavía.',
        );
    }

    /**
     * @return array{Guarderia, Nino, Responsable, Usuario}
     */
    private function escena(): array
    {
        $guarderia = Guarderia::factory()->create();

        [$nino, $responsable, $operador] = $this->enGuarderia($guarderia, fn (): array => [
            Nino::factory()->de($guarderia)->create(),
            Responsable::factory()->de($guarderia)->create(),
            Usuario::factory()->de($guarderia)->administrativo()->create(),
        ]);

        $this->darIngreso($guarderia, $nino, $operador);

        return [$guarderia, $nino, $responsable, $operador];
    }

    private function intentarRetiro(Guarderia $guarderia, Nino $nino, Responsable $responsable, Usuario $operador): void
    {
        $this->enGuarderia($guarderia, function () use ($nino, $responsable, $operador): void {
            try {
                app(ServicioRetiro::class)->registrar(
                    $nino,
                    $responsable,
                    $operador,
                    CarbonImmutable::now(),
                );
            } catch (\App\Exceptions\DomainException) {
                // Esperado. Lo que importa es lo que quedó escrito.
            }
        });
    }
}