# Native SQLite Facade Smoke Test

This directory contains a local smoke test for the lazy native MySQL parser path
as used by the SQLite driver.

Run the smoke test directly from the repository root:

```bash
php tmp-test-native/run-sqlite-facade-smoke.php
```

That file does not know or care whether the Rust extension is loaded. It just
loads the SQLite library, runs MySQL-flavored queries through
`WP_PDO_MySQL_On_SQLite`, asks the driver to create parsers, and traverses the
returned ASTs. The library chooses the native parser/tokenizer when available
and falls back to the PHP implementation otherwise.

By default, it processes 2000 generated SQL queries. That includes a
2000-row multi-insert every 250 queries. To change the query count:

```bash
TMP_TEST_NATIVE_QUERY_COUNT=500 php tmp-test-native/run-sqlite-facade-smoke.php
```

To build the Rust extension locally and run the smoke test once with the current
PHP configuration and once with the extension explicitly loaded:

```bash
./tmp-test-native/run.sh
```

If you already have a PHP/ext build environment and do not want the helper to
enter a Nix shell, run:

```bash
TMP_TEST_NATIVE_NO_NIX=1 ./tmp-test-native/run.sh
```
