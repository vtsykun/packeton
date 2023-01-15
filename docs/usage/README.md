# Administration

This section contains information on managing your Packeton application. 

Table of content
----------------

- [API Usage](api.md)
- [Outgoing Webhook](../webhook.md)
    - [Intro](../webhook.md#introduction)
    - [Examples](../webhook.md#examples)
        - [Telegram notification](../webhook.md#telegram-notification)
        - [Slack notification](../webhook.md#slack-notification)
        - [JIRA issue fix version](../webhook.md#jira-create-a-new-release-and-set-fix-version)
        - [Gitlab setup auto webhook](../webhook.md#gitlab-auto-webhook)
- [Application Roles](#application-roles)


## Application Roles

- `ROLE_USER` - minimal customer access level, these users only can read metadata only for selected packages.
- `ROLE_FULL_CUSTOMER` - Can read all packages metadata.
- `ROLE_MAINTAINER` -  Can submit a new package and read all metadata.
- `ROLE_ADMIN` - Can create a new customer users, management webhooks and credentials.

You can create a user and then promote to admin or maintainer via console using fos user bundle commands.

```
php bin/console packagist:user:manager username --email=admin@example.com --password=123456 --admin # create admin user
php bin/console packagist:user:manager user1 --add-role=ROLE_MAINTAINER # Add ROLE_MAINTAINER to user user1
```
