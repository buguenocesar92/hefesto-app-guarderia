# 08 · Respaldos

Los dos guiones de `deploy/respaldos/`:

- `respaldo.sh` — sacar un respaldo.
- `restauracion.sh` — ponerlo de vuelta.

Las seis reglas obligatorias están en `respaldo.sh` y en `restauracion.sh`, con el
número de la regla al lado de cada una. Esta página explica por qué son así.

**Nada de esto se ha ejecutado.** Ver
[10 · Pruebas y estado](10-PRUEBAS-Y-ESTADO.md).

---

# Las seis reglas obligatorias

| # | Regla | Dónde está |
|---|---|---|
| 1 | Manifiesto con SHA-256 de cada archivo | `respaldo.sh`, sección "El manifiesto" |
| 2 | `COMPLETO` sólo al final, `EN_CURSO` mientras corre, y **antes** de la retención | `respaldo.sh`, dos secciones separadas |
| 3 | Validar rutas y espacio libre antes de escribir; el destino debe existir | `respaldo.sh`, "Las validaciones" |
| 4 | Destino ocupado se rechaza, salvo `--force` | `respaldo.sh`, "El destino ocupado" |
| 5 | Nunca `DROP`, nunca sobreescritura automática; la retención avisa qué borra | Los dos guiones; la lista es explícita |
| 6 | La retención va **después** de verificar el respaldo nuevo | `respaldo.sh`, al final |

---

# Regla 1 · El manifiesto con SHA-256

Un respaldo sin manifiesto es un archivo sin nombre. No se sabe si sigue íntegro.

`respaldo.sh` escribe `manifiesto.sha256` con el resumen de cada archivo, y lo
verifica antes de marcar el respaldo como completo.

**El manifiesto no se incluye a sí mismo**, porque un archivo no puede contener su
propio resumen. Se anota el volcado, que es lo que hay que verificar.

El SHA-256 detecta corrupción de disco y modificación. En una base con fichas
médicas de menores, saber que el respaldo de hace seis meses sigue íntegro es la
diferencia entre poder restaurarlo y tener que empezar de nuevo.

---

# Regla 2 · `COMPLETO` sólo al final

## Las dos marcas

| Marca | Significa |
|---|---|
| `EN_CURSO` | El respaldo empezó y **no terminó**. Puede estar a medias |
| `COMPLETO` | Terminó, y el SHA-256 **coincidió** |

Un respaldo sin `COMPLETO` no es un respaldo. Es un intento.

## El orden, y por qué importa

```
1. Se escribe EN_CURSO        <- antes de empezar
2. Se hace el volcado
3. Se escribe el manifiesto
4. Se VERIFICA el SHA-256
5. Se escribe COMPLETO         <- DESPUÉS de verificar
6. Se borra EN_CURSO
7. Recién ahora, la retención
```

**`COMPLETO` se escribe después de verificar, no después del volcado.**

La diferencia importa cuando el respaldo está dañado: con este orden queda sin
marca, y `restauracion.sh` se niega a usarlo. Con el orden inverso quedaría
marcado como bueno, y nadie lo sabría hasta que haga falta.

## Y antes de la retención

La retención viene **al final**, y sólo corre si el respaldo nuevo pasó la
verificación.

Si el respaldo nuevo falló, el guion sale con error y la retención no corrió. Es
decir: **no se borra un respaldo viejo porque el nuevo no se pudo hacer.**

---

# Regla 3 · Validar antes de escribir

**Nada se escribe hasta que todo lo que se puede comprobar está comprobado.**

Y el script **no crea el directorio de destino**.

Eso parece un detalle y es lo contrario: si lo creara, un error de tipeo en la ruta
produciría un respaldo nuevo y vacío en una carpeta que nadie está mirando,
mientras el respaldo real estaría en la carpeta anterior.

## Lo que comprueba

