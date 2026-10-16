<?php
/**
 * Plugin Name: PropVendor Marketplace
 * Description: Core marketplace support for property, land, vehicle listings, vendor profiles and starter marketplace pages.
 * Version: 0.1.0
 * Author: Copilot
 */

if (!defined('ABSPATH')) {
    exit;
}

function propvendor_marketplace_get_listing_post_types() {
    return array('property_listing', 'land_listing', 'vehicle_listing');
}

function propvendor_marketplace_get_listing_status_choices() {
    return array('Pending review', 'Published', 'Rejected', 'Sold', 'Rented', 'Unavailable', 'Expired');
}

/**
 * =========================================================================
 * STAGE 3: LISTING QUOTA & VIP ENTITLEMENT HELPERS
 * =========================================================================
 */

if (!defined('PROPVENDOR_FREE_LISTING_LIMIT')) {
    define('PROPVENDOR_FREE_LISTING_LIMIT', 3);
}

/**
 * Get the configurable free listing quota limit.
 *
 * @return int
 */
function propvendor_marketplace_get_free_listing_limit() {
    return (int) apply_filters('propvendor_free_listing_limit', PROPVENDOR_FREE_LISTING_LIMIT);
}

/**
 * Invalidate the in-request active listing count cache.
 *
 * @param int $user_id Optional user ID. If 0, clears all.
 */
function propvendor_marketplace_invalidate_listing_count_cache($user_id = 0) {
    global $propvendor_listing_count_cache;
    $user_id = absint($user_id);
    if ($user_id > 0 && isset($propvendor_listing_count_cache[$user_id])) {
        unset($propvendor_listing_count_cache[$user_id]);
    } else {
        $propvendor_listing_count_cache = array();
    }
}
add_action('save_post', 'propvendor_marketplace_invalidate_listing_count_cache', 5);
add_action('deleted_post', 'propvendor_marketplace_invalidate_listing_count_cache', 5);
add_action('transition_post_status', 'propvendor_marketplace_invalidate_listing_count_cache', 5);

/**
 * Resolve canonical listing status with documented precedence and fail-closed resolution.
 *
 * Precedence:
 * 1. Fail-closed: If either propvendor_listing_status or propvendor_status has an inactive marker
 *    ('Rejected', 'Unavailable', 'Expired', 'Sold', 'Rented'), that inactive marker takes precedence
 *    to prevent conflicting metadata from counting an inactive listing as active.
 * 2. Primary key: propvendor_listing_status (if non-empty).
 * 3. Fallback key: propvendor_status (if non-empty).
 * 4. Default: Derived from WordPress post_status ('publish' -> 'Published', 'pending' -> 'Pending review',
 *    'draft' -> 'Rejected', default -> 'Unavailable').
 *
 * @param int $post_id
 * @return string Canonical moderation status string
 */
function propvendor_marketplace_get_canonical_listing_status($post_id) {
    $post_id = absint($post_id);
    if ($post_id <= 0) {
        return 'Unavailable';
    }

    $post = get_post($post_id);
    if (!$post || !in_array($post->post_type, propvendor_marketplace_get_listing_post_types(), true)) {
        return 'Unavailable';
    }

    $meta_listing_status = trim((string) get_post_meta($post_id, 'propvendor_listing_status', true));
    $meta_status         = trim((string) get_post_meta($post_id, 'propvendor_status', true));

    $inactive_statuses = array('Rejected', 'Unavailable', 'Expired', 'Sold', 'Rented');

    // 1. Fail-closed: inactive status marker in either key takes precedence
    if (in_array($meta_listing_status, $inactive_statuses, true)) {
        return $meta_listing_status;
    }
    if (in_array($meta_status, $inactive_statuses, true)) {
        return $meta_status;
    }

    // 2. Primary key propvendor_listing_status
    if ('' !== $meta_listing_status) {
        return $meta_listing_status;
    }

    // 3. Fallback key propvendor_status
    if ('' !== $meta_status) {
        return $meta_status;
    }

    // 4. Default derivation from WordPress core post_status
    switch ($post->post_status) {
        case 'publish':
            return 'Published';
        case 'pending':
            return 'Pending review';
        case 'draft':
            return 'Rejected';
        case 'trash':
            return 'Unavailable';
        default:
            return 'Unavailable';
    }
}

/**
 * Check if a listing is currently in the active pool.
 *
 * A listing consumes a slot when ALL of the following are true:
 * - post_type is one of the three supported listing types (property_listing, land_listing, vehicle_listing).
 * - post_status is EXACTLY 'publish' or 'pending'.
 * - Canonical moderation status is NOT in: Rejected, Unavailable, Expired, Sold, Rented.
 *
 * Explicitly does NOT count: draft, auto-draft, future, private, trash, permanently deleted,
 * or any listing with an inactive moderation marker.
 *
 * @param int $post_id
 * @return bool
 */
function propvendor_marketplace_is_listing_active($post_id) {
    $post_id = absint($post_id);
    if ($post_id <= 0) {
        return false;
    }

    $post = get_post($post_id);
    if (!$post || !in_array($post->post_type, propvendor_marketplace_get_listing_post_types(), true)) {
        return false;
    }

    if (!in_array($post->post_status, array('publish', 'pending'), true)) {
        return false;
    }

    $canonical_status = propvendor_marketplace_get_canonical_listing_status($post_id);
    $inactive_statuses = array('Rejected', 'Unavailable', 'Expired', 'Sold', 'Rented');

    if (in_array($canonical_status, $inactive_statuses, true)) {
        return false;
    }

    return true;
}

/**
 * Count active listings for a vendor across all supported post types.
 *
 * Uses in-request static cache to optimize repeated calls per request.
 * Excludes listings that have any inactive status marker via NOT EXISTS.
 *
 * @param int $user_id
 * @param int $exclude_post_id Optional post ID to exclude (e.g. during reactivation checks)
 * @return int
 */
function propvendor_marketplace_get_vendor_active_listing_count($user_id, $exclude_post_id = 0) {
    global $wpdb, $propvendor_listing_count_cache;

    $user_id = absint($user_id);
    if ($user_id <= 0) {
        return 0;
    }

    if (!is_array($propvendor_listing_count_cache)) {
        $propvendor_listing_count_cache = array();
    }

    $exclude_post_id = absint($exclude_post_id);
    $cache_key = $user_id . ':' . $exclude_post_id;

    if (isset($propvendor_listing_count_cache[$cache_key])) {
        return (int) $propvendor_listing_count_cache[$cache_key];
    }

    $post_types = propvendor_marketplace_get_listing_post_types();
    $type_placeholders = implode(', ', array_fill(0, count($post_types), '%s'));

    $inactive_statuses = array('Rejected', 'Unavailable', 'Expired', 'Sold', 'Rented');
    $inactive_placeholders = implode(', ', array_fill(0, count($inactive_statuses), '%s'));

    $exclude_clause = '';
    $params = array_merge(array($user_id), $post_types);

    if ($exclude_post_id > 0) {
        $exclude_clause = ' AND p.ID <> %d ';
        $params[] = $exclude_post_id;
    }

    $params = array_merge($params, $inactive_statuses);

    $sql = "SELECT COUNT(p.ID)
            FROM {$wpdb->posts} p
            WHERE p.post_author = %d
              AND p.post_type IN ({$type_placeholders})
              AND p.post_status IN ('publish', 'pending')
              {$exclude_clause}
              AND NOT EXISTS (
                  SELECT 1 FROM {$wpdb->postmeta} pm
                  WHERE pm.post_id = p.ID
                    AND pm.meta_key IN ('propvendor_listing_status', 'propvendor_status')
                    AND pm.meta_value IN ({$inactive_placeholders})
              )";

    $count = (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    $propvendor_listing_count_cache[$cache_key] = $count;

    return $count;
}

/**
 * Check if a vendor has active VIP listing privileges.
 *
 * Rules:
 * - Administrators with manage_options bypass quota entirely.
 * - propvendor_listing_plan must be 'vip'.
 * - If expiry is empty/unset: VIP is valid (permanent admin choice).
 * - If expiry is a valid future date: VIP valid.
 * - If expiry is in the past: Expired (not VIP).
 * - If expiry is malformed/unparseable: Fail closed as expired (not VIP) and error_log.
 * - Timezone handling: compares against current_time('timestamp') in site timezone (WAT).
 * - Date-only expiry (YYYY-MM-DD) is interpreted as 23:59:59 end-of-day site time.
 * - Independent: never grants VIP from lead plan (premium/pro/unlocked).
 *
 * @param int $user_id
 * @return bool
 */
function propvendor_marketplace_is_vendor_vip($user_id) {
    $user_id = absint($user_id);
    if ($user_id <= 0) {
        return false;
    }

    if (user_can($user_id, 'manage_options')) {
        return true;
    }

    $plan = strtolower(trim((string) get_user_meta($user_id, 'propvendor_listing_plan', true)));
    if ('vip' !== $plan) {
        return false;
    }

    $expires_at = get_user_meta($user_id, 'propvendor_plan_expires_at', true);
    if ('' !== trim((string) $expires_at)) {
        $expiry_str = trim((string) $expires_at);
        $site_tz = wp_timezone();
        $now_ts = current_time('timestamp');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_str)) {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $expiry_str . ' 23:59:59', $site_tz);
            $expiry_ts = $dt ? $dt->getTimestamp() : false;
        } elseif (is_numeric($expiry_str)) {
            $expiry_ts = (int) $expiry_str;
        } else {
            try {
                $dt = new DateTimeImmutable($expiry_str, $site_tz);
                $expiry_ts = $dt->getTimestamp();
            } catch (Exception $e) {
                $expiry_ts = false;
            }
        }

        if (false === $expiry_ts || $expiry_ts <= 0) {
            error_log(sprintf('PropVendor: Malformed VIP plan expiry "%s" for user ID %d. Treated as expired.', $expiry_str, $user_id));
            return false;
        }

        if ($now_ts > $expiry_ts) {
            return false;
        }
    }

    return true;
}

/**
 * Get listing plan status identifier for a vendor.
 *
 * @param int $user_id
 * @return string 'vip' | 'expired_vip' | 'free'
 */
function propvendor_marketplace_get_vendor_listing_plan($user_id) {
    $user_id = absint($user_id);
    if ($user_id <= 0) {
        return 'free';
    }
    if (user_can($user_id, 'manage_options')) {
        return 'vip';
    }
    $raw_plan = strtolower(trim((string) get_user_meta($user_id, 'propvendor_listing_plan', true)));
    if ('vip' !== $raw_plan) {
        return 'free';
    }
    if (!propvendor_marketplace_is_vendor_vip($user_id)) {
        return 'expired_vip';
    }
    return 'vip';
}

/**
 * Check if a vendor can submit a new listing or reactivate an existing one.
 *
 * Evaluated against the listing owner (post_author).
 * If the acting user has manage_options, bypasses quota entirely.
 *
 * @param int $owner_id User ID of listing owner
 * @param int $existing_post_id Optional existing post ID (0 for new submission)
 * @param int $acting_user_id Optional acting user ID (defaults to current logged-in user)
 * @return bool
 */
function propvendor_marketplace_vendor_can_create_listing($owner_id, $existing_post_id = 0, $acting_user_id = 0) {
    $owner_id = absint($owner_id);
    if ($acting_user_id <= 0) {
        $acting_user_id = get_current_user_id();
    }

    // Acting admin with manage_options bypasses quota entirely
    if ($acting_user_id > 0 && user_can($acting_user_id, 'manage_options')) {
        return true;
    }

    if ($owner_id <= 0) {
        return false;
    }

    // Owner is admin or active VIP
    if (propvendor_marketplace_is_vendor_vip($owner_id)) {
        return true;
    }

    $limit = propvendor_marketplace_get_free_listing_limit();
    $existing_post_id = absint($existing_post_id);

    if ($existing_post_id > 0) {
        // Editing an already active listing does NOT consume another slot
        if (propvendor_marketplace_is_listing_active($existing_post_id)) {
            return true;
        }
        // Reactivating an inactive listing: check if adding it would exceed the quota
        $active_count = propvendor_marketplace_get_vendor_active_listing_count($owner_id, $existing_post_id);
        return ($active_count < $limit);
    }

    // New listing creation
    $active_count = propvendor_marketplace_get_vendor_active_listing_count($owner_id);
    return ($active_count < $limit);
}

/**
 * Render the vendor listing quota card for the vendor dashboard.
 *
 * @param int $user_id
 * @return string HTML
 */
function propvendor_marketplace_render_vendor_quota_card($user_id) {
    $user_id = absint($user_id);
    if ($user_id <= 0) {
        return '';
    }

    $limit = propvendor_marketplace_get_free_listing_limit();
    $active_count = propvendor_marketplace_get_vendor_active_listing_count($user_id);
    $is_vip = propvendor_marketplace_is_vendor_vip($user_id);
    $plan_status = propvendor_marketplace_get_vendor_listing_plan($user_id);
    $expires_at = get_user_meta($user_id, 'propvendor_plan_expires_at', true);

    $plan_badge_text = __('Free Plan', 'propvendor-marketplace');
    $plan_slug = 'free';
    $expiry_notice = '';

    if ('vip' === $plan_status) {
        $plan_badge_text = __('VIP Vendor', 'propvendor-marketplace');
        $plan_slug = 'vip';
        if (!empty($expires_at)) {
            $ts = is_numeric($expires_at) ? (int) $expires_at : strtotime($expires_at);
            if ($ts > 0) {
                $expiry_notice = sprintf(__('VIP Active (valid until %s)', 'propvendor-marketplace'), date_i18n('j F Y', $ts));
            }
        } else {
            $expiry_notice = __('VIP Active (no expiry set)', 'propvendor-marketplace');
        }
    } elseif ('expired_vip' === $plan_status) {
        $plan_badge_text = __('VIP Plan Expired', 'propvendor-marketplace');
        $plan_slug = 'expired';
        if (!empty($expires_at)) {
            $ts = is_numeric($expires_at) ? (int) $expires_at : strtotime($expires_at);
            if ($ts > 0) {
                $expiry_notice = sprintf(__('VIP Expired on %s — Free limits now apply. Your existing listings remain live.', 'propvendor-marketplace'), date_i18n('j F Y', $ts));
            }
        }
    }

    $remaining_slots = max(0, $limit - $active_count);
    $is_over_limit = (!$is_vip && $active_count > $limit);

    if ($is_vip) {
        $counter_text = sprintf(__('%d (Unlimited)', 'propvendor-marketplace'), $active_count);
    } elseif ($is_over_limit) {
        $counter_text = sprintf(__('%d of %d (over limit)', 'propvendor-marketplace'), $active_count, $limit);
    } else {
        $counter_text = sprintf(__('%d of %d', 'propvendor-marketplace'), $active_count, $limit);
    }

    $progress_pct = $is_vip ? 100 : min(100, round(($active_count / max(1, $limit)) * 100));

    // Optional admin-configured upgrade destination / note
    $upgrade_url = esc_url(get_option('propvendor_upgrade_info_url', ''));
    $upgrade_msg = get_option('propvendor_upgrade_info_message', '');

    $output = '<div class="propvendor-quota-card">';
    $output .= '<div class="propvendor-quota-header">';
    $output .= '<div class="propvendor-quota-title">';
    $output .= '<span class="propvendor-quota-label">' . esc_html__('Listing Plan:', 'propvendor-marketplace') . '</span> ';
    $output .= '<span class="propvendor-quota-badge propvendor-quota-badge-' . esc_attr($plan_slug) . '">' . esc_html($plan_badge_text) . '</span>';
    $output .= '</div>';
    if (!empty($expiry_notice)) {
        $output .= '<div class="propvendor-quota-expiry">' . esc_html($expiry_notice) . '</div>';
    }
    $output .= '</div>';

    $output .= '<div class="propvendor-quota-body">';
    $output .= '<div class="propvendor-quota-stats">';
    $output .= '<div class="propvendor-quota-stat-item">';
    $output .= '<span class="propvendor-quota-stat-label">' . esc_html__('Active Listings:', 'propvendor-marketplace') . '</span> ';
    $output .= '<strong class="propvendor-quota-stat-num">' . esc_html($counter_text) . '</strong>';
    $output .= '</div>';
    if (!$is_vip && !$is_over_limit) {
        $output .= '<div class="propvendor-quota-stat-item">';
        $output .= '<span class="propvendor-quota-stat-label">' . esc_html__('Remaining:', 'propvendor-marketplace') . '</span> ';
        $output .= '<span class="propvendor-quota-stat-num">' . sprintf(_n('%d slot', '%d slots', $remaining_slots, 'propvendor-marketplace'), $remaining_slots) . '</span>';
        $output .= '</div>';
    }
    $output .= '</div>';

    if (!$is_vip) {
        $is_full = ($active_count >= $limit);
        $output .= '<div class="propvendor-quota-progress" role="progressbar" aria-valuenow="' . esc_attr((string) $active_count) . '" aria-valuemin="0" aria-valuemax="' . esc_attr((string) $limit) . '" aria-label="' . esc_attr__('Active listing quota usage', 'propvendor-marketplace') . '">';
        $output .= '<div class="propvendor-quota-progress-bar' . ($is_full ? ' full' : '') . '" style="width: ' . esc_attr((string) $progress_pct) . '%;"></div>';
        $output .= '</div>';
    }
    $output .= '</div>';

    if ($is_over_limit) {
        $output .= '<div class="propvendor-quota-alert propvendor-quota-alert-warning">';
        $output .= sprintf(
            esc_html__('Your account has %d active listings, which exceeds the free limit of %d. Existing listings remain safe and editable, but new listing submissions are blocked until your active count is below %d or you upgrade to a VIP plan.', 'propvendor-marketplace'),
            $active_count,
            $limit,
            $limit
        );
        $output .= '</div>';
    } elseif (!$is_vip && $active_count >= $limit) {
        $output .= '<div class="propvendor-quota-alert propvendor-quota-alert-warning">';
        $output .= sprintf(
            esc_html__('Active listing limit reached (%d of %d). To create new listings, remove or mark an existing listing as sold or unavailable, or contact a marketplace administrator to upgrade to a VIP plan.', 'propvendor-marketplace'),
            $active_count,
            $limit
        );
        $output .= '</div>';
    } elseif (!$is_vip) {
        $output .= '<div class="propvendor-quota-info-note">';
        if (!empty($upgrade_msg)) {
            $output .= esc_html($upgrade_msg);
        } else {
            $output .= sprintf(
                esc_html__('Free vendors may have up to %d active listings across Property, Land, and Vehicle categories. Need more listing slots? Contact an administrator to upgrade to a VIP plan for unlimited listings.', 'propvendor-marketplace'),
                $limit
            );
        }
        if (!empty($upgrade_url)) {
            $output .= ' <a href="' . $upgrade_url . '" class="propvendor-btn-upgrade">' . esc_html__('Upgrade Information', 'propvendor-marketplace') . ' &rarr;</a>';
        }
        $output .= '</div>';
    }

    $output .= '</div>';
    return $output;
}

/**
 * =========================================================================
 * DYNAMIC CATEGORY SCHEMAS & ATTRIBUTE HELPERS
 * =========================================================================
 */

function propvendor_marketplace_get_property_subtypes() {
    return array(
        'Flat / Apartment',
        'Detached House',
        'Semi-Detached Duplex',
        'Terraced Duplex',
        'Maisonette',
        'Bungalow',
        'Penthouse',
        'Commercial Space',
        'Office',
        'Shop',
        'Warehouse',
        'Other',
    );
}

function propvendor_marketplace_get_property_purposes() {
    return array(
        'Sale',
        'Rent',
        'Short-Let',
    );
}

function propvendor_marketplace_get_furnishing_options() {
    return array(
        'Furnished',
        'Semi-Furnished',
        'Unfurnished',
    );
}

function propvendor_marketplace_get_title_documents() {
    return array(
        'Certificate of Occupancy (C of O)',
        "Governor's Consent",
        'Gazette',
        'Deed of Assignment',
        'Excision',
        'Survey Plan',
        'Court Judgment',
        'Registered Conveyance',
        'Other / In Process',
    );
}

function propvendor_marketplace_get_land_uses() {
    return array(
        'Residential',
        'Commercial',
        'Industrial',
        'Agricultural',
        'Mixed Use',
    );
}

function propvendor_marketplace_get_vehicle_subtypes() {
    return array(
        'Car / Sedan',
        'SUV',
        'Truck / Pickup',
        'Van / Bus',
        'Motorcycle',
        'Commercial Vehicle',
        'Other',
    );
}

function propvendor_marketplace_get_vehicle_transmissions() {
    return array(
        'Automatic',
        'Manual',
        'CVT',
    );
}

function propvendor_marketplace_get_vehicle_conditions() {
    return array(
        'Brand New',
        'Foreign Used / Tokunbo',
        'Nigerian Used',
    );
}

function propvendor_marketplace_get_vehicle_fuel_types() {
    return array(
        'Petrol',
        'Diesel',
        'Hybrid',
        'Electric',
    );
}

/**
 * Return allowlist of all 37 Nigerian States (including FCT - Abuja).
 *
 * @return string[]
 */
function propvendor_marketplace_get_nigerian_states() {
    return array(
        'Abia',
        'Adamawa',
        'Akwa Ibom',
        'Anambra',
        'Bauchi',
        'Bayelsa',
        'Benue',
        'Borno',
        'Cross River',
        'Delta',
        'Ebonyi',
        'Edo',
        'Ekiti',
        'Enugu',
        'FCT - Abuja',
        'Gombe',
        'Imo',
        'Jigawa',
        'Kaduna',
        'Kano',
        'Katsina',
        'Kebbi',
        'Kogi',
        'Kwara',
        'Lagos',
        'Nasarawa',
        'Niger',
        'Ogun',
        'Ondo',
        'Osun',
        'Oyo',
        'Plateau',
        'Rivers',
        'Sokoto',
        'Taraba',
        'Yobe',
        'Zamfara',
    );
}

/**
 * Normalize and match a state string to canonical Nigerian state allowlist.
 *
 * @param string $state
 * @return string Canonical state name or empty string if not recognized.
 */
function propvendor_marketplace_normalize_nigerian_state($state) {
    if (!is_string($state)) {
        return '';
    }
    $trimmed = trim($state);
    if ('' === $trimmed) {
        return '';
    }

    $states = propvendor_marketplace_get_nigerian_states();
    // 1. Direct case-insensitive match
    foreach ($states as $s) {
        if (0 === strcasecmp($s, $trimmed)) {
            return $s;
        }
    }

    // 2. Common aliases & abbreviations
    $lower = strtolower($trimmed);
    if ('abuja' === $lower || 'fct' === $lower || 'abuja fct' === $lower || 'fct abuja' === $lower || 'federal capital territory' === $lower) {
        return 'FCT - Abuja';
    }
    if ('akwa ibom' === $lower || 'akwa-ibom' === $lower || 'akwaibom' === $lower) {
        return 'Akwa Ibom';
    }
    if ('cross river' === $lower || 'cross-river' === $lower || 'crossriver' === $lower) {
        return 'Cross River';
    }

    // Common legacy Nigerian cities to states
    $city_map = array(
        'ikeja'           => 'Lagos',
        'lekki'           => 'Lagos',
        'victoria island' => 'Lagos',
        'ikoyi'           => 'Lagos',
        'yaba'            => 'Lagos',
        'surulere'        => 'Lagos',
        'ibadan'          => 'Oyo',
        'abeokuta'        => 'Ogun',
        'port harcourt'   => 'Rivers',
        'calabar'         => 'Cross River',
        'warri'           => 'Delta',
        'asaba'           => 'Delta',
        'benin city'      => 'Edo',
        'ilorin'          => 'Kwara',
        'owerri'          => 'Imo',
        'uyo'             => 'Akwa Ibom',
        'maiduguri'       => 'Borno',
    );
    if (isset($city_map[$lower])) {
        return $city_map[$lower];
    }

    // 3. Substring / legacy address match (e.g., "Ikeja, Lagos", "Ilorin, Kwara State")
    foreach ($states as $s) {
        if ('FCT - Abuja' === $s) {
            if (preg_match('/\b(abuja|fct)\b/i', $lower)) {
                return 'FCT - Abuja';
            }
        } else {
            $s_esc = preg_quote($s, '/');
            if (preg_match('/\b' . $s_esc . '\b/i', $lower)) {
                return $s;
            }
        }
    }

    return '';
}

/**
 * Return allowed plot size units with canonical keys and human-readable labels.
 *
 * @return array<string, string>
 */
function propvendor_marketplace_get_plot_size_units() {
    return array(
        'sqm'      => 'Sqm (Square Meters)',
        'plots'    => 'Plots',
        'acres'    => 'Acres',
        'hectares' => 'Hectares',
        'sqft'     => 'Sqft (Square Feet)',
    );
}

/**
 * Normalize a plot size unit input to canonical unit key.
 *
 * @param string $unit
 * @return string
 */
function propvendor_marketplace_normalize_plot_size_unit($unit) {
    if (!is_string($unit)) {
        return '';
    }
    $trimmed = strtolower(trim($unit));
    $units = propvendor_marketplace_get_plot_size_units();
    if (isset($units[$trimmed])) {
        return $trimmed;
    }
    foreach ($units as $key => $label) {
        if (strtolower($label) === $trimmed || 0 === strcasecmp($label, $unit)) {
            return $key;
        }
    }
    if (strpos($trimmed, 'sqm') !== false || strpos($trimmed, 'square meter') !== false || strpos($trimmed, 'm²') !== false) {
        return 'sqm';
    }
    if (strpos($trimmed, 'plot') !== false) {
        return 'plots';
    }
    if (strpos($trimmed, 'acre') !== false) {
        return 'acres';
    }
    if (strpos($trimmed, 'hectare') !== false) {
        return 'hectares';
    }
    if (strpos($trimmed, 'sqft') !== false || strpos($trimmed, 'square feet') !== false || strpos($trimmed, 'ft²') !== false) {
        return 'sqft';
    }
    return '';
}

/**
 * Get display label for a plot size unit.
 *
 * @param string $unit
 * @return string
 */
function propvendor_marketplace_get_plot_size_unit_label($unit) {
    $normalized = propvendor_marketplace_normalize_plot_size_unit($unit);
    $units = propvendor_marketplace_get_plot_size_units();
    return $units[$normalized] ?? $unit;
}

/**
 * Return allowed category-specific meta keys for a listing post type.
 *
 * @param string $listing_type
 * @return string[]
 */
function propvendor_marketplace_get_category_meta_keys($listing_type) {
    switch ($listing_type) {
        case 'property_listing':
            return array(
                'propvendor_property_subtype',
                'propvendor_purpose',
                'propvendor_bedrooms',
                'propvendor_bathrooms',
                'propvendor_furnishing',
                'propvendor_title_document',
                'propvendor_floor_area',
                'propvendor_floor_area_unit',
                'propvendor_plot_size',
                'propvendor_plot_size_unit',
            );
        case 'land_listing':
            return array(
                'propvendor_plot_size',
                'propvendor_plot_size_unit',
                'propvendor_land_use',
                'propvendor_title_document',
                'propvendor_land_length',
                'propvendor_land_width',
                'propvendor_dimension_unit',
                'propvendor_frontage',
            );
        case 'vehicle_listing':
            return array(
                'propvendor_vehicle_subtype',
                'propvendor_vehicle_make',
                'propvendor_vehicle_model',
                'propvendor_vehicle_year',
                'propvendor_condition',
                'propvendor_transmission',
                'propvendor_mileage',
                'propvendor_fuel_type',
                'propvendor_engine_size',
            );
        default:
            return array();
    }
}

/**
 * Return all category-specific meta keys across all listing types.
 *
 * @return string[]
 */
function propvendor_marketplace_get_all_category_meta_keys() {
    $keys = array_merge(
        propvendor_marketplace_get_category_meta_keys('property_listing'),
        propvendor_marketplace_get_category_meta_keys('land_listing'),
        propvendor_marketplace_get_category_meta_keys('vehicle_listing')
    );
    return array_values(array_unique($keys));
}


function propvendor_marketplace_register_role() {
    $vendor_caps = array(
        'read' => true,
        'propvendor_submit_marketplace_listings' => true,
    );

    if (!get_role('vendor')) {
        add_role('vendor', 'Vendor', $vendor_caps);
    }

    $role = get_role('vendor');
    if ($role) {
        foreach ($vendor_caps as $cap => $grant) {
            if ($grant) {
                $role->add_cap($cap);
            } else {
                $role->remove_cap($cap);
            }
        }

        foreach (
            array(
                'upload_files',
                'edit_property_listings',
                'edit_published_property_listings',
                'publish_property_listings',
                'delete_property_listings',
                'delete_published_property_listings',
                'read_private_property_listings',
                'edit_private_property_listings',
                'edit_land_listings',
                'edit_published_land_listings',
                'publish_land_listings',
                'delete_land_listings',
                'delete_published_land_listings',
                'read_private_land_listings',
                'edit_private_land_listings',
                'edit_vehicle_listings',
                'edit_published_vehicle_listings',
                'publish_vehicle_listings',
                'delete_vehicle_listings',
                'delete_published_vehicle_listings',
                'read_private_vehicle_listings',
                'edit_private_vehicle_listings',
            ) as $cap
        ) {
            $role->remove_cap($cap);
        }
    }

    $administrator_role = get_role('administrator');
    if ($administrator_role) {
        foreach (propvendor_marketplace_get_listing_post_types() as $post_type) {
            $post_type_object = get_post_type_object($post_type);
            if (!$post_type_object) {
                continue;
            }

            foreach (get_object_vars($post_type_object->cap) as $capability) {
                if (is_string($capability)) {
                    $administrator_role->add_cap($capability);
                }
            }
        }
    }
}

function propvendor_marketplace_register_post_types() {
    $post_types = array(
        'property_listing' => array(
            'labels' => array(
                'name' => 'Property Listings',
                'singular_name' => 'Property Listing',
                'add_new_item' => 'Add New Property Listing',
                'edit_item' => 'Edit Property Listing',
            ),
            'public' => true,
            'has_archive' => true,
            'rewrite' => array('slug' => 'properties'),
            'menu_icon' => 'dashicons-building',
            'supports' => array('title', 'editor', 'excerpt', 'thumbnail', 'author'),
            'show_in_rest' => true,
            'taxonomies' => array('listing_category', 'listing_location', 'listing_state'),
            'capability_type' => array('property_listing', 'property_listings'),
            'map_meta_cap' => true,
        ),
        'land_listing' => array(
            'labels' => array(
                'name' => 'Land Listings',
                'singular_name' => 'Land Listing',
                'add_new_item' => 'Add New Land Listing',
                'edit_item' => 'Edit Land Listing',
            ),
            'public' => true,
            'has_archive' => true,
            'rewrite' => array('slug' => 'land'),
            'menu_icon' => 'dashicons-admin-home',
            'supports' => array('title', 'editor', 'excerpt', 'thumbnail', 'author'),
            'show_in_rest' => true,
            'taxonomies' => array('listing_category', 'listing_location', 'listing_state'),
            'capability_type' => array('land_listing', 'land_listings'),
            'map_meta_cap' => true,
        ),
        'vehicle_listing' => array(
            'labels' => array(
                'name' => 'Vehicle Listings',
                'singular_name' => 'Vehicle Listing',
                'add_new_item' => 'Add New Vehicle Listing',
                'edit_item' => 'Edit Vehicle Listing',
            ),
            'public' => true,
            'has_archive' => true,
            'rewrite' => array('slug' => 'vehicles'),
            'menu_icon' => 'dashicons-car',
            'supports' => array('title', 'editor', 'excerpt', 'thumbnail', 'author'),
            'show_in_rest' => true,
            'taxonomies' => array('listing_category', 'listing_location', 'listing_state'),
            'capability_type' => array('vehicle_listing', 'vehicle_listings'),
            'map_meta_cap' => true,
        ),
        'vendor_profile' => array(
            'labels' => array(
                'name' => 'Vendor Profiles',
                'singular_name' => 'Vendor Profile',
                'add_new_item' => 'Add New Vendor Profile',
                'edit_item' => 'Edit Vendor Profile',
            ),
            'public' => true,
            'has_archive' => true,
            'rewrite' => array('slug' => 'vendors'),
            'menu_icon' => 'dashicons-businessperson',
            'supports' => array('title', 'editor', 'thumbnail'),
            'show_in_rest' => true,
            'capability_type' => 'post',
        ),
    );

    foreach ($post_types as $post_type => $args) {
        register_post_type($post_type, $args);
        if (in_array($post_type, propvendor_marketplace_get_listing_post_types(), true)) {
            add_filter('rest_pre_insert_' . $post_type, 'propvendor_marketplace_restrict_vendor_rest_writes', 10, 2);
            add_filter('rest_' . $post_type . '_query', 'propvendor_marketplace_filter_rest_listing_query', 10, 2);
        }
    }

    register_taxonomy(
        'listing_category',
        array('property_listing', 'land_listing', 'vehicle_listing'),
        array(
            'labels' => array(
                'name' => 'Listing Categories',
                'singular_name' => 'Listing Category',
            ),
            'public' => true,
            'hierarchical' => true,
            'show_admin_column' => true,
            'rewrite' => array('slug' => 'listing-category'),
        )
    );

    register_taxonomy(
        'listing_location',
        array('property_listing', 'land_listing', 'vehicle_listing'),
        array(
            'labels' => array(
                'name' => 'Locations',
                'singular_name' => 'Location',
            ),
            'public' => true,
            'hierarchical' => true,
            'show_admin_column' => true,
            'rewrite' => array('slug' => 'location'),
        )
    );

    register_taxonomy(
        'listing_state',
        array('property_listing', 'land_listing', 'vehicle_listing'),
        array(
            'labels' => array(
                'name' => 'Listing Statuses',
                'singular_name' => 'Listing Status',
            ),
            'public' => true,
            'hierarchical' => false,
            'show_admin_column' => true,
            'rewrite' => array('slug' => 'listing-state'),
        )
    );
}

function propvendor_marketplace_restrict_vendor_rest_writes($prepared_post, $request) {
    if (current_user_can('manage_options')) {
        return $prepared_post;
    }

    return new WP_Error(
        'rest_forbidden',
        'Submit or edit marketplace listings using the vendor listing form.',
        array('status' => 403)
    );
}

function propvendor_marketplace_filter_rest_listing_query($args, $request) {
    if (current_user_can('edit_others_posts')) {
        return $args;
    }

    $existing_meta_query = isset($args['meta_query']) && is_array($args['meta_query']) ? $args['meta_query'] : array();
    $args['post_status'] = 'publish';
    $public_listing_meta_query = propvendor_marketplace_public_listing_meta_query();
    $args['meta_query'] = empty($existing_meta_query)
        ? $public_listing_meta_query
        : array(
            'relation' => 'AND',
            $existing_meta_query,
            $public_listing_meta_query,
        );

    return $args;
}

function propvendor_marketplace_get_unavailable_listing_ids() {
    global $wpdb;

    $post_types = propvendor_marketplace_get_listing_post_types();
    $type_placeholders = implode(', ', array_fill(0, count($post_types), '%s'));
    $query = "SELECT DISTINCT listing.ID
        FROM {$wpdb->posts} AS listing
        LEFT JOIN {$wpdb->postmeta} AS moderation
            ON moderation.post_id = listing.ID
            AND moderation.meta_key IN (%s, %s)
        WHERE listing.post_type IN ({$type_placeholders})
            AND (listing.post_status <> 'publish' OR moderation.meta_value IN (%s, %s, %s, %s))";
    $prepare_values = array_merge(
        array('propvendor_listing_status', 'propvendor_status'),
        $post_types,
        array('Pending review', 'Rejected', 'Unavailable', 'Expired')
    );

    return array_map('absint', $wpdb->get_col($wpdb->prepare($query, $prepare_values)));
}

function propvendor_marketplace_filter_rest_attachment_query($args, $request) {
    if (current_user_can('edit_others_posts')) {
        return $args;
    }

    $unavailable_listing_ids = propvendor_marketplace_get_unavailable_listing_ids();
    $requested_parent_ids = array_filter(array_map('absint', (array) $request->get_param('parent')));
    if (!empty($requested_parent_ids)) {
        $allowed_parent_ids = array_values(array_diff($requested_parent_ids, $unavailable_listing_ids));
        $args['post_parent__in'] = !empty($allowed_parent_ids) ? $allowed_parent_ids : array(0);
        if (empty($allowed_parent_ids)) {
            $args['post__in'] = array(0);
        }
    }

    if (!empty($unavailable_listing_ids)) {
        $existing_exclusions = isset($args['post_parent__not_in']) ? (array) $args['post_parent__not_in'] : array();
        $args['post_parent__not_in'] = array_unique(array_merge($existing_exclusions, $unavailable_listing_ids));
    }

    return $args;
}

add_filter('rest_attachment_query', 'propvendor_marketplace_filter_rest_attachment_query', 10, 2);

function propvendor_marketplace_restrict_unavailable_rest_item($result, $server, $request) {
    if (null !== $result || current_user_can('edit_others_posts')) {
        return $result;
    }

    $route = $request->get_route();
    if (preg_match('#^/wp/v2/(property_listing|land_listing|vehicle_listing)/([0-9]+)(?:/|$)#', $route, $matches)) {
        $listing = get_post(absint($matches[2]));
        if ($listing instanceof WP_Post && !propvendor_marketplace_is_public_listing($listing)) {
            return new WP_Error('rest_listing_not_found', 'This listing is not available.', array('status' => 404));
        }
    } elseif (preg_match('#^/wp/v2/media/([0-9]+)(?:/|$)#', $route, $matches)) {
        $attachment = get_post(absint($matches[1]));
        $parent = $attachment instanceof WP_Post ? propvendor_marketplace_get_listing_for_attachment($attachment->ID) : null;
        if ($parent instanceof WP_Post && !propvendor_marketplace_is_public_listing($parent)) {
            return new WP_Error('rest_attachment_not_found', 'This media item is not available.', array('status' => 404));
        }
    }

    return $result;
}
add_filter('rest_pre_dispatch', 'propvendor_marketplace_restrict_unavailable_rest_item', 10, 3);

add_action('init', 'propvendor_marketplace_register_post_types');
add_action('init', 'propvendor_marketplace_register_role');
register_activation_hook(__FILE__, 'propvendor_marketplace_activate');

function propvendor_marketplace_restrict_vendor_admin_access() {
    if (!is_user_logged_in()) {
        return;
    }

    $user = wp_get_current_user();
    if (!in_array('vendor', (array) $user->roles, true)) {
        return;
    }

    global $pagenow;
    if ('admin-ajax.php' === $pagenow) {
        return;
    }

    if ('admin-post.php' === $pagenow) {
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if (in_array($action, array('propvendor_submit_listing', 'propvendor_update_listing', 'propvendor_listing_image', 'propvendor_update_vendor_profile', 'propvendor_vendor_image', 'propvendor_submit_enquiry', 'propvendor_send_inbox_reply'), true)) {
            return;
        }
    }

    wp_safe_redirect(home_url('/vendor-dashboard/'));
    exit;
}
add_action('admin_init', 'propvendor_marketplace_restrict_vendor_admin_access');

function propvendor_marketplace_hide_vendor_admin_bar($show) {
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        if (in_array('vendor', (array) $user->roles, true)) {
            return false;
        }
    }

    return $show;
}
add_filter('show_admin_bar', 'propvendor_marketplace_hide_vendor_admin_bar');

function propvendor_marketplace_activate() {
    propvendor_marketplace_register_post_types();
    propvendor_marketplace_register_role();
    propvendor_marketplace_create_default_pages();
    propvendor_marketplace_create_enquiries_table();
    propvendor_marketplace_create_credit_ledger_table();
    propvendor_marketplace_create_payment_tables();
    flush_rewrite_rules();
}

function propvendor_marketplace_enqueue_assets() {
    wp_enqueue_style(
        'propvendor-marketplace',
        plugins_url('assets/css/front-end.css', __FILE__),
        array(),
        '0.5.0'
    );
    wp_enqueue_script(
        'paystack-inline',
        'https://js.paystack.co/v1/inline.js',
        array(),
        null,
        true
    );
    wp_enqueue_script(
        'propvendor-marketplace-frontend',
        plugins_url('assets/js/front-end.js', __FILE__),
        array('jquery'),
        '0.5.0',
        true
    );
    wp_localize_script(
        'propvendor-marketplace-frontend',
        'propvendor_payment_vars',
        array(
            'ajax_url'            => admin_url('admin-ajax.php'),
            'rest_verify_url'     => esc_url_raw(rest_url('propvendor/v1/verify-payment')),
            'paystack_public_key' => propvendor_marketplace_get_paystack_public_key(),
            'nonce'               => wp_create_nonce('propvendor_checkout_nonce'),
            'unlock_nonce'        => wp_create_nonce('propvendor_unlock_lead_nonce'),
            'wallet_url'          => home_url('/vendor-wallet/'),
            'user_email'          => is_user_logged_in() ? wp_get_current_user()->user_email : '',
        )
    );
}
add_action('wp_enqueue_scripts', 'propvendor_marketplace_enqueue_assets');

function propvendor_marketplace_create_default_pages() {
    $pages = array(
        'home' => array(
            'title' => 'Home',
            'content' => '[propvendor_home]',
        ),
        'browse-listings' => array(
            'title' => 'Browse Listings',
            'content' => '[propvendor_browse_listings]',
        ),
        'property-details' => array(
            'title' => 'Property Details',
            'content' => '<p>Property detail pages are generated from each listing record to present pricing, location, features, and vendor details.</p>',
        ),
        'vehicle-details' => array(
            'title' => 'Vehicle Details',
            'content' => '<p>Vehicle detail pages will present make, model, condition, rental or sale rate, location and vendor profile information.</p>',
        ),
        'submit-a-listing' => array(
            'title' => 'Submit a Listing',
            'content' => '[propvendor_submit_listing_form]',
        ),
        'vendor-registration' => array(
            'title' => 'Vendor Registration',
            'content' => '[propvendor_vendor_registration_form]',
        ),
        'vendor-dashboard' => array(
            'title' => 'Vendor Dashboard',
            'content' => '[propvendor_vendor_dashboard]',
        ),
        'vendor-profile' => array(
            'title' => 'Vendor Profile',
            'content' => '[propvendor_vendor_profile]',
        ),
        'about-propvendor' => array(
            'title' => 'About PropVendor',
            'content' => '<p>PropVendor links Nigerian buyers with reputable vendors for properties, land sales and vehicles across the country.</p>',
        ),
        'contact-us' => array(
            'title' => 'Contact Us',
            'content' => '[propvendor_contact_form]',
        ),
        'help-and-faqs' => array(
            'title' => 'Help and FAQs',
            'content' => '<h3>Frequently asked questions</h3><p>How do I list a property? Who approves listings? How do I request a viewing?</p>',
        ),
        'privacy-policy' => array(
            'title' => 'Privacy Policy',
            'content' => '<p>PropVendor respects customer and vendor privacy and stores contact details securely for marketplace operations.</p>',
        ),
        'terms-and-conditions' => array(
            'title' => 'Terms and Conditions',
            'content' => '<p>Marketplace users agree to accurate listing details, safe transactions and respectful communication.</p>',
        ),
        'listing-and-transaction-safety-guidelines' => array(
            'title' => 'Listing and Transaction Safety Guidelines',
            'content' => '<p>Verify identity, confirm documentation, use trusted communication channels and avoid cash-only transactions without due diligence.</p>',
        ),
        'login-and-my-account' => array(
            'title' => 'Login and My Account',
            'content' => '<p>Customers and vendors can manage their profiles, saved searches and listing status from their account area.</p>',
        ),
        'customer-inbox' => array(
            'title' => 'Customer Inbox',
            'content' => '[propvendor_customer_inbox]',
        ),
        'vendor-wallet' => array(
            'title' => 'Vendor Wallet',
            'content' => '[propvendor_vendor_wallet]',
        ),
    );

    foreach ($pages as $slug => $page) {
        $existing = get_page_by_path($slug);
        if ($existing) {
            continue;
        }

        $post_id = wp_insert_post(
            array(
                'post_title' => $page['title'],
                'post_name' => $slug,
                'post_content' => $page['content'],
                'post_status' => 'publish',
                'post_type' => 'page',
            )
        );

        if ($slug === 'home' && $post_id) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', $post_id);
        }
    }
}

function propvendor_marketplace_ensure_customer_inbox_page() {
    $existing = get_page_by_path('customer-inbox');
    if (!$existing) {
        wp_insert_post(
            array(
                'post_title'   => 'Customer Inbox',
                'post_name'    => 'customer-inbox',
                'post_content' => '[propvendor_customer_inbox]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
            )
        );
    }
}
add_action('init', 'propvendor_marketplace_ensure_customer_inbox_page');

function propvendor_marketplace_public_listing_meta_query() {
    $moderation_keys = array('propvendor_listing_status', 'propvendor_status');
    $unapproved_statuses = array('Pending review', 'Rejected', 'Unavailable', 'Expired', 'Sold', 'Rented');
    $meta_query = array('relation' => 'AND');

    foreach ($moderation_keys as $meta_key) {
        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key' => $meta_key,
                'value' => $unapproved_statuses,
                'compare' => 'NOT IN',
            ),
            array(
                'key' => $meta_key,
                'compare' => 'NOT EXISTS',
            ),
        );
    }

    return $meta_query;
}

function propvendor_marketplace_filter_public_listing_queries($query) {
    if (is_admin() || !$query->is_main_query()) {
        return;
    }

    $post_types = $query->get('post_type');
    $post_types = is_array($post_types) ? $post_types : array($post_types);
    $has_listing_type = (bool) array_intersect(propvendor_marketplace_get_listing_post_types(), $post_types);
    $has_listing_taxonomy = $query->is_tax(array('listing_category', 'listing_location', 'listing_state'));

    if (!$has_listing_type && !$has_listing_taxonomy) {
        return;
    }

    $query->set('post_status', 'publish');
    $existing_meta_query = $query->get('meta_query');
    if (!is_array($existing_meta_query)) {
        $existing_meta_query = array();
    }
    if (isset($existing_meta_query['relation'])) {
        unset($existing_meta_query['relation']);
    }

    $query->set(
        'meta_query',
        array_merge(
            array('relation' => 'AND'),
            $existing_meta_query,
            propvendor_marketplace_public_listing_meta_query()
        )
    );
}
add_action('pre_get_posts', 'propvendor_marketplace_filter_public_listing_queries');

function propvendor_marketplace_is_public_listing($listing) {
    if (!$listing instanceof WP_Post
        || !in_array($listing->post_type, propvendor_marketplace_get_listing_post_types(), true)
        || 'publish' !== $listing->post_status
    ) {
        return false;
    }

    $unavailable_statuses = array('Pending review', 'Rejected', 'Unavailable', 'Expired');
    foreach (array('propvendor_listing_status', 'propvendor_status') as $meta_key) {
        $status = get_post_meta($listing->ID, $meta_key, true);
        if ('' !== $status && in_array($status, $unavailable_statuses, true)) {
            return false;
        }
    }

    return true;
}

function propvendor_marketplace_get_listing_for_attachment($attachment_id) {
    $attachment = get_post($attachment_id);
    if (!$attachment instanceof WP_Post || 'attachment' !== $attachment->post_type) {
        return null;
    }

    $parent = get_post($attachment->post_parent);
    if ($parent instanceof WP_Post && in_array($parent->post_type, propvendor_marketplace_get_listing_post_types(), true)) {
        return $parent;
    }

    $listing_ids = get_posts(
        array(
            'post_type' => propvendor_marketplace_get_listing_post_types(),
            'post_status' => array('publish', 'pending', 'draft', 'private', 'trash'),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_thumbnail_id',
            'meta_value' => $attachment->ID,
            'no_found_rows' => true,
        )
    );

    if (!empty($listing_ids)) {
        return get_post($listing_ids[0]);
    }

    $gallery_listings = get_posts(
        array(
            'post_type' => propvendor_marketplace_get_listing_post_types(),
            'post_status' => array('publish', 'pending', 'draft', 'private', 'trash'),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => '_propvendor_gallery_image_ids',
                    'value' => (string) $attachment->ID,
                    'compare' => 'LIKE',
                ),
            ),
            'no_found_rows' => true,
        )
    );

    return !empty($gallery_listings) ? get_post($gallery_listings[0]) : null;
}

function propvendor_marketplace_get_private_listing_image_root() {
    $uploads = wp_upload_dir();
    if (!empty($uploads['error'])) {
        return new WP_Error('propvendor_upload_directory_error', $uploads['error']);
    }

    return trailingslashit($uploads['basedir']) . 'propvendor-private';
}

function propvendor_marketplace_ensure_private_listing_storage() {
    $private_root = propvendor_marketplace_get_private_listing_image_root();
    if (is_wp_error($private_root)) {
        return $private_root;
    }

    if (!wp_mkdir_p($private_root) || !is_dir($private_root) || !is_writable($private_root)) {
        return new WP_Error('propvendor_private_storage_unavailable', 'Protected listing image storage could not be created or written.');
    }

    $apache_rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
    $iis_rules = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\" /><add accessType=\"Deny\" users=\"*\" /></authorization></security></system.webServer></configuration>\n";
    $index_file = "<?php\nexit;\n";
    $rules = array(
        trailingslashit($private_root) . '.htaccess' => $apache_rules,
        trailingslashit($private_root) . 'web.config' => $iis_rules,
        trailingslashit($private_root) . 'index.php' => $index_file,
    );

    foreach ($rules as $path => $contents) {
        if (!is_file($path) || file_get_contents($path) !== $contents) {
            if (false === file_put_contents($path, $contents, LOCK_EX)) {
                return new WP_Error('propvendor_private_storage_rules_failed', 'Protected listing image access rules could not be installed.');
            }
        }
    }

    return $private_root;
}

function propvendor_marketplace_use_private_listing_upload_directory($uploads) {
    $uploads['subdir'] = '/propvendor-private' . (isset($uploads['subdir']) ? $uploads['subdir'] : '');
    $uploads['path'] = trailingslashit($uploads['basedir']) . ltrim($uploads['subdir'], '/');
    $uploads['url'] = trailingslashit($uploads['baseurl']) . ltrim($uploads['subdir'], '/');

    return $uploads;
}

function propvendor_marketplace_get_listing_image_delivery_url($attachment_id, $size = 'full') {
    return add_query_arg(
        array(
            'action' => 'propvendor_listing_image',
            'attachment_id' => absint($attachment_id),
            'size' => sanitize_key($size),
        ),
        admin_url('admin-post.php')
    );
}

function propvendor_marketplace_filter_listing_attachment_url($url, $attachment_id) {
    if (propvendor_marketplace_get_listing_for_attachment($attachment_id)) {
        return propvendor_marketplace_get_listing_image_delivery_url($attachment_id);
    }

    return $url;
}
add_filter('wp_get_attachment_url', 'propvendor_marketplace_filter_listing_attachment_url', 10, 2);

function propvendor_marketplace_filter_listing_attachment_image_src($image, $attachment_id, $size, $icon) {
    if (!$image || !propvendor_marketplace_get_listing_for_attachment($attachment_id)) {
        return $image;
    }

    $metadata = wp_get_attachment_metadata($attachment_id);
    $image_filename = wp_basename(wp_parse_url($image['0'], PHP_URL_PATH));
    $delivery_size = 'full';
    if (is_array($metadata) && !empty($image_filename)) {
        foreach ((array) ($metadata['sizes'] ?? array()) as $size_name => $size_data) {
            if (!empty($size_data['file']) && wp_basename($size_data['file']) === $image_filename) {
                $delivery_size = sanitize_key($size_name);
                break;
            }
        }
    }

    $image['0'] = propvendor_marketplace_get_listing_image_delivery_url($attachment_id, $delivery_size);
    return $image;
}
add_filter('wp_get_attachment_image_src', 'propvendor_marketplace_filter_listing_attachment_image_src', 10, 4);

function propvendor_marketplace_disable_listing_image_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
    if (propvendor_marketplace_get_listing_for_attachment($attachment_id)) {
        return false;
    }

    return $sources;
}
add_filter('wp_calculate_image_srcset', 'propvendor_marketplace_disable_listing_image_srcset', 10, 5);

function propvendor_marketplace_deliver_listing_image() {
    $attachment_id = isset($_GET['attachment_id']) ? absint($_GET['attachment_id']) : 0;
    $size = isset($_GET['size']) ? sanitize_key(wp_unslash($_GET['size'])) : 'full';
    $attachment = get_post($attachment_id);
    $listing = propvendor_marketplace_get_listing_for_attachment($attachment_id);
    $private_root = propvendor_marketplace_get_private_listing_image_root();

    if (!$attachment instanceof WP_Post || !$listing instanceof WP_Post || is_wp_error($private_root)) {
        status_header(404);
        exit;
    }

    $may_view = propvendor_marketplace_is_public_listing($listing)
        || current_user_can('manage_options')
        || ((int) $listing->post_author === get_current_user_id() && current_user_can('propvendor_submit_marketplace_listings'));
    if (!$may_view) {
        status_header(404);
        nocache_headers();
        exit;
    }

    $original_path = get_attached_file($attachment_id);
    $original_real_path = $original_path ? realpath($original_path) : false;
    $private_real_root = realpath($private_root);
    if (!$original_real_path || !$private_real_root
        || 0 !== strpos(wp_normalize_path($original_real_path), trailingslashit(wp_normalize_path($private_real_root)))
        || !is_file($original_real_path)
    ) {
        status_header(404);
        exit;
    }

    $image_path = $original_real_path;
    if ('full' !== $size) {
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!is_array($metadata)
            || empty($metadata['sizes'][$size]['file'])
            || !preg_match('/^[a-zA-Z0-9_-]+$/', $size)
        ) {
            status_header(404);
            exit;
        }
        $image_path = dirname($original_real_path) . DIRECTORY_SEPARATOR . basename($metadata['sizes'][$size]['file']);
    }

    $image_real_path = realpath($image_path);
    if (!$image_real_path
        || 0 !== strpos(wp_normalize_path($image_real_path), trailingslashit(wp_normalize_path($private_real_root)))
        || !is_file($image_real_path)
    ) {
        status_header(404);
        exit;
    }

    $image_info = @getimagesize($image_real_path);
    $allowed_mime_types = array('image/jpeg', 'image/png', 'image/webp');
    if (!$image_info || empty($image_info['mime']) || !in_array($image_info['mime'], $allowed_mime_types, true)) {
        status_header(404);
        exit;
    }

    nocache_headers();
    header('Content-Type: ' . $image_info['mime']);
    header('Content-Length: ' . filesize($image_real_path));
    header('Content-Disposition: inline; filename="' . sanitize_file_name(basename($image_real_path)) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($image_real_path);
    exit;
}
add_action('admin_post_nopriv_propvendor_listing_image', 'propvendor_marketplace_deliver_listing_image');
add_action('admin_post_propvendor_listing_image', 'propvendor_marketplace_deliver_listing_image');

function propvendor_marketplace_hide_unavailable_listing_routes() {
    if (is_attachment()) {
        $attachment = get_queried_object();
        $parent_listing = $attachment instanceof WP_Post ? propvendor_marketplace_get_listing_for_attachment($attachment->ID) : null;
        if ($parent_listing instanceof WP_Post && !propvendor_marketplace_is_public_listing($parent_listing)) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
            return;
        }
        if ($parent_listing instanceof WP_Post) {
            wp_safe_redirect(get_permalink($parent_listing), 301);
            exit;
        }
    }

    if (!is_singular(propvendor_marketplace_get_listing_post_types())) {
        return;
    }

    $listing = get_queried_object();
    if (propvendor_marketplace_is_public_listing($listing)) {
        return;
    }

    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    nocache_headers();
}
add_action('template_redirect', 'propvendor_marketplace_hide_unavailable_listing_routes', 1);

function propvendor_marketplace_filter_keyword_search($search, $query) {
    $keyword = $query->get('propvendor_keyword_search');
    if (empty($keyword)) {
        return $search;
    }

    global $wpdb;
    $terms = preg_split('/\s+/', $keyword, -1, PREG_SPLIT_NO_EMPTY);
    $conditions = array();
    $prepare_values = array();

    foreach ($terms as $term) {
        $like = '%' . $wpdb->esc_like($term) . '%';
        $conditions[] = '((' . $wpdb->posts . '.post_title LIKE %s OR ' . $wpdb->posts . '.post_excerpt LIKE %s OR ' . $wpdb->posts . '.post_content LIKE %s)
            OR EXISTS (SELECT 1 FROM ' . $wpdb->postmeta . ' AS propvendor_search_location WHERE propvendor_search_location.post_id = ' . $wpdb->posts . '.ID AND propvendor_search_location.meta_key = %s AND propvendor_search_location.meta_value LIKE %s)
            OR EXISTS (SELECT 1 FROM ' . $wpdb->postmeta . ' AS propvendor_search_addr WHERE propvendor_search_addr.post_id = ' . $wpdb->posts . '.ID AND propvendor_search_addr.meta_key IN (%s, %s, %s) AND propvendor_search_addr.meta_value LIKE %s))';
        $prepare_values = array_merge(
            $prepare_values,
            array($like, $like, $like, 'propvendor_location', $like, 'propvendor_full_address', '_listing_full_address', 'full_address', $like)
        );
    }

    if (empty($conditions)) {
        return '';
    }

    return ' AND (' . $wpdb->prepare(implode(' AND ', $conditions), $prepare_values) . ')';
}

function propvendor_marketplace_get_public_locations() {
    global $wpdb;
    $post_types = propvendor_marketplace_get_listing_post_types();
    $type_placeholders = implode(', ', array_fill(0, count($post_types), '%s'));
    $query = "SELECT DISTINCT location_meta.meta_value
        FROM {$wpdb->postmeta} AS location_meta
        INNER JOIN {$wpdb->posts} AS listing ON listing.ID = location_meta.post_id
        WHERE listing.post_type IN ({$type_placeholders})
        AND listing.post_status = 'publish'
        AND location_meta.meta_key = %s
        AND TRIM(location_meta.meta_value) <> ''
        AND NOT EXISTS (
            SELECT 1 FROM {$wpdb->postmeta} AS moderation_meta
            WHERE moderation_meta.post_id = listing.ID
            AND moderation_meta.meta_key IN (%s, %s)
            AND moderation_meta.meta_value IN (%s, %s, %s, %s)
        )
        ORDER BY location_meta.meta_value ASC";
    $prepare_values = array_merge(
        $post_types,
        array('propvendor_location', 'propvendor_listing_status', 'propvendor_status', 'Pending review', 'Rejected', 'Unavailable', 'Expired')
    );

    return $wpdb->get_col($wpdb->prepare($query, $prepare_values));
}

function propvendor_marketplace_listings_query($count = 6) {
    $query = new WP_Query(
        array(
            'post_type' => propvendor_marketplace_get_listing_post_types(),
            'post_status' => 'publish',
            'posts_per_page' => max(1, absint($count)),
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => propvendor_marketplace_public_listing_meta_query(),
            'no_found_rows' => true,
        )
    );

    return $query->posts;
}

/**
 * Render Universal Anti-Advance-Fee Trust & Safety Notice.
 *
 * @return string HTML
 */
function propvendor_marketplace_render_anti_fraud_notice() {
    $notice_text = '⚠️ Never pay inspection fees, commitment fees, or transport money before seeing this vehicle or property in person. PropVendor will never ask you to transfer money to an agent beforehand.';
    return '<div class="propvendor-safety-notice-card" style="margin: 1.25rem 0; padding: 1rem 1.25rem; background: #fff8e6; border: 1px solid #ffd591; border-left: 4px solid #fa8c16; border-radius: 6px; color: #873800; font-size: 0.92rem; line-height: 1.5; font-weight: 500;">' . esc_html($notice_text) . '</div>';
}

/**
 * Render Vehicle Purchase CMRIS & Customs Legal Notice.
 *
 * @return string HTML
 */
function propvendor_marketplace_render_vehicle_legal_notice() {
    $notice_text = '⚠️ Vehicle Purchase Notice: PropVendor is an advertising platform. Buyers are strongly advised to independently verify the VIN (Chassis Number) with the Nigeria Police Central Motor Registry (CMRIS), confirm plate registration with FRSC, and demand genuine Nigeria Customs duty clearance before purchase.';
    return '<div class="propvendor-vehicle-legal-notice-card" style="margin: 1.25rem 0; padding: 1rem 1.25rem; background: #f0f5ff; border: 1px solid #adc6ff; border-left: 4px solid #2f54eb; border-radius: 6px; color: #10239e; font-size: 0.92rem; line-height: 1.5; font-weight: 500;">' . esc_html($notice_text) . '</div>';
}

function propvendor_marketplace_render_listing_cards($listings, $title = 'Listings') {
    if (empty($listings)) {
        return '<p class="propvendor-empty">No listings are available to display right now.</p>';
    }

    $output = '<div class="propvendor-listings-header"><h2>' . esc_html($title) . '</h2></div><div class="propvendor-grid">';

    foreach ($listings as $listing) {
        $post_type = get_post_type($listing);
        $type_label = array(
            'property_listing' => 'Property',
            'land_listing' => 'Land',
            'vehicle_listing' => 'Vehicle',
        );

        $price = get_post_meta($listing->ID, 'propvendor_price', true);
        $location = get_post_meta($listing->ID, 'propvendor_location', true);
        $image = get_the_post_thumbnail($listing->ID, 'medium_large', array('class' => 'propvendor-card-image'));
        $categories = get_the_terms($listing->ID, 'listing_category');
        $category_labels = array();
        if (is_array($categories)) {
            foreach ($categories as $category) {
                $category_labels[] = $category->name;
            }
        }

        $is_direct_owner = get_post_meta($listing->ID, '_propvendor_is_direct_owner', true);
        $direct_owner_status = get_post_meta($listing->ID, '_propvendor_direct_owner_status', true);

        $output .= '<article class="propvendor-card">';
        if ($image) {
            $output .= '<a class="propvendor-card-image-link" href="' . esc_url(get_permalink($listing)) . '" aria-label="' . esc_attr(sprintf('View details for %s', get_the_title($listing))) . '">' . $image . '</a>';
        }
        $output .= '<div class="propvendor-badge">' . esc_html($type_label[$post_type] ?? 'Listing') . '</div>';
        if ('1' === (string) $is_direct_owner && 'approved' === (string) $direct_owner_status) {
            $output .= '<div class="propvendor-badge propvendor-badge-direct-owner" style="background: #0a8a4f; color: #fff; font-weight: 600;">Direct from Owner — Zero Agent Fee</div>';
        }
        if (!empty($category_labels)) {
            $output .= '<p class="propvendor-card-category">' . esc_html(implode(', ', $category_labels)) . '</p>';
        }
        $output .= '<h3><a href="' . esc_url(get_permalink($listing)) . '">' . esc_html(get_the_title($listing)) . '</a></h3>';
        $output .= '<p class="propvendor-meta">' . esc_html($location ?: 'Location not provided') . '</p>';
        $output .= '<p class="propvendor-price">' . ( $price ? esc_html('₦ ' . number_format((float) $price, 2)) : esc_html('Price on request') ) . '</p>';
        $output .= '<a class="propvendor-card-view" href="' . esc_url(get_permalink($listing)) . '">View Details</a>';
        $output .= '</article>';
    }

    $output .= '</div>';

    return $output;
}

function propvendor_marketplace_render_listing_details($content) {
    if (!is_singular(propvendor_marketplace_get_listing_post_types()) || !in_the_loop() || !is_main_query()) {
        return $content;
    }

    global $post;
    if (!propvendor_marketplace_is_public_listing($post)) {
        return $content;
    }

    $type_labels = array(
        'property_listing' => 'Property',
        'land_listing' => 'Land',
        'vehicle_listing' => 'Vehicle',
    );
    $type_label = $type_labels[$post->post_type];
    $categories = get_the_terms($post->ID, 'listing_category');
    $category_names = array();
    if (is_array($categories)) {
        foreach ($categories as $category) {
            $category_names[] = $category->name;
        }
    } else {
        $categories = array();
    }

    $location = get_post_meta($post->ID, 'propvendor_location', true);
    $full_address = get_post_meta($post->ID, 'propvendor_full_address', true);
    $price = get_post_meta($post->ID, 'propvendor_price', true);
    $details = array(
        'Listing type' => $type_label,
        'Category' => !empty($category_names) ? implode(', ', $category_names) : '',
        'State' => $location,
    );
    if (!empty($full_address)) {
        $details['Address'] = $full_address;
    }
    $details['Price'] = is_numeric($price) && (float) $price > 0 ? '₦ ' . number_format((float) $price, 2) : 'Price on request';

    if ('property_listing' === $post->post_type) {
        $subtype = get_post_meta($post->ID, 'propvendor_property_subtype', true);
        if ($subtype) {
            $details['Property type'] = $subtype;
        }
        $purpose = get_post_meta($post->ID, 'propvendor_purpose', true);
        if ($purpose) {
            $details['Purpose'] = $purpose;
        }
        $bedrooms = get_post_meta($post->ID, 'propvendor_bedrooms', true);
        $bathrooms = get_post_meta($post->ID, 'propvendor_bathrooms', true);
        if ('' !== $bedrooms && null !== $bedrooms) {
            $details['Bedrooms'] = absint($bedrooms);
        }
        if ('' !== $bathrooms && null !== $bathrooms) {
            $details['Bathrooms'] = absint($bathrooms);
        }
        $furnishing = get_post_meta($post->ID, 'propvendor_furnishing', true);
        if ($furnishing) {
            $details['Furnishing'] = $furnishing;
        }
        $floor_area = get_post_meta($post->ID, 'propvendor_floor_area', true);
        $floor_area_unit = get_post_meta($post->ID, 'propvendor_floor_area_unit', true) ?: 'sqm';
        if ($floor_area) {
            $details['Floor area'] = esc_html($floor_area . ' ' . $floor_area_unit);
        }
        $plot_size = get_post_meta($post->ID, 'propvendor_plot_size', true);
        $plot_size_unit = get_post_meta($post->ID, 'propvendor_plot_size_unit', true);
        if ($plot_size) {
            $unit_label = propvendor_marketplace_get_plot_size_unit_label($plot_size_unit);
            $details['Plot size'] = esc_html($plot_size . ($unit_label ? ' ' . $unit_label : ''));
        }
        $title_doc = get_post_meta($post->ID, 'propvendor_title_document', true);
        if ($title_doc) {
            $details['Title document'] = $title_doc;
        }
    } elseif ('land_listing' === $post->post_type) {
        $land_use = get_post_meta($post->ID, 'propvendor_land_use', true);
        if ($land_use) {
            $details['Land use'] = $land_use;
        }
        $plot_size = get_post_meta($post->ID, 'propvendor_plot_size', true);
        $plot_size_unit = get_post_meta($post->ID, 'propvendor_plot_size_unit', true);
        if ('' !== $plot_size && null !== $plot_size) {
            $unit_label = propvendor_marketplace_get_plot_size_unit_label($plot_size_unit);
            $details['Land size'] = esc_html($plot_size . ($unit_label ? ' ' . $unit_label : ''));
        }
        $length = get_post_meta($post->ID, 'propvendor_land_length', true);
        $width = get_post_meta($post->ID, 'propvendor_land_width', true);
        $dim_unit = get_post_meta($post->ID, 'propvendor_dimension_unit', true) ?: 'm';
        if ($length || $width) {
            $details['Dimensions'] = esc_html(($length ?: '-') . ' x ' . ($width ?: '-') . ' ' . $dim_unit);
        }
        $frontage = get_post_meta($post->ID, 'propvendor_frontage', true);
        if ($frontage) {
            $details['Road frontage'] = $frontage;
        }
        $title_doc = get_post_meta($post->ID, 'propvendor_title_document', true);
        if ($title_doc) {
            $details['Title document'] = $title_doc;
        }
    } elseif ('vehicle_listing' === $post->post_type) {
        $veh_subtype = get_post_meta($post->ID, 'propvendor_vehicle_subtype', true);
        if ($veh_subtype) {
            $details['Vehicle type'] = $veh_subtype;
        }
        $make = get_post_meta($post->ID, 'propvendor_vehicle_make', true);
        $model = get_post_meta($post->ID, 'propvendor_vehicle_model', true);
        if ($make || $model) {
            $details['Make / Model'] = trim($make . ' ' . $model);
        }
        $year = get_post_meta($post->ID, 'propvendor_vehicle_year', true);
        if ($year) {
            $details['Year'] = absint($year);
        }
        $condition = get_post_meta($post->ID, 'propvendor_condition', true);
        if ('' !== $condition && null !== $condition) {
            $details['Condition'] = $condition;
        }
        $trans = get_post_meta($post->ID, 'propvendor_transmission', true);
        if ($trans) {
            $details['Transmission'] = $trans;
        }
        $mileage = get_post_meta($post->ID, 'propvendor_mileage', true);
        if ('' !== $mileage && null !== $mileage) {
            $details['Mileage'] = number_format(absint($mileage)) . ' km';
        }
        $fuel = get_post_meta($post->ID, 'propvendor_fuel_type', true);
        if ($fuel) {
            $details['Fuel type'] = $fuel;
        }
        $engine = get_post_meta($post->ID, 'propvendor_engine_size', true);
        if ($engine) {
            $details['Engine size'] = $engine;
        }
    }

    $is_direct_owner = get_post_meta($post->ID, '_propvendor_is_direct_owner', true);
    $direct_owner_status = get_post_meta($post->ID, '_propvendor_direct_owner_status', true);
    $has_owner_direct_badge = ('1' === (string) $is_direct_owner && 'approved' === (string) $direct_owner_status);

    if ($has_owner_direct_badge) {
        $details['Ownership'] = 'Direct from Owner — Zero Agent Fee';
    }

    $output = '<section class="propvendor-listing-detail" aria-label="' . esc_attr($type_label . ' listing details') . '">';
    $output .= '<div class="propvendor-detail-top"><a class="propvendor-detail-back" href="' . esc_url(home_url('/browse-listings/')) . '">&larr; Browse Listings</a></div>';

    if ($has_owner_direct_badge) {
        $output .= '<div class="propvendor-detail-owner-badge" style="margin: 0.5rem 0 1rem 0;"><span class="propvendor-badge propvendor-badge-direct-owner" style="display: inline-block; padding: 6px 14px; background: #0a8a4f; color: #fff; font-size: 0.95rem; font-weight: 700; border-radius: 4px;">Direct from Owner — Zero Agent Fee</span></div>';
    }

    // Stage 2: Universal Anti-Advance-Fee Trust & Safety Notice
    $output .= propvendor_marketplace_render_anti_fraud_notice();

    // Stage 2: Vehicle-Specific CMRIS & Customs Legal Notice
    if ('vehicle_listing' === $post->post_type) {
        $output .= propvendor_marketplace_render_vehicle_legal_notice();
    }

    $output .= '<div class="propvendor-detail-layout"><div class="propvendor-detail-main">';

    $image_ids = propvendor_marketplace_get_listing_image_ids($post->ID);


    $output .= '<div class="propvendor-detail-gallery">';
    if (!empty($image_ids)) {
        foreach ($image_ids as $image_id) {
            $alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
            if ('' === $alt) {
                $alt = get_the_title($post);
            }
            $output .= '<figure>' . wp_get_attachment_image($image_id, 'large', false, array('class' => 'propvendor-detail-image', 'alt' => $alt, 'loading' => 'lazy')) . '</figure>';
        }
    } else {
        $output .= '<p class="propvendor-detail-no-image">No listing images have been provided.</p>';
    }
    $output .= '</div>';

    $output .= '<section class="propvendor-detail-description"><h2>Description</h2>';
    if ('' !== trim($post->post_content)) {
        $output .= wp_kses_post(wpautop($post->post_content));
    } else {
        $output .= '<p>Description not provided.</p>';
    }
    $output .= '</section></div>';

    $output .= '<aside class="propvendor-detail-sidebar"><section class="propvendor-detail-facts"><h2>Listing details</h2><dl>';
    foreach ($details as $label => $value) {
        if ('' === $value || null === $value) {
            if ('Category' === $label) {
                $value = 'Not specified';
            } elseif ('Location' === $label) {
                $value = 'Not provided';
            } else {
                continue;
            }
        }
        $output .= '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html((string) $value) . '</dd></div>';
    }
    $output .= '</dl></section>';

    $vendor = get_user_by('id', (int) $post->post_author);
    if ($vendor) {
        $business_name = get_user_meta($vendor->ID, 'propvendor_business_name', true);
        $vendor_name = $business_name ? $business_name : $vendor->display_name;
        $vendor_profile_url = propvendor_marketplace_get_vendor_profile_url($vendor->ID);
        $show_phone = get_user_meta($vendor->ID, 'propvendor_show_phone', true);
        $show_email = get_user_meta($vendor->ID, 'propvendor_show_email', true);
        $phone = get_user_meta($vendor->ID, 'propvendor_phone_number', true);
        $public_email = get_user_meta($vendor->ID, 'propvendor_public_email', true);

        $output .= '<section class="propvendor-detail-contact"><h2>Vendor Information</h2>';
        $output .= '<p class="propvendor-detail-vendor-name"><a href="' . esc_url($vendor_profile_url) . '"><strong>' . esc_html($vendor_name) . '</strong></a></p>';
        $output .= '<p class="propvendor-detail-view-profile"><a class="propvendor-btn secondary propvendor-btn-sm" href="' . esc_url($vendor_profile_url) . '">View Vendor Profile &rarr;</a></p>';

        $has_public_contact = false;
        if ('1' === (string) $show_phone && '' !== $phone) {
            $has_public_contact = true;
            $telephone = preg_replace('/[^0-9+]/', '', $phone);
            if ('' !== $telephone) {
                $output .= '<a class="propvendor-contact-action" href="' . esc_attr('tel:' . $telephone) . '">Call vendor</a>';
            }
            $output .= '<p class="propvendor-contact-detail">' . esc_html($phone) . '</p>';
        }
        if ('1' === (string) $show_email && is_email($public_email)) {
            $has_public_contact = true;
            $output .= '<a class="propvendor-contact-action" href="' . esc_url('mailto:' . $public_email) . '">Email vendor</a>';
        }

        if (!$has_public_contact) {
            $output .= '<p class="propvendor-detail-muted">Contact details are kept private by this vendor.</p>';
        }
        $output .= '</section>';
    }

    $output .= propvendor_marketplace_render_listing_enquiry_form($post);
    $output .= '</aside></div>';

    $related_args = array(
        'post_type' => $post->post_type,
        'post_status' => 'publish',
        'posts_per_page' => 3,
        'post__not_in' => array($post->ID),
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_query' => propvendor_marketplace_public_listing_meta_query(),
        'no_found_rows' => true,
    );
    if (!empty($categories)) {
        $related_args['tax_query'] = array(
            array(
                'taxonomy' => 'listing_category',
                'field' => 'term_id',
                'terms' => wp_list_pluck($categories, 'term_id'),
            ),
        );
    }
    $related = new WP_Query($related_args);
    if ($related->have_posts()) {
        $output .= '<section class="propvendor-related-listings">';
        $output .= propvendor_marketplace_render_listing_cards($related->posts, 'Related listings');
        $output .= '</section>';
    }

    // Phase 5: Accessible Confirmation Modal for Lead Contact Unlock
    $output .= '
    <div id="pv-unlock-lead-modal" class="pv-unlock-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="pv-unlock-modal-title">
        <div class="pv-unlock-modal-backdrop"></div>
        <div class="pv-unlock-modal-dialog">
            <div class="pv-unlock-modal-header">
                <h4 id="pv-unlock-modal-title"><span class="dashicons dashicons-unlock"></span> ' . esc_html__('Confirm Lead Contact Unlock', 'propvendor-marketplace') . '</h4>
                <button type="button" class="pv-unlock-modal-close" aria-label="' . esc_attr__('Close dialog', 'propvendor-marketplace') . '">&times;</button>
            </div>
            <div class="pv-unlock-modal-body">
                <p>' . esc_html__('Are you sure you want to unlock this customer\'s direct contact details?', 'propvendor-marketplace') . '</p>
                <div class="pv-unlock-modal-details">
                    <div><strong>' . esc_html__('Cost:', 'propvendor-marketplace') . '</strong> 1 Credit</div>
                    <div><strong>' . esc_html__('Your Balance:', 'propvendor-marketplace') . '</strong> <span id="pv-modal-current-balance">0</span> Credits</div>
                    <div><strong>' . esc_html__('Balance After Unlock:', 'propvendor-marketplace') . '</strong> <span id="pv-modal-balance-after">0</span> Credits</div>
                </div>
                <p class="pv-unlock-modal-subtext">' . esc_html__('1 credit will be deducted from your wallet upon confirmation. Customer phone number and email will be revealed immediately.', 'propvendor-marketplace') . '</p>
                <div id="pv-unlock-modal-error" class="pv-unlock-modal-error" style="display: none;"></div>
            </div>
            <div class="pv-unlock-modal-footer">
                <button type="button" id="pv-btn-confirm-unlock" class="button button-primary">' . esc_html__('Confirm & Deduct 1 Credit', 'propvendor-marketplace') . '</button>
                <button type="button" id="pv-btn-cancel-unlock" class="button button-secondary">' . esc_html__('Cancel', 'propvendor-marketplace') . '</button>
            </div>
        </div>
    </div>';

    $output .= '</section>';
    return $output;
}
add_filter('the_content', 'propvendor_marketplace_render_listing_details', 20);

function propvendor_marketplace_home_shortcode() {
    $featured = propvendor_marketplace_listings_query(4);
    $output = '<section class="propvendor-hero">';
    $output .= '<div class="propvendor-hero-copy"><span class="propvendor-tag">Nigerian marketplace</span><h1>Find Properties and Rental Opportunities Across Nigeria</h1><p>Explore property sales, rentals, land listings and vehicle offers in one place. Compare options, connect with vendors and make informed decisions.</p><div class="propvendor-actions"><a class="propvendor-btn primary" href="/Propvendor/browse-listings/">Explore Listings</a><a class="propvendor-btn secondary" href="/Propvendor/vendor-registration/">Become a Vendor</a></div></div>';
    $output .= '<div class="propvendor-hero-panel"><h3>Quick search</h3><ul><li>Houses & apartments</li><li>Land & plots</li><li>Cars & vehicles</li><li>Rental opportunities</li></ul></div>';
    $output .= '</section>';
    $output .= propvendor_marketplace_render_listing_cards($featured, 'Featured listings');
    $output .= '<section class="propvendor-info-grid"><div><h3>How it works</h3><p>Browse by category or location, compare vendor details and request a viewing or enquiry.</p></div><div><h3>Vendor moderation</h3><p>Each listing should be reviewed before publication to improve trust and reduce fraud risk.</p></div><div><h3>Trust & safety</h3><p>Document verification, contact transparency and clear transaction terms help protect buyers and sellers.</p></div></section>';
    return $output;
}
add_shortcode('propvendor_home', 'propvendor_marketplace_home_shortcode');

/**
 * Build sanitized WP_Query arguments from search & filter parameters.
 *
 * @param array $params Optional GET or explicit parameters.
 * @return array
 */
function propvendor_marketplace_get_search_query_args($params = array()) {
    if (empty($params)) {
        $params = $_GET;
    }

    // 1. Keyword search (post_title, post_excerpt, post_content, location, and full_address)
    $keyword = isset($params['pv_keyword']) ? sanitize_text_field(wp_unslash($params['pv_keyword'])) : '';
    $keyword = trim(substr($keyword, 0, 150));

    // 2. Listing Type / Category
    $raw_type = isset($params['pv_type']) ? sanitize_key(wp_unslash($params['pv_type'])) : '';
    $type_aliases = array(
        'property' => 'property_listing',
        'land'     => 'land_listing',
        'vehicle'  => 'vehicle_listing',
    );
    if (isset($type_aliases[$raw_type])) {
        $raw_type = $type_aliases[$raw_type];
    }
    $allowed_types = propvendor_marketplace_get_listing_post_types();
    $listing_type  = in_array($raw_type, $allowed_types, true) ? $raw_type : '';

    // 3. State (pv_state with pv_location fallback)
    $state_param = isset($params['pv_state']) ? $params['pv_state'] : (isset($params['pv_location']) ? $params['pv_location'] : '');
    $state       = propvendor_marketplace_normalize_nigerian_state(sanitize_text_field(wp_unslash($state_param)));

    // 4. Price range
    $min_price_raw = isset($params['pv_min_price']) ? sanitize_text_field(wp_unslash($params['pv_min_price'])) : '';
    $max_price_raw = isset($params['pv_max_price']) ? sanitize_text_field(wp_unslash($params['pv_max_price'])) : '';
    $min_price_raw = str_replace(array(',', ' '), '', $min_price_raw);
    $max_price_raw = str_replace(array(',', ' '), '', $max_price_raw);
    $min_price     = ('' !== $min_price_raw && is_numeric($min_price_raw) && (float) $min_price_raw >= 0) ? (float) $min_price_raw : '';
    $max_price     = ('' !== $max_price_raw && is_numeric($max_price_raw) && (float) $max_price_raw >= 0) ? (float) $max_price_raw : '';
    if ('' !== $min_price && '' !== $max_price && $min_price > $max_price) {
        $temp      = $min_price;
        $min_price = $max_price;
        $max_price = $temp;
    }

    // 5. Title document (Property & Land)
    $title_doc = isset($params['pv_title_doc']) ? sanitize_text_field(wp_unslash($params['pv_title_doc'])) : '';
    $title_doc = trim($title_doc);

    // 6. Property-specific
    $property_subtype = isset($params['pv_property_subtype']) ? sanitize_text_field(wp_unslash($params['pv_property_subtype'])) : '';
    $min_bedrooms     = isset($params['pv_min_bedrooms']) && '' !== trim((string)$params['pv_min_bedrooms']) && is_numeric($params['pv_min_bedrooms']) ? max(0, absint($params['pv_min_bedrooms'])) : '';
    $min_bathrooms    = isset($params['pv_min_bathrooms']) && '' !== trim((string)$params['pv_min_bathrooms']) && is_numeric($params['pv_min_bathrooms']) ? max(0, absint($params['pv_min_bathrooms'])) : '';
    $furnishing       = isset($params['pv_furnishing']) ? sanitize_text_field(wp_unslash($params['pv_furnishing'])) : '';

    // 7. Land-specific
    $land_use          = isset($params['pv_land_use']) ? sanitize_text_field(wp_unslash($params['pv_land_use'])) : '';
    $min_plot_size_raw = isset($params['pv_min_plot_size']) ? sanitize_text_field(wp_unslash($params['pv_min_plot_size'])) : '';
    $max_plot_size_raw = isset($params['pv_max_plot_size']) ? sanitize_text_field(wp_unslash($params['pv_max_plot_size'])) : '';
    $min_plot_size     = ('' !== $min_plot_size_raw && is_numeric($min_plot_size_raw) && (float) $min_plot_size_raw >= 0) ? (float) $min_plot_size_raw : '';
    $max_plot_size     = ('' !== $max_plot_size_raw && is_numeric($max_plot_size_raw) && (float) $max_plot_size_raw >= 0) ? (float) $max_plot_size_raw : '';
    if ('' !== $min_plot_size && '' !== $max_plot_size && $min_plot_size > $max_plot_size) {
        $temp          = $min_plot_size;
        $min_plot_size = $max_plot_size;
        $max_plot_size = $temp;
    }
    $plot_unit = isset($params['pv_plot_unit']) ? propvendor_marketplace_normalize_plot_size_unit(sanitize_text_field(wp_unslash($params['pv_plot_unit']))) : '';

    // 8. Vehicle-specific
    $vehicle_type = isset($params['pv_vehicle_type']) ? sanitize_text_field(wp_unslash($params['pv_vehicle_type'])) : '';
    $make         = isset($params['pv_make']) ? sanitize_text_field(wp_unslash($params['pv_make'])) : '';
    $model        = isset($params['pv_model']) ? sanitize_text_field(wp_unslash($params['pv_model'])) : '';
    $min_year     = isset($params['pv_min_year']) && '' !== trim((string)$params['pv_min_year']) && is_numeric($params['pv_min_year']) ? absint($params['pv_min_year']) : '';
    $max_year     = isset($params['pv_max_year']) && '' !== trim((string)$params['pv_max_year']) && is_numeric($params['pv_max_year']) ? absint($params['pv_max_year']) : '';
    if ('' !== $min_year && '' !== $max_year && $min_year > $max_year) {
        $temp     = $min_year;
        $min_year = $max_year;
        $max_year = $temp;
    }
    $condition    = isset($params['pv_condition']) ? sanitize_text_field(wp_unslash($params['pv_condition'])) : '';
    $transmission = isset($params['pv_transmission']) ? sanitize_text_field(wp_unslash($params['pv_transmission'])) : '';

    // 9. Sorting
    $sort        = isset($params['pv_sort']) ? sanitize_key(wp_unslash($params['pv_sort'])) : 'date_desc';
    $valid_sorts = array('date_desc', 'date_asc', 'price_asc', 'price_desc');
    if (!in_array($sort, $valid_sorts, true)) {
        $sort = 'date_desc';
    }

    // 10. Taxonomy category (pv_category)
    $category_id = isset($params['pv_category']) ? absint($params['pv_category']) : 0;
    if ($category_id && !term_exists($category_id, 'listing_category')) {
        $category_id = 0;
    }

    // 11. Pagination
    $paged          = max(1, absint(get_query_var('paged', 1)), isset($params['paged']) ? absint($params['paged']) : 1);
    $posts_per_page = isset($params['posts_per_page']) ? max(1, absint($params['posts_per_page'])) : 9;

    // Build meta_query
    $meta_query = array('relation' => 'AND');

    // Active listing filter: Exclude 'Pending review', 'Rejected', 'Unavailable', 'Expired', 'Sold', 'Rented'
    $inactive_statuses = array('Pending review', 'Rejected', 'Unavailable', 'Expired', 'Sold', 'Rented');
    $moderation_keys   = array('propvendor_listing_status', 'propvendor_status');
    foreach ($moderation_keys as $meta_key) {
        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key'     => $meta_key,
                'value'   => $inactive_statuses,
                'compare' => 'NOT IN',
            ),
            array(
                'key'     => $meta_key,
                'compare' => 'NOT EXISTS',
            ),
        );
    }

    // State filter (checks propvendor_location, _listing_location, location)
    if (!empty($state)) {
        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key'     => 'propvendor_location',
                'value'   => $state,
                'compare' => 'LIKE',
            ),
            array(
                'key'     => '_listing_location',
                'value'   => $state,
                'compare' => 'LIKE',
            ),
            array(
                'key'     => 'location',
                'value'   => $state,
                'compare' => 'LIKE',
            ),
        );
    }

    // Price filter (checks propvendor_price, _listing_price, price)
    $price_keys = array('propvendor_price', '_listing_price', 'price');
    if ('' !== $min_price && '' !== $max_price) {
        $price_or = array('relation' => 'OR');
        foreach ($price_keys as $pk) {
            $price_or[] = array(
                'key'     => $pk,
                'value'   => array($min_price, $max_price),
                'compare' => 'BETWEEN',
                'type'    => 'NUMERIC',
            );
        }
        $meta_query[] = $price_or;
    } elseif ('' !== $min_price) {
        $price_or = array('relation' => 'OR');
        foreach ($price_keys as $pk) {
            $price_or[] = array(
                'key'     => $pk,
                'value'   => $min_price,
                'compare' => '>=',
                'type'    => 'NUMERIC',
            );
        }
        $meta_query[] = $price_or;
    } elseif ('' !== $max_price) {
        $price_or = array('relation' => 'OR');
        foreach ($price_keys as $pk) {
            $price_or[] = array(
                'key'     => $pk,
                'value'   => $max_price,
                'compare' => '<=',
                'type'    => 'NUMERIC',
            );
        }
        $meta_query[] = $price_or;
    }

    // Title document filter (flexible matching for C of O / Certificate of Occupancy across keys)
    if (!empty($title_doc)) {
        $doc_keys = array('propvendor_title_document', '_listing_title_document', 'title_document');
        $doc_or   = array('relation' => 'OR');
        if (0 === strcasecmp($title_doc, 'C of O') || stripos($title_doc, 'Certificate of Occupancy') !== false) {
            foreach ($doc_keys as $dk) {
                $doc_or[] = array(
                    'key'     => $dk,
                    'value'   => 'Certificate of Occupancy (C of O)',
                    'compare' => '=',
                );
                $doc_or[] = array(
                    'key'     => $dk,
                    'value'   => 'C of O',
                    'compare' => 'LIKE',
                );
                $doc_or[] = array(
                    'key'     => $dk,
                    'value'   => 'Certificate of Occupancy',
                    'compare' => 'LIKE',
                );
            }
        } else {
            foreach ($doc_keys as $dk) {
                $doc_or[] = array(
                    'key'     => $dk,
                    'value'   => $title_doc,
                    'compare' => 'LIKE',
                );
            }
        }
        $meta_query[] = $doc_or;
    }

    // Property-specific filters
    if (!empty($property_subtype)) {
        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key'     => 'propvendor_property_subtype',
                'value'   => $property_subtype,
                'compare' => 'LIKE',
            ),
            array(
                'key'     => '_listing_property_subtype',
                'value'   => $property_subtype,
                'compare' => 'LIKE',
            ),
        );
    }
    if ('' !== $min_bedrooms && $min_bedrooms > 0) {
        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key'     => 'propvendor_bedrooms',
                'value'   => $min_bedrooms,
                'compare' => '>=',
                'type'    => 'NUMERIC',
            ),
            array(
                'key'     => '_listing_bedrooms',
                'value'   => $min_bedrooms,
                'compare' => '>=',
                'type'    => 'NUMERIC',
            ),
        );
    }
    if ('' !== $min_bathrooms && $min_bathrooms > 0) {
        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key'     => 'propvendor_bathrooms',
                'value'   => $min_bathrooms,
                'compare' => '>=',
                'type'    => 'NUMERIC',
            ),
            array(
                'key'     => '_listing_bathrooms',
                'value'   => $min_bathrooms,
                'compare' => '>=',
                'type'    => 'NUMERIC',
            ),
        );
    }
    if (!empty($furnishing)) {
        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key'     => 'propvendor_furnishing',
                'value'   => $furnishing,
                'compare' => 'LIKE',
            ),
            array(
                'key'     => '_listing_furnishing',
                'value'   => $furnishing,
                'compare' => 'LIKE',
            ),
        );
    }

    // Land-specific filters
    if (!empty($land_use)) {
        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key'     => 'propvendor_land_use',
                'value'   => $land_use,
                'compare' => 'LIKE',
            ),
            array(
                'key'     => '_listing_land_use',
                'value'   => $land_use,
                'compare' => 'LIKE',
            ),
        );
    }
    if ('' !== $min_plot_size && '' !== $max_plot_size) {
        $meta_query[] = array(
            'key'     => 'propvendor_plot_size',
            'value'   => array($min_plot_size, $max_plot_size),
            'compare' => 'BETWEEN',
            'type'    => 'NUMERIC',
        );
    } elseif ('' !== $min_plot_size) {
        $meta_query[] = array(
            'key'     => 'propvendor_plot_size',
            'value'   => $min_plot_size,
            'compare' => '>=',
            'type'    => 'NUMERIC',
        );
    } elseif ('' !== $max_plot_size) {
        $meta_query[] = array(
            'key'     => 'propvendor_plot_size',
            'value'   => $max_plot_size,
            'compare' => '<=',
            'type'    => 'NUMERIC',
        );
    }
    if (!empty($plot_unit)) {
        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key'     => 'propvendor_plot_size_unit',
                'value'   => $plot_unit,
                'compare' => '=',
            ),
            array(
                'key'     => 'propvendor_plot_size_unit',
                'value'   => propvendor_marketplace_get_plot_size_unit_label($plot_unit),
                'compare' => '=',
            ),
            array(
                'key'     => '_listing_plot_size_unit',
                'value'   => $plot_unit,
                'compare' => '=',
            ),
        );
    }

    // Vehicle-specific filters
    if (!empty($vehicle_type)) {
        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key'     => 'propvendor_vehicle_subtype',
                'value'   => $vehicle_type,
                'compare' => 'LIKE',
            ),
            array(
                'key'     => '_listing_vehicle_subtype',
                'value'   => $vehicle_type,
                'compare' => 'LIKE',
            ),
        );
    }
    if (!empty($make)) {
        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key'     => 'propvendor_vehicle_make',
                'value'   => $make,
                'compare' => 'LIKE',
            ),
            array(
                'key'     => '_listing_make',
                'value'   => $make,
                'compare' => 'LIKE',
            ),
        );
    }
    if (!empty($model)) {
        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key'     => 'propvendor_vehicle_model',
                'value'   => $model,
                'compare' => 'LIKE',
            ),
            array(
                'key'     => '_listing_model',
                'value'   => $model,
                'compare' => 'LIKE',
            ),
        );
    }
    if ('' !== $min_year && '' !== $max_year) {
        $meta_query[] = array(
            'key'     => 'propvendor_vehicle_year',
            'value'   => array($min_year, $max_year),
            'compare' => 'BETWEEN',
            'type'    => 'NUMERIC',
        );
    } elseif ('' !== $min_year) {
        $meta_query[] = array(
            'key'     => 'propvendor_vehicle_year',
            'value'   => $min_year,
            'compare' => '>=',
            'type'    => 'NUMERIC',
        );
    } elseif ('' !== $max_year) {
        $meta_query[] = array(
            'key'     => 'propvendor_vehicle_year',
            'value'   => $max_year,
            'compare' => '<=',
            'type'    => 'NUMERIC',
        );
    }
    if (!empty($condition)) {
        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key'     => 'propvendor_condition',
                'value'   => $condition,
                'compare' => 'LIKE',
            ),
            array(
                'key'     => '_listing_condition',
                'value'   => $condition,
                'compare' => 'LIKE',
            ),
        );
    }
    if (!empty($transmission)) {
        $meta_query[] = array(
            'relation' => 'OR',
            array(
                'key'     => 'propvendor_transmission',
                'value'   => $transmission,
                'compare' => 'LIKE',
            ),
            array(
                'key'     => '_listing_transmission',
                'value'   => $transmission,
                'compare' => 'LIKE',
            ),
        );
    }

    // Construct query_args
    $query_args = array(
        'post_type'      => !empty($listing_type) ? $listing_type : $allowed_types,
        'post_status'    => 'publish',
        'posts_per_page' => $posts_per_page,
        'paged'          => $paged,
        'meta_query'     => $meta_query,
    );

    // Sorting
    switch ($sort) {
        case 'date_asc':
            $query_args['orderby'] = 'date';
            $query_args['order']   = 'ASC';
            break;
        case 'price_asc':
            $query_args['meta_key'] = 'propvendor_price';
            $query_args['orderby']  = 'meta_value_num';
            $query_args['order']    = 'ASC';
            break;
        case 'price_desc':
            $query_args['meta_key'] = 'propvendor_price';
            $query_args['orderby']  = 'meta_value_num';
            $query_args['order']    = 'DESC';
            break;
        case 'date_desc':
        default:
            $query_args['orderby'] = 'date';
            $query_args['order']   = 'DESC';
            break;
    }

    // Keyword Search
    if (!empty($keyword)) {
        $query_args['s'] = $keyword;
        $query_args['propvendor_keyword_search'] = $keyword;
    }

    // Category taxonomy
    if (!empty($category_id)) {
        $query_args['tax_query'] = array(
            array(
                'taxonomy' => 'listing_category',
                'field'    => 'term_id',
                'terms'    => $category_id,
            ),
        );
    }

    return $query_args;
}

/**
 * Build and execute search query with keyword filter.
 *
 * @param array $params Optional GET or explicit parameters.
 * @return WP_Query
 */
function propvendor_marketplace_build_search_query($params = array()) {
    $query_args = propvendor_marketplace_get_search_query_args($params);
    add_filter('posts_search', 'propvendor_marketplace_filter_keyword_search', 10, 2);
    try {
        $query = new WP_Query($query_args);
    } finally {
        remove_filter('posts_search', 'propvendor_marketplace_filter_keyword_search', 10);
    }
    return $query;
}

/**
 * Render Stage 4 Search & Filter shortcode / component.
 *
 * @param array $atts
 * @return string HTML
 */
function propvendor_marketplace_render_search_filters($atts = array()) {
    $atts = shortcode_atts(
        array(
            'action_url' => '',
            'layout'     => 'horizontal',
            'show_title' => 'false',
            'title'      => 'Search & Filter Listings',
        ),
        $atts,
        'propvendor_search_filters'
    );

    $action_url = !empty($atts['action_url']) ? $atts['action_url'] : '';
    if (empty($action_url)) {
        if (is_page()) {
            $action_url = get_permalink();
        } else {
            $action_url = home_url('/browse-listings/');
        }
    }

    // Extract current GET params to persist in form
    $p_keyword       = isset($_GET['pv_keyword']) ? sanitize_text_field(wp_unslash($_GET['pv_keyword'])) : '';
    $p_type          = isset($_GET['pv_type']) ? sanitize_key(wp_unslash($_GET['pv_type'])) : '';
    $p_state         = isset($_GET['pv_state']) ? sanitize_text_field(wp_unslash($_GET['pv_state'])) : (isset($_GET['pv_location']) ? sanitize_text_field(wp_unslash($_GET['pv_location'])) : '');
    $p_state_norm    = propvendor_marketplace_normalize_nigerian_state($p_state);
    $p_min_price     = isset($_GET['pv_min_price']) ? sanitize_text_field(wp_unslash($_GET['pv_min_price'])) : '';
    $p_max_price     = isset($_GET['pv_max_price']) ? sanitize_text_field(wp_unslash($_GET['pv_max_price'])) : '';
    $p_title_doc     = isset($_GET['pv_title_doc']) ? sanitize_text_field(wp_unslash($_GET['pv_title_doc'])) : '';
    $p_prop_subtype  = isset($_GET['pv_property_subtype']) ? sanitize_text_field(wp_unslash($_GET['pv_property_subtype'])) : '';
    $p_min_beds      = isset($_GET['pv_min_bedrooms']) ? sanitize_text_field(wp_unslash($_GET['pv_min_bedrooms'])) : '';
    $p_min_baths     = isset($_GET['pv_min_bathrooms']) ? sanitize_text_field(wp_unslash($_GET['pv_min_bathrooms'])) : '';
    $p_furnishing    = isset($_GET['pv_furnishing']) ? sanitize_text_field(wp_unslash($_GET['pv_furnishing'])) : '';
    $p_land_use      = isset($_GET['pv_land_use']) ? sanitize_text_field(wp_unslash($_GET['pv_land_use'])) : '';
    $p_min_plot_size = isset($_GET['pv_min_plot_size']) ? sanitize_text_field(wp_unslash($_GET['pv_min_plot_size'])) : '';
    $p_max_plot_size = isset($_GET['pv_max_plot_size']) ? sanitize_text_field(wp_unslash($_GET['pv_max_plot_size'])) : '';
    $p_plot_unit     = isset($_GET['pv_plot_unit']) ? sanitize_text_field(wp_unslash($_GET['pv_plot_unit'])) : '';
    $p_veh_type      = isset($_GET['pv_vehicle_type']) ? sanitize_text_field(wp_unslash($_GET['pv_vehicle_type'])) : '';
    $p_make          = isset($_GET['pv_make']) ? sanitize_text_field(wp_unslash($_GET['pv_make'])) : '';
    $p_model         = isset($_GET['pv_model']) ? sanitize_text_field(wp_unslash($_GET['pv_model'])) : '';
    $p_min_year      = isset($_GET['pv_min_year']) ? sanitize_text_field(wp_unslash($_GET['pv_min_year'])) : '';
    $p_max_year      = isset($_GET['pv_max_year']) ? sanitize_text_field(wp_unslash($_GET['pv_max_year'])) : '';
    $p_condition     = isset($_GET['pv_condition']) ? sanitize_text_field(wp_unslash($_GET['pv_condition'])) : '';
    $p_transmission  = isset($_GET['pv_transmission']) ? sanitize_text_field(wp_unslash($_GET['pv_transmission'])) : '';
    $p_sort          = isset($_GET['pv_sort']) ? sanitize_key(wp_unslash($_GET['pv_sort'])) : 'date_desc';

    // Normalize category alias if user passed 'property', 'land', 'vehicle'
    if ('property' === $p_type) $p_type = 'property_listing';
    if ('land' === $p_type)     $p_type = 'land_listing';
    if ('vehicle' === $p_type)  $p_type = 'vehicle_listing';

    // Determine initial visibility
    $is_prop = ('property_listing' === $p_type);
    $is_land = ('land_listing' === $p_type);
    $is_veh  = ('vehicle_listing' === $p_type);

    $output  = '<div class="propvendor-search-card layout-' . esc_attr($atts['layout']) . '">';
    if ('true' === $atts['show_title']) {
        $output .= '<h3 class="propvendor-search-title">' . esc_html($atts['title']) . '</h3>';
    }

    $output .= '<form class="propvendor-search-form" method="get" action="' . esc_url($action_url) . '">';

    // Row 1: General Search Controls
    $output .= '<div class="propvendor-filter-row propvendor-filter-row-primary">';

    // Keyword
    $output .= '<div class="propvendor-filter-group propvendor-filter-keyword">';
    $output .= '<label for="pv_keyword">Keyword</label>';
    $output .= '<input type="text" id="pv_keyword" name="pv_keyword" value="' . esc_attr($p_keyword) . '" placeholder="Search titles, description, or address..." maxlength="150" />';
    $output .= '</div>';

    // Category / Listing Type
    $output .= '<div class="propvendor-filter-group propvendor-filter-type">';
    $output .= '<label for="pv_type">Category</label>';
    $output .= '<select id="pv_type" name="pv_type" class="propvendor-search-type-select">';
    $output .= '<option value="">All Categories</option>';
    $output .= '<option value="property_listing"' . selected($p_type, 'property_listing', false) . '>Property</option>';
    $output .= '<option value="land_listing"' . selected($p_type, 'land_listing', false) . '>Land</option>';
    $output .= '<option value="vehicle_listing"' . selected($p_type, 'vehicle_listing', false) . '>Vehicle</option>';
    $output .= '</select>';
    $output .= '</div>';

    // State
    $output .= '<div class="propvendor-filter-group propvendor-filter-state">';
    $output .= '<label for="pv_state">State</label>';
    $output .= '<select id="pv_state" name="pv_state">';
    $output .= '<option value="">All States</option>';
    foreach (propvendor_marketplace_get_nigerian_states() as $st) {
        $output .= '<option value="' . esc_attr($st) . '"' . selected($p_state_norm, $st, false) . '>' . esc_html($st) . '</option>';
    }
    $output .= '</select>';
    $output .= '</div>';

    // Price Range
    $output .= '<div class="propvendor-filter-group propvendor-filter-price">';
    $output .= '<label>Price Range (₦)</label>';
    $output .= '<div class="propvendor-price-inputs">';
    $output .= '<input type="number" id="pv_min_price" name="pv_min_price" value="' . esc_attr($p_min_price) . '" placeholder="Min Price (₦)" min="0" step="1000" />';
    $output .= '<span class="propvendor-price-sep">-</span>';
    $output .= '<input type="number" id="pv_max_price" name="pv_max_price" value="' . esc_attr($p_max_price) . '" placeholder="Max Price (₦)" min="0" step="1000" />';
    $output .= '</div>';
    $output .= '</div>';

    $output .= '</div>'; // End Row 1

    // Row 2: Category-Specific & Title Document Filters
    $output .= '<div class="propvendor-filter-category-wrap">';

    // Title Document Filter (Property & Land)
    $title_docs = array(
        'Certificate of Occupancy (C of O)',
        "Governor's Consent",
        'Gazette',
        'Excision',
        'Deed of Assignment',
        'Survey Plan',
        'Court Judgment',
        'Registered Conveyance',
        'Other / In Process',
    );
    $td_style = ($is_veh) ? ' style="display:none;"' : '';
    $output .= '<div class="propvendor-filter-group propvendor-filter-title-doc"' . $td_style . '>';
    $output .= '<label for="pv_title_doc">Title Document</label>';
    $output .= '<select id="pv_title_doc" name="pv_title_doc">';
    $output .= '<option value="">All Title Documents</option>';
    foreach ($title_docs as $td) {
        $td_selected = (0 === strcasecmp($p_title_doc, $td) || ($p_title_doc === 'C of O' && strpos($td, 'C of O') !== false));
        $output .= '<option value="' . esc_attr($td) . '"' . ($td_selected ? ' selected="selected"' : '') . '>' . esc_html($td) . '</option>';
    }
    $output .= '</select>';
    $output .= '</div>';

    // Property-Specific Fields
    $prop_subtypes = array(
        'Flat / Apartment',
        'Detached Duplex',
        'Semi-Detached Duplex',
        'Terraced House',
        'Bungalow',
        'Mansion',
        'Commercial Property',
        'Office Space',
        'Shop / Retail',
        'Warehouse',
        'Shortlet / Serviced Apartment',
        'Other',
    );
    $prop_style = ($is_prop || empty($p_type)) ? '' : ' style="display:none;"';
    $output .= '<div class="propvendor-filter-row propvendor-filter-property-row"' . $prop_style . '>';

    // Property Subtype
    $output .= '<div class="propvendor-filter-group">';
    $output .= '<label for="pv_property_subtype">Property Subtype</label>';
    $output .= '<select id="pv_property_subtype" name="pv_property_subtype">';
    $output .= '<option value="">All Property Types</option>';
    foreach ($prop_subtypes as $stype) {
        $output .= '<option value="' . esc_attr($stype) . '"' . selected($p_prop_subtype, $stype, false) . '>' . esc_html($stype) . '</option>';
    }
    $output .= '</select>';
    $output .= '</div>';

    // Min Bedrooms
    $output .= '<div class="propvendor-filter-group">';
    $output .= '<label for="pv_min_bedrooms">Min Bedrooms</label>';
    $output .= '<select id="pv_min_bedrooms" name="pv_min_bedrooms">';
    $output .= '<option value="">Any Bedrooms</option>';
    for ($i = 1; $i <= 6; $i++) {
        $val_str = (string) $i;
        $output .= '<option value="' . $val_str . '"' . selected((string)$p_min_beds, $val_str, false) . '>' . $i . '+ Beds</option>';
    }
    $output .= '</select>';
    $output .= '</div>';

    // Min Bathrooms
    $output .= '<div class="propvendor-filter-group">';
    $output .= '<label for="pv_min_bathrooms">Min Bathrooms</label>';
    $output .= '<select id="pv_min_bathrooms" name="pv_min_bathrooms">';
    $output .= '<option value="">Any Bathrooms</option>';
    for ($i = 1; $i <= 5; $i++) {
        $val_str = (string) $i;
        $output .= '<option value="' . $val_str . '"' . selected((string)$p_min_baths, $val_str, false) . '>' . $i . '+ Baths</option>';
    }
    $output .= '</select>';
    $output .= '</div>';

    // Furnishing
    $furnishings = array('Furnished', 'Semi-Furnished', 'Unfurnished');
    $output .= '<div class="propvendor-filter-group">';
    $output .= '<label for="pv_furnishing">Furnishing</label>';
    $output .= '<select id="pv_furnishing" name="pv_furnishing">';
    $output .= '<option value="">All Furnishing</option>';
    foreach ($furnishings as $f) {
        $output .= '<option value="' . esc_attr($f) . '"' . selected($p_furnishing, $f, false) . '>' . esc_html($f) . '</option>';
    }
    $output .= '</select>';
    $output .= '</div>';

    $output .= '</div>'; // End Property Row

    // Land-Specific Fields
    $land_uses   = propvendor_marketplace_get_land_uses();
    $land_style  = ($is_land || empty($p_type)) ? '' : ' style="display:none;"';
    $output .= '<div class="propvendor-filter-row propvendor-filter-land-row"' . $land_style . '>';

    // Land Use
    $output .= '<div class="propvendor-filter-group">';
    $output .= '<label for="pv_land_use">Land Use</label>';
    $output .= '<select id="pv_land_use" name="pv_land_use">';
    $output .= '<option value="">All Land Uses</option>';
    foreach ($land_uses as $lu) {
        $output .= '<option value="' . esc_attr($lu) . '"' . selected($p_land_use, $lu, false) . '>' . esc_html($lu) . '</option>';
    }
    $output .= '</select>';
    $output .= '</div>';

    // Plot Size Range
    $output .= '<div class="propvendor-filter-group">';
    $output .= '<label>Plot Size Range</label>';
    $output .= '<div class="propvendor-price-inputs">';
    $output .= '<input type="number" id="pv_min_plot_size" name="pv_min_plot_size" value="' . esc_attr($p_min_plot_size) . '" placeholder="Min Size" min="0" step="any" />';
    $output .= '<span class="propvendor-price-sep">-</span>';
    $output .= '<input type="number" id="pv_max_plot_size" name="pv_max_plot_size" value="' . esc_attr($p_max_plot_size) . '" placeholder="Max Size" min="0" step="any" />';
    $output .= '</div>';
    $output .= '</div>';

    // Plot Unit
    $plot_units       = propvendor_marketplace_get_plot_size_units();
    $p_plot_unit_norm = propvendor_marketplace_normalize_plot_size_unit($p_plot_unit);
    $output .= '<div class="propvendor-filter-group">';
    $output .= '<label for="pv_plot_unit">Plot Unit</label>';
    $output .= '<select id="pv_plot_unit" name="pv_plot_unit">';
    $output .= '<option value="">All Units</option>';
    foreach ($plot_units as $ukey => $ulabel) {
        $output .= '<option value="' . esc_attr($ukey) . '"' . selected($p_plot_unit_norm, $ukey, false) . '>' . esc_html($ulabel) . '</option>';
    }
    $output .= '</select>';
    $output .= '</div>';

    $output .= '</div>'; // End Land Row

    // Vehicle-Specific Fields
    $veh_subtypes = propvendor_marketplace_get_vehicle_subtypes();
    $veh_style    = ($is_veh) ? '' : ' style="display:none;"';
    $output .= '<div class="propvendor-filter-row propvendor-filter-vehicle-row"' . $veh_style . '>';

    // Vehicle Type
    $output .= '<div class="propvendor-filter-group">';
    $output .= '<label for="pv_vehicle_type">Vehicle Type</label>';
    $output .= '<select id="pv_vehicle_type" name="pv_vehicle_type">';
    $output .= '<option value="">All Vehicle Types</option>';
    foreach ($veh_subtypes as $vt) {
        $output .= '<option value="' . esc_attr($vt) . '"' . selected($p_veh_type, $vt, false) . '>' . esc_html($vt) . '</option>';
    }
    $output .= '</select>';
    $output .= '</div>';

    // Make
    $output .= '<div class="propvendor-filter-group">';
    $output .= '<label for="pv_make">Make</label>';
    $output .= '<input type="text" id="pv_make" name="pv_make" value="' . esc_attr($p_make) . '" placeholder="e.g. Toyota, Honda, Lexus..." />';
    $output .= '</div>';

    // Model
    $output .= '<div class="propvendor-filter-group">';
    $output .= '<label for="pv_model">Model</label>';
    $output .= '<input type="text" id="pv_model" name="pv_model" value="' . esc_attr($p_model) . '" placeholder="e.g. Camry, Corolla, RX 350..." />';
    $output .= '</div>';

    // Year Range
    $output .= '<div class="propvendor-filter-group">';
    $output .= '<label>Year Range</label>';
    $output .= '<div class="propvendor-price-inputs">';
    $output .= '<input type="number" id="pv_min_year" name="pv_min_year" value="' . esc_attr($p_min_year) . '" placeholder="Min Year" min="1970" max="2035" />';
    $output .= '<span class="propvendor-price-sep">-</span>';
    $output .= '<input type="number" id="pv_max_year" name="pv_max_year" value="' . esc_attr($p_max_year) . '" placeholder="Max Year" min="1970" max="2035" />';
    $output .= '</div>';
    $output .= '</div>';

    // Condition
    $conditions = array('Foreign Used (Tokunbo)', 'Nigerian Used', 'Brand New');
    $output .= '<div class="propvendor-filter-group">';
    $output .= '<label for="pv_condition">Condition</label>';
    $output .= '<select id="pv_condition" name="pv_condition">';
    $output .= '<option value="">All Conditions</option>';
    foreach ($conditions as $c) {
        $output .= '<option value="' . esc_attr($c) . '"' . selected($p_condition, $c, false) . '>' . esc_html($c) . '</option>';
    }
    $output .= '</select>';
    $output .= '</div>';

    // Transmission
    $transmissions = array('Automatic', 'Manual');
    $output .= '<div class="propvendor-filter-group">';
    $output .= '<label for="pv_transmission">Transmission</label>';
    $output .= '<select id="pv_transmission" name="pv_transmission">';
    $output .= '<option value="">All Transmissions</option>';
    foreach ($transmissions as $t) {
        $output .= '<option value="' . esc_attr($t) . '"' . selected($p_transmission, $t, false) . '>' . esc_html($t) . '</option>';
    }
    $output .= '</select>';
    $output .= '</div>';

    $output .= '</div>'; // End Vehicle Row

    $output .= '</div>'; // End Category Wrap

    // Row 3: Sorting & Action Buttons
    $output .= '<div class="propvendor-filter-row propvendor-sort-bar">';

    // Sort By
    $output .= '<div class="propvendor-filter-group propvendor-filter-sort">';
    $output .= '<label for="pv_sort">Sort By</label>';
    $output .= '<select id="pv_sort" name="pv_sort">';
    $output .= '<option value="date_desc"' . selected($p_sort, 'date_desc', false) . '>Newest First</option>';
    $output .= '<option value="date_asc"' . selected($p_sort, 'date_asc', false) . '>Oldest First</option>';
    $output .= '<option value="price_asc"' . selected($p_sort, 'price_asc', false) . '>Price: Low to High</option>';
    $output .= '<option value="price_desc"' . selected($p_sort, 'price_desc', false) . '>Price: High to Low</option>';
    $output .= '</select>';
    $output .= '</div>';

    // Action Buttons
    $reset_url = esc_url(remove_query_arg(array(
        'pv_keyword', 'pv_type', 'pv_state', 'pv_location', 'pv_min_price', 'pv_max_price',
        'pv_title_doc', 'pv_property_subtype', 'pv_min_bedrooms', 'pv_min_bathrooms',
        'pv_furnishing', 'pv_land_use', 'pv_min_plot_size', 'pv_max_plot_size',
        'pv_plot_unit', 'pv_vehicle_type', 'pv_make', 'pv_model', 'pv_min_year',
        'pv_max_year', 'pv_condition', 'pv_transmission', 'pv_sort', 'paged',
    ), $action_url));

    $output .= '<div class="propvendor-filter-actions">';
    $output .= '<button type="submit" class="propvendor-btn-search">Search Listings</button>';
    $output .= '<a href="' . $reset_url . '" class="propvendor-btn-reset">Reset Filters</a>';
    $output .= '</div>';

    $output .= '</div>'; // End Row 3

    $output .= '</form>';
    $output .= '</div>'; // End Card

    return $output;
}

/**
 * Shortcode handler for [propvendor_search_filters].
 *
 * @param array $atts
 * @return string HTML
 */
function propvendor_marketplace_search_filters_shortcode($atts = array()) {
    return propvendor_marketplace_render_search_filters($atts);
}
add_shortcode('propvendor_search_filters', 'propvendor_marketplace_search_filters_shortcode');

function propvendor_marketplace_browse_shortcode() {
    $query = propvendor_marketplace_build_search_query($_GET);

    $output  = '<section class="propvendor-browse" aria-label="' . esc_attr__('Browse Listings', 'propvendor-marketplace') . '">';
    $output .= propvendor_marketplace_render_search_filters(array('action_url' => get_permalink()));

    $found_count = (int) $query->found_posts;
    $output .= '<p class="propvendor-results-count" role="status">' . esc_html(sprintf(_n('%s matching listing', '%s matching listings', $found_count, 'propvendor-marketplace'), number_format_i18n($found_count))) . '</p>';

    if ($query->have_posts()) {
        $output .= propvendor_marketplace_render_listing_cards($query->posts, '');
    } else {
        $output .= '<div class="propvendor-no-results"><p class="propvendor-empty">No listings match your search criteria. Try adjusting your filters or resetting to view all available listings.</p></div>';
    }

    if ($query->max_num_pages > 1) {
        $pagination_args = $_GET;
        unset($pagination_args['paged']);
        $output .= '<nav class="propvendor-pagination" aria-label="Listing pages">';
        $output .= paginate_links(
            array(
                'base'      => add_query_arg('paged', '%#%', get_permalink()),
                'format'    => '',
                'current'   => max(1, absint(get_query_var('paged', 1)), isset($_GET['paged']) ? absint($_GET['paged']) : 1),
                'total'     => (int) $query->max_num_pages,
                'add_args'  => $pagination_args,
                'type'      => 'list',
                'prev_text' => '&laquo; Previous',
                'next_text' => 'Next &raquo;',
            )
        );
        $output .= '</nav>';
    }

    wp_reset_postdata();
    // Phase 5: Accessible Confirmation Modal for Lead Contact Unlock
    $output .= '
    <div id="pv-unlock-lead-modal" class="pv-unlock-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="pv-unlock-modal-title">
        <div class="pv-unlock-modal-backdrop"></div>
        <div class="pv-unlock-modal-dialog">
            <div class="pv-unlock-modal-header">
                <h4 id="pv-unlock-modal-title"><span class="dashicons dashicons-unlock"></span> ' . esc_html__('Confirm Lead Contact Unlock', 'propvendor-marketplace') . '</h4>
                <button type="button" class="pv-unlock-modal-close" aria-label="' . esc_attr__('Close dialog', 'propvendor-marketplace') . '">&times;</button>
            </div>
            <div class="pv-unlock-modal-body">
                <p>' . esc_html__('Are you sure you want to unlock this customer\'s direct contact details?', 'propvendor-marketplace') . '</p>
                <div class="pv-unlock-modal-details">
                    <div><strong>' . esc_html__('Cost:', 'propvendor-marketplace') . '</strong> 1 Credit</div>
                    <div><strong>' . esc_html__('Your Balance:', 'propvendor-marketplace') . '</strong> <span id="pv-modal-current-balance">0</span> Credits</div>
                    <div><strong>' . esc_html__('Balance After Unlock:', 'propvendor-marketplace') . '</strong> <span id="pv-modal-balance-after">0</span> Credits</div>
                </div>
                <p class="pv-unlock-modal-subtext">' . esc_html__('1 credit will be deducted from your wallet upon confirmation. Customer phone number and email will be revealed immediately.', 'propvendor-marketplace') . '</p>
                <div id="pv-unlock-modal-error" class="pv-unlock-modal-error" style="display: none;"></div>
            </div>
            <div class="pv-unlock-modal-footer">
                <button type="button" id="pv-btn-confirm-unlock" class="button button-primary">' . esc_html__('Confirm & Deduct 1 Credit', 'propvendor-marketplace') . '</button>
                <button type="button" id="pv-btn-cancel-unlock" class="button button-secondary">' . esc_html__('Cancel', 'propvendor-marketplace') . '</button>
            </div>
        </div>
    </div>';

    $output .= '</section>';
    return $output;
}
add_shortcode('propvendor_browse_listings', 'propvendor_marketplace_browse_shortcode');

function propvendor_marketplace_get_notice_message() {
    $notice = isset($_GET['propvendor_notice']) ? sanitize_key($_GET['propvendor_notice']) : '';
    $message = isset($_GET['propvendor_message']) ? sanitize_text_field(wp_unslash($_GET['propvendor_message'])) : '';

    if (empty($notice) || empty($message)) {
        return '';
    }

    return '<div class="propvendor-alert propvendor-alert-' . esc_attr($notice) . '">' . esc_html($message) . '</div>';
}

function propvendor_marketplace_redirect_with_notice($path, $notice, $message) {
    $target = (0 === strpos($path, 'http://') || 0 === strpos($path, 'https://')) ? $path : home_url($path);
    $redirect = add_query_arg(
        array(
            'propvendor_notice' => sanitize_key($notice),
            'propvendor_message' => rawurlencode($message),
        ),
        $target
    );

    wp_safe_redirect($redirect);
    exit;
}

function propvendor_marketplace_submit_listing_form_shortcode() {
    if (!is_user_logged_in()) {
        return '<div class="propvendor-form-box"><h3>Submit a listing</h3><p>Please <a href="' . esc_url(wp_login_url(home_url('/submit-a-listing/'))) . '">log in</a> to submit a listing as a vendor.</p></div>';
    }

    if (!current_user_can('manage_options') && !current_user_can('propvendor_submit_marketplace_listings')) {
        return '<div class="propvendor-form-box"><h3>Submit a listing</h3><p>Your account does not yet have vendor listing permissions. Please complete vendor registration or contact an administrator.</p></div>';
    }

    $notice = propvendor_marketplace_get_notice_message();
    $listing = null;
    $edit_id = 0;
    foreach (array('propvendor_edit_listing', 'edit_listing', 'listing_id', 'edit') as $param) {
        if (isset($_GET[$param]) && absint($_GET[$param]) > 0) {
            $edit_id = absint($_GET[$param]);
            break;
        }
    }
    if ($edit_id) {
        $listing = get_post($edit_id);
        if ($listing && (int) $listing->post_author !== get_current_user_id() && !current_user_can('manage_options')) {
            $listing = null;
        }
    }

    $current_user_id = get_current_user_id();

    // Stage 3 Quota Check:
    $free_limit = propvendor_marketplace_get_free_listing_limit();
    if (!$listing && !propvendor_marketplace_vendor_can_create_listing($current_user_id, 0)) {
        $active_count = propvendor_marketplace_get_vendor_active_listing_count($current_user_id);
        $dash_url = home_url('/vendor-dashboard/');
        return '<div class="propvendor-form-box propvendor-quota-exceeded-box">'
            . '<h3>Listing Quota Reached</h3>'
            . '<p>Free vendor accounts are limited to a maximum of <strong>' . absint($free_limit) . ' active listings</strong> across Property, Land, and Vehicle categories.</p>'
            . '<p>You currently have <strong>' . absint($active_count) . ' of ' . absint($free_limit) . '</strong> active listings in your account (published or awaiting review).</p>'
            . '<p>To publish a new listing, remove or mark an existing listing as sold or unavailable from your <a href="' . esc_url($dash_url) . '">Vendor Dashboard</a>.</p>'
            . '<p class="propvendor-field-hint" style="margin-top: 15px;">To list unlimited properties, lands, and vehicles without quota restrictions, contact our marketplace administrators to upgrade your account to a VIP plan.</p>'
            . '<div style="margin-top: 20px;"><a href="' . esc_url($dash_url) . '" class="button button-primary" style="display:inline-block; padding:10px 20px; background:#2563eb; color:#fff; text-decoration:none; border-radius:4px; font-weight:600;">Return to Vendor Dashboard</a></div>'
            . '</div>';
    }

    $listing_owner_id = $listing ? (int) $listing->post_author : $current_user_id;
    if ($listing && !propvendor_marketplace_vendor_can_create_listing($listing_owner_id, $listing->ID, $current_user_id)) {
        $active_count = propvendor_marketplace_get_vendor_active_listing_count($listing_owner_id, $listing->ID);
        $notice .= '<div class="propvendor-quota-alert propvendor-quota-alert-warning" style="margin-bottom: 20px;">'
            . sprintf(
                esc_html__('Notice: This listing is currently inactive. Updating it will submit it for review and attempt to return it to your active listing pool, but your account is at or over the free active listing limit (%d of %d active). To reactivate this listing upon submission, first remove or mark another listing as sold or unavailable, or contact an administrator to upgrade to a VIP plan.', 'propvendor-marketplace'),
                $active_count,
                $free_limit
            )
            . '</div>';
    }

    $form_title = $listing ? 'Edit listing' : 'Submit a listing';
    $action = $listing ? 'propvendor_update_listing' : 'propvendor_submit_listing';
    $hidden_id = $listing ? '<input type="hidden" name="propvendor_listing_id" value="' . esc_attr((string) $listing->ID) . '" />' : '';
    $type_value = $listing ? get_post_type($listing) : 'property_listing';
    $title_value = $listing ? (!empty($listing->post_title) ? $listing->post_title : get_the_title($listing->ID)) : '';
    $price_value = $listing ? get_post_meta($listing->ID, 'propvendor_price', true) : '';
    $location_value = $listing ? get_post_meta($listing->ID, 'propvendor_location', true) : '';
    $full_address_value = $listing ? get_post_meta($listing->ID, 'propvendor_full_address', true) : '';
    $selected_state = propvendor_marketplace_normalize_nigerian_state($location_value);
    $has_unmatched_legacy_location = ($listing && !empty($location_value) && empty($selected_state));
    $description_value = $listing ? $listing->post_content : '';
    $is_direct_owner_value = $listing ? get_post_meta($listing->ID, '_propvendor_is_direct_owner', true) : '0';

    // Property fields
    $property_subtype_val = $listing ? get_post_meta($listing->ID, 'propvendor_property_subtype', true) : '';
    $purpose_val          = $listing ? get_post_meta($listing->ID, 'propvendor_purpose', true) : 'Sale';
    $bedrooms_val         = $listing ? get_post_meta($listing->ID, 'propvendor_bedrooms', true) : '';
    $bathrooms_val        = $listing ? get_post_meta($listing->ID, 'propvendor_bathrooms', true) : '';
    $furnishing_val       = $listing ? get_post_meta($listing->ID, 'propvendor_furnishing', true) : '';
    $prop_title_doc_val   = $listing ? get_post_meta($listing->ID, 'propvendor_title_document', true) : '';
    $floor_area_val       = $listing ? get_post_meta($listing->ID, 'propvendor_floor_area', true) : '';
    $floor_area_unit_val  = $listing ? (get_post_meta($listing->ID, 'propvendor_floor_area_unit', true) ?: 'sqm') : 'sqm';
    $prop_plot_size_val   = $listing ? get_post_meta($listing->ID, 'propvendor_plot_size', true) : '';
    $prop_plot_unit_val   = $listing ? (get_post_meta($listing->ID, 'propvendor_plot_size_unit', true) ?: 'sqm') : 'sqm';

    // Land fields
    $land_use_val         = $listing ? get_post_meta($listing->ID, 'propvendor_land_use', true) : '';
    $land_plot_size_val   = $listing ? get_post_meta($listing->ID, 'propvendor_plot_size', true) : '';
    $land_plot_unit_val   = $listing ? (get_post_meta($listing->ID, 'propvendor_plot_size_unit', true) ?: 'plots') : 'plots';
    $land_title_doc_val   = $listing ? get_post_meta($listing->ID, 'propvendor_title_document', true) : '';
    $land_length_val      = $listing ? get_post_meta($listing->ID, 'propvendor_land_length', true) : '';
    $land_width_val       = $listing ? get_post_meta($listing->ID, 'propvendor_land_width', true) : '';
    $land_dim_unit_val    = $listing ? (get_post_meta($listing->ID, 'propvendor_dimension_unit', true) ?: 'm') : 'm';
    $land_frontage_val    = $listing ? get_post_meta($listing->ID, 'propvendor_frontage', true) : '';

    // Vehicle fields
    $veh_subtype_val      = $listing ? get_post_meta($listing->ID, 'propvendor_vehicle_subtype', true) : '';
    $veh_make_val         = $listing ? get_post_meta($listing->ID, 'propvendor_vehicle_make', true) : '';
    $veh_model_val        = $listing ? get_post_meta($listing->ID, 'propvendor_vehicle_model', true) : '';
    $veh_year_val         = $listing ? get_post_meta($listing->ID, 'propvendor_vehicle_year', true) : '';
    $veh_condition_val    = $listing ? get_post_meta($listing->ID, 'propvendor_condition', true) : '';
    $veh_trans_val        = $listing ? get_post_meta($listing->ID, 'propvendor_transmission', true) : '';
    $veh_mileage_val      = $listing ? get_post_meta($listing->ID, 'propvendor_mileage', true) : '';
    $veh_fuel_val         = $listing ? get_post_meta($listing->ID, 'propvendor_fuel_type', true) : '';
    $veh_engine_val       = $listing ? get_post_meta($listing->ID, 'propvendor_engine_size', true) : '';

    // Existing images
    $existing_images = $listing ? propvendor_marketplace_get_listing_image_ids($listing->ID) : array();
    $thumbnail_id = $listing ? absint(get_post_thumbnail_id($listing->ID)) : 0;

    $fields = '<div class="propvendor-form-box"><h3>' . esc_html($form_title) . '</h3>' . $notice . '<form class="propvendor-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
    $fields .= '<input type="hidden" name="action" value="' . esc_attr($action) . '" />';
    $fields .= wp_nonce_field('propvendor_listing_nonce', 'propvendor_listing_nonce', true, false);
    $fields .= $hidden_id;

    // Common fields: Listing Type & Title
    $title_placeholder = 'e.g., Luxury 4 Bedroom Detached Duplex with Swimming Pool';
    if ('land_listing' === $type_value) {
        $title_placeholder = 'e.g., 2 Plots of Dry Commercial Land along Express Road';
    } elseif ('vehicle_listing' === $type_value) {
        $title_placeholder = 'e.g., Clean Tokunbo 2020 Toyota Camry XLE';
    }

    $fields .= '<div class="propvendor-form-grid"><label>Listing Type<select name="propvendor_listing_type">';
    $fields .= '<option value="property_listing" ' . selected($type_value, 'property_listing', false) . '>Property</option>';
    $fields .= '<option value="land_listing" ' . selected($type_value, 'land_listing', false) . '>Land</option>';
    $fields .= '<option value="vehicle_listing" ' . selected($type_value, 'vehicle_listing', false) . '>Vehicle</option>';
    $fields .= '</select></label><label>Listing Title<span class="propvendor-field-hint">Give your listing a clear, descriptive heading</span><input type="text" name="propvendor_title" value="' . esc_attr($title_value) . '" placeholder="' . esc_attr($title_placeholder) . '" required /></label></div>';

    // Common fields: Price & State
    $fields .= '<div class="propvendor-form-grid"><label>Price (NGN)<input type="number" name="propvendor_price" value="' . esc_attr((string) $price_value) . '" min="0" step="1000" placeholder="e.g., 45000000" required /></label>';
    $fields .= '<label for="propvendor_location">State<select name="propvendor_location" id="propvendor_location" required>';
    $fields .= '<option value="">Select State</option>';
    if ($has_unmatched_legacy_location) {
        $fields .= '<option value="" disabled selected>-- Select State (Legacy: ' . esc_html($location_value) . ') --</option>';
    }
    foreach (propvendor_marketplace_get_nigerian_states() as $st) {
        $fields .= '<option value="' . esc_attr($st) . '" ' . selected($selected_state, $st, false) . '>' . esc_html($st) . '</option>';
    }
    $fields .= '</select>';
    if ($has_unmatched_legacy_location) {
        $fields .= '<span class="propvendor-legacy-warning" style="display:block; color:#b7791f; font-size:0.85rem; margin-top:4px;">Legacy location "' . esc_html($location_value) . '" must be updated to a recognized Nigerian State before saving.</span>';
    }
    $fields .= '</label></div>';

    // Common fields: Full Address / Specific Location
    $address_required = ('vehicle_listing' === $type_value) ? '' : 'required';
    $address_label = ('vehicle_listing' === $type_value) ? 'Specific Location / Landmark' : 'Full Address';
    $address_placeholder = ('vehicle_listing' === $type_value) ? 'e.g., Victoria Island, Lagos (or specific showroom/landmark)' : 'e.g., House 14, Admiralty Way, Lekki Phase 1';
    $address_req_star = ('vehicle_listing' === $type_value) ? 'style="display:none;"' : '';
    $fields .= '<div class="propvendor-form-row" style="margin-bottom: 1rem;"><label for="propvendor_full_address" id="propvendor_full_address_label"><span class="propvendor-address-label-text">' . esc_html($address_label) . '</span><span class="propvendor-address-required-mark" style="color:#d9363e;" ' . $address_req_star . '> *</span><span class="propvendor-field-hint">Street, area, estate, or landmark details</span><input type="text" id="propvendor_full_address" name="propvendor_full_address" value="' . esc_attr($full_address_value) . '" maxlength="255" placeholder="' . esc_attr($address_placeholder) . '" ' . $address_required . ' /></label></div>';

    // 1. PROPERTY FIELDS CONTAINER
    $prop_display = ('property_listing' === $type_value) ? 'block' : 'none';
    $prop_disabled = ('property_listing' === $type_value) ? '' : ' disabled';
    $fields .= '<div class="propvendor-category-fields" id="propvendor-fields-property" style="display: ' . $prop_display . ';">';
    $fields .= '<h4>Property Details</h4>';
    $fields .= '<div class="propvendor-form-grid-3">';
    $fields .= '<label>Property Subtype<select name="propvendor_property_subtype"' . $prop_disabled . '><option value="">Select Property Subtype</option>';
    foreach (propvendor_marketplace_get_property_subtypes() as $opt) {
        $fields .= '<option value="' . esc_attr($opt) . '" ' . selected($property_subtype_val, $opt, false) . '>' . esc_html($opt) . '</option>';
    }
    $fields .= '</select></label>';
    $fields .= '<label>Purpose<select name="propvendor_purpose"' . $prop_disabled . '>';
    foreach (propvendor_marketplace_get_property_purposes() as $opt) {
        $fields .= '<option value="' . esc_attr($opt) . '" ' . selected($purpose_val, $opt, false) . '>' . esc_html($opt) . '</option>';
    }
    $fields .= '</select></label>';
    $fields .= '<label>Bedrooms<input type="number" name="propvendor_bedrooms" min="0" value="' . esc_attr((string) $bedrooms_val) . '" placeholder="e.g., 4"' . $prop_disabled . ' /></label>';
    $fields .= '<label>Bathrooms<input type="number" name="propvendor_bathrooms" min="0" value="' . esc_attr((string) $bathrooms_val) . '" placeholder="e.g., 5"' . $prop_disabled . ' /></label>';
    $fields .= '<label>Furnishing<select name="propvendor_furnishing"' . $prop_disabled . '><option value="">Select Furnishing</option>';
    foreach (propvendor_marketplace_get_furnishing_options() as $opt) {
        $fields .= '<option value="' . esc_attr($opt) . '" ' . selected($furnishing_val, $opt, false) . '>' . esc_html($opt) . '</option>';
    }
    $fields .= '</select></label>';
    $fields .= '<label>Title Document<select name="propvendor_title_document"' . $prop_disabled . '><option value="">Select Title Document</option>';
    foreach (propvendor_marketplace_get_title_documents() as $opt) {
        $fields .= '<option value="' . esc_attr($opt) . '" ' . selected($prop_title_doc_val, $opt, false) . '>' . esc_html($opt) . '</option>';
    }
    $fields .= '</select></label>';
    $fields .= '</div>';
    $fields .= '<div class="propvendor-form-grid-3">';
    $fields .= '<label>Floor Area (Interior Space)<span class="propvendor-field-hint">Total indoor living or usable space</span><input type="number" step="any" name="propvendor_floor_area" value="' . esc_attr((string) $floor_area_val) . '" placeholder="e.g., 350"' . $prop_disabled . ' /></label>';
    $fields .= '<label>Floor Area Unit<select name="propvendor_floor_area_unit"' . $prop_disabled . '>';
    foreach (propvendor_marketplace_get_plot_size_units() as $u_key => $u_label) {
        $fields .= '<option value="' . esc_attr($u_key) . '" ' . selected(propvendor_marketplace_normalize_plot_size_unit($floor_area_unit_val), $u_key, false) . '>' . esc_html($u_label) . '</option>';
    }
    $fields .= '</select></label>';
    $fields .= '<label>Plot Size / Compound Size (Optional)<span class="propvendor-field-hint">Total compound or land area the property sits on</span><input type="text" name="propvendor_plot_size" value="' . esc_attr((string) $prop_plot_size_val) . '" placeholder="e.g., 2"' . $prop_disabled . ' /></label>';
    $fields .= '<label>Plot Size Unit<select name="propvendor_plot_size_unit"' . $prop_disabled . '>';
    foreach (propvendor_marketplace_get_plot_size_units() as $u_key => $u_label) {
        $fields .= '<option value="' . esc_attr($u_key) . '" ' . selected(propvendor_marketplace_normalize_plot_size_unit($prop_plot_unit_val), $u_key, false) . '>' . esc_html($u_label) . '</option>';
    }
    $fields .= '</select></label>';
    $fields .= '</div>';
    $fields .= '</div>';

    // 2. LAND FIELDS CONTAINER
    $land_display = ('land_listing' === $type_value) ? 'block' : 'none';
    $land_disabled = ('land_listing' === $type_value) ? '' : ' disabled';
    $land_plot_req = ('land_listing' === $type_value) ? ' required' : '';
    $fields .= '<div class="propvendor-category-fields" id="propvendor-fields-land" style="display: ' . $land_display . ';">';
    $fields .= '<h4>Land Details</h4>';
    $fields .= '<div class="propvendor-form-grid-3">';
    $fields .= '<label>Land Type / Use<select name="propvendor_land_use"' . $land_disabled . '><option value="">Select Land Use</option>';
    foreach (propvendor_marketplace_get_land_uses() as $opt) {
        $fields .= '<option value="' . esc_attr($opt) . '" ' . selected($land_use_val, $opt, false) . '>' . esc_html($opt) . '</option>';
    }
    $fields .= '</select></label>';
    $fields .= '<label>Plot Size<span class="propvendor-field-hint">Total land area</span><input type="text" name="propvendor_plot_size" value="' . esc_attr((string) $land_plot_size_val) . '" placeholder="e.g., 2"' . $land_disabled . $land_plot_req . ' /></label>';
    $fields .= '<label>Plot Size Unit<select name="propvendor_plot_size_unit"' . $land_disabled . '>';
    foreach (propvendor_marketplace_get_plot_size_units() as $u_key => $u_label) {
        $fields .= '<option value="' . esc_attr($u_key) . '" ' . selected(propvendor_marketplace_normalize_plot_size_unit($land_plot_unit_val), $u_key, false) . '>' . esc_html($u_label) . '</option>';
    }
    $fields .= '</select></label>';
    $fields .= '<label>Title Document<select name="propvendor_title_document"' . $land_disabled . '><option value="">Select Title Document</option>';
    foreach (propvendor_marketplace_get_title_documents() as $opt) {
        $fields .= '<option value="' . esc_attr($opt) . '" ' . selected($land_title_doc_val, $opt, false) . '>' . esc_html($opt) . '</option>';
    }
    $fields .= '</select></label>';
    $fields .= '</div>';
    $fields .= '<div class="propvendor-form-grid-3">';
    $fields .= '<label>Length<input type="text" name="propvendor_land_length" value="' . esc_attr((string) $land_length_val) . '" placeholder="e.g., 100"' . $land_disabled . ' /></label>';
    $fields .= '<label>Width<input type="text" name="propvendor_land_width" value="' . esc_attr((string) $land_width_val) . '" placeholder="e.g., 60"' . $land_disabled . ' /></label>';
    $fields .= '<label>Dimension Unit<select name="propvendor_dimension_unit"' . $land_disabled . '><option value="m" ' . selected($land_dim_unit_val, 'm', false) . '>Meters (m)</option><option value="ft" ' . selected($land_dim_unit_val, 'ft', false) . '>Feet (ft)</option></select></label>';
    $fields .= '<label>Road Frontage<input type="text" name="propvendor_frontage" value="' . esc_attr((string) $land_frontage_val) . '" placeholder="e.g., 50m frontage"' . $land_disabled . ' /></label>';
    $fields .= '</div>';
    $fields .= '</div>';

    // 3. VEHICLE FIELDS CONTAINER
    $veh_display = ('vehicle_listing' === $type_value) ? 'block' : 'none';
    $veh_disabled = ('vehicle_listing' === $type_value) ? '' : ' disabled';
    $fields .= '<div class="propvendor-category-fields" id="propvendor-fields-vehicle" style="display: ' . $veh_display . ';">';
    $fields .= '<h4>Vehicle Details</h4>';
    $fields .= '<div class="propvendor-form-grid-3">';
    $fields .= '<label>Vehicle Type / Subtype<select name="propvendor_vehicle_subtype"' . $veh_disabled . '><option value="">Select Vehicle Type</option>';
    foreach (propvendor_marketplace_get_vehicle_subtypes() as $opt) {
        $fields .= '<option value="' . esc_attr($opt) . '" ' . selected($veh_subtype_val, $opt, false) . '>' . esc_html($opt) . '</option>';
    }
    $fields .= '</select></label>';
    $fields .= '<label>Make<input type="text" name="propvendor_vehicle_make" value="' . esc_attr((string) $veh_make_val) . '" placeholder="e.g., Toyota"' . $veh_disabled . ' /></label>';
    $fields .= '<label>Model<input type="text" name="propvendor_vehicle_model" value="' . esc_attr((string) $veh_model_val) . '" placeholder="e.g., Camry"' . $veh_disabled . ' /></label>';
    $fields .= '<label>Year of Manufacture<input type="number" name="propvendor_vehicle_year" min="1900" max="' . (date('Y') + 2) . '" value="' . esc_attr((string) $veh_year_val) . '" placeholder="e.g., 2020"' . $veh_disabled . ' /></label>';
    $fields .= '<label>Condition<select name="propvendor_condition"' . $veh_disabled . '><option value="">Select Condition</option>';
    foreach (propvendor_marketplace_get_vehicle_conditions() as $opt) {
        $fields .= '<option value="' . esc_attr($opt) . '" ' . selected($veh_condition_val, $opt, false) . '>' . esc_html($opt) . '</option>';
    }
    $fields .= '</select></label>';
    $fields .= '<label>Transmission<select name="propvendor_transmission"' . $veh_disabled . '><option value="">Select Transmission</option>';
    foreach (propvendor_marketplace_get_vehicle_transmissions() as $opt) {
        $fields .= '<option value="' . esc_attr($opt) . '" ' . selected($veh_trans_val, $opt, false) . '>' . esc_html($opt) . '</option>';
    }
    $fields .= '</select></label>';
    $fields .= '<label>Mileage (km)<span class="propvendor-field-hint">Current odometer reading in kilometers</span><input type="number" name="propvendor_mileage" min="0" value="' . esc_attr((string) $veh_mileage_val) . '" placeholder="e.g., 45000"' . $veh_disabled . ' /></label>';
    $fields .= '<label>Fuel Type<select name="propvendor_fuel_type"' . $veh_disabled . '><option value="">Select Fuel Type</option>';
    foreach (propvendor_marketplace_get_vehicle_fuel_types() as $opt) {
        $fields .= '<option value="' . esc_attr($opt) . '" ' . selected($veh_fuel_val, $opt, false) . '>' . esc_html($opt) . '</option>';
    }
    $fields .= '</select></label>';
    $fields .= '<label>Engine Size<input type="text" name="propvendor_engine_size" value="' . esc_attr((string) $veh_engine_val) . '" placeholder="e.g. 2.4L I4"' . $veh_disabled . ' /></label>';
    $fields .= '</div>';
    $fields .= '</div>';

    // Description
    $fields .= '<label>Description<textarea name="propvendor_description" rows="6" placeholder="Describe the features, condition, and any other important details..." required>' . esc_textarea($description_value) . '</textarea></label>';

    // Existing Images Management (Edit Mode)
    if (!empty($existing_images)) {
        $fields .= '<div class="propvendor-existing-images-box">';
        $fields .= '<h4>Current Images (' . count($existing_images) . ' of 10)</h4>';
        $fields .= '<div class="propvendor-existing-images-grid">';
        foreach ($existing_images as $img_id) {
            $is_primary = ($img_id === $thumbnail_id);
            $img_tag = wp_get_attachment_image($img_id, 'thumbnail');
            $fields .= '<div class="propvendor-existing-image-card">';
            if ($is_primary) {
                $fields .= '<span class="propvendor-image-primary-badge">Primary</span>';
            }
            $fields .= $img_tag;
            $fields .= '<div class="propvendor-existing-image-actions">';
            $fields .= '<label class="propvendor-action-main"><input type="radio" name="propvendor_primary_image_id" value="' . esc_attr((string) $img_id) . '" ' . checked($is_primary, true, false) . ' /> Main</label>';
            $fields .= '<label class="propvendor-action-delete"><input type="checkbox" class="propvendor-remove-image-checkbox" name="propvendor_delete_images[]" value="' . esc_attr((string) $img_id) . '" /> Delete</label>';
            $fields .= '</div>';
            $fields .= '</div>';
        }
        $fields .= '</div>';
        $fields .= '</div>';
    }

    // New Multiple Images Upload
    $img_required = ($listing && !empty($existing_images)) ? '' : 'required';
    $fields .= '<div class="propvendor-image-upload-wrap">';
    $fields .= '<label for="propvendor_listing_images">Listing Images (Up to 10 images total, max 4MB each, JPG/PNG/WebP)</label>';
    $fields .= '<input type="file" name="propvendor_listing_images[]" id="propvendor_listing_images" multiple accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" ' . $img_required . ' />';
    $fields .= '<div id="propvendor-image-preview-grid" class="propvendor-image-preview-grid"></div>';
    $fields .= '</div>';

    // Owner-Direct Checkbox
    $fields .= '<div class="propvendor-form-checkbox-row" style="margin: 1rem 0; padding: 0.85rem 1rem; background: #f6ffed; border: 1px solid #b7eb8f; border-radius: 4px;">';
    $fields .= '<label style="font-weight: 600; color: #135200; display: flex; align-items: center; gap: 0.6rem; cursor: pointer; font-size: 0.95rem;">';
    $fields .= '<input type="checkbox" name="propvendor_is_direct_owner" value="1" ' . checked('1', (string) $is_direct_owner_value, false) . ' /> ';
    $fields .= 'I am the direct owner of this property/vehicle (No Agent Fee / Direct Owner).';
    $fields .= '</label>';
    $fields .= '</div>';

    $fields .= '<button type="submit">' . ($listing ? 'Update Listing' : 'Submit for Review') . '</button>';
    $fields .= '</form></div>';

    return $fields;
}
add_shortcode('propvendor_submit_listing_form', 'propvendor_marketplace_submit_listing_form_shortcode');

function propvendor_marketplace_vendor_registration_shortcode() {
    if (is_user_logged_in()) {
        return '<div class="propvendor-form-box"><h3>Vendor registration</h3><p>Your account is already active. You can manage listings from the vendor dashboard.</p></div>';
    }

    $notice = propvendor_marketplace_get_notice_message();
    $output = '<div class="propvendor-form-box"><h3>Vendor registration</h3>' . $notice . '<form class="propvendor-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    $output .= '<input type="hidden" name="action" value="propvendor_register_vendor" />';
    $output .= wp_nonce_field('propvendor_register_vendor', 'propvendor_register_vendor_nonce', true, false);
    $output .= '<div class="propvendor-form-grid"><label>First name<input type="text" name="first_name" required /></label><label>Last name<input type="text" name="last_name" required /></label></div>';
    $output .= '<div class="propvendor-form-grid"><label>Business name<input type="text" name="business_name" required /></label><label>Phone number<input type="tel" name="phone_number" required /></label></div>';
    $output .= '<label>Email address<input type="email" name="email" required /></label>';
    $output .= '<label>Business category<select name="business_type"><option value="Property sales">Property sales</option><option value="Rentals">Rentals</option><option value="Land">Land</option><option value="Vehicles">Vehicles</option></select></label>';
    $output .= '<label>Verification details<textarea name="verification_details" rows="4" placeholder="Business registration, ID, or address verification notes"></textarea></label>';
    $output .= '<button type="submit">Register as Vendor</button>';
    $output .= '</form></div>';
    return $output;
}
add_shortcode('propvendor_vendor_registration_form', 'propvendor_marketplace_vendor_registration_shortcode');

function propvendor_marketplace_vendor_dashboard_shortcode() {
    if (!is_user_logged_in()) {
        return '<div class="propvendor-dashboard"><h2>Vendor dashboard</h2><p class="propvendor-empty">Please <a href="' . esc_url(wp_login_url(home_url('/vendor-dashboard/'))) . '">log in</a> with your vendor account to view your listings.</p></div>';
    }

    $user_id = get_current_user_id();
    $user = wp_get_current_user();
    if (!in_array('vendor', (array) $user->roles, true) || !current_user_can('propvendor_submit_marketplace_listings')) {
        return '<div class="propvendor-dashboard"><h2>Vendor dashboard</h2><p class="propvendor-empty">This dashboard is available to registered vendors only.</p></div>';
    }

    $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : 'listings';
    $public_profile_url = propvendor_marketplace_get_vendor_profile_url($user_id);
    $dashboard_url = home_url('/vendor-dashboard/');
    $profile_tab_url = add_query_arg(array('view' => 'profile'), $dashboard_url);
    $inbox_tab_url = add_query_arg(array('view' => 'inbox'), $dashboard_url);

    $unread_count = propvendor_marketplace_get_vendor_unread_count($user_id);
    $unread_badge = ($unread_count > 0) ? ' <span class="pv-badge-unread">' . esc_html($unread_count) . '</span>' : '';

    $notice = propvendor_marketplace_get_notice_message();
    $output = '<section class="propvendor-dashboard" aria-labelledby="propvendor-dashboard-title">';
    $output .= '<nav class="propvendor-dashboard-nav" aria-label="Vendor Dashboard Navigation">';
    $output .= '<a class="propvendor-dashboard-tab ' . ('listings' === $view ? 'active' : '') . '" href="' . esc_url($dashboard_url) . '">My Listings</a>';
    $output .= '<a class="propvendor-dashboard-tab ' . ('inbox' === $view ? 'active' : '') . '" href="' . esc_url($inbox_tab_url) . '">Inbox' . $unread_badge . '</a>';
    $output .= '<a class="propvendor-dashboard-tab ' . ('profile' === $view ? 'active' : '') . '" href="' . esc_url($profile_tab_url) . '">Edit Profile</a>';
    $output .= '<a class="propvendor-dashboard-tab" href="' . esc_url($public_profile_url) . '" target="_blank" rel="noopener">View Public Profile &#8599;</a>';
    $output .= '<a class="propvendor-dashboard-tab propvendor-dashboard-tab-action" href="' . esc_url(home_url('/submit-a-listing/')) . '">+ Add New Listing</a>';
    $output .= '</nav>' . $notice;

    if ('inbox' === $view) {
        $output .= propvendor_marketplace_render_vendor_inbox($user_id);
        $output .= '</section>';
        return $output;
    }

    if ('profile' === $view) {
        $output .= propvendor_marketplace_render_vendor_profile_form($user_id);
        $output .= '</section>';
        return $output;
    }

    $listings = get_posts(
        array(
            'post_type' => propvendor_marketplace_get_listing_post_types(),
            'post_status' => array('publish', 'pending', 'draft'),
            'posts_per_page' => -1,
            'author' => $user_id,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
        )
    );

    // Stage 3: Vendor Listing Quota Card
    $output .= propvendor_marketplace_render_vendor_quota_card($user_id);

    $output .= '<div class="propvendor-dashboard-header"><div><h2 id="propvendor-dashboard-title">My listings</h2><p>Manage and track your marketplace submissions.</p></div><a class="propvendor-dashboard-add" href="' . esc_url(home_url('/submit-a-listing/')) . '">Add New Listing</a></div>';
    if (empty($listings)) {
        $output .= '<div class="propvendor-dashboard-empty"><p class="propvendor-empty">You have no listings yet. Add your first listing to get started.</p><a class="propvendor-dashboard-add" href="' . esc_url(home_url('/submit-a-listing/')) . '">Add your first listing</a></div>';
    } else {
        $output .= '<div class="propvendor-dashboard-table-wrap"><table class="propvendor-dashboard-table"><thead><tr><th scope="col">Listing</th><th scope="col">Category / Type</th><th scope="col">Location</th><th scope="col">Price</th><th scope="col">Submitted</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>';
        foreach ($listings as $listing) {
            $stored_status = get_post_meta($listing->ID, 'propvendor_listing_status', true);
            if (empty($stored_status)) {
                $stored_status = get_post_meta($listing->ID, 'propvendor_status', true);
            }
            $status_labels = array(
                'Pending review' => 'Pending Review',
                'Published' => 'Published',
                'Rejected' => 'Rejected',
                'Sold' => 'Sold',
                'Rented' => 'Rented',
                'Unavailable' => 'Unavailable',
                'Expired' => 'Expired',
            );
            $status = isset($status_labels[$stored_status]) ? $status_labels[$stored_status] : '';
            if (empty($status)) {
                $post_status_labels = array(
                    'pending' => 'Pending Review',
                    'publish' => 'Published',
                    'draft' => 'Rejected',
                );
                $status = isset($post_status_labels[$listing->post_status]) ? $post_status_labels[$listing->post_status] : 'Unavailable';
            }

            $price = get_post_meta($listing->ID, 'propvendor_price', true);
            $type_label = array(
                'property_listing' => 'Property',
                'land_listing' => 'Land',
                'vehicle_listing' => 'Vehicle',
            );
            $listing_type = get_post_type($listing);
            $categories = get_the_terms($listing->ID, 'listing_category');
            $category_labels = array();
            if (is_array($categories)) {
                foreach ($categories as $category) {
                    $category_labels[] = $category->name;
                }
            }
            $category = isset($type_label[$listing_type]) ? $type_label[$listing_type] : 'Listing';
            if (!empty($category_labels)) {
                $category .= ' - ' . implode(', ', $category_labels);
            }
            $edit_url = add_query_arg(array('propvendor_edit_listing' => $listing->ID), home_url('/vendor-dashboard/'));
            $location = get_post_meta($listing->ID, 'propvendor_location', true);
            $submitted = get_the_date(get_option('date_format'), $listing);
            $actions = '<a href="' . esc_url($edit_url) . '">Edit</a>';
            if ('publish' === $listing->post_status) {
                $actions .= ' <a href="' . esc_url(get_permalink($listing)) . '">View</a>';
            }

            $output .= '<tr>';
            $output .= '<td data-label="Listing"><strong>' . esc_html(get_the_title($listing)) . '</strong></td>';
            $output .= '<td data-label="Category / Type">' . esc_html($category) . '</td>';
            $output .= '<td data-label="Location">' . esc_html($location ?: 'Not provided') . '</td>';
            $output .= '<td data-label="Price">' . esc_html($price ? '₦ ' . number_format_i18n((float) $price, 2) : 'Price on request') . '</td>';
            $output .= '<td data-label="Submitted">' . esc_html($submitted) . '</td>';
            $output .= '<td data-label="Status"><span class="propvendor-dashboard-status propvendor-dashboard-status-' . esc_attr(sanitize_title($status)) . '">' . esc_html($status) . '</span></td>';
            $output .= '<td data-label="Actions" class="propvendor-dashboard-actions">' . $actions . '</td>';
            $output .= '</tr>';
        }
        $output .= '</tbody></table></div>';
    }

    $edit_id = 0;
    foreach (array('propvendor_edit_listing', 'edit_listing', 'listing_id', 'edit') as $param) {
        if (isset($_GET[$param]) && absint($_GET[$param]) > 0) {
            $edit_id = absint($_GET[$param]);
            break;
        }
    }

    if ($edit_id > 0) {
        $edit_listing = get_post($edit_id);
        if ($edit_listing && in_array($edit_listing->post_type, propvendor_marketplace_get_listing_post_types(), true) && ((int) $edit_listing->post_author === $user_id || current_user_can('manage_options'))) {
            $output .= propvendor_marketplace_submit_listing_form_shortcode();
        }
    }

    // Phase 5: Accessible Confirmation Modal for Lead Contact Unlock
    $output .= '
    <div id="pv-unlock-lead-modal" class="pv-unlock-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="pv-unlock-modal-title">
        <div class="pv-unlock-modal-backdrop"></div>
        <div class="pv-unlock-modal-dialog">
            <div class="pv-unlock-modal-header">
                <h4 id="pv-unlock-modal-title"><span class="dashicons dashicons-unlock"></span> ' . esc_html__('Confirm Lead Contact Unlock', 'propvendor-marketplace') . '</h4>
                <button type="button" class="pv-unlock-modal-close" aria-label="' . esc_attr__('Close dialog', 'propvendor-marketplace') . '">&times;</button>
            </div>
            <div class="pv-unlock-modal-body">
                <p>' . esc_html__('Are you sure you want to unlock this customer\'s direct contact details?', 'propvendor-marketplace') . '</p>
                <div class="pv-unlock-modal-details">
                    <div><strong>' . esc_html__('Cost:', 'propvendor-marketplace') . '</strong> 1 Credit</div>
                    <div><strong>' . esc_html__('Your Balance:', 'propvendor-marketplace') . '</strong> <span id="pv-modal-current-balance">0</span> Credits</div>
                    <div><strong>' . esc_html__('Balance After Unlock:', 'propvendor-marketplace') . '</strong> <span id="pv-modal-balance-after">0</span> Credits</div>
                </div>
                <p class="pv-unlock-modal-subtext">' . esc_html__('1 credit will be deducted from your wallet upon confirmation. Customer phone number and email will be revealed immediately.', 'propvendor-marketplace') . '</p>
                <div id="pv-unlock-modal-error" class="pv-unlock-modal-error" style="display: none;"></div>
            </div>
            <div class="pv-unlock-modal-footer">
                <button type="button" id="pv-btn-confirm-unlock" class="button button-primary">' . esc_html__('Confirm & Deduct 1 Credit', 'propvendor-marketplace') . '</button>
                <button type="button" id="pv-btn-cancel-unlock" class="button button-secondary">' . esc_html__('Cancel', 'propvendor-marketplace') . '</button>
            </div>
        </div>
    </div>';

    $output .= '</section>';
    return $output;
}
add_shortcode('propvendor_vendor_dashboard', 'propvendor_marketplace_vendor_dashboard_shortcode');

function propvendor_marketplace_contact_form_shortcode() {
    $output = '<div class="propvendor-form-box"><h3>Send a message</h3><form class="propvendor-form"><label>Name<input type="text" placeholder="Full name" /></label><label>Email<input type="email" placeholder="Email address" /></label><label>Topic<select><option>Listing enquiry</option><option>Vendor support</option><option>Fraud report</option></select></label><label>Message<textarea rows="5" placeholder="How can we help?"></textarea></label><button type="button">Send enquiry</button></form></div>';
    return $output;
}
add_shortcode('propvendor_contact_form', 'propvendor_marketplace_contact_form_shortcode');

function propvendor_marketplace_handle_vendor_registration() {
    if (!isset($_POST['propvendor_register_vendor_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['propvendor_register_vendor_nonce'])), 'propvendor_register_vendor')) {
        wp_die('Security check failed.');
    }

    $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
    $last_name = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
    $business_name = sanitize_text_field(wp_unslash($_POST['business_name'] ?? ''));
    $phone_number = sanitize_text_field(wp_unslash($_POST['phone_number'] ?? ''));
    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    $business_type = sanitize_text_field(wp_unslash($_POST['business_type'] ?? ''));
    $verification_details = sanitize_textarea_field(wp_unslash($_POST['verification_details'] ?? ''));

    if (empty($first_name) || empty($last_name) || empty($business_name) || empty($phone_number) || empty($email)) {
        propvendor_marketplace_redirect_with_notice('/vendor-registration/', 'error', 'Please complete all required vendor registration fields.');
    }

    if (!is_email($email)) {
        propvendor_marketplace_redirect_with_notice('/vendor-registration/', 'error', 'The email address is invalid.');
    }

    if (email_exists($email)) {
        propvendor_marketplace_redirect_with_notice('/vendor-registration/', 'error', 'A user with that email already exists.');
    }

    $username = sanitize_user(str_replace(array(' ', '@', '.'), '_', $email), true);
    $username = preg_replace('/[^a-z0-9_\-]+/i', '', $username);
    if (empty($username) || username_exists($username)) {
        $username = 'vendor_' . wp_generate_password(8, false, false);
    }

    $password = wp_generate_password(18, true, true);
    $user_id = wp_insert_user(
        array(
            'user_login' => $username,
            'user_email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => trim($first_name . ' ' . $last_name),
            'user_pass' => $password,
            'role' => 'vendor',
        )
    );

    if (is_wp_error($user_id)) {
        propvendor_marketplace_redirect_with_notice('/vendor-registration/', 'error', $user_id->get_error_message());
    }

    update_user_meta($user_id, 'propvendor_business_name', $business_name);
    update_user_meta($user_id, 'propvendor_phone_number', $phone_number);
    update_user_meta($user_id, 'propvendor_business_type', $business_type);
    update_user_meta($user_id, 'propvendor_verification_details', $verification_details);

    wp_new_user_notification($user_id, null, 'both');
    propvendor_marketplace_redirect_with_notice('/vendor-registration/', 'success', 'Vendor registration submitted successfully. A secure login email has been sent to the address provided.');
}
add_action('admin_post_nopriv_propvendor_register_vendor', 'propvendor_marketplace_handle_vendor_registration');
add_action('admin_post_propvendor_register_vendor', 'propvendor_marketplace_handle_vendor_registration');

/**
 * Get all image attachment IDs for a marketplace listing.
 *
 * @param int $post_id
 * @return int[]
 */
function propvendor_marketplace_get_listing_image_ids($post_id) {
    $post_id = absint($post_id);
    if (!$post_id) {
        return array();
    }

    $image_ids = array();
    $thumbnail_id = absint(get_post_thumbnail_id($post_id));
    if ($thumbnail_id) {
        $image_ids[] = $thumbnail_id;
    }

    $meta_ids = get_post_meta($post_id, '_propvendor_gallery_image_ids', true);
    if (is_array($meta_ids)) {
        foreach ($meta_ids as $mid) {
            $mid = absint($mid);
            if ($mid && !in_array($mid, $image_ids, true)) {
                $image_ids[] = $mid;
            }
        }
    }

    $attached = get_posts(array(
        'post_parent'    => $post_id,
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => 'image',
        'posts_per_page' => 50,
        'orderby'        => 'menu_order ID',
        'order'          => 'ASC',
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ));
    if (is_array($attached)) {
        foreach ($attached as $aid) {
            $aid = absint($aid);
            if ($aid && !in_array($aid, $image_ids, true)) {
                $image_ids[] = $aid;
            }
        }
    }

    $valid_ids = array();
    foreach ($image_ids as $id) {
        if (get_post($id)) {
            $valid_ids[] = $id;
        }
    }

    return $valid_ids;
}

/**
 * Normalize single or multiple $_FILES entries into a uniform list of file arrays.
 *
 * @param array|null $file_entry
 * @return array[]
 */
function propvendor_marketplace_normalize_uploaded_files($file_entry) {
    $normalized = array();
    if (empty($file_entry) || !isset($file_entry['name'])) {
        return $normalized;
    }

    if (is_array($file_entry['name'])) {
        $count = count($file_entry['name']);
        for ($i = 0; $i < $count; $i++) {
            if (!empty($file_entry['name'][$i]) && (!isset($file_entry['error'][$i]) || UPLOAD_ERR_NO_FILE !== $file_entry['error'][$i])) {
                $normalized[] = array(
                    'name'     => $file_entry['name'][$i],
                    'type'     => $file_entry['type'][$i] ?? '',
                    'tmp_name' => $file_entry['tmp_name'][$i] ?? '',
                    'error'    => $file_entry['error'][$i] ?? 0,
                    'size'     => $file_entry['size'][$i] ?? 0,
                );
            }
        }
    } else {
        if (!empty($file_entry['name']) && (!isset($file_entry['error']) || UPLOAD_ERR_NO_FILE !== $file_entry['error'])) {
            $normalized[] = $file_entry;
        }
    }

    return $normalized;
}

/**
 * Handle a single image upload to protected listing storage.
 *
 * @param int $post_id
 * @param array $file
 * @return int|WP_Error
 */
function propvendor_marketplace_handle_single_listing_image_upload($post_id, $file) {
    if (empty($file['name'])) {
        return new WP_Error('missing_image', 'Please upload a valid listing image.');
    }

    if (!isset($file['tmp_name']) || !file_exists($file['tmp_name'])) {
        return new WP_Error('invalid_uploaded_file', 'The uploaded image is not valid.');
    }

    if ($file['size'] > 4 * 1024 * 1024) {
        return new WP_Error('image_too_large', 'The listing image must be 4MB or smaller.');
    }

    $file_type = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
    $allowed_mime_types = array('image/jpeg', 'image/png', 'image/webp');
    if (false === $file_type['type'] || !in_array($file_type['type'], $allowed_mime_types, true)) {
        return new WP_Error('invalid_file_type', 'Only JPEG, PNG, and WebP images are allowed.');
    }

    $private_root = propvendor_marketplace_ensure_private_listing_storage();
    if (is_wp_error($private_root)) {
        return $private_root;
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    add_filter('upload_dir', 'propvendor_marketplace_use_private_listing_upload_directory');
    try {
        $attachment_id = media_handle_sideload($file, $post_id);
    } finally {
        remove_filter('upload_dir', 'propvendor_marketplace_use_private_listing_upload_directory');
    }

    if (is_wp_error($attachment_id)) {
        return $attachment_id;
    }

    $attached_file = get_attached_file($attachment_id);
    $private_real_root = realpath($private_root);
    $attached_real_path = $attached_file ? realpath($attached_file) : false;
    if (!$private_real_root || !$attached_real_path
        || 0 !== strpos(wp_normalize_path($attached_real_path), trailingslashit(wp_normalize_path($private_real_root)))
    ) {
        wp_delete_attachment($attachment_id, true);
        return new WP_Error('propvendor_image_not_protected', 'The uploaded image could not be stored securely.');
    }

    return $attachment_id;
}

/**
 * Handle listing image upload (single/legacy entry).
 *
 * @param int $post_id
 * @param array $file
 * @return int|WP_Error
 */
function propvendor_marketplace_handle_listing_image_upload($post_id, $file) {
    $attachment_id = propvendor_marketplace_handle_single_listing_image_upload($post_id, $file);
    if (!is_wp_error($attachment_id)) {
        set_post_thumbnail($post_id, $attachment_id);
    }
    return $attachment_id;
}

/**
 * Safely delete an image attached to a listing.
 *
 * @param int $post_id
 * @param int $attachment_id
 * @param int $current_user_id
 * @return bool
 */
function propvendor_marketplace_delete_listing_image($post_id, $attachment_id, $current_user_id) {
    $post_id = absint($post_id);
    $attachment_id = absint($attachment_id);
    if (!$post_id || !$attachment_id) {
        return false;
    }

    $listing = get_post($post_id);
    if (!$listing) {
        return false;
    }

    if ((int) $listing->post_author !== (int) $current_user_id && !user_can($current_user_id, 'manage_options')) {
        return false;
    }

    $attachment = get_post($attachment_id);
    if (!$attachment || 'attachment' !== $attachment->post_type) {
        return false;
    }

    $gallery_ids = (array) get_post_meta($post_id, '_propvendor_gallery_image_ids', true);
    $is_parent = ((int) $attachment->post_parent === $post_id);
    $is_thumb = ((int) get_post_thumbnail_id($post_id) === $attachment_id);
    $is_in_gallery = in_array($attachment_id, $gallery_ids, true);

    if (!$is_parent && !$is_thumb && !$is_in_gallery) {
        return false;
    }

    $other_usage = get_posts(array(
        'post_type'      => 'any',
        'exclude'        => array($post_id),
        'meta_key'       => '_thumbnail_id',
        'meta_value'     => $attachment_id,
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ));

    if (empty($other_usage) && $is_parent) {
        wp_delete_attachment($attachment_id, true);
    }

    $gallery_ids = array_values(array_diff($gallery_ids, array($attachment_id)));
    update_post_meta($post_id, '_propvendor_gallery_image_ids', $gallery_ids);

    if ($is_thumb) {
        delete_post_thumbnail($post_id);
        $remaining = propvendor_marketplace_get_listing_image_ids($post_id);
        $remaining = array_values(array_diff($remaining, array($attachment_id)));
        if (!empty($remaining)) {
            set_post_thumbnail($post_id, $remaining[0]);
        }
    }

    return true;
}

function propvendor_marketplace_process_listing_submission($data, $files, $current_user_id, $existing_post_id = 0) {
    if (!is_user_logged_in()) {
        return new WP_Error('not_logged_in', 'You must be logged in to submit a listing.');
    }

    if (empty($current_user_id)) {
        return new WP_Error('missing_user', 'The current user could not be identified.');
    }

    $listing_type = isset($data['propvendor_listing_type']) ? sanitize_key($data['propvendor_listing_type']) : 'property_listing';
    if ($existing_post_id > 0) {
        $existing_listing = get_post($existing_post_id);
        if (!$existing_listing) {
            return new WP_Error('invalid_listing', 'The listing could not be found.');
        }
        if ((int) $existing_listing->post_author !== (int) $current_user_id && !user_can($current_user_id, 'manage_options')) {
            return new WP_Error('not_owner', 'You may only edit your own listings.');
        }
        $listing_type = get_post_type($existing_listing);
    }

    $allowed_types = propvendor_marketplace_get_listing_post_types();
    if (!in_array($listing_type, $allowed_types, true)) {
        return new WP_Error('invalid_listing_type', 'The selected listing type is invalid.');
    }

    if (!user_can($current_user_id, 'manage_options') && !user_can($current_user_id, 'propvendor_submit_marketplace_listings')) {
        return new WP_Error('insufficient_permissions', 'Your account does not have permission to submit or edit listings.');
    }

    $owner_id = ($existing_post_id > 0 && isset($existing_listing) && $existing_listing) ? (int) $existing_listing->post_author : (int) $current_user_id;
    if (!$existing_post_id && user_can($current_user_id, 'manage_options') && !empty($data['post_author'])) {
        $owner_id = absint($data['post_author']);
    }

    // Stage 3: Early Quota & Reactivation Check
    if (!propvendor_marketplace_vendor_can_create_listing($owner_id, $existing_post_id, $current_user_id)) {
        $free_limit = propvendor_marketplace_get_free_listing_limit();
        return new WP_Error(
            'quota_exceeded',
            sprintf(
                __('You have reached the maximum limit of %d active listings for free vendor accounts. Upgrade to VIP for unlimited listings or remove an existing listing.', 'propvendor-marketplace'),
                $free_limit
            )
        );
    }

    $title = sanitize_text_field(wp_unslash($data['propvendor_title'] ?? ''));
    $location_raw = sanitize_text_field(wp_unslash($data['propvendor_location'] ?? ''));
    $full_address = sanitize_text_field(wp_unslash($data['propvendor_full_address'] ?? ''));
    $description = wp_kses_post(wp_unslash($data['propvendor_description'] ?? ''));
    $price = floatval($data['propvendor_price'] ?? 0);

    if (empty($title) || empty($description) || $price <= 0) {
        return new WP_Error('missing_required_fields', 'Please provide a title, description, and a valid price.');
    }

    $state = propvendor_marketplace_normalize_nigerian_state($location_raw);
    if (empty($state)) {
        return new WP_Error('invalid_location_state', 'Please select a valid Nigerian State from the list.');
    }

    if (!isset($data['propvendor_full_address']) && !empty($location_raw)) {
        $full_address = $location_raw;
    } elseif (empty($full_address)) {
        if (!empty($location_raw) && 0 !== strcasecmp($location_raw, $state)) {
            $full_address = $location_raw;
        }
    }

    if (in_array($listing_type, array('property_listing', 'land_listing'), true) && '' === trim($full_address)) {
        return new WP_Error('missing_address', 'Please provide a full address or specific property location.');
    }

    if (mb_strlen($full_address) > 255) {
        return new WP_Error('address_too_long', 'The full address must not exceed 255 characters.');
    }

    // 1. Check existing images and planned deletions
    $current_image_ids = ($existing_post_id > 0) ? propvendor_marketplace_get_listing_image_ids($existing_post_id) : array();
    $to_delete = array();
    if ($existing_post_id > 0 && !empty($data['propvendor_delete_images'])) {
        $to_delete = array_map('absint', (array) $data['propvendor_delete_images']);
    }

    // 2. Normalize and check incoming uploaded files
    $uploaded_files = array();
    if (!empty($files['propvendor_listing_images'])) {
        $uploaded_files = propvendor_marketplace_normalize_uploaded_files($files['propvendor_listing_images']);
    } elseif (!empty($files['propvendor_listing_image'])) {
        $uploaded_files = propvendor_marketplace_normalize_uploaded_files($files['propvendor_listing_image']);
    }

    $remaining_existing = array_diff($current_image_ids, $to_delete);
    $total_images_count = count($remaining_existing) + count($uploaded_files);

    if ($existing_post_id > 0 && !empty($to_delete) && $total_images_count === 0) {
        return new WP_Error('missing_image', 'Every listing must have at least one image. Please upload a replacement image before deleting all existing images.');
    }

    if (!$existing_post_id && $total_images_count === 0) {
        return new WP_Error('missing_image', 'Please upload at least one listing image.');
    }

    if ($total_images_count > 10) {
        return new WP_Error('too_many_images', 'You may have a maximum of 10 images per listing.');
    }

    // 3. Process deletions safely now that at least one image is guaranteed to remain
    if ($existing_post_id > 0 && !empty($to_delete)) {
        foreach ($to_delete as $del_id) {
            if ($del_id > 0) {
                propvendor_marketplace_delete_listing_image($existing_post_id, $del_id, $current_user_id);
            }
        }
    }

    $current_image_ids = ($existing_post_id > 0) ? propvendor_marketplace_get_listing_image_ids($existing_post_id) : array();

    $post_status = 'pending';
    $listing_status = 'Pending review';
    if ($existing_post_id > 0) {
        $existing_status = get_post_status($existing_post_id);
        if ('publish' === $existing_status) {
            $post_status = 'pending';
            $listing_status = 'Pending review';
        }
    }

    $post_args = array(
        'post_title'   => $title,
        'post_content' => $description,
        'post_author'  => $owner_id,
        'post_status'  => $post_status,
    );

    // Stage 3: Atomic locking for new listing creation or reactivation
    $lock_acquired = false;
    $lock_name = 'pv_listing_quota_' . $owner_id;

    if ($existing_post_id === 0 || !propvendor_marketplace_is_listing_active($existing_post_id)) {
        if (!user_can($current_user_id, 'manage_options') && !propvendor_marketplace_is_vendor_vip($owner_id)) {
            global $wpdb;
            $lock_res = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 10)", $lock_name));
            if ('1' !== (string) $lock_res) {
                return new WP_Error('quota_lock_failed', __('The server is currently busy processing another listing for your account. Please wait a few seconds and try again.', 'propvendor-marketplace'));
            }
            $lock_acquired = true;

            // Re-verify quota under lock to prevent concurrent race-condition bypass
            if (!propvendor_marketplace_vendor_can_create_listing($owner_id, $existing_post_id, $current_user_id)) {
                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
                $lock_acquired = false;
                $free_limit = propvendor_marketplace_get_free_listing_limit();
                return new WP_Error(
                    'quota_exceeded',
                    sprintf(
                        __('You have reached the maximum limit of %d active listings for free vendor accounts. Upgrade to VIP for unlimited listings or remove an existing listing.', 'propvendor-marketplace'),
                        $free_limit
                    )
                );
            }
        }
    }

    try {
        if ($existing_post_id > 0) {
            $post_args['ID'] = absint($existing_post_id);
            $post_args['post_type'] = get_post_type($existing_post_id);
            $post_id = wp_update_post($post_args, true);
        } else {
            $post_args['post_type'] = $listing_type;
            $post_id = wp_insert_post($post_args, true);
        }
    } finally {
        if ($lock_acquired) {
            global $wpdb;
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
            $lock_acquired = false;
        }
    }

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    // 3. Process new image uploads safely
    $new_attachment_ids = array();
    if (!empty($uploaded_files)) {
        foreach ($uploaded_files as $ufile) {
            $upload_res = propvendor_marketplace_handle_single_listing_image_upload($post_id, $ufile);
            if (is_wp_error($upload_res)) {
                foreach ($new_attachment_ids as $clean_id) {
                    wp_delete_attachment($clean_id, true);
                }
                if (!$existing_post_id) {
                    wp_delete_post($post_id, true);
                }
                return $upload_res;
            }
            wp_update_post(array('ID' => $upload_res, 'post_parent' => $post_id));
            $new_attachment_ids[] = absint($upload_res);
        }
    }

    // 4. Update gallery image IDs and primary thumbnail
    $all_image_ids = array_values(array_unique(array_merge($current_image_ids, $new_attachment_ids)));
    update_post_meta($post_id, '_propvendor_gallery_image_ids', $all_image_ids);

    $primary_id = !empty($data['propvendor_primary_image_id']) ? absint($data['propvendor_primary_image_id']) : 0;
    if ($primary_id > 0 && in_array($primary_id, $all_image_ids, true)) {
        set_post_thumbnail($post_id, $primary_id);
    } else {
        $current_thumb = (int) get_post_thumbnail_id($post_id);
        if (!$current_thumb || !in_array($current_thumb, $all_image_ids, true)) {
            if (!empty($all_image_ids)) {
                set_post_thumbnail($post_id, $all_image_ids[0]);
            }
        }
    }

    // 5. Save common metadata
    update_post_meta($post_id, 'propvendor_price', $price);
    update_post_meta($post_id, 'propvendor_location', $state);
    update_post_meta($post_id, 'propvendor_state', $state);
    update_post_meta($post_id, 'propvendor_full_address', $full_address);
    update_post_meta($post_id, 'propvendor_listing_status', $listing_status);
    update_post_meta($post_id, 'propvendor_status', $listing_status);
    update_post_meta($post_id, 'propvendor_listing_type', $listing_type);

    // 6. Save category-specific metadata and clean up obsolete cross-category metadata
    $valid_cat_keys = propvendor_marketplace_get_category_meta_keys($listing_type);
    $all_cat_keys   = propvendor_marketplace_get_all_category_meta_keys();
    $obsolete_keys  = array_diff($all_cat_keys, $valid_cat_keys);

    if ($existing_post_id > 0) {
        foreach ($obsolete_keys as $obsolete_key) {
            delete_post_meta($post_id, $obsolete_key);
        }
    }

    if ('property_listing' === $listing_type) {
        if (isset($data['propvendor_property_subtype'])) {
            $val = sanitize_text_field(wp_unslash($data['propvendor_property_subtype']));
            if (in_array($val, propvendor_marketplace_get_property_subtypes(), true)) {
                update_post_meta($post_id, 'propvendor_property_subtype', $val);
            } elseif ('' === $val) {
                delete_post_meta($post_id, 'propvendor_property_subtype');
            }
        }
        if (isset($data['propvendor_purpose'])) {
            $val = sanitize_text_field(wp_unslash($data['propvendor_purpose']));
            if (in_array($val, propvendor_marketplace_get_property_purposes(), true)) {
                update_post_meta($post_id, 'propvendor_purpose', $val);
            }
        }
        if (isset($data['propvendor_bedrooms'])) {
            update_post_meta($post_id, 'propvendor_bedrooms', absint($data['propvendor_bedrooms']));
        }
        if (isset($data['propvendor_bathrooms'])) {
            update_post_meta($post_id, 'propvendor_bathrooms', absint($data['propvendor_bathrooms']));
        }
        if (isset($data['propvendor_furnishing'])) {
            $val = sanitize_text_field(wp_unslash($data['propvendor_furnishing']));
            if (in_array($val, propvendor_marketplace_get_furnishing_options(), true)) {
                update_post_meta($post_id, 'propvendor_furnishing', $val);
            } elseif ('' === $val) {
                delete_post_meta($post_id, 'propvendor_furnishing');
            }
        }
        if (isset($data['propvendor_title_document'])) {
            $val = sanitize_text_field(wp_unslash($data['propvendor_title_document']));
            if (in_array($val, propvendor_marketplace_get_title_documents(), true)) {
                update_post_meta($post_id, 'propvendor_title_document', $val);
            } elseif ('' === $val) {
                delete_post_meta($post_id, 'propvendor_title_document');
            }
        }
        if (isset($data['propvendor_floor_area'])) {
            $val = floatval($data['propvendor_floor_area']);
            if ($val > 0) {
                update_post_meta($post_id, 'propvendor_floor_area', $val);
            } else {
                delete_post_meta($post_id, 'propvendor_floor_area');
            }
        }
        if (isset($data['propvendor_floor_area_unit'])) {
            update_post_meta($post_id, 'propvendor_floor_area_unit', sanitize_text_field(wp_unslash($data['propvendor_floor_area_unit'])));
        }
        if (isset($data['propvendor_plot_size'])) {
            $val = sanitize_text_field(wp_unslash($data['propvendor_plot_size']));
            if ('' !== $val) {
                update_post_meta($post_id, 'propvendor_plot_size', $val);
            } else {
                delete_post_meta($post_id, 'propvendor_plot_size');
            }
        }
        if (isset($data['propvendor_plot_size_unit'])) {
            $u_val = propvendor_marketplace_normalize_plot_size_unit(wp_unslash($data['propvendor_plot_size_unit']));
            if (!empty($u_val)) {
                update_post_meta($post_id, 'propvendor_plot_size_unit', $u_val);
            } else {
                delete_post_meta($post_id, 'propvendor_plot_size_unit');
            }
        }
    } elseif ('land_listing' === $listing_type) {
        if (isset($data['propvendor_land_use'])) {
            $val = sanitize_text_field(wp_unslash($data['propvendor_land_use']));
            if (in_array($val, propvendor_marketplace_get_land_uses(), true)) {
                update_post_meta($post_id, 'propvendor_land_use', $val);
            } elseif ('' === $val) {
                delete_post_meta($post_id, 'propvendor_land_use');
            }
        }
        if (isset($data['propvendor_plot_size'])) {
            $val = sanitize_text_field(wp_unslash($data['propvendor_plot_size']));
            if ('' !== $val) {
                update_post_meta($post_id, 'propvendor_plot_size', $val);
            } else {
                delete_post_meta($post_id, 'propvendor_plot_size');
            }
        }
        if (isset($data['propvendor_plot_size_unit'])) {
            $u_val = propvendor_marketplace_normalize_plot_size_unit(wp_unslash($data['propvendor_plot_size_unit']));
            if (!empty($u_val)) {
                update_post_meta($post_id, 'propvendor_plot_size_unit', $u_val);
            } else {
                delete_post_meta($post_id, 'propvendor_plot_size_unit');
            }
        }
        if (isset($data['propvendor_title_document'])) {
            $val = sanitize_text_field(wp_unslash($data['propvendor_title_document']));
            if (in_array($val, propvendor_marketplace_get_title_documents(), true)) {
                update_post_meta($post_id, 'propvendor_title_document', $val);
            } elseif ('' === $val) {
                delete_post_meta($post_id, 'propvendor_title_document');
            }
        }
        if (isset($data['propvendor_land_length'])) {
            $val = sanitize_text_field(wp_unslash($data['propvendor_land_length']));
            if ('' !== $val) {
                update_post_meta($post_id, 'propvendor_land_length', $val);
            } else {
                delete_post_meta($post_id, 'propvendor_land_length');
            }
        }
        if (isset($data['propvendor_land_width'])) {
            $val = sanitize_text_field(wp_unslash($data['propvendor_land_width']));
            if ('' !== $val) {
                update_post_meta($post_id, 'propvendor_land_width', $val);
            } else {
                delete_post_meta($post_id, 'propvendor_land_width');
            }
        }
        if (isset($data['propvendor_dimension_unit'])) {
            update_post_meta($post_id, 'propvendor_dimension_unit', sanitize_text_field(wp_unslash($data['propvendor_dimension_unit'])));
        }
        if (isset($data['propvendor_frontage'])) {
            $val = sanitize_text_field(wp_unslash($data['propvendor_frontage']));
            if ('' !== $val) {
                update_post_meta($post_id, 'propvendor_frontage', $val);
            } else {
                delete_post_meta($post_id, 'propvendor_frontage');
            }
        }
    } elseif ('vehicle_listing' === $listing_type) {
        if (isset($data['propvendor_vehicle_subtype'])) {
            $val = sanitize_text_field(wp_unslash($data['propvendor_vehicle_subtype']));
            if (in_array($val, propvendor_marketplace_get_vehicle_subtypes(), true)) {
                update_post_meta($post_id, 'propvendor_vehicle_subtype', $val);
            } elseif ('' === $val) {
                delete_post_meta($post_id, 'propvendor_vehicle_subtype');
            }
        }
        if (isset($data['propvendor_vehicle_make'])) {
            $val = sanitize_text_field(wp_unslash($data['propvendor_vehicle_make']));
            if ('' !== $val) {
                update_post_meta($post_id, 'propvendor_vehicle_make', $val);
            } else {
                delete_post_meta($post_id, 'propvendor_vehicle_make');
            }
        }
        if (isset($data['propvendor_vehicle_model'])) {
            $val = sanitize_text_field(wp_unslash($data['propvendor_vehicle_model']));
            if ('' !== $val) {
                update_post_meta($post_id, 'propvendor_vehicle_model', $val);
            } else {
                delete_post_meta($post_id, 'propvendor_vehicle_model');
            }
        }
        if (isset($data['propvendor_vehicle_year'])) {
            $val = absint($data['propvendor_vehicle_year']);
            if ($val >= 1900 && $val <= (int) date('Y') + 2) {
                update_post_meta($post_id, 'propvendor_vehicle_year', $val);
            } else {
                delete_post_meta($post_id, 'propvendor_vehicle_year');
            }
        }
        if (isset($data['propvendor_condition'])) {
            $val = sanitize_text_field(wp_unslash($data['propvendor_condition']));
            if (in_array($val, propvendor_marketplace_get_vehicle_conditions(), true)) {
                update_post_meta($post_id, 'propvendor_condition', $val);
            } elseif ('' === $val) {
                delete_post_meta($post_id, 'propvendor_condition');
            }
        }
        if (isset($data['propvendor_transmission'])) {
            $val = sanitize_text_field(wp_unslash($data['propvendor_transmission']));
            if (in_array($val, propvendor_marketplace_get_vehicle_transmissions(), true)) {
                update_post_meta($post_id, 'propvendor_transmission', $val);
            } elseif ('' === $val) {
                delete_post_meta($post_id, 'propvendor_transmission');
            }
        }
        if (isset($data['propvendor_mileage'])) {
            update_post_meta($post_id, 'propvendor_mileage', absint($data['propvendor_mileage']));
        }
        if (isset($data['propvendor_fuel_type'])) {
            $val = sanitize_text_field(wp_unslash($data['propvendor_fuel_type']));
            if (in_array($val, propvendor_marketplace_get_vehicle_fuel_types(), true)) {
                update_post_meta($post_id, 'propvendor_fuel_type', $val);
            } elseif ('' === $val) {
                delete_post_meta($post_id, 'propvendor_fuel_type');
            }
        }
        if (isset($data['propvendor_engine_size'])) {
            $val = sanitize_text_field(wp_unslash($data['propvendor_engine_size']));
            if ('' !== $val) {
                update_post_meta($post_id, 'propvendor_engine_size', $val);
            } else {
                delete_post_meta($post_id, 'propvendor_engine_size');
            }
        }
    }

    // 7. Stage 2: Owner-Direct Claim & Auto-Reset Revocation Handling
    $is_direct_owner = (!empty($data['propvendor_is_direct_owner']) && '1' === (string) $data['propvendor_is_direct_owner']) ? '1' : '0';
    update_post_meta($post_id, '_propvendor_is_direct_owner', $is_direct_owner);

    if ('1' === $is_direct_owner) {
        update_post_meta($post_id, '_propvendor_direct_owner_status', 'pending');
    } else {
        update_post_meta($post_id, '_propvendor_direct_owner_status', 'none');
    }

    $category_name = ucfirst(str_replace('_', ' ', $listing_type));
    if (!term_exists($category_name, 'listing_category')) {
        wp_insert_term($category_name, 'listing_category');
    }
    wp_set_object_terms($post_id, array($category_name), 'listing_category', false);

    return array('post_id' => $post_id, 'listing_status' => $listing_status);
}

function propvendor_marketplace_handle_listing_submission() {
    if (!isset($_POST['propvendor_listing_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['propvendor_listing_nonce'])), 'propvendor_listing_nonce')) {
        wp_die('Security check failed.');
    }

    $user_id = get_current_user_id();
    $result = propvendor_marketplace_process_listing_submission($_POST, $_FILES, $user_id, isset($_POST['propvendor_listing_id']) ? absint($_POST['propvendor_listing_id']) : 0);
    if (is_wp_error($result)) {
        propvendor_marketplace_redirect_with_notice('/submit-a-listing/', 'error', $result->get_error_message());
    }

    propvendor_marketplace_redirect_with_notice('/submit-a-listing/', 'success', 'Your listing was submitted successfully and is pending review by an administrator.');
}
add_action('admin_post_nopriv_propvendor_submit_listing', 'propvendor_marketplace_handle_listing_submission');
add_action('admin_post_propvendor_submit_listing', 'propvendor_marketplace_handle_listing_submission');

function propvendor_marketplace_handle_listing_update() {
    if (!isset($_POST['propvendor_listing_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['propvendor_listing_nonce'])), 'propvendor_listing_nonce')) {
        wp_die('Security check failed.');
    }

    $listing_id = isset($_POST['propvendor_listing_id']) ? absint($_POST['propvendor_listing_id']) : 0;
    $user_id = get_current_user_id();
    $result = propvendor_marketplace_process_listing_submission($_POST, $_FILES, $user_id, $listing_id);
    if (is_wp_error($result)) {
        $referer = wp_get_referer();
        if ($referer && $listing_id > 0) {
            $redirect_url = add_query_arg('propvendor_edit_listing', $listing_id, $referer);
        } elseif ($listing_id > 0) {
            $redirect_url = add_query_arg('propvendor_edit_listing', $listing_id, home_url('/vendor-dashboard/'));
        } else {
            $redirect_url = '/vendor-dashboard/';
        }
        propvendor_marketplace_redirect_with_notice($redirect_url, 'error', $result->get_error_message());
    }

    propvendor_marketplace_redirect_with_notice('/vendor-dashboard/', 'success', 'Listing updated successfully and returned to pending review for moderation.');
}
add_action('admin_post_nopriv_propvendor_update_listing', 'propvendor_marketplace_handle_listing_update');
add_action('admin_post_propvendor_update_listing', 'propvendor_marketplace_handle_listing_update');

function propvendor_marketplace_add_listing_meta_boxes() {
    $screens = array('property_listing', 'land_listing', 'vehicle_listing');
    foreach ($screens as $screen) {
        add_meta_box(
            'propvendor_listing_details',
            'Marketplace Details',
            'propvendor_marketplace_render_meta_box',
            $screen,
            'normal',
            'default'
        );
        add_meta_box(
            'propvendor_moderation',
            'Moderation',
            'propvendor_marketplace_render_moderation_meta_box',
            $screen,
            'side',
            'default'
        );
    }
}
add_action('add_meta_boxes', 'propvendor_marketplace_add_listing_meta_boxes');

function propvendor_marketplace_render_meta_box($post) {
    wp_nonce_field('propvendor_listing_meta_nonce', 'propvendor_listing_meta_nonce');
    $price = get_post_meta($post->ID, 'propvendor_price', true);
    $location = get_post_meta($post->ID, 'propvendor_location', true);
    $full_address = get_post_meta($post->ID, 'propvendor_full_address', true);
    $status = get_post_meta($post->ID, 'propvendor_listing_status', true);
    ?>
    <p>
        <label for="propvendor_price"><strong>Price (NGN)</strong></label>
        <input type="number" id="propvendor_price" name="propvendor_price" value="<?php echo esc_attr($price); ?>" min="0" step="1000" class="widefat" />
    </p>
    <p>
        <label for="propvendor_location"><strong>State</strong></label>
        <select id="propvendor_location" name="propvendor_location" class="widefat">
            <option value="">Select State</option>
            <?php foreach (propvendor_marketplace_get_nigerian_states() as $st) : ?>
                <option value="<?php echo esc_attr($st); ?>" <?php selected(propvendor_marketplace_normalize_nigerian_state($location), $st); ?>><?php echo esc_html($st); ?></option>
            <?php endforeach; ?>
        </select>
    </p>
    <p>
        <label for="propvendor_full_address"><strong>Full Address / Property Address</strong></label>
        <input type="text" id="propvendor_full_address" name="propvendor_full_address" value="<?php echo esc_attr($full_address); ?>" maxlength="255" class="widefat" />
    </p>
    <?php if ('property_listing' === $post->post_type) :
        $subtype = get_post_meta($post->ID, 'propvendor_property_subtype', true);
        $purpose = get_post_meta($post->ID, 'propvendor_purpose', true);
        $beds = get_post_meta($post->ID, 'propvendor_bedrooms', true);
        $baths = get_post_meta($post->ID, 'propvendor_bathrooms', true);
        $furnishing = get_post_meta($post->ID, 'propvendor_furnishing', true);
        $title_doc = get_post_meta($post->ID, 'propvendor_title_document', true);
        $floor_area = get_post_meta($post->ID, 'propvendor_floor_area', true);
        $floor_unit = get_post_meta($post->ID, 'propvendor_floor_area_unit', true) ?: 'sqm';
        $plot_size = get_post_meta($post->ID, 'propvendor_plot_size', true);
        $plot_unit = get_post_meta($post->ID, 'propvendor_plot_size_unit', true) ?: 'sqm';
        ?>
        <p>
            <label for="propvendor_property_subtype">Property Subtype</label>
            <select id="propvendor_property_subtype" name="propvendor_property_subtype" class="widefat">
                <option value="">Select Subtype</option>
                <?php foreach (propvendor_marketplace_get_property_subtypes() as $opt) : ?>
                    <option value="<?php echo esc_attr($opt); ?>" <?php selected($subtype, $opt); ?>><?php echo esc_html($opt); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="propvendor_purpose">Purpose</label>
            <select id="propvendor_purpose" name="propvendor_purpose" class="widefat">
                <?php foreach (propvendor_marketplace_get_property_purposes() as $opt) : ?>
                    <option value="<?php echo esc_attr($opt); ?>" <?php selected($purpose, $opt); ?>><?php echo esc_html($opt); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="propvendor_bedrooms">Bedrooms</label>
            <input type="number" id="propvendor_bedrooms" name="propvendor_bedrooms" value="<?php echo esc_attr($beds); ?>" min="0" class="widefat" />
        </p>
        <p>
            <label for="propvendor_bathrooms">Bathrooms</label>
            <input type="number" id="propvendor_bathrooms" name="propvendor_bathrooms" value="<?php echo esc_attr($baths); ?>" min="0" class="widefat" />
        </p>
        <p>
            <label for="propvendor_furnishing">Furnishing</label>
            <select id="propvendor_furnishing" name="propvendor_furnishing" class="widefat">
                <option value="">Select Furnishing</option>
                <?php foreach (propvendor_marketplace_get_furnishing_options() as $opt) : ?>
                    <option value="<?php echo esc_attr($opt); ?>" <?php selected($furnishing, $opt); ?>><?php echo esc_html($opt); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="propvendor_title_document">Title Document</label>
            <select id="propvendor_title_document" name="propvendor_title_document" class="widefat">
                <option value="">Select Document</option>
                <?php foreach (propvendor_marketplace_get_title_documents() as $opt) : ?>
                    <option value="<?php echo esc_attr($opt); ?>" <?php selected($title_doc, $opt); ?>><?php echo esc_html($opt); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="propvendor_floor_area">Floor Area (<?php echo esc_html($floor_unit); ?>)</label>
            <input type="number" step="any" id="propvendor_floor_area" name="propvendor_floor_area" value="<?php echo esc_attr($floor_area); ?>" class="widefat" />
        </p>
        <p>
            <label for="propvendor_plot_size">Plot Size</label>
            <input type="text" id="propvendor_plot_size" name="propvendor_plot_size" value="<?php echo esc_attr($plot_size); ?>" class="widefat" />
        </p>
        <p>
            <label for="propvendor_plot_size_unit">Plot Size Unit</label>
            <select id="propvendor_plot_size_unit" name="propvendor_plot_size_unit" class="widefat">
                <?php foreach (propvendor_marketplace_get_plot_size_units() as $u_key => $u_label) : ?>
                    <option value="<?php echo esc_attr($u_key); ?>" <?php selected(propvendor_marketplace_normalize_plot_size_unit($plot_unit), $u_key); ?>><?php echo esc_html($u_label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
    <?php elseif ('land_listing' === $post->post_type) :
        $land_use = get_post_meta($post->ID, 'propvendor_land_use', true);
        $plot_size = get_post_meta($post->ID, 'propvendor_plot_size', true);
        $plot_unit = get_post_meta($post->ID, 'propvendor_plot_size_unit', true) ?: 'plots';
        $title_doc = get_post_meta($post->ID, 'propvendor_title_document', true);
        $length = get_post_meta($post->ID, 'propvendor_land_length', true);
        $width = get_post_meta($post->ID, 'propvendor_land_width', true);
        $dim_unit = get_post_meta($post->ID, 'propvendor_dimension_unit', true) ?: 'm';
        $frontage = get_post_meta($post->ID, 'propvendor_frontage', true);
        ?>
        <p>
            <label for="propvendor_land_use">Land Use</label>
            <select id="propvendor_land_use" name="propvendor_land_use" class="widefat">
                <option value="">Select Land Use</option>
                <?php foreach (propvendor_marketplace_get_land_uses() as $opt) : ?>
                    <option value="<?php echo esc_attr($opt); ?>" <?php selected($land_use, $opt); ?>><?php echo esc_html($opt); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="propvendor_plot_size">Plot / Land Size</label>
            <input type="text" id="propvendor_plot_size" name="propvendor_plot_size" value="<?php echo esc_attr($plot_size); ?>" class="widefat" />
        </p>
        <p>
            <label for="propvendor_plot_size_unit">Plot Size Unit</label>
            <select id="propvendor_plot_size_unit" name="propvendor_plot_size_unit" class="widefat">
                <?php foreach (propvendor_marketplace_get_plot_size_units() as $u_key => $u_label) : ?>
                    <option value="<?php echo esc_attr($u_key); ?>" <?php selected(propvendor_marketplace_normalize_plot_size_unit($plot_unit), $u_key); ?>><?php echo esc_html($u_label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="propvendor_title_document">Title Document</label>
            <select id="propvendor_title_document" name="propvendor_title_document" class="widefat">
                <option value="">Select Document</option>
                <?php foreach (propvendor_marketplace_get_title_documents() as $opt) : ?>
                    <option value="<?php echo esc_attr($opt); ?>" <?php selected($title_doc, $opt); ?>><?php echo esc_html($opt); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="propvendor_land_length">Length (<?php echo esc_html($dim_unit); ?>)</label>
            <input type="text" id="propvendor_land_length" name="propvendor_land_length" value="<?php echo esc_attr($length); ?>" class="widefat" />
        </p>
        <p>
            <label for="propvendor_land_width">Width (<?php echo esc_html($dim_unit); ?>)</label>
            <input type="text" id="propvendor_land_width" name="propvendor_land_width" value="<?php echo esc_attr($width); ?>" class="widefat" />
        </p>
        <p>
            <label for="propvendor_frontage">Road Frontage</label>
            <input type="text" id="propvendor_frontage" name="propvendor_frontage" value="<?php echo esc_attr($frontage); ?>" class="widefat" />
        </p>
    <?php elseif ('vehicle_listing' === $post->post_type) :
        $veh_subtype = get_post_meta($post->ID, 'propvendor_vehicle_subtype', true);
        $make = get_post_meta($post->ID, 'propvendor_vehicle_make', true);
        $model = get_post_meta($post->ID, 'propvendor_vehicle_model', true);
        $year = get_post_meta($post->ID, 'propvendor_vehicle_year', true);
        $condition = get_post_meta($post->ID, 'propvendor_condition', true);
        $trans = get_post_meta($post->ID, 'propvendor_transmission', true);
        $mileage = get_post_meta($post->ID, 'propvendor_mileage', true);
        $fuel = get_post_meta($post->ID, 'propvendor_fuel_type', true);
        $engine = get_post_meta($post->ID, 'propvendor_engine_size', true);
        ?>
        <p>
            <label for="propvendor_vehicle_subtype">Vehicle Subtype</label>
            <select id="propvendor_vehicle_subtype" name="propvendor_vehicle_subtype" class="widefat">
                <option value="">Select Subtype</option>
                <?php foreach (propvendor_marketplace_get_vehicle_subtypes() as $opt) : ?>
                    <option value="<?php echo esc_attr($opt); ?>" <?php selected($veh_subtype, $opt); ?>><?php echo esc_html($opt); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="propvendor_vehicle_make">Vehicle Make</label>
            <input type="text" id="propvendor_vehicle_make" name="propvendor_vehicle_make" value="<?php echo esc_attr($make); ?>" class="widefat" />
        </p>
        <p>
            <label for="propvendor_vehicle_model">Vehicle Model</label>
            <input type="text" id="propvendor_vehicle_model" name="propvendor_vehicle_model" value="<?php echo esc_attr($model); ?>" class="widefat" />
        </p>
        <p>
            <label for="propvendor_vehicle_year">Year</label>
            <input type="number" id="propvendor_vehicle_year" name="propvendor_vehicle_year" value="<?php echo esc_attr($year); ?>" min="1900" max="<?php echo (int) date('Y') + 2; ?>" class="widefat" />
        </p>
        <p>
            <label for="propvendor_condition">Vehicle Condition</label>
            <select id="propvendor_condition" name="propvendor_condition" class="widefat">
                <option value="">Select Condition</option>
                <?php foreach (propvendor_marketplace_get_vehicle_conditions() as $opt) : ?>
                    <option value="<?php echo esc_attr($opt); ?>" <?php selected($condition, $opt); ?>><?php echo esc_html($opt); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="propvendor_transmission">Transmission</label>
            <select id="propvendor_transmission" name="propvendor_transmission" class="widefat">
                <option value="">Select Transmission</option>
                <?php foreach (propvendor_marketplace_get_vehicle_transmissions() as $opt) : ?>
                    <option value="<?php echo esc_attr($opt); ?>" <?php selected($trans, $opt); ?>><?php echo esc_html($opt); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="propvendor_mileage">Mileage (km)</label>
            <input type="number" id="propvendor_mileage" name="propvendor_mileage" value="<?php echo esc_attr($mileage); ?>" min="0" class="widefat" />
        </p>
        <p>
            <label for="propvendor_fuel_type">Fuel Type</label>
            <select id="propvendor_fuel_type" name="propvendor_fuel_type" class="widefat">
                <option value="">Select Fuel Type</option>
                <?php foreach (propvendor_marketplace_get_vehicle_fuel_types() as $opt) : ?>
                    <option value="<?php echo esc_attr($opt); ?>" <?php selected($fuel, $opt); ?>><?php echo esc_html($opt); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="propvendor_engine_size">Engine Size</label>
            <input type="text" id="propvendor_engine_size" name="propvendor_engine_size" value="<?php echo esc_attr($engine); ?>" class="widefat" />
        </p>
    <?php endif; ?>
    <?php if (current_user_can('manage_options')) : ?>
        <p>
            <label for="propvendor_listing_status"><strong>Marketplace status</strong></label>
            <select id="propvendor_listing_status" name="propvendor_listing_status" class="widefat">
                <?php
                foreach (propvendor_marketplace_get_listing_status_choices() as $option) {
                    echo '<option value="' . esc_attr($option) . '" ' . selected($status, $option, false) . '>' . esc_html($option) . '</option>';
                }
                ?>
            </select>
        </p>
    <?php endif; ?>
    <?php
}

function propvendor_marketplace_render_moderation_meta_box($post) {
    if (!current_user_can('manage_options')) {
        echo '<p>Only administrators may approve or reject marketplace listings.</p>';
        return;
    }

    $current_status = get_post_meta($post->ID, 'propvendor_listing_status', true);
    if (empty($current_status)) {
        $current_status = $post->post_status === 'publish' ? 'Published' : 'Pending review';
    }

    $is_direct_owner = get_post_meta($post->ID, '_propvendor_is_direct_owner', true);
    $direct_owner_status = get_post_meta($post->ID, '_propvendor_direct_owner_status', true);
    if (empty($direct_owner_status)) {
        $direct_owner_status = ('1' === (string) $is_direct_owner) ? 'pending' : 'none';
    }
    ?>
    <p>
        <label for="propvendor_moderation_status">Moderation status</label>
        <select id="propvendor_moderation_status" name="propvendor_moderation_status" class="widefat">
            <?php
            foreach (propvendor_marketplace_get_listing_status_choices() as $status) {
                echo '<option value="' . esc_attr($status) . '" ' . selected($current_status, $status, false) . '>' . esc_html($status) . '</option>';
            }
            ?>
        </select>
    </p>
    <p>
        <label><input type="checkbox" name="propvendor_remove_listing" value="1" /> Remove listing</label>
    </p>
    <hr style="margin: 15px 0; border: 0; border-top: 1px solid #ddd;" />
    <p>
        <strong>Owner-Direct Claim:</strong><br />
        <?php if ('1' === (string) $is_direct_owner): ?>
            <span style="color: #0a8a4f; font-weight: 600;">&#10003; Vendor claimed direct ownership</span>
        <?php else: ?>
            <span style="color: #64748b;">Vendor did not claim direct ownership</span>
        <?php endif; ?>
    </p>
    <p>
        <label for="propvendor_direct_owner_status"><strong>Owner-Direct Status:</strong></label>
        <select id="propvendor_direct_owner_status" name="propvendor_direct_owner_status" class="widefat">
            <option value="none" <?php selected($direct_owner_status, 'none'); ?>>None (No Claim)</option>
            <option value="pending" <?php selected($direct_owner_status, 'pending'); ?>>Pending Review</option>
            <option value="approved" <?php selected($direct_owner_status, 'approved'); ?>>Approved (Badge Displayed)</option>
            <option value="rejected" <?php selected($direct_owner_status, 'rejected'); ?>>Rejected</option>
            <option value="revoked" <?php selected($direct_owner_status, 'revoked'); ?>>Revoked</option>
        </select>
        <span class="description" style="display:block; margin-top:4px;">When Approved, public badge "Direct from Owner — Zero Agent Fee" is displayed.</span>
    </p>
    <?php
    wp_nonce_field('propvendor_moderation_nonce', 'propvendor_moderation_nonce');
}

function propvendor_marketplace_handle_admin_moderation_save($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!isset($_POST['propvendor_moderation_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['propvendor_moderation_nonce'])), 'propvendor_moderation_nonce')) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['propvendor_remove_listing']) && '1' === $_POST['propvendor_remove_listing']) {
        remove_action('save_post', 'propvendor_marketplace_handle_admin_moderation_save', 20);
        try {
            $trashed = wp_trash_post($post_id);
        } finally {
            add_action('save_post', 'propvendor_marketplace_handle_admin_moderation_save', 20);
        }
        if (!$trashed) {
            error_log('PropVendor could not move listing ' . absint($post_id) . ' to the trash.');
        }
        return;
    }

    if (isset($_POST['propvendor_direct_owner_status'])) {
        $submitted_do_status = sanitize_key(wp_unslash($_POST['propvendor_direct_owner_status']));
        $allowed_do_statuses = array('none', 'pending', 'approved', 'rejected', 'revoked');
        if (in_array($submitted_do_status, $allowed_do_statuses, true)) {
            update_post_meta($post_id, '_propvendor_direct_owner_status', $submitted_do_status);
        }
    }

    if (!isset($_POST['propvendor_moderation_status'])) {
        return;
    }

    $moderation_status = sanitize_text_field(wp_unslash($_POST['propvendor_moderation_status']));
    $allowed = propvendor_marketplace_get_listing_status_choices();
    if (!in_array($moderation_status, $allowed, true)) {
        return;
    }

    $post_status = '';
    if ('Published' === $moderation_status) {
        $post_status = 'publish';
    } elseif ('Rejected' === $moderation_status) {
        $post_status = 'draft';
    } elseif ('Pending review' === $moderation_status) {
        $post_status = 'pending';
    }

    if ($post_status) {
        remove_action('save_post', 'propvendor_marketplace_handle_admin_moderation_save', 20);
        try {
            $updated = wp_update_post(
                array(
                    'ID' => $post_id,
                    'post_status' => $post_status,
                ),
                true
            );
        } finally {
            add_action('save_post', 'propvendor_marketplace_handle_admin_moderation_save', 20);
        }
        if (is_wp_error($updated)) {
            error_log('PropVendor moderation status update failed for listing ' . absint($post_id) . ': ' . $updated->get_error_message());
            return;
        }
    }

    update_post_meta($post_id, 'propvendor_listing_status', $moderation_status);
    update_post_meta($post_id, 'propvendor_status', $moderation_status);
}
add_action('save_post', 'propvendor_marketplace_handle_admin_moderation_save', 20);

function propvendor_marketplace_save_meta_boxes($post_id) {
    if (!isset($_POST['propvendor_listing_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['propvendor_listing_meta_nonce'])), 'propvendor_listing_meta_nonce')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!in_array(get_post_type($post_id), propvendor_marketplace_get_listing_post_types(), true)) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    if (isset($_POST['propvendor_price'])) {
        update_post_meta($post_id, 'propvendor_price', floatval($_POST['propvendor_price']));
    }

    if (isset($_POST['propvendor_location'])) {
        $loc_raw = sanitize_text_field(wp_unslash($_POST['propvendor_location']));
        $loc_norm = propvendor_marketplace_normalize_nigerian_state($loc_raw);
        $final_state = !empty($loc_norm) ? $loc_norm : $loc_raw;
        update_post_meta($post_id, 'propvendor_location', $final_state);
        update_post_meta($post_id, 'propvendor_state', $final_state);
    }

    if (isset($_POST['propvendor_full_address'])) {
        $full_addr = sanitize_text_field(wp_unslash($_POST['propvendor_full_address']));
        if (mb_strlen($full_addr) > 255) {
            $full_addr = mb_substr($full_addr, 0, 255);
        }
        update_post_meta($post_id, 'propvendor_full_address', $full_addr);
    }

    $post_type = get_post_type($post_id);
    if ('property_listing' === $post_type) {
        if (isset($_POST['propvendor_property_subtype'])) {
            update_post_meta($post_id, 'propvendor_property_subtype', sanitize_text_field(wp_unslash($_POST['propvendor_property_subtype'])));
        }
        if (isset($_POST['propvendor_purpose'])) {
            update_post_meta($post_id, 'propvendor_purpose', sanitize_text_field(wp_unslash($_POST['propvendor_purpose'])));
        }
        if (isset($_POST['propvendor_bedrooms'])) {
            update_post_meta($post_id, 'propvendor_bedrooms', absint($_POST['propvendor_bedrooms']));
        }
        if (isset($_POST['propvendor_bathrooms'])) {
            update_post_meta($post_id, 'propvendor_bathrooms', absint($_POST['propvendor_bathrooms']));
        }
        if (isset($_POST['propvendor_furnishing'])) {
            update_post_meta($post_id, 'propvendor_furnishing', sanitize_text_field(wp_unslash($_POST['propvendor_furnishing'])));
        }
        if (isset($_POST['propvendor_title_document'])) {
            update_post_meta($post_id, 'propvendor_title_document', sanitize_text_field(wp_unslash($_POST['propvendor_title_document'])));
        }
        if (isset($_POST['propvendor_floor_area'])) {
            update_post_meta($post_id, 'propvendor_floor_area', floatval($_POST['propvendor_floor_area']));
        }
        if (isset($_POST['propvendor_plot_size'])) {
            update_post_meta($post_id, 'propvendor_plot_size', sanitize_text_field(wp_unslash($_POST['propvendor_plot_size'])));
        }
        if (isset($_POST['propvendor_plot_size_unit'])) {
            $u_val = propvendor_marketplace_normalize_plot_size_unit(sanitize_text_field(wp_unslash($_POST['propvendor_plot_size_unit'])));
            if (!empty($u_val)) {
                update_post_meta($post_id, 'propvendor_plot_size_unit', $u_val);
            } else {
                delete_post_meta($post_id, 'propvendor_plot_size_unit');
            }
        }
    } elseif ('land_listing' === $post_type) {
        if (isset($_POST['propvendor_land_use'])) {
            update_post_meta($post_id, 'propvendor_land_use', sanitize_text_field(wp_unslash($_POST['propvendor_land_use'])));
        }
        if (isset($_POST['propvendor_plot_size'])) {
            update_post_meta($post_id, 'propvendor_plot_size', sanitize_text_field(wp_unslash($_POST['propvendor_plot_size'])));
        }
        if (isset($_POST['propvendor_plot_size_unit'])) {
            $u_val = propvendor_marketplace_normalize_plot_size_unit(sanitize_text_field(wp_unslash($_POST['propvendor_plot_size_unit'])));
            if (!empty($u_val)) {
                update_post_meta($post_id, 'propvendor_plot_size_unit', $u_val);
            } else {
                delete_post_meta($post_id, 'propvendor_plot_size_unit');
            }
        }
        if (isset($_POST['propvendor_title_document'])) {
            update_post_meta($post_id, 'propvendor_title_document', sanitize_text_field(wp_unslash($_POST['propvendor_title_document'])));
        }
        if (isset($_POST['propvendor_land_length'])) {
            update_post_meta($post_id, 'propvendor_land_length', sanitize_text_field(wp_unslash($_POST['propvendor_land_length'])));
        }
        if (isset($_POST['propvendor_land_width'])) {
            update_post_meta($post_id, 'propvendor_land_width', sanitize_text_field(wp_unslash($_POST['propvendor_land_width'])));
        }
        if (isset($_POST['propvendor_frontage'])) {
            update_post_meta($post_id, 'propvendor_frontage', sanitize_text_field(wp_unslash($_POST['propvendor_frontage'])));
        }
    } elseif ('vehicle_listing' === $post_type) {
        if (isset($_POST['propvendor_vehicle_subtype'])) {
            update_post_meta($post_id, 'propvendor_vehicle_subtype', sanitize_text_field(wp_unslash($_POST['propvendor_vehicle_subtype'])));
        }
        if (isset($_POST['propvendor_vehicle_make'])) {
            update_post_meta($post_id, 'propvendor_vehicle_make', sanitize_text_field(wp_unslash($_POST['propvendor_vehicle_make'])));
        }
        if (isset($_POST['propvendor_vehicle_model'])) {
            update_post_meta($post_id, 'propvendor_vehicle_model', sanitize_text_field(wp_unslash($_POST['propvendor_vehicle_model'])));
        }
        if (isset($_POST['propvendor_vehicle_year'])) {
            update_post_meta($post_id, 'propvendor_vehicle_year', absint($_POST['propvendor_vehicle_year']));
        }
        if (isset($_POST['propvendor_condition'])) {
            update_post_meta($post_id, 'propvendor_condition', sanitize_text_field(wp_unslash($_POST['propvendor_condition'])));
        }
        if (isset($_POST['propvendor_transmission'])) {
            update_post_meta($post_id, 'propvendor_transmission', sanitize_text_field(wp_unslash($_POST['propvendor_transmission'])));
        }
        if (isset($_POST['propvendor_mileage'])) {
            update_post_meta($post_id, 'propvendor_mileage', absint($_POST['propvendor_mileage']));
        }
        if (isset($_POST['propvendor_fuel_type'])) {
            update_post_meta($post_id, 'propvendor_fuel_type', sanitize_text_field(wp_unslash($_POST['propvendor_fuel_type'])));
        }
        if (isset($_POST['propvendor_engine_size'])) {
            update_post_meta($post_id, 'propvendor_engine_size', sanitize_text_field(wp_unslash($_POST['propvendor_engine_size'])));
        }
    }

    if (current_user_can('manage_options') && isset($_POST['propvendor_listing_status'])) {
        $status = sanitize_text_field(wp_unslash($_POST['propvendor_listing_status']));
        if (in_array($status, propvendor_marketplace_get_listing_status_choices(), true)) {
            update_post_meta($post_id, 'propvendor_listing_status', $status);
            update_post_meta($post_id, 'propvendor_status', $status);
        }
    }
}
add_action('save_post', 'propvendor_marketplace_save_meta_boxes', 10);

/**
 * =========================================================================
 * VENDOR PUBLIC PROFILES & PROFILE MANAGEMENT
 * =========================================================================
 */

function propvendor_marketplace_register_vendor_profile_rewrites() {
    add_rewrite_tag('%propvendor_vendor%', '([^/]+)');
    add_rewrite_rule('^vendor/([^/]+)/?$', 'index.php?pagename=vendor-profile&propvendor_vendor=$matches[1]', 'top');
}
add_action('init', 'propvendor_marketplace_register_vendor_profile_rewrites');

function propvendor_marketplace_register_vendor_query_vars($vars) {
    $vars[] = 'propvendor_vendor';
    return $vars;
}
add_filter('query_vars', 'propvendor_marketplace_register_vendor_query_vars');

function propvendor_marketplace_maybe_flush_rewrite_rules() {
    $rules = get_option('rewrite_rules');
    if (!isset($rules['^vendor/([^/]+)/?$'])) {
        flush_rewrite_rules(false);
    }
}
add_action('init', 'propvendor_marketplace_maybe_flush_rewrite_rules', 99);

function propvendor_marketplace_get_vendor_profile_url($user_id) {
    $user = get_user_by('id', $user_id);
    if (!$user) {
        return home_url('/vendor-profile/');
    }
    return home_url('/vendor/' . rawurlencode($user->user_nicename) . '/');
}

function propvendor_marketplace_get_vendor_by_slug($slug) {
    if (empty($slug)) {
        return null;
    }
    $slug = sanitize_title(wp_unslash($slug));

    // 1. Try slug (user_nicename)
    $user = get_user_by('slug', $slug);

    // 2. Try login
    if (!$user) {
        $user = get_user_by('login', $slug);
    }

    // 3. Try numeric ID
    if (!$user && is_numeric($slug)) {
        $user = get_user_by('id', absint($slug));
    }

    // 4. Try business name slug
    if (!$user) {
        $vendors = get_users(array(
            'role' => 'vendor',
            'meta_key' => 'propvendor_business_name',
            'number' => 20,
        ));
        foreach ($vendors as $v) {
            $b_name = get_user_meta($v->ID, 'propvendor_business_name', true);
            if (sanitize_title($b_name) === $slug) {
                $user = $v;
                break;
            }
        }
    }

    if ($user instanceof WP_User) {
        if (in_array('vendor', (array) $user->roles, true) || in_array('administrator', (array) $user->roles, true)) {
            return $user;
        }
    }

    return null;
}

function propvendor_marketplace_get_vendor_image_url($vendor_id, $attachment_id) {
    return add_query_arg(
        array(
            'action' => 'propvendor_vendor_image',
            'vendor_id' => absint($vendor_id),
            'attachment_id' => absint($attachment_id),
        ),
        admin_url('admin-post.php')
    );
}

function propvendor_marketplace_deliver_vendor_image() {
    $vendor_id = isset($_GET['vendor_id']) ? absint($_GET['vendor_id']) : 0;
    $attachment_id = isset($_GET['attachment_id']) ? absint($_GET['attachment_id']) : 0;

    if (!$vendor_id && $attachment_id) {
        $vendors = get_users(array(
            'meta_key' => 'propvendor_profile_image_id',
            'meta_value' => $attachment_id,
            'number' => 1,
        ));
        if (!empty($vendors)) {
            $vendor_id = $vendors[0]->ID;
        }
    }

    $stored_image_id = absint(get_user_meta($vendor_id, 'propvendor_profile_image_id', true));
    if (!$stored_image_id || ($attachment_id && $attachment_id !== $stored_image_id)) {
        status_header(404);
        exit;
    }

    $private_root = propvendor_marketplace_get_private_listing_image_root();
    $original_path = get_attached_file($stored_image_id);
    $original_real_path = $original_path ? realpath($original_path) : false;
    $private_real_root = is_wp_error($private_root) ? false : realpath($private_root);

    if (!$original_real_path || !$private_real_root
        || 0 !== strpos(wp_normalize_path($original_real_path), trailingslashit(wp_normalize_path($private_real_root)))
        || !is_file($original_real_path)
    ) {
        status_header(404);
        exit;
    }

    $image_info = @getimagesize($original_real_path);
    $allowed_mime_types = array('image/jpeg', 'image/png', 'image/webp');
    if (!$image_info || empty($image_info['mime']) || !in_array($image_info['mime'], $allowed_mime_types, true)) {
        status_header(404);
        exit;
    }

    nocache_headers();
    header('Content-Type: ' . $image_info['mime']);
    header('Content-Length: ' . filesize($original_real_path));
    header('Content-Disposition: inline; filename="' . sanitize_file_name(basename($original_real_path)) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($original_real_path);
    exit;
}
add_action('admin_post_nopriv_propvendor_vendor_image', 'propvendor_marketplace_deliver_vendor_image');
add_action('admin_post_propvendor_vendor_image', 'propvendor_marketplace_deliver_vendor_image');

function propvendor_marketplace_handle_vendor_profile_image_upload($user_id, $file) {
    if (empty($file['name'])) {
        return 0;
    }

    if (!isset($file['tmp_name']) || !file_exists($file['tmp_name'])) {
        return new WP_Error('invalid_uploaded_file', 'The uploaded profile image is not valid.');
    }

    if ($file['size'] > 4 * 1024 * 1024) {
        return new WP_Error('image_too_large', 'The profile image must be 4MB or smaller.');
    }

    $file_type = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
    $allowed_mime_types = array('image/jpeg', 'image/png', 'image/webp');
    if (false === $file_type['type'] || !in_array($file_type['type'], $allowed_mime_types, true)) {
        return new WP_Error('invalid_file_type', 'Only JPEG, PNG, and WebP images are allowed.');
    }

    $private_root = propvendor_marketplace_ensure_private_listing_storage();
    if (is_wp_error($private_root)) {
        return $private_root;
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    add_filter('upload_dir', 'propvendor_marketplace_use_private_listing_upload_directory');
    try {
        if (is_uploaded_file($file['tmp_name'])) {
            $attachment_id = media_handle_upload('propvendor_profile_image', 0);
        } else {
            $attachment_id = media_handle_sideload($file, 0);
        }
    } finally {
        remove_filter('upload_dir', 'propvendor_marketplace_use_private_listing_upload_directory');
    }

    if (is_wp_error($attachment_id)) {
        return $attachment_id;
    }

    $old_attachment_id = absint(get_user_meta($user_id, 'propvendor_profile_image_id', true));
    if ($old_attachment_id && $old_attachment_id !== $attachment_id) {
        wp_delete_attachment($old_attachment_id, true);
    }

    update_user_meta($user_id, 'propvendor_profile_image_id', $attachment_id);
    return $attachment_id;
}

function propvendor_marketplace_handle_profile_update() {
    if (!is_user_logged_in()) {
        wp_die('You must be logged in to update your profile.');
    }

    if (!isset($_POST['propvendor_profile_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['propvendor_profile_nonce'])), 'propvendor_update_profile')) {
        wp_die('Security check failed.');
    }

    $current_user_id = get_current_user_id();
    $user = wp_get_current_user();
    if (!in_array('vendor', (array) $user->roles, true) && !current_user_can('manage_options')) {
        propvendor_marketplace_redirect_with_notice('/vendor-dashboard/?view=profile', 'error', 'You do not have permission to update a vendor profile.');
    }

    $business_name = sanitize_text_field(wp_unslash($_POST['propvendor_business_name'] ?? ''));
    $display_name = sanitize_text_field(wp_unslash($_POST['display_name'] ?? ''));
    $business_type = sanitize_text_field(wp_unslash($_POST['propvendor_business_type'] ?? ''));
    $location = sanitize_text_field(wp_unslash($_POST['propvendor_location'] ?? ''));
    $bio = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
    $phone = sanitize_text_field(wp_unslash($_POST['propvendor_phone_number'] ?? ''));
    $public_email = sanitize_email(wp_unslash($_POST['propvendor_public_email'] ?? ''));
    $show_phone = (isset($_POST['propvendor_show_phone']) && '1' === $_POST['propvendor_show_phone']) ? '1' : '0';
    $show_email = (isset($_POST['propvendor_show_email']) && '1' === $_POST['propvendor_show_email']) ? '1' : '0';
    $forward_enquiries = (isset($_POST['propvendor_forward_enquiries']) && '1' === $_POST['propvendor_forward_enquiries']) ? '1' : '0';

    if (empty($display_name)) {
        $display_name = $business_name ? $business_name : $user->display_name;
    }

    wp_update_user(array(
        'ID' => $current_user_id,
        'display_name' => $display_name,
        'description' => $bio,
    ));

    update_user_meta($current_user_id, 'propvendor_business_name', $business_name);
    update_user_meta($current_user_id, 'propvendor_business_type', $business_type);
    update_user_meta($current_user_id, 'propvendor_location', $location);
    update_user_meta($current_user_id, 'propvendor_phone_number', $phone);
    update_user_meta($current_user_id, 'propvendor_public_email', $public_email);
    update_user_meta($current_user_id, 'propvendor_show_phone', $show_phone);
    update_user_meta($current_user_id, 'propvendor_show_email', $show_email);
    update_user_meta($current_user_id, 'propvendor_forward_enquiries', $forward_enquiries);

    // Stage 2: State Regulatory Compliance Numbers
    if (isset($_POST['propvendor_lasrera_number'])) {
        update_user_meta($current_user_id, 'propvendor_lasrera_number', sanitize_text_field(wp_unslash($_POST['propvendor_lasrera_number'])));
    }
    if (isset($_POST['propvendor_agency_license'])) {
        update_user_meta($current_user_id, 'propvendor_agency_license', sanitize_text_field(wp_unslash($_POST['propvendor_agency_license'])));
    }

    if (isset($_POST['propvendor_remove_profile_image']) && '1' === $_POST['propvendor_remove_profile_image']) {
        $old_img = absint(get_user_meta($current_user_id, 'propvendor_profile_image_id', true));
        if ($old_img) {
            wp_delete_attachment($old_img, true);
            delete_user_meta($current_user_id, 'propvendor_profile_image_id');
        }
    }

    if (isset($_FILES['propvendor_profile_image']) && !empty($_FILES['propvendor_profile_image']['name'])) {
        $upload_res = propvendor_marketplace_handle_vendor_profile_image_upload($current_user_id, $_FILES['propvendor_profile_image']);
        if (is_wp_error($upload_res)) {
            propvendor_marketplace_redirect_with_notice('/vendor-dashboard/?view=profile', 'error', $upload_res->get_error_message());
        }
    }

    propvendor_marketplace_redirect_with_notice('/vendor-dashboard/?view=profile', 'success', 'Profile updated successfully.');
}
add_action('admin_post_propvendor_update_vendor_profile', 'propvendor_marketplace_handle_profile_update');

function propvendor_marketplace_render_vendor_profile_form($user_id) {
    $user = get_user_by('id', $user_id);
    if (!$user) {
        return '';
    }

    $business_name = get_user_meta($user_id, 'propvendor_business_name', true);
    $business_type = get_user_meta($user_id, 'propvendor_business_type', true);
    $location = get_user_meta($user_id, 'propvendor_location', true);
    $phone = get_user_meta($user_id, 'propvendor_phone_number', true);
    $public_email = get_user_meta($user_id, 'propvendor_public_email', true);
    $show_phone = get_user_meta($user_id, 'propvendor_show_phone', true);
    $show_email = get_user_meta($user_id, 'propvendor_show_email', true);
    $forward_enquiries = get_user_meta($user_id, 'propvendor_forward_enquiries', true);
    if ('' === (string) $forward_enquiries && '1' === (string) $show_email) {
        $forward_enquiries = '1';
    }
    $img_id = absint(get_user_meta($user_id, 'propvendor_profile_image_id', true));

    $types = array('Property', 'Land', 'Vehicles', 'Rentals', 'Services', 'General Marketplace');

    $html = '<div class="propvendor-form-box propvendor-profile-edit-box">';
    $html .= '<div class="propvendor-dashboard-header"><div><h3>Edit Vendor Profile</h3><p class="propvendor-form-subtext">Manage your public storefront details, business logo, and contact privacy settings.</p></div></div>';

    $html .= '<form class="propvendor-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
    $html .= wp_nonce_field('propvendor_update_profile', 'propvendor_profile_nonce', true, false);
    $html .= '<input type="hidden" name="action" value="propvendor_update_vendor_profile" />';

    $html .= '<div class="propvendor-profile-photo-field">';
    $html .= '<span class="propvendor-field-label"><strong>Profile Photo / Business Logo</strong></span>';
    if ($img_id) {
        $img_url = propvendor_marketplace_get_vendor_image_url($user_id, $img_id);
        $html .= '<div class="propvendor-photo-current">';
        $html .= '<img src="' . esc_url($img_url) . '" alt="Current profile photo" class="propvendor-photo-thumb" />';
        $html .= '<label class="propvendor-checkbox-label"><input type="checkbox" name="propvendor_remove_profile_image" value="1" /> Remove current photo</label>';
        $html .= '</div>';
    }
    $html .= '<input type="file" name="propvendor_profile_image" accept="image/jpeg,image/png,image/webp" />';
    $html .= '<p class="propvendor-field-hint">Accepted formats: JPEG, PNG, WebP (Max size: 4MB). Stored securely in protected storage.</p>';
    $html .= '</div>';

    $html .= '<label>Business / Brand Name<input type="text" name="propvendor_business_name" value="' . esc_attr($business_name) . '" placeholder="e.g. Afriab Properties" /></label>';

    $html .= '<label>Public Display Name<input type="text" name="display_name" value="' . esc_attr($user->display_name) . '" required /></label>';

    $html .= '<label>Primary Category / Business Type<select name="propvendor_business_type">';
    foreach ($types as $type_opt) {
        $html .= '<option value="' . esc_attr($type_opt) . '" ' . selected($business_type, $type_opt, false) . '>' . esc_html($type_opt) . '</option>';
    }
    $html .= '</select></label>';

    $html .= '<label>Location / City<input type="text" name="propvendor_location" value="' . esc_attr($location) . '" placeholder="e.g. Lagos, Nigeria or Ilorin, Kwara State" /></label>';

    $html .= '<label>Business Introduction / Bio<textarea name="description" rows="4" placeholder="Briefly describe your business, experience, or services...">' . esc_textarea($user->description) . '</textarea></label>';

    $html .= '<div class="propvendor-form-section-divider"><h4>Contact Details & Privacy Controls</h4><p class="propvendor-field-hint">Your login email and sensitive account details are always kept private. Only check the boxes below if you want your contact details visible to the public.</p></div>';

    $html .= '<label>Phone Number<input type="text" name="propvendor_phone_number" value="' . esc_attr($phone) . '" placeholder="e.g. 08012345678" /></label>';
    $html .= '<label class="propvendor-checkbox-label"><input type="checkbox" name="propvendor_show_phone" value="1" ' . checked('1', (string) $show_phone, false) . ' /> Publish phone number on public profile and listings</label>';

    $html .= '<label>Public Contact Email (Optional)<input type="email" name="propvendor_public_email" value="' . esc_attr($public_email) . '" placeholder="e.g. sales@mybusiness.com" /></label>';
    $html .= '<label class="propvendor-checkbox-label"><input type="checkbox" name="propvendor_show_email" value="1" ' . checked('1', (string) $show_email, false) . ' /> Publish contact email on public profile and listings</label>';

    $html .= '<div class="propvendor-forwarding-setting-box" style="margin-top: 1rem; margin-bottom: 0.75rem; padding: 0.95rem; background: #f0f7ff; border: 1px solid #c2defc; border-radius: 0.5rem;">';
    $html .= '<label class="propvendor-checkbox-label" style="font-weight: 700; color: #0b477f;"><input type="checkbox" name="propvendor_forward_enquiries" value="1" ' . checked('1', (string) $forward_enquiries, false) . ' /> Direct Email Forwarding — Forward customer listing enquiries directly to my email</label>';
    $html .= '<p class="propvendor-field-hint" style="margin: 0.35rem 0 0 1.55rem; color: #234b6e; font-size: 0.85rem; line-height: 1.45;">When enabled, enquiries submitted through your listing pages are immediately forwarded to your contact email (or your registered account email if no public email is provided). Your private account email is kept confidential and never displayed publicly.</p>';
    $html .= '</div>';

    // Stage 2: Regulatory Compliance Fields
    $lasrera_number = get_user_meta($user_id, 'propvendor_lasrera_number', true);
    $agency_license = get_user_meta($user_id, 'propvendor_agency_license', true);
    $html .= '<div class="propvendor-form-section-divider"><h4>State Regulatory Compliance &amp; Licensing</h4><p class="propvendor-field-hint">Provide your registration details to qualify for the State-Registered Professional verified shield badge.</p></div>';
    $html .= '<label>LASRERA Registration Number (Lagos Agents)<input type="text" name="propvendor_lasrera_number" value="' . esc_attr($lasrera_number) . '" placeholder="e.g. LASRERA/AGT/12345" /></label>';
    $html .= '<label>Real Estate Board / REDAN License Number (Abuja &amp; Other States)<input type="text" name="propvendor_agency_license" value="' . esc_attr($agency_license) . '" placeholder="e.g. REDAN/ABJ/2026/098" /></label>';

    $html .= '<div class="propvendor-form-actions">';
    $html .= '<button type="submit">Save Profile Changes</button>';
    $html .= ' <a class="propvendor-btn secondary" href="' . esc_url(home_url('/vendor-dashboard/')) . '">Cancel</a>';
    $html .= '</div>';

    $html .= '</form></div>';

    return $html;
}

function propvendor_marketplace_add_admin_user_profile_fields($user) {
    if (!current_user_can('manage_options') && !in_array('vendor', (array) $user->roles, true)) {
        return;
    }

    $business_name = get_user_meta($user->ID, 'propvendor_business_name', true);
    $business_type = get_user_meta($user->ID, 'propvendor_business_type', true);
    $location = get_user_meta($user->ID, 'propvendor_location', true);
    $phone = get_user_meta($user->ID, 'propvendor_phone_number', true);
    $show_phone = get_user_meta($user->ID, 'propvendor_show_phone', true);
    $public_email = get_user_meta($user->ID, 'propvendor_public_email', true);
    $show_email = get_user_meta($user->ID, 'propvendor_show_email', true);
    $forward_enquiries = get_user_meta($user->ID, 'propvendor_forward_enquiries', true);
    if ('' === (string) $forward_enquiries && '1' === (string) $show_email) {
        $forward_enquiries = '1';
    }
    ?>
    <h2>PropVendor Marketplace Settings</h2>
    <table class="form-table">
        <tr>
            <th><label for="propvendor_business_name">Business / Brand Name</label></th>
            <td><input type="text" name="propvendor_business_name" id="propvendor_business_name" value="<?php echo esc_attr($business_name); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="propvendor_business_type">Business Type</label></th>
            <td>
                <select name="propvendor_business_type" id="propvendor_business_type">
                    <?php
                    $types = array('Property', 'Land', 'Vehicles', 'Rentals', 'Services', 'General Marketplace');
                    foreach ($types as $type_opt) {
                        echo '<option value="' . esc_attr($type_opt) . '" ' . selected($business_type, $type_opt, false) . '>' . esc_html($type_opt) . '</option>';
                    }
                    ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="propvendor_location">Location / City</label></th>
            <td><input type="text" name="propvendor_location" id="propvendor_location" value="<?php echo esc_attr($location); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="propvendor_phone_number">Phone Number</label></th>
            <td>
                <input type="text" name="propvendor_phone_number" id="propvendor_phone_number" value="<?php echo esc_attr($phone); ?>" class="regular-text" /><br>
                <label><input type="checkbox" name="propvendor_show_phone" value="1" <?php checked('1', (string) $show_phone); ?> /> Publish phone number on public storefront & listings</label>
            </td>
        </tr>
        <tr>
            <th><label for="propvendor_public_email">Public Contact Email</label></th>
            <td>
                <input type="email" name="propvendor_public_email" id="propvendor_public_email" value="<?php echo esc_attr($public_email); ?>" class="regular-text" /><br>
                <label><input type="checkbox" name="propvendor_show_email" value="1" <?php checked('1', (string) $show_email); ?> /> Publish contact email on public storefront & listings</label>
            </td>
        </tr>
        <tr>
            <th><label for="propvendor_forward_enquiries">Direct Email Forwarding</label></th>
            <td>
                <label>
                    <input type="checkbox" name="propvendor_forward_enquiries" id="propvendor_forward_enquiries" value="1" <?php checked('1', (string) $forward_enquiries); ?> />
                    <strong>Enable Direct Email Forwarding</strong>
                </label>
                <p class="description">When checked, customer enquiries submitted on this vendor's listings are forwarded directly to their contact email (or their WordPress account email if no public email is configured). If unchecked, enquiries are stored for administrator review without direct vendor notification.</p>
            </td>
        </tr>
        <?php if (current_user_can('manage_options')) : 
            $vendor_plan = get_user_meta($user->ID, 'propvendor_vendor_plan', true);
            $vendor_plan = strtolower(trim((string) $vendor_plan));
            if (empty($vendor_plan)) {
                $vendor_plan = 'free';
            }
            $lasrera_number = get_user_meta($user->ID, 'propvendor_lasrera_number', true);
            $agency_license = get_user_meta($user->ID, 'propvendor_agency_license', true);
            $regulatory_verified = get_user_meta($user->ID, 'propvendor_regulatory_verified', true);
        ?>
        <tr>
            <th><label for="propvendor_lasrera_number">LASRERA Registration Number</label></th>
            <td><input type="text" name="propvendor_lasrera_number" id="propvendor_lasrera_number" value="<?php echo esc_attr($lasrera_number); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="propvendor_agency_license">Agency / REDAN License Number</label></th>
            <td><input type="text" name="propvendor_agency_license" id="propvendor_agency_license" value="<?php echo esc_attr($agency_license); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="propvendor_regulatory_verified">State Regulatory Verification</label></th>
            <td>
                <select name="propvendor_regulatory_verified" id="propvendor_regulatory_verified">
                    <option value="0" <?php selected((string) $regulatory_verified, '0'); ?>>Unverified (No Badge)</option>
                    <option value="1" <?php selected((string) $regulatory_verified, '1'); ?>>Verified (Shield Badge Displayed)</option>
                </select>
                <p class="description">Only administrators with manage_options may modify this verification status.</p>
            </td>
        </tr>
        <tr>
            <th><label for="propvendor_vendor_plan">PropVendor Plan Tier</label></th>
            <td>
                <select name="propvendor_vendor_plan" id="propvendor_vendor_plan">
                    <option value="free" <?php selected($vendor_plan, 'free'); ?>>Free (Masked Contact Leads)</option>
                    <option value="premium" <?php selected(in_array($vendor_plan, array('premium', 'pro', 'unlocked'), true), true); ?>>Premium Vendor (Unlocked Direct Leads)</option>
                </select>
                <p class="description">Controls customer contact visibility in vendor lead views. Free vendors see masked phone/email with Pay-to-Unlock prompts; Premium vendors see direct contact info.</p>
            </td>
        </tr>
        <?php
            $listing_plan = get_user_meta($user->ID, 'propvendor_listing_plan', true);
            $listing_plan = ('vip' === strtolower(trim((string) $listing_plan))) ? 'vip' : 'free';
            $plan_expires_at = get_user_meta($user->ID, 'propvendor_plan_expires_at', true);
        ?>
        <tr>
            <th><label for="propvendor_listing_plan">Listing Quota Plan</label></th>
            <td>
                <select name="propvendor_listing_plan" id="propvendor_listing_plan">
                    <option value="free" <?php selected($listing_plan, 'free'); ?>>Free (Max <?php echo absint(propvendor_marketplace_get_free_listing_limit()); ?> Active Listings)</option>
                    <option value="vip" <?php selected($listing_plan, 'vip'); ?>>VIP Vendor (Unlimited Active Listings)</option>
                </select>
                <p class="description"><?php printf(esc_html__('Controls marketplace active listing limit across Property, Land, and Vehicle post types. Free vendors may have up to %d active listings; VIP vendors have unlimited listings.', 'propvendor-marketplace'), absint(propvendor_marketplace_get_free_listing_limit())); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="propvendor_plan_expires_at">VIP Plan Expiration Date</label></th>
            <td>
                <input type="date" name="propvendor_plan_expires_at" id="propvendor_plan_expires_at" value="<?php echo esc_attr($plan_expires_at); ?>" class="regular-text" />
                <p class="description"><?php printf(esc_html__('Optional. Leave blank for lifetime/non-expiring VIP. If expired, the vendor automatically reverts to the free %d-listing quota for new submissions without deleting existing listings.', 'propvendor-marketplace'), absint(propvendor_marketplace_get_free_listing_limit())); ?></p>
            </td>
        </tr>
        <?php endif; ?>
    </table>
    <?php
}
add_action('show_user_profile', 'propvendor_marketplace_add_admin_user_profile_fields');
add_action('edit_user_profile', 'propvendor_marketplace_add_admin_user_profile_fields');

function propvendor_marketplace_save_admin_user_profile_fields($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return;
    }

    if (isset($_POST['propvendor_business_name'])) {
        update_user_meta($user_id, 'propvendor_business_name', sanitize_text_field(wp_unslash($_POST['propvendor_business_name'])));
    }
    if (isset($_POST['propvendor_business_type'])) {
        update_user_meta($user_id, 'propvendor_business_type', sanitize_text_field(wp_unslash($_POST['propvendor_business_type'])));
    }
    if (isset($_POST['propvendor_location'])) {
        update_user_meta($user_id, 'propvendor_location', sanitize_text_field(wp_unslash($_POST['propvendor_location'])));
    }
    if (isset($_POST['propvendor_phone_number'])) {
        update_user_meta($user_id, 'propvendor_phone_number', sanitize_text_field(wp_unslash($_POST['propvendor_phone_number'])));
        update_user_meta($user_id, 'propvendor_show_phone', (isset($_POST['propvendor_show_phone']) && '1' === $_POST['propvendor_show_phone']) ? '1' : '0');
    }
    if (isset($_POST['propvendor_public_email'])) {
        update_user_meta($user_id, 'propvendor_public_email', sanitize_email(wp_unslash($_POST['propvendor_public_email'])));
        update_user_meta($user_id, 'propvendor_show_email', (isset($_POST['propvendor_show_email']) && '1' === $_POST['propvendor_show_email']) ? '1' : '0');
    }
    $forward_enquiries = (isset($_POST['propvendor_forward_enquiries']) && '1' === $_POST['propvendor_forward_enquiries']) ? '1' : '0';
    update_user_meta($user_id, 'propvendor_forward_enquiries', $forward_enquiries);

    if (current_user_can('manage_options')) {
        if (isset($_POST['propvendor_lasrera_number'])) {
            update_user_meta($user_id, 'propvendor_lasrera_number', sanitize_text_field(wp_unslash($_POST['propvendor_lasrera_number'])));
        }
        if (isset($_POST['propvendor_agency_license'])) {
            update_user_meta($user_id, 'propvendor_agency_license', sanitize_text_field(wp_unslash($_POST['propvendor_agency_license'])));
        }
        if (isset($_POST['propvendor_regulatory_verified'])) {
            $reg_val = ('1' === (string) $_POST['propvendor_regulatory_verified']) ? '1' : '0';
            update_user_meta($user_id, 'propvendor_regulatory_verified', $reg_val);
        }
        if (isset($_POST['propvendor_vendor_plan'])) {
            $plan = sanitize_key(wp_unslash($_POST['propvendor_vendor_plan']));
            if (in_array($plan, array('free', 'premium', 'pro', 'unlocked'), true)) {
                update_user_meta($user_id, 'propvendor_vendor_plan', $plan);
            }
        }
        if (isset($_POST['propvendor_listing_plan'])) {
            $lplan = sanitize_key(wp_unslash($_POST['propvendor_listing_plan']));
            if (in_array($lplan, array('free', 'vip'), true)) {
                update_user_meta($user_id, 'propvendor_listing_plan', $lplan);
            }
        }
        if (isset($_POST['propvendor_plan_expires_at'])) {
            $exp_val = sanitize_text_field(wp_unslash($_POST['propvendor_plan_expires_at']));
            if ('' === $exp_val) {
                delete_user_meta($user_id, 'propvendor_plan_expires_at');
            } else {
                update_user_meta($user_id, 'propvendor_plan_expires_at', $exp_val);
            }
        }
    }
}
add_action('personal_options_update', 'propvendor_marketplace_save_admin_user_profile_fields');
add_action('edit_user_profile_update', 'propvendor_marketplace_save_admin_user_profile_fields');

function propvendor_marketplace_vendor_profile_shortcode($atts = array()) {
    $atts = shortcode_atts(array(
        'vendor' => '',
    ), $atts, 'propvendor_vendor_profile');

    $slug = !empty($atts['vendor']) ? sanitize_title($atts['vendor']) : '';
    if (empty($slug)) {
        $slug = get_query_var('propvendor_vendor');
    }
    if (empty($slug) && isset($_GET['vendor'])) {
        $slug = sanitize_title(wp_unslash($_GET['vendor']));
    }

    $vendor = null;
    if (!empty($slug)) {
        $vendor = propvendor_marketplace_get_vendor_by_slug($slug);
    } elseif (is_user_logged_in()) {
        $curr = wp_get_current_user();
        if (in_array('vendor', (array) $curr->roles, true) || in_array('administrator', (array) $curr->roles, true)) {
            $vendor = $curr;
        }
    }

    if (!$vendor) {
        if (!empty($slug)) {
            status_header(404);
            return '<div class="propvendor-profile-not-found"><div class="propvendor-empty-state"><h2>Vendor Profile Not Found</h2><p>The vendor profile you requested does not exist or is no longer active.</p><p><a class="propvendor-btn primary" href="' . esc_url(home_url('/browse-listings/')) . '">Browse Marketplace Listings &rarr;</a></p></div></div>';
        }

        return '<div class="propvendor-profile-hub"><div class="propvendor-hero-panel"><h2>PropVendor Marketplace Vendors</h2><p>Explore trusted property developers, real estate agents, land vendors, and automobile dealers across Nigeria.</p><div class="propvendor-actions"><a class="propvendor-btn primary" href="' . esc_url(home_url('/browse-listings/')) . '">Browse Listings</a><a class="propvendor-btn secondary" href="' . esc_url(home_url('/vendor-registration/')) . '">Register as a Vendor</a></div></div></div>';
    }

    $business_name = get_user_meta($vendor->ID, 'propvendor_business_name', true);
    $display_title = $business_name ? $business_name : $vendor->display_name;
    $business_type = get_user_meta($vendor->ID, 'propvendor_business_type', true);
    $location = get_user_meta($vendor->ID, 'propvendor_location', true);
    $bio = trim((string) $vendor->description);
    $show_phone = get_user_meta($vendor->ID, 'propvendor_show_phone', true);
    $show_email = get_user_meta($vendor->ID, 'propvendor_show_email', true);
    $phone = get_user_meta($vendor->ID, 'propvendor_phone_number', true);
    $public_email = get_user_meta($vendor->ID, 'propvendor_public_email', true);
    $img_id = absint(get_user_meta($vendor->ID, 'propvendor_profile_image_id', true));

    $is_owner = (get_current_user_id() === (int) $vendor->ID);

    $output = '<section class="propvendor-vendor-profile-container" aria-label="' . esc_attr($display_title . ' Profile') . '">';

    if ($is_owner) {
        $output .= '<div class="propvendor-profile-owner-banner"><span>You are viewing your public vendor profile.</span> <a class="propvendor-btn secondary propvendor-btn-sm" href="' . esc_url(home_url('/vendor-dashboard/?view=profile')) . '">Edit Profile Settings</a></div>';
    }

    $output .= '<div class="propvendor-vendor-profile-card">';
    $output .= '<div class="propvendor-vendor-header">';

    $output .= '<div class="propvendor-vendor-avatar-wrap">';
    if ($img_id) {
        $img_url = propvendor_marketplace_get_vendor_image_url($vendor->ID, $img_id);
        $output .= '<img src="' . esc_url($img_url) . '" alt="' . esc_attr($display_title) . '" class="propvendor-vendor-avatar" />';
    } else {
        $initial = strtoupper(mb_substr($display_title, 0, 1));
        $output .= '<div class="propvendor-vendor-monogram" aria-hidden="true">' . esc_html($initial) . '</div>';
    }
    $output .= '</div>';

    $output .= '<div class="propvendor-vendor-header-content">';
    $output .= '<div class="propvendor-vendor-title-row">';
    $output .= '<h1 class="propvendor-vendor-name">' . esc_html($display_title) . '</h1>';
    $output .= '<span class="propvendor-badge propvendor-badge-verified">Verified Vendor</span>';
    $regulatory_verified = get_user_meta($vendor->ID, 'propvendor_regulatory_verified', true);
    if ('1' === (string) $regulatory_verified) {
        $output .= '<span class="propvendor-badge propvendor-badge-regulatory-verified" style="background: #0b5394; color: #fff; font-weight: 600;">🛡️ State-Registered Professional (Verified License)</span>';
    }
    if (!empty($business_type)) {
        $output .= '<span class="propvendor-badge">' . esc_html($business_type) . '</span>';
    }
    $output .= '</div>';

    $output .= '<div class="propvendor-vendor-meta-row">';
    if (!empty($location)) {
        $output .= '<span class="propvendor-vendor-meta-item"><span class="dashicons dashicons-location"></span> ' . esc_html($location) . '</span>';
    }
    $output .= '<span class="propvendor-vendor-meta-item"><span class="dashicons dashicons-calendar-alt"></span> Member since ' . esc_html(date_i18n('F Y', strtotime($vendor->user_registered))) . '</span>';
    if ('1' === (string) $regulatory_verified) {
        $lasrera_num = get_user_meta($vendor->ID, 'propvendor_lasrera_number', true);
        $agency_lic = get_user_meta($vendor->ID, 'propvendor_agency_license', true);
        if (!empty($lasrera_num)) {
            $output .= '<span class="propvendor-vendor-meta-item"><span class="dashicons dashicons-shield"></span> LASRERA: ' . esc_html($lasrera_num) . '</span>';
        }
        if (!empty($agency_lic)) {
            $output .= '<span class="propvendor-vendor-meta-item"><span class="dashicons dashicons-shield"></span> License: ' . esc_html($agency_lic) . '</span>';
        }
    }
    $output .= '</div>';

    if (!empty($business_name) && !empty($vendor->display_name) && $business_name !== $vendor->display_name) {
        $output .= '<p class="propvendor-vendor-contact-person">Contact Representative: <strong>' . esc_html($vendor->display_name) . '</strong></p>';
    }

    $output .= '</div>';
    $output .= '</div>';

    if (!empty($bio)) {
        $output .= '<div class="propvendor-vendor-bio-box">';
        $output .= '<h3>About ' . esc_html($display_title) . '</h3>';
        $output .= '<div class="propvendor-vendor-bio-text">' . wp_kses_post(wpautop($bio)) . '</div>';
        $output .= '</div>';
    }

    $output .= '<div class="propvendor-vendor-contact-box">';
    $output .= '<h3>Contact Vendor</h3>';

    $has_contact = false;
    $output .= '<div class="propvendor-vendor-contact-actions">';
    if ('1' === (string) $show_phone && !empty($phone)) {
        $has_contact = true;
        $tel = preg_replace('/[^0-9+]/', '', $phone);
        if (!empty($tel)) {
            $output .= '<a class="propvendor-btn primary" href="' . esc_attr('tel:' . $tel) . '"><span class="dashicons dashicons-phone"></span> Call ' . esc_html($phone) . '</a>';
        } else {
            $output .= '<span class="propvendor-contact-text">Phone: ' . esc_html($phone) . '</span>';
        }
    }

    if ('1' === (string) $show_email && is_email($public_email)) {
        $has_contact = true;
        $output .= '<a class="propvendor-btn secondary" href="' . esc_url('mailto:' . $public_email) . '"><span class="dashicons dashicons-email"></span> Send Email</a>';
    }

    if (!$has_contact) {
        $output .= '<p class="propvendor-detail-muted">Contact details are kept private by this vendor. Please enquire through their verified listings below or use official marketplace channels.</p>';
    }
    $output .= '</div>';
    $output .= '</div>';

    $output .= '</div>';

    $vendor_listings = get_posts(array(
        'post_type' => propvendor_marketplace_get_listing_post_types(),
        'post_status' => 'publish',
        'author' => $vendor->ID,
        'posts_per_page' => 24,
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_query' => propvendor_marketplace_public_listing_meta_query(),
        'no_found_rows' => true,
    ));

    $output .= '<div class="propvendor-vendor-listings-wrap">';
    if (!empty($vendor_listings)) {
        $output .= propvendor_marketplace_render_listing_cards($vendor_listings, 'Listings by ' . $display_title . ' (' . count($vendor_listings) . ')');
    } else {
        $output .= '<div class="propvendor-listings-header"><h2>Listings by ' . esc_html($display_title) . '</h2></div>';
        $output .= '<p class="propvendor-empty">This vendor does not have any active public listings at this time.</p>';
    }
    $output .= '</div>';

    $output .= '</section>';

    return $output;
}
add_shortcode('propvendor_vendor_profile', 'propvendor_marketplace_vendor_profile_shortcode');

function propvendor_marketplace_filter_vendor_profile_title($title, $id = null) {
    if (!is_admin() && is_page('vendor-profile') && in_the_loop() && (int) $id === (int) get_queried_object_id()) {
        $slug = get_query_var('propvendor_vendor');
        if (empty($slug) && isset($_GET['vendor'])) {
            $slug = sanitize_title(wp_unslash($_GET['vendor']));
        }
        if (!empty($slug)) {
            $vendor = propvendor_marketplace_get_vendor_by_slug($slug);
            if ($vendor) {
                $b_name = get_user_meta($vendor->ID, 'propvendor_business_name', true);
                return $b_name ? $b_name : $vendor->display_name;
            }
        }
    }
    return $title;
}
add_filter('the_title', 'propvendor_marketplace_filter_vendor_profile_title', 10, 2);

function propvendor_marketplace_filter_vendor_document_title($title_parts) {
    if (is_page('vendor-profile')) {
        $slug = get_query_var('propvendor_vendor');
        if (empty($slug) && isset($_GET['vendor'])) {
            $slug = sanitize_title(wp_unslash($_GET['vendor']));
        }
        if (!empty($slug)) {
            $vendor = propvendor_marketplace_get_vendor_by_slug($slug);
            if ($vendor) {
                $b_name = get_user_meta($vendor->ID, 'propvendor_business_name', true);
                $title_parts['title'] = ($b_name ? $b_name : $vendor->display_name) . ' — Vendor Profile';
            }
        }
    }
    return $title_parts;
}
add_filter('document_title_parts', 'propvendor_marketplace_filter_vendor_document_title');

/**
 * =========================================================================
 * CUSTOMER ENQUIRY SYSTEM
 * =========================================================================
 */

/**
 * =========================================================================
 * STAGE 5: CREDIT LEDGER & MONETIZATION ENGINE SCHEMA
 * =========================================================================
 */

function propvendor_marketplace_create_credit_ledger_table() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_ledger    = $wpdb->prefix . 'propvendor_credit_ledger';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql_ledger = "CREATE TABLE {$table_ledger} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        vendor_id bigint(20) unsigned NOT NULL,
        amount int(11) NOT NULL,
        balance_after int(11) NOT NULL,
        transaction_type varchar(50) NOT NULL,
        reference varchar(100) DEFAULT '',
        description varchar(255) DEFAULT '',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY vendor_id (vendor_id),
        KEY transaction_type (transaction_type),
        KEY reference (reference),
        KEY vendor_ref (vendor_id, reference),
        KEY created_at (created_at)
    ) ENGINE=InnoDB {$charset_collate};";

    dbDelta($sql_ledger);

    // Ensure InnoDB engine for ACID transactions and row-level locking
    $wpdb->query("ALTER TABLE {$table_ledger} ENGINE = InnoDB");
}

/**
 * =========================================================================
 * PHASE 4B / STAGE 5B: PAYMENT GATEWAY & ORDER TRACKING SCHEMA
 * =========================================================================
 */

function propvendor_marketplace_create_payment_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_plans     = $wpdb->prefix . 'propvendor_plans';
    $table_orders    = $wpdb->prefix . 'propvendor_orders';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // 1. Plans Catalog Table
    $sql_plans = "CREATE TABLE {$table_plans} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        plan_key varchar(50) NOT NULL,
        name varchar(100) NOT NULL,
        product_type varchar(50) NOT NULL,
        price_kobo bigint(20) unsigned NOT NULL,
        credits int(11) NOT NULL DEFAULT 0,
        duration_days int(11) NOT NULL DEFAULT 0,
        description text DEFAULT NULL,
        is_active tinyint(1) NOT NULL DEFAULT 1,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY plan_key (plan_key),
        KEY product_type (product_type),
        KEY is_active (is_active)
    ) ENGINE=InnoDB {$charset_collate};";
    dbDelta($sql_plans);
    $wpdb->query("ALTER TABLE {$table_plans} ENGINE = InnoDB");

    // 2. Orders Table
    $sql_orders = "CREATE TABLE {$table_orders} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        vendor_id bigint(20) unsigned NOT NULL,
        plan_id bigint(20) unsigned NOT NULL,
        amount_kobo bigint(20) unsigned NOT NULL,
        transaction_reference varchar(100) NOT NULL,
        gateway varchar(50) NOT NULL DEFAULT 'paystack',
        status varchar(50) NOT NULL DEFAULT 'pending',
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY transaction_reference (transaction_reference),
        KEY vendor_id (vendor_id),
        KEY plan_id (plan_id),
        KEY status (status),
        KEY created_at (created_at)
    ) ENGINE=InnoDB {$charset_collate};";
    dbDelta($sql_orders);
    $wpdb->query("ALTER TABLE {$table_orders} ENGINE = InnoDB");

    // Seed default products
    propvendor_marketplace_seed_default_plans();
}

function propvendor_marketplace_seed_default_plans() {
    global $wpdb;
    $table_plans = $wpdb->prefix . 'propvendor_plans';

    $default_plans = array(
        array(
            'plan_key'      => 'vip_agent_monthly',
            'name'          => 'VIP Agent Monthly',
            'product_type'  => 'vip_agent',
            'price_kobo'    => 3000000, // ₦30,000.00
            'credits'       => 0,
            'duration_days' => 30,
            'description'   => 'Unlimited leads and contact unlocks for 30 days plus VIP badge on all listings.',
            'is_active'     => 1,
        ),
        array(
            'plan_key'      => 'credit_pack_starter',
            'name'          => 'Starter Pack (5 Leads)',
            'product_type'  => 'credit_pack',
            'price_kobo'    => 500000, // ₦5,000.00
            'credits'       => 5,
            'duration_days' => 0,
            'description'   => '5 Contact Unlocks (₦1,000 per lead). Credits never expire.',
            'is_active'     => 1,
        ),
        array(
            'plan_key'      => 'credit_pack_growth',
            'name'          => 'Growth Pack (12 Leads)',
            'product_type'  => 'credit_pack',
            'price_kobo'    => 1000000, // ₦10,000.00
            'credits'       => 12,
            'duration_days' => 0,
            'description'   => '12 Contact Unlocks (₦833 per lead). Best value for active agents. Credits never expire.',
            'is_active'     => 1,
        ),
        array(
            'plan_key'      => 'credit_pack_pro',
            'name'          => 'Pro Pack (28 Leads)',
            'product_type'  => 'credit_pack',
            'price_kobo'    => 2000000, // ₦20,000.00
            'credits'       => 28,
            'duration_days' => 0,
            'description'   => '28 Contact Unlocks (₦714 per lead). Highest volume discount. Credits never expire.',
            'is_active'     => 1,
        ),
    );

    foreach ($default_plans as $p) {
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$table_plans} WHERE plan_key = %s", $p['plan_key']));
        if ($existing) {
            $wpdb->update(
                $table_plans,
                array(
                    'name'          => $p['name'],
                    'product_type'  => $p['product_type'],
                    'price_kobo'    => $p['price_kobo'],
                    'credits'       => $p['credits'],
                    'duration_days' => $p['duration_days'],
                    'description'   => $p['description'],
                    'is_active'     => $p['is_active'],
                ),
                array('id' => $existing->id),
                array('%s', '%s', '%d', '%d', '%d', '%s', '%d'),
                array('%d')
            );
        } else {
            $wpdb->insert(
                $table_plans,
                array(
                    'plan_key'      => $p['plan_key'],
                    'name'          => $p['name'],
                    'product_type'  => $p['product_type'],
                    'price_kobo'    => $p['price_kobo'],
                    'credits'       => $p['credits'],
                    'duration_days' => $p['duration_days'],
                    'description'   => $p['description'],
                    'is_active'     => $p['is_active'],
                    'created_at'    => current_time('mysql'),
                ),
                array('%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s')
            );
        }
    }
}

function propvendor_marketplace_create_enquiries_table() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $table_enquiries = $wpdb->prefix . 'propvendor_enquiries';
    $table_threads   = $wpdb->prefix . 'propvendor_threads';
    $table_messages  = $wpdb->prefix . 'propvendor_messages';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // 1. Enquiries Table Schema (Fix 4: includes customer_user_id after vendor_id)
    $sql_enquiries = "CREATE TABLE {$table_enquiries} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        listing_id bigint(20) unsigned NOT NULL,
        vendor_id bigint(20) unsigned NOT NULL,
        customer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        customer_name varchar(255) NOT NULL,
        customer_email varchar(255) NOT NULL,
        customer_phone varchar(50) DEFAULT '',
        message text NOT NULL,
        vendor_email_sent tinyint(1) NOT NULL DEFAULT 0,
        vendor_email_address varchar(255) DEFAULT '',
        ip_address varchar(45) DEFAULT '',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY listing_id (listing_id),
        KEY vendor_id (vendor_id),
        KEY customer_user_id (customer_user_id),
        KEY created_at (created_at)
    ) {$charset_collate};";
    dbDelta($sql_enquiries);

    // Fallback: Ensure customer_user_id column exists on existing table if dbDelta did not alter
    $col_exists = $wpdb->get_results($wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'customer_user_id'",
        DB_NAME,
        $table_enquiries
    ));
    if (empty($col_exists)) {
        $wpdb->query("ALTER TABLE {$table_enquiries} ADD COLUMN customer_user_id bigint(20) unsigned NOT NULL DEFAULT 0 AFTER vendor_id, ADD KEY customer_user_id (customer_user_id)");
    }

    // 2. Threads Table Schema (Fix 5)
    $sql_threads = "CREATE TABLE {$table_threads} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        listing_id bigint(20) unsigned NOT NULL,
        vendor_id bigint(20) unsigned NOT NULL,
        customer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        customer_name varchar(255) NOT NULL,
        customer_email varchar(255) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'open',
        last_message_at datetime NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY listing_id (listing_id),
        KEY vendor_id (vendor_id),
        KEY customer_user_id (customer_user_id),
        KEY last_message_at (last_message_at)
    ) {$charset_collate};";
    dbDelta($sql_threads);

    // 3. Messages Table Schema (Fix 5)
    $sql_messages = "CREATE TABLE {$table_messages} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        thread_id bigint(20) unsigned NOT NULL,
        sender_id bigint(20) unsigned NOT NULL DEFAULT 0,
        sender_role varchar(20) NOT NULL,
        message text NOT NULL,
        is_read tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY thread_id (thread_id),
        KEY thread_read (thread_id, is_read),
        KEY created_at (created_at)
    ) {$charset_collate};";
    dbDelta($sql_messages);

    // 4. Credit Ledger Table Schema (Stage 5)
    propvendor_marketplace_create_credit_ledger_table();

    // 5. Payment & Order Schema (Phase 4B)
    propvendor_marketplace_create_payment_tables();

    // 6. Backfill existing enquiries (Fix 6)
    propvendor_marketplace_backfill_enquiries();
}

function propvendor_marketplace_backfill_enquiries() {
    global $wpdb;
    $table_enquiries = $wpdb->prefix . 'propvendor_enquiries';
    $table_threads   = $wpdb->prefix . 'propvendor_threads';
    $table_messages  = $wpdb->prefix . 'propvendor_messages';

    if (get_option('propvendor_enquiries_backfilled_v2')) {
        return;
    }

    // Ensure tables exist before running
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_threads)) !== $table_threads ||
        $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_messages)) !== $table_messages) {
        return;
    }

    $enquiries = $wpdb->get_results("SELECT * FROM {$table_enquiries} ORDER BY id ASC");
    if (!empty($enquiries)) {
        foreach ($enquiries as $enq) {
            // Determine customer_user_id: strictly respect authentic session customer_user_id.
            // Never infer account ownership from guest-submitted email strings.
            // Explicitly prevent self-conversations where customer_user_id equals vendor_id.
            $customer_user_id = 0;
            if (isset($enq->customer_user_id) && absint($enq->customer_user_id) > 0) {
                $candidate_id = absint($enq->customer_user_id);
                if ($candidate_id !== absint($enq->vendor_id)) {
                    $customer_user_id = $candidate_id;
                } else {
                    // Invalid self-conversation attribution: reset enquiry record to guest (0)
                    $wpdb->update(
                        $table_enquiries,
                        array('customer_user_id' => 0),
                        array('id' => $enq->id),
                        array('%d'),
                        array('%d')
                    );
                }
            }

            // Check if thread already exists for this enquiry
            $existing_thread_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_threads} WHERE listing_id = %d AND customer_email = %s AND created_at = %s LIMIT 1",
                $enq->listing_id,
                $enq->customer_email,
                $enq->created_at
            ));

            if (!$existing_thread_id) {
                $wpdb->insert(
                    $table_threads,
                    array(
                        'listing_id'       => $enq->listing_id,
                        'vendor_id'        => $enq->vendor_id,
                        'customer_user_id' => $customer_user_id,
                        'customer_name'    => $enq->customer_name,
                        'customer_email'   => $enq->customer_email,
                        'status'           => 'open',
                        'last_message_at'  => $enq->created_at,
                        'created_at'       => $enq->created_at,
                    ),
                    array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
                );
                $existing_thread_id = $wpdb->insert_id;
            } else {
                // Ensure existing thread does not have invalid self-conversation attribution
                $existing_thread = $wpdb->get_row($wpdb->prepare("SELECT id, vendor_id, customer_user_id FROM {$table_threads} WHERE id = %d", $existing_thread_id));
                if ($existing_thread && absint($existing_thread->customer_user_id) > 0 && absint($existing_thread->customer_user_id) === absint($existing_thread->vendor_id)) {
                    $wpdb->update($table_threads, array('customer_user_id' => 0), array('id' => $existing_thread_id), array('%d'), array('%d'));
                    $wpdb->update($table_messages, array('sender_id' => 0), array('thread_id' => $existing_thread_id, 'sender_role' => 'customer'), array('%d'), array('%d', '%s'));
                }
            }

            if ($existing_thread_id) {
                // Check if first message already exists
                $existing_msg = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table_messages} WHERE thread_id = %d AND sender_role = 'customer' AND created_at = %s LIMIT 1",
                    $existing_thread_id,
                    $enq->created_at
                ));

                if (!$existing_msg) {
                    $wpdb->insert(
                        $table_messages,
                        array(
                            'thread_id'   => $existing_thread_id,
                            'sender_id'   => $customer_user_id,
                            'sender_role' => 'customer',
                            'message'     => $enq->message,
                            'is_read'     => 0,
                            'created_at'  => $enq->created_at,
                        ),
                        array('%d', '%d', '%s', '%s', '%d', '%s')
                    );
                }
            }
        }
    }

    update_option('propvendor_enquiries_backfilled_v2', 1);
}

function propvendor_marketplace_maybe_create_enquiries_table() {
    global $wpdb;
    $table_enquiries = $wpdb->prefix . 'propvendor_enquiries';
    $table_threads   = $wpdb->prefix . 'propvendor_threads';
    $table_messages  = $wpdb->prefix . 'propvendor_messages';
    $table_ledger    = $wpdb->prefix . 'propvendor_credit_ledger';
    $table_plans     = $wpdb->prefix . 'propvendor_plans';
    $table_orders    = $wpdb->prefix . 'propvendor_orders';

    $schema_version = get_option('propvendor_enquiry_schema_version', '1.0');

    if (
        version_compare((string) $schema_version, '1.4', '<') ||
        $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_enquiries)) !== $table_enquiries ||
        $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_threads)) !== $table_threads ||
        $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_messages)) !== $table_messages ||
        $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_ledger)) !== $table_ledger ||
        $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_plans)) !== $table_plans ||
        $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_orders)) !== $table_orders
    ) {
        propvendor_marketplace_create_enquiries_table();
        propvendor_marketplace_create_payment_tables();
        update_option('propvendor_enquiry_schema_version', '1.4');
    }
}
add_action('init', 'propvendor_marketplace_maybe_create_enquiries_table');

function propvendor_marketplace_render_listing_enquiry_form($post) {
    if (!$post instanceof WP_Post || !propvendor_marketplace_is_public_listing($post)) {
        return '';
    }

    $listing_id = $post->ID;
    $listing_title = get_the_title($post);

    $current_user = wp_get_current_user();
    if ($current_user->exists() && (int) $post->post_author === (int) $current_user->ID) {
        $html = '<section class="propvendor-detail-enquiry propvendor-enquiry-box" id="propvendor-enquiry-form-wrap" aria-labelledby="propvendor-enquiry-heading">';
        $html .= '<h2 id="propvendor-enquiry-heading">Listing Enquiries</h2>';
        $html .= '<p class="propvendor-enquiry-intro" style="color:#64748b; font-style:italic;">You are the author of this listing. Customer enquiries submitted for this property are forwarded to your configured email.</p>';
        $html .= '</section>';
        return $html;
    }

    $default_name = $current_user->exists() ? $current_user->display_name : '';
    $default_email = $current_user->exists() ? $current_user->user_email : '';

    $notice = propvendor_marketplace_get_notice_message();

    $html = '<section class="propvendor-detail-enquiry propvendor-enquiry-box" id="propvendor-enquiry-form-wrap" aria-labelledby="propvendor-enquiry-heading">';
    $html .= '<h2 id="propvendor-enquiry-heading">Send an Enquiry</h2>';
    $html .= '<p class="propvendor-enquiry-intro">Interested in this listing? Send a direct enquiry to request more details or arrange an inspection.</p>';

    if (!empty($notice)) {
        $html .= $notice;
    }

    $html .= '<form class="propvendor-form propvendor-enquiry-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    $html .= wp_nonce_field('propvendor_submit_enquiry_' . $listing_id, 'propvendor_enquiry_nonce', true, false);
    $html .= '<input type="hidden" name="action" value="propvendor_submit_enquiry" />';
    $html .= '<input type="hidden" name="listing_id" value="' . absint($listing_id) . '" />';

    // Honeypot field (hidden from legitimate users, catches spam bots)
    $html .= '<div class="propvendor-hp" style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">';
    $html .= '<label for="propvendor_company_hp_' . absint($listing_id) . '">Company (Leave Blank)</label>';
    $html .= '<input type="text" id="propvendor_company_hp_' . absint($listing_id) . '" name="propvendor_enquiry_company" tabindex="-1" autocomplete="off" value="" />';
    $html .= '</div>';

    // Listing Reference Context
    $html .= '<div class="propvendor-enquiry-listing-meta">';
    $html .= '<span>Enquiring about:</span> <strong>' . esc_html($listing_title) . '</strong> <span class="propvendor-enquiry-ref">(Ref: #' . absint($listing_id) . ')</span>';
    $html .= '</div>';

    // Customer Name (required)
    $html .= '<label for="pv_customer_name_' . absint($listing_id) . '">Your Name <span class="propvendor-required">*</span>';
    $html .= '<input type="text" id="pv_customer_name_' . absint($listing_id) . '" name="customer_name" value="' . esc_attr($default_name) . '" required placeholder="Full name" maxlength="100" />';
    $html .= '</label>';

    // Customer Email (required)
    $html .= '<label for="pv_customer_email_' . absint($listing_id) . '">Your Email <span class="propvendor-required">*</span>';
    $html .= '<input type="email" id="pv_customer_email_' . absint($listing_id) . '" name="customer_email" value="' . esc_attr($default_email) . '" required placeholder="email@example.com" maxlength="100" />';
    $html .= '</label>';

    // Customer Phone (optional)
    $html .= '<label for="pv_customer_phone_' . absint($listing_id) . '">Phone Number <span class="propvendor-optional">(optional)</span>';
    $html .= '<input type="tel" id="pv_customer_phone_' . absint($listing_id) . '" name="customer_phone" placeholder="e.g. 08012345678" maxlength="30" />';
    $html .= '</label>';

    // Message (required)
    $html .= '<label for="pv_customer_msg_' . absint($listing_id) . '">Message <span class="propvendor-required">*</span>';
    $html .= '<textarea id="pv_customer_msg_' . absint($listing_id) . '" name="customer_message" rows="4" required placeholder="I am interested in this listing. Please provide more information or schedule an inspection."></textarea>';
    $html .= '</label>';

    $html .= '<button type="submit" class="propvendor-btn primary propvendor-enquiry-submit">Send Enquiry</button>';
    $html .= '<p class="propvendor-enquiry-privacy-note">Your enquiry is transmitted securely in compliance with marketplace privacy standards.</p>';

    $html .= '</form>';
    $html .= '</section>';

    return $html;
}

function propvendor_marketplace_handle_enquiry_submission() {
    // FIX 2: Explicit POST method guard
    if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
        wp_die( 'Method Not Allowed', '', array( 'response' => 405 ) );
    }

    $listing_id = isset($_POST['listing_id']) ? absint($_POST['listing_id']) : 0;
    $listing = get_post($listing_id);
    $is_public = ($listing && propvendor_marketplace_is_public_listing($listing));
    $redirect_url = $is_public ? (get_permalink($listing) . '#propvendor-enquiry-form-wrap') : home_url('/browse-listings/');

    // 1. Verify listing is public & approved (Requirement 5)
    if (!$is_public) {
        propvendor_marketplace_redirect_with_notice('/browse-listings/', 'error', 'Enquiries are only accepted for active, approved marketplace listings.');
    }

    // 2. CSRF & Cross-listing nonce check (Requirement 4)
    if (!isset($_POST['propvendor_enquiry_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['propvendor_enquiry_nonce'])), 'propvendor_submit_enquiry_' . $listing_id)) {
        propvendor_marketplace_redirect_with_notice($redirect_url, 'error', 'Security verification failed. Please reload the page and try again.');
    }

    // 3. Spam honeypot (Requirement 10)
    if (!empty($_POST['propvendor_enquiry_company'])) {
        propvendor_marketplace_redirect_with_notice($redirect_url, 'error', 'Spam protection triggered. Submission rejected.');
    }

    // 4. Rate limiting (Requirement 10)
    $ip_address = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
    $ip_key = 'pv_rate_' . md5($ip_address);
    $recent_count = (int) get_transient($ip_key);
    if ($recent_count >= 5) {
        propvendor_marketplace_redirect_with_notice($redirect_url, 'error', 'You have submitted multiple enquiries recently. Please wait a few minutes before submitting another.');
    }

    // 5. Validation and Sanitization (Requirements 2, 3)
    $customer_name = sanitize_text_field(wp_unslash($_POST['customer_name'] ?? ''));
    $customer_email = sanitize_email(wp_unslash($_POST['customer_email'] ?? ''));
    $customer_phone = sanitize_text_field(wp_unslash($_POST['customer_phone'] ?? ''));
    $message = sanitize_textarea_field(wp_unslash($_POST['customer_message'] ?? ''));

    $name_validation = propvendor_marketplace_validate_customer_name($customer_name);
    if (is_wp_error($name_validation)) {
        propvendor_marketplace_redirect_with_notice($redirect_url, 'error', $name_validation->get_error_message());
    }

    if (empty($customer_email) || !is_email($customer_email)) {
        propvendor_marketplace_redirect_with_notice($redirect_url, 'error', 'Please provide a valid email address.');
    }

    if (!empty($customer_phone)) {
        $customer_phone = preg_replace('/[^\d\+\-\(\)\s]/', '', $customer_phone);
        if (mb_strlen($customer_phone) < 7 || mb_strlen($customer_phone) > 25) {
            propvendor_marketplace_redirect_with_notice($redirect_url, 'error', 'Please provide a valid phone number or leave the field blank.');
        }
    }

    if (empty($message) || mb_strlen($message) < 5) {
        propvendor_marketplace_redirect_with_notice($redirect_url, 'error', 'Please enter a message of at least 5 characters.');
    }

    // 6. Duplicate prevention (Requirement 9)
    $dup_hash = md5($listing_id . '|' . strtolower($customer_email) . '|' . trim($message));
    $dup_key = 'pv_dup_' . $dup_hash;
    if (get_transient($dup_key)) {
        propvendor_marketplace_redirect_with_notice($redirect_url, 'error', 'A matching enquiry was already received recently. Please avoid duplicate submissions.');
    }

    // Set transients
    set_transient($ip_key, $recent_count + 1, 10 * MINUTE_IN_SECONDS);
    set_transient($dup_key, 1, 2 * MINUTE_IN_SECONDS);

    // 7. Store enquiry securely (Requirements 8, 11, 12, Fix 1, Fix 4)
    global $wpdb;
    $table_name = $wpdb->prefix . 'propvendor_enquiries';
    $vendor_id = absint($listing->post_author);
    $customer_user_id = get_current_user_id();

    // Prevent invalid self-conversations where vendor submits enquiry on their own listing
    if ($customer_user_id > 0 && $customer_user_id === $vendor_id) {
        propvendor_marketplace_redirect_with_notice($redirect_url, 'error', 'You cannot submit an enquiry on your own listing.');
    }

    // 8. Vendor notification logic (Requirements 6, 7)
    $forward_enquiries = get_user_meta($vendor_id, 'propvendor_forward_enquiries', true);
    $public_email = get_user_meta($vendor_id, 'propvendor_public_email', true);
    $show_email = get_user_meta($vendor_id, 'propvendor_show_email', true);

    // Forwarding is enabled if explicitly '1', or backwards-compatible if show_email is '1' and forward_enquiries is not explicitly '0'
    $is_forwarding_enabled = ('1' === (string) $forward_enquiries) || ('' === (string) $forward_enquiries && '1' === (string) $show_email);

    $vendor_obj = get_user_by('id', $vendor_id);
    $target_email = '';
    if (!empty($public_email) && is_email($public_email)) {
        $target_email = sanitize_email($public_email);
    } elseif ($vendor_obj && is_email($vendor_obj->user_email)) {
        $target_email = sanitize_email($vendor_obj->user_email);
    }

    $can_forward_vendor_email = ($is_forwarding_enabled && !empty($target_email));
    $vendor_recipient = $can_forward_vendor_email ? $target_email : '';

    // Fix 1 & Fix 4: Insert into database first and verify return value before notifications or success messaging
    $inserted = $wpdb->insert(
        $table_name,
        array(
            'listing_id' => $listing_id,
            'vendor_id' => $vendor_id,
            'customer_user_id' => $customer_user_id,
            'customer_name' => $customer_name,
            'customer_email' => $customer_email,
            'customer_phone' => $customer_phone,
            'message' => $message,
            'vendor_email_sent' => 0,
            'vendor_email_address' => $vendor_recipient,
            'ip_address' => $ip_address,
            'created_at' => current_time('mysql'),
        ),
        array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
    );

    if (false === $inserted) {
        error_log($wpdb->last_error);
        propvendor_marketplace_redirect_with_notice($redirect_url, 'error', 'Unable to record your enquiry due to a temporary system error. Please try again.');
    }

    $enquiry_id = $wpdb->insert_id;
    $vendor_email_sent = 0;

    // Phase 3: Create conversation thread and initial message
    $table_threads  = $wpdb->prefix . 'propvendor_threads';
    $table_messages = $wpdb->prefix . 'propvendor_messages';
    $now_mysql = current_time('mysql');
    $thread_id = 0;

    $thread_inserted = $wpdb->insert(
        $table_threads,
        array(
            'listing_id'       => $listing_id,
            'vendor_id'        => $vendor_id,
            'customer_user_id' => $customer_user_id,
            'customer_name'    => $customer_name,
            'customer_email'   => $customer_email,
            'status'           => 'open',
            'last_message_at'  => $now_mysql,
            'created_at'       => $now_mysql,
        ),
        array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
    );

    if (false !== $thread_inserted) {
        $thread_id = $wpdb->insert_id;
        $wpdb->insert(
            $table_messages,
            array(
                'thread_id'   => $thread_id,
                'sender_id'   => $customer_user_id,
                'sender_role' => 'customer',
                'message'     => $message,
                'is_read'     => 0,
                'created_at'  => $now_mysql,
            ),
            array('%d', '%d', '%s', '%s', '%d', '%s')
        );
    }

    // Send vendor notification ONLY after the enquiry has been successfully saved in the database
    if ($can_forward_vendor_email) {
        // Remediate Finding 1: Evaluate vendor entitlement for lead contact details
        $can_view_contact = propvendor_marketplace_vendor_can_view_contact_info($vendor_id, $thread_id);

        $subject = sprintf('[PropVendor Enquiry] %s (Ref: #%d)', get_the_title($listing), $listing_id);
        $email_body = "Hello,\n\nYou have received a new customer enquiry for your listing on PropVendor.\n\n";
        $email_body .= "Listing: " . get_the_title($listing) . "\n";
        $email_body .= "URL: " . get_permalink($listing) . "\n\n";
        $email_body .= "Customer Details:\n";
        $email_body .= "Name: " . $customer_name . "\n";

        if ($can_view_contact) {
            $email_body .= "Email: " . $customer_email . "\n";
            $email_body .= "Phone: " . (!empty($customer_phone) ? $customer_phone : "Not provided") . "\n\n";
        } else {
            $masked_email = propvendor_marketplace_mask_email($customer_email);
            if (empty($masked_email)) {
                $masked_email = '[Protected Email]';
            }
            $masked_phone = !empty($customer_phone) ? propvendor_marketplace_mask_phone($customer_phone) : "Not provided";

            $email_body .= "Email: " . $masked_email . " [Locked Lead]\n";
            $email_body .= "Phone: " . $masked_phone . (!empty($customer_phone) ? " [Locked Lead]" : "") . "\n\n";
            $email_body .= "Note: Direct customer contact details are locked. To reveal this customer's full phone number and email address, upgrade to PropVendor Premium or unlock this lead in your Vendor Dashboard:\n";
            $email_body .= home_url('/vendor-dashboard/?view=inbox' . (!empty($thread_id) ? '&thread_id=' . $thread_id : '')) . "\n\n";
        }

        $display_message = $can_view_contact ? $message : propvendor_marketplace_mask_message_contact_info($message);
        $email_body .= "Message:\n" . $display_message . "\n\n";
        if (!empty($thread_id)) {
            $email_body .= "View and respond via Vendor Dashboard:\n";
            $email_body .= home_url('/vendor-dashboard/?view=inbox&thread_id=' . $thread_id) . "\n\n";
        }
        $email_body .= "Regards,\nPropVendor Marketplace\n" . home_url();

        $headers = array(
            'From: PropVendor Marketplace <no-reply@' . wp_parse_url(home_url(), PHP_URL_HOST) . '>',
        );

        if ($can_view_contact) {
            $headers[] = 'Reply-To: ' . $customer_name . ' <' . $customer_email . '>';
        } else {
            $headers[] = 'Reply-To: PropVendor Marketplace <no-reply@' . wp_parse_url(home_url(), PHP_URL_HOST) . '>';
        }

        $sent = wp_mail($vendor_recipient, $subject, $email_body, $headers);
        if ($sent) {
            $vendor_email_sent = 1;
            $wpdb->update(
                $table_name,
                array('vendor_email_sent' => 1),
                array('id' => $enquiry_id),
                array('%d'),
                array('%d')
            );
        }
    }

    if ($can_forward_vendor_email) {
        $success_msg = 'Thank you! Your enquiry has been received and forwarded to the vendor.';
    } else {
        $success_msg = 'Thank you! Your enquiry has been recorded and submitted for administrator review. (Note: The vendor has not enabled direct email forwarding).';
    }

    propvendor_marketplace_redirect_with_notice($redirect_url, 'success', $success_msg);
}
add_action('admin_post_nopriv_propvendor_submit_enquiry', 'propvendor_marketplace_handle_enquiry_submission');
add_action('admin_post_propvendor_submit_enquiry', 'propvendor_marketplace_handle_enquiry_submission');

function propvendor_marketplace_handle_admin_delete_enquiry() {
    // Explicit HTTP POST guard
    if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
        wp_die( 'Method Not Allowed', '', array( 'response' => 405 ) );
    }

    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }

    $del_id = isset($_POST['enquiry_id']) ? absint($_POST['enquiry_id']) : 0;
    check_admin_referer('propvendor_delete_enquiry_' . $del_id);

    $paged = isset($_POST['paged']) ? max(1, absint($_POST['paged'])) : 1;
    $redirect_url = admin_url('admin.php?page=propvendor-enquiries' . ($paged > 1 ? '&paged=' . $paged : ''));

    global $wpdb;
    $table_enquiries = $wpdb->prefix . 'propvendor_enquiries';
    $table_threads   = $wpdb->prefix . 'propvendor_threads';
    $table_messages  = $wpdb->prefix . 'propvendor_messages';

    // 1. Fetch enquiry record
    $enquiry = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_enquiries} WHERE id = %d", $del_id));
    if (!$enquiry) {
        $redirect_url = add_query_arg(array('propvendor_notice' => 'delete_failed', 'enquiry_id' => $del_id), $redirect_url);
        wp_safe_redirect($redirect_url);
        exit;
    }

    // Finding 6: Cascading Enquiry Deletion
    // Step 1: Find the matching thread in wp_propvendor_threads
    $thread = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_threads} WHERE listing_id = %d AND customer_email = %s AND created_at = %s LIMIT 1",
        $enquiry->listing_id,
        $enquiry->customer_email,
        $enquiry->created_at
    ));
    if (!$thread) {
        $thread = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_threads} WHERE listing_id = %d AND customer_email = %s ORDER BY ABS(TIMESTAMPDIFF(SECOND, created_at, %s)) ASC LIMIT 1",
            $enquiry->listing_id,
            $enquiry->customer_email,
            $enquiry->created_at
        ));
    }

    if ($thread) {
        // Step 2: Delete all child messages from wp_propvendor_messages where thread_id = $thread->id
        $wpdb->delete($table_messages, array('thread_id' => $thread->id), array('%d'));

        // Step 3: Delete the thread from wp_propvendor_threads
        $wpdb->delete($table_threads, array('id' => $thread->id), array('%d'));
    }

    // Step 4: Delete the primary row from wp_propvendor_enquiries
    $deleted = $wpdb->delete($table_enquiries, array('id' => $del_id), array('%d'));

    if (false !== $deleted && $deleted > 0) {
        $redirect_url = add_query_arg(array('propvendor_notice' => 'enquiry_deleted', 'enquiry_id' => $del_id), $redirect_url);
    } else {
        $redirect_url = add_query_arg(array('propvendor_notice' => 'delete_failed', 'enquiry_id' => $del_id), $redirect_url);
    }

    wp_safe_redirect($redirect_url);
    exit;
}
add_action('admin_post_propvendor_delete_enquiry', 'propvendor_marketplace_handle_admin_delete_enquiry');

function propvendor_marketplace_add_admin_menu() {
    add_menu_page(
        'Marketplace Enquiries',
        'Enquiries',
        'manage_options',
        'propvendor-enquiries',
        'propvendor_marketplace_render_admin_enquiries_page',
        'dashicons-email-alt',
        26
    );
    add_submenu_page(
        'propvendor-enquiries',
        'Enquiries',
        'All Enquiries',
        'manage_options',
        'propvendor-enquiries',
        'propvendor_marketplace_render_admin_enquiries_page'
    );
    add_submenu_page(
        'propvendor-enquiries',
        'Payment Settings',
        'Payment Settings',
        'manage_options',
        'propvendor-payments',
        'propvendor_marketplace_render_admin_payments_page'
    );
}
add_action('admin_menu', 'propvendor_marketplace_add_admin_menu');

function propvendor_marketplace_render_admin_enquiries_page() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'propvendor_enquiries';

    // Display admin notices from redirect actions (Fix 3)
    if (isset($_GET['propvendor_notice'])) {
        $notice_code = sanitize_text_field(wp_unslash($_GET['propvendor_notice']));
        $notice_id = isset($_GET['enquiry_id']) ? absint($_GET['enquiry_id']) : 0;
        if ('enquiry_deleted' === $notice_code) {
            echo '<div class="notice notice-success is-dismissible"><p>Enquiry #' . absint($notice_id) . ' deleted successfully.</p></div>';
        } elseif ('delete_failed' === $notice_code) {
            echo '<div class="notice notice-error is-dismissible"><p>Could not delete enquiry #' . absint($notice_id) . '. Record may have already been removed.</p></div>';
        }
    }

    $per_page = 20;
    $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $offset = ($paged - 1) * $per_page;

    $total_items = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    $enquiries = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ));

    $total_pages = ceil($total_items / $per_page);

    echo '<div class="wrap">';
    echo '<h1>Marketplace Customer Enquiries (' . absint($total_items) . ')</h1>';
    echo '<p>Review customer leads and enquiry messages submitted for properties, land, and vehicle listings.</p>';

    if (empty($enquiries)) {
        echo '<div class="notice notice-info"><p>No customer enquiries have been recorded yet.</p></div>';
        echo '</div>';
        return;
    }

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th style="width: 50px;">ID</th>';
    echo '<th style="width: 140px;">Date</th>';
    echo '<th>Customer</th>';
    echo '<th>Listing</th>';
    echo '<th>Vendor</th>';
    echo '<th>Vendor Email Status</th>';
    echo '<th>Message</th>';
    echo '<th style="width: 90px;">Actions</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ($enquiries as $enq) {
        $listing = get_post($enq->listing_id);
        $listing_title = $listing ? get_the_title($listing) : ('Listing #' . absint($enq->listing_id) . ' (Deleted)');
        $listing_link = $listing ? get_permalink($listing) : '#';

        $vendor = get_user_by('id', $enq->vendor_id);
        $vendor_biz = $vendor ? get_user_meta($vendor->ID, 'propvendor_business_name', true) : '';
        $vendor_name = $vendor_biz ? $vendor_biz : ($vendor ? $vendor->display_name : 'Unknown');

        $form_action = admin_url('admin-post.php');

        if ($enq->vendor_email_sent) {
            $status_label = '<span style="color:#0a8a4f; font-weight:600;">&#10003; Sent (' . esc_html($enq->vendor_email_address) . ')</span>';
        } elseif (!empty($enq->vendor_email_address)) {
            $status_label = '<span style="color:#b7791f; font-weight:600;">Delivery failed / local (' . esc_html($enq->vendor_email_address) . ')</span>';
        } else {
            $status_label = '<span style="color:#64748b; font-style:italic;">Not forwarded (No public email configured)</span>';
        }

        echo '<tr>';
        echo '<td>#' . absint($enq->id) . '</td>';
        echo '<td>' . esc_html(date_i18n('M j, Y g:i a', strtotime($enq->created_at))) . '</td>';
        echo '<td><strong>' . esc_html($enq->customer_name) . '</strong><br>';
        echo '<a href="mailto:' . esc_attr($enq->customer_email) . '">' . esc_html($enq->customer_email) . '</a>';
        if (!empty($enq->customer_phone)) {
            echo '<br><a href="tel:' . esc_attr($enq->customer_phone) . '">' . esc_html($enq->customer_phone) . '</a>';
        }
        if (!empty($enq->customer_user_id)) {
            echo '<br><small style="color:#64748b;">User #' . absint($enq->customer_user_id) . '</small>';
        }
        echo '</td>';
        echo '<td><a href="' . esc_url($listing_link) . '" target="_blank">' . esc_html($listing_title) . '</a><br><small>Ref #' . absint($enq->listing_id) . '</small></td>';
        echo '<td>' . esc_html($vendor_name) . '</td>';
        echo '<td>' . $status_label . '</td>';
        echo '<td><div style="max-height: 80px; overflow-y: auto; background: #f8fafc; padding: 6px; border-radius: 4px; border: 1px solid #e2e8f0; font-size: 13px;">' . nl2br(esc_html($enq->message)) . '</div></td>';
        echo '<td>';
        echo '<form method="post" action="' . esc_url($form_action) . '" onsubmit="return confirm(\'Permanently delete enquiry #' . absint($enq->id) . '?\');" style="margin:0;">';
        wp_nonce_field('propvendor_delete_enquiry_' . absint($enq->id));
        echo '<input type="hidden" name="action" value="propvendor_delete_enquiry" />';
        echo '<input type="hidden" name="enquiry_id" value="' . absint($enq->id) . '" />';
        if ($paged > 1) {
            echo '<input type="hidden" name="paged" value="' . absint($paged) . '" />';
        }
        echo '<button type="submit" class="button button-small delete" style="color:#b32d2e; border-color:#b32d2e;">Delete</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    if ($total_pages > 1) {
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo paginate_links(array(
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'total' => $total_pages,
            'current' => $paged,
        ));
        echo '</div></div>';
    }

    echo '</div>';
}

function propvendor_marketplace_render_admin_payments_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'propvendor-marketplace'));
    }

    $saved_notice = false;

    // Handle form submit
    if (isset($_POST['propvendor_save_payment_settings'])) {
        check_admin_referer('propvendor_payment_settings_verify', 'propvendor_payment_settings_nonce');

        $mode = isset($_POST['propvendor_paystack_mode']) ? sanitize_key($_POST['propvendor_paystack_mode']) : 'test';
        if (!in_array($mode, array('test', 'live'), true)) {
            $mode = 'test';
        }
        update_option('propvendor_paystack_mode', $mode);

        if (isset($_POST['propvendor_paystack_test_public_key'])) {
            update_option('propvendor_paystack_test_public_key', sanitize_text_field(wp_unslash($_POST['propvendor_paystack_test_public_key'])));
        }
        if (isset($_POST['propvendor_paystack_test_secret_key'])) {
            update_option('propvendor_paystack_test_secret_key', sanitize_text_field(wp_unslash($_POST['propvendor_paystack_test_secret_key'])));
        }
        if (isset($_POST['propvendor_paystack_live_public_key'])) {
            update_option('propvendor_paystack_live_public_key', sanitize_text_field(wp_unslash($_POST['propvendor_paystack_live_public_key'])));
        }
        if (isset($_POST['propvendor_paystack_live_secret_key'])) {
            update_option('propvendor_paystack_live_secret_key', sanitize_text_field(wp_unslash($_POST['propvendor_paystack_live_secret_key'])));
        }

        $saved_notice = true;
    }

    $mode         = propvendor_marketplace_get_paystack_mode();
    $test_pub_key = get_option('propvendor_paystack_test_public_key', '');
    $test_sec_key = get_option('propvendor_paystack_test_secret_key', '');
    $live_pub_key = get_option('propvendor_paystack_live_public_key', '');
    $live_sec_key = get_option('propvendor_paystack_live_secret_key', '');
    $webhook_url  = propvendor_marketplace_get_paystack_webhook_url();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('PropVendor Payment Gateway Settings (Paystack)', 'propvendor-marketplace'); ?></h1>
        <p><?php esc_html_e('Configure your Paystack API credentials for monetization, credit packs, and VIP agent subscriptions.', 'propvendor-marketplace'); ?></p>

        <?php if ($saved_notice) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Payment gateway settings saved successfully.', 'propvendor-marketplace'); ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field('propvendor_payment_settings_verify', 'propvendor_payment_settings_nonce'); ?>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="propvendor_paystack_mode"><?php esc_html_e('Gateway Mode', 'propvendor-marketplace'); ?></label></th>
                        <td>
                            <select name="propvendor_paystack_mode" id="propvendor_paystack_mode">
                                <option value="test" <?php selected($mode, 'test'); ?>><?php esc_html_e('Test Mode (Local Sandbox & Development)', 'propvendor-marketplace'); ?></option>
                                <option value="live" <?php selected($mode, 'live'); ?>><?php esc_html_e('Live Mode (Production Charges)', 'propvendor-marketplace'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Select "Test Mode" during testing to avoid actual card charges.', 'propvendor-marketplace'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="propvendor_paystack_test_public_key"><?php esc_html_e('Test Public Key', 'propvendor-marketplace'); ?></label></th>
                        <td>
                            <input name="propvendor_paystack_test_public_key" type="text" id="propvendor_paystack_test_public_key" value="<?php echo esc_attr($test_pub_key); ?>" class="regular-text" placeholder="pk_test_..." autocomplete="off" />
                            <p class="description"><?php esc_html_e('Paystack test public key starting with pk_test_', 'propvendor-marketplace'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="propvendor_paystack_test_secret_key"><?php esc_html_e('Test Secret Key', 'propvendor-marketplace'); ?></label></th>
                        <td>
                            <input name="propvendor_paystack_test_secret_key" type="password" id="propvendor_paystack_test_secret_key" value="<?php echo esc_attr($test_sec_key); ?>" class="regular-text" placeholder="sk_test_..." autocomplete="off" />
                            <p class="description"><?php esc_html_e('Paystack test secret key starting with sk_test_', 'propvendor-marketplace'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="propvendor_paystack_live_public_key"><?php esc_html_e('Live Public Key', 'propvendor-marketplace'); ?></label></th>
                        <td>
                            <input name="propvendor_paystack_live_public_key" type="text" id="propvendor_paystack_live_public_key" value="<?php echo esc_attr($live_pub_key); ?>" class="regular-text" placeholder="pk_live_..." autocomplete="off" />
                            <p class="description"><?php esc_html_e('Paystack live public key starting with pk_live_', 'propvendor-marketplace'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="propvendor_paystack_live_secret_key"><?php esc_html_e('Live Secret Key', 'propvendor-marketplace'); ?></label></th>
                        <td>
                            <input name="propvendor_paystack_live_secret_key" type="password" id="propvendor_paystack_live_secret_key" value="<?php echo esc_attr($live_sec_key); ?>" class="regular-text" placeholder="sk_live_..." autocomplete="off" />
                            <p class="description"><?php esc_html_e('Paystack live secret key starting with sk_live_', 'propvendor-marketplace'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e('Paystack Webhook URL', 'propvendor-marketplace'); ?></label></th>
                        <td>
                            <input type="text" readonly value="<?php echo esc_url($webhook_url); ?>" class="large-text code" onclick="this.select();" />
                            <p class="description">
                                <?php esc_html_e('Copy this webhook URL into your Paystack Dashboard (Settings &rarr; API Keys &amp; Webhooks &rarr; Webhook URL) to automatically receive transaction events.', 'propvendor-marketplace'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="submit">
                <input type="submit" name="propvendor_save_payment_settings" id="submit" class="button button-primary" value="<?php esc_attr_e('Save Changes', 'propvendor-marketplace'); ?>" />
            </p>
        </form>
    </div>
    <?php
}

/**
 * ----------------------------------------------------------------------
 * Phase 3: On-Site Messaging Inbox System
 * ----------------------------------------------------------------------
 */

/**
 * Central Ownership Authorization Helper (Finding 1)
 *
 * @param int    $thread_id
 * @param int    $user_id
 * @param string $context 'vendor', 'customer', or empty for general
 * @return bool
 */
function propvendor_marketplace_user_can_access_thread($thread_id, $user_id = 0, $context = '') {
    if (empty($user_id)) {
        $user_id = get_current_user_id();
    }
    $user_id = absint($user_id);
    if ($user_id <= 0) {
        return false;
    }

    $thread_id = absint($thread_id);
    if ($thread_id <= 0) {
        return false;
    }

    global $wpdb;
    $table_threads = $wpdb->prefix . 'propvendor_threads';
    $thread = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_threads} WHERE id = %d", $thread_id));

    if (!$thread) {
        return false;
    }

    $is_admin    = user_can($user_id, 'manage_options');
    $is_vendor   = ((int) $thread->vendor_id === (int) $user_id);
    $is_customer = ((int) $thread->customer_user_id > 0 && (int) $thread->customer_user_id === (int) $user_id);

    // Finding 1 Context rules:
    // 1. Vendor Context: Vendor access requires (int)$thread->vendor_id === (int)$user_id (or Admin).
    if ('vendor' === $context) {
        return $is_admin || $is_vendor;
    }

    // 2. Customer Context: Customer access requires (int)$thread->customer_user_id === (int)$user_id AND $thread->customer_user_id > 0.
    // Guest threads (customer_user_id === 0) are strictly blocked from customer views.
    if ('customer' === $context) {
        return $is_customer;
    }

    // 3. General / unspecified context:
    if ($is_admin || $is_vendor || $is_customer) {
        return true;
    }

    // Guest submissions (customer_user_id === 0) access via dashboard inbox disallowed unless Admin or Vendor
    return false;
}

/**
 * Mask a phone number string for free tier display (retain first 4 and last 3 digits).
 *
 * @param string $phone
 * @return string
 */
function propvendor_marketplace_mask_phone($phone) {
    if (empty($phone)) {
        return '';
    }
    $trimmed = trim((string) $phone);
    if ('' === $trimmed) {
        return '';
    }
    $has_plus = (strpos($trimmed, '+') === 0);
    $digits = preg_replace('/[^\d]/', '', $trimmed);
    $len = strlen($digits);
    if ($len === 0) {
        return '';
    }
    if ($len < 7) {
        return ($has_plus ? '+' : '') . substr($digits, 0, 1) . '****' . substr($digits, -1);
    }
    $first4 = substr($digits, 0, 4);
    $last3  = substr($digits, -3);
    return ($has_plus ? '+' : '') . $first4 . '****' . $last3;
}

/**
 * Mask an email address string for free tier display (mask name portion).
 *
 * @param string $email
 * @return string
 */
function propvendor_marketplace_mask_email($email) {
    if (empty($email)) {
        return '';
    }
    $trimmed = trim((string) $email);
    if ('' === $trimmed || !is_email($trimmed)) {
        return '';
    }
    $parts = explode('@', $trimmed);
    $user = $parts[0];
    $domain = isset($parts[1]) ? $parts[1] : '';
    $len = strlen($user);

    if ($len <= 1) {
        $masked_user = '*';
    } elseif ($len === 2) {
        $masked_user = $user[0] . '*';
    } else {
        $masked_user = $user[0] . '***' . substr($user, -1);
    }

    return $masked_user . '@' . $domain;
}

/**
 * Detect and mask email addresses and phone numbers in freeform message text.
 *
 * @param string $text
 * @return string
 */
function propvendor_marketplace_mask_message_contact_info($text) {
    if (empty($text) || !is_string($text)) {
        return '';
    }

    // 1. Mask email addresses
    $email_pattern = '/(?<![\w])\b[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}\b/u';
    $text = preg_replace($email_pattern, '[Protected Email]', $text);

    // 2. Mask phone numbers (matches 7 to 15 digits with optional country/area codes and separators)
    $phone_pattern = '/(?<![\w@])(?:\+?\d{1,4}[-.\s]*)?(?:\(\d{1,4}\)[-.\s]*)?(?:\d[-.\s()]*){6,14}\d(?![\w])/u';
    $text = preg_replace_callback($phone_pattern, function($matches) {
        $match = $matches[0];
        $digits = preg_replace('/\D/', '', $match);
        $len = strlen($digits);

        if ($len >= 7 && $len <= 15) {
            // Preserve common date formats like YYYY-MM-DD or DD/MM/YYYY
            if (preg_match('/^\d{4}[-\/]\d{1,2}[-\/]\d{1,2}$/', trim($match)) ||
                preg_match('/^\d{1,2}[-\/]\d{1,2}[-\/]\d{4}$/', trim($match))) {
                return $match;
            }
            return '[Protected Phone]';
        }
        return $match;
    }, $text);

    return $text;
}

/**
 * Validate customer name on submission to prevent contact smuggling.
 *
 * @param string $name
 * @return true|WP_Error
 */
function propvendor_marketplace_validate_customer_name($name) {
    $trimmed = trim((string) $name);
    if (empty($trimmed) || mb_strlen($trimmed) < 2) {
        return new WP_Error('invalid_name_length', 'Please provide a valid name (at least 2 characters).');
    }

    if (mb_strlen($trimmed) > 100) {
        return new WP_Error('invalid_name_length', 'Name cannot exceed 100 characters.');
    }

    // Reject names containing email addresses or '@'
    if (strpos($trimmed, '@') !== false || preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/u', $trimmed)) {
        return new WP_Error('invalid_name_email', 'Please provide a valid name. Names cannot contain email addresses.');
    }

    // Reject names containing phone numbers or numeric digits
    if (preg_match('/\d/', $trimmed) || preg_match('/(?:\+?\d{1,3}[\s-]?)?\(?\d{2,4}\)?[\s-]?\d{3,}/u', $trimmed)) {
        return new WP_Error('invalid_name_phone', 'Please provide a valid name. Names cannot contain phone numbers or numeric digits.');
    }

    // Enforce allowed name characters: Unicode letters, spaces, hyphens, apostrophes, and periods
    if (!preg_match('/^[\p{L}\s\'\’\-\.]{2,100}$/u', $trimmed)) {
        return new WP_Error('invalid_name_chars', 'Please provide a valid name (letters, spaces, hyphens, and apostrophes only).');
    }

    return true;
}

/**
 * Safely format or mask customer name for display to vendors who do not have unlocked access.
 *
 * @param string $name
 * @param bool $can_view_contact
 * @return string
 */
function propvendor_marketplace_format_display_customer_name($name, $can_view_contact = true) {
    $name = trim((string) $name);
    if (empty($name)) {
        return 'Customer';
    }

    if ($can_view_contact) {
        return $name;
    }

    // For locked leads, if historical or malformed name contains email or phone, sanitize it
    if (strpos($name, '@') !== false || preg_match('/\d{3,}/', $name)) {
        return propvendor_marketplace_mask_message_contact_info($name);
    }

    return $name;
}

/**
 * =========================================================================
 * STAGE 5: CREDIT LEDGER & LEAD MONETIZATION ENGINE
 * =========================================================================
 */

/**
 * Get available credit pack definitions.
 *
 * @return array
 */
function propvendor_marketplace_get_credit_packs() {
    return array(
        'starter' => array(
            'credits'   => 5,
            'price_ngn' => 5000,
            'label'     => 'Starter (5 Credits)',
        ),
        'growth' => array(
            'credits'   => 12,
            'price_ngn' => 10000,
            'label'     => 'Growth (12 Credits)',
        ),
        'pro' => array(
            'credits'   => 28,
            'price_ngn' => 20000,
            'label'     => 'Pro (28 Credits)',
        ),
    );
}

/**
 * Get the current credit balance for a vendor from the ledger.
 *
 * @param int $vendor_id
 * @return int
 */
function propvendor_marketplace_get_vendor_credit_balance($vendor_id) {
    $vendor_id = absint($vendor_id);
    if ($vendor_id <= 0) {
        return 0;
    }

    global $wpdb;
    $table_ledger = $wpdb->prefix . 'propvendor_credit_ledger';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_ledger)) !== $table_ledger) {
        propvendor_marketplace_create_credit_ledger_table();
    }

    $bal = $wpdb->get_var($wpdb->prepare(
        "SELECT balance_after FROM {$table_ledger} WHERE vendor_id = %d ORDER BY id DESC LIMIT 1",
        $vendor_id
    ));

    return (null !== $bal) ? (int) $bal : 0;
}

/**
 * Add credits to a vendor's balance using atomic locking and InnoDB transaction.
 *
 * @param int $vendor_id
 * @param int $amount
 * @param string $transaction_type
 * @param string $reference
 * @param string $description
 * @return int|WP_Error New balance or WP_Error on failure
 */
function propvendor_marketplace_add_vendor_credits($vendor_id, $amount, $transaction_type = 'pack_purchase', $reference = '', $description = '') {
    $vendor_id = absint($vendor_id);
    $amount    = (int) $amount;

    if ($vendor_id <= 0) {
        return new WP_Error('invalid_vendor', __('Invalid vendor ID.', 'propvendor-marketplace'));
    }
    if ($amount <= 0) {
        return new WP_Error('invalid_amount', __('Credit addition amount must be greater than zero.', 'propvendor-marketplace'));
    }

    global $wpdb;
    $table_ledger = $wpdb->prefix . 'propvendor_credit_ledger';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_ledger)) !== $table_ledger) {
        propvendor_marketplace_create_credit_ledger_table();
    }

    $lock_name = 'pv_credit_ledger_' . $vendor_id;
    $lock_res  = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 10)", $lock_name));
    if ('1' !== (string) $lock_res) {
        return new WP_Error('lock_failed', __('Unable to acquire lock for credit transaction. Please try again.', 'propvendor-marketplace'));
    }

    try {
        $wpdb->query('START TRANSACTION');

        $last_balance = $wpdb->get_var($wpdb->prepare(
            "SELECT balance_after FROM {$table_ledger} WHERE vendor_id = %d ORDER BY id DESC LIMIT 1 FOR UPDATE",
            $vendor_id
        ));
        $current_balance = (null !== $last_balance) ? (int) $last_balance : 0;
        $new_balance     = $current_balance + $amount;

        $inserted = $wpdb->insert(
            $table_ledger,
            array(
                'vendor_id'        => $vendor_id,
                'amount'           => $amount,
                'balance_after'    => $new_balance,
                'transaction_type' => sanitize_key($transaction_type),
                'reference'        => sanitize_text_field($reference),
                'description'      => sanitize_text_field($description),
                'created_at'       => current_time('mysql'),
            ),
            array('%d', '%d', '%d', '%s', '%s', '%s', '%s')
        );

        if (false === $inserted) {
            $wpdb->query('ROLLBACK');
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
            return new WP_Error('insert_failed', __('Failed to record credit transaction in ledger.', 'propvendor-marketplace'));
        }

        $wpdb->query('COMMIT');
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));

        return $new_balance;
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
        return new WP_Error('exception', $e->getMessage());
    }
}

/**
 * Deduct credits from a vendor's balance atomically with row locking.
 *
 * @param int $vendor_id
 * @param int $amount
 * @param string $transaction_type
 * @param string $reference
 * @param string $description
 * @return int|WP_Error New balance or WP_Error on failure/insufficient funds
 */
function propvendor_marketplace_deduct_vendor_credits($vendor_id, $amount, $transaction_type = 'lead_unlock', $reference = '', $description = '') {
    $vendor_id = absint($vendor_id);
    $amount    = (int) $amount;

    if ($vendor_id <= 0) {
        return new WP_Error('invalid_vendor', __('Invalid vendor ID.', 'propvendor-marketplace'));
    }
    if ($amount <= 0) {
        return new WP_Error('invalid_amount', __('Deduction amount must be greater than zero.', 'propvendor-marketplace'));
    }

    global $wpdb;
    $table_ledger = $wpdb->prefix . 'propvendor_credit_ledger';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_ledger)) !== $table_ledger) {
        propvendor_marketplace_create_credit_ledger_table();
    }

    $lock_name = 'pv_credit_ledger_' . $vendor_id;
    $lock_res  = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 10)", $lock_name));
    if ('1' !== (string) $lock_res) {
        return new WP_Error('lock_failed', __('Unable to acquire lock for credit transaction. Please try again.', 'propvendor-marketplace'));
    }

    try {
        $wpdb->query('START TRANSACTION');

        $last_balance = $wpdb->get_var($wpdb->prepare(
            "SELECT balance_after FROM {$table_ledger} WHERE vendor_id = %d ORDER BY id DESC LIMIT 1 FOR UPDATE",
            $vendor_id
        ));
        $current_balance = (null !== $last_balance) ? (int) $last_balance : 0;

        if ($current_balance < $amount) {
            $wpdb->query('ROLLBACK');
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
            return new WP_Error(
                'insufficient_credits',
                sprintf(__('Insufficient credit balance. You have %d credits, but %d is required.', 'propvendor-marketplace'), $current_balance, $amount)
            );
        }

        $new_balance = $current_balance - $amount;

        $inserted = $wpdb->insert(
            $table_ledger,
            array(
                'vendor_id'        => $vendor_id,
                'amount'           => -$amount,
                'balance_after'    => $new_balance,
                'transaction_type' => sanitize_key($transaction_type),
                'reference'        => sanitize_text_field($reference),
                'description'      => sanitize_text_field($description),
                'created_at'       => current_time('mysql'),
            ),
            array('%d', '%d', '%d', '%s', '%s', '%s', '%s')
        );

        if (false === $inserted) {
            $wpdb->query('ROLLBACK');
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
            return new WP_Error('insert_failed', __('Failed to record credit deduction in ledger.', 'propvendor-marketplace'));
        }

        $wpdb->query('COMMIT');
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));

        return $new_balance;
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
        return new WP_Error('exception', $e->getMessage());
    }
}

/**
 * Add a predefined or custom credit pack to a vendor account.
 *
 * @param int $vendor_id
 * @param string|int $pack_or_credits
 * @param string $reference
 * @return int|WP_Error
 */
function propvendor_marketplace_add_vendor_credit_pack($vendor_id, $pack_or_credits, $reference = '') {
    $packs   = propvendor_marketplace_get_credit_packs();
    $credits = 0;
    $label   = '';

    if (is_numeric($pack_or_credits)) {
        $c = (int) $pack_or_credits;
        foreach ($packs as $k => $p) {
            if ($p['credits'] === $c) {
                $credits = $c;
                $label   = $p['label'];
                break;
            }
        }
        if ($credits === 0) {
            $credits = $c;
            $label   = sprintf('%d Credits Pack', $c);
        }
    } elseif (isset($packs[$pack_or_credits])) {
        $credits = $packs[$pack_or_credits]['credits'];
        $label   = $packs[$pack_or_credits]['label'];
    } else {
        return new WP_Error('invalid_pack', __('Invalid credit pack specified.', 'propvendor-marketplace'));
    }

    return propvendor_marketplace_add_vendor_credits(
        $vendor_id,
        $credits,
        'pack_purchase',
        $reference,
        $label
    );
}

/**
 * Check if a specific lead thread is unlocked for a vendor.
 *
 * @param int $vendor_id
 * @param int $thread_id
 * @return bool
 */
function propvendor_marketplace_is_thread_unlocked($vendor_id, $thread_id) {
    $vendor_id = absint($vendor_id);
    $thread_id = absint($thread_id);

    if ($vendor_id <= 0 || $thread_id <= 0) {
        return false;
    }

    // 1. Check user meta (fast cached lookup)
    $unlocked_threads = get_user_meta($vendor_id, 'propvendor_unlocked_thread_ids', true);
    if (is_array($unlocked_threads) && in_array($thread_id, array_map('absint', $unlocked_threads), true)) {
        return true;
    }

    // 2. Check credit ledger database table
    global $wpdb;
    $table_ledger = $wpdb->prefix . 'propvendor_credit_ledger';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_ledger)) === $table_ledger) {
        $ref1 = 'thread_' . $thread_id;
        $ref2 = (string) $thread_id;
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_ledger} WHERE vendor_id = %d AND transaction_type = 'lead_unlock' AND (reference = %s OR reference = %s) LIMIT 1",
            $vendor_id,
            $ref1,
            $ref2
        ));
        if ($found) {
            // Self-heal: synchronize back to user meta
            if (!is_array($unlocked_threads)) {
                $unlocked_threads = array();
            }
            $unlocked_threads[] = $thread_id;
            update_user_meta($vendor_id, 'propvendor_unlocked_thread_ids', array_values(array_unique(array_map('absint', $unlocked_threads))));
            return true;
        }
    }

    return false;
}

/**
 * Unlock a customer lead thread using credits idempotently.
 *
 * @param int $vendor_id
 * @param int $thread_id
 * @return array|WP_Error
 */
function propvendor_marketplace_unlock_thread_with_credits($vendor_id, $thread_id) {
    $vendor_id = absint($vendor_id);
    $thread_id = absint($thread_id);

    if ($vendor_id <= 0) {
        return new WP_Error('invalid_vendor', __('Invalid vendor ID.', 'propvendor-marketplace'));
    }
    if ($thread_id <= 0) {
        return new WP_Error('invalid_thread', __('Invalid thread ID.', 'propvendor-marketplace'));
    }

    // 1. VIP bypass check: VIP Agent and VIP vendors have automatic lead unlock entitlement
    if (propvendor_marketplace_is_vendor_vip_agent($vendor_id) || propvendor_marketplace_is_vendor_vip($vendor_id)) {
        return array(
            'success'          => true,
            'already_unlocked' => false,
            'vip_bypass'       => true,
            'deducted'         => 0,
            'balance'          => propvendor_marketplace_get_vendor_credit_balance($vendor_id),
            'message'          => __('VIP vendors have automatic access to all lead contacts without credit deduction.', 'propvendor-marketplace'),
        );
    }

    // 2. Fast check: is thread already unlocked?
    if (propvendor_marketplace_is_thread_unlocked($vendor_id, $thread_id)) {
        return array(
            'success'          => true,
            'already_unlocked' => true,
            'vip_bypass'       => false,
            'deducted'         => 0,
            'balance'          => propvendor_marketplace_get_vendor_credit_balance($vendor_id),
            'message'          => __('Thread contact details are already unlocked.', 'propvendor-marketplace'),
        );
    }

    // 3. Thread verification: ensure thread exists and belongs to this vendor (if threads table exists)
    global $wpdb;
    $table_threads = $wpdb->prefix . 'propvendor_threads';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_threads)) === $table_threads) {
        $thread = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_threads} WHERE id = %d", $thread_id));
        if ($thread) {
            if ((int) $thread->vendor_id !== $vendor_id && !user_can($vendor_id, 'manage_options')) {
                return new WP_Error('unauthorized_thread', __('You do not have permission to unlock this thread.', 'propvendor-marketplace'));
            }
        }
    }

    // 4. Deduct 1 credit atomically
    $ref_str = 'thread_' . $thread_id;
    $deduct_res = propvendor_marketplace_deduct_vendor_credits(
        $vendor_id,
        1,
        'lead_unlock',
        $ref_str,
        sprintf(__('Lead contact unlock for thread #%d', 'propvendor-marketplace'), $thread_id)
    );

    if (is_wp_error($deduct_res)) {
        return $deduct_res;
    }

    // 5. Synchronize to user meta for backward compatibility
    $meta = get_user_meta($vendor_id, 'propvendor_unlocked_thread_ids', true);
    if (!is_array($meta)) {
        $meta = array();
    }
    if (!in_array($thread_id, array_map('absint', $meta), true)) {
        $meta[] = $thread_id;
        update_user_meta($vendor_id, 'propvendor_unlocked_thread_ids', array_values(array_unique(array_map('absint', $meta))));
    }

    return array(
        'success'          => true,
        'already_unlocked' => false,
        'vip_bypass'       => false,
        'deducted'         => 1,
        'balance'          => (int) $deduct_res,
        'message'          => __('Lead contact details successfully unlocked.', 'propvendor-marketplace'),
    );
}

/**
 * Check if a vendor can view unmasked customer lead contact info.
 *
 * @param int $vendor_id
 * @param int $thread_id
 * @return bool
 */
function propvendor_marketplace_vendor_can_view_contact_info($vendor_id, $thread_id = 0) {
    $vendor_id = absint($vendor_id);
    if ($vendor_id <= 0) {
        $vendor_id = get_current_user_id();
    }

    // 1. Administrator check:
    // If the target vendor itself is an Administrator:
    if ($vendor_id > 0 && user_can($vendor_id, 'manage_options')) {
        return true;
    }
    // If the active session is an Administrator viewing on-screen in their own/dashboard context:
    if ($vendor_id === get_current_user_id() && current_user_can('manage_options')) {
        return true;
    }

    if ($vendor_id <= 0) {
        return false;
    }

    // 2. Vendor Plan Tier check (premium, pro, unlocked)
    $plan = get_user_meta($vendor_id, 'propvendor_vendor_plan', true);
    $plan = strtolower(trim((string) $plan));
    if (in_array($plan, array('premium', 'pro', 'unlocked'), true)) {
        return true;
    }

    // 3. Unlocked specific thread check (VIP Plan, Credit Ledger, or User Meta)
    $thread_id = absint($thread_id);
    if ($thread_id > 0) {
        // Active VIP Agent or VIP vendor has automatic access to lead contact info on threads
        if (propvendor_marketplace_is_vendor_vip_agent($vendor_id) || propvendor_marketplace_is_vendor_vip($vendor_id)) {
            return true;
        }
        if (propvendor_marketplace_is_thread_unlocked($vendor_id, $thread_id)) {
            return true;
        }
        $unlocked_threads = get_user_meta($vendor_id, 'propvendor_unlocked_thread_ids', true);
        if (is_array($unlocked_threads) && in_array($thread_id, array_map('absint', $unlocked_threads), true)) {
            return true;
        }
    }

    return false;
}

/**
 * Get total unread messages count for a vendor
 *
 * @param int $vendor_id
 * @return int
 */
function propvendor_marketplace_get_vendor_unread_count($vendor_id = 0) {
    if (empty($vendor_id)) {
        $vendor_id = get_current_user_id();
    }
    $vendor_id = absint($vendor_id);
    if ($vendor_id <= 0) {
        return 0;
    }

    global $wpdb;
    $table_threads  = $wpdb->prefix . 'propvendor_threads';
    $table_messages = $wpdb->prefix . 'propvendor_messages';

    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(m.id) FROM {$table_messages} m 
         INNER JOIN {$table_threads} t ON m.thread_id = t.id 
         WHERE t.vendor_id = %d AND m.sender_role = 'customer' AND m.is_read = 0",
        $vendor_id
    ));

    return absint($count);
}

/**
 * Get total unread messages count for a customer
 *
 * @param int $customer_id
 * @return int
 */
function propvendor_marketplace_get_customer_unread_count($customer_id = 0) {
    if (empty($customer_id)) {
        $customer_id = get_current_user_id();
    }
    $customer_id = absint($customer_id);
    if ($customer_id <= 0) {
        return 0;
    }

    global $wpdb;
    $table_threads  = $wpdb->prefix . 'propvendor_threads';
    $table_messages = $wpdb->prefix . 'propvendor_messages';

    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(m.id) FROM {$table_messages} m 
         INNER JOIN {$table_threads} t ON m.thread_id = t.id 
         WHERE t.customer_user_id = %d AND m.sender_role = 'vendor' AND m.is_read = 0",
        $customer_id
    ));

    return absint($count);
}

/**
 * Render Vendor Inbox view inside Vendor Dashboard
 *
 * @param int $vendor_id
 * @return string HTML
 */
function propvendor_marketplace_render_vendor_inbox($vendor_id) {
    global $wpdb;
    $table_threads   = $wpdb->prefix . 'propvendor_threads';
    $table_messages  = $wpdb->prefix . 'propvendor_messages';
    $table_enquiries = $wpdb->prefix . 'propvendor_enquiries';

    $threads = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_threads} WHERE vendor_id = %d ORDER BY last_message_at DESC",
        $vendor_id
    ));

    $output = '<div class="propvendor-dashboard-header"><div><h2>Inbox</h2><p>Communicate directly with prospective clients and customers.</p></div></div>';

    if (empty($threads)) {
        $output .= '<div class="propvendor-dashboard-empty"><p class="propvendor-empty">You have no messages yet. Enquiries received for your listings will appear here.</p></div>';
        return $output;
    }

    // Determine active thread
    $active_thread_id = isset($_GET['thread_id']) ? absint($_GET['thread_id']) : 0;
    if ($active_thread_id > 0) {
        if (!propvendor_marketplace_user_can_access_thread($active_thread_id, $vendor_id, 'vendor')) {
            wp_die('Access denied.', '', array('response' => 403));
        }
    } else {
        $active_thread_id = (int) $threads[0]->id;
    }

    // Finding 5: Explicit POST action for read receipts. Automatic GET updates removed.
    $active_thread = null;
    foreach ($threads as $t) {
        if ((int) $t->id === $active_thread_id) {
            $active_thread = $t;
            break;
        }
    }
    if (!$active_thread) {
        $active_thread = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_threads} WHERE id = %d", $active_thread_id));
    }

    // Finding 5: If there are unread customer messages in active thread, trigger explicit POST mark-read
    $has_unread = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(id) FROM {$table_messages} WHERE thread_id = %d AND sender_role = 'customer' AND is_read = 0",
        $active_thread_id
    ));
    if ($has_unread > 0) {
        $mark_read_nonce = wp_create_nonce('propvendor_mark_read_' . $active_thread_id);
        $admin_post_url  = admin_url('admin-post.php');
        $output .= '<script>(function(){ try { var fd = new FormData(); fd.append("action", "propvendor_mark_thread_read"); fd.append("thread_id", "' . absint($active_thread_id) . '"); fd.append("propvendor_mark_read_nonce", "' . esc_js($mark_read_nonce) . '"); fd.append("is_ajax", "1"); fetch("' . esc_url($admin_post_url) . '", { method: "POST", body: fd, credentials: "same-origin" }).catch(function(){}); } catch(e){} })();</script>';
    }

    $output .= '<div class="pv-inbox-container">';

    // Left Column: Thread List Pane
    $output .= '<div class="pv-inbox-sidebar">';
    $output .= '<div class="pv-inbox-sidebar-header">Conversations (' . count($threads) . ')</div>';
    $output .= '<ul class="pv-inbox-thread-list">';

    $dashboard_url = home_url('/vendor-dashboard/');

    foreach ($threads as $t) {
        $is_active = ((int) $t->id === $active_thread_id);
        $thread_url = add_query_arg(array('view' => 'inbox', 'thread_id' => $t->id), $dashboard_url);

        $unread_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(id) FROM {$table_messages} WHERE thread_id = %d AND sender_role = 'customer' AND is_read = 0",
            $t->id
        ));

        $thread_can_view = propvendor_marketplace_vendor_can_view_contact_info($vendor_id, $t->id);
        $latest_msg = $wpdb->get_row($wpdb->prepare(
            "SELECT message, created_at FROM {$table_messages} WHERE thread_id = %d ORDER BY created_at DESC LIMIT 1",
            $t->id
        ));
        $raw_msg_text = $latest_msg ? $latest_msg->message : '';
        $display_msg_text = $thread_can_view ? $raw_msg_text : propvendor_marketplace_mask_message_contact_info($raw_msg_text);
        $snippet = $latest_msg ? wp_trim_words(esc_html($display_msg_text), 8, '...') : '';
        $time_diff = human_time_diff(strtotime($t->last_message_at), current_time('timestamp')) . ' ago';

        $listing = get_post($t->listing_id);
        $listing_title = $listing ? get_the_title($listing) : ('Listing #' . $t->listing_id);
        $thumb_url = $listing ? get_the_post_thumbnail_url($listing, 'thumbnail') : '';

        $item_class = 'pv-inbox-thread-item' . ($is_active ? ' is-active' : '') . ($unread_count > 0 ? ' is-unread' : '');

        $output .= '<li class="' . esc_attr($item_class) . '">';
        $output .= '<a class="pv-inbox-thread-link" href="' . esc_url($thread_url) . '">';
        $output .= '<div class="pv-inbox-thread-header">';
        $output .= '<span class="pv-inbox-thread-name">';
        if ($unread_count > 0) {
            $output .= '<span class="pv-inbox-unread-dot" title="Unread messages"></span>';
        }
        $output .= esc_html(propvendor_marketplace_format_display_customer_name($t->customer_name, $thread_can_view));
        $output .= '</span>';
        $output .= '<span class="pv-inbox-thread-time">' . esc_html($time_diff) . '</span>';
        $output .= '</div>';

        $output .= '<div class="pv-inbox-thread-property">';
        if ($thumb_url) {
            $output .= '<img class="pv-inbox-thread-thumb" src="' . esc_url($thumb_url) . '" alt="" />';
        }
        $output .= '<span>' . esc_html($listing_title) . '</span>';
        $output .= '</div>';

        if ($snippet) {
            $output .= '<div class="pv-inbox-thread-snippet">' . $snippet . '</div>';
        }

        $output .= '</a>';
        $output .= '</li>';
    }

    $output .= '</ul>';
    $output .= '</div>'; // pv-inbox-sidebar

    // Right Column: Active Conversation Pane
    $output .= '<div class="pv-inbox-main">';

    if ($active_thread) {
        $listing = get_post($active_thread->listing_id);
        $listing_title = $listing ? get_the_title($listing) : ('Listing #' . $active_thread->listing_id);
        $listing_url = $listing ? get_permalink($listing) : '#';

        $customer_phone = $wpdb->get_var($wpdb->prepare(
            "SELECT customer_phone FROM {$table_enquiries} WHERE listing_id = %d AND customer_email = %s AND customer_phone != '' ORDER BY id DESC LIMIT 1",
            $active_thread->listing_id,
            $active_thread->customer_email
        ));

        $status = sanitize_key($active_thread->status ?: 'open');
        $status_label = ucfirst($status);

        $can_view_contact = propvendor_marketplace_vendor_can_view_contact_info($vendor_id, $active_thread_id);

        // Header
        $output .= '<div class="pv-inbox-header">';
        $output .= '<div>';
        $output .= '<h3 class="pv-inbox-header-title"><a href="' . esc_url($listing_url) . '" target="_blank" rel="noopener">' . esc_html($listing_title) . ' &#8599;</a></h3>';
        $output .= '<div class="pv-inbox-header-meta">';
        $display_customer_name = propvendor_marketplace_format_display_customer_name($active_thread->customer_name, $can_view_contact);
        $output .= '<span><strong>Customer:</strong> ' . esc_html($display_customer_name) . '</span>';
        if ($can_view_contact) {
            $output .= '<span><strong>Email:</strong> <a href="mailto:' . esc_attr($active_thread->customer_email) . '">' . esc_html($active_thread->customer_email) . '</a></span>';
            if (!empty($customer_phone)) {
                $output .= '<span><strong>Phone:</strong> <a href="tel:' . esc_attr($customer_phone) . '">' . esc_html($customer_phone) . '</a></span>';
            }
        } else {
            // Part 2: Monetization Engine Lead Masking & Anti-Inspector Rule
            $masked_email = propvendor_marketplace_mask_email($active_thread->customer_email);
            $output .= '<span class="pv-contact-field pv-contact-email" id="pv-contact-email-' . esc_attr($active_thread_id) . '"><strong>Email:</strong> <span class="pv-masked-info">' . esc_html($masked_email) . '</span></span>';
            if (!empty($customer_phone)) {
                $masked_phone = propvendor_marketplace_mask_phone($customer_phone);
                $output .= '<span class="pv-contact-field pv-contact-phone" id="pv-contact-phone-' . esc_attr($active_thread_id) . '"><strong>Phone:</strong> <span class="pv-masked-info">' . esc_html($masked_phone) . '</span></span>';
            }

            // Phase 5: Interactive Unlock with 1 Credit Interface
            $credit_balance = propvendor_marketplace_get_vendor_credit_balance($vendor_id);
            $wallet_url     = home_url('/vendor-wallet/');

            $output .= '<div class="pv-unlock-lead-container" id="pv-unlock-box-' . esc_attr($active_thread_id) . '">';
            $output .= '<div class="pv-unlock-lead-inner">';
            $output .= '<div class="pv-unlock-status">';
            $output .= '<span class="pv-unlock-badge"><span class="dashicons dashicons-lock"></span> ' . esc_html__('Locked Lead Contact', 'propvendor-marketplace') . '</span>';
            $output .= '<span class="pv-unlock-balance-pill">' . sprintf(esc_html__('Balance: %d Credits', 'propvendor-marketplace'), $credit_balance) . '</span>';
            $output .= '</div>';

            if ($credit_balance >= 1) {
                $output .= '<div class="pv-unlock-action">';
                $output .= '<button type="button" class="button button-primary pv-btn-unlock-lead-trigger" data-thread-id="' . esc_attr($active_thread_id) . '" data-balance="' . esc_attr($credit_balance) . '">';
                $output .= '<span class="dashicons dashicons-unlock"></span> ' . esc_html__('Unlock Contact — 1 Credit', 'propvendor-marketplace');
                $output .= '</button>';
                $output .= '<span class="pv-unlock-hint">' . esc_html__('1 credit will be deducted upon successful unlock. Contact details remain permanently accessible.', 'propvendor-marketplace') . '</span>';
                $output .= '</div>';
            } else {
                $output .= '<div class="pv-unlock-action pv-unlock-insufficient">';
                $output .= '<a href="' . esc_url($wallet_url) . '" class="button button-secondary pv-btn-buy-credits">';
                $output .= '<span class="dashicons dashicons-plus-alt"></span> ' . esc_html__('Get Credits to Unlock (1 Credit needed)', 'propvendor-marketplace');
                $output .= '</a>';
                $output .= '<span class="pv-unlock-hint pv-text-warning">' . wp_kses_post(sprintf(__('You have 0 credits. <a href="%s">Purchase a credit pack</a> to reveal customer phone & email.', 'propvendor-marketplace'), esc_url($wallet_url))) . '</span>';
                $output .= '</div>';
            }
            $output .= '</div>';
            $output .= '</div>';
        }
        $output .= '</div>';
        $output .= '</div>';
        $output .= '<div><span class="pv-inbox-header-status pv-status-' . esc_attr($status) . '">' . esc_html($status_label) . '</span></div>';
        $output .= '</div>';

        // Messages History Stream
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_messages} WHERE thread_id = %d ORDER BY created_at ASC",
            $active_thread_id
        ));

        // Stage 2: Universal Anti-Advance-Fee Trust & Safety Notice
        $output .= propvendor_marketplace_render_anti_fraud_notice();

        $output .= '<div class="pv-msg-stream" id="pv-msg-stream">';
        if (!empty($messages)) {
            foreach ($messages as $msg) {
                $role = sanitize_key($msg->sender_role);
                $is_vendor = ('vendor' === $role);
                $is_customer = ('customer' === $role);
                $row_class = 'pv-msg-row ' . ($is_vendor ? 'pv-msg-from-vendor pv-msg-vendor' : ($is_customer ? 'pv-msg-from-customer pv-msg-customer' : 'pv-msg-from-admin'));

                $sender_label = $is_vendor ? 'You (Vendor)' : ($is_customer ? esc_html($display_customer_name) : 'Administrator');
                $msg_time = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($msg->created_at));

                $msg_content = $msg->message;
                if (!$can_view_contact && 'customer' === $role) {
                    $msg_content = propvendor_marketplace_mask_message_contact_info($msg_content);
                }

                $output .= '<div class="' . esc_attr($row_class) . '">';
                $output .= '<div class="pv-msg-bubble">' . nl2br(esc_html($msg_content)) . '</div>';
                $output .= '<div class="pv-msg-meta">' . $sender_label . ' &bull; ' . esc_html($msg_time) . '</div>';
                $output .= '</div>';
            }
        } else {
            $output .= '<p class="propvendor-empty" style="text-align:center;">No messages in this conversation yet.</p>';
        }
        $output .= '</div>';

        // Reply Box Form with Finding 4 frontend maxlength="5000"
        $output .= '<div class="pv-reply-box">';
        $output .= '<form class="pv-reply-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $output .= wp_nonce_field('propvendor_inbox_reply_' . $active_thread_id, 'propvendor_inbox_nonce', true, false);
        $output .= '<input type="hidden" name="action" value="propvendor_send_inbox_reply" />';
        $output .= '<input type="hidden" name="thread_id" value="' . absint($active_thread_id) . '" />';
        $output .= '<input type="hidden" name="redirect_context" value="vendor" />';
        $output .= '<textarea name="message_content" rows="3" required minlength="2" maxlength="5000" placeholder="Write your reply to ' . esc_attr($display_customer_name) . '..."></textarea>';
        $output .= '<button type="submit" class="propvendor-btn primary pv-reply-submit">Send Reply</button>';
        $output .= '</form>';
        $output .= '</div>';

    } else {
        $output .= '<div style="padding: 2rem; text-align: center; color: #64748b;">Select a conversation from the left to view messages.</div>';
    }

    $output .= '</div>'; // pv-inbox-main
    $output .= '</div>'; // pv-inbox-container

    // Auto-scroll conversation stream to bottom
    $output .= '<script>document.addEventListener("DOMContentLoaded", function() { var el = document.getElementById("pv-msg-stream"); if (el) { el.scrollTop = el.scrollHeight; } });</script>';

    return $output;
}

/**
 * Customer Dashboard Inbox Shortcode: [propvendor_customer_inbox] (Finding 1)
 *
 * @return string HTML
 */
function propvendor_marketplace_customer_inbox_shortcode() {
    if (!is_user_logged_in()) {
        $login_url = wp_login_url(home_url('/customer-inbox/'));
        return '<div class="propvendor-dashboard"><h2>Customer Inbox</h2><p class="propvendor-empty">Please <a href="' . esc_url($login_url) . '">log in</a> with your account to view your messages and enquiries.</p></div>';
    }

    $current_user_id = get_current_user_id();
    global $wpdb;
    $table_threads   = $wpdb->prefix . 'propvendor_threads';
    $table_messages  = $wpdb->prefix . 'propvendor_messages';

    // Finding 1: Query threads strictly matching customer_user_id = get_current_user_id() and customer_user_id > 0
    $threads = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_threads} WHERE customer_user_id = %d AND customer_user_id > 0 ORDER BY last_message_at DESC",
        $current_user_id
    ));

    $notice = propvendor_marketplace_get_notice_message();
    $output = '<section class="propvendor-dashboard" aria-labelledby="propvendor-customer-inbox-title">';
    $output .= '<div class="propvendor-dashboard-header"><div><h2 id="propvendor-customer-inbox-title">My Messages</h2><p>View your enquiries and chat directly with property and vehicle vendors.</p></div></div>';
    $output .= $notice;

    if (empty($threads)) {
        $output .= '<div class="propvendor-dashboard-empty"><p class="propvendor-empty">You have no active message threads yet. Send an enquiry on any listing to start a conversation.</p><a class="propvendor-dashboard-add" href="' . esc_url(home_url('/browse-listings/')) . '">Browse Listings</a></div>';
        $output .= '</section>';
        return $output;
    }

    $active_thread_id = isset($_GET['thread_id']) ? absint($_GET['thread_id']) : 0;
    if ($active_thread_id > 0) {
        if (!propvendor_marketplace_user_can_access_thread($active_thread_id, $current_user_id, 'customer')) {
            wp_die('Access denied.', '', array('response' => 403));
        }
    } else {
        $active_thread_id = (int) $threads[0]->id;
    }

    // Finding 5: Explicit POST action for read receipts. Automatic GET updates removed.
    $active_thread = null;
    foreach ($threads as $t) {
        if ((int) $t->id === $active_thread_id) {
            $active_thread = $t;
            break;
        }
    }
    if (!$active_thread) {
        $candidate = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_threads} WHERE id = %d", $active_thread_id));
        if ($candidate && (int) $candidate->customer_user_id === (int) $current_user_id && (int) $candidate->customer_user_id > 0) {
            $active_thread = $candidate;
        }
    }

    // Finding 5: If there are unread vendor messages in active thread, trigger explicit POST mark-read
    $has_unread = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(id) FROM {$table_messages} WHERE thread_id = %d AND sender_role = 'vendor' AND is_read = 0",
        $active_thread_id
    ));
    if ($has_unread > 0) {
        $mark_read_nonce = wp_create_nonce('propvendor_mark_read_' . $active_thread_id);
        $admin_post_url  = admin_url('admin-post.php');
        $output .= '<script>(function(){ try { var fd = new FormData(); fd.append("action", "propvendor_mark_thread_read"); fd.append("thread_id", "' . absint($active_thread_id) . '"); fd.append("propvendor_mark_read_nonce", "' . esc_js($mark_read_nonce) . '"); fd.append("is_ajax", "1"); fetch("' . esc_url($admin_post_url) . '", { method: "POST", body: fd, credentials: "same-origin" }).catch(function(){}); } catch(e){} })();</script>';
    }

    $output .= '<div class="pv-inbox-container">';

    // Left sidebar: thread list
    $output .= '<div class="pv-inbox-sidebar">';
    $output .= '<div class="pv-inbox-sidebar-header">Conversations (' . count($threads) . ')</div>';
    $output .= '<ul class="pv-inbox-thread-list">';

    $base_inbox_url = home_url('/customer-inbox/');

    foreach ($threads as $t) {
        $is_active = ((int) $t->id === $active_thread_id);
        $thread_url = add_query_arg(array('thread_id' => $t->id), $base_inbox_url);

        $unread_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(id) FROM {$table_messages} WHERE thread_id = %d AND sender_role = 'vendor' AND is_read = 0",
            $t->id
        ));

        $latest_msg = $wpdb->get_row($wpdb->prepare(
            "SELECT message, created_at FROM {$table_messages} WHERE thread_id = %d ORDER BY created_at DESC LIMIT 1",
            $t->id
        ));
        $snippet = $latest_msg ? wp_trim_words(esc_html($latest_msg->message), 8, '...') : '';
        $time_diff = human_time_diff(strtotime($t->last_message_at), current_time('timestamp')) . ' ago';

        $listing = get_post($t->listing_id);
        $listing_title = $listing ? get_the_title($listing) : ('Listing #' . $t->listing_id);
        $thumb_url = $listing ? get_the_post_thumbnail_url($listing, 'thumbnail') : '';

        $vendor_user = get_user_by('id', $t->vendor_id);
        $vendor_name = $vendor_user ? $vendor_user->display_name : 'Vendor';

        $item_class = 'pv-inbox-thread-item' . ($is_active ? ' is-active' : '') . ($unread_count > 0 ? ' is-unread' : '');

        $output .= '<li class="' . esc_attr($item_class) . '">';
        $output .= '<a class="pv-inbox-thread-link" href="' . esc_url($thread_url) . '">';
        $output .= '<div class="pv-inbox-thread-header">';
        $output .= '<span class="pv-inbox-thread-name">';
        if ($unread_count > 0) {
            $output .= '<span class="pv-inbox-unread-dot" title="Unread messages"></span>';
        }
        $output .= esc_html($vendor_name);
        $output .= '</span>';
        $output .= '<span class="pv-inbox-thread-time">' . esc_html($time_diff) . '</span>';
        $output .= '</div>';

        $output .= '<div class="pv-inbox-thread-property">';
        if ($thumb_url) {
            $output .= '<img class="pv-inbox-thread-thumb" src="' . esc_url($thumb_url) . '" alt="" />';
        }
        $output .= '<span>' . esc_html($listing_title) . '</span>';
        $output .= '</div>';

        if ($snippet) {
            $output .= '<div class="pv-inbox-thread-snippet">' . $snippet . '</div>';
        }

        $output .= '</a>';
        $output .= '</li>';
    }

    $output .= '</ul>';
    $output .= '</div>'; // pv-inbox-sidebar

    // Right column: active conversation
    $output .= '<div class="pv-inbox-main">';

    if ($active_thread) {
        $listing = get_post($active_thread->listing_id);
        $listing_title = $listing ? get_the_title($listing) : ('Listing #' . $active_thread->listing_id);
        $listing_url = $listing ? get_permalink($listing) : '#';

        $vendor_user = get_user_by('id', $active_thread->vendor_id);
        $vendor_name = $vendor_user ? $vendor_user->display_name : 'Vendor';

        $status = sanitize_key($active_thread->status ?: 'open');
        $status_label = ucfirst($status);

        // Header
        $output .= '<div class="pv-inbox-header">';
        $output .= '<div>';
        $output .= '<h3 class="pv-inbox-header-title"><a href="' . esc_url($listing_url) . '" target="_blank" rel="noopener">' . esc_html($listing_title) . ' &#8599;</a></h3>';
        $output .= '<div class="pv-inbox-header-meta">';
        $output .= '<span><strong>Vendor:</strong> ' . esc_html($vendor_name) . '</span>';
        $output .= '</div>';
        $output .= '</div>';
        $output .= '<div><span class="pv-inbox-header-status pv-status-' . esc_attr($status) . '">' . esc_html($status_label) . '</span></div>';
        $output .= '</div>';

        // Messages Stream
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_messages} WHERE thread_id = %d ORDER BY created_at ASC",
            $active_thread_id
        ));

        // Stage 2: Universal Anti-Advance-Fee Trust & Safety Notice
        $output .= propvendor_marketplace_render_anti_fraud_notice();

        $output .= '<div class="pv-msg-stream" id="pv-msg-stream-cust">';
        if (!empty($messages)) {
            foreach ($messages as $msg) {
                $role = sanitize_key($msg->sender_role);
                $is_vendor = ('vendor' === $role);
                $is_customer = ('customer' === $role);
                $row_class = 'pv-msg-row ' . ($is_vendor ? 'pv-msg-from-vendor pv-msg-vendor' : ($is_customer ? 'pv-msg-from-customer pv-msg-customer' : 'pv-msg-from-admin'));

                $sender_label = $is_customer ? 'You (Customer)' : ($is_vendor ? esc_html($vendor_name) : 'Administrator');
                $msg_time = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($msg->created_at));

                $output .= '<div class="' . esc_attr($row_class) . '">';
                $output .= '<div class="pv-msg-bubble">' . nl2br(esc_html($msg->message)) . '</div>';
                $output .= '<div class="pv-msg-meta">' . $sender_label . ' &bull; ' . esc_html($msg_time) . '</div>';
                $output .= '</div>';
            }
        } else {
            $output .= '<p class="propvendor-empty" style="text-align:center;">No messages in this conversation yet.</p>';
        }
        $output .= '</div>';

        // Reply Box with Finding 4 frontend maxlength="5000"
        $output .= '<div class="pv-reply-box">';
        $output .= '<form class="pv-reply-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $output .= wp_nonce_field('propvendor_inbox_reply_' . $active_thread_id, 'propvendor_inbox_nonce', true, false);
        $output .= '<input type="hidden" name="action" value="propvendor_send_inbox_reply" />';
        $output .= '<input type="hidden" name="thread_id" value="' . absint($active_thread_id) . '" />';
        $output .= '<input type="hidden" name="redirect_context" value="customer" />';
        $output .= '<textarea name="message_content" rows="3" required minlength="2" maxlength="5000" placeholder="Write your reply to ' . esc_attr($vendor_name) . '..."></textarea>';
        $output .= '<button type="submit" class="propvendor-btn primary pv-reply-submit">Send Reply</button>';
        $output .= '</form>';
        $output .= '</div>';

    } else {
        $output .= '<div style="padding: 2rem; text-align: center; color: #64748b;">Select a conversation to view messages.</div>';
    }

    $output .= '</div>'; // pv-inbox-main
    $output .= '</div>'; // pv-inbox-container

    // Auto-scroll conversation stream to bottom
    $output .= '<script>document.addEventListener("DOMContentLoaded", function() { var el = document.getElementById("pv-msg-stream-cust"); if (el) { el.scrollTop = el.scrollHeight; } });</script>';

    // Phase 5: Accessible Confirmation Modal for Lead Contact Unlock
    $output .= '
    <div id="pv-unlock-lead-modal" class="pv-unlock-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="pv-unlock-modal-title">
        <div class="pv-unlock-modal-backdrop"></div>
        <div class="pv-unlock-modal-dialog">
            <div class="pv-unlock-modal-header">
                <h4 id="pv-unlock-modal-title"><span class="dashicons dashicons-unlock"></span> ' . esc_html__('Confirm Lead Contact Unlock', 'propvendor-marketplace') . '</h4>
                <button type="button" class="pv-unlock-modal-close" aria-label="' . esc_attr__('Close dialog', 'propvendor-marketplace') . '">&times;</button>
            </div>
            <div class="pv-unlock-modal-body">
                <p>' . esc_html__('Are you sure you want to unlock this customer\'s direct contact details?', 'propvendor-marketplace') . '</p>
                <div class="pv-unlock-modal-details">
                    <div><strong>' . esc_html__('Cost:', 'propvendor-marketplace') . '</strong> 1 Credit</div>
                    <div><strong>' . esc_html__('Your Balance:', 'propvendor-marketplace') . '</strong> <span id="pv-modal-current-balance">0</span> Credits</div>
                    <div><strong>' . esc_html__('Balance After Unlock:', 'propvendor-marketplace') . '</strong> <span id="pv-modal-balance-after">0</span> Credits</div>
                </div>
                <p class="pv-unlock-modal-subtext">' . esc_html__('1 credit will be deducted from your wallet upon confirmation. Customer phone number and email will be revealed immediately.', 'propvendor-marketplace') . '</p>
                <div id="pv-unlock-modal-error" class="pv-unlock-modal-error" style="display: none;"></div>
            </div>
            <div class="pv-unlock-modal-footer">
                <button type="button" id="pv-btn-confirm-unlock" class="button button-primary">' . esc_html__('Confirm & Deduct 1 Credit', 'propvendor-marketplace') . '</button>
                <button type="button" id="pv-btn-cancel-unlock" class="button button-secondary">' . esc_html__('Cancel', 'propvendor-marketplace') . '</button>
            </div>
        </div>
    </div>';

    $output .= '</section>';
    return $output;
}
add_shortcode('propvendor_customer_inbox', 'propvendor_marketplace_customer_inbox_shortcode');

/**
 * Finding 5: Explicit POST/AJAX action for read receipts
 */
function propvendor_marketplace_mark_thread_read() {
    // 1. Verify HTTP POST method
    if ('POST' !== $_SERVER['REQUEST_METHOD']) {
        if (wp_doing_ajax() || !empty($_POST['is_ajax'])) {
            wp_send_json_error(array('message' => 'Method Not Allowed'), 405);
        }
        wp_die('Method Not Allowed', '', array('response' => 405));
    }

    if (!is_user_logged_in()) {
        if (wp_doing_ajax() || !empty($_POST['is_ajax'])) {
            wp_send_json_error(array('message' => 'Unauthorized'), 403);
        }
        wp_die('Access denied.', '', array('response' => 403));
    }

    $thread_id = isset($_POST['thread_id']) ? absint($_POST['thread_id']) : 0;
    if ($thread_id <= 0) {
        if (wp_doing_ajax() || !empty($_POST['is_ajax'])) {
            wp_send_json_error(array('message' => 'Invalid thread.'), 400);
        }
        wp_die('Invalid thread.', '', array('response' => 400));
    }

    // 2. Nonce verification
    $nonce = isset($_POST['propvendor_mark_read_nonce']) ? sanitize_text_field(wp_unslash($_POST['propvendor_mark_read_nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'propvendor_mark_read_' . $thread_id)) {
        if (wp_doing_ajax() || !empty($_POST['is_ajax'])) {
            wp_send_json_error(array('message' => 'Security check failed.'), 403);
        }
        wp_die('Security verification failed.', '', array('response' => 403));
    }

    $current_user_id = get_current_user_id();

    // 3. Central ownership authorization
    if (!propvendor_marketplace_user_can_access_thread($thread_id, $current_user_id)) {
        if (wp_doing_ajax() || !empty($_POST['is_ajax'])) {
            wp_send_json_error(array('message' => 'Forbidden'), 403);
        }
        wp_die('Access denied.', '', array('response' => 403));
    }

    global $wpdb;
    $table_threads  = $wpdb->prefix . 'propvendor_threads';
    $table_messages = $wpdb->prefix . 'propvendor_messages';

    $thread = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_threads} WHERE id = %d", $thread_id));
    if (!$thread) {
        if (wp_doing_ajax() || !empty($_POST['is_ajax'])) {
            wp_send_json_error(array('message' => 'Thread not found.'), 404);
        }
        wp_die('Conversation thread not found.', '', array('response' => 404));
    }

    // 4. Idempotently update wp_propvendor_messages SET is_read = 1 for incoming messages only
    if ((int) $current_user_id === (int) $thread->vendor_id) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_messages} SET is_read = 1 WHERE thread_id = %d AND sender_role = 'customer' AND is_read = 0",
            $thread_id
        ));
    } elseif ((int) $thread->customer_user_id > 0 && (int) $current_user_id === (int) $thread->customer_user_id) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_messages} SET is_read = 1 WHERE thread_id = %d AND sender_role = 'vendor' AND is_read = 0",
            $thread_id
        ));
    } elseif (user_can($current_user_id, 'manage_options')) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_messages} SET is_read = 1 WHERE thread_id = %d AND sender_id != %d AND is_read = 0",
            $thread_id,
            $current_user_id
        ));
    }

    if (wp_doing_ajax() || !empty($_POST['is_ajax']) || (isset($_SERVER['HTTP_ACCEPT']) && false !== strpos($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
        wp_send_json_success(array('thread_id' => $thread_id, 'status' => 'marked_read'));
    }

    $redirect_url = isset($_POST['redirect_url']) ? esc_url_raw($_POST['redirect_url']) : wp_get_referer();
    if (!$redirect_url) {
        $redirect_url = home_url();
    }
    wp_safe_redirect($redirect_url);
    exit;
}
add_action('admin_post_propvendor_mark_thread_read', 'propvendor_marketplace_mark_thread_read');

/**
 * Handle in-app reply POST submission: admin_post_propvendor_send_inbox_reply
 * (Findings 1, 2, 3, 4)
 */
function propvendor_marketplace_handle_send_inbox_reply() {
    // 1. Verify HTTP POST method
    if ('POST' !== $_SERVER['REQUEST_METHOD']) {
        wp_die('Method Not Allowed', '', array('response' => 405));
    }

    if (!is_user_logged_in()) {
        wp_die('Access denied.', '', array('response' => 403));
    }

    $thread_id = isset($_POST['thread_id']) ? absint($_POST['thread_id']) : 0;
    if ($thread_id <= 0) {
        wp_die('Invalid thread.', '', array('response' => 400));
    }

    // 2. Nonce verification
    $nonce = isset($_POST['propvendor_inbox_nonce']) ? sanitize_text_field(wp_unslash($_POST['propvendor_inbox_nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'propvendor_inbox_reply_' . $thread_id)) {
        wp_die('Security verification failed. Please reload and try again.', '', array('response' => 403));
    }

    $current_user_id = get_current_user_id();

    // Determine redirect context
    $redirect_context = isset($_POST['redirect_context']) ? sanitize_key($_POST['redirect_context']) : '';
    $context = ('customer' === $redirect_context) ? 'customer' : (('vendor' === $redirect_context) ? 'vendor' : '');

    // 3. Central ownership authorization with strict context (Finding 1)
    if (!propvendor_marketplace_user_can_access_thread($thread_id, $current_user_id, $context)) {
        wp_die('Access denied.', '', array('response' => 403));
    }

    global $wpdb;
    $table_threads  = $wpdb->prefix . 'propvendor_threads';
    $table_messages = $wpdb->prefix . 'propvendor_messages';

    $thread = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_threads} WHERE id = %d", $thread_id));
    if (!$thread) {
        wp_die('Conversation thread not found.', '', array('response' => 404));
    }

    // Determine redirect URL
    if ('customer' === $redirect_context || ((int) $thread->customer_user_id === (int) $current_user_id && !user_can($current_user_id, 'manage_options') && (int) $thread->vendor_id !== (int) $current_user_id)) {
        $redirect_url = add_query_arg(array('thread_id' => $thread_id), home_url('/customer-inbox/'));
    } else {
        $redirect_url = add_query_arg(array('view' => 'inbox', 'thread_id' => $thread_id), home_url('/vendor-dashboard/'));
    }

    // 4. Sanitize and validate reply message
    $reply_text = isset($_POST['message_content']) ? sanitize_textarea_field(wp_unslash($_POST['message_content'])) : '';
    $reply_text = trim($reply_text);
    if (empty($reply_text) || mb_strlen($reply_text) < 2) {
        propvendor_marketplace_redirect_with_notice($redirect_url, 'error', 'Please enter a reply message of at least 2 characters.');
    }

    // Finding 4: Server-Side Message Bounding - enforce maximum 5,000 characters
    if (mb_strlen($reply_text) > 5000) {
        propvendor_marketplace_redirect_with_notice($redirect_url, 'error', 'Your message exceeds the maximum allowed length of 5,000 characters.');
    }

    // Finding 3: Server-Side Rate Limiting - transient capping replies at 10 submissions per 60 seconds
    $rate_transient_key = 'pv_reply_rate_' . $current_user_id;
    $current_rate_count = (int) get_transient($rate_transient_key);
    if ($current_rate_count >= 10) {
        propvendor_marketplace_redirect_with_notice($redirect_url, 'error', 'You have sent too many replies in a short period. Please wait a minute before replying again.');
    }

    // Finding 3: Server-Side Duplicate Protection - MD5 hash of thread_id|user_id|message for 60 seconds
    $dup_hash = md5($thread_id . '|' . $current_user_id . '|' . $reply_text);
    $dup_transient_key = 'pv_reply_dup_' . $dup_hash;
    if (get_transient($dup_transient_key)) {
        propvendor_marketplace_redirect_with_notice($redirect_url, 'error', 'Duplicate reply detected. You have already sent this exact message.');
    }

    // 5. Determine sender role securely based on user identity
    if ((int) $current_user_id === (int) $thread->vendor_id) {
        $sender_role = 'vendor';
    } elseif ((int) $thread->customer_user_id > 0 && (int) $current_user_id === (int) $thread->customer_user_id) {
        $sender_role = 'customer';
    } elseif (user_can($current_user_id, 'manage_options')) {
        $sender_role = 'admin';
    } else {
        wp_die('Access denied.', '', array('response' => 403));
    }

    $now_mysql = current_time('mysql');

    // 6. Insert reply into wp_propvendor_messages
    $inserted = $wpdb->insert(
        $table_messages,
        array(
            'thread_id'   => $thread_id,
            'sender_id'   => $current_user_id,
            'sender_role' => $sender_role,
            'message'     => $reply_text,
            'is_read'     => 0,
            'created_at'  => $now_mysql,
        ),
        array('%d', '%d', '%s', '%s', '%d', '%s')
    );

    if (false === $inserted) {
        error_log($wpdb->last_error);
        propvendor_marketplace_redirect_with_notice($redirect_url, 'error', 'Unable to send reply due to a database error. Please try again.');
    }

    // Finding 3: Record rate limit and duplicate protection transients for 60 seconds
    set_transient($rate_transient_key, $current_rate_count + 1, 60);
    set_transient($dup_transient_key, 1, 60);

    // 7. Update wp_propvendor_threads status and last_message_at
    $new_status = ('vendor' === $sender_role) ? 'replied' : 'open';
    $wpdb->update(
        $table_threads,
        array(
            'last_message_at' => $now_mysql,
            'status'          => $new_status,
        ),
        array('id' => $thread_id),
        array('%s', '%s'),
        array('%d')
    );

    // 8. Safe Email Notification Alert (Finding 2)
    $listing = get_post($thread->listing_id);
    $listing_title = $listing ? get_the_title($listing) : ('Listing #' . $thread->listing_id);
    $snippet = wp_trim_words($reply_text, 25, '...');

    if ('vendor' === $sender_role) {
        // Vendor replied -> Alert customer
        $vendor_user = get_user_by('id', $thread->vendor_id);
        $vendor_name = $vendor_user ? $vendor_user->display_name : 'The vendor';

        // Finding 2: Never expose a vendor's private account email; use configured public email or site default.
        $public_email = get_user_meta($thread->vendor_id, 'propvendor_public_email', true);
        if (!empty($public_email) && is_email($public_email)) {
            $vendor_reply_to = sanitize_email($public_email);
        } else {
            $vendor_reply_to = 'no-reply@' . wp_parse_url(home_url(), PHP_URL_HOST);
        }

        $headers = array(
            'From: PropVendor Marketplace <no-reply@' . wp_parse_url(home_url(), PHP_URL_HOST) . '>',
            'Reply-To: ' . $vendor_reply_to,
        );

        $customer_email = $thread->customer_email;
        if (!empty($customer_email) && is_email($customer_email)) {
            $subject = sprintf('New reply regarding %s', $listing_title);

            // Finding 2: Safe Guest Communication Workflow
            if ((int) $thread->customer_user_id === 0) {
                // Guest inquiry: do NOT link to customer inbox, append registration CTA
                $register_link = wp_registration_url();
                $body  = "Hello " . $thread->customer_name . ",\n\n";
                $body .= $vendor_name . " has replied to your enquiry regarding " . $listing_title . ":\n\n";
                $body .= "\"" . $snippet . "\"\n\n";
                $body .= "Note: You submitted this inquiry as a guest. To view your full message history or chat live on PropVendor, register an account using this email address: " . $register_link . "\n\n";
                $body .= "Regards,\nPropVendor Team";
            } else {
                // Registered customer: link to customer inbox
                $customer_inbox_link = home_url('/customer-inbox/?thread_id=' . $thread_id);
                $body  = "Hello " . $thread->customer_name . ",\n\n";
                $body .= $vendor_name . " has replied to your enquiry regarding " . $listing_title . ":\n\n";
                $body .= "\"" . $snippet . "\"\n\n";
                $body .= "Log in to your account to view the full conversation: " . $customer_inbox_link . "\n\n";
                $body .= "Regards,\nPropVendor Team";
            }

            wp_mail($customer_email, $subject, $body, $headers);
        }
    } elseif ('customer' === $sender_role) {
        // Customer replied -> Alert vendor
        $vendor_user = get_user_by('id', $thread->vendor_id);
        $vendor_name = $vendor_user ? $vendor_user->display_name : 'Vendor';
        $vendor_inbox_link = home_url('/vendor-dashboard/?view=inbox&thread_id=' . $thread_id);

        $headers = array(
            'From: PropVendor Marketplace <no-reply@' . wp_parse_url(home_url(), PHP_URL_HOST) . '>',
        );

        $vendor_email = '';
        $public_email = get_user_meta($thread->vendor_id, 'propvendor_public_email', true);
        if (!empty($public_email) && is_email($public_email)) {
            $vendor_email = sanitize_email($public_email);
        } elseif ($vendor_user && is_email($vendor_user->user_email)) {
            $vendor_email = sanitize_email($vendor_user->user_email);
        }

        if (!empty($vendor_email)) {
            $can_view_contact = propvendor_marketplace_vendor_can_view_contact_info($thread->vendor_id, $thread_id);
            $email_snippet = $can_view_contact ? $snippet : propvendor_marketplace_mask_message_contact_info($snippet);
            $email_customer_name = propvendor_marketplace_format_display_customer_name($thread->customer_name, $can_view_contact);

            $subject = sprintf('New reply regarding %s', $listing_title);
            $body  = "Hello " . $vendor_name . ",\n\n";
            $body .= $email_customer_name . " has replied to your conversation regarding " . $listing_title . ":\n\n";
            $body .= "\"" . $email_snippet . "\"\n\n";
            $body .= "Log in to your vendor dashboard to view and respond: " . $vendor_inbox_link . "\n\n";
            $body .= "Regards,\nPropVendor Team";

            wp_mail($vendor_email, $subject, $body, $headers);
        }
    }

    // 9. Redirect safely back to active thread view with success notice
    propvendor_marketplace_redirect_with_notice($redirect_url, 'success', 'Your reply has been sent.');
}
add_action('admin_post_propvendor_send_inbox_reply', 'propvendor_marketplace_handle_send_inbox_reply');

/**
 * =========================================================================
 * PHASE 4B: PAYMENT GATEWAY INTEGRATION (PAYSTACK) & CHECKOUT ENGINE
 * =========================================================================
 */

/**
 * Get active Paystack mode ('test' or 'live').
 *
 * @return string
 */
function propvendor_marketplace_get_paystack_mode() {
    $mode = get_option('propvendor_paystack_mode', 'test');
    return ('live' === $mode) ? 'live' : 'test';
}

/**
 * Get active Paystack Public Key.
 *
 * @return string
 */
function propvendor_marketplace_get_paystack_public_key() {
    $mode = propvendor_marketplace_get_paystack_mode();
    if ('live' === $mode) {
        return trim((string) get_option('propvendor_paystack_live_public_key', ''));
    }
    return trim((string) get_option('propvendor_paystack_test_public_key', ''));
}

/**
 * Get active Paystack Secret Key.
 *
 * @return string
 */
function propvendor_marketplace_get_paystack_secret_key() {
    $mode = propvendor_marketplace_get_paystack_mode();
    if ('live' === $mode) {
        return trim((string) get_option('propvendor_paystack_live_secret_key', ''));
    }
    return trim((string) get_option('propvendor_paystack_test_secret_key', ''));
}

/**
 * Get Paystack Webhook URL.
 *
 * @return string
 */
function propvendor_marketplace_get_paystack_webhook_url() {
    return home_url('/wp-json/propvendor/v1/paystack-webhook');
}

/**
 * Query active plans from catalog.
 *
 * @param string $product_type Optional filter ('vip_agent' or 'credit_pack')
 * @return array
 */
function propvendor_marketplace_get_active_plans($product_type = '') {
    global $wpdb;
    $table_plans = $wpdb->prefix . 'propvendor_plans';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_plans)) !== $table_plans) {
        propvendor_marketplace_create_payment_tables();
    }
    if (!empty($product_type)) {
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_plans} WHERE is_active = 1 AND product_type = %s ORDER BY price_kobo ASC",
            sanitize_key($product_type)
        ));
    }
    return $wpdb->get_results("SELECT * FROM {$table_plans} WHERE is_active = 1 ORDER BY product_type DESC, price_kobo ASC");
}

/**
 * Get single plan by ID.
 *
 * @param int $plan_id
 * @return object|null
 */
function propvendor_marketplace_get_plan_by_id($plan_id) {
    global $wpdb;
    $table_plans = $wpdb->prefix . 'propvendor_plans';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_plans)) !== $table_plans) {
        propvendor_marketplace_create_payment_tables();
    }
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_plans} WHERE id = %d", absint($plan_id)));
}

/**
 * Get single plan by plan key.
 *
 * @param string $plan_key
 * @return object|null
 */
function propvendor_marketplace_get_plan_by_key($plan_key) {
    global $wpdb;
    $table_plans = $wpdb->prefix . 'propvendor_plans';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_plans)) !== $table_plans) {
        propvendor_marketplace_create_payment_tables();
    }
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_plans} WHERE plan_key = %s", sanitize_key($plan_key)));
}

/**
 * Create a new pending order tracking record.
 *
 * @param int $vendor_id
 * @param int $plan_id
 * @param string $gateway
 * @return array|WP_Error
 */
function propvendor_marketplace_create_order($vendor_id, $plan_id, $gateway = 'paystack') {
    $vendor_id = absint($vendor_id);
    $plan_id   = absint($plan_id);

    if ($vendor_id <= 0) {
        return new WP_Error('invalid_vendor', __('Invalid vendor ID.', 'propvendor-marketplace'));
    }
    $vendor_user = get_user_by('id', $vendor_id);
    if (!$vendor_user) {
        return new WP_Error('vendor_not_found', __('Vendor user not found.', 'propvendor-marketplace'));
    }

    $plan = propvendor_marketplace_get_plan_by_id($plan_id);
    if (!$plan || (int) $plan->is_active !== 1) {
        return new WP_Error('invalid_plan', __('The selected plan does not exist or is inactive.', 'propvendor-marketplace'));
    }

    global $wpdb;
    $table_orders = $wpdb->prefix . 'propvendor_orders';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_orders)) !== $table_orders) {
        propvendor_marketplace_create_payment_tables();
    }

    // Generate unique transaction reference (e.g. PV_12_1729000000_aB3dE8)
    $reference = 'PV_' . $vendor_id . '_' . time() . '_' . wp_generate_password(6, false, false);

    $now = current_time('mysql');
    $inserted = $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_id,
            'plan_id'               => $plan->id,
            'amount_kobo'           => (int) $plan->price_kobo,
            'transaction_reference' => $reference,
            'gateway'               => sanitize_key($gateway),
            'status'                => 'pending',
            'created_at'            => $now,
            'updated_at'            => $now,
        ),
        array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
    );

    if (false === $inserted) {
        return new WP_Error('order_creation_failed', __('Failed to create order record.', 'propvendor-marketplace'));
    }

    $order_id = $wpdb->insert_id;

    return array(
        'success'      => true,
        'order_id'     => $order_id,
        'reference'    => $reference,
        'amount_kobo'  => (int) $plan->price_kobo,
        'plan_id'      => (int) $plan->id,
        'plan_key'     => $plan->plan_key,
        'plan_name'    => $plan->name,
        'product_type' => $plan->product_type,
        'vendor_email' => $vendor_user->user_email,
        'status'       => 'pending',
    );
}

/**
 * Grant or extend VIP Agent entitlement for a vendor.
 *
 * @param int $vendor_id
 * @param int $duration_days
 * @param string $reference
 * @return array|WP_Error
 */
/**
 * Check if a vendor has an active VIP Agent subscription (for lead contact access).
 *
 * @param int $user_id
 * @return bool
 */
function propvendor_marketplace_is_vendor_vip_agent($user_id) {
    $user_id = absint($user_id);
    if ($user_id <= 0) {
        return false;
    }

    if (user_can($user_id, 'manage_options')) {
        return true;
    }

    $plan = strtolower(trim((string) get_user_meta($user_id, 'propvendor_vip_agent_plan', true)));
    if ('vip' !== $plan) {
        return false;
    }

    $expires_at = get_user_meta($user_id, 'propvendor_vip_agent_expires_at', true);
    if ('' !== trim((string) $expires_at)) {
        $expiry_str = trim((string) $expires_at);
        $site_tz    = wp_timezone();
        $now_ts     = current_time('timestamp');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_str)) {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $expiry_str . ' 23:59:59', $site_tz);
            $expiry_ts = $dt ? $dt->getTimestamp() : false;
        } elseif (is_numeric($expiry_str)) {
            $expiry_ts = (int) $expiry_str;
        } else {
            try {
                $dt = new DateTimeImmutable($expiry_str, $site_tz);
                $expiry_ts = $dt->getTimestamp();
            } catch (Exception $e) {
                $expiry_ts = false;
            }
        }

        if (false === $expiry_ts || $expiry_ts <= 0) {
            return false;
        }

        if ($now_ts > $expiry_ts) {
            return false;
        }
    }

    return true;
}

/**
 * Grant VIP Agent entitlement to a vendor wallet.
 *
 * Stores subscription under dedicated meta 'propvendor_vip_agent_plan' and
 * 'propvendor_vip_agent_expires_at', completely separated from Stage 3 listing quotas.
 *
 * @param int $vendor_id
 * @param int $duration_days
 * @param string $reference
 * @return array|WP_Error
 */
function propvendor_marketplace_grant_vip_agent_entitlement($vendor_id, $duration_days = 30, $reference = '') {
    $vendor_id     = absint($vendor_id);
    $duration_days = max(1, (int) $duration_days);

    if ($vendor_id <= 0) {
        return new WP_Error('invalid_vendor', __('Invalid vendor ID.', 'propvendor-marketplace'));
    }

    // Set dedicated VIP agent subscription (does NOT touch listing quota plan)
    update_user_meta($vendor_id, 'propvendor_vip_agent_plan', 'vip');

    // Check existing expiry date for VIP agent subscription
    $existing_expiry = get_user_meta($vendor_id, 'propvendor_vip_agent_expires_at', true);
    $site_tz = wp_timezone();
    $now_ts  = current_time('timestamp');
    $base_ts = $now_ts;

    if (!empty($existing_expiry)) {
        $expiry_str = trim((string) $existing_expiry);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_str)) {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $expiry_str . ' 23:59:59', $site_tz);
            $exp_ts = $dt ? $dt->getTimestamp() : false;
        } else {
            try {
                $dt = new DateTimeImmutable($expiry_str, $site_tz);
                $exp_ts = $dt->getTimestamp();
            } catch (Exception $e) {
                $exp_ts = false;
            }
        }
        // If current expiry is in the future, extend from that point
        if ($exp_ts && $exp_ts > $now_ts) {
            $base_ts = $exp_ts;
        }
    }

    $new_expiry_ts   = $base_ts + ($duration_days * DAY_IN_SECONDS);
    $new_expiry_date = wp_date('Y-m-d', $new_expiry_ts, $site_tz);

    update_user_meta($vendor_id, 'propvendor_vip_agent_expires_at', $new_expiry_date);

    return array(
        'success'        => true,
        'plan'           => 'vip',
        'expires_at'     => $new_expiry_date,
        'duration_added' => $duration_days,
        'reference'      => $reference,
    );
}

/**
 * Grant credits to vendor wallet atomically with idempotency protection.
 *
 * @param int $vendor_id
 * @param int $credits
 * @param string $idempotency_key
 * @param string $description
 * @return array|WP_Error
 */
function propvendor_marketplace_grant_vendor_credits($vendor_id, $credits, $idempotency_key = '', $description = '') {
    $vendor_id = absint($vendor_id);
    $credits   = (int) $credits;

    if ($vendor_id <= 0) {
        return new WP_Error('invalid_vendor', __('Invalid vendor ID.', 'propvendor-marketplace'));
    }
    if ($credits <= 0) {
        return new WP_Error('invalid_credits', __('Credits must be greater than zero.', 'propvendor-marketplace'));
    }

    // Check if idempotency key was already applied in ledger
    global $wpdb;
    $table_ledger = $wpdb->prefix . 'propvendor_credit_ledger';
    if (!empty($idempotency_key) && $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_ledger)) === $table_ledger) {
        $already_applied = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_ledger} WHERE vendor_id = %d AND reference = %s AND transaction_type = 'pack_purchase' LIMIT 1",
            $vendor_id,
            $idempotency_key
        ));
        if ($already_applied) {
            return array(
                'success'          => true,
                'already_credited' => true,
                'balance'          => propvendor_marketplace_get_vendor_credit_balance($vendor_id),
            );
        }
    }

    if (empty($description)) {
        $description = sprintf(__('%d Credits Pack Purchase', 'propvendor-marketplace'), $credits);
    }

    $new_balance = propvendor_marketplace_add_vendor_credits(
        $vendor_id,
        $credits,
        'pack_purchase',
        $idempotency_key,
        $description
    );

    if (is_wp_error($new_balance)) {
        return $new_balance;
    }

    return array(
        'success'          => true,
        'already_credited' => false,
        'credits_added'    => $credits,
        'new_balance'      => $new_balance,
        'reference'        => $idempotency_key,
    );
}

/**
 * Query Paystack API to verify a transaction.
 *
 * @param string $reference
 * @param array|null $mock_api_response Optional mock response for automated testing
 * @return array|WP_Error Paystack data array or WP_Error
 */
function propvendor_marketplace_verify_paystack_transaction($reference, $mock_api_response = null) {
    $reference = sanitize_text_field(trim($reference));
    if (empty($reference)) {
        return new WP_Error('empty_reference', __('Transaction reference cannot be empty.', 'propvendor-marketplace'));
    }

    // Allow mock response strictly in automated test environment
    if (null !== $mock_api_response) {
        if (!defined('PROPVENDOR_TESTING') || !PROPVENDOR_TESTING) {
            return new WP_Error('mock_forbidden', __('Mock verification is strictly forbidden outside the test environment.', 'propvendor-marketplace'));
        }
        if (is_wp_error($mock_api_response)) {
            return $mock_api_response;
        }
        if (is_array($mock_api_response)) {
            if (!empty($mock_api_response['status']) && !empty($mock_api_response['data']) && 'success' === $mock_api_response['data']['status']) {
                return $mock_api_response['data'];
            }
            $msg = !empty($mock_api_response['message']) ? $mock_api_response['message'] : __('Paystack verification failed.', 'propvendor-marketplace');
            return new WP_Error('payment_not_successful', $msg, $mock_api_response);
        }
    }

    $secret_key = propvendor_marketplace_get_paystack_secret_key();
    if (empty($secret_key)) {
        return new WP_Error('paystack_not_configured', __('Paystack Secret Key is not configured in PropVendor settings.', 'propvendor-marketplace'));
    }

    $url = 'https://api.paystack.co/transaction/verify/' . rawurlencode($reference);
    $response = wp_remote_get($url, array(
        'timeout' => 25,
        'headers' => array(
            'Authorization' => 'Bearer ' . $secret_key,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ),
    ));

    if (is_wp_error($response)) {
        return new WP_Error('http_error', sprintf(__('Failed to communicate with Paystack: %s', 'propvendor-marketplace'), $response->get_error_message()));
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($code !== 200 || !is_array($data) || empty($data['status'])) {
        $msg = !empty($data['message']) ? $data['message'] : __('Paystack transaction could not be verified.', 'propvendor-marketplace');
        return new WP_Error('verification_failed', $msg, array('status_code' => $code, 'body' => $data));
    }

    if (empty($data['data']) || 'success' !== $data['data']['status']) {
        return new WP_Error('payment_not_success', __('Payment was not successful.', 'propvendor-marketplace'), $data);
    }

    return $data['data'];
}

/**
 * Verify payment with Paystack and fulfill entitlements atomically.
 *
 * @param string $reference
 * @param array|null $mock_api_response Optional mock response for automated testing
 * @return array|WP_Error
 */
function propvendor_marketplace_verify_and_fulfill_order($reference, $mock_api_response = null) {
    $reference = sanitize_text_field(trim($reference));
    if (empty($reference)) {
        return new WP_Error('empty_reference', __('Transaction reference cannot be empty.', 'propvendor-marketplace'));
    }

    global $wpdb;
    $table_orders = $wpdb->prefix . 'propvendor_orders';
    $table_plans  = $wpdb->prefix . 'propvendor_plans';

    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_orders)) !== $table_orders) {
        propvendor_marketplace_create_payment_tables();
    }

    // Mutex locking to prevent concurrent race conditions
    $lock_name     = 'pv_order_' . md5($reference);
    $lock_acquired = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 15)", $lock_name));

    if ('1' !== (string) $lock_acquired) {
        return new WP_Error('lock_timeout', __('Could not acquire processing lock for order.', 'propvendor-marketplace'));
    }

    try {
        // Find order under lock
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_orders} WHERE transaction_reference = %s LIMIT 1",
            $reference
        ));

        if (!$order) {
            return new WP_Error('order_not_found', __('Order with the specified transaction reference does not exist.', 'propvendor-marketplace'));
        }

        // Idempotency: Already fulfilled
        if ('successful' === $order->status) {
            return array(
                'success'           => true,
                'already_fulfilled' => true,
                'order_id'          => (int) $order->id,
                'reference'         => $reference,
                'vendor_id'         => (int) $order->vendor_id,
                'plan_id'           => (int) $order->plan_id,
                'amount_kobo'       => (int) $order->amount_kobo,
                'message'           => __('Order has already been fulfilled.', 'propvendor-marketplace'),
            );
        }

        // Query Paystack (or mock if testing)
        $paystack_data = propvendor_marketplace_verify_paystack_transaction($reference, $mock_api_response);
        if (is_wp_error($paystack_data)) {
            $wpdb->update(
                $table_orders,
                array('status' => 'failed', 'updated_at' => current_time('mysql')),
                array('id' => $order->id),
                array('%s', '%s'),
                array('%d')
            );
            return $paystack_data;
        }

        // Security check 1: Amount match (strict integer kobo equality)
        $paid_kobo  = isset($paystack_data['amount']) ? (int) $paystack_data['amount'] : 0;
        $order_kobo = (int) $order->amount_kobo;

        if ($paid_kobo !== $order_kobo) {
            $wpdb->update(
                $table_orders,
                array('status' => 'failed', 'updated_at' => current_time('mysql')),
                array('id' => $order->id),
                array('%s', '%s'),
                array('%d')
            );
            return new WP_Error(
                'amount_mismatch',
                sprintf(__('Paid amount (%d kobo) does not match required order amount (%d kobo).', 'propvendor-marketplace'), $paid_kobo, $order_kobo)
            );
        }

        // Security check 2: Reference match
        if (empty($paystack_data['reference']) || $paystack_data['reference'] !== $reference) {
            $wpdb->update(
                $table_orders,
                array('status' => 'failed', 'updated_at' => current_time('mysql')),
                array('id' => $order->id),
                array('%s', '%s'),
                array('%d')
            );
            return new WP_Error('reference_mismatch', __('Transaction reference does not match the order.', 'propvendor-marketplace'));
        }

        // Security check 3: Currency match (NGN only)
        if (empty($paystack_data['currency']) || strtoupper(trim((string) $paystack_data['currency'])) !== 'NGN') {
            $wpdb->update(
                $table_orders,
                array('status' => 'failed', 'updated_at' => current_time('mysql')),
                array('id' => $order->id),
                array('%s', '%s'),
                array('%d')
            );
            return new WP_Error('currency_mismatch', __('Transaction currency must be NGN.', 'propvendor-marketplace'));
        }

        // Fetch Plan
        $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_plans} WHERE id = %d", (int) $order->plan_id));
        if (!$plan) {
            return new WP_Error('plan_not_found', __('Plan associated with this order could not be found.', 'propvendor-marketplace'));
        }

        // Atomic fulfillment wrapped in DB transaction
        $wpdb->query('START TRANSACTION');

        $fulfillment_result = null;
        if ('vip_agent' === $plan->product_type) {
            $fulfillment_result = propvendor_marketplace_grant_vip_agent_entitlement(
                (int) $order->vendor_id,
                (int) $plan->duration_days,
                $reference
            );
        } elseif ('credit_pack' === $plan->product_type) {
            $fulfillment_result = propvendor_marketplace_grant_vendor_credits(
                (int) $order->vendor_id,
                (int) $plan->credits,
                $reference,
                $plan->name
            );
        } else {
            $wpdb->query('ROLLBACK');
            return new WP_Error('unsupported_product_type', __('Unsupported product type.', 'propvendor-marketplace'));
        }

        if (is_wp_error($fulfillment_result)) {
            $wpdb->query('ROLLBACK');
            return $fulfillment_result;
        }

        // Mark order as successful
        $updated = $wpdb->update(
            $table_orders,
            array(
                'status'     => 'successful',
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $order->id),
            array('%s', '%s'),
            array('%d')
        );

        if (false === $updated) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('order_update_failed', __('Failed to update order status.', 'propvendor-marketplace'));
        }

        $wpdb->query('COMMIT');

        return array(
            'success'           => true,
            'already_fulfilled' => false,
            'order_id'          => (int) $order->id,
            'reference'         => $reference,
            'vendor_id'         => (int) $order->vendor_id,
            'plan_id'           => (int) $order->plan_id,
            'plan_name'         => $plan->name,
            'product_type'      => $plan->product_type,
            'amount_kobo'       => $order_kobo,
            'credits'           => (int) $plan->credits,
            'duration_days'     => (int) $plan->duration_days,
            'details'           => $fulfillment_result,
        );
    } finally {
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
    }
}

/**
 * Register REST API routes for Paystack webhook and direct verification.
 */
function propvendor_marketplace_register_payment_rest_routes() {
    register_rest_route('propvendor/v1', '/paystack-webhook', array(
        'methods'             => 'POST',
        'callback'            => 'propvendor_marketplace_handle_paystack_webhook',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('propvendor/v1', '/verify-payment', array(
        'methods'             => 'POST',
        'callback'            => 'propvendor_marketplace_handle_rest_verify_payment',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'propvendor_marketplace_register_payment_rest_routes');

/**
 * Handle incoming Paystack webhook events.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function propvendor_marketplace_handle_paystack_webhook(WP_REST_Request $request) {
    $secret_key = propvendor_marketplace_get_paystack_secret_key();
    if (empty($secret_key)) {
        return new WP_REST_Response(array('message' => 'Paystack secret key not configured.'), 400);
    }

    $signature = $request->get_header('x-paystack-signature');
    $payload   = $request->get_body();

    // Verify signature
    $computed_signature = hash_hmac('sha512', $payload, $secret_key);
    if (!hash_equals($computed_signature, (string) $signature)) {
        return new WP_REST_Response(array('message' => 'Invalid webhook signature.'), 401);
    }

    $event = json_decode($payload, true);
    if (!is_array($event) || empty($event['event'])) {
        return new WP_REST_Response(array('message' => 'Invalid event payload.'), 400);
    }

    if ('charge.success' === $event['event']) {
        $reference = isset($event['data']['reference']) ? sanitize_text_field($event['data']['reference']) : '';
        if (!empty($reference)) {
            $result = propvendor_marketplace_verify_and_fulfill_order($reference);
            if (is_wp_error($result)) {
                return new WP_REST_Response(array('message' => $result->get_error_message()), 400);
            }
            return new WP_REST_Response(array('message' => 'Order fulfilled successfully.', 'result' => $result), 200);
        }
    }

    return new WP_REST_Response(array('message' => 'Event acknowledged.'), 200);
}

/**
 * Handle REST direct payment verification request.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function propvendor_marketplace_handle_rest_verify_payment(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $reference = isset($params['reference']) ? sanitize_text_field($params['reference']) : '';
    if (empty($reference) && isset($_POST['reference'])) {
        $reference = sanitize_text_field($_POST['reference']);
    }

    if (empty($reference)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Reference is required.'), 400);
    }

    $result = propvendor_marketplace_verify_and_fulfill_order($reference);
    if (is_wp_error($result)) {
        return new WP_REST_Response(array('success' => false, 'message' => $result->get_error_message()), 400);
    }

    return new WP_REST_Response($result, 200);
}

/**
 * AJAX Handler: Create checkout order.
 */
function propvendor_marketplace_ajax_create_checkout_order() {
    check_ajax_referer('propvendor_checkout_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => __('You must be logged in to make a purchase.', 'propvendor-marketplace')));
    }

    $vendor_id = get_current_user_id();
    $plan_id   = isset($_POST['plan_id']) ? absint($_POST['plan_id']) : 0;

    if ($plan_id <= 0) {
        wp_send_json_error(array('message' => __('Invalid plan selected.', 'propvendor-marketplace')));
    }

    $order = propvendor_marketplace_create_order($vendor_id, $plan_id, 'paystack');
    if (is_wp_error($order)) {
        wp_send_json_error(array('message' => $order->get_error_message()));
    }

    $public_key = propvendor_marketplace_get_paystack_public_key();
    $order['paystack_public_key'] = $public_key;

    wp_send_json_success($order);
}
add_action('wp_ajax_propvendor_create_checkout_order', 'propvendor_marketplace_ajax_create_checkout_order');

/**
 * AJAX Handler: Verify and fulfill payment after inline popup success.
 */
function propvendor_marketplace_ajax_verify_payment() {
    check_ajax_referer('propvendor_checkout_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => __('You must be logged in to verify payment.', 'propvendor-marketplace')));
    }

    $reference = isset($_POST['reference']) ? sanitize_text_field(wp_unslash($_POST['reference'])) : '';
    if (empty($reference)) {
        wp_send_json_error(array('message' => __('Transaction reference is required.', 'propvendor-marketplace')));
    }

    $result = propvendor_marketplace_verify_and_fulfill_order($reference);
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    wp_send_json_success($result);
}
add_action('wp_ajax_propvendor_verify_payment', 'propvendor_marketplace_ajax_verify_payment');

/**
 * AJAX Handler: Unlock thread lead contact details with 1 credit.
 */
function propvendor_marketplace_ajax_unlock_thread_lead() {
    check_ajax_referer('propvendor_unlock_lead_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array(
            'code'    => 'not_logged_in',
            'message' => __('You must be logged in to unlock contact details.', 'propvendor-marketplace'),
        ), 401);
    }

    $vendor_id = get_current_user_id();
    $thread_id = isset($_POST['thread_id']) ? absint($_POST['thread_id']) : 0;

    if ($thread_id <= 0) {
        wp_send_json_error(array(
            'code'    => 'invalid_thread',
            'message' => __('Invalid thread ID.', 'propvendor-marketplace'),
        ), 400);
    }

    global $wpdb;
    $table_threads   = $wpdb->prefix . 'propvendor_threads';
    $table_enquiries = $wpdb->prefix . 'propvendor_enquiries';

    // Verify thread exists
    $thread = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_threads} WHERE id = %d",
        $thread_id
    ));

    if (!$thread) {
        wp_send_json_error(array(
            'code'    => 'thread_not_found',
            'message' => __('The specified enquiry thread could not be found.', 'propvendor-marketplace'),
        ), 404);
    }

    // Authorization: Vendor must own the thread or be an administrator
    if ((int) $thread->vendor_id !== $vendor_id && !user_can($vendor_id, 'manage_options')) {
        wp_send_json_error(array(
            'code'    => 'unauthorized',
            'message' => __('You are not authorized to unlock contact details for this thread.', 'propvendor-marketplace'),
        ), 403);
    }

    // Call the core atomic entitlement unlock engine
    $unlock_res = propvendor_marketplace_unlock_thread_with_credits($vendor_id, $thread_id);

    if (is_wp_error($unlock_res)) {
        $err_code = $unlock_res->get_error_code();
        $status_code = ('insufficient_credits' === $err_code) ? 400 : 400;
        wp_send_json_error(array(
            'code'            => $err_code,
            'message'         => $unlock_res->get_error_message(),
            'current_balance' => propvendor_marketplace_get_vendor_credit_balance($vendor_id),
            'wallet_url'      => home_url('/vendor-wallet/'),
        ), $status_code);
    }

    // Access confirmed! Fetch real unmasked customer phone & email
    $customer_phone = $wpdb->get_var($wpdb->prepare(
        "SELECT customer_phone FROM {$table_enquiries} WHERE listing_id = %d AND customer_email = %s AND customer_phone != '' ORDER BY id DESC LIMIT 1",
        $thread->listing_id,
        $thread->customer_email
    ));

    $new_balance = propvendor_marketplace_get_vendor_credit_balance($vendor_id);

    wp_send_json_success(array(
        'success'          => true,
        'thread_id'        => $thread_id,
        'already_unlocked' => !empty($unlock_res['already_unlocked']),
        'vip_bypass'       => !empty($unlock_res['vip_bypass']),
        'deducted'         => isset($unlock_res['deducted']) ? (int) $unlock_res['deducted'] : 0,
        'balance'          => $new_balance,
        'customer_name'    => esc_html($thread->customer_name),
        'customer_email'   => sanitize_email($thread->customer_email),
        'customer_phone'   => !empty($customer_phone) ? sanitize_text_field($customer_phone) : '',
        'message'          => !empty($unlock_res['message']) ? $unlock_res['message'] : __('Lead contact details unlocked successfully.', 'propvendor-marketplace'),
    ));
}
add_action('wp_ajax_propvendor_unlock_thread_lead', 'propvendor_marketplace_ajax_unlock_thread_lead');


/**
 * =========================================================================
 * FRONTEND VENDOR WALLET & PRICING SHORTCODE [propvendor_vendor_wallet]
 * =========================================================================
 */

function propvendor_marketplace_vendor_wallet_shortcode($atts = array()) {
    if (!is_user_logged_in()) {
        $login_url = wp_login_url(get_permalink());
        return '<div class="propvendor-wallet-login-prompt"><p>' .
            sprintf(__('Please <a href="%s">log in</a> with your vendor account to access your wallet and subscription options.', 'propvendor-marketplace'), esc_url($login_url)) .
            '</p></div>';
    }

    $vendor_id      = get_current_user_id();
    $credit_balance = propvendor_marketplace_get_vendor_credit_balance($vendor_id);
    $is_vip         = propvendor_marketplace_is_vendor_vip_agent($vendor_id) || propvendor_marketplace_is_vendor_vip($vendor_id);
    $vip_expiry     = get_user_meta($vendor_id, 'propvendor_vip_agent_expires_at', true);
    if (empty($vip_expiry)) {
        $vip_expiry = get_user_meta($vendor_id, 'propvendor_plan_expires_at', true);
    }

    $plans = propvendor_marketplace_get_active_plans();

    // Fetch recent transactions (last 10 orders)
    global $wpdb;
    $table_orders  = $wpdb->prefix . 'propvendor_orders';
    $table_plans   = $wpdb->prefix . 'propvendor_plans';
    $recent_orders = array();
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_orders)) === $table_orders) {
        $recent_orders = $wpdb->get_results($wpdb->prepare(
            "SELECT o.*, p.name as plan_name, p.product_type
             FROM {$table_orders} o
             LEFT JOIN {$table_plans} p ON o.plan_id = p.id
             WHERE o.vendor_id = %d
             ORDER BY o.id DESC LIMIT 10",
            $vendor_id
        ));
    }

    ob_start();
    ?>
    <div class="propvendor-wallet-container" id="propvendor-wallet-app">
        <!-- Wallet Header / Balances -->
        <div class="propvendor-wallet-header">
            <div class="propvendor-wallet-card propvendor-balance-card">
                <div class="propvendor-wallet-card-icon">
                    <span class="dashicons dashicons-money-alt"></span>
                </div>
                <div class="propvendor-wallet-card-info">
                    <span class="propvendor-card-label"><?php esc_html_e('Lead Contact Credits', 'propvendor-marketplace'); ?></span>
                    <span class="propvendor-card-value" id="pv-current-credit-balance"><?php echo esc_html(number_format_i18n($credit_balance)); ?></span>
                    <span class="propvendor-card-subtext"><?php esc_html_e('1 Credit = 1 Lead Contact Unlock', 'propvendor-marketplace'); ?></span>
                </div>
            </div>

            <div class="propvendor-wallet-card propvendor-vip-card <?php echo $is_vip ? 'is-vip-active' : 'is-vip-inactive'; ?>">
                <div class="propvendor-wallet-card-icon">
                    <span class="dashicons dashicons-awards"></span>
                </div>
                <div class="propvendor-wallet-card-info">
                    <span class="propvendor-card-label"><?php esc_html_e('VIP Agent Status', 'propvendor-marketplace'); ?></span>
                    <?php if ($is_vip) : ?>
                        <span class="propvendor-card-value propvendor-status-active"><?php esc_html_e('Active VIP Agent', 'propvendor-marketplace'); ?></span>
                        <span class="propvendor-card-subtext">
                            <?php
                            if (!empty($vip_expiry)) {
                                printf(esc_html__('Expires on: %s', 'propvendor-marketplace'), esc_html(date_i18n(get_option('date_format'), strtotime($vip_expiry))));
                            } else {
                                esc_html_e('Unlimited VIP Plan', 'propvendor-marketplace');
                            }
                            ?>
                        </span>
                    <?php else : ?>
                        <span class="propvendor-card-value propvendor-status-inactive"><?php esc_html_e('Standard Vendor', 'propvendor-marketplace'); ?></span>
                        <span class="propvendor-card-subtext"><?php esc_html_e('Upgrade for unlimited leads & VIP badge', 'propvendor-marketplace'); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Notification container -->
        <div id="propvendor-wallet-notice" class="propvendor-wallet-notice" style="display: none;"></div>

        <!-- Pricing Cards Section -->
        <div class="propvendor-pricing-section">
            <div class="propvendor-section-header">
                <h2><?php esc_html_e('Subscriptions & Credit Packs', 'propvendor-marketplace'); ?></h2>
                <p><?php esc_html_e('Select a package to upgrade your vendor status or purchase lead contact unlock credits.', 'propvendor-marketplace'); ?></p>
            </div>

            <div class="propvendor-pricing-grid">
                <?php foreach ($plans as $plan) :
                    $is_vip_plan = ('vip_agent' === $plan->product_type);
                    $price_naira = number_format($plan->price_kobo / 100);
                ?>
                    <div class="propvendor-pricing-card <?php echo $is_vip_plan ? 'featured-plan' : ''; ?>" data-plan-id="<?php echo esc_attr($plan->id); ?>">
                        <?php if ($is_vip_plan) : ?>
                            <div class="propvendor-card-badge"><?php esc_html_e('Most Popular', 'propvendor-marketplace'); ?></div>
                        <?php elseif ($plan->credits >= 12) : ?>
                            <div class="propvendor-card-badge best-value-badge"><?php esc_html_e('Best Value', 'propvendor-marketplace'); ?></div>
                        <?php endif; ?>

                        <div class="propvendor-plan-header">
                            <h3 class="propvendor-plan-title"><?php echo esc_html($plan->name); ?></h3>
                            <div class="propvendor-plan-price">
                                <span class="currency">&#8358;</span><span class="amount"><?php echo esc_html($price_naira); ?></span>
                                <?php if ($is_vip_plan) : ?>
                                    <span class="period">/ <?php printf(esc_html__('%d days', 'propvendor-marketplace'), (int) $plan->duration_days); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="propvendor-plan-body">
                            <p class="propvendor-plan-desc"><?php echo esc_html($plan->description); ?></p>
                            <ul class="propvendor-plan-features">
                                <?php if ($is_vip_plan) : ?>
                                    <li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Unlimited Direct Lead Phone & Email Unlocks', 'propvendor-marketplace'); ?></li>
                                    <li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Verified VIP Agent Badge On All Listings', 'propvendor-marketplace'); ?></li>
                                    <li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Priority Placement In Discovery Search', 'propvendor-marketplace'); ?></li>
                                    <li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Instant WhatsApp Customer Direct Chat', 'propvendor-marketplace'); ?></li>
                                <?php else : ?>
                                    <li><span class="dashicons dashicons-yes-alt"></span> <?php printf(esc_html__('%d Contact Unlocks (Phone & Email)', 'propvendor-marketplace'), (int) $plan->credits); ?></li>
                                    <li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Credits Never Expire', 'propvendor-marketplace'); ?></li>
                                    <li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Use Anytime Across All Inquiries', 'propvendor-marketplace'); ?></li>
                                    <li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Unlock Leads One At A Time As Needed', 'propvendor-marketplace'); ?></li>
                                <?php endif; ?>
                            </ul>
                        </div>

                        <div class="propvendor-plan-footer">
                            <button type="button"
                                class="button button-primary propvendor-checkout-btn"
                                data-plan-id="<?php echo esc_attr($plan->id); ?>"
                                data-plan-name="<?php echo esc_attr($plan->name); ?>"
                                data-price-kobo="<?php echo esc_attr($plan->price_kobo); ?>">
                                <?php echo $is_vip_plan ? esc_html__('Upgrade to VIP Agent', 'propvendor-marketplace') : esc_html__('Buy Credits Now', 'propvendor-marketplace'); ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Recent Transactions Table -->
        <div class="propvendor-transactions-section">
            <div class="propvendor-section-header">
                <h3><?php esc_html_e('Payment & Order History', 'propvendor-marketplace'); ?></h3>
            </div>
            <?php if (!empty($recent_orders)) : ?>
                <table class="propvendor-table propvendor-transactions-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'propvendor-marketplace'); ?></th>
                            <th><?php esc_html_e('Item', 'propvendor-marketplace'); ?></th>
                            <th><?php esc_html_e('Reference', 'propvendor-marketplace'); ?></th>
                            <th><?php esc_html_e('Amount', 'propvendor-marketplace'); ?></th>
                            <th><?php esc_html_e('Status', 'propvendor-marketplace'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_orders as $o) :
                            $status_class = ('successful' === $o->status) ? 'status-success' : (('pending' === $o->status) ? 'status-pending' : 'status-failed');
                        ?>
                            <tr>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($o->created_at))); ?></td>
                                <td><strong><?php echo esc_html(!empty($o->plan_name) ? $o->plan_name : 'Plan #' . $o->plan_id); ?></strong></td>
                                <td><code><?php echo esc_html($o->transaction_reference); ?></code></td>
                                <td>&#8358;<?php echo esc_html(number_format($o->amount_kobo / 100, 2)); ?></td>
                                <td><span class="propvendor-badge <?php echo esc_attr($status_class); ?>"><?php echo esc_html(ucfirst($o->status)); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="propvendor-no-orders"><?php esc_html_e('No orders placed yet.', 'propvendor-marketplace'); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('propvendor_vendor_wallet', 'propvendor_marketplace_vendor_wallet_shortcode');
