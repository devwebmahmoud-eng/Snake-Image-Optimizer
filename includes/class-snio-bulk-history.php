<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tracks processed attachment IDs for Bulk runs.
 */
final class SNIO_Bulk_History {
    private static ?SNIO_Bulk_History $instance = null;

    private const IDS_OPTION = 'snio_bulk_history_ids';
    private const MIGRATION_IDS_OPTION = 'snio_bulk_' . 'lite_ids';

    public static function instance(): SNIO_Bulk_History {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * @return array{used:int}
     */
    public function state(): array {
        return array(
            'used' => count( $this->ids() ),
        );
    }

    /**
     * @return array<int,int>
     */
    private function ids(): array {
        $ids = get_option( self::IDS_OPTION, null );

        if ( $ids === null ) {
            $ids = get_option( self::MIGRATION_IDS_OPTION, array() );
            if ( is_array( $ids ) ) {
                $this->save_ids( $ids );
                delete_option( self::MIGRATION_IDS_OPTION );
            }
        }

        if ( ! is_array( $ids ) ) {
            $ids = array();
        }

        return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
    }

    /**
     * @param array<int,int> $ids
     */
    private function save_ids( array $ids ): void {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        update_option( self::IDS_OPTION, $ids, false );
    }

    public function record_usage( int $attachment_id ): void {
        $attachment_id = max( 0, $attachment_id );
        if ( $attachment_id <= 0 ) {
            return;
        }

        $ids = $this->ids();
        if ( in_array( $attachment_id, $ids, true ) ) {
            return;
        }

        $ids[] = $attachment_id;
        $this->save_ids( $ids );
    }
}
