<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SNIO_Lazy {
    private static ?SNIO_Lazy $instance = null;

    private int $seen_attr = 0;
    private int $seen_content = 0;

    public static function instance(): SNIO_Lazy {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'wp_get_attachment_image_attributes', array( $this, 'filter_attributes' ), 20, 3 );
        add_filter( 'the_content', array( $this, 'filter_content_images' ), 25 );
        add_filter( 'widget_text', array( $this, 'filter_content_images' ), 25 );
    }

    /**
     * Apply lazy-loading and priority hints for images rendered via wp_get_attachment_image().
     *
     * @param array<string,mixed> $attr
     * @param WP_Post $attachment
     * @param string|array $size
     * @return array<string,mixed>
     */
    public function filter_attributes( array $attr, $attachment, $size ): array {
        if ( is_admin() ) {
            return $attr;
        }

        $s = snio_get_settings();
        if ( empty( $s['lazy_enabled'] ) ) {
            return $attr;
        }

        $test = function_exists( 'snio_is_lazy_test_request' ) ? snio_is_lazy_test_request() : false;

        $this->seen_attr++;

        $skip = isset( $s['lazy_skip_first'] ) ? (int) $s['lazy_skip_first'] : 0;
        $skip = max( 0, min( 50, $skip ) );

        if ( $this->seen_attr <= $skip ) {
            if ( empty( $attr['decoding'] ) ) {
                $attr['decoding'] = 'async';
            }
            $attr['loading'] = 'eager';
            if ( empty( $attr['fetchpriority'] ) ) {
                $attr['fetchpriority'] = 'high';
            }
            if ( $test ) {
                $attr['data-snio-lazy'] = 'skip';
            }
            return $attr;
        }

        $attr['loading']  = 'lazy';
        $attr['decoding'] = $attr['decoding'] ?? 'async';

        if ( $test ) {
            $attr['data-snio-lazy'] = 'lazy';
        }

        return $attr;
    }

    /**
     * Enforce lazy-loading for raw HTML <img> in content and add test markers.
     *
     * @param string $html
     * @return string
     */
    public function filter_content_images( string $html ): string {
        if ( is_admin() ) {
            return $html;
        }

        $s = snio_get_settings();
        if ( empty( $s['lazy_enabled'] ) ) {
            return $html;
        }

        if ( stripos( $html, '<img' ) === false ) {
            return $html;
        }

        $test = function_exists( 'snio_is_lazy_test_request' ) ? snio_is_lazy_test_request() : false;

        $skip = isset( $s['lazy_skip_first'] ) ? (int) $s['lazy_skip_first'] : 0;
        $skip = max( 0, min( 50, $skip ) );

        $out = preg_replace_callback(
            '/<img\b[^>]*>/i',
            function ( array $m ) use ( $s, $skip, $test ) : string {
                $tag = (string) $m[0];

                $this->seen_content++;
                $is_skip = $this->seen_content <= $skip;

                // Ensure / override loading.
                if ( ! preg_match( '/\bloading\s*=\s*["\'](lazy|eager)["\']/i', $tag ) ) {
                    $tag = preg_replace( '/<img\b/i', '<img loading="' . ( $is_skip ? 'eager' : 'lazy' ) . '"', $tag, 1 );
                } elseif ( $is_skip ) {
                    $tag = preg_replace( '/\bloading\s*=\s*["\']lazy["\']/i', 'loading="eager"', $tag, 1 );
                } else {
                    // If explicitly eager below the fold, keep it.
                }

                if ( $test && ! preg_match( '/\bdata-snio-lazy\s*=/i', $tag ) ) {
                    $tag = preg_replace( '/<img\b/i', '<img data-snio-lazy="' . ( $is_skip ? 'skip' : 'lazy' ) . '"', $tag, 1 );
                }

                return $tag;
            },
            $html
        );

        return is_string( $out ) ? $out : $html;
    }
}
