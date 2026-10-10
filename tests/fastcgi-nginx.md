# FastCGI comparison with nginx

`NginxComparisonTest` sends identical HTTP requests through nginx and Swoole's
FastCGI Proxy. Both must use the same PHP-FPM pool and the same `tests/www/fastcgi`
directory. The remaining FastCGI tests do not require nginx.

Use nginx 1.28 or newer for the comparison: it combines repeated request headers
with `, ` and Cookie headers with `; `. Older versions can pass duplicate FastCGI
parameters instead, causing PHP-FPM to retain only the last value.

Example nginx server configuration (adjust the absolute root and upstream):

```nginx
server {
    listen 127.0.0.1:19086;
    server_name localhost;
    root /var/www/tests/www/fastcgi;
    index index.php;
    default_type application/octet-stream;
    client_max_body_size 32m;
    large_client_header_buffers 16 32k;
    location / { try_files $uri $uri/ =404; }
    location ~ \.php$ {
        include /etc/nginx/fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass php-fpm:9000;
        fastcgi_connect_timeout 600ms;
        fastcgi_send_timeout 600ms;
        fastcgi_read_timeout 600ms;
    }
}
```

Disable PHP-FPM output buffering for the stream fixture (`output_buffering=0`).
Allow at least two PHP-FPM workers. Run:

```sh
SWOOLE_FASTCGI_NGINX_URL=http://127.0.0.1:19086 \
SWOOLE_FASTCGI_FPM_URL=tcp://php-fpm:9000 \
SWOOLE_FASTCGI_DOCUMENT_ROOT=/var/www/tests/www/fastcgi \
php -d swoole.enable_library=Off vendor/bin/phpunit \
    tests/unit/Coroutine/FastCGI/NginxComparisonTest.php
```

The document root must name the same path in nginx and PHP-FPM. The slow-reader
fixture uses PUT so PHP does not consume the request body before the script starts.
Gateway failures compare status codes; successful FastCGI responses compare the
body, Cookies, and application headers. Server, Date, and transport framing headers
are excluded. Requests run sequentially so the two paths do not compete for workers.
The static-file case compares status, content type, and body; front-end-generated
ETag, Last-Modified, and Accept-Ranges headers are excluded. The sibling-directory
and null-byte probes require rejection with 400 by both paths. Gateway-generated
error pages are not compared.
