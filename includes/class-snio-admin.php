<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SNIO_Admin {
    private static ?SNIO_Admin $instance = null;

    public static function instance(): SNIO_Admin {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }


private function __construct() {
    add_action( 'admin_menu', array( $this, 'register_menu' ), 9 );
    add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    add_action( 'wp_ajax_snio_lazy_test', array( $this, 'ajax_lazy_test' ) );
    add_action( 'wp_ajax_snio_quick_save', array( $this, 'ajax_quick_save' ) );
    add_action( 'wp_ajax_snio_bulk', array( $this, 'ajax_bulk' ) );
    add_action( 'wp_ajax_snio_dashboard_stats', array( $this, 'ajax_dashboard_stats' ) );
    add_action( 'wp_ajax_snio_clear_cache', array( $this, 'ajax_clear_cache' ) );
    add_action( 'delete_attachment', array( $this, 'clear_dashboard_stats_cache' ) );
}


public function register_menu(): void {
    $cap = 'manage_options';

    add_menu_page(
        __( 'Snake Image Optimizer', 'snake-image-optimizer' ),
        __( 'Snake Image Optimizer', 'snake-image-optimizer' ),
        $cap,
        'snake-image-optimizer',
        array( $this, 'render_dashboard' ),
        'dashicons-images-alt2',
        58
    );

    add_submenu_page( 'snake-image-optimizer', __( 'Dashboard', 'snake-image-optimizer' ), __( 'Dashboard', 'snake-image-optimizer' ), $cap, 'snake-image-optimizer', array( $this, 'render_dashboard' ) );
    add_submenu_page( 'snake-image-optimizer', __( 'Core', 'snake-image-optimizer' ), __( 'Core', 'snake-image-optimizer' ), $cap, 'snio-settings', array( SNIO_Settings::instance(), 'render_page' ) );
    add_submenu_page( 'snake-image-optimizer', __( 'Lazy Load', 'snake-image-optimizer' ), __( 'Lazy Load', 'snake-image-optimizer' ), $cap, 'snio-lazy', array( SNIO_Settings::instance(), 'render_lazy_page' ) );
    add_submenu_page( 'snake-image-optimizer', __( 'Logs', 'snake-image-optimizer' ), __( 'Logs', 'snake-image-optimizer' ), $cap, 'snio-logs', array( $this, 'render_logs' ) );
    add_submenu_page( 'snake-image-optimizer', __( 'Diagnostics', 'snake-image-optimizer' ), __( 'Diagnostics', 'snake-image-optimizer' ), $cap, 'snio-diagnostics', array( $this, 'render_diagnostics' ) );

}

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'snake-image-optimizer' ) === false ) {
            return;
        }
        $v_css = file_exists( SNIO_PLUGIN_DIR . 'assets/admin.css' ) ? (string) filemtime( SNIO_PLUGIN_DIR . 'assets/admin.css' ) : SNIO_VERSION;
        $v_js  = file_exists( SNIO_PLUGIN_DIR . 'assets/admin.js' ) ? (string) filemtime( SNIO_PLUGIN_DIR . 'assets/admin.js' ) : SNIO_VERSION;


        wp_enqueue_style(
            'snio-admin',
            SNIO_PLUGIN_URL . 'assets/admin.css',
            array(),
            $v_css
        );

        wp_enqueue_script(
            'snio-admin',
            SNIO_PLUGIN_URL . 'assets/admin.js',
            array( 'jquery' ),
            $v_js,
            true
        );

        wp_localize_script(
            'snio-admin',
            'SNIO_Admin',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'siteUrl' => home_url( '/' ),
                'nonce'      => wp_create_nonce( 'snio_lazy_test' ),
                'nonceSave'  => wp_create_nonce( 'snio_quick_save' ),
                'nonceBulk'  => wp_create_nonce( 'snio_bulk' ),
                'nonceDashboard' => wp_create_nonce( 'snio_dashboard_stats' ),
                'nonceClearCache' => wp_create_nonce( 'snio_clear_cache' ),
                'i18n'    => array(
                    'running' => __( 'Running test…', 'snake-image-optimizer' ),
                    'failed'  => __( 'The test failed.', 'snake-image-optimizer' ),
                    'ajaxErr' => __( 'AJAX request failed.', 'snake-image-optimizer' ),
                    'bulkStart' => __( 'Starting bulk optimize…', 'snake-image-optimizer' ),
                    'bulkRunning' => __( 'Bulk optimize in progress…', 'snake-image-optimizer' ),
                    'bulkDone' => __( 'Bulk optimize completed.', 'snake-image-optimizer' ),
                    'bulkCancel' => __( 'Cancel', 'snake-image-optimizer' ),
                    'refreshingStats' => __( 'Refreshing dashboard stats…', 'snake-image-optimizer' ),
                    'statsUpdated' => __( 'Dashboard stats updated.', 'snake-image-optimizer' ),
                    'cacheClearing' => __( 'Clearing cache…', 'snake-image-optimizer' ),
                    'cacheCleared' => __( 'Cache cleared.', 'snake-image-optimizer' ),
                ),
            )
        );
    }



