<?php
/**
 * Opt-in SVG upload support.
 *
 * @license   GPL-3.0-or-later. See LICENSE in the plugin root.
 * @package MyFilesPro
 */

declare(strict_types=1);

namespace MyFilesPro;

final class SvgUploads {
	private const MIME_TYPE = 'image/svg+xml';
	private const MAX_BYTES = 2097152;
	private const SVG_EXTENSIONS = ['svg', 'svgz'];
	private const SVG_MIME_TYPES = [
		self::MIME_TYPE,
		'image/svg',
		'application/svg+xml',
		'text/xml',
		'application/xml',
		'text/plain',
		'application/octet-stream',
	];

	private const ALLOWED_ELEMENTS = [
		'circle'         => true,
		'clippath'       => true,
		'defs'           => true,
		'desc'           => true,
		'ellipse'        => true,
		'g'              => true,
		'line'           => true,
		'lineargradient' => true,
		'marker'         => true,
		'mask'           => true,
		'path'           => true,
		'pattern'        => true,
		'polygon'        => true,
		'polyline'       => true,
		'radialgradient' => true,
		'rect'           => true,
		'stop'           => true,
		'svg'            => true,
		'symbol'         => true,
		'text'           => true,
		'title'          => true,
		'tspan'          => true,
		'use'            => true,
	];

	private const ALLOWED_ATTRIBUTES = [
		'aria-hidden'         => true,
		'aria-label'          => true,
		'class'               => true,
		'clip-path'           => true,
		'clip-rule'           => true,
		'cx'                  => true,
		'cy'                  => true,
		'd'                   => true,
		'direction'           => true,
		'display'             => true,
		'dominant-baseline'   => true,
		'fill'                => true,
		'fill-opacity'        => true,
		'fill-rule'           => true,
		'focusable'           => true,
		'font-family'         => true,
		'font-size'           => true,
		'font-style'          => true,
		'font-weight'         => true,
		'gradienttransform'   => true,
		'gradientunits'       => true,
		'height'              => true,
		'href'                => true,
		'id'                  => true,
		'letter-spacing'      => true,
		'marker-end'          => true,
		'marker-mid'          => true,
		'marker-start'        => true,
		'mask'                => true,
		'offset'              => true,
		'opacity'             => true,
		'overflow'            => true,
		'patternunits'        => true,
		'points'              => true,
		'preserveaspectratio' => true,
		'r'                   => true,
		'refx'                => true,
		'refy'                => true,
		'role'                => true,
		'rx'                  => true,
		'ry'                  => true,
		'spreadmethod'        => true,
		'stop-color'          => true,
		'stop-opacity'        => true,
		'stroke'              => true,
		'stroke-dasharray'    => true,
		'stroke-dashoffset'   => true,
		'stroke-linecap'      => true,
		'stroke-linejoin'     => true,
		'stroke-miterlimit'   => true,
		'stroke-opacity'      => true,
		'stroke-width'        => true,
		'text-anchor'         => true,
		'transform'           => true,
		'version'             => true,
		'viewbox'             => true,
		'visibility'          => true,
		'width'               => true,
		'x'                   => true,
		'x1'                  => true,
		'x2'                  => true,
		'xlink:href'          => true,
		'xmlns'               => true,
		'xmlns:xlink'         => true,
		'xml:space'           => true,
		'y'                   => true,
		'y1'                  => true,
		'y2'                  => true,
	];

	private const ALLOWED_STYLE_PROPERTIES = [
		'clip-rule'         => true,
		'fill'              => true,
		'fill-opacity'      => true,
		'fill-rule'         => true,
		'opacity'           => true,
		'stroke'            => true,
		'stroke-dasharray'  => true,
		'stroke-dashoffset' => true,
		'stroke-linecap'    => true,
		'stroke-linejoin'   => true,
		'stroke-miterlimit' => true,
		'stroke-opacity'    => true,
		'stroke-width'      => true,
	];

	private Settings $settings;

	public function __construct(Settings $settings) {
		$this->settings = $settings;
	}

