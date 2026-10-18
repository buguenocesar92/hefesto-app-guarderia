<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Autenticación
|------------------------------------------------------------------------------
|
| EL MODELO ES `Usuario`, EN `usuarios`
| ------------------------------------
| No es `users`. En una aplicación que maneja datos de menores, una tabla
| `users` que en realidad guarda al personal del centro es una fuente de
| confusión innecesaria. Ver `App\Models\Usuario`.
|
| LA CONTRASEÑA NO ESTÁ EN ESTA APLICACIÓN
| ----------------------------------------
| Authentik autentica. Esta aplicación recibe un token de identificación, verifica
| su firma y abre sesión. No hay hashed de contraseña en esta base, y no hay
| ninguna forma de que se llegue a guardar uno: `Usuario::getAuthPassword()`
| devuelve un vacío porque es el único valor que la interfaz de Laravel exige,
| y porque devolver la contraseña real aquí sería devolver a guardarla.
|
| EL GUARD ES `web`, CON SESIÓN EN LA BASE
| ----------------------------------------
| La sesión va en `database` y no en `file`: una sesión en un archivo dentro del
| contenedor se pierde cuando el contenedor se reinicia, y en esta aplicación
| eso sería cerrar la sesión de una educadora a mitad del ingreso de un niño.
|
| Ver docs/01 y docs/04.
|
*/

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => null,
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'usuarios',
        ],
    ],

    'providers' => [
        'usuarios' => [
            'driver' => 'eloquent',
            'model' => App\Models\Usuario::class,
        ],
    ],

];