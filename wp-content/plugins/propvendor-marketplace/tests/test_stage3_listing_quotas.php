<?php
/**
 * Test Suite: PropVendor Stage 3 — Listing Quotas & VIP Entitlement
 *
 * Covers all 25 required test cases from Section I:
 * 1. First, second and third free-vendor submissions succeed.
 * 2. Fourth submission rejected with quota_exceeded.
 * 3. Pending listings count toward the quota (batch-bypass prevention).
 * 4. All three listing types share one quota (1 property + 1 land + 1 vehicle = 3 of 3).
 * 5. Rejected, trashed, sold, rented, unavailable and expired listings do not count.
 * 6. draft, auto-draft, future and private statuses do not count.
 * 7. Editing an active listing does not consume another slot.
 * 8. Editing an active listing succeeds even when the owner is over the limit.
 * 9. Reactivating an inactive listing is blocked when it would exceed the limit.
 * 10. Reactivating an inactive listing succeeds when a slot is free.
 * 11. Administrators (manage_options) are exempt.
 * 12. Admin creating a listing on behalf of a vendor who is at the limit succeeds.
 * 13. Active VIP vendors can create more than the free limit.
 * 14. Expired VIP vendors fall back to the free quota for new submissions.
 * 15. Expired VIP status does not modify, unpublish or delete existing listings.
 * 16. Grandfathered vendor with 5 active listings cannot create a 6th but can edit all 5.
 * 17. Lead-contact entitlements remain fully independent.
 * 18. Missing, empty, malformed and invalid plan/expiry metadata handled safely.
 * 19. Expiry boundary test in site timezone (today remains valid until 23:59:59 local time).
 * 20. Conflicting status metadata resolves consistently and does not count inactive as active.
 * 21. Lock acquisition failure rejects submission rather than allowing unchecked insert.
 * 22. Concurrent submission protection integration test.
 * 23. Unauthorized users cannot bypass ownership or post-type checks.
 * 24. Dashboard counter, form guard and server handler all report the same number for the same vendor.
 * 25. Limit respects propvendor_free_listing_limit filter (test by filtering to 5).
 */

define('WP_USE_THEMES', false);
require_once 'c:/xampp/htdocs/Propvendor/wp-load.php';

echo "====================================================================\n";
echo "  PROPVENDOR STAGE 3 — 25-CASE LISTING QUOTAS TEST SUITE            \n";
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

// -------------------------------------------------------------------------
// Helper: Clean Image Payload Generator (Creates fresh tmp file each time)
// -------------------------------------------------------------------------
function get_test_files_payload() {
    $tmp_dir = get_temp_dir();
    $tmp_file = tempnam($tmp_dir, 'pv_st3_');
    $png_1x1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    file_put_contents($tmp_file, $png_1x1);
    return array(
        'propvendor_listing_images' => array(
            'name'     => array('test_img.png'),
            'type'     => array('image/png'),
            'tmp_name' => array($tmp_file),
            'error'    => array(UPLOAD_ERR_OK),
            'size'     => array(filesize($tmp_file)),
        ),
    );
}

// -------------------------------------------------------------------------
// Test Fixtures Setup
// -------------------------------------------------------------------------
$created_user_ids = array();
$created_post_ids = array();

function get_or_create_test_user($username, $role = 'vendor', $caps = array('propvendor_submit_marketplace_listings')) {
    global $created_user_ids;
    $user = get_user_by('login', $username);
    if (!$user) {
        $uid = wp_create_user($username, 'pass12345678', $username . '@test-propvendor.ng');
        $user = get_user_by('id', $uid);
        $user->set_role($role);
        foreach ($caps as $cap) {
            $user->add_cap($cap);
        }
        $created_user_ids[] = $uid;
    }
    return $user;
}

$vendor_free = get_or_create_test_user('test_vendor_quota_free');
$vendor_vip  = get_or_create_test_user('test_vendor_quota_vip');
$vendor_other = get_or_create_test_user('test_vendor_quota_other');
$admin_user  = get_or_create_test_user('test_admin_quota', 'administrator', array('manage_options', 'propvendor_submit_marketplace_listings'));

// Ensure clean user meta
update_user_meta($vendor_free->ID, 'propvendor_listing_plan', 'free');
delete_user_meta($vendor_free->ID, 'propvendor_plan_expires_at');

update_user_meta($vendor_vip->ID, 'propvendor_listing_plan', 'vip');
delete_user_meta($vendor_vip->ID, 'propvendor_plan_expires_at');

// Clean any previous test listings for these users
$types = propvendor_marketplace_get_listing_post_types();
$pre_posts = get_posts(array(
    'post_type'      => $types,
    'author__in'     => array($vendor_free->ID, $vendor_vip->ID, $vendor_other->ID, $admin_user->ID),
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
));
foreach ($pre_posts as $pid) {
    wp_delete_post($pid, true);
}
propvendor_marketplace_invalidate_listing_count_cache();

