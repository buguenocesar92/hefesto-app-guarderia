# =============================================================================
# Imagen de la API de Guardería
# =============================================================================
#
# Software original de Hefesto Studio. Esta imagen construye la API de Laravel
# desde el código fuente de `api/`, no desde una imagen de terceros con el
# proyecto ya adentro.
#
# Las versiones están fijadas a propósito. `composer.lock` y la versión de PHP
# decididas acá son las que se probaron en el momento de escribir esto; moverlas
# es una decisión, no un detalle de empaquetado.
#
# Ver docs/04.

# -----------------------------------------------------------------------------
# Construcción
# -----------------------------------------------------------------------------
FROM composer:2.8.12 AS construccion

WORKDIR /construccion

# Se copia primero SÓLO el manifiesto de dependencias, para que la instalación
# quede en la caché de Docker y no se rehaga cuando sólo cambia un archivo de
# código. Es la diferencia entre un despliegue de un minuto y uno de diez.
COPY api/composer.json api/composer.lock* ./

# Sin interacción: si falta una extensión o una llave, el error tiene que
# aparecer acá, no en el despliegue.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist

COPY api/ ./

RUN composer dump-autoload --optimize --no-dev

# -----------------------------------------------------------------------------
# La imagen final
# -----------------------------------------------------------------------------
# La imagen final NO tiene Composer. El código va precompilado y las
# dependencias van con `--no-dev`: menos superficie, y una imagen donde no hay
# una herramienta que pueda instalar paquetes.
FROM php:8.3-fpm-bookworm AS ejecucion

# Las extensiones que necesita esta API. `pdo_pgsql` es la que habla con
# PostgreSQL; `pcntl` la que permite que el supervisor gestione los procesos;
# `opcache` es lo que hace que el PHP no compile cada archivo en cada petición.
RUN apt-get update \
    && apt-get install --no-install-recommends --yes \
        libpq-dev \
        libicu-dev \
        libzip-dev \
        unzip \
        supervisor \
        nginx \
        ca-certificates \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pcntl \
        opcache \
        intl \
    && rm -rf /var/lib/apt/lists/*

# Redis: el estado de sesión y los limitadores.
RUN pecl install redis-6.1.0 \
    && docker-php-ext-enable redis

COPY --from=construccion /construccion /var/www/html

# Usuario sin privilegios. La API no corre como root: un proceso de PHP que
# corre como root convierte cualquier fallo de configuración en acceso total al
# contenedor.
RUN useradd --create-home --shell /usr/sbin/nologin hefesto \
    && chown -R hefesto:hefesto /var/www/html

# El directorio de sesión y de caché tiene que existir antes de que arranque el
# supervisor, y tiene que ser del usuario de la aplicación.
RUN mkdir -p /var/www/html/storage/framework/{cache/data,sessions,views} \
    && mkdir -p /var/www/html/bootstrap/cache \
    && chown -R hefesto:hefesto /var/www/html/storage \
    && chown -R hefesto:hefesto /var/www/html/bootstrap/cache

WORKDIR /var/www/html

COPY deploy/api/docker/php.ini /usr/local/etc/php/conf.d/guarderia.ini
COPY deploy/api/docker/nginx.conf /etc/nginx/nginx.conf
COPY deploy/api/docker/supervisord.conf /etc/supervisor/conf.d/guarderia.conf
COPY deploy/api/docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /run/nginx /run/php /var/log/supervisor \
    && chown -R hefesto:hefesto /run/php /var/log/nginx

# PHP corre como el usuario de la aplicación. Nginx también: un nginx como root
# que ejecuta workers sin privilegios es lo mínimo razonable para este servicio.
RUN chown -R hefesto:hefesto /run/nginx

USER hefesto

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD php -r '$s=@file_get_contents("http://127.0.0.1:8000/api/v1/salud"); exit($s===false?1:0);'

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf", "-n"]