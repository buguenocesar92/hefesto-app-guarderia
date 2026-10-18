# 09 · API

Todos los endpoints, qué permiso necesitan y qué significan sus respuestas.

Base: `/api/v1`

---

## La convención de las respuestas

| Código | Cuándo |
|---|---|
| `200` | Todo bien |
| `201` | Se creó algo |
| `401` | No hay sesión, o la sesión ya no es válida |
| `403` | El `state` de OIDC no coincide. **Casi nunca aparece** |
| `404` | **No existe, o no le corresponde.** Ver abajo |
| `422` | Error de negocio, con un motivo que se puede mostrar |

### El 404

En esta aplicación un 404 casi siempre significa **"no tiene permiso"**, no "no
existe".

Y es a propósito. Un 403 diría "esto existe y tú no puedes", y eso ya enseña que
el niño existe, que hay una ficha y que hay una autorización.

Ver `App\Policies\Concerns\RespondeNoEncontrado` y
[03 · Multi-tenancy y roles](03-MULTITENANCY-Y-ROLES.md).

### El 422

Los errores de negocio llevan un `código` además del mensaje:

```json
{
  "mensaje": "La autorización de Fabiola Bernal venció el 12/03/2026 y no fue renovada. No puede retirar al niño hasta que un adulto responsable renueve la autorización.",
  "codigo": "retiro_autorizacion_vencida"
}
```

Los códigos:

| Código | Qué pasó |
|---|---|
| `retiro_sin_autorizacion` | No hay autorización registrada a su nombre |
| `retiro_autorizacion_vencida` | Venció, y el mensaje trae la fecha |
| `retiro_autorizacion_anulada` | Fue anulada, y el mensaje trae el motivo |
| `retiro_ni_no_presente` | No tiene ingreso registrado hoy |
| `retiro_duplicado` | Ya tiene un retiro registrado |
| `asistencia_ya_cerrada` | |
| `autorizacion_vigencia_invalida` | El vencimiento es anterior al inicio |

**Y en todos los casos de retiro, el rechazo queda registrado en la base.**

---

## Público

### `GET /guarderias`

El selector de la pantalla de ingreso.

**Sin sesión.** Es la única ruta que usa `vw_guarderias_publicas`, y expone
**tres columnas**.

```json
{
  "guarderias": [
    { "id": "9f3c...", "nombre": "Guardería Los Robles", "slug": "los-robles" }
  ]
}
```

No expone razón social, dirección, correo ni teléfono. **A propósito.**

---

## Sesión

### `GET /auth/ingreso?guarderia=SLUG`

Devuelve la dirección del proveedor de identidad.

```json
{ "url": "https://authentik.ejemplo.local/application/o/guarderia/authorize?..." }
```

Genera un `state` y lo guarda en sesión. El navegador va directo allá.

### `GET /auth/retorno?code=...&state=...`

Intercambia el código por un token, verifica la firma y abre sesión.

Comprueba el `state` contra el que se guardó. Si no coinciden, corta con 403.

**El rol se escribe en el primer ingreso y después queda en la base.** No se
recalcula en cada ingreso.

### `GET /auth/yo`

Quién está dentro, y qué puede hacer.

```json
{
  "usuario": { "nombres": "Patricia", "apellidos": "Soto", "rol": "direccion", "etiqueta_rol": "Dirección" },
  "capacidades": {
    "guarderia_id": "9f3c...",
    "rol": "direccion",
    "abre_fichas_medicas": true,
    "ve_pagos": true,
    "acceso_ficha_medica": "Puede abrir la ficha médica de cualquier niño del centro. La dirección responde por el cuidado de los menores.",
    "ve_bitacora": true
  }
}
```

**La interfaz no decide permisos: los lee de acá.**

### `POST /auth/salida`

Cierra la sesión.

---

## Panel

### `GET /panel`

Lo que hay que mirar hoy.

**Todos los roles.**

```json
{
  "fecha": "2026-10-18",
  "hoy": {
    "ninos_en_el_centro": 84,
    "presentes": 61,
    "retirados": 12,
    "ausentes": 8,
    "ausentes_sin_aviso": 2,
    "ausentes_sin_aviso_detalle": [
      { "nino_id": "a71b...", "nombre": "Lucas Vera" }
    ]
  },
  "pendientes": {
    "autorizaciones_por_vencer": 3,
    "dias_anticipacion_del_aviso": 15,
    "incidencias_con_seguimiento": 1,
    "retiros_rechazados_hoy": 1
  }
}
```

**`ausentes_sin_aviso_detalle` trae los NOMBRES.** Un número no hace que nadie
llame a una familia; un nombre sí.

Y el panel **no trae ninguna alerta de alergias ni de medicación**: el panel se usa
de pie, todas las mañanas, y no es el lugar donde vive el dato sensible de un
menor.

---

## Niños

### `GET /ninos`

Lista. **Todos los roles.**

| Parámetro | |
|---|---|
| `buscar` | Apellido o nombre |
| `solo_activos` | `1` |

