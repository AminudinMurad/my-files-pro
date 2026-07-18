<?php
/**
 * Media Library query integration.
 *
 * @license   GPL-3.0-or-later. See LICENSE in the plugin root.
 * @package MyFilesPro
 */

declare(strict_types=1);

namespace MyFilesPro;

final class Media {
	private Folders $folders;

	private Settings $settings;

	public function __construct(Folders $folders, Settings $settings) {
		$this->folders  = $folders;
		$this->settings = $settings;
	}

	/**
	 * Register media hooks.
	 */
	public function boot(): void {
		add_filter('ajax_query_attachments_args', [$this, 'filter_ajax_attachments']);
		add_action('pre_get_posts', [$this, 'filter_list_view']);
		add_action('add_attachment', [$this, 'assign_upload_folder']);
	}

	/**
	 * Filter media grid and media modal AJAX queries.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, mixed>
	 */
	public function filter_ajax_attachments(array $args): array {
		if (! current_user_can('upload_files')) {
			return $args;
		}

		$folder = null;

		if (isset($_REQUEST['query']) && is_array($_REQUEST['query'])) {
			$query = wp_unslash($_REQUEST['query']);

			if (isset($query['myfiles_folder'])) {
				$folder = $this->normalize_folder_selector($query['myfiles_folder']);
			}
		}

		if (isset($args['myfiles_folder'])) {
			$folder = $this->normalize_folder_selector($args['myfiles_folder']);
			unset($args['myfiles_folder']);
		}

		return $this->apply_folder_filter($args, $folder);
	}

	/**
	 * Filter list table query on upload.php.
	 */
	public function filter_list_view(\WP_Query $query): void {
		if (! is_admin() || ! $query->is_main_query() || ! current_user_can('upload_files')) {
			return;
		}

		global $pagenow;

		if ('upload.php' !== $pagenow) {
			return;
		}

		$post_type = $query->get('post_type');

		if ('attachment' !== $post_type && ! empty($post_type)) {
			return;
		}

		if (! isset($_GET['myfiles_folder'])) {
			return;
		}

		$folder = $this->normalize_folder_selector(wp_unslash((string) $_GET['myfiles_folder']));
		$args   = $this->apply_folder_filter($query->query_vars, $folder);

		if (isset($args['tax_query'])) {
			$query->set('tax_query', $args['tax_query']);
		}
	}

	/**
	 * Assign newly uploaded files to the selected or default folder.
	 */
	public function assign_upload_folder(int $attachment_id): void {
		if (! current_user_can('upload_files')) {
			return;
		}

		if (! current_user_can('edit_post', $attachment_id)) {
			return;
		}

		$folder_id = 0;

		if ($this->settings->current_user_can_manage_folders() && isset($_REQUEST['myfiles_folder'])) {
			$folder_id = absint(wp_unslash($_REQUEST['myfiles_folder']));
		}

		if (0 === $folder_id) {
			$folder_id = $this->settings->default_upload_folder();
		}

		if ($folder_id > 0 && $this->folders->folder_exists($folder_id)) {
			$this->folders->assign_attachments([$attachment_id], $folder_id);
		}
	}

	/**
	 * Apply folder filtering to query args.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @param string|null          $folder Folder selector.
	 * @return array<string, mixed>
	 */
	private function apply_folder_filter(array $args, ?string $folder): array {
		if (null === $folder || '' === $folder || 'all' === $folder) {
			return $args;
		}

		$tax_query = isset($args['tax_query']) && is_array($args['tax_query'])
			? $args['tax_query']
			: [];

		if ('uncategorized' === $folder || '0' === $folder) {
			$tax_query[] = [
				'taxonomy' => Folders::TAXONOMY,
				'operator' => 'NOT EXISTS',
			];
		} else {
			$folder_id = absint($folder);

			if ($folder_id <= 0 || ! $this->folders->folder_exists($folder_id)) {
				return $args;
			}

			$tax_query[] = [
				'taxonomy'         => Folders::TAXONOMY,
				'field'            => 'term_id',
				'terms'            => [$folder_id],
				'include_children' => true,
			];
		}

		if (count($tax_query) > 1 && ! isset($tax_query['relation'])) {
			$tax_query['relation'] = 'AND';
		}

		$args['tax_query'] = $tax_query;

		return $args;
	}

	/**
	 * Normalize folder selectors from requests.
	 */
	private function normalize_folder_selector($folder): ?string {
		if (! is_scalar($folder)) {
			return null;
		}

		$folder = sanitize_text_field((string) $folder);

		if (in_array($folder, ['all', 'uncategorized', '0'], true)) {
			return $folder;
		}

		return preg_match('/^\d+$/', $folder) === 1 ? $folder : null;
	}
}
