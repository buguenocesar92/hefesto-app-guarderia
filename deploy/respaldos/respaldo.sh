#!/usr/bin/env bash
# =============================================================================
# Respaldo de la base de datos de Guardería
# =============================================================================
#
# -----------------------------------------------------------------------------
# LAS SEIS REGLAS, Y DÓNDE ESTÁ CADA UNA
# -----------------------------------------------------------------------------
#
# 1. MANIFIESTO CON SHA-256 DE CADA ARCHIVO
#    Rule 1 · más abajo, en la sección "El manifiesto".
#
# 2. `COMPLETO` SOLO AL FINAL, `EN_CURSO` MIENTRAS CORRE, Y ANTES DE LA RETENCIÓN
#    Rule 2 · la marca se escribe al principio y al final, en dos lugares
#    distintos del archivo, para que sea imposible marcarla como completa antes
#    de que esté.
#
# 3. VALIDAR RUTAS Y ESPACIO LIBRE ANTES DE ESCRIBIR; EL DESTINO DEBE EXISTIR
#    Rule 3 · "Las validaciones", antes de crear nada.
#
# 4. DESTINO OCUPADO SE RECHAZA, SALVO `--force`
#    Rule 4 · con `--force` se elige un nombre nuevo, NO se sobreescribe.
#
# 5. NUNCA `DROP`, NUNCA SOBRESCRITURA AUTOMÁTICA; LA RETENCIÓN AVISA QUÉ BORRA
#    Rule 5 · no hay un solo `DROP` en este archivo, y la retención lista lo que
#    va a borrar y pide confirmación.
#
# 6. LA RETENCIÓN VA DESPUÉS DE VERIFICAR EL RESPALDO NUEVO
#    Rule 6 · la retención es lo último, y sólo corre si el respaldo nuevo pasó
#    la verificación del SHA-256.
#
# -----------------------------------------------------------------------------
# LO QUE ESTE RESPALDO CONTIENE Y LO QUE NO
# -----------------------------------------------------------------------------
#
# SÍ: el volcado completo de la base, con el esquema, las tablas y los datos.
# Incluye los registros de retiro, las fichas médicas y la bitácora de accesos.
# Es lo que hay que tener.
#
# NO: los volúmenes, ni los certificados, ni la configuración. Si se pierde el
# volumen de PostgreSQL, el respaldo no basta: hay que reconstruir la
# instalación con `docs/04`.
#
# -----------------------------------------------------------------------------
# USO
# -----------------------------------------------------------------------------
#   ./respaldo.sh --directorio /var/respaldo/guarderia
#   ./respaldo.sh --directorio /var/respaldo/guarderia --dias 30
#   ./respaldo.sh --directorio /var/respaldo/guarderia --sin-retencion
#
# PENDIENTE: la ruta por omisión es `/var/respaldo/guarderia` y NO se verificó
# que exista en esta instalación. El script lo comprueba y falla si no está.
# Ver docs/08.

set -Eeuo pipefail

# =============================================================================
# Las variables
# =============================================================================

DIRECTORIO_RESPALDO="/var/respaldo/guarderia"
DIAS_RETENCION="30"
APLICAR_RETENCION="1"
FUERZA="0"

MARCA_EN_CURSO="EN_CURSO"
MARCA_COMPLETO="COMPLETO"
NOMBRE_MANIFIESTO="manifiesto.sha256"

# =============================================================================
# Los argumentos
# =============================================================================

while [ "$#" -gt 0 ]; do
    case "$1" in
        --directorio)
            DIRECTORIO_RESPALDO="${2:?--directorio necesita un valor}"
            shift 2
            ;;
        --dias)
            DIAS_RETENCION="${2:?--dias necesita un valor}"
            shift 2
            ;;
        --sin-retencion)
            APLICAR_RETENCION="0"
            shift
            ;;
        --force)
            FUERZA="1"
            shift
            ;;
        --ayuda|-h)
            echo "Uso: $0 --directorio RUTA [--dias N] [--sin-retencion] [--force]"
            echo ""
            echo "  --directorio RUTA   Dónde escribir el respaldo. Debe existir."
            echo "  --dias N            Cuántos días se conservan los respaldos."
            echo "  --sin-retencion     No borra respaldos viejos. Úselo la primera vez."
            echo "  --force             Permite escribir aunque ya exista un respaldo de hoy."
            exit 0
            ;;
        *)
            echo "Argumento desconocido: $1" >&2
            exit 2
            ;;
    esac
