<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Default plugin settings.
 *
 * @return array<string,mixed>
 */

function snio_default_settings(): array {
    return array(
        'enabled'         => true,
        'serve_webp'      => true,
        'lazy_enabled'    => false,
        'lazy_skip_first' => 0,
    );
}

/**
 * Normalize and whitelist saved settings.
 *
 * @param mixed $raw
 * @return array<string,mixed>
 */
function snio_clean_settings_array( $raw ): array {
    $defaults = snio_default_settings();
    $raw = is_array( $raw ) ? $raw : array();

    return array(
        'enabled'         => array_key_exists( 'enabled', $raw ) ? ! empty( $raw['enabled'] ) : (bool) $defaults['enabled'],
        'serve_webp'      => array_key_exists( 'serve_webp', $raw ) ? ! empty( $raw['serve_webp'] ) : (bool) $defaults['serve_webp'],
        'lazy_enabled'    => array_key_exists( 'lazy_enabled', $raw ) ? ! empty( $raw['lazy_enabled'] ) : (bool) $defaults['lazy_enabled'],
        'lazy_skip_first' => array_key_exists( 'lazy_skip_first', $raw ) ? max( 0, (int) $raw['lazy_skip_first'] ) : (int) $defaults['lazy_skip_first'],
    );
}

/**
 * Normalize and whitelist the saved settings option.
 */
function snio_normalize_saved_settings(): void {
    $saved = get_option( 'snio_settings', null );
    if ( $saved === null ) {
        return;
    }

    $clean = snio_clean_settings_array( $saved );
    if ( $clean !== $saved ) {
        update_option( 'snio_settings', $clean, false );
    }
}

/**
 * Get merged settings (defaults + saved).
 *
 * @return array<string,mixed>
 */
function snio_get_settings(): array {
    $saved = get_option( 'snio_settings', array() );
    return snio_clean_settings_array( $saved );
}


/**
 * Cache key used by the dashboard insights widget.
 */
function snio_dashboard_stats_cache_key(): string {
    return 'snio_dashboard_media_stats_v1';
}

/**
 * Clear cached dashboard insights.
 */
function snio_clear_dashboard_stats_cache(): void {
    delete_transient( snio_dashboard_stats_cache_key() );
}


/**
 * Collect eligible Media Library attachment IDs for Bulk conversion.
 *
 * @param int $max Maximum number of IDs to return. Use 0 for no explicit cap.
 * @return array<int,int>
 */
function snio_bulk_collect_eligible_ids( int $max = 0 ): array {
    $settings = snio_get_settings();
    $formats  = snio_get_formats_to_generate( 0, $settings );
    $ids      = array();

    $query = new WP_Query( array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => array( 'image/jpeg', 'image/png' ),
        'fields'         => 'ids',
        'orderby'        => array( 'date' => 'DESC', 'ID' => 'DESC' ),
        'order'          => 'DESC',
        'posts_per_page' => -1,
        'no_found_rows'  => true,
    ) );

    if ( $query->have_posts() ) {
        foreach ( $query->posts as $aid ) {
            $aid = (int) $aid;
            if ( $aid <= 0 ) {
                continue;
            }

            if ( ! snio_bulk_attachment_is_eligible( $aid, $settings, $formats ) ) {
                continue;
            }

            $ids[] = $aid;
            if ( $max > 0 && count( $ids ) >= $max ) {
                break;
            }
        }
    }

    return array_values( $ids );
}

/**
 * Check whether Bulk currently has any eligible Media Library images.
 */
function snio_bulk_has_eligible_media(): bool {
    return ! empty( snio_bulk_collect_eligible_ids( 1 ) );
}

/**
 * Return a sanitized request parameter from GET or POST.
 */
function snio_request_param( string $key ): string {
    $value = filter_input( INPUT_GET, $key, FILTER_UNSAFE_RAW );

    if ( null === $value || false === $value ) {
        $value = filter_input( INPUT_POST, $key, FILTER_UNSAFE_RAW );
    }

    return is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
}

/**
 * Detect whether the current request is an internal lazy-load test request.
 */
function snio_is_lazy_test_request(): bool {
    return snio_request_param( 'snio_lazy_test' ) === '1';
}


/**
 * Best-effort purge for common frontend/page caches after Lazy Load changes.
 *
 * @return array{ok:bool,cleared:array<int,string>,message:string}
 */
