# Using a reverse proxy

It is recommended to put a reverse proxy such as nginx, Apache for docker installation.

### Reverse-proxy configuration examples

#### nginx

```
server {
    listen *:443 ssl http2;

    server_name pkg.example.gob.ar;

    ssl_certificate /etc/nginx/ssl/gob.ar.crt;
    ssl_certificate_key /etc/nginx/ssl/gob.ar.key;
    ssl_ciphers 'ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-SHA384:ECDHE-RSA-AES128-SHA256:ECDHE-RSA-AES256-SHA:ECDHE-RSA-AES128-SHA:ECDHE-RSA-DES-CBC3-SHA:AES256-GCM-SHA384:AES128-GCM-SHA256:AES256-SHA256:AES128-SHA256:AES256-SHA:AES128-SHA:DES-CBC3-SHA:!aNULL:!eNULL:!EXPORT:!DES:!MD5:!PSK:!RC4';

    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_buffers 16 16k;
    gzip_http_version 1.1;
    gzip_min_length 2048;
    gzip_types text/css application/javascript text/javascript application/json;
    access_log  off;

    location / {
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_pass http://127.0.0.1:8082/;
    }
}
```

#### nginx with cloudflare

```
server {
    listen *:80;

    server_name demo.packeton.org;

    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_buffers 16 16k;
    gzip_http_version 1.1;
    gzip_min_length 2048;
    gzip_types text/css application/javascript text/javascript application/json;

    set_real_ip_from 173.245.48.0/20;
    set_real_ip_from 103.21.244.0/22;
    set_real_ip_from 103.22.200.0/22;
    set_real_ip_from 103.31.4.0/22;
    set_real_ip_from 141.101.64.0/18;
    set_real_ip_from 108.162.192.0/18;
    set_real_ip_from 190.93.240.0/20;
    set_real_ip_from 188.114.96.0/20;
    set_real_ip_from 197.234.240.0/22;
    set_real_ip_from 198.41.128.0/17;
    set_real_ip_from 162.158.0.0/15;
    set_real_ip_from 104.16.0.0/13;
    set_real_ip_from 104.24.0.0/14;
    set_real_ip_from 172.64.0.0/13;
    set_real_ip_from 131.0.72.0/22;
    real_ip_header CF-Connecting-IP;
    add_header Access-Control-Allow-Origin *;

    location / {
        proxy_http_version 1.1;
        proxy_buffering off;
        proxy_set_header Host $http_host;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_pass http://127.0.0.1:8082/;
    }
}
```

#### Apache

```
<VirtualHost *:443>
    ServerName pack1.loc.example.ovh
    SSLEngine on
    SSLCertificateFile /etc/nginx/ssl/example.crt
    SSLCertificateKeyFile /etc/nginx/ssl/example.key

    Protocols h2 http/1.1
    ProxyRequests on
    ProxyPreserveHost On
    ProxyPass / http://127.0.0.1:8082/
    ProxyPassReverse / http://127.0.0.1:8082/

    SSLProxyEngine On
    SSLProxyVerify none
    SSLProxyCheckPeerCN off
    SSLProxyCheckPeerName off
    SSLProxyCheckPeerExpire off

    RequestHeader set "X-Forwarded-Proto" expr=%{REQUEST_SCHEME}
</VirtualHost>
```
