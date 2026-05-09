<?php
/**
 * Plugin Name:       Snake Image Optimizer
 * Description:       Snake Image Optimizer is a local-first WebP plugin for WordPress that keeps originals as fallback, includes diagnostics and activity logs, and supports bulk processing without a plugin-imposed limit.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Mahmoud Hamed
 * License:           GPLv2 or later
 * Text Domain:       snake-image-optimizer
 * Domain Path:       /languages
 */


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'SNIO_VERSION' ) ) { define( 'SNIO_VERSION', '1.0.0' ); }
if ( ! defined( 'SNIO_PLUGIN_FILE' ) ) { define( 'SNIO_PLUGIN_FILE', __FILE__ ); }
if ( ! defined( 'SNIO_PLUGIN_DIR' ) ) { define( 'SNIO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) ); }
if ( ! defined( 'SNIO_PLUGIN_URL' ) ) { define( 'SNIO_PLUGIN_URL', plugin_dir_url( __FILE__ ) ); }


require_once SNIO_PLUGIN_DIR . 'includes/functions.php';
require_once SNIO_PLUGIN_DIR . 'includes/class-snio-plugin.php';



SNIO_Plugin::instance();
