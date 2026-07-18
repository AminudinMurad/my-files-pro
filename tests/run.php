<?php
/**
 * Dependency-free MY Files PRO behavior tests.
 *
 * Loads the plugin bootstrap against lightweight WordPress stubs and asserts
 * the plugin's structural invariants: version coherence, GPLv3 licensing, header
 * identity, hook registration, and autoloading of every first-party class.
 *
 * @package MyFilesPro
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/**
 * Fail with an actionable message.
 */
function myfiles_test_fail(string $message): void {
	fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
	exit(1);
}

/**
 * Assert a condition.
 */
function myfiles_test_assert(bool $condition, string $message): void {
	if (! $condition) {
		myfiles_test_fail($message);
	}
}

/**
 * Assert that a substring is present.
 */
function myfiles_test_contains(string $needle, string $haystack, string $message): void {
	myfiles_test_assert(false !== strpos($haystack, $needle), $message);
}

$root = dirname(__DIR__);

// Load the plugin bootstrap against the stubs.
require $root . '/my-files-pro.php';

// --- Version coherence -------------------------------------------------------
myfiles_test_assert(defined('MY_FILES_PRO_VERSION'), 'MY_FILES_PRO_VERSION is not defined.');

$header = (string) file_get_contents($root . '/my-files-pro.php');
$readme = (string) file_get_contents($root . '/readme.txt');

myfiles_test_assert(
	1 === preg_match('/^ \* Version:\s+([0-9.]+)$/m', $header, $header_version),
	'Plugin header Version is missing.'
);
myfiles_test_assert(
	1 === preg_match('/^Stable tag:\s+([0-9.]+)$/m', $readme, $stable_tag),
	'readme.txt Stable tag is missing.'
);
myfiles_test_assert(
	$header_version[1] === MY_FILES_PRO_VERSION,
	'Plugin header Version and MY_FILES_PRO_VERSION differ.'
);
myfiles_test_assert(
	$header_version[1] === $stable_tag[1],
	'Plugin header Version and readme Stable tag differ.'
);

// --- Header identity ---------------------------------------------------------
myfiles_test_contains('License: GPLv3 or later', $header, 'Plugin header must declare the GPLv3 license.');
myfiles_test_contains('Text Domain: my-files-pro', $header, 'Plugin header text domain is incorrect.');
myfiles_test_contains('@package MyFilesPro', $header, 'Plugin header package tag is incorrect.');

// --- Licensing ---------------------------------------------------------------
$license = (string) file_get_contents($root . '/LICENSE');
myfiles_test_assert(false !== strpos($license, 'GNU GENERAL PUBLIC LICENSE'), 'LICENSE is not the GNU GPL.');
myfiles_test_contains('Version 3, 29 June 2007', $license, 'LICENSE is not GPL version 3.');

// --- Hook registration -------------------------------------------------------
myfiles_test_assert(
	! empty($GLOBALS['myfiles_test_state']['activation_hooks']),
	'The activation hook was not registered.'
);
myfiles_test_assert(
	isset($GLOBALS['myfiles_test_state']['actions']['plugins_loaded']),
	'The plugins_loaded boot action was not registered.'
);

// --- Autoloading -------------------------------------------------------------
$classes = [
	'Autoloader',
	'Plugin',
	'Activator',
	'Folders',
	'Media',
	'RestApi',
	'Settings',
	'Assets',
	'Admin',
	'ImportExport',
	'SvgUploads',
	'Uninstall',
];

foreach ($classes as $class) {
	myfiles_test_assert(
		class_exists('\\MyFilesPro\\' . $class),
		"Class MyFilesPro\\{$class} did not autoload from src/."
	);
}

fwrite(STDOUT, 'MY Files PRO v' . MY_FILES_PRO_VERSION . ' behavior tests passed.' . PHP_EOL);
