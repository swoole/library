FROM phpswoole/swoole:4.5-php8.0

RUN \
    pecl update-channels        && \
    pecl install redis-stable   && \
    docker-php-ext-enable redis && \
    docker-php-ext-install mysqli pdo_mysql && \
    echo "swoole.enable_library=off" >> /usr/local/etc/php/conf.d/docker-php-ext-swoole.ini && \
    { \
        echo '[supervisord]'; \
        echo 'user = root'; \
        echo ''; \
        echo '[program:wordpress]'; \
        echo 'command = php /var/www/examples/fastcgi/proxy/wordpress.php'; \
        echo 'user = root'; \
        echo 'autostart = true'; \
        echo 'stdout_logfile=/proc/self/fd/1'; \
        echo 'stdout_logfile_maxbytes=0'; \
        echo 'stderr_logfile=/proc/self/fd/1'; \
        echo 'stderr_logfile_maxbytes=0'; \
    } > /etc/supervisor/service.d/wordpress.conf
