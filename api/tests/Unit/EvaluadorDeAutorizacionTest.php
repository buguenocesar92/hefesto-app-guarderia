<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\EstadoAutorizacion;
use App\Models\AutorizacionRetiro;
use App\Services\Autorizaciones\EvaluadorDeAutorizacion;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * El evaluador de autorizaciones, sin base de datos.
 *
 * POR QUÉ UNA PRUEBA DE UNIDAD Y NO OTRA DE INTEGRACIÓN
 * ----------------------------------------------------
 * Porque la vigencia de una autorización es una cuenta de fechas, y esa cuenta
 * tiene una frontera exacta que merece pruebas que enumeren los días una por
 * una. Una prueba de integración no puede afirmar "todos los días de la ventana
 * menos uno funcionan y el día de la fecha no".
 *
 * Y porque este archivo corre sin PostgreSQL: es de las pocas cosas de esta
 * aplicación que se puede comprobar en una máquina sin base instalada.
 *
 * Ver docs/02.
 */
class EvaluadorDeAutorizacionTest extends TestCase
{
    private EvaluadorDeAutorizacion $evaluador;

    private CarbonImmutable $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluador = new EvaluadorDeAutorizacion();

        // Fecha fija. Una prueba de vigencia que depende del día en que se corre
        // falla un día y pasa al otro.
        $this->hoy = CarbonImmutable::parse('2026-10-18T12:00:00-03:00');
    }

    /**
     * La fecha vence al FINAL del día. El último día sirve ENTERO.
     */
    public function test_el_ultimo_dia_de_vigencia_sigue_sirviendo(): void
    {
        $autorizacion = $this->autorizacionHasta($this->hoy);

        $this->assertSame(
            EstadoAutorizacion::Vigente,
            $this->evaluador->estadoEn($autorizacion, $this->hoy),
            'El día de la fecha de vencimiento todavía tiene que servir.',
        );

        // Un segundo antes de que termine el día.
        $this->assertSame(
            EstadoAutorizacion::Vigente,
            $this->evaluador->estadoEn($autorizacion, $this->hoy->endOfDay()->subSecond()),
        );

        // Un segundo después.
        $this->assertSame(
            EstadoAutorizacion::Vencida,
            $this->evaluador->estadoEn($autorizacion, $this->hoy->addDay()->startOfDay()),
            'Al día siguiente ya no sirve.',
        );
    }

    /**
     * Los días previos, todos.
     */
    public function test_todos_los_dias_previos_sirven(): void
    {
        $autorizacion = $this->autorizacionHasta($this->hoy->addDays(10));

        for ($dia = 0; $dia <= 10; $dia++) {
            $this->assertSame(
                EstadoAutorizacion::Vigente,
                $this->evaluador->estadoEn($autorizacion, $this->hoy->addDays($dia)),
                sprintf('El día %d de la ventana debería servir.', $dia),
            );
        }
    }

    /**
     * Un día después de la fecha, ya no.
     */
    public function test_un_dia_despues_no_sirve(): void
    {
        $autorizacion = $this->autorizacionHasta($this->hoy->subDay());

        $this->assertSame(
            EstadoAutorizacion::Vencida,
            $this->evaluador->estadoEn($autorizacion, $this->hoy),
        );

        $this->assertFalse($this->evaluador->permiteRetiro($autorizacion, $this->hoy));
    }

    /**
     * La fecha MANDA sobre el estado guardado.
     */
    public function test_la_fecha_manda_sobre_el_estado_guardado(): void
    {
        // La fila dice vigente; la fecha dice vencida. La fecha gana.
        $autorizacion = $this->autorizacionHasta($this->hoy->subMonth());
        $autorizacion->estado = EstadoAutorizacion::Vigente;

        $this->assertSame(
            EstadoAutorizacion::Vencida,
            $this->evaluador->estadoEn($autorizacion, $this->hoy),
            'Una fila marcada vigente con la fecha pasada está vencida.',
        );
    }

    /**
     * Una autorización anulada lo está aunque la fecha siga vigente.
     */
    public function test_la_anulada_no_vuelve_a_servir(): void
    {
        $autorizacion = $this->autorizacionHasta($this->hoy->addYear());
        $autorizacion->estado = EstadoAutorizacion::Anulada;
        $autorizacion->motivo_anulacion = 'La familia lo pidió por teléfono.';

        $this->assertSame(
            EstadoAutorizacion::Anulada,
            $this->evaluador->estadoEn($autorizacion, $this->hoy),
            'Anulada manda sobre cualquier fecha.',
        );
    }

    /**
     * Sin fecha de vencimiento, sigue sirviendo.
     */
    public function test_sin_fecha_de_vencimiento_sigue_sirviendo(): void
    {
        $autorizacion = $this->autorizacionHasta(null);

        $this->assertSame(EstadoAutorizacion::Vigente, $this->evaluador->estadoEn($autorizacion, $this->hoy));
        $this->assertTrue($this->evaluador->permiteRetiro($autorizacion, $this->hoy));
        $this->assertNull($this->evaluador->diasParaVencer($autorizacion, $this->hoy));
    }

    /**
     * Una autorización que todavía no empieza NO está vigente.
     */
    public function test_una_autorizacion_que_aun_no_empieza_no_sirve(): void
    {
        $autorizacion = $this->autorizacionHasta($this->hoy->addDays(30));
        $autorizacion->vigente_desde = $this->hoy->addDays(10);

        $this->assertNotSame(
            EstadoAutorizacion::Vigente,
            $this->evaluador->estadoEn($autorizacion, $this->hoy),
            'Una autorización que empieza en diez días no sirve hoy.',
        );
    }

    /**
     * Los días para vencer.
     */
    public function test_los_dias_para_vencer(): void
    {
        $this->assertSame(0, $this->evaluador->diasParaVencer($this->autorizacionHasta($this->hoy), $this->hoy));
        $this->assertSame(5, $this->evaluador->diasParaVencer($this->autorizacionHasta($this->hoy->addDays(5)), $this->hoy));
        $this->assertSame(-3, $this->evaluador->diasParaVencer($this->autorizacionHasta($this->hoy->subDays(3)), $this->hoy));
    }

    /**
     * La ventana de aviso.
     */
    public function test_la_ventana_de_aviso_cubre_solo_lo_que_esta_por_vencer(): void
    {
        // Ventana de quince días, que es el valor de configuración.
        $this->assertTrue(
            $this->evaluador->entraEnVentanaDeAviso($this->autorizacionHasta($this->hoy->addDays(15)), $this->hoy),
            'Quince días exactos entran en la ventana.',
        );

        $this->assertTrue(
            $this->evaluador->entraEnVentanaDeAviso($this->autorizacionHasta($this->hoy->addDays(3)), $this->hoy),
        );

        $this->assertFalse(
            $this->evaluador->entraEnVentanaDeAviso($this->autorizacionHasta($this->hoy->addDays(16)), $this->hoy),
            'Dieciséis días no entran en la ventana de quince.',
        );

        $this->assertFalse(
            $this->evaluador->entraEnVentanaDeAviso($this->autorizacionHasta($this->hoy->subDay()), $this->hoy),
            'Lo que ya venció no "entra en la ventana": ya pasó.',
        );

        $this->assertFalse(
            $this->evaluador->entraEnVentanaDeAviso($this->autorizacionHasta(null), $this->hoy),
            'Sin fecha de vencimiento no hay nada que avisar.',
        );
    }

    /**
     * Una autorización de ejemplo que vence en la fecha dada.
     *
     * Es un modelo REAL, construido en memoria y sin guardar. Se puede porque
     * `AutorizacionRetiro` es un modelo de Eloquent con `$fillable`:
     * instanciarlo no toca la base.
     *
     * No se usa un objeto con la misma forma a propósito: `EvaluadorDeAutorizacion`
     * pide un `AutorizacionRetiro`, y una prueba que usara un sustituto estaría
     * probando que el método acepta cualquier cosa.
     */
    private function autorizacionHasta(?CarbonImmutable $vigenteHasta): AutorizacionRetiro
    {
        return new AutorizacionRetiro([
            'estado' => EstadoAutorizacion::Vigente,
            'vigente_desde' => CarbonImmutable::parse('2020-01-01')->startOfDay(),
            'vigente_hasta' => $vigenteHasta,
        ]);
    }
}