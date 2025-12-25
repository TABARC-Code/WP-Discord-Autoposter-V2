<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'wpda_discord_webhook_url' );
delete_option( 'wpda_discord_default_template' );
