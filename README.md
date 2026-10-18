# Guardería

Aplicación de **guardería y centro infantil** para una PyME chilena, de
**Hefesto Studio**. Software original de Hefesto: no es un despliegue de una
herramienta de terceros.

---

## Qué resuelve

No es un colegio: no hay notas ni conducta escolar. No es una escuela de música.

El problema central es **legal antes que técnico**: la responsabilidad sobre un
menor y la trazabilidad de quién lo retiró.

Lo que hace de verdad:

| Función | Qué resuelve |
|---|---|
| **Niños y responsables** | Cada niño tiene responsables autorizados, y **sólo un responsable autorizado puede retirarlo**. Es la función más importante del sistema. |
| **Retiro con registro** | Quién retiró, a qué hora, con qué documento y en nombre de quién. **Se registra siempre**, también el rechazo: un intento sin autorización es lo que hay que poder reconstruir tres meses después. |
| **Ingreso y egreso por día** | Asistencia con entrada y salida, y si falta, el motivo y quién avisó. Un niño que no llega y nadie avisó es una situación que el sistema pone adelante. |
| **Autorizaciones con vencimiento** | Cada autorización tiene fecha de vencimiento y **una vencida no sirve**. El sistema avisa **antes** de que expire. |
| **Restricciones alimentarias y médicas** | Alergias, alimentación, medicación. Es **dato de salud de un menor**, y su acceso está escalonado por rol: el administrativo **no** lo ve. |
| **Planes y mensualidades** | Planes, matrículas, pagos y saldos calculados. Sin tarifas inventadas. |
| **Incidencias** | Qué pasó, cuándo y qué se hizo. Un registro de incidencias es lo que sostiene una conversación difícil con la familia. |

---

## Las tres decisiones que definen esta aplicación

**1. Un responsable no autorizado NO retira a un niño.**
Y el rechazo se registra igual, con motivo y hora. Ver
[`docs/07-SEGURIDAD-Y-LEY-19628.md`](docs/07-SEGURIDAD-Y-LEY-19628.md).

**2. La ficha médica es dato de salud y está separada.**
Vive en su propia tabla, con su propia Row Level Security, su propia ruta y su
propia bitácora. El rol administrativo recibe **404**, no 403. Ver
[`docs/03-MULTITENANCY-Y-ROLES.md`](docs/03-MULTITENANCY-Y-ROLES.md).

**3. El aislamiento entre guarderías está en la base, no en el código.**
Columna `tenant_id`, políticas con `USING` y `WITH CHECK`,
`FORCE ROW LEVEL SECURITY`, y dos roles de base de datos separados.

---

## Estructura

```
apps/guarderia/
├── api/        Laravel 13 + PHP 8.3.  Modelos, migraciones, Policies, servicios.
├── web/        Nuxt 4 + TypeScript.  La interfaz.
├── deploy/     Dockerfiles, compose, proxy, respaldos y el script del rol de base.
└── docs/       Once documentos numerados, más este README.
```

---

## Empezar

```bash
# 1. Configurar
cp api/.env.example api/.env
cp web/.env.example web/.env
#    Complete los valores. Ver docs/05.

# 2. Levantar
docker compose -f deploy/docker-compose.dev.yml up -d

# 3. Crear la guardería
docker compose -f deploy/docker-compose.dev.yml exec api \
  php artisan guarderia:crear --nombre="Mi Guardería" --slug="mi-guarderia"

# 4. VERIFICAR EL AISLAMIENTO. Este paso no es opcional.
docker compose -f deploy/docker-compose.dev.yml exec api \
  php artisan guarderia:verificar-aislamiento
```

Instalación completa en [`docs/04-INSTALACION.md`](docs/04-INSTALACION.md).

---

## Documentos

| # | Documento | De qué trata |
|---|---|---|
| 00 | [Índice](docs/00-INDICE.md) | El mapa de los once documentos |
| 01 | [Arquitectura](docs/01-ARQUITECTURA.md) | Las tres capas y por qué |
| 02 | [Modelo de datos](docs/02-MODELO-DE-DATOS.md) | Las tablas y las decisiones de cada una |
| 03 | [Multi-tenancy y roles](docs/03-MULTITENANCY-Y-ROLES.md) | RLS, los dos roles de base de datos, los tres roles del centro |
| 04 | [Instalación](docs/04-INSTALACION.md) | Cómo se levanta, y los tres errores que la rompen |
| 05 | [Configuración](docs/05-CONFIGURACION.md) | Cada variable, y cuál está pendiente |
| 06 | [Primer niño y responsable](docs/06-PRIMER-NINO-Y-RESPONSABLE.md) | El camino completo, paso a paso |
| 07 | [Seguridad y Ley 19.628](docs/07-SEGURIDAD-Y-LEY-19628.md) | **El documento más importante de esta aplicación** |
| 08 | [Respaldos](docs/08-RESPALDOS.md) | Las seis reglas, y cómo restaurar |
| 09 | [API](docs/09-API.md) | Los endpoints y qué permiten |
| 10 | [Pruebas y estado](docs/10-PRUEBAS-Y-ESTADO.md) | **Lo que se corrió y lo que no** |

---

## Estado

**Nada se ha ejecutado.** En la máquina donde se escribió esta aplicación no
hay PHP, ni Composer, ni Docker, ni PostgreSQL. Cero migraciones aplicadas, cero
pruebas corridas, cero imágenes construidas.

[`docs/10-PRUEBAS-Y-ESTADO.md`](docs/10-PRUEBAS-Y-ESTADO.md) dice exactamente qué
falta correr, en qué orden, y qué se debe comprobar en cada paso. Léalo antes de
usar esto con datos de nadie.

---

## Lo que está pendiente de verificación

Se prefiere el hueco al dato inventado. Queda marcado como pendiente:

- **Los plazos legales de conservación.** No se verificó qué plazo corresponde a
  la ficha de un menor en un centro infantil. Los campos están en `null` y el
  sistema **no borra nada** por su cuenta. Ver `docs/07`.
- **Las tarifas.** Esta aplicación no trae ni una. Los precios los carga cada
  centro. Ver `docs/05`.
- **El correo saliente.** No hay transporte implementado. El aviso de
  vencimiento se escribe en el registro del servidor, no en el correo. Ver
  `docs/10`.
- **El dominio, el tipo de documento y el certificado de la autoridad interna.**
  Están vacíos a propósito. Ver `docs/04`.