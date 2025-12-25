# Idiot’s guide

Assumes you’re leanrning. Not stupid.

## 1) Make the webhook
Discord → Channel → Edit → Integrations → Webhooks → New Webhook → Copy URL.

Treat that URL like a password.
Because it basically is.

## 2) Install the plugin
Put the folder here:
`wp-content/plugins/wp-discord-announcer/`

Activate it.

## 3) Configure it
WordPress:
Settings → Discord Announcer

Paste webhook URL.
Save.

## 4) Publish a post
Edit a post.
Find “Discord Announcement”.
Leave the checkbox ticked (default).
Publish.

Result: one message in Discord.

## If nothing happens
- Webhook URL wrong (most common).
- You published a post type that isn’t allowed (default is just `post`).
- Check your PHP error log for lines starting:
  `[WP Discord Announcer]`

## If it posts twice
It shouldn’t. The plugin stores a fingerprint to stop obvious duplicates.
If you have weird editorial workflows, you can still create edge cases.

