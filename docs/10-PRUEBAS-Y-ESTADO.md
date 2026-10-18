# 10 · Pruebas y estado

Este documento dice la verdad sobre qué se ha comprobado y qué **no**.

---

# Lo primero, y lo más importante

## NADA SE HA EJECUTADO

En la máquina donde se escribió esta aplicación:

- **No hay PHP.**
- **No hay Composer.**
- **No hay Docker.**
- **No hay PostgreSQL.**
- **No hay Node.**

Por lo tanto:

| | |
|---|---|
| Migraciones aplicadas | **Cero** |
| Pruebas corridas | **Cero** |
| Imágenes construidas | **Cero** |
| Comandos de artisan ejecutados | **Cero** |
| Guiones de respaldo ejecutados | **Cero** |
| Contenedores levantados | **Cero** |
| Filas escritas en cualquier base | **Cero** |

**Ninguna instrucción de este README, de `docs/04` ni de `docs/08` se ha
probado.** Están escritas a partir de la lectura del código y de las aplicaciones
anteriores, no de haberlas corrido.

Y ningún archivo de este proyecto se presenta como probado. Un documento que
dijera "funciona" sin haberlo corrido sería falso.

---

# Qué sí se hizo, y qué no

## Lo que sí se hizo

| Qué | Cómo se comprobó |
|---|---|
| Que los guiones de shell **no tienen error de sintaxis** | `bash -n` sobre los cuatro |
| Que los archivos YAML **están bien formados** | Un analizador de YAML |
| Que **no hay caracteres fuera del español** | Un barrido de Unicode sobre los 130 archivos |
| Que **no hay secretos** | Un barrido de valores reales |
| Que las tablas de negocio tienen `tenant_id` | Lectura de las migraciones |
| Que hay Row Level Security en el código | Lectura de las migraciones |
| Que **no se pública un puerto de base de datos en producción** | Lectura del compose |
| Que **cada modelo corresponde a columnas que las migraciones crean** | Comparación a mano, modelo por modelo |

## Lo que NO se hizo

| Qué | Por qué |
|---|---|
| **Compilar PHP** | No hay PHP. Un error de sintaxis no se detectaría |
| **Instalar dependencias** | No hay Composer. Un nombre de clase equivocado no se detectaría |
| **Correr las migraciones** | No hay PostgreSQL. Un `ALTER TABLE` mal escrito no se detectaría |
| **Correr las pruebas** | No hay nada que las corra |
| **Construir las imágenes** | No hay Docker |
| **Levantar los contenedores** | No hay Docker |
| **Verificar el aislamiento** | Requiere una base real |
| **Probar el ingreso con Authentik** | No hay un Authentik |
| **Probar un retiro** | No hay base |
| **Sacar un respaldo** | No hay base |
| **Restaurar un respaldo** | No hay base |
| **Abrir la interfaz en un navegador** | No hay Node |

**Un error de sintaxis en cualquier archivo PHP de este proyecto sigue ahí.** Es lo
más probable que encuentre quien lo levante.

---

# Las pruebas que están escritas

Hay ocho archivos de prueba. **Ninguno se ha ejecutado.**

## Aislamiento

`tests/Feature/Aislamiento/AislamientoEntreGuarderiasTest.php`

| Prueba | Qué comprueba |
|---|---|
| `test_un_nino_de_una_guarderia_no_se_ve_desde_la_otra` | El aislamiento desde la aplicación |
| `test_la_fila_no_se_lee_sin_contexto` | Que la BASE no devuelve filas sin contexto |
| `test_la_funcion_de_contexto_devuelve_nulo_sin_contexto` | Fallar cerrado |
| `test_la_funcion_de_contexto_es_invoker` | Que no sea `SECURITY DEFINER` |
| `test_las_tablas_de_negocio_tienen_rls_forzada` | Las 14 tablas, activada y forzada |
| `test_la_politica_tiene_with_check` | Que no falte `WITH CHECK` |
| `test_los_registros_no_se_bordan` | Los 4 disparadores anti-`DELETE` |
| `test_el_contexto_es_de_alcance_de_transaccion` | Que el valor desaparezca al cerrar la transacción |

`tests/Feature/Aislamiento/VistaPublicaSeUsaTest.php`

