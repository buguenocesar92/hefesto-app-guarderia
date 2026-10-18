<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\RolGuarderia;
use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\Auth\DescubridorOidc;
use App\Services\Auth\ProveedorOidcAuthentik;
use App\Services\Auth\VerificadorIdToken;
use App\Services\Permisos\CapacidadesDeUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ingreso y salida de la aplicación.
 *
 * LA CONTRASEÑA NO PASA POR AQUÍ
 * -----------------------------
 * Authentik autentica. Esta aplicación recibe un código, lo cambia por un token
 * de identificación, verifica la firma y abre sesión. No hay contraseña en
 * ninguna tabla de esta base.
 *
 * EL ROL SE LEE DEL USUARIO, NO DEL EQUIPO EN CADA PETICIÓN
 * ------------------------------------------------------
 * Al ingresar se lee el equipo de Authentik y se determina el rol, pero sólo si
 * la fila `usuarios` todavía no existe. Después, el rol vive en la base.
 *
 * La razón está escrita en `App\Models\Usuario`: si el rol se recalculara en
 * cada ingreso, sacar a alguien de un equipo en Authentik lo desactivaría al
 * instante, y nadie revisa la base después de eso.
 *
 * Y hay una consecuencia que conviene que sea explícita: si la dirección cambia
 * un rol en Authentik y no actualiza `usuarios`, el cambio no se refleja acá. La
 * columna `rol_actualizado_en` existe para poder detectarlo. Ver docs/03.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly DescubridorOidc $descubridor,
        private readonly VerificadorIdToken $verificador,
        private readonly ProveedorOidcAuthentik $proveedor,
    ) {}

    /**
     * La dirección a la que hay que mandar a la persona para que se autentique.
     */
    public function ingreso(Request $peticion): JsonResponse
    {
        $slug = $peticion->query('guarderia');
        $redirigir = $peticion->query('redirigir');

        if (! is_string($slug) || $slug === '') {
            return response()->json([
                'mensaje' => 'Falta elegir la guardería.',
            ], 422);
        }

        // La búsqueda del `slug` va contra la VISTA PÚBLICA, no contra
        // `guarderias`. Antes del ingreso no se lee la tabla completa.
        $existe = DB::table('vw_guarderias_publicas')
            ->where('slug', $slug)
            ->exists();

        if (! $existe) {
            return response()->json([
                'mensaje' => 'No existe una guardería activa con ese identificador.',
            ], 404);
        }

        $url = $this->descubridor->urlDeAutorizacion();

        $parametros = [
            'client_id' => (string) config('authentik.client_id'),
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'redirect_uri' => (string) config('authentik.redirect_uri'),
            'state' => $this->estado($peticion),
            'guarderia' => $slug,
        ];

        if (is_string($redirigir) && $redirigir !== '') {
            $parametros['redirigir'] = $redirigir;
        }

        return response()->json([
            'url' => $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($parametros),
        ]);
    }

    /**
     * Intercambia el código por un token y abre sesión.
     */
    public function retorno(Request $peticion): JsonResponse
    {
        $codigo = $peticion->query('code');
        $slug = $peticion->session()->get('guarderia_slug');
        $estadoEsperado = $peticion->session()->get('estado_oidc');
        $estadoRecibido = $peticion->query('state');

        if (! is_string($codigo) || $codigo === '' || ! is_string($slug)) {
            return response()->json([
                'mensaje' => 'El ingreso no se completó. Vuelva a intentarlo.',
            ], 422);
        }

        // El `state` se compara con el que se guardó al salir. Sin esta
        // comparación, una URL de retorno con el `code` de otra sesión pegada en
        // el navegador abriría sesión con una credencial ajena.
        if (! is_string($estadoEsperado) || $estadoEsperado === '' || $estadoRecibido !== $estadoEsperado) {
            $peticion->session()->forget('estado_oidc');
            $peticion->session()->forget('guarderia_slug');

            return response()->json([
                'mensaje' => 'El ingreso no se completó. Vuelva a intentarlo desde el ingreso.',
            ], 403);
        }

        $peticion->session()->forget('estado_oidc');

        $afirmaciones = $this->verificador->verificar(
            $this->pedirToken($codigo)
        );

        $sub = $afirmaciones['sub'] ?? null;

        if (! is_string($sub) || $sub === '') {
            return response()->json([
                'mensaje' => 'El token de identificación no trae un sujeto.',
            ], 422);
        }

        $guarderia = DB::table('vw_guarderias_publicas')
            ->where('slug', $slug)
            ->first();

        if ($guarderia === null) {
            return response()->json([
                'mensaje' => 'La guardería elegida ya no está activa.',
            ], 404);
        }

        $rol = $this->resolverRol($afirmaciones);

        if ($rol === null) {
            return response()->json([
                'mensaje' => 'Su usuario no pertenece a ningún equipo de esta guardería. '
                    . 'Consulte a la dirección del centro para que le asignen un rol.',
            ], 403);
        }

        $usuario = Usuario::query()->firstOrNew([
            'tenant_id' => $guarderia->id,
            'sub' => $sub,
        ]);

        $usuario->fill([
            'correo' => (string) ($afirmaciones['email'] ?? $usuario->correo ?? ''),
            'nombres' => (string) ($afirmaciones['given_name'] ?? $usuario->nombres ?? ''),
            'apellidos' => (string) ($afirmaciones['family_name'] ?? $usuario->apellidos ?? ''),
            'rol' => $rol,
            'activo' => true,
        ]);

        if (! $usuario->exists) {
            // El rol se escribe aquí, en el primer ingreso, y después queda. La
            // fecha dice cuándo se resolvió por última vez desde la fila.
            $usuario->rol_actualizado_en = now();
        }

        $usuario->save();

        $peticion->session()->regenerate();
        $peticion->session()->put('usuario_sub', $usuario->sub);
        $peticion->session()->put('guarderia_id', $guarderia->id);
        $peticion->session()->put('rol', $usuario->rol->value);
        $peticion->session()->put('capacidades', CapacidadesDeUsuario::de($usuario)->aArreglo());

        return response()->json([
            'usuario' => [
                'nombres' => $usuario->nombres,
                'apellidos' => $usuario->apellidos,
                'rol' => $usuario->rol->value,
            ],
        ]);
    }

    /**
     * Quién está ingressado, con sus capacidades.
     *
     * La interfaz no decide permisos: los lee acá. Ver
     * `CapacidadesDeUsuario::aArreglo()`.
     */
    public function yo(Request $peticion): JsonResponse
    {
        $usuario = $peticion->attributes->get('usuario');

        if (! $usuario instanceof Usuario) {
            return response()->json([
                'mensaje' => 'No hay sesión abierta.',
            ], 401);
        }

        $capacidades = CapacidadesDeUsuario::de($usuario)->aArreglo();

        return response()->json([
            'usuario' => [
                'nombres' => $usuario->nombres,
                'apellidos' => $usuario->apellidos,
                'rol' => $usuario->rol->value,
                'etiqueta_rol' => $usuario->rol->etiqueta(),
            ],
            'capacidades' => $capacidades,
        ]);
    }

    /**
     * Cierra la sesión.
     */
    public function salida(Request $peticion): JsonResponse
    {
        $peticion->session()->flush();
        $peticion->session()->invalidate();
        $peticion->session()->regenerateToken();

        return response()->json([
            'mensaje' => 'Sesión cerrada.',
        ]);
    }

    /**
     * Cambia el código por un token de identificación.
     */
    private function pedirToken(string $codigo): string
    {
        $respuesta = $this->proveedor->obtenerConFormulario(
            $this->descubridor->urlDeToken(),
            [
                'grant_type' => 'authorization_code',
                'code' => $codigo,
                'redirect_uri' => (string) config('authentik.redirect_uri'),
                'client_id' => (string) config('authentik.client_id'),
                'client_secret' => (string) config('authentik.client_secret'),
            ],
        );

        $datos = json_decode($respuesta, true);
        $token = is_array($datos) ? ($datos['id_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('El proveedor de identidad no devolvió un token de identificación.');
        }

        return $token;
    }

    /**
     * El rol, deducido de los equipos de Authentik.
     *
     * Se recorre de MÁS alto a MÁS bajo a propósito. Si alguien está en dos
     * equipos, gana el de más permisos, porque el error de configuración
     * corriente es dejar a la gente en varios.
     */
    private function resolverRol(array $afirmaciones): ?RolGuarderia
    {
        $equipos = $afirmaciones[(string) config('authentik.claim_equipos', 'groups')] ?? [];

        if (! is_array($equipos)) {
            return null;
        }

        $nombres = array_map(
            static fn (mixed $equipo): string => mb_strtolower(trim((string) $equipo)),
            $equipos,
        );

        foreach ([RolGuarderia::Direccion, RolGuarderia::Administrativo, RolGuarderia::Educadora] as $rol) {
            if (in_array(mb_strtolower($rol->equipoAuthentik((string) config('authentik.grupo_por_guarderia', 'Guarderia'))), $nombres, true)) {
                return $rol;
            }
        }

        return null;
    }

    /**
     * El estado anti-falsificación de la petición de ingreso.
     *
     * Se guarda en sesión y se compara al volver. Es lo que impide que alguien
     * pegue una URL de retorno con un `code` de otra sesión.
     */
    private function estado(Request $peticion): string
    {
        $estado = bin2hex(random_bytes(16));

        $peticion->session()->put('estado_oidc', $estado);
        $peticion->session()->put('guarderia_slug', $peticion->query('guarderia'));

        return $estado;
    }
}