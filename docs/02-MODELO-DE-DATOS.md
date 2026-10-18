# 02 · Modelo de datos

Quince tablas, una por una, con la decisión que hay detrás de cada una.

---

## El mapa

```
  guarderias                     el inquilino. SIN Row Level Security.
  |                              (se consulta antes del ingreso)
  |
  +-- usuarios                   el personal. rol escalonado.
  |
  +-- ninos
  |     +-- responsable_nino      el vinculo MUCHOS A MUCHOS
  |     |     +-- responsables
  |     |           +-- autorizaciones_retiros   LA TABLA CENTRAL
  |     |
  |     +-- fichas_medicas       dato de salud. SU AISLAMIENTO PROPIO.
  |     +-- incidencias          no se borran
  |     +-- asistencias
  |     |     +-- retiros        autorizados Y rechazados
  |     |
  |     +-- matriculas
  |           +-- pagos           un anulado conserva la fila
  |           +-- planes
  |
  +-- accesos                    la bitacora. permitido Y denegado.
  +-- sesiones                   el estado de la sesion.

  vw_guarderias_publicas         TRES columnas. sin Row Level Security.
```

---

## `guarderias` — el inquilino

| Columna | Tipo | Nota |
|---|---|---|
| `id` | uuid | |
| `nombre` | string(160) | Lo que ve la gente |
| `slug` | string(80) | Único. Lo que la gente escribe en el ingreso |
| `razon_social` | string(160) | Nullable |
| `comuna` | string(80) | Nullable |
| `direccion` | string(240) | Nullable |
| `telefono` | string(40) | Nullable |
| `correo` | string(160) | Nullable |
| `activo` | boolean, índice | Las inactivas no salen en el selector de ingreso |

### Sin Row Level Security, a propósito

Se consulta antes del ingreso, cuando no hay sesión y por lo tanto no hay
`app.guarderia_id` que poner. Con Row Level Security, esa consulta no devolvería
nada y no se podría ni mostrar el selector de guardería.

Para que la excepción no sea una fuga, antes del ingreso la aplicación lee
**`vw_guarderias_publicas`**: tres columnas, sólo las activas. Ver
[03 · Multi-tenancy y roles](03-MULTITENANCY-Y-ROLES.md).

---

## `usuarios` — el personal

| Columna | Tipo | Nota |
|---|---|---|
| `tenant_id` | uuid → `guarderias` | |
| `sub` | string(160), índice | El sujeto en Authentik. Único por guardería |
| `correo` | string(160) | Único por guardería |
| `nombres` / `apellidos` | string(120) | |
| `rol` | string(32), índice | `educadora`, `administrativo`, `direccion` |
| `activo` | boolean | |
| `ultimo_ingreso_en` | timestamptz | Nullable |
| `rol_actualizado_en` | timestamptz | Cuándo se revisó el rol a mano |
| `creado_en` | timestamptz | |

### La decisión: el rol es una COLUMNA, no una consulta a Authentik

Si el rol se leyera del equipo de Authentik en cada ingreso, sacar a alguien de un
equipo lo desactivaría al instante, y nadie revisa la base después de eso.

El precio de esa decisión es que hay que mantener los dos lugares. Por eso existe
`rol_actualizado_en`: dice cuándo se resolvió el rol por última vez. Si la
dirección cambia un rol en Authentik y no actualiza esta tabla, el cambio no se
refleja acá, y esa columna es lo que permite detectarlo.

**No hay campo booleano de permisos.** El permiso sale del rol, con `match` en
`RolGuarderia`. Un booleano por persona es un campo que alguien puede cambiar sin
querer y que nadie audita.

---

## `ninos` — los niños

| Columna | Tipo | Nota |
|---|---|---|
| `tenant_id` | uuid → `guarderias` | |
| `nombres` / `apellidos` | string(120) | |
| `fecha_nacimiento` | date | |
| `sexo` | string(24) | Nullable |
| `rut` | string(24) | **Texto, no número** |
| `activo` | boolean, índice | |
| `creado_en` | timestamptz | |

Índice por `(tenant_id, apellidos)`: en la pantalla de retiro se busca por
apellido, porque es lo que se conoce.

### Lo que NO está en esta tabla

**Nada de salud.** Ni una palabra. Las alergias, la alimentación y la medicación
están en `fichas_medicas`, y esa separación es deliberada.

### Por qué `rut` es texto

