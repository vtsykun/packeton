# Generic OIDC Provider

Generic OpenID Connect (OIDC) provider for SSO login with any OIDC-compliant identity provider:
Authentik, Keycloak, Azure AD, Okta, Auth0, and others.

**Note**: This is a login-only provider. It does not support repository synchronization.

## Configuration

```yaml
packeton:
    integrations:
        authentik:  # Alias name - can be any URL-safe value
            allow_login: true
            allow_register: true
            default_roles: ['ROLE_USER', 'ROLE_MAINTAINER']
            login_title: 'Login with Authentik'
            oidc:
                client_id: 'packeton-client-id'
                client_secret: 'packeton-client-secret'
                issuer: 'https://auth.example.com/application/o/packeton/'
```

## Configuration Options

| Option | Required | Default | Description |
|--------|----------|---------|-------------|
| `client_id` | Yes | - | OAuth2 client ID |
| `client_secret` | Yes | - | OAuth2 client secret |
| `issuer` | Yes* | - | OIDC issuer URL (discovery URL is derived from this) |
| `discovery_url` | Yes* | - | Explicit OIDC discovery URL (alternative to issuer) |
| `scopes` | No | `['openid', 'email', 'profile']` | OIDC scopes to request |
| `require_email_verified` | No | `true` | Reject login if `email_verified` claim is false |
| `claim_mapping` | No | See below | Map OIDC claims to user fields and roles |

*Either `issuer` or `discovery_url` must be provided.

## Redirect URL

Configure this redirect URL in your identity provider:

```
https://example.com/oauth2/{alias}/check
```

Where `{alias}` is the integration name (e.g., `authentik`, `keycloak`).

## Claim Mapping

The provider maps standard OIDC claims to Packeton user fields. Configure `claim_mapping` for providers with non-standard claim names:

```yaml
oidc:
    claim_mapping:
        email: 'email'                    # Default: email
        username: 'preferred_username'    # Default: preferred_username
        sub: 'sub'                        # Default: sub
```

| Mapping Key | Default OIDC Claim | Packeton Field |
|-------------|-------------------|----------------|
| `email` | `email` | User email / identifier |
| `username` | `preferred_username` | Username (falls back to email prefix) |
| `sub` | `sub` | External ID (prefixed with provider name) |

## Role Mapping

Map OIDC groups/roles claims directly to Packeton roles. This provides an alternative to `login_control_expression` for simple role assignment.

```yaml
packeton:
    integrations:
        keycloak:
            allow_login: true
            allow_register: true
            login_title: 'Login with Keycloak'
            oidc:
                client_id: 'packeton'
                client_secret: 'secret'
                issuer: 'https://keycloak.example.com/realms/myrealm'
                scopes: ['openid', 'email', 'profile', 'groups']
                claim_mapping:
                    roles_claim: 'groups'
                    roles_map:
                        'packeton-admins': ['ROLE_ADMIN', 'ROLE_MAINTAINER']
                        'packeton-maintainers': ['ROLE_MAINTAINER']
                        'packeton-users': ['ROLE_USER']
```

### Role Mapping Options

| Option | Description |
|--------|-------------|
| `roles_claim` | Claim containing user roles/groups (e.g., `groups`, `roles`, `realm_access.roles`) |
| `roles_map` | Map OIDC groups to Packeton roles |

### Behavior

| Scenario | Result |
|----------|--------|
| User has `packeton-admins` group | Gets `ROLE_ADMIN`, `ROLE_MAINTAINER` |
| User has multiple mapped groups | Roles are merged (union) |
| User has no mapped groups | Falls back to `default_roles` |
| `roles_claim` not configured | Role mapping disabled, uses `default_roles` |
| Claim doesn't exist in token | Falls back to `default_roles` |

## Provider-Specific Setup

### Authentik

1. Create an OAuth2/OIDC Provider in Authentik admin
2. Set the redirect URI to `https://your-packeton.com/oauth2/{alias}/check`
3. Use issuer format: `https://auth.example.com/application/o/{app-slug}/`

```yaml
authentik:
    allow_login: true
    allow_register: true
    login_title: 'Login with Authentik'
    oidc:
        client_id: 'your-client-id'
        client_secret: 'your-client-secret'
        issuer: 'https://auth.example.com/application/o/packeton/'
```

### Keycloak

1. Create a "Confidential" client with Standard Flow enabled
2. Add redirect URI in client settings
3. Use issuer format: `https://keycloak.example.com/realms/{realm-name}`

```yaml
keycloak:
    allow_login: true
    allow_register: true
    login_title: 'Login with Keycloak'
    oidc:
        client_id: 'packeton'
        client_secret: 'your-client-secret'
        issuer: 'https://keycloak.example.com/realms/myrealm'
        scopes: ['openid', 'email', 'profile', 'groups']
        claim_mapping:
            roles_claim: 'groups'
            roles_map:
                'packeton-admins': ['ROLE_ADMIN']
                'packeton-maintainers': ['ROLE_MAINTAINER']
```

### Azure AD / Entra ID

1. Register an application in Azure Portal
2. Add redirect URI in the application settings
3. Use issuer format: `https://login.microsoftonline.com/{tenant-id}/v2.0`
4. May need additional scopes for email access

```yaml
azure:
    allow_login: true
    login_title: 'Login with Microsoft'
    oidc:
        client_id: 'your-application-id'
        client_secret: 'your-client-secret'
        issuer: 'https://login.microsoftonline.com/{tenant-id}/v2.0'
```

### Okta

1. Create a Web application in Okta admin
2. Use issuer format: `https://{domain}.okta.com` or custom authorization server

```yaml
okta:
    allow_login: true
    login_title: 'Login with Okta'
    oidc:
        client_id: 'your-client-id'
        client_secret: 'your-client-secret'
        issuer: 'https://dev-xxxxx.okta.com'
```
