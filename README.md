Packeton - Private PHP package repository for vendors
======================================================

[![Docker pulls](https://img.shields.io/docker/pulls/okvpn/packeton.svg?label=docker+pulls)](https://hub.docker.com/r/okvpn/packeton)
[![Docker stars](https://img.shields.io/docker/stars/okvpn/packeton.svg?label=docker+stars)](https://hub.docker.com/r/okvpn/packeton)
[![Docker version](https://images.microbadger.com/badges/version/okvpn/packeton.svg)](https://hub.docker.com/r/okvpn/packeton)
[![Docker layers](https://images.microbadger.com/badges/image/okvpn/packeton.svg)](https://hub.docker.com/r/okvpn/packeton)

Fork of [Packagist](https://github.com/composer/packagist). 
The Open Source alternative of [Private Packagist for vendors](https://packagist.com), that based on [Satis](https://github.com/composer/satis) and [Packagist](https://github.com/composer/packagist).

Features
--------

- Compatible with composer.
- Support update webhook for GitHub, Bitbucket and GitLab.
- Support custom webhook format.
- Customers user and groups.
- Generic Packeton [webhooks](docs/webhook.md)
- Limit access by vendor and versions.
- Allow to freeze updates for the new releases after expire a customers license.
- Mirroring for packages' zip files and downloads its from your host.
- Allow to add ssh keys from UI and use multiple SSH Keys settings for different github/git accounts.

What was changed in this fork?
-----------------------------
- Disable anonymously access, registrations, spam/antispam, added groups and permissions.
- Support MySQL and PostgresSQL.
- Removed HWIOBundle, Algolia, GoogleAnalytics and other not used dependencies.

Table of content
---------------

- [Run as Docker container](#install-and-run-in-docker)
- [Demo](#demo)
- [Installation from code](#installation)
- [Outgoing Webhook](/docs/webhook.md)
    - [Intro](/docs/webhook.md#introduction)
    - [Examples](/docs/webhook.md#examples)
        - [Telegram notification](/docs/webhook.md#telegram-notification)
        - [Slack notification](/docs/webhook.md#slack-notification)
        - [JIRA issue fix version](/docs/webhook.md#jira-create-a-new-release-and-set-fix-version)
        - [Gitlab setup auto webhook](/docs/webhook.md#gitlab-auto-webhook)
- [Ssh key access](#ssh-key-access-and-composer-oauth-token)
- [Update Webhooks](#update-webhooks)
    - [Github](#github-webhooks)
    - [GitLab](#gitlab-service)
    - [GitLab Organization](#gitlab-group-hooks)
    - [Bitbucket](#bitbucket-webhooks)
    - [Manual hook](#manual-hook-setup)
    - [Custom webhook format](#custom-webhook-format-transformer)
- [Usage](#usage-and-authentication)
    - [Create admin user](#create-admin-user)

Demo
----
See our [Administration Demo](https://pkg.okvpn.org). Username/password (admin/composer)

[![Demo](docs/img/demo.png)](docs/img/demo.png)

Install and Run in Docker
------------------------

Pull the image from docker hub https://hub.docker.com/r/okvpn/packeton:

```
docker pull okvpn/packeton
```

Run the image (with docker-composer):

```yaml
version: '3'

services:
    packagist:
        image: okvpn/packeton:latest
        container_name: packagist
        restart: unless-stopped
        hostname: packagist
        volumes:
            - .docker/redis:/var/lib/redis  # Redis data
            - .docker/zipball:/var/www/packagist/app/zipball # Zipped archive cache for "dist" downloads
            - .docker/composer:/var/www/.composer  # Composer cache
            - .docker/ssh:/var/www/.ssh # Share here your ssh keys
        environment:
            PRIVATE_REPO_DOMAIN_LIST: bitbucket.org gitlab.com github.com
            PACKAGIST_DIST_HOST: https://pkg.okvpn.org # Dist url to download the zip package.
            DATABASE_HOST: 172.17.0.1
            DATABASE_PORT: 5432
            DATABASE_DRIVER: pdo_pgsql
            DATABASE_USER: postgres
            DATABASE_NAME: packagist
            DATABASE_PASSWORD: 123456
            ADMIN_USER: admin
            ADMIN_PASSWORD: composer
            ADMIN_EMAIL: admin@example.com
            GITHUB_NO_API: 'true'
        ports:
          - 127.0.0.1:8080:80
```

Also you can configure Packeton server to run behind a NGINX reverse proxy. 
For example to enable ssl.

```
server {
    listen *:443 ssl http2;

    server_name pkg.okvpn.org;

    ssl_certificate /etc/letsencrypt/live/pkg.okvpn.org/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/pkg.okvpn.org/privkey.pem;
    ssl_dhparam /etc/nginx/ssl/dh.pem;
    ssl_ciphers 'ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-SHA384:ECDHE-RSA-AES128-SHA256:ECDHE-RSA-AES256-SHA:ECDHE-RSA-AES128-SHA:ECDHE-RSA-DES-CBC3-SHA:AES256-GCM-SHA384:AES128-GCM-SHA256:AES256-SHA256:AES128-SHA256:AES256-SHA:AES128-SHA:DES-CBC3-SHA:!aNULL:!eNULL:!EXPORT:!DES:!MD5:!PSK:!RC4';
 
    ssl_protocols TLSv1.1 TLSv1.2;
    ssl_prefer_server_ciphers on;
    ssl_session_cache  builtin:1000  shared:SSL:10m;
    ssl_session_timeout  5m;
    access_log  off;
    error_log  /var/log/nginx/pkg_error.log;

    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_buffers 16 16k;
    gzip_http_version 1.1;
    gzip_min_length 2048;
    gzip_types text/css image/svg+xml application/octet-stream application/javascript text/javascript application/json;

    location / {
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_pass http://127.0.0.1:8080/;
    }
}

server {
    if ($host = pkg.okvpn.org) {
        return 301 https://$host$request_uri;
    } # managed by Certbot

    listen 80;
    return 301 https://$host$request_uri;
    server_name pkg.okvpn.org;
}
```

Installation
------------

### Requirements

- MySQL or PostgresSQL for the main data store.
- Redis for some functionality (favorites, download statistics, worker queue).
- git/svn/hg depending on which repositories you want to support.
- Supervisor to run a background job worker

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
# Add maintainer role
php bin/console fos:user:promote <username> ROLE_MAINTAINER
```

8. Enable cron tabs and background jobs.
Enable crontab `crontab -e -u www-data` 

```
* * * * * /var/www/packagist/bin/console --env=prod okvpn:cron >> /dev/null
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

Ssh key access and composer oauth token.
-----------------------
Packagist uses the Composer global config and global ssh-key to get read access to your repositories, so
the supervisor worker `packagist:run-workers` and web-server must run under the user, 
that have ssh key or composer config that gives it read (clone) access to your git/svn/hg repositories.
For example, if your application runs under `www-data` and have home directory `/var/www`, directory
structure must be like this.

```
    └── /var/www/
        ├── .ssh/ # ssh keys directory
        │   ├── config
        │   ├── id_rsa # main ssh key
        │   ├── private_key_2 # additional ssh key
        │   └── private_key_3
        │
        └── .composer/ # composer home
            ├── auth.json
            └── config.json
    
```

Example ssh config for multiple SSH Keys for different github account/repos, 
see [here for details](https://gist.github.com/jexchan/2351996)

```
# .ssh/config - example

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

You can add GitHub/GitLab access token to `auth.json`, see [here](https://gist.github.com/jeffersonmartin/d0d4a8dfec90d224d14f250b36c74d2f)

```
{
    "github-oauth": {
        "github.com": "xxxxxxxxxxxxx"
    }
}
```

#### Don't use GitHub Api.

By default composer will use GitHub API to get metadata for your GitHub repository, you can add 
`use-github-api` to composer config.json to always use ssh key and clone the repository as 
it would with any other git repository, [see here](https://getcomposer.org/doc/06-config.md#use-github-api)

Update Webhooks
---------------
You can use GitLab, GitHub, and Bitbucket project post-receive hook to keep your packages up to date 
every time you push code.

#### Bitbucket Webhooks
To enable the Bitbucket web hook, go to your BitBucket repository, 
open the settings and select "Webhooks" in the menu. Add a new hook. Y
ou have to enter the Packagist endpoint, containing both your username and API token. 
Enter `https://<app>/api/bitbucket?token=user:token` as URL. Save your changes and you're done.

#### GitLab Service

To enable the GitLab service integration, go to your GitLab repository, open 
the Settings > Integrations page from the menu. 
Search for Packagist in the list of Project Services. Check the "Active" box, 
enter your `packeton.org` username and API token. Save your changes and you're done.

#### GitLab Group Hooks

Group webhooks will apply to all projects in a group and allow to sync all projects.
To enable the Group GitLab webhook you must have the paid plan. 
Go to your GitLab Group > Settings > Webhooks.
Enter `https://<app>/api/update-package?token=user:token` as URL.

#### GitHub Webhooks
To enable the GitHub webhook go to your GitHub repository. Click the "Settings" button, click "Webhooks". 
Add a new hook. Enter `https://<app>/api/github?token=user:token` as URL.

#### Manual hook setup

If you do not use Bitbucket or GitHub there is a generic endpoint you can call manually 
from a git post-receive hook or similar. You have to do a POST request to 
`https://pkg.okvpn.org/api/update-package?token=user:api_token` with a request body looking like this:

```
{
  "repository": {
    "url": "PACKAGIST_PACKAGE_URL"
  }
}
```

Also you can overwrite regex that was used to parse the repository url, 
see [ApiController](src/Packagist/WebBundle/Controller/ApiController.php#L348)

```
{
  "repository": {
    "url": "PACKAGIST_PACKAGE_URL"
  },
  "packeton": {
    "regex": "{^(?:ssh://git@|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(scm/)?(?P<path>[\\w.-]+(?:/[\\w.-]+?)+)(?:\\.git|/)?$}i"
  }
}
```

You can do this using curl for example:

```
curl -XPOST -H 'content-type:application/json' 'https://pkg.okvpn.org/api/update-package?token=user:api_token' -d' {"repository":{"url":"PACKAGIST_PACKAGE_URL"}}'
```

Instead of using repo url you can use directly composer package name. 
You have to do a POST request with a request body.

```
{
  "composer": {
    "package_name": "okvpn/test"
  }
}
```

```
{
  "composer": {
    "package_name": ["okvpn/test", "okvpn/pack2"]
  }
}
```

#### Custom webhook format transformer

You can create a proxy middleware to transform JSON payload to the applicable inner format.
In first you need create a new Rest Endpoint to accept external request.

Go to `Settings > Webhooks` and click `Add webhook`. Fill the form:
 - url - `https://<app>/api/update-package?token=user:token`
 - More options > Name restriction - `#your-unique-name#` (must be a valid regex)
 - Trigger > By HTTP requests to https://APP_URL/api/webhook-invoke/{name} - select checkbox
 - Payload - Write a script using twig expression to transform external request to POST request from previous example.

For example, if the input request has a format, the twig payload may look like this:

```json
{
   "repository":{
      "slug":"vtsykun-packeton",
      "id":11,
      "name":"vtsykun-packeton",
      "scmId":"git",
      "state":"AVAILABLE",
      "links": {
          "clone": [
              {"href": "https://github.com/vtsykun/packeton.git"}
          ]
      }
   }
}
```

```twig
{% set repository = request.repository.links.clone[0].href %}
{% if repository is null %}
    {{ interrupt('Request does not contains repository link') }}
{% endif %}

{% set response = {
    'repository': {'url': repository },
    'packeton': {'regex': '{^(?:ssh://git@|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(scm/)?(?P<path>[\\w.-]+(?:/[\\w.-]+?)+)(?:\\.git|/)?$}i'} 
} %}

{{ response|json_encode }}
```

See [twig expression](docs/webhook.md) syntax for details.

Click the "Save button"

Now if you call the url `https://APP_URL/api/webhook-invoke/your-unique-name?token=<user>:<token>`
request will be forward to `https://APP_URL/api/update-package?token=user:token` with converted POST
payload according to your rules.

Usage and Authentication
------------------------
By default admin user have access to all repositories and able to submit packages, create users, view statistics. 
The customer users can only see related packages and own profile with instruction how to use api token.

To authenticate composer access to repository needs add credentials globally into auth.json, for example:

```
composer config --global --auth http-basic.pkg.okvpn.org <user> <token>
```

API Token you can found in your Profile.

Configure this private repository in your `composer.json`. 

```
{
  "repositories": [{
      "type": "composer",
      "url": "https://packeton.company.com"
  }],
  "require": {
    "company/name1": "1.0.*",
    ....
  }
}
```

### Create admin and maintainer users.

Only admin and maintainer user can submit a new package.
Only admin user can create the new customer users. 
You can create an user and then promote to admin or maintainer via console using fos user bundle commands.

```
php bin/console fos:user:create
php bin/console fos:user:promote <username> ROLE_ADMIN
```


LICENSE
------
MIT
