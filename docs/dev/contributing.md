# Contributing

Everyone is welcome to contribute code to https://github.com/vtsykun/packeton.git

### 1. Development environment.

If you are running Windows, the Linux Windows Subsystem (WSL) is recommended for development.
The code of Packeton is written in PHP.

#### Requirements

- PHP 8.1+
- Redis (or Docker) for some functionality.
- Symfony CLI / nginx / php-fpm to run the web server.
- (optional) MySQL or PostgresSQL for the main data store, default SQLite.

### 2. Get the source.

Make a fork on GitHub, and then create a pull request to provide your changes.

```
git clone git@github.com:YOUR_GITHUB_NAME/packeton.git
git checkout -b patch-1
```

### 4. Install the dependencies

Run composer install

```
cd packeton
composer install
```
