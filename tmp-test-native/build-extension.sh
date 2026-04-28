#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
EXT_DIR="$ROOT_DIR/packages/mysql-on-sqlite/ext/wp-mysql-parser"

if [ ! -f "$EXT_DIR/Cargo.toml" ]; then
  echo "Cannot find Rust extension at $EXT_DIR" >&2
  exit 1
fi

if [ "${1:-}" != "--inside-nix" ] && [ "${TMP_TEST_NATIVE_NO_NIX:-}" != "1" ] && command -v nix >/dev/null 2>&1; then
  exec nix --extra-experimental-features 'nix-command flakes' shell \
    nixpkgs#php82 \
    nixpkgs#php82.unwrapped.dev \
    nixpkgs#clang_19 \
    nixpkgs#glibc.dev \
    -c bash "$SCRIPT_DIR/build-extension.sh" --inside-nix
fi

cd "$EXT_DIR"

PHP_CONFIG="${PHP_CONFIG:-$(command -v php-config || true)}"
if [ -z "$PHP_CONFIG" ]; then
  echo "php-config is required. Use Nix or install the PHP development package." >&2
  exit 1
fi

if ! command -v clang >/dev/null 2>&1; then
  echo "clang is required. Use Nix or install clang/libclang." >&2
  exit 1
fi

if [ -z "${LIBCLANG_PATH:-}" ]; then
  LIBCLANG_SO="$(find /nix/store -path '*/lib/libclang.so' -print -quit 2>/dev/null || true)"
  if [ -n "$LIBCLANG_SO" ]; then
    export LIBCLANG_PATH
    LIBCLANG_PATH="$(dirname "$LIBCLANG_SO")"
  fi
fi

PHP_INCLUDES="$("$PHP_CONFIG" --includes)"
CLANG_RESOURCE="$(clang -print-resource-dir)"

export BINDGEN_EXTRA_CLANG_ARGS="$PHP_INCLUDES -isystem $CLANG_RESOURCE/include ${BINDGEN_EXTRA_CLANG_ARGS:-}"
export CFLAGS="$PHP_INCLUDES -isystem $CLANG_RESOURCE/include ${CFLAGS:-}"

cargo build --release

EXT_SO="$EXT_DIR/target/release/libwp_mysql_parser.so"
if [ ! -f "$EXT_SO" ]; then
  echo "Build completed but extension was not found at $EXT_SO" >&2
  exit 1
fi

printf '%s\n' "$EXT_SO"
