<?php
/**
 * Plugin Name: Celerate Auto-Update Provider
 * Description: Provides auto-update capabilities to Celerate custom plugins.
 * Version: 1.0.0
 * Author: Celerate
 * Author URI: https://www.gocelerate.com/
 * License: Proprietary
 * Requires PHP: 7.4
 */

require_once  __DIR__ . '/src/AutoUpdateProvider.php';

// Allow for self-update.
add_action('plugins_loaded', function () {
    \Celerate\WordPress\AutoUpdateProvider::register(__FILE__);
});