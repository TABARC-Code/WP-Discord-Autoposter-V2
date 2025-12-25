# Security

- Webhook URLs are secrets. Do not commit them to git.
- Mentions are disabled (`allowed_mentions.parse = []`) to avoid accidental pings.
- Settings are gated by `manage_options`.
- Per-post meta saves are nonce + capability checked.

Report issues via GitHub.
