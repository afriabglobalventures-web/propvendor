<?php
/**
 * PropVendor - Dynamic Category Forms & Multi-Image Upload Verification Test
 */

// Define WordPress execution environment
define('WP_USE_THEMES', false);
require_once 'c:/xampp/htdocs/Propvendor/wp-load.php';

echo "=======================================================\n";
echo "PROPVENDOR DYNAMIC FORMS & MULTI-IMAGE VERIFICATION TEST\n";
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

// 1. Verify Category Helper Functions
echo "--- TEST 1: Helper Functions & Options ---\n";
$prop_subtypes = propvendor_marketplace_get_property_subtypes();
pv_assert(is_array($prop_subtypes) && in_array('Flat / Apartment', $prop_subtypes, true), 'Property subtypes helper returns expected array');

$land_uses = propvendor_marketplace_get_land_uses();
pv_assert(is_array($land_uses) && in_array('Residential', $land_uses, true), 'Land uses helper returns expected array');

$veh_subtypes = propvendor_marketplace_get_vehicle_subtypes();
pv_assert(is_array($veh_subtypes) && in_array('Car / Sedan', $veh_subtypes, true), 'Vehicle subtypes helper returns expected array');

$prop_keys = propvendor_marketplace_get_category_meta_keys('property_listing');
pv_assert(in_array('propvendor_property_subtype', $prop_keys, true) && in_array('propvendor_floor_area', $prop_keys, true), 'Property meta keys list correct');

$land_keys = propvendor_marketplace_get_category_meta_keys('land_listing');
pv_assert(in_array('propvendor_land_use', $land_keys, true) && in_array('propvendor_land_length', $land_keys, true), 'Land meta keys list correct');

$veh_keys = propvendor_marketplace_get_category_meta_keys('vehicle_listing');
pv_assert(in_array('propvendor_vehicle_model', $veh_keys, true) && in_array('propvendor_mileage', $veh_keys, true), 'Vehicle meta keys list correct');


// Setup Test Vendor User
$test_user = get_user_by('login', 'test_vendor_dynamic');
if (!$test_user) {
    $user_id = wp_create_user('test_vendor_dynamic', 'StrongPassword123!', 'vendor_dynamic_test@propvendor.ng');
    $test_user = get_user_by('id', $user_id);
    $test_user->set_role('vendor');
    $test_user->add_cap('propvendor_submit_marketplace_listings');
}
wp_set_current_user($test_user->ID);

// Clean up any leftover test posts from previous runs so quota is clear
$old_test_posts = get_posts(array(
    'post_type'      => propvendor_marketplace_get_listing_post_types(),
    'author'         => $test_user->ID,
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
));
foreach ($old_test_posts as $opid) {
    wp_delete_post($opid, true);
}
propvendor_marketplace_invalidate_listing_count_cache($test_user->ID);


// 2. Verify Form Shortcode Rendering
echo "\n--- TEST 2: Shortcode Markup Rendering ---\n";
$form_html = propvendor_marketplace_submit_listing_form_shortcode();

pv_assert(strpos($form_html, 'id="propvendor-fields-property"') !== false, 'Form contains property category section');
pv_assert(strpos($form_html, 'id="propvendor-fields-land"') !== false, 'Form contains land category section');
pv_assert(strpos($form_html, 'id="propvendor-fields-vehicle"') !== false, 'Form contains vehicle category section');
pv_assert(strpos($form_html, 'name="propvendor_listing_images[]"') !== false, 'Form contains multiple image upload input');
pv_assert(strpos($form_html, 'id="propvendor-image-preview-grid"') !== false, 'Form contains preview grid container');
pv_assert(strpos($form_html, 'name="propvendor_floor_area"') !== false, 'Form contains floor area input');
pv_assert(strpos($form_html, 'name="propvendor_land_length"') !== false, 'Form contains land dimensions input');
pv_assert(strpos($form_html, 'name="propvendor_mileage"') !== false, 'Form contains vehicle mileage input');


// Helper function to create fresh dummy test image for upload simulation without GD dependency
function pv_create_test_image($filename) {
    $path = __DIR__ . '/' . $filename;
    $png_1x1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    file_put_contents($path, $png_1x1);
    return $path;
}

$dummy_image_path = pv_create_test_image('test_sample_image.png');

$test_file_array = array(
    'name'     => 'test_sample_image.png',
    'type'     => 'image/png',
    'tmp_name' => $dummy_image_path,
    'error'    => 0,
    'size'     => filesize($dummy_image_path),
);

