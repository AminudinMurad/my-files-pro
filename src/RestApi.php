<?php
/**
 * REST API endpoints.
 *
 * @license   GPL-3.0-or-later. See LICENSE in the plugin root.
 * @package MyFilesPro
 */

declare(strict_types=1);

namespace MyFilesPro;

final class RestApi {
	private const NAMESPACE = 'my-files-pro/v1';
	private const MAX_ATTACHMENT_MOVE = 500;
	private const MAX_FOLDER_ORDER_ITEMS = 1000;

	private Folders $folders;

	private Settings $settings;

	public function __construct(Folders $folders, Settings $settings) {
		$this->folders  = $folders;
		$this->settings = $settings;
	}

	/**
	 * Register REST hooks.
	 */
	public function boot(): void {
		add_action('rest_api_init', [$this, 'register_routes']);
	}

	/**
	 * Register plugin REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/folders',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [$this, 'get_folders'],
					'permission_callback' => [$this, 'can_read_media'],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [$this, 'create_folder'],
					'permission_callback' => [$this, 'can_manage_folders'],
					'args'                => [
						'name'     => $this->string_arg(true, 1, 200),
						'parent'   => $this->integer_arg(false, 0),
						'color'    => $this->color_arg(),
						'favorite' => $this->boolean_arg(),
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/folders/(?P<id>\d+)',
			[
				[
					'methods'             => ['POST', 'PUT', 'PATCH'],
					'callback'            => [$this, 'update_folder'],
					'permission_callback' => [$this, 'can_manage_folders'],
					'args'                => [
						'id'       => $this->integer_arg(true, 1),
						'name'     => $this->string_arg(false, 1, 200),
						'parent'   => $this->integer_arg(false, 0),
						'order'    => $this->integer_arg(false, 0, self::MAX_FOLDER_ORDER_ITEMS),
						'color'    => $this->color_arg(),
						'favorite' => $this->boolean_arg(),
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [$this, 'delete_folder'],
					'permission_callback' => [$this, 'can_manage_folders'],
					'args'                => [
						'id'     => $this->integer_arg(true, 1),
						'moveTo' => $this->integer_arg(false, 0),
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/folders/(?P<id>\d+)/duplicate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [$this, 'duplicate_folder'],
				'permission_callback' => [$this, 'can_manage_folders'],
				'args'                => [
					'id' => $this->integer_arg(true, 1),
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/folders/order',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [$this, 'update_order'],
				'permission_callback' => [$this, 'can_manage_folders'],
				'args'                => [
					'items' => [
						'required'          => true,
						'type'              => 'array',
						'validate_callback' => [$this, 'validate_order_items'],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/attachments/move',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [$this, 'move_attachments'],
				'permission_callback' => [$this, 'can_manage_folders'],
				'args'                => [
					'attachmentIds' => [
						'required'          => true,
						'type'              => 'array',
						'validate_callback' => [$this, 'validate_attachment_ids'],
					],
					'folderId'      => $this->integer_arg(true, 0),
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [$this, 'get_settings'],
					'permission_callback' => [$this, 'can_manage_options'],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [$this, 'save_settings'],
					'permission_callback' => [$this, 'can_manage_options'],
				],
			]
		);
	}

	/**
	 * Return folders and special views.
	 */
	public function get_folders(): \WP_REST_Response {
		$settings    = $this->settings->get();
		$show_counts = ! empty($settings['show_counts']);

		return rest_ensure_response(
			[
				'folders' => $this->folders->get_flat_folders($show_counts),
				'special' => [
					'all'           => [
						'id'    => 'all',
						'name'  => __('All Files', 'my-files-pro'),
						'count' => $show_counts ? $this->folders->get_all_count() : null,
					],
					'uncategorized' => [
						'id'    => 'uncategorized',
						'name'  => __('Uncategorized', 'my-files-pro'),
						'count' => $show_counts ? $this->folders->get_uncategorized_count() : null,
					],
				],
			]
		);
	}

	/**
	 * Create a folder.
	 */
	public function create_folder(\WP_REST_Request $request) {
		$result = $this->folders->create_folder(
			(string) $request->get_param('name'),
			absint($request->get_param('parent')),
			[
				'color'    => $request->get_param('color'),
				'favorite' => $request->get_param('favorite'),
			]
		);

		return is_wp_error($result) ? $result : rest_ensure_response($result);
	}

	/**
	 * Update a folder.
	 */
	public function update_folder(\WP_REST_Request $request) {
		$id     = absint($request['id']);
		$params = [];

		foreach (['name', 'parent', 'order', 'color', 'favorite'] as $key) {
			if (null !== $request->get_param($key)) {
				$params[$key] = $request->get_param($key);
			}
		}

		$result = $this->folders->update_folder($id, $params);

		return is_wp_error($result) ? $result : rest_ensure_response($result);
	}

	/**
	 * Delete a folder.
	 */
	public function delete_folder(\WP_REST_Request $request) {
		$id      = absint($request['id']);
		$move_to = absint($request->get_param('moveTo'));
		$result  = $this->folders->delete_folder($id, $move_to);

		return is_wp_error($result)
			? $result
			: rest_ensure_response(['deleted' => true]);
	}

	/**
	 * Duplicate a folder branch.
	 */
	public function duplicate_folder(\WP_REST_Request $request) {
		$result = $this->folders->duplicate_folder(absint($request['id']));

		return is_wp_error($result) ? $result : rest_ensure_response($result);
	}

