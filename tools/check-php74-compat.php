#!/usr/bin/env php
<?php
/**
 * Dependency-free PHP 7.4 compatibility guard for MY Files PRO.
 *
 * Licensed under the GNU General Public License v3.0 or later. See LICENSE in the repository root.
 *
 * Run from the repository root:
 *
 *     php tools/check-php74-compat.php
 *     php tools/check-php74-compat.php /path/to/php/source
 *
 * Supply an actual PHP 7.4 binary for native linting when one is available:
 *
 *     PHP74_BIN=/path/to/php7.4 php tools/check-php74-compat.php
 */

declare(strict_types=1);

const MYFILES_PHP74_MIN_VERSION_ID = 70400;
const MYFILES_PHP80_VERSION_ID     = 80000;

/**
 * Add one unique compatibility finding.
 *
 * @param array<string, array{path:string,line:int,message:string}> $findings Findings.
 */
function myfiles_add_finding(array &$findings, string $path, int $line, string $message): void {
	$key = $path . ':' . $line . ':' . $message;

	$findings[$key] = [
		'path'    => $path,
		'line'    => $line,
		'message' => $message,
	];
}

/**
 * Return a repository-relative path.
 */
function myfiles_relative_path(string $root, string $path): string {
	$prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

	return 0 === strpos($path, $prefix) ? substr($path, strlen($prefix)) : $path;
}

/**
 * Convert tokenizer output into significant token records.
 *
 * @param array<int, array{0:int,1:string,2:int}|string> $tokens Raw tokens.
 * @return array<int, array{id:int,text:string,line:int}>
 */
function myfiles_significant_tokens(array $tokens): array {
	$records = [];
	$line    = 1;

	foreach ($tokens as $token) {
		if (is_array($token)) {
			$id   = $token[0];
			$text = $token[1];
			$line = $token[2];

			if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				$line += substr_count($text, "\n");
				continue;
			}
		} else {
			$id   = 0;
			$text = $token;
		}

		$records[] = [
			'id'   => $id,
			'text' => $text,
			'line' => $line,
		];

		$line += substr_count($text, "\n");
	}

	return $records;
}

/**
 * Find the matching closing punctuation token.
 *
 * @param array<int, array{id:int,text:string,line:int}> $records Token records.
 */
function myfiles_matching_index(array $records, int $open_index, string $open, string $close): ?int {
	$depth = 0;
	$count = count($records);

	for ($index = $open_index; $index < $count; ++$index) {
		if ($open === $records[$index]['text']) {
			++$depth;
		} elseif ($close === $records[$index]['text']) {
			--$depth;

			if (0 === $depth) {
				return $index;
			}
		}
	}

	return null;
}

/**
 * Determine whether a token is the PHP 8 intersection-type ampersand.
 *
 * @param array{id:int,text:string,line:int} $record Token record.
 */
function myfiles_is_intersection_ampersand(array $record): bool {
	if ('&' !== $record['text']) {
		return false;
	}

	return defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG')
		&& constant('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG') === $record['id'];
}

/**
 * Check PHP type tokens that were introduced after PHP 7.4.
 *
 * @param array<int, array{id:int,text:string,line:int}>                 $records Token records.
 * @param array<string, array{path:string,line:int,message:string}> $findings Findings.
 */
function myfiles_check_type_range(array $records, int $start, int $end, string $path, string $context, array &$findings): void {
	for ($index = $start; $index <= $end; ++$index) {
		$record = $records[$index];
		$text   = strtolower($record['text']);

		if ('|' === $record['text']) {
			myfiles_add_finding($findings, $path, $record['line'], 'PHP 8 union type in ' . $context);
		}

		if (myfiles_is_intersection_ampersand($record)) {
			myfiles_add_finding($findings, $path, $record['line'], 'PHP 8.1 intersection type in ' . $context);
		}

		if (in_array($text, ['mixed', 'never', 'false', 'true', 'null'], true)) {
			myfiles_add_finding($findings, $path, $record['line'], 'Post-PHP-7.4 type "' . $text . '" in ' . $context);
		}

		if (T_STATIC === $record['id'] && 'return type' === $context) {
			myfiles_add_finding($findings, $path, $record['line'], 'PHP 8 static return type');
		}
	}
}