	/**
	 * Register SVG upload hooks only when explicitly enabled.
	 */
	public function boot(): void {
		if (! $this->settings->svg_uploads_managed()) {
			return;
		}

		add_filter('mime_types', [$this, 'allow_svg_mime'], 99);
		add_filter('upload_mimes', [$this, 'allow_svg_mime'], 99);
		add_filter('wp_handle_upload_prefilter', [$this, 'sanitize_upload']);
		add_filter('wp_handle_sideload_prefilter', [$this, 'sanitize_upload']);
		add_filter('wp_check_filetype_and_ext', [$this, 'confirm_svg_filetype'], 75, 5);
		add_filter('wp_handle_upload', [$this, 'confirm_handled_svg_upload'], 10, 2);
		add_action('add_attachment', [$this, 'prime_svg_attachment_metadata']);
		add_action('rest_after_insert_attachment', [$this, 'prime_rest_svg_attachment_metadata'], 10, 3);
		add_filter('wp_generate_attachment_metadata', [$this, 'bypass_svg_metadata_generation'], 10, 3);
		add_filter('wp_update_attachment_metadata', [$this, 'normalize_svg_attachment_metadata'], 10, 2);
		add_filter('wp_get_attachment_metadata', [$this, 'normalize_svg_attachment_metadata'], 10, 2);
		add_filter('wp_get_missing_image_subsizes', [$this, 'remove_svg_missing_subsizes'], 10, 3);
		add_filter('intermediate_image_sizes_advanced', [$this, 'remove_svg_intermediate_sizes'], 10, 3);
		add_filter('file_is_displayable_image', [$this, 'disable_svg_displayable_image_processing'], 10, 2);
		add_filter('image_downsize', [$this, 'svg_image_downsize'], 10, 3);
		add_filter('wp_calculate_image_srcset', [$this, 'disable_svg_srcset'], 10, 5);
		add_filter('wp_prepare_attachment_for_js', [$this, 'prepare_svg_attachment_for_js'], 10, 3);
		add_filter('wp_prevent_unsupported_mime_type_uploads', [$this, 'allow_svg_unsupported_mime_uploads'], 10, 2);
		add_filter('rest_request_before_callbacks', [$this, 'disable_svg_rest_image_processing'], 0, 3);
		add_filter('rest_pre_dispatch', [$this, 'short_circuit_svg_post_process'], 10, 3);
		add_action('wp_ajax_media-create-image-subsizes', [$this, 'short_circuit_svg_ajax_subsizes'], 0);
		add_action('admin_head', [$this, 'print_svg_thumbnail_support_css']);
	}

	/**
	 * Allow SVG MIME type for administrators when managed mode is enabled.
	 *
	 * @param array<string, string> $mimes Allowed MIME types.
	 * @return array<string, string>
	 */
	public function allow_svg_mime(array $mimes): array {
		if ($this->current_user_can_upload_svg()) {
			$mimes['svg']  = self::MIME_TYPE;
			$mimes['svgz'] = self::MIME_TYPE;
		}

		return $mimes;
	}

	/**
	 * Sanitize an uploaded SVG before WordPress moves it into uploads.
	 *
	 * @param array<string, mixed> $file Upload file array.
	 * @return array<string, mixed>
	 */
	public function sanitize_upload(array $file): array {
		if (! $this->is_svg_upload($file)) {
			return $file;
		}

		if (! $this->current_user_can_upload_svg()) {
			$file['error'] = __('SVG uploads are only available to administrators when enabled in MY Files PRO settings.', 'my-files-pro');
			return $file;
		}

		$tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
		$name     = isset($file['name']) && ! is_array($file['name']) ? (string) $file['name'] : '';

		if (! $this->is_valid_svg_upload_file($tmp_name, $name)) {
			$file['error'] = __('File is not a valid SVG.', 'my-files-pro');
			return $file;
		}

		$result   = $this->sanitize_file($tmp_name);

		if (is_wp_error($result)) {
			$file['error'] = $result->get_error_message();
			return $file;
		}

		$file['type'] = self::MIME_TYPE;

		return $file;
	}

	/**
	 * Confirm the SVG MIME type after upload sanitization.
	 *
	 * @param mixed                 $data Existing filetype data.
	 * @param string               $file Full file path.
	 * @param string               $filename Original filename.
	 * @param mixed                $mimes Allowed mimes.
	 * @param mixed                $real_mime Detected MIME type.
	 * @return mixed
	 */
	public function confirm_svg_filetype($data = null, string $file = '', string $filename = '', $mimes = null, $real_mime = false) {
		unset($mimes, $real_mime);

		if (! $this->current_user_can_upload_svg() || ! $this->is_svg_filename($filename)) {
			return $data;
		}

		if (! $this->is_safe_svg_file($file)) {
			return $data;
		}

		$data = is_array($data) ? $data : [];

		$data['ext']             = $this->svg_extension_from_filename($filename);
		$data['type']            = self::MIME_TYPE;
		$data['proper_filename'] = false;

		return $data;
	}

	/**
	 * Keep the final moved upload array aligned with the sanitized SVG MIME type.
	 *
	 * @param array<string, mixed> $upload Upload result.
	 * @param mixed                $context Upload context.
	 * @return array<string, mixed>
	 */
	public function confirm_handled_svg_upload(array $upload, $context = null): array {
		unset($context);

		$file = isset($upload['file']) ? (string) $upload['file'] : '';

		if (! $this->current_user_can_upload_svg() || ! $this->is_svg_filename($file)) {
			return $upload;
		}

		if (! $this->is_safe_svg_file($file)) {
			return $upload;
		}

		$upload['type'] = self::MIME_TYPE;

		return $upload;
	}