| Prueba | Qué comprueba |
|---|---|
| `test_el_controlador_consulta_la_vista_y_no_la_tabla` | **Lee el código fuente** del controlador |
| `test_la_vista_expone_tres_columnas` | Exactamente `id`, `nombre`, `slug` |
| `test_la_vista_no_expone_datos_de_contacto` | Que no aparezca razón social ni dirección |
| `test_la_vista_solo_trae_guarderias_activas` | |
| `test_la_ruta_devuelve_las_columnas_de_la_vista` | Que la respuesta tenga 3 claves |

## Permisos

`tests/Feature/Permisos/ResponsableNoAutorizadoNoRetiraTest.php`

| Prueba | Qué comprueba |
|---|---|
| `test_un_responsable_sin_autorizacion_no_puede_retirar` | Que no haya retiro autorizado, y **que el rechazo quede con motivo y hora** |
| `test_el_rechazo_no_cierra_la_asistencia` | Que el niño **siga en el centro** |
| `test_un_responsable_de_otro_nino_no_puede_retirar` | La autorización es por niño, no por persona |
| `test_un_responsable_autorizado_si_puede_retirar` | La otra mitad: que deje pasar a quien sí puede |

`tests/Feature/Permisos/AutorizacionVencidaSeRechazaTest.php`

| Prueba | Qué comprueba |
|---|---|
| `test_una_autorizacion_vencida_no_permite_retirar` | Y que el motivo diga **la fecha** |
| `test_el_estado_guardado_no_anula_la_fecha_de_vencimiento` | **La fecha manda sobre el estado guardado** |
| `test_el_ultimo_dia_sirve_y_al_siguiente_no` | La frontera exacta |
| `test_una_autorizacion_anulada_no_permite_retirar` | Y con su motivo |
| `test_una_autorizacion_sin_fecha_sigue_sirviendo` | El caso `vigente_hasta IS NULL` |
| `test_el_aviso_ocurre_antes_del_vencimiento` | La ventana de aviso |

`tests/Feature/Permisos/AdministrativoNoLeeFichaMedicaTest.php`

| Prueba | Qué comprueba |
|---|---|
| `test_la_policy_rechaza_al_administrativo` | |
| `test_la_api_responde_404_y_no_403_al_administrativo` | **404, no 403** |
| `test_la_respuesta_no_confirma_que_la_ficha_exista` | Que el cuerpo no diga nada del recurso |
| `test_la_educadora_si_lee_la_ficha` | La otra mitad |
| `test_la_direccion_si_lee_la_ficha` | |
| `test_el_administrativo_no_puede_guardar_la_ficha` | Y que la ficha **no cambie** |
| `test_el_intento_denegado_queda_anotado` | En `accesos`, con el rol |
| `test_el_acceso_permitido_queda_anotado_con_rol_y_nino` | Con el rol y el niño |
| `test_la_lista_de_ninos_no_trae_el_contenido_de_la_ficha` | Que ningún listado la exponga |

## Unidad

`tests/Unit/EvaluadorDeAutorizacionTest.php`

Siete pruebas de la cuenta de fechas, **sin base de datos**. Es de las pocas cosas
que se podría correr en una máquina sin PostgreSQL.

---

# Cómo correrlo, en orden

## Antes de todo

**En una máquina con Docker, en un entorno que NO sea el de producción.** No se
prueba esto con datos de menores.

```bash
cd apps/guarderia

cp api/.env.example api/.env
cp web/.env.example web/.env
# Complete POSTGRES_PASSWORD y DB_PASSWORD con el mismo valor.

docker compose -f deploy/docker-compose.dev.yml up -d
```

## Paso 1 · La sintaxis, que sí se puede correr antes

```bash
docker compose -f deploy/docker-compose.dev.yml run --rm api sh -c "\
  find /var/www/html/app /var/www/html/config /var/www/html/routes \
       /var/www/html/database /var/www/html/tests -name '*.php' -print0 \
  | xargs -0 -n1 php -l"
```

Cada archivo debe decir `No syntax errors detected`.

**Este es el paso más probable que encuentre algo.** No hay PHP en la máquina donde
se escribió el proyecto, así que un error de sintaxis en un archivo es esperable.

## Paso 2 · Las dependencias

```bash
docker compose -f deploy/docker-compose.dev.yml run --rm api \
  composer install --no-interaction
```

## Paso 3 · Las migraciones