Porque el RUT chileno tiene dígito verificador y formato con guion, y **no se
validó contra el registro civil**. Se guarda lo que la persona declara. Poner un
número sería inventar una validación que esta aplicación no hace.

---

## `responsables` y `responsable_nino` — el vínculo

### `responsables`

| Columna | Tipo | Nota |
|---|---|---|
| `tenant_id` | uuid → `guarderias` | |
| `nombres` / `apellidos` | string(120) | |
| `documento_tipo` | string(24) | `rut`, `cedula`, `pasaporte`, `sin_documento` |
| `documento_numero` | string(32) | Nullable. Índice por `(tenant_id, documento_numero)` |
| `telefono` / `correo` / `direccion` | | Nullable |
| `activo` | boolean, índice | |

### `responsable_nino` — MUCHOS A MUCHOS

| Columna | Nota |
|---|---|
| `nino_id`, `responsable_id` | Único el par |
| `parentesco` | **Texto.** "Tía", "abuela" y "madre" no son un conjunto cerrado |
| `es_responsable_legal` | **Sí es un booleano**, y está bien que lo sea |

### Por qué muchos a muchos

Porque una misma persona es responsable de varios niños —hermanos, primos,
sobrinos— y **cada uno tiene sus propias autorizaciones, con plazos distintos**.

La hermana puede estar autorizada para uno y no para el otro. Con un campo
`responsable_id` en `ninos`, esa situación no se puede representar, y es
exactamente el caso que el registro de retiro tiene que distinguir.

### Por qué `es_responsable_legal` sí es un booleano

Porque es un hecho del **vínculo familiar**, no del permiso. La organización legal
del menor no cambia según el niño; lo que cambia es la autorización, y esa vive
en `autorizaciones_retiros`, con su fecha.

---

## `autorizaciones_retiros` — LA TABLA CENTRAL

Esta es la tabla que hace que el sistema pueda decir **no**.

| Columna | Tipo | Nota |
|---|---|---|
| `tenant_id` | uuid → `guarderias` | |
| `nino_id` | uuid → `ninos` | |
| `responsable_id` | uuid → `responsables` | |
| `vigente_desde` | date | Nullable |
| `vigente_hasta` | date | **Nullable, y tiene que serlo** |
| `documento_tipo` / `documento_numero` | | El documento con que se FIRMÓ, que es distinto del de hoy |
| `estado` | string(24), índice | `vigente`, `vencida`, `anulada` |
| `motivo_anulacion` | text | Nullable |
| `anulada_por` | uuid → `usuarios` | Nullable |
| `anulada_en` | timestamptz | Nullable |
| `registrada_por` | uuid → `usuarios` | |

Índices: `(tenant_id, nino_id, responsable_id)` para la puerta, y
`(tenant_id, vigente_hasta)` para el aviso.

### Por qué `vigente_hasta` ADMITE NULO

Porque hay centros donde la autorización de un progenitor legal no vence. Eso es
una decisión del centro.

Lo que **no** existe es un plazo escrito por omisión sin fuente. La aplicación no
pone ninguno. `config/guarderia.php` trae `dias_aviso_vencimiento` —una ayuda de
operación— y nada más.

### Por qué tres estados y no un booleano "vigente"

Porque una autorización **vencida** no es lo mismo que una **anulada**:

- Vencida: estuvo autorizada y el plazo pasó. Hay que renovarla.
- Anulada: alguien la dejó sin efecto a propósito, con un motivo escrito.

Con un booleano, las dos se verían iguales en pantalla, y son dos conversaciones
distintas con la familia.

### Por qué anular no es eliminar

Porque una autorización borrada no se puede citar después de una conversación con
la familia, y este es un registro de responsabilidad. `anulada_por`,
`anulada_en` y `motivo_anulacion` quedan.

Y hay más: la tabla `autorizaciones_retiros` **no admite `DELETE`**. Lo impone un
disparador en la base. Ver [07 · Seguridad](07-SEGURIDAD-Y-LEY-19628.md).

### La fecha MANDA sobre el estado guardado

`estado = vigente` con la fecha pasada está **vencida**. El estado guardado y la
fecha se pueden desincronizar, y si el evaluador confiara en el estado guardado,
una autorización con la fecha pasada seguiría autorizando.

`EvaluadorDeAutorizacion::estadoEn()` combina los dos, y la fecha gana.

La fecha vence **al final del día**: el último día sigue sirviendo entero. Hay una
prueba que lo comprueba día por día.

---

## `asistencias` — el día

