<?php
/**
 * Plugin Name: WP Discord Announcer
 * Description: Sends a Discord notification via webhook when posts are published. Per-post toggle. Templates. One embed. Minimal drama.
 * Version:     2.0.0.0
 * Author:      TABARC-Code
 * Plugin URI:  https://github.com/TABARC-Code/
 * Text Domain: wp-discord-announcer
 *
 * Maintainer notes (TABARC-Code):
 * - This is deliberately boring. If you want a "Discord marketing suite", go buy one.
 * - Assume WordPress will fire hooks twice. Assume editors will click publish, unpublish, republish, and blame you.
 * - Webhooks are secrets. Treat them like passwords. Don't print them. Don't expose them.
 * - Avoid pings: Discord's @everyone surprises are not "features".
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_Discord_Announcer' ) ) {

    final class WP_Discord_Announcer {

        // Options.
        private const OPT_WEBHOOK          = 'wpda_discord_webhook_url';
        private const OPT_DEFAULT_TEMPLATE = 'wpda_discord_default_template';

        // Post meta.
        private const META_ENABLED         = '_wpda_discord_announce_enabled';      // '1' or '0'
        private const META_TEMPLATE        = '_wpda_discord_announce_template';     // per-post override
        private const META_ANNOUNCED_FPR   = '_wpda_discord_announced_fingerprint'; // prevent doubles
        private const META_ANNOUNCED_AT    = '_wpda_discord_announced_at';

        // Limits (Discord side). Keep these conservative.
        private const DISCORD_CONTENT_LIMIT     = 2000;
        private const DISCORD_EMBED_TITLE_LIMIT = 256;
        private const DISCORD_EMBED_DESC_LIMIT  = 4096;

        /**
         * Allowed post types. Keep it small by default.
         * Filter: `wpda_allowed_post_types`.
         *
         * @var array<string>
         */
        private $allowed_post_types = array( 'post' );

        public function __construct() {
            $this->allowed_post_types = (array) apply_filters( 'wpda_allowed_post_types', $this->allowed_post_types );

            // Admin.
            add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
            add_action( 'admin_init', array( $this, 'register_settings' ) );

            // Per-post controls.
            add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
            add_action( 'save_post', array( $this, 'save_post_meta' ), 10, 2 );

            // Status transition: publish edge.
            add_action( 'transition_post_status', array( $this, 'handle_post_status_transition' ), 10, 3 );
        }

        /* -------------------------------------------------------------------------
         * Settings UI
         * ---------------------------------------------------------------------- */

        public function register_settings_page() {
            add_options_page(
                __( 'Discord Announcer', 'wp-discord-announcer' ),
                __( 'Discord Announcer', 'wp-discord-announcer' ),
                'manage_options',
                'wp-discord-announcer',
                array( $this, 'render_settings_page' )
            );
        }

        public function register_settings() {
            register_setting(
                'wpda_discord_announcer_settings',
                self::OPT_WEBHOOK,
                array(
                    'type'              => 'string',
                    'sanitize_callback' => array( $this, 'sanitize_webhook_url' ),
                    'default'           => '',
                )
            );

            register_setting(
                'wpda_discord_announcer_settings',
                self::OPT_DEFAULT_TEMPLATE,
                array(
                    'type'              => 'string',
                    'sanitize_callback' => array( $this, 'sanitize_template' ),
                    'default'           => '',
                )
            );

            add_settings_section(
                'wpda_main',
                __( 'Discord Webhook Settings', 'wp-discord-announcer' ),
                array( $this, 'render_settings_section_intro' ),
                'wp-discord-announcer'
            );

            add_settings_field(
                'wpda_webhook_url_field',
                __( 'Webhook URL', 'wp-discord-announcer' ),
                array( $this, 'render_webhook_url_field' ),
                'wp-discord-announcer',
                'wpda_main'
            );

            add_settings_field(
                'wpda_default_template_field',
                __( 'Default Message Template', 'wp-discord-announcer' ),
                array( $this, 'render_default_template_field' ),
                'wp-discord-announcer',
                'wpda_main'
            );
        }

        public function sanitize_webhook_url( $value ) {
            $value = trim( (string) $value );

            if ( $value === '' ) {
                return '';
            }

            $value = esc_url_raw( $value );
            if ( ! $value ) {
                add_settings_error( self::OPT_WEBHOOK, 'wpda_invalid_url', __( 'The Discord webhook URL is invalid.', 'wp-discord-announcer' ) );
                return '';
            }

            $parsed = wp_parse_url( $value );
            $host   = isset( $parsed['host'] ) ? (string) $parsed['host'] : '';

            if ( $host === '' || ( stripos( $host, 'discord.com' ) === false && stripos( $host, 'discordapp.com' ) === false ) ) {
                add_settings_error( self::OPT_WEBHOOK, 'wpda_not_discord', __( 'The URL does not appear to be a valid Discord webhook URL.', 'wp-discord-announcer' ) );
                return '';
            }

            return $value;
        }

        public function sanitize_template( $value ) {
            $value = (string) $value;
            $value = wp_strip_all_tags( $value, false );
            $value = trim( $value );

            if ( strlen( $value ) > 1000 ) {
                $value = substr( $value, 0, 1000 );
            }

            return $value;
        }

        public function render_settings_section_intro() {
            echo '<p>' . esc_html__( 'Configure the Discord webhook and the default message template used for announcements.', 'wp-discord-announcer' ) . '</p>';
        }

        public function render_webhook_url_field() {
            $value = (string) get_option( self::OPT_WEBHOOK, '' );
            ?>
            <input
                type="url"
                name="<?php echo esc_attr( self::OPT_WEBHOOK ); ?>"
                id="<?php echo esc_attr( self::OPT_WEBHOOK ); ?>"
                value="<?php echo esc_attr( $value ); ?>"
                class="regular-text"
                placeholder="https://discord.com/api/webhooks/..."
                autocomplete="off"
            />
            <p class="description">
                <?php esc_html_e( 'Create a webhook in your Discord channel settings and paste the URL here. Keep it private.', 'wp-discord-announcer' ); ?>
            </p>
            <?php
        }

        public function render_default_template_field() {
            $value = (string) get_option( self::OPT_DEFAULT_TEMPLATE, '' );

            if ( $value === '' ) {
                $value = "New post: {{post_title}}\n{{post_url}}";
            }
            ?>
            <textarea
                name="<?php echo esc_attr( self::OPT_DEFAULT_TEMPLATE ); ?>"
                id="<?php echo esc_attr( self::OPT_DEFAULT_TEMPLATE ); ?>"
                rows="3"
                class="large-text"
            ><?php echo esc_textarea( $value ); ?></textarea>
            <p class="description">
                <?php esc_html_e( 'Placeholders:', 'wp-discord-announcer' ); ?>
                <code>{{post_title}}</code>,
                <code>{{post_url}}</code>,
                <code>{{post_excerpt}}</code>,
                <code>{{site_name}}</code>,
                <code>{{author_name}}</code>,
                <code>{{post_date}}</code>
            </p>
            <?php
        }

        public function render_settings_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'Discord Announcer', 'wp-discord-announcer' ); ?></h1>
                <form action="options.php" method="post">
                    <?php
                    settings_fields( 'wpda_discord_announcer_settings' );
                    do_settings_sections( 'wp-discord-announcer' );
                    submit_button();
                    ?>
                </form>

                <hr />
                <p class="description">
                    <?php esc_html_e( 'This plugin posts on the publish transition. Updates to already-published posts do not announce again by default.', 'wp-discord-announcer' ); ?>
                </p>
            </div>
            <?php
        }

        /* -------------------------------------------------------------------------
         * Per-post meta box
         * ---------------------------------------------------------------------- */

        public function register_meta_boxes() {
            foreach ( $this->allowed_post_types as $post_type ) {
                add_meta_box(
                    'wpda_discord_announcer_meta',
                    __( 'Discord Announcement', 'wp-discord-announcer' ),
                    array( $this, 'render_meta_box' ),
                    $post_type,
                    'side',
                    'default'
                );
            }
        }

        public function render_meta_box( $post ) {
            wp_nonce_field( 'wpda_discord_meta', 'wpda_discord_meta_nonce' );

            $enabled_meta  = get_post_meta( $post->ID, self::META_ENABLED, true );
            $template_meta = get_post_meta( $post->ID, self::META_TEMPLATE, true );

            $enabled_checked = ( $enabled_meta === '' || $enabled_meta === '1' );
            ?>
            <p>
                <label>
                    <input type="checkbox" name="wpda_discord_announce_enabled" value="1" <?php checked( $enabled_checked ); ?> />
                    <?php esc_html_e( 'Announce this post on Discord when published.', 'wp-discord-announcer' ); ?>
                </label>
            </p>

            <p>
                <label for="wpda_discord_announce_template">
                    <?php esc_html_e( 'Custom message template (optional):', 'wp-discord-announcer' ); ?>
                </label>
                <textarea
                    name="wpda_discord_announce_template"
                    id="wpda_discord_announce_template"
                    rows="3"
                    class="widefat"
                    placeholder="<?php echo esc_attr__( 'Leave empty to use the global template.', 'wp-discord-announcer' ); ?>"
                ><?php echo esc_textarea( (string) $template_meta ); ?></textarea>
            </p>

            <p class="description">
                <?php esc_html_e( 'Placeholders:', 'wp-discord-announcer' ); ?>
                <code>{{post_title}}</code>,
                <code>{{post_url}}</code>,
                <code>{{post_excerpt}}</code>,
                <code>{{site_name}}</code>,
                <code>{{author_name}}</code>,
                <code>{{post_date}}</code>
            </p>
            <?php
        }

        public function save_post_meta( $post_id, $post ) {
            if ( ! $post instanceof WP_Post ) {
                return;
            }

            if ( ! in_array( $post->post_type, $this->allowed_post_types, true ) ) {
                return;
            }

            if ( ! isset( $_POST['wpda_discord_meta_nonce'] ) || ! wp_verify_nonce( (string) $_POST['wpda_discord_meta_nonce'], 'wpda_discord_meta' ) ) {
                return;
            }

            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }
            if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
                return;
            }

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return;
            }

            $enabled = isset( $_POST['wpda_discord_announce_enabled'] ) ? '1' : '0';
            update_post_meta( $post_id, self::META_ENABLED, $enabled );

            if ( isset( $_POST['wpda_discord_announce_template'] ) ) {
                $template = $this->sanitize_template( wp_unslash( $_POST['wpda_discord_announce_template'] ) );
                if ( $template === '' ) {
                    delete_post_meta( $post_id, self::META_TEMPLATE );
                } else {
                    update_post_meta( $post_id, self::META_TEMPLATE, $template );
                }
            }
        }

        /* -------------------------------------------------------------------------
         * Publish transition
         * ---------------------------------------------------------------------- */

        public function handle_post_status_transition( $new_status, $old_status, $post ) {
            $webhook_url = (string) get_option( self::OPT_WEBHOOK, '' );
            if ( $webhook_url === '' ) {
                return;
            }

            if ( ! $post instanceof WP_Post ) {
                return;
            }

            if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
                return;
            }

            if ( ! in_array( $post->post_type, $this->allowed_post_types, true ) ) {
                return;
            }

            $was_published = ( $old_status === 'publish' );
            $is_published  = ( $new_status === 'publish' );

            if ( $was_published || ! $is_published ) {
                return;
            }

            $enabled_meta = get_post_meta( $post->ID, self::META_ENABLED, true );
            if ( $enabled_meta === '0' ) {
                return;
            }

            $fingerprint = $this->build_post_fingerprint( $post );
            $last_fpr    = (string) get_post_meta( $post->ID, self::META_ANNOUNCED_FPR, true );

            if ( $last_fpr !== '' && hash_equals( $last_fpr, $fingerprint ) ) {
                return;
            }

            $ok = $this->send_discord_notification_for_post( $post, $webhook_url );

            if ( $ok ) {
                update_post_meta( $post->ID, self::META_ANNOUNCED_FPR, $fingerprint );
                update_post_meta( $post->ID, self::META_ANNOUNCED_AT, (string) time() );
            }
        }

        private function build_post_fingerprint( WP_Post $post ) : string {
            $parts = array(
                (string) $post->ID,
                (string) $post->post_date_gmt,
                (string) $post->post_modified_gmt,
                (string) $post->post_status,
            );
            return hash( 'sha256', implode( '|', $parts ) );
        }

        /* -------------------------------------------------------------------------
         * Discord payload
         * ---------------------------------------------------------------------- */

        private function send_discord_notification_for_post( WP_Post $post, string $webhook_url ) : bool {
            $post_id   = (int) $post->ID;
            $permalink = get_permalink( $post_id );
            if ( ! $permalink ) {
                return false;
            }

            $title       = (string) get_the_title( $post_id );
            $author_id   = (int) $post->post_author;
            $author_name = $author_id ? (string) get_the_author_meta( 'display_name', $author_id ) : '';
            $site_name   = (string) get_bloginfo( 'name' );

            $raw_excerpt = (string) get_the_excerpt( $post_id );
            $excerpt     = wp_trim_words( wp_strip_all_tags( $raw_excerpt ), 50, '…' );

            $post_date = (string) get_post_time(
                get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
                false,
                $post
            );

            $context = array(
                'post_title'   => $title,
                'post_url'     => (string) $permalink,
                'post_excerpt' => (string) $excerpt,
                'site_name'    => $site_name,
                'author_name'  => $author_name,
                'post_date'    => $post_date,
            );

            $template = (string) get_post_meta( $post_id, self::META_TEMPLATE, true );
            if ( $template === '' ) {
                $template = (string) get_option( self::OPT_DEFAULT_TEMPLATE, '' );
            }
            if ( $template === '' ) {
                $template = "New post: {{post_title}}\n{{post_url}}";
            }

            $message = $this->render_template( $template, $context );
            $message = $this->truncate( $message, self::DISCORD_CONTENT_LIMIT );

            $embed = array(
                'title'       => $this->truncate( $title, self::DISCORD_EMBED_TITLE_LIMIT ),
                'url'         => (string) $permalink,
                'description' => $this->truncate( (string) $excerpt, self::DISCORD_EMBED_DESC_LIMIT ),
                'timestamp'   => (string) get_post_time( 'c', true, $post ),
                'author'      => array(
                    'name' => $author_name !== '' ? $author_name : $site_name,
                ),
                'footer'      => array(
                    'text' => $site_name,
                ),
            );

            $image_url = get_the_post_thumbnail_url( $post_id, 'full' );
            if ( $image_url ) {
                $embed['image'] = array( 'url' => (string) $image_url );
            }

            $payload = array(
                'content' => $message,
                'embeds'  => array( $embed ),
                'allowed_mentions' => array( 'parse' => array() ),
            );

            $payload = apply_filters( 'wpda_discord_payload', $payload, $post );

            $args = array(
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( $payload ),
                'timeout' => 10,
            );

            $response = wp_remote_post( $webhook_url, $args );

            if ( is_wp_error( $response ) ) {
                $this->log_error( sprintf( 'Request failed for post %d: %s', $post_id, $response->get_error_message() ) );
                return false;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code < 200 || $code >= 300 ) {
                $this->log_error( sprintf( 'Discord webhook returned HTTP %d for post %d.', $code, $post_id ) );
                return false;
            }

            return true;
        }

        private function render_template( string $template, array $context ) : string {
            $replacements = array();
            foreach ( $context as $k => $v ) {
                $replacements[ '{{' . $k . '}}' ] = (string) $v;
            }

            $out = strtr( $template, $replacements );
            $out = preg_replace( "/\n{3,}/", "\n\n", $out );
            return trim( (string) $out );
        }

        private function truncate( string $text, int $max ) : string {
            if ( $max <= 0 ) {
                return '';
            }
            if ( strlen( $text ) <= $max ) {
                return $text;
            }
            $cut = substr( $text, 0, max( 0, $max - 1 ) );
            return $cut . '…';
        }

        private function log_error( string $message ) : void {
            error_log( '[WP Discord Announcer] ' . $message );
        }
    }

    new WP_Discord_Announcer();
}
