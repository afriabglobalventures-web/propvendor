<?php
/**
 * PropVendor - Location, Address & Plot Units Schema Enhancement Test Suite
 */

define('WP_USE_THEMES', false);
require_once 'c:/xampp/htdocs/Propvendor/wp-load.php';

echo "=======================================================\n";
echo "PROPVENDOR LOCATION, ADDRESS & PLOT UNITS SCHEMA TESTS\n";
echo "=======================================================\n\n";

$pass_count = 0;
$fail_count = 0;

function pv_assert($condition, $test_name, $details = '') {
    global $pass_count, $fail_count;
    if ($condition) {
        echo "[PASS] " . $test_name . "\n";
        $pass_count++;
    } else {
        echo "[FAIL] " . $test_name . ($details ? " -> " . $details : "") . "\n";
        $fail_count++;
    }
}

function setup_mock_singular_context($post_obj) {
    global $wp_the_query, $wp_query, $post;
    $post = $post_obj;
    $wp_query = new WP_Query();
    $wp_the_query = $wp_query;
    $wp_query->is_singular = true;
    $wp_query->is_single = true;
    $wp_query->in_the_loop = true;
    $wp_query->queried_object = $post_obj;
    $wp_query->queried_object_id = $post_obj->ID;
    $wp_query->posts = array($post_obj);
    $wp_query->post = $post_obj;
    $wp_query->post_count = 1;
    $wp_query->current_post = 0;
}

// -------------------------------------------------------------
// 1. Nigerian States Allowlist & Normalization Tests
// -------------------------------------------------------------
echo "--- TEST 1: Nigerian States Allowlist & Normalization ---\n";
$states = propvendor_marketplace_get_nigerian_states();
pv_assert(is_array($states) && count($states) === 37, 'States list contains exactly 37 entries (36 states + FCT)');
pv_assert(in_array('FCT - Abuja', $states, true), 'FCT - Abuja is in the states list');
pv_assert(in_array('Lagos', $states, true) && in_array('Kwara', $states, true) && in_array('Rivers', $states, true), 'Major states are in the list');

// Direct normalization
pv_assert(propvendor_marketplace_normalize_nigerian_state('Lagos') === 'Lagos', 'Normalizes exact state "Lagos"');
pv_assert(propvendor_marketplace_normalize_nigerian_state('  kwara  ') === 'Kwara', 'Normalizes case-insensitive trimmed "  kwara  "');
pv_assert(propvendor_marketplace_normalize_nigerian_state('enugu') === 'Enugu', 'Normalizes lowercase "enugu"');

// FCT / Abuja aliases
pv_assert(propvendor_marketplace_normalize_nigerian_state('abuja') === 'FCT - Abuja', 'Maps "abuja" to "FCT - Abuja"');
pv_assert(propvendor_marketplace_normalize_nigerian_state('FCT') === 'FCT - Abuja', 'Maps "FCT" to "FCT - Abuja"');
pv_assert(propvendor_marketplace_normalize_nigerian_state('Federal Capital Territory') === 'FCT - Abuja', 'Maps "Federal Capital Territory" to "FCT - Abuja"');
pv_assert(propvendor_marketplace_normalize_nigerian_state('Akwa-Ibom') === 'Akwa Ibom', 'Maps hyphenated "Akwa-Ibom"');
pv_assert(propvendor_marketplace_normalize_nigerian_state('Cross-River') === 'Cross River', 'Maps hyphenated "Cross-River"');

// Substring matching in legacy addresses
pv_assert(propvendor_marketplace_normalize_nigerian_state('Plot 12, Victoria Island, Lagos') === 'Lagos', 'Extracts state from "Plot 12, Victoria Island, Lagos"');
pv_assert(propvendor_marketplace_normalize_nigerian_state('Maitama District, Abuja') === 'FCT - Abuja', 'Extracts state from "Maitama District, Abuja"');

// Invalid states
pv_assert(propvendor_marketplace_normalize_nigerian_state('London') === '', 'Rejects foreign city "London"');
pv_assert(propvendor_marketplace_normalize_nigerian_state('California') === '', 'Rejects foreign state "California"');
pv_assert(propvendor_marketplace_normalize_nigerian_state('') === '', 'Rejects empty string');