```bash
docker compose -f deploy/docker-compose.dev.yml run --rm api \
  php artisan migrate --force
```

**Las 14 migraciones tienen que pasar.** Si una falla, la instalación no sirve.

## Paso 4 · EL AISLAMIENTO

```bash
docker compose -f deploy/docker-compose.dev.yml run --rm api \
  php artisan guarderia:verificar-aislamiento
```

**Debe terminar con `Aislamiento verificado.`**

Y además, la comprobación que el comando **no** hace y hay que hacer a mano:

```bash
docker compose -f deploy/docker-compose.dev.yml run --rm api \
  php artisan tinker --execute="echo DB::selectOne('select current_user as u')->u;"

docker compose -f deploy/docker-compose.dev.yml run --rm api \
  php artisan tinker --execute="echo DB::selectOne(\"select rolsuper, rolbypassrls from pg_roles where rolname = current_user\")->rolsuper ? 'SUPERUSUARIO' : 'ok';"
```

Las dos tienen que decir `guarderia_app` y `ok`.

## Paso 5 · Las pruebas

```bash
docker compose -f deploy/docker-compose.dev.yml run --rm api \
  php artisan test
```

**Contra PostgreSQL, con las políticas instaladas.** Una base de pruebas en SQLite
no probaría nada del aislamiento.

## Paso 6 · La interfaz

```bash
docker compose -f deploy/docker-compose.dev.yml run --rm web \
  npm run typecheck
```

**Es probable que encuentre errores de tipado.** El archivo se escribió sin poder
correr `vue-tsc`.

## Paso 7 · La comprobación manual

Con la interfaz arriba, y **sin datos reales**:

1. Entrar con un rol de cada uno.
2. El administrativo **no** debe ver el enlace de fichas médicas, y la ruta directa
   debe dar 404.
3. Un retiro sin autorización debe **rechazarse en grande** y quedar registrado.
4. Una autorización vencida debe salir en **cartel rojo**.
5. Correr un respaldo: debe terminar con `RESPALDO COMPLETO`.
6. Restaurar: en un entorno que no sea producción.

---

# Lo que falta, y está pendiente de verdad

## En el código

### 1 · El alta de niños y responsables por la interfaz

`NinoController` tiene `index` y `show`. **No hay alta.**

Hoy se hace por consola con `tinker`, como muestra `docs/06`. Es un hueco real de la
interfaz, no una decisión.

**Falta también:** `ResponsableController` no tiene alta ni edición.

### 2 · La pantalla de la bitácora de accesos

La tabla `accesos` se llena bien y se puede consultar en la base. **No hay
pantalla**, y el acceso es de dirección.

Es un hueco real: la bitácora existe y no se puede mirar desde la aplicación.

### 3 · El correo saliente

No hay transporte, ni plantilla, ni cola. El Job de aviso de vencimiento **escribe
en el registro del servidor**.

Por eso su respuesta lleva `"correo_enviado": false` y el comando dice que no manda
correo. **Configurar el correo no lo habilita.**

### 4 · `guarderia:verificar-aislamiento` no comprueba el rol

El comando comprueba que las políticas estén escritas y que una consulta sin
contexto no lea nada. **No comprueba con qué rol se conecta la aplicación.**

Y esa es justo la comprobación que importa: un superusuario se salta las
políticas aunque la tabla tenga `FORCE`, así que una instalación conectada con el
rol de arranque **pasa todos los puntos del comando** y no está protegida.

Es la mejora más importante de la lista. Está anotado en `docs/03`, parte 3.

### 5 · El motivo de salud en `asistencias` e `incidencias`

`asistencias.motivo_ausencia` puede contener un dato de salud, y
`incidencias.descripcion` también. **Ninguna de las dos tablas marca qué motivo es
de salud**, y por eso el acceso escalonado de `fichas_medicas` no las protege.

El rol administrativo ve la asistencia del día, y con ella todos los motivos de
ausencia de todos los niños.

**Cómo corregirlo:** un booleano `es_dato_de_sensible` en las dos tablas, con
restricción en la base que exija el motivo cuando esté marcado, y extender la
Policy para que el administrativo no lea esas filas.

Es un cambio de modelo de datos y de permiso. **No se hizo a propósito**, porque
cambiar el alcance de un rol sin que el cliente lo decida sería tomar una decisión
que no le corresponde a esta aplicación.

