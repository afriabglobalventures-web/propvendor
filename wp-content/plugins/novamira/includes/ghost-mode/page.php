<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\GhostMode;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

const PAGE_SLUG = 'novamira-ghost-mode';

const DISABLE_ACTION = 'novamira_ghost_mode_disable';

const PANEL_HOOK = 'novamira_ghost_mode_panel';

function page_url(): string
{
    return admin_url('admin.php?page=' . PAGE_SLUG);
}

function register_menu(): void
{
    add_submenu_page(
        parent_slug: 'novamira-connect',
        page_title: __('Ghost Mode', domain: 'novamira'),
        menu_title: __('Ghost Mode', domain: 'novamira'),
        capability: \novamira_manage_capability(),
        menu_slug: PAGE_SLUG,
        callback: __NAMESPACE__ . '\\render_page',
    );
}

function enqueue_page_assets(string $hook): void
{
    if ($hook !== 'novamira_page_' . PAGE_SLUG) {
        return;
    }

    $path = __DIR__ . '/assets/page.css';
    $version = defined('WP_DEBUG') && constant('WP_DEBUG') === true && is_file($path)
        ? (string) filemtime($path)
        : NOVAMIRA_VERSION;

    wp_enqueue_style(
        'novamira-ghost-mode',
        (string) NOVAMIRA_PLUGIN_URL . 'includes/ghost-mode/assets/page.css',
        [],
        $version,
    );
}

function render_page(): void
{
    if (!\novamira_current_user_can_manage() || !ui_visible()) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', domain: 'default'), args: [
            'response' => 403,
        ]);
    }
    if (has_action(PANEL_HOOK)) {
        do_action(PANEL_HOOK);

        return;
    }

    $status = status();
    \novamira_render_admin_header();
    ?>
    <div class="wrap novamira-ghost-mode">
        <h1 class="wp-heading-inline"><?php esc_html_e('Ghost Mode', domain: 'novamira'); ?></h1>
        <hr class="wp-header-end">
        <p class="novamira-gm-lead"><?php esc_html_e(
            'Show Novamira only to the administrators you choose.',
            domain: 'novamira',
        ); ?></p>
        <?php render_disabled_notice(); ?>
        <?php

        match (true) {
            $status['state'] === 'off' => render_presentation(),
            default => render_status($status),
        };
        ?>
    </div>
    <?php
}

function render_disabled_notice(): void
{
    if (($_GET['novamira_ghost_mode'] ?? null) !== 'disabled') {
        return;
    }
    wp_admin_notice(esc_html__('Ghost Mode is off. Every administrator sees Novamira again.', domain: 'novamira'), [
        'type' => 'success',
    ]);
}

function render_presentation(): void
{ ?>
    <div class="novamira-gm-compare">
        <div class="novamira-gm-card novamira-gm-team">
            <h2><span class="dashicons dashicons-visibility" aria-hidden="true"></span><?php esc_html_e(
                'Administrators you choose',
                domain: 'novamira',
            ); ?></h2>
            <ul class="novamira-gm-list">
                <li><?php esc_html_e('The Novamira menu and admin bar entry', domain: 'novamira'); ?></li>
                <li><?php esc_html_e('Novamira notices', domain: 'novamira'); ?></li>
                <li><?php esc_html_e('Novamira in Plugins and Updates', domain: 'novamira'); ?></li>
            </ul>
        </div>
        <div class="novamira-gm-card novamira-gm-client">
            <h2><span class="dashicons dashicons-hidden" aria-hidden="true"></span><?php esc_html_e(
                'Other administrators',
                domain: 'novamira',
            ); ?></h2>
            <ul class="novamira-gm-list">
                <li><?php esc_html_e('No Novamira menu or admin bar entry', domain: 'novamira'); ?></li>
                <li><?php esc_html_e('No Novamira notices', domain: 'novamira'); ?></li>
                <li><?php esc_html_e('No Novamira rows in Plugins and Updates', domain: 'novamira'); ?></li>
            </ul>
        </div>
    </div>
    <?php render_limits(); ?>
    <?php if (\novamira_pro_is_active()): ?>
        <div class="novamira-gm-card novamira-gm-pro">
            <p><?php esc_html_e(
                'Ghost Mode needs a newer version of Novamira Pro. Update Novamira Pro to turn it on.',
                domain: 'novamira',
            ); ?></p>
            <a href="<?php echo
                esc_url(admin_url('update-core.php'))
            ; ?>" class="button button-primary"><?php esc_html_e('Go to Updates', domain: 'novamira'); ?></a>
        </div>
        <?php return;
    endif; ?>
    <div class="novamira-gm-card novamira-gm-pro">
        <p><?php esc_html_e('Ghost Mode is part of Novamira Pro.', domain: 'novamira'); ?></p>
        <a href="<?php echo
            esc_url(NOVAMIRA_PRO_URL . '?utm_source=plugin&utm_medium=ghost_mode')
        ; ?>" target="_blank" rel="noopener" class="button button-primary"><?php esc_html_e(
            'Get Novamira Pro',
            domain: 'novamira',
        ); ?></a>
    </div>
    <?php }

function render_security_callout(): void
{ ?>
    <div class="novamira-gm-security" role="note">
        <p><strong><?php esc_html_e(
            'Ghost Mode is not a security feature.',
            domain: 'novamira',
        ); ?></strong> <?php esc_html_e(
            'It hides the Novamira menu, its admin bar entry, its notices and its rows in the Plugins and Updates screens, but it changes what other administrators see, not what they can do: they can still find Novamira, connect an AI to it, or keep the connection they already have.',
            domain: 'novamira',
        ); ?></p>
    </div>
    <?php }

