# syntax=docker/dockerfile:1

# The development and test image of the library: the phpswoole/swoole image of the Swoole series under test, with
# the extensions the tests and examples need, and Swoole rebuilt from the head of SWOOLE_BRANCH of swoole-src.
#
# The defaults follow the branch of the library: the development branch here, i.e. the nightly phpswoole/swoole
# images (no tag prefix) and branch master of swoole-src. On branch 6.2.x they are the images of Swoole 6.2 (tag
# prefix "6.2-") and branch 6.2.
#
# Everything is compiled in build stages, which BuildKit runs side by side. The final image only receives the
# stripped extensions, the Oracle Instant Client and the shared libraries these need. BUILD_JOBS caps the number of
# compilers of each stage (default: one per CPU); three stages compile at once.
ARG IMAGE_TAG_PREFIX=""
ARG PHP_VERSION=8.4

FROM phpswoole/swoole:${IMAGE_TAG_PREFIX}php${PHP_VERSION} AS base
SHELL ["/bin/bash", "-euo", "pipefail", "-c"]

# Oracle Instant Client, pinned to one release and verified. The download URLs without a version always serve the
# latest release, which changes the image without any change here. To upgrade, change the three arguments together.
FROM base AS oracle
ARG ORACLE_IC_VERSION=23.26.2.0.0
ARG ORACLE_IC_BASICLITE_SHA256=c5e97765e633ad02597b8274c35efe5c10bd249a3285e34ee58fa7b318243225
ARG ORACLE_IC_SDK_SHA256=b49ce017cb1c8035b0506289f95cd6ee69173632e2795e4520fd2a38b51fbedb
RUN <<'EOF'
    cd /tmp
    base_url="https://download.oracle.com/otn_software/linux/instantclient/$(tr -d . <<< "${ORACLE_IC_VERSION}" | cut -c1-7)"
    for package in basiclite sdk ; do
        curl -fsSL --retry 5 --retry-all-errors --connect-timeout 30 -o "${package}.zip" \
            "${base_url}/instantclient-${package}-linux.x64-${ORACLE_IC_VERSION}.zip"
    done
    sha256sum -c - <<CHECKSUMS
${ORACLE_IC_BASICLITE_SHA256}  basiclite.zip
${ORACLE_IC_SDK_SHA256}  sdk.zip
CHECKSUMS
    # Both archives carry the same META-INF/ files, which unzip would ask about.
    unzip -q basiclite.zip -x 'META-INF/*'
    unzip -q sdk.zip -x 'META-INF/*'
    mv instantclient_*_* /usr/local/instantclient
    rm basiclite.zip sdk.zip
    cd /usr/local/instantclient
    # Conflicts with the ldap.h of the system when PHP extensions are compiled against the SDK.
    rm sdk/include/ldap.h
    # Neither PDO_OCI nor Swoole uses the Java drivers, the C++ API (OCCI) or the tools. Nothing else may go: the
    # client loads libraries such as legacy.so by name when it logs in, and fails with ORA-28041 without them.
    rm -f -- *.jar libocci.so* libocijdbc*.so libtfojdbc1.so* adrci genezi uidrvci
    echo DISABLE_INTERRUPT=on > network/admin/sqlnet.ora
EOF

