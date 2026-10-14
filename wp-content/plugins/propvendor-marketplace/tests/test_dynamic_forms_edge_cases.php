<?php
/**
 * PropVendor - Dynamic Forms & Multi-Image Uploads Comprehensive Edge Cases Test
 */
define('WP_USE_THEMES', false);
require_once 'c:/xampp/htdocs/Propvendor/wp-load.php';

echo "====================================================================\n";
echo "  PROPVENDOR DYNAMIC FORMS & MULTI-IMAGE EDGE CASES & SECURITY TEST \n";
echo "====================================================================\n\n";

$pass_count = 0;
$fail_count = 0;

function pv_assert($condition, $test_name, $details = '') {
    global $pass_count, $fail_count;
    if ($condition) {
        echo " [PASS] " . $test_name . "\n";
        $pass_count++;
    } else {
        echo " [FAIL] " . $test_name . ($details ? " -> " . $details : "") . "\n";
        $fail_count++;
    }
}

// 1. Setup Test Users
$vendor_a = get_user_by('login', 'vendor_test_alpha');
if (!$vendor_a) {
    $uid_a = wp_create_user('vendor_test_alpha', 'PassWord123#', 'vendor_a@propvendor.ng');
    $vendor_a = get_user_by('id', $uid_a);
    $vendor_a->set_role('vendor');
    $vendor_a->add_cap('propvendor_submit_marketplace_listings');
}

$vendor_b = get_user_by('login', 'vendor_test_beta');
if (!$vendor_b) {
    $uid_b = wp_create_user('vendor_test_beta', 'PassWord123#', 'vendor_b@propvendor.ng');
    $vendor_b = get_user_by('id', $uid_b);
    $vendor_b->set_role('vendor');
    $vendor_b->add_cap('propvendor_submit_marketplace_listings');
}

function create_dummy_png($filename, $size_bytes = 100) {
    $path = __DIR__ . '/' . $filename;
    $png_head = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $data = $png_head . str_repeat('A', max(0, $size_bytes - strlen($png_head)));
    file_put_contents($path, $data);
    return $path;
}

$sample_img = create_dummy_png('edge_test_image.png', 100);
$sample_file_entry = array(
    'propvendor_listing_images' => array(
        'name'     => array('edge_test_image.png'),
        'type'     => array('image/png'),
        'tmp_name' => array($sample_img),
        'error'    => array(0),
        'size'     => array(filesize($sample_img)),
    )
);

// --- TEST 1: Invalid listing type rejected ---
echo "--- TEST 1: Invalid listing post_type rejection ---\n";
wp_set_current_user($vendor_a->ID);
$bad_type_data = array(
    'propvendor_listing_type' => 'airplane_listing',
    'propvendor_title'        => 'Boeing 737 Private Jet',
    'propvendor_price'        => 500000000,
    'propvendor_location'     => 'Abuja Airport',
    'propvendor_description'  => 'Luxury private aircraft.',
);
$res_bad_type = propvendor_marketplace_process_listing_submission($bad_type_data, $sample_file_entry, $vendor_a->ID, 0);
pv_assert(is_wp_error($res_bad_type) && $res_bad_type->get_error_code() === 'invalid_listing_type', 'Invalid post_type rejected with invalid_listing_type');

// --- TEST 2: Invalid numeric values (price <= 0) ---
echo "\n--- TEST 2: Invalid price handling ---\n";
$bad_price_data = array(
    'propvendor_listing_type' => 'property_listing',
    'propvendor_title'        => 'Zero Price House',
    'propvendor_price'        => 0,
    'propvendor_location'     => 'Lagos',
    'propvendor_description'  => 'Free house',
);
$res_bad_price = propvendor_marketplace_process_listing_submission($bad_price_data, $sample_file_entry, $vendor_a->ID, 0);
pv_assert(is_wp_error($res_bad_price) && $res_bad_price->get_error_code() === 'missing_required_fields', 'Zero/negative price rejected with missing_required_fields');

// --- TEST 3: Invalid MIME type rejected ---
echo "\n--- TEST 3: Invalid MIME type rejection ---\n";
$txt_file = __DIR__ . '/test_script.txt';
file_put_contents($txt_file, 'This is plain text, not an image.');
$bad_mime_files = array(
    'propvendor_listing_images' => array(
        'name'     => array('test_script.txt'),
        'type'     => array('text/plain'),
        'tmp_name' => array($txt_file),
        'error'    => array(0),
        'size'     => array(filesize($txt_file)),
    )
);
$valid_data = array(
    'propvendor_listing_type' => 'property_listing',
    'propvendor_title'        => 'Security Test Property',
    'propvendor_price'        => 25000000,
    'propvendor_location'     => 'Ikeja, Lagos',
    'propvendor_description'  => 'Testing text file upload rejection.',
);
$res_bad_mime = propvendor_marketplace_process_listing_submission($valid_data, $bad_mime_files, $vendor_a->ID, 0);
pv_assert(is_wp_error($res_bad_mime) && $res_bad_mime->get_error_code() === 'invalid_file_type', 'Non-image file rejected with invalid_file_type');
@unlink($txt_file);

