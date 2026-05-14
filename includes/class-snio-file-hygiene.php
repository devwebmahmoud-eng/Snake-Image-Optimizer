<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * File hygiene:
 * - Prevent "old file persists" bugs when an attachment was deleted but the physical file stayed behind.
 * - If an orphan file blocks re-upload with the same name, we delete the orphan + its derived variants so WP can reuse the same filename.
 * - On permanent deletion, remove WebP sidecars + SNIO backups for the attachment (best-effort).
 */
final class SNIO_File_Hygiene {

    private static ?SNIO_File_Hygiene $instance = null;

    public static function instance(): SNIO_File_Hygiene {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Run before WP appends "-1" etc when a file with same name still exists.
        add_filter( 'wp_unique_filename', array( $this, 'filter_wp_unique_filename' ), 10, 7 );

        // Extra cleanup for sidecars/backups on permanent delete.
        add_action( 'delete_attachment', array( $this, 'on_delete_attachment' ), 50, 1 );
    }

    /**
     * If a physical file exists but no attachment references it (orphan),
     * delete it + its derived variants so WP can reuse the original filename.
     *
     * @param string $filename
     * @param string $ext
     * @param string $dir
     * @param callable|null $unique_filename_callback
     * @param array<int,string>|null $alt_filenames
     * @param int|null $number
     * @return string
     */
    public function filter_wp_unique_filename( $filename, $ext, $dir, $unique_filename_callback = null, $alt_filenames = null, $number = null ): string {
        $ext_l = strtolower( ltrim( (string) $ext, '.' ) );
        if ( ! in_array( $ext_l, array( 'jpg', 'jpeg', 'png' ), true ) ) {
            return (string) $filename;
        }

        $dir  = (string) $dir;
        $file = (string) $filename;
        if ( $dir === '' || $file === '' ) {
            return $file;
        }

        $path = trailingslashit( $dir ) . $file;
        if ( ! file_exists( $path ) ) {
            return $file;
        }

        $rel = $this->uploads_relpath( $dir, $file );
        if ( $rel === '' ) {
            // Can't safely determine ownership, so do nothing.
            return $file;
        }

        $attachment_id = $this->find_attachment_by_relpath( $rel );
        if ( $attachment_id > 0 ) {
            // In use by an attachment: do not delete.
            return $file;
        }

        // Orphan file: delete it and its derived variants so WP can reuse the original name.
        $this->delete_file_group( $dir, $file );

        return $file;
    }

    public function on_delete_attachment( $attachment_id ): void {
        $attachment_id = (int) $attachment_id;
        if ( $attachment_id <= 0 ) {
            return;
        }

        $file = get_attached_file( $attachment_id, true );
        if ( ! $file || ! is_string( $file ) ) {
            return;
        }

        $dir  = dirname( $file );
        $name = basename( $file );
        $this->delete_file_group( $dir, $name );

        $meta = wp_get_attachment_metadata( $attachment_id );
        if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $size ) {
                if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
                    $this->delete_file_group( $dir, (string) $size['file'] );
                }
            }
        }

        // Remove SNIO originals cache for this attachment (best-effort).
        $uploads = wp_get_upload_dir();
        $basedir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
        if ( $basedir !== '' ) {
            $orig_dir = trailingslashit( $basedir ) . '.snio-originals/att-' . $attachment_id;
            if ( is_dir( $orig_dir ) ) {
                $this->rrmdir( $orig_dir );
            }
        }
    }

    private function uploads_relpath( string $dir, string $filename ): string {
        $uploads = wp_get_upload_dir();
        $basedir_raw = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
        if ( $basedir_raw === '' ) {
            return '';
        }

        $basedir_real = realpath( $basedir_raw );
        $basedir = $basedir_real ? wp_normalize_path( $basedir_real ) : wp_normalize_path( $basedir_raw );
        $basedir = rtrim( $basedir, '/' ) . '/';

        $dir_norm = wp_normalize_path( $dir );
        $dir_norm = rtrim( $dir_norm, '/' ) . '/';

        if ( strpos( $dir_norm, $basedir ) !== 0 ) {
            return '';
        }

        $sub = ltrim( substr( $dir_norm, strlen( $basedir ) ), '/' );
        return ( $sub !== '' ? $sub : '' ) . $filename;
    }

    private function find_attachment_by_relpath( string $rel ): int {
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            return 0;
        }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                '_wp_attached_file',
                $rel
            )
        );
return (int) $id;
    }

    /**
     * Delete the original file + its WP derivatives + SNIO sidecars/backups for a given filename in a directory.
     */
    private function delete_file_group( string $dir, string $filename ): void {
        $dir = rtrim( $dir, "/\\" );
        $filename = (string) $filename;
        if ( $dir === '' || $filename === '' ) {
            return;
        }

        $path = $dir . '/' . $filename;
        $info = pathinfo( $path );
        $name = isset( $info['filename'] ) ? (string) $info['filename'] : '';
        $ext  = isset( $info['extension'] ) ? strtolower( (string) $info['extension'] ) : '';
        if ( $name === '' || $ext === '' ) {
            return;
        }

        // Exact candidates.
        $candidates = array(
            $dir . '/' . $name . '.' . $ext,
            $dir . '/' . $name . '-scaled.' . $ext,
            $dir . '/' . $name . '-rotated.' . $ext,
            $dir . '/' . $name . '.webp',
            $dir . '/' . $name . '-scaled.webp',
            $dir . '/' . $name . '-rotated.webp',
        );

        foreach ( $candidates as $p ) {
            if ( is_file( $p ) ) {
                wp_delete_file( $p );
            }
        }

        // Patterns: subsizes + sidecars + backups.
        $patterns = array(
            $dir . '/' . $name . '-*x*.' . $ext,
            $dir . '/' . $name . '-*x*.webp',
            $dir . '/' . $name . '-scaled-*x*.' . $ext,
            $dir . '/' . $name . '-rotated-*x*.' . $ext,
            $dir . '/' . $name . '.snio-orig.*',
            $dir . '/' . $name . '-*x*.snio-orig.*',
            $dir . '/' . $name . '-scaled.snio-orig.*',
            $dir . '/' . $name . '-rotated.snio-orig.*',
        );

        foreach ( $patterns as $pat ) {
            $list = glob( $pat );
            if ( ! is_array( $list ) ) {
                continue;
            }
            foreach ( $list as $p ) {
                if ( is_file( $p ) ) {
                    wp_delete_file( $p );
                }
            }
        }
    }

    private function rrmdir( string $dir ): void {
        $dir = rtrim( $dir, "/\\" );
        if ( $dir === '' || ! is_dir( $dir ) ) {
            return;
        }

        if ( function_exists( 'WP_Filesystem' ) ) {
            global $wp_filesystem;
            if ( ! $wp_filesystem ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }
            if ( $wp_filesystem && is_object( $wp_filesystem ) && method_exists( $wp_filesystem, 'delete' ) ) {
                $wp_filesystem->delete( $dir, true );
                return;
            }
        }

        $items = scandir( $dir );
        if ( ! is_array( $items ) ) {
            return;
        }

        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }
            $path = $dir . '/' . $item;
            if ( is_dir( $path ) ) {
                $this->rrmdir( $path );
            } elseif ( is_file( $path ) ) {
                wp_delete_file( $path );
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Fallback only when WP_Filesystem is unavailable.
        @rmdir( $dir );
    }
}
