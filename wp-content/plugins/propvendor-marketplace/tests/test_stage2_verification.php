<?php
/**
 * PropVendor Stage 2 Verification Test Suite
 *
 * Tests Owner-Direct Badge, Regulatory Verification & Trust Safeguards
 */

require_once 'C:/xampp/htdocs/Propvendor/wp-load.php';

echo "====================================================================\n";
echo "  PROPVENDOR STAGE 2 VERIFICATION TEST SUITE                        \n";
echo "====================================================================\n\n";

// Block real emails completely
$captured_emails = array();
add_filter('pre_wp_mail', function($null, $atts) use (&$captured_emails) {
    $captured_emails[] = $atts;
    return false;
}, 10, 2);

// Intercept wp_redirect to avoid exit in CLI
add_filter('wp_redirect', function($location) {
    throw new Exception("REDIRECT: {$location}");
});

$total_tests = 0;
$passed_tests = 0;
$failed_tests = 0;

function report($test_name, $passed, $details = '') {
    global $total_tests, $passed_tests, $failed_tests;
    $total_tests++;
    if ($passed) {
        $passed_tests++;
        echo " [PASS] {$test_name}\n";
    } else {
        $failed_tests++;
        echo " [FAIL] {$test_name}\n";
    }
    if (!empty($details)) {
        echo "        Details: {$details}\n";
    }
}

// Helper to configure WordPress global query for single listing detail view
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

// Identify admin user
$admin_user = get_user_by('login', 'admin');
if (!$admin_user) {
    $admins = get_users(array('role' => 'administrator', 'number' => 1));
    $admin_user = !empty($admins) ? $admins[0] : null;
}

// Identify vendor user
$vendors = get_users(array('role' => 'vendor', 'number' => 2));
if (empty($vendors)) {
    $vendors = get_users(array('number' => 2));
}
$vendor_a = $vendors[0];
$vendor_a->add_role('vendor');
$vendor_a->add_cap('propvendor_submit_marketplace_listings');

// Clean up any existing synthetic test listings
$existing_test_posts = get_posts(array(
    'post_type' => array('property_listing', 'vehicle_listing', 'land_listing'),
    'meta_key' => '_is_stage2_test',
    'posts_per_page' => -1,
    'post_status' => 'any',
));
foreach ($existing_test_posts as $p) {
    wp_delete_post($p->ID, true);
}

// -----------------------------------------------------------------------------
// REQUIREMENT 1: New listing / claim with Owner-Direct checked sets status to 'pending'
// -----------------------------------------------------------------------------
echo "\n--- REQUIREMENT 1: Listing with Owner-Direct checked sets status to pending ---\n";
wp_set_current_user($vendor_a->ID);

// Create base post
$test_post_id = wp_insert_post(array(
    'post_title' => 'Stage2 Test Property Direct Claim',
    'post_content' => 'Direct owner luxury duplex for sale without agency fee.',
    'post_type' => 'property_listing',
    'post_status' => 'publish',
    'post_author' => $vendor_a->ID,
));
update_post_meta($test_post_id, '_is_stage2_test', '1');
update_post_meta($test_post_id, 'propvendor_price', '25000000');
update_post_meta($test_post_id, 'propvendor_location', 'Lekki Phase 1, Lagos');
update_post_meta($test_post_id, 'propvendor_listing_status', 'Published');

// Process submission with Owner-Direct claim = 1
$submission_data = array(
    'propvendor_title' => 'Stage2 Test Property Direct Claim',
    'propvendor_price' => '25000000',
    'propvendor_location' => 'Lekki Phase 1, Lagos',
    'propvendor_listing_type' => 'property_listing',
    'propvendor_description' => 'Direct owner luxury duplex for sale without agency fee.',
    'propvendor_is_direct_owner' => '1',
);

$res = propvendor_marketplace_process_listing_submission($submission_data, array(), $vendor_a->ID, $test_post_id);
report('Listing processed through submission handler', is_array($res) && isset($res['post_id']), "Result: " . (is_wp_error($res) ? $res->get_error_message() : 'Success'));

$saved_claim = get_post_meta($test_post_id, '_propvendor_is_direct_owner', true);
$saved_status = get_post_meta($test_post_id, '_propvendor_direct_owner_status', true);

