FROM php:8.2-fpm-alpine

RUN apk --no-cache add nginx curl subversion  \
    git bash openssh-client zip unzip redis shadow && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    printf "Host *\n    StrictHostKeyChecking no" > /etc/ssh/ssh_config

RUN set -eux; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        icu-dev \
        coreutils \
        linux-headers \
        libxml2-dev openldap-dev \
        bzip2-dev libzip-dev \
        libxslt-dev \
        oniguruma-dev \
    ; \
    \
    export CFLAGS="$PHP_CFLAGS" \
        CPPFLAGS="$PHP_CPPFLAGS" \
        LDFLAGS="$PHP_LDFLAGS"; \
    \
    pecl install -o -f redis; \
    docker-php-ext-enable redis; \
    docker-php-ext-install sockets ldap xsl zip pdo pdo_pgsql pdo_mysql intl sysvsem opcache \
        bz2 mbstring pcntl; \
    runDeps="$( \
        scanelf --needed --nobanner --format '%n#p' --recursive /usr/local \
            | tr ',' '\n' \
            | sort -u \
            | awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }' \
    )"; \
    echo $runDeps; \
    apk add --no-cache $runDeps; \
    \
    apk del --no-network .build-deps;

COPY --from=ochinchina/supervisord:latest /usr/local/bin/supervisord /usr/bin/supervisord

WORKDIR /var/www/packagist

COPY composer.json composer.lock /var/www/packagist/

RUN composer install --no-interaction --no-suggest --no-dev --no-scripts && \
    chown www-data:www-data -R /var/www && \
    rm -rf /root/.composer

COPY --chown=82:82 . /var/www/packagist/

RUN composer run-script auto-scripts && \
    mkdir var/composer var/zipball && \
    rm -rf /root/.composer var/cache

RUN set -eux; \
    cp docker/php/www.conf /usr/local/etc/php-fpm.d/zzz-docker.conf; \
    cp docker/php/php.ini /usr/local/etc/php/conf.d/90-php.ini; \
    mkdir /etc/supervisor.d/; cp docker/supervisor/* /etc/supervisor.d/; \
    cp docker/php/supervisord.conf /etc/; \
    cp docker/nginx/nginx.conf /etc/nginx/nginx.conf; \
    cp docker/php/index.php public/index.php; \
    cp docker/php/app /usr/local/bin/app; \
    cp docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh; \
    cp docker/php/wait-for-it.sh /usr/local/bin/wait-for-it.sh; \
    mkdir -p /run/php/ /data; \
    chmod +x /usr/local/bin/app /usr/local/bin/docker-entrypoint.sh; \
    usermod -d /var/www www-data; \
    echo "dir /data/redis" >> /etc/redis.conf; \
    chown www-data:www-data /var/lib/nginx /var/lib/nginx/tmp /data

RUN echo '# Added by docker build' >> .env;\
    echo 'DATABASE_URL=sqlite:////data/app.db' >> .env; \
    echo 'APP_COMPOSER_HOME=/data/composer' >> .env; \
    echo 'PACKAGIST_DIST_PATH=/data/zipball' >> .env;

VOLUME ["/data"]

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
