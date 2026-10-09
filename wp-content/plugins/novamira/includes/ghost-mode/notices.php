<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\GhostMode;

if (!defined('ABSPATH')) {
    exit();
}

const PENDING_UPDATE_SCREENS = [
    'dashboard',
    'plugins',
    'update-core',
    'dashboard-network',
    'plugins-network',
    'update-core-network',
];

function render_bypass_notice(): void
{
    if (!bypass_active() || !\novamira_current_user_can_manage() || !is_active(read_policy())) {
        return;
    }

    wp_admin_notice(
        esc_html__(
            'Ghost Mode is bypassed: NOVAMIRA_GHOST_MODE_BYPASS is set in wp-config.php, so every administrator sees Novamira. Remove the constant to hide Novamira again from administrators not listed under Visible to.',
            domain: 'novamira',
        ),
        ['type' => 'warning', 'dismissible' => false],
    );
}

/**
 * @return list<string>
 */
function pending_update_basenames(): array
{
    // @mago-expect analysis:mixed-assignment
    $updates = get_site_transient('update_plugins');
    if (!is_object($updates) || !property_exists($updates, 'response') || !is_array($updates->response)) {
        return [];
    }

    return array_values(array_intersect(canonical_basenames(), array_keys($updates->response)));
}

function render_pending_update_notice(): void
{
    if (!is_active(read_policy()) || !ui_visible() || !current_user_can('update_plugins')) {
        return;
    }
    $screen = get_current_screen();
    if ($screen === null || !in_array($screen->id, PENDING_UPDATE_SCREENS, strict: true)) {
        return;
    }
    if (pending_update_basenames() === []) {
        return;
    }

    $url = is_network_admin() ? network_admin_url('update-core.php') : admin_url('update-core.php');
    wp_admin_notice(
        sprintf(
            '%s <a href="%s">%s</a>',
            esc_html__(
                'A Novamira update is available. Administrators not listed under Visible to do not see it in the Updates screen.',
                domain: 'novamira',
            ),
            esc_url($url),
            esc_html__('Go to Updates', domain: 'novamira'),
        ),
        ['type' => 'info'],
    );
}
