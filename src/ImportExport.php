<?php
/**
 * Import/export admin actions.
 *
 * @license   GPL-3.0-or-later. See LICENSE in the plugin root.
 * @package MyFilesPro
 */

declare(strict_types=1);

namespace MyFilesPro;

final class ImportExport {
	private const MAX_IMPORT_BYTES = 2097152;
	private const MAX_IMPORT_FOLDERS = 2000;
	private const MAX_IMPORT_ASSIGNMENTS = 10000;

	private Folders $folders;

	public function __construct(Folders $folders) {
		$this->folders = $folders;
	}

	/**
	 * Register admin-post actions.
	 */
	public function boot(): void {
		add_action('admin_post_my_files_pro_export', [$this, 'handle_export']);
		add_action('admin_post_my_files_pro_import', [$this, 'handle_import']);
	}

	/**
	 * Render settings-page import/export tools.
	 */
	public function render_tools(): void {
		$export_url = wp_nonce_url(
			add_query_arg('action', 'my_files_pro_export', admin_url('admin-post.php')),
			'my_files_pro_export'
		);
		?>
		<hr />
		<h2><?php echo esc_html__('Import / Export', 'my-files-pro'); ?></h2>
		<p><?php echo esc_html__('Export the folder structure and attachment assignments as JSON, or import a compatible MY Files PRO JSON file or CSV media-folder file.', 'my-files-pro'); ?></p>

		<p>
			<a class="button button-secondary" href="<?php echo esc_url($export_url); ?>">
				<?php echo esc_html__('Export JSON', 'my-files-pro'); ?>
			</a>
		</p>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="my_files_pro_import" />
			<?php wp_nonce_field('my_files_pro_import'); ?>
			<p>
				<input type="file" name="myfiles_import_file" accept="application/json,text/csv,.json,.csv" required />
			</p>
			<p>
				<label>
					<input type="checkbox" name="include_assignments" value="1" checked />
					<?php echo esc_html__('Import attachment assignments where matching attachment IDs exist.', 'my-files-pro'); ?>
				</label>
			</p>
			<?php submit_button(__('Import file', 'my-files-pro'), 'secondary', 'submit', false); ?>
		</form>
		<?php
	}

	/**
	 * Download JSON export.
	 */
	public function handle_export(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to export MY Files PRO data.', 'my-files-pro'));
		}

		check_admin_referer('my_files_pro_export');

		$data = $this->folders->export_data();

		nocache_headers();
		header('Content-Type: application/json; charset=utf-8');
		header('X-Content-Type-Options: nosniff');
		header('Content-Disposition: attachment; filename="my-files-pro-export-' . gmdate('Y-m-d-His') . '.json"');

		$json = wp_json_encode($data, JSON_PRETTY_PRINT);

		if (! is_string($json)) {
			wp_die(esc_html__('MY Files PRO export could not be generated.', 'my-files-pro'));
		}

