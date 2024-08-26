FROM dunglas/frankenphp:php8.2-alpine
LABEL authors="YEIMAR LEMUS"

# Instala Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copia los archivos de la aplicación
COPY . /app

# Establece el usuario root
USER root

# Define el directorio de trabajo
WORKDIR /app


# Copia los archivos de composer para instalar las dependencias
COPY composer.json composer.lock ./

# Instala las dependencias de Composer
RUN composer install --no-dev --optimize-autoloader --no-interaction



#Instalar node
RUN apk add --no-cache nodejs

#Instalar las dependencias de Node
RUN apk add --no-cache curl \
    && curl -sL https://unpkg.com/@pnpm/self-installer | node


# Instala las dependencias de Node
RUN pnpm i --frozen-lockfile


# Instala las extensiones de PHP necesarias
RUN install-php-extensions pdo pdo_mysql pdo_pgsql mysqli opcache redis pcntl gd bcmath posix zip \
 && apk add supervisor


# Copia el archivo de configuración de supervisord
COPY docker/supervisor/supervisord.conf /etc/supervisord.conf

# Ejecuta comandos de Artisan para optimizar la aplicación
RUN php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache \
    && php artisan l5-swagger:generate

EXPOSE 8000

CMD ["supervisord", "-c", "/etc/supervisord.conf"]