	/**
	 * Save folder ordering.
	 */
	public function update_order(\WP_REST_Request $request): \WP_REST_Response {
		$items = $request->get_param('items');

		return rest_ensure_response(
			$this->folders->update_order(is_array($items) ? array_slice($items, 0, self::MAX_FOLDER_ORDER_ITEMS) : [])
		);
	}

	/**
	 * Move selected attachments.
	 */
	public function move_attachments(\WP_REST_Request $request) {
		$ids       = $request->get_param('attachmentIds');
		$folder_id = absint($request->get_param('folderId'));

		if (! is_array($ids)) {
			return new \WP_Error('myfiles_invalid_attachments', __('Attachment IDs must be an array.', 'my-files-pro'), ['status' => 400]);
		}

		$attachment_ids = array_values(array_unique(array_filter(array_map('absint', $ids))));

		if (empty($attachment_ids)) {
			return new \WP_Error('myfiles_invalid_attachments', __('Select at least one valid attachment.', 'my-files-pro'), ['status' => 400]);
		}

		$result = $this->folders->assign_attachments($attachment_ids, $folder_id);

		return is_wp_error($result) ? $result : rest_ensure_response($result);
	}

	/**
	 * Return settings.
	 */
	public function get_settings(): \WP_REST_Response {
		return rest_ensure_response($this->settings->get());
	}

	/**
	 * Save settings.
	 */
	public function save_settings(\WP_REST_Request $request): \WP_REST_Response {
		$params = $request->get_json_params();

		$this->settings->save(is_array($params) ? $params : []);

		return rest_ensure_response($this->settings->get());
	}

	/**
	 * Read permission.
	 */
	public function can_read_media(): bool {
		return current_user_can('upload_files');
	}

	/**
	 * Folder management permission.
	 */
	public function can_manage_folders(): bool {
		return $this->settings->current_user_can_manage_folders();
	}

	/**
	 * Settings permission.
	 */
	public function can_manage_options(): bool {
		return current_user_can('manage_options');
	}

	/**
	 * Validate REST attachment ID arrays.
	 */
	public function validate_attachment_ids($value): bool {
		if (! is_array($value) || empty($value) || count($value) > self::MAX_ATTACHMENT_MOVE) {
			return false;
		}

		foreach ($value as $id) {
			if (! self::is_integer_like($id) || absint($id) < 1) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate folder order payloads.
	 */
	public function validate_order_items($value): bool {
		if (! is_array($value) || count($value) > self::MAX_FOLDER_ORDER_ITEMS) {
			return false;
		}

		foreach ($value as $item) {
			if (! is_array($item) || empty($item['id']) || ! self::is_integer_like($item['id']) || absint($item['id']) < 1) {
				return false;
			}

			if (isset($item['parent']) && ! self::is_integer_like($item['parent'])) {
				return false;
			}

			if (isset($item['order']) && (! self::is_integer_like($item['order']) || absint($item['order']) > self::MAX_FOLDER_ORDER_ITEMS)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Integer REST argument definition.
	 *
	 * @return array<string, mixed>
	 */
	private function integer_arg(bool $required, int $minimum = 0, ?int $maximum = null): array {
		$arg = [
			'required'          => $required,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => static function ($value) use ($minimum, $maximum): bool {
				if (! self::is_integer_like($value)) {
					return false;
				}

				$value = absint($value);

				if ($value < $minimum) {
					return false;
				}

				return null === $maximum || $value <= $maximum;
			},
		];

		return $arg;
	}

	/**
	 * String REST argument definition.
	 *
	 * @return array<string, mixed>
	 */
	private function string_arg(bool $required, int $minimum_length = 0, int $maximum_length = 200): array {
		return [
			'required'          => $required,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => static function ($value) use ($minimum_length, $maximum_length): bool {
				if (! is_scalar($value)) {
					return false;
				}

				$value  = sanitize_text_field((string) $value);
				$length = strlen($value);

				return $length >= $minimum_length && $length <= $maximum_length;
			},
		];
	}

	/**
	 * Hex color REST argument definition.
	 *
	 * @return array<string, mixed>
	 */
	private function color_arg(): array {
		return [
			'required'          => false,
			'type'              => 'string',
			'sanitize_callback' => static function ($value): string {
				if (! is_scalar($value)) {
					return '';
				}

				$value = trim((string) $value);

				if ('' === $value) {
					return '';
				}

				if (0 !== strpos($value, '#')) {
					$value = '#' . $value;
				}

				return sanitize_hex_color($value) ?: '';
			},
			'validate_callback' => static function ($value): bool {
				if (! is_scalar($value)) {
					return false;
				}

				$value = trim((string) $value);

				if ('' === $value) {
					return true;
				}

				if (0 !== strpos($value, '#')) {
					$value = '#' . $value;
				}

				return (bool) sanitize_hex_color($value);
			},
		];
	}

	/**
	 * Boolean REST argument definition.
	 *
	 * @return array<string, mixed>
	 */
	private function boolean_arg(): array {
		return [
			'required'          => false,
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'validate_callback' => static function ($value): bool {
				return is_bool($value) || in_array($value, [0, 1, '0', '1', 'false', 'true'], true);
			},
		];
	}

	/**
	 * Determine whether a REST value is a non-negative integer.
	 */
	private static function is_integer_like($value): bool {
		if (is_int($value)) {
			return $value >= 0;
		}

		if (is_string($value)) {
			return preg_match('/^\d+$/', $value) === 1;
		}

		return false;
	}
}
