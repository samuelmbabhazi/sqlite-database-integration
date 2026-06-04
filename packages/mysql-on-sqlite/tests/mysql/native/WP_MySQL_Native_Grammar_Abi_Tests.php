<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the native-extension grammar-ABI gate used by "load.php".
 *
 * The native parser exchanges the parser grammar with PHP across an ABI that is
 * tracked by the extension's minor version. "load.php" selects the native path
 * only when the loaded extension's version is within the supported range, so a
 * stale or mismatched extension binary falls back to pure PHP instead of failing
 * at parse time. The helper "wp_sqlite_mysql_native_grammar_abi_supported()" is
 * defined by "load.php" (loaded via the test bootstrap).
 */
class WP_MySQL_Native_Grammar_Abi_Tests extends TestCase {

	/**
	 * @dataProvider supported_versions
	 */
	public function test_supported_versions_are_accepted( string $version ): void {
		$this->assertTrue( wp_sqlite_mysql_native_grammar_abi_supported( $version ) );
	}

	/**
	 * @dataProvider unsupported_versions
	 * @param string|false $version
	 */
	public function test_unsupported_versions_are_rejected( $version ): void {
		$this->assertFalse( wp_sqlite_mysql_native_grammar_abi_supported( $version ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function supported_versions(): array {
		return array(
			'minor line lower bound' => array( '0.2.0' ),
			'patch within the line'  => array( '0.2.1' ),
			'higher patch'           => array( '0.2.99' ),
		);
	}

	/**
	 * @return array<string,array{0:string|false}>
	 */
	public function unsupported_versions(): array {
		return array(
			'extension not loaded'      => array( false ),
			'older ABI line'            => array( '0.1.0' ),
			'older ABI line high patch' => array( '0.1.99' ),
			'next (breaking) ABI line'  => array( '0.3.0' ),
			'future major'              => array( '1.0.0' ),
		);
	}
}
