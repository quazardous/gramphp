FROM php:8.4-cli

# pdo_mysql for the MariaDB driver, pcntl for the concurrency tests (forked
# workers on separate connections), zip/unzip for Composer, graphviz so the
# test drawing a graph has `dot` check what it wrote.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev graphviz \
    && docker-php-ext-install pdo_mysql pcntl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_HOME=/tmp/composer
WORKDIR /app
