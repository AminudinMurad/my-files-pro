<?php
/**
 * Plugin Name: MY Files PRO
 * Plugin URI: https://github.com/AminudinMurad
 * Description: Organize WordPress media library files into fast, nested folders with filtering, bulk moves, import/export, and role-aware controls.
 * Version: 1.0.0
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Author: Aminudin Murad
 * Author URI: https://github.com/AminudinMurad
 * Text Domain: my-files-pro
 * Domain Path: /languages
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @license   GPL-3.0-or-later. See LICENSE in the plugin root.
 * @package MyFilesPro
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('MY_FILES_PRO_VERSION', '1.0.0');
define('MY_FILES_PRO_FILE', __FILE__);
define('MY_FILES_PRO_PATH', plugin_dir_path(__FILE__));
define('MY_FILES_PRO_URL', plugin_dir_url(__FILE__));
define('MY_FILES_PRO_BASENAME', plugin_basename(__FILE__));

require_once MY_FILES_PRO_PATH . 'src/Autoloader.php';

\MyFilesPro\Autoloader::register();

register_activation_hook(
	__FILE__,
	static function (): void {
		\MyFilesPro\Activator::activate();
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		\MyFilesPro\Plugin::instance()->boot();
	}
);
