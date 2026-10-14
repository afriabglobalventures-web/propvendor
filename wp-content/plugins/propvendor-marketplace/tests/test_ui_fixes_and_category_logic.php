<?php
/**
 * Test Suite: Sections 2, 3, & 4 Implementation Verification
 * (Standardized Units, Placeholders & Microcopy, Dynamic Category Isolation)
 */

define('WP_USE_THEMES', false);
require_once 'c:/xampp/htdocs/Propvendor/wp-load.php';

echo "====================================================================\n";
echo "  PROPVENDOR SECTIONS 2, 3, & 4 VERIFICATION TEST SUITE             \n";
echo "====================================================================\n\n";

$pass_count = 0;
$fail_count = 0;

function run_test($condition, $name, $extra = '') {
    global $pass_count, $fail_count;
    if ($condition) {
        echo " [PASS] $name\n";
        $pass_count++;
    } else {
        echo " [FAIL] $name" . ($extra ? " ($extra)" : "") . "\n";
        $fail_count++;
    }
}

// Ensure an authorized vendor user is active for shortcode rendering
$vendor_test = get_user_by('login', 'test_vendor_sec4');
if (!$vendor_test) {
    $uid = wp_create_user('test_vendor_sec4', 'pass12345678', 'vendor_sec4@propvendor.ng');
    $vendor_test = get_user_by('id', $uid);
    $vendor_test->set_role('vendor');
    $vendor_test->add_cap('propvendor_submit_marketplace_listings');
}
wp_set_current_user($vendor_test->ID);

// -------------------------------------------------------------------------
// SECTION 2: Standardized Unit Labels
// -------------------------------------------------------------------------
echo "--- SECTION 2: Standardized Unit Labels ---\n";

$plot_units = propvendor_marketplace_get_plot_size_units();
run_test($plot_units['sqm'] === 'Sqm (Square Meters)', "sqm unit label is 'Sqm (Square Meters)'");
run_test($plot_units['plots'] === 'Plots', "plots unit label is 'Plots'");
run_test($plot_units['acres'] === 'Acres', "acres unit label is 'Acres'");
run_test($plot_units['hectares'] === 'Hectares', "hectares unit label is 'Hectares'");
run_test($plot_units['sqft'] === 'Sqft (Square Feet)', "sqft unit label is 'Sqft (Square Feet)'");

// Verify raw mathematical symbols are not present in labels
foreach ($plot_units as $key => $label) {
    run_test(strpos($label, 'm²') === false && strpos($label, 'ft²') === false, "Unit '$key' does not contain raw symbols m² or ft²");
}

// Form shortcode rendering checks for unit options
$_GET = array();
$html_new = propvendor_marketplace_submit_listing_form_shortcode();
run_test(strpos($html_new, 'Sqm (Square Meters)') !== false, "Form renders 'Sqm (Square Meters)'");
run_test(strpos($html_new, 'Sqft (Square Feet)') !== false, "Form renders 'Sqft (Square Feet)'");
run_test(strpos($html_new, 'Meters (m)') !== false, "Form renders 'Meters (m)' for land dimension unit");
run_test(strpos($html_new, 'Feet (ft)') !== false, "Form renders 'Feet (ft)' for land dimension unit");

// -------------------------------------------------------------------------
// SECTION 3: Field Placeholders, Hints & Microcopy
// -------------------------------------------------------------------------
echo "\n--- SECTION 3: Field Placeholders, Hints & Microcopy ---\n";

// General fields
run_test(strpos($html_new, 'Give your listing a clear, descriptive heading') !== false, "Listing title contains hint microcopy");
run_test(strpos($html_new, 'e.g., Luxury 4 Bedroom Detached Duplex with Swimming Pool') !== false, "Default property title placeholder rendered");
run_test(strpos($html_new, 'placeholder="e.g., 45000000"') !== false, "Price field contains placeholder 'e.g., 45000000'");
run_test(strpos($html_new, 'Select State') !== false, "State field contains default option 'Select State'");
run_test(strpos($html_new, 'Street, area, estate, or landmark details') !== false, "Address field contains hint microcopy");
run_test(strpos($html_new, 'placeholder="e.g., House 14, Admiralty Way, Lekki Phase 1"') !== false, "Address field contains property placeholder");

