# How to proxy

We take `WordPress` as an example here:

> if you want to test it on your mac (instead of docker)

```shell
sudo echo "127.0.0.1 php-fpm" >> /etc/hosts
```

> sudo is for listen port 80

```shell
sudo DOCUMENT_ROOT=/path/to/wordpress php -d swoole.enable_library=Off ./base.php
```

> if you want to use domain

```shell
sudo echo "127.0.0.1 www.my-wordpress.com" >> /etc/hosts
```

Now you can open `www.my-wordpress.com` in the browser to visit your `WordPress`