| Comprobación | Por qué |
|---|---|
| El destino **existe** | Un error de tipeo no puede crear una carpeta vacía |
| Hay permiso de escritura | |
| La ruta es **absoluta** | Un respaldo se corre desde un cron y a mano. Una ruta relativa significa un destino distinto según el directorio |
| Las herramientas existen | |
| El espacio libre alcanza | Un disco lleno a mitad deja un directorio con `EN_CURSO` y un archivo a medias |

## El espacio libre

Se estima al cincuenta por ciento del tamaño de la base sin comprimir, y se
compara con el espacio disponible.

Es un margen que falla poco. Un margen que falla a menudo se apaga, y un respaldo
que nunca se hace no sirve para nada.

---

# Regla 4 · Destino ocupado se rechaza

Un respaldo por día. Si ya existe el de hoy, el guion **no lo sobreescribe**: sale
con un error.

## Por qué no se sobreescribe, ni con `--force`

Un respaldo que se sobreescribe **no es un respaldo nuevo**: es el mismo archivo
con el volcado abriéndolo encima.

Si la máquina se apaga a mitad, queda un respaldo **corrupto** donde antes había
uno bueno. Y como se llama todos los días, nadie se da cuenta hasta que hace falta
restaurarlo.

## Qué hace `--force`

**Escribe en otro lado.** Con la hora en el nombre:

```
/var/respaldo/guarderia/2026-10-18        <- el primero
/var/respaldo/guarderia/2026-10-18-143022  <- el segundo, con --force
```

Los dos quedan. `--force` no es "reemplazar", es "poner aparte".

---

# Regla 5 · Nunca `DROP`, nunca sobreescritura automática

## En `respaldo.sh`

**No hay un solo `DROP` en el archivo.** Y no hay ninguna operación que
sobreescriba un archivo existente: `--force` escribe en otro lado.

## La retención avisa qué borra, ANTES de borrar

No es un aviso después. El guion **lista** lo que va a borrar:

```
[respaldo] la retención BORRARÍA estos respaldos:

  - /var/respaldo/guarderia/2026-09-01  (48M)
  - /var/respaldo/guarderia/2026-09-02  (48M)

Escriba BORRAR para confirmar:
```

## Y pide la palabra exacta

```
read -r -p "¿Confirma que borre esos respaldos? Escriba BORRAR para confirmar: "
```

Un `sí` a secas no basta.

## Nunca borra un respaldo sin `COMPLETO`

```bash
[ -f "${CANDIDATO}/${MARCA_COMPLETO}" ] || continue
```

Uno a medias puede ser el respaldo de hoy, y borrarlo porque es viejo sería
borrar el único respaldo bueno.

## Y nunca borra un directorio que no parezca un respaldo

```bash
if ! [[ "${CANDIDATO_FECHA}" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
    continue
fi
```

Un directorio que alguien puso a mano no se borra porque se le parezca a uno
viejo.

## Sin terminal, no borra

Si el guion corre desde un cron, no hay nadie para confirmar:

```
[respaldo] no hay terminal para pedir confirmación. NO se borra nada.
```

Borrar sin que nadie lo haya pedido, en un directorio de respaldos de una
guardería, no es una operación que se pueda hacer a ciegas.

---

# Regla 6 · La retención va después de verificar

Ya está dicho en la regla 2, y es lo más importante del resumen:

**No se borra un respaldo viejo porque el nuevo no se pudo hacer.**

El orden del final de `respaldo.sh` es:

```
volcado
  -> manifiesto
    -> verificación del SHA-256
      -> COMPLETO
        -> retención
```

Si algo falla antes de `COMPLETO`, el guion sale con error y **la retención no
corre**.

---

# La restauración

## Los cuatro pasos, en este orden

### 1 · El SHA-256, ANTES de tocar la base

```bash
SHA_CALCULADO="$(sha256sum "${RUTA_RESPALDO}/datos.dump.gz" | cut -d' ' -f1)"
SHA_ESPERADO="$(cut -d' ' -f1 "${RUTA_RESPALDO}/${NOMBRE_MANIFIESTO}" | head -1)"
```