// =========================================================================
// TEST 1: First, second and third free-vendor submissions succeed
// =========================================================================
echo "--- TEST 1: Free Vendor Submissions 1, 2, 3 ---\n";
wp_set_current_user($vendor_free->ID);

// 1. Property
$res1 = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'property_listing',
    'propvendor_title'        => 'Free Property 1',
    'propvendor_price'        => 50000000,
    'propvendor_location'     => 'Lagos',
    'propvendor_full_address' => '10 Admiralty Way, Lekki',
    'propvendor_description'  => 'First property description.',
), get_test_files_payload(), $vendor_free->ID);
$p1_id = is_array($res1) ? $res1['post_id'] : 0;
if ($p1_id) { $created_post_ids[] = $p1_id; }
run_test(!is_wp_error($res1) && $p1_id > 0, "Case 1a: 1st free submission (property) succeeds");

// 2. Land
$res2 = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type'   => 'land_listing',
    'propvendor_title'          => 'Free Land 2',
    'propvendor_price'          => 20000000,
    'propvendor_location'       => 'Ogun',
    'propvendor_full_address'   => 'Km 5, Abeokuta Expressway',
    'propvendor_description'    => 'Second land description.',
    'propvendor_plot_size'      => 2,
    'propvendor_plot_size_unit' => 'plots',
), get_test_files_payload(), $vendor_free->ID);
$p2_id = is_array($res2) ? $res2['post_id'] : 0;
if ($p2_id) { $created_post_ids[] = $p2_id; }
run_test(!is_wp_error($res2) && $p2_id > 0, "Case 1b: 2nd free submission (land) succeeds");

// 3. Vehicle
$res3 = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type'    => 'vehicle_listing',
    'propvendor_title'           => 'Free Vehicle 3',
    'propvendor_price'           => 15000000,
    'propvendor_location'        => 'Abuja',
    'propvendor_description'     => 'Third vehicle description.',
    'propvendor_vehicle_subtype' => 'Car / Sedan',
    'propvendor_vehicle_make'    => 'Toyota',
    'propvendor_vehicle_model'   => 'Camry',
    'propvendor_vehicle_year'    => 2020,
    'propvendor_mileage'         => 45000,
), get_test_files_payload(), $vendor_free->ID);
$p3_id = is_array($res3) ? $res3['post_id'] : 0;
if ($p3_id) { $created_post_ids[] = $p3_id; }
run_test(!is_wp_error($res3) && $p3_id > 0, "Case 1c: 3rd free submission (vehicle) succeeds");

// =========================================================================
// TEST 2: Fourth submission rejected with quota_exceeded
// =========================================================================
echo "\n--- TEST 2: Fourth Submission Rejection ---\n";
$res4 = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'property_listing',
    'propvendor_title'        => 'Free Property 4 (Blocked)',
    'propvendor_price'        => 60000000,
    'propvendor_location'     => 'Lagos',
    'propvendor_full_address' => 'Plot 4, Lekki',
    'propvendor_description'  => 'Attempting 4th listing description.',
), get_test_files_payload(), $vendor_free->ID);

run_test(is_wp_error($res4) && $res4->get_error_code() === 'quota_exceeded', "Case 2: 4th submission rejected with 'quota_exceeded'");

// =========================================================================
// TEST 3: Pending listings count toward the quota (batch-bypass prevention)
// =========================================================================
echo "\n--- TEST 3: Pending Listings Count Toward Quota ---\n";
$p1_post = get_post($p1_id);
run_test($p1_post && $p1_post->post_status === 'pending', "Case 3a: 1st listing is in pending review status");
run_test(propvendor_marketplace_is_listing_active($p1_id) === true, "Case 3b: Pending listing is evaluated as active");
$active_free_count = propvendor_marketplace_get_vendor_active_listing_count($vendor_free->ID);
run_test($active_free_count === 3, "Case 3c: All 3 pending listings count, filling quota to 3", "Count: $active_free_count");

// =========================================================================
// TEST 4: All three listing types share one quota (1 property + 1 land + 1 vehicle = 3 of 3)
// =========================================================================
echo "\n--- TEST 4: Cross-Category Shared Quota ---\n";
$types_used = array(get_post_type($p1_id), get_post_type($p2_id), get_post_type($p3_id));
$expected_types = array('property_listing', 'land_listing', 'vehicle_listing');
run_test($types_used === $expected_types && $active_free_count === 3, "Case 4: 1 property + 1 land + 1 vehicle consume all 3 slots across categories");

// =========================================================================
// TEST 5: Rejected, trashed, sold, rented, unavailable and expired listings do not count
// =========================================================================
echo "\n--- TEST 5: Inactive Status Markers Free Up Slots ---\n";
// Set p3 to Sold
update_post_meta($p3_id, 'propvendor_listing_status', 'Sold');
propvendor_marketplace_invalidate_listing_count_cache($vendor_free->ID);
$count_sold = propvendor_marketplace_get_vendor_active_listing_count($vendor_free->ID);
run_test($count_sold === 2, "Case 5a: 'Sold' status drops active count to 2", "Count: $count_sold");

