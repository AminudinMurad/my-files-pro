#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if ! command -v php >/dev/null 2>&1; then
  echo "PHP is required to run the source checks." >&2
  exit 1
fi

# PHP 7.4 compatibility guard (plugin class source).
php "$project_root/tools/check-php74-compat.php" "$project_root/src"

# PHP syntax check across the repository (excluding VCS and local-only dirs).
php_files=0
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
  php_files=$((php_files + 1))
done < <(
  find "$project_root" -type f -name '*.php' \
    -not -path '*/.git/*' \
    -not -path '*/build/*' \
    -not -path '*/worktrees/*' \
    -print0
)

if [[ "$php_files" -eq 0 ]]; then
  echo "No PHP files were found." >&2
  exit 1
fi

# JavaScript syntax check.
if command -v node >/dev/null 2>&1; then
  while IFS= read -r -d '' file; do
    node --check "$file"
  done < <(find "$project_root/assets" -type f -name '*.js' -print0)
else
  echo "Node.js is unavailable; JavaScript syntax check was not run." >&2
fi

# Version metadata coherence (header version == readme stable tag; PHP 7.4 pinned).
php -r '
$bootstrap = file_get_contents($argv[1]);
$readme = file_get_contents($argv[2]);
if (!preg_match("/^ \\* Version:[[:space:]]+([0-9.]+)$/m", $bootstrap, $header)) {
    fwrite(STDERR, "Plugin header version is missing.\n");
    exit(1);
}
if (!preg_match("/^Stable tag:[[:space:]]+([0-9.]+)$/m", $readme, $stable)) {
    fwrite(STDERR, "Stable tag is missing.\n");
    exit(1);
}
if (!preg_match("/^Requires PHP:[[:space:]]+([0-9.]+)$/m", $readme, $requires)) {
    fwrite(STDERR, "Requires PHP is missing.\n");
    exit(1);
}
if ($header[1] !== $stable[1]) {
    fwrite(STDERR, "Plugin header version and stable tag differ.\n");
    exit(1);
}
if ($requires[1] !== "7.4") {
    fwrite(STDERR, "Requires PHP must remain 7.4 for this release.\n");
    exit(1);
}
echo "Version metadata passed for {$header[1]} with PHP {$requires[1]}+.\n";
' "$project_root/my-files-pro.php" "$project_root/readme.txt"

# Dependency-free behavior tests.
php "$project_root/tests/run.php"

echo "Source checks passed for $php_files PHP files."