Se comparan los dos valores y se muestran.

Si no coinciden, el guion sale **sin haber tocado nada**:

```
[restauracion] el SHA-256 NO coincide.
[restauracion] El archivo está dañado o fue modificado.
[restauracion] NO se tocó la base.
```

Un respaldo dañado restaurado deja la instalación sin los datos que tenía, y sin
los nuevos. La comprobación cuesta un segundo.

Y antes del SHA-256, comprueba que existe la marca `COMPLETO`. Un respaldo sin esa
marca es un intento interrumpido.

### 2 · Pide confirmación

Con la palabra exacta, y mostrando qué se va a hacer:

```
  SE VA A RESTAURAR:
    Desde:  /var/respaldo/guarderia/2026-10-18  (48M)
    Hacia:  guarderia

  LO QUE PASA:
    1. Se guarda la base actual en un volcado de emergencia.
    2. Se vacían las tablas.
    3. Se restaura este respaldo encima.

Escriba RESTAURAR para continuar:
```

Y con `--si-confirmado` para una emergencia deliberada por consola. Queda escrito
quién lo hizo y cuándo.

### 3 · Deja el servicio detenido

```bash
docker compose -f "${COMPOSE_FILE}" stop api web
```

**Antes de escribir, no después.**

Una aplicación escribiendo en la base mientras se restaura deja filas a medias en
dos procesos distintos. En esta aplicación eso no son facturas: son registros de
retiro.

Y un retiro que queda a medias es un retiro que nadie puede confirmar ni desmentir.

### 4 · Comprueba que el aislamiento se conservó

Al final, el guion comprueba cuatro cosas:

| Qué | Por qué |
|---|---|
| Las 13 tablas de negocio tienen RLS **activada y forzada** | Un respaldo puede ser de una versión anterior |
| `guarderia_actual()` existe y es `SECURITY INVOKER` | Sin ella, las políticas no filtran |
| `vw_guarderias_publicas` expone `id,nombre,slug` | Si no, el ingreso expone datos de contacto |
| Los 4 disparadores anti-`DELETE` siguen puestos | Si no, los registros se pueden borrar |

Y si algo falla:

```
[restauracion] LA BASE RESTAURADA NO CONSERVA EL AISLAMIENTO.
[restauracion] Los datos están, pero las políticas no están.
[restauracion] NO abra la instalación al público todavía.
```

**Esto importa.** Un respaldo puede haber sido tomado de una base a la que le
faltaba el `FORCE ROW LEVEL SECURITY`, o de una versión anterior de las
migraciones. Restaurar sin comprobar deja una base con los datos **pero sin la
protección**, y eso es peor que no restaurar: parece que sí.

## Y no hay ningún `DROP`

Para dejar la base como estaba, `restauracion.sh`:

1. **Vuelca la base ACTUAL** a un archivo de emergencia.
2. **Trunca** las tablas con `TRUNCATE ... RESTART IDENTITY CASCADE`.
3. Restaura el respaldo encima.

Así hay una salida. Un `DROP DATABASE` no la tiene.

`guarderias` **no** se trunca: es la tabla de inquilinos y sin ella no hay
contexto con el que trabajar.

La salida queda en:

```
/var/respaldo/guarderia/2026-10-18/antes-de-restaurar-20261018-143022/
```

---

# Cómo usarlo

## Sacar un respaldo

```bash
deploy/respaldos/respaldo.sh --directorio /var/respaldo/guarderia
```

Con otra retención:

```bash
deploy/respaldos/respaldo.sh --directorio /var/respaldo/guarderia --dias 45
```

Sin retención, la primera vez:

```bash
deploy/respaldos/respaldo.sh --directorio /var/respaldo/guarderia --sin-retencion
```

## Restaurar

```bash
deploy/respaldos/restauracion.sh --respaldo /var/respaldo/guarderia/2026-10-18
```

## Las variables de entorno

Los guiones leen:

