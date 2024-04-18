<?php
/**
 * Plugin Name: REST Posts Embedder
 * Description: Embed posts from a specified REST API endpoint.
 * Plugin URI:  https://developer.wordpress.org/
 * Author:      Rafael Minuesa
 * Author URI:  https://www.linkedin.com/in/rafaelminuesa/
 * Version:     1.4
 * Text Domain: restpostsembedder
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 *
 * @package restpostsembedder
 */

// Disable direct file access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Include admin functions
require plugin_dir_path( __FILE__ ) . 'admin/functions.php';

// Include shortcode functions
require plugin_dir_path( __FILE__ ) . 'shortcodes/functions.php';

// Register shortcode
add_shortcode( 'posts_embedder', 'rest_posts_embedder' );
