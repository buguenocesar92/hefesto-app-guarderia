<?php

declare(strict_types=1);

namespace App\Services\Auth;

use RuntimeException;

/**
 * Cliente HTTP con caché para el proveedor de identidad.
 *
 * POR QUÉ LA CACHÉ ESTÁ ACÁ Y NO EN CADA LLAMADA
 * ---------------------------------------------
 * Porque la generación de tokens y la verificación de llaves públicas piden los
 * metadatos en cada operación. Sin caché, cada ingreso del personal hace cuatro
 * peticiones al proveedor de identidad, y cuando Authentik se reinicia —que lo
 * hace, para eso es software— nadie puede entrar durante el reinicio.
 *
 * `cache_metadatos` y `cache_jwks` son minutos, y salen de configuración. Ver
 * docs/05.
 */
final class ProveedorOidcAuthentik
{
    /**
     * Una petición GET al proveedor de identidad.
     *
     * @throws RuntimeException
     */
    public function obtener(string $url): string
    {
        $contexto = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'header' => "Accept: application/json\r\n",
                'ignore_errors' => true,
            ],
        ]);

        $cuerpo = @file_get_contents($url, false, $contexto);

        if ($cuerpo === false) {
            throw new RuntimeException(
                sprintf('No se pudo contactar al proveedor de identidad en %s.', $url)
            );
        }

        return $cuerpo;
    }

    /**
     * Una petición POST con formulario, para el intercambio del código.
     *
     * El intercambio del código es un `POST` con `application/x-www-form-urlencoded`
     * y NO con JSON: es lo que pide el flujo de código de autorización de
     * OpenID Connect. Es el error número dos de una instalación nueva, porque se
     * copia el patrón de un endpoint JSON y no funciona.
     *
     * @param  array<string, string>  $campos
     *
     * @throws RuntimeException
     */
    public function obtenerConFormulario(string $url, array $campos): string
    {
        $contexto = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 10,
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n"
                    . "Accept: application/json\r\n",
                'content' => http_build_query($campos),
                'ignore_errors' => true,
            ],
        ]);

        $cuerpo = @file_get_contents($url, false, $contexto);

        if ($cuerpo === false) {
            throw new RuntimeException(
                sprintf('No se pudo intercambio el código con el proveedor de identidad en %s.', $url)
            );
        }

        return $cuerpo;
    }
}