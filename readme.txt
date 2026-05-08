=== TaskShunt ===
Contributors: liviu13
Tags: staging, deployment, content sync, migration, push
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Staging-to-production content deployment for WordPress. Track changes on a staging site and push them to production via REST API.

== Description ==

TaskShunt lets you work freely on a staging site, then push only the changes you want to production — no full-site migrations, no database dumps, no downtime.

It tracks every content change you make while a task is active, bundles them into a reviewable list, and deploys them to your production site over a secure REST API with a single click.

= How It Works =

1. **Activate a task** on your staging site to start recording changes.
2. **Work normally** — create pages, upload images, install plugins. TaskShunt captures it all.
3. **Review the task** to see exactly what will be pushed.
4. **Push to production** and TaskShunt applies each change on the live site.

= What Gets Tracked =

* **Content** — Posts, pages, media/attachments, and any registered custom post type. Creates, updates, and deletes are all captured.
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
* **Configurable auto-cleanup** — Automatically delete pushed tasks after a custom number of days (1–365, default 30). Can be disabled in Settings.
* **Dashboard widget** — Quick status overview right on your WordPress dashboard.

= Two Modes =

TaskShunt operates in one of two modes, chosen on first activation:

* **Sender (Staging)** — Tracks changes and pushes them to the connected production site.
* **Receiver (Production)** — Accepts incoming pushes and applies the changes.

= What the Receiver Endpoint Can Do =

**Content items** (posts, pages, attachments, and any registered custom post type):

* `create` — Insert a new post. Attachments are sideloaded from the embedded file data (or downloaded from the sender URL as fallback).
* `update` — Match an existing post by slug and update its fields, meta, and (for attachments) replace the underlying file.
* `delete` — Match by slug and permanently delete the post.

**Environment items — plugins** (identified by their basename, e.g. `woocommerce/woocommerce.php`):

* `activate` — Activate the plugin, installing it from WordPress.org first if it is not already present.
* `deactivate` — Deactivate the plugin.
* `install` — Install the plugin from WordPress.org (optionally activating after install).
* `update` — Update the plugin to the latest version from WordPress.org.
* `delete` — Deactivate (if active) and delete the plugin from disk.

**Environment items — themes** (identified by their stylesheet slug):

* `switch` — Activate the theme, installing it from WordPress.org first if it is missing.
* `install` — Install the theme from WordPress.org (optionally activating after install).
* `update` — Update the theme to the latest version from WordPress.org.
* `delete` — Delete the theme. Refused if it is the currently active theme.

URL rewriting is automatic: any reference to the sender site URL inside post content, excerpts, or post meta is rewritten to the receiver site URL on apply.

= Setup =

1. On your **production site**, go to **TaskShunt > Settings** and select **Receiver** mode. Copy the generated API key.
2. On your **staging site**, go to **TaskShunt > Settings** and select **Sender** mode. Enter the production site URL and API key.
3. Use the **Test Connection** button to verify the link.
4. Create your first task and start tracking changes.

= Security =

TaskShunt's push payloads can include post content, plugin/theme operations, and embedded media. To keep that data confidential and tamper-evident in transit:

* **Run both the sender and the receiver site over HTTPS.** The HMAC signature protects against forged or replayed requests, but only TLS prevents an on-path observer from reading payloads or capturing the API key as it is sent in the initial connection setup.
* Restrict admin access on the receiver to trusted users — the API key is stored encrypted at rest, but anyone with administrator-level access to **TaskShunt > Settings** can read and regenerate it.

== Frequently Asked Questions ==

= Does TaskShunt copy my entire database? =

No. TaskShunt only tracks and pushes the specific changes you make while a task is active. It does not touch your full database.

= Can I push from localhost to a live site? =

Yes. Media files are embedded directly in the push payload, so they transfer even when the staging site is not publicly accessible.

= What happens if a push fails? =

The task is marked as failed and you can retry it. Items that were already applied successfully on the receiver are not duplicated.

= Can I choose which post types to track? =

Yes. The Settings page lets you toggle tracking for each registered post type individually.

= Does it work with custom post types? =

Yes. Any post type registered on the staging site can be tracked and pushed.

= Is the connection between sites secure? =

Every push request is signed with an HMAC derived from the API key, plus a timestamp and a single-use nonce, so requests cannot be tampered with or replayed. The API key itself is stored encrypted at rest on both sides. For full transport-level confidentiality you must also serve **both** the sender and the receiver site over HTTPS — see the **Security** section above for details.

= How do I rotate the API key if I think it has been compromised? =

If you suspect the key has leaked (for example, it was committed to a public repository or shared insecurely), rotate it immediately:

1. On the **production (receiver)** site, go to **TaskShunt > Settings** and click **Regenerate API key**. This invalidates the old key on the receiver right away — any further push attempts using the old key will be rejected with a signature failure.
2. Copy the newly generated key.
3. On the **staging (sender)** site, go to **TaskShunt > Settings**, open the configured server, and paste the new key in place of the old one. Save.
4. Click **Test Connection** to confirm the sender and receiver agree on the new key.
5. If you suspect the leak has already been used, audit the production site for changes you did not initiate — recently created or modified posts, newly installed or activated plugins and themes, and uploaded media. The sender's **Recent pushes** panel on the TaskShunt dashboard shows what was sent legitimately, so anything on production that does not match that history is worth investigating.

You can also rotate the key proactively on a schedule using the same steps — it does not affect any in-flight tasks beyond requiring the sender to be updated with the new value before the next push.

== Changelog ==

= 1.0.0 =
* Initial release.
* Content tracking for posts, pages, attachments, and custom post types.
* Environment tracking for plugin/theme lifecycle events.
* Secure REST API for staging-to-production deployment.
* Task management with create, preview, push, retry, and discard.
* Dashboard widget and admin bar integration.
* Automatic URL rewriting and smart deduplication.
* Configurable auto-cleanup for pushed tasks.
