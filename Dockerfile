ARG PHP_VERSION
FROM php:${PHP_VERSION}-apache
# Host user id: Apache (www-data) then writes var/ in the bind mount as the host user.
ARG HOST_UID=1001

# A typical shared host: Apache with .htaccess, GD and Imagick, no libvips, no Redis.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip zip libicu-dev libzip-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libmagickwand-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" intl pdo_mysql zip opcache gd exif \
    && pecl install imagick && docker-php-ext-enable imagick \
    && a2enmod rewrite headers \
    && usermod -u "$HOST_UID" www-data && groupmod -g "$HOST_UID" www-data \
    && rm -rf /var/lib/apt/lists/*

# Shared-host limits: small uploads (chunked upload works around them), modest memory.
RUN printf 'upload_max_filesize=8M\npost_max_size=10M\nmemory_limit=256M\nmax_execution_time=60\n' > "$PHP_INI_DIR/conf.d/shared-host.ini"

# AllowOverride All so the project's .htaccess files are honoured, as on a shared host.
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /app>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-enabled/app.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
