# 07 · Seguridad y Ley 19.628

**El documento más importante de esta aplicación.**

No es un capítulo de cumplimiento. Es la explicación de qué datos hay, quién los
ve, y por qué. Si algo de lo que sigue está mal, el sistema no sirve para lo que
se construyó.

---

## Lo primero: qué es esta aplicación

Una aplicación que registra **datos personales de menores de edad**, y entre esos
datos, **datos de salud**.

Eso la pone en la categoría más restrictiva que existe en esta área. No por la
tecnología: por lo que hay adentro.

Un sistema de facturación con datos de una persona es un problema de datos
personales. Un sistema con las alergias, la medicación y el plan de emergencia de
un niño de cuatro años es otra cosa, y por eso las decisiones de acá son más
estrictas que en el resto de las aplicaciones de Hefesto.

---

# 1 · Los datos

## Qué es dato de menor

Todo lo de `ninos` y `responsables`:

| Dato | Dónde |
|---|---|
| Nombre y apellido | `ninos.nombres`, `ninos.apellidos` |
| Fecha de nacimiento | `ninos.fecha_nacimiento` |
| Sexo | `ninos.sexo` |
| RUT | `ninos.rut` |
| Nombre y documento de los responsables | `responsables` |
| Teléfono y dirección de los responsables | `responsables` |
| Parentesco y responsabilidad legal | `responsable_nino` |

Cada fila de `ninos` identifica a un menor. Un nombre y un apellido identifican a
una persona; **un nombre y un fecha de nacimiento, en un centro con cien niños,
identifican a un menor y son datos que no se publican**.

## Qué es dato de SALUD

Todo lo de `fichas_medicas`, y es lo más sensible que hay:

| Dato | Por qué es salud |
|---|---|
| Alergias | Una condición del cuerpo, y una posible reacción grave |
| Alimentos prohibidos | Una forma de la alergia, con consecuencias inmediatas |
| Indicación de alimentación | Condición médica o con equipo |
| Medicación | Un medicamento, con una dosis y un horario |
| Plan de emergencia | Qué hacer si algo pasa |
| Contacto de emergencia | A quién llamar |
| Autoriza medicamento | Una decisión sobre el cuerpo del menor |

**Una alergia identifica a alguien por su cuerpo y por una condición que la
persona no eligió tener.** Es el dato más íntimo de esta aplicación, y por eso
tiene una tabla sola, con una política sola, una ruta sola y una bitácora sola.

## Qué es dato de SALUD Y ADEMÁS es de un tercero

Esto es lo que suele pasarse por alto.

`retiros` dice que una persona con un RUT específico no estaba autorizada para
retirar a un menor. `asistencias.motivo_ausencia` puede decir "el niño no vino
porque estaba en tratamiento". `incidencias` puede describir un episodio de salud.

**Todo eso es dato de salud del menor, escrito en tablas que no se llaman
`fichas_medicas`.**

Y aquí está el problema de verdad: el sistema protege `fichas_medicas` con mucho
cuidado, y el resto de las tablas con el cuidado que corresponde a cada una. Pero
el acceso escalonado de `fichas_medicas` **no protege** un motivo de ausencia
escrito en `asistencias`.

**Lo que se hizo y lo que falta:**

**Lo que se hizo:**
- La ficha médica está en su propia tabla, sin datos de salud en `ninos`.
- El acceso está escalonado por rol, con roles reales.
- Cada lectura y cada escritura queda en `accesos`.
- El registro de retiros congela el nombre y el documento.

**Lo que falta, y hay que decirlo:**
- `asistencias` y `incidencias` no distinguen un motivo de salud de uno que no lo
  es. Un administrativo que ve la asistencia del día ve los motivos de ausencia de
  todos los niños.
- **No hay un campo que marque "este motivo es de salud".** Y sin ese campo, no se
  puede filtrar por rol.

**Cómo se corrige, cuando el cliente lo decida:**
agregar un booleano `es_dato_de_sensible` a `asistencias` y a `incidencias`, con
una restricción en la base que exija el motivo cuando esté marcado, y extender la
Policy para que un administrativo no pueda leer esas filas.

