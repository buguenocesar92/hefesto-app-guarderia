# 06 · Primer niño y responsable

El camino completo, de la guardería vacía al primer retiro registrado.

Los errores que aparecen en el camino están al lado, porque es donde aparecen.

---

## Antes de empezar

Cuatro cosas tienen que estar listas:

1. La guardería creada.
2. El aislamiento verificado.
3. Los equipos de Authentik creados.
4. Al menos una persona que haya entrado.

---

## Paso 1 · La guardería

```bash
docker compose -f deploy/docker-compose.prod.yml exec api \
  php artisan guarderia:crear --nombre="Guardería Los Robles" --slug="los-robles"
```

El `slug` tiene que ser único, en minúsculas y con guiones. Es lo que la gente
escribe en el selector de ingreso.

**Lo que hace:** crea la fila, fija el contexto, y recuerda que falta verificar el
aislamiento y crear los usuarios.

**Lo que NO hace:** no crea usuarios, y no crea planes con precios.

---

## Paso 2 · Verificar el aislamiento

```bash
docker compose -f deploy/docker-compose.prod.yml exec api \
  php artisan guarderia:verificar-aislamiento
```

**No es opcional.** Antes de que entre un niño.

Debe terminar con `Aislamiento verificado.` y con
`Consulta sin contexto sobre public.ninos: 0 filas. Correcto.`

Si alguno de los dos falla, **no siga**. Explicación en
[03 · Multi-tenancy y roles](03-MULTITENANCY-Y-ROLES.md).

---

## Paso 3 · Los equipos en Authentik

### Los tres roles

Créelos en la consola de Authentik, con estos nombres exactos:

```
Educadoras de Guarderia
Administrativos de Guarderia
Direccion de Guarderia
```

**Llegan al nombre del producto a propósito.** Authentik es compartido por todas
las instalaciones de Hefesto: sin el nombre, una educadora de una guardería sería
educadora en todas.

### El equipo de la guardería

Además, un equipo que diga a qué guardería pertenece cada persona:

```
guarderia-los-robles
```

El prefijo sale de `AUTHENTIK_PREFIJO_EQUIPO_GUARDERIA`, y por omisión es
`guarderia-`.

Su contenido puede ser el identificador o el nombre corto de la guardería.

### Quién entra en qué equipo

| Persona | Equipo de rol | Equipo de guardería |
|---|---|---|
| La directora del centro | `Direccion de Guarderia` | `guarderia-los-robles` |
| La administrativa | `Administrativos de Guarderia` | `guarderia-los-robles` |
| Las educadoras | `Educadoras de Guarderia` | `guarderia-los-robles` |

### El orden de los roles al entrar

Si alguien está en más de un equipo, **gana el de más permisos**: dirección, luego
administrativo, luego educadora.

Es a propósito. El error de configuración corriente es dejar a la gente en varios
equipos, y en ese caso conviene que se le dé más permiso, no menos.

---

## Paso 4 · El primer ingreso

Abra la aplicación, elija la guardería e ingrese.

Lo que pasa por dentro:

1. La pantalla pide el `slug` de la guardería. La lista viene de
   `vw_guarderias_publicas`, que expone **tres columnas**.
2. La API arma la dirección del proveedor de identidad con ese `slug` y un `state`
   aleatorio, que se guarda en sesión.
3. El navegador va a Authentik. **La contraseña nunca pasa por esta aplicación.**
4. Authentik devuelve con un `code`. Esta aplicación compara el `state` con el que
   guardó; si no coinciden, corta.
5. Intercambia el código por un token, verifica la firma, el emisor, la audiencia
   y los tiempos.
6. Lee el rol de los equipos y busca al usuario por `tenant_id` y `sub`.
7. Si no existía, lo crea. Si existía, **no toca el rol**.
8. Abre sesión con cookie.

### El paso 7 es importante

**El rol se escribe UNA vez, en el primer ingreso, y después queda en la base.**

No se recalcula en cada ingreso. Si se recalculara, sacar a alguien de un equipo
lo desactivaría al instante, y nadie revisa la base después de eso.

