# 03 · Multi-tenancy y roles

Este documento tiene tres partes:

1. El aislamiento entre guarderías.
2. Los roles dentro de la guardería.
3. Los dos roles de base de datos.

La tercera es la que más veces se olvida y la que más veces ha hecho que todo lo
demás no sirviera.

---

# Parte 1 · El aislamiento entre guarderías

## El patrón

Columna `tenant_id` en cada tabla de negocio, más Row Level Security en cada una.

Las dos capas hacen trabajos distintos:

- **La columna** es comodidad. Evita tener que acordarse de filtrar.
- **La política** es la protección. No depende de que nadie se acuerde.

Una consulta sin filtro escrita a mano, sin contexto, no lee nada.

## Por qué NO un esquema por guardería

Un esquema por guardería se parecería más al aislamiento, y aquí sería peor:

- La Row Level Security **no** aplica a los esquemas: habría que apoyarse en
  permisos de esquema, que son del usuario de conexión, no de la sesión. Con el
  mismo rol para todas las guarderías, el aislamiento dependería de cambiar de
  usuario por petición.
- El respaldo y la restauración se multiplican por guardería.
- El comando de verificación tendría que recorrer N esquemas y saber cuántos hay.
- El error de configuración —una guardería en el esquema equivocado— es silencioso.

La columna única con Row Level Security hace que el olvido **falla**, y un
aislamiento que falla al olvidarse no es un aislamiento.

## Las dos capas, en detalle

### La función de contexto

```sql
CREATE OR REPLACE FUNCTION public.guarderia_actual()
RETURNS uuid
LANGUAGE sql
STABLE
SECURITY INVOKER
AS $$
    SELECT current_setting('app.guarderia_id', true)::uuid
$$;
```

Tres cosas en esa definición, y las tres importan:

**`current_setting(..., true)`** — el `true` es el valor por omisión cuando la
variable no está definida. Sin contexto devuelve **NULL**, y `NULL::uuid` es
`NULL`, y `tenant_id = NULL` no es verdadero.

Eso es **fallar cerrado sin excepción**: la fila simplemente no aparece.

**`SECURITY INVOKER`** — corre con los privilegios de quien llama. Si fuera
`DEFINER`, correría con los de quien la creó, que es el dueño del esquema, y las
políticas se volverían decorativas.

**`STABLE`** — le dice a PostgreSQL que dentro de una consulta puede devolver el
mismo valor. Sin esto, PostgreSQL no puede asumir nada y el planificador hace
cosas raras.

### La función que falla cerrado CON excepción

Hay un camino inverso: cuando una operación **necesita** una guardería y no la
tiene.

```sql
CREATE OR REPLACE FUNCTION public.guarderia_fallar_sin_contexto()
RETURNS uuid
LANGUAGE plpgsql
STABLE
SECURITY INVOKER
AS $$
DECLARE
    contexto uuid;
BEGIN
    contexto := current_setting('app.guarderia_id', true)::uuid;

    IF contexto IS NULL THEN
        RAISE EXCEPTION
            'No hay contexto de guarderia en esta transaccion (app.guarderia_id).'
            USING ERRCODE = '42501';
    END IF;

    RETURN contexto;
END;
$$;
```

Una conexión de respaldo que se conecta sin ejecutar `set_config` falla con un
error que explica qué pasó, en vez de un 401 o un "no encontrado" que no dice
nada.

### La política

```sql
CREATE POLICY aislamiento_por_guarderia
    ON public.<tabla>
    FOR ALL
    TO PUBLIC
    USING      (tenant_id = public.guarderia_actual())
    WITH CHECK (tenant_id = public.guarderia_actual())
```

**`USING`** filtra lo que se **lee** y lo que se **modifica**.
**`WITH CHECK`** impide **escribir** filas de otra guardería.

Sin `WITH CHECK` se puede leer lo propio y escribir lo ajeno. Es el error de la
mitad de las instalaciones, y por eso hay una prueba que lo comprueba
(`test_la_politica_tiene_with_check`).

### `FORCE ROW LEVEL SECURITY`

En PostgreSQL, el dueño de una tabla **no** está sujeto a las políticas de su
propia tabla. Con la Row Level Security activada y sin `FORCE`, `guarderia_app`
—que es el dueño del esquema— leería todas las filas.

Con `FORCE`, también el dueño está sujeto.

```sql
ALTER TABLE public.ninos ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.ninos FORCE ROW LEVEL SECURITY;
```

## Cómo se fija el contexto

```php
DB::transaction(function () {
    DB::select("SELECT set_config('app.guarderia_id', ?, true)", [$guarderiaId]);

    return $operacion();
});
```

