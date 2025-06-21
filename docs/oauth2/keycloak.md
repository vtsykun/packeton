# Keycloak Integration Setup

Go to your Keycloak admin console (`https://keycloak.example.com/admin/`) and select your realm. Then navigate to "Clients" and click "Create".

Set up a new client with the following settings:
- Client ID: (choose a name for your client)
- Client Protocol: openid-connect
- Root URL: (your Packeton URL)
- Valid Redirect URIs:

```
https://example.com/oauth2/keycloak/check
```

Go to the "Credentials" tab to obtain the `Client Secret`.

## Environment Variables

You can configure the Keycloak integration using environment variables, either in your Docker setup or by adding them to your project's `.env` file:

```
# Keycloak SSO
OAUTH_KEYCLOAK_CLIENT_ID=your-client-id
OAUTH_KEYCLOAK_CLIENT_SECRET=your-client-secret
OAUTH_KEYCLOAK_BASE_URL=https://keycloak.example.com
OAUTH_KEYCLOAK_REALM=your-realm
```