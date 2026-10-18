<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Auth\DescubridorOidc;
use App\Services\Auth\VerificadorIdToken;
use App\Services\Autorizaciones\EvaluadorDeAutorizacion;
use App\Services\Tenancy\AplicaContextoTenant;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Servicios de la aplicación.
 *
 * QUÉ SE ENLAZA ACÁ
 * -----------------
 * Solamente las clases que llevan configuración o estado: los servicios de OIDC
 * y los del aislamiento entre guarderías.
 *
 * LO QUE NO SE ENLAZA
 * -------------------
 * LAS POLICIES. Cada ruta vuelve a preguntar a su `Policy`, y no hay un permiso
 * declarado en un middleware que se pueda olvidar. Ver docs/03.
 *
 * `EvaluadorDeAutorizacion` tampoco se enlaza: se resuelve por contenedor
 * cuando se necesita, y no tiene estado entre llamadas.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ContextoTenant::class);
        $this->app->singleton(AplicaContextoTenant::class);
        $this->app->singleton(DescubridorOidc::class);
        $this->app->singleton(VerificadorIdToken::class);
    }

    public function boot(): void
    {
        // El límite de la pantalla de ingreso. Se cuenta por dirección y no
        // por cuenta, porque en esta aplicación la cuenta todavía no existe:
        // quien está ingresando es alguien que todavía no se identificó.
        RateLimiter::for('ingreso', static function (Request $peticion): Limit {
            return Limit::perMinute(10)->by($peticion->ip());
        });

        // El límite de los intentos de retiro.
        //
        // Un límite de retiro no es una medida de seguridad contra un atacante:
        // es una protección contra el error humano de apretar el botón veinte
        // veces y llenar el registro de veinte filas del mismo retiro.
        //
        // Y por eso el registro del RECHAZO nunca se pierde por esto: el
        // rechazo escribe en su propia transacción y no depende de que la
        // escritura del retiro llegue a terminar.
        RateLimiter::for('retiros', static function (Request $peticion): Limit {
            return Limit::perMinute(30)->by($peticion->user()?->id ?: $peticion->ip());
        });
    }
}