**El `true` es el alcance de TRANSACCIÓN.** Es lo que hace que el valor se descarte
al terminar.

Con alcance de SESIÓN, el valor sobrevive al cierre de la transacción y la
petición siguiente lee filas de la guardería anterior. Hay una prueba que lo
comprueba (`test_el_contexto_es_de_alcance_de_transaccion`): después de la
transacción, el valor tiene que haber desaparecido.

Y por eso esto va **siempre** dentro de una transacción. Un `set_config` suelto,
con alcance de transacción y sin transacción, no dura nada.

### Un detalle que no es evidente

`set_config` habla con la **sesión de PostgreSQL**, no con la sesión de PHP. Son
distintas.

Por eso la aplicación usa `DB_CONNECTION=pgsql` para los datos y Redis para el
estado de sesión de Laravel. No hay ningún conflicto: el estado de sesión de
Laravel está en la tabla `sesiones` de PostgreSQL y en Redis, y ninguna de las dos
cosas toca la variable `app.guarderia_id`.

## `guarderias` NO lleva Row Level Security

Porque se consulta antes del ingreso, cuando no hay sesión y por lo tanto no hay
`app.guarderia_id` que poner. Es la excepción consciente del patrón.

Para que la excepción no sea una fuga, antes del ingreso la aplicación **no lee
la tabla**: lee la vista.

## La vista pública, Y SE USA DE VERDAD

```sql
CREATE VIEW public.vw_guarderias_publicas AS
    SELECT id, nombre, slug
    FROM public.guarderias
    WHERE activo = true
```

**Tres columnas.** No expone razón social, dirección, correo ni teléfono.

### La ruta que la consulta

`app/Http/Controllers/Publico/GuarderiasPublicasController.php`:

```php
$guarderias = DB::table('vw_guarderias_publicas')
    ->select('id', 'nombre', 'slug')
    ->orderBy('nombre')
    ->get();
```

Y `AuthController` la consulta dos veces más: para validar el `slug` al pedir el
ingreso y para resolver la guardería al volver del proveedor de identidad.

### Por qué se insiste tanto

Porque en aplicaciones anteriores de Hefesto se creó `vw_<entidad>s_publicas` en la
migración, se documentó que la aplicación la consultaba, y **nunca se consultó**:
el controlador leía la tabla entera.

Acá el uso está en el código y hay tres cosas que lo vigilan:

1. `tests/Feature/Aislamiento/VistaPublicaSeUsaTest.php` lee el **código fuente**
   del controlador y falla si encuentra `DB::table('guarderias')`.
2. La misma prueba consulta `information_schema` y exige exactamente
   `id, nombre, slug`, y falla si aparece `razon_social`, `direccion`, `telefono` o
   `correo`.
3. `guarderia:verificar-aislamiento` hace la misma comprobación en el servidor.

## Verificación

```bash
php artisan guarderia:verificar-aislamiento
```

**Antes de recibir al primer niño.** Una instalación en la que este comando no se
ha corrido **no está verificada**.

Comprueba, en orden:

| # | Qué comprueba | Por qué importa |
|---|---|---|
| 1 | `guarderia_actual()` existe y es `SECURITY INVOKER` | Con `DEFINER` las políticas no filtran |
| 2 | Las 14 tablas tienen Row Level Security **activada** | |
| 3 | Las 14 la tienen **forzada** | Sin `FORCE` el dueño se salta la política |
| 4 | Cada una tiene política, con `USING` y con `WITH CHECK` | |
| 5 | La vista pública expone exactamente tres columnas | |
| 6 | **Una consulta sin contexto NO lee filas** | **Este es el que importa** |

El punto 6 es el que no se puede deducir de los otros cinco.

Los puntos del 1 al 5 dicen que la protección está **escrita** como corresponde.
El punto 6 dice que **funciona**: que una conexión sin `app.guarderia_id` fijado
no lee ni una fila.

Un punto 6 que falla significa que una conexión sin contexto lee toda la base, y
eso es una fuga silenciosa: el sistema sigue funcionando, y nadie lo ve.

## El caso de `sesiones`, y por qué es distinto

`sesiones` tiene una política **distinta**, y no por descuido.

Se lee **antes** de que exista contexto: es la lectura que **resuelve** el
contexto. La fila de sesión dice a qué guardería pertenece la persona, y con eso
se fija `app.guarderia_id` para todo lo demás.

Con la política estricta, el orden sería imposible: la primera lectura de la
petición no encontraría su propia fila de sesión, porque `guarderia_actual()`
todavía no está fijado.

