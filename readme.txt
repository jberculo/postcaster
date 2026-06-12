=== PostCaster ===
Contributors: jberculo
Tags: social, bluesky, mastodon
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.4.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publishes WordPress posts with featured images to Bluesky and Mastodon.

== Description ==

PostCaster publishes WordPress posts to Bluesky and Mastodon when a post goes live. It supports both site-wide accounts and personal author accounts, with previews, test posts, and per-post overrides on the post edit screen.

This plugin is intended for editorial sites that want social posting to stay close to the WordPress publishing workflow.

Key capabilities:

* Publish automatically when a post moves from unpublished to `publish`
* Use site-wide accounts in `PostCaster > Global socials`
* Let authors use their own accounts in `PostCaster > My socials`
* Preview the exact text before publishing
* Override the text per article
* Choose featured-image behavior per network
* Re-publish manually from the post edit screen when needed

Quick start:

1. Activate the plugin.
2. Enable PostCaster in `PostCaster > Settings`.
3. Choose which post types may be published automatically.
4. Configure one or more site-wide accounts in `PostCaster > Global socials`.
5. Send a test post for each configured network.
6. Publish a test article and confirm the result on the target network.

Supported networks:

* Bluesky
* Mastodon
Permissions and publishing model:

* Editors and administrators can publish through configured global accounts.
* Authors can publish through their own personal accounts when personal publishing is enabled for them.
* A user who has access to both global and personal targets can choose which targets to use during manual publishing.
* If Co-Authors Plus is active, linked co-authors are also recognized for personal publishing.

Templates and previews:

* The default message template is based on post title and URL.
* You can set a general template, network-specific templates, and post-specific overrides.
* The post edit screen shows previews for the currently available publish targets.
* If the final message is still too long for a network, PostCaster skips that target and stores an error on the article.

For template details and examples, see `docs/templates.md` in the plugin repository.

Troubleshooting highlights:

* Always use the built-in test-post buttons after changing credentials.
* If a network is enabled but not posting, first check credentials and article-level errors.
* If encrypted credentials can no longer be read, re-save them in `Global socials` or `My socials`.
* Publishing runs through Action Scheduler in the background; low-traffic sites still benefit from a real server cron job or scheduled task for reliable timing.

External services:

PostCaster connects to external social networks only after an administrator or user explicitly configures that network and saves the required credentials.

When configured, PostCaster may send:

* text rendered from post fields and templates
* featured image bytes and alt text
* the configured account identifiers required by that network

Relevant service documentation and terms:

* Bluesky developer docs: https://docs.bsky.app/
* Bluesky terms: https://bsky.social/about/support/tos
* Mastodon API docs: https://docs.joinmastodon.org/api/
Privacy note:

PostCaster does not send usage analytics or telemetry to the plugin author. Network requests are only made to the configured social networks for test posts and actual publishing.

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory.
2. Activate the plugin through the WordPress admin.
3. Open `PostCaster > Settings` and enable the plugin.
4. Select the post types that may publish automatically.
5. Configure global accounts in `PostCaster > Global socials`.
6. Optionally configure personal author accounts in `PostCaster > My socials`.
7. Send test posts before using automatic publishing on live content.

== FAQ ==

= Who should configure the plugin first? =

An administrator should first enable the plugin, choose the allowed post types, and configure at least one global account or enable personal publishing.

= Does PostCaster publish updates when an already published post is edited? =

No. Automatic publishing is intended for the transition to `publish`. Already published posts can be re-posted manually from the post edit screen.

= Can authors use their own social accounts? =

Yes. Personal publishing is available through `PostCaster > My socials` when the site administrator allows that network for authors.

= What happens if a message is too long? =

PostCaster applies the character limit for the target network. If the final message still does not fit safely, that target is skipped and an error is stored on the post.

= What happens if featured-image uploads or remote APIs are slow? =

PostCaster may retry the publishing through Action Scheduler. On low-traffic sites, a server administrator may need to add a real server cron job or scheduled task for more reliable timing. In practice this is often only a small issue, because even fairly quiet public sites usually still receive enough bot traffic for WordPress cron to run regularly.

= What is a "real" cron job? =

WordPress cron normally runs only when someone visits the site. A real cron job is a scheduler on the server itself that triggers WordPress cron on a fixed interval, even when the site has little traffic.

If you do not manage the server yourself, ask the server administrator or hosting provider to add it. If that is not possible, it is usually acceptable to live with slightly slower retries.

= Where do I find technical integration details? =

See the docs in the GitHub repository:
https://github.com/jberculo/postcaster/tree/master/docs

* `docs/templates.md` for placeholders, precedence, and template examples
* `docs/integration.md` for hooks, theme integration, and implementation examples
* `docs/troubleshooting.md` for FAQ-style help with credentials, cron, previews, and publishing

== Changelog ==

= 0.4.6 =
* Fixed the release packaging so WordPress.org SVN receives only the intended plugin distribution files, without repo-only release notes or WordPress.org source asset folders.

= 0.4.5 =
* Reworked the Action Scheduler hook migration query to use WordPress database helpers instead of an interpolated SQL update.
* Normalized line endings in the remaining plugin files that still triggered Plugin Check mixed-line-ending warnings.

= 0.4.4 =
* Excluded repository documentation from distribution packages via `.distignore` so release archives stay focused on runtime plugin files.

= 0.4.3 =
* Fixed Bluesky image processing on WP-Cron and other non-admin runtimes by explicitly loading the WordPress file helpers before calling `wp_tempnam()`.
* Fixed the bundled Action Scheduler WP-CLI bootstrap so `wp action-scheduler` commands register correctly after prefixing.
* Verified compatibility against WordPress 7.0 and updated the tested-up-to header accordingly.

= 0.4.2 =
* Clarified the Settings API sanitization entry point around `register_setting()` and documented the field-by-field use of core WordPress sanitizers.

= 0.4.1 =
* Declared `type`, `default`, and `show_in_rest` on the plugin's `register_setting()` call alongside the existing sanitize callback, addressing feedback from the WordPress.org plugin review.

= 0.4.0 =
* Renamed all internal prefixes (namespace, option names, post/user meta keys, JS handles, constants) to `justbee_postcaster_` / `Justbee\PostCaster\…` to avoid collisions with other plugins. Existing data is migrated automatically.
* Scoped the bundled Action Scheduler under `Justbee\PostCaster\Vendor\` (via Strauss) so it can no longer clash with other plugins shipping Action Scheduler.
* Guarded the bundled Action Scheduler bootstrap against double-loading.
* All admin UI assets are now properly enqueued; no more inline `<script>` or `<style>` tags in PHP views.
* Tightened nonce and capability checks on preview and template AJAX endpoints.
* Fixed uninstall: post meta and user meta are now actually removed (both current and legacy prefixes).
* Paginated the queue and logging views for sites with many entries.
* Added Bluesky placeholder mentions and queue diagnostics.
* Fixed duplicate entries in queue status output.
* Hardened async publish retries and sped up media publishing.
* Added `jberculo` to the plugin contributors.

= 0.3.1 =
* Hardened async publish and retry reliability.
* Improved scaling for personal-target resolution on sites with many authors.
* Completed plugin data cleanup and operational warnings before first public release.

= 0.3.0 =
* Initial release.