La columna `rol_actualizado_en` dice cuándo se resolvió el rol por última vez, y
permite detectar el caso en que alguien cambió un equipo en Authentik y no
actualizó esta base.

### Si el ingreso falla

| Mensaje | Qué revisar |
|---|---|
| "El token no viene del proveedor de identidad configurado" | `AUTHENTIK_ISSUER`. **Tiene que ser la URL del navegador** |
| "Su usuario no pertenece a ningún equipo" | Los equipos. O el equipo con el prefijo |
| "El ingreso no se completó" | El `state`. Puede ser que se haya abierto en otra pestaña |
| Un error de conexión a la base | `DB_PASSWORD`. Ver el error número dos en `docs/04` |

---

## Paso 5 · Dar de alta un niño

### Por la interfaz

**No hay formulario de alta de niños todavía.** El comando `NinoController`
tiene `index` y `show`; el alta es trabajo pendiente. Ver
[10 · Pruebas y estado](10-PRUEBAS-Y-ESTADO.md).

Mientras tanto, se puede dar de alta con la consola de la aplicación:

```bash
docker compose -f deploy/docker-compose.prod.yml exec api \
  php artisan tinker
```

```php
$nino = App\Models\Nino::create([
    'nombres' => 'Matías',
    'apellidos' => 'Alarcón',
    'fecha_nacimiento' => '2022-04-11',
    'sexo' => 'masculino',
    'activo' => true,
]);
```

`tenant_id` lo pone el contexto de la guardería, si no se indica.

---

## Paso 6 · Sus responsables

Un niño puede tener **varios** responsables, y cada uno con sus propias
autorizaciones. Es el caso real: la hermana mayor, el padre y la abuela.

```php
$madre = App\Models\Responsable::create([
    'nombres' => 'Fabiola',
    'apellidos' => 'Bernal',
    'documento_tipo' => 'rut',
    'documento_numero' => '15.432.198-2',
    'telefono' => '+56987654321',
    'activo' => true,
]);

$abuela = App\Models\Responsable::create([
    'nombres' => 'Rosa',
    'apellidos' => 'Bernal',
    'documento_tipo' => 'rut',
    'documento_numero' => '12.998.765-3',
    'activo' => true,
]);

$nino->responsables()->attach($madre->id, [
    'parentesco' => 'Madre',
    'es_responsable_legal' => true,
]);

$nino->responsables()->attach($abuela->id, [
    'parentesco' => 'Abuela',
    'es_responsable_legal' => false,
]);
```

### Sobre `es_responsable_legal`

Es un hecho del **vínculo familiar**, no del permiso. La organización legal del
menor no cambia según el niño.

Lo que cambia es la **autorización de retiro**, y esa se da en el paso siguiente,
con su fecha.

### Sobre el documento

**No se valida.** Se guarda lo que declara la persona. Esta aplicación no consulta
el registro civil y no dice que un RUT sea real.

---

## Paso 7 · La autorización de retiro

**Es de DIRECCIÓN.** Educadora y administrativa pueden verlas —para explicar un
rechazo en la puerta— pero no pueden darlas de alta.

```bash
docker compose -f deploy/docker-compose.prod.yml exec api \
  php artisan tinker
```

```php
App\Models\AutorizacionRetiro::create([
    'nino_id' => $nino->id,
    'responsable_id' => $madre->id,
    'vigente_desde' => now()->toDateString(),
    'vigente_hasta' => now()->addYear()->toDateString(),
    'estado' => 'vigente',
    'registrada_por' => $directora->id,
]);
```

### Sobre `vigente_hasta`

**Admite `null`, y a propósito.**

Hay centros donde la autorización de un progenitor legal no vence. Eso es una
decisión del centro.

Lo que **no** hay es un plazo por omisión sin fuente. Si pone una fecha, que sea
una decisión del centro, y no un número que salió de la nada.

### El documento con que se firmó

`documento_tipo` y `documento_numero` son los del responsable **el día que se
firmó**, que son distintos de los de hoy si alguien cambió de documento.

---

## Paso 8 · La ficha médica

**Es de dirección o educadora.** El administrativo recibe **404**.

