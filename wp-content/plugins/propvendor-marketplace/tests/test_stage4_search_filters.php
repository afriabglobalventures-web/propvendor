<?php
/**
 * Test Suite: PropVendor Stage 4 — Search & Discovery Filters
 *
 * Verifies all 10 required test cases for Stage 4:
 * 1. Keyword search matches post titles, descriptions, and full address fields.
 * 2. State filtering (pv_state = 'Lagos') returns only listings in Lagos.
 * 3. Price range filtering (pv_min_price, pv_max_price) returns listings within range.
 * 4. Title document filtering (pv_title_doc = 'C of O') matches C of O land and property.
 * 5. Vehicle make/model/year filtering (pv_make, pv_min_year) matches vehicles.
 * 6. Land use and plot size unit filtering matches requested criteria.
 * 7. Sorting by price ascending and price descending returns correct post order.
 * 8. Inactive listings (Sold, Rented, Rejected, Unavailable, Expired, draft) excluded.
 * 9. Malformed, tampered, or invalid search parameters fail safely.
 * 10. Query parameters persist cleanly in form inputs after form submission.
 */

define('WP_USE_THEMES', false);
$wp_load = dirname(__DIR__, 4) . '/wp-load.php';
if (file_exists($wp_load)) {
    require_once $wp_load;
} else {
    require_once 'c:/xampp/htdocs/Propvendor/wp-load.php';
}

add_filter('pre_wp_mail', '__return_false');

echo "====================================================================\n";
echo "  PROPVENDOR STAGE 4 — SEARCH & DISCOVERY FILTERS TEST SUITE        \n";
echo "====================================================================\n\n";

$total_tests  = 0;
$passed_tests = 0;
$failed_tests = 0;

function run_test($condition, $name, $extra = '') {
    global $total_tests, $passed_tests, $failed_tests;
    $total_tests++;
    if ($condition) {
        $passed_tests++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed_tests++;
        echo "  [FAIL] {$name}" . ($extra ? " ({$extra})" : "") . "\n";
    }
}

$created_post_ids = array();
$orig_get = $_GET;