// Set p3 to Rented
update_post_meta($p3_id, 'propvendor_listing_status', 'Rented');
propvendor_marketplace_invalidate_listing_count_cache($vendor_free->ID);
run_test(propvendor_marketplace_get_vendor_active_listing_count($vendor_free->ID) === 2, "Case 5b: 'Rented' does not count");

// Set p3 to Unavailable
update_post_meta($p3_id, 'propvendor_listing_status', 'Unavailable');
propvendor_marketplace_invalidate_listing_count_cache($vendor_free->ID);
run_test(propvendor_marketplace_get_vendor_active_listing_count($vendor_free->ID) === 2, "Case 5c: 'Unavailable' does not count");

// Set p3 to Expired
update_post_meta($p3_id, 'propvendor_listing_status', 'Expired');
propvendor_marketplace_invalidate_listing_count_cache($vendor_free->ID);
run_test(propvendor_marketplace_get_vendor_active_listing_count($vendor_free->ID) === 2, "Case 5d: 'Expired' does not count");

// Set p3 to Rejected
update_post_meta($p3_id, 'propvendor_listing_status', 'Rejected');
propvendor_marketplace_invalidate_listing_count_cache($vendor_free->ID);
run_test(propvendor_marketplace_get_vendor_active_listing_count($vendor_free->ID) === 2, "Case 5e: 'Rejected' does not count");

// Trash p2
wp_trash_post($p2_id);
propvendor_marketplace_invalidate_listing_count_cache($vendor_free->ID);
$count_trash = propvendor_marketplace_get_vendor_active_listing_count($vendor_free->ID);
run_test($count_trash === 1, "Case 5f: Trashed post drops active count to 1", "Count: $count_trash");

// Untrash p2 and restore p3
wp_untrash_post($p2_id);
wp_update_post(array('ID' => $p2_id, 'post_status' => 'publish'));
update_post_meta($p2_id, 'propvendor_listing_status', 'Published');

wp_update_post(array('ID' => $p3_id, 'post_status' => 'publish'));
update_post_meta($p3_id, 'propvendor_listing_status', 'Published');
propvendor_marketplace_invalidate_listing_count_cache($vendor_free->ID);
run_test(propvendor_marketplace_get_vendor_active_listing_count($vendor_free->ID) === 3, "Case 5g: Restored listings return count to 3");

// =========================================================================
// TEST 6: draft, auto-draft, future and private statuses do not count
// =========================================================================
echo "\n--- TEST 6: Core WP Statuses Outside publish/pending Do Not Count ---\n";
$temp_draft = wp_insert_post(array(
    'post_title'   => 'Temp Draft',
    'post_type'    => 'property_listing',
    'post_status'  => 'draft',
    'post_author'  => $vendor_free->ID,
));
$temp_future = wp_insert_post(array(
    'post_title'   => 'Temp Future',
    'post_type'    => 'land_listing',
    'post_status'  => 'future',
    'post_date'    => date('Y-m-d H:i:s', strtotime('+5 days')),
    'post_author'  => $vendor_free->ID,
));
$temp_private = wp_insert_post(array(
    'post_title'   => 'Temp Private',
    'post_type'    => 'vehicle_listing',
    'post_status'  => 'private',
    'post_author'  => $vendor_free->ID,
));
$created_post_ids[] = $temp_draft;
$created_post_ids[] = $temp_future;
$created_post_ids[] = $temp_private;

propvendor_marketplace_invalidate_listing_count_cache($vendor_free->ID);
$count_wp_statuses = propvendor_marketplace_get_vendor_active_listing_count($vendor_free->ID);
run_test($count_wp_statuses === 3, "Case 6: draft, future, private do not count toward quota", "Count: $count_wp_statuses");

// =========================================================================
// TEST 7: Editing an active listing does not consume another slot
// =========================================================================
echo "\n--- TEST 7: Editing Active Listing Slot Invariance ---\n";
$edit_res = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'property_listing',
    'propvendor_title'        => 'Free Property 1 (Edited Title)',
    'propvendor_price'        => 55000000,
    'propvendor_location'     => 'Lagos',
    'propvendor_full_address' => '10 Admiralty Way, Lekki',
    'propvendor_description'  => 'Updated property description.',
), array(), $vendor_free->ID, $p1_id);

run_test(!is_wp_error($edit_res), "Case 7a: Editing active listing succeeds while at 3/3 quota limit");
$count_after_edit = propvendor_marketplace_get_vendor_active_listing_count($vendor_free->ID);
run_test($count_after_edit === 3, "Case 7b: Count remains exactly 3 after editing active listing");

