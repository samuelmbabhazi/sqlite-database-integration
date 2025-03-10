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
	'Tests_Post_wpPost::test_get_instance_should_succeed_for_float_that_is_equal_to_post_id',
	'Tests_Post::test_stick_post_with_unexpected_sticky_posts_option with data set &quot;null&quot;',
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
	'Tests_DB::test_has_cap',
	'Tests_DB::test_insert_value_too_long_for_field with data set &quot;too long&quot;',
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
	'Tests_Menu_wpNavMenu::test_wp_nav_menu_should_not_have_has_children_class_with_custom_depth',
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