```php
App\Models\FichaMedica::create([
    'nino_id' => $nino->id,
    'alergias' => 'Alergia al maní. Confirmada por el pediatra.',
    'alimentos_prohibidos' => 'Frutos secos, marañas.',
    'alimentacion' => 'Come normal.',
    'medicacion' => null,
    'autoriza_medicamento' => false,
    'actualizado_por' => $educadora->id,
]);
```

### `autoriza_medicamento`

Es la **declaración del adulto responsable**, no un permiso del sistema.

Que el campo sea `true` no le da permiso a nadie para leer la ficha. Lo que decide
es si se puede administrar medicación **en el centro**.

### Cada apertura queda registrada

Al abrir o guardar esta ficha se escribe una fila en `accesos`, con el usuario, el
rol, el niño y la hora. También los intentos denegados.

---

## Paso 9 · Registrar el ingreso

En la pantalla de asistencia, o por consola:

```php
App\Models\Asistencia::create([
    'nino_id' => $nino->id,
    'fecha' => now()->toDateString(),
    'ingreso_en' => now(),
    'estado' => 'presente',
    'registrado_por' => $educadora->id,
]);
```

**Un día, una fila.** Si ya existe, la API devuelve la que hay con un aviso, en vez
de crear una segunda. Y la restricción `unico_nino_fecha` lo impide de todos modos.

---

## Paso 10 · El primer retiro

En la pantalla de retiro:

1. Busque al niño por apellido.
2. Vea al niño, sus responsables y **quién puede retirarlo hoy**.
3. Toque al responsable autorizado.

Eso registra el retiro con su nombre, la hora y el documento, y cierra la
asistencia del día.

### Pruebe también las dos otras salidas

**Un responsable NO autorizado.** Si toca uno que aparece abajo, en el grupo de los
que no pueden, la pantalla **no lo deja**, y muestra en grande por qué.

Y el intento queda registrado: `resultado = rechazado`, con el motivo y la hora.

**Una autorización vencida.** Si una autorización venció, la pantalla lo muestra
**en un cartel rojo del tamaño de la pantalla**, antes de que alguien intente.

Esa es la parte más importante de la aplicación. Pruébela con calma antes de
usarla de verdad.

---

## La lista de comprobación

Antes de que entre el primer niño de verdad:

- [ ] `guarderia:verificar-aislamiento` dice `Aislamiento verificado`
- [ ] La aplicación se conecta con `guarderia_app`, **no** con `guarderia_admin`
- [ ] Los tres equipos existen en Authentik, con el nombre del producto
- [ ] Entró alguien con **cada uno** de los tres roles
- [ ] El administrativo **no** ve la ficha médica, y da 404
- [ ] La educadora **sí** ve la ficha médica
- [ ] Un retiro sin autorización se rechaza y **queda registrado**
- [ ] Una autorización vencida se marca **en grande**
- [ ] El intento denegado aparece en `accesos` con el rol
- [ ] Corrió un respaldo y salió `COMPLETO`
- [ ] Los responsables tienen teléfono y documento
- [ ] Cada niño tiene al menos un responsable **autorizado**
- [ ] Nadie tiene autorizaciones por vencer en la ventana

Las tres primeras son de la instalación. Las del medio son del funcionamiento. Las
últimas son de los datos, y son las que más se olvidan: un niño con
responsables pero sin autorización **no tiene a nadie que lo pueda retirar**.

---

## Si algo sale mal

| Síntoma | Dónde mirar |
|---|---|
| "No hay ningún equipo" | Los equipos de Authentik |
| "Su rol no abre fichas médicas" | El rol es administrativo. Es correcto |
| "No hay ningún responsable autorizado" | La tabla `autorizaciones_retiros` de esa guardería |
| El niño aparece con "Sin ficha" en la lista | **No tiene `fichas_medicas`** |
| "La autorización venció" | La fecha. La última venta al final del día |
| El retiro sale rechazado sin motivo claro | `retiros.motivo_rechazo` en la base |
| Alguien no puede entrar | `usuarios.activo`, y el `tenant_id` de la sesión |