**Eso NO está hecho.** Está anotado aquí para que no se pierda, y en
[10 · Pruebas y estado](10-PRUEBAS-Y-ESTADO.md) como pendiente.

---

# 2 · El consentimiento del adulto responsable

## Qué se pide y dónde queda

Esta aplicación **no pide el consentimiento**. No tiene un formulario de
consentimiento, no tiene una casilla de aceptación, y no lo escribe en ninguna
tabla.

Eso es una decisión, no un olvido, y hay que entender por qué antes de usarla.

## Por qué no hay formulario de consentimiento

Un formulario que dijera "acepto que se guarden estos datos" y una casilla que
alguien marca, **no es consentimiento informado**. Es un registro de que alguien
apretó un botón.

El consentimiento informado en esta materia tiene que decir:

- **Quién** es el responsable legal del menor, y que la persona que lo firma es esa
  persona y no otra.
- **Qué** datos se guardan, en concreto. No "datos del niño": qué campos, para qué.
- **Para qué** se usan. Y para qué **no** se usan.
- **Cuánto tiempo** se guardan.
- **Quién** puede verlos, y qué roles.
- Que puede **revocar** el consentimiento, y qué pasa con lo que ya se guardó.

Esa conversación tiene que tener lugar, y tiene que quedar en un documento. Un
formulario en pantalla no puede producirlo.

## Qué sí tiene esta aplicación

**La autorización de retiro.** `autorizaciones_retiros` es un documento con:

- El niño.
- El responsable.
- El documento con que se identificó al firmar.
- La fecha desde la que sirve.
- La fecha en que deja de servir.
- Quién la registró.

Eso es la autorización que el centro necesita para hacer lo que hace, y es la que
permite que el sistema diga "no" cuando tiene que decirlo.

## Lo que hay que hacer antes de usar esto

**Obtener el consentimiento por escrito, fuera de esta aplicación.**

Los documentos que un centro necesita tener, y que esta aplicación **no genera**:

| Documento | Para qué |
|---|---|
| Consentimiento de tratamiento de datos del menor | El resguardo general |
| Autorización de retiro, por persona | `autorizaciones_retiros` es el registro de esto, no el documento |
| Autorización de uso de imágenes del menor | Este sistema no guarda imágenes |
| Autorización de atención de emergencia | `fichas_medicas.plan_emergencia` es el dato, no el documento firmado |

**Este sistema NO reemplaza ninguno de esos documentos.** Guarda el registro
operativo; el documento firmado es del centro y va en su carpeta.

Y eso hay que decirlo a quien use el sistema, porque es exactamente el tipo de
suposición que después cuesta una conversación con una familia.

---

# 3 · El acceso escalonado por rol

## Los tres roles

| | Ficha médica | Pagos | Puerta | Autorizados | Bitácora |
|---|:---:|:---:|:---:|:---:|:---:|
| **Educadora** | **sí** | no | sí | no | no |
| **Administrativo** | **no** | **sí** | sí | no | no |
| **Dirección** | **sí** | sí | sí | **sí** | **sí** |

## Roles reales, no campos booleanos

**No existe un `ve_ficha_medica` en ninguna tabla.** El permiso sale del rol:

```php
public function abreFichasMedicas(): bool
{
    return match ($this) {
        self::Educadora, self::Direccion => true,
        self::Administrativo => false,
    };
}
```

Un booleano por persona es un campo que alguien puede cambiar sin querer, que
nadie audita, y que un día vale `true` para una administrativa porque alguien lo
probó. Con roles escalonados, el permiso es una consecuencia del rol.

## Por qué la administrativa NO ve la ficha

Porque ve la asistencia, los retiros, las autorizaciones y los pagos: es quien
atiende el teléfono y cobra. Eso es su trabajo.

Y las alergias y la medicación de un menor **no son su trabajo**.

No es que "no pueda saber si el niño es alérgico". Es que la ficha médica no le
corresponde, y el sistema lo hace explícito en vez de esconderlo.

