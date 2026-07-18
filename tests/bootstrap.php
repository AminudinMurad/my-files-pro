<?php
/**
 * Dependency-free WordPress stubs for MY Files PRO tests.
 *
 * Provides just enough of the WordPress API for the plugin bootstrap to load
 * and register its hooks without a live WordPress environment.
 *
 * @package MyFilesPro
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/');

$GLOBALS['myfiles_test_state'] = [
	'actions'          => [],
	'filters'          => [],
	'activation_hooks' => [],
];

/**
 * Reset the mutable test state.
 */
function myfiles_test_reset_state(): void {
	$GLOBALS['myfiles_test_state'] = [
		'actions'          => [],
		'filters'          => [],
		'activation_hooks' => [],
	];
}

function plugin_dir_path(string $file): string {
	return dirname($file) . '/';
}

function plugin_dir_url(string $file = ''): string {
	unset($file);
	return 'https://example.test/wp-content/plugins/my-files-pro/';
}

function plugin_basename(string $file): string {
	return 'my-files-pro/' . basename($file);
}

function register_activation_hook($file, callable $callback): void {
	unset($callback);
	$GLOBALS['myfiles_test_state']['activation_hooks'][] = $file;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {
	$GLOBALS['myfiles_test_state']['actions'][$hook][] = [
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	];
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {
	$GLOBALS['myfiles_test_state']['filters'][$hook][] = [
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	];
}
