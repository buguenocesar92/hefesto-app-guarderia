# 05 · Configuración

Cada variable de entorno, qué hace, qué pasa si queda vacía, y cuál quedó
**pendiente de verificación**.

---

## La lista de lo que está pendiente

Primero, porque es lo que hay que resolver antes de usar esto con datos de nadie.

| Qué | Dónde | Por qué está pendiente |
|---|---|---|
| **Los plazos legales de conservación** | `GUARDERIA_RETENCION_*` | **No se verificó** qué plazo corresponde a cada tipo de dato. Ver `docs/07`. |
| **El dominio** | `deploy/caddy/Caddyfile` | No hay un dominio de la instalación. Se deja `ejemplo.local` a propósito |
| **El tipo de documento** | `enum TipoDocumento` | La lista no es oficial: no se verificó el texto legal que la respalda |
| **Las tarifas** | `planes` | No se conoce lo que cobra cada guardería. La aplicación no trae ninguna |
| **El certificado de la autoridad interna** | `AUTHENTIK_CA` | Depende de su instalación de Authentik |
| **La normativa de habilitación de centros** | — | **No se leyó ninguna.** Esta aplicación no la conoce ni la cumple. Ver `docs/07` |

**Nada de eso se rellenó con un valor plausible.** Un valor inventado en un plazo
legal es peor que un vacío: se lee, se confía y se aplica.

---

## Aplicación

| Variable | Por omisión | Qué hace |
|---|---|---|
| `APP_NAME` | `Guarderia` | |
| `APP_ENV` | `local` | **`production` en producción** |
| `APP_KEY` | — | **Se genera.** Sin ella, la sesión no se cifra |
| `APP_DEBUG` | `false` | **Nunca `true` con datos de menores.** Un error puede incluir el contenido de una petición |
| `APP_URL` | — | La URL pública. En producción, con `https://` |
| `APP_TIMEZONE` | `America/Santiago` | |
| `APP_LOCALE` | `es` | |

### Sobre `APP_DEBUG`

Con `true`, Laravel muestra la traza del error: el archivo, la línea, y a veces
los valores de las variables.

En una aplicación con datos de menores, eso puede imprimir un nombre de niño en
la pantalla de quien la abrió el error.

En producción: **`false`**, siempre. Y si quiere ver los errores en desarrollo,
`php.ini` se cambia con el compose de desarrollo, no con esta variable.

---

## Base de datos

| Variable | Por omisión | Qué hace |
|---|---|---|
| `DB_CONNECTION` | `pgsql` | |
| `DB_HOST` | — | `postgres` dentro de Docker |
| `DB_PORT` | `5432` | |
| `DB_DATABASE` | — | |
| `DB_USERNAME` | — | **`guarderia_app`. El rol de la APLICACIÓN** |
| `DB_PASSWORD` | — | **La de `guarderia_app`** |
| `DB_SSLMODE` | `prefer` | **`require` en producción** |

### Y las del servidor

| Variable | Por omisión | Qué hace |
|---|---|---|
| `POSTGRES_DB` | `guarderia` | |
| `POSTGRES_USER` | `guarderia_admin` | **El rol de ARRANQUE. La aplicación NO lo usa** |
| `POSTGRES_PASSWORD` | — | La del rol de arranque |

### `DB_PASSWORD` y `POSTGRES_PASSWORD` SON DISTINTAS

Es el error número dos de una instalación nueva, y está en
[04 · Instalación](04-INSTALACION.md).

```
POSTGRES_USER=guarderia_admin     ->  el que arranca el clúster
POSTGRES_PASSWORD=xxx             ->  la de ese

DB_USERNAME=guarderia_app         ->  el que corre la aplicación
DB_PASSWORD=yyy                   ->  la de ese
```

El script de inicialización crea `guarderia_app` con **`DB_PASSWORD`**, y la
aplicación se conecta con **`DB_PASSWORD`**. Si pone la misma en las dos,
`guarderia_app` no existe con esa contraseña.

### Por qué dos roles

Por la parte 3 de [03 · Multi-tenancy y roles](03-MULTITENANCY-Y-ROLES.md), en
corto: **un superusuario siempre se salta las políticas de Row Level Security,
incluso con `FORCE`**.

---

## Redis

| Variable | Por omisión | Qué hace |
|---|---|---|
| `REDIS_HOST` | `redis` | |
| `REDIS_PORT` | `6379` | |
| `REDIS_PASSWORD` | — | |
| `CACHE_STORE` | `redis` | |
| `SESSION_DRIVER` | `database` | **No `file`** |
| `QUEUE_CONNECTION` | `sync` | |

