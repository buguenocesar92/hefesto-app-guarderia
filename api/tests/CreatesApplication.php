<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * El arranque de la aplicación en las pruebas.
 *
 * Laravel 13 pide este archivo aparte de `TestCase`: es lo que carga el
 * esqueleto antes de la primera prueba. Ver el esqueleto de Laravel y docs/04.
 */
trait CreatesApplication
{
    public function createApplication(): Application
    {
        /** @var Application $aplicacion */
        $aplicacion = require __DIR__ . '/../bootstrap/app.php';

        $aplicacion->make(Kernel::class)->bootstrap();

        return $aplicacion;
    }
}