**Trae `tiene_ficha_medica`, que es un sí o un no. Nunca qué dice la ficha.**

### `GET /ninos/{nino}`

Un niño con sus responsables. **Todos los roles.**

### `GET /ninos/{nino}/retiro`

**La ruta de la pantalla de retiro.**

```json
{
  "nino": { "id": "a71b...", "nombre": "Lucas Vera", "edad": 4 },
  "responsables": [
    {
      "id": "c12d...",
      "nombre": "Rodrigo Vera",
      "documento": "RUT: 15.432.198-2",
      "autorizacion_id": "e45f...",
      "estado": "vigente",
      "vigente_hasta": "2027-04-30",
      "dias_para_vencer": 194,
      "permite_retiro": true
    },
    {
      "id": "f78a...",
      "nombre": "Patricia Soto",
      "estado": "vencida",
      "vigente_hasta": "2026-03-12",
      "permite_retiro": false
    }
  ],
  "hora": "09:42"
}
```

`estado` y `permite_retiro` los calcula el **servidor**, con la fecha del servidor.

**POST** no está implementado: el alta de niños es trabajo pendiente.

---

## Responsables

### `GET /responsables`

Lista, con el estado **calculado** de las autorizaciones de cada uno.

**Todos los roles.** No hay nada de salud en esta respuesta.

| Parámetro | |
|---|---|
| `nino_id` | Acota a los responsables de ese niño |
| `buscar` | Apellido, nombre o número de documento |
| `solo_activos` | `1` |

### `GET /responsables/{responsable}`

Uno, con sus niños y sus autorizaciones.

---

## Asistencia

### `GET /asistencias?fecha=AAAA-MM-DD`

La asistencia de un día. **Todos los roles.**

```json
{
  "fecha": "2026-10-18",
  "asistencias": [ ... ],
  "resumen": {
    "total": 81, "presentes": 61, "salidas_temporales": 0,
    "retirados": 12, "ausentes": 8, "sin_aviso": 2
  }
}
```

Cada fila trae `sin_aviso`, que es un booleano **calculado**, y
`sin_registro_de_retiro`.

### `POST /ninos/{nino}/ingreso`

Registra el ingreso. **Un día, una fila**: si ya hay una, la devuelve con un aviso.

### `POST /ninos/{nino}/salida-temporal`

Registra una salida temporal.

**Una salida temporal NO lleva registro de retiro.** El registro de retiro se
reserva para lo que de verdad sale del centro.

### `POST /ninos/{nino}/ausencia`

Marca una ausencia.

**De DIRECCIÓN.** Y exige `motivo_ausencia`: en el formulario y en una
restricción de la base (`asistencia_ausente_exige_motivo`).

```json
{ "motivo_ausencia": "La familia avisó por teléfono.", "avisado_por": "c12d..." }
```

---

## Retiros

### `GET /ninos/{nino}/retiros`

El historial de ese niño. **Todos los roles.**

Incluye los rechazados, porque el rechazo es parte del historial.

### `POST /ninos/{nino}/retiros`

**Registra un retiro. La operación central de la aplicación.**

```json
{ "responsable_id": "c12d...", "autorizacion_id": "e45f...", "observacion": null }
```

**Todos los que operan la puerta.**

El cuerpo **no lleva un campo "autorizado"**. El sistema no pregunta: comprueba.

Si la autorización no sirve, el servicio escribe el registro del rechazo y lanza
la excepción de dominio. La respuesta es **422** con el motivo, y **el rechazo
queda escrito**.

### `GET /ninos/{nino}/retiros/estado/{responsable}`

El estado de esa autorización **hoy**, antes de intentar.

Es la ruta que permite que la pantalla diga "VENCIDA" en grande **antes** de que
alguien intente.

```json
{
  "estado": "vencida",
  "permite_retiro": false,
  "vigente_hasta": "2026-03-12",
  "dias_para_vencer": -219,
  "por_vencer": false,
  "titulo": "VENCIDA",
  "detalle": "La autorización venció el 12/03/2026. No puede retirar al niño hasta que un adulto responsable renueve la autorización."
}
```

`titulo` y `detalle` los calcula el servidor, con el título en mayúsculas porque
se muestra en grande.

### `GET /retiros/bitacora`

La bitácora completa de la guardería, con los intentos rechazados de todos los
niños.

**De DIRECCIÓN.** Dice a qué familias se les ha rechazado retirar a su niño, y eso
es información delicada sobre la relación del centro con cada familia.

---

## Autorizaciones

### `GET /ninos/{nino}/autorizaciones`

Las de ese niño. **Todos los roles.**

### `POST /ninos/{nino}/autorizaciones`

Da de alta una, o renueva una vencida.

**De DIRECCIÓN.**

```json
{
  "responsable_id": "c12d...",
  "vigente_desde": "2026-10-18",
  "vigente_hasta": "2027-10-18"
}
```

`vigente_hasta` **admite `null`**, y hay centros donde la autorización de un
progenitor legal no vence.

