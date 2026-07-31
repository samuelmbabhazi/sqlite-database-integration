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
	private static $super_admin_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		if ( ! is_multisite() ) {
			return;
		}

		self::$super_admin_id = $factory->user->create( array( 'role' => 'subscriber' ) );
		grant_super_admin( self::$super_admin_id );
	}

	public static function wpTearDownAfterClass() {
		if ( is_multisite() ) {
			revoke_super_admin( self::$super_admin_id );
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
		$this->set_single_site_administrator();
		$this->reset_admin_menu();

		$this->assertSame( 10, has_action( 'admin_menu', 'sqlite_add_admin_menu' ) );
		$this->assertFalse( has_action( 'network_admin_menu', 'sqlite_add_admin_menu' ) );
		$this->assertSame( 10, has_action( 'admin_notices', 'sqlite_plugin_admin_notice' ) );
		$this->assertFalse( has_action( 'network_admin_notices', 'sqlite_plugin_admin_notice' ) );
		$this->assertSame( admin_url( 'options-general.php?page=sqlite-integration' ), sqlite_plugin_get_admin_page_url() );

		sqlite_add_admin_menu();

		$this->assertSame( 10, has_action( 'settings_page_sqlite-integration', 'sqlite_integration_admin_screen' ) );
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
		wp_set_current_user( self::$super_admin_id );
		$this->reset_admin_menu();

		$this->assertFalse( has_action( 'admin_menu', 'sqlite_add_admin_menu' ) );
		$this->assertSame( 10, has_action( 'network_admin_menu', 'sqlite_add_admin_menu' ) );
		$this->assertFalse( has_action( 'admin_notices', 'sqlite_plugin_admin_notice' ) );
		$this->assertSame( 10, has_action( 'network_admin_notices', 'sqlite_plugin_admin_notice' ) );
		$this->assertSame( network_admin_url( 'settings.php?page=sqlite-integration' ), sqlite_plugin_get_admin_page_url() );

		sqlite_add_admin_menu();

		$this->assertSame( 10, has_action( 'settings_page_sqlite-integration', 'sqlite_integration_admin_screen' ) );
	}

	/**
	 * @group ms-excluded
	 */
	public function test_single_site_activation_redirects_to_site_admin() {
		$this->assertSame(
			admin_url( 'options-general.php?page=sqlite-integration' ),
			$this->get_activation_redirect()
		);
	}

	/**
	 * @group ms-required
	 */
	public function test_multisite_activation_redirects_to_network_admin() {
		$this->assertSame(
			network_admin_url( 'settings.php?page=sqlite-integration' ),
			$this->get_activation_redirect()
		);
	}

	/**
	 * @group ms-excluded
	 */
	public function test_single_site_admin_bar_links_to_site_admin() {
		$admin_bar = new WP_Admin_Bar();

		sqlite_plugin_adminbar_item( $admin_bar );

		$this->assertSame(
			admin_url( 'options-general.php?page=sqlite-integration' ),
			$admin_bar->get_node( 'sqlite-db-integration' )->href
		);
	}

	/**
	 * @group ms-required
	 */
	public function test_multisite_admin_bar_links_to_network_admin() {
		$admin_bar = new WP_Admin_Bar();

		sqlite_plugin_adminbar_item( $admin_bar );

		$this->assertSame(
			network_admin_url( 'settings.php?page=sqlite-integration' ),
			$admin_bar->get_node( 'sqlite-db-integration' )->href
		);
	}

	private function set_single_site_administrator() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $user_id );
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

	private function get_activation_redirect() {
		$redirect_url = null;
		$filter       = function ( $location ) use ( &$redirect_url ) {
			$redirect_url = $location;
			return false;
		};

		add_filter( 'wp_redirect', $filter );
		sqlite_plugin_activation_redirect( plugin_basename( SQLITE_MAIN_FILE ) );
		remove_filter( 'wp_redirect', $filter );

		return $redirect_url;
	}
}
