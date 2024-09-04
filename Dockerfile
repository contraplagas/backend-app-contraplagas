FROM dunglas/frankenphp:php8.3
LABEL authors="YEIMAR LEMUS"

ENV TZ=America/Bogota
USER root

# Instala Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copia los archivos de la aplicación
COPY . /app
WORKDIR /app


RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone
RUN install-php-extensions \
	pdo_mysql \
	gd \
	intl \
    imap \
    bcmath \
    redis \
    curl \
    exif \
    hash \
    iconv \
    json \
    mbstring \
    mysqli \
    mysqlnd \
    pcntl \
    pcre \
    xml \
    libxml \
    zlib \
	zip

# Instala supervisor
RUN apt-get update && apt-get install -y supervisor

# Copia los archivos de composer para instalar las dependencias
COPY composer.json composer.lock ./

# Instala las dependencias de Composer
RUN composer install --no-dev --optimize-autoloader --no-interaction


#Instalar node
RUN apt-get update && apt-get install -y nodejs

#Instalar las dependencias de Node
RUN apt-get update && apt-get install -y curl \
    && curl -sL https://unpkg.com/@pnpm/self-installer | node


# Instala las dependencias de Node
RUN pnpm i --frozen-lockfile


# Copia el archivo de configuración de supervisord
COPY docker/supervisor/supervisor.conf /etc/supervisord.conf

# Ejecuta comandos de Artisan para optimizar la aplicación
RUN php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache

EXPOSE 8000

CMD ["supervisord", "-c", "/etc/supervisord.conf"]
