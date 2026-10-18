<?php

declare(strict_types=1);

namespace Tests\Feature\Permisos;

use App\Enums\EstadoAsistencia;
use App\Models\Asistencia;
use App\Models\Guarderia;
use App\Models\Nino;
use App\Models\Responsable;
use App\Models\Retiro;
use App\Models\Usuario;
use App\Services\Retiros\ServicioRetiro;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Soporte\PreparaEscena;
use Tests\TestCase;

/**
 * Un responsable NO autorizado no puede retirar a un niño.
 *
 * ESTA ES LA REGLA CENTRAL DE LA APLICACIÓN
 * ------------------------------------------
 * Si alguien llega a la puerta y no está autorizado, el sistema no lo deja
 * llevarse al niño. Y deja escrito por qué no.
 *
 * LO QUE ESTA PRUEBA COMPRUEBA, Y POR QUÉ SON VARIAS
 * -------------------------------------------------
 * No basta con que el retiro falle. Fallar es lo mínimo. Lo que importa es:
 *
 *   1. Que NO se registre una salida.
 *   2. Que el intento quede REGISTRADO como rechazado, con motivo y con hora.
 *   3. Que la asistencia NO se cierre: el niño sigue en el centro.
 *
 * El punto 3 es el que se olvida. Un sistema que cierra la asistencia y después
 * avisa que el retiro fue rechazado deja al niño marcado como retirado en un
 * sistema donde el niño sigue adentro.
 *
 * Ver docs/02 y docs/07.
 */
class ResponsableNoAutorizadoNoRetiraTest extends TestCase
{
    use PreparaEscena;
    use RefreshDatabase;

    /**
     * Sin autorización, el retiro falla Y queda registrado.
     */
    public function test_un_responsable_sin_autorizacion_no_puede_retirar(): void
    {
        [$guarderia, $nino, $responsable, $operador] = $this->escena();

        $this->intentarRetiro($guarderia, $nino, $responsable, $operador);

        $retiros = $this->retirosDe($guarderia, $nino);

        // 1. Ningún retiro autorizado.
        $this->assertSame(
            0,
            $retiros->where('resultado', 'autorizado')->count(),
            'No debería existir ningún retiro autorizado para un responsable sin autorización.',
        );

        // 2. El intento quedó registrado como rechazado, con motivo y hora.
        $rechazo = $retiros->firstWhere('resultado', 'rechazado');

        $this->assertNotNull($rechazo, 'El intento de retiro debería quedar registrado como rechazado.');
        $this->assertNotNull($rechazo->motivo_rechazo, 'El rechazo necesita un motivo escrito.');
        $this->assertStringContainsString(
            'autorización',
            (string) $rechazo->motivo_rechazo,
            'El motivo debería hablar de la autorización que falta.',
        );
        $this->assertNotNull($rechazo->ocurrido_en, 'El rechazo necesita la hora a la que ocurrió.');

        // Y con qué documento se identificó, que es la otra mitad de lo que hay
        // que poder reconstruir.
        $this->assertSame(
            $responsable->documento_numero,
            $rechazo->documento_numero,
            'El registro del rechazo tiene que decir con qué documento se identificó.',
        );
    }

    /**
     * El rechazo NO cierra la asistencia: el niño sigue en el centro.
     */
    public function test_el_rechazo_no_cierra_la_asistencia(): void
    {
        [$guarderia, $nino, $responsable, $operador] = $this->escena();

        $this->intentarRetiro($guarderia, $nino, $responsable, $operador);

        $asistencia = $this->enGuarderia($guarderia, fn (): ?Asistencia => Asistencia::query()
            ->where('nino_id', $nino->id)
            ->whereDate('fecha', now()->toDateString())
            ->first());

        $this->assertNotNull($asistencia, 'La asistencia de hoy debería seguir existiendo.');
        $this->assertSame(
            EstadoAsistencia::Presente,
            $asistencia->estado,
            'Un retiro rechazado no puede cerrar la asistencia: el niño sigue en el centro.',
        );
        $this->assertNull($asistencia->egreso_en, 'No se debe registrar salida si el retiro fue rechazado.');
    }

