Install and Run in Docker
------------------------

You can use prebuild [packeton/packeton](https://hub.docker.com/r/packeton/packeton) image

## Quick start 
```
docker run -d --name packeton \
    --mount type=volume,src=packeton-data,dst=/data \
    -p 8080:80 \
    packeton/packeton:latest
```

After container is running, you may wish to create an admin user via command `packagist:user:manager`
```
docker exec -it packeton bin/console packagist:user:manager admin --password=123456 --admin
```

## Docker Environment

All env variables is optional. By default, Packeton uses an SQLite database and build-in redis service,
but you can overwrite it by env REDIS_URL and DATABASE_URL. The all app data is stored in the VOLUME /data

- `APP_SECRET` - Must be static, used for encrypt SSH keys in database. The value is generated automatically, see `.env` in the data volume.
- `APP_COMPOSER_HOME` - composer home, default /data/composer
- `DATABASE_URL` - Database DSN, default sqlite:////data/app.db. Example for postgres "postgresql://app:pass@127.0.0.1:5432/app?serverVersion=14&charset=utf8"
- `PACKAGIST_DIST_PATH` - Default /data/zipball, path to storage zipped versions
- `REDIS_URL` - Redis DB, default redis://localhost
- `PACKAGIST_DIST_HOST` - Hostname, (auto) default use the current host header in the request.
  Overwrite packagist host (example https://packagist.youcomany.org). Used for downloading the mirroring zip packages. 
(The host add into dist url for composer metadata). The default value is define dynamically from the header Host.

- `TRUSTED_PROXIES` - Ips for Reverse Proxy. See [Symfony docs](https://symfony.com/doc/current/deployment/proxies.html)
- `PUBLIC_ACCESS` - Allow anonymous users access to read packages metadata, default: `false`
- `MAILER_DSN` - Mailer for reset password, default disabled
- `MAILER_FROM` - Mailer from
- `ADMIN_USER` Creating admin account, by default there is no admin user created so you won't be able to login to 
the packagist. To create an admin account you need to use environment variables to pass 
in an initial username and password (`ADMIN_PASSWORD`, `ADMIN_EMAIL`).
- `ADMIN_PASSWORD` - used together with `ADMIN_USER`
- `ADMIN_EMAIL` -  used together with `ADMIN_USER`
- `PRIVATE_REPO_DOMAIN_LIST` - Save ssh fingerprints to known_hosts for this domain.

#### VOLUME

The all app data is stored in the VOLUME /data

### User docker compose 

The typical example `docker-compose.yml`

```yaml
version: '3.6'

services:
    packeton:
        image: packeton/packeton:latest
        container_name: packeton
        hostname: packeton
        environment:
            ADMIN_USER: admin
            ADMIN_PASSWORD: 123456
            ADMIN_EMAIL: admin@example.com
            DATABASE_URL: mysql://app:!ChangeMe!@127.0.0.1:3306/app?serverVersion=8&charset=utf8mb4
        ports:
            - '127.0.0.1:8080:80'
        volumes:
            - .docker:/data
```

By default, the container starts the supervisor, which is used to run other tasks: 
nginx, redis, php-fpm, cron, however, you can start one service per container. 
See docker-compose-prod.yml example:

```yaml
version: '3.9'

x-volumes: &default-volume
    volumes:
        - app-data:/data
        - app-var:/var/www/packagist/var

x-restart-policy: &restart_policy
    restart: unless-stopped

x-environment: &default-environment
    REDIS_URL: redis://redis
    DATABASE_URL: "postgresql://packeton:pack123@postgres:5432/packeton?serverVersion=14&charset=utf8"
    SKIP_INIT: 1

services:
    redis:
        image: redis:7-alpine
        hostname: redis
        <<: *restart_policy
        volumes:
            - redis-data:/data
 
    postgres:
        image: postgres:14-alpine
        hostname: postgres
        <<: *restart_policy
        volumes:
            - postgres-data:/var/lib/postgresql/data
        environment:
            POSTGRES_USER: packeton
            POSTGRES_PASSWORD: pack123
            POSTGRES_DB: packeton

    php-fpm:
        image: packeton/packeton:latest
        hostname: php-fpm
        command: ['php-fpm', '-F']
        <<: *restart_policy
        <<: *default-volume
        environment:
            <<: *default-environment
            SKIP_INIT: 0
            WAIT_FOR_HOST: 'postgres:5432'
        depends_on:
            - "postgres"
            - "redis"

    nginx:
        image: packeton/packeton:latest
        hostname: nginx
        ports:
            - '127.0.0.1:8088:80'
        <<: *restart_policy
        <<: *default-volume
        command: >
            bash -c 'sed s/_PHP_FPM_HOST_/php-fpm:9000/g < docker/nginx/nginx-tpl.conf > /etc/nginx/nginx.conf && nginx'
        environment:
            <<: *default-environment
            WAIT_FOR_HOST: 'php-fpm:9000'
        depends_on:
            - "php-fpm"

    worker:
        image: packeton/packeton:latest
        hostname: packeton-worker
        command: ['bin/console', 'packagist:run-workers', '-v']
        user: www-data
        <<: *restart_policy
        <<: *default-volume
        environment:
            <<: *default-environment
            WAIT_FOR_HOST: 'php-fpm:9000'
        depends_on:
            - "php-fpm"

    cron:
        image: packeton/packeton:latest
        hostname: packeton-cron
        command: ['bin/console', 'okvpn:cron', '--demand', '--time-limit=3600']
        user: www-data
        <<: *restart_policy
        <<: *default-volume
        environment:
            <<: *default-environment
            WAIT_FOR_HOST: 'php-fpm:9000'
        depends_on:
            - "php-fpm"

volumes:
    redis-data:
    postgres-data:
    app-data:
    app-var:

```

## Build and run docker container with docker-compose

1. Clone repository

```bash
git clone https://github.com/vtsykun/packeton.git /var/www/packeton/
cd /var/www/packeton/
```

2. Run `docker-compose build`

```bash
docker-compose build

# start container.
docker-compose up -d # Run with single supervisor container 
docker-compose up -f docker-compose-prod.yml -d # Or split 
```
