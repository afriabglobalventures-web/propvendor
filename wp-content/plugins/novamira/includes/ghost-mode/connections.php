<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\GhostMode;

use WP_Application_Passwords;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * @return array<int, array{oauth: list<string>, app_passwords: int}>
 */
function connected_users(): array
{
    $users = [];

    if (
        function_exists('Novamira\OAuth\Connections\oauth_storage_available')
        && \Novamira\OAuth\Connections\oauth_storage_available()
    ) {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $tokens = $wpdb->prefix . 'novamira_oauth_access_tokens';
        $refresh = $wpdb->prefix . 'novamira_oauth_refresh_tokens';
        $clients = $wpdb->prefix . 'novamira_oauth_clients';
        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:non-existent-constant
        // @mago-expect analysis:mixed-argument
        // @mago-expect analysis:possibly-invalid-argument
        $rows = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT at.user_id, c.client_name
         FROM `{$refresh}` rt
         JOIN `{$tokens}` at ON at.identifier_hash = rt.access_token_hash
         JOIN `{$clients}` c ON c.client_id = at.client_id
         WHERE rt.revoked = 0 AND rt.expires_at > %s", gmdate('Y-m-d H:i:s')), ARRAY_A);
        foreach (is_array($rows) ? $rows : [] as $row) {
            // @mago-expect analysis:invalid-array-access
            $user_id = (int) ($row['user_id'] ?? 0);
            // @mago-expect analysis:invalid-array-access
            $name = (string) ($row['client_name'] ?? '');
            if ($user_id <= 0 || $name === '') {
                continue;
            }
            $users[$user_id] ??= ['oauth' => [], 'app_passwords' => 0];
            if (!in_array($name, $users[$user_id]['oauth'], strict: true)) {
                $users[$user_id]['oauth'][] = $name;
            }
        }
    }

    foreach (\novamira_application_password_user_ids() as $user_id) {
        $count = count(array_filter(
            WP_Application_Passwords::get_user_application_passwords($user_id),
            static fn(array $password): bool => \novamira_is_application_password($password),
        ));
        if ($count === 0) {
            continue;
        }
        $users[$user_id] ??= ['oauth' => [], 'app_passwords' => 0];
        $users[$user_id]['app_passwords'] = $count;
    }

    return $users;
}
