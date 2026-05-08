# TaskShunt

Staging-to-production content deployment for WordPress. Track changes on a staging site and push them to production over a REST API — no full-site migrations, no database dumps.

> **Note:** This `README.md` is the developer-facing documentation for the GitHub repository. The user-facing description published to WordPress.org lives in [`readme.txt`](readme.txt).

## Requirements

- PHP **8.2+**
- WordPress **6.0+**
- Node.js **18+** and npm (for building front-end assets)
- Composer 2

## Quick start

```bash
git clone git@github.com:Liviu-p/stagify.git taskshunt
cd taskshunt
composer install
npm install
npm run build
```

Symlink or copy the directory into a WordPress install at `wp-content/plugins/taskshunt/` and activate it from the admin.

## Repository layout

```
admin/        Admin UI: pages, list tables, AJAX actions, dashboard widget
api/          REST receiver and per-item handlers (Content / Environment / File)
includes/     Domain layer, services, repositories, hook manager, DI bootstrap
assets/       Source TypeScript and SCSS, plus the compiled output webpack writes
scripts/      Release packaging shell scripts
tests/        PHPUnit (Unit + Integration) and Cypress (e2e) suites
```

Service wiring lives in [`includes/bootstrap.php`](includes/bootstrap.php) (PHP-DI). The plugin chooses between **Sender** and **Receiver** mode at runtime — see [`includes/Plugin.php`](includes/Plugin.php).

## Two modes

The same plugin runs on both sides of a deployment and switches behavior based on the mode chosen during setup:

- **Sender** — tracks content/file/environment changes against the active task and POSTs them to the receiver.
- **Receiver** — exposes the `taskshunt/v1/receive` REST route, authenticates on the `X-TaskShunt-API-Key` header, and applies each item via its handler.

## npm scripts

| Script | What it does |
| --- | --- |
| `npm run build` | Production webpack build (TS + SCSS → `assets/build`). |
| `npm run dev` | Development build with watcher. |
| `npm run zip` | Build a WordPress.org-ready zip (`taskshunt.zip`). |
| `npm run test:e2e` | Open the Cypress e2e runner. |
| `npm run test:e2e:run` | Run the Cypress suite headlessly. |

## PHP tooling

```bash
# Run linting (WordPress + WordPress VIP + Slevomat standards)
vendor/bin/phpcs --standard=phpcs.xml .

# Auto-fix what can be fixed
vendor/bin/phpcbf --standard=phpcs.xml .

# Unit tests
vendor/bin/phpunit --testsuite Unit

```

The `phpcs.xml` ruleset enforces WordPress core, WordPress VIP minimum, and Slevomat coding standards. CI must stay green; suppress only with a justified `phpcs:ignore -- reason` comment.

## Contributing

1. Open an issue describing the change before opening large PRs.
2. Branch from `main`.
3. Keep `phpcs` and the unit suite green.
4. Add/update tests where it makes sense.
5. Update `readme.txt` (user-facing) and this `README.md` (developer-facing) if behavior changes.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html).
