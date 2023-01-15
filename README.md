Packeton - Private PHP package repository for vendors
======================================================

[![PHP Version Require](http://poser.pugx.org/okvpn/packeton/require/php)](https://packagist.org/packages/packeton/packeton)
[![Docker pulls](https://img.shields.io/docker/pulls/okvpn/packeton.svg?label=docker+pulls)](https://hub.docker.com/r/packeton/packeton)
[![Docker stars](https://img.shields.io/docker/stars/okvpn/packeton.svg?label=docker+stars)](https://hub.docker.com/r/packeton/packeton)
[![License](http://poser.pugx.org/okvpn/packeton/license)](https://packagist.org/packages/okvpn/packeton)

Fork of [Packagist](https://github.com/composer/packagist). 
The Open Source alternative of [Private Packagist for vendors](https://packagist.com), that based on [Satis](https://github.com/composer/satis) and [Packagist](https://github.com/composer/packagist).

**All** documentation here [docs.packeton.org](https://docs.packeton.org/)

### Legacy Symfony 3.4 version

Update to 2.0. [UPGRADE.md](./UPGRADE.md)

Features
--------

- Compatible with Composer API v2, bases on Symfony 5.4.
- Support update webhook for GitHub, Bitbucket and GitLab or custom format.
- Customers user and ACL groups and limit access by vendor and versions.
- Generic Packeton [webhooks](docs/webhook.md)
- Allow to freeze updates for the new releases after expire a customers license.
- Mirroring for packages zip files and downloads it's from your host.
- Credentials and Authentication for privately hosted packages by oauth/http-basic config or ssh keys.

What was changed in this fork?
-----------------------------
- Disable anonymously access, registrations, spam/antispam, added ACL permissions.
- Support MySQL, PostgresSQL or SQLite.
- Removed HWIOBundle, Algolia, GoogleAnalytics and other not used dependencies and other metrics collectors.

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
See our [Administration Demo](https://demo.packeton.org). Username/password (admin/123456)

[![Demo](docs/img/demo.png)](docs/img/demo.png)

Install and Run in Docker
------------------------

You can use [packeton/packeton](https://hub.docker.com/r/packeton/packeton) image

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

Or build and run docker container with docker-compose:

- [docker-compose.yml](./docker-compose.yml) Single container example, here the container runs supervisor that to start 
other jobs: nginx, redis, php-fpm, cron, worker. However, it does not follow the docker best-practises 
where 1 service must be per container. But it is very easy to use and KISS principle 

- [docker-compose-prod.yml](./docker-compose-prod.yml) - multiple containers, where 1 service per container

```
docker-compose build

docker-compose up -d # Run with single supervisor container 
docker-compose up -f docker-compose-prod.yml -d # Or split 
```

#### Docker Environment variables

- `APP_SECRET` - Must be static, used for encrypt SSH keys in database. The value is generated automatically, see `.env` in the data volume. 
- `APP_COMPOSER_HOME` - composer home, default /data/composer
- `DATABASE_URL` - Database DSN, default sqlite:////data/app.db. Example for postgres "postgresql://app:pass@127.0.0.1:5432/app?serverVersion=14&charset=utf8"
- `PACKAGIST_DIST_PATH` - Default /data/zipball, path to storage zipped versions
- `REDIS_URL` - Redis DB, default redis://localhost
- `PACKAGIST_DIST_HOST` - Hostname, (auto) default use the current host header in the request.
- `TRUSTED_PROXIES` - Ips for Reverse Proxy. See [Symfony docs](https://symfony.com/doc/current/deployment/proxies.html)
- `PUBLIC_ACCESS` - Allow anonymous users access to read packages metadata, default: `false`
- `MAILER_DSN` - Mailter for reset password, default disabled
- `MAILER_FROM` - Mailter from

Installation
------------

### Requirements

- PHP 8.1+
- Redis for some functionality (favorites, download statistics, worker queue).
- git/svn/hg depending on which repositories you want to support.
- Supervisor to run a background job worker
- (optional) MySQL or PostgresSQL for the main data store, default SQLite

1. Clone the repository
2. Install dependencies: `composer install`
3. Create .env.local and copy needed environment variables into it, see docker Environment variables section
4. Run `bin/console doctrine:schema:create` to setup the DB
5. Create admin user via console.

```
php bin/console packagist:user:manager username --email=admin@example.com --password=123456 --admin 
```

6. Enable cron tabs and background jobs.
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

7. **IMPORTANT** Make sure that web-server, cron and supervisor run under the same user, that should have an ssh key 
that gives it read (clone) access to your git/svn/hg repositories. If you run application under `www-data` 
you can add your ssh keys to /var/www/.ssh/

You should now be able to access the site, create a user, etc.

8. Make a VirtualHost with DocumentRoot pointing to public/

Ssh key access and composer oauth token.
-----------------------
Packagist uses the Composer global config and global ssh-key to get read access to your repositories, so
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

If you have the error ```This private key is not valid``` inserting your ssh in admin panel is because the ssh key was generated with newer OpenSSH.
New keys with OpenSSH private key format can be converted using ssh-keygen utility to the old PEM format.
```ssh-keygen -p -m PEM -f ~/.ssh/id_rsa```

You can add GitHub/GitLab access token to `auth.json` of composer home dir 
(default `APP_COMPOSER_HOME="%kernel.project_dir%/var/.composer"`) or use UI credentials,
see [here](https://getcomposer.org/doc/articles/authentication-for-private-packages.md) 

```json
{
    "github-oauth": {
        "github.com": "xxxxxxxxxxxxx"
    }
}
```

#### Allow connections to http

You can create `config.json` in the composer home (see `APP_COMPOSER_HOME` env var) or add this option
in the UI credentials form.

```json
{
    "secure-http": false
}
```

#### Don't use GitHub Api.

We disable usage GitHub API by default to force use ssh key or clone the repository via https as
it would with any other git repository. You can enable it again with env option `GITHUB_NO_API` 
[see here](https://getcomposer.org/doc/06-config.md#use-github-api).

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

Also, you can overwrite regex that was used to parse the repository url, 
see [ApiController](src/Controller/ApiController.php#L348)

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
By default, admin user have access to all repositories and able to submit packages, create users, view statistics. 
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


**Application Roles**

- ROLE_USER - minimal access level, these users only can read metadata only for selected packages.
- ROLE_FULL_CUSTOMER - Can read all packages metadata.
- ROLE_MAINTAINER -  Can submit a new package and read all metadata.
- ROLE_ADMIN - Can create a new customer users, management webhooks and credentials.

You can create a user and then promote to admin or maintainer via console using fos user bundle commands.

```
php bin/console packagist:user:manager username --email=admin@example.com --password=123456 --admin # create admin user
php bin/console packagist:user:manager user1 --add-role=ROLE_MAINTAINER # Add ROLE_MAINTAINER to user user1
```

LICENSE
------
MIT
