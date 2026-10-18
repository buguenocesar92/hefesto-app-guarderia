<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use RuntimeException;

/**
 * Verificación de la firma del token de identificación.
 *
 * POR QUÉ SE VERIFICA LA FIRMA Y NO SOLO EL VENCIMIENTO
 * ---------------------------------------------------
 * Porque un token sin verificar es un texto que cualquiera puede escribir. Si
 * sólo se mirara la fecha de vencimiento, una cadena falsa con `exp` en el
 * futuro daría por válida a cualquiera.
 *
 * Lo que se comprueba, en orden:
 *   1. La firma, con la llave pública del proveedor de identidad.
 *   2. El emisor, exactamente el que dice la configuración.
 *   3. La audiencia, si la configuración declara una.
 *   4. El tiempo, con el margen de reloj de `authentik.leeway`.
 *   5. El algoritmo, contra el declarado en configuración. Sin esto, un token
 *      puede pedir `alg: none` y no se firma con nada.
 *
 * LA LLAVE PÚBLICA VIENE DEL PROVEEDOR, NO DEL ARCHIVO
 * ---------------------------------------------------
 * Se leen de `jwks_uri`, que sale del descubrimiento. La llave no está escrita
 * en el código de esta aplicación ni en su archivo de configuración, y no puede
 * estarlo: cambia cuando alguien rota el secreto del proveedor.
 */
final class VerificadorIdToken
{
    public function __construct(private readonly DescubridorOidc $descubridor) {}

    /**
     * Verifica el token y devuelve sus afirmaciones.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function verificar(string $token): array
    {
        $partes = explode('.', $token);

        if (count($partes) !== 3) {
            throw new RuntimeException('El token de identificación no tiene el formato esperado.');
        }

        [$encabezado, $cuerpo, $firma] = $partes;

        $algoritmo = (string) config('authentik.algoritmo', 'RS256');

        // El algoritmo se compara con el de configuración ANTES de verificar
        // nada. Si el token pide otro, no se toca.
        $algoritmoDeclarado = $this->decodificar($encabezado)['alg'] ?? null;

        if ($algoritmoDeclarado !== $algoritmo) {
            throw new RuntimeException(sprintf(
                'El token declara el algoritmo "%s" y esta aplicación espera "%s".',
                is_string($algoritmoDeclarado) ? $algoritmoDeclarado : 'desconocido',
                $algoritmo,
            ));
        }

        $afirmaciones = $this->decodificar($cuerpo);

        $this->comprobarEmisor($afirmaciones);
        $this->comprobarAudiencia($afirmaciones);
        $this->comprobarTiempo($afirmaciones);
        $this->comprobarFirma($encabezado, $cuerpo, $firma);

        /** @var array<string, mixed> $afirmaciones */
        return $afirmaciones;
    }

    /**
     * @param  array<string, mixed>  $afirmaciones
     */
    private function comprobarEmisor(array $afirmaciones): void
    {
        $esperado = (string) config('authentik.issuer');
        $encontrado = $afirmaciones['iss'] ?? null;

        if (! is_string($encontrado) || rtrim($encontrado, '/') !== rtrim($esperado, '/')) {
            throw new RuntimeException('El token no viene del proveedor de identidad configurado.');
        }
    }

    /**
     * @param  array<string, mixed>  $afirmaciones
     */
    private function comprobarAudiencia(array $afirmaciones): void
    {
        $esperada = config('authentik.audiencia');

        // Si la configuración no declara audiencia, no se comprueba. Es una
        // omisión explícita de quien despliega, no una excepción del código.
        if (! is_string($esperada) || $esperada === '') {
            return;
        }

        $encontrada = $afirmaciones['aud'] ?? null;
        $coincide = is_string($encontrada)
            ? $encontrada === $esperada
            : is_array($encontrada) && in_array($esperada, $encontrada, true);

        if (! $coincide) {
            throw new RuntimeException('El token no es para esta aplicación.');
        }
    }

    /**
     * @param  array<string, mixed>  $afirmaciones
     */
    private function comprobarTiempo(array $afirmaciones): void
    {
        $margen = (int) config('authentik.leeway', 60);
        $ahora = time();

        $emision = $afirmaciones['iat'] ?? null;
        $vencimiento = $afirmaciones['exp'] ?? null;

        if (! is_int($emision) || ! is_int($vencimiento)) {
            throw new RuntimeException('El token no declara sus tiempos de emisión y vencimiento.');
        }

        if ($ahora > $vencimiento + $margen) {
            throw new RuntimeException('El token ya venció.');
        }

        // Un token emitido en el futuro casi siempre es un reloj desfasado en el
        // proveedor. Con el margen se tolera; sin él, sería una caída.
        if ($emision - $margen > $ahora) {
            throw new RuntimeException('El token fue emitido en el futuro.');
        }
    }

    /**
     * Verifica la firma con las llaves públicas del proveedor.
     */
    private function comprobarFirma(string $encabezado, string $cuerpo, string $firma): void
    {
        if (! class_exists(JWK::class)) {
            throw new RuntimeException(
                'Falta la biblioteca de verificación de tokens. Ejecute composer install.'
            );
        }

        $respuesta = $this->descubridor->obtener($this->descubridor->urlDeJwks());
        $jwks = json_decode($respuesta, true);

        if (! is_array($jwks) || ! isset($jwks['keys']) || ! is_array($jwks['keys'])) {
            throw new RuntimeException(
                'No se pudieron obtener las llaves públicas del proveedor de identidad.'
            );
        }

        try {
            $llaves = JWK::parseKeySet($jwks);
        } catch (\Throwable $error) {
            throw new RuntimeException(
                'Las llaves públicas del proveedor de identidad no se pudieron interpretar.',
                previous: $error,
            );
        }

        try {
            JWT::decode($encabezado . '.' . $cuerpo, $llaves);
        } catch (\Throwable $error) {
            throw new RuntimeException(
                'La firma del token no corresponde a ninguna llave del proveedor de identidad.',
                previous: $error,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodificar(string $parte): array
    {
        $decodificado = $this->decodificarBase64Url($parte);
        $datos = json_decode($decodificado, true);

        if (! is_array($datos)) {
            throw new RuntimeException('El token de identificación tiene una parte ilegible.');
        }

        /** @var array<string, mixed> $datos */
        return $datos;
    }

    private function decodificarBase64Url(string $texto): string
    {
        $resto = strtr($texto, '-_', '+/');
        $relleno = strlen($resto) % 4;

        if ($relleno > 0) {
            $resto .= str_repeat('=', 4 - $relleno);
        }

        $decodificado = base64_decode($resto, true);

        if ($decodificado === false) {
            throw new RuntimeException('El token de identificación no está codificado como corresponde.');
        }

        return $decodificado;
    }
}