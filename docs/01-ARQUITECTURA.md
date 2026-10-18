# 01 · Arquitectura

Las tres capas de esta aplicación, y por qué están así.

---

## El dibujo

```
    +------------------------------------------------------------------+
    |                      EL NAVEGADOR                                |
    |                                                                  |
    |    Pantallas de Nuxt.  No hablan con la API.                    |
    |    No deciden permisos: los leen de las capacidades.            |
    +-------------------------------+----------------------------------+
                                    |
                          mismo origen, misma cookie
                                    |
    +-------------------------------v----------------------------------+
    |              SERVIDOR DE NUXT  (web, puerto 3000)               |
    |                                                                  |
    |    server/api/[...ruta].ts:  reenvía a la API                    |
    |    Reenvía las cabeceras de sesión y el cuerpo.                |
    |    No filtra respuestas, no decide permisos,                     |
    |    no guarda nada entre peticiones.                             |
    +-------------------------------+----------------------------------+
                                    |
                              red interna
                                    |
    +-------------------------------v----------------------------------+
    |                   LA API  (api, puerto 8000)                    |
    |                                                                  |
    |    nginx -> php-fpm -> Laravel                                   |
    |                                                                  |
    |    ResolverTenant: abre una transacción y fija                  |
    |    `app.guarderia_id` con alcance de TRANSACCIÓN.                |
    |                                                                  |
    |    Policies: qué puede ver cada rol.                             |
    |    La fila de la respuesta es 404 cuando no.                     |
    +-------------------------------+----------------------------------+
                                    |
                                    |
    +-------------------------------v----------------------------------+
    |                    POSTGRESQL  (postgres)                       |
    |                                                                  |
    |    `guarderia_admin`   rol de ARRANQUE. La aplicación            |
    |                        NO lo usa.                               |
    |                                                                  |
    |    `guarderia_app`     rol de la APLICACIÓN.                     |
    |                        NOSUPERUSER, NOBYPASSRLS.                |
    |                        Dueño del esquema, sujeto a las          |
    |                        políticas.                                |
    |                                                                  |
    |    14 tablas con Row Level Security FORZADA.                      |
    |    `guarderia_actual()` lee el contexto de la conexión.          |
    +------------------------------------------------------------------+
```

---

## Capa 1 · La interfaz

Nuxt 4 con TypeScript. Vive en `web/`.

**Lo que hace:** muestra. Toma lo que la API le devuelve y lo dibuja.

**Lo que NO hace:** no habla con la API de Laravel, y no decide permisos.

### Por qué el navegador no habla con la API

Porque la autenticación es una **cookie de sesión**, y una cookie que viaja a
otro origen hay que exponerla a mano, con credenciales y con un token CSRF aparte.
Con el proxy de Nuxt, la cookie es de mismo origen y no hay nada que exponer.

Todo el camino pasa por `server/api/[...ruta].ts`, que reenvía la petición a la
API con las cabeceras de sesión. El destino se arma ahí y **no lo elige el
cliente**: si el cliente pudiera elegir a dónde se mandan sus cookies, un valor
mal puesto mandaría la sesión a otro servidor.

### Por qué la interfaz no decide permisos

Porque duplicar la lista de roles en TypeScript es la forma más común de que
aparezca un botón que la API va a rechazar. Eso se ve como "el sistema a veces
deja ver cosas".

`composables/useSesion.ts` lee las capacidades que la API manda en `/auth/yo`. Las
pantallas usan `puede('abre_fichas_medicas')` para **no pintar** algo, no para
**permitir** algo. Si el navegador mintiera, la API igual responde 404.

---

## Capa 2 · La API

Laravel 13 sobre PHP 8.3. Vive en `api/`.

Un contenedor con nginx, php-fpm y supervisor. nginx traduce HTTP al protocolo de
proceso de PHP-FPM; `php.ini` va aparte; supervisor mantiene los dos procesos.

### El orden de las tres cosas, en cada petición

`ResolverTenant` hace exactamente esto, en este orden:

1. **Resuelve la sesión**, para saber QUIÉN es.
2. **Resuelve la guardería** desde esa sesión, y comprueba que el usuario siga
   activo. Si lo desactivaron mientras tenía la sesión abierta, esta petición es
   la primera en enterarse.
3. **Abre una transacción** con `app.guarderia_id` fijado.

El paso 3 es una transacción y no un `set_config` suelto porque el valor tiene
**alcance de transacción**. Con alcance de sesión sobreviviría al cierre de la
transacción, y la petición siguiente leería filas de la guardería anterior.

### Por qué la sesión va en la base y no en un archivo

Porque una sesión en un archivo dentro del contenedor se pierde cuando el
contenedor se reinicia. En esta aplicación eso sería cerrar la sesión de una
educadora a mitad del ingreso de un niño.

Redis queda para el estado en memoria del servidor de Nuxt y los limitadores.
**Ningún dato de menor está en Redis.**

### Por qué no hay token en el código del navegador

Porque no hace falta. La cookie viaja sola en el mismo origen, y el token CSRF
viaja en la cabecera que Laravel ya manda.

---

## Capa 3 · La base

PostgreSQL 17. Vive en `deploy/postgres/`.

### Las dos capas de protección

**La primera: la fila.** Cada tabla de negocio tiene `tenant_id`, con llave
foránea a `guarderias`.

