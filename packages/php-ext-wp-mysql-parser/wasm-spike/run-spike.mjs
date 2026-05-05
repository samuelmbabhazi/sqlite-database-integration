// Headless PHP.wasm runner for the wasm-spike. It feeds the generated
// extension manifest to the released @php-wasm/node runtime, then runs a
// snippet of PHP that pokes the Rust parser and asserts the output.
//
// Run with:
//   node --experimental-wasm-jspi packages/php-ext-wp-mysql-parser/wasm-spike/run-spike.mjs
//
import { existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { loadNodeRuntime } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';

const here = dirname(fileURLToPath(import.meta.url));
const SPIKE_DIR = here;
const MANIFEST = resolve(SPIKE_DIR, 'dist/manifest.json');
const PHP_VERSION = process.env.PHP_VERSION || '8.4';

if (!existsSync(MANIFEST)) {
  console.error(`[spike] Missing ${MANIFEST}. Run build-in-docker-rust.sh first.`);
  process.exit(2);
}

const PHP_CODE = `<?php
$missing = [];
foreach ([
  'WP_MySQL_Native_Lexer',
  'WP_MySQL_Native_Parser',
  'WP_MySQL_Native_Grammar',
  'WP_MySQL_Native_Token_Stream',
] as $class) {
  if (!class_exists($class)) { $missing[] = $class; }
}
if ($missing) {
  echo 'MISSING:', implode(',', $missing);
  exit;
}

$lexer = new WP_MySQL_Native_Lexer('SELECT 1 FROM wp_posts');
$stream = $lexer->native_token_stream();
echo 'COUNT=', $stream->count();
`;

const EXPECTED =
  'COUNT=5';

console.error('[spike] loading manifest in @php-wasm/node:', MANIFEST);
const runtime = await loadNodeRuntime(PHP_VERSION, {
  extensions: [
    {
      source: {
        format: 'manifest',
        manifestUrl: MANIFEST,
      },
    },
  ],
});
const php = new PHP(runtime);

try {
  const response = await php.run({ code: PHP_CODE });
  if (response.exitCode !== 0) {
    console.error(response.errors);
    console.error(response.text);
    process.exit(response.exitCode || 1);
  }
  if (response.text !== EXPECTED) {
    console.error(`[spike] Expected ${JSON.stringify(EXPECTED)}, got ${JSON.stringify(response.text)}.`);
    process.exit(1);
  }
  console.log(response.text);
} finally {
  php.exit();
}
