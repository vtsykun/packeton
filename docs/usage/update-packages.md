# How to auto update packages?

You can use GitLab, GitHub, and Bitbucket project post-receive hook to keep your packages up to date every time you push code.

## Into

Token-based webhook API request authorization requires a minimum access level of `ROLE_MAINTAINER`.
You can use `token` query parameter with `<username:api_token>` to call it. 

Also support Packagist.org authorization with `username` and `apiToken` query parameters.

## Bitbucket Webhooks
To enable the Bitbucket web hook, go to your BitBucket repository,
open the settings and select "Webhooks" in the menu. Add a new hook. Y
ou have to enter the Packagist endpoint, containing both your username and API token.
Enter `https://<app>/api/bitbucket?token=user:token` as URL. Save your changes and you're done.

## GitLab Service

To enable the GitLab service integration, go to your GitLab repository, open
the Settings > Integrations page from the menu.
Search for Packagist in the list of Project Services. Check the "Active" box,
enter your `packeton.org` username and API token. Save your changes and you're done.

## GitLab Group Hooks

Group webhooks will apply to all projects in a group and allow to sync all projects.
To enable the Group GitLab webhook you must have the paid plan.
Go to your GitLab Group > Settings > Webhooks.
Enter `https://<app>/api/update-package?token=user:token` as URL.

## GitHub Organization Webhooks (recommended)

Organization webhooks can authenticate with GitHub's `X-Hub-Signature-256` header, without exposing a user API token
in the webhook URL.

1. In Packeton, go to Settings > Incoming webhook secrets and create a secret.
2. In GitHub, go to Organization Settings > Webhooks > Add webhook.
3. Set the payload URL to `https://<app>/api/hooks/github` and the content type to `application/json`.
4. Paste the generated secret and subscribe to push events.

The secret is shown once and stored encrypted by Packeton. Existing token-based endpoints remain available for
backwards compatibility.

## GitHub Repository Webhooks (legacy)
To enable the GitHub webhook go to your GitHub repository. Click the "Settings" button, click "Webhooks".
Add a new hook. Enter `https://<app>/api/github?token=user:token` as URL.

## Gitea Webhooks

To enable the Gitea web hook, go to your Gitea repository, open the settings, select "Webhooks" 
in the menu and click on 'Add Webhook'. From the dropdown menu select Gitea.
You have to enter the Packagist endpoint, containing both your username and API token. 
Enter `https://<app>/api/update-package?token=user:token` as URL. 
The HTTP method has to be POST and content type is application/json. Save your changes and you're done.

## Manual hook setup

If you do not use Bitbucket or GitHub there is a generic endpoint you can call manually
from a git post-receive hook or similar. You have to do a POST request to
`https://<app>g/api/update-package?token=user:api_token` with a request body looking like this:


```
{
  "repository": {
    "url": "PACKAGIST_PACKAGE_URL"
  }
}
```

You can also send a GET request with query parameter `composer_package_name`

```
curl 'https://<app>/api/update-package?token=<user:token>&composer_package_name=vender/name'
```
