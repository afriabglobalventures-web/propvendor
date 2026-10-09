<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/** @var string $vars_style  Inline CSS custom-property string (already sanitized). */
$vars_style ??= '';
?>
<div class="nd-preview" data-nd-preview style="<?php echo esc_attr($vars_style); ?>">
    <div class="nd-preview-screen">
        <header class="nd-preview-nav">
            <span class="nd-preview-brand"><span class="nd-preview-mark" aria-hidden="true"></span><?php echo
                esc_html(get_bloginfo('name'))
            ; ?></span>
            <nav class="nd-preview-links" aria-hidden="true">
                <span><?php esc_html_e('Work', domain: 'novamira'); ?></span>
                <span><?php esc_html_e('Studio', domain: 'novamira'); ?></span>
                <span><?php esc_html_e('Journal', domain: 'novamira'); ?></span>
            </nav>
            <span class="nd-preview-pill"><?php esc_html_e('Get started', domain: 'novamira'); ?></span>
        </header>

        <section class="nd-preview-hero">
            <span class="nd-preview-tag"><?php esc_html_e('Preview', domain: 'novamira'); ?></span>
            <h3 class="nd-preview-title"><?php esc_html_e(
                'A heading in this design system.',
                domain: 'novamira',
            ); ?></h3>
            <p class="nd-preview-lede"><?php esc_html_e(
                'Sample body copy: type, colour, spacing and shape applied to real content.',
                domain: 'novamira',
            ); ?></p>
            <div class="nd-preview-actions">
                <span class="nd-preview-btn nd-preview-btn-primary"><?php esc_html_e(
                    'Primary action',
                    domain: 'novamira',
                ); ?></span>
                <span class="nd-preview-btn nd-preview-btn-ghost"><?php esc_html_e(
                    'Learn more',
                    domain: 'novamira',
                ); ?> <span class="nd-preview-arrow" aria-hidden="true">&rarr;</span></span>
            </div>
        </section>

        <section class="nd-preview-grid" aria-hidden="true">
            <div class="nd-preview-tile">
                <span class="nd-preview-num">01</span>
                <strong><?php esc_html_e('Typography', domain: 'novamira'); ?></strong>
                <span class="nd-preview-tile-sub"><?php esc_html_e('Scale & weight', domain: 'novamira'); ?></span>
            </div>
            <div class="nd-preview-tile">
                <span class="nd-preview-num">02</span>
                <strong><?php esc_html_e('Colour', domain: 'novamira'); ?></strong>
                <span class="nd-preview-tile-sub"><?php esc_html_e('Palette & accent', domain: 'novamira'); ?></span>
            </div>
            <div class="nd-preview-tile">
                <span class="nd-preview-num">03</span>
                <strong><?php esc_html_e('Spacing', domain: 'novamira'); ?></strong>
                <span class="nd-preview-tile-sub"><?php esc_html_e('Rhythm & density', domain: 'novamira'); ?></span>
            </div>
        </section>
    </div>
</div>
