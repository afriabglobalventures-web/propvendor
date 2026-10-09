<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\GhostMode;

if (!defined('ABSPATH')) {
    exit();
}

// @mago-expect lint:file-name -- this file is the visibility module; forget_visibility() lives beside it.
final class VisibilityCache
{
    /** @var array<int, bool> */
    public static array $by_user = [];
}

function forget_visibility(): void
{
    VisibilityCache::$by_user = [];
}

const BYPASS_CONSTANT = 'NOVAMIRA_GHOST_MODE_BYPASS';

function bypass_active(): bool
{
    return defined(BYPASS_CONSTANT) && constant(BYPASS_CONSTANT) === true;
}

/**
 * @param array{state: 'absent'|'ok'|'unreadable', enabled: bool, visible_to: list<int>, malformed: int, schema_version: int, updated_at: int, updated_by: int, source: string, pro_version: string} $policy
 */
function is_active(array $policy): bool
{
    return $policy['state'] === 'unreadable' || $policy['state'] === 'ok' && $policy['enabled'];
}

function ui_visible(): bool
{
    if (!did_action('init')) {
        return false;
    }
    if (!\novamira_current_user_can_manage()) {
        return false;
    }
    if (bypass_active()) {
        return true;
    }

    $user_id = get_current_user_id();
    if (!array_key_exists($user_id, VisibilityCache::$by_user)) {
        $policy = read_policy();
        VisibilityCache::$by_user[$user_id] =
            !is_active($policy)
            || $policy['state'] === 'ok' && in_array($user_id, valid_owner_ids($policy['visible_to']), strict: true);
    }

    return VisibilityCache::$by_user[$user_id];
}

function hidden_by_policy(): bool
{
    return did_action('init') > 0 && \novamira_current_user_can_manage() && !ui_visible();
}

/**
 * @return array{state: 'off'|'on'|'unreadable', bypass: bool, owners: list<int>, ignored_ids: list<int>, malformed: int, fail_closed: bool, updated_at: int, updated_by: int, source: string}
 */
function status(): array
{
    $policy = read_policy();
    $owners = valid_owner_ids($policy['visible_to']);
    $state = match (true) {
        $policy['state'] === 'unreadable' => 'unreadable',
        $policy['enabled'] => 'on',
        default => 'off',
    };

    return [
        'state' => $state,
        'bypass' => bypass_active(),
        'owners' => $owners,
        'ignored_ids' => array_values(array_diff($policy['visible_to'], $owners)),
        'malformed' => $policy['malformed'],
        'fail_closed' => $state === 'unreadable' || $state === 'on' && $owners === [],
        'updated_at' => $policy['updated_at'],
        'updated_by' => $policy['updated_by'],
        'source' => $policy['source'],
    ];
}
