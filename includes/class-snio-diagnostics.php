<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SNIO_Diagnostics {
    public static function get_report(): array {
        $wp_version = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '';
        $gd_loaded  = extension_loaded( 'gd' );
        $im_loaded  = extension_loaded( 'imagick' );

        $gd_webp = function_exists( 'imagewebp' );
        $wp_editor_webp = function_exists( 'wp_image_editor_supports' ) ? wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) : false;

        return array(
            'php_version'     => PHP_VERSION,
            'wp_version'      => $wp_version,
            'gd_loaded'       => $gd_loaded,
            'imagick_loaded'  => $im_loaded,
            'gd_webp'         => $gd_webp,
            'wp_editor_webp'  => (bool) $wp_editor_webp,
            'can_encode_webp' => (bool) ( $gd_webp || $wp_editor_webp || $im_loaded ),
            'plugin_version'  => defined( 'SNIO_VERSION' ) ? SNIO_VERSION : '',
        );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $r = self::get_report();

        echo '<h2>' . esc_html__( 'Diagnostics', 'snake-image-optimizer' ) . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>' .
            esc_html__( 'Key', 'snake-image-optimizer' ) . '</th><th>' .
            esc_html__( 'Value', 'snake-image-optimizer' ) . '</th></tr></thead><tbody>';

                $hidden_keys = array( 'package_fp', 'manifest_owner', 'manifest_build_utc', 'integrity_ok', 'integrity_mismatches' );

        foreach ( $r as $k => $v ) {
            if ( in_array( (string) $k, $hidden_keys, true ) ) {
                continue;
            }
            $val = is_bool( $v ) ? ( $v ? '✅' : '❌' ) : (string) $v;
            echo '<tr><td><code>' . esc_html( $k ) . '</code></td><td>' . esc_html( $val ) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<p>' . esc_html__( 'Tip: If GD WebP is ❌ and Imagick is ❌, your server cannot generate WebP locally.', 'snake-image-optimizer' ) . '</p>';
    }
}