| Columna | Tipo | Nota |
|---|---|---|
| `tenant_id`, `nino_id` | | |
| `fecha` | date | |
| `ingreso_en` / `egreso_en` | timestamptz | Nullable |
| `estado` | string(24), índice | `presente`, `salida_temporal`, `retirado`, `ausente` |
| `motivo_ausencia` | string(240) | **Obligatorio si `estado = ausente`** |
| `avisado_por` | uuid → `responsables` | Quién avisó |
| `observacion` | string(500) | Nullable |
| `registrado_por` | uuid → `usuarios` | |

### Un día, una fila

`UNIQUE (nino_id)` nombrada `unico_nino_fecha`. Sin ella, un doble clic en
"registrar ingreso" crea dos filas y la pantalla muestra que el niño entró dos
veces.

Está en SQL y no en un `if` de PHP: una validación de aplicación se puede saltar
con una consulta directa, y esta fila se factura y se consulta.

### Dos restricciones `CHECK` en la base

```sql
asistencia_ausente_exige_motivo
    estado <> 'ausente' OR (motivo_ausencia IS NOT NULL AND btrim(motivo_ausencia) > 0)

asistencia_ingreso_requiere_hora
    estado = 'ausente' OR ingreso_en IS NOT NULL
```

La primera es la que hace que "no llegó y nadie avisó" tenga que escribirse con un
motivo y con la hora en que se supo.

### Por qué cuatro estados y no dos

Porque "no vino y avisaron" y "no vino y nadie avisó" son dos situaciones
distintas, y la segunda es la que el sistema tiene que poner adelante. Y porque
una salida temporal y un retiro del día **no** son lo mismo: la salida temporal no
lleva responsable autorizado, y el registro de retiro se reserva para lo que de
verdad sale del centro.

---

## `retiros` — LA TABLA MÁS IMPORTANTE

| Columna | Tipo | Nota |
|---|---|---|
| `tenant_id`, `nino_id` | | |
| `responsable_id` | uuid → `responsables` | **NULL** si no tiene ficha |
| `responsable_nombre_texto` | string(240) | **CONGELADO** |
| `responsable_documento_texto` | string(80) | **CONGELADO** |
| `autorizacion_retiro_id` | uuid | **NULL** si fue rechazado |
| `asistencia_id` | uuid | Nullable |
| `ocurrido_en` | timestamptz | |
| `documento_tipo` / `documento_numero` | | Copia del momento |
| `resultado` | string(24), índice | `autorizado` o `rechazado`. Sólo esos dos |
| `motivo_rechazo` | text | Nullable |
| `observacion` | string(500) | Nullable |
| `registrado_por` | uuid → `usuarios` | Nullable |

Restricción: `CHECK (resultado IN ('autorizado', 'rechazado'))`. No hay un tercer
valor para "quedamos a medias". Un retiro de un menor es un hecho: o se
registró, o se rechazó, y ambos casos se anotan.

### Se escriben LOS DOS resultados

El rechazo se registra porque el caso raro es el que más importa. Si alguien llega
a la puerta y no está autorizado, dentro de tres meses nadie se acuerda de eso.

Lo que permite reconstruirlo es esta fila.

### Por qué el nombre y el documento están CONGELADOS

Porque la ficha del responsable puede cambiar, y **el registro de lo que pasó a
las cuatro de la tarde de ese día no puede cambiar con ella**.

Un registro que cambia cuando cambian los datos de la persona no sirve para
reconstruir nada.

Cuando el responsable sí existe, el texto congelado sigue siendo el del momento
del hecho, y el recurso muestra al responsable actual. Los dos están, por si uno
de los dos cambia después.

### Por qué `registrado_por` puede ser NULO

Porque alguien puede llegar a la puerta sin pasar por el sistema. El rechazo se
registra igual, con `responsable_nombre_texto` y `responsable_documento_texto`.

### Y por qué el rechazo no se pierde

`ServicioRetiro::escribirRechazo()` abre **su propia transacción**. Si el rechazo
se escribiera dentro de la transacción que después se revierte, el registro
desaparecería junto con el error.

Y por eso el rechazo se escribe **antes** de lanzar la excepción.

### Esta tabla no admite `DELETE`

Lo impone un disparador. Ver [07 · Seguridad](07-SEGURIDAD-Y-LEY-19628.md).

---

## `fichas_medicas` — DATO DE SALUD

