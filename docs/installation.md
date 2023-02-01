Install and Run
----------------

There is an official packeton image available at [https://hub.docker.com/r/packeton/packeton](https://hub.docker.com/r/packeton/packeton)
which can be used with the docker-compose file, see [docker installation](installation-docker.md)

Installation
------------

### Requirements

- PHP 8.1+
- Redis for some functionality (favorites, download statistics, worker queue).
- git/svn/hg depending on which repositories you want to support.
- Supervisor to run a background job worker
- (optional) MySQL or PostgresSQL for the main data store, default SQLite

1. Clone the repository

```bash
git clone https://github.com/vtsykun/packeton.git /var/www/packeton/
cd /var/www/packeton/
```

2. Install dependencies `composer install`
3. Create `.env.local` and copy needed environment variables into it. See [Configuration](#configuration) 
4.  IMPORTANT! Don't forget change `APP_SECRET`
5. Run `bin/console doctrine:schema:update --force --complete` to setup the DB
6. Create admin user via console.

```bash
php bin/console packagist:user:manager username --email=admin@example.com --password=123456 --admin 
```

7. Setup nginx or any webserver, for example nginx config looks like.

```
server {
    listen *:443 ssl http2;
    server_name packeton.example.org;
    root /var/www/packeton/public;

    ssl_certificate /etc/nginx/ssl/example.crt;
    ssl_certificate_key /etc/nginx/ssl/example.key;

    ssl_ciphers 'TLS13-CHACHA20-POLY1305-SHA256:TLS13-AES-128-GCM-SHA256:TLS13-AES-256-GCM-SHA384:ECDHE:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-SHA384:ECDHE-RSA-AES128-SHA256:ECDHE-RSA-AES256-SHA:ECDHE-RSA-AES128-SHA:ECDHE-RSA-DES-CBC3-SHA:AES256-GCM-SHA384:AES128-GCM-SHA256:AES256-SHA256:AES128-SHA256:AES256-SHA:AES128-SHA:DES-CBC3-SHA:!aNULL:!eNULL:!EXPORT:!DES:!MD5:!PSK:!RC4';

    ssl_protocols TLSv1.1 TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers on;
    ssl_session_cache  builtin:1000  shared:SSL:10m;
    ssl_session_timeout  5m;

    rewrite ^/index\.php/?(.+)$ /$1 permanent;
    try_files $uri @rewriteapp;

    location @rewriteapp {
        rewrite ^(.*)$ /index.php/$1 last;
    }

    access_log off;

    location ~ ^/index\.php(/|$) {
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_index index.php;
        send_timeout 300;
        fastcgi_read_timeout 300;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    }
}
```

8. Change cache permission to made it accessible for web server.

```bash
chown www-data:www-data -R var/
```

9. If you get a 500 error in index page `packeton.example.org`, please check your logs `var/log/prod.log` or/and webserver log
and fix permissions, database config, redis etc.

10. Enable cron tabs and background jobs.

Enable crontab `crontab -e -u www-data`

```
* * * * * /var/www/packeton/bin/console --env=prod okvpn:cron >> /dev/null
```

**Setup Supervisor to run worker**

```bash
sudo apt -y --no-install-recommends install supervisor
```

Create a new supervisor configuration.

```bash
sudo vim /etc/supervisor/conf.d/packagist.conf
```
Add the following lines to the file.

```
[program:packeton-workers]
environment =
        HOME=/var/www/
command=/var/www/packeton/bin/console packagist:run-workers
directory=/var/www/packeton/
process_name=%(program_name)s_%(process_num)02d
numprocs=1
autostart=true
autorestart=true
startsecs=0
redirect_stderr=true
priority=1
user=www-data
```

### Configuration

Create a file `.env.local` and change next options

- `APP_SECRET` - Must be static, used for encrypt SSH keys in database.
- `APP_COMPOSER_HOME` - composer home, default `/var/www/packeton/var/.composer/`
- `DATABASE_URL` - Database DSN, default sqlite:///%kernel.project_dir%/var/app.db

Example for postgres `postgresql://app:pass@127.0.0.1:5432/app?serverVersion=14&charset=utf8`
Example for mysql `mysql://app:!ChangeMe!@127.0.0.1:3306/app?serverVersion=8&charset=utf8mb4`

```
# .env.local

DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=14&charset=utf8"
```

- `PACKAGIST_DIST_PATH` - Default `%kernel.project_dir%/var/zipball`, path to storage zipped artifacts 
- `REDIS_URL` - Redis DB, default `redis://localhost`
- `PACKAGIST_DIST_HOST` - Hostname, (auto) default use the current host header in the request.
- `TRUSTED_PROXIES` - Ips for Reverse Proxy. See [Symfony docs](https://symfony.com/doc/current/deployment/proxies.html)
- `PUBLIC_ACCESS` - Allow anonymous users access to read packages metadata, default: `false`
- `MAILER_DSN` - Mailer for reset password, default disabled
- `MAILER_FROM` - Mailer from

### Ssh key access and composer oauth token.

Packagist uses the composer config and global ssh-key to get read access to your repositories, so
the supervisor worker `packagist:run-workers` and web-server must run under the user,
that have ssh key or composer config that gives it read (clone) access to your git/svn/hg repositories.
For example, if your application runs under `www-data` and have home directory `/var/www`, directory
structure must be like this.

```
    └── /var/www/
        └── .ssh/ # ssh keys directory
            ├── config
            ├── id_rsa # main ssh key
            ├── private_key_2 # additional ssh key
            └── private_key_3
        └── packeton/ # project dir
            ├── config APP_COMPOSER_HOME="%kernel.project_dir%/var/.composer"
            ├── public 
            ....
            ├── src 
            └── var
                ├── cache
                ....
                └── .composer # APP_COMPOSER_HOME="%kernel.project_dir%/var/.composer"
```

By default, composer configuration load from `COMPOSER_HOME` and it placed at path `%kernel.project_dir%/var/.composer`.
if you want to setup authentication in `auth.json` need to place this file to composer home, i e. `/var/www/packeton/var/.composer/`
See [Authentication in auth.json](https://getcomposer.org/doc/articles/authentication-for-private-packages.md#authentication-in-auth-json-per-project)

```
# Example /var/www/packeton/var/.composer/auth.json
{ 
  "http-basic": {
    "git.example.pl": {
      "username": "kastus",
      "password": "489df705a503ac0173256ce01f"
    }
  }
}
```

Example ssh config for multiple SSH Keys for different github account/repos,
see [here for details](https://gist.github.com/jexchan/2351996)

```
# ~/.ssh/config - example

Host github-oroinc
	HostName github.com
	User git
	IdentityFile /var/www/.ssh/private_key_2
	IdentitiesOnly yes

Host github-org2
	HostName github.com
	User git
	IdentityFile /var/www/.ssh/private_key_3
	IdentitiesOnly yes

```

#### Allow connections to http.

You can create `config.json` in the composer home (see `APP_COMPOSER_HOME` env var) or add this option
in the UI credentials form.

```json
{
    "secure-http": false
}
```