```sql
CREATE POLICY sesiones_aislamiento ON public.sesiones
    FOR ALL TO PUBLIC
    USING (
        public.guarderia_actual() IS NULL
        OR tenant_id IS NULL
        OR tenant_id = public.guarderia_actual()
    )
    WITH CHECK (mismo)
```

Se permite leer cuando **no** hay contexto. Con contexto fijado, vuelve la
política estricta: una guardería establecida no ve sesiones de otra.

## Riesgos que siguen abiertos

Están aquí porque un riesgo que no está escrito no se puede managear.

### 1. El riesgo residual de `sesiones`

Quien tenga un `id` de sesión ajeno puede leer esa fila **cuando no hay contexto**.

Un `id` de sesión es un identificador aleatorio de 40 caracteres; es la misma
cookie que ya habría que presentar para usarla; y la fila contiene el sujeto de
Authentik, **no datos de menores**.

**No se puede eliminar sin romper el orden de arranque.** Y el riesgo no
crece con el número de guarderías.

**Cómo se reduce:** el identificador de sesión debe seguir siendo aleatorio y lo
bastante largo. Acá es de 40 caracteres.

### 2. El rol de arranque puede leer todo

`guarderia_admin` es superusuario, y **un superusuario siempre se salta las
políticas de Row Level Security, incluso con `FORCE`**.

Por eso la aplicación **nunca** lo usa. Y por eso `guarderia:verificar-aislamiento`
debería comprobar también quién corre la conexión. **Hoy no lo hace, y es una
mejora pendiente.** Ver [10 · Pruebas y estado](10-PRUEBAS-Y-ESTADO.md).

### 3. La conexión de respaldo

Un respaldo de la base hay que sacarlo con un rol con permiso de lectura, y ese
rol puede leer todo. Está fuera del alcance de la Row Level Security: es una
propiedad de la herramienta, no del esquema.

**Cómo se reduce:** sacar los respaldos con `guarderia_app`, que sí está sujeto a
las políticas, o sacar un `pg_dump` desde dentro del contenedor de la aplicación.

### 4. Los datos en memoria

Una consulta dentro de PHP que lee filas ya pasó la Row Level Security. La
política protege el acceso; no puede deshacer lo que ya se leyó.

**Cómo se reduce:** no registrar datos de menores en el registro del servidor. Por
eso `display_errors = Off` en producción.

---

# Parte 2 · Los roles dentro de la guardería

## Los tres roles

| | Ve la ficha médica | Ve pagos | Opera la puerta | Da de alta autorizados | Ve la bitácora |
|---|:---:|:---:|:---:|:---:|:---:|
| **Educadora** | **sí** | no | sí | no | no |
| **Administrativo** | **no** | **sí** | sí | no | no |
| **Dirección** | **sí** | sí | sí | **sí** | **sí** |

## La distinción que define el producto

No es que un rol tenga menos permisos que otro. Es que **cada uno tiene un trabajo
distinto, y el de un rol no incluye el del siguiente**.

**Educadora** necesita ver las alergias para servir el almuerzo. Ve la ficha
médica. No ve los pagos: no es su trabajo y el dato no le sirve para nada.

**Administrativo** ve la asistencia, los retiros, las autorizaciones y los pagos:
es quien atiende el teléfono y cobra. **No ve la ficha médica.**

Y esto no es "no puede saber si el niño es alérgico". Es que la ficha médica **no
le corresponde**.

**Dirección** administra el centro. Es la responsable legal del cuidado de los
menores, y por eso sí abre la ficha médica. También es la única que ve la
bitácora de accesos y la única que da de alta autorizados.

## De dónde sale el permiso de abrir fichas médicas

**De `match`, en el enum.** No de un campo.

```php
enum RolGuarderia: string
{
    case Educadora = 'educadora';
    case Administrativo = 'administrativo';
    case Direccion = 'direccion';

    public function abreFichasMedicas(): bool
    {
        return match ($this) {
            self::Educadora, self::Direccion => true,
            self::Administrativo => false,
        };
    }
}
```

Y **no existe** un `ve_ficha_medica` en ninguna tabla.

Un booleano por usuario es un campo que alguien puede cambiar sin querer, que
nadie recuerda auditar, y que un día vale `true` para una administrativa porque
alguien lo probó. Con roles escalonados, el permiso es una consecuencia del rol y
se puede razonar sin mirar ninguna fila.

## Por qué 404 y no 403

Un **403** dice "esto existe y tú no puedes". Un **404** dice "esto no existe".

En una guardería la diferencia importa de verdad:

- La ficha médica de un niño, la autorización de un responsable y el registro de
  retiro son datos que **existen**. Un 403 aprende que ese niño existe, que esa
  ficha tiene contenido, y que hay una autorización.
