#!/usr/bin/env bash
# =============================================================================
# Restauración de la base de datos de Guardería
# =============================================================================
#
# -----------------------------------------------------------------------------
# LAS CUATRO COSAS QUE HACE, EN ESTE ORDEN, Y POR QUÉ EN ESTE ORDEN
# -----------------------------------------------------------------------------
#
#   1. VERIFICA EL SHA-256 CONTRA EL MANIFIESTO, ANTES DE TOCAR LA BASE.
#   2. PIDE CONFIRMACIÓN.
#   3. DEJA EL SERVICIO DETENIDO.
#   4. COMPRUEBA QUE LA BASE RESTAURADA CONSERVA LAS POLÍTICAS DE AISLAMIENTO.
#
# -----------------------------------------------------------------------------
# POR QUÉ EN ESTE ORDEN Y NO EN OTRO
# -----------------------------------------------------------------------------
#
# EL SHA-256 PRIMERO, Y ANTES DE TOCAR LA BASE.
# Un respaldo con el SHA-256 que no coincide está dañado. Restaurarlo no es
# inofensivo: sobreescribe la base que está funcionando con un archivo que no
# sirve, y después no hay a qué volver. La comprobación cuesta un segundo.
#
# LA CONFIRMACIÓN DESPUÉS, Y CON LA PALABRA EXACTA.
# Restaurar borra los datos actuales. Es la única operación de esta aplicación
# que puede perder fichas de menores de un día para otro, y por eso no se hace
# con un `sí` a secas ni con un valor por omisión.
#
# EL SERVICIO DETENIDO, ANTES DE ESCRIBIR.
# Una aplicación escribiendo en la base mientras se restaura deja filas a medias en
# dos procesos distintos. En esta aplicación eso no son facturas: son registros
# de retiro. Un retiro que queda a medias es un retiro que nadie puede confirmar
# ni desmentir.
#
# LAS POLÍTICAS DE AISLAMIENTO, AL FINAL.
# Un respaldo puede haber sido tomado de una base a la que le faltaba el
# `FORCE ROW LEVEL SECURITY`, o de una versión anterior de las migraciones.
# Restaurar sin comprobar eso deja una base con los datos restored pero SIN la
# protección entre guarderías, y eso es peor que no restaurar: parece que sí.
#
# -----------------------------------------------------------------------------
# LO QUE NO HACE
# -----------------------------------------------------------------------------
#
# No hay ningún `DROP DATABASE` ni `DROP TABLE` con `--if-exists`. Para dejar la
# base como estaba, este script:
#
#   1. Vuelca la base ACTUAL a un archivo de emergencia.
#   2. Vacía las tablas con `TRUNCATE ... RESTART IDENTITY CASCADE`, que es
#      reversible con el paso 1.
#   3. Restaura el respaldo encima.
#
# Así hay una salida. Un `DROP DATABASE` no la tiene.
#
# -----------------------------------------------------------------------------
# USO
# -----------------------------------------------------------------------------
#   ./restauracion.sh --respaldo /var/respaldo/guarderia/2026-10-18
#
# Para una restauración de emergencia por consola, sin confirmar:
#   ./restauracion.sh --respaldo RUTA --si-confirmado
#
# PENDIENTE: la ruta por omisión es `/var/respaldo/guarderia` y NO se verificó
# que exista en esta instalación. Ver docs/08.

set -Eeuo pipefail

DIRECTORIO_RESPALDO="/var/respaldo/guarderia"
RUTA_RESPALDO=""
SI_CONFIRMADO="0"

MARCA_COMPLETO="COMPLETO"
NOMBRE_MANIFIESTO="manifiesto.sha256"

# =============================================================================
# Los argumentos
# =============================================================================

