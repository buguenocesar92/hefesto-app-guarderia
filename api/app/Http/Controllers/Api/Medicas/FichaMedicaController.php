<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Medicas;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarFichaMedicaRequest;
use App\Http\Resources\FichaMedicaResource;
use App\Models\FichaMedica;
use App\Models\Nino;
use App\Models\Usuario;
use App\Policies\FichaMedicaPolicy;
use App\Policies\NinoPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * La ficha médica de un niño.
 *
 * TODAS LAS RUTAS DE ESTE CONTROLADOR LLEVAN EL MIDDLEWARE `medica.auditada`
 * -----------------------------------------------------
 * Ver `routes/web.php`. Ese middleware anota en `accesos` cada lectura, cada
 * escritura y cada intento denegado, con el rol y la hora.
 *
 * EL ADMINISTRATIVO NO ENTRA
 * -------------------------
 * Ve la asistencia, los retiros, las autorizaciones y los pagos. No ve las
 * alergias ni la medicación de un niño, y no es que "no pueda saber": es que
 * la ficha médica no le corresponde.
 *
 * La respuesta es 404, no 403. Un 403 confirmaría que ese niño existe y que
 * tiene ficha. Ver `App\Policies\Concerns\RespondeNoEncontrado`.
 *
 * Ver docs/03 y docs/07.
 */
class FichaMedicaController extends Controller
{
    /**
     * La ficha de un niño.
     */
    public function show(Request $peticion, Nino $nino): JsonResponse
    {
        Gate::authorize([NinoPolicy::class, 'ver'], [$nino]);

        $ficha = FichaMedica::query()->where('nino_id', $nino->id)->first();

        if ($ficha === null) {
            Gate::authorize([FichaMedicaPolicy::class, 'ver'], [$nino->tenant_id]);

            return response()->json([
                'ficha' => null,
                'mensaje' => 'Este niño no tiene ficha médica registrada. '
                    . 'Sin ella no se puede saber qué servirle en el almuerzo.',
            ]);
        }

        Gate::authorize([FichaMedicaPolicy::class, 'ver'], [$ficha]);

        return response()->json([
            'ficha' => (new FichaMedicaResource($ficha))->resolve($peticion),
        ]);
    }

    /**
     * Crea o actualiza la ficha.
     *
     * Guardar una ficha es escribir sobre dato de salud de un menor, y por eso
     * tiene el MISMO permiso que leerla. No hay un "escribir sin poder leer".
     */
    public function store(GuardarFichaMedicaRequest $peticion, Nino $nino): JsonResponse
    {
        Gate::authorize([NinoPolicy::class, 'ver'], [$nino]);

        $usuario = $peticion->attributes->get('usuario');
        abort_unless($usuario instanceof Usuario, 401);

        $ficha = FichaMedica::query()->where('nino_id', $nino->id)->first();

        if ($ficha === null) {
            Gate::authorize([FichaMedicaPolicy::class, 'crear'], [$nino->tenant_id]);

            $ficha = new FichaMedica([
                'tenant_id' => $nino->tenant_id,
                'nino_id' => $nino->id,
            ]);
        } else {
            Gate::authorize([FichaMedicaPolicy::class, 'actualizar'], [$ficha]);
        }

        $ficha->fill($peticion->safe()->except('nino_id'));
        $ficha->actualizado_por = $usuario->id;
        $ficha->actualizado_en = now();
        $ficha->save();

        return response()->json([
            'ficha' => (new FichaMedicaResource($ficha->fresh()))->resolve($peticion),
        ], $ficha->wasRecentlyCreated ? 201 : 200);
    }
}