## Por qué la educadora SÍ

Porque **necesita** ver las alergias antes de servir el almuerzo. Sin eso, está
serviendo comida a un niño sin saber qué no puede darle.

## La separación en la base, no sólo en la pantalla

`fichas_medicas` está en su propia tabla. En `ninos` no hay nada de salud.

La razón: `ninos` se lista en pantalla, se consulta desde el panel, y la lee el
administrativo. Si las alergias estuvieran ahí, la ficha dejaría de estar
protegida el día que alguien escribiera una consulta a `ninos` para armar una
lista, y eso es un cambio de una tarde que nadie revisa.

## Por qué 404 y no 403

Un **403** dice "esto existe y tú no puedes". Un **404** dice "esto no existe".

Un 403 aprende que ese niño existe, que tiene ficha, y que hay una autorización.

Y en el registro de retiros, el 403 revelaría además que hubo un **intento
denegado** y a quién le correspondía. Esa es exactamente la información que el
registro guarda para proteger al menor, no para mostrársela a quien preguntó.

## Lo que hace el middleware `medica.auditada`

Está en **todas** las rutas de ficha médica, no en las que alguien se acuerde.

Anota en `accesos`: el usuario, **el rol que tenía en ese momento**, el niño, la
acción (`leer`, `crear`, `actualizar`), el resultado (`permitido` o `denegado`), el
motivo del rechazo, la ruta y la dirección.

Va en un middleware y no en el controlador porque, si estuviera en el controlador,
la próxima ruta nueva se olvidaría de anotarlo — y el olvido es silencioso: el
registro simplemente deja de tener un dato.

---

# 4 · La auditoría

## Qué se anota

**Cada acceso a una ficha médica.** Permitido y denegado.

**Los retiros.** Autorizados y rechazados, con el rol del operador y la hora.

**Lo que NO se anota:** la lectura de la lista de niños, de la asistencia o de los
pagos. Son consultas menos sensibles, y anotar todo tendría un costo que no se
justifica con el riesgo que corre.

## Un acceso denegado también se anota

Porque es información: es el caso en que alguien pidió abrir una ficha que no le
correspondía.

Si sólo se anotara lo permitido, el registro sería la lista de las cosas que el
sistema dejó pasar, y no diría nada sobre lo que alguien intentó hacer.

## El rol está COPIADO

Porque la bitácora tiene que decir el rol que tenía la persona **al abrir la
ficha**, y no el que tiene hoy. Dentro de seis meses nadie se acuerda de que era
administrativa y hoy es directora.

## La bitácora NO es la protección

La bitácora sirve para reconstruir. La que impide abrir la ficha es la Row Level
Security más la Policy.

Si alguien encuentra un modo de abrir una ficha sin permiso, eso va a quedar
anotado acá; y eso es un problema del que hay que enterarse mirando el registro,
no de que haya pasado inadvertido.

## Quién ve la bitácora

**Sólo dirección.** Y es de dirección porque dice quién del equipo vio qué dato de
menor. Es información sobre el equipo, y el equipo no debería auditarse a sí
mismo.

---

# 5 · La autorización de retiro

Es la función más importante del sistema. Está en su propia sección porque lo es.

## La regla

**Sólo un responsable autorizado puede retirar a un niño.**

Nunca "un adulto que viene a buscarlo". Nunca "alguien que conoce al niño".

## Cómo se comprueba

1. Se busca la autorización vigente de ese responsable **para ese niño**.
2. Se comprueba la **fecha de hoy**, contra el reloj del **servidor**.
3. Si la fecha pasó, está vencida. Si alguien la anuló, está anulada.

**La fecha MANDA sobre el estado guardado.** Una fila marcada `vigente` con la
fecha pasada está vencida, y el sistema la trata como vencida.

**Contra el reloj del servidor**, no el del navegador: una pantalla con el reloj
desfasado no puede decidir quién se lleva a un niño.

## Por qué tiene fecha de vencimiento

