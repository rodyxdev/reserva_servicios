# =====================================================================
#  PHP 8.3 + Apache para el sistema de reservas
# =====================================================================
FROM php:8.3-apache

# --- Extensiones de PHP -----------------------------------------------
# pdo_mysql es la unica imprescindible; el resto son utilidades de las
# que dependen PHPMailer (mbstring) y el manejo de errores en desarrollo.
RUN docker-php-ext-install pdo pdo_mysql opcache \
    && docker-php-ext-enable opcache

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# --- Composer ----------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- Apache ------------------------------------------------------------
# El DocumentRoot apunta a public/: todo lo demas (src/, config/, .env,
# vendor/) queda FUERA del alcance del servidor web. Esto no es un detalle
# cosmetico: es lo que impide que alguien pida /.env por HTTP.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && a2enmod rewrite headers

# Permite que el .htaccess de public/ tenga efecto.
RUN printf '<Directory /var/www/html/public>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n' > /etc/apache2/conf-available/z-reservas.conf \
    && a2enconf z-reservas

# --- Configuracion de PHP ----------------------------------------------
# date.timezone en UTC a proposito: toda la aplicacion calcula en UTC y
# convierte a la zona del negocio solo al presentar.
RUN printf 'date.timezone = UTC\n\
display_errors = Off\n\
log_errors = On\n\
error_log = /var/www/html/storage/logs/php-error.log\n\
upload_max_filesize = 8M\n\
post_max_size = 8M\n\
session.cookie_httponly = 1\n\
session.cookie_samesite = "Lax"\n\
session.use_strict_mode = 1\n' > /usr/local/etc/php/conf.d/z-reservas.ini

WORKDIR /var/www/html