// -------------------------------------------------------------
// 2. Plot Size Units Tests
// -------------------------------------------------------------
echo "\n--- TEST 2: Plot Size Units Definition & Normalization ---\n";
$plot_units = propvendor_marketplace_get_plot_size_units();
pv_assert(is_array($plot_units) && count($plot_units) === 5, 'Plot units contains exactly 5 standard Nigerian units');
pv_assert(isset($plot_units['sqm']) && isset($plot_units['plots']) && isset($plot_units['acres']) && isset($plot_units['hectares']) && isset($plot_units['sqft']), 'All 5 canonical unit keys exist');

pv_assert(propvendor_marketplace_normalize_plot_size_unit('plots') === 'plots', 'Normalizes "plots"');
pv_assert(propvendor_marketplace_normalize_plot_size_unit('Plots') === 'plots', 'Normalizes "Plots"');
pv_assert(propvendor_marketplace_normalize_plot_size_unit('Sqm (Square Meters)') === 'sqm', 'Normalizes "Sqm (Square Meters)"');
pv_assert(propvendor_marketplace_normalize_plot_size_unit('Sqm (m²)') === 'sqm', 'Normalizes "Sqm (m²)"');
pv_assert(propvendor_marketplace_normalize_plot_size_unit('square meters') === 'sqm', 'Normalizes "square meters"');
pv_assert(propvendor_marketplace_normalize_plot_size_unit('m²') === 'sqm', 'Normalizes "m²"');
pv_assert(propvendor_marketplace_normalize_plot_size_unit('acres') === 'acres', 'Normalizes "acres"');
pv_assert(propvendor_marketplace_normalize_plot_size_unit('Hectares') === 'hectares', 'Normalizes "Hectares"');
pv_assert(propvendor_marketplace_normalize_plot_size_unit('Sqft (Square Feet)') === 'sqft', 'Normalizes "Sqft (Square Feet)"');
pv_assert(propvendor_marketplace_normalize_plot_size_unit('Sqft (ft²)') === 'sqft', 'Normalizes "Sqft (ft²)"');
pv_assert(propvendor_marketplace_normalize_plot_size_unit('ft²') === 'sqft', 'Normalizes "ft²"');
pv_assert(propvendor_marketplace_normalize_plot_size_unit('liters') === '', 'Rejects invalid unit "liters"');

pv_assert(propvendor_marketplace_get_plot_size_unit_label('plots') === 'Plots', 'Formats unit label for "plots"');
pv_assert(propvendor_marketplace_get_plot_size_unit_label('sqm') === 'Sqm (Square Meters)', 'Formats unit label for "sqm"');
pv_assert(propvendor_marketplace_get_plot_size_unit_label('acres') === 'Acres', 'Formats unit label for "acres"');
pv_assert(propvendor_marketplace_get_plot_size_unit_label('sqft') === 'Sqft (Square Feet)', 'Formats unit label for "sqft"');

// -------------------------------------------------------------
// 3. Submission Validation & Persistence Tests
// -------------------------------------------------------------
echo "\n--- TEST 3: Submission Handler Validation & Persistence ---\n";

function pv_create_fresh_dummy_image($filename) {
    $path = __DIR__ . '/' . $filename;
    $png_1x1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    file_put_contents($path, $png_1x1);
    return array(
        'path' => $path,
        'files' => array(
            'propvendor_listing_images' => array(
                'name'     => array($filename),
                'type'     => array('image/png'),
                'tmp_name' => array($path),
                'error'    => array(0),
                'size'     => array(strlen($png_1x1)),
            )
        )
    );
}

$test_user = get_user_by('login', 'test_vendor_location_schema');
if (!$test_user) {
    $user_id = wp_create_user('test_vendor_location_schema', 'StrongPassword123!', 'vendor_loc_schema@propvendor.ng');
    $test_user = get_user_by('id', $user_id);
    $test_user->set_role('vendor');
    $test_user->add_cap('propvendor_submit_marketplace_listings');
}
wp_set_current_user($test_user->ID);