# Headers and libraries needed to compile the extensions; used by the build stages only.
FROM base AS build-deps
COPY --from=oracle /usr/local/instantclient /usr/local/instantclient
RUN <<'EOF'
    apt-get update
    apt-get install -y --no-install-recommends \
        libaio1t64 \
        libavif-dev \
        libc-ares-dev \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg-dev \
        libpng-dev \
        libpq-dev \
        libsqlite3-dev \
        libssl-dev \
        libwebp-dev \
        libxpm-dev \
        zlib1g-dev
    rm -rf /var/lib/apt/lists/*
    # The Instant Client needs libaio.so.1, which Debian 13 ships as libaio.so.1t64 only.
    lib_dir=/usr/lib/x86_64-linux-gnu
    if [[ ! -e "${lib_dir}/libaio.so.1" ]] ; then ln -s libaio.so.1t64 "${lib_dir}/libaio.so.1" ; fi
    echo /usr/local/instantclient > /etc/ld.so.conf.d/oracle-instantclient.conf
    ldconfig
EOF
# Prints the Debian packages that provide the shared libraries the given files load, so that the final stage
# installs exactly those, whatever their names on the Debian release of the base image.
COPY --chmod=755 <<'EOF' /usr/local/bin/runtime-packages
#!/usr/bin/env bash
set -euo pipefail
if ldd "$@" | grep -F 'not found' ; then exit 1 ; fi
ldd "$@" | awk '/=> \// { print $3 }' | sort -u | while read -r lib ; do
    lib="$(readlink -f "${lib}")"
    case "${lib}" in /usr/local/*) continue ;; esac
    dpkg-query --search "${lib}" | cut -d: -f1
done | sort -u
EOF

FROM build-deps AS extensions
ARG BUILD_JOBS=""
ARG PDO_OCI_VERSION=1.2.0
RUN <<'EOF'
    jobs="${BUILD_JOBS:-$(nproc)}"
    docker-php-ext-install -j"${jobs}" mysqli pdo_pgsql
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-xpm --with-avif
    docker-php-ext-install -j"${jobs}" gd
    export ORACLE_HOME=instantclient,/usr/local/instantclient
    # PDO_OCI moved from the PHP sources to PECL in PHP 8.4.
    if php -r 'exit(PHP_VERSION_ID < 80400 ? 0 : 1);' ; then
        docker-php-ext-install -j"${jobs}" pdo_oci
    else
        MAKEFLAGS="-j${jobs}" pecl install "pdo_oci-${PDO_OCI_VERSION}"
    fi
    mkdir /out
    ext_dir="$(php-config --extension-dir)"
    for ext in mysqli pdo_pgsql gd pdo_oci ; do
        strip --strip-all -o "/out/${ext}.so" "${ext_dir}/${ext}.so"
    done
    runtime-packages /out/*.so /usr/local/instantclient/*.so* > /out/runtime-packages
EOF

# The MongoDB driver is the most expensive build, so it has a stage of its own: a change to the other extensions
# does not compile it again.
FROM build-deps AS mongodb
ARG BUILD_JOBS=""
ARG MONGODB_VERSION=2.5.3
RUN <<'EOF'
    # pecl runs make without -j: MAKEFLAGS is what compiles the ~900 source files of the driver in parallel.
    MAKEFLAGS="-j${BUILD_JOBS:-$(nproc)}" pecl install "mongodb-${MONGODB_VERSION}"
    mkdir /out
    strip --strip-all -o /out/mongodb.so "$(php-config --extension-dir)/mongodb.so"
    runtime-packages /out/mongodb.so > /out/runtime-packages
EOF

# Swoole from the head of SWOOLE_BRANCH. ADD resolves the branch to a commit on every build, so this stage is
# rebuilt exactly when the branch has moved; a RUN git clone would stay cached on the commit of its first build.
FROM build-deps AS swoole
ARG BUILD_JOBS=""
ARG SWOOLE_BRANCH=master
ADD https://github.com/swoole/swoole-src.git#${SWOOLE_BRANCH} /usr/src/swoole-src
RUN <<'EOF'
    cd /usr/src/swoole-src
    phpize
    ./configure --enable-openssl \
                --enable-sockets \
                --enable-mysqlnd \
                --enable-swoole-curl \
                --enable-cares \
                --enable-swoole-pgsql \
                --with-swoole-oracle=instantclient,/usr/local/instantclient \
                --enable-swoole-sqlite
    make -j"${BUILD_JOBS:-$(nproc)}"
    mkdir /out
    # Stripped: 3 MB instead of 53 MB. Build Swoole by hand in the container to debug a crash with symbols.
    strip --strip-all -o /out/swoole.so modules/swoole.so
    runtime-packages /out/swoole.so > /out/runtime-packages
EOF

FROM base
COPY --from=oracle /usr/local/instantclient /usr/local/instantclient
RUN --mount=type=bind,from=extensions,source=/out,target=/mnt/extensions \
    --mount=type=bind,from=mongodb,source=/out,target=/mnt/mongodb \
    --mount=type=bind,from=swoole,source=/out,target=/mnt/swoole <<'EOF'
    apt-get update
    # shellcheck disable=SC2046
    apt-get install -y --no-install-recommends git $(sort -u /mnt/*/runtime-packages)
    rm -rf /var/lib/apt/lists/*
    lib_dir=/usr/lib/x86_64-linux-gnu
    if [[ ! -e "${lib_dir}/libaio.so.1" ]] ; then ln -s libaio.so.1t64 "${lib_dir}/libaio.so.1" ; fi
    echo /usr/local/instantclient > /etc/ld.so.conf.d/oracle-instantclient.conf
    ldconfig
    cp /mnt/*/*.so "$(php-config --extension-dir)/"
    docker-php-ext-enable mysqli pdo_pgsql gd pdo_oci mongodb swoole
    echo "swoole.enable_library=off" >> /usr/local/etc/php/conf.d/docker-php-ext-swoole.ini
    php -m
    php --ri swoole
    # Fail the build, not the tests, when an extension or a Swoole feature is missing.
    for extension in curl gd mongodb mysqli pdo_mysql pdo_oci pdo_pgsql pdo_sqlite redis sockets swoole ; do
        php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "${extension}" || { echo "Extension ${extension} is missing." ; exit 1 ; }
    done
    php -r 'exit(ini_get("swoole.enable_library") ? 1 : 0);' || { echo "swoole.enable_library is on." ; exit 1 ; }
    swoole_info="$(php --ri swoole)"
    for feature in 'coroutine_oracle => enabled' 'coroutine_pgsql => enabled' 'coroutine_sqlite => enabled' \
                   'curl-native => enabled' 'c-ares => ' 'openssl => ' 'mysqlnd => enabled' 'sockets => enabled' ; do
        grep -qF -- "${feature}" <<< "${swoole_info}" || { echo "Swoole lacks: ${feature}" ; exit 1 ; }
    done
EOF

COPY <<'EOF' /etc/supervisor/service.d/wordpress.conf
[supervisord]
user = root

[program:wordpress]
command = php /var/www/examples/fastcgi/proxy/wordpress.php
user = root
autostart = true
stdout_logfile=/proc/self/fd/1
stdout_logfile_maxbytes=0
stderr_logfile=/proc/self/fd/1
stderr_logfile_maxbytes=0
EOF
