# OAuth2 and Sync integrations

Table of content
---------------
- [Pull Request review](pull-request-review.md)
- [Login Restriction](oauth2/login-expression.md)
- [GitHub Setup](oauth2/github-oauth.md)
- [GitHub App Setup](oauth2/githubapp.md)
- [GitLab Setup](oauth2/gitlab-integration.md)
- [Gitea Setup](oauth2/gitea.md)
- [Bitbucket Setup](oauth2/bitbucket.md)

## Base configuration reference

To enable OAuth2 integrations, you need to add the following configuration 
```yml
packeton:
    integrations:
        github: # Alias name 
            allow_login: true # default false 
            allow_register: false # default false 
            default_roles: ['ROLE_USER', 'ROLE_MAINTAINER', 'ROLE_GITLAB']
            
            clone_preference: 'api'
            repos_synchronization: true
            
            disable_hook_repos: false # disabled auto setup webhook
            disable_hook_org: false
            svg_logo: ~ # <svg xmlns= logo
            logo: ~ # png logo
            login_title: Login or Register with GitHub
            description: ~

            login_control_expression: "data['email'] ends with '@packeton.org'" # Restrict logic/register by custom condition.
            login_control_expression_debug: false # help debugging    

            pull_request_review: true # Enable pull request composer.lock review. Default false
            webhook_url: ~ #overwrite host when setup webhooks

            github:
                client_id: 'xxx'
                client_secret: 'xxx'

        gitlab2:  # Alias name - may be any url safe value.
            base_url: 'https://gitlab.production.com/'
            clone_preference: 'clone_https' # Allows [api, clone_https, clone_ssh]
            gitlab: # Provider name: github, gitlab, bitbucket etc 
                client_id: 'xxx'
                client_secret: 'xxx'
                api_version: 'v4' # you may overwrite only for gitlab provider, default v4

        # Use GitHub APP JWT
        # See https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/about-authentication-with-a-github-app
        githubapp_main:
            repos_synchronization: true
            pull_request_review: true
            githubapp:
                private_key: '%kernel.project_dir%/var/packeton-private-key.pem'
                passphrase: ~ # private key pass
                app_id: 345472

        gitea:
            allow_login: true
            repos_synchronization: true
            pull_request_review: true
            base_url: 'https://gitea.packeton.com.ar/'
            gitea:
                client_id: '44000000-0000-0000-0000-00000000000'
                client_secret: 'gto_acxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'

        bitbucket:
            repos_synchronization: true
            pull_request_review: true
            bitbucket:
                key: GA7000000000000000
                secret: 9chxxxxxzxxxxxxxxeexxxxxxxxxxxxx
                api_version: ~ # '/example/rest/v2/' custom api prefix

        google:
            allow_login: true
            google:
                client_id: 'xxxxx.apps.googleusercontent.com'
                client_secret: 'xxxx'    

```

Where `clone_preference`:

- `api` - Use api to get composer info
- `clone_https` - clone repo with using oauth api token
- `clone_ssh` - clone repo with system ssh key

`repos_synchronization` - If enabled, a new package will be automatically created when you will push to a new or exists repo that contains `composer.json` 

## Docker env.

To make docker usage more easy, you can use env variables to configure basic settings for each integration without editing the `*.yaml` configs.

```
# GitLab
# OAUTH_GITLAB_CLIENT_ID=
# OAUTH_GITLAB_CLIENT_SECRET=
# OAUTH_GITLAB_BASE_URL=

# GitHub
# OAUTH_GITHUB_CLIENT_ID=
# OAUTH_GITHUB_CLIENT_SECRET=
# OAUTH_GITHUB_BASE_URL=

# Gitea 
# OAUTH_GITEA_CLIENT_ID=
# OAUTH_GITEA_CLIENT_SECRET=
# OAUTH_GITEA_BASE_URL=

# Bitbucket 
# OAUTH_BITBUCKET_CLIENT_ID=
# OAUTH_BITBUCKET_CLIENT_SECRET=
# OAUTH_BITBUCKET_BASE_URL=

# Google SSO
# OAUTH_GOOGLE_CLIENT_ID=
# OAUTH_GOOGLE_CLIENT_SECRET=
# OAUTH_GOOGLE_ALLOW_REGISTRATION=

# Additinal vars ${NAME} = GITLAB/GITHUB/ .. 
# OAUTH_*_DISABLE_ORG_HOOK=
# OAUTH_*_DISABLE_REP_HOOK=
# OAUTH_*_ALLOW_LOGIN=
# OAUTH_*_ALLOW_REGISTRATION=
```

## Supported 3-d provider

### GitHub

Scopes:

- login: `user:email`
- repositories: `read:org`, `repo`

Redirect Urls:

```
https://example.com/
```

### GitLab

Scopes:

- login: `read_user`
- repositories: `api`

Redirect Urls:

```
https://example.com/oauth2/{alias}/install
https://example.com/oauth2/{alias}/check
```

####  GitLab Groups Webhooks notices

A group webhooks needed for synchronization a new package. 
They are triggered by events that occur across all projects in the group.
This feature is enabled only for Premium / EE / Gold paid plan, but it can be replaced with GitLab Packagist Integration

You must manually set up this integration.

[![Gitlab](img/gitlab.png)](img/gitlab.png)

Where token you can find on the packeton integration view page. The token must have `whk` prefix 
to find related integration access token.

### Gitea

Scopes:

- login: `read:user`
- repositories: `organization`, `repository`, `write:issue`

Redirect Urls:

```
https://example.com/oauth2/{alias}/auto
```

### Bitbucket

Scopes:

- repositories & login: `account`, `webhook`, `team`, `project`, `pullrequest`

Redirect Urls:

```
https://example.com/oauth2/{alias}/auto
```