function snio_clear_frontend_cache(): array {
    $cleared = array();

    if ( function_exists( 'snio_clear_dashboard_stats_cache' ) ) {
        snio_clear_dashboard_stats_cache();
        $cleared[] = 'Snake Image Optimizer dashboard cache';
    }

    if ( function_exists( 'rocket_clean_domain' ) ) {
        rocket_clean_domain();
        $cleared[] = 'WP Rocket';
    }

    if ( function_exists( 'w3tc_flush_all' ) ) {
        w3tc_flush_all();
        $cleared[] = 'W3 Total Cache';
    }

    if ( function_exists( 'wp_cache_clear_cache' ) ) {
        if ( function_exists( 'get_current_blog_id' ) ) {
            wp_cache_clear_cache( get_current_blog_id() );
        } else {
            wp_cache_clear_cache();
        }
        $cleared[] = 'WP Super Cache';
    }

    if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
        sg_cachepress_purge_cache();
        $cleared[] = 'SiteGround Optimizer';
    }

    if ( function_exists( 'has_action' ) && has_action( 'litespeed_purge_all' ) ) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party integration hook.
        do_action( 'litespeed_purge_all' );
        $cleared[] = 'LiteSpeed Cache';
    }

    if ( class_exists( 'autoptimizeCache' ) && is_callable( array( 'autoptimizeCache', 'clearall' ) ) ) {
        call_user_func( array( 'autoptimizeCache', 'clearall' ) );
        $cleared[] = 'Autoptimize';
    }

    if ( class_exists( 'Cache_Enabler' ) && is_callable( array( 'Cache_Enabler', 'clear_total_cache' ) ) ) {
        call_user_func( array( 'Cache_Enabler', 'clear_total_cache' ) );
        $cleared[] = 'Cache Enabler';
    }

    if ( class_exists( '\FlyingPress\Purge' ) && is_callable( array( '\FlyingPress\Purge', 'purge_everything' ) ) ) {
        call_user_func( array( '\FlyingPress\Purge', 'purge_everything' ) );
        $cleared[] = 'FlyingPress';
    }

    /**
     * Allow third-party integrations to purge their own caches.
     */
    do_action( 'snio_clear_frontend_cache' );

    $cleared = array_values( array_unique( array_filter( array_map( 'strval', $cleared ) ) ) );

    if ( ! empty( $cleared ) ) {
        return array(
            'ok'      => true,
            'cleared' => $cleared,
            'message' => sprintf(
                /* translators: %s: comma-separated cache names */
                __( 'Cache cleared: %s.', 'snake-image-optimizer' ),
                implode( ', ', $cleared )
            ),
        );
    }

    return array(
        'ok'      => true,
        'cleared' => array(),
        'message' => __( 'No supported cache layer was detected. SNIO local cache was refreshed.', 'snake-image-optimizer' ),
    );
}
/**
 * Decide which formats should be generated for an attachment.
 *
 * @param int                $attachment_id
 * @param array<string,mixed>|null $settings
 * @return array<string,bool>
 */
function snio_get_formats_to_generate( int $attachment_id = 0, ?array $settings = null ): array {
    return array( 'webp' => true );
}


/**
 * Detect browser support for a given mime type using Accept header.
 */


function snio_client_accepts( string $mime ): bool {
    if ( is_admin() ) {
        return false;
    }
    $accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_ACCEPT'] ) ) : '';
    return stripos( $accept, $mime ) !== false;
}



/**
 * Deep-sanitize arrays and scalar values coming from request variables.
 *
 * @param mixed $value
 * @return mixed
 */
function snio_sanitize_deep( $value ) {
    if ( is_array( $value ) ) {
        $out = array();
        foreach ( $value as $k => $v ) {
            $out[ is_string( $k ) ? sanitize_key( $k ) : $k ] = snio_sanitize_deep( $v );
        }
        return $out;
    }

    if ( is_string( $value ) ) {
        return sanitize_text_field( $value );
    }

    return $value;
}


/**
 * Convert a local uploads URL to local absolute path.
 */


/**
 * Add a cache-busting query parameter based on the file mtime (uploads only).
 * This prevents "same filename" re-uploads from showing stale images on the front-end.
 */
