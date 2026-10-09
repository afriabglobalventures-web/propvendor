<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\GhostMode\Cli;

use WP_Error;

use function Novamira\GhostMode\add_owner;
use function Novamira\GhostMode\describe_user;
use function Novamira\GhostMode\disable;
use function Novamira\GhostMode\status;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    if (!defined('WP_CLI') || constant('WP_CLI') !== true || !class_exists('WP_CLI')) {
        return;
    }

    \WP_CLI::add_command('novamira ghost-mode status', __NAMESPACE__ . '\\status_command');
    \WP_CLI::add_command('novamira ghost-mode disable', __NAMESPACE__ . '\\disable_command');
    \WP_CLI::add_command('novamira ghost-mode owners add', __NAMESPACE__ . '\\owners_add_command');
}

/**
 * Show whether Ghost Mode is on, who sees Novamira, and anything that needs attention.
 *
 * ## EXAMPLES
 *
 *     wp novamira ghost-mode status
 */
function status_command(): void
{
    $status = status();
    $labels = [
        'off' => __('off', domain: 'novamira'),
        'on' => __('on', domain: 'novamira'),
        'unreadable' => __('unreadable (Novamira is hidden from every administrator)', domain: 'novamira'),
    ];

    \WP_CLI::line(sprintf(__('Ghost Mode: %s', domain: 'novamira'), $labels[$status['state']]));
    \WP_CLI::line(sprintf(
        __('Bypass constant: %s', domain: 'novamira'),
        $status['bypass'] ? __('set', domain: 'novamira') : __('not set', domain: 'novamira'),
    ));
    foreach ($status['owners'] as $owner_id) {
        \WP_CLI::line(sprintf(__('Visible to: %s', domain: 'novamira'), describe_user($owner_id)));
    }
    $ignored = count($status['ignored_ids']) + $status['malformed'];
    if ($ignored > 0) {
        \WP_CLI::warning(sprintf(
            __(
                '%d entries in the list are not administrators who can manage Novamira and are ignored.',
                domain: 'novamira',
            ),
            $ignored,
        ));
    }
    if ($status['updated_at'] > 0) {
        \WP_CLI::line(sprintf(
            __('Last change: %1$s by user %2$d (%3$s)', domain: 'novamira'),
            (string) wp_date('Y-m-d H:i', $status['updated_at']),
            $status['updated_by'],
            $status['source'],
        ));
    }
    if ($status['state'] === 'on' && $status['fail_closed']) {
        \WP_CLI::warning(__(
            'No administrator on the list can manage Novamira, so it is hidden from everyone. Run: wp novamira ghost-mode owners add <user>',
            domain: 'novamira',
        ));
    }
}

/**
 * Turn Ghost Mode off. Every administrator sees Novamira again.
 *
 * ## OPTIONS
 *
 * [--yes]
 * : Skip the confirmation.
 *
 * ## EXAMPLES
 *
 *     wp novamira ghost-mode disable --yes
 *
 * @param list<string> $args
 * @param array<string, mixed> $assoc_args
 */
function disable_command(array $args, array $assoc_args): void
{
    \WP_CLI::confirm(
        __('Turn off Ghost Mode? Every administrator will see Novamira again.', domain: 'novamira'),
        $assoc_args,
    );
    report(disable(user_id: 0, source: 'cli'), __('Ghost Mode is off.', domain: 'novamira'));
}

/**
 * Add an administrator to the list of users who see Novamira. Does not turn Ghost Mode on.
 *
 * ## OPTIONS
 *
 * <user>
 * : User ID, login or email.
 *
 * ## EXAMPLES
 *
 *     wp novamira ghost-mode owners add agency-admin
 *
 * @param list<string> $args
 */
function owners_add_command(array $args): void
{
    $identifier = $args[0] ?? '';
    $user = ctype_digit($identifier) ? get_user_by('id', (int) $identifier) : get_user_by('login', $identifier);
    if ($user === false && str_contains($identifier, '@')) {
        $user = get_user_by('email', $identifier);
    }
    if ($user === false) {
        \WP_CLI::error(sprintf(__('User not found: %s', domain: 'novamira'), $identifier));

        return;
    }

    report(
        add_owner(owner_id: $user->ID, user_id: 0, source: 'cli'),
        sprintf(__('%s now sees Novamira.', domain: 'novamira'), describe_user($user->ID)),
    );
}

function report(?WP_Error $error, string $success): void
{
    if ($error instanceof WP_Error) {
        \WP_CLI::error($error->get_error_message());

        return;
    }
    \WP_CLI::success($success);
}