while [ "$#" -gt 0 ]; do
    case "$1" in
        --respaldo)
            RUTA_RESPALDO="${2:?--respaldo necesita un valor}"
            shift 2
            ;;
        --directorio)
            DIRECTORIO_RESPALDO="${2:?--directorio necesita un valor}"
            shift 2
            ;;
        --si-confirmado)
            SI_CONFIRMADO="1"
            shift
            ;;
        --ayuda|-h)
            echo "Uso: $0 --respaldo RUTA [--si-confirmado]"
            echo ""
            echo "  --respaldo RUTA     El directorio del respaldo. Debe tener la marca COMPLETO."
            echo "  --si-confirmado    No pregunta. Para uso por consola en una emergencia."
            exit 0
            ;;
        *)
            echo "Argumento desconocido: $1" >&2
            exit 2
            ;;
    esac
done

if [ -z "${RUTA_RESPALDO}" ]; then
    RUTA_RESPALDO="${DIRECTORIO_RESPALDO}/$(ls -1 "${DIRECTORIO_RESPALDO}" 2>/dev/null | grep -E '^[0-9]{4}-[0-9]{2}-[0-9]{2}' | sort | tail -1 || true)"
fi

if [ -z "${RUTA_RESPALDO}" ] || [ ! -d "${RUTA_RESPALDO}" ]; then
    echo "[restauracion] ERROR: no se encontró el respaldo en: ${RUTA_RESPALDO}" >&2
    exit 1
fi

echo "[restauracion] respaldo elegido: ${RUTA_RESPALDO}"

# =============================================================================
# Las herramientas
# =============================================================================

for HERRAMIENTA in sha256sum pg_restore pg_dump psql docker; do
    if ! command -v "${HERRAMIENTA}" >/dev/null 2>&1; then
        echo "[restauracion] ERROR: falta ${HERRAMIENTA}." >&2
        exit 1
    fi
done

# =============================================================================
# 1 · EL SHA-256, ANTES DE TOCAR LA BASE
# =============================================================================

echo ""
echo "[restauracion] comprobando el sello ${MARCA_COMPLETO}..."

if [ ! -f "${RUTA_RESPALDO}/${MARCA_COMPLETO}" ]; then
    echo "[restauracion] ERROR: este respaldo NO tiene la marca ${MARCA_COMPLETO}." >&2
    echo "[restauracion] Un respaldo sin esa marca es un intento interrumpido, no un respaldo." >&2
    exit 1
fi

echo "[restauracion] verificando el SHA-256 contra el manifiesto..."

if [ ! -f "${RUTA_RESPALDO}/${NOMBRE_MANIFIESTO}" ]; then
    echo "[restauracion] ERROR: falta el manifiesto ${NOMBRE_MANIFIESTO}." >&2
    echo "[restauracion] Sin manifiesto no hay forma de saber si el archivo está íntegro." >&2
    exit 1
fi

# Se calcula el SHA-256 y se COMPARA con lo que dice el manifiesto. No se usa
# `sha256sum --check` a secas: aquí se quiere ver el valor de las dos partes.
SHA_CALCULADO="$(sha256sum "${RUTA_RESPALDO}/datos.dump.gz" | cut -d' ' -f1)"
SHA_ESPERADO="$(cut -d' ' -f1 "${RUTA_RESPALDO}/${NOMBRE_MANIFIESTO}" | head -1)"

echo "[restauracion]   esperado:  ${SHA_ESPERADO}"
echo "[restauracion]   calculado: ${SHA_CALCULADO}"

if [ "${SHA_CALCULADO}" != "${SHA_ESPERADO}" ]; then
    echo ""
    echo "[restauracion] ERROR: el SHA-256 NO coincide." >&2
    echo "[restauracion] El archivo está dañado o fue modificado." >&2
    echo "[restauracion] NO se tocó la base. Un respaldo dañado restaurado deja la" >&2
    echo "[restauracion] instalación sin los datos que tenía, y sin los nuevos." >&2
    exit 1
fi

echo "[restauracion] sello correcto. La base no se ha tocado."

# =============================================================================
# La confirmación
# =============================================================================