public function render_shell_start( string $active_slug, string $title, string $subtitle = '' ): void {
    $history = class_exists( 'SNIO_Bulk_History' ) ? SNIO_Bulk_History::instance()->state() : array( 'used' => 0 );
    $used    = (int) ( $history['used'] ?? 0 );
    $pct     = 0;
    $brand_subtitle = __( 'by Mahmoud Hamed', 'snake-image-optimizer' );

    echo '<div class="wrap snio-app snio-app--' . esc_attr( sanitize_html_class( $active_slug ) ) . '">';
    echo '<div class="snio-topbar">';
    echo '<div class="snio-topbar__brand"><div class="snio-logoMark"><span class="dashicons dashicons-format-image"></span></div><div class="snio-brandstack"><div class="snio-logo">Snake Image Optimizer</div><div class="snio-brandsub">' . esc_html( $brand_subtitle ) . '</div></div><div class="snio-plan">' . esc_html__( 'Free', 'snake-image-optimizer' ) . '</div></div>';
    echo '<div class="snio-topbar__actions">';
    echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=snake-image-optimizer' ) ) . '">' . esc_html__( 'Dashboard', 'snake-image-optimizer' ) . '</a> ';
    echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=snio-logs' ) ) . '">' . esc_html__( 'Logs', 'snake-image-optimizer' ) . '</a> ';
    echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=snio-diagnostics' ) ) . '">' . esc_html__( 'Diagnostics', 'snake-image-optimizer' ) . '</a>';
    echo '</div></div>';

    echo '<div class="snio-shell"><aside class="snio-nav">';
    $nav = array(
        array( 'slug' => 'snake-image-optimizer', 'label' => __( 'Dashboard', 'snake-image-optimizer' ), 'icon' => 'dashicons-dashboard' ),
        array( 'slug' => 'snio-settings', 'label' => __( 'Core', 'snake-image-optimizer' ), 'icon' => 'dashicons-images-alt2' ),
        array( 'slug' => 'snio-lazy', 'label' => __( 'Lazy Load', 'snake-image-optimizer' ), 'icon' => 'dashicons-update' ),
        array( 'slug' => 'snio-logs', 'label' => __( 'Logs', 'snake-image-optimizer' ), 'icon' => 'dashicons-list-view' ),
        array( 'slug' => 'snio-diagnostics', 'label' => __( 'Diagnostics', 'snake-image-optimizer' ), 'icon' => 'dashicons-heart' ),
    );
    echo '<ul>';
    foreach ( $nav as $item ) {
        $url    = admin_url( 'admin.php?page=' . $item['slug'] );
        $active = $item['slug'] === $active_slug ? ' is-active' : '';
        echo '<li><a class="snio-nav__link' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '"><span class="dashicons ' . esc_attr( $item['icon'] ) . '"></span><span>' . esc_html( $item['label'] ) . '</span></a></li>';
    }
    echo '</ul>';
    echo '<div class="snio-nav__hint"><div class="snio-progress"><div class="snio-progress__meta"><span>' . esc_html__( 'Bulk', 'snake-image-optimizer' ) . '</span><span>' . esc_html__( 'Unlimited', 'snake-image-optimizer' ) . '</span></div><div class="snio-progress__bar"><span style="width:' . esc_attr( (string) $pct ) . '%"></span></div></div><div class="snio-nav__subhint">&nbsp;</div></div>';
    echo '</aside><main class="snio-main"><div class="snio-pagehead"><h1 class="snio-pagehead__title">' . esc_html( $title ) . '</h1>';
    if ( $subtitle !== '' ) {
        echo '<p class="snio-pagehead__sub">' . esc_html( $subtitle ) . '</p>';
    }
    echo '</div>';
}

    public function render_shell_end(): void {
        echo '</main></div>';
        echo '<div id="snio-toast" class="snio-toast" style="display:none"></div>';
        echo '</div>';
    }

    public function ajax_lazy_test(): void {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You are not allowed to perform this action.', 'snake-image-optimizer' ) ),
                403
            );
        }

        if ( ! check_ajax_referer( 'snio_lazy_test', '_ajax_nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid nonce. Please refresh the page and try again.', 'snake-image-optimizer' ) ), 403 );
        }


        $settings = snio_get_settings();
        if ( empty( $settings['lazy_enabled'] ) ) {
            wp_send_json_success(
                array(
                    'message' => __( 'Lazy Load is disabled in settings. Enable it first, then run the test again.', 'snake-image-optimizer' ),
                    'enabled' => false,
                )
            );
        }

        $url = isset( $_POST['url'] ) ? esc_url_raw( (string) wp_unslash( $_POST['url'] ) ) : home_url( '/' );
        if ( $url === '' ) {
            $url = home_url( '/' );
        }

        // Prevent SSRF: allow only this site's host.
        $home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        $test_host = wp_parse_url( $url, PHP_URL_HOST );
        if ( $home_host && $test_host && strcasecmp( (string) $home_host, (string) $test_host ) !== 0 ) {
            wp_send_json_error(
                array( 'message' => __( 'Please test a URL on this site only.', 'snake-image-optimizer' ) ),
                400
            );
        }

        $url = add_query_arg(
            array(
                'snio_lazy_test' => '1',
                'snio_rand'      => (string) wp_rand( 100000, 999999 ),
            ),
            $url
        );

        $resp = wp_remote_get(
            $url,
            array(
                'timeout'     => 12,
                'redirection' => 3,
                'headers'     => array(
                    'Cache-Control' => 'no-cache',
                ),
                'user-agent'  => 'SNIO Lazy Test',
            )
        );

        if ( is_wp_error( $resp ) ) {
            wp_send_json_error(
                array( 'message' => $resp->get_error_message() ),
                500
            );
        }

        $code = (int) wp_remote_retrieve_response_code( $resp );
        $body = (string) wp_remote_retrieve_body( $resp );

        if ( $code < 200 || $code >= 300 || $body === '' ) {
            wp_send_json_error(
                array(
                    'message' => sprintf(
                        /* translators: %d: HTTP status code */
                        __( 'Could not fetch the test page (HTTP %d). Please verify the URL and try again.', 'snake-image-optimizer' ),
                        $code
                    ),
                ),
                500
            );
        }

        $lazy_marked = preg_match_all( '/data-snio-lazy\s*=\s*["\']lazy["\']/i', $body, $m1 );
        $skip_marked = preg_match_all( '/data-snio-lazy\s*=\s*["\']skip["\']/i', $body, $m2 );
        $loading_lazy = preg_match_all( '/<img\b[^>]*\bloading\s*=\s*["\']lazy["\']/i', $body, $m3 );

        $msg = sprintf(
            /* translators: 1: lazy-marked images, 2: skip-marked images, 3: img tags with loading=lazy */
            __( 'Lazy Load appears to be working. lazy-marked images: %1$d, skip-marked images: %2$d, images with loading="lazy": %3$d.', 'snake-image-optimizer' ),
            (int) $lazy_marked,
            (int) $skip_marked,
            (int) $loading_lazy
        );

        if ( (int) $lazy_marked === 0 && (int) $skip_marked === 0 ) {
            $msg .= ' ' . __( 'No SNIO test markers were found. The page may not contain WordPress attachment images, or a cache/CDN may have returned a cached response. Try another page URL and bypass cache if possible.', 'snake-image-optimizer' );
        }

        wp_send_json_success(
            array(
                'message' => $msg,
                'enabled' => true,
                'counts'  => array(
                    'lazy_marked'  => (int) $lazy_marked,
                    'skip_marked'  => (int) $skip_marked,
                    'loading_lazy' => (int) $loading_lazy,
                ),
                'tested_url' => $url,
            )
        );
    }

    private function card( string $title, string $desc, string $url, string $badge = '' ): void {
        echo '<a class="snio-card" href="' . esc_url( $url ) . '">';
        echo '<div class="snio-card__head">';
        echo '<h3 class="snio-card__title">' . esc_html( $title ) . '</h3>';
        if ( $badge !== '' ) {
            echo '<span class="snio-badge">' . esc_html( $badge ) . '</span>';
        }
        echo '</div>';
        echo '<p class="snio-card__desc">' . esc_html( $desc ) . '</p>';
        echo '</a>';
    }

    private function dashboard_stats_cache_key(): string {
        return function_exists( 'snio_dashboard_stats_cache_key' )
            ? snio_dashboard_stats_cache_key()
            : 'snio_dashboard_media_stats_v1';
    }

    public function clear_dashboard_stats_cache( int $attachment_id = 0 ): void {
        if ( function_exists( 'snio_clear_dashboard_stats_cache' ) ) {
            snio_clear_dashboard_stats_cache();
            return;
        }

        delete_transient( $this->dashboard_stats_cache_key() );
    }

    /**
     * @return array<string,int>
     */
    private function get_dashboard_media_stats( bool $force = false ): array {
        $cache_key = $this->dashboard_stats_cache_key();


        $cached = wp_cache_get( $cache_key, 'snake-image-optimizer' );
        if ( is_array( $cached ) && ! $force ) {
            return $cached;
        }

        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $mime_types = array( 'image/jpeg', 'image/png' );

        $total_q = new WP_Query(
            array(
                'post_type'              => 'attachment',
                'post_status'            => array( 'inherit', 'private' ),
                'post_mime_type'         => $mime_types,
                'fields'                 => 'ids',
                'posts_per_page'         => 1,
                'no_found_rows'          => false,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            )
        );
        $total_images = (int) $total_q->found_posts;

        $optimized_query_args = array(
            'post_type'              => 'attachment',
            'post_status'            => array( 'inherit', 'private' ),
            'post_mime_type'         => $mime_types,
            'fields'                 => 'ids',
            'posts_per_page'         => 200,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required to count optimized attachments with generated variants.
            'meta_key'               => '_snio_generated',
            'meta_compare'           => 'EXISTS',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );

        $optimized_images   = 0;
        $generated_variants = 0;
        $total_saved_bytes  = 0;
        $total_source_bytes = 0;

        $offset = 0;
        do {
            $batch = get_posts( array_merge( $optimized_query_args, array( 'offset' => $offset ) ) );
            if ( empty( $batch ) ) {
                break;
            }

            foreach ( $batch as $attachment_id ) {
                $attachment_id = (int) $attachment_id;
                if ( $attachment_id <= 0 ) {
                    continue;
                }

                $generated = get_post_meta( $attachment_id, '_snio_generated', true );
                if ( ! is_array( $generated ) || empty( $generated ) ) {
                    continue;
                }

                $seen         = array();
                $has_variants = false;

                foreach ( $generated as $row ) {
                    if ( ! is_array( $row ) ) {
                        continue;
                    }

                    $src  = isset( $row['src'] ) && is_string( $row['src'] ) ? wp_normalize_path( $row['src'] ) : '';
                    $dest = isset( $row['dest'] ) && is_string( $row['dest'] ) ? wp_normalize_path( $row['dest'] ) : '';

                    if ( $src === '' || $dest === '' ) {
                        continue;
                    }

                    $hash = md5( $src . '|' . $dest );
                    if ( isset( $seen[ $hash ] ) ) {
                        continue;
                    }
                    $seen[ $hash ] = true;

                    if ( ! file_exists( $src ) || ! file_exists( $dest ) ) {
                        continue;
                    }

                    $bytes_src  = @filesize( $src );
                    $bytes_dest = @filesize( $dest );

                    if ( ! is_numeric( $bytes_src ) || ! is_numeric( $bytes_dest ) ) {
                        continue;
                    }

                    $bytes_src  = (int) $bytes_src;
                    $bytes_dest = (int) $bytes_dest;

                    if ( $bytes_src <= 0 || $bytes_dest <= 0 ) {
                        continue;
                    }

                    $has_variants = true;
                    $generated_variants++;
                    $total_source_bytes += $bytes_src;

                    if ( $bytes_dest < $bytes_src ) {
                        $total_saved_bytes += ( $bytes_src - $bytes_dest );
                    }
                }

                if ( $has_variants ) {
                    $optimized_images++;
                }
            }

            $offset += (int) $optimized_query_args['posts_per_page'];
        } while ( true );

        $optimized_pct = $total_images > 0
            ? (int) max( 0, min( 100, round( ( $optimized_images / $total_images ) * 100 ) ) )
            : 0;

        $total_saved_pct = $total_source_bytes > 0
            ? (int) max( 0, min( 100, round( ( $total_saved_bytes / $total_source_bytes ) * 100 ) ) )
            : 0;

        $stats = array(
            'total_images'       => $total_images,
            'optimized_images'   => $optimized_images,
            'optimized_pct'      => $optimized_pct,
            'total_saved_bytes'  => $total_saved_bytes,
            'total_saved_pct'    => $total_saved_pct,
            'generated_variants' => $generated_variants,
            'calculated_at'      => time(),
        );

        set_transient( $cache_key, $stats, 15 * MINUTE_IN_SECONDS );

        return $stats;
    }

    /**
     * @return array<string,int>
     */
    public function dashboard_media_stats(): array {
        return $this->get_dashboard_media_stats( false );
    }

    /**
     * @param array<string,int> $stats
     * @return array<string,string|int>
     */
    private function present_dashboard_media_stats( array $stats ): array {
        $total_images       = (int) ( $stats['total_images'] ?? 0 );
        $optimized_images   = (int) ( $stats['optimized_images'] ?? 0 );
        $optimized_pct      = (int) ( $stats['optimized_pct'] ?? 0 );
        $total_saved_bytes  = (int) ( $stats['total_saved_bytes'] ?? 0 );
        $total_saved_pct    = (int) ( $stats['total_saved_pct'] ?? 0 );
        $generated_variants = (int) ( $stats['generated_variants'] ?? 0 );
        $calculated_at      = (int) ( $stats['calculated_at'] ?? 0 );

        if ( $total_images <= 0 ) {
            $summary = __( 'No JPG or PNG images were found in the Media Library yet.', 'snake-image-optimizer' );
        } elseif ( $optimized_images <= 0 ) {
            $summary = __( 'No supported images have been optimized yet.', 'snake-image-optimizer' );
        } elseif ( $optimized_pct >= 100 ) {
            $summary = __( 'All supported Media Library images are currently optimized.', 'snake-image-optimizer' );
        } else {
            $summary = sprintf(
                /* translators: 1: optimized images, 2: total images. */
                __( '%1$d of %2$d supported images are optimized.', 'snake-image-optimizer' ),
                $optimized_images,
                $total_images
            );
        }

        $last_updated = $calculated_at > 0
            ? sprintf(
                /* translators: %s: date/time. */
                __( 'Last updated: %s', 'snake-image-optimizer' ),
                wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $calculated_at )
            )
            : __( 'Last updated: —', 'snake-image-optimizer' );

        return array(
            'total_images'       => (string) $total_images,
            'optimized_images'   => (string) $optimized_images,
            'optimized_pct'      => $optimized_pct,
            'total_saved_bytes'  => size_format( max( 0, $total_saved_bytes ), 2 ),
            'total_saved_pct'    => (string) $total_saved_pct,
            'generated_variants' => (string) $generated_variants,
            'summary'            => $summary,
            'last_updated'       => $last_updated,
        );
    }

    public function ajax_dashboard_stats(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden', 'snake-image-optimizer' ) ), 403 );
        }

        if ( ! check_ajax_referer( 'snio_dashboard_stats', '_ajax_nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid nonce. Please refresh and try again.', 'snake-image-optimizer' ) ), 403 );
        }

        $stats = $this->get_dashboard_media_stats( true );

        wp_send_json_success(
            array(
                'message' => __( 'Dashboard stats updated.', 'snake-image-optimizer' ),
                'stats'   => $stats,
                'display' => $this->present_dashboard_media_stats( $stats ),
            )
        );
    }