// 3. Verify Property Listing Processing
echo "\n--- TEST 3: Property Listing Submission & Metadata Storage ---\n";
$prop_data = array(
    'propvendor_listing_type'      => 'property_listing',
    'propvendor_title'             => 'Luxury 4 Bedroom Detached House',
    'propvendor_price'             => 85000000,
    'propvendor_location'          => 'Lekki Phase 1, Lagos',
    'propvendor_description'       => 'Magnificent detached duplex with modern finishes.',
    'propvendor_property_subtype'  => 'Detached House',
    'propvendor_purpose'           => 'Sale',
    'propvendor_bedrooms'          => 4,
    'propvendor_bathrooms'         => 5,
    'propvendor_furnishing'        => 'Semi-Furnished',
    'propvendor_title_document'    => "Governor's Consent",
    'propvendor_floor_area'        => 380,
    'propvendor_floor_area_unit'   => 'sqm',
    'propvendor_plot_size'         => 600,
    'propvendor_plot_size_unit'    => 'sqm',
    'propvendor_is_direct_owner'   => '1',
    // Stale fields from other categories that should NOT be saved
    'propvendor_land_use'          => 'Industrial',
    'propvendor_vehicle_make'      => 'Toyota',
);

$files_single = array(
    'propvendor_listing_images' => array(
        'name'     => array('test_sample_image.png'),
        'type'     => array('image/png'),
        'tmp_name' => array($dummy_image_path),
        'error'    => array(0),
        'size'     => array(filesize($dummy_image_path)),
    )
);

$prop_res = propvendor_marketplace_process_listing_submission($prop_data, $files_single, $test_user->ID, 0);
pv_assert(!is_wp_error($prop_res) && !empty($prop_res['post_id']), 'Property listing created successfully');

$prop_id = is_wp_error($prop_res) ? 0 : $prop_res['post_id'];
if ($prop_id) {
    pv_assert(get_post_meta($prop_id, 'propvendor_property_subtype', true) === 'Detached House', 'Property subtype saved correctly');
    pv_assert((int) get_post_meta($prop_id, 'propvendor_bedrooms', true) === 4, 'Bedrooms saved correctly');
    pv_assert((float) get_post_meta($prop_id, 'propvendor_floor_area', true) === 380.0, 'Floor area saved correctly');
    pv_assert(get_post_meta($prop_id, 'propvendor_floor_area_unit', true) === 'sqm', 'Floor area unit saved correctly');
    pv_assert(get_post_meta($prop_id, 'propvendor_title_document', true) === "Governor's Consent", 'Title doc saved correctly');
    // Obsolete keys must NOT be present
    pv_assert(get_post_meta($prop_id, 'propvendor_land_use', true) === '', 'Irrelevant land_use key NOT saved');
    pv_assert(get_post_meta($prop_id, 'propvendor_vehicle_make', true) === '', 'Irrelevant vehicle_make key NOT saved');
    // Owner direct claim status must be pending
    pv_assert(get_post_meta($prop_id, '_propvendor_direct_owner_status', true) === 'pending', 'Owner-direct status set to pending');
}


// 4. Verify Land Listing Processing & Obsolete Cleanup
echo "\n--- TEST 4: Land Listing Submission & Dimensions Storage ---\n";
$dummy_image_land = pv_create_test_image('test_sample_land.png');


$land_data = array(
    'propvendor_listing_type'      => 'land_listing',
    'propvendor_title'             => 'Prime Commercial Land along Lekki-Epe Expressway',
    'propvendor_price'             => 120000000,
    'propvendor_location'          => 'Ibeju-Lekki, Lagos',
    'propvendor_description'       => '100% dry land facing expressway.',
    'propvendor_plot_size'         => '2',
    'propvendor_plot_size_unit'    => 'acres',
    'propvendor_land_use'          => 'Commercial',
    'propvendor_title_document'    => 'Certificate of Occupancy (C of O)',
    'propvendor_land_length'       => '120',
    'propvendor_land_width'        => '65',
    'propvendor_dimension_unit'    => 'm',
    'propvendor_frontage'          => '65m frontage on express',
    // Irrelevant fields
    'propvendor_bedrooms'          => 3,
    'propvendor_vehicle_year'      => 2020,
);

$files_land = array(
    'propvendor_listing_images' => array(
        'name'     => array('test_sample_land.png'),
        'type'     => array('image/png'),
        'tmp_name' => array($dummy_image_land),
        'error'    => array(0),
        'size'     => array(filesize($dummy_image_land)),
    )
);

$land_res = propvendor_marketplace_process_listing_submission($land_data, $files_land, $test_user->ID, 0);
pv_assert(!is_wp_error($land_res) && !empty($land_res['post_id']), 'Land listing created successfully');

