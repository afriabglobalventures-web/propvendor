<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Novamira\Design\Admin;
use Novamira\Design\Contract;
use Novamira\Design\Library;
use Novamira\Design\Store;
use Novamira\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

if (!Admin\current_user_can_manage()) {
    wp_die(__('You do not have permission to view this page.', domain: 'novamira'));
}

$library = Library\all();
$active_slug = Store\get_active_slug();
$action_url = admin_url('admin-post.php');

// The page chrome quietly wears the active design's accent colour.
$active = $active_slug !== '' ? Library\find($active_slug) : null;
$page_accent = $active !== null ? Tokens\css_vars(Tokens\extract($active['content']))['--nd-accent'] ?? '' : '';
$page_style = $page_accent !== '' ? '--ds-accent:' . $page_accent : '';
?>
<?php novamira_render_admin_header(
    logo_file: 'novamira-design-logo.svg',
    logo_alt: 'Novamira Design',
    logo_width: 340,
    logo_height: 40,
); ?>
<div class="wrap novamira-design" style="<?php echo esc_attr($page_style); ?>">
    <h1 class="wp-heading-inline"><?php esc_html_e('Design', domain: 'novamira'); ?></h1>
    <a href="<?php echo
        esc_url(add_query_arg(['page' => Admin\PAGE_SLUG, 'import' => 1], admin_url('admin.php')))
    ; ?>" class="page-title-action"><?php esc_html_e('Import DESIGN.md', domain: 'novamira'); ?></a>
    <hr class="wp-header-end" />
    <p class="novamira-design-intro"><?php esc_html_e(
        'Your site has one design direction: the active design your AI builds within. It is created for this site from a brief, not picked from a catalog. On every page the AI also enforces a floor of anti-slop rules.',
        domain: 'novamira',
    ); ?></p>

    <?php if ($library === []): ?>
        <div class="novamira-design-empty">
            <p><?php esc_html_e(
                'No design system yet. Ask your AI agent to create one for this site, or import a DESIGN.md.',
                domain: 'novamira',
            ); ?></p>
        </div>
    <?php endif; ?>

    <div class="novamira-design-gallery">
        <?php foreach ($library as $entry):
            $tokens = Tokens\extract($entry['content']);
            $inspection = Contract\inspect($entry['content']);
            $is_ready = $inspection['readiness']['ready'];
            $vars_style = Tokens\css_vars_string($tokens);
            $accent = Tokens\css_vars($tokens)['--nd-accent'] ?? '';
            $is_active = $entry['slug'] === $active_slug;

            $edit_url = '';
            $post = Store\find_user_post($entry['slug']);
            if ($post instanceof \WP_Post) {
                $edit_url = add_query_arg([
                    'page' => Admin\PAGE_SLUG,
                    'design' => $post->ID,
                ], admin_url('admin.php'));
            }
            $card_style = $accent !== '' ? '--ds-accent:' . $accent : '';
            $view_url = add_query_arg(['page' => Admin\PAGE_SLUG, 'view' => $entry['slug']], admin_url('admin.php'));
            ?>
            <article class="novamira-design-card2<?php echo $is_active ? ' is-active' : ''; ?>" style="<?php echo
                esc_attr($card_style)
            ; ?>">
                <a class="novamira-design-stage" href="<?php echo esc_url($view_url); ?>">
                    <?php require __DIR__ . '/preview.php'; ?>
                </a>
                <div class="novamira-design-card2-body">
                    <div class="novamira-design-card2-head">
                        <h2><a href="<?php echo esc_url($view_url); ?>"><?php echo esc_html($entry['name']); ?></a></h2>
                        <?php if ($is_active): ?>
                            <span class="novamira-design-active-badge"><?php esc_html_e(
                                'Active',
                                domain: 'novamira',
                            ); ?></span>
                        <?php endif; ?>
                        <?php if (!$is_ready): ?>
                            <span class="novamira-design-incomplete-badge"><?php esc_html_e(
                                'Incomplete',
                                domain: 'novamira',
                            ); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($entry['description'] !== ''): ?>
                        <p class="novamira-design-desc"><?php echo esc_html($entry['description']); ?></p>
                    <?php endif; ?>
                    <?php $waivers = Novamira\Design\Preflight\waivers($entry['content']); ?>
                    <?php if ($waivers !== []): ?>
                        <span class="novamira-design-allows"><?php echo
                            esc_html(sprintf(
                                /* translators: %s: list of anti-slop rules this design waives */
                                __('Allows: %s', domain: 'novamira'),
                                implode(' · ', $waivers),
                            ))
                        ; ?><span class="novamira-design-allows-help" title="<?php echo
                            esc_attr__(
                                'Anti-slop rules this design intentionally waives. Novamira normally flags these AI tells; here they count as a deliberate house-style choice, not a mistake.',
                                domain: 'novamira',
                            )
                        ; ?>">?</span></span>
                    <?php endif; ?>
                    <div class="novamira-design-card2-actions">
                        <?php if (!$is_active && $is_ready): ?>
                            <form method="post" action="<?php echo esc_url($action_url); ?>">
                                <?php wp_nonce_field('novamira_design_activate'); ?>
                                <input type="hidden" name="action" value="novamira_design_activate" />
                                <input type="hidden" name="slug" value="<?php echo esc_attr($entry['slug']); ?>" />
                                <button type="submit" class="button button-primary"><?php esc_html_e(
                                    'Activate',
                                    domain: 'novamira',
                                ); ?></button>
                            </form>
                        <?php endif; ?>
                        <?php if (!$is_active && !$is_ready): ?>
                            <button type="button" class="button" disabled title="<?php echo
                                esc_attr(Contract\activation_error($inspection))
                            ; ?>"><?php esc_html_e('Activate', domain: 'novamira'); ?></button>
                        <?php endif; ?>
                        <?php if ($edit_url !== ''): ?>
                            <a class="button-link" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e(
                                'Edit',
                                domain: 'novamira',
                            ); ?></a>
                        <?php endif; ?>
                        <form method="post" action="<?php echo esc_url($action_url); ?>">
                            <?php wp_nonce_field('novamira_design_duplicate'); ?>
                            <input type="hidden" name="action" value="novamira_design_duplicate" />
                            <input type="hidden" name="slug" value="<?php echo esc_attr($entry['slug']); ?>" />
                            <button type="submit" class="button-link"><?php esc_html_e(
                                'Duplicate',
                                domain: 'novamira',
                            ); ?></button>
                        </form>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</div>
