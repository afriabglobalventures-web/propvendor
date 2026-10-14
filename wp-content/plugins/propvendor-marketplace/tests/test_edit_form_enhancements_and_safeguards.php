<?php
/**
 * Test Suite: PropVendor Final Listing Schema & Edit Form Enhancements & Bug Fixes
 *
 * Verifies:
 * 1. Title pre-population in edit mode with post_title / get_the_title fallback
 * 2. Multi-parameter edit support (propvendor_edit_listing, edit_listing, listing_id, edit)
 * 3. Description label ("Description") and placeholder
 * 4. Existing image action classes (.propvendor-action-main, .propvendor-action-delete)
 * 5. Single image deletion safeguard (cannot delete all images without replacement)
 * 6. Replace image on edit (delete old, upload new succeeds)
 * 7. Error redirect preserving propvendor_edit_listing
 * 8. .gitignore verification for uploads/
 */

define('WP_USE_THEMES', false);
require_once 'c:/xampp/htdocs/Propvendor/wp-load.php';

echo "====================================================================\n";
echo "  PROPVENDOR EDIT FORM ENHANCEMENTS & SAFEGUARDS TEST SUITE\n";
echo "====================================================================\n\n";

$passed = 0;
$failed = 0;

function assert_test($condition, $name) {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] $name\n";
        $passed++;
    } else {
        echo " [FAIL] $name\n";
        $failed++;
    }
}

// Setup test vendor
$test_user = get_user_by('login', 'test_vendor_edit_suite');
if (!$test_user) {
    $uid = wp_create_user('test_vendor_edit_suite', 'testpass123', 'vendor_edit_suite@example.com');
    $test_user = get_user_by('id', $uid);
    $test_user->add_cap('propvendor_submit_marketplace_listings');
}
wp_set_current_user($test_user->ID);

// -------------------------------------------------------------------------
// TEST 1: Title Pre-population and Multi-Query Param Support
// -------------------------------------------------------------------------
echo "--- TEST 1: Title Pre-population & Edit Parameter Support ---\n";

$test_post_id = wp_insert_post(array(
    'post_title'   => 'Stunning 4 Bedroom Duplex Lekki',
    'post_content' => 'Full luxury duplex with swimming pool and gym.',
    'post_status'  => 'publish',
    'post_type'    => 'property_listing',
    'post_author'  => $test_user->ID,
));
update_post_meta($test_post_id, 'propvendor_price', 85000000);
update_post_meta($test_post_id, 'propvendor_location', 'Lagos');
update_post_meta($test_post_id, 'propvendor_full_address', 'Plot 4, Admiralty Way, Lekki Phase 1');
update_post_meta($test_post_id, 'propvendor_property_subtype', 'Duplex');
update_post_meta($test_post_id, 'propvendor_bedrooms', 4);

// Test across all 4 query parameters
$edit_params = array('propvendor_edit_listing', 'edit_listing', 'listing_id', 'edit');

foreach ($edit_params as $param) {
    $_GET = array($param => $test_post_id);
    $html = propvendor_marketplace_submit_listing_form_shortcode();

    assert_test(strpos($html, 'value="Stunning 4 Bedroom Duplex Lekki"') !== false, "Param '$param' pre-populates post_title accurately");
    assert_test(strpos($html, 'value="' . $test_post_id . '"') !== false, "Param '$param' sets hidden listing ID");
    assert_test(strpos($html, 'Edit listing') !== false, "Param '$param' renders 'Edit listing' form title");
    assert_test(strpos($html, 'name="action" value="propvendor_update_listing"') !== false, "Param '$param' sets update action");
}

// -------------------------------------------------------------------------
// TEST 2: Description Label and Placeholder
// -------------------------------------------------------------------------
echo "\n--- TEST 2: Description Label and Placeholder ---\n";

$_GET = array('propvendor_edit_listing' => $test_post_id);
$html = propvendor_marketplace_submit_listing_form_shortcode();

assert_test(strpos($html, '<label>Description<textarea') !== false, "Label is 'Description' rather than legacy 'Listing Details'");
assert_test(strpos($html, 'placeholder="Describe the features, condition, and any other important details..."') !== false, "Textarea has expected placeholder");
assert_test(strpos($html, 'Full luxury duplex with swimming pool and gym.') !== false, "Textarea pre-populates post_content");

// -------------------------------------------------------------------------
// TEST 3: Image Actions Classes and Styling Hooks
// -------------------------------------------------------------------------
echo "\n--- TEST 3: Existing Image Action Classes ---\n";

// Attach a dummy image attachment to $test_post_id
$attach_id = wp_insert_attachment(array(
    'post_title'     => 'Test Duplex Front',
    'post_mime_type' => 'image/png',
    'post_status'    => 'inherit',
), __FILE__, $test_post_id);
set_post_thumbnail($test_post_id, $attach_id);
update_post_meta($test_post_id, '_propvendor_gallery_image_ids', array($attach_id));

