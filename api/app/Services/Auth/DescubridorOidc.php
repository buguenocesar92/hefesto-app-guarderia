<?php

declare(strict_types=1);

namespace App\Services\Auth;

use RuntimeException;

/**
 * Los metadatos de descubrimiento de Authentik.
 *
 * SE LEEN DE LA RED, NO SE COPIAN
 * -------------------------------
 * La aplicación NO lleva la URL de autorización ni la de token escritas en el
 * código. Las pide a
 * `<AUTHENTIK_ISSUER>/.well-known/openid-configuration`.
 *
 * La razón es práctica y es la causa número uno de una instalación rota: la
 * URL de redirect tiene que ser la que el NAVEGADOR alcanza, y en un despliegue
 * con tres contenedores hay tres nombres de host distintos. Si la URL está
 * escrita en el código, alguien tiene que acertar cuál de los tres es.
 *
 * Con descubrimiento automático, se pone una sola variable —el emisor— y el
 * resto sale de ahí. Ver docs/04.
 */
final class DescubridorOidc
{
    /** @var array<string, mixed>|null */
    private ?array $metadatos = null;

    public function __construct(private readonly ProveedorOidcAuthentik $proveedor) {}

    /**
     * Los metadatos, con caché.
     *
     * @return array<string, mixed>
     */
    public function metadatos(): array
    {
        if ($this->metadatos !== null) {
            return $this->metadatos;
        }

        $issuer = rtrim((string) config('authentik.issuer'), '/');

        if ($issuer === '') {
            throw new RuntimeException(
                'Falta AUTHENTIK_ISSUER. No se puede descubrir la configuración del proveedor de identidad.'
            );
        }

        $respuesta = $this->proveedor->obtener($issuer . '/.well-known/openid-configuration');

        $metadatos = json_decode($respuesta, true);

        if (! is_array($metadatos)) {
            throw new RuntimeException(
                'La configuración del proveedor de identidad no es un documento JSON válido.'
            );
        }

        $this->metadatos = $metadatos;

        return $metadatos;
    }

    public function urlDeAutorizacion(): string
    {
        return $this->exigir('authorization_endpoint');
    }

    public function urlDeToken(): string
    {
        return $this->exigir('token_endpoint');
    }

    public function urlDeJwks(): string
    {
        return $this->exigir('jwks_uri');
    }

    public function urlDeDeslogueo(): ?string
    {
        $valor = $this->metadatos()['end_session_endpoint'] ?? null;

        return is_string($valor) ? $valor : null;
    }

    /**
     * Una clave de los metadatos que de verdad tiene que estar.
     *
     * @return non-empty-string
     */
    private function exigir(string $clave): string
    {
        $valor = $this->metadatos()[$clave] ?? null;

        if (! is_string($valor) || $valor === '') {
            throw new RuntimeException(
                sprintf('La configuración del proveedor de identidad no declara "%s".', $clave)
            );
        }

        return $valor;
    }
}