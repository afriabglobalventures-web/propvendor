<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Novamira\Design\Admin;
use Novamira\Design\Cpt;
use Novamira\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

if (!Admin\current_user_can_manage()) {
    wp_die(__('You do not have permission to edit designs.', domain: 'novamira'));
}

$design_param = $_GET['design'] ?? '';
$post_id = is_scalar($design_param) ? (int) $design_param : 0;
// @mago-expect analysis:mixed-assignment
$maybe_post = $post_id > 0 ? get_post($post_id) : null;
if (!$maybe_post instanceof \WP_Post || $maybe_post->post_type !== Cpt\POST_TYPE) {
    wp_die(__('Design not found.', domain: 'novamira'));
}
/** @var \WP_Post $post */
$post = $maybe_post;
$content = $post->post_content;
$name = $post->post_title !== '' ? $post->post_title : $post->post_name;

$list_url = admin_url('admin.php?page=' . Admin\PAGE_SLUG);
$action_url = admin_url('admin-post.php');
$tokens = Tokens\extract($content);
$vars_style = Tokens\css_vars_string($tokens);
?>
<?php novamira_render_admin_header(
    logo_file: 'novamira-design-logo.svg',
    logo_alt: 'Novamira Design',
    logo_width: 340,
    logo_height: 40,
); ?>
<div class="wrap novamira-design novamira-design-edit">
    <h1>
        <a href="<?php echo esc_url($list_url); ?>">← <?php esc_html_e('Design', domain: 'novamira'); ?></a>
        / <?php echo esc_html($name); ?>
    </h1>

    <div class="novamira-design-edit-grid">
        <form method="post" action="<?php echo esc_url($action_url); ?>" class="novamira-design-edit-form">
            <?php wp_nonce_field('novamira_design_save_' . $post_id); ?>
            <input type="hidden" name="action" value="novamira_design_save" />
            <input type="hidden" name="design_id" value="<?php echo (int) $post_id; ?>" />
            <p class="description"><?php esc_html_e(
                'Edit the raw DESIGN.md. The name comes from the front matter. The preview updates as you type.',
                domain: 'novamira',
            ); ?></p>
            <textarea name="content" id="novamira-design-content" rows="22" class="large-text code"><?php

            echo esc_textarea($content);
            ?></textarea>
            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e(
                    'Save',
                    domain: 'novamira',
                ); ?></button>
                <a href="<?php echo esc_url($list_url); ?>" class="button"><?php esc_html_e(
                    'Cancel',
                    domain: 'novamira',
                ); ?></a>
            </p>
        </form>

        <div class="novamira-design-edit-preview">
            <h2><?php esc_html_e('Live preview', domain: 'novamira'); ?></h2>
            <?php require __DIR__ . '/preview.php'; ?>
        </div>
    </div>

    <form method="post" action="<?php echo esc_url($action_url); ?>" class="novamira-design-delete-form"
        onsubmit="return confirm('<?php echo esc_js(__('Delete this design permanently?', domain: 'novamira')); ?>');">
        <?php wp_nonce_field('novamira_design_delete_' . $post_id); ?>
        <input type="hidden" name="action" value="novamira_design_delete" />
        <input type="hidden" name="design_id" value="<?php echo (int) $post_id; ?>" />
        <button type="submit" class="button button-link-delete"><?php esc_html_e(
            'Delete design',
            domain: 'novamira',
        ); ?></button>
    </form>
</div>