/**
 * Check the type prefix for each parameter without scanning its default value.
 *
 * @param array<int, array{id:int,text:string,line:int}>                 $records Token records.
 * @param array<string, array{path:string,line:int,message:string}> $findings Findings.
 */
function myfiles_check_parameters(array $records, int $open_index, int $close_index, string $function_name, string $path, array &$findings): void {
	$segment_start = $open_index + 1;
	$paren_depth   = 0;
	$bracket_depth = 0;
	$brace_depth   = 0;

	for ($index = $open_index + 1; $index <= $close_index; ++$index) {
		$text = $index < $close_index ? $records[$index]['text'] : ')';

		if ('(' === $text) {
			++$paren_depth;
		} elseif (')' === $text && $index < $close_index) {
			--$paren_depth;
		} elseif ('[' === $text) {
			++$bracket_depth;
		} elseif (']' === $text) {
			--$bracket_depth;
		} elseif ('{' === $text) {
			++$brace_depth;
		} elseif ('}' === $text) {
			--$brace_depth;
		}

		$is_separator = $index === $close_index || (
			',' === $text
			&& 0 === $paren_depth
			&& 0 === $bracket_depth
			&& 0 === $brace_depth
		);

		if (! $is_separator) {
			continue;
		}

		$segment_end   = $index - 1;
		$variable_index = null;

		for ($cursor = $segment_start; $cursor <= $segment_end; ++$cursor) {
			if (T_VARIABLE === $records[$cursor]['id']) {
				$variable_index = $cursor;
				break;
			}
		}

		if (null !== $variable_index && $variable_index > $segment_start) {
			myfiles_check_type_range($records, $segment_start, $variable_index - 1, $path, 'parameter declaration', $findings);

			if ('__construct' === $function_name) {
				for ($cursor = $segment_start; $cursor < $variable_index; ++$cursor) {
					if (in_array($records[$cursor]['id'], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
						myfiles_add_finding($findings, $path, $records[$cursor]['line'], 'PHP 8 constructor property promotion');
					}
				}
			}
		}

		$segment_start = $index + 1;
	}
}

/**
 * Check one function, method, or closure declaration.
 *
 * @param array<int, array{id:int,text:string,line:int}>                 $records Token records.
 * @param array<string, array{path:string,line:int,message:string}> $findings Findings.
 */
function myfiles_check_function_declaration(array $records, int $function_index, string $path, array &$findings): void {
	$count         = count($records);
	$open_index    = null;
	$function_name = '';

	for ($index = $function_index + 1; $index < $count; ++$index) {
		if (T_STRING === $records[$index]['id']) {
			$function_name = strtolower($records[$index]['text']);
		}

		if ('(' === $records[$index]['text']) {
			$open_index = $index;
			break;
		}

		if (';' === $records[$index]['text'] || '{' === $records[$index]['text']) {
			return;
		}
	}

	if (null === $open_index) {
		return;
	}

	$close_index = myfiles_matching_index($records, $open_index, '(', ')');

	if (null === $close_index) {
		return;
	}

	if ($close_index > $open_index + 1) {
		myfiles_check_parameters($records, $open_index, $close_index, $function_name, $path, $findings);

		if (',' === $records[$close_index - 1]['text']) {
			myfiles_add_finding($findings, $path, $records[$close_index - 1]['line'], 'PHP 8 trailing comma in parameter declaration');
		}
	}

	$depth        = 0;
	$return_start = null;
	$return_end   = null;

	for ($index = $close_index + 1; $index < $count; ++$index) {
		$text = $records[$index]['text'];

		if ('(' === $text) {
			++$depth;
			continue;
		}

		if (')' === $text && $depth > 0) {
			--$depth;
			continue;
		}

		if (0 !== $depth) {
			continue;
		}

		if (':' === $text && null === $return_start) {
			$return_start = $index + 1;
			continue;
		}

		if ('{' === $text || ';' === $text || T_DOUBLE_ARROW === $records[$index]['id']) {
			$return_end = $index - 1;
			break;
		}
	}

	if (null !== $return_start && null !== $return_end && $return_end >= $return_start) {
		myfiles_check_type_range($records, $return_start, $return_end, $path, 'return type', $findings);
	}
}

/**
 * Check a property declaration beginning with a visibility token.
 *
 * @param array<int, array{id:int,text:string,line:int}>                 $records Token records.
 * @param array<string, array{path:string,line:int,message:string}> $findings Findings.
 */
function myfiles_check_property_declaration(array $records, int $visibility_index, string $path, array &$findings): void {
	$count          = count($records);
	$variable_index = null;

	for ($index = $visibility_index + 1; $index < $count; ++$index) {
		if (T_FUNCTION === $records[$index]['id'] || in_array($records[$index]['text'], ['(', ')', '{', ';'], true)) {
			return;
		}

		if (T_VARIABLE === $records[$index]['id']) {
			$variable_index = $index;
			break;
		}
	}

	if (null !== $variable_index && $variable_index > $visibility_index + 1) {
		myfiles_check_type_range($records, $visibility_index + 1, $variable_index - 1, $path, 'property declaration', $findings);
	}
}

/**
 * Check whether a catch declaration omits its exception variable.
 *
 * @param array<int, array{id:int,text:string,line:int}>                 $records Token records.
 * @param array<string, array{path:string,line:int,message:string}> $findings Findings.
 */
function myfiles_check_catch_declaration(array $records, int $catch_index, string $path, array &$findings): void {
	$count      = count($records);
	$open_index = null;

	for ($index = $catch_index + 1; $index < $count; ++$index) {
		if ('(' === $records[$index]['text']) {
			$open_index = $index;
			break;
		}
	}

	if (null === $open_index) {
		return;
	}

	$close_index = myfiles_matching_index($records, $open_index, '(', ')');

	if (null === $close_index) {
		return;
	}

	for ($index = $open_index + 1; $index < $close_index; ++$index) {
		if (T_VARIABLE === $records[$index]['id']) {
			return;
		}
	}

	myfiles_add_finding($findings, $path, $records[$catch_index]['line'], 'PHP 8 catch declaration without an exception variable');
}

/**
 * Run native PHP 7.4 lint when a binary is supplied.
 *
 * @param array<int, string>                                             $files PHP files.
 * @param array<string, array{path:string,line:int,message:string}> $findings Findings.
 */
function myfiles_run_php74_lint(string $binary, array $files, string $root, array &$findings): bool {
	if (! is_file($binary) || ! is_executable($binary)) {
		myfiles_add_finding($findings, 'PHP74_BIN', 1, 'Configured PHP 7.4 binary is not executable: ' . $binary);
		return false;
	}

	$version_output = [];
	$version_status = 0;
	$version_code   = 'echo PHP_VERSION_ID;';
	$version_cmd    = escapeshellarg($binary) . ' -r ' . escapeshellarg($version_code);
	exec($version_cmd, $version_output, $version_status);
	$version_id = isset($version_output[0]) ? (int) trim($version_output[0]) : 0;

	if (0 !== $version_status || $version_id < MYFILES_PHP74_MIN_VERSION_ID || $version_id >= MYFILES_PHP80_VERSION_ID) {
		myfiles_add_finding($findings, 'PHP74_BIN', 1, 'PHP74_BIN must point to PHP 7.4; detected version ID ' . $version_id);
		return false;
	}

	foreach ($files as $file) {
		$output = [];
		$status = 0;
		$cmd    = escapeshellarg($binary) . ' -l ' . escapeshellarg($file) . ' 2>&1';
		exec($cmd, $output, $status);

		if (0 !== $status) {
			$message = empty($output) ? 'Native PHP 7.4 lint failed' : implode(' ', $output);
			myfiles_add_finding($findings, myfiles_relative_path($root, $file), 1, $message);
		}
	}

	return true;
}

$root             = dirname(__DIR__);
$requested_source = isset($argv[1]) ? $argv[1] : $root . '/my-files-pro';
$source_dir       = realpath($requested_source);
$files            = [];
$findings         = [];

if (false === $source_dir || ! is_dir($source_dir)) {
	fwrite(STDERR, 'PHP source directory does not exist: ' . $requested_source . PHP_EOL);
	exit(2);
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($source_dir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
	if ($file->isFile() && 'php' === strtolower($file->getExtension())) {
		$files[] = $file->getPathname();
	}
}

sort($files, SORT_STRING);

$forbidden_token_rules = [];
$token_rule_names       = [
	'T_ATTRIBUTE'                => 'PHP 8 attribute syntax',
	'T_MATCH'                    => 'PHP 8 match expression',
	'T_NULLSAFE_OBJECT_OPERATOR' => 'PHP 8 nullsafe operator',
	'T_ENUM'                     => 'PHP 8.1 enum declaration',
	'T_READONLY'                 => 'PHP 8.1 readonly declaration',
	'T_PUBLIC_SET'               => 'PHP 8.4 asymmetric property visibility',
	'T_PROTECTED_SET'            => 'PHP 8.4 asymmetric property visibility',
	'T_PRIVATE_SET'              => 'PHP 8.4 asymmetric property visibility',
];

foreach ($token_rule_names as $token_name => $message) {
	if (defined($token_name)) {
		$forbidden_token_rules[constant($token_name)] = $message;
	}
}

$forbidden_functions = [
	'array_all',
	'array_any',
	'array_find',
	'array_find_key',
	'array_is_list',
	'enum_exists',
	'fdiv',
	'fdatasync',
	'fsync',
	'get_debug_type',
	'get_mangled_object_vars',
	'get_resource_id',
	'grapheme_str_split',
	'http_clear_last_response_headers',
	'http_get_last_response_headers',
	'ini_parse_quantity',
	'json_validate',
	'mb_lcfirst',
	'mb_ltrim',
	'mb_rtrim',
	'mb_str_pad',
	'mb_trim',
	'mb_ucfirst',
	'memory_reset_peak_usage',
	'preg_last_error_msg',
	'request_parse_body',
	'str_contains',
	'str_decrement',
	'str_ends_with',
	'str_increment',
	'str_starts_with',
];

$constant_name_token_ids = [T_STRING];

foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED'] as $token_name) {
	if (defined($token_name)) {
		$constant_name_token_ids[] = constant($token_name);
	}
}

foreach ($files as $file) {
	$relative = myfiles_relative_path($root, $file);
	$source   = file_get_contents($file);

	if (! is_string($source)) {
		myfiles_add_finding($findings, $relative, 1, 'File could not be read');
		continue;
	}

	try {
		$records = myfiles_significant_tokens(token_get_all($source, TOKEN_PARSE));
	} catch (ParseError $error) {
		myfiles_add_finding($findings, $relative, $error->getLine(), 'Current PHP parser error: ' . $error->getMessage());
		continue;
	}

	$count = count($records);

	for ($index = 0; $index < $count; ++$index) {
		$record = $records[$index];
		$id     = $record['id'];
		$text   = $record['text'];

		if (isset($forbidden_token_rules[$id])) {
			myfiles_add_finding($findings, $relative, $record['line'], $forbidden_token_rules[$id]);
		}

		if (T_FUNCTION === $id) {
			myfiles_check_function_declaration($records, $index, $relative, $findings);
		}

		if (in_array($id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR], true)) {
			myfiles_check_property_declaration($records, $index, $relative, $findings);
		}

		if (T_CATCH === $id) {
			myfiles_check_catch_declaration($records, $index, $relative, $findings);
		}

		if (T_FINAL === $id && isset($records[$index + 1]) && T_CONST === $records[$index + 1]['id']) {
			myfiles_add_finding($findings, $relative, $record['line'], 'PHP 8.1 final class constant');
		}

		if (T_CONST === $id) {
			$name_tokens = 0;

			for ($cursor = $index + 1; $cursor < $count && '=' !== $records[$cursor]['text'] && ';' !== $records[$cursor]['text']; ++$cursor) {
				if (in_array($records[$cursor]['id'], $constant_name_token_ids, true)) {
					++$name_tokens;
				}
			}

			if ($name_tokens > 1) {
				myfiles_add_finding($findings, $relative, $record['line'], 'PHP 8.3 typed class constant');
			}
		}

		if (T_ELLIPSIS === $id && isset($records[$index + 1]) && ')' === $records[$index + 1]['text']) {
			myfiles_add_finding($findings, $relative, $record['line'], 'PHP 8.1 first-class callable syntax');
		}

		if (T_LNUMBER === $id && preg_match('/^0[oO][0-7_]+$/', $text)) {
			myfiles_add_finding($findings, $relative, $record['line'], 'PHP 8.1 explicit octal integer syntax');
		}

		if (T_THROW === $id && isset($records[$index - 1])) {
			$previous = $records[$index - 1];

			if (in_array($previous['text'], ['(', '[', ',', '=', '?'], true) || in_array($previous['id'], [T_DOUBLE_ARROW, T_COALESCE], true)) {
				myfiles_add_finding($findings, $relative, $record['line'], 'PHP 8 throw expression');
			}
		}

		if (T_STRING === $id && isset($records[$index + 1]) && '(' === $records[$index + 1]['text']) {
			$name        = strtolower($text);
			$previous_id = isset($records[$index - 1]) ? $records[$index - 1]['id'] : 0;
			$previous    = isset($records[$index - 1]) ? $records[$index - 1]['text'] : '';

			if (
				in_array($name, $forbidden_functions, true)
				&& ! in_array($previous_id, [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)
				&& ! in_array($previous, ['->', '?->', '::'], true)
			) {
				myfiles_add_finding($findings, $relative, $record['line'], 'Function ' . $name . '() requires PHP 8 or later');
			}
		}

		if (
			T_STRING === $id
			&& isset($records[$index - 1], $records[$index + 1])
			&& in_array($records[$index - 1]['text'], ['(', ','], true)
			&& ':' === $records[$index + 1]['text']
		) {
			myfiles_add_finding($findings, $relative, $record['line'], 'PHP 8 named argument syntax');
		}
	}

}

$native_php74 = PHP_VERSION_ID >= MYFILES_PHP74_MIN_VERSION_ID && PHP_VERSION_ID < MYFILES_PHP80_VERSION_ID;
$php74_binary = getenv('PHP74_BIN');
$external_run = false;

if (is_string($php74_binary) && '' !== trim($php74_binary)) {
	$external_run = myfiles_run_php74_lint(trim($php74_binary), $files, $root, $findings);
}

if (! empty($findings)) {
	ksort($findings, SORT_STRING);

	foreach ($findings as $finding) {
		fwrite(STDERR, $finding['path'] . ':' . $finding['line'] . ': ' . $finding['message'] . PHP_EOL);
	}

	fwrite(STDERR, 'PHP 7.4 compatibility scan failed with ' . count($findings) . ' finding(s).' . PHP_EOL);
	exit(1);
}

fwrite(STDOUT, 'PHP 7.4 compatibility scan passed for ' . count($files) . ' plugin PHP files.' . PHP_EOL);

if ($native_php74) {
	fwrite(STDOUT, 'Native parser: PHP ' . PHP_VERSION . '.' . PHP_EOL);
} elseif ($external_run) {
	fwrite(STDOUT, 'Native PHP 7.4 lint: passed via PHP74_BIN.' . PHP_EOL);
} else {
	fwrite(STDOUT, 'Native PHP 7.4 runtime not supplied; set PHP74_BIN=/path/to/php7.4 for native lint.' . PHP_EOL);
}