function snio_cache_bust_url( string $url ): string {
    if ( $url === '' || strpos( $url, 'data:' ) === 0 ) {
        return $url;
    }

    // Remove only our param if present.
    $clean = remove_query_arg( 'snake-image-optimizer', $url );

    $path = snio_uploads_url_to_path( $clean );
    if ( ! $path || ! file_exists( $path ) ) {
        return $url;
    }

    $mtime = @filemtime( $path );
    if ( ! $mtime ) {
        return $url;
    }

    return add_query_arg( 'snake-image-optimizer', (string) $mtime, $clean );
}
function snio_uploads_url_to_path( string $url ): ?string {
    $uploads = wp_get_upload_dir();
    if ( empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
        return null;
    }

    $baseurl = (string) $uploads['baseurl'];
    $candidates = array_unique(
        array_filter(
            array(
                rtrim( $baseurl, '/' ),
                rtrim( set_url_scheme( $baseurl, 'http' ), '/' ),
                rtrim( set_url_scheme( $baseurl, 'https' ), '/' ),
            )
        )
    );

    $match_base = '';
    foreach ( $candidates as $b ) {
        if ( $b === '' ) {
            continue;
        }
        if ( strpos( $url, $b ) === 0 ) {
            $match_base = $b;
            break;
        }
    }

    if ( $match_base === '' ) {
        return null;
    }

    $rel = ltrim( substr( $url, strlen( $match_base ) ), '/' );
    if ( $rel === '' ) {
        return null;
    }

    $path = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . $rel );

    $real_base = realpath( $uploads['basedir'] );
    $real_path = realpath( $path );
    if ( ! $real_base || ! $real_path ) {
        return null;
    }

    $real_base = wp_normalize_path( $real_base );
    $real_path = wp_normalize_path( $real_path );

    if ( strpos( $real_path, $real_base ) !== 0 ) {
        return null;
    }

    return $real_path;
}

/**
 * Check if a real file path is inside the WordPress uploads directory.
 *
 * This prevents accidental reads/writes/deletes if attachment metadata is tampered with.
 */
function snio_is_path_in_uploads( string $path ): bool {
    $uploads = wp_get_upload_dir();
    $basedir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
    if ( $basedir === '' ) {
        return false;
    }

    $real_base = realpath( $basedir );
    $real_path = realpath( $path );

    if ( ! $real_base || ! $real_path ) {
        return false;
    }

    $real_base = rtrim( wp_normalize_path( $real_base ), '/' ) . '/';
    $real_path = wp_normalize_path( $real_path );

    return strpos( $real_path, $real_base ) === 0;
}

/**
 * Get existing WebP variant URL if present next to original.
 */
function snio_get_existing_variant_url( string $src_url, string $format ): ?string {
    $format = strtolower( $format );
    if ( $format !== 'webp' ) {
        return null;
    }

    $src_path = snio_uploads_url_to_path( $src_url );
    if ( ! $src_path ) {
        return null;
    }

    $ext = strtolower( pathinfo( $src_path, PATHINFO_EXTENSION ) );
    if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
        return null;
    }

    $dest_path = preg_replace( '/\.(jpe?g|png)$/i', '.' . $format, $src_path );
    if ( ! $dest_path || ! file_exists( $dest_path ) ) {
        return null;
    }

    $uploads     = wp_get_upload_dir();
$basedir_raw = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
$basedir_real = $basedir_raw !== '' ? realpath( $basedir_raw ) : false;

$dest_norm = wp_normalize_path( $dest_path );

$candidates = array();
if ( $basedir_real ) {
    $candidates[] = wp_normalize_path( (string) $basedir_real );
}
if ( $basedir_raw !== '' ) {
    $candidates[] = wp_normalize_path( $basedir_raw );
}

$rel = '';
foreach ( array_unique( $candidates ) as $base ) {
    if ( $base === '' ) {
        continue;
    }
    if ( strpos( $dest_norm, $base ) === 0 ) {
        $rel = ltrim( substr( $dest_norm, strlen( $base ) ), '/' );
        break;
    }
}

if ( $rel === '' ) {
    return null;
}

// Preserve slashes in URL.
$u = trailingslashit( $uploads['baseurl'] ) . str_replace( '%2F', '/', rawurlencode( $rel ) );
return $u;
}

