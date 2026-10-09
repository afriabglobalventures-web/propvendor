<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Abilities\Gutenberg;

use WP_Block_Type;
use WP_Block_Type_Registry;
use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

const BLOCK_TYPE_LIKELY_PARTIAL_THRESHOLD = 5;

wp_register_ability('novamira/gutenberg-get-block-type', [
    'label' => __('Get Gutenberg Block Type', domain: 'novamira'),
    'description' => __(
        'Reads one block type\'s full server-side schema: attribute map (type, default, enum, source), supports, block context, and parent/ancestor constraints. Server-side registration is indicative, not authoritative: blocks registered only in editor JavaScript are missing here entirely, and others register a stripped-down attribute set server-side (likely_partial flags that heuristic). Schemas also never list editor-assigned attributes such as per-block style IDs — the Block Editor Queue finalizer assigns and validates those against the real editor.',
        domain: 'novamira',
    ),
    'category' => 'gutenberg',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'name' => [
                'type' => 'string',
                'description' => 'Full block name, e.g. "core/paragraph" or "kadence/rowlayout".',
            ],
        ],
        'required' => ['name'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'category' => ['type' => 'string'],
            'api_version' => ['type' => 'integer'],
            'dynamic' => ['type' => 'boolean'],
            'attributes' => ['type' => 'object'],
            'attributes_count' => ['type' => 'integer'],
            'likely_partial' => ['type' => 'boolean'],
            'supports' => ['type' => 'object'],
            'parent' => ['type' => 'array'],
            'ancestor' => ['type' => 'array'],
            'uses_context' => ['type' => 'array'],
            'provides_context' => ['type' => 'object'],
            'source' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => __NAMESPACE__ . '\\gutenberg_get_block_type',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use this before composing a block_spec to check attribute names, types, and parent/ancestor constraints. When likely_partial is true, or the block is not found here at all, the block probably registers client-side only — do not conclude an attribute or the block itself does not exist. Attribute schemas are shape-only (no value-domain validation) and never include editor-assigned attributes; final validation happens in the Block Editor Queue.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function gutenberg_get_block_type(array $input): array|WP_Error
{
    $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
    if ($name === '') {
        return new WP_Error('gutenberg_block_type_name_required', 'A block type name is required.');
    }

    $block_type = WP_Block_Type_Registry::get_instance()->get_registered($name);
    if ($block_type === null) {
        return new WP_Error('gutenberg_block_type_not_found', sprintf(
            'Block type %s is not registered server-side. It may still exist in the editor: some plugins register their blocks in editor JavaScript only. Submitting it through the pending-change pipeline is still possible; the Block Editor Queue validates against the real editor registry.',
            $name,
        ));
    }

    /** @var array<string, mixed> $raw_attributes */
    $raw_attributes = is_array($block_type->attributes) ? $block_type->attributes : [];
    $attributes = [];
    /** @var mixed $definition */
    foreach ($raw_attributes as $attribute_name => $definition) {
        /** @var array<string, mixed> $definition_map */
        $definition_map = is_array($definition) ? $definition : [];
        $attributes[$attribute_name] = block_type_attribute_schema($definition_map);
    }

    $response = [
        'name' => $name,
        'title' => $block_type->title,
        'category' => $block_type->category ?? '',
        'api_version' => (int) $block_type->api_version,
        'dynamic' => $block_type->is_dynamic(),
        'attributes' => $attributes,
        'attributes_count' => count($attributes),
        'likely_partial' => count($attributes) < BLOCK_TYPE_LIKELY_PARTIAL_THRESHOLD,
        'supports' => is_array($block_type->supports) ? $block_type->supports : [],
        'uses_context' => block_type_uses_context($block_type),
        'provides_context' => is_array($block_type->provides_context) ? $block_type->provides_context : [],
        'source' => 'server-registry',
    ];

    if (is_array($block_type->parent) && $block_type->parent !== []) {
        $response['parent'] = array_values(array_map('strval', $block_type->parent));
    }
    if (is_array($block_type->ancestor) && $block_type->ancestor !== []) {
        $response['ancestor'] = array_values(array_map('strval', $block_type->ancestor));
    }

    return $response;
}

/**
 * @return list<string>
 */
function block_type_uses_context(WP_Block_Type $block_type): array
{
    /** @var array<string, mixed> $vars */
    $vars = get_object_vars($block_type);
    /** @var mixed $raw */
    $raw = $vars['uses_context'] ?? null;
    if (!is_array($raw)) {
        return [];
    }

    $context_keys = [];
    /** @var mixed $key */
    foreach ($raw as $key) {
        if (!is_string($key)) {
            continue;
        }

        $context_keys[] = $key;
    }

    return $context_keys;
}

/**
 * @param array<string, mixed> $definition
 * @return array<string, mixed>
 */
function block_type_attribute_schema(array $definition): array
{
    $schema = [];
    foreach (['type', 'default', 'enum', 'source', 'selector', 'attribute'] as $key) {
        if (!array_key_exists($key, $definition)) {
            continue;
        }

        $schema[$key] = $definition[$key];
    }

    return $schema;
}
