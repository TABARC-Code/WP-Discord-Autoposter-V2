# WP-Discord-Autoposter-V2
A deliberately boring, hardened Discord webhook announcer for WordPress.

# WP Discord Announcer

A deliberately boring, hardened Discord webhook announcer for WordPress.

<p align="center"> <img src=".branding/tabarc-icon.svg" width="180" alt="TABARC-Code Icon"> </p>

It posts **once** when a post first becomes `publish`.
It avoids cleverness, but assumes WordPress will misbehave. Its shit at times.
It assumes editors will click things they shouldn’t.

You want fireworks, dashboards, or “engagement analytics”, this is not it....  
However if you want something that quietly does one job and then gets out of the way, this might be.

--------

## What this plugin does

- Sends **one Discord message** when a post is first published
- Adds a **per-post checkbox**:
  “Announce this post on Discord when published”
- Supports:
  - a **global default template**
  - an **optional per-post template override**
- Sends a single **Discord embed** containing:
  - post title (linked)
  - excerpt
  - author name
  - site name
  - featured image (if present)
- Explicitly disables Discord mentions  
  (no accidental `@everyone` surprises at 9am on a Monday)

That’s it. No background jobs, no cron queues, no retry storms.

--------

## What this plugin deliberately does *not* do

This section exists to prevent future arguments.

-  No posting on post updates  
  Publish means publish. Editing later is not an event.
-  No multi-channel routing  
  If you want that, filter the payload and build it yourself.
-  No dashboards, logs UI, charts, analytics, or “AI summaries”
-  No retries, queues, or background workers  
  If Discord is down, the request fails and moves on.

These are not omissions. Thus is a design decision.

--------

## How it works (high level)

- Hooks into `transition_post_status`
- Watches for **non-published → published**
- Checks:
  - post type is allowed
  - per-post toggle is enabled
  - this exact post hasn’t already been announced
- Builds a payload
- Sends it to Discord via webhook
- Records a lightweight fingerprint in post meta to avoid duplicates

If WordPress fires the hook twice (it happens), the fingerprint stops noise.

--------

## Installation

1. Copy the plugin folder to:

wp-content/plugins/wp-discord-announcer/

markdown
Copy code

2. Activate **WP Discord Announcer** in the WordPress admin.

3. Go to:

Settings → Discord Announcer

yaml
Copy code

4. Paste your Discord webhook URL and save.

That’s the global setup done.

--------

## Creating a Discord webhook (quick version)

Discord - Channel - Edit Channel - Integrations - Webhooks → New Webhook → Copy URL.

Treat the URL like a password.
Because functionally, it is.

Do not:
- commit it to git
- paste it into screenshots
- share it in support tickets

---

## Per-post control (important)

Every supported post type gets a meta box:

**“Discord Announcement”**

It contains:
- a checkbox (enabled by default)
- an optional custom message template

If the checkbox is unticked, that post will never announce.

This exists because not every post deserves to interrupt a Discord channel.

--------

## Templates

Templates are simple token replacement.  
No conditionals. No logic. No loops. On purpose.

### Available tokens

- `{{post_title}}`
- `{{post_url}}`
- `{{post_excerpt}}`
- `{{site_name}}`
- `{{author_name}}`
- `{{post_date}}`

### Example default template

New post: {{post_title}}
{{post_url}}

csharp
Copy code

If you can’t express what you want with this, you’re probably trying to do marketing in a webhook.

--------

## Supported post types

By default, only `post` is announced.

You can extend this via a filter:

```php
add_filter( 'wpda_allowed_post_types', function ( $types ) {
    $types[] = 'page';
    $types[] = 'product';
    return $types;
});
If you announce everything, that’s a social problem, not a technical one.

Hooks for people who can’t leave things alone
wpda_allowed_post_types
Filter the allowed post types.

wpda_discord_payload
Filter the payload array before it’s sent.

If you use these hooks, you own the consequences.

Failure modes (honest version)
If the webhook URL is wrong → nothing posts.

If Discord returns a non-2xx response → nothing retries.

If WordPress fires hooks strangely → duplicates are usually blocked.

If you build a complicated editorial workflow → you may find edge cases.

Security notes
Webhook URLs are basically treated as secrets. The plugin never prints the webhook once saved.
Settings require manage_options. Per-post saves are nonce + capability checked.

Discord mentions are disabled by default.

This plugin is for Small to medium sites, Blogs, documentation sites, internal comms basiially for People who want a publish notification, not a social media platform

Who this plugin is not for!!!! Growth hacking, Marketing automation, People who want “just one more feature” i.e most bloody clients



Author: TABARC-Code
Plugin URI: https://github.com/TABARC-Code/
Version: 2.0.0.0