report('Listing saves _propvendor_is_direct_owner as 1', '1' === (string)$saved_claim, "Value: {$saved_claim}");
report('Listing defaults _propvendor_direct_owner_status to pending (not approved)', 'pending' === $saved_status, "Status: {$saved_status}");

// -----------------------------------------------------------------------------
// REQUIREMENT 2: Public badge renders ONLY when status is explicitly 'approved'
// -----------------------------------------------------------------------------
echo "\n--- REQUIREMENT 2: Public badge display conditions ---\n";

// Ensure post is published for public detail rendering
wp_update_post(array('ID' => $test_post_id, 'post_status' => 'publish'));
update_post_meta($test_post_id, 'propvendor_listing_status', 'Published');
delete_post_meta($test_post_id, 'propvendor_status');
$test_post = get_post($test_post_id);
setup_mock_singular_context($test_post);

// Case 2a: Pending status -> NO badge on card or detail
$cards_html = propvendor_marketplace_render_listing_cards(array($test_post));
$details_html = propvendor_marketplace_render_listing_details($test_post->post_content);

report('Badge NOT shown on listing card when status is pending', strpos($cards_html, 'Direct from Owner — Zero Agent Fee') === false);
report('Badge NOT shown on listing detail when status is pending', strpos($details_html, 'Direct from Owner — Zero Agent Fee') === false);

// Case 2b: Rejected status -> NO badge
update_post_meta($test_post_id, '_propvendor_direct_owner_status', 'rejected');
$cards_html = propvendor_marketplace_render_listing_cards(array($test_post));
$details_html = propvendor_marketplace_render_listing_details($test_post->post_content);
report('Badge NOT shown on listing card when status is rejected', strpos($cards_html, 'Direct from Owner — Zero Agent Fee') === false);
report('Badge NOT shown on listing detail when status is rejected', strpos($details_html, 'Direct from Owner — Zero Agent Fee') === false);

// Case 2c: Revoked status -> NO badge
update_post_meta($test_post_id, '_propvendor_direct_owner_status', 'revoked');
$cards_html = propvendor_marketplace_render_listing_cards(array($test_post));
$details_html = propvendor_marketplace_render_listing_details($test_post->post_content);
report('Badge NOT shown on listing card when status is revoked', strpos($cards_html, 'Direct from Owner — Zero Agent Fee') === false);
report('Badge NOT shown on listing detail when status is revoked', strpos($details_html, 'Direct from Owner — Zero Agent Fee') === false);

// Case 2d: Approved status BUT claim = 0 -> NO badge
update_post_meta($test_post_id, '_propvendor_is_direct_owner', '0');
update_post_meta($test_post_id, '_propvendor_direct_owner_status', 'approved');
$cards_html = propvendor_marketplace_render_listing_cards(array($test_post));
$details_html = propvendor_marketplace_render_listing_details($test_post->post_content);
report('Badge NOT shown on card when claim is 0 even if status is approved', strpos($cards_html, 'Direct from Owner — Zero Agent Fee') === false);
report('Badge NOT shown on detail when claim is 0 even if status is approved', strpos($details_html, 'Direct from Owner — Zero Agent Fee') === false);

// Case 2e: Both claim = 1 AND status = approved -> BADGE IS SHOWN
update_post_meta($test_post_id, '_propvendor_is_direct_owner', '1');
update_post_meta($test_post_id, '_propvendor_direct_owner_status', 'approved');
$cards_html = propvendor_marketplace_render_listing_cards(array($test_post));
$details_html = propvendor_marketplace_render_listing_details($test_post->post_content);
report('Badge IS shown on listing card when claim=1 and status=approved', strpos($cards_html, 'Direct from Owner — Zero Agent Fee') !== false);
report('Badge IS shown on listing detail when claim=1 and status=approved', strpos($details_html, 'Direct from Owner — Zero Agent Fee') !== false);

