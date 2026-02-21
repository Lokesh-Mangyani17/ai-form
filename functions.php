<?php
/**
 * Theme bootstrap for WordPress + WooCommerce admin custom fields.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'ai_form_register_backend_fields');

function ai_form_register_backend_fields(): void
{
    add_action('show_user_profile', 'ai_form_render_cpn_user_field');
    add_action('edit_user_profile', 'ai_form_render_cpn_user_field');
    add_action('personal_options_update', 'ai_form_save_cpn_user_field');
    add_action('edit_user_profile_update', 'ai_form_save_cpn_user_field');

    if (class_exists('WooCommerce')) {
        add_action('woocommerce_product_options_general_product_data', 'ai_form_render_prescription_product_fields');
        add_action('woocommerce_process_product_meta', 'ai_form_save_prescription_product_fields');
    }
}

function ai_form_render_cpn_user_field(WP_User $user): void
{
    $cpn = get_user_meta($user->ID, 'cpn', true);
    $title = get_user_meta($user->ID, 'title', true);
    $preferredName = get_user_meta($user->ID, 'preferred_name', true);
    wp_nonce_field('ai_form_save_cpn', 'ai_form_cpn_nonce');
    ?>
    <h2>Prescription Form Fields</h2>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="ai_form_title"><?php esc_html_e('Title', 'ai-form'); ?></label></th>
            <td>
                <input type="text" name="title" id="ai_form_title" value="<?php echo esc_attr((string) $title); ?>" class="regular-text" />
                <p class="description"><?php esc_html_e('Used to auto-fill the prescription form Title field (e.g. Dr).', 'ai-form'); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="ai_form_preferred_name"><?php esc_html_e('Preferred Name', 'ai-form'); ?></label></th>
            <td>
                <input type="text" name="preferred_name" id="ai_form_preferred_name" value="<?php echo esc_attr((string) $preferredName); ?>" class="regular-text" />
                <p class="description"><?php esc_html_e('Used to auto-fill the prescription form Preferred Name field. If left empty, First Name is used.', 'ai-form'); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="cpn"><?php esc_html_e('CPN', 'ai-form'); ?></label></th>
            <td>
                <input type="text" name="cpn" id="cpn" value="<?php echo esc_attr((string) $cpn); ?>" class="regular-text" />
                <p class="description"><?php esc_html_e('Used to auto-fill the prescription form CPN field.', 'ai-form'); ?></p>
            </td>
        </tr>
    </table>
    <?php
}

function ai_form_save_cpn_user_field(int $user_id): void
{
    if (!current_user_can('edit_user', $user_id)) {
        return;
    }

    if (!isset($_POST['ai_form_cpn_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ai_form_cpn_nonce'])), 'ai_form_save_cpn')) {
        return;
    }

    $cpn = isset($_POST['cpn']) ? sanitize_text_field(wp_unslash($_POST['cpn'])) : '';
    update_user_meta($user_id, 'cpn', $cpn);

    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    update_user_meta($user_id, 'title', $title);

    $preferredName = isset($_POST['preferred_name']) ? sanitize_text_field(wp_unslash($_POST['preferred_name'])) : '';
    update_user_meta($user_id, 'preferred_name', $preferredName);
}

function ai_form_render_prescription_product_fields(): void
{
    echo '<div class="options_group">';

    woocommerce_wp_text_input([
        'id' => '_prescription_component',
        'label' => __('Component', 'ai-form'),
        'description' => __('Auto-fills the form Component field.', 'ai-form'),
        'desc_tip' => true,
    ]);

    woocommerce_wp_text_input([
        'id' => '_prescription_strength',
        'label' => __('Strength', 'ai-form'),
        'description' => __('Auto-fills the form Strength field.', 'ai-form'),
        'desc_tip' => true,
    ]);

    woocommerce_wp_text_input([
        'id' => '_prescription_form',
        'label' => __('Form', 'ai-form'),
        'description' => __('Auto-fills the form Form field.', 'ai-form'),
        'desc_tip' => true,
    ]);

    woocommerce_wp_text_input([
        'id' => '_prescription_source',
        'label' => __('Sourced From', 'ai-form'),
        'description' => __('Auto-fills the form Sourced From field.', 'ai-form'),
        'desc_tip' => true,
    ]);

    woocommerce_wp_text_input([
        'id' => '_prescription_indications',
        'label' => __('Indication(s)', 'ai-form'),
        'description' => __('Comma-separated indications for the indication dropdown.', 'ai-form'),
        'desc_tip' => true,
    ]);

    woocommerce_wp_textarea_input([
        'id' => '_prescription_supporting_evidence_map',
        'label' => __('Supporting Evidence Mapping', 'ai-form'),
        'description' => __('One per line: Indication | supporting evidence text', 'ai-form'),
        'desc_tip' => true,
    ]);

    woocommerce_wp_textarea_input([
        'id' => '_prescription_treatment_protocol_map',
        'label' => __('Treatment Protocol Mapping', 'ai-form'),
        'description' => __('One per line: Indication | treatment protocol text', 'ai-form'),
        'desc_tip' => true,
    ]);

    woocommerce_wp_textarea_input([
        'id' => '_prescription_scientific_peer_review_map',
        'label' => __('Scientific Peer Review Mapping', 'ai-form'),
        'description' => __('One per line: Indication | scientific peer review text', 'ai-form'),
        'desc_tip' => true,
    ]);

    woocommerce_wp_textarea_input([
        'id' => '_prescription_indication_map',
        'label' => __('Indication Mapping JSON (optional)', 'ai-form'),
        'description' => __('Optional advanced JSON map. Usually not needed if you use the mapping textareas above.', 'ai-form'),
        'desc_tip' => true,
    ]);

    echo '</div>';
}

function ai_form_save_prescription_product_fields(int $product_id): void
{
    $keys = [
        '_prescription_component',
        '_prescription_strength',
        '_prescription_form',
        '_prescription_source',
        '_prescription_indications',
        '_prescription_supporting_evidence_map',
        '_prescription_treatment_protocol_map',
        '_prescription_scientific_peer_review_map',
        '_prescription_indication_map',
    ];

    foreach ($keys as $key) {
        $value = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
        if ($key === '_prescription_indication_map') {
            update_post_meta($product_id, $key, wp_kses_post((string) $value));
            continue;
        }
        update_post_meta($product_id, $key, sanitize_text_field((string) $value));
    }
}
