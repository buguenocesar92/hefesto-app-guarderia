<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * Controlador base.
 *
 * HEREDA LAS DOS COSAS QUE NECESITA TODA RUTA
 * ------------------------------------------
 * Autorización de Policies y validación de formularios. Los controladores de
 * esta aplicación no registran `use AuthorizesRequests` uno por uno: heredan de
 * acá, que es donde el esqueleto de Laravel lo dejó.
 */
abstract class Controller extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;
}