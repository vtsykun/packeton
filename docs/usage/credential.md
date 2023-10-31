# SSH Credential and Composer Auth.

Composer provide two why of authentication for privately hosted packages. 

You may to setup authentication in `auth.json` in composer home, i e. `/var/www/packeton/var/.composer/` 
or `/data/composer/auth.json` for docker installation. 

See example usage [system auth setup](../installation.md#ssh-key-access-and-composer-oauth-token)

### Using UI Credential Manager

You can overwrite credentials for each repository in UI.

All credentials are encrypted in database by custom DBAL type `EncryptedTextType`.
Encrypted key is `APP_SECRET` so it must be permanent.

[![Groups](../img/keys.png)](../img/keys.png)
