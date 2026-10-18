<?php

declare(strict_types=1);

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * El selector de guardería de la pantalla de ingreso.
 *
 * ESTE CONTROLADOR ES EL QUE USA LA VISTA PÚBLICA, Y DE VERDAD
 * -----------------------------------------------------------
 * La migración `*_crear_vista_guarderias_publicas.php` crea
 * `vw_guarderias_publicas`, y esta es la única ruta que la consulta.
 *
 * No lee `guarderias` crudo, porque esa tabla no lleva Row Level Security —se
 * consulta antes del ingreso, cuando no hay contexto— y leerla entera antes del
 * ingreso expondría razón social, dirección, teléfono y correo de centros con
 * los que la persona no tiene nada que ver.
 *
 * En aplicaciones anteriores de Hefesto se creó esta vista y nunca se consultó,
 * mientras la documentación afirmaba lo contrario. Acá:
 *
 *   - Este controlador la consulta, línea por línea.
 *   - `tests/Feature/Aislamiento/VistaPublicaSeUsaTest.php` falla si alguien lo
 *     cambia para leer `guarderias`.
 *   - `guarderia:verificar-aislamiento` falla si la vista deja de exponer
 *     exactamente sus tres columnas.
 *
 * Ver docs/03.
 */
class GuarderiasPublicasController extends Controller
{
    /**
     * La lista de guarderías para el selector de ingreso.
     */
    public function index(): JsonResponse
    {
        $guarderias = DB::table('vw_guarderias_publicas')
            ->select('id', 'nombre', 'slug')
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'guarderias' => $guarderias,
        ]);
    }
}