// 3.1 Invalid state validation (validation triggers before image check)
$res_bad_state = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'property_listing',
    'propvendor_title'        => 'Test Bad State Listing',
    'propvendor_description'  => 'Description for bad state test.',
    'propvendor_price'        => 50000000,
    'propvendor_location'     => 'Berlin',
    'propvendor_full_address' => 'Alexanderplatz 1',
), array(), $test_user->ID);
pv_assert(is_wp_error($res_bad_state) && $res_bad_state->get_error_code() === 'invalid_location_state', 'Rejects submission with invalid state');

// 3.2 Property missing address validation
$res_missing_addr = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'property_listing',
    'propvendor_title'        => 'Test Missing Address Property',
    'propvendor_description'  => 'Description for missing address property.',
    'propvendor_price'        => 50000000,
    'propvendor_location'     => 'Lagos',
    'propvendor_full_address' => '',
), array(), $test_user->ID);
pv_assert(is_wp_error($res_missing_addr) && $res_missing_addr->get_error_code() === 'missing_address', 'Rejects property listing with missing address');

// 3.3 Land missing address validation
$res_missing_land_addr = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'land_listing',
    'propvendor_title'        => 'Test Missing Address Land',
    'propvendor_description'  => 'Description for missing address land.',
    'propvendor_price'        => 20000000,
    'propvendor_location'     => 'Ogun',
    'propvendor_full_address' => '   ',
), array(), $test_user->ID);
pv_assert(is_wp_error($res_missing_land_addr) && $res_missing_land_addr->get_error_code() === 'missing_address', 'Rejects land listing with missing address');

// 3.4 Address exceeding 255 chars validation
$long_addr = str_repeat('A', 256);
$res_long_addr = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'property_listing',
    'propvendor_title'        => 'Test Address Too Long',
    'propvendor_description'  => 'Description for address too long test.',
    'propvendor_price'        => 50000000,
    'propvendor_location'     => 'Lagos',
    'propvendor_full_address' => $long_addr,
), array(), $test_user->ID);
pv_assert(is_wp_error($res_long_addr) && $res_long_addr->get_error_code() === 'address_too_long', 'Rejects address longer than 255 characters');

// 3.5 Vehicle with empty address succeeds (address is optional for vehicles)
$f_veh = pv_create_fresh_dummy_image('test_veh_ok.png');
$res_veh_ok = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'vehicle_listing',
    'propvendor_title'        => 'Test Vehicle Optional Address',
    'propvendor_description'  => 'Immaculate vehicle in great condition.',
    'propvendor_price'        => 12000000,
    'propvendor_location'     => 'FCT - Abuja',
    'propvendor_full_address' => '',
    'propvendor_vehicle_make' => 'Toyota',
), $f_veh['files'], $test_user->ID);
pv_assert(!is_wp_error($res_veh_ok) && !empty($res_veh_ok['post_id']), 'Vehicle listing succeeds with empty address', is_wp_error($res_veh_ok) ? $res_veh_ok->get_error_message() : '');
$veh_id = !is_wp_error($res_veh_ok) ? $res_veh_ok['post_id'] : 0;
if ($veh_id) {
    pv_assert(get_post_meta($veh_id, 'propvendor_location', true) === 'FCT - Abuja', 'Vehicle location saved as canonical state');
    pv_assert(get_post_meta($veh_id, 'propvendor_state', true) === 'FCT - Abuja', 'Vehicle state saved as canonical state');
    pv_assert(get_post_meta($veh_id, 'propvendor_full_address', true) === '', 'Vehicle full address saved as empty string');
}