	/**
	 * Prime SVG metadata immediately after WordPress creates the attachment.
	 */
	public function prime_svg_attachment_metadata(int $attachment_id): void {
		if (! $this->is_svg_attachment($attachment_id)) {
			return;
		}

		wp_update_attachment_metadata($attachment_id, $this->svg_attachment_metadata($attachment_id));
	}

	/**
	 * Prime SVG metadata before REST upload post-processing runs.
	 */
	public function prime_rest_svg_attachment_metadata(\WP_Post $attachment, \WP_REST_Request $request, bool $creating): void {
		unset($request, $creating);

		if (! $this->is_svg_attachment((int) $attachment->ID)) {
			return;
		}

		wp_update_attachment_metadata((int) $attachment->ID, $this->svg_attachment_metadata((int) $attachment->ID));
	}

	/**
	 * Prevent WordPress from processing SVG uploads with raster image editors.
	 */
	public function bypass_svg_metadata_generation($metadata, int $attachment_id, $context = null) {
		unset($context);

		if (! $this->is_svg_attachment($attachment_id)) {
			return $metadata;
		}

		return $this->svg_attachment_metadata($attachment_id);
	}

	/**
	 * Keep SVG attachment metadata in a predictable, non-raster format.
	 */
	public function normalize_svg_attachment_metadata($metadata, int $attachment_id) {
		if (! $this->is_svg_attachment($attachment_id)) {
			return $metadata;
		}

		if (is_array($metadata) && isset($metadata['file'], $metadata['width'], $metadata['height'], $metadata['sizes'])) {
			return $metadata;
		}

		return $this->svg_attachment_metadata($attachment_id);
	}

	/**
	 * SVG uploads do not need raster sub-size recovery.
	 *
	 * @param array<string, mixed> $missing_sizes Missing image sizes.
	 * @param array<string, mixed> $image_meta Attachment metadata.
	 * @return array<string, mixed>
	 */
	public function remove_svg_missing_subsizes(array $missing_sizes, array $image_meta, int $attachment_id): array {
		unset($image_meta);

		if ($this->is_svg_attachment($attachment_id)) {
			return [];
		}

		return $missing_sizes;
	}

	/**
	 * Avoid creating intermediate raster sizes for SVG uploads.
	 *
	 * @param array<string, mixed> $new_sizes Requested image sizes.
	 * @param array<string, mixed> $image_meta Attachment metadata.
	 * @return array<string, mixed>
	 */
	public function remove_svg_intermediate_sizes(array $new_sizes, array $image_meta, int $attachment_id): array {
		unset($image_meta);

		if ($this->is_svg_attachment($attachment_id)) {
			return [];
		}

		return $new_sizes;
	}

	/**
	 * Keep SVGs out of WordPress raster image-editor processing.
	 */
	public function disable_svg_displayable_image_processing(bool $result, string $path): bool {
		if ($this->is_svg_filename($path)) {
			return false;
		}

		return $result;
	}

	/**
	 * Return SVG dimensions without asking WordPress image editors to read the file.
	 *
	 * @return array{0:string,1:int,2:int,3:bool}|mixed
	 */
	public function svg_image_downsize($downsize, int $attachment_id, $size = null) {
		unset($size);

		if (! $this->is_svg_attachment($attachment_id)) {
			return $downsize;
		}

		$url = wp_get_attachment_url($attachment_id);

		if (! is_string($url) || '' === $url) {
			return $downsize;
		}

		$dimensions = $this->svg_attachment_dimensions($attachment_id);

		return [$url, $dimensions['width'], $dimensions['height'], false];
	}

	/**
	 * SVG images do not have raster srcset variants.
	 */
	public function disable_svg_srcset($sources, array $size_array, string $image_src, array $image_meta, int $attachment_id) {
		unset($size_array, $image_src, $image_meta);

		if ($this->is_svg_attachment($attachment_id)) {
			return false;
		}

		return $sources;
	}

	/**
	 * Make sanitized SVG attachments display correctly in the Media Library UI.
	 *
	 * @param array<string, mixed> $response Attachment response.
	 * @param \WP_Post             $attachment Attachment post.
	 * @param mixed                $meta Attachment metadata.
	 * @return array<string, mixed>
	 */
	public function prepare_svg_attachment_for_js(array $response, \WP_Post $attachment, $meta): array {
		unset($meta);

		if (! $this->is_svg_attachment((int) $attachment->ID)) {
			return $response;
		}

		$dimensions = $this->svg_attachment_dimensions((int) $attachment->ID);
		$url        = wp_get_attachment_url((int) $attachment->ID);

		$response['type']             = 'file';
		$response['subtype']          = 'svg+xml';
		$response['mime']             = self::MIME_TYPE;
		$response['width']            = $dimensions['width'];
		$response['height']           = $dimensions['height'];
		$response['myfilesSvg'] = true;

		if (is_string($url) && '' !== $url) {
			$response['url']   = $url;
			$response['icon']  = $url;
			$response['sizes'] = [
				'full' => [
					'url'         => $url,
					'width'       => $dimensions['width'],
					'height'      => $dimensions['height'],
					'orientation' => $dimensions['width'] >= $dimensions['height'] ? 'landscape' : 'portrait',
				],
			];
			$response['image'] = [
				'src'    => $url,
				'width'  => $dimensions['width'],
				'height' => $dimensions['height'],
			];
		} else {
			$response['sizes'] = [];
		}

		return $response;
	}