Si el vencimiento es anterior al inicio, es 422 con
`autorizacion_vigencia_invalida`.

### `POST /autorizaciones/{autorizacion}/anular`

Anula. **De DIRECCIÓN.** Exige `motivo_anulacion`.

**Anular no es borrar.** La fila queda con su motivo y con quién la anuló, y el
rechazo posterior puede explicar exactamente qué pasó. La tabla no admite
`DELETE`.

### `GET /autorizaciones/por-vencer?dias=N`

Las que entran en la ventana de aviso. **De DIRECCIÓN.**

```json
{
  "dias_aviso": 15,
  "total": 3,
  "autorizaciones": [ ... ],
  "mensaje": "Hay 3 autorizaciones por vencer en los próximos 15 días."
}
```

**El aviso ocurre ANTES de que expiren.** Un sistema que avisa cuando ya venció no
está avisando.

---

## Fichas médicas

### `GET /ninos/{nino}/ficha-medica`

### `POST /ninos/{nino}/ficha-medica`

**Las dos rutas llevan el middleware `medica.auditada`.** Es decir, cada lectura,
cada escritura y cada intento denegado se anota en `accesos`.

| Rol | Respuesta |
|---|---|
| Educadora | **200** |
| Dirección | **200** |
| Administrativo | **404** |

**Un administrativo recibe 404, no 403.** Un 403 confirmaría que ese niño existe y
que tiene ficha.

Guardar tiene el mismo permiso que leer: guardar una ficha es escribir sobre
dato de salud de un menor, y quien puede escribirlo tiene que poder leerlo.

---

## Pagos

### `GET /pagos`

### `GET /ninos/{nino}/pagos`

### `POST /pagos`

**Administrativo y dirección.** La educadora recibe **404**.

El saldo viene **calculado**: precio del plan menos los pagos **no anulados**.

```json
{ "matricula_id": "b91c...", "monto_clp": 180000, "pagado_en": "2026-10-05", "metodo": "transferencia" }
```

### `POST /pagos/{pago}/anular`

**De DIRECCIÓN.** Exige `motivo_anulacion`.

**El pago anulado conserva la fila** y aparece en las listas. Si los pagos anulados
no se vieran, el saldo de una familia dejaría de poder reconstruirse.

---

## Incidencias

### `GET /ninos/{nino}/incidencias`

### `GET /incidencias`

El segundo es de DIRECCIÓN, y trae las del centro.

### `POST /ninos/{nino}/incidencias`

```json
{
  "categoria": "salud",
  "gravedad": "moderada",
  "ocurrido_en": "2026-10-18T09:30:00-03:00",
  "descripcion": "Se golpeó la cabeza en el patio y se le formó un chichón en la frente.",
  "accion_tomada": "Se avisó a la madre por teléfono y se dejó observação durante la tarde.",
  "informada_a": "c12d...",
  "requiere_seguimiento": true
}
```

`descripcion` y `accion_tomada` son **obligatorias**, con largo mínimo.

Una incidencia sin acción deja registro del problema y no de la respuesta, y con la
respuesta no se sostiene una conversación con la familia.

**No hay ruta de borrado.** No es una omisión pendiente: la base tiene un
disparador que rechaza el `DELETE`.

---

## La salud

### `GET /salud`

```json
{ "estado": "ok", "servicio": "api-guarderia" }
```

Sin tocar la base. La usa el `HEALTHCHECK` del contenedor.

**Lo que NO comprueba:** que PostgreSQL responda. Un healthcheck que consulta la
base pone el contenedor en `unhealthy` cada vez que la base se reinicia, y el
orquestador lo reinicia en cascada.

---

## Lo que NO hay

Para que no haya que deducirlo:

| No hay | Por qué |
|---|---|
| `DELETE /ninos/{nino}` | Ver [07 · Seguridad](07-SEGURIDAD-Y-LEY-19628.md) |
| `DELETE /retiros/{retiro}` | Un registro de responsabilidad no se borra |
| `DELETE /incidencias/{id}` | No es una omisión pendiente. La base lo rechaza |
| `PUT /retiros/{id}` | La primera versión de un hecho es la que se cita |
| `POST /ninos` | Pendiente. Ver `docs/10` |
| `GET /accesos` | La bitácora se ve en la base. La pantalla es pendiente |
| `POST /pagos/{id}/ver` | No hay verificación de pagos |

---

## Cómo probarla

Con la sesión abierta, desde el navegador:

```js
// El panel
await fetch('/api/panel', { credentials: 'same-origin' })

// El estado de una autorización
await fetch(`/api/ninos/${ninoId}/retiros/estado/${responsableId}`, {
    credentials: 'same-origin'
})

// Un retiro
await fetch(`/api/ninos/${ninoId}/retiros`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ responsable_id: responsableId })
})
```

Todas van a `/api/...` del **servidor de Nuxt**, que reenvía a la API. El
navegador nunca habla con la API de Laravel. Ver
[01 · Arquitectura](01-ARQUITECTURA.md).