public function render_dashboard(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $s         = snio_get_settings();
    $history   = class_exists( 'SNIO_Bulk_History' ) ? SNIO_Bulk_History::instance()->state() : array( 'used' => 0 );
    $used      = (int) ( $history['used'] ?? 0 );
    $pct       = 0;
    $stats     = $this->dashboard_media_stats();
    $display   = $this->present_dashboard_media_stats( $stats );
    $has_eligible_media = function_exists( 'snio_bulk_has_eligible_media' ) ? snio_bulk_has_eligible_media() : false;

    $this->render_shell_start(
        'snake-image-optimizer',
        __( 'Snake Image Optimizer Dashboard', 'snake-image-optimizer' ),
        __( 'Unlimited WebP Converter, unlimited Bulk, Lazy Load, logs, and diagnostics.', 'snake-image-optimizer' )
    );

    echo '<div class="snio-grid2">';

    echo '<div class="snio-cardx snio-cardx--wide snio-cardx--core">';
    echo '<div class="snio-cardx__head"><h2>' . esc_html__( 'Core settings', 'snake-image-optimizer' ) . '</h2><span class="snio-badge">' . esc_html__( 'WebP', 'snake-image-optimizer' ) . '</span></div>';
    echo '<p class="snio-muted">' . esc_html__( 'Keep WebP conversion enabled for new uploads.', 'snake-image-optimizer' ) . '</p>';
    echo '<label class="snio-switchx"><input class="snio-qs" data-key="enabled" type="checkbox" value="1" ' . checked( true, ! empty( $s['enabled'] ), false ) . '><span class="snio-switchx__ui" aria-hidden="true"></span><span class="snio-switchx__label">' . esc_html__( 'Enable conversion', 'snake-image-optimizer' ) . '</span></label>';
    echo '<label class="snio-switchx"><input class="snio-qs" data-key="serve_webp" type="checkbox" value="1" ' . checked( true, ! empty( $s['serve_webp'] ), false ) . '><span class="snio-switchx__ui" aria-hidden="true"></span><span class="snio-switchx__label">' . esc_html__( 'Serve WebP', 'snake-image-optimizer' ) . '</span></label>';
    echo '<p class="snio-field__hint" style="margin:8px 0 0 0;">' . esc_html__( 'Original files remain available as fallback.', 'snake-image-optimizer' ) . '</p>';
    echo '<div class="snio-cardx__actions"><button type="button" class="button button-primary snio-qs-save">' . esc_html__( 'Save settings', 'snake-image-optimizer' ) . '</button><a class="button" href="' . esc_url( admin_url( 'admin.php?page=snio-settings' ) ) . '">' . esc_html__( 'Open Core', 'snake-image-optimizer' ) . '</a></div>';
    echo '</div>';

    echo '<div class="snio-cardx snio-cardx--wide">';
    echo '<div class="snio-cardx__head"><h2>' . esc_html__( 'Bulk', 'snake-image-optimizer' ) . '</h2><span class="snio-badge">' . esc_html__( 'Unlimited', 'snake-image-optimizer' ) . '</span></div>';
    /* translators: %d: number of converted images. */
    $converted_label = sprintf( esc_html__( '%d converted', 'snake-image-optimizer' ), $used );
    echo '<div class="snio-progress"><div class="snio-progress__meta"><span id="snio-bulk-converted-count">' . esc_html( $converted_label ) . '</span><span>' . esc_html__( 'Unlimited', 'snake-image-optimizer' ) . '</span></div><div class="snio-progress__bar"><span id="snio-bulk-bar" style="width:' . esc_attr( (string) $pct ) . '%"></span></div></div>';
    echo '<div class="snio-cardx__actions"><button type="button" class="button" id="snio-bulk-start" ' . ( ! $has_eligible_media ? 'disabled' : '' ) . ( ! $has_eligible_media ? ' data-disable-after-finish="1"' : '' ) . '>' . esc_html__( 'Start Bulk', 'snake-image-optimizer' ) . '</button><button type="button" class="button" id="snio-bulk-cancel" style="display:none">' . esc_html__( 'Cancel', 'snake-image-optimizer' ) . '</button></div>';
    $bulk_note = ! $has_eligible_media
        ? __( 'No eligible images for conversion.', 'snake-image-optimizer' )
        : __( 'Ready to optimize eligible Media Library images.', 'snake-image-optimizer' );
    echo '<div id="snio-bulk-status" class="snio-inline-status">' . esc_html( $bulk_note ) . '</div><div id="snio-bulk-log" class="snio-logbox" style="display:none"></div>';
    echo '</div>';

    echo '<div class="snio-cardx snio-cardx--mini">';
    echo '<div class="snio-cardx__head"><h2>' . esc_html__( 'Included', 'snake-image-optimizer' ) . '</h2><span class="snio-badge">' . esc_html__( 'by Mahmoud Hamed', 'snake-image-optimizer' ) . '</span></div>';
    echo '<ul class="snio-features">';
    foreach ( array( 'Unlimited WebP Converter for uploads', 'Automatic WebP delivery', 'Lazy Load', 'Unlimited Bulk', 'Logs', 'Diagnostics' ) as $line ) {
        echo '<li class="is-on"><span class="dashicons dashicons-yes"></span>' . esc_html( $line ) . '</li>';
    }
    echo '</ul>';
    echo '</div>';

    echo '<div class="snio-cardx snio-cardx--delivery">';
    echo '<div class="snio-cardx__head"><h2>' . esc_html__( 'Lazy Load', 'snake-image-optimizer' ) . '</h2><span class="snio-badge">' . esc_html( ! empty( $s['lazy_enabled'] ) ? __( 'On', 'snake-image-optimizer' ) : __( 'Off', 'snake-image-optimizer' ) ) . '</span></div>';
    echo '<p class="snio-muted">' . esc_html__( 'Configure skip count and run a page test from the Lazy Load page.', 'snake-image-optimizer' ) . '</p>';
    echo '<div class="snio-cardx__actions"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=snio-lazy' ) ) . '">' . esc_html__( 'Open Lazy Load', 'snake-image-optimizer' ) . '</a></div>';
    echo '</div>';

    echo '<div class="snio-cardx snio-cardx--mini snio-cardx--wide">';
    echo '<div class="snio-cardx__head"><h2>' . esc_html__( 'Troubleshooting', 'snake-image-optimizer' ) . '</h2></div>';
    echo '<p class="snio-muted">' . esc_html__( 'Use Logs to inspect activity and Diagnostics to verify local WebP support.', 'snake-image-optimizer' ) . '</p>';
    echo '<div class="snio-cardx__actions"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=snio-logs' ) ) . '">' . esc_html__( 'Logs', 'snake-image-optimizer' ) . '</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=snio-diagnostics' ) ) . '">' . esc_html__( 'Diagnostics', 'snake-image-optimizer' ) . '</a></div>';
    echo '</div>';

    echo '</div>';
    $this->render_shell_end();
}

    private function pill( string $label, string $value, bool $active ): void {
        $cls = $active ? 'snio-pill snio-pill--on' : 'snio-pill snio-pill--off';
        echo '<div class="' . esc_attr( $cls ) . '">';
        echo '<span class="snio-pill__label">' . esc_html( $label ) . '</span>';
        echo '<span class="snio-pill__value">' . esc_html( $value ) . '</span>';
        echo '</div>';
    }

    