TOTAL_INTENTO="$(du -sh "${RUTA_RESPALDO}" | cut -f1)"

echo ""
echo "========================================================================"
echo "  SE VA A RESTAURAR:"
echo "    Desde:  ${RUTA_RESPALDO}  (${TOTAL_INTENTO})"
echo "    Hacia:  ${DB_DATABASE:-guarderia}"
echo ""
echo "  LO QUE PASA:"
echo "    1. Se guarda la base actual en un volcado de emergencia."
echo "    2. Se vacían las tablas."
echo "    3. Se restaura este respaldo encima."
echo ""
echo "  Un respaldo nunca se sobreescribe ni se borra: el volcado de"
echo "  emergencia queda para poder volver atrás."
echo "========================================================================"
echo ""

if [ "${SI_CONFIRMADO}" != "1" ]; then
    if [ ! -t 0 ]; then
        echo "[restauracion] no hay terminal para pedir confirmación." >&2
        echo "[restauracion] Si esto es una emergencia deliberada, use --si-confirmado" >&2
        echo "[restauracion] y quede registrado quién lo hizo." >&2
        exit 1
    fi

    read -r -p "Escriba RESTAURAR para continuar: " CONFIRMACION

    if [ "${CONFIRMACION}" != "RESTAURAR" ]; then
        echo "[restauracion] cancelado. No se hizo nada."
        exit 1
    fi
fi

# =============================================================================
# 3 · EL SERVICIO DETENIDO
# =============================================================================
#
# Antes de tocar la base, no después. Una aplicación escribiendo mientras se
# restaura deja filas a medias en dos procesos, y en esta aplicación esas filas
# son registros de retiro de menores.
echo ""
echo "[restauracion] deteniendo los servicios..."

COMPOSE_FILE="${COMPOSE_FILE:-deploy/docker-compose.prod.yml}"

docker compose -f "${COMPOSE_FILE}" stop api web 2>/dev/null || {
    echo "[restauracion] aviso: no se pudieron detener los servicios con docker compose." >&2
    echo "[restauracion] Si la aplicación sigue corriendo, DETENGALA ANTES de seguir." >&2
    exit 1
}

echo "[restauracion] servicios detenidos."

# =============================================================================
# El volcado de emergencia
# =============================================================================
#
# ANTES de vaciar nada. Con esto, la operación es reversible: si la restauración
# sale mal, se restaura este archivo y la instalación queda como estaba.
CARPETA_EMERGENCIA="${RUTA_RESPALDO}/antes-de-restaurar-$(date +%Y%m%d-%H%M%S)"
mkdir -p "${CARPETA_EMERGENCIA}"

echo "[restauracion] guardando la base actual como emergencia..."

PGPASSWORD="${DB_PASSWORD:?falta DB_PASSWORD}" \
PGHOST="${DB_HOST:-postgres}" \
PGPORT="${DB_PORT:-5432}" \
PGUSER="${DB_USERNAME:-guarderia_app}" \
pg_dump \
    --dbname="${DB_DATABASE:-guarderia}" \
    --format=custom \
    --compress=9 \
    --no-owner \
    --no-privileges \
    --file="${CARPETA_EMERGENCIA}/base-anterior.dump"

cat > "${CARPETA_EMERGENCIA}/${MARCA_COMPLETO}" <<EOF
fin=$(date --iso-8601=seconds)
descripcion=Volcado de la base existente, tomado antes de una restauracion.
aviso=Este NO es un respaldo programado. Es la salida de esta operacion.
EOF

echo "[restauracion] salida guardada en: ${CARPETA_EMERGENCIA}"

# =============================================================================
# Vaciar y restaurar
# =============================================================================
#
# `TRUNCATE`, NO `DROP`. La diferencia es la reversibilidad: este script dejó el
# volcado anterior en disco, así que un error aquí tiene a dónde volver.
#
# `guarderias` NO se vacía: es la tabla de inquilinos y sin ella no hay contexto
# de guardería con el que trabajar. El respaldo trae las filas que falten.
echo ""
echo "[restauracion] vaciando las tablas..."

