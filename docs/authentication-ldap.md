# Authenticating against an LDAP server

You can enable LDAP authenticating only on configuration level.

Packeton has pre-installed Symfony LDAP component. Add the file `config/packages/ldap.yaml` to enable LDAP 
with following content. See LDAP in [Symfony Docs](https://symfony.com/doc/current/security/ldap.html)

```yaml
parameters:
    default_login_provider: 'form_login_ldap'
    default_login_options:
        provider: all_users
        login_path: /login
        use_forward: false
        check_path: /login
        failure_path: null
        service: Symfony\Component\Ldap\Ldap
        dn_string: 'uid={username},dc=example,dc=com'

services:
    Symfony\Component\Ldap\Ldap:
        arguments: ['@Symfony\Component\Ldap\Adapter\ExtLdap\Adapter']
        tags:
            - ldap

    Symfony\Component\Ldap\Adapter\ExtLdap\Adapter:
        arguments:
            -   host: ldap.forumsys.com
                port: 389

security:
    providers:
        users_ldap:
            ldap:
                service: Symfony\Component\Ldap\Ldap
                base_dn: dc=example,dc=com
                search_dn: "cn=read-only-admin,dc=example,dc=com"
                search_password: password
                default_roles: ROLE_MAINTAINER
                uid_key: uid

        all_users:
            chain:
                providers: ['packagist', 'users_ldap']
```

Here is working example where used test `ldap.forumsys.com` server https://www.forumsys.com/2022/05/10/online-ldap-test-server/

Using LDAP integration does not prevent you from creating user manually from CLI and assign more accessible roles.
At the same LDAP password validation will be done on LDAP server side, because `CheckLdapCredentialsListener` has higher priority 
loading than default check listener. Therefore, if user is not enable in LDAP - it will not able login to packeton. 

## User providers priority.

Packeton use Symfony [Chain User Provider](https://symfony.com/doc/current/security/user_providers.html#chain-user-provider)
to lookup users.

If you want to use customer user restriction by vendors and versions, `packagist` user provider must load before ldap.

```yaml
security:
    providers:
        users_ldap:
            ldap:
                ... 
 
        all_users:
            chain:
                providers: ['packagist', 'users_ldap'] # Load user/roles form default packagist and if not found - use ldap user
                providers: ['users_ldap', 'packagist'] # packagist users will be ignore 
```

## Load different roles from LDAP. 

You can use more 1 user providers:

```yaml
security:
    providers:
        users_ldap:
            ldap:
                service: Symfony\Component\Ldap\Ldap
                base_dn: dc=example,dc=com
                search_dn: "cn=read-only-admin,dc=example,dc=com"
                filter: "(&(objectclass=groupOfUniqueNames)(ou=scientists)(uniqueMember=uid={username},dc=example,dc=com))"
                search_password: password
                default_roles: ROLE_MAINTAINER
                uid_key: uid

        users_ldap_admin:
            ldap:
                service: Symfony\Component\Ldap\Ldap
                base_dn: dc=example,dc=com
                search_dn: "cn=read-only-admin,dc=example,dc=com"
                filter: "(&(objectclass=groupOfUniqueNames)(ou=mathematicians)(uniqueMember=uid={username},dc=example,dc=com))"
                search_password: password
                default_roles: ROLE_ADMIN
                uid_key: uid

        all_users:
            chain:
                providers: ['packagist', 'users_ldap', 'users_ldap_admin']
```

Here test example where exists two Groups (ou) that include:

 * ou=mathematicians,dc=example,dc=com - assign role ROLE_ADMIN
 * ou=scientists,dc=example,dc=com - assign role ROLE_MAINTAINER

## API authentication with LDAP users.

By default, packeton is storage api token in database for each user. 
But if the user was loaded by custom external users' provider, but from the database, you will need enable JWT configuration.
See [JWT Configuration](authentication-jwt.md)

## Enable LDAP for docker runtime.

You can use docker volume to share own configuration to application.

```
...
        volumes:
            - .docker:/data
            - ${PWD}/ldap.yaml:/var/www/packagist/config/packages/ldap.yaml
```