Porque sin ella, una vez autorizada una persona, queda autorizada para siempre. Y
una autorización permanente no se puede revertir con el software.

Con fecha, hay un momento en que deja de servir, y ese momento **está escrito, se
ve en pantalla grande y avisa antes de llegar**.

## Las tres respuestas distintas

| Situación | Qué dice la pantalla |
|---|---|
| No hay autorización | "no está autorizado para retirar a este niño" |
| Está vencida | "la autorización venció el [fecha] y no fue renovada" |
| Está anulada | "la autorización fue anulada: [motivo]" |

Las tres NO son la misma, y la diferencia es de minutos en la puerta: "usted nunca
ha estado autorizada" y "usted estaba autorizada y el papel se venció el 12 de
marzo" llevan a conversaciones completamente distintas.

Una de ellas es una falla del centro. La otra es una falla de la familia. El
sistema tiene que poder distinguir, porque la familia tiene derecho a saberlo.

## El registro del rechazo

**Un intento rechazado se registra SIEMPRE.**

Con quién llegó, a qué hora, con qué documento y con qué motivo se le rechazó.

Y lo escribe **en su propia transacción**, antes de lanzar el error. Si se escribiera
dentro de la transacción que después se revierte, el registro desaparecería junto
con el error — y el registro del rechazo es precisamente el registro que hay que
conservar.

## Lo que NO hay

- **No hay ruta para borrar un retiro.** Ni autorizada ni rechazado. Lo impone un
  disparador en la base.
- **No hay ruta para editar un retiro.** La primera versión de un hecho es la que
  se cita.
- **No hay forma de "corregir" un retiro.** Si el dato estaba mal, se deja el
  registro y se corrige el dato equivocado.

---

# 6 · Los plazos de conservación

## NO HAY VALOR POR OMISIÓN. Y NO ES UN OLVIDO.

Las cuatro variables están en `null`:

```dotenv
GUARDERIA_RETENCION_FICHAS_MEDICAS_DIAS=
GUARDERIA_RETENCION_ASISTENCIAS_DIAS=
GUARDERIA_RETENCION_RETIROS_DIAS=
GUARDERIA_RETENCION_ACCESOS_DIAS=
GUARDERIA_AVISAR_ANTES_DE_PURGAR=false
```

## Por qué están vacías

Porque **no se verificó** qué plazo de conservación corresponde a la ficha de un
menor en un centro infantil chileno, ni cuántos años se guardan los registros de
asistencia o de retiros.

Y peor: **aunque se verificara, no hay un plazo único que sirva para todos los
datos de un centro.** La ficha médica, el registro de retiro y el comprobante de
un pago no tienen la misma naturaleza, y probablemente no el mismo plazo.

Escribir un número sin fuente sería inventar un plazo legal. Esta aplicación no
inventa plazos legales.

## Qué hace el sistema con un plazo vacío

**No borra nada.**

No hay tarea programada que purgue. No hay `Job` de limpieza. No hay comando que
borre. La razón está escrita en `routes/console.php`:

> No hay ninguna tarea programada que borre ficha de un menor, ni asistencia, ni
> registro de retiros. No hay tarea que purgue nada.

Un plazo en `null` significa "no hay plazo configurado", y en esta aplicación eso
significa **conservar**.

## Por qué conservar es el error más seguro

Porque el error de conservar de más y el de borrar de más **no son el mismo error**.

- Conservar de más: el centro tiene una copia de más de algo que ya no debía
  tener. Es un problema de privacidad, y se corrige borrando.
- **Borrar de más: un registro de retiro que alguien puso con el documento con que
  se identificó, y que después se necesita para reconstruir qué pasó.** Eso no
  se corrige.

El segundo no tiene arreglo. Y por eso, ante la duda, el sistema conserva.

## Qué tiene que decidir el cliente

Para cada tipo de dato:

1. **Qué plazo corresponde**, y **de dónde sale ese plazo**: una norma, una
   política interna, una decisión de la dirección. **Anótalo.**
