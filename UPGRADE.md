# UPGRADE

## UPGRADE FROM 1.4 to 2.0

- Require PHP 8.1+
- Symfony LTS has been changed from `3.4` to `5.4`
- Application composer HOME was changed to `%kernel.project_dir%/var/.composer`

#### Run migrations
There are no major BC breaks in the database structure. 

Run the command to show SQL changes:

```
bin/console doctrine:schema:update --dump-sql
```

Run the command to execute migrations:

```
bin/console doctrine:schema:update --force
```

*Note* 
Now composer v2 metadata is supported. The app also supports composer v1 too.
No additional action required.

### Docker UPGRADE

- Image has been renamed from [okvpn/packeton](https://hub.docker.com/r/okvpn/packeton) to [packeton/packeton](https://hub.docker.com/r/packeton/packeton)
- Volume directory structure was changed:
  - /data/composer - composer home
  - /data/redis - redis data
  - /data/zipball - dist path
  - /data/ssh - ssh for www-data path

Before:

```
    - .docker/redis:/var/lib/redis
    - .docker/zipball:/var/www/packagist/app/zipball
    - .docker/composer:/var/www/.composer
    - .docker/ssh:/var/www/.ssh
```

After:

```
    - .docker:/data
```

#### Env variables update

Before `docker-compose.yml`

```yaml
    environment:
        DATABASE_HOST: postgres
        DATABASE_PORT: 5432
        DATABASE_DRIVER: pdo_pgsql
        DATABASE_USER: postgres
        DATABASE_NAME: packagist
        DATABASE_PASSWORD: 123456
```

After:

```yaml
    environment:
        DATABASE_URL: mysql://app:!ChangeMe!@127.0.0.1:3306/app?serverVersion=8&charset=utf8mb4
```