| Columna | Tipo | Nota |
|---|---|---|
| `tenant_id`, `nino_id` | | **ÚNICO `nino_id`**: una ficha por niño |
| `alergias` | text | Nullable |
| `alimentacion` | string(240) | Nullable |
| `alimentos_prohibidos` | text | Nullable |
| `medicacion` | text | Nullable |
| `plan_emergencia` | text | Nullable |
| `contacto_emergencia_nombre` | string(160) | Nullable |
| `contacto_emergencia_telefono` | string(40) | Nullable |
| `autoriza_medicamento` | boolean | **Declaración del adulto responsable** |
| `actualizado_por` / `actualizado_en` | | Quién y cuándo |

### Por qué está sola

Porque `ninos` se lista, se consulta desde el panel y la lee el rol
administrativo. Si las alergias estuvieran ahí, la ficha dejaría de estar
protegida el día que alguien escribiera una consulta a `ninos`.

`fichas_medicas` se abre por una ruta sola, con el middleware `medica.auditada`,
que anota cada lectura, cada escritura y cada intento denegado.

### `autoriza_medicamento` NO es un permiso

Es la declaración del adulto responsable sobre si se puede administrar
medicamento en el centro.

Que el campo sea `true` **no le da permiso a nadie para leer nada**. Es un dato, no
un acceso. Y la confusión entre esas dos cosas sería un error grave, así que está
escrito en el comentario de la columna y en el formulario de la interfaz.

### Una ficha por niño, no varias

`UNIQUE (nino_id)`. Varias fichas para el mismo niño son versiones que se
contradicen, y una ficha de salud que se contradice no sirve.

### Lo que este modelo NO hace

No valida contenido clínico. No dice si una alergia es probable, ni si una dosis
es la correcta, ni si un medicamento se puede dar con cierta comida.

Eso lo escribe el adulto responsable y lo revisa quien tiene permiso para verlo.

---

## `planes`, `matriculas`, `pagos` — el dinero

### `planes`

| Columna | Nota |
|---|---|
| `nombre` | Único por guardería |
| `precio_clp` | integer, `CHECK (>= 0)` |
| `dias_cobro` | Nullable |
| `descripcion` | Nullable |
| `activo` | |

**No hay monto por omisión.** No se conoce lo que cobra cada guardería, y escribir
un precio sería inventar uno. El seeder de demostración crea un plan con precio
**cero**, y su descripción lo dice.

### `matriculas` — y la ausencia deliberada de un saldo

| Columna | Nota |
|---|---|
| `nino_id`, `plan_id` | |
| `inicia_en`, `termina_en` | |
| `activa` | |
| `registrada_por` | |

**No hay columna de saldo.** Se calcula: precio del plan menos la suma de los pagos
**no anulados**.

Un saldo guardado se desactualiza en cuanto alguien registra un pago y no lo
actualiza, y el sistema muestra que se debe una mensualidad que ya se pagó. Un
saldo calculado no puede quedar viejo.

Un niño puede tener dos matrículas activas: cuando cambia de plan a mitad de mes,
la anterior se cierra y la nueva se abre.

### `pagos`

| Columna | Nota |
|---|---|
| `matricula_id` | |
| `monto_clp` | integer, **`CHECK (> 0)`** |
| `pagado_en` | date |
| `metodo`, `comprobante` | Nullable |
| `estado` | `registrado`, `anulado` |
| `motivo_anulacion` | Nullable |
| `anulado_por` / `anulado_en` | Nullable |
| `registrado_por` | |

### Un pago anulado conserva la fila

Anular no es borrar: es dejar constancia de que se creó un pago y después se dejó
sin efecto, con un motivo y con quién lo anuló.

Borrar un pago deja el mismo hueco que borrar una autorización: el saldo de una
familia deja de poder reconstruirse.

### El saldo a favor NO se recorta

`saldoEnPesos()` puede dar negativo, y `saldoLegible()` lo muestra como "saldo a
favor". También es un caso real.

---

## `incidencias` — qué pasó

| Columna | Nota |
|---|---|
| `nino_id` | |
| `categoria` | **Texto.** "Conducta", "salud", "alimentación" no son un conjunto cerrado |
| `gravedad` | `CHECK`: `menor`, `moderada`, `relevante`, `critica` |
| `ocurrido_en` | timestamptz |
| `descripcion` | **Obligatoria**, con largo mínimo |
| `accion_tomada` | **Obligatoria**, con largo mínimo |
| `informada_a` | uuid → `responsables`. Nullable |
| `requiere_seguimiento` | |
| `registrada_por` | |

### Las dos partes obligatorias

