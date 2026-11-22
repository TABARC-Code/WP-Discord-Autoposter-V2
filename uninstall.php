<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'wpda_discord_webhook_url' );
delete_option( 'wpda_discord_default_template' );

delete_post_meta_by_key( '_wpda_discord_announce_enabled' );
delete_post_meta_by_key( '_wpda_discord_announce_template' );
delete_post_meta_by_key( '_wpda_discord_announced_fingerprint' );
delete_post_meta_by_key( '_wpda_discord_announced_at' );