function snio_attachment_declares_webp_source( int $attachment_id ): bool {
    $mime = (string) get_post_mime_type( $attachment_id );
    if ( $mime === 'image/webp' ) {
        return true;
    }

    $candidates = array();

    $file = get_attached_file( $attachment_id );
    if ( is_string( $file ) && $file !== '' ) {
        $candidates[] = $file;
    }

    $meta = wp_get_attachment_metadata( $attachment_id );
    if ( is_array( $meta ) && ! empty( $meta['file'] ) && is_string( $meta['file'] ) ) {
        $candidates[] = (string) $meta['file'];
    }

    foreach ( $candidates as $candidate ) {
        $ext = strtolower( (string) pathinfo( (string) $candidate, PATHINFO_EXTENSION ) );
        if ( $ext === 'webp' ) {
            return true;
        }
    }

    return false;
}

function snio_is_supported_image_attachment( int $attachment_id ): bool {
    if ( snio_attachment_declares_webp_source( $attachment_id ) ) {
        return false;
    }

    $mime = (string) get_post_mime_type( $attachment_id );
    if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
        return false;
    }

    $file = get_attached_file( $attachment_id );
    if ( ! is_string( $file ) || $file === '' ) {
        return false;
    }

    $ext = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );
    if ( $ext === 'webp' ) {
        return false;
    }

    return in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true );
}

/**
 * Check whether an attachment is currently eligible for Bulk.
 */
function snio_bulk_attachment_is_eligible( int $attachment_id, ?array $settings = null, ?array $formats = null ): bool {
    if ( $attachment_id <= 0 ) {
        return false;
    }

    if ( function_exists( 'snio_attachment_declares_webp_source' ) && snio_attachment_declares_webp_source( $attachment_id ) ) {
        return false;
    }

    $file = get_attached_file( $attachment_id );
    if ( ! is_string( $file ) || $file === '' || ! file_exists( $file ) ) {
        return false;
    }

    $ext = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );
    if ( $ext === 'webp' ) {
        return false;
    }

    if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
        return false;
    }

    if ( ! snio_is_supported_image_attachment( $attachment_id ) ) {
        return false;
    }

    if ( null === $settings ) {
        $settings = snio_get_settings();
    }
    if ( null === $formats ) {
        $formats = snio_get_formats_to_generate( 0, $settings );
    }

    if ( ! class_exists( 'SNIO_Converter' ) ) {
        return true;
    }

    try {
        $conv = new SNIO_Converter();
        return $conv->needs_conversion( $attachment_id, $formats, $settings );
    } catch ( Throwable $e ) {
        return false;
    }
}

/**
 * -----------------------------
 * Pipeline state (v2)
 * -----------------------------
 *
 * مجموعة “ثبات نتائج معالجة الصور” تعتمد على ربط ناتج التحويل/التصغير
 * بحالة المصدر الحالية + الإعدادات الحالية، بدل الاعتماد على اسم الملف
 * أو توقيع واحد لكل attachment.
 */

/**
 * Convert an absolute uploads path to a stable relative key.
 */
function snio_uploads_relpath( string $path ): string {
    $path = wp_normalize_path( $path );
    $uploads = wp_get_upload_dir();
    $basedir_raw = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
    $basedir_real = $basedir_raw !== '' ? realpath( $basedir_raw ) : false;

    $candidates = array();
    if ( $basedir_real ) {
        $candidates[] = wp_normalize_path( (string) $basedir_real );
    }
    if ( $basedir_raw !== '' ) {
        $candidates[] = wp_normalize_path( $basedir_raw );
    }

    foreach ( array_unique( $candidates ) as $base ) {
        if ( $base === '' ) {
            continue;
        }
        $base = rtrim( $base, '/' ) . '/';
        if ( strpos( $path, $base ) === 0 ) {
            return ltrim( substr( $path, strlen( $base ) ), '/' );
        }
    }

    // Fall back to basename (still stable enough for state keys).
    return wp_basename( $path );
}

/**
 * Lightweight fingerprint for a file.
 *
 * Uses mtime + size always; adds sha1 for reasonably small files to detect
 * same-mtime replacements.
 */
function snio_file_fingerprint( string $path ): string {
    $path = wp_normalize_path( $path );
    $mtime = (int) @filemtime( $path );
    $size  = (int) @filesize( $path );

    $hash = '';
    // Avoid hashing huge originals on busy servers.
    if ( $size > 0 && $size <= 8 * 1024 * 1024 ) {
        $h = @sha1_file( $path );
        if ( is_string( $h ) ) {
            $hash = $h;
        }
    }

    return 'm' . $mtime . ':s' . $size . ( $hash !== '' ? ( ':h' . $hash ) : '' );
}