- En el registro de retiros, el 403 revelaría además que hubo un **intento
  denegado** y a quién le correspondía. Esa es exactamente la información que el
  registro guarda para proteger al menor, no para mostrarla a quien preguntó.

`App\Policies\Concerns\RespondeNoEncontrado` produce el 404 y
`bootstrap/app.php` lo traduce:

```php
$exceptions->render(function (AuthorizationException $e, Request $request) {
    if ($request->is('api/v1/*')) {
        return response()->json(['mensaje' => 'No encontrado.'], 404);
    }
    return null;
});
```

## La bitácora y el motivo

Cada acceso a una ficha médica se anota en `accesos`: quién, con qué rol, qué
niño, cuándo, y si se permitió o se denegó.

Y el motivo del rechazo va en el registro. No siempre se muestra al que pregunta —
eso lo decide la pantalla—, pero **siempre queda escrito**.

## Por qué la interfaz explica

Una pantalla vacía sin explicación hace que la persona piense que el sistema
falló. En esta aplicación, una pantalla vacía casi siempre significa que no tiene
permiso: mejor decirlo.

Por eso `RolGuarderia::accesoAFichaMedica()` devuelve el texto **completo** de por
qué un rol no entra, y la pantalla de fichas médicas lo muestra. Y por eso el
menú de la interfaz esconde el enlace de fichas médicas al administrativo, en vez
de dejarlo y que dé 404 al apretarlo.

## Dónde se aplica cada permiso

| Permiso | Dónde |
|---|---|
| Abrir o guardar ficha médica | `FichaMedicaPolicy` + middleware `medica.auditada` |
| Registrar retiro | `RetiroPolicy::registrar` |
| **Autorizar el retiro en sí** | `ServicioRetiro` + `EvaluadorDeAutorizacion`. **No es un permiso** |
| Ver la bitácora de retiros | `RetiroPolicy::verBitacora` |
| Dar de alta una autorización | `AutorizacionPolicy::crear` |
| Registrar una ausencia | `AsistenciaPolicy::marcarAusencia` |
| Anular un pago | `PagoPolicy::anular` |
| Matricular un niño | `NinoPolicy::crear` |
| Ver el registro de accesos | Middleware `direccion` |
| Ver usuarios del centro | Middleware `direccion` |

### Por qué dar de alta un autorizado es de dirección

Porque quien autoriza a una persona a retirar a un niño le está dando, en la
práctica, acceso a ese niño.

Educadora y administrativo **pueden leer** las autorizaciones —lo necesitan para
explicar un rechazo en la puerta— pero no pueden darlas de alta. La lectura no
expone dato de salud: expone nombre, documento y fecha.

## Lo que la interfaz hace y lo que no

**Hace:** esconder botones que van a fallar. Ocultar el enlace de fichas médicas
al administrativo. Poner el motivo del rechazo en un cartel grande.

**No hace:** decidir. Si alguien falsea la vista desde el navegador, la API igual
rechaza el retiro, deja el registro del rechazo y anota el intento en la
bitácora.

La interfaz es una cortesía. La protección está en la base y en la Policy.

---

# Parte 3 · Los dos roles de base de datos

## Por qué son dos

Porque **un superusuario SIEMPRE se salta las políticas de Row Level Security**.

No hay configuración que lo desactive. No hay extensión que lo arregle. Es una
propiedad de PostgreSQL.

Y como cada tabla lleva `FORCE ROW LEVEL SECURITY`, la pregunta natural es
"¿entonces `FORCE` no lo arregla?". La respuesta es **no**: `FORCE` sujeta al
**dueño** de la tabla, y un superusuario se salta la Row Level Security
**aunque la tabla tenga `FORCE`**.

Entonces, si la aplicación se conectara con el rol de arranque:

- El código se vería correcto.
- Las políticas estarían escritas.
- Las migraciones se habrían aplicado.
- Y la protección entre guarderías **no estaría funcionando**.

Es el fallo más difícil de detectar de esta arquitectura, porque **no se ve en
ninguna prueba que no mire los atributos del rol**.

## Los dos roles

| | `guarderia_admin` | `guarderia_app` |
|---|---|---|
| Nombre en la configuración | `POSTGRES_USER` | `DB_USERNAME` |
| Para qué existe | Arrancar el clúster | Correr la aplicación |
| Lo usa la aplicación | **NO** | **SÍ** |
| Dueño inicial del clúster | **SÍ** | No |
| Superusuario | **SÍ** | **NO** |
| `BYPASSRLS` | **SÍ** | **NO** |
| Crea bases y roles | **SÍ** | **NO** |
| Dueño del esquema `public` | No | **SÍ** |
| Ve las políticas | Se las salta | **Está sujeto a ellas** |

