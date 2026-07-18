<?php
/**
 * Main plugin coordinator.
 *
 * @license   GPL-3.0-or-later. See LICENSE in the plugin root.
 * @package MyFilesPro
 */

declare(strict_types=1);

namespace MyFilesPro;

final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	private Folders $folders;

	private Settings $settings;

	private ImportExport $import_export;

	/**
	 * Get the plugin instance.
	 */
	public static function instance(): self {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot plugin services.
	 */
	public function boot(): void {
		load_plugin_textdomain('my-files-pro', false, dirname(MY_FILES_PRO_BASENAME) . '/languages');
		$this->maybe_update_version();

		$this->folders       = new Folders();
		$this->settings      = new Settings();
		$this->import_export = new ImportExport($this->folders);

		$this->folders->boot();
		$this->settings->boot($this->import_export);
		$this->import_export->boot();

		(new Media($this->folders, $this->settings))->boot();
		(new RestApi($this->folders, $this->settings))->boot();
		(new Assets($this->settings))->boot();
		(new Admin($this->folders, $this->settings))->boot();
		(new SvgUploads($this->settings))->boot();
	}

	/**
	 * Folder service accessor.
	 */
	public function folders(): Folders {
		return $this->folders;
	}

	/**
	 * Settings service accessor.
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Track the installed plugin version for future migrations.
	 */
	private function maybe_update_version(): void {
		$installed_version = (string) get_option('my_files_pro_version', '');

		if (MY_FILES_PRO_VERSION !== $installed_version) {
			update_option('my_files_pro_version', MY_FILES_PRO_VERSION, false);
		}
	}
}