// -----------------------------------------------------------------------------
// REQUIREMENT 3: Vendor editing an approved listing automatically resets to 'pending'
// -----------------------------------------------------------------------------
echo "\n--- REQUIREMENT 3: Vendor edit auto-revocation to pending ---\n";
// Create another listing for vendor_a that is also 'approved' to test isolation
$isolated_post_id = wp_insert_post(array(
    'post_title' => 'Vendor Second Listing',
    'post_type' => 'property_listing',
    'post_status' => 'publish',
    'post_author' => $vendor_a->ID,
));
update_post_meta($isolated_post_id, '_is_stage2_test', '1');
update_post_meta($isolated_post_id, '_propvendor_is_direct_owner', '1');
update_post_meta($isolated_post_id, '_propvendor_direct_owner_status', 'approved');

// Set $test_post_id to approved
update_post_meta($test_post_id, '_propvendor_is_direct_owner', '1');
update_post_meta($test_post_id, '_propvendor_direct_owner_status', 'approved');

// Vendor edits $test_post_id
$edit_data = array(
    'propvendor_title' => 'Stage2 Test Property Direct Claim (Updated Title)',
    'propvendor_price' => '27000000',
    'propvendor_location' => 'Lekki Phase 1, Lagos',
    'propvendor_listing_type' => 'property_listing',
    'propvendor_description' => 'Updated description content.',
    'propvendor_is_direct_owner' => '1',
);

wp_set_current_user($vendor_a->ID);
$edit_res = propvendor_marketplace_process_listing_submission($edit_data, array(), $vendor_a->ID, $test_post_id);

$reset_status = get_post_meta($test_post_id, '_propvendor_direct_owner_status', true);
$other_status = get_post_meta($isolated_post_id, '_propvendor_direct_owner_status', true);

report('Editing approved listing automatically resets status to pending', 'pending' === $reset_status, "Status: {$reset_status}");
report('Editing listing does NOT affect status of vendor other listings', 'approved' === $other_status, "Other listing status: {$other_status}");

// If vendor unchecks direct owner on edit, status becomes 'none'
$uncheck_edit_data = array(
    'propvendor_title' => 'Stage2 Test Property Direct Claim (Unchecked)',
    'propvendor_price' => '27000000',
    'propvendor_location' => 'Lekki Phase 1, Lagos',
    'propvendor_listing_type' => 'property_listing',
    'propvendor_description' => 'Unchecked claim test.',
    // 'propvendor_is_direct_owner' omitted/unchecked
);
propvendor_marketplace_process_listing_submission($uncheck_edit_data, array(), $vendor_a->ID, $test_post_id);
$uncheck_claim = get_post_meta($test_post_id, '_propvendor_is_direct_owner', true);
$uncheck_status = get_post_meta($test_post_id, '_propvendor_direct_owner_status', true);
report('Unchecking Owner-Direct sets claim to 0 and status to none', '0' === (string)$uncheck_claim && 'none' === $uncheck_status, "Claim: {$uncheck_claim}, Status: {$uncheck_status}");

// -----------------------------------------------------------------------------
// REQUIREMENT 4: Vendor cannot self-approve through crafted POST parameters
// -----------------------------------------------------------------------------
echo "\n--- REQUIREMENT 4: Vendor cannot self-approve via crafted POST ---\n";
$tamper_data = array(
    'propvendor_title' => 'Tamper Attempt Listing',
    'propvendor_price' => '15000000',
    'propvendor_location' => 'Ikeja, Lagos',
    'propvendor_listing_type' => 'property_listing',
    'propvendor_description' => 'Trying to forge approved status in submission.',
    'propvendor_is_direct_owner' => '1',
    'propvendor_direct_owner_status' => 'approved',
    '_propvendor_direct_owner_status' => 'approved',
);

wp_set_current_user($vendor_a->ID);
$tamper_res = propvendor_marketplace_process_listing_submission($tamper_data, array(), $vendor_a->ID, $test_post_id);

$tamper_status = get_post_meta($test_post_id, '_propvendor_direct_owner_status', true);
report('Crafted POST parameter cannot force approved status; remains pending', 'pending' === $tamper_status, "Status: {$tamper_status}");

// -----------------------------------------------------------------------------
// REQUIREMENT 5: Administrator can approve, reject, and revoke the Owner-Direct claim
// -----------------------------------------------------------------------------
echo "\n--- REQUIREMENT 5: Admin moderation metabox save ---\n";