public function ajax_quick_save(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Forbidden', 'snake-image-optimizer' ) ), 403 );
    }

    if ( ! check_ajax_referer( 'snio_quick_save', '_ajax_nonce', false ) ) {
        wp_send_json_error( array( 'message' => __( 'Invalid nonce. Please refresh the page and try again.', 'snake-image-optimizer' ) ), 403 );
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above with check_ajax_referer().
    $raw_settings = isset( $_POST['settings'] ) ? map_deep( wp_unslash( $_POST['settings'] ), 'sanitize_text_field' ) : array();
    $input        = is_array( $raw_settings ) ? (array) $raw_settings : array();
    $allowed  = array( 'enabled', 'serve_webp', 'lazy_enabled', 'lazy_skip_first' );
    $filtered = array();

    foreach ( $allowed as $k ) {
        if ( array_key_exists( $k, $input ) ) {
            $filtered[ $k ] = $input[ $k ];
        }
    }

    $settings = SNIO_Settings::instance()->sanitize( $filtered );
    update_option( 'snio_settings', $settings, false );

    wp_send_json_success(
        array(
            'message'  => '',
            'settings' => $settings,
        )
    );
}



    public function ajax_clear_cache(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden', 'snake-image-optimizer' ) ), 403 );
        }

        if ( ! check_ajax_referer( 'snio_clear_cache', '_ajax_nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid nonce. Please refresh the page and try again.', 'snake-image-optimizer' ) ), 403 );
        }

        if ( ! function_exists( 'snio_clear_frontend_cache' ) ) {
            wp_send_json_error( array( 'message' => __( 'Cache tools are not available.', 'snake-image-optimizer' ) ), 500 );
        }

        $result = snio_clear_frontend_cache();

        if ( class_exists( 'SNIO_Logger' ) ) {
            $suffix = ! empty( $result['cleared'] ) && is_array( $result['cleared'] ) ? ' [' . implode( ', ', $result['cleared'] ) . ']' : '';
            SNIO_Logger::log( 'info', 'Manual cache clear triggered from Lazy Load.' . $suffix );
        }

        wp_send_json_success(
            array(
                'message' => isset( $result['message'] ) ? (string) $result['message'] : __( 'Cache cleared.', 'snake-image-optimizer' ),
                'cleared' => isset( $result['cleared'] ) && is_array( $result['cleared'] ) ? array_values( $result['cleared'] ) : array(),
            )
        );
    }

    public function ajax_bulk(): void {
        try {
                if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Forbidden', 'snake-image-optimizer' ) ), 403 );
            }

            if ( ! check_ajax_referer( 'snio_bulk', '_ajax_nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid nonce. Please refresh the page and try again.', 'snake-image-optimizer' ) ), 403 );
        }


        $cmd = isset( $_POST['cmd'] ) ? sanitize_key( (string) wp_unslash( $_POST['cmd'] ) ) : 'run';

        if ( class_exists( 'SNIO_Logger' ) && $cmd === 'start' ) {
            SNIO_Logger::log( 'info', 'Bulk: start requested.' );
        }
        $uid = (int) get_current_user_id();
        $tkey = 'snio_bulk_' . $uid;

        if ( $cmd === 'cancel' ) {
            delete_transient( $tkey );
            wp_send_json_success( array( 'message' => __( 'Cancelled.', 'snake-image-optimizer' ) ) );
        }

        $state = get_transient( $tkey );
        if ( ! is_array( $state ) || $cmd === 'start' ) {

            $ids = function_exists( 'snio_bulk_collect_eligible_ids' ) ? snio_bulk_collect_eligible_ids( 0 ) : array();

            if ( empty( $ids ) ) {
                delete_transient( $tkey );
                $history_used = class_exists( 'SNIO_Bulk_History' ) ? (int) ( SNIO_Bulk_History::instance()->state()['used'] ?? 0 ) : 0;
                wp_send_json_success( array(
                    'message'      => __( 'No eligible images for conversion.', 'snake-image-optimizer' ),
                    'progress'     => array( 'done' => 0, 'total' => 0, 'ok' => 0, 'err' => 0 ),
                    'converted'    => array(),
                    'errors'       => array(),
                    'finished'     => true,
                    'no_eligible'  => true,
                    'history_used' => $history_used,
                ) );
            }

            $state = array(
                'ids'   => array_values( $ids ),
                'i'     => 0,
                'total' => count( $ids ),
                'ok'    => 0,
                'err'   => 0,
                'started' => time(),
            );

            set_transient( $tkey, $state, 30 * MINUTE_IN_SECONDS );
        }

        $ids = isset( $state['ids'] ) && is_array( $state['ids'] ) ? $state['ids'] : array();
        $i   = isset( $state['i'] ) ? (int) $state['i'] : 0;
        $total = isset( $state['total'] ) ? (int) $state['total'] : count( $ids );

        $settings = snio_get_settings();
        $formats = snio_get_formats_to_generate( 0, $settings );

        $batch = 3;
        $converted = array();
        $errors = array();

        for ( $n = 0; $n < $batch; $n++ ) {
            if ( $i >= $total ) {
                break;
            }
            $aid = (int) $ids[ $i ];
            $i++;

            try {
                if ( function_exists( 'snio_bulk_attachment_is_eligible' ) && ! snio_bulk_attachment_is_eligible( $aid, $settings, $formats ) ) {
                    continue;
                }

                $conv = new SNIO_Converter();
                $conv->convert_attachment( $aid, $formats, $settings, false, true );
                update_post_meta( $aid, '_snio_last_success', time() );

                $ok = false;
                if ( function_exists( 'snio_is_supported_image_attachment' ) && snio_is_supported_image_attachment( $aid ) ) {
                    $ok = ! $conv->needs_conversion( $aid, $formats, $settings );
                }

                if ( $ok ) {
                    $converted[] = $aid;
                    $state['ok'] = (int) ( $state['ok'] ?? 0 ) + 1;
                } else {
                    $msg = (string) get_post_meta( $aid, '_snio_last_error', true );
                    if ( $msg === '' ) {
                        $msg = __( 'Conversion did not generate WebP.', 'snake-image-optimizer' );
                    }
                    if ( class_exists( 'SNIO_Logger' ) ) {
                        SNIO_Logger::log( 'warning', sprintf( 'Bulk: attachment #%d not converted: %s', $aid, $msg ) );
                    }
                    $errors[] = array( 'id' => $aid, 'message' => substr( sanitize_text_field( (string) $msg ), 0, 500 ) );
                    $state['err'] = (int) ( $state['err'] ?? 0 ) + 1;
                }
            } catch ( Throwable $e ) {
                if ( class_exists( 'SNIO_Logger' ) ) {
                    SNIO_Logger::log( 'error', sprintf( 'Bulk: attachment #%d error: %s', $aid, $e->getMessage() ) );
                }
                $errors[] = array( 'id' => $aid, 'message' => substr( sanitize_text_field( (string) $e->getMessage() ), 0, 500 ) );
                $state['err'] = (int) ( $state['err'] ?? 0 ) + 1;
            }
        }

        $state['i'] = $i;
        set_transient( $tkey, $state, 30 * MINUTE_IN_SECONDS );

        $finished = $i >= $total;
        $ok_count = (int) ( $state['ok'] ?? 0 );
        $err_count = (int) ( $state['err'] ?? 0 );
        $no_eligible = $finished && $ok_count <= 0 && $err_count <= 0;

        if ( $finished ) {
            if ( class_exists( 'SNIO_Logger' ) ) {
                SNIO_Logger::log( 'info', sprintf( 'Bulk: finished. ok=%d err=%d', $ok_count, $err_count ) );
            }
            delete_transient( $tkey );
        }

        $history_used = class_exists( 'SNIO_Bulk_History' ) ? (int) ( SNIO_Bulk_History::instance()->state()['used'] ?? 0 ) : 0;
        $message = __( 'Bulk run in progress…', 'snake-image-optimizer' );
        if ( $finished ) {
            if ( $no_eligible ) {
                $message = __( 'No eligible images for conversion.', 'snake-image-optimizer' );
            } elseif ( $ok_count > 0 ) {
                $message = sprintf(
                    /* translators: %d: number of images converted during the bulk run. */
                    __( 'Bulk run completed. %d converted.', 'snake-image-optimizer' ),
                    $ok_count
                );
            } else {
                $message = __( 'Bulk run completed. No images were converted.', 'snake-image-optimizer' );
            }
        }

        wp_send_json_success( array(
            'message'      => $message,
            'progress'     => array(
                'done'  => $i,
                'total' => $total,
                'ok'    => $ok_count,
                'err'   => $err_count,
            ),
            'converted'    => $converted,
            'errors'       => $errors,
            'finished'     => $finished,
            'no_eligible'  => $no_eligible,
            'history_used' => $history_used,
        ) );
        } catch ( Throwable $e ) {
            if ( class_exists( 'SNIO_Logger' ) ) {
                SNIO_Logger::log( 'error', 'Bulk fatal: ' . $e->getMessage() );
            }
            wp_send_json_error( array( 'message' => substr( sanitize_text_field( (string) $e->getMessage() ), 0, 500 ) ?: __( 'Bulk failed unexpectedly.', 'snake-image-optimizer' ) ), 500 );
        }
    }

