#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")"

if ! command -v php >/dev/null 2>&1; then
    echo "PHP CLI is not installed." >&2
    exit 1
fi

php_version="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
if [ "$php_version" != "8.3" ]; then
    echo "PHP 8.3 is required; found PHP ${php_version}." >&2
    exit 1
fi

exec php -S 0.0.0.0:8000 -t public