// Property category fields
run_test(strpos($html_new, 'Select Property Subtype') !== false, "Property subtype default option is 'Select Property Subtype'");
run_test(strpos($html_new, 'Floor Area (Interior Space)') !== false, "Floor area label is 'Floor Area (Interior Space)'");
run_test(strpos($html_new, 'Total indoor living or usable space') !== false, "Floor area contains hint microcopy");
run_test(strpos($html_new, 'name="propvendor_floor_area" value="" placeholder="e.g., 350"') !== false, "Floor area has placeholder 'e.g., 350'");
run_test(strpos($html_new, 'Plot Size / Compound Size (Optional)') !== false, "Property plot size label is 'Plot Size / Compound Size (Optional)'");
run_test(strpos($html_new, 'Total compound or land area the property sits on') !== false, "Property plot size contains hint microcopy");
run_test(strpos($html_new, 'name="propvendor_bedrooms" min="0" value="" placeholder="e.g., 4"') !== false, "Bedrooms has placeholder 'e.g., 4'");
run_test(strpos($html_new, 'name="propvendor_bathrooms" min="0" value="" placeholder="e.g., 5"') !== false, "Bathrooms has placeholder 'e.g., 5'");

// Land category fields
run_test(strpos($html_new, 'Select Land Use') !== false, "Land use default option is 'Select Land Use'");
run_test(strpos($html_new, 'Total land area') !== false, "Land plot size contains hint microcopy");
run_test(strpos($html_new, 'Select Title Document') !== false, "Title document default option is 'Select Title Document'");
run_test(strpos($html_new, 'placeholder="e.g., 100"') !== false, "Land length has placeholder 'e.g., 100'");
run_test(strpos($html_new, 'placeholder="e.g., 60"') !== false, "Land width has placeholder 'e.g., 60'");
run_test(strpos($html_new, 'placeholder="e.g., 50m frontage"') !== false, "Road frontage has placeholder 'e.g., 50m frontage'");

// Vehicle category fields
run_test(strpos($html_new, 'Select Vehicle Type') !== false, "Vehicle subtype default option is 'Select Vehicle Type'");
run_test(strpos($html_new, 'placeholder="e.g., Toyota"') !== false, "Vehicle make has placeholder 'e.g., Toyota'");
run_test(strpos($html_new, 'placeholder="e.g., Camry"') !== false, "Vehicle model has placeholder 'e.g., Camry'");
run_test(strpos($html_new, 'Year of Manufacture') !== false, "Vehicle year label is 'Year of Manufacture'");
run_test(strpos($html_new, 'placeholder="e.g., 2020"') !== false, "Vehicle year has placeholder 'e.g., 2020'");
run_test(strpos($html_new, 'Current odometer reading in kilometers') !== false, "Vehicle mileage contains hint microcopy");
run_test(strpos($html_new, 'placeholder="e.g., 45000"') !== false, "Vehicle mileage has placeholder 'e.g., 45000'");
run_test(strpos($html_new, 'Select Condition') !== false, "Vehicle condition default option is 'Select Condition'");
run_test(strpos($html_new, 'Select Transmission') !== false, "Vehicle transmission default option is 'Select Transmission'");

// -------------------------------------------------------------------------
// SECTION 4: Category Isolation & Server-Side Persistence
// -------------------------------------------------------------------------
echo "\n--- SECTION 4: Category Isolation & Metadata Persistence ---\n";

$dummy_png = __DIR__ . '/test_sec4_img.png';
$png_1x1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
file_put_contents($dummy_png, $png_1x1);

$files_payload = array(
    'propvendor_listing_images' => array(
        'name'     => array('test_sec4_img.png'),
        'type'     => array('image/png'),
        'tmp_name' => array($dummy_png),
        'error'    => array(UPLOAD_ERR_OK),
        'size'     => array(filesize($dummy_png)),
    ),
);

