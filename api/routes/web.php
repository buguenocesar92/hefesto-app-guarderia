<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Autorizaciones\AutorizacionController;
use App\Http\Controllers\Api\Asistencia\AsistenciaController;
use App\Http\Controllers\Api\Incidencias\IncidenciaController;
use App\Http\Controllers\Api\Medicas\FichaMedicaController;
use App\Http\Controllers\Api\Ninos\NinoController;
use App\Http\Controllers\Api\Pagos\PagoController;
use App\Http\Controllers\Api\Panel\PanelController;
use App\Http\Controllers\Api\Responsables\ResponsableController;
use App\Http\Controllers\Api\Retiros\RetiroController;
use App\Http\Controllers\Publico\GuarderiasPublicasController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Rutas de la API
|------------------------------------------------------------------------------
|
| TODAS CUELGAN DEL GRUPO `web`
| ----------------------------
| La interfaz se autentica con cookie de sesión, y la cookie viaja en el mismo
| origen que el token CSRF. Por eso no hay ningún token de API en el código del
| navegador, y por eso las rutas usan el middleware `web` de Laravel y no uno
| propio.
|
| `ResolverTenant` se agrega al grupo `web` en `bootstrap/app.php` y envuelve
| cada petición autenticada en una transacción con el contexto de guardería
| fijado. Ver docs/01 y docs/03.
|
| LAS PANTALLAS DE FICHA MÉDICA LLEVAN `medica.auditada`
| ------------------------------------------------------
| Ese middleware anota en `accesos` cada lectura, cada escritura y cada intento
| denegado. Y es lo que hace que el registro no dependa de que alguien se acuerde
| de anotarlo en el controlador.
|
*/

/*
|------------------------------------------------------------------------------
| Público: lo que se puede pedir SIN sesión
|------------------------------------------------------------------------------
*/

// El selector de guardería de la pantalla de ingreso. La ÚNICA ruta que
// consulta `vw_guarderias_publicas`, y lo hace de verdad. Ver
// `Publico\GuarderiasPublicasController` y
// `tests/Feature/Aislamiento/VistaPublicaSeUsaTest.php`.
Route::get('/guarderias', [GuarderiasPublicasController::class, 'index'])
    ->name('guarderias.publicas');

/*
|------------------------------------------------------------------------------
| Sesión
|------------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function (): void {
    Route::get('/ingreso', [AuthController::class, 'ingreso'])->name('auth.ingreso');
    Route::get('/retorno', [AuthController::class, 'retorno'])->name('auth.retorno');

    Route::middleware('auth')->group(function (): void {
        Route::get('/yo', [AuthController::class, 'yo'])->name('auth.yo');
        Route::post('/salida', [AuthController::class, 'salida'])->name('auth.salida');
    });
});

/*
|------------------------------------------------------------------------------
| Lo que necesita sesión
|------------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function (): void {
    Route::get('/panel', [PanelController::class, 'index'])->name('panel');

    /*
     * Niño: la ruta del retiro va aparte del resource porque devuelve una cosa
     * distinta: el niño CON sus responsables y el estado de autorización de
     * cada uno. Es lo que la pantalla de retiro necesita para decidir el color
     * antes de que alguien intente.
     */
    Route::get('/ninos', [NinoController::class, 'index'])->name('ninos.index');
    Route::get('/ninos/{nino}', [NinoController::class, 'show'])->name('ninos.show');
    Route::get('/ninos/{nino}/retiro', [NinoController::class, 'paraRetiro'])->name('ninos.retiro');

    Route::get('/responsables', [ResponsableController::class, 'index'])->name('responsables.index');
    Route::get('/responsables/{responsable}', [ResponsableController::class, 'show'])->name('responsables.show');

    /*
     * Asistencia.
     */
    Route::get('/asistencias', [AsistenciaController::class, 'index'])->name('asistencias.index');
    Route::post('/ninos/{nino}/ingreso', [AsistenciaController::class, 'registrarIngreso'])
        ->middleware('throttle:retiros')
        ->name('asistencias.ingreso');
    Route::post('/ninos/{nino}/salida-temporal', [AsistenciaController::class, 'registrarSalidaTemporal'])
        ->middleware('throttle:retiros')
        ->name('asistencias.salida-temporal');
    Route::post('/ninos/{nino}/ausencia', [AsistenciaController::class, 'marcarAusencia'])
        ->name('asistencias.ausencia');

    /*
     * Retiros. La pantalla más importante de la aplicación.
     */
    Route::get('/ninos/{nino}/retiros', [RetiroController::class, 'index'])->name('retiros.index');
    Route::post('/ninos/{nino}/retiros', [RetiroController::class, 'store'])
        ->middleware('throttle:retiros')
        ->name('retiros.store');
    Route::get('/retiros/bitacora', [RetiroController::class, 'bitacora'])->name('retiros.bitacora');
    Route::get('/ninos/{nino}/retiros/estado/{responsable}', [RetiroController::class, 'estadoDeAutorizacion'])
        ->name('retiros.estado');

    /*
     * Autorizaciones. El alta y la renovación son de DIRECCIÓN; la lectura es
     * de todos los que operan la puerta.
     */
    Route::get('/ninos/{nino}/autorizaciones', [AutorizacionController::class, 'index'])
        ->name('autorizaciones.index');
    Route::post('/ninos/{nino}/autorizaciones', [AutorizacionController::class, 'store'])
        ->name('autorizaciones.store');
    Route::post('/autorizaciones/{autorizacion}/anular', [AutorizacionController::class, 'anular'])
        ->name('autorizaciones.anular');
    Route::get('/autorizaciones/por-vencer', [AutorizacionController::class, 'porVencer'])
        ->name('autorizaciones.por-vencer');

    /*
     * Ficha médica. TODAS estas rutas llevan `medica.auditada`.
     *
     * El middleware anota en `accesos` cada lectura, cada escritura y cada
     * intento denegado. Y el `Policy` (`FichaMedicaPolicy`) deja pasar a
     * `educadora` y `direccion`, y responde 404 a `administrativo`.
     */
    Route::middleware('medica.auditada')->group(function (): void {
        Route::get('/ninos/{nino}/ficha-medica', [FichaMedicaController::class, 'show'])
            ->name('fichas-medicas.show');
        Route::post('/ninos/{nino}/ficha-medica', [FichaMedicaController::class, 'store'])
            ->name('fichas-medicas.store');
    });

    /*
     * Pagos. La educadora no llega acá: `PagoPolicy` le responde 404.
     */
    Route::get('/pagos', [PagoController::class, 'index'])->name('pagos.index');
    Route::get('/ninos/{nino}/pagos', [PagoController::class, 'deNino'])->name('pagos.nino');
    Route::post('/pagos', [PagoController::class, 'store'])->name('pagos.store');
    Route::post('/pagos/{pago}/anular', [PagoController::class, 'anular'])->name('pagos.anular');

    /*
     * Incidencias. Sin ruta de borrado, y no es una omisión pendiente.
     */
    Route::get('/ninos/{nino}/incidencias', [IncidenciaController::class, 'index'])
        ->name('incidencias.index');
    Route::post('/ninos/{nino}/incidencias', [IncidenciaController::class, 'store'])
        ->name('incidencias.store');
    Route::get('/incidencias', [IncidenciaController::class, 'delCentro'])
        ->name('incidencias.centro');
});