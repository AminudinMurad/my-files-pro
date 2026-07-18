<?php
/**
 * Activation tasks.
 *
 * @license   GPL-3.0-or-later. See LICENSE in the plugin root.
 * @package MyFilesPro
 */

declare(strict_types=1);

namespace MyFilesPro;

final class Activator {
	/**
	 * Prepare defaults and register the taxonomy once during activation.
	 */
	public static function activate(): void {
		Folders::register_taxonomy();

		if (false === get_option(Settings::OPTION_NAME, false)) {
			add_option(Settings::OPTION_NAME, Settings::defaults(), '', false);
		}

		add_option('my_files_pro_version', MY_FILES_PRO_VERSION, '', false);
	}
}
