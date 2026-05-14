<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SNIO_Delivery {
    private static ?SNIO_Delivery $instance = null;

    public static function instance(): SNIO_Delivery {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_attachment_image_src' ), 10, 4 );
        add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_srcset' ), 10, 5 );
    }

    private function should_serve_webp(): bool {
        $settings = snio_get_settings();
        return ! empty( $settings['serve_webp'] ) && snio_client_accepts( 'image/webp' );
    }

    public function filter_attachment_image_src( $image, $attachment_id, $size, $icon ) {
        if ( ! $this->should_serve_webp() ) {
            return $image;
        }

        if ( ! is_array( $image ) || empty( $image[0] ) || ! is_string( $image[0] ) ) {
            return $image;
        }

        $variant = snio_get_existing_variant_url( $image[0], 'webp' );
        if ( $variant ) {
            $image[0] = snio_cache_bust_url( $variant );
        }

        return $image;
    }

    public function filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
        if ( ! $this->should_serve_webp() || ! is_array( $sources ) ) {
            return $sources;
        }

        foreach ( $sources as $width => $source ) {
            if ( empty( $source['url'] ) || ! is_string( $source['url'] ) ) {
                continue;
            }
            $variant = snio_get_existing_variant_url( $source['url'], 'webp' );
            if ( $variant ) {
                $sources[ $width ]['url'] = snio_cache_bust_url( $variant );
            }
        }

        return $sources;
    }
}
