<?php

declare(strict_types=1);

use App\Exceptions\DomainException;
use App\Http\Middleware\AuditarFichaMedica;
use App\Http\Middleware\ResolverTenant;
use App\Http\Middleware\SoloDireccion;
use App\Providers\AppServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

/**
 * Punto de arranque de la API de Guarderia.
 *
 * Nota sobre el esqueleto: este archivo reemplaza al que genera
 * `composer create-project laravel/laravel`. El resto del esqueleto
 * (artisan, public/index.php, storage) lo genera Composer. Ver docs/04.
 *
 * Todas las rutas cuelgan del grupo `web` de Laravel porque la interfaz
 * (Nuxt) se autentica con cookie de sesion: la cookie viaja en el mismo
 * origen y el token CSRF se reenvia como cabecera X-XSRF-TOKEN. Eso es lo
 * que hace que no haya ningun token en el codigo del navegador. Ver docs/01.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/api/v1/salud',
    )
    ->withProviders([
        // Enlaza los servicios de OIDC con sus escalares de configuracion. Los
        // permisos NO se registran aca: cada ruta vuelve a preguntar a su
        // Policy. Ver docs/03.
        AppServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // ResolverTenant envuelve la peticion autenticada en una transaccion con
        // la guarderia ya fijada, para que la Row Level Security sepa de quien
        // son las filas. Corre ANTES que `auth`, por eso resuelve el usuario por
        // su cuenta con el guard `web`.
        $middleware->web(append: [
            ResolverTenant::class,
        ]);

        $middleware->alias([
            // Marca las rutas que abren la FICHA MEDICA de un nino: leerla o
            // guardarla. El middleware anota cada intento en `accesos`,
            // permitido o denegado, y corta con 404.
            //
            // Es la diferencia central de esta aplicacion con un sistema de
            // asistencia comum: aca las alergias, la alimentacion y la
            // medicacion de un menor son el dato mas sensible que hay. Ver
            // docs/03 y docs/07.
            'medica.auditada' => AuditarFichaMedica::class,

            // Direccion del centro: usuarios, planes y bitacora de accesos.
            'direccion' => SoloDireccion::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Un rechazo de autorizacion dentro de la API responde 404, no 403: un
        // 403 confirmaria que el recurso existe. En la ficha medica y en el
        // registro de retiros eso importa mas que en un sistema comun: no se le
        // dice a quien pregunto si ese nino existe.
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/v1/*')) {
                return response()->json([
                    'mensaje' => 'No encontrado.',
                ], 404);
            }

            return null;
        });

        // Un error de dominio del negocio -por ejemplo, retirar a un nino con
        // una autorizacion vencida- se traduce a 422 con un mensaje que la
        // persona puede leer. Un 500 haria pensar que la aplicacion se cayo, y
        // lo que paso es que el sistema hizo lo que debia.
        $exceptions->render(function (DomainException $e, Request $request) {
            if ($request->is('api/v1/*')) {
                return response()->json($e->aArreglo(), 422);
            }

            return null;
        });
    })
    ->create();