Packeton - Private PHP package repository for vendors
======================================================

Fork of [Packagist](https://github.com/composer/packagist). 
The Open Source alternative of [Private Packagist for vendors](https://packagist.com), that based on [Satis](https://github.com/composer/satis) and [Packagist](https://github.com/composer/packagist).

Features
--------

- Compatible with composer.
- Customers user and groups.
- Limit access by vendor and versions.
- Expire access time.
- Archive packages and downloads its from your host.

What was changed in this fork?
-----------------------------
- Disable anonymously access, registrations, added groups and permissions.
- Support MySQL and PostgresSQL.
- Support Symfony 3.4
- Removed HWIOBundle, NelmioCorsBundle, NelmioSecurityBundle, Algolia, GoogleAnalytics dependencies.

Demo
-------
See our [Administration Demo](https://pkg.okvpn.org). Username/password (admin/composer)

[![Demo](docs/demo.png)](docs/demo.png)

Requirements
------------

- MySQL or PostgresSQL for the main data store.
- Redis for some functionality (favorites, download statistics).
- git/svn/hg depending on which repositories you want to support.

Installation
------------

1. Clone the repository
2. Copy and edit `app/config/parameters.yml` and change the relevant values for your setup.
3. Install dependencies: `composer install`
4. Run `bin/console doctrine:schema:create` to setup the DB
5. Run `bin/console assets:install web` to deploy the assets on the web dir.
6. Run `bin/console cache:warmup --env=prod` and `app/console cache:warmup --env=prod` to warmup cache
7. Create admin user via console.

```
php bin/console fos:user:create
# Add admin role
php bin/console fos:user:promote <username> ROLE_ADMIN
```

8. Enable cron tabs and background jobs.
Enable crontab `crontab -e -u www-data` 

```
*/30 * * * * /var/www/packagist/bin/console --env=prod packagist:update >> /dev/null
0 0 * * * /var/www/packagist/bin/console --env=prod packagist:stats:compile >> /dev/null
```

Setup Supervisor to run worker.

```
sudo apt -y --no-install-recommends install supervisor
```

Create a new supervisor configuration.

```
sudo vim /etc/supervisor/conf.d/packagist.conf
```
Add the following lines to the file.

```
[program:packagist-workers]
environment =
        HOME=/var/www/
command=/var/www/packagist/bin/console packagist:run-workers --env=prod --no-debug
directory=/var/www/packagist/
process_name=%(program_name)s_%(process_num)02d
numprocs=1
autostart=true
autorestart=true
startsecs=0
redirect_stderr=true
priority=1
user=www-data
```

9. **IMPORTANT** Make sure that web-server, cron and supervisor run under the same user, that should have an ssh key 
that gives it read (clone) access to your git/svn/hg repositories. If you run application under `www-data` 
you can add your ssh keys to /var/www/.ssh/

You should now be able to access the site, create a user, etc.

10. Make a VirtualHost with DocumentRoot pointing to web/

Installation using docker
------------------------

See [packagist-docker](https://github.com/vtsykun/packagist-docker.git).

Usage
------
By default admin user have access to all repositories and able to submit packages, create users, view statistics. 
The customer users can only see related packages and own profile with instruction how to use api token.

To authenticate composer access to repository needs add credentials globally into auth.json, for example:

```
composer config --global --auth http-basic.pkg.okvpn.org admin Ydmhi1C3XIP5fnRWc3y2
```

API Token you can found in your Profile.

LICENSE
------
MIT
