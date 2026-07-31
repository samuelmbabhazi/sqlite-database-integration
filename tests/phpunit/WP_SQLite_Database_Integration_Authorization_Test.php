<?php

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
require_once WP_CONTENT_DIR . '/plugins/sqlite-database-integration/load.php';

class WP_SQLite_Database_Integration_Authorization_Tests extends WP_UnitTestCase {

	/**
	 * @var array|null
	 */
	private $admin_menu_globals;

	/**
	 * @var int
	 */
	private static $site_id;

	/**
	 * @var int
	 */
	private static $subsite_admin_id;

	/**
	 * @var int
	 */
	private static $super_admin_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		if ( ! is_multisite() ) {
			return;
		}

		self::$subsite_admin_id = $factory->user->create( array( 'role' => 'subscriber' ) );
		self::$site_id          = $factory->blog->create(
			array(
				'path'    => '/sqlite-authorization/',
				'user_id' => self::$subsite_admin_id,
			)
		);
		self::$super_admin_id   = $factory->user->create( array( 'role' => 'subscriber' ) );

		add_user_to_blog( self::$site_id, self::$super_admin_id, 'administrator' );
		grant_super_admin( self::$super_admin_id );
	}

	public static function wpTearDownAfterClass() {
		if ( is_multisite() ) {
			revoke_super_admin( self::$super_admin_id );
			wp_delete_site( self::$site_id );
		}
	}

	public function tear_down() {
		if ( null !== $this->admin_menu_globals ) {
			foreach ( $this->admin_menu_globals as $name => $state ) {
				if ( $state['exists'] ) {
					$GLOBALS[ $name ] = $state['value'];
				} else {
					unset( $GLOBALS[ $name ] );
				}
			}
			$this->admin_menu_globals = null;
		}

		while ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}

		parent::tear_down();
	}

	public function test_plugin_is_loaded() {
		$this->assertTrue( defined( 'SQLITE_MAIN_FILE' ) );
		$this->assertFileExists( SQLITE_MAIN_FILE );
	}

	/**
	 * @group ms-excluded
	 */
	public function test_single_site_uses_site_admin() {
		$this->set_single_site_user( 'administrator' );
		$this->reset_admin_menu();

		$this->assertSame( 10, has_action( 'admin_menu', 'sqlite_add_admin_menu' ) );
		$this->assertFalse( has_action( 'network_admin_menu', 'sqlite_add_admin_menu' ) );
		$this->assertSame( 10, has_action( 'admin_notices', 'sqlite_plugin_admin_notice' ) );
		$this->assertFalse( has_action( 'network_admin_notices', 'sqlite_plugin_admin_notice' ) );
		$this->assertSame( admin_url( 'options-general.php?page=sqlite-integration' ), sqlite_plugin_get_admin_page_url() );

		sqlite_add_admin_menu();

		$this->assertSame( 10, has_action( 'settings_page_sqlite-integration', 'sqlite_integration_admin_screen' ) );
		$this->assert_admin_screen_is_accessible();
		$this->assertSame(
			admin_url( 'options-general.php?page=sqlite-integration' ),
			$this->get_activation_redirect()
		);
		$this->assertSame(
			admin_url( 'options-general.php?page=sqlite-integration' ),
			$this->get_admin_bar_node()->href
		);
		$this->assertStringContainsString( 'file is missing', $this->get_admin_notice_without_dropin() );
	}

	/**
	 * @group ms-required
	 */
	public function test_multisite_plugin_is_network_only() {
		$plugin_data = get_plugin_data( SQLITE_MAIN_FILE, false, false );

		$this->assertTrue( $plugin_data['Network'] );
	}

	/**
	 * @group ms-required
	 */
	public function test_multisite_uses_network_admin() {
		$this->set_multisite_user( true );
		$this->reset_admin_menu();

		$this->assertFalse( has_action( 'admin_menu', 'sqlite_add_admin_menu' ) );
		$this->assertSame( 10, has_action( 'network_admin_menu', 'sqlite_add_admin_menu' ) );
		$this->assertFalse( has_action( 'admin_notices', 'sqlite_plugin_admin_notice' ) );
		$this->assertSame( 10, has_action( 'network_admin_notices', 'sqlite_plugin_admin_notice' ) );
		$this->assertSame( network_admin_url( 'settings.php?page=sqlite-integration' ), sqlite_plugin_get_admin_page_url() );

		sqlite_add_admin_menu();

		$this->assertSame( 10, has_action( 'settings_page_sqlite-integration', 'sqlite_integration_admin_screen' ) );
		$this->assert_admin_screen_is_accessible();
		$this->assertSame(
			network_admin_url( 'settings.php?page=sqlite-integration' ),
			$this->get_activation_redirect()
		);
		$this->assertSame(
			network_admin_url( 'settings.php?page=sqlite-integration' ),
			$this->get_admin_bar_node()->href
		);
		$this->assertStringContainsString( 'file is missing', $this->get_admin_notice_without_dropin() );
	}

	/**
	 * @group ms-excluded
	 */
	public function test_single_site_subscriber_cannot_access_management_surfaces() {
		$this->set_single_site_user( 'subscriber' );

		$this->assertNull( $this->get_admin_bar_node() );
		$this->assertSame( '', $this->get_admin_notice_without_dropin() );
		$this->assertNull( $this->get_activation_redirect() );

		$this->reset_admin_menu();

		sqlite_add_admin_menu();

		$this->assertTrue( $GLOBALS['_wp_submenu_nopriv']['options-general.php']['sqlite-integration'] );

		$this->expectException( WPDieException::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessage( 'Sorry, you are not allowed to access the SQLite integration settings.' );

		sqlite_integration_admin_screen();
	}

	/**
	 * @group ms-required
	 */
	public function test_subsite_administrator_cannot_access_management_surfaces() {
		$this->set_multisite_user( false );

		$this->assertNull( $this->get_admin_bar_node() );
		$this->assertSame( '', $this->get_admin_notice_without_dropin() );
		$this->assertNull( $this->get_activation_redirect() );

		$this->reset_admin_menu();

		sqlite_add_admin_menu();

		$this->assertTrue( $GLOBALS['_wp_submenu_nopriv']['settings.php']['sqlite-integration'] );

		$this->expectException( WPDieException::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessage( 'Sorry, you are not allowed to access the SQLite integration settings.' );

		sqlite_integration_admin_screen();
	}

	/**
	 * @group ms-excluded
	 */
	public function test_other_plugin_activation_does_not_redirect() {
		$this->assertNull( $this->get_activation_redirect( 'another-plugin/plugin.php' ) );
	}

	/**
	 * @group ms-excluded
	 */
	public function test_confirm_install_is_ignored_on_another_page() {
		$_GET     = array(
			'page'            => 'another-page',
			'confirm-install' => '1',
			'_wpnonce'        => 'invalid',
		);
		$_REQUEST = $_GET;

		$this->assertNull( sqlite_activation() );
	}

	/**
	 * @group ms-excluded
	 */
	public function test_single_site_administrator_with_valid_nonce_can_install() {
		$this->set_single_site_user( 'administrator' );

		$this->assert_valid_nonce_reaches_install_redirect();
	}

	/**
	 * @group ms-excluded
	 */
	public function test_single_site_administrator_with_invalid_nonce_is_denied() {
		$this->set_single_site_user( 'administrator' );

		$this->assert_invalid_nonce_is_denied();
	}

	/**
	 * @group ms-excluded
	 */
	public function test_single_site_editor_with_valid_nonce_cannot_install() {
		$this->set_single_site_user( 'editor' );

		$this->assert_valid_nonce_install_is_denied();
	}

	/**
	 * @group ms-required
	 */
	public function test_super_admin_with_valid_nonce_can_install() {
		$this->set_multisite_user( true );

		$this->assert_valid_nonce_reaches_install_redirect();
	}

	/**
	 * @group ms-required
	 */
	public function test_super_admin_with_invalid_nonce_is_denied() {
		$this->set_multisite_user( true );

		$this->assert_invalid_nonce_is_denied();
	}

	/**
	 * @group ms-required
	 */
	public function test_subsite_administrator_with_valid_nonce_cannot_install() {
		$this->set_multisite_user( false );

		$this->assert_valid_nonce_install_is_denied();
	}

	/**
	 * @group ms-excluded
	 */
	public function test_single_site_administrator_can_deactivate_sqlite() {
		$this->set_single_site_user( 'administrator' );

		$this->assertTrue( current_user_can( 'deactivate_plugin', plugin_basename( SQLITE_MAIN_FILE ) ) );
		$this->assert_deactivation_removes_dropin();
	}

	/**
	 * @group ms-excluded
	 */
	public function test_single_site_plugin_manager_can_only_deactivate_sqlite_without_dropin() {
		$this->set_single_site_user( 'administrator' );
		wp_get_current_user()->add_cap( 'manage_options', false );

		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->assertTrue( current_user_can( 'deactivate_plugin', 'another-plugin/plugin.php' ) );
		$this->assertFalse( current_user_can( 'deactivate_plugin', plugin_basename( SQLITE_MAIN_FILE ) ) );

		$this->run_without_dropin(
			function () {
				$this->assertTrue( current_user_can( 'deactivate_plugin', plugin_basename( SQLITE_MAIN_FILE ) ) );
			}
		);
	}

	/**
	 * @group ms-required
	 */
	public function test_super_admin_can_deactivate_sqlite() {
		$this->set_multisite_user( true );

		$this->assertTrue( current_user_can( 'deactivate_plugin', plugin_basename( SQLITE_MAIN_FILE ) ) );
		$this->assert_deactivation_removes_dropin();
	}

	/**
	 * @group ms-required
	 */
	public function test_delegated_network_plugin_manager_can_only_deactivate_sqlite_without_dropin() {
		$this->run_as_delegated_network_plugin_manager(
			function () {
				$this->assertTrue( current_user_can( 'deactivate_plugin', 'another-plugin/plugin.php' ) );
				$this->assertFalse( current_user_can( 'deactivate_plugin', plugin_basename( SQLITE_MAIN_FILE ) ) );

				$this->run_without_dropin(
					function () {
						$this->assertTrue( current_user_can( 'deactivate_plugin', plugin_basename( SQLITE_MAIN_FILE ) ) );
					}
				);
			}
		);
	}

	private function set_single_site_user( $role ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );

		wp_set_current_user( $user_id );
	}

	private function set_multisite_user( $is_super_admin ) {
		$user_id = $is_super_admin ? self::$super_admin_id : self::$subsite_admin_id;

		switch_to_blog( self::$site_id );
		wp_set_current_user( $user_id );
	}

	private function run_as_delegated_network_plugin_manager( $callback ) {
		$this->set_multisite_user( false );

		$user         = wp_get_current_user();
		$capabilities = array( 'activate_plugins', 'manage_network_plugins' );

		foreach ( $capabilities as $capability ) {
			$user->add_cap( $capability );
		}

		try {
			return call_user_func( $callback );
		} finally {
			foreach ( $capabilities as $capability ) {
				$user->remove_cap( $capability );
			}
		}
	}

	private function assert_deactivation_removes_dropin() {
		$filesystem = $this->run_with_deactivation_filesystem( 'sqlite_plugin_remove_db_file' );

		$this->assertSame( WP_CONTENT_DIR . '/db.php', $filesystem->deleted_path );
		$this->assertFileExists( WP_CONTENT_DIR . '/db.php' );
	}

	private function run_with_deactivation_filesystem( $callback ) {
		$wp_filesystem_was_set = array_key_exists( 'wp_filesystem', $GLOBALS );
		$wp_filesystem_before  = $wp_filesystem_was_set ? $GLOBALS['wp_filesystem'] : null;
		$filesystem            = new class() {
			/**
			 * @var string|null
			 */
			public $deleted_path;

			public function delete( $path ) {
				$this->deleted_path = $path;
				return true;
			}
		};

		$GLOBALS['wp_filesystem'] = $filesystem;

		try {
			call_user_func( $callback );
		} finally {
			if ( $wp_filesystem_was_set ) {
				$GLOBALS['wp_filesystem'] = $wp_filesystem_before;
			} else {
				unset( $GLOBALS['wp_filesystem'] );
			}
		}

		return $filesystem;
	}

	private function reset_admin_menu() {
		$global_names = array(
			'menu',
			'submenu',
			'_wp_submenu_nopriv',
			'_registered_pages',
			'_parent_pages',
			'admin_page_hooks',
			'_wp_real_parent_file',
		);

		$this->admin_menu_globals = array();
		foreach ( $global_names as $name ) {
			$this->admin_menu_globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? $GLOBALS[ $name ] : null,
			);
		}

		$GLOBALS['menu']                 = array();
		$GLOBALS['submenu']              = array();
		$GLOBALS['_wp_submenu_nopriv']   = array();
		$GLOBALS['_registered_pages']    = array();
		$GLOBALS['_parent_pages']        = array();
		$GLOBALS['admin_page_hooks']     = array(
			is_multisite() ? 'settings.php' : 'options-general.php' => 'settings',
		);
		$GLOBALS['_wp_real_parent_file'] = array();
	}

	private function get_activation_redirect( $plugin = null ) {
		if ( null === $plugin ) {
			$plugin = plugin_basename( SQLITE_MAIN_FILE );
		}

		$redirect_url = null;
		$filter       = function ( $location ) use ( &$redirect_url ) {
			$redirect_url = $location;
			return false;
		};

		add_filter( 'wp_redirect', $filter );
		sqlite_plugin_activation_redirect( $plugin );
		remove_filter( 'wp_redirect', $filter );

		return $redirect_url;
	}

	private function get_admin_bar_node() {
		$admin_bar = new WP_Admin_Bar();

		sqlite_plugin_adminbar_item( $admin_bar );

		return $admin_bar->get_node( 'sqlite-db-integration' );
	}

	private function assert_admin_screen_is_accessible() {
		ob_start();
		sqlite_integration_admin_screen();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'SQLite is enabled.', $output );
		$this->assertStringContainsString( esc_url( self_admin_url( 'plugins.php' ) ), $output );
	}

	private function get_admin_notice_without_dropin() {
		return $this->run_without_dropin(
			function () {
				ob_start();
				sqlite_plugin_admin_notice();
				return ob_get_clean();
			}
		);
	}

	private function run_without_dropin( $callback ) {
		$dropin_path        = WP_CONTENT_DIR . '/db.php';
		$dropin_backup_path = tempnam( WP_CONTENT_DIR, 'db.php.' );

		$this->assertNotFalse( $dropin_backup_path );
		$this->assertTrue( unlink( $dropin_backup_path ) );
		$this->assertTrue( rename( $dropin_path, $dropin_backup_path ) );

		$restore_dropin      = function () use ( $dropin_path, $dropin_backup_path ) {
			if ( ! file_exists( $dropin_backup_path ) ) {
				return file_exists( $dropin_path );
			}

			return rename( $dropin_backup_path, $dropin_path );
		};
		$restore_on_shutdown = true;
		register_shutdown_function(
			function () use ( &$restore_on_shutdown, $restore_dropin ) {
				if ( $restore_on_shutdown ) {
					call_user_func( $restore_dropin );
				}
			}
		);

		try {
			$result = call_user_func( $callback );
		} finally {
			$dropin_restored     = call_user_func( $restore_dropin );
			$restore_on_shutdown = false;
		}

		$this->assertTrue( $dropin_restored );
		$this->assertFileExists( $dropin_path );

		return $result;
	}

	private function assert_valid_nonce_reaches_install_redirect() {
		$nonce            = wp_create_nonce( 'sqlite-install' );
		$redirect_url     = null;
		$redirect_reached = false;
		$filter           = function ( $location ) use ( &$redirect_url ) {
			$redirect_url = $location;
			throw new RuntimeException( 'Install redirect reached.' );
		};

		$this->set_install_request( $nonce );
		add_filter( 'wp_redirect', $filter );

		try {
			sqlite_activation();
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Install redirect reached.', $exception->getMessage() );
			$redirect_reached = true;
		} finally {
			remove_filter( 'wp_redirect', $filter );
		}

		$this->assertTrue( $redirect_reached, 'The install request did not redirect.' );
		$this->assertSame( admin_url(), $redirect_url );
	}

	private function assert_valid_nonce_install_is_denied() {
		$dropin_before = file_get_contents( WP_CONTENT_DIR . '/db.php' );
		$nonce         = wp_create_nonce( 'sqlite-install' );
		$nonce_checked = false;
		$nonce_action  = function () use ( &$nonce_checked ) {
			$nonce_checked = true;
		};

		$this->assertNotFalse( wp_verify_nonce( $nonce, 'sqlite-install' ) );
		$this->set_install_request( $nonce );
		add_action( 'check_admin_referer', $nonce_action );

		try {
			sqlite_activation();
			$this->fail( 'The install request was not denied.' );
		} catch ( WPDieException $exception ) {
			$this->assertSame( 403, $exception->getCode() );
			$this->assertSame( 'Sorry, you are not allowed to install the SQLite database drop-in.', $exception->getMessage() );
		} finally {
			remove_action( 'check_admin_referer', $nonce_action );
		}

		$this->assertFalse( $nonce_checked, 'The capability check must run before nonce validation.' );
		$this->assertSame( $dropin_before, file_get_contents( WP_CONTENT_DIR . '/db.php' ) );
	}

	private function assert_invalid_nonce_is_denied() {
		$nonce_checked = false;
		$nonce_action  = function ( $action, $result ) use ( &$nonce_checked ) {
			$nonce_checked = true;
			$this->assertSame( 'sqlite-install', $action );
			$this->assertFalse( $result );
		};

		$this->set_install_request( 'invalid' );
		add_action( 'check_admin_referer', $nonce_action, 10, 2 );

		try {
			sqlite_activation();
			$this->fail( 'The invalid nonce was accepted.' );
		} catch ( WPDieException $exception ) {
			$this->assertSame( 403, $exception->getCode() );
		} finally {
			remove_action( 'check_admin_referer', $nonce_action );
		}

		$this->assertTrue( $nonce_checked );
	}

	private function set_install_request( $nonce ) {
		$_GET     = array(
			'page'            => 'sqlite-integration',
			'confirm-install' => '1',
			'_wpnonce'        => $nonce,
		);
		$_REQUEST = $_GET;
	}
}
