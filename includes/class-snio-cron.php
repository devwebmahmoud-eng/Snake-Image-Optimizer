<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SNIO_Cron {
    public const HOOK = 'snio_convert_attachment';

    private static ?SNIO_Cron $instance = null;

    public static function instance(): SNIO_Cron {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_attachment_metadata' ), 10, 2 );
        add_action( self::HOOK, array( $this, 'run' ), 10, 1 );
    }

    public static function clear_scheduled(): void {
        $hook = self::HOOK;
        $timestamp = wp_next_scheduled( $hook );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, $hook );
            $timestamp = wp_next_scheduled( $hook );
        }
    }

    public static function clear_scheduled_attachment( int $attachment_id ): void {
        $hook = self::HOOK;
        $timestamp = wp_next_scheduled( $hook, array( $attachment_id ) );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, $hook, array( $attachment_id ) );
            $timestamp = wp_next_scheduled( $hook, array( $attachment_id ) );
        }
    }

    public function on_attachment_metadata( array $metadata, int $attachment_id ): array {
        $settings = snio_get_settings();
        if ( empty( $settings['enabled'] ) || ! snio_is_supported_image_attachment( $attachment_id ) ) {
            return $metadata;
        }

        $this->convert_now( $attachment_id );
        return $metadata;
    }

    public function run( int $attachment_id ): void {
        $this->convert_now( $attachment_id );
    }

    private function convert_now( int $attachment_id ): void {
        $settings = snio_get_settings();
        if ( empty( $settings['enabled'] ) || ! snio_is_supported_image_attachment( $attachment_id ) ) {
            return;
        }

        $lock_key = 'snio_lock_' . $attachment_id;
        if ( get_transient( $lock_key ) ) {
            return;
        }
        set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );

        try {
            $formats = snio_get_formats_to_generate( $attachment_id, $settings );
            $converter = new SNIO_Converter();
            $converter->convert_attachment( $attachment_id, $formats, $settings, false, false );
            update_post_meta( $attachment_id, '_snio_last_success', time() );
        } catch ( Throwable $e ) {
            update_post_meta( $attachment_id, '_snio_last_error', $e->getMessage() );
            SNIO_Logger::log( 'error', 'Convert failed for #' . $attachment_id . ': ' . $e->getMessage() );
        }

        delete_transient( $lock_key );
    }
}
