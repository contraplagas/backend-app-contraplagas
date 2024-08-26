FROM dunglas/frankenphp

RUN install-php-extensions \
    pcntl \
    sqlite3 \
    pdo_sqlite \
    pdo_mysql \
    redis \
    xdebug \
    opcache \
    bcmath \
    zip \
    gd \
    imagick \
    intl \
    soap \
    sockets 



WORKDIR /app

COPY . /app

ENTRYPOINT ["php", "artisan", "octane:frankenphp"]
