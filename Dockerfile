FROM phpswoole/swoole

RUN \
    pecl update-channels        && \
    pecl install redis-5.1.1    && \
    docker-php-ext-enable redis && \
    docker-php-ext-install mysqli pdo_mysql && \
    echo "swoole.enable_library=off" >> /usr/local/etc/php/conf.d/docker-php-ext-swoole.ini
