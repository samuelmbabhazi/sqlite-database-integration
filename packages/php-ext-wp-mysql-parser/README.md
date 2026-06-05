# WP MySQL Parser PHP Extension

`wp_mysql_parser` is an optional native PHP extension for the SQLite Database Integration project. It provides native implementations of the MySQL lexer/parser path used by the SQLite driver while keeping the pure-PHP implementation as the portable fallback.

When the extension is loaded before `packages/mysql-on-sqlite/src/load.php`, it registers native base classes used by the public `WP_MySQL_Lexer` and `WP_MySQL_Parser` wrappers. Without the extension, those public wrappers extend the pure-PHP implementations instead.

## Versioning and the grammar ABI

The native parser and the PHP driver exchange the parser grammar at runtime via
`wp_sqlite_mysql_native_export_grammar()`. The shape of that data is an ABI shared
between the extension binary and the PHP code, and it can change between releases
(for example, the move from a coarse lookahead table to per-token branch selectors).

Compatibility is tracked by the extension's **minor** version (the `x` in `0.x`):

- **Bump the minor version on any backward-incompatible change to the grammar ABI**
  (the data exchanged by `wp_sqlite_mysql_native_export_grammar()` or consumed by the
  native parser). Patch releases must keep the ABI unchanged.
- The PHP side (`packages/mysql-on-sqlite/src/load.php`) pins the supported minor
  line and selects the native lexer/parser only when `phpversion( 'wp_mysql_parser' )`
  falls within it. A mismatch — most commonly a plugin update that outpaces the
  installed extension binary — falls back cleanly to the pure-PHP path instead of
  failing at parse time.

When you change the grammar ABI, bump `version` in `Cargo.toml` and update the
supported range in `wp_sqlite_mysql_native_grammar_abi_supported()` in `load.php`
together.

## Published WASM build for Playground

Published WASM builds are listed on this repository's GitHub Pages site, with manifest links and a “Run in Playground” link for each release:

<https://wordpress.github.io/sqlite-database-integration/>

The current build is also available directly:

- Manifest: <https://wordpress.github.io/sqlite-database-integration/wp_mysql_parser-wasm-extension/latest/manifest.json>
- Supported PHP versions: 8.0, 8.1, 8.2, 8.3, 8.4, and 8.5.

Use this Playground URL to load the extension and open the demo/benchmark page.
The published manifest contains PHP 8.0 through 8.5 side modules, and CI checks
that every side module imports only symbols exported by the matching Playground
browser runtime.

<https://playground.wordpress.net/?php=8.5&php-extension=https%3A%2F%2Fwordpress.github.io%2Fsqlite-database-integration%2Fwp_mysql_parser-wasm-extension%2Flatest%2Fmanifest.json&blueprint-url=https%3A%2F%2Fwordpress.github.io%2Fsqlite-database-integration%2Fblueprint.json>

## Build the native extension locally

Requirements:

- Rust toolchain.
- PHP development headers and `php-config`.
- libclang, with `LIBCLANG_PATH` pointing at the directory containing the libclang shared library when auto-detection is not enough.

```bash
(
	cd packages/php-ext-wp-mysql-parser
	PHP_CONFIG="$(command -v php-config)" \
	LIBCLANG_PATH=/path/to/libclang \
	cargo build --release
)
```

On macOS, if the linker reports unresolved Zend/PHP symbols, add dynamic lookup linker flags:

```bash
(
	cd packages/php-ext-wp-mysql-parser
	RUSTFLAGS='-C link-arg=-undefined -C link-arg=dynamic_lookup' \
	PHP_CONFIG="$(command -v php-config)" \
	LIBCLANG_PATH=/path/to/libclang \
	cargo build --release
)
```

The resulting shared object is written under `target/release/` as `libwp_mysql_parser.so` on Linux or `libwp_mysql_parser.dylib` on macOS.

From the repository root, load it for local verification or benchmarks:

```bash
php -d extension=/absolute/path/to/libwp_mysql_parser.so -m | grep wp_mysql_parser
php -d extension=/absolute/path/to/libwp_mysql_parser.so packages/mysql-on-sqlite/tests/tools/verify-native-parser-extension.php
```

## Benchmarks

Run the pure-PHP lexer/parser benchmarks:

```bash
php packages/mysql-on-sqlite/tests/tools/run-lexer-benchmark.php --json
php packages/mysql-on-sqlite/tests/tools/run-parser-benchmark.php --json
```

Run the same parser benchmark with the native extension loaded:

```bash
php -d extension=/absolute/path/to/libwp_mysql_parser.so packages/mysql-on-sqlite/tests/tools/run-parser-benchmark.php --json
```

Compare the pure-PHP lexer with the native extension lexer in one process:

```bash
php -d extension=/absolute/path/to/libwp_mysql_parser.so packages/mysql-on-sqlite/tests/tools/run-native-extension-benchmark.php
php -d extension=/absolute/path/to/libwp_mysql_parser.so packages/mysql-on-sqlite/tests/tools/run-native-extension-benchmark.php --json
```

The GitHub Pages demo reads published benchmark data from:

<https://wordpress.github.io/sqlite-database-integration/native-extension/benchmark.json>

Latest local measurement (Apple Silicon macOS, PHP 8.5.5 CLI, over the 69,577-query corpus,
best of five runs, 2026-06-05):

**Without JIT:**

| Benchmark | Pure PHP [QPS] | Native [QPS] | Speedup |
| --- | ---: | ---: | ---: |
| MySQL lexer | 178,619 | 354,058 | 1.98x |
| MySQL parser | 28,640 | 60,119 | 2.10x |

**With opcache + tracing JIT:**

| Benchmark | Pure PHP [QPS] | Native [QPS] | Speedup |
| --- | ---: | ---: | ---: |
| MySQL lexer | 332,974 | 364,365 | 1.09x |
| MySQL parser | 50,088 | 60,253 | 1.20x |

The parser rows are parse-only and reuse a single parser instance across the corpus (resetting
tokens per query), mirroring the driver, which reuses its parser across a request's queries.
On this branch the native parser materializes the full
`WP_Parser_Node` tree eagerly, so the number reflects building a complete AST rather than a
deferred handle (the earlier 15x figure measured a lazy parse that never built the tree).

The native code is essentially JIT-independent, while the pure-PHP path speeds up substantially
under opcache + tracing JIT — so the native edge narrows from roughly 2x to about 1.1x for the
lexer and 1.2x for the parser. The published `benchmark.json` environment matches the without-JIT
numbers.

That file should be updated whenever a new extension build or benchmark environment is published.

## PHP.wasm side-module build

The experimental PHP.wasm side-module build lives in `wasm-spike/` and is verified in CI for PHP `8.0` through `8.5`.

PHP `7.4` is not supported by this Rust WASM path. The build uses `ext-php-rs` `0.15`, which depends on PHP 8 Zend APIs and does not compile against PHP `7.4` headers.
