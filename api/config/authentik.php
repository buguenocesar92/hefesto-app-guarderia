<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Configuracion de Authentik (OpenID Connect)
|------------------------------------------------------------------------------
|
| La contrasena nunca pasa por esta aplicacion. Authentik autentica; esta
| aplicacion recibe un token de identificacion, lo verifica y abre sesion.
|
| Los metadatos de descubrimiento se leen de
| `<AUTHENTIK_ISSUER>/.well-known/openid-configuration`, asi que el emisor
| tiene que ser la URL que el NAVEGADOR puede alcanzar, no el nombre de un
| contenedor de Docker. Es el error de despliegue mas comun en esta aplicacion.
|
| Ver docs/04 y docs/05.
|
*/

return [

    'issuer' => env('AUTHENTIK_ISSUER'),

    'client_id' => env('AUTHENTIK_CLIENT_ID'),

    'client_secret' => env('AUTHENTIK_CLIENT_SECRET'),

    'redirect_uri' => env('AUTHENTIK_REDIRECT_URI'),

    'algoritmo' => env('AUTHENTIK_ALGORITMO', 'RS256'),

    'audiencia' => env('AUTHENTIK_AUDIENCE'),

    // Tolerancia, en segundos, para la diferencia de reloj entre el servidor y
    // el proveedor de identidad. Sin esto, una instalación con reloj desfasado
    // falla el ingreso con un error que no dice nada util.
    'margen' => (int) env('AUTHENTIK_LEEWAY', 60),

    // Minutos de cache de los metadatos de descubrimiento.
    'cache_metadatos' => (int) env('AUTHENTIK_CACHE_METADATA', 60),

    // Minutos de cache de las llaves publicas (JWKS).
    'cache_jwks' => (int) env('AUTHENTIK_CACHE_JWKS', 1440),

    /*
    |----------------------------------------------------------------------
    | Equipos que declaran el rol y la guarderia
    |----------------------------------------------------------------------
    |
    | El equipo con el prefijo declara a que guarderia pertenece la persona.
    | Su contenido es el identificador o el slug de la guarderia.
    |
    | Los nombres de los tres equipos SIEMPRE llevan el nombre del producto.
    | Authentik es compartido por todas las instalaciones de Hefesto: sin el
    | nombre, una educadora de una guarderia seria educadora en todas.
    |
    */
    'grupo_por_guarderia' => env('AUTHENTIK_GRUPO_POR_GUARDERIA', 'Guarderia'),

    'prefijo_equipo_guarderia' => env('AUTHENTIK_PREFIJO_EQUIPO_GUARDERIA', 'guarderia-'),

    'equipo_educadora' => env('AUTHENTIK_EQUIPO_EDUCADORA', 'Educadoras de Guarderia'),

    'equipo_administrativo' => env('AUTHENTIK_EQUIPO_ADMINISTRATIVO', 'Administrativos de Guarderia'),

    'equipo_direccion' => env('AUTHENTIK_EQUIPO_DIRECCION', 'Direccion de Guarderia'),

    'claim_equipos' => env('AUTHENTIK_CLAIM_GRUPOS', 'groups'),

];