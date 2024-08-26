FROM dunglas/frankenphp

RUN install-php-extensions \
    pcntl \
    sqlite3 \
    pdo_sqlite \
    pdo_mysql \
    redis 



WORKDIR /app

COPY . /app

ENTRYPOINT ["php", "artisan", "octane:frankenphp"]