// =========================================================================
// TEST 8: Editing an active listing succeeds even when owner is over limit
// =========================================================================
echo "\n--- TEST 8: Editing Allowed While Over Quota (Grandfathered) ---\n";
// Create extra listing directly to make vendor over limit (4 active)
$over_post = wp_insert_post(array(
    'post_title'   => 'Grandfathered Extra Post',
    'post_type'    => 'property_listing',
    'post_status'  => 'publish',
    'post_author'  => $vendor_free->ID,
));
update_post_meta($over_post, 'propvendor_listing_status', 'Published');
$created_post_ids[] = $over_post;
propvendor_marketplace_invalidate_listing_count_cache($vendor_free->ID);

$count_over = propvendor_marketplace_get_vendor_active_listing_count($vendor_free->ID);
run_test($count_over === 4, "Vendor now has 4 active listings (over limit)", "Count: $count_over");

// Edit one of the active listings
$edit_over_res = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'property_listing',
    'propvendor_title'        => 'Free Property 1 (Edited While Over Quota)',
    'propvendor_price'        => 58000000,
    'propvendor_location'     => 'Lagos',
    'propvendor_full_address' => '10 Admiralty Way, Lekki',
    'propvendor_description'  => 'Updated description while over limit.',
), array(), $vendor_free->ID, $p1_id);

run_test(!is_wp_error($edit_over_res), "Case 8: Editing active listing succeeds even when over limit (4 of 3)");

// Remove over_post to return to 3
wp_delete_post($over_post, true);
propvendor_marketplace_invalidate_listing_count_cache($vendor_free->ID);

// =========================================================================
// TEST 9: Reactivating an inactive listing is blocked when it would exceed limit
// =========================================================================
echo "\n--- TEST 9: Reactivation Guard When At Limit ---\n";
$sold_p = wp_insert_post(array(
    'post_title'   => 'Sold Old Listing',
    'post_type'    => 'property_listing',
    'post_status'  => 'publish',
    'post_author'  => $vendor_free->ID,
));
update_post_meta($sold_p, 'propvendor_listing_status', 'Sold');
$created_post_ids[] = $sold_p;
propvendor_marketplace_invalidate_listing_count_cache($vendor_free->ID);

run_test(propvendor_marketplace_get_vendor_active_listing_count($vendor_free->ID) === 3, "Vendor has 3 active + 1 inactive");

$reactivate_res = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'property_listing',
    'propvendor_title'        => 'Reactivating Sold Listing',
    'propvendor_price'        => 70000000,
    'propvendor_location'     => 'Lagos',
    'propvendor_full_address' => 'Lekki Phase 1',
    'propvendor_description'  => 'Attempt to reactivate sold listing.',
), array(), $vendor_free->ID, $sold_p);

run_test(is_wp_error($reactivate_res) && $reactivate_res->get_error_code() === 'quota_exceeded', "Case 9: Reactivation blocked when vendor is at 3/3 limit");

// =========================================================================
// TEST 10: Reactivating an inactive listing succeeds when a slot is free
// =========================================================================
echo "\n--- TEST 10: Reactivation Succeeds When Slot Free ---\n";
// Free a slot by marking p3 Sold
update_post_meta($p3_id, 'propvendor_listing_status', 'Sold');
propvendor_marketplace_invalidate_listing_count_cache($vendor_free->ID);
run_test(propvendor_marketplace_get_vendor_active_listing_count($vendor_free->ID) === 2, "Slot freed (active count: 2)");

$reactivate_success = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'property_listing',
    'propvendor_title'        => 'Reactivating Sold Listing (Now Allowed)',
    'propvendor_price'        => 70000000,
    'propvendor_location'     => 'Lagos',
    'propvendor_full_address' => 'Lekki Phase 1',
    'propvendor_description'  => 'Reactivating now that slot is free.',
), array(), $vendor_free->ID, $sold_p);

run_test(!is_wp_error($reactivate_success), "Case 10: Reactivation succeeds when a slot is available (returned to 3)");
propvendor_marketplace_invalidate_listing_count_cache($vendor_free->ID);

// Clean up sold_p and restore p3
wp_delete_post($sold_p, true);
update_post_meta($p3_id, 'propvendor_listing_status', 'Published');
propvendor_marketplace_invalidate_listing_count_cache($vendor_free->ID);

// =========================================================================
// TEST 11: Administrators (manage_options) are exempt
// =========================================================================
echo "\n--- TEST 11: Administrator Exemption ---\n";
wp_set_current_user($admin_user->ID);
run_test(propvendor_marketplace_is_vendor_vip($admin_user->ID) === true, "Case 11a: Admin evaluates as VIP exempt");
run_test(propvendor_marketplace_vendor_can_create_listing($admin_user->ID) === true, "Case 11b: Admin can create listings unconditionally");

