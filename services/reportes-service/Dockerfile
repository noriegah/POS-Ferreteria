# Reportes consulta Ventas con cURL y genera sus propios PDF con Dompdf.
FROM php:8.3-cli-alpine
RUN apk add --no-cache curl-dev libpng-dev && docker-php-ext-install curl gd
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-plugins --optimize-autoloader
COPY public/ /app/public/
COPY src/ /app/src/
COPY templates/ /app/templates/
EXPOSE 8084
CMD ["php", "-S", "0.0.0.0:8084", "-t", "/app/public", "/app/public/index.php"]
