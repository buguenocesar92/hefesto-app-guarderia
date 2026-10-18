# 04 · Instalación

De cero a funcionando, en desarrollo y en producción.

**Nada de esto se ha ejecutado.** Ver
[10 · Pruebas y estado](10-PRUEBAS-Y-ESTADO.md).

---

## Antes de empezar

### Lo que hace falta

| Herramienta | Para qué |
|---|---|
| Docker y `docker compose` | Todo lo demás corre adentro |
| Un dominio que apunte a esta máquina | **Sólo en producción**: para el certificado |
| Authentik, con una aplicación OIDC creada | La autenticación |

### Sobre PHP, Composer y Node

**No hacen falta en la máquina.** Las imágenes de `deploy/api/Dockerfile` y
`deploy/web/Dockerfile` los traen dentro y construyen el proyecto ahí.

La única excepción es si quiere correr las pruebas en la máquina sin contenedor.

---

## Desarrollo

### 1 · Los archivos de entorno

```bash
cd apps/guarderia

cp api/.env.example api/.env
cp web/.env.example web/.env
```

Complete los valores. Lo que está vacío y hay que completar:

**En `api/.env`:**

| Variable | Qué poner |
|---|---|
| `APP_KEY` | Lo genera el contenedor en el primer arranque |
| `POSTGRES_PASSWORD` | Una contraseña. La que usted ponga |
| `DB_PASSWORD` | **La misma.** Es la contraseña de `guarderia_app` |
| `AUTHENTIK_ISSUER` | La URL de su Authentik |
| `AUTHENTIK_CLIENT_ID` | El identificador de la aplicación OIDC |
| `AUTHENTIK_CLIENT_SECRET` | El secreto |

**El detalle de `DB_PASSWORD`:** el script de inicialización crea
`guarderia_app` con esa contraseña, y la aplicación se conecta con ella. Si son
distintas, la aplicación no entra.

### 2 · Levantar

```bash
docker compose -f deploy/docker-compose.dev.yml up -d
```

### 3 · Verificar que arrancó

```bash
docker compose -f deploy/docker-compose.dev.yml ps
```

Y la salud de la API:

```bash
docker compose -f deploy/docker-compose.dev.yml exec api \
  curl -s http://127.0.0.1:8000/api/v1/salud
```

Debería responder `{"estado":"ok","servicio":"api-guarderia"}`.

### 4 · Crear la guardería

```bash
docker compose -f deploy/docker-compose.dev.yml exec api \
  php artisan guarderia:crear --nombre="Mi Guardería" --slug="mi-guarderia"
```

El `slug` tiene que ser único, en minúsculas y con guiones.

### 5 · VERIFICAR EL AISLAMIENTO

```bash
docker compose -f deploy/docker-compose.dev.yml exec api \
  php artisan guarderia:verificar-aislamiento
```

**Este paso no es opcional.** Dice si la protección entre guarderías está puesta.
Una instalación en la que este comando no se ha corrido **no está verificada**, y
no debería recibir al primer niño.

Lo que tiene que decir:

```
  Verificando el aislamiento entre guarderías.

  +---------------------------+-----+-------+-----------+
  | Tabla                     | RLS | FORCE | Politicas |
  +---------------------------+-----+-------+-----------+
  | accesos                   | si  | si    | 1         |
  ...
  Vista publica: id, nombre, slug.
  Consulta sin contexto sobre public.ninos: 0 filas. Correcto.

  Aislamiento verificado.
```

Si alguno de los dos últimos falla, **no siga**. La instalación no está protegida.

### 6 · Los equipos en Authentik

Antes del primer ingreso, hay que crear los equipos. Los nombres **llevan el
nombre del producto**, porque Authentik es compartido por todas las instalaciones
de Hefesto: sin el nombre, una educadora de una guardería sería educadora en todas.

| Equipo | Rol |
|---|---|
| `Educadoras de Guarderia` | Educadora |
| `Administrativos de Guarderia` | Administrativo |
| `Direccion de Guarderia` | Dirección |

