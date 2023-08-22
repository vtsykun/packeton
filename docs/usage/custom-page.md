# Custom landing page

If you are distributing packages to your customers, 
you may want to create a separate domain for Composer metadata-only to hide 
the default web interface and login page.

Add following lines to you configuration. `config.yaml or config/packages/*.yaml`

```yaml
packeton:
    web_protection:
        ## Multi host protection, disable web-ui if host !== app.example.com and ips != 127.0.0.1, 10.9.1.0/24
        ## But the repo metadata will be available for all hosts and ips.
        repo_hosts: ['*', '!app.example.com']
        allow_ips: '127.0.0.1, 10.9.1.0/24'
        status_code: 402
        custom_page: > # Custom landing non-auth page. Path or HTML
            <html>
            <head><title>402 Payment Required</title></head>
            <body>
            <center><h1>402 Payment Required</h1></center>
            <hr><center>nginx</center>
            </body>
            </html>
```

Where `custom_page` html content or path to html page.

Here all hosts will be hidden under this page (if ip is not match or host != app.example.com). 

`app.example.com` - this is host for default Web-UI.

### Example 2

```yaml
    web_protection: 
        repo_hosts: ['repo.example.com']
```

Here Web-UI will be hidden for `repo_hosts` host `repo.example.com`.
