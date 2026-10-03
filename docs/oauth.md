# MemberPress OAuth connection

The 0.2.0 development build requires the matching Plandalf OAuth backend. It is not yet a hosted production release.

## Merchant flow

1. Open **MemberPress → Plandalf → Connect with Plandalf**.
2. Sign in to Plandalf, select the account and purchase mode, and review its domain.
3. Authorize MemberPress. The browser returns to WordPress.
4. WordPress obtains the authorized account, host, domain and SDK URL from Plandalf and registers its purchase-event endpoint.
5. Choose a checkout design and link membership prices.

There is no manual API-key or host form. Existing 0.1.0 installations must reconnect. Reconnection requires the same account and purchase mode; changing either requires an explicit disconnect first.

## Protocol

- WordPress sends `/connect/site` its site URL, exact callback, random state and an S256 PKCE challenge. State belongs to the initiating administrator and expires after 15 minutes. The verifier is encrypted in the transient.
- Account selection creates a public Passport authorization-code client with an exact redirect URI. Its connection record binds the consenting user, organization, site and purchase mode.
- Passport supplies the authorization code. WordPress validates state and exchanges the code at the original issuer's `/oauth/token`, with its PKCE verifier. No client secret is distributed in the plugin.
- `GET /api/v1/site-connection` returns authoritative account and host metadata. WordPress checks the client ID, site URL, issuer, mode and matching host/SDK origins before storing it.
- Access and refresh tokens use AES-256-GCM with a key derived from the WordPress authentication salt, in a non-autoloaded option. Keep WordPress configuration and backups protected; changing authentication salts requires reconnecting.
- Expiring access tokens refresh at the original issuer. Requests fail on refresh errors; they never fall back to API keys.
- `POST /api/v1/site-connection/identity` signs a five-minute customer token on Plandalf. Signing secrets never leave Plandalf. Identity failure prevents a signed-in buyer's checkout from loading as a guest.
- `DELETE /api/v1/site-connection` revokes the client, access/refresh tokens, server-side identity key and registered webhooks. A failed disconnect preserves credentials so the merchant can retry.

The backend must bind API access to the connection's organization, not the user's current dashboard organization. It must restrict scope, webhook ownership and webhook delivery to the authorized site. API keys used by unrelated developer clients remain a separate backend capability.

## Development and release

The hosted issuer is `https://admin.plandalf.dev`. A developer can set `PLANDALF_MEPR_OAUTH_ISSUER` in WordPress configuration for a local backend; merchants do not enter it in the admin UI. Host and SDK metadata must still come from the authorized backend response.

Backend release prerequisites include the `site_oauth_connections` migration, account-selection consent, the Passport site scope, connection metadata/identity/revocation endpoints, and connection-bound API authentication. Deploy these together before releasing the new plugin ZIP.

Local verification covers the real Passport authorization-code exchange, wrong-verifier rejection, single-use codes, token refresh/revocation, account switching, wrong-user/scope rejection and webhook isolation. Plugin tests cover encrypted storage, metadata mismatches, refresh failures, reconnect mode protection and removal of manual setup. A full browser replay and buyer checkout against the intended account remain release gates.