done

# =============================================================================
# Rule 3 · Las validaciones, ANTES de crear nada
# =============================================================================
#
# La primera regla operativa de este archivo: nada se escribe hasta que todo lo
# que se puede comprobar está comprobado.
#
# Comprobar después de crear el directorio es comprobar tarde: si el disco está
# lleno a mitad de la escritura, ya hay un directorio a medias y un archivo a
# medias, y alguien tiene que limpiar a mano.

echo "[respaldo] comprobando que se pueda escribir..."

# -----------------------------------------------------------------------------
# El destino tiene que existir
# -----------------------------------------------------------------------------
#
# El script NO crea el directorio de destino. Si lo creara, un error de tipeo en
# la ruta produciría un respaldo nuevo y vacío en una carpeta que nadie está
# mirando, y el respaldo real estaría en la carpeta anterior, sin que nadie lo
# supiera.
if [ ! -d "${DIRECTORIO_RESPALDO}" ]; then
    echo "[respaldo] ERROR: el destino no existe: ${DIRECTORIO_RESPALDO}" >&2
    echo "[respaldo] Créelo usted y vuelva a intentarlo. Este script no lo crea a propósito." >&2
    exit 1
fi

if [ ! -w "${DIRECTORIO_RESPALDO}" ]; then
    echo "[respaldo] ERROR: no hay permiso de escritura en ${DIRECTORIO_RESPALDO}" >&2
    exit 1
fi