	/**
	 * Let managed SVG uploads pass WordPress' raster image support guard.
	 */
	public function allow_svg_unsupported_mime_uploads(bool $prevent, $mime_type = null): bool {
		if (! $this->current_user_can_upload_svg()) {
			return $prevent;
		}

		if (is_string($mime_type) && $this->is_exact_svg_mime($mime_type)) {
			return false;
		}

		if ($this->current_request_contains_svg_upload() && (! is_string($mime_type) || $this->is_svg_like_mime($mime_type))) {
			return false;
		}

		return $prevent;
	}

	/**
	 * Tell REST media uploads that SVGs do not need responsive sub-size work.
	 */
	public function disable_svg_rest_image_processing($response, $handler, $request) {
		unset($handler);

		if (! $request instanceof \WP_REST_Request || ! $this->is_rest_svg_media_upload($request)) {
			return $response;
		}

		$request->set_param('generate_sub_sizes', false);
		$request->set_param('convert_format', false);

		return $response;
	}

	/**
	 * Skip the REST image-subsize post-process route for SVG attachments.
	 */
	public function short_circuit_svg_post_process($result, $server, $request) {
		unset($server);

		if (! $request instanceof \WP_REST_Request) {
			return $result;
		}

		if ('create-image-subsizes' !== (string) $request->get_param('action')) {
			return $result;
		}

		if (! preg_match('#^/wp/v2/media/(\d+)/post-process$#', $request->get_route(), $matches)) {
			return $result;
		}

		$attachment_id = absint($matches[1]);

		if (! $this->is_svg_attachment($attachment_id)) {
			return $result;
		}

		wp_update_attachment_metadata($attachment_id, $this->svg_attachment_metadata($attachment_id));
		$request->set_param('context', 'edit');

		if (class_exists('\WP_REST_Attachments_Controller')) {
			$controller = new \WP_REST_Attachments_Controller('attachment');
			$post       = get_post($attachment_id);

			if ($post instanceof \WP_Post) {
				return $controller->prepare_item_for_response($post, $request);
			}
		}

		return new \WP_REST_Response(['id' => $attachment_id], 200);
	}

	/**
	 * Skip the admin AJAX image-subsize request for SVG uploads.
	 */
	public function short_circuit_svg_ajax_subsizes(): void {
		$attachment_id = 0;

		if (isset($_POST['attachment_id']) && ! is_array($_POST['attachment_id'])) {
			$attachment_id = absint(wp_unslash($_POST['attachment_id']));
		}

		if ($attachment_id <= 0 || ! $this->is_svg_attachment($attachment_id)) {
			return;
		}

		check_ajax_referer('media-form');

		if (! current_user_can('upload_files')) {
			wp_send_json_error(['message' => __('Sorry, you are not allowed to upload files.', 'my-files-pro')]);
		}

		if (! empty($_POST['_wp_upload_failed_cleanup'])) {
			if (current_user_can('delete_post', $attachment_id)) {
				$attachment = get_post($attachment_id);

				if ($attachment instanceof \WP_Post && time() - strtotime($attachment->post_date_gmt) < 600) {
					wp_delete_attachment($attachment_id, true);
					wp_send_json_success();
				}
			}

			wp_send_json_error(['message' => __('Upload failed. Please reload and try again.', 'my-files-pro')]);
		}

		wp_update_attachment_metadata($attachment_id, $this->svg_attachment_metadata($attachment_id));

		if (! empty($_POST['_legacy_support'])) {
			wp_send_json_success(['id' => $attachment_id]);
		}

		$response = wp_prepare_attachment_for_js($attachment_id);

		if (! $response) {
			wp_send_json_error(['message' => __('Upload failed.', 'my-files-pro')]);
		}

		wp_send_json_success($response);
	}

	/**
	 * Make SVG thumbnails fit WordPress Media Library preview containers.
	 */
	public function print_svg_thumbnail_support_css(): void {
		?>
		<style>
			.media-modal-content ul.attachments li.attachment img[src$=".svg"],
			.media-frame ul.attachments li.attachment img[src$=".svg"],
			table.media img[src$=".svg"] {
				width: 100% !important;
				height: auto !important;
			}
		</style>
		<?php
	}

