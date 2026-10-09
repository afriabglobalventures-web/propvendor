<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\GhostMode;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

const OPTION = 'novamira_ghost_mode';

const SCHEMA_VERSION = 1;

const RUNTIME_VERSION = 1;

const SOURCES = ['pro', 'cli', 'free-ui'];

/**
 * @return array{state: 'absent'|'ok'|'unreadable', enabled: bool, visible_to: list<int>, malformed: int, schema_version: int, updated_at: int, updated_by: int, source: string, pro_version: string}
 */
function normalize(mixed $raw): array
{
    $policy = [
        'state' => 'absent',
        'enabled' => false,
        'visible_to' => [],
        'malformed' => 0,
        'schema_version' => 0,
        'updated_at' => 0,
        'updated_by' => 0,
        'source' => '',
        'pro_version' => '',
    ];
    if ($raw === false) {
        return $policy;
    }
    if (!is_array($raw) || !is_bool($raw['enabled'] ?? null)) {
        $policy['state'] = 'unreadable';

        return $policy;
    }

    [$visible_to, $malformed] = parse_user_ids($raw['visible_to'] ?? null);
    $policy['state'] = 'ok';
    $policy['enabled'] = $raw['enabled'];
    $policy['visible_to'] = $visible_to;
    $policy['malformed'] = $malformed;
    $policy['schema_version'] = is_int($raw['schema_version'] ?? null) ? $raw['schema_version'] : 0;
    $policy['updated_at'] = is_int($raw['updated_at'] ?? null) ? $raw['updated_at'] : 0;
    $policy['updated_by'] = is_int($raw['updated_by'] ?? null) ? $raw['updated_by'] : 0;
    $policy['source'] = is_string($raw['source'] ?? null) ? $raw['source'] : '';
    $policy['pro_version'] = is_string($raw['pro_version'] ?? null) ? $raw['pro_version'] : '';

    return $policy;
}

/**
 * @return array{0: list<int>, 1: int} Deduplicated positive IDs, and the number of entries dropped.
 */
function parse_user_ids(mixed $value): array
{
    if ($value === null) {
        return [[], 0];
    }
    if (!is_array($value)) {
        return [[], 1];
    }

    $ids = [];
    $malformed = 0;
    // @mago-expect analysis:mixed-assignment
    foreach ($value as $entry) {
        $id = match (true) {
            is_int($entry) => $entry,
            is_string($entry) && ctype_digit($entry) => (int) $entry,
            default => 0,
        };
        if ($id <= 0) {
            $malformed++;
            continue;
        }
        $ids[$id] = $id;
    }

    return [array_values($ids), $malformed];
}

/**
 * @return array{state: 'absent'|'ok'|'unreadable', enabled: bool, visible_to: list<int>, malformed: int, schema_version: int, updated_at: int, updated_by: int, source: string, pro_version: string}
 */
function read_policy(): array
{
    return normalize(get_site_option(OPTION, default_value: false));
}

/**
 * @param list<int> $ids
 * @return list<int>
 */
function valid_owner_ids(array $ids): array
{
    $valid = [];
    foreach ($ids as $id) {
        if (get_userdata($id) === false || !\novamira_user_can_manage($id)) {
            continue;
        }
        $valid[] = $id;
    }

    return $valid;
}

/**
 * @param array<mixed> $visible_to
 */
// @mago-expect lint:no-boolean-flag-parameter -- $enabled mirrors the stored policy's own boolean field.
function save_policy(
    bool $enabled,
    array $visible_to,
    int $user_id,
    string $source,
    string $pro_version = '',
): ?WP_Error {
    if (!in_array($source, SOURCES, strict: true)) {
        return new WP_Error('novamira_ghost_mode_invalid_source', __('Unknown Ghost Mode source.', domain: 'novamira'));
    }

    [$ids, $malformed] = parse_user_ids($visible_to);
    $valid = valid_owner_ids($ids);
    if ($malformed > 0 || count($valid) !== count($ids)) {
        return new WP_Error('novamira_ghost_mode_invalid_owner', __(
            'Every entry in Visible to must be an administrator who can manage Novamira.',
            domain: 'novamira',
        ));
    }
    if ($enabled && $valid === []) {
        return new WP_Error('novamira_ghost_mode_no_owner', __(
            'Ghost Mode needs at least one administrator in Visible to.',
            domain: 'novamira',
        ));
    }
    if ($enabled && $source !== 'cli' && !in_array($user_id, $valid, strict: true)) {
        return new WP_Error('novamira_ghost_mode_self_missing', __(
            'Add yourself to Visible to before saving, or you would lose access to Novamira.',
            domain: 'novamira',
        ));
    }

    write(record($enabled, $ids, $user_id, $source, $pro_version));

    return null;
}

function disable(int $user_id, string $source): ?WP_Error
{
    if (!in_array($source, SOURCES, strict: true)) {
        return new WP_Error('novamira_ghost_mode_invalid_source', __('Unknown Ghost Mode source.', domain: 'novamira'));
    }

    $policy = read_policy();
    if ($policy['state'] === 'absent') {
        return null;
    }
    write(record(false, $policy['visible_to'], $user_id, $source, $policy['pro_version']));

    return null;
}

function add_owner(int $owner_id, int $user_id, string $source): ?WP_Error
{
    if (!in_array($source, SOURCES, strict: true)) {
        return new WP_Error('novamira_ghost_mode_invalid_source', __('Unknown Ghost Mode source.', domain: 'novamira'));
    }

    $policy = read_policy();
    if ($policy['state'] === 'unreadable') {
        return new WP_Error('novamira_ghost_mode_unreadable', __(
            'The Ghost Mode settings cannot be read. Turn Ghost Mode off first.',
            domain: 'novamira',
        ));
    }
    if (valid_owner_ids([$owner_id]) === []) {
        return new WP_Error('novamira_ghost_mode_invalid_owner', __(
            'Every entry in Visible to must be an administrator who can manage Novamira.',
            domain: 'novamira',
        ));
    }

    $ids = $policy['visible_to'];
    if (!in_array($owner_id, $ids, strict: true)) {
        $ids[] = $owner_id;
    }
    write(record($policy['enabled'], $ids, $user_id, $source, $policy['pro_version']));

    return null;
}

/**
 * @param list<int> $visible_to
 * @return array{schema_version: int, enabled: bool, visible_to: list<int>, updated_at: int, updated_by: int, source: string, pro_version: string}
 */
function record(bool $enabled, array $visible_to, int $user_id, string $source, string $pro_version): array
{
    return [
        'schema_version' => SCHEMA_VERSION,
        'enabled' => $enabled,
        'visible_to' => $visible_to,
        'updated_at' => time(),
        'updated_by' => $user_id,
        'source' => $source,
        'pro_version' => $pro_version,
    ];
}

/**
 * @param array<string, mixed> $record
 */
function write(array $record): void
{
    update_site_option(OPTION, $record);
    forget_visibility();
}
