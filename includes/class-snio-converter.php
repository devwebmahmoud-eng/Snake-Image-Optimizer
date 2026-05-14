<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SNIO_Converter {
    /**
     * Resolve the internal compression profile used by this build.
     *
     * @return array{level:string,strategy:string,quality:int}
     */
    private function resolve_compression(): array {
        return array(
            'level'    => 'balanced',
            'strategy' => 'lossy',
            'quality'  => 82,
        );
    }

    private function debug_compression_enabled(): bool {
        if ( defined( 'SNIO_DEBUG_COMPRESSION' ) ) {
            return (bool) SNIO_DEBUG_COMPRESSION;
        }
        return defined( 'WP_DEBUG' ) && (bool) WP_DEBUG;
    }

    /**
     * Convert an attachment to WebP variants.
     *
     * @param array<string,bool> $formats
     */
    public function convert_attachment( int $attachment_id, array $formats, $quality_or_settings, bool $overwrite, bool $record_bulk_usage = true ): void {
        if ( ! snio_is_supported_image_attachment( $attachment_id ) ) {
            return;
        }

        $original = get_attached_file( $attachment_id );
        if ( ! $original || ! is_string( $original ) || ! file_exists( $original ) ) {
            return;
        }

        if ( empty( $formats['webp'] ) ) {
            return;
        }

        $settings = is_array( $quality_or_settings ) ? $quality_or_settings : snio_get_settings();

        $comp = $this->resolve_compression();
        $level = (string) $comp['level'];
        $strategy = (string) $comp['strategy'];
        $base_quality = (int) $comp['quality'];

        if ( $this->debug_compression_enabled() ) {
            SNIO_Logger::log(
                'debug',
                sprintf(
                    'Compression resolved for #%d: level=%s strategy=%s base_q=%d',
                    $attachment_id,
                    $level,
                    $strategy,
                    $base_quality
                )
            );
        }

        $sources = $this->collect_files( $attachment_id, $original );
        $generated_map = array();
        $prev_generated = get_post_meta( $attachment_id, '_snio_generated', true );
        if ( is_array( $prev_generated ) ) {
            foreach ( $prev_generated as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $dest = isset( $row['dest'] ) ? wp_normalize_path( (string) $row['dest'] ) : '';
                if ( $dest !== '' && file_exists( $dest ) ) {
                    $generated_map[ 'webp|' . $dest ] = $row;
                }
            }
        }

        $generated_new = array();
        $had_fail = false;
        $src_original = wp_normalize_path( $original );

        foreach ( $sources as $src ) {
            $src = wp_normalize_path( (string) $src );
            $src_ext = strtolower( (string) pathinfo( $src, PATHINFO_EXTENSION ) );
            if ( ! in_array( $src_ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
                continue;
            }

            $dest = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $src );
            if ( ! is_string( $dest ) || $dest === '' ) {
                continue;
            }

            $quality = $this->effective_quality( $strategy, $src_ext, $base_quality, $settings );
            $is_thumb = $src !== $src_original;
            $src_fp = function_exists( 'snio_file_fingerprint' ) ? snio_file_fingerprint( $src ) : ( (int) @filemtime( $src ) . ':' . (int) @filesize( $src ) );
            $settings_sig = function_exists( 'snio_variant_settings_sig' )
                ? snio_variant_settings_sig( $settings, 'webp', $level, $strategy, (int) $quality, $src_ext, $is_thumb, 'local' )
                : sha1( $level . ':' . $strategy . ':' . (int) $quality . ':' . $src_ext . ':' . ( $is_thumb ? 't' : 'o' ) );

            if ( ! $overwrite && function_exists( 'snio_variant_cache_hit' ) && snio_variant_cache_hit( $attachment_id, $src, $dest, 'webp', $settings_sig, (string) $src_fp ) ) {
                $key = 'webp|' . wp_normalize_path( $dest );
                if ( ! isset( $generated_map[ $key ] ) ) {
                    $generated_map[ $key ] = array(
                        'src'          => $src,
                        'dest'         => wp_normalize_path( $dest ),
                        'format'       => 'webp',
                        'quality'      => (int) $quality,
                        'strategy'     => $strategy,
                        'time'         => time(),
                        'engine'       => 'local',
                        'source_type'  => $is_thumb ? 'thumbnail' : 'original',
                        'settings_sig' => $settings_sig,
                        'src_fp'       => $src_fp,
                    );
                }

                SNIO_Logger::log(
                    'info',
                    sprintf(
                        'Skipped attachment #%d (webp, %s) — cache hit (settings+source unchanged)',
                        $attachment_id,
                        wp_basename( $src )
                    )
                );
                continue;
            }

            $result = $this->convert_variant( $src, $dest, $strategy, $quality, $overwrite );
            if ( ! empty( $result['ok'] ) ) {
                $row = array(
                    'src'          => $src,
                    'dest'         => wp_normalize_path( $dest ),
                    'format'       => 'webp',
                    'quality'      => (int) $result['quality'],
                    'strategy'     => $strategy,
                    'time'         => time(),
                    'engine'       => 'local',
                    'source_type'  => $is_thumb ? 'thumbnail' : 'original',
                    'settings_sig' => $settings_sig,
                    'src_fp'       => $src_fp,
                );

                $generated_map[ 'webp|' . wp_normalize_path( $dest ) ] = $row;
                $generated_new[] = $row;

                update_post_meta( $attachment_id, '_snio_last_quality_webp', (int) $result['quality'] );
                update_post_meta( $attachment_id, '_snio_last_sig_webp', $level . ':' . $strategy . ':' . $quality );

                if ( function_exists( 'snio_variant_record' ) ) {
                    snio_variant_record(
                        $attachment_id,
                        $src,
                        wp_normalize_path( $dest ),
                        'webp',
                        $settings_sig,
                        $src_fp,
                        'local',
                        $is_thumb ? 'thumbnail' : 'original',
                        array(
                            'quality'   => (int) $result['quality'],
                            'strategy'  => $strategy,
                            'level'     => $level,
                            'bytes_src' => (int) ( $result['bytes_src'] ?? 0 ),
                            'bytes_dest'=> (int) ( $result['bytes_dest'] ?? 0 ),
                        )
                    );
                }

                if ( ! empty( $result['warning'] ) ) {
                    update_post_meta( $attachment_id, '_snio_last_error', __( 'Generated WebP is not smaller than the original. Serving may not improve bandwidth.', 'snake-image-optimizer' ) );
                    SNIO_Logger::log( 'warning', sprintf( 'Generated WebP for #%d but it is not smaller than original.', $attachment_id ) );
                }

                $saved = (int) $result['bytes_src'] - (int) $result['bytes_dest'];
                $pct = ( $result['bytes_src'] > 0 ) ? (int) round( ( $saved / (int) $result['bytes_src'] ) * 100 ) : 0;
                SNIO_Logger::log(
                    'info',
                    sprintf(
                        'Converted attachment #%d (webp, level=%s, strategy=%s, q=%d, saved=%d bytes ~%d%%)',
                        $attachment_id,
                        $level,
                        $strategy,
                        (int) $result['quality'],
                        max( 0, $saved ),
                        max( 0, $pct )
                    )
                );

                if ( $this->debug_compression_enabled() ) {
                    $enc = isset( $result['encoder'] ) ? (string) $result['encoder'] : '';
                    SNIO_Logger::log(
                        'debug',
                        sprintf(
                            'Compression debug: #%d webp encoder=%s level=%s strategy=%s q=%d bytes=%d→%d',
                            $attachment_id,
                            $enc,
                            $level,
                            $strategy,
                            (int) $result['quality'],
                            (int) $result['bytes_src'],
                            (int) $result['bytes_dest']
                        )
                    );
                }
            } elseif ( ! empty( $result['skipped'] ) ) {
                update_post_meta( $attachment_id, '_snio_last_error', (string) ( $result['reason'] ?? 'skipped' ) );
                SNIO_Logger::log(
                    'info',
                    sprintf(
                        'Skipped attachment #%d (webp, level=%s, strategy=%s, q=%d) — %s',
                        $attachment_id,
                        $level,
                        $strategy,
                        (int) $quality,
                        (string) ( $result['reason'] ?? 'not smaller than original' )
                    )
                );
            } else {
                $had_fail = true;
                update_post_meta( $attachment_id, '_snio_last_error', (string) ( $result['reason'] ?? __( 'WebP conversion failed. Your server may not support generating WebP.', 'snake-image-optimizer' ) ) );
                SNIO_Logger::log(
                    'warning',
                    sprintf(
                        'Failed converting attachment #%d (webp, level=%s, strategy=%s, q=%d)',
                        $attachment_id,
                        $level,
                        $strategy,
                        (int) $quality
                    )
                );
            }
        }

        foreach ( $generated_map as $key => $row ) {
            if ( ! is_array( $row ) ) {
                unset( $generated_map[ $key ] );
                continue;
            }
            $dest = isset( $row['dest'] ) ? wp_normalize_path( (string) $row['dest'] ) : '';
            if ( $dest === '' || ! file_exists( $dest ) ) {
                unset( $generated_map[ $key ] );
            }
        }

        $final = array_values( $generated_map );
        if ( empty( $final ) ) {
            delete_post_meta( $attachment_id, '_snio_generated' );
        } else {
            update_post_meta( $attachment_id, '_snio_generated', $final );
        }

        if ( ! empty( $generated_new ) && $record_bulk_usage && class_exists( 'SNIO_Bulk_History' ) ) {
            SNIO_Bulk_History::instance()->record_usage( $attachment_id );
        }

        if ( ! $had_fail && ! empty( $final ) ) {
            delete_post_meta( $attachment_id, '_snio_last_error' );
        }

        if ( function_exists( 'snio_clear_dashboard_stats_cache' ) ) {
            snio_clear_dashboard_stats_cache();
        }
    }

    /**
     * @param array<string,bool>  $formats
     * @param array<string,mixed> $settings
     */
    public function needs_conversion( int $attachment_id, array $formats, array $settings ): bool {
        if ( empty( $formats['webp'] ) ) {
            return false;
        }

        $original = get_attached_file( $attachment_id );
        if ( ! $original || ! is_string( $original ) || ! file_exists( $original ) ) {
            return false;
        }
        if ( ! snio_is_supported_image_attachment( $attachment_id ) ) {
            return false;
        }

        $comp = $this->resolve_compression();
        $level = (string) $comp['level'];
        $strategy = (string) $comp['strategy'];
        $base_quality = (int) $comp['quality'];

        foreach ( $this->collect_files( $attachment_id, (string) $original ) as $src ) {
            $src = wp_normalize_path( (string) $src );
            $src_ext = strtolower( (string) pathinfo( $src, PATHINFO_EXTENSION ) );
            if ( ! in_array( $src_ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
                continue;
            }

            $dest = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $src );
            if ( ! is_string( $dest ) || $dest === '' ) {
                continue;
            }

            $quality = $this->effective_quality( $strategy, $src_ext, $base_quality, $settings );
            $is_thumb = wp_normalize_path( (string) $original ) !== $src;
            $src_fp = function_exists( 'snio_file_fingerprint' ) ? snio_file_fingerprint( $src ) : ( (int) @filemtime( $src ) . ':' . (int) @filesize( $src ) );
            $settings_sig = function_exists( 'snio_variant_settings_sig' )
                ? snio_variant_settings_sig( $settings, 'webp', $level, $strategy, (int) $quality, $src_ext, $is_thumb, 'local' )
                : sha1( $level . ':' . $strategy . ':' . (int) $quality . ':' . $src_ext . ':' . ( $is_thumb ? 't' : 'o' ) );

            if ( ! function_exists( 'snio_variant_cache_hit' ) ) {
                return true;
            }
            if ( ! snio_variant_cache_hit( $attachment_id, $src, (string) $dest, 'webp', $settings_sig, (string) $src_fp ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function effective_quality( string $strategy, string $src_ext, int $base_quality, array $settings ): int {
        $base = $base_quality;
        $base = (int) apply_filters( 'snio_effective_base_quality', $base, $strategy, $src_ext, 'webp', $settings );

        if ( $strategy === 'lossy' ) {
            $q = max( 1, min( 100, $base ) );
            if ( $src_ext === 'png' ) {
                $q = max( 40, min( 100, $q ) );
            }
            return (int) apply_filters( 'snio_effective_quality', $q, $strategy, $src_ext, 'webp', $settings );
        }

        if ( $strategy === 'lossless' ) {
            if ( $src_ext === 'png' ) {
                return (int) apply_filters( 'snio_effective_quality', 100, $strategy, $src_ext, 'webp', $settings );
            }
            return (int) apply_filters( 'snio_effective_quality', max( 90, $base ), $strategy, $src_ext, 'webp', $settings );
        }

        return (int) apply_filters( 'snio_effective_quality', $base, $strategy, $src_ext, 'webp', $settings );
    }

    /**
     * @return array{ok:bool,reason:string}
     */
    private function encoder_available(): array {
        if ( function_exists( 'wp_image_editor_supports' ) ) {
            try {
                if ( wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
                    return array( 'ok' => true, 'reason' => 'wp_editor' );
                }
            } catch ( Throwable $e ) {
                // ignore
            }
        }

        if ( class_exists( 'Imagick' ) && extension_loaded( 'imagick' ) && method_exists( 'Imagick', 'queryFormats' ) ) {
            try {
                $supported = Imagick::queryFormats( 'WEBP' );
                if ( ! empty( $supported ) ) {
                    return array( 'ok' => true, 'reason' => 'imagick' );
                }
            } catch ( Throwable $e ) {
                // ignore
            }
        }

        if ( function_exists( 'imagewebp' ) ) {
            return array( 'ok' => true, 'reason' => 'gd' );
        }

        return array( 'ok' => false, 'reason' => 'no_encoder' );
    }

    /**
     * @return array{ok:bool,skipped?:bool,warning?:bool,reason?:string,quality:int,bytes_src:int,bytes_dest:int,encoder?:string}
     */
    private function convert_variant( string $src, string $dest, string $strategy, int $quality, bool $overwrite ): array {
        $bytes_src = (int) ( file_exists( $src ) ? filesize( $src ) : 0 );
        $enc = $this->encoder_available();
        if ( ! $enc['ok'] ) {
            return array( 'ok' => false, 'reason' => 'no_encoder', 'quality' => $quality, 'bytes_src' => $bytes_src, 'bytes_dest' => 0, 'encoder' => 'no_encoder' );
        }

        if ( $overwrite && file_exists( $dest ) ) {
            wp_delete_file( $dest );
            clearstatcache( true, $dest );
        }

        $dir = dirname( $dest );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $src_ext = strtolower( (string) pathinfo( $src, PATHINFO_EXTENSION ) );
        if ( $strategy === 'optimal' && $src_ext === 'png' ) {
            $tmp_lossy = $dest . '.snio-lossy.tmp';
            $tmp_lossless = $dest . '.snio-lossless.tmp';
            wp_delete_file( $tmp_lossy );
            wp_delete_file( $tmp_lossless );

            $m1 = '';
            $ok1 = $this->convert_file( $src, $tmp_lossy, max( 1, min( 100, $quality ) ), false, $m1 );
            $m2 = '';
            $ok2 = $this->convert_file( $src, $tmp_lossless, 100, true, $m2 );

            $candidates = array();
            if ( $ok1 && file_exists( $tmp_lossy ) ) {
                $candidates[] = array( 'path' => $tmp_lossy, 'bytes' => (int) filesize( $tmp_lossy ), 'q' => $quality, 'm' => $m1 );
            }
            if ( $ok2 && file_exists( $tmp_lossless ) ) {
                $candidates[] = array( 'path' => $tmp_lossless, 'bytes' => (int) filesize( $tmp_lossless ), 'q' => 100, 'm' => $m2 );
            }

            if ( empty( $candidates ) ) {
                wp_delete_file( $tmp_lossy );
                wp_delete_file( $tmp_lossless );
                return array( 'ok' => false, 'quality' => $quality, 'bytes_src' => $bytes_src, 'bytes_dest' => 0 );
            }

            usort( $candidates, fn( $a, $b ) => $a['bytes'] <=> $b['bytes'] );
            $chosen = $candidates[0];
            wp_delete_file( $dest );
            $this->move_file( (string) $chosen['path'], $dest );
            if ( file_exists( $tmp_lossy ) ) {
                wp_delete_file( $tmp_lossy );
            }
            if ( file_exists( $tmp_lossless ) ) {
                wp_delete_file( $tmp_lossless );
            }

            $bytes_dest = (int) ( file_exists( $dest ) ? filesize( $dest ) : 0 );
            if ( $bytes_src > 0 && $bytes_dest >= $bytes_src ) {
                return array(
                    'ok' => true,
                    'warning' => true,
                    'reason' => 'variant not smaller than original',
                    'quality' => (int) $chosen['q'],
                    'bytes_src' => $bytes_src,
                    'bytes_dest' => $bytes_dest,
                    'encoder' => (string) ( $chosen['m'] ?? '' ),
                );
            }

            return array(
                'ok' => file_exists( $dest ),
                'quality' => (int) $chosen['q'],
                'bytes_src' => $bytes_src,
                'bytes_dest' => $bytes_dest,
                'encoder' => (string) ( $chosen['m'] ?? '' ),
            );
        }

        $lossless = ( $strategy === 'lossless' && $src_ext === 'png' );
        $method = '';
        $ok = $this->convert_file( $src, $dest, $quality, $lossless, $method );
        $bytes_dest = (int) ( $ok && file_exists( $dest ) ? filesize( $dest ) : 0 );

        if ( $ok && $strategy !== 'lossless' && $bytes_src > 0 && $bytes_dest >= $bytes_src ) {
            return array(
                'ok' => true,
                'warning' => true,
                'reason' => 'variant not smaller than original',
                'quality' => $quality,
                'bytes_src' => $bytes_src,
                'bytes_dest' => $bytes_dest,
                'encoder' => $method,
            );
        }

        return array(
            'ok' => (bool) $ok && file_exists( $dest ),
            'quality' => $quality,
            'bytes_src' => $bytes_src,
            'bytes_dest' => $bytes_dest,
            'encoder' => $method,
        );
    }

    /**
     * @return array<int,string>
     */
    private function collect_files( int $attachment_id, string $original ): array {
        $files = array( wp_normalize_path( $original ) );
        $meta = wp_get_attachment_metadata( $attachment_id );
        if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            $dir = wp_normalize_path( dirname( $original ) );
            $dir_real = realpath( $dir );
            $dir_real_norm = $dir_real ? wp_normalize_path( $dir_real ) : $dir;
            $dir_real_norm = rtrim( (string) $dir_real_norm, '/' ) . '/';

            foreach ( $meta['sizes'] as $size ) {
                if ( ! is_array( $size ) || empty( $size['file'] ) ) {
                    continue;
                }

                $file = str_replace( '\\', '/', (string) $size['file'] );
                $base = wp_basename( $file );
                if ( $base === '' || $base !== $file || strpos( $base, '..' ) !== false ) {
                    continue;
                }

                $path = wp_normalize_path( $dir . '/' . $base );
                $real_path = realpath( $path );
                if ( ! $real_path ) {
                    continue;
                }

                $real_path_norm = wp_normalize_path( $real_path );
                if ( strpos( $real_path_norm, $dir_real_norm ) !== 0 ) {
                    continue;
                }

                if ( is_file( $real_path ) ) {
                    $files[] = $real_path_norm;
                }
            }
        }

        return array_values( array_unique( $files ) );
    }

    private function convert_file( string $src, string $dest, int $quality, bool $lossless = false, string &$method = '' ): bool {
        $method = '';

        if ( class_exists( 'Imagick' ) && extension_loaded( 'imagick' ) ) {
            if ( $this->convert_with_imagick( $src, $dest, $quality, $lossless ) ) {
                $method = 'imagick';
                return true;
            }
        }

        if ( $this->convert_with_gd( $src, $dest, $quality ) ) {
            $method = 'gd';
            return true;
        }

        $editor = wp_get_image_editor( $src );
        if ( ! is_wp_error( $editor ) ) {
            try {
                $editor->set_quality( $quality );
                $saved = $editor->save( $dest, 'image/webp' );
                if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) && file_exists( $saved['path'] ) ) {
                    $method = 'wp_editor';
                    return true;
                }
            } catch ( Throwable $e ) {
                // ignore
            }
        }

        return false;
    }

    private function convert_with_imagick( string $src, string $dest, int $quality, bool $lossless ): bool {
        try {
            if ( method_exists( 'Imagick', 'queryFormats' ) ) {
                $supported = Imagick::queryFormats( 'WEBP' );
                if ( empty( $supported ) ) {
                    return false;
                }
            }

            $im = new Imagick();
            $im->readImage( $src );
            $im->setImageFormat( 'webp' );
            $im->setImageCompressionQuality( $quality );
            if ( $lossless && method_exists( $im, 'setOption' ) ) {
                $im->setOption( 'webp:lossless', 'true' );
            }
            if ( method_exists( $im, 'stripImage' ) ) {
                $im->stripImage();
            }
            $ok = $im->writeImage( $dest );
            $im->clear();
            $im->destroy();

            return (bool) $ok && file_exists( $dest );
        } catch ( Throwable $e ) {
            return false;
        }
    }

    private function convert_with_gd( string $src, string $dest, int $quality ): bool {
        if ( ! function_exists( 'imagewebp' ) ) {
            return false;
        }

        $ext = strtolower( (string) pathinfo( $src, PATHINFO_EXTENSION ) );
        $img = null;

        if ( $ext === 'png' ) {
            if ( ! function_exists( 'imagecreatefrompng' ) ) {
                return false;
            }
            $img = @imagecreatefrompng( $src );
            if ( ! $img ) {
                return false;
            }

            if ( function_exists( 'imageistruecolor' ) && ! imageistruecolor( $img ) && function_exists( 'imagepalettetotruecolor' ) ) {
                @imagepalettetotruecolor( $img );
            }
            if ( function_exists( 'imagealphablending' ) ) {
                @imagealphablending( $img, true );
            }
            if ( function_exists( 'imagesavealpha' ) ) {
                @imagesavealpha( $img, true );
            }
        } else {
            if ( ! function_exists( 'imagecreatefromjpeg' ) ) {
                return false;
            }
            $img = @imagecreatefromjpeg( $src );
            if ( ! $img ) {
                return false;
            }
        }

        $dir = dirname( $dest );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $ok = @imagewebp( $img, $dest, $quality );
        @imagedestroy( $img );
        return (bool) $ok && file_exists( $dest );
    }


    /**
     * Move a file using WP_Filesystem when available, falling back to copy+delete.
     */
    private function move_file( string $src, string $dest ): bool {
        if ( $src === '' || $dest === '' ) {
            return false;
        }
        if ( ! file_exists( $src ) ) {
            return false;
        }

        if ( function_exists( 'WP_Filesystem' ) ) {
            global $wp_filesystem;
            if ( ! $wp_filesystem ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }
            if ( $wp_filesystem && is_object( $wp_filesystem ) && method_exists( $wp_filesystem, 'move' ) ) {
                $moved = $wp_filesystem->move( $src, $dest, true );
                if ( $moved ) {
                    return true;
                }
            }
        }

        if ( ! @copy( $src, $dest ) ) {
            return false;
        }
        wp_delete_file( $src );
        return true;
    }


}
