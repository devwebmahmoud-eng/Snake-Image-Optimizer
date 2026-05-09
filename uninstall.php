<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'snio_settings' );
delete_option( 'snio_logs' );

delete_option( 'snio_bulk_history_ids' );
$snio_migration_option = 'snio_bulk_' . 'lite_ids';
delete_option( $snio_migration_option );

delete_post_meta_by_key( '_snio_generated' );
delete_post_meta_by_key( '_snio_last_success' );
delete_post_meta_by_key( '_snio_last_error' );

