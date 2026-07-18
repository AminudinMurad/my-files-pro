<?php
/**
 * Small PSR-4 style autoloader for plugin classes.
 *
 * @license   GPL-3.0-or-later. See LICENSE in the plugin root.
 * @package MyFilesPro
 */

declare(strict_types=1);

namespace MyFilesPro;

final class Autoloader {
	/**
	 * Register the plugin autoloader.
	 */
	public static function register(): void {
		spl_autoload_register([self::class, 'autoload']);
	}

	/**
	 * Load namespaced plugin classes.
	 *
	 * @param string $class Fully qualified class name.
	 */
	public static function autoload(string $class): void {
		$prefix = __NAMESPACE__ . '\\';

		if (0 !== strpos($class, $prefix)) {
			return;
		}

		$relative = substr($class, strlen($prefix));
		$file     = MY_FILES_PRO_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';

		if (is_readable($file)) {
			require_once $file;
		}
	}
}