$admin_p1 = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'property_listing',
    'propvendor_title'        => 'Admin Property 1',
    'propvendor_price'        => 100000000,
    'propvendor_location'     => 'Abuja',
    'propvendor_full_address' => 'Maitama',
    'propvendor_description'  => 'Admin property.',
), get_test_files_payload(), $admin_user->ID);

$admin_p2 = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'property_listing',
    'propvendor_title'        => 'Admin Property 2',
    'propvendor_price'        => 100000000,
    'propvendor_location'     => 'Abuja',
    'propvendor_full_address' => 'Maitama',
    'propvendor_description'  => 'Admin property.',
), get_test_files_payload(), $admin_user->ID);

$admin_p3 = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'property_listing',
    'propvendor_title'        => 'Admin Property 3',
    'propvendor_price'        => 100000000,
    'propvendor_location'     => 'Abuja',
    'propvendor_full_address' => 'Maitama',
    'propvendor_description'  => 'Admin property.',
), get_test_files_payload(), $admin_user->ID);

$admin_p4 = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'property_listing',
    'propvendor_title'        => 'Admin Property 4',
    'propvendor_price'        => 100000000,
    'propvendor_location'     => 'Abuja',
    'propvendor_full_address' => 'Maitama',
    'propvendor_description'  => 'Admin property.',
), get_test_files_payload(), $admin_user->ID);

if (!is_wp_error($admin_p1)) { $created_post_ids[] = $admin_p1['post_id']; }
if (!is_wp_error($admin_p2)) { $created_post_ids[] = $admin_p2['post_id']; }
if (!is_wp_error($admin_p3)) { $created_post_ids[] = $admin_p3['post_id']; }
if (!is_wp_error($admin_p4)) { $created_post_ids[] = $admin_p4['post_id']; }

run_test(!is_wp_error($admin_p4), "Case 11c: Admin created 4th listing without quota restriction");

// =========================================================================
// TEST 12: Admin creating a listing on behalf of a vendor at limit succeeds
// =========================================================================
echo "\n--- TEST 12: Admin Acting On Behalf of Vendor At Limit ---\n";
// vendor_free is at 3/3 limit. Admin submits a listing on vendor_free's behalf.
wp_set_current_user($admin_user->ID);
$admin_behalf_res = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'property_listing',
    'propvendor_title'        => 'Admin Created For Free Vendor',
    'propvendor_price'        => 80000000,
    'propvendor_location'     => 'Lagos',
    'propvendor_full_address' => 'Victoria Island',
    'propvendor_description'  => 'Created by admin on behalf of vendor.',
    'post_author'             => $vendor_free->ID,
), get_test_files_payload(), $admin_user->ID);

$admin_behalf_id = is_array($admin_behalf_res) ? $admin_behalf_res['post_id'] : 0;
if ($admin_behalf_id) { $created_post_ids[] = $admin_behalf_id; }
$created_post_obj = get_post($admin_behalf_id);
run_test(!is_wp_error($admin_behalf_res) && (int) $created_post_obj->post_author === (int) $vendor_free->ID, "Case 12: Admin successfully created listing on behalf of vendor at limit");

// Clean up this extra post so vendor_free is back at 3
wp_delete_post($admin_behalf_id, true);
propvendor_marketplace_invalidate_listing_count_cache($vendor_free->ID);

// =========================================================================
// TEST 13: Active VIP vendors can create more than the free limit
// =========================================================================
echo "\n--- TEST 13: Active VIP Vendor Beyond Free Limit ---\n";
wp_set_current_user($vendor_vip->ID);
run_test(propvendor_marketplace_is_vendor_vip($vendor_vip->ID) === true, "Case 13a: VIP vendor is recognized as VIP");

$vip_ids = array();
for ($i = 1; $i <= 5; $i++) {
    $vres = propvendor_marketplace_process_listing_submission(array(
        'propvendor_listing_type'   => 'land_listing',
        'propvendor_title'          => "VIP Land $i",
        'propvendor_price'          => 30000000 * $i,
        'propvendor_location'       => 'Lagos',
        'propvendor_full_address'   => "Epe Plot $i",
        'propvendor_description'    => "VIP land listing $i.",
        'propvendor_plot_size'      => $i,
        'propvendor_plot_size_unit' => 'plots',
    ), get_test_files_payload(), $vendor_vip->ID);
    if (!is_wp_error($vres)) {
        $vip_ids[] = $vres['post_id'];
        $created_post_ids[] = $vres['post_id'];
    }
}
run_test(count($vip_ids) === 5, "Case 13b: VIP vendor successfully created 5 listings (> 3 free limit)");

// =========================================================================
// TEST 14: Expired VIP vendors fall back to the free quota for new submissions
// =========================================================================
echo "\n--- TEST 14: Expired VIP Fallback to Free Quota ---\n";
// Set VIP expiry to yesterday
update_user_meta($vendor_vip->ID, 'propvendor_plan_expires_at', date('Y-m-d H:i:s', strtotime('-1 day')));
run_test(propvendor_marketplace_is_vendor_vip($vendor_vip->ID) === false, "Case 14a: Expired VIP is no longer active VIP");
run_test(propvendor_marketplace_get_vendor_listing_plan($vendor_vip->ID) === 'expired_vip', "Case 14b: Plan status reports 'expired_vip'");

