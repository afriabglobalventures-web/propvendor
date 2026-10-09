<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Database schema for Novamira Chat sessions.
 */

if (!defined('ABSPATH')) {
    exit();
}

const NOVAMIRA_CHAT_SCHEMA_VERSION = 1;

const NOVAMIRA_CHAT_SCHEMA_VERSION_OPTION = 'novamira_chat_schema_version';

function novamira_chat_wpdb(): wpdb
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;

    /** @var wpdb $wpdb */
    return $wpdb;
}

function novamira_chat_sessions_table(): string
{
    $wpdb = novamira_chat_wpdb();

    return $wpdb->prefix . 'novamira_chat_sessions';
}

function novamira_chat_schema_maybe_install(): void
{
    if ((int) get_option(NOVAMIRA_CHAT_SCHEMA_VERSION_OPTION, default_value: 0) >= NOVAMIRA_CHAT_SCHEMA_VERSION) {
        return;
    }

    novamira_chat_schema_install();
}

// @mago-expect lint:no-boolean-flag-parameter -- WordPress activation callbacks receive the network-wide flag.
function novamira_chat_schema_install(bool $network_wide = false): void
{
    if (is_multisite() && $network_wide) {
        // @mago-expect analysis:mixed-assignment -- WordPress returns site ids when fields=ids.
        $site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
        if (!is_array($site_ids)) {
            return;
        }

        // @mago-expect analysis:mixed-assignment
        foreach ($site_ids as $site_id) {
            switch_to_blog((int) $site_id);
            novamira_chat_schema_install_current_site();
            restore_current_blog();
        }
        return;
    }

    novamira_chat_schema_install_current_site();
}

function novamira_chat_schema_install_current_site(): void
{
    $wpdb = novamira_chat_wpdb();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = novamira_chat_sessions_table();
    $charset_collate = $wpdb->get_charset_collate();

    dbDelta("CREATE TABLE {$table} (
            id VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            data LONGTEXT NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY updated_at (updated_at)
        ) {$charset_collate};");

    update_option(NOVAMIRA_CHAT_SCHEMA_VERSION_OPTION, NOVAMIRA_CHAT_SCHEMA_VERSION, autoload: false);
}
