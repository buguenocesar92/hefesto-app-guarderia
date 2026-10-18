# 00 · Índice

Los once documentos de esta aplicación, y en qué orden leerlos.

---

## Para quien va a operar el sistema

1. **[06 · Primer niño y responsable](06-PRIMER-NINO-Y-RESPONSABLE.md)** — el
   camino completo, del ingreso al retiro.
2. **[07 · Seguridad y Ley 19.628](07-SEGURIDAD-Y-LEY-19628.md)** — qué datos
   hay, quién los ve, y por qué.

## Para quien va a instalar

1. **[04 · Instalación](04-INSTALACION.md)** — de cero a funcionando.
2. **[05 · Configuración](05-CONFIGURACION.md)** — cada variable, y cuál quedó
   pendiente.
3. **[03 · Multi-tenancy y roles](03-MULTITENANCY-Y-ROLES.md)** — los dos roles
   de base de datos, y por qué son dos.

## Para quien va a mantener

1. **[01 · Arquitectura](01-ARQUITECTURA.md)** — las tres capas.
2. **[02 · Modelo de datos](02-MODELO-DE-DATOS.md)** — cada tabla y por qué.
3. **[09 · API](09-API.md)** — los endpoints.
4. **[08 · Respaldos](08-RESPALDOS.md)** — las seis reglas y la restauración.

## Antes de usar esto con datos de nadie

**[10 · Pruebas y estado](10-PRUEBAS-Y-ESTADO.md).** Dice la verdad: nada se ha
ejecutado, y esto es exactamente lo que hay que correr antes de poner un niño
dentro.

---

## Los once documentos

### 01 · [Arquitectura](01-ARQUITECTURA.md)

Las tres capas: interfaz, API y base. Por qué el navegador no habla con la API.
Por qué la sesión va en la base y no en un archivo. Por qué la ficha médica está
en su propia tabla.

### 02 · [Modelo de datos](02-MODELO-DE-DATOS.md)

Las quince tablas, una por una, con la decisión que hay detrás de cada una. Por
qué el saldo no es una columna. Por qué el nombre y el documento se congelan en
el registro de retiro.

### 03 · [Multi-tenancy y roles](03-MULTITENANCY-Y-ROLES.md)

**Parte 1: el aislamiento.** `tenant_id`, Row Level Security con `USING` y
`WITH CHECK`, `FORCE ROW LEVEL SECURITY`, el contexto con `set_config(..., true)`
y la función que falla cerrado.

**Parte 2: los roles dentro de la guardería.** Los tres roles escalonados, de
dónde sale el permiso de abrir una ficha médica, y por qué un rechazo responde
**404 y no 403**.

**Parte 3: los dos roles de base de datos.** Por qué `guarderia_app` y el rol de
arranque están separados, y por qué un superusuario se salta las políticas
aunque la tabla tenga `FORCE`.

### 04 · [Instalación](04-INSTALACION.md)

De cero a funcionando, en desarrollo y en producción. Los tres errores que
rompen la instalación y cómo se reconocen.

### 05 · [Configuración](05-CONFIGURACION.md)

Cada variable de entorno, qué hace y qué pasa si se deja vacía. Y una lista
explícita de lo que quedó **pendiente de verificación**.

### 06 · [Primer niño y responsable](06-PRIMER-NINO-Y-RESPONSABLE.md)

El recorrido completo: crear la guardería, el primer ingreso de una persona,
dar de alta un niño, sus responsables, su autorización, registrar el ingreso y
hacer el primer retiro. Con los errores que aparecen en el camino.

### 07 · [Seguridad y Ley 19.628](07-SEGURIDAD-Y-LEY-19628.md)

**El documento más importante de esta aplicación.**

Qué es dato de menores y qué es dato de salud. El consentimiento del adulto
responsable. El acceso escalonado por rol. La auditoría de quién abrió qué
ficha y cuándo. La autorización de retiro.

Y los plazos de conservación: **no se inventan**. Si no hay uno configurado, el
campo queda en `null` y se documenta como decisión pendiente del cliente.

### 08 · [Respaldos](08-RESPALDOS.md)

Las seis reglas obligatorias, dónde está cada una en el guion, y el procedimiento
de restauración con sus cuatro pasos en orden.

### 09 · [API](09-API.md)

Cada endpoint, qué permiso necesita y qué devuelve. Y qué significa un 404 en
esta aplicación.

### 10 · [Pruebas y estado](10-PRUEBAS-Y-ESTADO.md)

**La verdad.** Qué se escribió, qué se comprobó, y qué **no** se ejecutó. En
qué orden correrlo antes de poner esto con datos de nadie.

---

## Una nota sobre las convenciones de este repositorio

Las convenciones de nombres, estructura, rutas y formato vienen de las
aplicaciones anteriores de Hefesto. Guardería sigue el patrón de
`apps/psicologia` para el aislamiento y el acceso escalonado por rol, y el de
`apps/parqueadero` para los guiones de respaldo.

No hay un cuarto patrón. Cuando algo no calza, se rompe la excepción y se escribe
por qué en el archivo.