// 1. Property Listing: submission & persistence
$prop_data = array(
    'propvendor_listing_type'     => 'property_listing',
    'propvendor_title'            => 'Luxury 4 Bedroom Detached Duplex with Swimming Pool',
    'propvendor_price'            => 120000000,
    'propvendor_location'         => 'Lagos',
    'propvendor_full_address'     => 'House 14, Admiralty Way, Lekki Phase 1',
    'propvendor_description'      => 'A magnificent duplex in prime Lekki.',
    'propvendor_property_subtype' => 'Duplex',
    'propvendor_bedrooms'         => 4,
    'propvendor_bathrooms'        => 5,
    'propvendor_floor_area'       => 350,
    'propvendor_floor_area_unit'  => 'sqm',
    'propvendor_plot_size'        => 2,
    'propvendor_plot_size_unit'   => 'plots',
);

$res_prop = propvendor_marketplace_process_listing_submission($prop_data, $files_payload, $vendor_test->ID);
run_test(!is_wp_error($res_prop) && isset($res_prop['post_id']), "Property listing submitted successfully");
$prop_id = $res_prop['post_id'] ?? 0;

run_test(get_post_meta($prop_id, 'propvendor_floor_area', true) == 350, "Property floor area saved as 350");
run_test(get_post_meta($prop_id, 'propvendor_floor_area_unit', true) === 'sqm', "Property floor area unit saved as sqm");
run_test(get_post_meta($prop_id, 'propvendor_plot_size', true) == 2, "Property plot size saved as 2");
run_test(get_post_meta($prop_id, 'propvendor_plot_size_unit', true) === 'plots', "Property plot size unit saved as plots");
run_test(metadata_exists('post', $prop_id, 'propvendor_vehicle_make') === false, "Vehicle make metadata is absent on property listing");
run_test(metadata_exists('post', $prop_id, 'propvendor_land_use') === false, "Land use metadata is absent on property listing");

// Edit Property listing: update floor area & plot size
$prop_edit_data = $prop_data;
$prop_edit_data['propvendor_floor_area'] = 420;
$prop_edit_data['propvendor_plot_size'] = 3;
// Stray metadata injection to test cleanup of obsolete keys on update
update_post_meta($prop_id, 'propvendor_vehicle_make', 'StrayToyota');
update_post_meta($prop_id, 'propvendor_mileage', 99999);

$res_prop_edit = propvendor_marketplace_process_listing_submission($prop_edit_data, array(), $vendor_test->ID, $prop_id);
run_test(!is_wp_error($res_prop_edit), "Property listing updated successfully");
run_test(get_post_meta($prop_id, 'propvendor_floor_area', true) == 420, "Property floor area updated to 420");
run_test(get_post_meta($prop_id, 'propvendor_plot_size', true) == 3, "Property plot size updated to 3");
run_test(metadata_exists('post', $prop_id, 'propvendor_vehicle_make') === false, "Stray vehicle make purged during property update");
run_test(metadata_exists('post', $prop_id, 'propvendor_mileage') === false, "Stray vehicle mileage purged during property update");

// 2. Land Listing: submission & persistence
file_put_contents($dummy_png, $png_1x1);
$land_data = array(
    'propvendor_listing_type'   => 'land_listing',
    'propvendor_title'          => '2 Plots of Dry Commercial Land along Express Road',
    'propvendor_price'          => 85000000,
    'propvendor_location'       => 'Ogun',
    'propvendor_full_address'   => 'Km 40, Lagos-Ibadan Expressway',
    'propvendor_description'    => 'Commercial land plot.',
    'propvendor_land_use'       => 'Commercial',
    'propvendor_plot_size'      => 2,
    'propvendor_plot_size_unit' => 'plots',
    'propvendor_title_document' => 'C of O',
    'propvendor_land_length'    => 100,
    'propvendor_land_width'     => 60,
    'propvendor_dimension_unit' => 'm',
    'propvendor_frontage'       => '50m frontage',
);

