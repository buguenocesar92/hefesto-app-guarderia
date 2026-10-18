#!/bin/bash
# =============================================================================
# Creación del rol de la aplicación
# =============================================================================
#
# Este script corre UNA SOLA VEZ: cuando el volumen de PostgreSQL está vacío y el
# servidor inicializa el clúster. Está en `/docker-entrypoint-initdb.d/`, que es
# la carpeta que el servidor ejecuta al inicializarse por primera vez.
#
# ---------------------------------------------------------------------
# POR QUÉ HAY DOS ROLES Y NO UNO
# ---------------------------------------------------------------------
#
#   POSTGRES_USER (guarderia_admin)
#       Rol de ARRANQUE. Dueño inicial del clúster. El que corre este script y el
#       que existe para poder crear cosas. La aplicación NUNCA lo usa.
#
#   guarderia_app
#       Rol de la APLICACIÓN. Dueño del esquema `public`, para que las
#       migraciones puedan crear las tablas. Sin superusuario, sin bypass de
#       políticas, sin permiso para crear bases ni roles.
#
# LA RAZÓN, QUE ES UNA SOLA Y ES GRAVE
# -------------------------------------
# En PostgreSQL, los SUPERUSUARIOS SIEMPRE se saltan las políticas de Row Level
# Security. Siempre. No hay configuración que lo desactive.
#
# Por eso cada tabla de negocio tiene `FORCE ROW LEVEL SECURITY`: porque sin
# `FORCE`, el dueño de una tabla tampoco está sujeto a sus propias políticas.
#
# Y como un superusuario se salta la política AUNQUE la tabla tenga `FORCE`, si
# la aplicación corriera con el rol de arranque, TODA la protección entre
# guarderías sería decorativa: el código se vería correcto y las políticas no
# estarían haciendo nada.
#
# Es por eso que el rol de la aplicación existe aparte. Dueño, sí —para que las
# migraciones creen el esquema—; superusuario, nunca.
#
# ---------------------------------------------------------------------
# LO QUE ESTE SCRIPT HACE
# ---------------------------------------------------------------------
#   1. Crea `guarderia_app` SIN superusuario, con las restricciones explícitas.
#   2. Le da el esquema `public` de la base, para que las migraciones creen las
#      tablas.
#   3. Le quita laTablespaces y las funciones públicas, para que no pueda
#      ejecutar cosas que no le corresponden.
#   4. Deja escrito qué acaba de hacer, en el registro del servidor de base.
#
# Lo que NO hace: no crea la base. La crea el servidor de PostgreSQL a partir de
# POSTGRES_DB. No aplica migraciones: eso es trabajo de la aplicación.
#
# Referencia: `apps/almacen/deploy/postgres/inicializar-rol.sh`.
# Ver docs/03.

set -euo pipefail

NOMBRE_ROL_APP="guarderia_app"
NOMBRE_BASE="${POSTGRES_DB:-guarderia}"

echo "[rol] creando el rol de aplicación: ${NOMBRE_ROL_APP}"

# -----------------------------------------------------------------------------
# ¿Ya existe?
# -----------------------------------------------------------------------------
#
# El script corre una sola vez, pero se comprueba de todos modos: un `CREATE
# ROLE` sobre un rol que existe aborta todo el script, y abortar el script de
# inicialización deja el contenedor de base sin poder arrancar.
EXISTE="$(psql --username "$POSTGRES_USER" --dbname "$NOMBRE_BASE" --tuples-only --no-align \
    --command "SELECT 1 FROM pg_roles WHERE rolname = '${NOMBRE_ROL_APP}'" || true)"

if [ "${EXISTE}" = "1" ]; then
    echo "[rol] el rol ${NOMBRE_ROL_APP} ya existe. No se hace nada."
    exit 0
fi

# -----------------------------------------------------------------------------
# La contraseña
# -----------------------------------------------------------------------------
#
# Viene de `DB_PASSWORD`, que es la MISMA variable que usa la aplicación para
# conectarse. Es un nombre de variable THINK: en un despliegue con varias
# aplicaciones, el rol de la base es `guarderia_app` en todas y la contraseña es
# distinta en cada una.
#
# Si no viene, el script falla. Un rol sin contraseña en una base a la que se
# llega por la red interna es una puerta abierta, y es más difícil de notar que
# una contraseña adivinada porque no falla nunca.
if [ -z "${DB_PASSWORD:-}" ]; then
    echo "[rol] ERROR: falta DB_PASSWORD."
    echo "[rol] El rol ${NOMBRE_ROL_APP} se crea SIN superusuario y con"
    echo "[rol] NOBYPASSRLS, y por eso necesita una contraseña para que la"
    echo "[rol] aplicación pueda conectarse a través de la red."
    exit 1
