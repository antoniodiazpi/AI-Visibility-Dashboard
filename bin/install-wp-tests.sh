#!/usr/bin/env bash
#
# Prepares a WordPress install for the PHPUnit suite, backed by SQLite so no
# database server is needed.
#
#   bin/install-wp-tests.sh [target-dir] [wordpress-version]
#
# Defaults: target ${TMPDIR:-/tmp}/aivd-wp, WordPress 7.1.4.
# Then run:  WP_CORE_DIR=<target-dir>/wordpress vendor/bin/phpunit
#
# To use MySQL instead (for example in CI), skip this script, point WP_CORE_DIR
# at any WordPress checkout without wp-content/db.php and set WP_DB_NAME,
# WP_DB_USER, WP_DB_PASSWORD and WP_DB_HOST.

set -euo pipefail

TARGET="${1:-${TMPDIR:-/tmp}/aivd-wp}"
WP_VERSION="${2:-7.1.4}"
# Pinned for reproducible runs; bump deliberately.
SQLITE_REF="${SQLITE_REF:-v3.1.3}"

WP_DIR="$TARGET/wordpress"
SQLITE_SRC="$TARGET/sqlite-database-integration"

mkdir -p "$TARGET"

if [ ! -f "$WP_DIR/wp-includes/version.php" ]; then
	echo "Downloading WordPress $WP_VERSION…"
	git clone --quiet --depth 1 --branch "$WP_VERSION" https://github.com/WordPress/WordPress.git "$WP_DIR"
fi

if [ ! -d "$SQLITE_SRC" ]; then
	echo "Downloading the SQLite Database Integration…"
	git clone --quiet --depth 1 --branch "$SQLITE_REF" https://github.com/WordPress/sqlite-database-integration.git "$SQLITE_SRC"
fi

PLUGIN_DST="$WP_DIR/wp-content/plugins/sqlite-database-integration"
if [ ! -f "$PLUGIN_DST/load.php" ]; then
	# -L copies the files behind the package's symlink to the database layer.
	cp -RL "$SQLITE_SRC/packages/plugin-sqlite-database-integration" "$PLUGIN_DST"
fi

sed \
	-e "s#'{SQLITE_IMPLEMENTATION_FOLDER_PATH}'#__DIR__ . '/plugins/sqlite-database-integration'#g" \
	-e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" \
	"$PLUGIN_DST/db.copy" > "$WP_DIR/wp-content/db.php"

echo "Ready. Run the tests with:"
echo "  WP_CORE_DIR=$WP_DIR vendor/bin/phpunit"
