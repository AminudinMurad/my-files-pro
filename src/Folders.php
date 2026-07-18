<?php
/**
 * Folder taxonomy and attachment assignment service.
 *
 * @license   GPL-3.0-or-later. See LICENSE in the plugin root.
 * @package MyFilesPro
 */

declare(strict_types=1);

namespace MyFilesPro;

final class Folders {
	public const TAXONOMY      = 'myfiles_folder';
	public const META_ORDER    = '_myfiles_order';
	public const META_COLOR    = '_myfiles_color';
	public const META_FAVORITE = '_myfiles_favorite';

	/**
	 * Per-request folder count cache.
	 *
	 * @var array<int, int>
	 */
	private array $folder_counts = [];

	/**
	 * Register runtime hooks.
	 */
	public function boot(): void {
		add_action('init', [self::class, 'register_taxonomy'], 0);
	}

	/**
	 * Register the private hierarchical taxonomy used as media folders.
	 */
	public static function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			'attachment',
			[
				'labels'            => [
					'name'          => __('MyFiles Folders', 'my-files-pro'),
					'singular_name' => __('MyFiles Folder', 'my-files-pro'),
				],
				'public'            => false,
				'hierarchical'      => true,
				'show_ui'           => false,
				'show_admin_column' => false,
				'show_in_menu'      => false,
				'show_in_nav_menus' => false,
				'show_tagcloud'     => false,
				'show_in_rest'      => false,
				'query_var'             => false,
				'rewrite'               => false,
				'update_count_callback' => '_update_generic_term_count',
				'capabilities'          => [
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'manage_options',
				],
			]
		);
	}

	/**
	 * Return a flat ordered folder list.
	 *
	 * @param bool $include_counts Whether counts should be returned.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_flat_folders(bool $include_counts = true): array {
		$terms = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'meta_key'   => self::META_ORDER,
				'orderby'    => 'meta_value_num',
				'order'      => 'ASC',
			]
		);

		if (is_wp_error($terms) || ! is_array($terms)) {
			return [];
		}

		usort(
			$terms,
			static function (\WP_Term $a, \WP_Term $b): int {
				$parent_compare = $a->parent <=> $b->parent;

				if (0 !== $parent_compare) {
					return $parent_compare;
				}

				$a_order = (int) get_term_meta($a->term_id, self::META_ORDER, true);
				$b_order = (int) get_term_meta($b->term_id, self::META_ORDER, true);

				if ($a_order === $b_order) {
					return strcasecmp($a->name, $b->name);
				}

				return $a_order <=> $b_order;
			}
		);

		$folders = [];

		foreach ($terms as $term) {
			$folders[] = $this->format_term($term, $include_counts);
		}

		return $folders;
	}

	/**
	 * Return direct attachment count for all files.
	 */
	public function get_all_count(): int {
		$count = wp_count_posts('attachment');

		return isset($count->inherit) ? (int) $count->inherit : 0;
	}

	/**
	 * Return count of attachments without a folder.
	 */
	public function get_uncategorized_count(): int {
		$query = new \WP_Query(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'tax_query'      => [
					[
						'taxonomy' => self::TAXONOMY,
						'operator' => 'NOT EXISTS',
					],
				],
			]
		);

		return (int) $query->found_posts;
	}

	/**
	 * Return the real attachment count for a folder, including child folders.
	 */
	public function get_folder_count(int $folder_id): int {
		if (isset($this->folder_counts[$folder_id])) {
			return $this->folder_counts[$folder_id];
		}

		$query = new \WP_Query(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'tax_query'      => [
					[
						'taxonomy'         => self::TAXONOMY,
						'field'            => 'term_id',
						'terms'            => [$folder_id],
						'include_children' => true,
					],
				],
			]
		);

		$this->folder_counts[$folder_id] = (int) $query->found_posts;

		return $this->folder_counts[$folder_id];
	}

	/**
	 * Create a folder term.
	 *
	 * @param string               $name Folder name.
	 * @param int                  $parent Parent term ID.
	 * @param array<string, mixed> $meta Optional folder metadata.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_folder(string $name, int $parent = 0, array $meta = []) {
		$name = sanitize_text_field($name);

		if ('' === $name) {
			return new \WP_Error('myfiles_empty_name', __('Folder name is required.', 'my-files-pro'), ['status' => 400]);
		}

		if ($parent > 0 && ! $this->folder_exists($parent)) {
			return new \WP_Error('myfiles_missing_parent', __('Parent folder does not exist.', 'my-files-pro'), ['status' => 404]);
		}

		$name = $this->unique_folder_name($name, $parent);

		$inserted = wp_insert_term(
			$name,
			self::TAXONOMY,
			[
				'parent' => $parent,
			]
		);

		if (is_wp_error($inserted)) {
			return $inserted;
		}

		$term_id = (int) $inserted['term_id'];
		update_term_meta($term_id, self::META_ORDER, $this->next_order($parent));

		if (isset($meta['color'])) {
			update_term_meta($term_id, self::META_COLOR, $this->sanitize_color((string) $meta['color']));
		}

		if (isset($meta['favorite'])) {
			update_term_meta($term_id, self::META_FAVORITE, ! empty($meta['favorite']) ? '1' : '0');
		}

		$term = get_term($term_id, self::TAXONOMY);

		if (! $term instanceof \WP_Term) {
			return new \WP_Error('myfiles_create_failed', __('Folder could not be loaded after creation.', 'my-files-pro'), ['status' => 500]);
		}

		return $this->format_term($term, true);
	}

	/**
	 * Update a folder term.
	 *
	 * @param int                  $folder_id Folder term ID.
	 * @param array<string, mixed> $data Update payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_folder(int $folder_id, array $data) {
		$term = get_term($folder_id, self::TAXONOMY);

		if (! $term instanceof \WP_Term) {
			return new \WP_Error('myfiles_missing_folder', __('Folder does not exist.', 'my-files-pro'), ['status' => 404]);
		}

		$args = [];

		if (isset($data['name'])) {
			$name = sanitize_text_field((string) $data['name']);

			if ('' === $name) {
				return new \WP_Error('myfiles_empty_name', __('Folder name is required.', 'my-files-pro'), ['status' => 400]);
			}

			$args['name'] = $name;
		}

		if (isset($data['parent'])) {
			$parent = absint($data['parent']);

			if ($parent === $folder_id || $this->is_descendant($parent, $folder_id)) {
				return new \WP_Error('myfiles_invalid_parent', __('A folder cannot be moved into itself or its own child.', 'my-files-pro'), ['status' => 400]);
			}

			if ($parent > 0 && ! $this->folder_exists($parent)) {
				return new \WP_Error('myfiles_missing_parent', __('Parent folder does not exist.', 'my-files-pro'), ['status' => 404]);
			}

			$args['parent'] = $parent;
		}

		if (! empty($args)) {
			$updated = wp_update_term($folder_id, self::TAXONOMY, $args);

			if (is_wp_error($updated)) {
				return $updated;
			}
		}

		if (isset($data['order'])) {
			update_term_meta($folder_id, self::META_ORDER, absint($data['order']));
		}

		if (array_key_exists('color', $data)) {
			update_term_meta($folder_id, self::META_COLOR, $this->sanitize_color((string) $data['color']));
		}

		if (array_key_exists('favorite', $data)) {
			update_term_meta($folder_id, self::META_FAVORITE, ! empty($data['favorite']) ? '1' : '0');
		}

		$updated_term = get_term($folder_id, self::TAXONOMY);

		if (! $updated_term instanceof \WP_Term) {
			return new \WP_Error('myfiles_update_failed', __('Folder could not be loaded after update.', 'my-files-pro'), ['status' => 500]);
		}

		return $this->format_term($updated_term, true);
	}

	/**
	 * Delete a folder, moving assigned attachments if requested.
	 *
	 * @param int $folder_id Folder term ID.
	 * @param int $move_to Folder ID to receive current attachments. Use 0 to unassign.
	 * @return bool|\WP_Error
	 */
	public function delete_folder(int $folder_id, int $move_to = 0) {
		$term = get_term($folder_id, self::TAXONOMY);

		if (! $term instanceof \WP_Term) {
			return new \WP_Error('myfiles_missing_folder', __('Folder does not exist.', 'my-files-pro'), ['status' => 404]);
		}

		if ($move_to > 0 && ! $this->folder_exists($move_to)) {
			return new \WP_Error('myfiles_missing_destination', __('Destination folder does not exist.', 'my-files-pro'), ['status' => 404]);
		}

		if ($move_to === $folder_id || $this->is_descendant($move_to, $folder_id)) {
			return new \WP_Error('myfiles_invalid_destination', __('Destination cannot be this folder or its child.', 'my-files-pro'), ['status' => 400]);
		}

		$attachment_ids = $this->get_attachment_ids_for_folder($folder_id);

		foreach ($attachment_ids as $attachment_id) {
			if (! current_user_can('edit_post', $attachment_id)) {
				return new \WP_Error(
					'myfiles_delete_denied',
					__('This folder contains media you are not allowed to edit.', 'my-files-pro'),
					['status' => 403]
				);
			}
		}

		$this->assign_attachments($attachment_ids, $move_to);

		$children = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'parent'     => $folder_id,
				'fields'     => 'ids',
			]
		);

		if (! is_wp_error($children)) {
			foreach ($children as $child_id) {
				wp_update_term((int) $child_id, self::TAXONOMY, ['parent' => (int) $term->parent]);
			}
		}

		$deleted = wp_delete_term($folder_id, self::TAXONOMY);

		if (is_wp_error($deleted)) {
			return $deleted;
		}

		return true;
	}

	/**
	 * Duplicate a folder branch without duplicating media files.
	 *
	 * @param int $folder_id Folder term ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function duplicate_folder(int $folder_id) {
		$term = get_term($folder_id, self::TAXONOMY);

		if (! $term instanceof \WP_Term) {
			return new \WP_Error('myfiles_missing_folder', __('Folder does not exist.', 'my-files-pro'), ['status' => 404]);
		}

		$new_name = sprintf(
			/* translators: %s: original folder name. */
			__('%s Copy', 'my-files-pro'),
			$term->name
		);

		$created = $this->create_folder($new_name, (int) $term->parent);

		if (is_wp_error($created)) {
			return $created;
		}

		update_term_meta((int) $created['id'], self::META_COLOR, $this->sanitize_color((string) get_term_meta($term->term_id, self::META_COLOR, true)));
		update_term_meta((int) $created['id'], self::META_FAVORITE, '1' === (string) get_term_meta($term->term_id, self::META_FAVORITE, true) ? '1' : '0');

		$this->duplicate_children($folder_id, (int) $created['id']);

		return $created;
	}

	/**
	 * Update folder order and optionally parent from a bulk payload.
	 *
	 * @param array<int, array<string, mixed>> $items Ordered folder rows.
	 * @return array<string, mixed>
	 */
	public function update_order(array $items): array {
		$updated = 0;

		foreach ($items as $index => $item) {
			$folder_id = isset($item['id']) ? absint($item['id']) : 0;

			if (! $folder_id || ! $this->folder_exists($folder_id)) {
				continue;
			}

			$data = [
				'order' => isset($item['order']) ? absint($item['order']) : $index,
			];

			if (array_key_exists('parent', $item)) {
				$data['parent'] = absint($item['parent']);
			}

			$result = $this->update_folder($folder_id, $data);

			if (! is_wp_error($result)) {
				++$updated;
			}
		}

		return ['updated' => $updated];
	}

	/**
	 * Assign attachments to one folder. Use folder ID 0 to unassign.
	 *
	 * @param array<int, int> $attachment_ids Attachment IDs.
	 * @param int            $folder_id Folder term ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function assign_attachments(array $attachment_ids, int $folder_id) {
		$attachment_ids = array_values(array_unique(array_filter(array_map('absint', $attachment_ids))));

		if ($folder_id > 0 && ! $this->folder_exists($folder_id)) {
			return new \WP_Error('myfiles_missing_folder', __('Folder does not exist.', 'my-files-pro'), ['status' => 404]);
		}

		$moved = 0;
		$skipped = 0;
		$touched_folder_ids = $folder_id > 0 ? [$folder_id] : [];

		foreach ($attachment_ids as $attachment_id) {
			if (! $attachment_id || 'attachment' !== get_post_type($attachment_id)) {
				++$skipped;
				continue;
			}

			if (! current_user_can('edit_post', $attachment_id)) {
				++$skipped;
				continue;
			}

			$previous_terms = wp_get_object_terms($attachment_id, self::TAXONOMY, ['fields' => 'ids']);

			if (! is_wp_error($previous_terms)) {
				$touched_folder_ids = array_merge($touched_folder_ids, array_map('absint', $previous_terms));
			}

			$result = $folder_id > 0
				? wp_set_object_terms($attachment_id, [$folder_id], self::TAXONOMY, false)
				: wp_set_object_terms($attachment_id, [], self::TAXONOMY, false);

			if (! is_wp_error($result)) {
				++$moved;
			} else {
				++$skipped;
			}
		}

		$this->refresh_folder_counts($touched_folder_ids);

		return [
			'moved'   => $moved,
			'skipped' => $skipped,
		];
	}

	/**
	 * Build export data for folders and assignments.
	 *
	 * @return array<string, mixed>
	 */
	public function export_data(): array {
		$folders     = $this->get_flat_folders(false);
		$attachments = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		$assignments = [];

		foreach ($attachments as $attachment_id) {
			$terms = wp_get_object_terms((int) $attachment_id, self::TAXONOMY, ['fields' => 'ids']);

			if (is_wp_error($terms) || empty($terms)) {
				continue;
			}

			$assignments[] = [
				'attachment_id' => (int) $attachment_id,
				'folder_id'     => (int) $terms[0],
			];
		}

		return [
			'plugin'      => 'MY Files PRO',
			'version'     => MY_FILES_PRO_VERSION,
			'generatedAt' => gmdate('c'),
			'folders'     => $folders,
			'assignments' => $assignments,
		];
	}

	/**
	 * Import folder structure and optional media assignments.
	 *
	 * @param array<string, mixed> $data Import payload.
	 * @param bool                 $include_assignments Whether to import assignments.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function import_data(array $data, bool $include_assignments = true) {
		$folders = isset($data['folders']) && is_array($data['folders']) ? $data['folders'] : [];

		if (empty($folders)) {
			return new \WP_Error('myfiles_empty_import', __('No folders found in import file.', 'my-files-pro'), ['status' => 400]);
		}

		$pending = $folders;
		$id_map  = [];
		$created = 0;

		while (! empty($pending)) {
			$progress = false;

			foreach ($pending as $key => $folder) {
				$old_id     = isset($folder['id']) ? absint($folder['id']) : 0;
				$old_parent = isset($folder['parent']) ? absint($folder['parent']) : 0;

				if ($old_parent > 0 && ! isset($id_map[$old_parent])) {
					continue;
				}

				$name       = isset($folder['name']) ? sanitize_text_field((string) $folder['name']) : '';
				$new_parent = $old_parent > 0 ? (int) $id_map[$old_parent] : 0;

				if ('' === $name || ! $old_id) {
					unset($pending[$key]);
					$progress = true;
					continue;
				}

				$existing = term_exists($name, self::TAXONOMY, $new_parent);

				if (is_array($existing) && isset($existing['term_id'])) {
					$new_id = (int) $existing['term_id'];
				} else {
					$result = $this->create_folder($name, $new_parent);

					if (is_wp_error($result)) {
						unset($pending[$key]);
						$progress = true;
						continue;
					}

					$new_id = (int) $result['id'];
					++$created;
				}

				$id_map[$old_id] = $new_id;

				if (isset($folder['order'])) {
					update_term_meta($new_id, self::META_ORDER, absint($folder['order']));
				}

				if (isset($folder['color'])) {
					update_term_meta($new_id, self::META_COLOR, $this->sanitize_color((string) $folder['color']));
				}

				if (isset($folder['favorite'])) {
					update_term_meta($new_id, self::META_FAVORITE, ! empty($folder['favorite']) ? '1' : '0');
				}

				unset($pending[$key]);
				$progress = true;
			}

			if (! $progress) {
				break;
			}
		}

		$assigned = 0;

		if ($include_assignments && isset($data['assignments']) && is_array($data['assignments'])) {
			foreach ($data['assignments'] as $assignment) {
				$attachment_id = isset($assignment['attachment_id']) ? absint($assignment['attachment_id']) : 0;
				$old_folder_id = isset($assignment['folder_id']) ? absint($assignment['folder_id']) : 0;

				if (! $attachment_id || ! isset($id_map[$old_folder_id]) || 'attachment' !== get_post_type($attachment_id)) {
					continue;
				}

				if (! current_user_can('edit_post', $attachment_id)) {
					continue;
				}

				$result = wp_set_object_terms($attachment_id, [(int) $id_map[$old_folder_id]], self::TAXONOMY, false);

				if (! is_wp_error($result)) {
					++$assigned;
				}
			}
		}

		return [
			'created'  => $created,
			'mapped'   => count($id_map),
			'assigned' => $assigned,
			'skipped'  => count($pending),
		];
	}

	/**
	 * Refresh WordPress term counts and clear local cache for changed folders.
	 *
	 * @param array<int, int> $folder_ids Folder term IDs.
	 */
	private function refresh_folder_counts(array $folder_ids): void {
		$folder_ids = array_values(array_unique(array_filter(array_map('absint', $folder_ids))));

		if (empty($folder_ids)) {
			$this->folder_counts = [];
			return;
		}

		foreach ($folder_ids as $folder_id) {
			$folder_ids = array_merge($folder_ids, array_map('absint', get_ancestors($folder_id, self::TAXONOMY, 'taxonomy')));
		}

		$folder_ids = array_values(array_unique(array_filter($folder_ids)));

		wp_update_term_count_now($folder_ids, self::TAXONOMY);
		clean_term_cache($folder_ids, self::TAXONOMY);

		foreach ($folder_ids as $folder_id) {
			unset($this->folder_counts[$folder_id]);
		}
	}

	/**
	 * Determine if a folder term exists.
	 */
	public function folder_exists(int $folder_id): bool {
		$term = get_term($folder_id, self::TAXONOMY);

		return $term instanceof \WP_Term;
	}

	/**
	 * Format a term for REST and JavaScript.
	 *
	 * @param \WP_Term $term Term object.
	 * @param bool     $include_count Whether to return the term count.
	 * @return array<string, mixed>
	 */
	private function format_term(\WP_Term $term, bool $include_count): array {
		return [
			'id'     => (int) $term->term_id,
			'name'   => $term->name,
			'slug'   => $term->slug,
			'parent' => (int) $term->parent,
			'count'  => $include_count ? $this->get_folder_count((int) $term->term_id) : null,
			'order'  => (int) get_term_meta($term->term_id, self::META_ORDER, true),
			'color'    => $this->sanitize_color((string) get_term_meta($term->term_id, self::META_COLOR, true)),
			'favorite' => '1' === (string) get_term_meta($term->term_id, self::META_FAVORITE, true),
		];
	}

	/**
	 * Sanitize a folder color value.
	 */
	private function sanitize_color(string $color): string {
		$color = trim($color);

		if ('' === $color) {
			return '';
		}

		if (0 !== strpos($color, '#')) {
			$color = '#' . $color;
		}

		return sanitize_hex_color($color) ?: '';
	}

	/**
	 * Return next sibling order for a folder.
	 */
	private function next_order(int $parent): int {
		$terms = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'parent'     => $parent,
				'fields'     => 'ids',
			]
		);

		if (is_wp_error($terms) || empty($terms)) {
			return 0;
		}

		return count($terms);
	}

	/**
	 * Determine whether $maybe_child is inside $folder_id.
	 */
	private function is_descendant(int $maybe_child, int $folder_id): bool {
		if ($maybe_child <= 0) {
			return false;
		}

		$ancestors = get_ancestors($maybe_child, self::TAXONOMY, 'taxonomy');

		return in_array($folder_id, array_map('absint', $ancestors), true);
	}

	/**
	 * Duplicate a branch recursively.
	 */
	private function duplicate_children(int $source_parent, int $new_parent): void {
		$children = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'parent'     => $source_parent,
			]
		);

		if (is_wp_error($children) || empty($children)) {
			return;
		}

		foreach ($children as $child) {
			if (! $child instanceof \WP_Term) {
				continue;
			}

			$created = $this->create_folder($child->name, $new_parent);

			if (is_wp_error($created)) {
				continue;
			}

			update_term_meta((int) $created['id'], self::META_ORDER, (int) get_term_meta($child->term_id, self::META_ORDER, true));
			update_term_meta((int) $created['id'], self::META_COLOR, $this->sanitize_color((string) get_term_meta($child->term_id, self::META_COLOR, true)));
			update_term_meta((int) $created['id'], self::META_FAVORITE, '1' === (string) get_term_meta($child->term_id, self::META_FAVORITE, true) ? '1' : '0');
			$this->duplicate_children((int) $child->term_id, (int) $created['id']);
		}
	}

	/**
	 * Return a folder name that is unique within one parent.
	 */
	private function unique_folder_name(string $name, int $parent): string {
		$candidate = $name;
		$suffix    = 2;

		while (term_exists($candidate, self::TAXONOMY, $parent)) {
			$candidate = sprintf('%s %d', $name, $suffix);
			++$suffix;

			if ($suffix > 1000) {
				break;
			}
		}

		return $candidate;
	}

	/**
	 * Return attachment IDs assigned to a folder.
	 *
	 * @return array<int, int>
	 */
	private function get_attachment_ids_for_folder(int $folder_id): array {
		$ids = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => [
					[
						'taxonomy' => self::TAXONOMY,
						'field'    => 'term_id',
						'terms'    => [$folder_id],
					],
				],
			]
		);

		return array_map('absint', $ids);
	}
}
