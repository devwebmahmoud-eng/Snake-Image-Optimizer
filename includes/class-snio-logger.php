<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SNIO_Logger {
    private const OPTION_KEY = 'snio_logs';
    private const MAX_ROWS   = 1000;

    /**
     * @param string $level info|warning|error
     * @param string $message
     */
    public static function log( string $level, string $message ): void {
        $logs = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $logs ) ) {
            $logs = array();
        }

        array_unshift(
            $logs,
            array(
                'time'    => time(),
                'level'   => $level,
                'message' => $message,
            )
        );

        if ( count( $logs ) > self::MAX_ROWS ) {
            $logs = array_slice( $logs, 0, self::MAX_ROWS );
        }

        update_option( self::OPTION_KEY, $logs, false );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function get_logs(): array {
        $logs = get_option( self::OPTION_KEY, array() );
        return is_array( $logs ) ? $logs : array();
    }

    public static function clear(): void {
        delete_option( self::OPTION_KEY );
    }
}