| Variable | |
|---|---|
| `DB_HOST` | `postgres` |
| `DB_PORT` | `5432` |
| `DB_DATABASE` | `guarderia` |
| `DB_USERNAME` | `guarderia_app` |
| `DB_PASSWORD` | **La de `guarderia_app`** |
| `COMPOSE_FILE` | `deploy/docker-compose.prod.yml` |

**`DB_USERNAME` debe ser `guarderia_app`, no el rol de arranque.**

Una conexión con el rol de arranque se salta las políticas de Row Level Security
durante el volcado. El archivo resultante sería consistente, pero el respaldo se
habría hecho saltándose la protección.

## PENDIENTE: la ruta por omisión

`/var/respaldo/guarderia` es la ruta por omisión y **no se verificó que exista en
ninguna instalación**. El guion lo comprueba y falla con un mensaje claro si no
está.

Cree el directorio antes de la primera corrida:

```bash
sudo mkdir -p /var/respaldo/guarderia
sudo chown "$(id -u):$(id -g)" /var/respaldo/guarderia
```

---

# La retención y el cron

## El respaldo sí se programa

```cron
# A las dos de la mañana, cuando el centro está cerrado.
0 2 * * * /ruta/al/respaldo.sh --directorio /var/respaldo/guarderia
```

## La retención NO se programa

**Y esa es la decisión.**

La retención pide confirmación con la palabra `BORRAR`, y sin terminal no borra
nada. Si se corre desde un cron, no va a borrar nunca.

Se puede programar con `--dias` alto y revisando a mano lo que queda, pero la idea
es que **una persona vea qué respaldos se van a perder antes de que se pierdan**.

En una guardería, un respaldo que se borre por una regla y no por una decisión es
un respaldo que se pierde cuando más se necesitaba.

---

# Qué NO incluye el respaldo

| No incluye | Por qué |
|---|---|
| Los volúmenes de Docker | El respaldo es de la **base**, no del sistema |
| Los certificados | Deben estar en el repositorio y en el gestor de secretos |
| La configuración | `.env`, que no se versiona y debe estar en un gestor de secretos |

**Si se pierde el volumen de PostgreSQL, el respaldo no basta.** Hay que
reconstruir la instalación con [04 · Instalación](04-INSTALACION.md) y después
restaurar.

El orden correcto es:

```
1. Reconstruir la instalación vacía, con el MISMO volumen.
2. Verificar el aislamiento.
3. Restaurar.
4. Verificar el aislamiento OTRA VEZ.
```

El paso 4 no es redundante: una base restaurada puede haber perdido las políticas,
y la comprobación del final de `restauracion.sh` lo detecta.

---

# Una comprobación periódica

Un respaldo que nunca se restauró **no es un respaldo**. Es una suposición.

Cada mes, en un entorno que **no** sea el de producción:

1. Levanté la instalación.
2. Restaure el respaldo más antiguo.
3. Compruebe que una ficha médica se ve.
4. Compruebe que un retiro rechazado se ve.
5. Compruebe que `guarderia:verificar-aislamiento` dice que está bien.

Si eso no funciona, el respaldo no sirve, y hay que enterarse **antes** de que lo
necesite.

---

# Resumen

| Regla | Cómo se cumple |
|---|---|
| 1 · Manifiesto SHA-256 | `manifiesto.sha256` con el resumen de cada archivo, verificado antes de `COMPLETO` |
| 2 · `COMPLETO` al final | `EN_CURSO` al principio, `COMPLETO` después de verificar, retención al final |
| 3 | Destino existente, ruta absoluta, espacio libre, herramientas. Antes de escribir nada |
| 4 | Se rechaza con error. `--force` escribe aparte, con la hora |
| 5 | Cero `DROP`. La retención lista y pide `BORRAR`. Nunca borra sin `COMPLETO` |
| 6 | La retención es lo último, y sólo si el respaldo nuevo pasó la verificación |

Y en la restauración: **SHA-256 antes de tocar la base**, confirmación con la
palabra exacta, **servicio detenido**, y **comprobación de las políticas al
final**.