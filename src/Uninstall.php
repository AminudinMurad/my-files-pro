<?php
/**
 * Data cleanup on uninstall.
 *
 * @license   GPL-3.0-or-later. See LICENSE in the plugin root.
 * @package MyFilesPro
 */

declare(strict_types=1);

namespace MyFilesPro;

final class Uninstall {
	/**
	 * Remove plugin data only when the cleanup setting is enabled.
	 */
	public static function run(): void {
		$settings = get_option(Settings::OPTION_NAME, []);

		if (! is_array($settings) || empty($settings['remove_data_on_uninstall'])) {
			return;
		}

		Folders::register_taxonomy();

		$terms = get_terms(
			[
				'taxonomy'   => Folders::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);

		if (! is_wp_error($terms)) {
			foreach ($terms as $term_id) {
				wp_delete_term((int) $term_id, Folders::TAXONOMY);
			}
		}

		delete_option(Settings::OPTION_NAME);
		delete_option('my_files_pro_version');
	}
}
