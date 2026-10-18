<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base de todas las pruebas de Guardería.
 *
 * LA HORA FIJA, Y POR QUÉ
 * -----------------------
 * La vigencia de una autorización y el estado de una asistencia dependen de la
 * fecha. Sin congelarla, una prueba que se corre a las 23:59 puede dar un
 * resultado distinto de la que se corrió a las 00:01, y una prueba de
 * vencimiento de autorización que falla un día y pasa al otro es peor que
 * ninguna.
 *
 * `PRUEBAS_HORA_FIJADA` está en `phpunit.xml`.
 *
 * LA BASE DE PRUEBAS USA LAS MISMAS POLÍTICAS QUE LA DE PRODUCCIÓN
 * ---------------------------------------------------------------
 * Las pruebas de aislamiento NO son un adorno: corren contra PostgreSQL, con
 * Row Level Security instalada. Una prueba que corre contra SQLite no probaría
 * nada de esta aplicación, porque SQLite no tiene Row Level Security y el
 * aislamiento de Hefesto se apoya en ella.
 *
 * Por eso `database.default` es `pgsql` también en pruebas, y por eso las
 * migraciones de RLS se ejecutan. Ver docs/10.
 */
abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * La hora que todas las pruebas de esta clase van a ver.
     */
    protected function horaFija(): string
    {
        return (string) env('PRUEBAS_HORA_FIJADA', '2026-10-18T12:00:00+03:00');
    }
}