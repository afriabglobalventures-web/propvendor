<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Design\Admin;

if (!defined('ABSPATH')) {
    exit();
}

const PAGE_SLUG = 'novamira-design';

function capability(): string
{
    return \novamira_manage_capability();
}

function current_user_can_manage(): bool
{
    return \novamira_current_user_can_manage();
}

function register_menu(): void
{
    add_submenu_page(
        parent_slug: 'novamira-connect',
        page_title: __('Design', domain: 'novamira'),
        menu_title: __('Design', domain: 'novamira'),
        capability: capability(),
        menu_slug: PAGE_SLUG,
        callback: __NAMESPACE__ . '\\render_page',
    );
}

/** Place the Design entry immediately after Skills (`novamira-skills`). */
// @mago-expect lint:no-global
function reorder_submenu(): void
{
    global $submenu;
    if (!is_array($submenu ?? null) || !is_array($submenu['novamira-connect'] ?? null)) {
        return;
    }
    /** @var array<int, array<int, string>> $entries */
    $entries = $submenu['novamira-connect'];
    $self = null;
    foreach ($entries as $key => $entry) {
        if (($entry[2] ?? null) !== PAGE_SLUG) {
            continue;
        }
        $self = $entry;
        unset($entries[$key]);
        break;
    }
    if ($self === null) {
        return;
    }
    $reordered = [];
    $inserted = false;
    foreach ($entries as $entry) {
        $reordered[] = $entry;
        if (!$inserted && ($entry[2] ?? null) === 'novamira-skills') {
            $reordered[] = $self;
            $inserted = true;
        }
    }
    if (!$inserted) {
        $reordered[] = $self;
    }
    $submenu['novamira-connect'] = $reordered;
}

function render_page(): void
{
    if (!current_user_can_manage()) {
        wp_die(__('You do not have permission to manage the design system.', domain: 'novamira'));
    }
    if (($_GET['view'] ?? null) !== null) {
        require __DIR__ . '/templates/detail.php';
        return;
    }
    if (($_GET['design'] ?? null) !== null) {
        require __DIR__ . '/templates/edit.php';
        return;
    }
    if (($_GET['import'] ?? null) !== null) {
        require __DIR__ . '/templates/import.php';
        return;
    }
    require __DIR__ . '/templates/panel.php';
}

function register_post_handlers(): void
{
    add_action('admin_post_novamira_design_activate', __NAMESPACE__ . '\\handle_activate');
    add_action('admin_post_novamira_design_import', __NAMESPACE__ . '\\handle_import');
    add_action('admin_post_novamira_design_save', __NAMESPACE__ . '\\handle_save');
    add_action('admin_post_novamira_design_duplicate', __NAMESPACE__ . '\\handle_duplicate');
    add_action('admin_post_novamira_design_restore', __NAMESPACE__ . '\\handle_restore');
    add_action('admin_post_novamira_design_delete', __NAMESPACE__ . '\\handle_delete');
}

function enqueue_assets(string $hook): void
{
    if ($hook !== 'novamira_page_' . PAGE_SLUG) {
        return;
    }
    wp_enqueue_style(
        'novamira-design-admin',
        (string) NOVAMIRA_PLUGIN_URL . 'includes/design/assets/admin.css',
        [],
        NOVAMIRA_VERSION,
    );
    wp_enqueue_script(
        'novamira-design-admin',
        (string) NOVAMIRA_PLUGIN_URL . 'includes/design/assets/admin.js',
        [],
        NOVAMIRA_VERSION,
        args: true,
    );
    wp_add_inline_script(
        'novamira-design-admin',
        'window.novamiraDesignFontNote = '
        . (string) wp_json_encode([
            'missing' => __(
                'This preview is not showing the design\'s real fonts (%s). The site itself displays them normally. To see them here, press:',
                domain: 'novamira',
            ),
            'load' => __('Preview with Google Fonts', domain: 'novamira'),
            'notOnGoogle' => __('Google Fonts does not have %s.', domain: 'novamira'),
            'copied' => __('Copied', domain: 'novamira'),
        ])
        . ';',
        position: 'before',
    );
    // Fonts the site itself hosts (theme.json, Font Library) render faithfully
    // in the previews; everything is served same-origin.
    add_action('admin_head', static function (): void {
        if (function_exists('wp_print_font_faces')) {
            wp_print_font_faces();
        }
    });
    if (($_GET['design'] ?? null) === null) {
        return;
    }
    $settings = wp_enqueue_code_editor(['type' => 'text/markdown']);
    if ($settings !== false) {
        wp_add_inline_script('code-editor', sprintf(
            'jQuery(function($){ var el=document.getElementById("novamira-design-content"); if(el&&window.wp&&wp.codeEditor){ var inst=wp.codeEditor.initialize(el,%s); window.novamiraDesignEditor=inst&&inst.codemirror?inst.codemirror:null; window.dispatchEvent(new CustomEvent("novamira-design-editor-ready")); } });',
            (string) wp_json_encode($settings),
        ));
    }
}

