# Parte de una imagen ligera que ya trae PHP.
FROM php:8.3-cli-alpine
# Instala el controlador que permite conectar PDO con MariaDB.
RUN docker-php-ext-install pdo_mysql
# Copia Composer para instalar la biblioteca JWT.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
# Usa /app como carpeta de trabajo dentro del contenedor.
WORKDIR /app
# Copia la lista de dependencias y las versiones fijadas en composer.lock.
COPY composer.json composer.lock ./
# Instala las dependencias fijadas, sin herramientas de desarrollo ni scripts adicionales.
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-plugins --optimize-autoloader
# Copia el punto de entrada HTTP del servicio.
COPY public/ /app/public/
# Copia las clases de conexión, JWT y lógica del servicio.
COPY src/ /app/src/
# Copia el comando de terminal para crear el administrador inicial.
COPY bin/ /app/bin/
# Documenta el puerto interno; este comando no lo publica en el equipo anfitrión.
EXPOSE 8083
# Inicia el servidor PHP usado para las pruebas locales del proyecto.
CMD ["php", "-S", "0.0.0.0:8083", "-t", "/app/public", "/app/public/index.php"]