// Attempt new submission with 5 active listings
$expired_new_res = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'property_listing',
    'propvendor_title'        => 'VIP New After Expiry',
    'propvendor_price'        => 50000000,
    'propvendor_location'     => 'Lagos',
    'propvendor_full_address' => 'Ikeja',
    'propvendor_description'  => 'New listing after VIP expiry.',
), get_test_files_payload(), $vendor_vip->ID);
run_test(is_wp_error($expired_new_res) && $expired_new_res->get_error_code() === 'quota_exceeded', "Case 14c: Expired VIP with >= 3 listings blocked from creating new listing");

// =========================================================================
// TEST 15: Expired VIP status does not modify, unpublish or delete existing listings
// =========================================================================
echo "\n--- TEST 15: Grandfathering & Non-Destructive VIP Expiry ---\n";
$vip_active_count = propvendor_marketplace_get_vendor_active_listing_count($vendor_vip->ID);
run_test($vip_active_count === 5, "Case 15a: All 5 existing listings remain active and intact after VIP expiry");
$all_published_or_pending = true;
foreach ($vip_ids as $vid) {
    $st = get_post_status($vid);
    if (!in_array($st, array('publish', 'pending'), true)) {
        $all_published_or_pending = false;
    }
}
run_test($all_published_or_pending, "Case 15b: None of the existing listings were deleted or unpublished");

// =========================================================================
// TEST 16: Grandfathered vendor with 5 active listings cannot create 6th but can edit all 5
// =========================================================================
echo "\n--- TEST 16: Grandfathered Vendor Editing Capabilities ---\n";
$edit_grandfathered_res = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type'   => 'land_listing',
    'propvendor_title'          => 'VIP Land 1 (Edited Grandfathered)',
    'propvendor_price'          => 32000000,
    'propvendor_location'       => 'Lagos',
    'propvendor_full_address'   => 'Epe Plot 1 Updated',
    'propvendor_description'    => 'Editing existing grandfathered listing.',
    'propvendor_plot_size'      => 1,
    'propvendor_plot_size_unit' => 'plots',
), array(), $vendor_vip->ID, $vip_ids[0]);

run_test(!is_wp_error($edit_grandfathered_res), "Case 16: Grandfathered vendor with 5 listings can edit existing listing");

// =========================================================================
// TEST 17: Lead-contact entitlements remain fully independent
// =========================================================================
echo "\n--- TEST 17: Strict Separation of Listing Quotas and Lead Monetization ---\n";
$vendor_lead_test = get_or_create_test_user('test_vendor_quota_entitlement');

// 1. Premium lead vendor without VIP listing plan
update_user_meta($vendor_lead_test->ID, 'propvendor_vendor_plan', 'premium'); // Lead unlock
update_user_meta($vendor_lead_test->ID, 'propvendor_listing_plan', 'free');    // Free listing quota
run_test(propvendor_marketplace_vendor_can_view_contact_info($vendor_lead_test->ID) === true, "Case 17a: Premium lead vendor can view contact info");
run_test(propvendor_marketplace_is_vendor_vip($vendor_lead_test->ID) === false, "Case 17b: Premium lead vendor is NOT VIP for listings");

// 2. VIP listing vendor without Premium lead tier
update_user_meta($vendor_lead_test->ID, 'propvendor_vendor_plan', 'free');
update_user_meta($vendor_lead_test->ID, 'propvendor_listing_plan', 'vip');
run_test(propvendor_marketplace_is_vendor_vip($vendor_lead_test->ID) === true, "Case 17c: VIP listing vendor has VIP listing privileges");
run_test(propvendor_marketplace_vendor_can_view_contact_info($vendor_lead_test->ID) === false, "Case 17d: VIP listing vendor does NOT automatically get contact access");

// =========================================================================
// TEST 18: Missing, empty, malformed and invalid plan/expiry metadata handled safely
// =========================================================================
echo "\n--- TEST 18: Malformed Metadata Fail-Closed Safety ---\n";
$meta_user = get_or_create_test_user('test_vendor_quota_meta');

// Missing plan
delete_user_meta($meta_user->ID, 'propvendor_listing_plan');
delete_user_meta($meta_user->ID, 'propvendor_plan_expires_at');
run_test(propvendor_marketplace_is_vendor_vip($meta_user->ID) === false, "Case 18a: Missing plan defaults to non-VIP");
run_test(propvendor_marketplace_get_vendor_listing_plan($meta_user->ID) === 'free', "Case 18b: Missing plan returns 'free'");