Y un **equipo con prefijo** que diga a qué guardería pertenece la persona:

```
guarderia-mi-guarderia
```

Su contenido es el identificador o el nombre corto de la guardería.

### 7 · Entrar

Abra `http://localhost:8080`, elija la guardería e ingrese.

### 8 · (Opcional) Datos de demostración

```bash
docker compose -f deploy/docker-compose.dev.yml exec api \
  php artisan db:seed --class=GuarderiaDemoSeeder
```

Crea tres niños con sus responsables y autorizaciones. **El tercero tiene una
autorización vencida**, que es el caso que la pantalla de retiro tiene que marcar
en grande.

Los usuarios que crea tienen un `sub` inventado y **no pueden ingresar**. Las
personas reales se crean en su primer ingreso.

Y el plan que crea tiene **precio cero**: esta aplicación no trae tarifas.

---

## Producción

### 1 · El archivo de entorno

```bash
cp api/.env.example /ruta/segura/guarderia.env
```

**Fuera del control de versiones.** El `docker-compose.prod.yml` lo toma con
`env_file: ../api/.env`, así que si despliega con el repositorio, déjelo ahí pero
asegúrese de que `.gitignore` lo cubre. Y `.gitignore` sí lo cubre.

Complete lo mismo que en desarrollo, más:

| Variable | En producción |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | **`false`** |
| `APP_URL` | `https://` y el dominio real |
| `DB_SSLMODE` | `require` |
| `AUTHENTIK_REDIRECT_URI` | `https://` y el dominio real |
| `AUTHENTIK_CA` | El certificado de la autoridad interna |

### 2 · El dominio

Edite `deploy/caddy/Caddyfile` y ponga el dominio real:

```
guarderia.ejemplo.local {
```

Está en `ejemplo.local` **a propósito**: un dominio inventado haría que el
despliegue fallara con un error de DNS, en vez de fallar con un mensaje claro.

El dominio tiene que resolver a esta máquina, y los puertos 80 y 443 tienen que
llegar. Caddy pide el certificado a Let's Encrypt y lo renueva solo.

Si la instalación está detrás de un balanceador, quite `automatico` del bloque y
use `tls internal`.

### 3 · Levantar

```bash
docker compose -f deploy/docker-compose.prod.yml up -d
```

### 4 · Crear la guardería y verificar

Igual que en desarrollo. **El paso de verificar el aislamiento no es opcional
tampoco en producción**, y ahí es más importante: es la instalación que va a
contener datos de menores.

### 5 · Comprobar los roles de la base

```bash
# ¿Con qué rol se conecta?
docker compose -f deploy/docker-compose.prod.yml exec api \
  php artisan tinker --execute="echo DB::selectOne('select current_user as u')->u;"

# Debe decir: guarderia_app
```

Si dice `guarderia_admin`, **detenga el despliegue**. La protección entre
guarderías no estaría funcionando. Ver
[03 · Multi-tenancy y roles](03-MULTITENANCY-Y-ROLES.md), parte 3.

---

## Los tres errores que rompen la instalación

### El primero: `AUTHENTIK_ISSUER` con un nombre de contenedor

```
AUTHENTIK_ISSUER=http://authentik:9000/application/o/guarderia
```

Funciona **desde dentro de la red de Docker** y no desde el navegador. El
proveedor de identidad manda un `issuer` en el token que dice la URL pública, y
la aplicación lo compara con el que tiene configurado: no coinciden, y el ingreso
falla con "el token no viene del proveedor de identidad configurado".

**La tiene que ser la URL que el NAVEGADOR puede alcanzar.** Ése es el error
número uno de una instalación nueva de esta aplicación.

### El segundo: `POSTGRES_PASSWORD` y `DB_PASSWORD` distintas

`POSTGRES_USER` es el rol de arranque. `DB_USERNAME` es el rol de la aplicación.
Son dos roles distintos, con contraseñas distintas, y **cada variable es la de su
rol**.

