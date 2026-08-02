# PHP 8.3 mit GD (Bilder neu zeichnen) und PHPMailer (SMTP-Versand).
FROM php:8.3-fpm-alpine

RUN set -eux; \
    apk add --no-cache libjpeg-turbo libpng libwebp freetype; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS libjpeg-turbo-dev libpng-dev libwebp-dev freetype-dev; \
    docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype; \
    docker-php-ext-install -j"$(nproc)" gd; \
    apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

COPY anfrage.php ./
COPY php.ini /usr/local/etc/php/conf.d/zz-objektfrei.ini

# Ohne clear_env = no räumt PHP-FPM die Umgebung leer, bevor das Skript läuft –
# getenv('SMTP_PASS') wäre dann leer und der Mailversand schlüge fehl.
RUN printf '[www]\nclear_env = no\n' > /usr/local/etc/php-fpm.d/zz-objektfrei.conf

# Uploads und Fehlerprotokoll liegen außerhalb des Webverzeichnisses.
# Das Volume objektfrei_data übernimmt diese Rechte beim ersten Start.
RUN mkdir -p /var/objektfrei/uploads /var/objektfrei/log \
 && chown -R www-data:www-data /var/objektfrei \
 && chmod 750 /var/objektfrei/uploads /var/objektfrei/log

# php-fpm startet als root und lässt die Arbeitsprozesse als www-data laufen –
# so wie im offiziellen Image vorgesehen.