**La segunda: la política.** Cada tabla tiene Row Level Security con `USING` y
`WITH CHECK`, y `FORCE ROW LEVEL SECURITY`.

La primera es comodidad: evita tener que acordarse de filtrar. La segunda es la
que protege, y no depende de que nadie se acuerde.

### El detalle que hace que funcione

`guarderia_actual()` es **`SECURITY INVOKER`**, no `SECURITY DEFINER`.

Con `DEFINER` correría con los privilegios de quien la creó, y las políticas se
vuelven decorativas: la base leería el valor del contexto, pero lo leería como si
fuera alguien con permiso para ver todo.

Y `FORCE ROW LEVEL SECURITY` existe por una razón separada: sin él, el DUEÑO de
una tabla tampoco está sujeto a sus propias políticas. Y en este despliegue el
dueño es el mismo rol que corre la aplicación.

`guarderia:verificar-aislamiento` comprueba las dos cosas, y además que una
consulta **sin contexto no lea ni una fila**. Ese último punto es el que importa:
los otros dicen cómo se instaló la protección; ése dice si funciona.

Detalle completo en [03 · Multi-tenancy y roles](03-MULTITENANCY-Y-ROLES.md).

---

## La excepción consciente: `guarderias`

`guarderias` **no** lleva Row Level Security, porque se consulta antes del
ingreso, cuando no hay sesión y por lo tanto no hay contexto que poner.

Para que eso no se convierta en una fuga, antes del ingreso la aplicación **no
lee la tabla**: lee `vw_guarderias_publicas`, que expone **tres columnas**
(`id`, `nombre`, `slug`) y sólo de las guarderías activas. No expone razón social,
dirección, correo ni teléfono.

Y la vista **se usa de verdad**: la consulta `Publico\GuarderiasPublicasController`,
la vigila una prueba que lee el código fuente, y
`guarderia:verificar-aislamiento` falla si deja de exponer esas tres columnas.

---

## La decisión estructural más importante: la ficha médica aparte

`ninos` y `responsables` **no** tienen nada de salud. Las alergias, la
alimentación y la medicación viven en `fichas_medicas`, que es su propia tabla.

La razón es directa:

- `ninos` se lista en pantalla, se consulta desde el panel, y la lee el rol
  administrativo. Si las alergias estuvieran ahí, la ficha dejaría de estar
  protegida el día que alguien escribiera una consulta a `ninos` para armar una
  lista.
- `fichas_medicas` se abre por una ruta sola, con un middleware que anota cada
  lectura, cada escritura y cada intento denegado.

Escribirlo en el modelo `Nino.php` y en el `NinoResource` es a propósito:
es el tipo de decisión que se deshace en una tarde si nadie la escribió.

---

## Lo que la aplicación NO hace

Para que no haya que deducirlo:

| No hace | Por qué |
|---|---|
| No manda correo | No hay transporte implementado. El aviso de vencimientos va al registro del servidor. |
| No borra datos | No hay tarea de purga. Los plazos están en `null` porque no se verificaron. Ver `docs/07`. |
| No trae tarifas | Los precios los carga cada centro. |
| No valida el RUT | No se verificó contra el registro civil. Se guarda lo que la persona declara. |
| No valida contenido clínico | No sabe si una alergia es probable ni si una dosis es la correcta. |
| No tiene `DELETE` en cuatro tablas | La base lo rechaza con un disparador. Ver `docs/07`. |

Ver la lista completa en [10 · Pruebas y estado](10-PRUEBAS-Y-ESTADO.md).

---

## Cómo circula una petición

Un retiro, de punta a punta:

```
 1. El operador busca al niño por apellido.
        web/app/pages/retiros.vue
        GET /api/ninos?buscar=alarcón

 2. Nuxt reenvía a la API.
        web/server/api/[...ruta].ts
        GET http://api:8000/api/v1/ninos?buscar=alarcón

 3. ResolverTenant abre la transacción y fija la guardería.
        app/Http/Middleware/ResolverTenant.php
        SELECT set_config('app.guarderia_id', '<uuid>', true)

 4. La Policy revisa el rol y el inquilino.
        app/Policies/NinoPolicy.php
        Si no corresponde: AuthorizationException -> 404

 5. La Row Level Security filtra, aunque la aplicación no lo haga.
        USING (tenant_id = guarderia_actual())

 6. La pantalla muestra al niño, sus responsables y QUÉ PUEDE RETIRARLO HOY,
    con el estado calculado por el servidor.
        GET /api/v1/ninos/{nino}/retiro
        EvaluadorDeAutorizacion: la fecha MANDA sobre el estado guardado

 7. El operador toca un responsable autorizado.
        POST /api/v1/ninos/{nino}/retiros

 8. ServicioRetiro comprueba la autorización con la fecha de HOY.
        app/Services/Retiros/ServicioRetiro.php

 9a. Si sirve:    escribe el retiro, cierra la asistencia, devuelve 201.
 9b. Si NO sirve: escribe el RECHAZO en su propia transacción, y DESPUÉS
                  lanza la excepción de dominio -> 422 con el motivo.
                  El registro del rechazo sobrevive al error.
```

El paso 9b es el que define esta aplicación. Ver
[07 · Seguridad y Ley 19.628](07-SEGURIDAD-Y-LEY-19628.md).