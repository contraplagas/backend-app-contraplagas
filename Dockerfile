FROM dunglas/frankenphp:php8.3-alpine

RUN install-php-extensions \
    pcntl \
    sqlite3 \
    pdo_sqlite \
    pdo_mysql \
    redis \
    && apk add supervisor

#Instalar supervisor
RUN apt-get update && apt-get install -y supervisor





COPY . /app

WORKDIR /app

# Copia el archivo de configuración de supervisord
COPY docker/supervisor/supervisor.conf /etc/supervisord.conf

EXPOSE 8000

CMD ["supervisord", "-c", "/etc/supervisord.conf"]
