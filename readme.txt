=== Stagify ===
Contributors: stagify
Tags: staging, deployment, content sync, migration, push
Requires at least: 6.0
Tested up to: 6.9.4
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Staging-to-production content deployment for WordPress. Track changes on a staging site and push them to production via REST API.

== Description ==

Stagify lets you work freely on a staging site, then push only the changes you want to production — no full-site migrations, no database dumps, no downtime.

It tracks every content and file change you make while a task is active, bundles them into a reviewable list, and deploys them to your production site over a secure REST API with a single click.

= How It Works =

1. **Activate a task** on your staging site to start recording changes.
2. **Work normally** — create pages, upload images, edit theme files. Stagify captures it all.
3. **Review the task** to see exactly what will be pushed.
4. **Push to production** and Stagify applies each change on the live site.

= What Gets Tracked =

* **Content** — Posts, pages, media/attachments, and any registered custom post type. Creates, updates, and deletes are all captured.
* **Files** — Theme and plugin file changes (PHP, CSS, JS, JSON, HTML, SVG, Twig). Stagify takes SHA-256 snapshots so only genuinely changed files are included.
* **Environment** — Plugin and theme activations, deactivations, installs, updates, and deletions.

= Key Features =

* **Granular change tracking** — Only the changes made during an active task are captured, not the entire site.
* **Named tasks** — Organize deployments by feature, sprint, or ticket. Create as many tasks as you need.
* **Preview before pushing** — Review every item in a task before it goes live.
* **Smart deduplication** — If you create and then delete something in the same task, the changes cancel out automatically.
* **Secure REST API** — All communication between staging and production is authenticated with an API key.
* **Media transfer** — Attachment files are embedded directly in the push payload, so media works across any network topology (localhost, private networks, cloud).
* **URL rewriting** — Content URLs are automatically rewritten from the staging domain to the production domain.
* **Retry failed pushes** — If a push fails partway through, retry it without duplicating already-applied changes.
* **Configurable auto-cleanup** — Optionally delete pushed tasks after a custom number of days (1–365). Disabled by default — enable it in Settings.
* **Dashboard widget** — Quick status overview right on your WordPress dashboard.

= Two Modes =

Stagify operates in one of two modes, chosen on first activation:

* **Sender (Staging)** — Tracks changes and pushes them to the connected production site.
* **Receiver (Production)** — Accepts incoming pushes and applies the changes.

= Setup =

1. On your **production site**, go to **Stagify > Settings** and select **Receiver** mode. Copy the generated API key.
2. On your **staging site**, go to **Stagify > Settings** and select **Sender** mode. Enter the production site URL and API key.
3. Use the **Test Connection** button to verify the link.
4. Create your first task and start tracking changes.

== Frequently Asked Questions ==

= Does Stagify copy my entire database? =

No. Stagify only tracks and pushes the specific changes you make while a task is active. It does not touch your full database.

= Can I push from localhost to a live site? =

Yes. Media files are embedded directly in the push payload, so they transfer even when the staging site is not publicly accessible.

= What happens if a push fails? =

The task is marked as failed and you can retry it. Items that were already applied successfully on the receiver are not duplicated.

= Can I choose which post types to track? =

Yes. The Settings page lets you toggle tracking for each registered post type individually.

= Does it work with custom post types? =

Yes. Any post type registered on the staging site can be tracked and pushed.

= Is the connection between sites secure? =

All push requests are authenticated with an API key sent in the request header. For full security, both sites should use HTTPS.

== Changelog ==

= 1.0.0 =
* Initial release.
* Content tracking for posts, pages, attachments, and custom post types.
* File tracking for theme and mu-plugin changes.
* Environment tracking for plugin/theme lifecycle events.
* Secure REST API for staging-to-production deployment.
* Task management with create, preview, push, retry, and discard.
* Dashboard widget and admin bar integration.
* Automatic URL rewriting and smart deduplication.
* Configurable auto-cleanup for pushed tasks.