El script de inicialización crea `guarderia_app` con `DB_PASSWORD`, y la
aplicación se conecta con `DB_PASSWORD`. Si pone la contraseña de arranque en los
dos, `guarderia_app` no existe con esa contraseña y la aplicación no entra.

### El tercero: el volumen ya existía

`/docker-entrypoint-initdb.d/` corre **una sola vez**: cuando el volumen de
PostgreSQL está vacío.

Si el volumen ya existía, el script del rol **no se volvió a ejecutar**, y la base
no tiene `guarderia_app`. La aplicación falla con un error de autenticación que no
dice nada sobre roles.

Cómo se reconoce: el error es de conexión, no de aplicación, y el rol no aparece
en la lista:

```sql
SELECT rolname, rolsuper FROM pg_roles;
```

Y si necesita empezar de cero **en desarrollo, con datos que no valen nada**:

```bash
docker compose -f deploy/docker-compose.dev.yml down -v
```

El `-v` **borra los volúmenes**. En producción no lo ejecute: borra la base con
los datos de los menores. Ver [08 · Respaldos](08-RESPALDOS.md).

---

## Las tareas del día

```bash
# Las autorizaciones por vencer, ANTES de que expiren.
docker compose -f deploy/docker-compose.prod.yml exec api \
  php artisan guarderia:autorizaciones-por-vencer

# Con otra ventana:
docker compose -f deploy/docker-compose.prod.yml exec api \
  php artisan guarderia:autorizaciones-por-vencer --dias=45

# El respaldo. Daily.
deploy/respaldos/respaldo.sh --directorio /var/respaldo/guarderia
```

Sobre la programación automática: ver [08 · Respaldos](08-RESPALDOS.md), sección
"La retención y el cron". En resumen, la **retención no se programa**, porque pide
confirmación.

---

## Qué hacer ANTES de recibir al primer niño

En orden. Los dos primeros no son opcionales.

1. **`guarderia:verificar-aislamiento`.** Sin esto, la instalación no está
   verificada.
2. **Comprobar el rol con que se conecta la aplicación**, y que no sea
   superusuario. Ver la parte 3 de [03](03-MULTITENANCY-Y-ROLES.md).
3. **Crear los equipos en Authentik**, con el nombre del producto.
4. **Probar un ingreso real** con cada uno de los tres roles.
5. **Probar un retiro sin autorización**, y ver que el rechazo aparece en grande
   y queda registrado.
6. **Probar un retiro con autorización vencida**, y ver lo mismo.
7. **Abrir la ficha médica con el rol administrativo** y ver que da 404, no 403.
8. **Correr un respaldo** y ver que sale `COMPLETO`.
9. **Probar la restauración** en un entorno que NO sea el de producción.

---

## Los registros

| Servicio | Dónde |
|---|---|
| API | Salida estándar del contenedor |
| Interfaz | Salida estándar del contenedor |
| Proxy | Salida estándar del contenedor |
| PostgreSQL | Salida estándar del contenedor |

Todos van a la salida estándar, no a archivos dentro del contenedor.

**La razón es concreta:** un archivo de registro dentro del contenedor desaparece
cuando se recrea, y en esta aplicación eso puede ser el registro de quién abrió
la ficha médica de un menor.

Recógelos con `docker compose logs`, o con el agregador de su orquestador.

---

## Para parar y arrancar

```bash
# Parar
docker compose -f deploy/docker-compose.prod.yml stop

# Arrancar
docker compose -f deploy/docker-compose.prod.yml start

# Reconstruir la API, después de cambiar el código
docker compose -f deploy/docker-compose.prod.yml up -d --build api

# Reconstruir la interfaz
docker compose -f deploy/docker-compose.prod.yml up -d --build web
```

`docker compose down` **conserva** los volúmenes. Sólo `down -v` los borra. Ver el
tercer error, más arriba.