// --- TEST 4: Oversized image (>4MB) rejected ---
echo "\n--- TEST 4: Oversized image rejection ---\n";
$big_img = create_dummy_png('big_image.png', 100); // 100 bytes on disk, but simulated 5MB size in metadata
$oversized_files = array(
    'propvendor_listing_images' => array(
        'name'     => array('big_image.png'),
        'type'     => array('image/png'),
        'tmp_name' => array($big_img),
        'error'    => array(0),
        'size'     => array(5 * 1024 * 1024), // 5MB
    )
);
$res_big = propvendor_marketplace_process_listing_submission($valid_data, $oversized_files, $vendor_a->ID, 0);
pv_assert(is_wp_error($res_big) && $res_big->get_error_code() === 'image_too_large', 'Image > 4MB rejected with image_too_large');
@unlink($big_img);

// --- TEST 5: Total image count > 10 rejected ---
echo "\n--- TEST 5: 10-Image Limit Enforcement ---\n";
$eleven_names = array();
$eleven_types = array();
$eleven_tmps  = array();
$eleven_errs  = array();
$eleven_sizes = array();
for ($i = 1; $i <= 11; $i++) {
    $eleven_names[] = "img_{$i}.png";
    $eleven_types[] = 'image/png';
    $eleven_tmps[]  = $sample_img;
    $eleven_errs[]  = 0;
    $eleven_sizes[] = filesize($sample_img);
}
$eleven_files = array(
    'propvendor_listing_images' => array(
        'name'     => $eleven_names,
        'type'     => $eleven_types,
        'tmp_name' => $eleven_tmps,
        'error'    => $eleven_errs,
        'size'     => $eleven_sizes,
    )
);
$res_eleven = propvendor_marketplace_process_listing_submission($valid_data, $eleven_files, $vendor_a->ID, 0);
pv_assert(is_wp_error($res_eleven) && $res_eleven->get_error_code() === 'too_many_images', '11 images rejected with too_many_images');

// --- TEST 6: Ownership check on Edit Mode ---
echo "\n--- TEST 6: Vendor edit cross-tenant protection ---\n";
// Create a legitimate listing by Vendor A
$valid_img_path = create_dummy_png('vendor_a_listing_img.png');
$res_a = propvendor_marketplace_process_listing_submission(
    array(
        'propvendor_listing_type' => 'property_listing',
        'propvendor_title'        => 'Vendor A Private Property',
        'propvendor_price'        => 50000000,
        'propvendor_location'     => 'Victoria Island, Lagos',
        'propvendor_description'  => 'Prime VI property by vendor A.',
    ),
    array(
        'propvendor_listing_images' => array(
            'name'     => array('vendor_a_listing_img.png'),
            'type'     => array('image/png'),
            'tmp_name' => array($valid_img_path),
            'error'    => array(0),
            'size'     => array(filesize($valid_img_path)),
        )
    ),
    $vendor_a->ID,
    0
);
pv_assert(!is_wp_error($res_a) && !empty($res_a['post_id']), 'Vendor A listing created');
$post_a_id = is_wp_error($res_a) ? 0 : $res_a['post_id'];

// Vendor B tries to update Vendor A's listing
wp_set_current_user($vendor_b->ID);
$res_unauthorized_edit = propvendor_marketplace_process_listing_submission(
    array(
        'propvendor_listing_type' => 'property_listing',
        'propvendor_title'        => 'Hacked Property Title',
        'propvendor_price'        => 1000000,
        'propvendor_location'     => 'Hacked Location',
        'propvendor_description'  => 'Hacked description',
    ),
    array(),
    $vendor_b->ID,
    $post_a_id
);
pv_assert(is_wp_error($res_unauthorized_edit) && $res_unauthorized_edit->get_error_code() === 'not_owner', 'Vendor B denied editing Vendor A post with not_owner error');