psql \
    --host="${DB_HOST:-postgres}" \
    --port="${DB_PORT:-5432}" \
    --username="${DB_USERNAME:-guarderia_app}" \
    --dbname="${DB_DATABASE:-guarderia}" \
    --set=ON_ERROR_STOP=1 <<'SQL'
    TRUNCATE TABLE
        accesos,
        pagos,
        matriculas,
        planes,
        incidencias,
        fichas_medicas,
        retiros,
        asistencias,
        autorizaciones_retiros,
        responsable_nino,
        responsables,
        ninos,
        sesiones,
        usuarios
    RESTART IDENTITY CASCADE;
SQL

echo "[restauracion] tablas vaciadas."

echo "[restauracion] restaurando..."

PGPASSWORD="${DB_PASSWORD}" \
PGHOST="${DB_HOST:-postgres}" \
PGPORT="${DB_PORT:-5432}" \
PGUSER="${DB_USERNAME:-guarderia_app}" \
pg_restore \
    --dbname="${DB_DATABASE:-guarderia}" \
    --no-owner \
    --no-privileges \
    --single-transaction \
    "${RUTA_RESPALDO}/datos.dump.gz"

echo "[restauracion] restauración terminada."

# =============================================================================
# 4 · LAS POLÍTICAS DE AISLAMIENTO
# =============================================================================
#
# El respaldo puede haber sido tomado de una base sin `FORCE ROW LEVEL
# SECURITY`, o de una versión anterior de las migraciones. Si eso pasó, la base
# restaurada tiene los datos PERO NO LA PROTECCIÓN, y eso es peor que no
# restaurar: parece que sí.
echo ""
echo "[restauracion] comprobando que las políticas de aislamiento sigan en pie..."

FALLOS=0

TABLAS_SIN_RLS="$(psql \
    --host="${DB_HOST:-postgres}" \
    --port="${DB_PORT:-5432}" \
    --username="${DB_USERNAME:-guarderia_app}" \
    --dbname="${DB_DATABASE:-guarderia}" \
    --tuples-only --no-align <<'SQL'
    SELECT string_agg(c.relname, ', ')
    FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public'
      AND c.relkind = 'r'
      AND c.relname IN (
          'usuarios', 'ninos', 'responsables', 'responsable_nino',
          'autorizaciones_retiros', 'asistencias', 'retiros',
          'fichas_medicas', 'planes', 'matriculas', 'pagos',
          'incidencias', 'accesos'
      )
      AND (c.relrowsecurity = false OR c.relforcerowsecurity = false);
SQL
)"

if [ -z "${TABLAS_SIN_RLS}" ]; then
    echo "[restauracion]   todas las tablas tienen RLS activada y forzada."
else
    echo "[restauracion]   SIN RLS FORZADA: ${TABLAS_SIN_RLS}" >&2
    FALLOS=$((FALLOS + 1))
fi

# La función de contexto tiene que existir y ser INVOKER.
ES_DEFINER="$(psql \
    --host="${DB_HOST:-postgres}" \
    --port="${DB_PORT:-5432}" \
    --username="${DB_USERNAME:-guarderia_app}" \
    --dbname="${DB_DATABASE:-guarderia}" \
    --tuples-only --no-align \
    --command "SELECT prosecdef FROM pg_proc WHERE proname = 'guarderia_actual'" 2>/dev/null || echo "")"

if [ -z "${ES_DEFINER}" ]; then
    echo "[restauracion]   NO EXISTE la función public.guarderia_actual()." >&2
    FALLOS=$((FALLOS + 1))
elif [ "${ES_DEFINER}" = "t" ]; then
    echo "[restauracion]   la función guarderia_actual() es SECURITY DEFINER." >&2
    echo "[restauracion]   Debe ser INVOKER, o las políticas no filtran." >&2
    FALLOS=$((FALLOS + 1))