2. **Qué pasa con lo anterior** si el plazo se acorta después de haber estado
   guardando más: ¿se purga retroactivamente, o sólo desde la fecha de la decisión?
3. **Quién autoriza la purga**: una sola persona, con nombre.
4. **Qué se conserva igual**: los registros de retiro probablemente no se borran
   nunca, porque son un registro de responsabilidad.

Mientras eso no esté decidido, `null` es la respuesta.

---

# 7 · Lo que este sistema NO protege

Para que no haya que deducirlo:

**No cifra los datos en la base.** Si alguien tiene acceso a `guarderia_pruebas`,
tiene acceso a los datos de todos. La Row Level Security protege el acceso desde
la aplicación; no protege a quien tenga el disco.

**No manda datos fuera.** No hay correo saliente, ni analítica, ni terceros. Es una
decisión, y por eso no hay un contrato de tratamiento de datos que firmar.

**No tiene cifrado de extremo a extremo en las fichas.** Que eso existiera haría
imposible indexar y buscar, y es una decisión de diseño que esta versión no toma.

**No borra.** Ver la sección 6.

**No valida que un documento exista.** El RUT se guarda como lo declara la persona.
No se validó contra el registro civil, y esta aplicación no dice que un RUT sea
real.

**No sabe nada de la normativa de protección de menores.** No se leyó ninguna norma
sobre el registro y la supervisión de centros infantiles en Chile. Hay requisitos
de registro, habilitación y supervisión que **esta aplicación no conoce y no
cumple**, y que son responsabilidad del centro.

**Ese punto es el más importante de esta lista.** El sistema de gestión de un
centro no reemplaza la habilitación del centro. Antes de recibir al primer niño,
la dirección tiene que estar habilitada.

---

# 8 · El resumen

| Decisión | Por qué |
|---|---|
| La ficha médica en su propia tabla | Para que separarla sea el comportamiento por omisión |
| Roles escalonados, sin booleanos | Para que el permiso sea una consecuencia del rol |
| 404 en vez de 403 | Un 403 enseña que el dato existe |
| Cada acceso a una ficha, anotado | Un acceso denegado también es información |
| El rechazo del retiro, registrado siempre | Porque dentro de tres meses nadie se acuerda |
| La fecha manda sobre el estado guardado | Porque un estado guardado se desincroniza |
| Contra el reloj del servidor | Un reloj desfasado no puede autorizar a nadie |
| Sin `DELETE` en cuatro tablas | Un registro que se borra no sirve para nada |
| Sin plazo de conservación por omisión | Un plazo inventado es un plazo falso |
| Sin correo saliente | Menos superficie, menos contratos |
| Sin datos de salud en `ninos` | Para que un listado no exponga la ficha |

## Y lo que falta

Está todo anotado en [10 · Pruebas y estado](10-PRUEBAS-Y-ESTADO.md). Lo más
importante:

1. **No se verificó ningún plazo de conservación.** Está en `null` a propósito.
2. **`asistencias` e `incidencias` no marcan qué motivo es de salud.** El acceso
   escalonado de `fichas_medicas` no protege los motivos de ausencia.
3. **No hay formulario de consentimiento.** Y el que esta aplicación tiene —la
   autorización de retiro— es un registro operativo, no el documento firmado.
4. **`guarderia:verificar-aislamiento` no comprueba el rol con que se conecta.**
   Si alguien conecta con el rol de arranque, la verificación pasa igual.
5. **No se leyó ninguna normativa de habilitación de centros infantiles.**

## Una nota para quien reciba esto

Esta aplicación **no vuelve conforme a una guardería**.

Reduce el riesgo documentado, deja registro de lo que pasa en la puerta, y hace
que el aislamiento entre centros no dependa de que nadie cometa un error. Nada de
eso reemplaza:

- Los documentos firmados por el adulto responsable.
- La habilitación del centro.
- La decisión documentada sobre cuánto tiempo se conserva cada cosa.
- El criterio de la dirección.

Es una herramienta. Lo que se haga con ella sigue siendo responsabilidad de quien
dirige el centro.