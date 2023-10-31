# User Authentication

Packeton may support multiple methods of authenticating users. It can additionally be extended to support 
custom authentication schemes.

## Web User authentication
Included in packeton is support for authenticating users via:

* A username and password.
* An email address and password.

But possible to enable LDAP only via configuration, see [ldap authentication](./authentication-ldap.md)

## Composer API authentication

Packeton is support API authentication only with api token. Password usage is not allowed. 
You can see api token in thr user profile menu.

Support for authenticating users via:
* HTTP Basic Authentication (username and api token)
* Short query param `token` = `username:apiToken`
* Default packagist hook API (query params: `username` = username, `apiToken` = apiToken) 

Your customer needs to authenticate to access their Composer repository:
The simplest way to provide your credentials is providing your set of credentials inline with the repository specification such as:

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "https://<username>:<api_token>@example.org"
        }
    ]
}
```

When you don't want to hard code your credentials into your composer.json, you can set up it global.

```
composer config --global --auth http-basic.example.org username api_token
```

Example API call.

```
curl https://example.com/packages/list.json
   -u "username:apiToken"
```

```
curl https://example.com/packages/list.json?token=username:apiToken
```
