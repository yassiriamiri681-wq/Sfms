FROM php:8.3-apache-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libpng-dev libjpeg62-turbo-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring gd \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
ENV SCHOOLLEDGER_IMAGE_STORAGE=database
COPY app ./app
COPY views ./views
COPY public ./public
COPY database ./database
COPY bin ./bin
COPY deploy/render/config.php ./config/local.php
COPY deploy/render/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/render/start.sh /usr/local/bin/schoolledger-start
RUN mkdir -p storage/sessions uploads \
    && chown -R www-data:www-data storage uploads \
    && chmod 700 storage storage/sessions uploads \
    && chmod +x /usr/local/bin/schoolledger-start
EXPOSE 80
CMD ["schoolledger-start"]
