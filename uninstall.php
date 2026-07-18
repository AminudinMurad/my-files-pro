<?php
/**
 * Uninstall handler for MY Files PRO.
 *
 * @license   GPL-3.0-or-later. See LICENSE in the plugin root.
 * @package MyFilesPro
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

define('MY_FILES_PRO_PATH', plugin_dir_path(__FILE__));

require_once MY_FILES_PRO_PATH . 'src/Autoloader.php';

\MyFilesPro\Autoloader::register();
\MyFilesPro\Uninstall::run();
