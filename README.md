# Plandalf for MemberPress

Sell MemberPress memberships through Plandalf checkout. The plugin connects to
Plandalf's public API and receives signed purchase events to grant membership access.

## Requirements

- WordPress 6.4 or later and PHP 8.2 or later.
- An active MemberPress installation (local checks used MemberPress 1.12.6).
- A Plandalf account with a connected Stripe account.
- An HTTPS WordPress site reachable by Plandalf for purchase-event delivery.

## Installation

Download the **plandalf-memberpress ZIP release asset** and upload it through
**WordPress → Plugins → Add New → Upload Plugin**. GitHub's automatic source archives
include development files; use the packaged asset for installation.

Activate the plugin, open **MemberPress → Plandalf**, connect your account, choose a
checkout design, and create or link the prices that grant each membership.

[Integration guide](https://plandalf.com/docs/product-guides/platforms/memberpress)

## Development

This repository is the standalone plugin source. From its root:

```sh
npm test
npm run build
```

No npm dependencies are needed. The build requires Node.js 22 or later and `zip`.
It writes `dist/plandalf-memberpress.zip` and a versioned copy. Only runtime files
are packaged; tests, development instructions and build tooling are excluded.

To use the checkout in a development WordPress installation:

```sh
ln -s "$PWD" /path/to/wordpress/wp-content/plugins/plandalf-memberpress
```

The PHP integration suite requires WordPress and MemberPress. Run it from that
WordPress installation with this repository installed as the active plugin:

```sh
wp eval-file wp-content/plugins/plandalf-memberpress/tests/run.php
```

Each test rolls back its database transaction. API requests are intercepted and
outgoing mail is captured. These tests do not establish hosted API compatibility,
real email delivery or a completed buyer journey. CI runs PHP syntax checks,
JavaScript tests and packaging; it does not install the commercial MemberPress plugin.

## Release status

Version 0.1.0 is being prepared for early access. Before publishing a supported
release, verify the target Plandalf deployment includes site connection, catalog
links, subscription and webhook APIs. Complete a clean installation, test purchase,
password setup, email delivery and protected-content access against that deployment.

Campaign mappings currently report `checkout_enabled: false`; timer-driven membership
pricing is not ready. The plugin skips post-payment upsell pages. Existing MemberPress
subscriptions continue through their original payment gateway.

## Releasing

Update `Version:` and `PLANDALF_MEPR_VERSION` in `plandalf-memberpress.php`,
`Stable tag:` and the changelog in `readme.txt`, and the package version in
`package.json`. The builder rejects mismatched versions.

Run the checks above, then attach the two ZIP files and `SHA256SUMS` from `dist/`
to a GitHub release for the reviewed commit. Keep the release as a draft until the
hosted setup and buyer journey checks have passed.

## License

GPLv2 or later, as declared in the plugin header and WordPress readme.
