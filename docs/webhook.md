# Generic Packeton webhooks

Webhooks allow external services to be notified when certain events happen. 
When the specified events happen, packeton'll send a POST request to each of the URLs you provide.
Now is supported the next events:

- new_release
- update_release
- delete_release
- push_new_event
- update_new_event
- update_repo_failed
- new_repo
- delete_repo

![diagram](img/diagram.png)

Examples 
=========

 - [Telegram notification](#telegram-notification)
 - [Slack notification](#slack-notification)
 - [How to use url placeholder](#use-url-placeholder)
 - [Interrupt request](#interrupt-request)

Telegram notification
---------------------

POST https://api.telegram.org/bot$TELEGRAM_TOKEN/sendMessage

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

POST https://slack.com/api/chat.postMessage

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

http://httpbin.org/{{ method }}?repo={{ repoName }}

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

http://httpbin.org/{{ method }}?repo={{ repoName }}

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
