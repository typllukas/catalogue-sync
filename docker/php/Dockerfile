FROM php:8.5-fpm

ARG UID=1000
ARG GID=1000
RUN groupadd --gid $GID app \
    && useradd --uid $UID --gid $GID --create-home app \
    && sed -i 's/^user = www-data/user = app/; s/^group = www-data/group = app/' \
        /usr/local/etc/php-fpm.d/www.conf

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" intl pdo_mysql zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.9 /usr/bin/composer /usr/bin/composer

WORKDIR /app