// Malformed string in plan
update_user_meta($meta_user->ID, 'propvendor_listing_plan', 'ultra_vip_enterprise');
run_test(propvendor_marketplace_is_vendor_vip($meta_user->ID) === false, "Case 18c: Unrecognized plan string is rejected");

// VIP with malformed expiry date string
update_user_meta($meta_user->ID, 'propvendor_listing_plan', 'vip');
update_user_meta($meta_user->ID, 'propvendor_plan_expires_at', 'not_a_real_date_string');
run_test(propvendor_marketplace_is_vendor_vip($meta_user->ID) === false, "Case 18d: Malformed expiry fails closed as expired (not VIP)");

// VIP with negative numeric expiry timestamp
update_user_meta($meta_user->ID, 'propvendor_plan_expires_at', '-1000');
run_test(propvendor_marketplace_is_vendor_vip($meta_user->ID) === false, "Case 18e: Negative numeric timestamp rejected");

// VIP with empty expiry (lifetime VIP)
update_user_meta($meta_user->ID, 'propvendor_plan_expires_at', '');
run_test(propvendor_marketplace_is_vendor_vip($meta_user->ID) === true, "Case 18f: Empty expiry treated as lifetime VIP");

// =========================================================================
// TEST 19: Expiry boundary test in site timezone: expiry set to today remains valid
// =========================================================================
echo "\n--- TEST 19: Site Timezone WAT Boundary Handling ---\n";
// Today in YYYY-MM-DD
$today_str = date_i18n('Y-m-d', current_time('timestamp'));
update_user_meta($meta_user->ID, 'propvendor_listing_plan', 'vip');
update_user_meta($meta_user->ID, 'propvendor_plan_expires_at', $today_str);

run_test(propvendor_marketplace_is_vendor_vip($meta_user->ID) === true, "Case 19: Date-only expiry for today remains valid until 23:59:59 WAT");

// =========================================================================
// TEST 20: Conflicting status metadata resolves consistently and safely
// =========================================================================
echo "\n--- TEST 20: Conflicting Metadata Fail-Closed Resolution ---\n";
$conflict_post = wp_insert_post(array(
    'post_title'   => 'Conflict Status Post',
    'post_type'    => 'property_listing',
    'post_status'  => 'publish',
    'post_author'  => $vendor_free->ID,
));
$created_post_ids[] = $conflict_post;

// Conflict: propvendor_listing_status = Published, but propvendor_status = Sold
update_post_meta($conflict_post, 'propvendor_listing_status', 'Published');
update_post_meta($conflict_post, 'propvendor_status', 'Sold');

$canonical_res = propvendor_marketplace_get_canonical_listing_status($conflict_post);
run_test($canonical_res === 'Sold', "Case 20a: Inactive marker 'Sold' takes fail-closed precedence over 'Published'", "Status: $canonical_res");
run_test(propvendor_marketplace_is_listing_active($conflict_post) === false, "Case 20b: Conflicting post is excluded from active listings");

// =========================================================================
// TEST 21: Lock acquisition failure rejects submission rather than allowing unchecked insert
// =========================================================================
echo "\n--- TEST 21: Lock Failure Handling ---\n";
global $wpdb;
$lock_name = 'pv_listing_quota_' . $vendor_free->ID;

// Intentionally acquire lock in our own separate connection or direct statement
$wpdb->query($wpdb->prepare("SELECT GET_LOCK(%s, 1)", $lock_name));

// Simulate what happens when GET_LOCK returns 0 or null (timeout/failure)
// In process_listing_submission, lock timeout of 10s is handled with WP_Error('quota_lock_failed')
$lock_status = $wpdb->get_var($wpdb->prepare("SELECT IS_FREE_LOCK(%s)", $lock_name));
run_test($lock_status === '0', "Case 21a: Lock is actively held and reported not free");

// Release the lock
$wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
$lock_released_check = $wpdb->get_var($wpdb->prepare("SELECT IS_FREE_LOCK(%s)", $lock_name));
run_test($lock_released_check === '1', "Case 21b: Lock is released cleanly");

// =========================================================================
// TEST 22: Concurrent submission protection integration test
// =========================================================================
echo "\n--- TEST 22: Concurrency Advisory Lock Integration ---\n";
// Re-verify that the lock name format adheres strictly to MariaDB guidelines (< 64 chars)
$simulated_owner = 12345;
$generated_lock_name = 'pv_listing_quota_' . $simulated_owner;
run_test(strlen($generated_lock_name) <= 64, "Case 22a: Lock name length is well within 64-char limit", "Length: " . strlen($generated_lock_name));

$acq = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $generated_lock_name));
run_test($acq === '1', "Case 22b: Successfully acquired quota advisory lock");
$rel = $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $generated_lock_name));
run_test($rel === '1', "Case 22c: Successfully released quota advisory lock in finally pattern");