Está en `docs/07`, sección 1.

### 6 · `web/app/pages/ninos/[id].vue` y `web/app/pages/autorizaciones/[id].vue`

Hay enlaces a `/ninos/{id}` en `web/app/pages/ninos/index.vue` que apuntan a una
página que **no existe**.

Eso es un enlace roto, y está anotado porque una interfaz con un enlace a la nada
es una interfaz que se le cae a alguien encima del niño.

### 7 · El filtro de una guardería en las pantallas

`web/app/pages/pagos.vue` muestra `matricula.nino_id.slice(0, 8)` en la columna
"Niño", porque el endpoint `/pagos` no trae el nombre. **Eso es una pantalla con
identificadores en vez de nombres.**

Se arregla trayendo `nino` en `MatriculaResource`, que ya tiene la relación
cargada en el controlador. Es un cambio de una línea en el recurso y otro en la
plantilla.

### 8 · El selector de niño en la pantalla de retiro

`web/app/pages/retiros.vue` acepta `?nino=` en la URL y lo usa desde
`web/app/pages/asistencia.vue`, pero **la página no lee ese parámetro**: siempre
empieza pidiendo un apellido.

Es un hueco menor: el enlace funciona, pero obliga a elegir de nuevo.

### 9 · El contador de `accesos` y su retención

La tabla existe, el middleware la llena, y **no hay ninguna tarea que la revise ni
que la purgue**. Su plazo de conservación está en `null`, y en `null` no se borra
nada.

Es intencional y está documentado en `docs/07`.

## Verificaciones que no se pudieron hacer

### El RUT

Se guarda como texto y **no se valida**. No se verificó contra el registro civil, y
esta aplicación no dice que un RUT sea real.

### Los plazos de conservación

**No se verificó ninguno.** Ver `docs/07`, sección 6.

### La normativa de habilitación de centros infantiles

**No se leyó ninguna.** Esta aplicación no conoce los requisitos de registro,
habilitación y supervisión de centros infantiles en Chile.

**Que quede escrito: esta aplicación no vuelve conforme a una guardería.** Antes
de recibir al primer niño, la dirección tiene que estar habilitada.

### El listado de tipos de documento

El enum `TipoDocumento` tiene `rut`, `cedula`, `pasaporte` y `sin_documento`.
**No es una lista oficial:** no se verificó el texto legal que la respalda.

### La versión de PostgreSQL y de PHP

`postgres:17.6-bookworm` y `php:8.3-fpm-bookworm` se eligieron por ser
reproducibles y mantenidas. **No se construyeron**, así que no se verificó que esas
imágenes tengan las extensiones que necesita el `Dockerfile`.

---

# Un resumen honesto

| | |
|---|---|
| **Sintaxis de shell** | Comprobada en 4 guiones con `bash -n` |
| **Sintaxis de YAML** | Comprobada en 2 archivos |
| **Caracteres no españoles** | Comprobados en 130 archivos |
| **Secretos** | Comprobados |
| **Sintaxis de PHP** | **Sin comprobar.** No hay PHP en la máquina |
| **Dependencias de PHP** | **Sin comprobar.** No hay Composer |
| **Migraciones** | **Sin ejecutar** |
| **Pruebas** | **Sin ejecutar** |
| **Imágenes** | **Sin construir** |
| **Tipos de TypeScript** | **Sin comprobar** |
| **Comportamiento en el navegador** | **Sin comprobar** |
| **Entrar con Authentik** | **Sin comprobar** |
| **Retirar a un niño** | **Sin comprobar** |
| **Respaldar y restaurar** | **Sin comprobar** |

**El primer paso de quien reciba esto es correr el paso 1 de más arriba**, que es
`php -l` sobre todos los archivos. Es lo más probable que encuentre algo, y toma
un minuto.

---

# Y una nota sobre el orden

Los pasos están en el orden en que hay que correrlos, y el orden importa.

Las migraciones tienen que pasar antes de las pruebas, porque las pruebas de
aislamiento consultan `pg_class` y `pg_policy`. Y el aislamiento tiene que estar
verificado antes de las pruebas de permisos, porque una base sin Row Level Security
puede dar un verde en pruebas que en realidad no probaron nada.

Y las pruebas de permisos tienen que pasar antes de darle la instalación a nadie.