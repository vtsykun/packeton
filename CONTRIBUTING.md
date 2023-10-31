# Contributing

Everyone is welcome to contribute code to [https://github.com/vtsykun/packeton.git](https://github.com/vtsykun/packeton.git)

### 1. Development environment.

If you are running Windows, the Windows Subsystem for Linux (WSL) is recommended for development.
But the most of the features will work on Windows too.
The code of Packeton is written in PHP, Symfony framework.

#### Requirements

- PHP 8.1+
- Redis (or Docker) for some functionality.
- (optional) nginx / php-fpm to run the web server.
- (optional) MySQL or PostgresSQL for the main data store, default SQLite.

### 2. Get the source.

Make a fork on GitHub, and then create a pull request to provide your changes.

```
git clone git@github.com:YOUR_GITHUB_NAME/packeton.git
git checkout -b fix/patch-1
```

### 3. Install the dependencies

Run composer install

```
cd packeton
composer install
```

### 4. Configure your env vars.

Create a file `.env.local` with following content.

```
# .env.local

APP_ENV=dev

# select database, default SQLite
DATABASE_URL="postgresql://postgres:123456@127.0.0.1:5432/packeton?serverVersion=12&charset=utf8"
```

### 5. Setup database

```
bin/console doctrine:schema:update --dump-sql --force
```

### 6. Run local webserver.

```
php -S localhost:8000 -t public/
```

Optional, to create admin user use the command:

```
php bin/console packagist:user:manager admin  --password=123456 --admin
```

To run sync workers:

```
php bin/console packagist:run-workers -vvv
```

ENJOY
-----