$land_id = is_wp_error($land_res) ? 0 : $land_res['post_id'];
if ($land_id) {
    pv_assert(get_post_meta($land_id, 'propvendor_land_use', true) === 'Commercial', 'Land use saved correctly');
    pv_assert(get_post_meta($land_id, 'propvendor_land_length', true) === '120', 'Land length saved correctly');
    pv_assert(get_post_meta($land_id, 'propvendor_land_width', true) === '65', 'Land width saved correctly');
    pv_assert(get_post_meta($land_id, 'propvendor_dimension_unit', true) === 'm', 'Dimension unit saved correctly');
    pv_assert(get_post_meta($land_id, 'propvendor_frontage', true) === '65m frontage on express', 'Frontage saved correctly');
    // Obsolete check
    pv_assert(get_post_meta($land_id, 'propvendor_bedrooms', true) === '', 'Irrelevant bedrooms key NOT saved');
    pv_assert(get_post_meta($land_id, 'propvendor_vehicle_year', true) === '', 'Irrelevant vehicle_year key NOT saved');
}


// 5. Verify Vehicle Listing Processing
echo "\n--- TEST 5: Vehicle Listing Submission ---\n";
$dummy_image_veh = pv_create_test_image('test_sample_veh.png');

$veh_data = array(
    'propvendor_listing_type'      => 'vehicle_listing',
    'propvendor_title'             => '2021 Toyota RAV4 XLE AWD',
    'propvendor_price'             => 32000000,
    'propvendor_location'          => 'Ikeja, Lagos',
    'propvendor_description'       => 'Clean Tokunbo vehicle, full option.',
    'propvendor_vehicle_subtype'   => 'SUV',
    'propvendor_vehicle_make'      => 'Toyota',
    'propvendor_vehicle_model'     => 'RAV4',
    'propvendor_vehicle_year'      => 2021,
    'propvendor_condition'         => 'Foreign Used / Tokunbo',
    'propvendor_transmission'      => 'Automatic',
    'propvendor_mileage'           => 45000,
    // Irrelevant
    'propvendor_floor_area'        => 200,
);

$files_veh = array(
    'propvendor_listing_images' => array(
        'name'     => array('test_sample_veh.png'),
        'type'     => array('image/png'),
        'tmp_name' => array($dummy_image_veh),
        'error'    => array(0),
        'size'     => array(filesize($dummy_image_veh)),
    )
);

$veh_res = propvendor_marketplace_process_listing_submission($veh_data, $files_veh, $test_user->ID, 0);
pv_assert(!is_wp_error($veh_res) && !empty($veh_res['post_id']), 'Vehicle listing created successfully');

$veh_id = is_wp_error($veh_res) ? 0 : $veh_res['post_id'];
if ($veh_id) {
    pv_assert(get_post_meta($veh_id, 'propvendor_vehicle_subtype', true) === 'SUV', 'Vehicle subtype saved correctly');
    pv_assert(get_post_meta($veh_id, 'propvendor_vehicle_make', true) === 'Toyota', 'Vehicle make saved correctly');
    pv_assert(get_post_meta($veh_id, 'propvendor_vehicle_model', true) === 'RAV4', 'Vehicle model saved correctly');
    pv_assert((int) get_post_meta($veh_id, 'propvendor_vehicle_year', true) === 2021, 'Vehicle year saved correctly');
    pv_assert(get_post_meta($veh_id, 'propvendor_condition', true) === 'Foreign Used / Tokunbo', 'Vehicle condition saved correctly');
    pv_assert((int) get_post_meta($veh_id, 'propvendor_mileage', true) === 45000, 'Mileage saved correctly');
    pv_assert(get_post_meta($veh_id, 'propvendor_floor_area', true) === '', 'Irrelevant floor_area key NOT saved');
}


