# =============================================================================
# Imagen de la interfaz de Guardería
# =============================================================================
#
# Software original de Hefesto Studio. Esta imagen construye la interfaz de Nuxt
# desde el código fuente de `web/`, no desde una imagen con el proyecto ya
# adentro.
#
# DOS ETAPAS
# ----------
# La construcción instala las dependencias y compila. La imagen final copia SÓLO
# la etapa de construcción: el código de Node, los módulos de compilación y el
# caché de construcción no llegan a producción.
#
# Ver docs/04.

# -----------------------------------------------------------------------------
# Construcción
# -----------------------------------------------------------------------------
FROM node:22.22-bookworm-slim AS construccion

WORKDIR /construccion

# El manifiesto primero, para que la instalación quede en la caché de Docker.
# `npm ci` y no `npm install`: `ci` instala EXACTAMENTE lo que dice el
# lockfile, y falla si el manifiesto y el lockfile no coinciden. En una
# aplicación con datos de menores, una dependencia que cambia de versión sin que
# nadie lo decida no es un detalle de empaquetado.
COPY web/package.json web/package-lock.json* ./

RUN npm ci --no-audit --no-fund

COPY web/ ./

# `NUXT_API_INTERNA` se reemplaza en el momento de la construcción porque la
# interfaz la compila DENTRO del paquete y no la lee en cada arranque. Ver
# `nuxt.config.ts`.
ARG NUXT_API_INTERNA=http://api:8000
ARG NUXT_PUBLIC_NOMBRE=Guardería
ENV NUXT_API_INTERNA=${NUXT_API_INTERNA}
ENV NUXT_PUBLIC_NOMBRE=${NUXT_PUBLIC_NOMBRE}

RUN npm run build

# -----------------------------------------------------------------------------
# La imagen final
# -----------------------------------------------------------------------------
FROM node:22.22-bookworm-slim AS ejecucion

WORKDIR /aplicacion

ENV NODE_ENV=production
ENV NITRO_PORT=3000
ENV NITRO_HOST=0.0.0.0

# Sin Node en el recorrido de la aplicación: el servidor de Nitro ya viene
# compilado y es lo único que corre.
COPY --from=construccion /construccion/.output ./.output

# Usuario sin privilegios, por la misma razón que en la API: un proceso de Node
# como root convierte cualquier fallo de configuración en acceso al contenedor.
RUN useradd --create-home --shell /usr/sbin/nologin hefesto \
    && chown -R hefesto:hefesto /aplicacion

USER hefesto

EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=5s --start-period=25s --retries=3 \
    CMD node -e "fetch('http://127.0.0.1:3000/').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

CMD ["node", "/aplicacion/.output/server/index.mjs"]