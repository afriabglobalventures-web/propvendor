<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\GhostMode;

if (!defined('ABSPATH')) {
    exit();
}

const PROTOCOL_PAGES = ['novamira-oauth-authorize', 'novamira-oauth-consent', 'novamira-oauth-device'];

const TECHNICAL_ACTIONS = [
    'novamira_visual_auth_check',
    'novamira_visual_safe_backend_tools_discover',
    'novamira_visual_safe_backend_tools_call',
];

const HIDDEN_REQUEST_KEYS = ['novamira_notice_dismiss', 'novamira_feature_action'];

function is_hidden_page(string $slug): bool
{
    return str_starts_with($slug, 'novamira') && !in_array($slug, PROTOCOL_PAGES, strict: true);
}

function hide_menus(): void
{
    if (!hidden_by_policy()) {
        return;
    }

    remove_menu_page('novamira-connect');

    // @mago-expect lint:no-global
    global $submenu;
    if (!is_array($submenu)) {
        return;
    }
    unset($submenu['novamira-connect']);
    // @mago-expect analysis:mixed-assignment
    foreach ($submenu as $parent => $items) {
        if (!is_array($items)) {
            continue;
        }
        $submenu[$parent] = array_filter(
            $items,
            static fn(mixed $item): bool => (
                !is_array($item)
                || !is_string($item[2] ?? null)
                || !is_hidden_page($item[2])
            ),
        );
    }
}

function request_targets_hidden_surface(): bool
{
    $page = $_GET['page'] ?? null;
    if (is_string($page) && is_hidden_page($page)) {
        return true;
    }

    // @mago-expect lint:no-global
    global $pagenow;
    if (wp_doing_ajax() || $pagenow === 'admin-post.php') {
        // @mago-expect lint:no-request-variable -- admin-ajax.php and admin-post.php accept the action via GET or POST.
        $action = $_REQUEST['action'] ?? null;
        if (
            is_string($action)
            && str_starts_with($action, 'novamira')
            && !in_array($action, TECHNICAL_ACTIONS, strict: true)
        ) {
            return true;
        }
    }

    foreach (HIDDEN_REQUEST_KEYS as $key) {
        // @mago-expect lint:no-request-variable -- these handlers accept their key via GET or POST.
        if (array_key_exists($key, $_REQUEST)) {
            return true;
        }
    }

    return false;
}

function guard_request(): void
{
    if (!hidden_by_policy() || !request_targets_hidden_surface()) {
        return;
    }

    wp_die(esc_html__('Sorry, you are not allowed to access this page.', domain: 'default'), args: ['response' => 403]);
}

const PRO_CANONICAL_BASENAME = 'novamira-pro/novamira-pro.php';

/**
 * @return list<string>
 */
function canonical_basenames(): array
{
    $basenames = [plugin_basename(dirname(__DIR__, levels: 2) . '/novamira.php'), PRO_CANONICAL_BASENAME];
    if (defined('NOVAMIRA_PRO_PLUGIN_BASE')) {
        $basenames[] = (string) constant('NOVAMIRA_PRO_PLUGIN_BASE');
    }

    return array_values(array_unique($basenames));
}

function in_display_scope(): bool
{
    if (did_action('init') === 0 || wp_doing_cron()) {
        return false;
    }
    foreach (['REST_REQUEST', 'WP_CLI', 'XMLRPC_REQUEST'] as $constant) {
        if (defined($constant) && constant($constant) === true) {
            return false;
        }
    }
    // @mago-expect lint:no-request-variable -- admin-ajax.php accepts the action via GET or POST.
    if (wp_doing_ajax() && ($_REQUEST['action'] ?? null) !== 'search-plugins') {
        return false;
    }

    return hidden_by_policy();
}

function filter_plugin_list(mixed $plugins): mixed
{
    if (!is_array($plugins) || !in_display_scope()) {
        return $plugins;
    }
    foreach (canonical_basenames() as $basename) {
        unset($plugins[$basename]);
    }

    return $plugins;
}

// @mago-expect lint:file-name -- this file is the enforcement module; project_update_transient() lives beside it.
final class UpdateProjection
{
    public static int $suspended = 0;
}

function suspend_update_projection(): void
{
    UpdateProjection::$suspended++;
}

function resume_update_projection(): void
{
    UpdateProjection::$suspended = max(0, UpdateProjection::$suspended - 1);
}

function project_update_transient(mixed $transient): mixed
{
    if (!is_object($transient) || UpdateProjection::$suspended > 0 || !in_display_scope()) {
        return $transient;
    }

    $projected = clone $transient;
    foreach (['response', 'no_update'] as $key) {
        // @mago-expect analysis:ambiguous-object-property-access -- $transient is a stdClass built by wp_update_plugins().
        if (!property_exists($projected, $key) || !is_array($projected->{$key})) {
            continue;
        }
        // @mago-expect analysis:ambiguous-object-property-access -- $transient is a stdClass built by wp_update_plugins().
        $entries = $projected->{$key};
        foreach (canonical_basenames() as $basename) {
            unset($entries[$basename]);
        }
        $projected->{$key} = $entries;
    }

    return $projected;
}
