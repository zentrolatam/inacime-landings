FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf

COPY docker/php.ini /usr/local/etc/php/conf.d/inacime.ini
COPY docker/apache.conf /etc/apache2/conf-available/inacime.conf
RUN a2enconf inacime

COPY docker/entrypoint.sh /usr/local/bin/inacime-entrypoint
RUN chmod +x /usr/local/bin/inacime-entrypoint

COPY public /var/www/html/public
COPY app /var/www/html/app

ENV STORAGE_ROOT=/var/www/storage

WORKDIR /var/www/html
EXPOSE 80

ENTRYPOINT ["inacime-entrypoint"]
CMD ["apache2-foreground"]