// 5a: Admin approves
wp_set_current_user($admin_user ? $admin_user->ID : 1);
$_POST = array(
    'propvendor_moderation_nonce' => wp_create_nonce('propvendor_moderation_nonce'),
    'propvendor_moderation_status' => 'Published',
    'propvendor_direct_owner_status' => 'approved',
);
propvendor_marketplace_handle_admin_moderation_save($test_post_id);
$admin_approved = get_post_meta($test_post_id, '_propvendor_direct_owner_status', true);
report('Admin can set status to approved', 'approved' === $admin_approved, "Status: {$admin_approved}");

// 5b: Admin rejects
$_POST['propvendor_direct_owner_status'] = 'rejected';
propvendor_marketplace_handle_admin_moderation_save($test_post_id);
$admin_rejected = get_post_meta($test_post_id, '_propvendor_direct_owner_status', true);
report('Admin can set status to rejected', 'rejected' === $admin_rejected, "Status: {$admin_rejected}");

// 5c: Admin revokes
$_POST['propvendor_direct_owner_status'] = 'revoked';
propvendor_marketplace_handle_admin_moderation_save($test_post_id);
$admin_revoked = get_post_meta($test_post_id, '_propvendor_direct_owner_status', true);
report('Admin can set status to revoked', 'revoked' === $admin_revoked, "Status: {$admin_revoked}");

// 5d: Non-admin attempt to save moderation handler
wp_set_current_user($vendor_a->ID);
$_POST['propvendor_direct_owner_status'] = 'approved';
propvendor_marketplace_handle_admin_moderation_save($test_post_id);
$non_admin_result = get_post_meta($test_post_id, '_propvendor_direct_owner_status', true);
report('Non-admin user cannot use admin moderation save handler', 'revoked' === $non_admin_result, "Status: {$non_admin_result}");

// -----------------------------------------------------------------------------
// REQUIREMENT 6: Vendor regulatory numbers save cleanly & Admin verification
// -----------------------------------------------------------------------------
echo "\n--- REQUIREMENT 6: Regulatory compliance & verification badge ---\n";

// Clear previous values
delete_user_meta($vendor_a->ID, 'propvendor_regulatory_verified');
delete_user_meta($vendor_a->ID, 'propvendor_lasrera_number');
delete_user_meta($vendor_a->ID, 'propvendor_agency_license');

// Vendor updates profile with LASRERA & Agency license
wp_set_current_user($vendor_a->ID);
$_POST = array(
    'propvendor_profile_nonce' => wp_create_nonce('propvendor_update_profile'),
    'propvendor_business_name' => 'Apex Realty Ltd',
    'propvendor_lasrera_number' => 'LASRERA/AGT/2026/007',
    'propvendor_agency_license' => 'REDAN/ABJ/2026/999',
    'propvendor_regulatory_verified' => '1', // Vendor tamper attempt
);
$_FILES = array();

try {
    propvendor_marketplace_handle_profile_update();
} catch (Exception $e) {
    // Intercepted redirect
}

$saved_lasrera = get_user_meta($vendor_a->ID, 'propvendor_lasrera_number', true);
$saved_license = get_user_meta($vendor_a->ID, 'propvendor_agency_license', true);
$reg_verified = get_user_meta($vendor_a->ID, 'propvendor_regulatory_verified', true);

report('Vendor LASRERA number saves cleanly', 'LASRERA/AGT/2026/007' === $saved_lasrera, "LASRERA: {$saved_lasrera}");
report('Vendor Agency/REDAN license saves cleanly', 'REDAN/ABJ/2026/999' === $saved_license, "License: {$saved_license}");
report('Vendor CANNOT self-verify regulatory status via profile form', '1' !== (string)$reg_verified, "Verified: {$reg_verified}");

// Public profile when unverified -> NO badge
$vendor_profile_html = propvendor_marketplace_vendor_profile_shortcode(array('vendor' => $vendor_a->user_nicename));
report('Regulatory shield badge NOT rendered when unverified', strpos($vendor_profile_html, 'State-Registered Professional (Verified License)') === false);

