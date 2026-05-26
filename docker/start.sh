#!/bin/sh
# Avvia PHP-FPM in background, poi Nginx in foreground
php-fpm -D
nginx -g "daemon off;"