$res_land = propvendor_marketplace_process_listing_submission($land_data, $files_payload, $vendor_test->ID);
run_test(!is_wp_error($res_land) && isset($res_land['post_id']), "Land listing submitted successfully");
$land_id = $res_land['post_id'] ?? 0;

run_test(get_post_meta($land_id, 'propvendor_land_use', true) === 'Commercial', "Land use saved as Commercial");
run_test(get_post_meta($land_id, 'propvendor_plot_size', true) == 2, "Land plot size saved as 2");
run_test(get_post_meta($land_id, 'propvendor_plot_size_unit', true) === 'plots', "Land plot size unit saved as plots");
run_test(metadata_exists('post', $land_id, 'propvendor_floor_area') === false, "Floor area metadata is absent on land listing");
run_test(metadata_exists('post', $land_id, 'propvendor_bedrooms') === false, "Bedrooms metadata is absent on land listing");
run_test(metadata_exists('post', $land_id, 'propvendor_vehicle_make') === false, "Vehicle make metadata is absent on land listing");

// Stray metadata injection on land listing, then update
update_post_meta($land_id, 'propvendor_floor_area', 500);
update_post_meta($land_id, 'propvendor_bedrooms', 6);
$land_edit_data = $land_data;
$land_edit_data['propvendor_plot_size'] = 4;
$res_land_edit = propvendor_marketplace_process_listing_submission($land_edit_data, array(), $vendor_test->ID, $land_id);
run_test(!is_wp_error($res_land_edit), "Land listing updated successfully");
run_test(get_post_meta($land_id, 'propvendor_plot_size', true) == 4, "Land plot size updated to 4");
run_test(metadata_exists('post', $land_id, 'propvendor_floor_area') === false, "Stray floor area purged during land update");
run_test(metadata_exists('post', $land_id, 'propvendor_bedrooms') === false, "Stray bedrooms purged during land update");

// 3. Vehicle Listing: submission & persistence
file_put_contents($dummy_png, $png_1x1);
$veh_data = array(
    'propvendor_listing_type'    => 'vehicle_listing',
    'propvendor_title'           => 'Clean Tokunbo 2020 Toyota Camry XLE',
    'propvendor_price'           => 18500000,
    'propvendor_location'        => 'Lagos',
    'propvendor_full_address'    => 'Victoria Island, Lagos',
    'propvendor_description'     => 'Direct foreign used Camry.',
    'propvendor_vehicle_subtype' => 'Car / Sedan',
    'propvendor_vehicle_make'    => 'Toyota',
    'propvendor_vehicle_model'   => 'Camry',
    'propvendor_vehicle_year'    => 2020,
    'propvendor_condition'       => 'Foreign Used (Tokunbo)',
    'propvendor_transmission'    => 'Automatic',
    'propvendor_mileage'         => 45000,
);

$res_veh = propvendor_marketplace_process_listing_submission($veh_data, $files_payload, $vendor_test->ID);
run_test(!is_wp_error($res_veh) && isset($res_veh['post_id']), "Vehicle listing submitted successfully");
$veh_id = $res_veh['post_id'] ?? 0;

run_test(get_post_meta($veh_id, 'propvendor_vehicle_make', true) === 'Toyota', "Vehicle make saved as Toyota");
run_test(get_post_meta($veh_id, 'propvendor_vehicle_model', true) === 'Camry', "Vehicle model saved as Camry");
run_test(get_post_meta($veh_id, 'propvendor_vehicle_year', true) == 2020, "Vehicle year saved as 2020");
run_test(get_post_meta($veh_id, 'propvendor_mileage', true) == 45000, "Vehicle mileage saved as 45000");
run_test(metadata_exists('post', $veh_id, 'propvendor_plot_size') === false, "Plot size metadata is absent on vehicle listing");
run_test(metadata_exists('post', $veh_id, 'propvendor_floor_area') === false, "Floor area metadata is absent on vehicle listing");

