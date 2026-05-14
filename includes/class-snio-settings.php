<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SNIO_Settings {
    private static ?SNIO_Settings $instance = null;

    public static function instance(): SNIO_Settings {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_snio_clear_logs', array( $this, 'handle_clear_logs' ) );
        add_action( 'update_option_snio_settings', array( $this, 'handle_settings_option_updated' ), 10, 3 );
    }

    /**
     * Purge frontend caches when Lazy Load settings change.
     *
     * @param mixed  $old_value Previous settings.
     * @param mixed  $value     New settings.
     * @param string $option    Updated option name.
     */
    public function handle_settings_option_updated( $old_value, $value, string $option = '' ): void {
        if ( $option !== 'snio_settings' ) {
            return;
        }

        $old = is_array( $old_value ) ? $old_value : array();
        $new = is_array( $value ) ? $value : array();
        $keys = array( 'lazy_enabled', 'lazy_skip_first' );

        foreach ( $keys as $key ) {
            if ( ( $old[ $key ] ?? null ) !== ( $new[ $key ] ?? null ) ) {
                if ( function_exists( 'snio_clear_frontend_cache' ) ) {
                    $result = snio_clear_frontend_cache();
                    if ( class_exists( 'SNIO_Logger' ) ) {
                        $suffix = ! empty( $result['cleared'] ) && is_array( $result['cleared'] ) ? ' [' . implode( ', ', $result['cleared'] ) . ']' : '';
                        SNIO_Logger::log( 'info', 'Lazy Load settings changed — cache refresh triggered.' . $suffix );
                    }
                }
                break;
            }
        }
    }

    public function register_menu(): void {
        if ( class_exists( 'SNIO_Admin' ) ) {
            return;
        }

        add_options_page(
            __( 'SNIO WebP', 'snake-image-optimizer' ),
            __( 'SNIO WebP', 'snake-image-optimizer' ),
            'manage_options',
            'snio-settings',
            array( $this, 'render_page' )
        );
    }

    public function register_settings(): void {
        register_setting(
            'snio_settings_group',
            'snio_settings',
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize' ),
                'default'           => snio_default_settings(),
            )
        );
    }

    /**
     * @param mixed $input
     * @return array<string,mixed>
     */
    public function sanitize( $input ): array {
        $input    = is_array( $input ) ? $input : array();
        $existing = get_option( 'snio_settings', array() );
        $existing = is_array( $existing ) ? $existing : array();
        return snio_clean_settings_array( array_merge( $existing, $input ) );
    }

    private function ui_toggle( string $key, string $label, bool $checked ): string {
        $k = esc_attr( $key );
        $html  = '<label class="snio-switchx">';
        $html .= '<input type="hidden" name="snio_settings[' . $k . ']" value="0" />';
        $html .= '<input class="snio-qs" data-key="' . $k . '" type="checkbox" name="snio_settings[' . $k . ']" value="1" ' . checked( true, $checked, false ) . ' />';
        $html .= '<span class="snio-switchx__ui" aria-hidden="true"></span>';
        $html .= '<span class="snio-switchx__label">' . esc_html( $label ) . '</span>';
        $html .= '</label>';
        return $html;
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $s                  = snio_get_settings();
        $history            = class_exists( 'SNIO_Bulk_History' ) ? SNIO_Bulk_History::instance()->state() : array( 'used' => 0 );
        $used               = (int) ( $history['used'] ?? 0 );
        $pct                = 0;
        $has_eligible_media = function_exists( 'snio_bulk_has_eligible_media' ) ? snio_bulk_has_eligible_media() : false;

        if ( class_exists( 'SNIO_Admin' ) ) {
            SNIO_Admin::instance()->render_shell_start(
                'snio-settings',
                __( 'Snake Image Optimizer – Core', 'snake-image-optimizer' ),
                __( 'Unlimited WebP Converter for uploads and unlimited Bulk for existing Media Library images.', 'snake-image-optimizer' )
            );
        } else {
            echo '<div class="wrap">';
        }

        echo '<form method="post" action="options.php" class="snio-form">';
        settings_fields( 'snio_settings_group' );
        echo '<div class="snio-grid2">';

        echo '<div class="snio-cardx snio-cardx--core">';
        echo '<div class="snio-cardx__head"><h2>' . esc_html__( 'Core', 'snake-image-optimizer' ) . '</h2><span class="snio-badge">' . esc_html__( 'WebP', 'snake-image-optimizer' ) . '</span></div>';
        echo '<p class="snio-muted">' . esc_html__( 'Keep the core setup simple: enable local WebP conversion and serve WebP automatically for supported visitors. Changes save instantly here too.', 'snake-image-optimizer' ) . '</p>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML built from escaped attributes and text.
        echo $this->ui_toggle( 'enabled', __( 'Enable conversion', 'snake-image-optimizer' ), ! empty( $s['enabled'] ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML built from escaped attributes and text.
        echo $this->ui_toggle( 'serve_webp', __( 'Serve WebP', 'snake-image-optimizer' ), ! empty( $s['serve_webp'] ) );
        echo '<p class="snio-field__hint" style="margin:6px 0 0 0;">' . esc_html__( 'Original files stay available as a fallback.', 'snake-image-optimizer' ) . '</p>';
        echo '<div class="snio-cardx__actions"><button type="button" class="button button-primary snio-qs-save">' . esc_html__( 'Save settings', 'snake-image-optimizer' ) . '</button></div>';
        echo '</div>';

        echo '<div class="snio-cardx snio-cardx--bulk">';
        echo '<div class="snio-cardx__head"><h2>' . esc_html__( 'Bulk', 'snake-image-optimizer' ) . '</h2><span class="snio-badge">' . esc_html__( 'Unlimited', 'snake-image-optimizer' ) . '</span></div>';
        echo '<p class="snio-muted">' . esc_html__( 'Bulk can optimize eligible Media Library images without a plugin-imposed limit.', 'snake-image-optimizer' ) . '</p>';
        if ( ! $has_eligible_media ) {
            echo '<p class="snio-field__hint" style="margin-top:0;">' . esc_html__( 'No eligible images for conversion.', 'snake-image-optimizer' ) . '</p>';
        }
        echo '<div class="snio-progress">';
        /* translators: %d: number of converted images. */
        $converted_label = sprintf( esc_html__( '%d converted', 'snake-image-optimizer' ), $used );
        echo '<div class="snio-progress__meta"><span id="snio-bulk-converted-count">' . esc_html( $converted_label ) . '</span><span>' . esc_html__( 'Unlimited', 'snake-image-optimizer' ) . '</span></div>';
        echo '<div class="snio-progress__bar"><span id="snio-bulk-bar" style="width:' . esc_attr( (string) $pct ) . '%"></span></div>';
        echo '</div>';
        echo '<div class="snio-cardx__actions">';
        echo '<button type="button" class="button button-primary" id="snio-bulk-start" ' . ( ! $has_eligible_media ? 'disabled' : '' ) . ( ! $has_eligible_media ? ' data-disable-after-finish="1"' : '' ) . '>' . esc_html__( 'Start Bulk', 'snake-image-optimizer' ) . '</button>';
        echo '<button type="button" class="button" id="snio-bulk-cancel" style="display:none">' . esc_html__( 'Cancel', 'snake-image-optimizer' ) . '</button>';
        echo '</div>';
        $bulk_note = ! $has_eligible_media ? __( 'No eligible images for conversion.', 'snake-image-optimizer' ) : __( 'Ready to optimize eligible Media Library images.', 'snake-image-optimizer' );
        echo '<div id="snio-bulk-status" class="snio-inline-status">' . esc_html( $bulk_note ) . '</div>';
        echo '<div id="snio-bulk-log" class="snio-logbox" style="display:none"></div>';
        echo '</div>';

        echo '<div class="snio-cardx snio-cardx--delivery">';
        echo '<div class="snio-cardx__head"><h2>' . esc_html__( 'Lazy Load', 'snake-image-optimizer' ) . '</h2><span class="snio-badge">' . esc_html__( 'Included', 'snake-image-optimizer' ) . '</span></div>';
        echo '<p class="snio-muted">' . esc_html__( 'Configure native Lazy Load settings and run a quick test from the dedicated page.', 'snake-image-optimizer' ) . '</p>';
        echo '<div class="snio-cardx__actions"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=snio-lazy' ) ) . '">' . esc_html__( 'Open Lazy Load', 'snake-image-optimizer' ) . '</a></div>';
        echo '</div>';

        echo '<div class="snio-cardx snio-cardx--mini">';
        echo '<div class="snio-cardx__head"><h2>' . esc_html__( 'Support Pages', 'snake-image-optimizer' ) . '</h2></div>';
        echo '<p class="snio-muted">' . esc_html__( 'Logs help you review plugin activity. Diagnostics checks whether the server is ready for local WebP conversion.', 'snake-image-optimizer' ) . '</p>';
        echo '<div class="snio-cardx__actions">';
        echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=snio-logs' ) ) . '">' . esc_html__( 'Open Logs', 'snake-image-optimizer' ) . '</a> ';
        echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=snio-diagnostics' ) ) . '">' . esc_html__( 'Open Diagnostics', 'snake-image-optimizer' ) . '</a>';
        echo '</div>';
        echo '</div>';

        echo '</div>';
        submit_button( __( 'Save all', 'snake-image-optimizer' ) );
        echo '</form>';

        if ( class_exists( 'SNIO_Admin' ) ) {
            SNIO_Admin::instance()->render_shell_end();
        } else {
            echo '</div>';
        }
    }

    public function handle_clear_logs(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden' );
        }
        check_admin_referer( 'snio_clear_logs' );
        SNIO_Logger::clear();
        wp_safe_redirect( admin_url( 'admin.php?page=snio-logs' ) );
        exit;
    }

    public function render_lazy_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $s       = snio_get_settings();
        $enabled = ! empty( $s['lazy_enabled'] );
        $skip    = isset( $s['lazy_skip_first'] ) ? (int) $s['lazy_skip_first'] : 0;
        if ( $skip === 2 ) {
            $skip = 0;
        }

        if ( class_exists( 'SNIO_Admin' ) ) {
            SNIO_Admin::instance()->render_shell_start(
                'snio-lazy',
                __( 'Snake Image Optimizer – Lazy Load', 'snake-image-optimizer' ),
                __( 'Configure native Lazy Load behavior for WordPress attachment images.', 'snake-image-optimizer' )
            );
        } else {
            echo '<div class="wrap">';
        }

        echo '<form method="post" action="options.php" class="snio-form">';
        settings_fields( 'snio_settings_group' );
        echo '<div class="snio-grid2">';

        echo '<div class="snio-cardx snio-cardx--delivery snio-cardx--wide">';
        echo '<div class="snio-cardx__head"><h2>' . esc_html__( 'Lazy Load', 'snake-image-optimizer' ) . '</h2><span class="snio-badge">' . esc_html__( 'Native', 'snake-image-optimizer' ) . '</span></div>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML built from escaped attributes and text.
        echo $this->ui_toggle( 'lazy_enabled', __( 'Enable Lazy Load', 'snake-image-optimizer' ), $enabled );

        echo '<label class="snio-field">';
        echo '<span class="snio-field__label">' . esc_html__( 'Skip first N images', 'snake-image-optimizer' ) . '</span>';
        echo '<input class="snio-inputx snio-qs-text" data-key="lazy_skip_first" type="number" min="0" name="snio_settings[lazy_skip_first]" value="' . esc_attr( (string) max( 0, $skip ) ) . '" />';
        echo '<span class="snio-field__hint">' . esc_html__( 'Useful for hero and above-the-fold images.', 'snake-image-optimizer' ) . '</span>';
        echo '</label>';

        echo '<div class="snio-cardx__actions">';
        echo '<button type="button" class="button button-primary snio-qs-save">' . esc_html__( 'Save Lazy Load settings', 'snake-image-optimizer' ) . '</button>';
        echo '<button type="button" class="button button-primary" id="snio-lazy-test">' . esc_html__( 'Run test', 'snake-image-optimizer' ) . '</button>';
        echo '<button type="button" class="button" id="snio-lazy-clear-cache">' . esc_html__( 'Clear cache', 'snake-image-optimizer' ) . '</button>';
        echo '</div>';
        echo '<div id="snio-lazy-test-result" class="notice" style="display:none;margin-top:12px"></div>';
        echo '<div id="snio-lazy-cache-result" class="notice" style="display:none;margin-top:12px"></div>';
        echo '</div>';

        echo '</div>';
        submit_button( __( 'Save all', 'snake-image-optimizer' ) );
        echo '</form>';

        if ( class_exists( 'SNIO_Admin' ) ) {
            SNIO_Admin::instance()->render_shell_end();
        } else {
            echo '</div>';
        }
    }
}