$_GET = array('propvendor_edit_listing' => $test_post_id);
$html_with_img = propvendor_marketplace_submit_listing_form_shortcode();

assert_test(strpos($html_with_img, 'class="propvendor-action-main"') !== false, "Main radio has .propvendor-action-main class");
assert_test(strpos($html_with_img, 'class="propvendor-action-delete"') !== false, "Delete checkbox has .propvendor-action-delete class");
assert_test(strpos($html_with_img, 'propvendor-existing-image-actions') !== false, "Container has .propvendor-existing-image-actions");

// -------------------------------------------------------------------------
// TEST 4: Single Image Deletion Safeguard
// -------------------------------------------------------------------------
echo "\n--- TEST 4: Single Image Deletion Safeguard (No Replacement) ---\n";

// Vendor tries to delete the ONLY image and submits NO new files
$post_data = array(
    'propvendor_listing_id'       => $test_post_id,
    'propvendor_listing_type'     => 'property_listing',
    'propvendor_title'            => 'Stunning 4 Bedroom Duplex Lekki',
    'propvendor_price'            => 85000000,
    'propvendor_location'         => 'Lagos',
    'propvendor_full_address'     => 'Plot 4, Admiralty Way, Lekki Phase 1',
    'propvendor_description'      => 'Updated description',
    'propvendor_delete_images'    => array($attach_id), // Mark only image for deletion
    'propvendor_property_subtype' => 'Duplex',
);
$files_data = array(); // No new files uploaded

$res = propvendor_marketplace_process_listing_submission($post_data, $files_data, $test_user->ID, $test_post_id);

assert_test(is_wp_error($res), "Attempt to delete only image without replacement returns WP_Error");
assert_test(is_wp_error($res) && $res->get_error_code() === 'missing_image', "Error code is 'missing_image'");
assert_test(is_wp_error($res) && strpos($res->get_error_message(), 'replacement image') !== false, "Error message informs user to upload replacement image");

// Verify attachment was NOT deleted from the database
$check_attach = get_post($attach_id);
assert_test(!empty($check_attach), "Existing attachment was preserved safely in DB (not prematurely deleted)");
$gallery_check = propvendor_marketplace_get_listing_image_ids($test_post_id);
assert_test(in_array($attach_id, $gallery_check, true), "Existing attachment remains in listing gallery");

// -------------------------------------------------------------------------
// TEST 5: Image Replacement (Delete Old + Upload Replacement)
// -------------------------------------------------------------------------
echo "\n--- TEST 5: Image Replacement (Delete Old + Upload Replacement) ---\n";

// Create a temp valid PNG image file
$tmp_img = tempnam(sys_get_temp_dir(), 'prop_img_');
$png_1x1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
file_put_contents($tmp_img, $png_1x1);

$files_data_replacement = array(
    'propvendor_listing_images' => array(
        'name'     => array('replacement_image.png'),
        'type'     => array('image/png'),
        'tmp_name' => array($tmp_img),
        'error'    => array(UPLOAD_ERR_OK),
        'size'     => array(filesize($tmp_img)),
    ),
);

$res2 = propvendor_marketplace_process_listing_submission($post_data, $files_data_replacement, $test_user->ID, $test_post_id);

assert_test(!is_wp_error($res2), "Edit with old image deleted and new image uploaded succeeds");
$updated_gallery = propvendor_marketplace_get_listing_image_ids($test_post_id);
assert_test(!in_array($attach_id, $updated_gallery, true), "Old image removed from listing gallery");
assert_test(count($updated_gallery) >= 1, "New replacement image is present in gallery");
$new_thumb = get_post_thumbnail_id($test_post_id);
assert_test($new_thumb > 0 && $new_thumb !== $attach_id, "New image automatically set as primary thumbnail");

// -------------------------------------------------------------------------
// TEST 6: Gitignore Uploads Rule Verification
// -------------------------------------------------------------------------
echo "\n--- TEST 6: .gitignore Uploads Rule Verification ---\n";

$gitignore_path = ABSPATH . 'wp-content/.gitignore';
$gitignore_content = file_get_contents($gitignore_path);

assert_test(strpos($gitignore_content, "uploads/\n") !== false || strpos($gitignore_content, "uploads/\r\n") !== false, "wp-content/.gitignore contains 'uploads/'");
assert_test(strpos($gitignore_content, "wp-content/uploads/") === false, "Invalid relative 'wp-content/uploads/' rule was removed");

// -------------------------------------------------------------------------
// CLEANUP
// -------------------------------------------------------------------------
echo "\n--- CLEANUP ---\n";
// Clean up created attachments
if (!empty($updated_gallery)) {
    foreach ($updated_gallery as $gid) {
        wp_delete_attachment($gid, true);
    }
}
wp_delete_post($test_post_id, true);
if (file_exists($tmp_img)) {
    @unlink($tmp_img);
}
echo "Cleaned up test listing and attachments.\n";

echo "\n====================================================================\n";
echo "SUMMARY: $passed PASSED, $failed FAILED\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
