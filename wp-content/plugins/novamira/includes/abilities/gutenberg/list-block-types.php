<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Abilities\Gutenberg;

use WP_Block_Type;
use WP_Block_Type_Registry;

if (!defined('ABSPATH')) {
    exit();
}

const BLOCK_TYPE_LIST_LIMIT = 150;

wp_register_ability('novamira/gutenberg-list-block-types', [
    'label' => __('List Gutenberg Block Types', domain: 'novamira'),
    'description' => __(
        'Enumerates block types registered in the server-side block registry. Without filters it returns a compact per-namespace overview; pass namespace, category, or name_contains to get the matching block list. Server-side registration is indicative, not authoritative: blocks registered only in editor JavaScript may be missing here or expose a partial attribute set (attributes_count is a strong hint — a handful of attributes on a styling-heavy block means the real schema lives client-side). The Block Editor Queue finalizer validates against the real editor registry.',
        domain: 'novamira',
    ),
    'category' => 'gutenberg',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'namespace' => [
                'type' => 'string',
                'description' => 'Only block types in this namespace, e.g. "core", "kadence", "uagb". A trailing slash or a full prefix like "kadence/" is accepted.',
            ],
            'category' => [
                'type' => 'string',
                'description' => 'Only block types registered under this block category slug, e.g. "text", "design".',
            ],
            'name_contains' => [
                'type' => 'string',
                'description' => 'Case-insensitive substring match on the full block name.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'total_registered' => ['type' => 'integer'],
            'namespaces' => ['type' => 'array'],
            'block_types' => ['type' => 'array'],
            'truncated' => ['type' => 'boolean'],
            'source' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => __NAMESPACE__ . '\\gutenberg_list_block_types',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Call without filters first to see which namespaces exist, then filter by namespace to list its blocks. Treat the result as discovery, not validation: a block missing here can still exist in the editor, and a low attributes_count usually means the block registers its real schema client-side only. Use novamira/gutenberg-get-block-type for one block\'s full server-side schema.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function gutenberg_list_block_types(array $input): array
{
    /** @var array<string, WP_Block_Type> $registered */
    $registered = WP_Block_Type_Registry::get_instance()->get_all_registered();
    $namespace_filter = block_type_namespace_filter($input);
    $category_filter = is_string($input['category'] ?? null) ? trim($input['category']) : '';
    $name_contains = is_string($input['name_contains'] ?? null) ? trim($input['name_contains']) : '';
    $has_filter = $namespace_filter !== '' || $category_filter !== '' || $name_contains !== '';

    $response = [
        'total_registered' => count($registered),
        'source' => 'server-registry',
    ];

    if (!$has_filter) {
        $response['namespaces'] = block_type_namespace_overview($registered);
        return $response;
    }

    $matches = [];
    $truncated = false;
    foreach ($registered as $name => $block_type) {
        if (!block_type_matches($name, $block_type, $namespace_filter, $category_filter, $name_contains)) {
            continue;
        }
        if (count($matches) >= BLOCK_TYPE_LIST_LIMIT) {
            $truncated = true;
            break;
        }
        $matches[] = block_type_summary($name, $block_type);
    }

    $response['block_types'] = $matches;
    $response['truncated'] = $truncated;

    return $response;
}

/**
 * @param array<string, WP_Block_Type> $registered
 * @return list<array{namespace: string, count: int}>
 */
function block_type_namespace_overview(array $registered): array
{
    $namespaces = [];
    foreach (array_keys($registered) as $name) {
        $block_namespace = block_type_namespace($name);
        $namespaces[$block_namespace] = ($namespaces[$block_namespace] ?? 0) + 1;
    }
    arsort($namespaces);

    $overview = [];
    foreach ($namespaces as $block_namespace => $count) {
        $overview[] = ['namespace' => $block_namespace, 'count' => $count];
    }

    return $overview;
}

function block_type_matches(
    string $name,
    WP_Block_Type $block_type,
    string $namespace_filter,
    string $category_filter,
    string $name_contains,
): bool {
    if ($namespace_filter !== '' && block_type_namespace($name) !== $namespace_filter) {
        return false;
    }
    if ($category_filter !== '' && ($block_type->category ?? '') !== $category_filter) {
        return false;
    }

    return $name_contains === '' || stripos($name, $name_contains) !== false;
}

/**
 * @param array<string, mixed> $input
 */
function block_type_namespace_filter(array $input): string
{
    $raw = is_string($input['namespace'] ?? null) ? trim($input['namespace']) : '';

    return rtrim($raw, characters: '/');
}

function block_type_namespace(string $name): string
{
    $slash = strpos($name, needle: '/');

    return $slash === false ? 'core' : substr($name, offset: 0, length: $slash);
}

/**
 * @return array<string, mixed>
 */
function block_type_summary(string $name, WP_Block_Type $block_type): array
{
    $attributes = is_array($block_type->attributes) ? $block_type->attributes : [];
    $summary = [
        'name' => $name,
        'title' => $block_type->title,
        'category' => $block_type->category ?? '',
        'dynamic' => $block_type->is_dynamic(),
        'attributes_count' => count($attributes),
    ];

    if (is_array($block_type->parent) && $block_type->parent !== []) {
        $summary['parent'] = array_values(array_map('strval', $block_type->parent));
    }
    if (is_array($block_type->ancestor) && $block_type->ancestor !== []) {
        $summary['ancestor'] = array_values(array_map('strval', $block_type->ancestor));
    }

    return $summary;
}