public function render_logs(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $this->render_shell_start(
            'snio-logs',
            __( 'Snake Image Optimizer Logs', 'snake-image-optimizer' ),
            __( 'Track conversions, lazy-load tests, and system events.', 'snake-image-optimizer' )
        );

        $logs    = SNIO_Logger::get_logs();
        $allowed = array( 10, 20, 50, 100, 200, 500, 1000 );
        $per_page = 10;

        $nonce_ok = isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'snio_logs' );
        if ( $nonce_ok && isset( $_GET['snio_logs_per_page'] ) ) {
            $per_page = absint( wp_unslash( $_GET['snio_logs_per_page'] ) );
        }

        if ( ! in_array( $per_page, $allowed, true ) ) {
            $per_page = 10;
        }
        $logs = array_slice( $logs, 0, $per_page );

        $clear_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=snio_clear_logs' ),
            'snio_clear_logs'
        );

        echo '<div class="snio-cardx snio-cardx--wide">';
        echo '<div class="snio-cardx__head">';
        echo '<h2>' . esc_html__( 'Activity log', 'snake-image-optimizer' ) . '</h2>';
        echo '<div class="snio-cardx__head-actions">';
        echo '<a class="button" href="' . esc_url( $clear_url ) . '">' . esc_html__( 'Clear', 'snake-image-optimizer' ) . '</a> ';
        echo '<button type="button" class="button snio-copy-logs">' . esc_html__( 'Copy', 'snake-image-optimizer' ) . '</button>';
        echo '</div>';
        echo '</div>';

        echo '<div class="snio-toolbar">';
        echo '<input id="snio-log-search" class="snio-inputx" type="text" placeholder="' . esc_attr__( 'Search logs…', 'snake-image-optimizer' ) . '" />';
        echo '<select id="snio-log-level" class="snio-selectx">';
        echo '<option value="">' . esc_html__( 'All levels', 'snake-image-optimizer' ) . '</option>';
        echo '<option value="info">INFO</option><option value="warning">WARNING</option><option value="error">ERROR</option>';
        echo '</select>';
        echo '<form method="get" class="snio-log-limit-form" style="margin-left:auto">';
        echo '<input type="hidden" name="page" value="snio-logs" />';
        $nonce_field = wp_nonce_field( 'snio_logs', '_wpnonce', false, false );
        echo wp_kses(
            $nonce_field,
            array(
                'input' => array(
                    'type'  => true,
                    'id'    => true,
                    'name'  => true,
                    'value' => true,
                ),
            )
        );
        echo '<label for="snio-logs-per-page" class="screen-reader-text">' . esc_html__( 'Logs to show', 'snake-image-optimizer' ) . '</label>';
        echo '<select id="snio-logs-per-page" name="snio_logs_per_page" class="snio-selectx" onchange="this.form.submit()">';
        foreach ( $allowed as $limit ) {
            /* translators: %d: number of log rows to show. */
            $label = sprintf( esc_html__( 'Show %d', 'snake-image-optimizer' ), $limit );
            echo '<option value="' . esc_attr( (string) $limit ) . '"' . selected( $per_page, $limit, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '</form>';
        echo '</div>';

        echo '<div class="snio-tablewrap">';
        echo '<table class="widefat striped snio-table" id="snio-log-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Time (UTC)', 'snake-image-optimizer' ) . '</th>';
        echo '<th>' . esc_html__( 'Level', 'snake-image-optimizer' ) . '</th>';
        echo '<th>' . esc_html__( 'Message', 'snake-image-optimizer' ) . '</th>';
        echo '</tr></thead><tbody>';

        if ( empty( $logs ) ) {
            echo '<tr><td colspan="3">' . esc_html__( 'No logs yet.', 'snake-image-optimizer' ) . '</td></tr>';
        } else {
            foreach ( $logs as $row ) {
                $t = isset( $row['time'] ) ? (int) $row['time'] : time();
                $lvl = isset( $row['level'] ) ? sanitize_key( (string) $row['level'] ) : 'info';
                $msg = isset( $row['message'] ) ? (string) $row['message'] : '';
                echo '<tr data-level="' . esc_attr( $lvl ) . '">';
                echo '<td>' . esc_html( gmdate( 'Y-m-d H:i:s', $t ) ) . '</td>';
                echo '<td><span class="snio-level snio-level--' . esc_attr( $lvl ) . '">' . esc_html( strtoupper( $lvl ) ) . '</span></td>';
                echo '<td class="snio-log-msg">' . esc_html( $msg ) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';

        $this->render_shell_end();
    }

public function render_diagnostics(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $this->render_shell_start(
        'snio-diagnostics',
        __( 'Snake Image Optimizer Diagnostics', 'snake-image-optimizer' ),
        __( 'Validate local WebP support and review the health of Snake Image Optimizer.', 'snake-image-optimizer' )
    );

    $r = SNIO_Diagnostics::get_report();
    echo '<div class="snio-grid2">';
    echo '<div class="snio-cardx"><div class="snio-cardx__head"><h2>' . esc_html__( 'Environment', 'snake-image-optimizer' ) . '</h2><button type="button" class="button snio-copy-diagnostics">' . esc_html__( 'Copy report', 'snake-image-optimizer' ) . '</button></div><p class="snio-muted">' . esc_html__( 'If WebP encoding is not supported, local conversion cannot work.', 'snake-image-optimizer' ) . '</p><table class="widefat striped snio-table snio-table--mini"><tbody>';
    foreach ( $r as $k => $v ) {
        $val = is_bool( $v ) ? ( $v ? '✅' : '❌' ) : (string) $v;
        echo '<tr><th><code>' . esc_html( $k ) . '</code></th><td>' . esc_html( $val ) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<div class="snio-cardx"><div class="snio-cardx__head"><h2>' . esc_html__( 'What to fix', 'snake-image-optimizer' ) . '</h2></div>';
    if ( empty( $r['can_encode_webp'] ) ) {
        echo '<div class="snio-callout snio-callout--bad"><strong>' . esc_html__( 'WebP encoding is not available.', 'snake-image-optimizer' ) . '</strong><br />' . esc_html__( 'Enable GD with WebP support or Imagick, then retry.', 'snake-image-optimizer' ) . '</div>';
    } else {
        echo '<div class="snio-callout snio-callout--ok"><strong>' . esc_html__( 'WebP encoding looks good.', 'snake-image-optimizer' ) . '</strong><br />' . esc_html__( 'Uploads should convert successfully.', 'snake-image-optimizer' ) . '</div>';
    }
    echo '<div class="snio-cardx__actions"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=snio-logs' ) ) . '">' . esc_html__( 'Open Logs', 'snake-image-optimizer' ) . '</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=snio-settings' ) ) . '">' . esc_html__( 'Open Core', 'snake-image-optimizer' ) . '</a></div></div></div>';
    $this->render_shell_end();
}

    
public function render_tools(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    wp_safe_redirect( admin_url( 'admin.php?page=snio-settings' ) );
    exit;
}

    
public function handle_export_settings(): void {
    wp_safe_redirect( admin_url( 'admin.php?page=snio-settings' ) );
    exit;
}

    
public function handle_import_settings(): void {
    wp_safe_redirect( admin_url( 'admin.php?page=snio-settings' ) );
    exit;
}

}