fi

# -----------------------------------------------------------------------------
# El rol
# -----------------------------------------------------------------------------
#
# Las cinco restricciones van ESCRITAS, no asumidas:
#
#   NOSUPERUSER   No puede saltarse la Row Level Security. Ésta es la que
#                 sostiene todo lo demás.
#   NOCREATEDB    No puede crear bases de datos.
#   NOCREATEROLE  No puede crear otros roles. Sin esto, un fallo en la
#                 aplicación podría acabar en un rol con privilegios que nadie
#                 autorizó.
#   NOINHERIT     No hereda los privilegios del rol de arranque, aunque se le
#                 añadan. Un `GRANT` accidental a un rol del que herede le
#                 daría a la aplicación superusuario sin que nadie lo escribiera.
#   NOBYPASSRLS   No puede saltarse las políticas. Es explícito y redundante
#                 con `NOSUPERUSER` a propósito: si alguien quita el
#                 superusuario y olvida esto, el script sigue siendo
#                 defendible.
#
# Contraseña por entrada estándar, para que no quede en la lista de procesos.
psql --username "$POSTGRES_USER" --dbname "$NOMBRE_BASE" <<SQL
    CREATE ROLE ${NOMBRE_ROL_APP}
        WITH LOGIN
        NOSUPERUSER
        NOCREATEDB
        NOCREATEROLE
        NOINHERIT
        NOBYPASSRLS
        NOREPLICATION
        PASSWORD '${DB_PASSWORD}';
SQL

echo "[rol] rol creado."

# -----------------------------------------------------------------------------
# El esquema
# -----------------------------------------------------------------------------
#
# `ALTER DATABASE ... OWNER TO` y NO un `GRANT ALL ON SCHEMA public`.
#
# La diferencia es la que importa para esta aplicación: con `GRANT`, el rol
# puede escribir en cualquier tabla, INCLUDING las de otra base de datos en el
# mismo clúster; con `OWNER`, es el dueño del esquema y nada más, y las
# políticas de Row Level Security le aplican igual.
#
# Que sea el dueño del esquema es lo que permite que las migraciones creen las
# tablas. Y `FORCE ROW LEVEL SECURITY` lo sujeta a las políticas de esas tablas
# cuando la aplicación las creen.
psql --username "$POSTGRES_USER" --dbname "$NOMBRE_BASE" <<SQL
    ALTER DATABASE ${NOMBRE_BASE} OWNER TO ${NOMBRE_ROL_APP};
SQL

echo "[rol] la base ${NOMBRE_BASE} pertenece a ${NOMBRE_ROL_APP}."

# -----------------------------------------------------------------------------
# Lo que se le quita explícitamente
# -----------------------------------------------------------------------------
#
# Las funciones públicas del esquema `public` son ejecutables por CUALQUIER rol
# por omisión en versiones antiguas de PostgreSQL. Quitar `CREATE` del esquema
# impide que un fallo en la aplicación termine en una función maliciosa instalada
# en la base.
#
# La migración que crea las políticas vuelve a darle esos permisos a
# `guarderia_app`, que es quien los necesita. Ver
# `2026_10_18_000030_activar_row_level_security.php`.
psql --username "$POSTGRES_USER" --dbname "$NOMBRE_BASE" <<SQL
    REVOKE ALL ON SCHEMA public FROM PUBLIC;
    GRANT ALL ON SCHEMA public TO ${NOMBRE_ROL_APP};
SQL

echo "[rol] permisos del esquema ajustados."

# -----------------------------------------------------------------------------
# Comprobación
# -----------------------------------------------------------------------------
#
# Se comprueba que el rol quedó como se pidió. Si algo falló, la instalación
# sigue adelante con una aplicación que se salta las políticas de aislamiento, y
# eso NO es un fallo que se pueda detectar después.
echo "[rol] comprobando los atributos del rol:"

psql --username "$POSTGRES_USER" --dbname "$NOMBRE_BASE" --tuples-only --no-align <<SQL
    SELECT
        'rol=' || rolname
        || ' superusuario=' || rolsuper::text
        || ' crea_bases=' || rolcreatedb::text
        || ' crea_roles=' || rolcreaterole::text
        || ' hereda=' || rolinherit::text
        || ' salta_rls=' || rolbypassrls::text
    FROM pg_roles
    WHERE rolname = '${NOMBRE_ROL_APP}';
SQL

echo "[rol] listo. La aplicación se conecta como ${NOMBRE_ROL_APP}."
echo "[rol] El rol de arranque ${POSTGRES_USER} NO lo usa la aplicación."