<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SNIO_Media {
    private static ?SNIO_Media $instance = null;

    public static function instance(): SNIO_Media {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'manage_upload_columns', array( $this, 'add_column' ) );
        add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
        add_filter( 'media_row_actions', array( $this, 'row_actions' ), 10, 2 );

        add_action( 'admin_post_snio_convert_now', array( $this, 'handle_convert_now' ) );
    }

    public function add_column( array $cols ): array {
        $cols['snake-image-optimizer'] = __( 'SNIO', 'snake-image-optimizer' );
        return $cols;
    }

    public function render_column( string $column_name, int $post_id ): void {
        if ( $column_name !== 'snake-image-optimizer' ) {
            return;
        }
        $mime = (string) get_post_mime_type( $post_id );
        if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
            echo '—';
            return;
        }

        $generated = get_post_meta( $post_id, '_snio_generated', true );
        $has_webp  = false;

        if ( is_array( $generated ) ) {
            foreach ( $generated as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                if ( ( $row['format'] ?? '' ) === 'webp' ) {
                    $has_webp = true;
                    break;
                }
            }
        }

        $err = (string) get_post_meta( $post_id, '_snio_last_error', true );

        echo '<span title="WebP">' . ( $has_webp ? 'WebP ✅' : 'WebP —' ) . '</span>';

        if ( $err !== '' ) {
            echo '<br /><span style="color:#b32d2e" title="' . esc_attr( $err ) . '">' . esc_html__( 'Error', 'snake-image-optimizer' ) . '</span>';
        }
    }

    public function row_actions( array $actions, WP_Post $post ): array {
        if ( $post->post_type !== 'attachment' ) {
            return $actions;
        }
        if ( ! snio_is_supported_image_attachment( (int) $post->ID ) ) {
            return $actions;
        }
        if ( ! current_user_can( 'upload_files' ) ) {
            return $actions;
        }

        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=snio_convert_now&attachment_id=' . (int) $post->ID ),
            'snio_convert_now_' . (int) $post->ID
        );

        $actions['snio_convert'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Convert now', 'snake-image-optimizer' ) . '</a>';

        return $actions;
    }

    public function handle_convert_now(): void {
        // Defensive: some renderers may double-escape query args and produce amp;attachment_id / amp;_wpnonce.
        $raw_id = function_exists( 'snio_request_param' ) ? snio_request_param( 'attachment_id' ) : '';
        if ( '' === $raw_id && function_exists( 'snio_request_param' ) ) {
            $raw_id = snio_request_param( 'amp;attachment_id' );
        }
        $id = absint( $raw_id );
        if ( $id <= 0 ) {
            wp_die( 'Invalid attachment.' );
        }
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_die( 'Forbidden' );
        }

        $nonce = function_exists( 'snio_request_param' ) ? snio_request_param( '_wpnonce' ) : '';
        if ( '' === $nonce && function_exists( 'snio_request_param' ) ) {
            $nonce = snio_request_param( 'amp;_wpnonce' );
        }
        if ( ! wp_verify_nonce( $nonce, 'snio_convert_now_' . $id ) ) {
            wp_die( esc_html__( 'Invalid nonce. Please refresh and try again.', 'snake-image-optimizer' ) );
        }

        // Convert immediately.
        $settings = snio_get_settings();
        $formats  = snio_get_formats_to_generate( $id, $settings );

        try {
            $converter = new SNIO_Converter();
            $converter->convert_attachment( $id, $formats, $settings, false, true );
            update_post_meta( $id, '_snio_last_success', time() );
            SNIO_Logger::log( 'info', 'Manual convert for #' . $id );
        } catch ( Throwable $e ) {
            update_post_meta( $id, '_snio_last_error', $e->getMessage() );
            SNIO_Logger::log( 'error', 'Manual convert failed for #' . $id . ': ' . $e->getMessage() );
        }

        wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' ) );
        exit;
    }
}