else
    echo "[restauracion]   la función de contexto es SECURITY INVOKER."
fi

# La vista pública tiene que exponer tres columnas.
COLUMNAS_VISTA="$(psql \
    --host="${DB_HOST:-postgres}" \
    --port="${DB_PORT:-5432}" \
    --username="${DB_USERNAME:-guarderia_app}" \
    --dbname="${DB_DATABASE:-guarderia}" \
    --tuples-only --no-align \
    --command "SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'vw_guarderias_publicas'" 2>/dev/null || echo "")"

if [ "${COLUMNAS_VISTA}" != "id,nombre,slug" ]; then
    echo "[restauracion]   la vista pública expone: ${COLUMNAS_VISTA:-nada}. Se esperaba id,nombre,slug." >&2
    FALLOS=$((FALLOS + 1))
else
    echo "[restauracion]   la vista pública expone id, nombre y slug."
fi

# -----------------------------------------------------------------------------
# ¿Los disparadores que impiden borrar siguen puestos?
# -----------------------------------------------------------------------------
SIN_DISPARADOR="$(psql \
    --host="${DB_HOST:-postgres}" \
    --port="${DB_PORT:-5432}" \
    --username="${DB_USERNAME:-guarderia_app}" \
    --dbname="${DB_DATABASE:-guarderia}" \
    --tuples-only --no-align <<'SQL'
    SELECT string_agg(t.tabla, ', ')
    FROM (
        VALUES ('retiros'), ('autorizaciones_retiros'), ('incidencias'), ('accesos')
    ) AS t(tabla)
    WHERE NOT EXISTS (
        SELECT 1
        FROM pg_trigger tr
        WHERE tr.tgrelid = ('public.' || t.tabla)::regclass
          AND NOT tr.tgisinternal
    );
SQL
)"

if [ -z "${SIN_DISPARADOR}" ]; then
    echo "[restauracion]   los disparadores que impiden borrar están puestos."
else
    echo "[restauracion]   SIN DISPARADOR ANTI-BORRADO: ${SIN_DISPARADOR}" >&2
    FALLOS=$((FALLOS + 1))
fi

if [ "${FALLOS}" -gt 0 ]; then
    echo ""
    echo "[restauracion] LA BASE RESTAURADA NO CONSERVA EL AISLAMIENTO." >&2
    echo "[restauracion] Los datos están, pero las políticas no están." >&2
    echo "[restauracion] NO abra la instalación al público todavía." >&2
    echo ""
    echo "[restauracion] Corrija con:"
    echo "[restauracion]   docker compose -f ${COMPOSE_FILE} run --rm api \\"
    echo "[restauracion]     php artisan migrate --force"
    echo "[restauracion] y después vuelva a comprobar con:"
    echo "[restauracion]   docker compose -f ${COMPOSE_FILE} run --rm api \\"
    echo "[restauracion]     php artisan guarderia:verificar-aislamiento"
    exit 1
fi

# =============================================================================
# Levantar y verificar
# =============================================================================

echo ""
echo "[restauracion] las políticas de aislamiento se conservaron."

echo "[restauracion] arrancando los servicios..."

docker compose -f "${COMPOSE_FILE}" start api web >/dev/null 2>&1 || true

echo ""
echo "========================================================================"
echo "  RESTAURACIÓN TERMINADA."
echo ""
echo "  Respaldo restaurado: ${RUTA_RESPALDO}"
echo "  Salida guardada en:  ${CARPETA_EMERGENCIA}"
echo ""
echo "  Para volver atrás, restaure ese volcado de emergencia."
echo ""
echo "  ANTES DE ABRIR AL PÚBLICO, corra:"
echo "    docker compose -f ${COMPOSE_FILE} exec api \\"
echo "      php artisan guarderia:verificar-aislamiento"
echo "========================================================================"