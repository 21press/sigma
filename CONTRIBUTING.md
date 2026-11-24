# Contributing to Sigma

Thanks for helping. Sigma is a WordPress plugin (GPL-2.0-or-later); by contributing you agree your work is released under the same license.

## Set up
1. Node 22 and PHP 8.2+ with Composer.
2. `npm install`, then `npm run test:setup` (installs the PHP test tools).
3. `npm run build` builds the admin app and the widget into `build/`.
4. Run it on a local WordPress (6.9+): symlink or copy the plugin folder into `wp-content/plugins/sigma` and activate it. Without an AI key, choose the test provider in Settings (local and development sites only).

## Before you open a pull request
- `npm test`, `npm run test:int` (or `test:int:local`), `npm run lint:php`, and the widget and admin tests for the parts you touched.
- `npm run audit` passes (no known vulnerabilities, dev dependencies included).
- Add a line to `CHANGELOG.md` for anything a user would notice.
- Follow [AGENTS.md](AGENTS.md).

## Reporting a security issue
Please don't open a public issue. Report it privately through the repository's **Security** tab → **Report a vulnerability**, with the details and how to reproduce it.
