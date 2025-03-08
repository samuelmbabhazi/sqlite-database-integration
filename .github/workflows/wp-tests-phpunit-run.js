/*
 * Wrap the "composer run wp-tests-phpunit" command to process tests
 * that are expected to error and fail at the moment.
 *
 * This makes sure that the CI job passes, while explicitly tracking
 * the issues that need to be addressed. Ideally, over time this script
 * will become obsolete when all errors and failures are resolved.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

const expectedErrors = [
	'Tests_Admin_wpSiteHealth::test_object_cache_default_thresholds_non_multisite',
	'Tests_Admin_wpSiteHealth::test_object_cache_thresholds with data set #0',
	'Tests_Admin_wpSiteHealth::test_object_cache_thresholds with data set #1',
	'Tests_Admin_wpSiteHealth::test_object_cache_thresholds with data set #2',
	'Tests_Admin_wpSiteHealth::test_object_cache_thresholds with data set #3',
	'Tests_Admin_wpSiteHealth::test_object_cache_thresholds with data set #4',
	'Tests_Comment_WpComment::test_get_instance_should_succeed_for_float_that_is_equal_to_post_id',
	'Tests_Cron_getCronArray::test_get_cron_array_output_validation with data set &quot;null&quot;',
	'Tests_DB_Charset::test_strip_invalid_text',
	'Tests_DB_RealEscape::test_real_escape_input_type_handling with data set &quot;empty array&quot;',
	'Tests_DB_RealEscape::test_real_escape_input_type_handling with data set &quot;non-empty array&quot;',
	'Tests_DB_RealEscape::test_real_escape_input_type_handling with data set &quot;null&quot;',
	'Tests_DB_RealEscape::test_real_escape_input_type_handling with data set &quot;simple object&quot;',
	'Tests_DB::test_db_reconnect',
	'Tests_DB::test_get_col_info',
	'Tests_DB::test_prepare_should_respect_the_allow_unsafe_unquoted_parameters_property with data set &quot;escaped-false-1&quot;',
	'Tests_DB::test_prepare_should_respect_the_allow_unsafe_unquoted_parameters_property with data set &quot;escaped-false-2&quot;',
	'Tests_DB::test_prepare_should_respect_the_allow_unsafe_unquoted_parameters_property with data set &quot;escaped-true-1&quot;',
	'Tests_DB::test_prepare_should_respect_the_allow_unsafe_unquoted_parameters_property with data set &quot;escaped-true-2&quot;',
	'Tests_DB::test_prepare_should_respect_the_allow_unsafe_unquoted_parameters_property with data set &quot;format-false-1&quot;',
	'Tests_DB::test_prepare_should_respect_the_allow_unsafe_unquoted_parameters_property with data set &quot;format-false-2&quot;',
	'Tests_DB::test_prepare_should_respect_the_allow_unsafe_unquoted_parameters_property with data set &quot;format-true-1&quot;',
	'Tests_DB::test_prepare_should_respect_the_allow_unsafe_unquoted_parameters_property with data set &quot;format-true-2&quot;',
	'Tests_DB::test_prepare_should_respect_the_allow_unsafe_unquoted_parameters_property with data set &quot;numbered-false-1&quot;',
	'Tests_DB::test_prepare_should_respect_the_allow_unsafe_unquoted_parameters_property with data set &quot;numbered-false-2&quot;',
	'Tests_DB::test_prepare_should_respect_the_allow_unsafe_unquoted_parameters_property with data set &quot;numbered-true-1&quot;',
	'Tests_DB::test_prepare_should_respect_the_allow_unsafe_unquoted_parameters_property with data set &quot;numbered-true-2&quot;',
	'Tests_DB::test_process_fields_value_too_long_for_field with data set &quot;invalid chars&quot;',
	'Tests_DB::test_process_fields_value_too_long_for_field with data set &quot;too long&quot;',
	'Tests_DB::test_process_fields',
	'Tests_DB::test_set_allowed_incompatible_sql_mode',
	'Tests_DB::test_set_incompatible_sql_mode',
	'Tests_DB::test_set_sql_mode',
	'Tests_Import_Import::test_double_import',
	'Tests_Import_Import::test_slashes_should_not_be_stripped',
	'Tests_Import_Import::test_small_import',
	'Tests_Import_Postmeta::test_serialized_postmeta_no_cdata',
	'Tests_Import_Postmeta::test_serialized_postmeta_with_cdata',
	'Tests_Import_Postmeta::test_serialized_postmeta_with_evil_stuff_in_cdata',
	'Tests_Import_Postmeta::test_utw_postmeta',
	'Tests_Meta_Query::test_convert_null_value_to_empty_string',
	'Tests_Meta_Query::test_null_value_sql',
	'Tests_Option_WpPrimeOptionCaches::test_get_option_should_return_identical_value_when_pre_primed_by_wp_prime_option_caches with data set &quot;null&quot;',
	'Tests_Option_WpPrimeOptionCaches::test_wp_prime_option_caches_cache_should_be_identical_to_get_option_cache with data set &quot;null&quot;',
	'Tests_Option_WpPrimeOptionCaches::test_wp_prime_option_caches_does_not_trigger_db_queries_for_alloptions with data set &quot;null&quot;',
	'Tests_Option_WpPrimeOptionCaches::test_wp_prime_option_caches_does_not_trigger_db_queries_repriming_options with data set &quot;null&quot;',
	'Tests_Post_Nav_Menu::test_class_applied_to_front_page_item',
	'Tests_Post_Nav_Menu::test_class_applied_to_privacy_policy_page_item',
	'Tests_Post_Nav_Menu::test_class_not_applied_to_taxonomies_with_same_id_as_front_page_item',
	'Tests_Post_Nav_Menu::test_iri_current_menu_item with data set #0',
	'Tests_Post_Nav_Menu::test_iri_current_menu_item with data set #1',
	'Tests_Post_Nav_Menu::test_iri_current_menu_item with data set #2',
	'Tests_Post_Nav_Menu::test_iri_current_menu_item with data set #3',
	'Tests_Post_Nav_Menu::test_iri_current_menu_item with data set #4',
	'Tests_Post_Nav_Menu::test_iri_current_menu_item with data set #5',
	'Tests_Post_Nav_Menu::test_no_front_page_class_applied',
	'Tests_Post_Nav_Menu::test_no_privacy_policy_class_applied',
	'Tests_Post_Nav_Menu::test_orphan_nav_menu_item',
	'Tests_Post_Nav_Menu::test_parent_ancestor_for_post_archive',
	'Tests_Post_Nav_Menu::test_wp_get_nav_menu_items_with_taxonomy_term',
	'Tests_Post_wpPost::test_get_instance_should_succeed_for_float_that_is_equal_to_post_id',
	'Tests_Post::test_stick_post_with_unexpected_sticky_posts_option with data set &quot;null&quot;',
	'Tests_Post::test_wp_tag_cloud_link_with_post_type',
	'Tests_Term_getTerms::test_wp_delete_term_should_invalidate_cache',
	'Tests_Term_GetTheTerms::test_term_cache_should_be_invalidated_on_remove_object_terms',
	'Tests_Term_GetTheTerms::test_term_cache_should_be_invalidated_on_set_object_terms',
];

const expectedFailures = [
	'Tests_Comment::test_wp_new_comment_respects_comment_field_lengths',
	'Tests_Comment::test_wp_update_comment',
	'Tests_DB_dbDelta::test_column_type_change_with_hyphens_in_name',
	'Tests_DB_dbDelta::test_query_with_backticks_does_not_cause_a_query_to_alter_all_columns_and_indices_to_run_even_if_none_have_changed',
	'Tests_DB_dbDelta::test_query_with_backticks_does_not_throw_an_undefined_index_warning',
	'Tests_DB_dbDelta::test_spatial_indices',
	'Tests_DB::test_charset_switched_to_utf8mb4',
	'Tests_DB::test_close',
	'Tests_DB::test_delete_value_too_long_for_field with data set &quot;too long&quot;',
	'Tests_DB::test_esc_like',
	'Tests_DB::test_escape_and_prepare with data set #0',
	'Tests_DB::test_escape_and_prepare with data set #1',
	'Tests_DB::test_escape_and_prepare with data set #2',
	'Tests_DB::test_has_cap',
	'Tests_DB::test_insert_value_too_long_for_field with data set &quot;too long&quot;',
	'Tests_DB::test_like_query with data set #1',
	'Tests_DB::test_like_query with data set #3',
	'Tests_DB::test_like_query with data set #4',
	'Tests_DB::test_like_query with data set #5',
	'Tests_DB::test_like_query with data set #6',
	'Tests_DB::test_like_query with data set #8',
	'Tests_DB::test_mysqli_flush_sync',
	'Tests_DB::test_non_unicode_collations',
	'Tests_DB::test_query_value_contains_invalid_chars',
	'Tests_DB::test_replace_value_too_long_for_field with data set &quot;too long&quot;',
	'Tests_DB::test_replace',
	'Tests_DB::test_supports_collation',
	'Tests_DB::test_update_value_too_long_for_field with data set &quot;too long&quot;',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #1',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #2',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #3',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #4',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #5',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #6',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #7',
	'Tests_Menu_wpNavMenu::test_parent_with_higher_id_should_not_error',
	'Tests_Menu_wpNavMenu::test_wp_nav_menu_should_have_has_children_class_without_custom_depth',
	'Tests_Menu_wpNavMenu::test_wp_nav_menu_should_not_have_has_children_class_with_custom_depth',
	'Tests_Post_Nav_Menu::test_wp_get_nav_menu_items_cache_primes_posts',
	'Tests_Post_Nav_Menu::test_wp_get_nav_menu_items_cache_primes_terms',
	'Tests_Post_Nav_Menu::test_wp_nav_menu_empty_container',
	'Tests_Post_Nav_Menu::test_wp_nav_menu_whitespace_options',
	'Tests_Sitemaps_Sitemaps::test_get_sitemap_entries_post_with_permalinks',
	'Tests_Sitemaps_Sitemaps::test_get_sitemap_entries',
	'Tests_Sitemaps_wpSitemapsTaxonomies::test_get_sitemap_entries_custom_taxonomies',
	'Tests_Sitemaps_wpSitemapsTaxonomies::test_get_url_list_custom_taxonomy',
	'Tests_Sitemaps_wpSitemapsTaxonomies::test_get_url_list_taxonomies',
	'Tests_Term_getTerms::test_get_terms_cache_should_be_missed_when_passing_number',
	'Tests_Term_getTerms::test_get_terms_cache',
	'Tests_Term_getTerms::test_get_terms_grandparent_zero',
	'Tests_Term_getTerms::test_get_terms_hierarchical_tax_hide_empty_true_fields_count_hierarchical_false',
	'Tests_Term_getTerms::test_get_terms_hierarchical_tax_hide_empty_true_fields_count',
	'Tests_Term_getTerms::test_get_terms_hierarchical_tax_hide_empty_true_fields_idname_hierarchical_false',
	'Tests_Term_getTerms::test_get_terms_hierarchical_tax_hide_empty_true_fields_idname',
	'Tests_Term_getTerms::test_get_terms_hierarchical_tax_hide_empty_true_fields_idparent_hierarchical_false',
	'Tests_Term_getTerms::test_get_terms_hierarchical_tax_hide_empty_true_fields_idparent',
	'Tests_Term_getTerms::test_get_terms_hierarchical_tax_hide_empty_true_fields_ids_hierarchical_false',
	'Tests_Term_getTerms::test_get_terms_hierarchical_tax_hide_empty_true_fields_ids',
	'Tests_Term_getTerms::test_get_terms_hierarchical_tax_hide_empty_true_fields_idslug_hierarchical_false',
	'Tests_Term_getTerms::test_get_terms_hierarchical_tax_hide_empty_true_fields_idslug',
	'Tests_Term_getTerms::test_get_terms_hierarchical_tax_hide_empty_true_fields_names_hierarchical_false',
	'Tests_Term_getTerms::test_get_terms_hierarchical_tax_hide_empty_true_fields_names',
	'Tests_Term_getTerms::test_get_terms_parent_zero',
	'Tests_Term_getTerms::test_get_terms_seven_levels_deep',
	'Tests_Term_getTerms::test_get_terms_without_update_get_terms_cache',
	'Tests_Term_getTerms::test_hierarchical_false_with_child_of_and_direct_child',
	'Tests_Term_getTerms::test_hierarchical_should_recurse_properly_for_all_taxonomies',
	'Tests_Term_getTerms::test_hierarchical_true_parent_overrides_child_of',
	'Tests_Term_getTerms::test_hierarchical_true_with_child_of_should_return_grandchildren',
	'Tests_Term_getTerms::test_hierarchical_true_with_parent',
	'Tests_Term_getTerms::test_meta_query_args_only',
	'Tests_Term_GetTheTerms::test_count_should_not_be_improperly_cached',
	'Tests_Term::test_wp_count_terms',
	'WP_Test_REST_Categories_Controller::test_get_items_hide_empty_arg',
	'WP_Test_REST_Tags_Controller::test_get_items_hide_empty_arg',
];

console.log( 'Running WordPress PHPUnit tests with expected failures tracking...' );
console.log( 'Expected errors:', expectedErrors );
console.log( 'Expected failures:', expectedFailures );

try {
	try {
		execSync(
			`composer run wp-test-phpunit -- --log-junit=phpunit-results.xml --verbose`,
			{ stdio: 'inherit' }
		);
		console.log( '\n⚠️ All tests passed, checking if expected errors/failures occurred...' );
	} catch ( error ) {
		console.log( '\n⚠️ Some tests errored/failed (expected). Analyzing results...' );
	}

	// Read the JUnit XML test output:
	const junitOutputFile = path.join( __dirname, '..', '..', 'wordpress', 'phpunit-results.xml' );
	if ( ! fs.existsSync( junitOutputFile ) ) {
		console.error( 'Error: JUnit output file not found!' );
		process.exit( 1 );
	}
	const junitXml = fs.readFileSync( junitOutputFile, 'utf8' );

	// Extract test info from the XML:
	const actualErrors = [];
	const actualFailures = [];
	for ( const testcase of junitXml.matchAll( /<testcase([^>]*)\/>|<testcase([^>]*)>([\s\S]*?)<\/testcase>/g ) ) {
		const attributes = {};
		const attributesString = testcase[2] ?? testcase[1];
		for ( const attribute of attributesString.matchAll( /(\w+)="([^"]*)"/g ) ) {
			attributes[attribute[1]] = attribute[2];
		}

		const content = testcase[3] ?? '';
		const fqn = attributes.class ? `${attributes.class}::${attributes.name}` : attributes.name;
		const hasError = content.includes( '<error' );
		const hasFailure = content.includes( '<failure' );

		if ( hasError ) {
			actualErrors.push( fqn );
		}

		if ( hasFailure ) {
			actualFailures.push( fqn );
		}
	}

	let isSuccess = true;

	// Check if all expected errors actually errored
	const unexpectedNonErrors = expectedErrors.filter( test => ! actualErrors.includes( test ) );
	if ( unexpectedNonErrors.length > 0 ) {
		console.error( '\n❌ The following tests were expected to error but did not:' );
		unexpectedNonErrors.forEach( test => console.error( `  - ${test}` ) );
		isSuccess = false;
	}

	// Check if all expected failures actually failed
	const unexpectedPasses = expectedFailures.filter( test => ! actualFailures.includes( test ) );
	if ( unexpectedPasses.length > 0 ) {
		console.error( '\n❌ The following tests were expected to fail but passed:' );
		unexpectedPasses.forEach( test => console.error( `  - ${test}` ) );
		isSuccess = false;
	}

	// Check for unexpected errors
	const unexpectedErrors = actualErrors.filter( test => ! expectedErrors.includes( test ) );
	if ( unexpectedErrors.length > 0 ) {
		console.error( '\n❌ The following tests errored unexpectedly:' );
		unexpectedErrors.forEach( test => console.error( `  - ${test}` ) );
		isSuccess = false;
	}

	// Check for unexpected failures
	const unexpectedFailures = actualFailures.filter( test => ! expectedFailures.includes( test ) );
	if ( unexpectedFailures.length > 0 ) {
		console.error( '\n❌ The following tests failed unexpectedly:' );
		unexpectedFailures.forEach( test => console.error( `  - ${test}` ) );
		isSuccess = false;
	}

	if ( isSuccess ) {
		console.log( '\n✅ All tests behaved as expected!' );
		process.exit( 0 );
	} else {
		console.log( '\n❌ Some tests did not behave as expected!' );
		process.exit( 1 );
	}
} catch ( error ) {
	console.error( '\n❌ Script execution error:', error.message );
	process.exit( 1 );
}