// Admin verifies vendor via admin user edit save
wp_set_current_user($admin_user ? $admin_user->ID : 1);
$_POST = array(
    'propvendor_regulatory_verified' => '1',
    'propvendor_lasrera_number' => 'LASRERA/AGT/2026/007',
    'propvendor_agency_license' => 'REDAN/ABJ/2026/999',
);
propvendor_marketplace_save_admin_user_profile_fields($vendor_a->ID);
$admin_reg_verified = get_user_meta($vendor_a->ID, 'propvendor_regulatory_verified', true);
report('Admin can verify vendor regulatory license', '1' === (string)$admin_reg_verified, "Verified: {$admin_reg_verified}");

// Public profile when verified -> BADGE IS SHOWN
$vendor_profile_html_verified = propvendor_marketplace_vendor_profile_shortcode(array('vendor' => $vendor_a->user_nicename));
report('Regulatory shield badge IS rendered on public profile when verified', strpos($vendor_profile_html_verified, 'State-Registered Professional (Verified License)') !== false);
report('LASRERA number displayed on public profile when verified', strpos($vendor_profile_html_verified, 'LASRERA/AGT/2026/007') !== false);
report('REDAN license displayed on public profile when verified', strpos($vendor_profile_html_verified, 'REDAN/ABJ/2026/999') !== false);

// -----------------------------------------------------------------------------
// REQUIREMENT 7: Anti-advance-fee warning displays on listing detail & threads
// -----------------------------------------------------------------------------
echo "\n--- REQUIREMENT 7: Universal Anti-Advance-Fee Notice ---\n";

$warning_needle = 'Never pay inspection fees, commitment fees, or transport money before seeing this vehicle or property in person';

// 7a: On listing detail
wp_update_post(array('ID' => $test_post_id, 'post_status' => 'publish'));
update_post_meta($test_post_id, 'propvendor_listing_status', 'Published');
delete_post_meta($test_post_id, 'propvendor_status');
$test_post = get_post($test_post_id);
setup_mock_singular_context($test_post);
$detail_view = propvendor_marketplace_render_listing_details($test_post->post_content);
report('Anti-fraud warning displays on listing detail page', strpos($detail_view, $warning_needle) !== false);

// 7b: Helper output
$helper_out = propvendor_marketplace_render_anti_fraud_notice();
report('propvendor_marketplace_render_anti_fraud_notice() renders correct card', strpos($helper_out, $warning_needle) !== false);

// -----------------------------------------------------------------------------
// REQUIREMENT 8: Vehicle-specific disclaimer displays ONLY on vehicle listings
// -----------------------------------------------------------------------------
echo "\n--- REQUIREMENT 8: Vehicle-Specific Legal Notice (CMRIS & Customs) ---\n";

$vehicle_needle = 'Vehicle Purchase Notice: PropVendor is an advertising platform. Buyers are strongly advised to independently verify the VIN (Chassis Number) with the Nigeria Police Central Motor Registry (CMRIS)';

// Create a synthetic vehicle listing
$vehicle_post_id = wp_insert_post(array(
    'post_title' => '2022 Toyota Corolla Leaked Test',
    'post_type' => 'vehicle_listing',
    'post_status' => 'publish',
    'post_author' => $vendor_a->ID,
    'post_content' => 'Clean foreign used vehicle.',
));
update_post_meta($vehicle_post_id, '_is_stage2_test', '1');
update_post_meta($vehicle_post_id, 'propvendor_price', '12000000');
update_post_meta($vehicle_post_id, 'propvendor_location', 'Victoria Island, Lagos');
update_post_meta($vehicle_post_id, 'propvendor_listing_status', 'Published');

// Create a synthetic property listing
$prop_post_id = wp_insert_post(array(
    'post_title' => '3 Bedroom Apartment',
    'post_type' => 'property_listing',
    'post_status' => 'publish',
    'post_author' => $vendor_a->ID,
    'post_content' => 'Luxury serviced apartment.',
));
update_post_meta($prop_post_id, '_is_stage2_test', '1');
update_post_meta($prop_post_id, 'propvendor_price', '45000000');
update_post_meta($prop_post_id, 'propvendor_location', 'Ikoyi, Lagos');
update_post_meta($prop_post_id, 'propvendor_listing_status', 'Published');

