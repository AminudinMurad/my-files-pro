<?php
/**
 * Plugin settings and capability checks.
 *
 * @license   GPL-3.0-or-later. See LICENSE in the plugin root.
 * @package MyFilesPro
 */

declare(strict_types=1);

namespace MyFilesPro;

final class Settings {
	public const OPTION_NAME = 'my_files_pro_settings';
	public const SVG_MODE_DEFER = 'defer';
	public const SVG_MODE_MYFILES = 'myfiles';

	private const SVG_UPLOAD_MODES = [
		self::SVG_MODE_DEFER         => true,
		self::SVG_MODE_MYFILES => true,
	];

	private ?ImportExport $import_export = null;

	/**
	 * Register settings hooks.
	 */
	public function boot(ImportExport $import_export): void {
		$this->import_export = $import_export;

		add_action('admin_menu', [$this, 'register_menu']);
		add_action('admin_post_my_files_pro_save_settings', [$this, 'handle_save']);
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'sidebar_enabled'         => true,
			'default_upload_folder'   => 0,
			'show_counts'             => true,
			'svg_upload_mode'         => self::SVG_MODE_DEFER,
			'allowed_roles'           => ['administrator', 'editor'],
			'remove_data_on_uninstall' => false,
		];
	}

	/**
	 * Current settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$stored = get_option(self::OPTION_NAME, []);

		if (! is_array($stored)) {
			$stored = [];
		}

		return wp_parse_args($stored, self::defaults());
	}

	/**
	 * Save sanitized settings.
	 *
	 * @param array<string, mixed> $input Raw input.
	 */
	public function save(array $input): void {
		update_option(self::OPTION_NAME, $this->sanitize($input), false);
	}

	/**
	 * Determine whether the current user may manage folders.
	 */
	public function current_user_can_manage_folders(): bool {
		if (current_user_can('manage_options')) {
			return true;
		}

		if (! current_user_can('upload_files')) {
			return false;
		}

		$user = wp_get_current_user();

		if (! $user || empty($user->roles)) {
			return false;
		}

		$settings      = $this->get();
		$allowed_roles = isset($settings['allowed_roles']) && is_array($settings['allowed_roles'])
			? array_map('sanitize_key', $settings['allowed_roles'])
			: [];

		return (bool) array_intersect($allowed_roles, array_map('sanitize_key', $user->roles));
	}

	/**
	 * Determine whether sidebar UI is enabled.
	 */
	public function sidebar_enabled(): bool {
		$settings = $this->get();

		return ! empty($settings['sidebar_enabled']);
	}

	/**
	 * Default folder for uploads.
	 */
	public function default_upload_folder(): int {
		$settings = $this->get();

		return isset($settings['default_upload_folder']) ? absint($settings['default_upload_folder']) : 0;
	}

	/**
	 * Current SVG upload handling mode.
	 */
	public function svg_upload_mode(): string {
		$settings = $this->get();
		$mode     = isset($settings['svg_upload_mode']) ? sanitize_key((string) $settings['svg_upload_mode']) : self::SVG_MODE_DEFER;

		return isset(self::SVG_UPLOAD_MODES[$mode]) ? $mode : self::SVG_MODE_DEFER;
	}

	/**
	 * Determine whether MyFiles should register its own SVG upload hooks.
	 */
	public function svg_uploads_managed(): bool {
		return self::SVG_MODE_MYFILES === $this->svg_upload_mode();
	}

	/**
	 * Register settings page.
	 */
	public function register_menu(): void {
		add_options_page(
			__('MY Files PRO', 'my-files-pro'),
			__('MY Files PRO', 'my-files-pro'),
			'manage_options',
			'my-files-pro',
			[$this, 'render_page']
		);
	}

	/**
	 * Save settings form.
	 */
	public function handle_save(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to change these settings.', 'my-files-pro'));
		}

		check_admin_referer('my_files_pro_save_settings');

		$settings = isset($_POST['my_files_pro_settings']) && is_array($_POST['my_files_pro_settings'])
			? wp_unslash($_POST['my_files_pro_settings'])
			: [];

		$this->save($settings);

		wp_safe_redirect(
			add_query_arg(
				[
					'page'              => 'my-files-pro',
					'settings-updated'  => 'true',
				],
				admin_url('options-general.php')
			)
		);
		exit;
	}

	/**
	 * Render settings page.
	 */
	public function render_page(): void {
		if (! current_user_can('manage_options')) {
			return;
		}

		$settings = $this->get();
		$roles    = wp_roles()->roles;
		$folders  = (new Folders())->get_flat_folders(false);
		?>
		<div class="wrap myfiles-settings">
			<h1><?php echo esc_html__('MY Files PRO', 'my-files-pro'); ?></h1>

			<?php if (isset($_GET['settings-updated'])) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__('Settings saved.', 'my-files-pro'); ?></p>
				</div>
			<?php endif; ?>

			<?php $this->render_import_notice(); ?>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="my_files_pro_save_settings" />
				<?php wp_nonce_field('my_files_pro_save_settings'); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__('Media Library sidebar', 'my-files-pro'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="my_files_pro_settings[sidebar_enabled]" value="1" <?php checked(! empty($settings['sidebar_enabled'])); ?> />
								<?php echo esc_html__('Show folder sidebar in the Media Library.', 'my-files-pro'); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__('Default upload folder', 'my-files-pro'); ?></th>
						<td>
							<select name="my_files_pro_settings[default_upload_folder]">
								<option value="0"><?php echo esc_html__('No default folder', 'my-files-pro'); ?></option>
								<?php foreach ($folders as $folder) : ?>
									<option value="<?php echo esc_attr((string) $folder['id']); ?>" <?php selected((int) $settings['default_upload_folder'], (int) $folder['id']); ?>>
										<?php echo esc_html($folder['name']); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__('Folder counts', 'my-files-pro'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="my_files_pro_settings[show_counts]" value="1" <?php checked(! empty($settings['show_counts'])); ?> />
								<?php echo esc_html__('Show media counts beside folders.', 'my-files-pro'); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__('SVG uploads', 'my-files-pro'); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php echo esc_html__('SVG uploads', 'my-files-pro'); ?></legend>
								<label>
									<input type="radio" name="my_files_pro_settings[svg_upload_mode]" value="<?php echo esc_attr(self::SVG_MODE_DEFER); ?>" <?php checked($this->svg_upload_mode(), self::SVG_MODE_DEFER); ?> />
									<?php echo esc_html__('Defer to another plugin or framework', 'my-files-pro'); ?>
								</label>
								<p class="description">
									<?php echo esc_html__('Recommended when UiCore Framework, Safe SVG, SVG Support, or another SVG plugin is active. MY Files PRO registers no SVG upload hooks in this mode.', 'my-files-pro'); ?>
								</p>
								<br />
								<label>
									<input type="radio" name="my_files_pro_settings[svg_upload_mode]" value="<?php echo esc_attr(self::SVG_MODE_MYFILES); ?>" <?php checked($this->svg_upload_mode(), self::SVG_MODE_MYFILES); ?> />
									<?php echo esc_html__('Enable SVG uploads with MY Files PRO', 'my-files-pro'); ?>
								</label>
								<p class="description">
									<?php echo esc_html__('Use only when the site has no other SVG upload support. SVG uploads are limited to administrators with media upload access and are sanitized before WordPress accepts the file.', 'my-files-pro'); ?>
								</p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__('Roles that can manage folders', 'my-files-pro'); ?></th>
						<td>
							<?php foreach ($roles as $role_key => $role) : ?>
								<label class="myfiles-role-option">
									<input type="checkbox" name="my_files_pro_settings[allowed_roles][]" value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, (array) $settings['allowed_roles'], true)); ?> />
									<?php echo esc_html(translate_user_role($role['name'])); ?>
								</label><br />
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__('Uninstall cleanup', 'my-files-pro'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="my_files_pro_settings[remove_data_on_uninstall]" value="1" <?php checked(! empty($settings['remove_data_on_uninstall'])); ?> />
								<?php echo esc_html__('Remove folders, assignments, and plugin settings when the plugin is uninstalled.', 'my-files-pro'); ?>
							</label>
							<p>
								<label>
									<?php echo esc_html__('Type DELETE to enable cleanup:', 'my-files-pro'); ?>
									<input type="text" name="my_files_pro_settings[remove_data_confirmation]" value="" autocomplete="off" />
								</label>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(__('Save Settings', 'my-files-pro')); ?>
			</form>

			<?php if ($this->import_export instanceof ImportExport) : ?>
				<?php $this->import_export->render_tools(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Sanitize settings payload.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	private function sanitize(array $input): array {
		$defaults = self::defaults();
		$current  = $this->get();
		$roles    = array_keys(wp_roles()->roles);

		$allowed_roles = isset($input['allowed_roles']) && is_array($input['allowed_roles'])
			? array_values(array_intersect(array_map('sanitize_key', $input['allowed_roles']), $roles))
			: [];

		$cleanup_confirmed = isset($input['remove_data_confirmation'])
			&& 'DELETE' === strtoupper(sanitize_text_field((string) $input['remove_data_confirmation']));
		$svg_upload_mode   = isset($input['svg_upload_mode']) ? sanitize_key((string) $input['svg_upload_mode']) : self::SVG_MODE_DEFER;

		if (! isset(self::SVG_UPLOAD_MODES[$svg_upload_mode])) {
			$svg_upload_mode = self::SVG_MODE_DEFER;
		}

		$default_upload_folder = isset($input['default_upload_folder']) ? absint($input['default_upload_folder']) : (int) $defaults['default_upload_folder'];

		if ($default_upload_folder > 0) {
			$folder = get_term($default_upload_folder, Folders::TAXONOMY);

			if (! $folder instanceof \WP_Term) {
				$default_upload_folder = 0;
			}
		}

		return [
			'sidebar_enabled'         => ! empty($input['sidebar_enabled']),
			'default_upload_folder'   => $default_upload_folder,
			'show_counts'             => ! empty($input['show_counts']),
			'svg_upload_mode'         => $svg_upload_mode,
			'allowed_roles'           => $allowed_roles,
			'remove_data_on_uninstall' => ! empty($input['remove_data_on_uninstall'])
				&& (! empty($current['remove_data_on_uninstall']) || $cleanup_confirmed),
		];
	}

	/**
	 * Render import result notices.
	 */
	private function render_import_notice(): void {
		if (! isset($_GET['myfiles_import'])) {
			return;
		}

		$status = sanitize_key(wp_unslash((string) $_GET['myfiles_import']));
		$type   = 'error';

		$messages = [
			'imported'                   => __('Import completed.', 'my-files-pro'),
			'missing-file'               => __('Choose a JSON or CSV file before importing.', 'my-files-pro'),
			'invalid-file'               => __('The import file must be a valid JSON or CSV file under 2 MB.', 'my-files-pro'),
			'invalid-json'               => __('The import file does not contain valid JSON.', 'my-files-pro'),
			'invalid-csv'                => __('The import file does not contain a compatible CSV header.', 'my-files-pro'),
			'import-too-large'           => __('The import file contains too many folders or assignments.', 'my-files-pro'),
			'myfiles_empty_import' => __('No valid folders were found in the import file.', 'my-files-pro'),
		];

		if (! isset($messages[$status])) {
			return;
		}

		if ('imported' === $status) {
			$type = 'success';
		}
		?>
		<div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible">
			<p><?php echo esc_html($messages[$status]); ?></p>
		</div>
		<?php
	}
}