try {
    // -------------------------------------------------------------
    // Setup Test User
    // -------------------------------------------------------------
    $test_author = get_user_by('login', 'test_vendor_search_stage4');
    if (!$test_author) {
        $user_id = wp_create_user('test_vendor_search_stage4', 'PassSearchStage4!', 'search_test@propvendor.ng');
        $test_author = get_user_by('id', $user_id);
        $test_author->set_role('vendor');
    }
    $author_id = $test_author->ID;

    // Helper to create test listing with tracked cleanup
    $create_listing = function($title, $type, $price, $state, $address, $desc = '', $status = 'publish', $meta_status = 'Published', $extra_meta = array()) use (&$created_post_ids, $author_id) {
        $post_id = wp_insert_post(array(
            'post_title'   => $title,
            'post_content' => $desc,
            'post_type'    => $type,
            'post_status'  => $status,
            'post_author'  => $author_id,
        ));

        if ($post_id && !is_wp_error($post_id)) {
            $created_post_ids[] = $post_id;
            update_post_meta($post_id, 'propvendor_price', $price);
            update_post_meta($post_id, 'propvendor_location', $state);
            update_post_meta($post_id, 'propvendor_full_address', $address);
            update_post_meta($post_id, 'propvendor_listing_status', $meta_status);
            update_post_meta($post_id, 'propvendor_status', $meta_status);

            foreach ($extra_meta as $mk => $mv) {
                update_post_meta($post_id, $mk, $mv);
            }
        }

        return $post_id;
    };

    // -------------------------------------------------------------
    // Create Diverse Test Fixtures
    // -------------------------------------------------------------
    // Listing 1: Property in Lagos with C of O
    $l1 = $create_listing(
        'Luxury 4 Bedroom Villa in Lekki',
        'property_listing',
        85000000,
        'Lagos',
        'House 12, Admiralty Way, Lekki Phase 1',
        'Spacious villa with swimming pool and garden.',
        'publish',
        'Published',
        array(
            'propvendor_title_document'   => 'Certificate of Occupancy (C of O)',
            'propvendor_property_subtype' => 'Detached Duplex',
            'propvendor_bedrooms'         => 4,
            'propvendor_bathrooms'        => 5,
            'propvendor_furnishing'       => 'Furnished',
        )
    );

    // Listing 2: Property in Abuja with Governor's Consent
    $l2 = $create_listing(
        'Modern 2 Bedroom Apartment in Maitama',
        'property_listing',
        35000000,
        'FCT - Abuja',
        'Plot 402, Gana Street, Maitama',
        'Cozy executive apartment with underground parking.',
        'publish',
        'Published',
        array(
            'propvendor_title_document'   => "Governor's Consent",
            'propvendor_property_subtype' => 'Flat / Apartment',
            'propvendor_bedrooms'         => 2,
            'propvendor_bathrooms'        => 2,
            'propvendor_furnishing'       => 'Semi-Furnished',
        )
    );

    // Listing 3: Land in Lagos with C of O (Commercial)
    $l3 = $create_listing(
        'Prime Commercial Land along Lekki Epe Expressway',
        'land_listing',
        120000000,
        'Lagos',
        'Km 24, Lekki Epe Expressway, Sangotedo',
        'Fenced dry land facing the express road.',
        'publish',
        'Published',
        array(
            'propvendor_title_document' => 'Certificate of Occupancy (C of O)',
            'propvendor_land_use'       => 'Commercial',
            'propvendor_plot_size'      => 4,
            'propvendor_plot_size_unit' => 'plots',
        )
    );

    // Listing 4: Land in Ogun with Gazette (Residential)
    $l4 = $create_listing(
        'Affordable Residential Plots in Arepo',
        'land_listing',
        15000000,
        'Ogun',
        'Journalists Estate Road, Arepo',
        'Dry land in developed residential estate.',
        'publish',
        'Published',
        array(
            'propvendor_title_document' => 'Gazette',
            'propvendor_land_use'       => 'Residential',
            'propvendor_plot_size'      => 600,
            'propvendor_plot_size_unit' => 'sqm',
        )
    );

    // Listing 5: Vehicle Toyota Corolla 2019 in Lagos
    $l5 = $create_listing(
        'Clean 2019 Toyota Corolla SE',
        'vehicle_listing',
        18500000,
        'Lagos',
        'Showroom 4, Allen Avenue, Ikeja',
        'Super clean foreign used sedan with factory leather.',
        'publish',
        'Published',
        array(
            'propvendor_vehicle_subtype' => 'Car / Sedan',
            'propvendor_vehicle_make'    => 'Toyota',
            'propvendor_vehicle_model'   => 'Corolla',
            'propvendor_vehicle_year'    => 2019,
            'propvendor_condition'       => 'Foreign Used (Tokunbo)',
            'propvendor_transmission'    => 'Automatic',
        )
    );

    // Listing 6: Vehicle Mercedes GLE 2021 in Abuja
    $l6 = $create_listing(
        '2021 Mercedes-Benz GLE 450 4MATIC',
        'vehicle_listing',
        65000000,
        'FCT - Abuja',
        'Central Business District, Abuja',
        'Brand new luxury SUV with panoramic roof.',
        'publish',
        'Published',
        array(
            'propvendor_vehicle_subtype' => 'SUV',
            'propvendor_vehicle_make'    => 'Mercedes-Benz',
            'propvendor_vehicle_model'   => 'GLE 450',
            'propvendor_vehicle_year'    => 2021,
            'propvendor_condition'       => 'Brand New',
            'propvendor_transmission'    => 'Automatic',
        )
    );

    // Listing 7: Inactive listings (Sold, Rented, Rejected, Unavailable, Expired, Draft)
    $l_sold = $create_listing('Sold Duplex', 'property_listing', 40000000, 'Lagos', 'Lagos Address', 'Sold property', 'publish', 'Sold');
    $l_rented = $create_listing('Rented Flat', 'property_listing', 3000000, 'Lagos', 'Lagos Address', 'Rented flat', 'publish', 'Rented');
    $l_rejected = $create_listing('Rejected Land', 'land_listing', 10000000, 'Lagos', 'Lagos Address', 'Rejected land', 'publish', 'Rejected');
    $l_unavail = $create_listing('Unavailable Car', 'vehicle_listing', 9000000, 'Lagos', 'Lagos Address', 'Unavailable car', 'publish', 'Unavailable');
    $l_expired = $create_listing('Expired Land', 'land_listing', 8000000, 'Lagos', 'Lagos Address', 'Expired land', 'publish', 'Expired');
    $l_draft = $create_listing('Draft Property', 'property_listing', 50000000, 'Lagos', 'Lagos Address', 'Draft property', 'draft', 'Published');

    // -------------------------------------------------------------
    // TEST 1: Keyword search across title, description, and full address
    // -------------------------------------------------------------
    echo "\n--- TEST 1: Keyword Search Scope ---\n";
    // Title match
    $q_title = propvendor_marketplace_build_search_query(array('pv_keyword' => 'Villa'));
    $ids_title = wp_list_pluck($q_title->posts, 'ID');
    run_test(in_array($l1, $ids_title, true), 'Keyword matches post title ("Villa")');
    run_test(!in_array($l2, $ids_title, true), 'Unrelated title excluded from title search');

    // Description match
    $q_desc = propvendor_marketplace_build_search_query(array('pv_keyword' => 'underground parking'));
    $ids_desc = wp_list_pluck($q_desc->posts, 'ID');
    run_test(in_array($l2, $ids_desc, true), 'Keyword matches description ("underground parking")');

    // Full Address match (unique street name not present in title or content)
    $q_addr = propvendor_marketplace_build_search_query(array('pv_keyword' => 'Admiralty Way'));
    $ids_addr = wp_list_pluck($q_addr->posts, 'ID');
    run_test(in_array($l1, $ids_addr, true), 'Keyword matches full address ("Admiralty Way")');

    // Landmark / street in address for vehicle
    $q_allen = propvendor_marketplace_build_search_query(array('pv_keyword' => 'Allen Avenue'));
    $ids_allen = wp_list_pluck($q_allen->posts, 'ID');
    run_test(in_array($l5, $ids_allen, true), 'Keyword matches showroom/landmark address ("Allen Avenue")');

    // Negative keyword
    $q_none = propvendor_marketplace_build_search_query(array('pv_keyword' => 'NonExistentZebraXYZ'));
    run_test(empty($q_none->posts), 'Non-matching keyword returns empty results');

    // -------------------------------------------------------------
    // TEST 2: State filtering (pv_state)
    // -------------------------------------------------------------
    echo "\n--- TEST 2: State Filtering ---\n";
    $q_lagos = propvendor_marketplace_build_search_query(array('pv_state' => 'Lagos', 'posts_per_page' => 50));
    $ids_lagos = wp_list_pluck($q_lagos->posts, 'ID');
    run_test(in_array($l1, $ids_lagos, true) && in_array($l3, $ids_lagos, true) && in_array($l5, $ids_lagos, true), 'Lagos filter includes all active Lagos listings (Property, Land, Vehicle)');
    run_test(!in_array($l2, $ids_lagos, true) && !in_array($l4, $ids_lagos, true) && !in_array($l6, $ids_lagos, true), 'Lagos filter excludes Abuja and Ogun listings');

    // Abuja state filter
    $q_abuja = propvendor_marketplace_build_search_query(array('pv_state' => 'Abuja', 'posts_per_page' => 50));
    $ids_abuja = wp_list_pluck($q_abuja->posts, 'ID');
    run_test(in_array($l2, $ids_abuja, true) && in_array($l6, $ids_abuja, true), 'Abuja alias normalizes and returns FCT - Abuja listings');
    run_test(!in_array($l1, $ids_abuja, true), 'Abuja filter excludes Lagos listings');

    // Ogun state filter
    $q_ogun = propvendor_marketplace_build_search_query(array('pv_state' => 'Ogun', 'posts_per_page' => 50));
    $ids_ogun = wp_list_pluck($q_ogun->posts, 'ID');
    run_test(in_array($l4, $ids_ogun, true), 'Ogun filter returns Ogun land listing');

    // Backward-compatibility alias pv_location
    $q_loc_alias = propvendor_marketplace_build_search_query(array('pv_location' => 'Lagos', 'posts_per_page' => 50));
    $ids_loc_alias = wp_list_pluck($q_loc_alias->posts, 'ID');
    run_test(in_array($l1, $ids_loc_alias, true), 'pv_location alias correctly filters by state');

    // -------------------------------------------------------------
    // TEST 3: Price range filtering (pv_min_price, pv_max_price)
    // -------------------------------------------------------------
    echo "\n--- TEST 3: Price Range Filtering ---\n";
    // Range 10M to 50M
    $q_price_range = propvendor_marketplace_build_search_query(array(
        'pv_min_price'   => 10000000,
        'pv_max_price'   => 50000000,
        'posts_per_page' => 50,
    ));
    $ids_price = wp_list_pluck($q_price_range->posts, 'ID');
    run_test(in_array($l2, $ids_price, true), '35M listing included in 10M-50M range');
    run_test(in_array($l4, $ids_price, true), '15M listing included in 10M-50M range');
    run_test(in_array($l5, $ids_price, true), '18.5M listing included in 10M-50M range');
    run_test(!in_array($l1, $ids_price, true), '85M listing excluded (> 50M)');
    run_test(!in_array($l3, $ids_price, true), '120M listing excluded (> 50M)');
    run_test(!in_array($l6, $ids_price, true), '65M listing excluded (> 50M)');

    // Min price only: >= 60M
    $q_min_only = propvendor_marketplace_build_search_query(array('pv_min_price' => 60000000, 'posts_per_page' => 50));
    $ids_min = wp_list_pluck($q_min_only->posts, 'ID');
    run_test(in_array($l1, $ids_min, true) && in_array($l3, $ids_min, true) && in_array($l6, $ids_min, true), 'Min price filter (>= 60M) returns high-value listings');
    run_test(!in_array($l2, $ids_min, true), 'Min price filter excludes listings below 60M');

    // Max price only: <= 20M
    $q_max_only = propvendor_marketplace_build_search_query(array('pv_max_price' => 20000000, 'posts_per_page' => 50));
    $ids_max = wp_list_pluck($q_max_only->posts, 'ID');
    run_test(in_array($l4, $ids_max, true) && in_array($l5, $ids_max, true), 'Max price filter (<= 20M) returns affordable listings');
    run_test(!in_array($l2, $ids_max, true), 'Max price filter excludes 35M listing');

    // -------------------------------------------------------------
    // TEST 4: Title document filtering (pv_title_doc = 'C of O')
    // -------------------------------------------------------------
    echo "\n--- TEST 4: Title Document Filtering ---\n";
    $q_coo = propvendor_marketplace_build_search_query(array('pv_title_doc' => 'C of O', 'posts_per_page' => 50));
    $ids_coo = wp_list_pluck($q_coo->posts, 'ID');
    run_test(in_array($l1, $ids_coo, true), 'C of O filter matches Property with Certificate of Occupancy (C of O)');
    run_test(in_array($l3, $ids_coo, true), 'C of O filter matches Land with Certificate of Occupancy (C of O)');
    run_test(!in_array($l2, $ids_coo, true), "C of O filter excludes Governor's Consent property");
    run_test(!in_array($l4, $ids_coo, true), 'C of O filter excludes Gazette land');

    // Governor's consent filter
    $q_gov = propvendor_marketplace_build_search_query(array('pv_title_doc' => "Governor's Consent", 'posts_per_page' => 50));
    $ids_gov = wp_list_pluck($q_gov->posts, 'ID');
    run_test(in_array($l2, $ids_gov, true), "Governor's Consent filter matches matching property");
    run_test(!in_array($l1, $ids_gov, true), "Governor's Consent filter excludes C of O property");

    // -------------------------------------------------------------
    // TEST 5: Vehicle make, model, and year filtering
    // -------------------------------------------------------------
    echo "\n--- TEST 5: Vehicle Make / Model / Year Filtering ---\n";
    // Make: Toyota
    $q_toyota = propvendor_marketplace_build_search_query(array('pv_make' => 'Toyota', 'posts_per_page' => 50));
    $ids_toyota = wp_list_pluck($q_toyota->posts, 'ID');
    run_test(in_array($l5, $ids_toyota, true), 'Make filter matches Toyota vehicle');
    run_test(!in_array($l6, $ids_toyota, true), 'Make filter excludes Mercedes-Benz');

    // Make + Min Year: Mercedes-Benz >= 2020
    $q_benz = propvendor_marketplace_build_search_query(array(
        'pv_make'        => 'Mercedes-Benz',
        'pv_min_year'    => 2020,
        'posts_per_page' => 50,
    ));
    $ids_benz = wp_list_pluck($q_benz->posts, 'ID');
    run_test(in_array($l6, $ids_benz, true), 'Make & Min Year (Mercedes >= 2020) matches 2021 GLE');

    // Year range boundary test: 2020 to 2022
    $q_yr_range = propvendor_marketplace_build_search_query(array(
        'pv_min_year'    => 2020,
        'pv_max_year'    => 2022,
        'posts_per_page' => 50,
    ));
    $ids_yr = wp_list_pluck($q_yr_range->posts, 'ID');
    run_test(in_array($l6, $ids_yr, true), '2021 vehicle included in 2020-2022 year range');
    run_test(!in_array($l5, $ids_yr, true), '2019 vehicle excluded from 2020-2022 year range');

    // Model filter: Corolla
    $q_corolla = propvendor_marketplace_build_search_query(array('pv_model' => 'Corolla', 'posts_per_page' => 50));
    $ids_corolla = wp_list_pluck($q_corolla->posts, 'ID');
    run_test(in_array($l5, $ids_corolla, true), 'Model filter matches Corolla');

    // Condition filter: Brand New
    $q_brand_new = propvendor_marketplace_build_search_query(array('pv_condition' => 'Brand New', 'posts_per_page' => 50));
    $ids_bn = wp_list_pluck($q_brand_new->posts, 'ID');
    run_test(in_array($l6, $ids_bn, true), 'Condition filter matches Brand New');
    run_test(!in_array($l5, $ids_bn, true), 'Condition filter excludes Foreign Used');

    // -------------------------------------------------------------
    // TEST 6: Land use and plot size unit filtering
    // -------------------------------------------------------------
    echo "\n--- TEST 6: Land Use & Plot Size Unit Filtering ---\n";
    // Commercial land use
    $q_comm = propvendor_marketplace_build_search_query(array('pv_land_use' => 'Commercial', 'posts_per_page' => 50));
    $ids_comm = wp_list_pluck($q_comm->posts, 'ID');
    run_test(in_array($l3, $ids_comm, true), 'Commercial land use filter matches commercial land');
    run_test(!in_array($l4, $ids_comm, true), 'Commercial land use filter excludes residential land');

    // Unit filter: plots
    $q_plots = propvendor_marketplace_build_search_query(array('pv_plot_unit' => 'plots', 'posts_per_page' => 50));
    $ids_plots = wp_list_pluck($q_plots->posts, 'ID');
    run_test(in_array($l3, $ids_plots, true), 'Plot unit filter matches plots');
    run_test(!in_array($l4, $ids_plots, true), 'Plot unit filter excludes sqm land');

    // Unit filter: sqm
    $q_sqm = propvendor_marketplace_build_search_query(array('pv_plot_unit' => 'sqm', 'posts_per_page' => 50));
    $ids_sqm = wp_list_pluck($q_sqm->posts, 'ID');
    run_test(in_array($l4, $ids_sqm, true), 'Plot unit filter matches sqm');

    // -------------------------------------------------------------
    // TEST 7: Sorting by price ascending and descending
    // -------------------------------------------------------------
    echo "\n--- TEST 7: Sorting Order ---\n";
    // Price Ascending
    $q_sort_asc = propvendor_marketplace_build_search_query(array(
        'pv_type'        => 'property_listing',
        'pv_sort'        => 'price_asc',
        'posts_per_page' => 10,
    ));
    $asc_prices = array();
    foreach ($q_sort_asc->posts as $p) {
        $asc_prices[] = (float) get_post_meta($p->ID, 'propvendor_price', true);
    }
    $is_asc = true;
    for ($i = 0; $i < count($asc_prices) - 1; $i++) {
        if ($asc_prices[$i] > $asc_prices[$i + 1]) {
            $is_asc = false;
            break;
        }
    }
    run_test($is_asc && count($asc_prices) >= 2, 'pv_sort=price_asc returns posts in ascending price order');

    // Price Descending
    $q_sort_desc = propvendor_marketplace_build_search_query(array(
        'pv_type'        => 'property_listing',
        'pv_sort'        => 'price_desc',
        'posts_per_page' => 10,
    ));
    $desc_prices = array();
    foreach ($q_sort_desc->posts as $p) {
        $desc_prices[] = (float) get_post_meta($p->ID, 'propvendor_price', true);
    }
    $is_desc = true;
    for ($i = 0; $i < count($desc_prices) - 1; $i++) {
        if ($desc_prices[$i] < $desc_prices[$i + 1]) {
            $is_desc = false;
            break;
        }
    }
    run_test($is_desc && count($desc_prices) >= 2, 'pv_sort=price_desc returns posts in descending price order');

    // -------------------------------------------------------------
    // TEST 8: Inactive listings strictly excluded from search results
    // -------------------------------------------------------------
    echo "\n--- TEST 8: Inactive Listings Exclusion ---\n";
    $q_all = propvendor_marketplace_build_search_query(array('posts_per_page' => 100));
    $all_returned_ids = wp_list_pluck($q_all->posts, 'ID');

    run_test(!in_array($l_sold, $all_returned_ids, true), 'Sold listings excluded from search results');
    run_test(!in_array($l_rented, $all_returned_ids, true), 'Rented listings excluded from search results');
    run_test(!in_array($l_rejected, $all_returned_ids, true), 'Rejected listings excluded from search results');
    run_test(!in_array($l_unavail, $all_returned_ids, true), 'Unavailable listings excluded from search results');
    run_test(!in_array($l_expired, $all_returned_ids, true), 'Expired listings excluded from search results');
    run_test(!in_array($l_draft, $all_returned_ids, true), 'Draft status listings excluded from search results');

    // -------------------------------------------------------------
    // TEST 9: Malformed, tampered, or invalid parameters fail safely
    // -------------------------------------------------------------
    echo "\n--- TEST 9: Tampering & Invalid Parameters Resilience ---\n";
    $malformed_params = array(
        'pv_keyword'     => "'; DROP TABLE wp_posts; --",
        'pv_type'        => 'invalid_evil_type',
        'pv_state'       => '<script>alert("xss")</script>',
        'pv_min_price'   => 'abc-invalid',
        'pv_max_price'   => '---999',
        'pv_min_year'    => 'invalid_year',
        'pv_max_year'    => '-500',
        'pv_sort'        => 'injection_order; SELECT 1',
        'pv_plot_unit'   => 'lightyears',
        'posts_per_page' => -999,
    );

    $safe_error = false;
    try {
        $q_malformed = propvendor_marketplace_build_search_query($malformed_params);
        $safe_resilience = ($q_malformed instanceof WP_Query);
    } catch (Exception $e) {
        $safe_resilience = false;
    }
    run_test($safe_resilience, 'Malformed / SQL injection search parameters execute safely without fatal error');

    // Inverted price bounds auto-correction (min > max swaps safely)
    $args_inverted = propvendor_marketplace_get_search_query_args(array(
        'pv_min_price' => 50000000,
        'pv_max_price' => 10000000,
    ));
    $inverted_handled = false;
    foreach ($args_inverted['meta_query'] as $mq) {
        if (isset($mq['relation']) && $mq['relation'] === 'OR') {
            foreach ($mq as $clause) {
                if (is_array($clause) && isset($clause['compare']) && $clause['compare'] === 'BETWEEN') {
                    if ($clause['value'][0] <= $clause['value'][1]) {
                        $inverted_handled = true;
                    }
                }
            }
        }
    }
    run_test($inverted_handled, 'Inverted min/max prices (50M - 10M) auto-swap to valid range (10M - 50M)');

    // -------------------------------------------------------------
    // TEST 10: Query parameters persist cleanly in form inputs
    // -------------------------------------------------------------
    echo "\n--- TEST 10: Form Pre-Population & Parameter Persistence ---\n";
    $_GET = array(
        'pv_keyword'          => 'Lekki Duplex',
        'pv_type'             => 'property_listing',
        'pv_state'            => 'Lagos',
        'pv_min_price'        => '20000000',
        'pv_max_price'        => '80000000',
        'pv_title_doc'        => 'Certificate of Occupancy (C of O)',
        'pv_property_subtype' => 'Detached Duplex',
        'pv_min_bedrooms'     => '3',
        'pv_sort'             => 'price_asc',
    );

    $form_markup = propvendor_marketplace_render_search_filters(array('action_url' => 'http://example.com/browse/'));

    run_test(strpos($form_markup, 'value="Lekki Duplex"') !== false, 'Form persists keyword input');
    run_test(strpos($form_markup, "selected='selected'>Property</option>") !== false || strpos($form_markup, 'selected="selected">Property</option>') !== false || strpos($form_markup, 'selected>Property</option>') !== false, 'Form persists selected category');
    run_test(strpos($form_markup, 'value="Lagos" selected') !== false || strpos($form_markup, 'selected="selected">Lagos</option>') !== false, 'Form persists selected state');
    run_test(strpos($form_markup, 'value="20000000"') !== false, 'Form persists min price');
    run_test(strpos($form_markup, 'value="80000000"') !== false, 'Form persists max price');
    run_test(strpos($form_markup, 'value="Certificate of Occupancy (C of O)" selected') !== false, 'Form persists title document selection');
    run_test(strpos($form_markup, 'value="price_asc" selected') !== false, 'Form persists sort selection');
    run_test(strpos($form_markup, 'class="propvendor-btn-reset"') !== false, 'Form contains clean reset button');

    // Shortcode registration check
    run_test(shortcode_exists('propvendor_search_filters'), '[propvendor_search_filters] shortcode is registered');
    run_test(shortcode_exists('propvendor_browse_listings'), '[propvendor_browse_listings] shortcode is registered');

} finally {
    // Teardown: delete all test fixtures
    foreach ($created_post_ids as $pid) {
        wp_delete_post($pid, true);
    }
    $_GET = $orig_get;
}

echo "\n====================================================================\n";
echo "  STAGE 4 TEST SUMMARY: {$passed_tests}/{$total_tests} PASSED\n";
if ($failed_tests === 0 && $passed_tests > 0) {
    echo "  SUMMARY: {$passed_tests} PASSED, 0 FAILED (ALL TESTS PASSED)\n";
} else {
    echo "  SUMMARY: {$passed_tests} PASSED, {$failed_tests} FAILED\n";
}
echo "====================================================================\n";

exit($failed_tests > 0 ? 1 : 0);
