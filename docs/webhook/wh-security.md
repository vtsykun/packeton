# Webhook security

To evaluate expressions uses a Twig sandbox mode.

You will get an error if try to get access for security sensitive information.

**SSRF** - To prevent SSRF attacks to make HTTP requests to inner private networks uses `NoPrivateNetworkHttpClient`,
so not possible call url like `http://10.8.100.1/` etc.

```
# This code is not works.

{% set text = "*New Releases*\n" %}
{% set title = package.name ~ ' (' ~ versions|map(v => "#{v.version}")|join(',') ~ ')' %}

{% set text = text ~ package.credentials.key  %}

{% set request = {
    'channel': 'jenkins',
    'text': text
} %}

{{ request|json_encode }}

```

```
Exception (Twig\Sandbox\SecurityNotAllowedMethodError). Calling "getcredentials" method on a "Packeton\Entity\Package" 
object is not allowed in "__string_template__4b1d9dd7416b75a6c353bd4750fe5490" at line 4.
```

Block SSRF
```
Exception (Symfony\Component\HttpClient\Exception\TransportException). IP "127.0.0.1" is blocked for "https://pack.loc.example.org/webhooks".
 * Prev exception (Symfony\Component\HttpClient\Exception\TransportException). IP "127.0.0.1" is blocked for "https://pack.loc.example.org/webhooks".
```
