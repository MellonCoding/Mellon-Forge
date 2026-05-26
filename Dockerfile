FROM php:8.2-fpm-alpine

# Installa nginx + curl
RUN apk add --no-cache nginx curl-dev \
    && docker-php-ext-install curl

# Configurazione nginx
RUN mkdir -p /run/nginx
COPY docker/nginx.conf /etc/nginx/nginx.conf

# Copia api/
WORKDIR /var/www/html
COPY api/ ./api/
RUN chown -R www-data:www-data /var/www/html

# Script di avvio
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 80
CMD ["/start.sh"]
