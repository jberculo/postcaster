# PostCaster FAQ and troubleshooting

This document collects common questions and short troubleshooting guidance for PostCaster.

## Start with the basics

Before looking for edge cases, check these first:

* PostCaster is enabled in `PostCaster > Settings`
* the correct post type is allowed for automatic publishing
* the target network is enabled
* the required credentials are filled in
* a test post succeeds for that network
* the article is not explicitly disabled for PostCaster on the post edit screen

## A post did not publish automatically

Check the following in order:

1. Confirm the post changed from unpublished to `publish`
2. Confirm the post type is enabled in PostCaster settings
3. Confirm the target network is enabled and configured
4. Check whether the article has stored PostCaster errors
5. Check whether the article was explicitly disabled in the PostCaster metabox

Important:

* Automatic publishing is for the transition to `publish`
* Editing an already published post does not automatically post again
* Re-posting an already published post should be done manually from the post edit screen

## A network is enabled but still not posting

Most often this is one of these:

* invalid or expired credentials
* missing required account identifier
* remote API rejection
* the rendered message is still too long after safe shortening
* delayed retry waiting on WordPress cron

Use the built-in test-post button first. If the test post fails, fix that before debugging article-specific behavior.

## Encrypted credentials can no longer be read

If PostCaster reports that encrypted credentials could not be decrypted:

* open the affected settings screen
* re-enter the credential value
* save the settings again
* send a new test post

This can happen after environment changes such as:

* WordPress key changes
* missing or changed sodium support
* moving stored encrypted values between environments with different key material

## WordPress cron and retries

PostCaster uses WordPress cron for retries after some remote failures or slow media processing.

What this means in practice:

* retries depend on normal site traffic unless a real server cron job or scheduled task triggers WordPress cron
* low-traffic sites may see delayed retries
* production sites should ideally use a real server cron for reliable background execution

Typical symptoms of cron-related issues:

* a post is scheduled but does not retry quickly
* media-processing errors appear to resolve only much later
* behavior differs between local/staging and production

## What is a real cron job?

WordPress cron is not a normal system scheduler. By default, it runs only when the site receives a visit.

A real cron job is the scheduler provided by the server or hosting platform itself. It can trigger WordPress cron every few minutes, even if nobody visits the site.

If you do not manage the server yourself, ask the server administrator or hosting provider to add this. If that is not possible, the practical fallback is simply to accept that retries may run a bit later.

In real-world use this is often only a small issue, because even fairly quiet public sites usually still receive enough bot traffic for WordPress cron to run regularly.

Common ways to do that:

* call `wp-cron.php` from a server cron job
* run `wp cron event run --due-now` via WP-CLI from a scheduled task

Example using WP-CLI:

```bash
*/5 * * * * cd /path/to/wordpress && wp cron event run --due-now --quiet
```

Example by calling `wp-cron.php`:

```bash
*/5 * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

If a real cron job is configured, WordPress sites often also disable built-in WP-Cron in `wp-config.php`:

```php
// If your host recommends disabling WP-Cron, make sure a real scheduler
// triggers WordPress background processing reliably.
```

The exact setup depends on the hosting provider:

* VPS or dedicated server: usually via `crontab`
* managed hosting: usually via a hosting control panel
* some WordPress hosts already provide a reliable cron setup

## Plugin Check and Action Scheduler

PostCaster builds a prefixed copy of Action Scheduler under `includes/vendor-prefixed/woocommerce/action-scheduler/`.

If Plugin Check or PCP reports issues in that directory, treat them differently from findings in PostCaster's own code:

* `TextDomainMismatch` with `action-scheduler` is expected
* direct database-call warnings are expected in Action Scheduler internals
* missing direct-file-access guards in library files are not PostCaster-specific application issues
* naming warnings for `as_*` functions and Action Scheduler hooks are part of the library design

In other words:

* findings in `includes/vendor-prefixed/woocommerce/action-scheduler/` are usually third-party library noise
* findings in `tests/` and build helper scripts are usually not release blockers either
* findings in PostCaster's own `includes/`, `views/`, `languages/`, and root plugin files should be reviewed normally

For local or CI scans, you can exclude vendored and non-release directories from Plugin Check if you want a cleaner report focused on PostCaster itself.

Typical exclusions:

* `includes/vendor-prefixed`
* `tests`
* `bin`

Important:

* excluding directories only affects that specific scan run
* it does not change the plugin package itself
  * if the prefixed library is present in a real release artifact, a scan of that artifact will still see the library unless the scan tool is also configured to exclude it

Practical recommendation:

1. use `.distignore` to keep repo-only and test files out of release packages
2. run Plugin Check separately in two modes when useful:
   * a focused local scan that excludes vendored/test directories
   * a release scan against the actual distribution artifact
3. rebuild the prefixed dependency copy before packaging if Action Scheduler changed

## Featured image problems

If text publishes but the image does not:

* confirm the post has a featured image
* confirm the network allows image uploads with the current credentials
* confirm the relevant featured-image setting is enabled
* test with a smaller image

Network-specific notes:

* Bluesky may re-encode images for card thumbnails
* Mastodon may reject or delay media processing
* slow media processing can fall through to retry behavior

## Preview does not match what I expected

Check these points:

* the preview context is correct: `global` or `personal`
* the active template is the one you think it is
* a network-specific template may override the general one
* a post-specific template may override both
* featured-image behavior can differ per network

If in doubt:

* remove the custom override temporarily
* test with a simple template such as `{title} {url}`
* compare the result per network

## Message too long

If a post is skipped because it is too long:

* shorten the template
* reduce non-shrinkable content such as explicit URLs or account references
* reduce hashtags
* use a network-specific shorter template
* use a post-specific override for that article

Remember:

* PostCaster shortens safely where possible
* if the final message still does not fit, it skips that target instead of posting a broken result

## Manual publish did not behave as expected

Check:

* whether the user has access to the selected context
* whether the post is already published
* whether the selected network is actually configured
* whether a previous remote ID already exists for that target

Manual publishing is the correct route when:

* you want to re-post an already published article
* you want to choose only global or only personal targets
* you want to publish after adjusting a post-specific override

## Logs and diagnostics

Useful places to inspect problems:

* article-level PostCaster errors on the post
* logging output in `PostCaster > Settings` when debug logging is enabled
* built-in test-post behavior for the affected network

When debugging, isolate one variable at a time:

* one network
* one account
* one simple template
* one test article

## When to look at integration docs

If the problem is not editorial but implementation-specific, see:

* `docs/integration.md` for hooks and override behavior
* `docs/templates.md` for placeholder and fallback behavior