### Por qué la sesión va en `database` y no en `file`

Porque una sesión en un archivo dentro del contenedor se pierde cuando el
contenedor se reinicia. En esta aplicación eso sería cerrar la sesión de una
educadora a mitad del ingreso de un niño.

**Redis no tiene datos de menores.** Sólo estado de sesión de Laravel y
limitadores. Si se pierde, quien estaba dentro tiene que entrar de nuevo.

---

## Authentik

| Variable | Por omisión | Qué hace |
|---|---|---|
| `AUTHENTIK_ISSUER` | — | **La URL que el NAVEGADOR alcanza.** Ver abajo |
| `AUTHENTIK_CLIENT_ID` | — | |
| `AUTHENTIK_CLIENT_SECRET` | — | |
| `AUTHENTIK_REDIRECT_URI` | — | `https://` y el dominio, en producción |
| `AUTHENTIK_ALGORITMO` | `RS256` | **No lo cambie sin saber qué está usando su Authentik** |
| `AUTHENTIK_AUDIENCE` | — | Vacío = **no se comprueba** |
| `AUTHENTIK_LEEWAY` | `60` | Tolerancia de reloj, en segundos |
| `AUTHENTIK_CACHE_METADATA` | `60` | Minutos de caché de los metadatos |
| `AUTHENTIK_CACHE_JWKS` | `1440` | Minutos de caché de las llaves |

### El error número uno

```
AUTHENTIK_ISSUER=http://authentik:9000/application/o/guarderia
```

Funciona desde dentro de Docker y **no desde el navegador**. El emisor del token
no coincide con el configurado, y el ingreso falla con "el token no viene del
proveedor de identidad configurado".

**Tiene que ser la URL que el navegador puede alcanzar.**

### `AUTHENTIK_AUDIENCE` vacío

Es una omisión explícita de quien despliega, no una excepción del código. Si la
deja vacía, la aplicación no comprueba la audiencia del token.

Eso es aceptable si el cliente de Authentik es de un solo uso. Si hay otras
aplicaciones usando el mismo emisor, **déjela puesta**: sin audiencia, un token
de otra aplicación del mismo emisor sería aceptado.

### Los equipos

| Variable | Por omisión |
|---|---|
| `AUTHENTIK_GRUPO_POR_GUARDERIA` | `Guarderia` |
| `AUTHENTIK_PREFIJO_EQUIPO_GUARDERIA` | `guarderia-` |
| `AUTHENTIK_EQUIPO_EDUCADORA` | `Educadoras de Guarderia` |
| `AUTHENTIK_EQUIPO_ADMINISTRATIVO` | `Administrativos de Guarderia` |
| `AUTHENTIK_EQUIPO_DIRECCION` | `Direccion de Guarderia` |
| `AUTHENTIK_CLAIM_GRUPOS` | `groups` |

**Los nombres llevan el nombre del producto a propósito.** Authentik es
compartido por todas las instalaciones de Hefesto: sin el nombre, una educadora
de una guardería sería educadora en todas.

El equipo con prefijo dice a qué guardería pertenece la persona:

```
guarderia-mi-guarderia
```

---

## Reglas de negocio

| Variable | Por omisión | Qué hace |
|---|---|---|
| `GUARDERIA_DIAS_AVISO_VENCIMIENTO` | `15` | Con cuánta antelación se avisa que una autorización vence |
| `GUARDERIA_VIGENCIA_MAXIMA_DIAS` | `730` | Máximo de días de una autorización en una línea |
| `GUARDERIA_MINUTOS_SIN_AVISO` | `60` | Minutos desde la jornada para considerar en ausencia a un niño que no llegó |

### `GUARDERIA_DIAS_AVISO_VENCIMIENTO`

**Es una ayuda de operación, NO un plazo legal.** El sistema avisa porque es útil,
no porque se haya verificado que deba.

Quince días es un valor razonable para hablar con una familia con tiempo. Si el
centro prefiere avisar un mes antes, ponga 30.

**Lo que importa es que el aviso ocurra ANTES de que expire.** Un sistema que avisa
cuando ya venció no está avisando: está dejando constancia.

### `GUARDERIA_MINUTOS_SIN_AVISO`

Regla de trabajo del centro, no una norma. Es el margen después del inicio de la
jornada para considerar que un niño que no registró ingreso y del que nadie avisó
está en ausencia.

---

## Conservación: DEJAR EN VACÍO

