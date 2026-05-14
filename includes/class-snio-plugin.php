<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once SNIO_PLUGIN_DIR . 'includes/class-snio-bulk-history.php';
require_once SNIO_PLUGIN_DIR . 'includes/class-snio-settings.php';
require_once SNIO_PLUGIN_DIR . 'includes/class-snio-logger.php';
require_once SNIO_PLUGIN_DIR . 'includes/class-snio-diagnostics.php';
require_once SNIO_PLUGIN_DIR . 'includes/class-snio-converter.php';
require_once SNIO_PLUGIN_DIR . 'includes/class-snio-cron.php';
require_once SNIO_PLUGIN_DIR . 'includes/class-snio-delivery.php';
require_once SNIO_PLUGIN_DIR . 'includes/class-snio-media.php';
require_once SNIO_PLUGIN_DIR . 'includes/class-snio-lazy.php';
require_once SNIO_PLUGIN_DIR . 'includes/class-snio-file-hygiene.php';
require_once SNIO_PLUGIN_DIR . 'includes/class-snio-admin.php';

final class SNIO_Plugin {
    private static ?SNIO_Plugin $instance = null;

    public static function instance(): SNIO_Plugin {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook( SNIO_PLUGIN_FILE, array( $this, 'on_activation' ) );
        register_deactivation_hook( SNIO_PLUGIN_FILE, array( $this, 'on_deactivation' ) );

        add_action( 'init', array( $this, 'bootstrap' ), 20 );
    }

    public function bootstrap(): void {
        snio_normalize_saved_settings();

        SNIO_Bulk_History::instance();
        SNIO_Settings::instance();
        SNIO_Cron::instance();
        SNIO_Delivery::instance();
        SNIO_Media::instance();
        SNIO_Lazy::instance();
        SNIO_File_Hygiene::instance();
        SNIO_Admin::instance();
    }

    public function on_activation(): void {
        if ( get_option( 'snio_settings', null ) === null ) {
            add_option( 'snio_settings', snio_default_settings() );
        }

        snio_normalize_saved_settings();
    }

    public function on_deactivation(): void {
        SNIO_Cron::clear_scheduled();
    }
}