## Cómo se crea `guarderia_app`

En `deploy/postgres/inicializar-rol.sh`, que corre **una sola vez**: cuando el
volumen de PostgreSQL está vacío y el servidor inicializa el clúster. Está en
`/docker-entrypoint-initdb.d/`.

```sql
CREATE ROLE guarderia_app
    WITH LOGIN
    NOSUPERUSER      -- no se salta la Row Level Security
    NOCREATEDB       -- no crea bases
    NOCREATEROLE     -- no crea roles
    NOINHERIT        -- no hereda privilegios del rol de arranque
    NOBYPASSRLS      -- explícito y redundante, a propósito
    NOREPLICATION
    PASSWORD '...';
```

### Por qué `NOINHERIT`

Porque si a alguien se le añadiera `guarderia_app` como miembro de un rol con más
privilegios, sin `NOINHERIT` los heredaría. Un `GRANT` accidental le daría a la
aplicación superusuario sin que nadie lo escribiera en ninguna parte.

### Por qué `NOBYPASSRLS` si ya es `NOSUPERUSER`

Porque es **redundante a propósito**.

`NOSUPERUSER` y `NOBYPASSRLS` son cosas distintas en PostgreSQL, y esta
aplicación depende de las dos. Si alguien quita el superusuario y olvida el otro,
el script sigue siendo defendible: queda una restricción que dice exactamente lo
que esta aplicación necesita.

### Por qué `ALTER DATABASE ... OWNER TO`, y no `GRANT ALL`

```sql
ALTER DATABASE guarderia OWNER TO guarderia_app;
```

Es lo que permite que las migraciones creen las tablas.

La alternativa sería `GRANT ALL ON SCHEMA public`, y esa tiene un problema
distinto: los permisos del esquema se heredan a las tablas que se creen después, y
en un clúster compartido el alcance no es el que uno cree.

Con `OWNER` el rol es dueño de **su** esquema, y nada más. Y como dueño, le
aplica `FORCE ROW LEVEL SECURITY` cuando las migraciones crean las tablas con
esa opción.

## El orden de arranque

```
 1. El servidor de PostgreSQL inicializa el clúster.
       Dueño: guarderia_admin.   Es el único superusuario.

 2. Corre /docker-entrypoint-initdb.d/10-crear-rol.sh.
       Crea guarderia_app con las cinco restricciones explícitas.
       Le da el esquema.
       Imprime los atributos del rol para que se vean.

 3. El contenedor de la API arranca.
       Sus migraciones corren como guarderia_app.
       Crean las tablas con ENABLE y FORCE ROW LEVEL SECURITY.

 4. guarderia:verificar-aislamiento.
       Dice si todo eso quedó como debe.
```

**El paso 2 corre una sola vez.** Si el volumen ya existe, no se vuelve a
ejecutar, y por eso un despliegue sobre una base creada sin él deja la aplicación
**sin rol**. Ver [04 · Instalación](04-INSTALACION.md).

## Lo que hay que comprobar en una instalación real

```bash
# ¿Con qué rol se conecta la aplicación?
docker compose -f deploy/docker-compose.prod.yml exec api \
  php -r 'echo DB::selectOne("select current_user as u")->u;'

# ¿Es superusuario? Tiene que decir false.
docker compose -f deploy/docker-compose.prod.yml exec api \
  php -r 'echo DB::selectOne("select usesuper from pg_user where usename = current_user")->usesuper ? "SI" : "NO";'

# ¿Se salta las políticas? Tiene que decir false.
docker compose -f deploy/docker-compose.prod.yml exec api \
  php -r 'echo DB::selectOne("select rolbypassrls from pg_roles where rolname = current_user")->rolbypassrls ? "SI" : "NO";'
```

Las dos respuestas tienen que ser **NO**. Si cualquiera es **SÍ**, la protección
entre guarderías no está funcionando, por más correcto que se vea el código.

**Esto debería estar dentro de `guarderia:verificar-aislamiento` y hoy no lo
está.** Es una mejora pendiente y está anotada en
[10 · Pruebas y estado](10-PRUEBAS-Y-ESTADO.md).

## Referencias

- `apps/colegio/docs/03-MULTITENANCY-Y-ROLES.md` — el patrón original.
- `apps/almacen/deploy/postgres/inicializar-rol.sh` — el script verificado de los
  dos roles.
- `apps/notaria` — el uso real de `vw_<entidad>s_publicas`, a diferencia de las
  aplicaciones donde la vista se creó y nunca se consultó.