// Create a synthetic land listing
$land_post_id = wp_insert_post(array(
    'post_title' => '2 Plots of Dry Land',
    'post_type' => 'land_listing',
    'post_status' => 'publish',
    'post_author' => $vendor_a->ID,
    'post_content' => 'Dry table land with C of O.',
));
update_post_meta($land_post_id, '_is_stage2_test', '1');
update_post_meta($land_post_id, 'propvendor_price', '18000000');
update_post_meta($land_post_id, 'propvendor_location', 'Epe, Lagos');
update_post_meta($land_post_id, 'propvendor_listing_status', 'Published');

// Test vehicle detail
$vehicle_post = get_post($vehicle_post_id);
setup_mock_singular_context($vehicle_post);
$vehicle_detail = propvendor_marketplace_render_listing_details($vehicle_post->post_content);
report('CMRIS/Customs disclaimer IS displayed on vehicle_listing detail', strpos($vehicle_detail, $vehicle_needle) !== false);

// Test property detail
$prop_post = get_post($prop_post_id);
setup_mock_singular_context($prop_post);
$property_detail = propvendor_marketplace_render_listing_details($prop_post->post_content);
report('CMRIS/Customs disclaimer is NOT displayed on property_listing detail', strpos($property_detail, $vehicle_needle) === false);

// Test land detail
$land_post = get_post($land_post_id);
setup_mock_singular_context($land_post);
$land_detail = propvendor_marketplace_render_listing_details($land_post->post_content);
report('CMRIS/Customs disclaimer is NOT displayed on land_listing detail', strpos($land_detail, $vehicle_needle) === false);

// -----------------------------------------------------------------------------
// REQUIREMENT 9: Regression check on Contact Masking & Security
// -----------------------------------------------------------------------------
echo "\n--- REQUIREMENT 9: Regression check (Phase 3.1 & 3.2 Contact Masking) ---\n";

// 9a: Name validation
report('Clean name passes validation', true === propvendor_marketplace_validate_customer_name('Babajide Sanwo'));
report('Smuggled email in name is rejected', is_wp_error(propvendor_marketplace_validate_customer_name('buyer@email.com')));
report('Smuggled phone in name is rejected', is_wp_error(propvendor_marketplace_validate_customer_name('John 08012345678')));

// 9b: Message masking
$masked_sample = propvendor_marketplace_mask_message_contact_info('Call me on 08031234567 or email test@example.com for price');
report('Message contact numbers and emails are masked for locked leads',
    strpos($masked_sample, '[Protected Phone]') !== false &&
    strpos($masked_sample, '[Protected Email]') !== false &&
    strpos($masked_sample, '08031234567') === false &&
    strpos($masked_sample, 'test@example.com') === false
);

// 9c: Vendor entitlement helper
update_user_meta($vendor_a->ID, 'propvendor_vendor_plan', 'free');
$can_view_free = propvendor_marketplace_vendor_can_view_contact_info($vendor_a->ID, 0);
report('Free vendor without unlock cannot view contact info', false === $can_view_free);

update_user_meta($vendor_a->ID, 'propvendor_vendor_plan', 'premium');
$can_view_premium = propvendor_marketplace_vendor_can_view_contact_info($vendor_a->ID, 0);
report('Premium vendor can view contact info', true === $can_view_premium);

// Reset vendor plan back to free
update_user_meta($vendor_a->ID, 'propvendor_vendor_plan', 'free');

// Clean up synthetic posts
$clean_posts = get_posts(array(
    'post_type' => array('property_listing', 'vehicle_listing', 'land_listing'),
    'meta_key' => '_is_stage2_test',
    'posts_per_page' => -1,
    'post_status' => 'any',
));
foreach ($clean_posts as $p) {
    wp_delete_post($p->ID, true);
}

echo "\n====================================================================\n";
echo "  STAGE 2 TEST SUMMARY: {$passed_tests}/{$total_tests} PASSED (" . ($failed_tests === 0 ? "ALL PASS" : "{$failed_tests} FAILED") . ")\n";
echo "====================================================================\n";

if ($failed_tests > 0) {
    exit(1);
}
exit(0);