// --- TEST 7: Cross-tenant image deletion protection ---
echo "\n--- TEST 7: Cross-tenant media deletion protection ---\n";
// Create listing for Vendor B with image
$valid_img_b = create_dummy_png('vendor_b_img.png');
$res_b = propvendor_marketplace_process_listing_submission(
    array(
        'propvendor_listing_type' => 'vehicle_listing',
        'propvendor_title'        => 'Vendor B Car',
        'propvendor_price'        => 8000000,
        'propvendor_location'     => 'Ikeja',
        'propvendor_description'  => 'Clean ride.',
        'propvendor_vehicle_make' => 'Honda',
    ),
    array(
        'propvendor_listing_images' => array(
            'name'     => array('vendor_b_img.png'),
            'type'     => array('image/png'),
            'tmp_name' => array($valid_img_b),
            'error'    => array(0),
            'size'     => array(filesize($valid_img_b)),
        )
    ),
    $vendor_b->ID,
    0
);
$post_b_id = is_wp_error($res_b) ? 0 : $res_b['post_id'];
$b_images = propvendor_marketplace_get_listing_image_ids($post_b_id);
$b_attachment_id = !empty($b_images) ? $b_images[0] : 0;

// Vendor A tries to delete Vendor B's image via delete helper
$delete_attempt_1 = propvendor_marketplace_delete_listing_image($post_b_id, $b_attachment_id, $vendor_a->ID);
pv_assert($delete_attempt_1 === false, 'Vendor A cannot delete image from Vendor B listing');

// Vendor A tries to delete Vendor B's image using Vendor A's listing ID
$delete_attempt_2 = propvendor_marketplace_delete_listing_image($post_a_id, $b_attachment_id, $vendor_a->ID);
pv_assert($delete_attempt_2 === false, 'Vendor A cannot target Vendor B attachment using Vendor A listing ID');

// Confirm Vendor B's attachment is still intact
pv_assert(get_post($b_attachment_id) !== null, 'Target attachment preserved safely');

// --- TEST 8: Preserving existing images on edit with no new uploads ---
echo "\n--- TEST 8: Image retention on edit ---\n";
wp_set_current_user($vendor_a->ID);
$a_images_before = propvendor_marketplace_get_listing_image_ids($post_a_id);
$edit_res_no_img = propvendor_marketplace_process_listing_submission(
    array(
        'propvendor_listing_type' => 'property_listing',
        'propvendor_title'        => 'Vendor A Private Property (Updated Title Only)',
        'propvendor_price'        => 55000000,
        'propvendor_location'     => 'Victoria Island, Lagos',
        'propvendor_description'  => 'Updated description text.',
    ),
    array(), // Empty files array
    $vendor_a->ID,
    $post_a_id
);
pv_assert(!is_wp_error($edit_res_no_img), 'Edit with no new files succeeds');
$a_images_after = propvendor_marketplace_get_listing_image_ids($post_a_id);
pv_assert($a_images_before === $a_images_after, 'Existing images preserved intact after edit');

// --- TEST 9: Owner-Direct auto-reset to pending upon edit ---
echo "\n--- TEST 9: Owner-Direct auto-reset on edit ---\n";
// Set listing to approved Owner-Direct by admin
update_post_meta($post_a_id, '_propvendor_is_direct_owner', '1');
update_post_meta($post_a_id, '_propvendor_direct_owner_status', 'approved');
pv_assert(get_post_meta($post_a_id, '_propvendor_direct_owner_status', true) === 'approved', 'Direct owner status pre-set to approved');

// Vendor edits listing
$edit_do = propvendor_marketplace_process_listing_submission(
    array(
        'propvendor_listing_type'    => 'property_listing',
        'propvendor_title'           => 'Vendor A Private Property (Vendor Edit)',
        'propvendor_price'           => 55000000,
        'propvendor_location'        => 'Victoria Island, Lagos',
        'propvendor_description'     => 'Vendor edit description.',
        'propvendor_is_direct_owner' => '1',
    ),
    array(),
    $vendor_a->ID,
    $post_a_id
);
pv_assert(!is_wp_error($edit_do), 'Vendor edit processed');
pv_assert(get_post_meta($post_a_id, '_propvendor_direct_owner_status', true) === 'pending', 'Direct owner status automatically revoked to pending for admin moderation');

// CLEANUP
if ($post_a_id) wp_delete_post($post_a_id, true);
if ($post_b_id) wp_delete_post($post_b_id, true);
@unlink($sample_img);
@unlink($valid_img_path);
@unlink($valid_img_b);

echo "\n====================================================================\n";
echo "SUMMARY: {$pass_count} PASSED, {$fail_count} FAILED\n";
echo "====================================================================\n";

if ($fail_count > 0) {
    exit(1);
}
exit(0);
