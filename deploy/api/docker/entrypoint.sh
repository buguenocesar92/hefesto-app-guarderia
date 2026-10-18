#!/bin/bash
# =============================================================================
# Arranque de la API de Guardería
# =============================================================================
#
# QUÉ HACE
# --------
#   1. Espera a que PostgreSQL acepte conexiones.
#   2. Pide el certificado de la autoridad interna de Authentik.
#   3. Corre las migraciones.
#   4. Avisa que el servicio va a empezar.
#
# Y nada más. En particular NO entra a un bucle: un contenedor que reinicia
# solo porque la base todavía no está lista termina en un ciclo de reinicios
# que esconde el problema real.
#
# LO QUE NO HACE
# --------------
# No crea la guardería. No crea usuarios. No crea planes con precios.
#
# Esas cosas se hacen a mano, con `guarderia:crear` y con el primer ingreso de
# cada persona. Un contenedor que crea datos de negocio al arrancar es un
# contenedor que puede volver a crearlos cuando nadie se lo pide.
#
# Ver docs/04.

set -euo pipefail

echo "[api] esperando a PostgreSQL..."

# El bucle tiene tope. Un contenedor que espera para siempre esconde una base
# mal configurada detrás de un proceso que "está arrancando".
INTENTOS=0
MAX_INTENTOS=60

while ! php -r '
$host = getenv("DB_HOST") ?: "postgres";
$porta = getenv("DB_PORT") ?: "5432";
$salida = @fsockopen($host, (int) $porta, $errno, $errstr, 2);
exit($salida === false ? 1 : 0);
'; do
    INTENTOS=$((INTENTOS + 1))

    if [ "$INTENTOS" -ge "$MAX_INTENTOS" ]; then
        echo "[api] PostgreSQL no respondió después de ${MAX_INTENTOS} intentos."
        echo "[api] Revise DB_HOST, DB_PORT y que el servicio de base esté sano."
        exit 1
    fi

    if [ $((INTENTOS % 10)) -eq 0 ]; then
        echo "[api] todavía esperando a PostgreSQL (intento ${INTENTOS} de ${MAX_INTENTOS})..."
    fi

    sleep 2
done

echo "[api] PostgreSQL responde."

# -----------------------------------------------------------------------------
# El certificado de Authentik
# -----------------------------------------------------------------------------
#
# La verificación de la firma del token de identificación necesita el certificado
# de la autoridad interna. Sin él, el ingreso falla con un error que dice "no se
# pudo verificar la firma" y no dice por qué.
#
# Se pide al punto de entrada de Authentik porque esa es la ruta por la que el
# navegador lo alcanza. Ver docs/04.
#
# Si AUTHENTIK_CA no está definida, la aplicación funciona igual: simplemente
# no tendrá esa raíz de confianza, y el ingreso fallará en el primer intento.
if [ -n "${AUTHENTIK_CA:-}" ]; then
    echo "[api] instalando el certificado de la autoridad interna de Authentik..."

    DESTINO=/usr/local/share/ca-certificates/authentik.crt

    if printf '%s\n' "$AUTHENTIK_CA" > "$DESTINO" 2>/dev/null; then
        update-ca-certificates >/dev/null 2>&1 || {
            echo "[api] aviso: no se pudo actualizar la lista de certificados."
        }
        echo "[api] certificado instalado."
    else
        echo "[api] aviso: no se pudo escribir ${DESTINO}."
        echo "[api] la imagen corre sin privilegios: defina AUTHENTIK_CA fuera, o"
        echo "[api] monte el certificado como solo lectura en /usr/local/share/ca-certificates/."
    fi
else
    echo "[api] AUTHENTIK_CA no está definida: se sigue sin esa raíz de confianza."
fi

# -----------------------------------------------------------------------------
# Las migraciones
# -----------------------------------------------------------------------------
#
# Corren en cada arranque y son idempotentes: se pueden volver a correr sin
# romper nada. Todas las sentencias son `CREATE OR REPLACE`, `DROP POLICY IF
# EXISTS` o `IF NOT EXISTS`.
#
# Y las corre el ROL DE LA APLICACIÓN (`guarderia_app`), que es el dueño del
# esquema. Ver docs/03 para por qué son dos roles y no uno.
echo "[api] corriendo migraciones..."

php artisan migrate --force --no-interaction

# -----------------------------------------------------------------------------
# La caché de configuración
# -----------------------------------------------------------------------------
#
# `config:cache` compila la configuración en un archivo. Se hace después de las
# migraciones, no antes, porque si el `.env` cambia y el contenedor se reinicia,
# la configuración compilada es la nueva.
echo "[api] compilando la configuración..."

php artisan config:clear >/dev/null 2>&1 || true
php artisan cache:clear >/dev/null 2>&1 || true

echo "[api] listo."

exec "$@"