```dotenv
GUARDERIA_RETENCION_FICHAS_MEDICAS_DIAS=
GUARDERIA_RETENCION_ASISTENCIAS_DIAS=
GUARDERIA_RETENCION_RETIROS_DIAS=
GUARDERIA_RETENCION_ACCESOS_DIAS=
GUARDERIA_AVISAR_ANTES_DE_PURGAR=false
```

### Por qué están vacías, y por qué no hay que llenarlas a la ligera

**No se verificó** qué plazo de conservación corresponde a la ficha de un menor en
un centro infantil chileno, ni cuántos años se guardan los registros de asistencia
o de retiros.

Y aunque se verificara, **no hay un plazo único que sirva para todos los datos de
un centro**.

Escribir un número sin fuente sería inventar un plazo legal.

### Qué hace el sistema con un plazo vacío

**No borra nada.** No hay tarea programada que purgue, y no hay comando que borre.

### Cuándo llenarlas

Cuando el cliente lo decida, y dejando escrito **de dónde salió cada número**. El
campo debería llevar la fuente al lado, no sólo el número.

La explicación completa está en [07 · Seguridad](07-SEGURIDAD-Y-LEY-19628.md),
sección 6.

---

## Correo

```dotenv
MAIL_MAILER=log
```

### NO HAY CORREO SALIENTE IMPLEMENTADO

**Configurar el correo no habilita el envío.** No hay transporte, ni plantilla, ni
cola, ni una línea que diga "este mensaje se envió".

Por eso:

- El Job `AvisarAutorizacionesPorVencer` **escribe en el registro del servidor**,
  y su respuesta lleva `"correo_enviado": false` explícito.
- El comando `guarderia:autorizaciones-por-vencer` **imprime la lista** y dice que
  no manda correo.

Escribir "notificación enviada" sin transporte sería afirmar una capacidad que no
existe, y eso es exactamente lo que este proyecto no hace.

**Para agregarlo hay que escribir:** el transporte, la plantilla, la cola y las
pruebas. Está en [10 · Pruebas y estado](10-PRUEBAS-Y-ESTADO.md).

---

## La interfaz

| Variable | Por omisión | Qué hace |
|---|---|---|
| `NUXT_API_INTERNA` | `http://api:8000` | **La usa SÓLO el servidor.** El navegador nunca la ve |
| `NUXT_PUBLIC_NOMBRE` | `Guardería` | El nombre en la barra de título y en la pantalla de ingreso |
| `NUXT_PORT` | `3000` | |
| `NUXT_HOST` | `0.0.0.0` | |

### `runtimeConfig.public` no lleva la dirección de la API

A propósito. Lo que esté en `public` está en el código del navegador, y en esta
aplicación el código del navegador no incluye datos de menores ni la dirección
del servidor interno.

`NUXT_API_INTERNA` se compila DENTRO del paquete en el momento de la
construcción, por eso es un `ARG` de construction en `deploy/web/Dockerfile`.

---

## Pruebas

```dotenv
DB_TEST_DATABASE=guarderia_pruebas
PRUEBAS_HORA_FIJADA=2026-10-18T12:00:00+03:00
```

### `PRUEBAS_HORA_FIJADA`

La vigencia de una autorización y el estado de una asistencia dependen de la
fecha. Sin congelarla, una prueba que se corre a las 23:59 puede dar un resultado
distinto de la que se corrió a las 00:01.

Una prueba de vencimiento de autorización que falla un día y pasa al otro es peor
que ninguna.

### La base de pruebas usa PostgreSQL, con las MISMAS políticas

Las pruebas de aislamiento **no son un adorno**.

Una prueba que corre contra SQLite no probaría nada de esta aplicación, porque
SQLite no tiene Row Level Security, y el aislamiento de Hefesto se apoya en ella.
Una prueba así probaría que existe una columna `tenant_id`, y nada más.

---

## Un resumen de los valores por omisión

| Valor por omisión | ¿Es inventado? |
|---|---|
| `GUARDERIA_DIAS_AVISO_VENCIMIENTO=15` | **No.** Ayuda de operación. Se cambia según el centro |
| `GUARDERIA_VIGENCIA_MAXIMA_DIAS=730` | **No.** Límite de captura. No es un límite legal |
| `GUARDERIA_MINUTOS_SIN_AVISO=60` | **No.** Regla de trabajo |
| `AUTHENTIK_LEEWAY=60` | **No.** Tolerancia técnica de reloj |
| **Plazos de conservación** | **No hay ninguno.** Y esa es la decisión correcta |
| **Tarifas** | **No hay ninguna.** Y esa es la decisión correcta |
| `ejemplo.local` | **Marcado como pendiente.** No es un dominio real |
| `APP_KEY` vacío | **Se genera.** No se escribe a mano |