`descripcion` y `accion_tomada`. Una incidencia sin acción deja registro del
**problema** y no de la **respuesta**, y con la respuesta no se sostiene una
conversación con la familia.

Los mínimos salen de `config('guarderia.incidencias')`, no de un número escrito en
el controlador, para que el centro pueda ajustarlos sin que cambie el código.

### Por qué cuatro grados y no un número del 1 al 10

Porque "leve" y "grave" no significan lo mismo en dos centros, y es la dirección
del centro la que decide qué significa acá.

Un 1 al 10 haría que dos educadoras llamaran "7" a cosas muy distintas, y la
conversación con la familia empieza por ahí.

### No se borran

No hay ruta de borrado, **y no es una omisión pendiente**. La base lo rechaza.

---

## `accesos` — la bitácora

| Columna | Nota |
|---|---|
| `usuario_id` | Nullable |
| `rol` | **COPIADO**, no relacionado |
| `recurso`, `recurso_id` | Qué ficha |
| `accion` | `leer`, `crear`, `actualizar` |
| `resultado` | `CHECK`: `permitido`, `denegado` |
| `motivo` | Nullable |
| `ruta`, `direccion_ip` | Nullable |
| `ocurrido_en` | timestamptz, índice |

### Por qué el rol está COPIADO

Porque la bitácora tiene que decir el rol que tenía la persona **al abrir la
ficha**, y no el que tiene hoy. Dentro de seis meses nadie se acuerda de que era
administrativa y hoy es directora.

### Se anotan también los DENEGADOS

Porque un intento denegado es información: es el caso en que alguien pidió abrir
una ficha que no le correspondía.

Si sólo se anotara lo permitido, el registro sería la lista de las cosas que el
sistema dejó pasar, y no diría nada sobre lo que alguien intentó hacer.

### Su plazo de conservación está en `null`

No se verificó un plazo para la bitácora. El sistema no purga nada. Ver
[07 · Seguridad](07-SEGURIDAD-Y-LEY-19628.md).

### No se borra

También con disparador.

---

## `sesiones` — y su política distinta

| Columna | Nota |
|---|---|
| `id` | string(40). La cookie |
| `tenant_id` | uuid. **NULLABLE** |
| `usuario_sub` | El sujeto en Authentik |
| `payload` | text |

Controlador `database`, no `file`: una sesión en un archivo dentro del contenedor
se pierde cuando el contenedor se reinicia, y eso sería cerrar la sesión de una
educadora a mitad del ingreso de un niño.

**Su Row Level Security es distinta, y tiene que serlo.** Se lee ANTES de que
exista contexto: es la lectura que RESUELVE el contexto. Con la política estricta,
la primera petición no encontraría su propia fila de sesión.

Se permite leer cuando no hay contexto; con contexto fijado, vuelve la política
estricta. El riesgo residual está anotado en
[03 · Multi-tenancy y roles](03-MULTITENANCY-Y-ROLES.md).

---

## `vw_guarderias_publicas` — la vista

```sql
CREATE VIEW vw_guarderias_publicas AS
    SELECT id, nombre, slug
    FROM guarderias
    WHERE activo = true
```

**Tres columnas. Sólo las activas.**

Es lo único que se puede leer de `guarderias` antes del ingreso. No expone razón
social, dirección, correo ni teléfono.

Y se usa de verdad: la consulta `Publico\GuarderiasPublicasController`, la vigila
`tests/Feature/Aislamiento/VistaPublicaSeUsaTest.php`, y
`guarderia:verificar-aislamiento` falla si deja de exponer esas tres columnas.

---

## Las restricciones que están en la base y no en el código

Resumen. Todas van en SQL y no en un `if`, porque un `if` se puede saltar.

| Restricción | Qué garantiza |
|---|---|
| `asistencias.unico_nino_fecha` | Un día, una fila |
| `asistencia_ausente_exige_motivo` | Una ausencia tiene motivo |
| `asistencia_ingreso_requiere_hora` | Todo lo que no es ausencia tiene hora de ingreso |
| `retiros.retiro_resultado_valido` | Sólo hay dos resultados |
| `planes.plan_precio_no_negativo` | |
| `pagos.pago_monto_no_negativo` | Un pago es mayor que cero |
| `incidencias.incidencia_gravedad_valida` | |
| `accesos.acceso_resultado_valido` | |
| `fichas_medicas.unico_nino` | Una ficha por niño |
| 4 disparadores anti-`DELETE` | Retiros, autorizaciones, incidencias y accesos no se borran |