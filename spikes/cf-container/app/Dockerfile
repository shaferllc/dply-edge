FROM dunglas/frankenphp:1-php8.4
RUN install-php-extensions pdo_pgsql
ENV SERVER_NAME=":8080"
COPY index.php /app/public/index.php
EXPOSE 8080
