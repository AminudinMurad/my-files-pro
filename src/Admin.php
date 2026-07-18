<?php
/**
 * Admin UI integration helpers.
 *
 * @license   GPL-3.0-or-later. See LICENSE in the plugin root.
 * @package MyFilesPro
 */

declare(strict_types=1);

namespace MyFilesPro;

final class Admin {
	private Folders $folders;

	private Settings $settings;

	public function __construct(Folders $folders, Settings $settings) {
		$this->folders  = $folders;
		$this->settings = $settings;
	}

	/**
	 * Register admin hooks.
	 */
	public function boot(): void {
		add_filter('plugin_action_links_' . MY_FILES_PRO_BASENAME, [$this, 'plugin_action_links']);
		add_action('restrict_manage_posts', [$this, 'render_list_filter']);
		add_action('admin_footer-upload.php', [$this, 'render_sidebar_mount']);
		add_action('admin_footer-post.php', [$this, 'render_sidebar_mount']);
		add_action('admin_footer-post-new.php', [$this, 'render_sidebar_mount']);
	}

	/**
	 * Add a settings link on Plugins screen.
	 *
	 * @param array<int, string> $links Existing links.
	 * @return array<int, string>
	 */
	public function plugin_action_links(array $links): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url(admin_url('options-general.php?page=my-files-pro')),
			esc_html__('Settings', 'my-files-pro')
		);

		array_unshift($links, $settings_link);

		return $links;
	}

	/**
	 * Render a classic list-view dropdown fallback.
	 */
	public function render_list_filter(string $post_type): void {
		if ('attachment' !== $post_type || ! $this->settings->sidebar_enabled() || ! current_user_can('upload_files')) {
			return;
		}

		$current = isset($_GET['myfiles_folder'])
			? sanitize_text_field(wp_unslash((string) $_GET['myfiles_folder']))
			: 'all';
		$folders = $this->folders->get_flat_folders(false);
		?>
		<label class="screen-reader-text" for="myfiles-folder-filter">
			<?php echo esc_html__('Filter by MY Files folder', 'my-files-pro'); ?>
		</label>
		<select name="myfiles_folder" id="myfiles-folder-filter">
			<option value="all" <?php selected($current, 'all'); ?>><?php echo esc_html__('All Files', 'my-files-pro'); ?></option>
			<option value="uncategorized" <?php selected($current, 'uncategorized'); ?>><?php echo esc_html__('Uncategorized', 'my-files-pro'); ?></option>
			<?php foreach ($folders as $folder) : ?>
				<option value="<?php echo esc_attr((string) $folder['id']); ?>" <?php selected($current, (string) $folder['id']); ?>>
					<?php echo esc_html($this->folder_prefix((int) $folder['id']) . $folder['name']); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render a mount point for JavaScript to attach to when useful.
	 */
	public function render_sidebar_mount(): void {
		if (! $this->settings->sidebar_enabled() || ! current_user_can('upload_files')) {
			return;
		}

		echo '<div id="my-files-pro-modal-mount" hidden></div>';
	}

	/**
	 * Prefix nested dropdown labels.
	 */
	private function folder_prefix(int $folder_id): string {
		$ancestors = get_ancestors($folder_id, Folders::TAXONOMY, 'taxonomy');
		$depth     = count($ancestors);

		return $depth > 0 ? str_repeat('&mdash; ', $depth) : '';
	}
}
