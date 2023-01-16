# Configuration Reference

Full packeton configuration:

```yaml

packeton:
    github_no_api: '%env(bool:GITHUB_NO_API)%' # default true
    rss_max_items: 30
    archive: true
    
    anonymous_access: '%env(bool:PUBLIC_ACCESS)%' # default false
    anonymous_archive_access: '%env(bool:PUBLIC_ACCESS)%' # default false
    archive_options:
        format: zip
        basedir: '%env(resolve:PACKAGIST_DIST_PATH)%'
        endpoint: '%env(PACKAGIST_DIST_HOST)%' # default auto detect by host headers 
        include_archive_checksum: false

    jwt_authentication: # disable by default 
        algo: EdDSA
        private_key: '%kernel.project_dir%/var/jwt/eddsa-key.pem'
        public_key: '%kernel.project_dir%/var/jwt/eddsa-public.pem'
        passphrase: ~

```
