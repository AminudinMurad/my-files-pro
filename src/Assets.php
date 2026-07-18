<?php
/**
 * Admin asset loading.
 *
 * @license   GPL-3.0-or-later. See LICENSE in the plugin root.
 * @package MyFilesPro
 */

declare(strict_types=1);

namespace MyFilesPro;

final class Assets {
	private Settings $settings;

	public function __construct(Settings $settings) {
		$this->settings = $settings;
	}

	/**
	 * Register asset hooks.
	 */
	public function boot(): void {
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
	}

	/**
	 * Enqueue scripts and styles only on media and plugin settings screens.
	 */
	public function enqueue_admin_assets(string $hook_suffix): void {
		$is_media_screen    = in_array($hook_suffix, ['upload.php', 'media-new.php', 'post.php', 'post-new.php'], true);
		$is_settings_screen = 'settings_page_my-files-pro' === $hook_suffix;

		if (! $is_media_screen && ! $is_settings_screen) {
			return;
		}

		wp_enqueue_style(
			'my-files-pro-admin',
			MY_FILES_PRO_URL . 'assets/css/admin.css',
			[],
			MY_FILES_PRO_VERSION
		);

		if (! $is_media_screen || ! $this->settings->sidebar_enabled() || ! current_user_can('upload_files')) {
			return;
		}

		wp_enqueue_script(
			'my-files-pro-admin',
			MY_FILES_PRO_URL . 'assets/js/admin.js',
			['wp-api-fetch', 'media-editor'],
			MY_FILES_PRO_VERSION,
			true
		);

		$settings = $this->settings->get();

		wp_add_inline_script(
			'my-files-pro-admin',
			'window.MyFilesPro = ' . wp_json_encode(
				[
					'restNamespace' => '/my-files-pro/v1',
					'productName'   => 'MY Files PRO',
					'version'       => MY_FILES_PRO_VERSION,
					'nonce'         => wp_create_nonce('wp_rest'),
					'currentFolder' => isset($_GET['myfiles_folder'])
						? sanitize_text_field(wp_unslash((string) $_GET['myfiles_folder']))
						: 'all',
					'enabled'       => ! empty($settings['sidebar_enabled']),
					'canManage'     => $this->settings->current_user_can_manage_folders(),
					'showCounts'    => ! empty($settings['show_counts']),
					'i18n'          => [
						'panelTitle'       => __('Folders', 'my-files-pro'),
						'search'           => __('Search folders', 'my-files-pro'),
						'newFolder'        => __('New folder', 'my-files-pro'),
						'rename'           => __('Rename', 'my-files-pro'),
						'delete'           => __('Delete', 'my-files-pro'),
						'duplicate'        => __('Duplicate', 'my-files-pro'),
						'favorite'         => __('Favorite', 'my-files-pro'),
						'sortAlpha'        => __('Sort A–Z', 'my-files-pro'),
						'sortConfirm'      => __('Sort all folders alphabetically? This replaces your current custom folder order.', 'my-files-pro'),
						'sorting'          => __('Sorting folders...', 'my-files-pro'),
						'sortSuccess'      => __('Folders sorted alphabetically.', 'my-files-pro'),
						'quickMove'        => __('Select to move', 'my-files-pro'),
						'quickMoveActive'  => __('Cancel move selection', 'my-files-pro'),
						'folderColor'      => __('Folder color', 'my-files-pro'),
						'expandFolder'     => __('Expand folder', 'my-files-pro'),
						'collapseFolder'   => __('Collapse folder', 'my-files-pro'),
						'moveSelected'     => __('Move selected here', 'my-files-pro'),
						'folderName'       => __('Folder name', 'my-files-pro'),
						'deleteConfirm'    => __('Delete this folder? Assigned files will become uncategorized.', 'my-files-pro'),
						'selectFolder'     => __('Select a destination folder first.', 'my-files-pro'),
						'selectFiles'      => __('Select one or more media files first.', 'my-files-pro'),
						'cannotMoveToAll'  => __('Choose a real folder or Uncategorized before moving files.', 'my-files-pro'),
						'loading'          => __('Loading folders...', 'my-files-pro'),
						'moving'           => __('Moving...', 'my-files-pro'),
						'movedOne'         => __('Moved 1 file to %s.', 'my-files-pro'),
						'movedMany'        => __('Moved %d files to %s.', 'my-files-pro'),
						'uploading'        => __('Uploading...', 'my-files-pro'),
						'uploadComplete'   => __('Upload complete. Refreshing Media Library...', 'my-files-pro'),
						'uploadRefreshed'  => __('Upload complete. Media Library refreshed.', 'my-files-pro'),
						'uploadFailed'     => __('Upload failed. Please try again.', 'my-files-pro'),
						'error'            => __('MY Files PRO could not complete that action.', 'my-files-pro'),
					],
				]
			) . ';',
			'before'
		);
	}
}
