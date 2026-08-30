FROM php:8.3-cli
RUN docker-php-ext-install pdo pdo_sqlite
WORKDIR /app
COPY . .
RUN mkdir -p /app/data && chmod -R 777 /app/data
CMD php -S 0.0.0.0:${PORT:-8080} -t /app
