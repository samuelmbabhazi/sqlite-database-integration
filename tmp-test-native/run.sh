#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

if ! grep -q 'WP_MySQL_Native_Ast' "$ROOT_DIR/packages/mysql-on-sqlite/ext/wp-mysql-parser/src/lib.rs"; then
  echo "This checkout does not include the lazy native AST facade." >&2
  echo "Switch to codex/native-lazy-ast-facade first." >&2
  exit 1
fi

EXT_SO="$("$SCRIPT_DIR/build-extension.sh" | tail -n 1)"

echo "=== Current PHP configuration ==="
php "$SCRIPT_DIR/run-sqlite-facade-smoke.php"

echo
echo "=== Explicit native extension ==="
php -d extension="$EXT_SO" "$SCRIPT_DIR/run-sqlite-facade-smoke.php"