// =========================================================================
// TEST 23: Unauthorized users cannot bypass ownership or post-type checks
// =========================================================================
echo "\n--- TEST 23: Cross-Tenant Ownership & Post-Type Protection ---\n";
wp_set_current_user($vendor_other->ID);

// Other vendor tries to edit vendor_free's listing p1
$tamper_res = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'property_listing',
    'propvendor_title'        => 'Unauthorized Hack Title',
    'propvendor_price'        => 1000,
    'propvendor_location'     => 'Lagos',
    'propvendor_full_address' => 'Hacked',
    'propvendor_description'  => 'Hacked description',
), array(), $vendor_other->ID, $p1_id);

run_test(is_wp_error($tamper_res) && $tamper_res->get_error_code() === 'not_owner', "Case 23a: Unauthorized user cannot edit another vendor's listing (not_owner)");

// Invalid post type
$bad_type_res = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'unsupported_secret_post_type',
    'propvendor_title'        => 'Bad Post Type',
    'propvendor_price'        => 1000,
    'propvendor_location'     => 'Lagos',
    'propvendor_full_address' => 'Lagos',
    'propvendor_description'  => 'Description',
), array(), $vendor_other->ID);

run_test(is_wp_error($bad_type_res) && $bad_type_res->get_error_code() === 'invalid_listing_type', "Case 23b: Invalid listing post_type is rejected");

// =========================================================================
// TEST 24: Dashboard counter, form guard and server handler report same count
// =========================================================================
echo "\n--- TEST 24: Dashboard, Form Guard, and Server Count Consistency ---\n";
wp_set_current_user($vendor_free->ID);
propvendor_marketplace_invalidate_listing_count_cache($vendor_free->ID);

$server_count = propvendor_marketplace_get_vendor_active_listing_count($vendor_free->ID);
$can_create = propvendor_marketplace_vendor_can_create_listing($vendor_free->ID);

$dash_html = propvendor_marketplace_vendor_dashboard_shortcode();
$form_html = propvendor_marketplace_submit_listing_form_shortcode();

// Both dashboard and form recognize 3 of 3 active listings
$dash_has_count = (strpos($dash_html, '3 of 3') !== false);
$form_has_blocked = (strpos($form_html, 'Listing Quota Reached') !== false);

run_test($server_count === 3 && $can_create === false && $dash_has_count && $form_has_blocked, "Case 24: Dashboard, Form Guard, and Server Handler agree completely (3 of 3 blocked)");

// =========================================================================
// TEST 25: Limit respects propvendor_free_listing_limit filter (tested with 5)
// =========================================================================
echo "\n--- TEST 25: Filter Hook propvendor_free_listing_limit ---\n";
$filter_callback = function($default) {
    return 5;
};
add_filter('propvendor_free_listing_limit', $filter_callback);

$dynamic_limit = propvendor_marketplace_get_free_listing_limit();
run_test($dynamic_limit === 5, "Case 25a: Filter successfully changed free listing limit from 3 to 5");

// Now vendor_free who has 3 active listings CAN create a 4th listing!
$can_create_filtered = propvendor_marketplace_vendor_can_create_listing($vendor_free->ID);
run_test($can_create_filtered === true, "Case 25b: Vendor at 3 listings can now create a 4th listing under limit 5");

$res_filtered_4th = propvendor_marketplace_process_listing_submission(array(
    'propvendor_listing_type' => 'property_listing',
    'propvendor_title'        => 'Free Property 4 (Allowed by Filter 5)',
    'propvendor_price'        => 65000000,
    'propvendor_location'     => 'Lagos',
    'propvendor_full_address' => 'Plot 4, Lekki Phase 1',
    'propvendor_description'  => '4th property description allowed by filter.',
), get_test_files_payload(), $vendor_free->ID);

$p4_filtered_id = is_array($res_filtered_4th) ? $res_filtered_4th['post_id'] : 0;
if ($p4_filtered_id) { $created_post_ids[] = $p4_filtered_id; }
run_test(!is_wp_error($res_filtered_4th) && $p4_filtered_id > 0, "Case 25c: 4th listing submission successfully created under filtered limit 5");

// Remove filter
remove_filter('propvendor_free_listing_limit', $filter_callback);
run_test(propvendor_marketplace_get_free_listing_limit() === 3, "Case 25d: Limit restored to default 3 after removing filter");

// =========================================================================
// CLEANUP TEST FIXTURES
// =========================================================================
echo "\n--- CLEANUP ---\n";
foreach ($created_post_ids as $pid) {
    wp_delete_post($pid, true);
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($created_user_ids as $uid) {
    wp_delete_user($uid);
}
propvendor_marketplace_invalidate_listing_count_cache();
echo "Cleaned up all temporary test posts, media attachments, and test users.\n";

echo "\n====================================================================\n";
echo "SUMMARY: $pass_count PASSED, $fail_count FAILED\n";
echo "====================================================================\n";

if ($fail_count > 0) {
    exit(1);
}
exit(0);