# -----------------------------------------------------------------------------
# La ruta no puede tener espacios raros ni caracteres que rompan el guion
# -----------------------------------------------------------------------------
#
# Las rutas con espacios se manejan con comillas en todo el archivo. Lo que NO se
# acepta es una ruta relativa: los respaldos se ejecutan a veces desde un cron y a
# veces a mano, y una ruta relativa significa un destino distinto según el
# directorio desde donde se llamó.
case "${DIRECTORIO_RESPALDO}" in
    /*)
        ;;
    *)
        echo "[respaldo] ERROR: la ruta debe ser absoluta. Recibida: ${DIRECTORIO_RESPALDO}" >&2
        exit 1
        ;;
esac

# -----------------------------------------------------------------------------
# La retención tiene que ser un número
# -----------------------------------------------------------------------------
if ! [[ "${DIAS_RETENCION}" =~ ^[0-9]+$ ]]; then
    echo "[respaldo] ERROR: --dias debe ser un número entero. Recibido: ${DIAS_RETENCION}" >&2
    exit 1
fi

# -----------------------------------------------------------------------------
# Las herramientas necesarias
# -----------------------------------------------------------------------------
for HERRAMIENTA in pg_dump sha256sum gzip du df date; do
    if ! command -v "${HERRAMIENTA}" >/dev/null 2>&1; then
        echo "[respaldo] ERROR: falta ${HERRAMIENTA}. Instale el cliente de PostgreSQL." >&2
        exit 1
    fi
done

# =============================================================================
# Rule 4 · El destino ocupado se rechaza
# =============================================================================
#
# Un respaldo por día. Si ya existe el de hoy, este script NO lo sobreescribe: sale
# con un error.
#
# POR QUÉ NO SE SOBRESCRIBE, NI CON `--force`
# -------------------------------------------
# Un respaldo que se sobreescribe no es un respaldo nuevo: es el mismo respaldo con
# el archivo abierto mientras se escribe. Si el equipo se apaga a mitad, queda un
# respaldo CORRUPTO donde antes había uno bueno. Y como se llama todos los días,
# nadie se da cuenta hasta que necesita restaurarlo.
#
# `--force` no sobreescribe: escribe en un directorio con la hora. Los dos
# respaldos quedan.
FECHA="$(date +%Y-%m-%d)"
HORA="$(date +%H%M%S)"
MARCA_FECHA="${DIRECTORIO_RESPALDO}/${FECHA}"

if [ -e "${MARCA_FECHA}" ]; then
    if [ "${FUERZA}" = "1" ]; then
        MARCA_FECHA="${DIRECTORIO_RESPALDO}/${FECHA}-${HORA}"
        echo "[respaldo] ya existe un respaldo de hoy."
        echo "[respaldo] Con --force se escribe aparte en: ${MARCA_FECHA}"
        echo "[respaldo] El respaldo anterior NO se toca."
    else
        echo "[respaldo] ERROR: ya existe un respaldo para el ${FECHA}: ${MARCA_FECHA}" >&2
        echo "[respaldo] No se sobreescribe nunca un respaldo: uno a medias es peor" >&2
        echo "[respaldo] que uno viejo, porque no se nota hasta que hay que restaurarlo." >&2
        echo "[respaldo] Use --force para escribir aparte, con la hora en el nombre." >&2
        exit 1
    fi
fi

# -----------------------------------------------------------------------------
# El espacio libre
# -----------------------------------------------------------------------------
#
# La regla 3 pide comprobar el espacio ANTES de escribir. Se estima el tamaño de
# la base comprimida al cincuenta por ciento del tamaño de la base sin comprimir:
# es un margen que falla poco, porque un margen que falla a menudo se apaga.
#
# Con un margen inadequate el respaldo falla por disco lleno a mitad, y queda un
# directorio con `EN_CURSO` y un archivo a medias. La retención no lo borra —no
# borra nada que no tenga `COMPLETO`—, así que se acumula hasta que alguien lo ve.
TAMANO_BASE_BYTES="$(du -sb /var/lib/postgresql/data 2>/dev/null | cut -f1 || echo 0)"
ESPACIO_NECESARIO_BYTES=$((TAMANO_BASE_BYTES / 2))

if [ "${ESPACIO_NECESARIO_BYTES}" -gt 0 ]; then
    ESPACIO_LIBRE_BYTES="$(df --output=avail -B1 "${DIRECTORIO_RESPALDO}" | tail -1 | tr -d ' ')"
    ESPACIO_NECESARIO_HUMANO="$(numfmt --to=iec-i --suffix=B "${ESPACIO_NECESARIO_BYTES}" 2>/dev/null || echo "${ESPACIO_NECESARIO_BYTES} bytes")"
    ESPACIO_LIBRE_HUMANO="$(numfmt --to=iec-i --suffix=B "${ESPACIO_LIBRE_BYTES}" 2>/dev/null || echo "${ESPACIO_LIBRE_BYTES} bytes")"

    echo "[respaldo] espacio libre: ${ESPACIO_LIBRE_HUMANO}. Estimado necesario: ${ESPACIO_NECESARIO_HUMANO}."

    if [ "${ESPACIO_LIBRE_BYTES}" -lt "${ESPACIO_NECESARIO_BYTES}" ]; then
        echo "[respaldo] ERROR: no hay espacio suficiente en ${DIRECTORIO_RESPALDO}." >&2
        echo "[respaldo] Libre: ${ESPACIO_LIBRE_HUMANO}. Necesario: ${ESPACIO_NECESARIO_HUMANO}." >&2
        exit 1
    fi
fi

echo "[respaldo] las comprobaciones pasaron."

# =============================================================================
# Rule 2 · `EN_CURSO`, antes de empezar
# =============================================================================
#
# Un respaldo sin marca `COMPLETO` no es un respaldo. Es un intento.
#
# La marca se escribe ANTES de que empiece el volcado, para que un respaldo
# interrumpido se note al mirar la carpeta. Y `COMPLETO` se escribe DESPUÉS de
# verificar el SHA-256, no después del volcado: el orden importa.

CARPETA="${MARCA_FECHA}"
mkdir -p "${CARPETA}"

echo "[respaldo] escribiendo la marca ${MARCA_EN_CURSO}..."

cat > "${CARPETA}/${MARCA_EN_CURSO}" <<EOF
inicio=$(date --iso-8601=seconds)
host=$(hostname)
pid=$$
estado=en curso
aviso=Este respaldo NO esta terminado. Si lee esto, el respaldo fallo o se interrumpio.
EOF

ARCHIVO_VOLCADO="${CARPETA}/datos.dump.gz"

# =============================================================================
# El volcado
# =============================================================================
#
# `pg_dump` en formato personalizado y comprimido. El formato "custom" ya trae
# compresión propia y permite restaurar tabla por tabla, que es lo que sirve
# cuando hay que rescatar UN niño y no toda la base.
#
# Se usan las variables de entorno de `pg_dump` y NO una cadena con la contraseña
# adentro: una contraseña en la línea de comandos aparece en la lista de procesos
# de la máquina, y en una máquina de una guardería hay más personas mirando que
# máquinas de producción.
echo "[respaldo] volcando la base..."

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
    --file="${ARCHIVO_VOLCADO}"

if [ ! -s "${ARCHIVO_VOLCADO}" ]; then
    echo "[respaldo] ERROR: el volcado quedó vacío." >&2
    exit 1
fi

echo "[respaldo] volcado terminado."

# =============================================================================
# Rule 1 · El manifiesto con el SHA-256 de cada archivo
# =============================================================================
#
# `sha256sum` sobre cada archivo del respaldo, INCLUDING el manifiesto mismo no
# puede ir: un archivo no se puede contener a sí mismo. Se anota cada archivo de
# datos, y la verificación posterior comprueba exactamente esos.
#
# Este manifiesto es lo que permite saber, dentro de seis meses, si un respaldo
# de disco está íntegro o si el almacenamiento se degradó en silencio.
echo "[respaldo] escribiendo el manifiesto..."

# El manifiesto NO se incluye a sí mismo: un archivo no puede contener su propio
# resumen. Se anota el volcado, que es lo que hay que verificar.
echo "[respaldo] escribiendo el manifiesto..."

ARCHIVO_MANIFIESTO="${CARPETA}/${NOMBRE_MANIFIESTO}"

(
    cd "${CARPETA}"
    sha256sum datos.dump.gz > "${NOMBRE_MANIFIESTO}"
)

echo "[respaldo] manifiesto escrito."

# =============================================================================
# La verificación del respaldo NUEVO, antes de la retención
# =============================================================================
#
# Se comprueba que el SHA-256 del archivo que se acaba de escribir coincide con el
# del manifiesto. Si no coincide, el respaldo está dañado, y un respaldo dañado
# con `COMPLETO` es peor que no tener respaldo: porque nadie va a mirar si no
# hace falta.
echo "[respaldo] verificando el respaldo nuevo..."

if (cd "${CARPETA}" && sha256sum --check --status "${NOMBRE_MANIFIESTO}"); then
    echo "[respaldo] verificación correcta."
else
    echo "[respaldo] ERROR: el SHA-256 no coincide. El respaldo está dañado." >&2
    echo "[respaldo] NO se marca como completo y NO se aplica la retención." >&2
    exit 1
fi

# =============================================================================
# Rule 2 · `COMPLETO`, AL FINAL
# =============================================================================
#
# Sólo después de verificar. Este es el orden que hace que la marca signifique
# algo.
echo "[respaldo] escribiendo la marca ${MARCA_COMPLETO}..."

cat > "${CARPETA}/${MARCA_COMPLETO}" <<EOF
fin=$(date --iso-8601=seconds)
host=$(hostname)
estado=completo
volcado=datos.dump.gz
manifiesto=${NOMBRE_MANIFIESTO}
verificacion=sha-256 correcto
EOF

rm -f "${CARPETA}/${MARCA_EN_CURSO}"

TAMANO="$(du -sh "${CARPETA}" | cut -f1)"

echo ""
echo "[respaldo] RESPALDO COMPLETO: ${CARPETA} (${TAMANO})"
echo "[respaldo] Verificado con SHA-256."

# =============================================================================
# Rule 6 · LA RETENCIÓN, DESPUÉS DE VERIFICAR
# =============================================================================
#
# Sólo llega aquí un respaldo que pasó la verificación. Si el respaldo nuevo
# falló, el script ya salió con error y esta parte no corrió.
#
# NUNCA se borra un directorio sin `COMPLETO`: uno a medias puede ser el respaldo
# de hoy, y borrarlo porque es viejo sería borrar el único respaldo bueno.
if [ "${APLICAR_RETENCION}" = "0" ]; then
    echo "[respaldo] retención omitida por --sin-retencion."
    exit 0
fi

echo ""
echo "[respaldo] revisando respaldos con más de ${DIAS_RETENCION} días..."

# Se listan PRIMERO. La regla 5 dice que la retención avisa qué borra antes de
# borrar, y eso es literal: este listado es el aviso, y el script pide
# confirmación después.
A_BORRAR=()

while IFS= read -r -d '' CANDIDATO; do
    [ -f "${CANDIDATO}/${MARCA_COMPLETO}" ] || continue

    CANDIDATO_NOMBRE="$(basename "${CANDIDATO}")"
    CANDIDATO_FECHA="${CANDIDATO_NOMBRE%%-*}"

    # El nombre es AAAA-MM-DD. Si no se puede leer como fecha, no se toca: un
    # directorio que alguien puso a mano no se borra porque se le parezca a uno
    # viejo.
    if ! [[ "${CANDIDATO_FECHA}" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
        continue
    fi

    DIAS_QUE_TIENE=$(( ( $(date +%s) - $(date -d "${CANDIDATO_FECHA}" +%s) ) / 86400 ))

    if [ "${DIAS_QUE_TIENE}" -gt "${DIAS_RETENCION}" ]; then
        A_BORRAR+=("${CANDIDATO}")
    fi
done < <(find "${DIRECTORIO_RESPALDO}" -mindepth 1 -maxdepth 1 -type d -print0)

if [ "${#A_BORRAR[@]}" -eq 0 ]; then
    echo "[respaldo] no hay nada que borrar."
    exit 0
fi

echo "[respaldo] la retención BORRARÍA estos respaldos:"
echo ""

for ELEMENTO in "${A_BORRAR[@]}"; do
    TAMANO_ELEMENTO="$(du -sh "${ELEMENTO}" | cut -f1)"
    echo "  - ${ELEMENTO}  (${TAMANO_ELEMENTO})"
done

echo ""

if [ ! -t 0 ]; then
    # Sin terminal: nadie puede confirmar. Se avisa y no se borra.
    #
    # Esto es lo que corre un cron. Borrar sin que nadie lo haya pedido, en un
    # directorio de respaldos de una guardería, no es una operación que se pueda
    # hacer a ciegas.
    echo "[respaldo] no hay terminal para pedir confirmación. NO se borra nada."
    echo "[respaldo] Borre a mano lo de arriba cuando lo haya revisado."
    exit 0
fi

read -r -p "¿Confirma que borre esos respaldos? Escriba BORRAR para confirmar: " CONFIRMACION

if [ "${CONFIRMACION}" != "BORRAR" ]; then
    echo "[respaldo] no se borró nada."
    exit 0
fi

for ELEMENTO in "${A_BORRAR[@]}"; do
    echo "[respaldo] borrando ${ELEMENTO}..."
    rm -rf "${ELEMENTO}"
done

echo "[respaldo] retención terminada."
echo ""
echo "Regla 5: en este archivo no hay ningún DROP y no hay sobreescritura automática."
echo "           Un respaldo nunca se sobreescribe: se escribe a otro lado."