function require_capability_and_nonce(string $nonce_action): void
{
    if (!current_user_can_manage()) {
        wp_die(__('Not allowed.', domain: 'novamira'), title: '', args: ['response' => 403]);
    }
    check_admin_referer($nonce_action);
}

/** @param array<string, int|string> $args */
function redirect_with_notice(string $type, string $message, array $args = []): void
{
    set_transient(
        'novamira_design_admin_notice_' . get_current_user_id(),
        ['type' => $type, 'message' => $message],
        expiration: 30,
    );
    wp_safe_redirect(add_query_arg(array_merge(['page' => PAGE_SLUG], $args), admin_url('admin.php')));
    exit();
}

function handle_activate(): void
{
    require_capability_and_nonce('novamira_design_activate');
    $slug_raw = $_POST['slug'] ?? '';
    $slug = \Novamira\Design\Parser\normalize_slug(is_string($slug_raw) ? $slug_raw : '');
    $record = $slug !== '' ? \Novamira\Design\Library\find($slug) : null;
    if ($record === null) {
        redirect_with_notice('error', __('That design does not exist.', domain: 'novamira'));
        return;
    }
    $inspection = \Novamira\Design\Contract\inspect($record['content']);
    if (!$inspection['readiness']['ready']) {
        redirect_with_notice('error', \Novamira\Design\Contract\activation_error($inspection));
        return;
    }
    \Novamira\Design\Store\set_active($slug);
    \Novamira\Design\Notices\set_pending_reload_notice();
    redirect_with_notice('success', __('Design activated.', domain: 'novamira'));
}

/**
 * Resolve import content from either the uploaded file or the pasted textarea.
 * Returns null and redirects on upload validation errors; returns empty string
 * when no file was uploaded (caller falls through to empty-content guard).
 *
 * @param array<string, mixed>|null $file
 */
function resolve_import_content(?array $file): ?string
{
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $pasted = $_POST['design_content'] ?? '';
        return is_string($pasted) ? wp_unslash($pasted) : '';
    }
    $name = is_string($file['name'] ?? null) ? $file['name'] : '';
    $tmp = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
    if (!str_ends_with(strtolower($name), '.md')) {
        redirect_with_notice('error', __('Please upload a .md file.', domain: 'novamira'));
        return null;
    }
    if ((int) ($file['size'] ?? 0) > \Novamira\Design\Parser\MAX_BYTES) {
        redirect_with_notice('error', __('File too large (max 1 MB).', domain: 'novamira'));
        return null;
    }
    $raw = $tmp !== '' && is_readable($tmp) ? file_get_contents($tmp) : false;
    if ($raw === false) {
        redirect_with_notice('error', __('Could not read the uploaded file.', domain: 'novamira'));
        return null;
    }
    return $raw;
}

function handle_import(): void
{
    require_capability_and_nonce('novamira_design_import');

    $file = $_FILES['design_file'] ?? null;
    $content = resolve_import_content(is_array($file) ? $file : null);
    if ($content === null) {
        return;
    }

    if (trim($content) === '') {
        redirect_with_notice('error', __('Nothing to import. Upload a file or paste a DESIGN.md.', domain: 'novamira'));
        return;
    }
    if (strlen($content) > \Novamira\Design\Parser\MAX_BYTES) {
        redirect_with_notice('error', __('DESIGN.md exceeds the size limit (max 1 MB).', domain: 'novamira'));
        return;
    }
    if (!\Novamira\Design\Parser\is_valid($content)) {
        redirect_with_notice('error', __(
            'Not a valid DESIGN.md (could not find a name: add YAML front matter or a # heading).',
            domain: 'novamira',
        ));
        return;
    }

    $inspection = \Novamira\Design\Contract\inspect($content);
    $parsed = \Novamira\Design\Parser\parse($content);
    $prospective_slug = \Novamira\Design\Parser\normalize_slug($parsed['name']);
    if (\Novamira\Design\Store\get_active_slug() === $prospective_slug && !$inspection['readiness']['ready']) {
        redirect_with_notice(
            'error',
            __('The active design was not overwritten because the import is incomplete. ', domain: 'novamira')
                . \Novamira\Design\Contract\activation_error($inspection),
        );
        return;
    }

    $result = \Novamira\Design\Store\save($content, slug: null, actor: 'user');
    if ($result['slug'] === '') {
        redirect_with_notice('error', __('Could not save the imported design.', domain: 'novamira'));
        return;
    }
    $activation_blocked = maybe_activate_import($result['slug'], $inspection['readiness']);
    $message = import_success_message($result, $content);
    if ($activation_blocked) {
        $message .=
            ' '
            . __('Saved, but not activated: ', domain: 'novamira')
            . \Novamira\Design\Contract\activation_error($inspection);
    }
    redirect_with_notice($activation_blocked ? 'warning' : 'success', $message);
}

