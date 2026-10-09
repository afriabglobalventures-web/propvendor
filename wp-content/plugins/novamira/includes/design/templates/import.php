<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Novamira\Design\Admin;

if (!defined('ABSPATH')) {
    exit();
}

if (!Admin\current_user_can_manage()) {
    wp_die(__('You do not have permission to import designs.', domain: 'novamira'));
}

$gallery_url = admin_url('admin.php?page=' . Admin\PAGE_SLUG);
$action_url = admin_url('admin-post.php');
?>
<?php novamira_render_admin_header(
    logo_file: 'novamira-design-logo.svg',
    logo_alt: 'Novamira Design',
    logo_width: 340,
    logo_height: 40,
); ?>
<div class="wrap novamira-design">
    <a class="nd-detail-back" href="<?php echo esc_url($gallery_url); ?>">&larr; <?php esc_html_e(
        'All designs',
        domain: 'novamira',
    ); ?></a>

    <h1><?php esc_html_e('Import a DESIGN.md', domain: 'novamira'); ?></h1>
    <p class="novamira-design-intro"><?php esc_html_e(
        'Bring a design direction from another site or project. The name comes from the front matter or the first heading.',
        domain: 'novamira',
    ); ?></p>
    <p class="novamira-design-import-trust"><?php esc_html_e(
        'Your AI agent reads this DESIGN.md, and it has full access to this site. A file from a source you do not control could hide instructions meant to steer the agent beyond design work. Import only files you trust.',
        domain: 'novamira',
    ); ?></p>

    <form
        method="post"
        action="<?php echo esc_url($action_url); ?>"
        enctype="multipart/form-data"
        class="novamira-design-import"
        onsubmit="return confirm('<?php echo
            esc_js(__(
                'Your AI agent reads this DESIGN.md, and it has full access to this site. A file from a source you do not control could hide instructions meant to steer the agent beyond design work. Import only files you trust. Continue?',
                domain: 'novamira',
            ))
        ; ?>');"
    >
        <?php wp_nonce_field('novamira_design_import'); ?>
        <input type="hidden" name="action" value="novamira_design_import" />
        <p>
            <label><?php esc_html_e('Upload a .md file:', domain: 'novamira'); ?>
                <input type="file" name="design_file" accept=".md" />
            </label>
        </p>
        <p><?php esc_html_e('…or paste the DESIGN.md below:', domain: 'novamira'); ?></p>
        <p>
            <textarea
                name="design_content"
                rows="14"
                class="large-text code"
                placeholder="---&#10;name: My Design&#10;---&#10;## Overview&#10;…"
            ></textarea>
        </p>
        <p>
            <label><input type="checkbox" name="activate" value="1" />
                <?php esc_html_e('Activate after import', domain: 'novamira'); ?></label>
        </p>
        <p>
            <button type="submit" class="button button-primary"><?php esc_html_e(
                'Import',
                domain: 'novamira',
            ); ?></button>
            <a href="<?php echo esc_url($gallery_url); ?>" class="button"><?php esc_html_e(
                'Cancel',
                domain: 'novamira',
            ); ?></a>
        </p>
    </form>
</div>
