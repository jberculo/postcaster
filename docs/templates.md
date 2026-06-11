# PostCaster templates

This document covers template placeholders, precedence, and practical examples for PostCaster.

## Default template

The built-in default template is:

```text
{title}

{url}
```

## Available placeholders

* `{title}`: the post title
* `{site}`: the site title
* `{post}`: a shortened excerpt from the post content
* `{excerpt}`: alias of `{post}`
* `{category}`: the first selected category
* `{cat_desc}`: the category description
* `{date}`: the post date
* `{modified}`: the modified date
* `{url}`: the post permalink
* `{author}`: the author display name
* `{@site}`: the configured site account reference for the current network target, or `{site}` when unavailable
* `{@author}`: the author account reference for the current target, or `{author}` when unavailable
* `{tags}`: the post tags converted to hashtags

## Template precedence

PostCaster resolves templates per final publish target.

Global context:

1. Post-specific template
2. Network-specific template
3. General template
4. Plugin default template

Personal context:

1. Personal post-specific template
2. Personal network-specific template
3. Personal general template
4. Global general template
5. Plugin default template

## Practical notes

* Network-specific templates only affect that network.
* Post-specific templates are stored per context: `global` or `personal`.
* If a customized template becomes identical to its fallback, PostCaster stores it as disabled and falls back automatically.
* The post edit screen previews the resolved output for the targets currently available for that article.

## Length handling

* Each network has its own character limit.
* Tags are shortened per hashtag, not character by character.
* URLs and account references are treated as non-shrinkable parts.
* If a message still does not fit safely, PostCaster skips that target and stores an error.

## Network-specific behavior

### Bluesky

When featured-image embeds are enabled for Bluesky, PostCaster can attach an external card and remove the visible permalink from the final post text to avoid duplication.

### Mastodon

Mastodon servers generate preview cards from links in the visible post text, so `{url}` should remain in the message if you want a card.

## Example templates

General site-wide template:

```text
{title}

{url}
```

Short social-first template:

```text
{title} {url}
```

Author-focused template:

```text
{title}
by {author}

{url}
```

Hashtag-heavy template:

```text
{title}

{tags}
{url}
```

## When to use post-specific overrides

Use a post-specific override when:

* one article needs a different tone
* one article needs shorter wording
* one article should omit tags or author information
* you need a different message for a special editorial moment