	/**
	 * Sanitize a temporary SVG file in place.
	 */
	private function sanitize_file(string $file) {
		if ('' === $file || ! is_readable($file) || ! is_writable($file)) {
			return new \WP_Error('myfiles_svg_unreadable', __('SVG file could not be read for sanitization.', 'my-files-pro'));
		}

		$size = filesize($file);

		if (false === $size || $size <= 0 || $size > self::MAX_BYTES) {
			return new \WP_Error('myfiles_svg_size', __('SVG file must be smaller than 2 MB.', 'my-files-pro'));
		}

		$raw = $this->read_svg_markup_from_file($file);

		if (is_wp_error($raw)) {
			return $raw;
		}

		$sanitized = $this->sanitize_svg($raw);

		if (is_wp_error($sanitized)) {
			return $sanitized;
		}

		if ($this->is_gzipped_file($file)) {
			if (! function_exists('gzencode')) {
				return new \WP_Error('myfiles_svg_zlib_missing', __('SVGZ sanitization requires the PHP zlib extension.', 'my-files-pro'));
			}

			$encoded = gzencode($sanitized);

			if (! is_string($encoded)) {
				return new \WP_Error('myfiles_svg_write_failed', __('SVG file could not be sanitized.', 'my-files-pro'));
			}

			$sanitized = $encoded;
		}

		if (false === file_put_contents($file, $sanitized, LOCK_EX)) {
			return new \WP_Error('myfiles_svg_write_failed', __('SVG file could not be sanitized.', 'my-files-pro'));
		}

		return true;
	}

	/**
	 * Sanitize SVG XML and return safe SVG markup.
	 */
	private function sanitize_svg(string $raw) {
		if (! class_exists('\DOMDocument')) {
			return new \WP_Error('myfiles_svg_dom_missing', __('SVG sanitization requires the PHP DOM extension.', 'my-files-pro'));
		}

		if (preg_match('/<!ENTITY/i', $raw)) {
			return new \WP_Error('myfiles_svg_entity', __('SVG files with entity declarations are not allowed.', 'my-files-pro'));
		}

		$raw = $this->strip_doctype($raw);

		if (preg_match('/<!DOCTYPE/i', $raw)) {
			return new \WP_Error('myfiles_svg_doctype', __('SVG document type declaration could not be removed safely.', 'my-files-pro'));
		}

		$document = new \DOMDocument('1.0', 'UTF-8');
		$previous = libxml_use_internal_errors(true);
		$loaded   = $document->loadXML($raw, $this->libxml_options());
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if (! $loaded || ! $document->documentElement instanceof \DOMElement) {
			return new \WP_Error('myfiles_svg_invalid', __('SVG file is not valid XML.', 'my-files-pro'));
		}

		if ('svg' !== strtolower($document->documentElement->localName)) {
			return new \WP_Error('myfiles_svg_missing_root', __('SVG file must use an svg root element.', 'my-files-pro'));
		}

		$this->sanitize_node($document->documentElement);
		$document->documentElement->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
		$this->normalize_svg_root_dimensions($document->documentElement);

		$sanitized = $document->saveXML($document->documentElement);

		if (! is_string($sanitized) || '' === trim($sanitized)) {
			return new \WP_Error('myfiles_svg_empty', __('SVG file could not be sanitized.', 'my-files-pro'));
		}

		return $sanitized;
	}

	/**
	 * Remove common SVG document type declarations before XML parsing.
	 */
	private function strip_doctype(string $raw): string {
		$stripped = preg_replace('/<!DOCTYPE[^>]*(?:\[[\s\S]*?\]\s*)?>/i', '', $raw);

		return is_string($stripped) ? $stripped : $raw;
	}