/**
 * Compute a stable per-variant settings signature.
 *
 * IMPORTANT: This must change whenever an output would differ.
 *
 * @param array<string,mixed> $settings
 */
function snio_variant_settings_sig( array $settings, string $format, string $level, string $strategy, int $quality, string $src_ext, bool $is_thumb, string $engine = 'local' ): string {
    $payload = array(
        'v'      => 2,
        'fmt'    => strtolower( $format ),
        'lvl'    => sanitize_key( $level ),
        'str'    => sanitize_key( $strategy ),
        'q'      => (int) $quality,
        'ext'    => strtolower( $src_ext ),
        'thumb'  => $is_thumb ? 1 : 0,
        'eng'   => $engine,
    );

    // Deterministic string (avoid locale differences).
    $parts = array();
    foreach ( $payload as $k => $v ) {
        $parts[] = $k . '=' . (string) $v;
    }
    return sha1( implode( '|', $parts ) );
}

/**
 * Get attachment pipeline state (v2).
 *
 * @return array<string,mixed>
 */
function snio_state_v2_get( int $attachment_id ): array {
    $raw = get_post_meta( $attachment_id, '_snio_state_v2', true );
    $st  = is_array( $raw ) ? $raw : array();
    if ( empty( $st['v'] ) || (int) $st['v'] !== 2 ) {
        $st = array( 'v' => 2 );
    }
    if ( empty( $st['variants'] ) || ! is_array( $st['variants'] ) ) {
        $st['variants'] = array();
    }
    return $st;
}

/**
 * Persist attachment pipeline state (v2).
 *
 * @param array<string,mixed> $state
 */
function snio_state_v2_put( int $attachment_id, array $state ): void {
    $state['v'] = 2;
    $state['updated_at'] = time();
    update_post_meta( $attachment_id, '_snio_state_v2', $state );
}

/**
 * Check whether an existing variant file matches the current source + settings.
 */
function snio_variant_cache_hit( int $attachment_id, string $src, string $dest, string $format, string $settings_sig, string $src_fp ): bool {
    if ( ! is_string( $dest ) || $dest === '' ) {
        return false;
    }

    $st = snio_state_v2_get( $attachment_id );
    $format = strtolower( $format );
    $dest_rel = snio_uploads_relpath( $dest );
    $entry = $st['variants'][ $format ][ $dest_rel ] ?? null;
    if ( ! is_array( $entry ) ) {
        return false;
    }

    $match = ( (string) ( $entry['src_fp'] ?? '' ) === $src_fp )
        && ( (string) ( $entry['settings_sig'] ?? '' ) === $settings_sig );

    if ( ! $match ) {
        return false;
    }

    // Positive cache hit requires the file to exist.
    if ( file_exists( $dest ) ) {
        return true;
    }

    // Negative cache hit: treat an intentionally skipped variant as stable if recorded.
    $note = (string) ( $entry['note'] ?? '' );
    $has  = isset( $entry['has_file'] ) ? (int) $entry['has_file'] : 1;
    return $has === 0 && $note === 'not_smaller';
}

/**
 * Record a variant state entry.
 *
 * @param array<string,mixed> $extra
 */
function snio_variant_record( int $attachment_id, string $src, string $dest, string $format, string $settings_sig, string $src_fp, string $engine, string $source_type, array $extra = array() ): void {
    $st = snio_state_v2_get( $attachment_id );
    $format = strtolower( $format );
    $dest_rel = snio_uploads_relpath( $dest );

    if ( empty( $st['variants'][ $format ] ) || ! is_array( $st['variants'][ $format ] ) ) {
        $st['variants'][ $format ] = array();
    }

    $row = array_merge(
        array(
            'src_rel'      => snio_uploads_relpath( $src ),
            'dest_rel'     => $dest_rel,
            'src_fp'       => $src_fp,
            'settings_sig' => $settings_sig,
            'engine'       => $engine,
            'source_type'  => $source_type,
            'at'           => time(),
        ),
        $extra
    );

    $st['variants'][ $format ][ $dest_rel ] = $row;
    snio_state_v2_put( $attachment_id, $st );
}

/**
 * Clear all variant-related state for an attachment.
 */
function snio_variant_state_clear( int $attachment_id ): void {
    $st = snio_state_v2_get( $attachment_id );
    $st['variants'] = array();
    snio_state_v2_put( $attachment_id, $st );
}