// 3.6 Property listing with valid state, full address, and plot unit succeeds and persists metadata
$f_prop = pv_create_fresh_dummy_image('test_prop_ok.png');
$res_prop_ok = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type'      => 'property_listing',
    'propvendor_title'             => 'Exquisite 5 Bedroom Mansion',
    'propvendor_description'       => 'Luxury architectural masterpiece in Lekki Phase 1.',
    'propvendor_price'             => 150000000,
    'propvendor_location'          => 'Lagos',
    'propvendor_full_address'      => '14 Admiralty Way, Lekki Phase 1',
    'propvendor_property_subtype'  => 'Detached House',
    'propvendor_plot_size'         => '2',
    'propvendor_plot_size_unit'    => 'plots',
), $f_prop['files'], $test_user->ID);
pv_assert(!is_wp_error($res_prop_ok) && !empty($res_prop_ok['post_id']), 'Property listing created successfully with address and state', is_wp_error($res_prop_ok) ? $res_prop_ok->get_error_message() : '');
$prop_id = !is_wp_error($res_prop_ok) ? $res_prop_ok['post_id'] : 0;
if ($prop_id) {
    pv_assert(get_post_meta($prop_id, 'propvendor_location', true) === 'Lagos', 'Property location saved as "Lagos"');
    pv_assert(get_post_meta($prop_id, 'propvendor_state', true) === 'Lagos', 'Property state saved as "Lagos"');
    pv_assert(get_post_meta($prop_id, 'propvendor_full_address', true) === '14 Admiralty Way, Lekki Phase 1', 'Property full address saved accurately');
    pv_assert(get_post_meta($prop_id, 'propvendor_plot_size', true) === '2', 'Plot size saved');
    pv_assert(get_post_meta($prop_id, 'propvendor_plot_size_unit', true) === 'plots', 'Plot size unit saved normalized as "plots"');
}

// 3.7 Land listing with unit normalization
$f_land = pv_create_fresh_dummy_image('test_land_ok.png');
$res_land_ok = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type'      => 'land_listing',
    'propvendor_title'             => 'Prime Commercial Land',
    'propvendor_description'       => 'Strategic commercial plot along expressway.',
    'propvendor_price'             => 75000000,
    'propvendor_location'          => 'Ogun',
    'propvendor_full_address'      => 'Km 42, Lagos-Ibadan Expressway',
    'propvendor_land_use'          => 'Commercial',
    'propvendor_plot_size'         => '5',
    'propvendor_plot_size_unit'    => 'Acres',
), $f_land['files'], $test_user->ID);
pv_assert(!is_wp_error($res_land_ok) && !empty($res_land_ok['post_id']), 'Land listing created successfully', is_wp_error($res_land_ok) ? $res_land_ok->get_error_message() : '');
$land_id = !is_wp_error($res_land_ok) ? $res_land_ok['post_id'] : 0;
if ($land_id) {
    pv_assert(get_post_meta($land_id, 'propvendor_location', true) === 'Ogun', 'Land location saved as "Ogun"');
    pv_assert(get_post_meta($land_id, 'propvendor_full_address', true) === 'Km 42, Lagos-Ibadan Expressway', 'Land address saved');
    pv_assert(get_post_meta($land_id, 'propvendor_plot_size_unit', true) === 'acres', 'Land plot unit normalized from "Acres" to "acres"');
}

// -------------------------------------------------------------
// 4. Single Listing Detail Output Test
// -------------------------------------------------------------
echo "\n--- TEST 4: Single Listing Details Rendering ---\n";
if ($prop_id) {
    // Publish listing for public detail check
    wp_update_post(array('ID' => $prop_id, 'post_status' => 'publish'));
    update_post_meta($prop_id, 'propvendor_listing_status', 'Published');
    update_post_meta($prop_id, 'propvendor_status', 'Published');

    $post_prop = get_post($prop_id);
    setup_mock_singular_context($post_prop);

    $rendered = propvendor_marketplace_render_listing_details('<p>Listing original content</p>');
    pv_assert(strpos($rendered, 'State') !== false && strpos($rendered, 'Lagos') !== false, 'Rendered details include State: Lagos');
    pv_assert(strpos($rendered, 'Address') !== false && strpos($rendered, '14 Admiralty Way, Lekki Phase 1') !== false, 'Rendered details include Address');
    pv_assert(strpos($rendered, 'Plot size') !== false && strpos($rendered, '2 Plots') !== false, 'Rendered details include formatted Plot size "2 Plots"');
}