	/**
	 * Sanitize an SVG node and its children recursively.
	 */
	private function sanitize_node(\DOMNode $node): void {
		if ($node instanceof \DOMElement) {
			$element_name = strtolower($node->localName);

			if (! isset(self::ALLOWED_ELEMENTS[$element_name])) {
				if (null !== $node->parentNode) {
					$node->parentNode->removeChild($node);
				}
				return;
			}

			$this->sanitize_attributes($node);
		}

		foreach (iterator_to_array($node->childNodes) as $child) {
			if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
				$node->removeChild($child);
				continue;
			}

			if ($child instanceof \DOMElement || $child instanceof \DOMDocumentFragment) {
				$this->sanitize_node($child);
			}
		}
	}

	/**
	 * Remove unsafe attributes from one SVG element.
	 */
	private function sanitize_attributes(\DOMElement $element): void {
		foreach (iterator_to_array($element->attributes) as $attribute) {
			if (! $attribute instanceof \DOMAttr) {
				continue;
			}

			$name  = strtolower($attribute->name);
			$value = trim($attribute->value);

			if ('style' === $name) {
				$this->apply_safe_style_attribute($element, $value);
				$element->removeAttributeNode($attribute);
				continue;
			}

			if (! isset(self::ALLOWED_ATTRIBUTES[$name]) || 0 === strpos($name, 'on')) {
				$element->removeAttributeNode($attribute);
				continue;
			}

			if (! $this->is_safe_attribute_value($name, $value)) {
				$element->removeAttributeNode($attribute);
			}
		}
	}

	/**
	 * Convert safe inline style declarations into normal SVG attributes.
	 */
	private function apply_safe_style_attribute(\DOMElement $element, string $style): void {
		foreach (explode(';', $style) as $declaration) {
			if (false === strpos($declaration, ':')) {
				continue;
			}

			[$property, $value] = array_map('trim', explode(':', $declaration, 2));
			$property           = strtolower($property);

			if ('' === $property || '' === $value || ! isset(self::ALLOWED_STYLE_PROPERTIES[$property])) {
				continue;
			}

			if (! isset(self::ALLOWED_ATTRIBUTES[$property]) || ! $this->is_safe_attribute_value($property, $value)) {
				continue;
			}

			$element->setAttribute($property, $value);
		}
	}

	/**
	 * Determine whether an SVG attribute value is safe.
	 */
	private function is_safe_attribute_value(string $name, string $value): bool {
		$lower = strtolower($value);

		if (
			false !== strpos($lower, 'javascript:')
			|| false !== strpos($lower, 'vbscript:')
			|| false !== strpos($lower, 'data:')
		) {
			return false;
		}

		if (false !== strpos($value, '<') || false !== strpos($value, '>')) {
			return false;
		}

		if (in_array($name, ['href', 'xlink:href'], true)) {
			return preg_match('/^#[A-Za-z][A-Za-z0-9_-]*$/', $value) === 1;
		}

		if (false !== strpos($lower, 'url(')) {
			return preg_match('/url\(\s*#[A-Za-z][A-Za-z0-9_-]*\s*\)/i', $value) === 1;
		}

		return true;
	}

	/**
	 * Determine whether the current request is uploading an SVG file.
	 */
	private function current_request_contains_svg_upload(): bool {
		foreach ($_FILES as $file) {
			if (is_array($file) && $this->is_svg_upload($file)) {
				return true;
			}
		}

		foreach (['HTTP_CONTENT_DISPOSITION', 'CONTENT_DISPOSITION', 'HTTP_X_FILE_NAME'] as $key) {
			$value = isset($_SERVER[$key]) && is_scalar($_SERVER[$key]) ? (string) $_SERVER[$key] : '';

			if ('' !== $value && $this->string_contains_svg_filename($value)) {
				return true;
			}
		}

		foreach (['name', 'filename', 'file', 'async-upload'] as $key) {
			$value = $_REQUEST[$key] ?? null;

			if (is_scalar($value) && $this->is_svg_filename((string) wp_unslash($value))) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether a file contains safe SVG after sanitization.
	 */
	private function is_safe_svg_file(string $file): bool {
		$raw = $this->read_svg_markup_from_file($file);

		if (is_wp_error($raw)) {
			return false;
		}

		return is_string($this->sanitize_svg($raw));
	}

	/**
	 * Determine whether a temp upload looks like a valid SVG before WordPress moves it.
	 */
	private function is_valid_svg_upload_file(string $file, string $filename): bool {
		if ('' === $file || ! is_readable($file)) {
			return false;
		}

		if ('' !== $filename && ! $this->is_svg_filename($filename)) {
			return false;
		}

		return $this->is_safe_svg_file($file);
	}

	/**
	 * Read plain or gzipped SVG markup from disk.
	 */
	private function read_svg_markup_from_file(string $file) {
		if ('' === $file || ! is_readable($file)) {
			return new \WP_Error('myfiles_svg_unreadable', __('SVG file could not be read for sanitization.', 'my-files-pro'));
		}

		$raw = file_get_contents($file, false, null, 0, self::MAX_BYTES + 1);

		if (! is_string($raw) || '' === trim($raw) || strlen($raw) > self::MAX_BYTES) {
			return new \WP_Error('myfiles_svg_read_failed', __('SVG file could not be read for sanitization.', 'my-files-pro'));
		}

		if (! $this->string_is_gzipped($raw)) {
			return $raw;
		}

		if (! function_exists('gzdecode')) {
			return new \WP_Error('myfiles_svg_zlib_missing', __('Reading SVGZ files requires the PHP zlib extension.', 'my-files-pro'));
		}

		$decoded = gzdecode($raw);

		if (! is_string($decoded) || '' === trim($decoded) || strlen($decoded) > self::MAX_BYTES) {
			return new \WP_Error('myfiles_svg_read_failed', __('SVG file could not be read for sanitization.', 'my-files-pro'));
		}

		return $decoded;
	}

	/**
	 * Determine whether a file is gzipped.
	 */
	private function is_gzipped_file(string $file): bool {
		$raw = file_get_contents($file, false, null, 0, 3);

		return is_string($raw) && $this->string_is_gzipped($raw);
	}

	/**
	 * Determine whether raw contents start with the gzip signature.
	 */
	private function string_is_gzipped(string $contents): bool {
		return 0 === strpos($contents, "\x1f" . "\x8b" . "\x08");
	}

	/**
	 * Determine whether a string contains an SVG filename reference.
	 */
	private function string_contains_svg_filename(string $value): bool {
		return preg_match("/(?:^|[\\s\"';=])[^\\s\"';=]+\\.svgz?(?:$|[\\s\"';&])/i", $value) === 1
			|| preg_match("/filename\\*?\\s*=\\s*(?:UTF-8'')?[\"']?[^\"';]+\\.svgz?/i", $value) === 1;
	}

	/**
	 * Determine whether a MIME value can represent an SVG upload.
	 */
	private function is_svg_like_mime(string $mime): bool {
		$mime = strtolower(trim(strtok($mime, ';') ?: $mime));

		return in_array($mime, self::SVG_MIME_TYPES, true);
	}

	/**
	 * Determine whether a MIME value is a direct SVG MIME, not a loose fallback label.
	 */
	private function is_exact_svg_mime(string $mime): bool {
		$mime = strtolower(trim(strtok($mime, ';') ?: $mime));

		return in_array($mime, [self::MIME_TYPE, 'image/svg', 'application/svg+xml'], true);
	}

	/**
	 * Read an SVG extension from a filename.
	 */
	private function svg_extension_from_filename(string $filename): string {
		$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

		return in_array($extension, self::SVG_EXTENSIONS, true) ? $extension : 'svg';
	}

	/**
	 * Build attachment metadata without invoking raster image processing.
	 *
	 * @return array<string, mixed>
	 */
	private function svg_attachment_metadata(int $attachment_id): array {
		$file       = get_attached_file($attachment_id);
		$dimensions = $this->svg_attachment_dimensions($attachment_id);
		$filesize   = is_string($file) && is_readable($file)
			? (function_exists('wp_filesize') ? wp_filesize($file) : filesize($file))
			: 0;

		return [
			'width'                => $dimensions['width'],
			'height'               => $dimensions['height'],
			'file'                 => is_string($file) && function_exists('_wp_relative_upload_path') ? _wp_relative_upload_path($file) : '',
			'filesize'             => is_int($filesize) ? $filesize : 0,
			'sizes'                => [],
			'myfiles_svg'    => true,
		];
	}

	/**
	 * Read SVG dimensions from width/height or viewBox.
	 *
	 * @return array{width:int,height:int}
	 */
	private function svg_attachment_dimensions(int $attachment_id): array {
		$file = get_attached_file($attachment_id);

		if (! is_string($file) || '' === $file || ! is_readable($file)) {
			return ['width' => 1, 'height' => 1];
		}

		return $this->svg_dimensions_from_file($file) ?? ['width' => 1, 'height' => 1];
	}

	/**
	 * Parse dimensions from a sanitized SVG file.
	 *
	 * @return array{width:int,height:int}|null
	 */
	private function svg_dimensions_from_file(string $file): ?array {
		$raw = $this->read_svg_markup_from_file($file);

		if (is_wp_error($raw) || ! class_exists('\DOMDocument')) {
			return null;
		}

		$raw = $this->strip_doctype($raw);

		$document = new \DOMDocument('1.0', 'UTF-8');
		$previous = libxml_use_internal_errors(true);
		$loaded   = $document->loadXML($raw, $this->libxml_options());
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if (! $loaded || ! $document->documentElement instanceof \DOMElement) {
			return null;
		}

		$viewbox_dimensions = $this->parse_svg_viewbox($document->documentElement);
		$width_attribute    = $document->documentElement->getAttribute('width');
		$height_attribute   = $document->documentElement->getAttribute('height');

		if (! $this->is_percentage_svg_length($width_attribute) && ! $this->is_percentage_svg_length($height_attribute)) {
			$width  = $this->parse_svg_length($width_attribute);
			$height = $this->parse_svg_length($height_attribute);

			if ($width > 0 && $height > 0) {
				return ['width' => $width, 'height' => $height];
			}
		}

		return $viewbox_dimensions;
	}

	/**
	 * Determine whether a REST media request is uploading an SVG file.
	 */
	private function is_rest_svg_media_upload(\WP_REST_Request $request): bool {
		if ('POST' !== strtoupper($request->get_method())) {
			return false;
		}

		if (! preg_match('#^/wp/v2/media/?$#', $request->get_route())) {
			return false;
		}

		foreach ($request->get_file_params() as $file) {
			if (is_array($file) && $this->is_svg_upload($file)) {
				return true;
			}
		}

		$content_type = (string) $request->get_header('content_type');

		if ('' !== $content_type && $this->is_exact_svg_mime($content_type)) {
			return true;
		}

		$content_disposition = (string) $request->get_header('content_disposition');

		return '' !== $content_disposition
			&& $this->string_contains_svg_filename($content_disposition)
			&& ('' === $content_type || $this->is_svg_like_mime($content_type));
	}

	/**
	 * Determine whether an attachment is a managed SVG image.
	 */
	private function is_svg_attachment(int $attachment_id): bool {
		if (! $this->settings->svg_uploads_managed()) {
			return false;
		}

		if ($attachment_id <= 0) {
			return false;
		}

		if (self::MIME_TYPE === get_post_mime_type($attachment_id)) {
			return true;
		}

		$file = get_attached_file($attachment_id);

		return is_string($file) && $this->is_svg_filename($file);
	}

	/**
	 * Replace percentage root dimensions with viewBox dimensions when possible.
	 */
	private function normalize_svg_root_dimensions(\DOMElement $element): void {
		$viewbox_dimensions = $this->parse_svg_viewbox($element);

		if (! $viewbox_dimensions) {
			return;
		}

		$width  = $this->parse_svg_length($element->getAttribute('width'));
		$height = $this->parse_svg_length($element->getAttribute('height'));

		if ($width <= 0 || $this->is_percentage_svg_length($element->getAttribute('width'))) {
			$element->setAttribute('width', (string) $viewbox_dimensions['width']);
		}

		if ($height <= 0 || $this->is_percentage_svg_length($element->getAttribute('height'))) {
			$element->setAttribute('height', (string) $viewbox_dimensions['height']);
		}
	}

	/**
	 * Parse SVG dimensions from viewBox.
	 *
	 * @return array{width:int,height:int}|null
	 */
	private function parse_svg_viewbox(\DOMElement $element): ?array {
		$viewbox_attribute = $element->getAttribute('viewBox');

		if ('' === $viewbox_attribute) {
			$viewbox_attribute = $element->getAttribute('viewbox');
		}

		$viewbox = preg_split('/[\s,]+/', trim($viewbox_attribute));

		if (is_array($viewbox) && count($viewbox) >= 4) {
			$width  = (int) round((float) $viewbox[2]);
			$height = (int) round((float) $viewbox[3]);

			if ($width > 0 && $height > 0) {
				return ['width' => $width, 'height' => $height];
			}
		}

		return null;
	}

	/**
	 * Determine whether an SVG length is percentage based.
	 */
	private function is_percentage_svg_length(string $value): bool {
		return '%' === substr(trim($value), -1);
	}

	/**
	 * Parse a simple SVG length value into pixels.
	 */
	private function parse_svg_length(string $value): int {
		$value = trim($value);

		if ('' === $value || preg_match('/^([0-9]+(?:\.[0-9]+)?)/', $value, $matches) !== 1) {
			return 0;
		}

		return (int) max(0, round((float) $matches[1]));
	}

	/**
	 * Determine whether the current user can upload managed SVG files.
	 */
	private function current_user_can_upload_svg(): bool {
		return $this->settings->svg_uploads_managed()
			&& current_user_can('manage_options')
			&& current_user_can('upload_files');
	}

	/**
	 * Determine whether an upload array describes an SVG.
	 *
	 * @param array<string, mixed> $file Upload file array.
	 */
	private function is_svg_upload(array $file): bool {
		$names = $this->flatten_upload_values($file['name'] ?? '');
		$types = array_map('strtolower', $this->flatten_upload_values($file['type'] ?? ''));

		foreach ($names as $name) {
			if ($this->is_svg_filename($name)) {
				return true;
			}
		}

		foreach ($types as $type) {
			if ($this->is_svg_like_mime($type)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Flatten scalar upload field values from standard and nested upload arrays.
	 *
	 * @return array<int, string>
	 */
	private function flatten_upload_values($value): array {
		if (is_scalar($value)) {
			return [(string) $value];
		}

		if (! is_array($value)) {
			return [];
		}

		$values = [];

		foreach ($value as $item) {
			array_push($values, ...$this->flatten_upload_values($item));
		}

		return $values;
	}

	/**
	 * Determine whether a filename has an SVG extension.
	 */
	private function is_svg_filename(string $filename): bool {
		return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::SVG_EXTENSIONS, true);
	}

	/**
	 * Safe libxml parser flags.
	 */
	private function libxml_options(): int {
		$options = LIBXML_NONET;

		if (defined('LIBXML_COMPACT')) {
			$options |= LIBXML_COMPACT;
		}

		return $options;
	}
}