function render_limits(): void
{
    render_security_callout(); ?>
    <div class="novamira-gm-card novamira-gm-keypoints">
        <p><strong><?php esc_html_e('Updates are up to you.', domain: 'novamira'); ?></strong> <?php esc_html_e(
            'Administrators not listed under Visible to do not see Novamira updates, so keeping Novamira up to date is the responsibility of the administrators who do.',
            domain: 'novamira',
        ); ?></p>
    </div>
    <?php
}

/**
 * @param array{state: 'off'|'on'|'unreadable', bypass: bool, owners: list<int>, ignored_ids: list<int>, malformed: int, fail_closed: bool, updated_at: int, updated_by: int, source: string} $status
 */
function render_status(array $status): void
{
    $alert = $status['state'] === 'unreadable' || $status['fail_closed'];
    ?>
    <div class="novamira-gm-card">
        <div class="novamira-gm-status-head">
            <h2><?php esc_html_e('Status', domain: 'novamira'); ?></h2>
            <span class="novamira-gm-pill <?php echo $alert ? 'is-alert' : 'is-on'; ?>"><?php echo
                $alert ? esc_html__('Needs attention', domain: 'novamira') : esc_html__('On', domain: 'novamira')
            ; ?></span>
        </div>
        <?php if ($status['state'] !== 'unreadable'): ?>
            <p class="novamira-gm-label"><?php esc_html_e('Visible to', domain: 'novamira'); ?></p>
            <ul class="novamira-gm-chips">
                <?php foreach ($status['owners'] as $owner_id): ?>
                    <li><?php echo esc_html(describe_user($owner_id)); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php render_status_details($status); ?>
        <p><?php echo
            \novamira_pro_is_active()
                ? esc_html__('Update Novamira Pro to change who sees Novamira.', domain: 'novamira')
                : esc_html__('To change who sees Novamira, activate Novamira Pro.', domain: 'novamira')
        ; ?></p>
    </div>
    <?php render_limits(); ?>
    <div class="novamira-gm-card novamira-gm-off">
        <h2><?php esc_html_e('Turn off Ghost Mode', domain: 'novamira'); ?></h2>
        <?php render_disable_form(); ?>
    </div>
    <?php
}

/**
 * @param array{state: 'off'|'on'|'unreadable', bypass: bool, owners: list<int>, ignored_ids: list<int>, malformed: int, fail_closed: bool, updated_at: int, updated_by: int, source: string} $status
 */
function render_status_details(array $status): void
{
    $ignored = count($status['ignored_ids']) + $status['malformed'];
    ?>
    <?php if ($status['state'] === 'unreadable'): ?>
        <p class="novamira-gm-warning is-error"><?php esc_html_e(
            'The Ghost Mode settings cannot be read, so Novamira is hidden from every administrator.',
            domain: 'novamira',
        ); ?></p>
    <?php endif; ?>
    <?php if ($status['state'] !== 'unreadable' && $status['fail_closed']): ?>
        <p class="novamira-gm-warning is-error"><?php esc_html_e(
            'No administrator on the list can manage Novamira, so it is hidden from everyone.',
            domain: 'novamira',
        ); ?></p>
    <?php endif; ?>
    <?php if ($ignored > 0): ?>
        <p class="novamira-gm-warning"><?php echo
            esc_html(sprintf(
                _n(
                    single: '%d entry in the list is not an administrator who can manage Novamira and is ignored.',
                    plural: '%d entries in the list are not administrators who can manage Novamira and are ignored.',
                    number: $ignored,
                    domain: 'novamira',
                ),
                $ignored,
            ))
        ; ?></p>
    <?php endif; ?>
    <?php if ($status['bypass']): ?>
        <p class="novamira-gm-warning"><?php esc_html_e(
            'NOVAMIRA_GHOST_MODE_BYPASS is set in wp-config.php, so every administrator sees Novamira right now.',
            domain: 'novamira',
        ); ?></p>
    <?php endif; ?>
    <?php
}

function render_disable_form(): void
{ ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="<?php echo esc_attr(DISABLE_ACTION); ?>">
        <?php wp_nonce_field(DISABLE_ACTION); ?>
        <label>
            <input type="checkbox" name="confirm" value="1" required>
            <?php esc_html_e('I understand that every administrator will see Novamira again.', domain: 'novamira'); ?>
        </label>
        <?php submit_button(
            __('Turn off Ghost Mode', domain: 'novamira'),
            type: 'secondary',
            name: 'submit',
            wrap: false,
        ); ?>
    </form>
    <?php }

function handle_disable(): void
{
    if (!\novamira_current_user_can_manage() || !ui_visible()) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', domain: 'default'), args: [
            'response' => 403,
        ]);
    }
    check_admin_referer(DISABLE_ACTION);
    if (!array_key_exists('confirm', $_POST)) {
        wp_die(esc_html__('Confirm that every administrator will see Novamira again.', domain: 'novamira'), args: [
            'response' => 400,
        ]);
    }

    $error = disable(get_current_user_id(), \novamira_pro_is_active() ? 'pro' : 'free-ui');
    if ($error instanceof WP_Error) {
        wp_die(esc_html($error->get_error_message()), args: ['response' => 500]);
    }

    $query = ['novamira_ghost_mode' => 'disabled'];
    wp_safe_redirect(add_query_arg($query, page_url()));
    exit();
}

function describe_user(int $user_id): string
{
    $user = get_userdata($user_id);
    if ($user === false) {
        /* translators: %d: user ID. */
        return sprintf(__('User #%d', domain: 'novamira'), $user_id);
    }

    if ($user->display_name === '' || $user->display_name === $user->user_login) {
        return $user->user_login;
    }

    return sprintf('%s (%s)', $user->display_name, $user->user_login);
}