// -------------------------------------------------------------
// 5. Admin Meta Boxes Save Test
// -------------------------------------------------------------
echo "\n--- TEST 5: Admin Meta Box Save Handler ---\n";
if ($prop_id) {
    // Setup admin user
    $admin_user = get_user_by('login', 'admin');
    if (!$admin_user) {
        $admins = get_users(array('role' => 'administrator', 'number' => 1));
        $admin_user = !empty($admins) ? $admins[0] : null;
    }
    if ($admin_user) {
        wp_set_current_user($admin_user->ID);
    }

    $_POST['propvendor_listing_meta_nonce'] = wp_create_nonce('propvendor_listing_meta_nonce');
    $_POST['propvendor_price'] = '160000000';
    $_POST['propvendor_location'] = 'Oyo';
    $_POST['propvendor_full_address'] = 'Bodija Estate, Ibadan';
    $_POST['propvendor_plot_size'] = '1200';
    $_POST['propvendor_plot_size_unit'] = 'Sqm (m²)';

    propvendor_marketplace_save_meta_boxes($prop_id);

    pv_assert(get_post_meta($prop_id, 'propvendor_location', true) === 'Oyo', 'Admin save updated location to "Oyo"');
    pv_assert(get_post_meta($prop_id, 'propvendor_state', true) === 'Oyo', 'Admin save updated state to "Oyo"');
    pv_assert(get_post_meta($prop_id, 'propvendor_full_address', true) === 'Bodija Estate, Ibadan', 'Admin save updated full address');
    pv_assert(get_post_meta($prop_id, 'propvendor_plot_size_unit', true) === 'sqm', 'Admin save normalized plot unit to "sqm"');

    // Clean up POST superglobal
    unset($_POST['propvendor_listing_meta_nonce'], $_POST['propvendor_price'], $_POST['propvendor_location'], $_POST['propvendor_full_address'], $_POST['propvendor_plot_size'], $_POST['propvendor_plot_size_unit']);
}

// -------------------------------------------------------------
// 6. Form Shortcode Rendering & Legacy Warning Test
// -------------------------------------------------------------
echo "\n--- TEST 6: Form Shortcode State Dropdown & Legacy Warning ---\n";
// Create a listing with legacy unmapped location
$legacy_post_id = wp_insert_post(array(
    'post_title'   => 'Legacy Location Property',
    'post_type'    => 'property_listing',
    'post_status'  => 'draft',
    'post_author'  => $test_user->ID,
));
update_post_meta($legacy_post_id, 'propvendor_location', 'Some Custom Old Town');

wp_set_current_user($test_user->ID);
$_GET['propvendor_edit_listing'] = $legacy_post_id;
$edit_html = propvendor_marketplace_submit_listing_form_shortcode();

pv_assert(strpos($edit_html, '<select name="propvendor_location"') !== false, 'Edit form renders state select dropdown');
pv_assert(strpos($edit_html, 'propvendor-legacy-warning') !== false, 'Edit form displays legacy warning when location is unmapped');
pv_assert(strpos($edit_html, 'Some Custom Old Town') !== false, 'Legacy warning references the unmapped location name');
pv_assert(strpos($edit_html, 'name="propvendor_full_address"') !== false, 'Edit form renders propvendor_full_address input');

unset($_GET['propvendor_edit_listing']);

// -------------------------------------------------------------
// 7. Cleanup Test Listings & Attachments
// -------------------------------------------------------------
echo "\n--- CLEANUP ---\n";
$posts_to_clean = array_filter(array($veh_id, $prop_id, $land_id, $legacy_post_id));
foreach ($posts_to_clean as $pid) {
    $att_ids = propvendor_marketplace_get_listing_image_ids($pid);
    foreach ($att_ids as $aid) {
        wp_delete_attachment($aid, true);
    }
    wp_delete_post($pid, true);
}

echo "\n=======================================================\n";
echo "SUMMARY: {$pass_count} PASSED, {$fail_count} FAILED\n";
echo "=======================================================\n";

if ($fail_count > 0) {
    exit(1);
}
exit(0);