/**
 * Activate a newly imported design when requested and ready. Returns true when
 * the request was intentionally blocked by the semantic contract.
 *
 * @param array{ready: bool, sync_ready: bool, errors: list<string>, warnings: list<string>} $readiness
 */
function maybe_activate_import(string $slug, array $readiness): bool
{
    if (($_POST['activate'] ?? null) === null) {
        return false;
    }
    if (!$readiness['ready']) {
        return true;
    }
    \Novamira\Design\Store\set_active($slug);
    \Novamira\Design\Notices\set_pending_reload_notice();
    return false;
}

/** @param array{slug: string, name: string} $result */
function import_success_message(array $result, string $content): string
{
    $message = sprintf(
        /* translators: %s: design name */
        __('Imported "%s".', domain: 'novamira'),
        $result['name'] !== '' ? $result['name'] : $result['slug'],
    );
    $waivers = \Novamira\Design\Preflight\waivers($content);
    if ($waivers !== []) {
        $message .= sprintf(
            /* translators: %s: list of anti-slop rules this design waives */
            __(' Allows: %s.', domain: 'novamira'),
            implode(' · ', $waivers),
        );
    }
    return $message;
}

function read_design_id(): int
{
    $raw = $_POST['design_id'] ?? $_GET['design'] ?? null;
    return is_scalar($raw) ? (int) $raw : 0;
}

function load_user_design(int $post_id): \WP_Post
{
    // @mago-expect analysis:mixed-assignment
    $post = get_post($post_id);
    if (!$post instanceof \WP_Post || $post->post_type !== \Novamira\Design\Cpt\POST_TYPE) {
        wp_die(__('Design not found.', domain: 'novamira'));
    }
    /** @var \WP_Post $post */
    return $post;
}

function handle_save(): void
{
    $post_id = read_design_id();
    require_capability_and_nonce('novamira_design_save_' . $post_id);
    $post = load_user_design($post_id);

    $content_raw = $_POST['content'] ?? '';
    $content = is_string($content_raw) ? wp_unslash($content_raw) : '';
    if (strlen($content) > \Novamira\Design\Parser\MAX_BYTES) {
        redirect_with_notice('error', __('DESIGN.md exceeds the size limit.', domain: 'novamira'));
        return;
    }
    if (!\Novamira\Design\Parser\is_valid($content)) {
        redirect_with_notice('error', __(
            'Not a valid DESIGN.md (could not find a name: add YAML front matter or a # heading).',
            domain: 'novamira',
        ));
        return;
    }

    $parsed = \Novamira\Design\Parser\parse($content);
    $new_slug = \Novamira\Design\Parser\normalize_slug($parsed['name'] !== '' ? $parsed['name'] : $post->post_name);
    if ($new_slug === '') {
        redirect_with_notice('error', __('Could not derive a slug from the name.', domain: 'novamira'));
        return;
    }

    $was_active = \Novamira\Design\Store\get_active_slug() === $post->post_name;
    $inspection = \Novamira\Design\Contract\inspect($content);
    if ($was_active && !$inspection['readiness']['ready']) {
        redirect_with_notice(
            'error',
            __('The active design was not changed because the edited version is incomplete. ', domain: 'novamira')
                . \Novamira\Design\Contract\activation_error($inspection),
        );
        return;
    }
    \Novamira\Design\Revisions\snapshot_current($post);
    $updated = wp_update_post([
        'ID' => $post_id,
        'post_title' => $parsed['name'] !== '' ? $parsed['name'] : $new_slug,
        'post_name' => $new_slug,
        'post_content' => wp_slash($content),
    ], wp_error: true);
    if (is_wp_error($updated)) {
        redirect_with_notice('error', __('The design could not be saved.', domain: 'novamira'));
        return;
    }
    update_post_meta($post_id, \Novamira\Design\Cpt\META_LAST_ACTOR, meta_value: 'user');
    // WordPress may append a suffix if the slug collides (design -> design-2);
    // read the slug it actually stored so the active pointer never drifts.
    // @mago-expect analysis:mixed-assignment
    $saved_post = get_post($post_id);
    $actual_slug =
        $saved_post instanceof \WP_Post && $saved_post->post_name !== '' ? $saved_post->post_name : $new_slug;
    if ($was_active) {
        \Novamira\Design\Store\set_active($actual_slug);
        \Novamira\Design\Notices\set_pending_reload_notice();
    }

    set_transient(
        'novamira_design_admin_notice_' . get_current_user_id(),
        ['type' => 'success', 'message' => __('Design saved.', domain: 'novamira')],
        expiration: 30,
    );
    wp_safe_redirect(add_query_arg(['page' => PAGE_SLUG, 'design' => $post_id], admin_url('admin.php')));
    exit();
}

