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

    metadata:
        format: auto # Default, see about metadata.
        info_cmd_message: ~ # Bash logo, example - \u001b[37;44m#StandWith\u001b[30;43mUkraine\u001b[0m

    artifacts:
        support_types: ['gz', 'tar', 'tgz', 'zip']
        allowed_paths:
            - '/data/hdd1/composer'
        # Default path to storage/(local cache for S3) of uploaded artifacts
        artifact_storage: '%composer_home_dir%/artifact_storage'

    integrations: # See oauth2 integrations
        alias_name: # Alias name ()
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
    
            gitlab:
                client_id: 'xxx'
                client_secret: 'xxx'
                api_version: 'v4'
    
            githubapp:
                private_key: '%kernel.project_dir%/var/packeton-private-key.pem'
                passphrase: ~ # private key pass
                app_id: 345472

            gitea:
                client_id: '44000000-0000-0000-0000-00000000000'
                client_secret: 'gto_acxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'

            bitbucket:
                key: GA7000000000000000
                secret: 9chxxxxxzxxxxxxxxeexxxxxxxxxxxxx
                api_version: ~ # '/example/rest/v2/' custom api prefix

    # See mirrors section
    mirrors:
        packagist:
            url: https://repo.packagist.org
        orocrm:
            url: https://satis.oroinc.com/
            git_ssh_keys:
                git@github.com:oroinc: '/var/www/.ssh/private_key1'
                git@github.com:org2: '/var/www/.ssh/private_key2'
        example:
            url: https://satis.example.com/
            logo: 'https://example.com/logo.png'
            http_basic:
                username: 123
                password: 123
            public_access: true # Allow public access, default false
            sync_lazy: true # default false 
            enable_dist_mirror: false # default true
            available_package_patterns: # Additional restriction, but you can restrict it in UI
                - 'vend1/*'
            available_packages:
                - 'pack1/name1' # but you can restrict it in UI
            composer_auth: '{"auth.json..."}' # JSON. auth.json to pass composer opts.
            sync_interval: 3600 # default auto.
            info_cmd_message: "\n\u001b[37;44m#Слава\u001b[30;43mУкраїні!\u001b[0m\n\u001b[40;31m#Смерть\u001b[30;41mворогам\u001b[0m" # Info message

```