		echo $json;
		exit;
	}

	/**
	 * Import JSON upload.
	 */
	public function handle_import(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to import MY Files PRO data.', 'my-files-pro'));
		}

		check_admin_referer('my_files_pro_import');

		$file = $this->get_uploaded_import_file();

		if (is_wp_error($file)) {
			wp_safe_redirect($this->settings_url($file->get_error_code()));
			exit;
		}

		$raw = file_get_contents((string) $file['tmp_name'], false, null, 0, self::MAX_IMPORT_BYTES + 1);

		if (! is_string($raw) || strlen($raw) > self::MAX_IMPORT_BYTES) {
			wp_safe_redirect($this->settings_url('invalid-file'));
			exit;
		}

		$data = 'csv' === ($file['ext'] ?? '')
			? $this->parse_folder_csv((string) $file['tmp_name'])
			: json_decode($raw, true);

		if (is_wp_error($data)) {
			wp_safe_redirect($this->settings_url($data->get_error_code()));
			exit;
		}

		if (! is_array($data) || ('csv' !== ($file['ext'] ?? '') && JSON_ERROR_NONE !== json_last_error())) {
			wp_safe_redirect($this->settings_url('invalid-json'));
			exit;
		}

		$payload = $this->validate_import_payload($data);

		if (is_wp_error($payload)) {
			wp_safe_redirect($this->settings_url($payload->get_error_code()));
			exit;
		}

		$include_assignments = isset($_POST['include_assignments']) && '1' === (string) wp_unslash($_POST['include_assignments']);
		$result              = $this->folders->import_data($payload, $include_assignments);

		if (is_wp_error($result)) {
			wp_safe_redirect($this->settings_url($result->get_error_code()));
			exit;
		}

		wp_safe_redirect($this->settings_url('imported'));
		exit;
	}

	/**
	 * Return a validated import upload file.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function get_uploaded_import_file() {
		if (! isset($_FILES['myfiles_import_file']) || ! is_array($_FILES['myfiles_import_file'])) {
			return new \WP_Error('missing-file');
		}

		$file = $_FILES['myfiles_import_file'];
		$error = isset($file['error']) ? absint($file['error']) : UPLOAD_ERR_NO_FILE;

		if (UPLOAD_ERR_OK !== $error) {
			return new \WP_Error('invalid-file');
		}

		$tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
		$name     = isset($file['name']) ? sanitize_file_name((string) $file['name']) : '';
		$size     = isset($file['size']) ? absint($file['size']) : 0;

		if ('' === $tmp_name || ! is_uploaded_file($tmp_name) || $size <= 0 || $size > self::MAX_IMPORT_BYTES) {
			return new \WP_Error('invalid-file');
		}

		$ext       = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$file_type = wp_check_filetype(
			$name,
			[
				'json' => 'application/json',
				'csv'  => 'text/csv',
			]
		);

		if (! in_array($ext, ['json', 'csv'], true) || ! in_array(($file_type['ext'] ?? ''), ['json', 'csv'], true)) {
			return new \WP_Error('invalid-file');
		}

		return [
			'tmp_name' => $tmp_name,
			'name'     => $name,
			'size'     => $size,
			'ext'      => $ext,
		];
	}

	/**
	 * Parse a compatible CSV export into the internal import payload shape.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function parse_folder_csv(string $file) {
		$handle = fopen($file, 'rb');

		if (! is_resource($handle)) {
			return new \WP_Error('invalid-file');
		}

		$header = fgetcsv($handle, 0, ',', '"', '');

		if (! is_array($header)) {
			fclose($handle);
			return new \WP_Error('invalid-csv');
		}

		$columns = $this->normalize_csv_header($header);

		foreach (['id', 'name', 'parent'] as $required) {
			if (! array_key_exists($required, $columns)) {
				fclose($handle);
				return new \WP_Error('invalid-csv');
			}
		}

		$folders     = [];
		$assignments = [];

		while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
			if (! is_array($row) || $this->is_empty_csv_row($row)) {
				continue;
			}

			$post_type = $this->csv_value($row, $columns, 'post_type');

			if ('' !== $post_type && 'attachment' !== strtolower($post_type)) {
				continue;
			}

			$id   = absint($this->csv_value($row, $columns, 'id'));
			$name = sanitize_text_field($this->csv_value($row, $columns, 'name'));

			if ($id < 1 || '' === $name) {
				continue;
			}

			$folders[] = [
				'id'       => $id,
				'name'     => $name,
				'parent'   => absint($this->csv_value($row, $columns, 'parent')),
				'order'    => absint($this->csv_value($row, $columns, 'ord')),
				'color'    => '',
				'favorite' => false,
			];

			if (count($folders) > self::MAX_IMPORT_FOLDERS) {
				fclose($handle);
				return new \WP_Error('import-too-large');
			}

			foreach ($this->parse_csv_attachment_ids($this->csv_value($row, $columns, 'attachment_ids')) as $attachment_id) {
				$assignments[] = [
					'attachment_id' => $attachment_id,
					'folder_id'     => $id,
				];

				if (count($assignments) > self::MAX_IMPORT_ASSIGNMENTS) {
					fclose($handle);
					return new \WP_Error('import-too-large');
				}
			}
		}

		fclose($handle);

		if (empty($folders)) {
			return new \WP_Error('myfiles_empty_import');
		}

		return [
			'plugin'      => 'CSV media folders',
			'version'     => '',
			'generatedAt' => gmdate('c'),
			'folders'     => $folders,
			'assignments' => $assignments,
		];
	}

	/**
	 * Normalize CSV headers into lookup keys.
	 *
	 * @param array<int, mixed> $header Header row.
	 * @return array<string, int>
	 */
	private function normalize_csv_header(array $header): array {
		$columns = [];
		$aliases = [
			'attachment_id' => 'attachment_ids',
			'attachments'   => 'attachment_ids',
			'media_ids'     => 'attachment_ids',
			'order'         => 'ord',
			'folder_id'     => 'id',
			'parent_id'     => 'parent',
		];

		foreach ($header as $index => $column) {
			$key = trim((string) $column);
			$key = preg_replace('/^\xEF\xBB\xBF/', '', $key);
			$key = strtolower((string) $key);
			$key = (string) preg_replace('/[^a-z0-9]+/', '_', $key);
			$key = trim($key, '_');

			if ('' === $key) {
				continue;
			}

			$key = $aliases[$key] ?? $key;

			if (! isset($columns[$key])) {
				$columns[$key] = (int) $index;
			}
		}

		return $columns;
	}

	/**
	 * Return one CSV cell by normalized column key.
	 *
	 * @param array<int, mixed>    $row CSV row.
	 * @param array<string, int> $columns Header lookup.
	 */
	private function csv_value(array $row, array $columns, string $key): string {
		if (! isset($columns[$key]) || ! array_key_exists($columns[$key], $row)) {
			return '';
		}

		return trim((string) $row[$columns[$key]]);
	}

	/**
	 * Determine whether a CSV row is empty.
	 *
	 * @param array<int, mixed> $row CSV row.
	 */
	private function is_empty_csv_row(array $row): bool {
		foreach ($row as $value) {
			if ('' !== trim((string) $value)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Parse pipe-, comma-, or space-separated attachment IDs from CSV.
	 *
	 * @return array<int, int>
	 */
	private function parse_csv_attachment_ids(string $value): array {
		if ('' === trim($value)) {
			return [];
		}

		$ids = preg_split('/[|,\s]+/', trim($value));

		if (! is_array($ids)) {
			return [];
		}

		return array_values(array_unique(array_filter(array_map('absint', $ids))));
	}

	/**
	 * Validate and normalize the import payload before folder writes.
	 *
	 * @param array<string, mixed> $data Raw import payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function validate_import_payload(array $data) {
		$folders = isset($data['folders']) && is_array($data['folders']) ? $data['folders'] : [];

		if (empty($folders)) {
			return new \WP_Error('myfiles_empty_import');
		}

		if (count($folders) > self::MAX_IMPORT_FOLDERS) {
			return new \WP_Error('import-too-large');
		}

		$clean_folders = [];

		foreach ($folders as $folder) {
			if (! is_array($folder)) {
				continue;
			}

			$id   = isset($folder['id']) ? absint($folder['id']) : 0;
			$name = isset($folder['name']) ? sanitize_text_field((string) $folder['name']) : '';

			if ($id < 1 || '' === $name || strlen($name) > 200) {
				continue;
			}

			$clean_folders[] = [
				'id'       => $id,
				'name'     => $name,
				'parent'   => isset($folder['parent']) ? absint($folder['parent']) : 0,
				'order'    => isset($folder['order']) ? absint($folder['order']) : 0,
				'color'    => isset($folder['color']) ? sanitize_hex_color((string) $folder['color']) : '',
				'favorite' => ! empty($folder['favorite']),
			];
		}

		if (empty($clean_folders)) {
			return new \WP_Error('myfiles_empty_import');
		}

		$assignments = isset($data['assignments']) && is_array($data['assignments']) ? $data['assignments'] : [];

		if (count($assignments) > self::MAX_IMPORT_ASSIGNMENTS) {
			return new \WP_Error('import-too-large');
		}

		$clean_assignments = [];

		foreach ($assignments as $assignment) {
			if (! is_array($assignment)) {
				continue;
			}

			$attachment_id = isset($assignment['attachment_id']) ? absint($assignment['attachment_id']) : 0;
			$folder_id     = isset($assignment['folder_id']) ? absint($assignment['folder_id']) : 0;

			if ($attachment_id < 1 || $folder_id < 1) {
				continue;
			}

			$clean_assignments[] = [
				'attachment_id' => $attachment_id,
				'folder_id'     => $folder_id,
			];
		}

		return [
			'plugin'      => 'MY Files PRO',
			'version'     => isset($data['version']) ? sanitize_text_field((string) $data['version']) : '',
			'generatedAt' => isset($data['generatedAt']) ? sanitize_text_field((string) $data['generatedAt']) : '',
			'folders'     => $clean_folders,
			'assignments' => $clean_assignments,
		];
	}

	/**
	 * Build settings redirect URL.
	 */
	private function settings_url(string $status): string {
		return add_query_arg(
			[
				'page'                      => 'my-files-pro',
				'myfiles_import'      => sanitize_key($status),
			],
			admin_url('options-general.php')
		);
	}
}