function unique_user_slug(string $base): string
{
    $base = \Novamira\Design\Parser\normalize_slug($base);
    if ($base === '') {
        $base = 'design';
    }
    $slug = $base;
    $n = 2;
    while (\Novamira\Design\Store\find_user_post($slug) !== null) {
        $slug = $base . '-' . $n;
        $n++;
    }
    return $slug;
}

function handle_duplicate(): void
{
    require_capability_and_nonce('novamira_design_duplicate');
    $slug_raw = $_POST['slug'] ?? '';
    $slug = \Novamira\Design\Parser\normalize_slug(is_string($slug_raw) ? $slug_raw : '');
    $source = $slug !== '' ? \Novamira\Design\Library\find($slug) : null;
    if ($source === null) {
        redirect_with_notice('error', __('That design does not exist.', domain: 'novamira'));
        return;
    }
    $new_slug = unique_user_slug($slug . '-copy');
    $result = \Novamira\Design\Store\save($source['content'], $new_slug, actor: 'user');
    if ($result['slug'] === '') {
        redirect_with_notice('error', __('Could not duplicate the design.', domain: 'novamira'));
        return;
    }
    $post = \Novamira\Design\Store\find_user_post($result['slug']);
    set_transient(
        'novamira_design_admin_notice_' . get_current_user_id(),
        ['type' => 'success', 'message' => __('Design duplicated. Edit your copy.', domain: 'novamira')],
        expiration: 30,
    );
    $args = ['page' => PAGE_SLUG];
    if ($post instanceof \WP_Post) {
        $args['design'] = $post->ID;
    }
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit();
}

function handle_restore(): void
{
    $post_id = read_design_id();
    $revision_raw = $_POST['revision_id'] ?? 0;
    $revision_id = is_scalar($revision_raw) ? (int) $revision_raw : 0;
    require_capability_and_nonce('novamira_design_restore_' . $revision_id);
    $post = load_user_design($post_id);

    $revision = \Novamira\Design\Revisions\find($post, $revision_id);
    if ($revision === null) {
        redirect_with_notice('error', __('That revision does not belong to this design.', domain: 'novamira'), [
            'view' => $post->post_name,
        ]);
        return;
    }

    $result = \Novamira\Design\Revisions\restore($post, $revision, actor: 'user');
    if (is_wp_error($result)) {
        redirect_with_notice('error', $result->get_error_message(), ['view' => $post->post_name]);
        return;
    }

    if (\Novamira\Design\Store\get_active_slug() === $post->post_name) {
        \Novamira\Design\Notices\set_pending_reload_notice();
    }
    redirect_with_notice('success', __('Design revision restored.', domain: 'novamira'), [
        'view' => $post->post_name,
    ]);
}

function handle_delete(): void
{
    $post_id = read_design_id();
    require_capability_and_nonce('novamira_design_delete_' . $post_id);
    $post = load_user_design($post_id);

    if (\Novamira\Design\Store\get_active_slug() === $post->post_name) {
        delete_option(\Novamira\Design\Cpt\OPTION_ACTIVE);
        \Novamira\Design\Notices\set_pending_reload_notice();
    }
    wp_delete_post($post_id, force_delete: true);
    redirect_with_notice('success', __('Design deleted.', domain: 'novamira'));
}
