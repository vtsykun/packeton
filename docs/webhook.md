# Generic Packeton webhooks

Introduction
----------
Webhooks allow external services to be notified when certain events happen. 
When the specified events happen, packeton will send a POST request to each of the URLs you provide.
Now is supported the next events:

- new_release
- update_release
- delete_release
- push_new_event
- update_new_event
- http_request
- update_repo_failed
- new_repo
- delete_repo

![diagram](img/diagram.png)

It may be useful for release/deploy process, for example: Automatically create new Jira release 
when a new version is created in packagist (triggered when new tag is created in bitbucket) and 
update "fix version" attribute of all the related issues from that release.


To build a custom request payload uses Twig expression language.
This allows you to create custom queries. Untrusted template code is evaluate in a Twig sandbox mode, so 
you will get an error if try to get access for security sensitive information.
By default only admin users can use Webhooks.

```
Exception (Twig\Sandbox\SecurityNotAllowedMethodError). Calling "setemail" method on a "Packagist\WebBundle\Entity\User" object is not allowed in "__string_template__0d2344b042278505e67568413272d80429f07ecccea43af39cb33608fa747830" at line 1.
```

Examples 
========
 
 - [List of twig variables](#twig-variables)
 - [Telegram notification](#telegram-notification)
 - [Slack notification](#slack-notification)
 - [How to use url placeholder](#use-url-placeholder)
 - [Interrupt request](#interrupt-request)
 - [Nesting webhook](#nesting-webhook)
 - [Gitlab setup auto webhook](#gitlab-auto-webhook)
 - [JIRA issue fix version](#jira-create-a-new-release-and-set-fix-version)
 - [Accept external request](#external-request)
 - [Packeton twig function](#new-twig-functions)

Twig variables
---------------

- package - `Package` entity. 
- versions - `Versions[]` array of versions.
- webhook - `Webhook` current webhook entity.
- user    - `User` entity. Only for user login event.
- parentResponse - `HookResponse` object. Only for nesting webhook.
- request - `array` Only for http request event.

Telegram notification
---------------------

POST `https://api.telegram.org/bot$TELEGRAM_TOKEN/sendMessage`

Options:
```json
{
    "headers": {
        "Content-Type": "application/json"
    }
}
```

Payload

```twig
{% set text = "*New Releases*\n" %}
{% set title = package.name ~ ' (' ~ versions|map(v => "#{v.version}")|join(',') ~ ')' %}
{% set text = text ~ "[" ~ title ~ "](https://pkg.okvpn.org/packages/" ~ package.name ~ ")\n" %}
{% set text = text ~ package.description  %}

{% set request = {
    'chat_id': '-1000006111000',
    'parse_mode': 'Markdown',
    'text': text
} %}

{{ request|json_encode }}
```

![Telegram](img/telegram.png)

Slack notification
------------------
In first you need create a [slack app](https://api.slack.com/apps)

POST `https://slack.com/api/chat.postMessage`

Options:

```json
{
    "headers": {
        "Content-Type": "application/json",
        "Authorization": "Bearer xoxp-xxxxxxxxxxxxxxxxxxxxxxxxxx"
    }
}
```

Payload

```twig
{% set text = "*New Releases*\n" %}
{% set title = package.name ~ ' (' ~ versions|map(v => "#{v.version}")|join(',') ~ ')' %}
{% set text = text ~ "<https://pkg.okvpn.org/packages/" ~ package.name ~ "|" ~ title ~ ">\n" %}
{% set text = text ~ package.description  %}

{% set request = {
    'channel': 'jenkins',
    'text': text
} %}

{{ request|json_encode }}
```

Use url placeholder
-------------------

The placeholder allow to build URL parameters from twig template.

`http://httpbin.org/{{ method }}?repo={{ repoName }}`

*Syntax*

Use `placeholder` tag 
```
URL: http://httpbin.org/post?repo={{ paramName }}

Variant 1. Send single request.

{% placeholder <paramName> with <string> %}

Variant 2. Send many request for each value from array string[]

{% placeholder <paramName> with <string[]> %}
```

Example

`http://httpbin.org/{{ method }}?repo={{ repoName }}`

Payload

```twig
{% placeholder method with 'post' %}
{% placeholder repoName with [package.name, 'test/test'] %}
```

![URL Placeholder](img/placeholder.png)

Interrupt request
-----------------

You can interrupt request if condition is not pass

*Syntax*

Use `interrupt` function.

Payload

```twig
{% set request = {
    'chat_id': '1555151',
    'parse_mode': 'Markdown',
    'text': 'Text'
} %}

{% if package.name == 'okvpn/mq-insight' %}
    {{ interrupt() }}
{% endif %}

{{ request|json_encode }}
```

Nesting webhook
---------------

You can trigger webhook from twig code. It may be used for send two requests a one event.

*Syntax*

Use `trigger_webhook(hookId: int|Webhook, context: array)` function.

Example Payload

```twig
{% do trigger_webhook(6, {'project': 'OK', 'version': versions[0].version}) %}
```

Jira create a new release and set fix version
---------------------------------------

You need to create two webhook for its, the first must triggers on a new release event, 
the second will be called from twig code.

![Jira](img/jira.png)

#### Create a new release in JIRA

POST `https://jiraserver/rest/api/2/version`

Options:

```json
{
    "headers": {
        "Content-Type": "application/json"
    },
    "auth_basic": "jirauser:password"
}
```

Payload

```twig
{% set changeLog = get_changelog(package, null, versions[0].version) %}
{% set ticket = preg_match_all('/((OK|OTEK)-(\\d+))\\s*:/u', changeLog|join(';'), 1) %}

{% set request = {
    'archived': false,
    'releaseDate': versions[0].releasedAt|date('Y-m-d'),
    'name': versions[0].version,
    'released': true,
    'description': 'Packagist auto release',
    'project': 'OK'
} %}

{% if ticket|length == 0 %}
    {{ interrupt('There are not commits with JIRA tiket no.') }}
{% endif %}

{% do trigger_webhook(6, {'project': 'OK', 'ticket': ticket, 'version': versions[0].version}) %}
{{ request|json_encode }}
```

#### Update an issue fix version

PUT `https://jiraserver/rest/api/2/issue/{{ issue }}`

Options:

```json
{
    "headers": {
        "Content-Type": "application/json"
    },
    "auth_basic": "jirauser:password"
}
```
Payload

```twig
{% placeholder issue with ticket %}

{% set request = {
    'fields': {
        'fixVersions': [{'name': version}]
    }
} %}

{{ request|json_encode }}
```

![Jira issue](img/jira_response.png)

Http request from code
---------------------

You can made http request from twig code.

```twig
{% set tags = http_request('https://registry.hub.docker.com/v1/repositories/okvpn/orocommerce/tags') %}

{{ tags|json_encode }}
```

External request
----------------
Triggered webhook by HTTP requests to `https://PACKEGIST_URL/api/webhook-invoke/{name}`

Optional `name`. If it is specified then this webhook can only be triggered if that name is supplied 
when invoking `https://PACKEGIST_URL/api/webhook-invoke/{name}` and name restriction is match.

Example payload:

```twig
{% if request.packageName is null %}
    {{ interrupt('package name is not found') }}
{% endif %}

{% set tags = http_request('https://registry.hub.docker.com/v1/repositories/' ~ request.packageName ~'/tags') %}

{{ tags|json_encode }}
```

Gitlab auto webhook
-------------------
You can use the event `new_repo` to add a hook to the specified Gitlab project.
It can be useful, if you don't have a Gold Gitlab Plan that allows configure webhooks for your group,
so you need add it manually for each a new repository.

POST `https://{{ host }}/api/v4/projects/{{ repo }}/hooks?private_token=xxxxxxxxxxxxxxxx`

Options:

```json
{
    "headers": {
        "Content-Type": "application/json"
    }
}
```

Payload

```twig
{% set regex = '#^(?:ssh://git@|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\\w.-]+(?:/[\\w.-]+?)+)(?:\\.git|/)?$#i' %}
{% set repository = preg_match_all(regex, package.repository) %}

{% if repository.path[0] is null or repository.host[0] is null %}
    {{ interrupt('Regex is not match') }}
{% endif %}

{% set request = {
    'url': 'https://pkg.okvpn.org/api/update-package?token=admin:xxxxxxxxxxxxxxxx',
    'push_events': true,
    'tag_push_events': true
} %}

{% placeholder host with repository.host[0] %}
{% placeholder repo with repository.path[0]|url_encode %}

{{ request|json_encode }}
```

Here you need replace `request.url` on your packagist. 

New twig functions
-----------------

See [WebhookExtension](/src/Packagist/WebBundle/Webhook/Twig/WebhookExtension.php) for details.