    /**
     * Un responsable de OTRO niño tampoco puede retirar a este.
     *
     * La autorización no es "ser responsable de alguien": es estar autorizado
     * para ESE niño. La hermana puede estar autorizada para uno y no para el
     * otro, y esa distinción es la que el registro tiene que poder hacer.
     */
    public function test_un_responsable_de_otro_nino_no_puede_retirar(): void
    {
        $guarderia = Guarderia::factory()->create();

        [$otroNino, $nino, $responsable, $operador] = $this->enGuarderia($guarderia, fn (): array => [
            Nino::factory()->de($guarderia)->create(),
            Nino::factory()->de($guarderia)->create(),
            Responsable::factory()->de($guarderia)->create(),
            Usuario::factory()->de($guarderia)->administrativo()->create(),
        ]);

        // Autorizado para el otro niño, no para éste.
        $this->crearAutorizacionVigiente($guarderia, $otroNino, $responsable, $operador);
        $this->darIngreso($guarderia, $nino, $operador);

        $this->intentarRetiro($guarderia, $nino, $responsable, $operador);

        $retiros = $this->retirosDe($guarderia, $nino);

        $this->assertSame(
            0,
            $retiros->where('resultado', 'autorizado')->count(),
            'Estar autorizado para otro niño no habilita para éste.',
        );
        $this->assertSame(
            1,
            $retiros->where('resultado', 'rechazado')->count(),
            'El intento debería quedar registrado.',
        );
    }

    /**
     * Un responsable AUTORIZADO sí puede retirar.
     *
     * La otra mitad de la regla: tiene que dejar pasar a quien sí puede. Una
     * aplicación que rechaza a todos es tan inútil como una que acepta a todos.
     */
    public function test_un_responsable_autorizado_si_puede_retirar(): void
    {
        [$guarderia, $nino, $responsable, $operador] = $this->escena(conAutorizacion: true);

        $retiros = $this->retirosDe($guarderia, $nino);
        $autorizado = $retiros->firstWhere('resultado', 'autorizado');

        $this->assertNotNull($autorizado, 'Con autorización vigente, el retiro debería quedar registrado.');
        $this->assertNotNull(
            $autorizado->autorizacion_retiro_id,
            'El retiro autorizado tiene que decir CON QUÉ autorización se hizo.',
        );

        $asistencia = $this->enGuarderia($guarderia, fn (): ?Asistencia => Asistencia::query()
            ->where('nino_id', $nino->id)
            ->whereDate('fecha', now()->toDateString())
            ->first());

        $this->assertNotNull($asistencia);
        $this->assertSame(EstadoAsistencia::Retirado, $asistencia->estado, 'El retiro debería cerrar la asistencia.');
        $this->assertNotNull($asistencia->egreso_en, 'El retiro debería registrar la hora de salida.');
    }

    /**
     * Prepara un niño con ingreso HOY, un responsable, y opcionalmente una
     * autorización vigente.
     *
     * @return array{Guarderia, Nino, Responsable, Usuario}
     */
    private function escena(bool $conAutorizacion = false): array
    {
        $guarderia = Guarderia::factory()->create();

        [$nino, $responsable, $operador] = $this->enGuarderia($guarderia, fn (): array => [
            Nino::factory()->de($guarderia)->create(),
            Responsable::factory()->de($guarderia)->create(),
            Usuario::factory()->de($guarderia)->administrativo()->create(),
        ]);

        if ($conAutorizacion) {
            $this->crearAutorizacionVigiente($guarderia, $nino, $responsable, $operador);
        }

        $this->darIngreso($guarderia, $nino, $operador);

        return [$guarderia, $nino, $responsable, $operador];
    }

    /**
     * Intenta el retiro y no deja que la excepción corte la prueba.
     *
     * Lo que se prueba aquí es QUÉ QUEDA ESCRITO, no sólo si se lanzó.
     */
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
            } catch (\App\Exceptions\DomainException $error) {
                // Lo esperado en el caso sin autorización. La prueba mira lo que
                // quedó escrito en la base, que es lo que importa.
                $this->assertNotEmpty($error->getMessage(), 'El error debe explicar qué pasó.');
            }
        });
    }
}