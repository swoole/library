ARG IMAGE_TAG_PREFIX=""
ARG PHP_VERSION=8.3

FROM phpswoole/swoole:${IMAGE_TAG_PREFIX}php${PHP_VERSION}

RUN set -ex \
    && apt update \
    && apt install -y libaio-dev libc-ares-dev supervisor wget git --no-install-recommends

RUN wget -nv https://download.oracle.com/otn_software/linux/instantclient/instantclient-basiclite-linuxx64.zip \
    && unzip instantclient-basiclite-linuxx64.zip && rm -rf META-INF instantclient-basiclite-linuxx64.zip \
    && wget -nv https://download.oracle.com/otn_software/linux/instantclient/instantclient-sdk-linuxx64.zip \
    && unzip instantclient-sdk-linuxx64.zip       && rm -rf META-INF instantclient-sdk-linuxx64.zip \
    && mv instantclient_*_* ./instantclient \
    && rm ./instantclient/sdk/include/ldap.h \
    && echo DISABLE_INTERRUPT=on > ./instantclient/network/admin/sqlnet.ora \
    && mv ./instantclient /usr/local/ \
    && echo '/usr/local/instantclient' > /etc/ld.so.conf.d/oracle-instantclient.conf \
    && export ORACLE_HOME=instantclient,/usr/local/instantclient

RUN ldconfig

RUN apt install -y sqlite3 libsqlite3-dev libpq-dev --no-install-recommends \
    && docker-php-ext-install mysqli pdo_pgsql pdo_sqlite mongodb \
    && docker-php-ext-enable  mysqli pdo_pgsql pdo_sqlite

RUN pecl channel-update pecl \
    && if [ "$(php -r 'echo version_compare(PHP_VERSION, "8.4.0", "<") ? "old" : "new";')" = "old" ] ; then docker-php-ext-install pdo_oci; else pecl install pdo_oci-stable; fi \
    && docker-php-ext-enable pdo_oci

RUN pecl install mongodb-stable \
    && docker-php-ext-enable mongodb

RUN git clone https://github.com/swoole/swoole-src.git \
    && cd ./swoole-src \
    && phpize \
    && ./configure --enable-openssl \
                   --enable-sockets \
                   --enable-mysqlnd \
                   --enable-swoole-curl \
                   --enable-cares \
                   --enable-swoole-pgsql \
                   --with-swoole-oracle=instantclient,/usr/local/instantclient \
                   --enable-swoole-sqlite \
    && make -j$(cat /proc/cpuinfo | grep processor | wc -l) \
    && make install \
    && docker-php-ext-enable swoole \
    && echo "swoole.enable_library=off" >> /usr/local/etc/php/conf.d/docker-php-ext-swoole.ini \
    && php -m \
    && php --ri swoole \
    && { \
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
