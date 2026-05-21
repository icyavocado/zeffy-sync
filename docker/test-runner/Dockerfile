FROM php:8.1-cli

RUN apt-get update && apt-get install -y git unzip zip

# Use composer image's composer binary
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /work

# Copy repo and install dev deps
COPY . /work
RUN composer install --prefer-dist --no-interaction --no-progress

CMD ["./vendor/bin/phpunit", "--configuration", "phpunit.xml.dist"]