// Stray metadata injection on vehicle listing, then update
update_post_meta($veh_id, 'propvendor_plot_size', 10);
update_post_meta($veh_id, 'propvendor_floor_area', 600);
$veh_edit_data = $veh_data;
$veh_edit_data['propvendor_mileage'] = 48000;
$res_veh_edit = propvendor_marketplace_process_listing_submission($veh_edit_data, array(), $vendor_test->ID, $veh_id);
run_test(!is_wp_error($res_veh_edit), "Vehicle listing updated successfully");
run_test(get_post_meta($veh_id, 'propvendor_mileage', true) == 48000, "Vehicle mileage updated to 48000");
run_test(metadata_exists('post', $veh_id, 'propvendor_plot_size') === false, "Stray plot size purged during vehicle update");
run_test(metadata_exists('post', $veh_id, 'propvendor_floor_area') === false, "Stray floor area purged during vehicle update");

// -------------------------------------------------------------------------
// SECTION 5: Edit Mode Rendering Restoration (All 3 Categories)
// -------------------------------------------------------------------------
echo "\n--- SECTION 5: Edit Mode Rendering Restoration ---\n";

// 1. Property Edit Rendering
$_GET = array('propvendor_edit_listing' => $prop_id);
$edit_prop_html = propvendor_marketplace_submit_listing_form_shortcode();
run_test(strpos($edit_prop_html, 'value="Luxury 4 Bedroom Detached Duplex with Swimming Pool"') !== false, "Property title restored in edit mode");
run_test(strpos($edit_prop_html, 'value="420"') !== false, "Property floor area 420 restored in edit mode");
run_test(strpos($edit_prop_html, 'value="3"') !== false, "Property plot size 3 restored in edit mode");
run_test(strpos($edit_prop_html, '<span class="propvendor-address-label-text">Full Address</span>') !== false, "Property address label rendered as 'Full Address'");

// 2. Land Edit Rendering
$_GET = array('propvendor_edit_listing' => $land_id);
$edit_land_html = propvendor_marketplace_submit_listing_form_shortcode();
run_test(strpos($edit_land_html, 'value="2 Plots of Dry Commercial Land along Express Road"') !== false, "Land title restored in edit mode");
run_test(strpos($edit_land_html, 'value="4"') !== false, "Land plot size 4 restored in edit mode");
run_test(preg_match('/value="Commercial"\s+selected=\'selected\'/', $edit_land_html) === 1, "Land use 'Commercial' selected in edit mode");
run_test(strpos($edit_land_html, '<span class="propvendor-address-label-text">Full Address</span>') !== false, "Land address label rendered as 'Full Address'");

// 3. Vehicle Edit Rendering
$_GET = array('propvendor_edit_listing' => $veh_id);
$edit_veh_html = propvendor_marketplace_submit_listing_form_shortcode();
run_test(strpos($edit_veh_html, 'value="Clean Tokunbo 2020 Toyota Camry XLE"') !== false, "Vehicle title restored in edit mode");
run_test(strpos($edit_veh_html, 'value="Toyota"') !== false, "Vehicle make 'Toyota' restored in edit mode");
run_test(strpos($edit_veh_html, 'value="Camry"') !== false, "Vehicle model 'Camry' restored in edit mode");
run_test(strpos($edit_veh_html, 'value="48000"') !== false, "Vehicle mileage '48000' restored in edit mode");
run_test(strpos($edit_veh_html, '<span class="propvendor-address-label-text">Specific Location / Landmark</span>') !== false, "Vehicle address label rendered as 'Specific Location / Landmark'");

// -------------------------------------------------------------------------
// CLEANUP
// -------------------------------------------------------------------------
echo "\n--- CLEANUP ---\n";
wp_delete_post($prop_id, true);
wp_delete_post($land_id, true);
wp_delete_post($veh_id, true);
if (file_exists($dummy_png)) {
    @unlink($dummy_png);
}
echo "Cleaned up test listings and files.\n";

echo "\n====================================================================\n";
echo "SUMMARY: $pass_count PASSED, $fail_count FAILED\n";
echo "====================================================================\n";

if ($fail_count > 0) {
    exit(1);
}
exit(0);