// 6. Verify Edit Mode Image Management & Primary Thumbnail Selection
echo "\n--- TEST 6: Edit Mode Image Management ---\n";
if ($prop_id) {
    $existing_images = propvendor_marketplace_get_listing_image_ids($prop_id);
    pv_assert(!empty($existing_images), 'Initial image attached to property listing');

    // Add a second image
    $dummy_image_second = pv_create_test_image('test_sample_second.png');

    $edit_data = $prop_data;
    $edit_data['propvendor_title'] = 'Luxury 4 Bedroom Detached House (Updated)';
    $files_second = array(
        'propvendor_listing_images' => array(
            'name'     => array('test_sample_second.png'),
            'type'     => array('image/png'),
            'tmp_name' => array($dummy_image_second),
            'error'    => array(0),
            'size'     => array(filesize($dummy_image_second)),
        )
    );

    $update_res = propvendor_marketplace_process_listing_submission($edit_data, $files_second, $test_user->ID, $prop_id);
    pv_assert(!is_wp_error($update_res), 'Listing updated with second image');

    $all_prop_images = propvendor_marketplace_get_listing_image_ids($prop_id);
    pv_assert(count($all_prop_images) >= 2, 'Listing now has at least 2 images attached');

    $second_image_id = end($all_prop_images);

    // Switch primary thumbnail to second image and delete first image
    $first_image_id = reset($all_prop_images);
    $edit_data_switch = $edit_data;
    $edit_data_switch['propvendor_primary_image_id'] = $second_image_id;
    $edit_data_switch['propvendor_delete_images'] = array($first_image_id);

    $switch_res = propvendor_marketplace_process_listing_submission($edit_data_switch, array(), $test_user->ID, $prop_id);
    pv_assert(!is_wp_error($switch_res), 'Listing updated with image deletion and primary switch');

    $new_thumb_id = (int) get_post_thumbnail_id($prop_id);
    pv_assert($new_thumb_id === $second_image_id, 'Primary thumbnail switched to second image');

    $updated_images = propvendor_marketplace_get_listing_image_ids($prop_id);
    pv_assert(!in_array($first_image_id, $updated_images, true), 'First image successfully removed from listing');
}


// 7. Verify Admin Meta Box Rendering & Category Presentation
echo "\n--- TEST 7: Admin Meta Box Rendering & Category Presentation ---\n";
if ($veh_id) {
    $veh_post = get_post($veh_id);
    ob_start();
    propvendor_marketplace_render_meta_box($veh_post);
    $meta_html = ob_get_clean();

    pv_assert(strpos($meta_html, 'Toyota') !== false, 'Admin meta box renders saved Vehicle Make');
    pv_assert(strpos($meta_html, 'RAV4') !== false, 'Admin meta box renders saved Vehicle Model');
    pv_assert(strpos($meta_html, '45000') !== false, 'Admin meta box renders saved Mileage');
    pv_assert(strpos($meta_html, 'Foreign Used / Tokunbo') !== false, 'Admin meta box renders saved Vehicle Condition');
}

$anti_fraud_notice = propvendor_marketplace_render_anti_fraud_notice();
pv_assert(strpos($anti_fraud_notice, 'inspection fees') !== false || strpos($anti_fraud_notice, 'transfer money') !== false, 'Anti-fraud trust notice renders correctly');


$veh_notice = propvendor_marketplace_render_vehicle_legal_notice();
pv_assert(strpos($veh_notice, 'CMRIS') !== false, 'Vehicle CMRIS legal notice renders correctly');

// 8. Regression Check: Phase 3.1 & Phase 3.2 Lead Masking & Stage 2 Permissions
echo "\n--- TEST 8: Regression Checks (Contact Masking & Direct Owner) ---\n";
// Verify lead masking entitlement helper exists and free vendor without unlock returns false
if (function_exists('propvendor_marketplace_vendor_can_view_contact_info')) {
    $can_view = propvendor_marketplace_vendor_can_view_contact_info($test_user->ID, 999999);
    pv_assert($can_view === false, 'Free vendor cannot view un-entitled contact info (Phase 3.1)');
}

// Verify mask function works
if (function_exists('propvendor_marketplace_mask_phone')) {
    $masked = propvendor_marketplace_mask_phone('+2348012345678');
    pv_assert(strpos($masked, '***') !== false, 'Phone masking helper operational');
}



// Cleanup Test Posts
echo "\n--- CLEANUP ---\n";
if ($prop_id) wp_delete_post($prop_id, true);
if ($land_id) wp_delete_post($land_id, true);
if ($veh_id)  wp_delete_post($veh_id, true);
if (file_exists($dummy_image_path)) @unlink($dummy_image_path);
if (file_exists(__DIR__ . '/test_sample_land.png')) @unlink(__DIR__ . '/test_sample_land.png');
if (file_exists(__DIR__ . '/test_sample_veh.png')) @unlink(__DIR__ . '/test_sample_veh.png');
if (file_exists(__DIR__ . '/test_sample_second.png')) @unlink(__DIR__ . '/test_sample_second.png');

echo "\n=======================================================\n";
echo "SUMMARY: {$pass_count} PASSED, {$fail_count} FAILED\n";
echo "=======================================================\n";

if ($fail_count > 0) {
    exit(1);
}
exit(0);
