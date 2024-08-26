FROM dunglas/frankenphp

RUN install-php-extensions \
    pcntl \
    sqlite3 \
    pdo_sqlite \
    pdo_mysql \
    redis \
    && apk add --no-cache supervisor





COPY . /app

WORKDIR /app

# Copia el archivo de configuración de supervisord
COPY supervisord.conf /etc/supervisord.conf

EXPOSE 8000

CMD ["supervisord", "-c", "/etc/supervisord.conf"]
