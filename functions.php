<?php
/**
 * Theme functions built for Allu Form WordPress theme.
 *
 * @package Allu_Form
 */

if (!defined('ABSPATH')) {
    // Standalone fallback: try to locate and load WordPress.
    allu_form_bootstrap_wp_runtime();
}

// ── Constants ─────────────────────────────────────────────────────────────────

if (!defined('PDF_TEMPLATE_URL')) {
    define('PDF_TEMPLATE_URL', 'https://www.medsafe.govt.nz/downloads/ApprovalToPrescribePsychedelics.pdf');
}
/** @var float Default Y position for signature box on page 6. */
if (!defined('PDF_DEFAULT_SIGNATURE_Y')) {
    define('PDF_DEFAULT_SIGNATURE_Y', 536);
}
/** @var float Horizontal offset from label to value in label-value pairs. */
if (!defined('PDF_LABEL_VALUE_OFFSET')) {
    define('PDF_LABEL_VALUE_OFFSET', 120);
}
/** @var int Total number of pages in the generated PDF. */
if (!defined('PDF_TOTAL_PAGES')) {
    define('PDF_TOTAL_PAGES', 6);
}
/** @var float Approximate character width multiplier for Helvetica/Helvetica-Bold at a given font size. */
if (!defined('PDF_CHAR_WIDTH_FACTOR')) {
    define('PDF_CHAR_WIDTH_FACTOR', 0.55);
}
/** @var float Medsafe purple red component (#573F7F). */
if (!defined('MEDSAFE_PURPLE_R')) {
    define('MEDSAFE_PURPLE_R', 0.341);
}
/** @var float Medsafe purple green component (#573F7F). */
if (!defined('MEDSAFE_PURPLE_G')) {
    define('MEDSAFE_PURPLE_G', 0.247);
}
/** @var float Medsafe purple blue component (#573F7F). */
if (!defined('MEDSAFE_PURPLE_B')) {
    define('MEDSAFE_PURPLE_B', 0.498);
}

// ── WordPress Hooks ───────────────────────────────────────────────────────────

add_action('init', 'allu_form_register_backend_fields');

function allu_form_register_backend_fields(): void
{
    add_action('show_user_profile', 'allu_form_render_cpn_user_field');
    add_action('edit_user_profile', 'allu_form_render_cpn_user_field');
    add_action('personal_options_update', 'allu_form_save_cpn_user_field');
    add_action('edit_user_profile_update', 'allu_form_save_cpn_user_field');

    if (class_exists('WooCommerce')) {
        add_action('woocommerce_product_options_general_product_data', 'allu_form_render_prescription_product_fields');
        add_action('woocommerce_process_product_meta', 'allu_form_save_prescription_product_fields');
    }
}

function allu_form_render_cpn_user_field(WP_User $user): void
{
    $cpn = get_user_meta($user->ID, 'cpn', true);
    $title = get_user_meta($user->ID, 'title', true);
    $preferredName = get_user_meta($user->ID, 'preferred_name', true);
    wp_nonce_field('allu_form_save_cpn', 'allu_form_cpn_nonce');
    ?>
    <h2>Prescription Form Fields</h2>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="allu_form_title"><?php esc_html_e('Title', 'allu-form'); ?></label></th>
            <td>
                <input type="text" name="title" id="allu_form_title" value="<?php echo esc_attr((string) $title); ?>" class="regular-text" />
                <p class="description"><?php esc_html_e('Used to auto-fill the prescription form Title field (e.g. Dr).', 'allu-form'); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="allu_form_preferred_name"><?php esc_html_e('Preferred Name', 'allu-form'); ?></label></th>
            <td>
                <input type="text" name="preferred_name" id="allu_form_preferred_name" value="<?php echo esc_attr((string) $preferredName); ?>" class="regular-text" />
                <p class="description"><?php esc_html_e('Used to auto-fill the prescription form Preferred Name field. If left empty, First Name is used.', 'allu-form'); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="cpn"><?php esc_html_e('CPN', 'allu-form'); ?></label></th>
            <td>
                <input type="text" name="cpn" id="cpn" value="<?php echo esc_attr((string) $cpn); ?>" class="regular-text" />
                <p class="description"><?php esc_html_e('Used to auto-fill the prescription form CPN field.', 'allu-form'); ?></p>
            </td>
        </tr>
    </table>
    <?php
}

function allu_form_save_cpn_user_field(int $user_id): void
{
    if (!current_user_can('edit_user', $user_id)) {
        return;
    }

    if (!isset($_POST['allu_form_cpn_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['allu_form_cpn_nonce'])), 'allu_form_save_cpn')) {
        return;
    }

    $cpn = isset($_POST['cpn']) ? sanitize_text_field(wp_unslash($_POST['cpn'])) : '';
    update_user_meta($user_id, 'cpn', $cpn);

    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    update_user_meta($user_id, 'title', $title);

    $preferredName = isset($_POST['preferred_name']) ? sanitize_text_field(wp_unslash($_POST['preferred_name'])) : '';
    update_user_meta($user_id, 'preferred_name', $preferredName);
}

function allu_form_render_prescription_product_fields(): void
{
    echo '<div class="options_group">';

    woocommerce_wp_text_input([
        'id' => '_prescription_component',
        'label' => __('Component', 'allu-form'),
        'description' => __('Auto-fills the form Component field.', 'allu-form'),
        'desc_tip' => true,
    ]);

    woocommerce_wp_text_input([
        'id' => '_prescription_strength',
        'label' => __('Strength', 'allu-form'),
        'description' => __('Auto-fills the form Strength field.', 'allu-form'),
        'desc_tip' => true,
    ]);

    woocommerce_wp_text_input([
        'id' => '_prescription_form',
        'label' => __('Form', 'allu-form'),
        'description' => __('Auto-fills the form Form field.', 'allu-form'),
        'desc_tip' => true,
    ]);

    woocommerce_wp_text_input([
        'id' => '_prescription_source',
        'label' => __('Sourced From', 'allu-form'),
        'description' => __('Auto-fills the form Sourced From field.', 'allu-form'),
        'desc_tip' => true,
    ]);

    woocommerce_wp_text_input([
        'id' => '_prescription_indications',
        'label' => __('Indication(s)', 'allu-form'),
        'description' => __('Comma-separated indications for the indication dropdown.', 'allu-form'),
        'desc_tip' => true,
    ]);

    woocommerce_wp_textarea_input([
        'id' => '_prescription_supporting_evidence_map',
        'label' => __('Supporting Evidence Mapping', 'allu-form'),
        'description' => __('One per line: Indication | supporting evidence text', 'allu-form'),
        'desc_tip' => true,
    ]);

    woocommerce_wp_textarea_input([
        'id' => '_prescription_treatment_protocol_map',
        'label' => __('Treatment Protocol Mapping', 'allu-form'),
        'description' => __('One per line: Indication | treatment protocol text', 'allu-form'),
        'desc_tip' => true,
    ]);

    woocommerce_wp_textarea_input([
        'id' => '_prescription_scientific_peer_review_map',
        'label' => __('Scientific Peer Review Mapping', 'allu-form'),
        'description' => __('One per line: Indication | scientific peer review text', 'allu-form'),
        'desc_tip' => true,
    ]);

    woocommerce_wp_textarea_input([
        'id' => '_prescription_indication_map',
        'label' => __('Indication Mapping JSON (optional)', 'allu-form'),
        'description' => __('Optional advanced JSON map. Usually not needed if you use the mapping textareas above.', 'allu-form'),
        'desc_tip' => true,
    ]);

    echo '</div>';
}

function allu_form_save_prescription_product_fields(int $product_id): void
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

// ── Style & Shortcode Registration ────────────────────────────────────────────

add_action('wp_enqueue_scripts', 'allu_form_enqueue_styles');
function allu_form_enqueue_styles(): void
{
    wp_enqueue_style('allu-form-style', get_stylesheet_uri());
}

add_shortcode('allu_prescription_form', 'allu_form_render_shortcode');

// ── Path Helpers ──────────────────────────────────────────────────────────────

function allu_form_get_data_dir(): string
{
    static $dir = null;
    if ($dir === null) {
        if (allu_form_is_wp_runtime()) {
            $upload_dir = wp_upload_dir();
            $dir = $upload_dir['basedir'] . '/allu-form';
        } else {
            $dir = __DIR__ . '/data';
        }
    }
    return $dir;
}

function allu_form_get_submissions_dir(): string
{
    return allu_form_get_data_dir() . '/submissions';
}

function allu_form_get_sqlite_db_file(): string
{
    return allu_form_get_data_dir() . '/store.db';
}

function allu_form_get_pdf_template_file(): string
{
    return allu_form_get_data_dir() . '/ApprovalToPrescribePsychedelics.pdf';
}

// ── Core Functions ────────────────────────────────────────────────────────────

function allu_form_bootstrap_wp_runtime(): void
{
    if (function_exists('wp_get_current_user')) {
        return;
    }

    $candidates = [];
    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    if ($docRoot !== '') {
        $candidates[] = $docRoot . '/wp-load.php';
    }

    $dir = __DIR__;
    for ($i = 0; $i < 5; $i++) {
        $candidates[] = $dir . '/wp-load.php';
        $dir = dirname($dir);
    }

    foreach (array_unique($candidates) as $path) {
        if ($path && file_exists($path)) {
            @include_once $path;
            if (function_exists('wp_get_current_user')) {
                return;
            }
        }
    }
}

function allu_form_is_wp_runtime(): bool
{
    return function_exists('wp_get_current_user');
}

function allu_form_get_doctor_profile(): array
{
    if (allu_form_is_wp_runtime()) {
        $user = wp_get_current_user();
        if (!empty($user) && !empty($user->ID)) {
            $firstName = trim((string)($user->user_firstname ?? ''));
            $surname = trim((string)($user->user_lastname ?? ''));
            $name = trim((string)($user->display_name ?? ''));
            if ($name === '') {
                $name = trim($firstName . ' ' . $surname);
            }
            $title = function_exists('get_user_meta') ? (string)get_user_meta($user->ID, 'title', true) : '';
            $preferredName = function_exists('get_user_meta') ? (string)get_user_meta($user->ID, 'preferred_name', true) : '';
            if (trim($preferredName) === '') {
                $preferredName = $firstName;
            }

            return [
                'id' => (int)$user->ID,
                'name' => $name,
                'title' => $title,
                'first_name' => $firstName,
                'preferred_name' => $preferredName,
                'surname' => $surname,
                'email' => (string)($user->user_email ?? ''),
                'phone' => function_exists('get_user_meta') ? (string)get_user_meta($user->ID, 'billing_phone', true) : '',
                'cpn' => function_exists('get_user_meta') ? (string)get_user_meta($user->ID, 'cpn', true) : '',
            ];
        }
    }

    return [
        'id' => 0,
        'name' => '',
        'title' => '',
        'first_name' => '',
        'preferred_name' => '',
        'surname' => '',
        'email' => '',
        'phone' => '',
        'cpn' => '',
    ];
}

function allu_form_get_sqlite_db(): PDO
{
    static $db = null;
    if ($db === null) {
        try {
            $db = new PDO('sqlite:' . allu_form_get_sqlite_db_file());
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->exec('PRAGMA journal_mode=WAL');
            $db->exec('PRAGMA foreign_keys = ON');
        } catch (\PDOException $e) {
            throw new \RuntimeException('Unable to open database at ' . allu_form_get_sqlite_db_file() . ': ' . $e->getMessage(), 0, $e);
        }
    }
    return $db;
}

function allu_form_bootstrap_storage(): void
{
    $data_dir = allu_form_get_data_dir();
    if (!is_dir($data_dir)) {
        if (!@mkdir($data_dir, 0755, true) && !is_dir($data_dir)) {
            throw new \RuntimeException('Unable to create data directory: ' . $data_dir);
        }
    }
    $subs_dir = allu_form_get_submissions_dir();
    if (!is_dir($subs_dir)) {
        if (!@mkdir($subs_dir, 0755, true) && !is_dir($subs_dir)) {
            throw new \RuntimeException('Unable to create submissions directory: ' . $subs_dir);
        }
    }

    $htaccess = $data_dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        $written = @file_put_contents($htaccess, "Options -Indexes\nDeny from all\n");
        if ($written === false && function_exists('error_log')) {
            error_log('Allu Form: Unable to create .htaccess in ' . $data_dir);
        }
    }

    if (allu_form_is_wp_runtime()) {
        allu_form_bootstrap_wp_tables();
    } else {
        allu_form_bootstrap_sqlite_tables();
    }

    allu_form_ensure_pdf_template();
}

function allu_form_bootstrap_wp_tables(): void
{
    global $wpdb;
    $table = $wpdb->prefix . 'allu_submissions';
    $prodTable = $wpdb->prefix . 'allu_submission_products';
    $charset = $wpdb->get_charset_collate();

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        $wpdb->query("CREATE TABLE {$table} (
            id VARCHAR(255) NOT NULL,
            submitted_at DATETIME NOT NULL,
            doctor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            doctor_name VARCHAR(255) NOT NULL DEFAULT '',
            doctor_email VARCHAR(255) NOT NULL DEFAULT '',
            doctor_phone VARCHAR(255) NOT NULL DEFAULT '',
            doctor_cpn VARCHAR(255) NOT NULL DEFAULT '',
            doctor_title VARCHAR(255) NOT NULL DEFAULT '',
            doctor_preferred_name VARCHAR(255) NOT NULL DEFAULT '',
            doctor_first_name VARCHAR(255) NOT NULL DEFAULT '',
            doctor_surname VARCHAR(255) NOT NULL DEFAULT '',
            vocational_scope TEXT NOT NULL DEFAULT '',
            clinical_experience TEXT NOT NULL DEFAULT '',
            indication TEXT NOT NULL DEFAULT '',
            indication_other TEXT NOT NULL DEFAULT '',
            sourcing_notes TEXT NOT NULL DEFAULT '',
            supporting_evidence_notes TEXT NOT NULL DEFAULT '',
            treatment_protocol_notes TEXT NOT NULL DEFAULT '',
            scientific_peer_review_notes TEXT NOT NULL DEFAULT '',
            admin_monitoring_notes TEXT NOT NULL DEFAULT '',
            application_date VARCHAR(255) NOT NULL DEFAULT '',
            signature_mode VARCHAR(255) NOT NULL DEFAULT '',
            signature_drawn MEDIUMTEXT NOT NULL DEFAULT '',
            signature_upload VARCHAR(255) NOT NULL DEFAULT '',
            peer_support_file VARCHAR(255) NOT NULL DEFAULT '',
            pdf_file VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY doctor_id (doctor_id),
            KEY submitted_at (submitted_at)
        ) {$charset}");
    }

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prodTable)) !== $prodTable) {
        $wpdb->query("CREATE TABLE {$prodTable} (
            submission_id VARCHAR(255) NOT NULL,
            product_id VARCHAR(255) NOT NULL,
            product_name VARCHAR(255) NOT NULL DEFAULT '',
            component VARCHAR(255) NOT NULL DEFAULT '',
            strength VARCHAR(255) NOT NULL DEFAULT '',
            form VARCHAR(255) NOT NULL DEFAULT '',
            source VARCHAR(255) NOT NULL DEFAULT '',
            indication TEXT NOT NULL DEFAULT '',
            indication_other TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (submission_id, product_id),
            KEY submission_id (submission_id)
        ) {$charset}");
    }
}

function allu_form_bootstrap_sqlite_tables(): void
{
    $db = allu_form_get_sqlite_db();

    $db->exec('CREATE TABLE IF NOT EXISTS products (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        component TEXT NOT NULL DEFAULT "",
        strength TEXT NOT NULL DEFAULT "",
        form TEXT NOT NULL DEFAULT "",
        source TEXT NOT NULL DEFAULT ""
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS product_indications (
        product_id TEXT NOT NULL,
        indication TEXT NOT NULL,
        PRIMARY KEY (product_id, indication),
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS product_indication_details (
        product_id TEXT NOT NULL,
        indication TEXT NOT NULL,
        supporting_evidence TEXT NOT NULL DEFAULT "",
        treatment_protocol TEXT NOT NULL DEFAULT "",
        scientific_peer_review TEXT NOT NULL DEFAULT "",
        PRIMARY KEY (product_id, indication),
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS doctor_prefs (
        doctor_id INTEGER PRIMARY KEY,
        vocational_scope TEXT NOT NULL DEFAULT "",
        clinical_experience TEXT NOT NULL DEFAULT ""
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS submissions (
        id TEXT PRIMARY KEY,
        submitted_at TEXT NOT NULL,
        doctor_id INTEGER NOT NULL DEFAULT 0,
        doctor_name TEXT NOT NULL DEFAULT "",
        doctor_email TEXT NOT NULL DEFAULT "",
        doctor_phone TEXT NOT NULL DEFAULT "",
        doctor_cpn TEXT NOT NULL DEFAULT "",
        doctor_title TEXT NOT NULL DEFAULT "",
        doctor_preferred_name TEXT NOT NULL DEFAULT "",
        doctor_first_name TEXT NOT NULL DEFAULT "",
        doctor_surname TEXT NOT NULL DEFAULT "",
        vocational_scope TEXT NOT NULL DEFAULT "",
        clinical_experience TEXT NOT NULL DEFAULT "",
        indication TEXT NOT NULL DEFAULT "",
        indication_other TEXT NOT NULL DEFAULT "",
        sourcing_notes TEXT NOT NULL DEFAULT "",
        supporting_evidence_notes TEXT NOT NULL DEFAULT "",
        treatment_protocol_notes TEXT NOT NULL DEFAULT "",
        scientific_peer_review_notes TEXT NOT NULL DEFAULT "",
        admin_monitoring_notes TEXT NOT NULL DEFAULT "",
        application_date TEXT NOT NULL DEFAULT "",
        signature_mode TEXT NOT NULL DEFAULT "",
        signature_drawn TEXT NOT NULL DEFAULT "",
        signature_upload TEXT NOT NULL DEFAULT "",
        peer_support_file TEXT NOT NULL DEFAULT "",
        pdf_file TEXT NOT NULL DEFAULT ""
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS submission_products (
        submission_id TEXT NOT NULL,
        product_id TEXT NOT NULL,
        product_name TEXT NOT NULL DEFAULT "",
        component TEXT NOT NULL DEFAULT "",
        strength TEXT NOT NULL DEFAULT "",
        form TEXT NOT NULL DEFAULT "",
        source TEXT NOT NULL DEFAULT "",
        indication TEXT NOT NULL DEFAULT "",
        indication_other TEXT NOT NULL DEFAULT "",
        PRIMARY KEY (submission_id, product_id),
        FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
    )');

    $count = $db->query('SELECT EXISTS(SELECT 1 FROM products LIMIT 1)')->fetchColumn();
    if (!(int)$count) {
        $db->exec("INSERT OR IGNORE INTO products (id, name, component, strength, form, source) VALUES ('prd-001', 'Psilocybin Oral Capsule', 'Psilocybin', '25mg', 'Capsule', 'Medsafe-approved compounding supplier, NZ')");
        $db->exec("INSERT OR IGNORE INTO product_indications (product_id, indication) VALUES ('prd-001', 'Depression')");
        $db->exec("INSERT OR IGNORE INTO product_indication_details (product_id, indication, supporting_evidence, treatment_protocol, scientific_peer_review) VALUES ('prd-001', 'Depression', 'Default supporting evidence', 'Default treatment protocol', 'Default peer review')");
    }
}

function allu_form_ensure_pdf_template(): void
{
    if (file_exists(allu_form_get_pdf_template_file())) {
        return;
    }

    $pdf = @file_get_contents(PDF_TEMPLATE_URL);
    if ($pdf && str_starts_with($pdf, '%PDF')) {
        file_put_contents(allu_form_get_pdf_template_file(), $pdf);
    }
}

function allu_form_is_admin_page(): bool
{
    return ($_GET['page'] ?? 'form') === 'admin';
}

function allu_form_parse_indication_line_map(string $raw): array
{
    $map = [];
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $parts = preg_split('/\s*[|:]\s*/', $line, 2);
        if (!$parts || count($parts) < 2) {
            continue;
        }

        $indication = trim((string)$parts[0]);
        $value = trim((string)$parts[1]);
        if ($indication === '' || $value === '') {
            continue;
        }

        $map[$indication] = $value;
    }
    return $map;
}

function allu_form_build_indication_map_from_inputs(array $data): array
{
    $supporting = allu_form_parse_indication_line_map((string)($data['supporting_evidence_map'] ?? ''));
    $protocol = allu_form_parse_indication_line_map((string)($data['treatment_protocol_map'] ?? ''));
    $peer = allu_form_parse_indication_line_map((string)($data['scientific_peer_review_map'] ?? ''));

    $all = array_unique(array_merge(array_keys($supporting), array_keys($protocol), array_keys($peer)));
    $map = [];
    foreach ($all as $indication) {
        $map[$indication] = [
            'supporting_evidence' => $supporting[$indication] ?? '',
            'treatment_protocol' => $protocol[$indication] ?? '',
            'scientific_peer_review' => $peer[$indication] ?? '',
        ];
    }

    return $map;
}

function allu_form_get_products(): array
{
    if (allu_form_is_wp_runtime() && function_exists('wc_get_products')) {
        $wcProducts = wc_get_products(['status' => 'publish', 'limit' => -1]);
        $mapped = [];
        foreach ($wcProducts as $product) {
            $pid = $product->get_id();
            $indicationMapRaw = (string)get_post_meta($pid, '_prescription_indication_map', true);
            $indicationMap = json_decode($indicationMapRaw, true);
            if (!is_array($indicationMap)) {
                $indicationMap = [];
            }

            $uiMap = allu_form_build_indication_map_from_inputs([
                'supporting_evidence_map' => (string)get_post_meta($pid, '_prescription_supporting_evidence_map', true),
                'treatment_protocol_map' => (string)get_post_meta($pid, '_prescription_treatment_protocol_map', true),
                'scientific_peer_review_map' => (string)get_post_meta($pid, '_prescription_scientific_peer_review_map', true),
            ]);
            if (!empty($uiMap)) {
                $indicationMap = array_replace_recursive($indicationMap, $uiMap);
            }

            $indicationsRaw = (string)get_post_meta($pid, '_prescription_indications', true);
            $indications = array_values(array_filter(array_map('trim', explode(',', $indicationsRaw))));

            $mapped[] = [
                'id' => (string)$pid,
                'name' => $product->get_name(),
                'component' => (string)get_post_meta($pid, '_prescription_component', true),
                'strength' => (string)get_post_meta($pid, '_prescription_strength', true),
                'form' => (string)get_post_meta($pid, '_prescription_form', true),
                'source' => (string)get_post_meta($pid, '_prescription_source', true),
                'indications' => $indications,
                'indication_map' => $indicationMap,
            ];
        }
        return $mapped;
    }

    $db = allu_form_get_sqlite_db();
    $rows = $db->query('SELECT id, name, component, strength, form, source FROM products')->fetchAll(PDO::FETCH_ASSOC);

    $indRows = $db->query('SELECT product_id, indication FROM product_indications ORDER BY product_id')->fetchAll(PDO::FETCH_ASSOC);
    $indMap = [];
    foreach ($indRows as $ir) {
        $indMap[$ir['product_id']][] = $ir['indication'];
    }

    $detRows = $db->query('SELECT product_id, indication, supporting_evidence, treatment_protocol, scientific_peer_review FROM product_indication_details ORDER BY product_id')->fetchAll(PDO::FETCH_ASSOC);
    $detMap = [];
    foreach ($detRows as $dr) {
        $detMap[$dr['product_id']][$dr['indication']] = [
            'supporting_evidence' => $dr['supporting_evidence'],
            'treatment_protocol' => $dr['treatment_protocol'],
            'scientific_peer_review' => $dr['scientific_peer_review'],
        ];
    }

    return array_map(function ($row) use ($indMap, $detMap) {
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'component' => $row['component'],
            'strength' => $row['strength'],
            'form' => $row['form'],
            'source' => $row['source'],
            'indications' => $indMap[$row['id']] ?? [],
            'indication_map' => $detMap[$row['id']] ?? [],
        ];
    }, $rows);
}

function allu_form_save_product(array $data): array
{
    $name = trim($data['name'] ?? '');
    if ($name === '') {
        return [false, null, 'Product name is required.'];
    }

    $id = 'prd-' . substr(md5(uniqid('', true)), 0, 8);
    $product = [
        'id' => $id,
        'name' => $name,
        'component' => trim($data['component'] ?? ''),
        'strength' => trim($data['strength'] ?? ''),
        'form' => trim($data['form'] ?? ''),
        'source' => trim($data['source'] ?? ''),
        'indications' => array_values(array_filter(array_map('trim', explode(',', (string)($data['indications'] ?? ''))))),
        'indication_map' => allu_form_build_indication_map_from_inputs($data),
    ];

    $db = allu_form_get_sqlite_db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('INSERT INTO products (id, name, component, strength, form, source) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $product['id'],
            $product['name'],
            $product['component'],
            $product['strength'],
            $product['form'],
            $product['source'],
        ]);

        $indStmt = $db->prepare('INSERT INTO product_indications (product_id, indication) VALUES (?, ?)');
        foreach ($product['indications'] as $ind) {
            $indStmt->execute([$product['id'], $ind]);
        }

        $detStmt = $db->prepare('INSERT INTO product_indication_details (product_id, indication, supporting_evidence, treatment_protocol, scientific_peer_review) VALUES (?, ?, ?, ?, ?)');
        foreach ($product['indication_map'] as $ind => $details) {
            $detStmt->execute([
                $product['id'],
                $ind,
                $details['supporting_evidence'] ?? '',
                $details['treatment_protocol'] ?? '',
                $details['scientific_peer_review'] ?? '',
            ]);
        }

        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        throw $e;
    }
    return [true, 'Product added successfully.', null];
}

function allu_form_delete_product(string $id): array
{
    if ($id === '') {
        return [false, null, 'Missing product id.'];
    }
    $db = allu_form_get_sqlite_db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('DELETE FROM product_indication_details WHERE product_id = ?');
        $stmt->execute([$id]);
        $stmt = $db->prepare('DELETE FROM product_indications WHERE product_id = ?');
        $stmt->execute([$id]);
        $stmt = $db->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        throw $e;
    }
    return [true, 'Product deleted.', null];
}

function allu_form_get_doctor_prefs(int $doctorId): array
{
    if (allu_form_is_wp_runtime() && function_exists('get_user_meta')) {
        return [
            'vocational_scope' => (string)get_user_meta($doctorId, 'allu_vocational_scope', true),
            'clinical_experience' => (string)get_user_meta($doctorId, 'allu_clinical_experience', true),
        ];
    }

    $db = allu_form_get_sqlite_db();
    $stmt = $db->prepare('SELECT vocational_scope, clinical_experience FROM doctor_prefs WHERE doctor_id = ?');
    $stmt->execute([$doctorId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return ['vocational_scope' => $row['vocational_scope'], 'clinical_experience' => $row['clinical_experience']];
    }
    return ['vocational_scope' => '', 'clinical_experience' => ''];
}

function allu_form_save_doctor_prefs(int $doctorId, array $payload): void
{
    $vocationalScope = trim($payload['vocational_scope'] ?? '');
    $clinicalExperience = trim($payload['clinical_experience'] ?? '');

    if (allu_form_is_wp_runtime() && function_exists('update_user_meta')) {
        update_user_meta($doctorId, 'allu_vocational_scope', $vocationalScope);
        update_user_meta($doctorId, 'allu_clinical_experience', $clinicalExperience);
        return;
    }

    $db = allu_form_get_sqlite_db();
    $stmt = $db->prepare('INSERT INTO doctor_prefs (doctor_id, vocational_scope, clinical_experience) VALUES (?, ?, ?)
        ON CONFLICT(doctor_id) DO UPDATE SET vocational_scope = excluded.vocational_scope, clinical_experience = excluded.clinical_experience');
    $stmt->execute([$doctorId, $vocationalScope, $clinicalExperience]);
}

function allu_form_reconstruct_submission_from_row(array $row, array $productRows): array
{
    $products = [];
    $productNames = [];
    $productDetails = [];
    $productIndications = [];
    $productIndicationOthers = [];

    foreach ($productRows as $pr) {
        $products[] = $pr['product_id'];
        $productNames[] = $pr['product_name'];
        $productDetails[] = [
            'id' => $pr['product_id'],
            'name' => $pr['product_name'],
            'component' => $pr['component'],
            'strength' => $pr['strength'],
            'form' => $pr['form'],
            'source' => $pr['source'],
            'indications' => [],
            'indication_map' => [],
        ];
        $productIndications[$pr['product_id']] = $pr['indication'];
        $productIndicationOthers[$pr['product_id']] = $pr['indication_other'];
    }

    return [
        'id' => $row['id'],
        'submitted_at' => $row['submitted_at'],
        'doctor' => [
            'id' => (int)$row['doctor_id'],
            'name' => $row['doctor_name'],
            'title' => $row['doctor_title'],
            'first_name' => $row['doctor_first_name'],
            'preferred_name' => $row['doctor_preferred_name'],
            'surname' => $row['doctor_surname'],
            'email' => $row['doctor_email'],
            'phone' => $row['doctor_phone'],
            'cpn' => $row['doctor_cpn'],
        ],
        'form' => [
            'vocational_scope' => $row['vocational_scope'],
            'clinical_experience' => $row['clinical_experience'],
            'products' => $products,
            'product_names' => $productNames,
            'product_details' => $productDetails,
            'product_indications' => $productIndications,
            'product_indication_others' => $productIndicationOthers,
            'indication' => $row['indication'],
            'indication_other' => $row['indication_other'],
            'sourcing_notes' => $row['sourcing_notes'],
            'supporting_evidence_notes' => $row['supporting_evidence_notes'],
            'treatment_protocol_notes' => $row['treatment_protocol_notes'],
            'scientific_peer_review_notes' => $row['scientific_peer_review_notes'],
            'admin_monitoring_notes' => $row['admin_monitoring_notes'],
            'date' => $row['application_date'],
            'signature_mode' => $row['signature_mode'],
            'signature_drawn' => $row['signature_drawn'],
            'signature_upload' => $row['signature_upload'] !== '' ? $row['signature_upload'] : null,
            'peer_support_file' => $row['peer_support_file'] !== '' ? $row['peer_support_file'] : null,
        ],
        'pdf_file' => $row['pdf_file'],
    ];
}

function allu_form_get_submissions(): array
{
    if (allu_form_is_wp_runtime()) {
        global $wpdb;
        $table = $wpdb->prefix . 'allu_submissions';
        $prodTable = $wpdb->prefix . 'allu_submission_products';
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY submitted_at DESC", ARRAY_A);
        $records = [];
        foreach ($rows as $row) {
            $prods = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$prodTable} WHERE submission_id = %s", $row['id']), ARRAY_A);
            $records[] = allu_form_reconstruct_submission_from_row($row, $prods ?: []);
        }
        return $records;
    }

    $db = allu_form_get_sqlite_db();
    $rows = $db->query('SELECT * FROM submissions ORDER BY submitted_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    $records = [];
    foreach ($rows as $row) {
        $stmt = $db->prepare('SELECT * FROM submission_products WHERE submission_id = ?');
        $stmt->execute([$row['id']]);
        $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $records[] = allu_form_reconstruct_submission_from_row($row, $prods);
    }
    return $records;
}

function allu_form_build_selected_product_details(array $selectedProductIds): array
{
    $catalog = allu_form_get_products();
    $lookup = [];
    foreach ($catalog as $p) {
        $lookup[(string)$p['id']] = $p;
    }

    $selected = [];
    foreach ($selectedProductIds as $pid) {
        if (isset($lookup[(string)$pid])) {
            $selected[] = $lookup[(string)$pid];
        }
    }
    return $selected;
}

function allu_form_save_submission(array $doctor, array $post, array $files): array
{
    $selectedProducts = array_values(array_filter($post['products'] ?? []));
    if (empty($selectedProducts)) {
        return [false, null, 'Please select at least one product.', null];
    }

    $vocationalToggle = trim($post['vocational_scope_toggle'] ?? 'no');
    $vocationalScope = trim((string)($post['vocational_scope'] ?? ''));
    if ($vocationalToggle === 'no') {
        $vocationalScope = '';
    }
    if ($vocationalToggle === 'yes' && $vocationalScope === '') {
        return [false, null, 'Please specify your vocational scope(s).', null];
    }
    if (trim((string)($post['clinical_experience'] ?? '')) === '') {
        return [false, null, 'Clinical Experience & Training is required.', null];
    }
    if (trim((string)($post['application_date'] ?? '')) === '') {
        return [false, null, 'Application date is required.', null];
    }

    $signatureDrawn = trim($post['signature_drawn'] ?? '');
    $signatureMode = $post['signature_mode'] ?? '';
    $signatureUploadPath = null;

    if ($signatureMode === 'upload' && !empty($files['signature_upload']['name'])) {
        $sigName = uniqid('sig_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $files['signature_upload']['name']);
        $target = allu_form_get_submissions_dir() . '/' . $sigName;
        move_uploaded_file($files['signature_upload']['tmp_name'], $target);
        $signatureUploadPath = $sigName;
    }

    if ($signatureMode === 'draw' && $signatureDrawn === '') {
        return [false, null, 'Please provide a drawn signature.', null];
    }
    if ($signatureMode === 'upload' && !$signatureUploadPath) {
        return [false, null, 'Please upload a signature image.', null];
    }

    // Handle per-product indications
    $productIndications = $post['product_indications'] ?? [];
    $productIndicationOthers = $post['product_indication_others'] ?? [];
    if (!is_array($productIndications)) {
        $productIndications = [];
    }
    if (!is_array($productIndicationOthers)) {
        $productIndicationOthers = [];
    }

    $selectedDetails = allu_form_build_selected_product_details($selectedProducts);
    $productLookup = [];
    foreach ($selectedDetails as $p) {
        $productLookup[(string)$p['id']] = $p['name'];
    }

    $indicationParts = [];
    foreach ($selectedProducts as $pid) {
        $ind = trim((string)($productIndications[$pid] ?? ''));
        if ($ind === '') {
            return [false, null, 'Please select an indication for each product.', null];
        }
        if ($ind === 'Other') {
            $other = trim((string)($productIndicationOthers[$pid] ?? ''));
            if ($other === '') {
                return [false, null, 'Please enter a custom indication for each product.', null];
            }
            $pName = $productLookup[(string)$pid] ?? '';
            $indicationParts[] = ($pName !== '' ? $pName . ': ' : '') . $other;
        } else {
            $pName = $productLookup[(string)$pid] ?? '';
            $indicationParts[] = ($pName !== '' ? $pName . ': ' : '') . $ind;
        }
    }
    // Build a combined indication string for backward compatibility / PDF
    $indication = implode("\n", $indicationParts);
    $indicationOther = '';

    // Override vocational_scope in post for saveDoctorPrefs
    $post['vocational_scope'] = $vocationalScope;
    allu_form_save_doctor_prefs((int)$doctor['id'], $post);

    $productNames = array_map(fn($p) => $p['name'], $selectedDetails);

    $id = 'sub-' . date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 6);
    $submission = [
        'id' => $id,
        'submitted_at' => date('c'),
        'doctor' => $doctor,
        'form' => [
            'vocational_scope' => $vocationalScope,
            'clinical_experience' => trim($post['clinical_experience'] ?? ''),
            'products' => $selectedProducts,
            'product_names' => $productNames,
            'product_details' => $selectedDetails,
            'product_indications' => $productIndications,
            'product_indication_others' => $productIndicationOthers,
            'indication' => $indication,
            'indication_other' => $indicationOther,
            'sourcing_notes' => trim($post['sourcing_notes'] ?? ''),
            'supporting_evidence_notes' => trim($post['supporting_evidence_notes'] ?? ''),
            'treatment_protocol_notes' => trim($post['treatment_protocol_notes'] ?? ''),
            'scientific_peer_review_notes' => trim($post['scientific_peer_review_notes'] ?? ''),
            'admin_monitoring_notes' => trim($post['admin_monitoring_notes'] ?? ''),
            'date' => trim($post['application_date'] ?? date('Y-m-d')),
            'signature_mode' => $signatureMode,
            'signature_drawn' => $signatureDrawn,
            'signature_upload' => $signatureUploadPath,
            'peer_support_file' => null,
        ],
    ];

    $pdfPath = allu_form_get_submissions_dir() . '/' . $id . '.pdf';
    [$pdfOk, $pdfError] = allu_form_create_regulator_pdf($submission, $pdfPath);
    if (!$pdfOk) {
        return [false, null, $pdfError, null];
    }
    $submission['pdf_file'] = basename($pdfPath);

    if (allu_form_is_wp_runtime()) {
        global $wpdb;
        $table = $wpdb->prefix . 'allu_submissions';
        $prodTable = $wpdb->prefix . 'allu_submission_products';
        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->insert($table, [
                'id' => $submission['id'],
                'submitted_at' => $submission['submitted_at'],
                'doctor_id' => (int)$doctor['id'],
                'doctor_name' => $doctor['name'],
                'doctor_email' => $doctor['email'],
                'doctor_phone' => $doctor['phone'],
                'doctor_cpn' => $doctor['cpn'],
                'doctor_title' => $doctor['title'],
                'doctor_preferred_name' => $doctor['preferred_name'],
                'doctor_first_name' => $doctor['first_name'],
                'doctor_surname' => $doctor['surname'],
                'vocational_scope' => $submission['form']['vocational_scope'],
                'clinical_experience' => $submission['form']['clinical_experience'],
                'indication' => $submission['form']['indication'],
                'indication_other' => $submission['form']['indication_other'],
                'sourcing_notes' => $submission['form']['sourcing_notes'],
                'supporting_evidence_notes' => $submission['form']['supporting_evidence_notes'],
                'treatment_protocol_notes' => $submission['form']['treatment_protocol_notes'],
                'scientific_peer_review_notes' => $submission['form']['scientific_peer_review_notes'],
                'admin_monitoring_notes' => $submission['form']['admin_monitoring_notes'],
                'application_date' => $submission['form']['date'],
                'signature_mode' => $submission['form']['signature_mode'],
                'signature_drawn' => $submission['form']['signature_drawn'],
                'signature_upload' => $submission['form']['signature_upload'] ?? '',
                'peer_support_file' => $submission['form']['peer_support_file'] ?? '',
                'pdf_file' => $submission['pdf_file'],
            ]);
            foreach ($selectedDetails as $pd) {
                $pid = (string)$pd['id'];
                $wpdb->insert($prodTable, [
                    'submission_id' => $submission['id'],
                    'product_id' => $pid,
                    'product_name' => $pd['name'],
                    'component' => $pd['component'],
                    'strength' => $pd['strength'],
                    'form' => $pd['form'],
                    'source' => $pd['source'],
                    'indication' => (string)($productIndications[$pid] ?? ''),
                    'indication_other' => (string)($productIndicationOthers[$pid] ?? ''),
                ]);
            }
            $wpdb->query('COMMIT');
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    } else {
        $db = allu_form_get_sqlite_db();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('INSERT INTO submissions (id, submitted_at, doctor_id, doctor_name, doctor_email, doctor_phone, doctor_cpn, doctor_title, doctor_preferred_name, doctor_first_name, doctor_surname, vocational_scope, clinical_experience, indication, indication_other, sourcing_notes, supporting_evidence_notes, treatment_protocol_notes, scientific_peer_review_notes, admin_monitoring_notes, application_date, signature_mode, signature_drawn, signature_upload, peer_support_file, pdf_file) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $submission['id'],
                $submission['submitted_at'],
                (int)$doctor['id'],
                $doctor['name'],
                $doctor['email'],
                $doctor['phone'],
                $doctor['cpn'],
                $doctor['title'],
                $doctor['preferred_name'],
                $doctor['first_name'],
                $doctor['surname'],
                $submission['form']['vocational_scope'],
                $submission['form']['clinical_experience'],
                $submission['form']['indication'],
                $submission['form']['indication_other'],
                $submission['form']['sourcing_notes'],
                $submission['form']['supporting_evidence_notes'],
                $submission['form']['treatment_protocol_notes'],
                $submission['form']['scientific_peer_review_notes'],
                $submission['form']['admin_monitoring_notes'],
                $submission['form']['date'],
                $submission['form']['signature_mode'],
                $submission['form']['signature_drawn'],
                $submission['form']['signature_upload'] ?? '',
                $submission['form']['peer_support_file'] ?? '',
                $submission['pdf_file'],
            ]);

            $prodStmt = $db->prepare('INSERT INTO submission_products (submission_id, product_id, product_name, component, strength, form, source, indication, indication_other) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($selectedDetails as $pd) {
                $pid = (string)$pd['id'];
                $prodStmt->execute([
                    $submission['id'],
                    $pid,
                    $pd['name'],
                    $pd['component'],
                    $pd['strength'],
                    $pd['form'],
                    $pd['source'],
                    (string)($productIndications[$pid] ?? ''),
                    (string)($productIndicationOthers[$pid] ?? ''),
                ]);
            }

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    return [true, 'Submission saved and PDF generated successfully.', null, $id];
}

function allu_form_create_regulator_pdf(array $submission, string $path): array
{
    $ok = allu_form_generate_submission_pdf($submission, $path);
    if ($ok) {
        return [true, null];
    }

    return [false, 'Unable to generate PDF file.'];
}

function allu_form_generate_submission_pdf(array $submission, string $path): bool
{
    $doctor = $submission['doctor'] ?? [];
    $form = $submission['form'] ?? [];
    $products = allu_form_build_submission_product_rows($submission);

    $indication = trim((string)($form['indication'] ?? ''));
    $other = trim((string)($form['indication_other'] ?? ''));
    if ($indication === 'Other' && $other !== '') {
        $indication .= ' - ' . $other;
    }

    // Collect URL annotations per page: pageIndex => [[url, x, y, w, h], ...]
    $pageAnnotations = array_fill(0, PDF_TOTAL_PAGES, []);

    // Page dimensions (A4: 595.28 x 841.89)
    $pageW = 595.28;
    $pageH = 841.89;
    $margin = 19.4;
    $contentR = 576.0;
    $bodyMargin = 20.8;

    // Medsafe purple: #573F7F
    $pR = MEDSAFE_PURPLE_R;
    $pG = MEDSAFE_PURPLE_G;
    $pB = MEDSAFE_PURPLE_B;

    $totalPages = PDF_TOTAL_PAGES;

    // Helper: convert reference top-Y to PDF baseline Y
    $yt = fn(float $topY, float $size) => allu_form_pdf_y_from_top($topY, $size, $pageH);

    // ============================================================
    // PAGE 1: Static Information Page
    // ============================================================
    $p1 = [];

    // MEDSAFE logo image (will be embedded as XObject)
    // Logo placement command - will be replaced with actual image in writeMultiPagePdf
    $logoPath = (function_exists('get_template_directory') ? get_template_directory() : __DIR__) . '/medsafe-logo.jpg';
    $logoCmd = '';
    if (file_exists($logoPath)) {
        // Place logo at top-left, scaled to approximately 160x50
        $logoCmd = "q\n160 0 0 50 18 " . number_format($pageH - 80, 2, '.', '') . " cm\n/LogoIm Do\nQ";
        $p1[] = $logoCmd;
    } else {
        // Fallback to text if logo not available
        $p1[] = allu_form_pdf_fill_color($pR, $pG, $pB);
        $p1[] = allu_form_pdf_bold_text_command('MEDSAFE', 48, $yt(30, 20), 20);
        $p1[] = allu_form_pdf_fill_color(0.3, 0.3, 0.3);
        $p1[] = allu_form_pdf_text_command('New Zealand Medicines and', 48, $yt(50, 7), 7);
        $p1[] = allu_form_pdf_text_command('Medical Devices Safety Authority', 48, $yt(58, 7), 7);
    }

    // "Application Form" title (top-right)
    $p1[] = allu_form_pdf_fill_color($pR, $pG, $pB);
    $p1[] = allu_form_pdf_bold_text_command('Application Form', 352.6, $yt(30.7, 26), 26);

    // Title block with purple border
    $titleBoxTop = 90.065;
    $titleBoxBot = 189.0;
    $p1[] = allu_form_pdf_stroke_color($pR, $pG, $pB);
    $p1[] = '0.50 w';
    $p1[] = allu_form_pdf_rect_command(18.0, $pageH - $titleBoxBot, $contentR - 18.0, $titleBoxBot - $titleBoxTop, false);

    // Title text inside box
    $p1[] = allu_form_pdf_fill_color($pR, $pG, $pB);
    $p1[] = allu_form_pdf_bold_text_command('Approval to Prescribe/Supply/Administer', 28.4, $yt(95.6, 22), 22);
    $p1[] = allu_form_pdf_text_command('Application for a New Approval (Psychedelic-assisted therapy)', 28.4, $yt(136.5, 16), 16);
    $p1[] = allu_form_pdf_text_command('Misuse of Drugs Regulations 1977', 28.4, $yt(167.2, 11), 11);

    // "INFORMATION FOR APPLICANTS" box
    $infoBoxTop = 207.0;
    $infoBoxBot = 378.0;
    $p1[] = allu_form_pdf_stroke_color(0, 0, 0);
    $p1[] = '0.50 w';
    $p1[] = allu_form_pdf_rect_command(18.0, $pageH - $infoBoxBot, $contentR - 18.0, $infoBoxBot - $infoBoxTop, false);

    $p1[] = allu_form_pdf_fill_color(0, 0, 0);
    $p1[] = allu_form_pdf_bold_text_command('INFORMATION FOR APPLICANTS', 28.7, $yt(206.8, 12), 12);

    $bullets1 = [
        'This form is used by a medical practitioner to make an application for approval to prescribe/supply/' . "\n" .
        'administer controlled drugs that require approval under regulation 22 Misuse of Drugs Regulations 1977,' . "\n" .
        'for psychedelic-assisted therapy outside of a research setting (for example psilocybin).',
        'The applicant must be the medical practitioner applying for approval to conduct the activities.',
        'For the application to be considered, all applicable sections of the application form must be completed,' . "\n" .
        'and the required supporting information attached.',
        'Before filling out this application you should make yourself familiar with the criteria Medsafe will use to' . "\n" .
        'assess the application. Guidance is available on the Medsafe website (https://medsafe.govt.nz/profs/' . "\n" .
        'psychedelics.asp).',
    ];

    $bulletY = 227.0;
    foreach ($bullets1 as $bullet) {
        $p1[] = allu_form_pdf_text_command("\x95", 44.4, $yt($bulletY, 11), 11);
        $bLines = explode("\n", $bullet);
        foreach ($bLines as $bl) {
            $p1[] = allu_form_pdf_text_command(trim($bl), 55.4, $yt($bulletY, 11), 11);
            $bulletY += 13.2;
        }
        $bulletY += 6;
    }

    // "APPLICATION FORM SUBMISSION" box
    $subBoxTop = 396.0;
    $subBoxBot = 522.0;
    $p1[] = allu_form_pdf_stroke_color(0, 0, 0);
    $p1[] = allu_form_pdf_rect_command(18.0, $pageH - $subBoxBot, $contentR - 18.0, $subBoxBot - $subBoxTop, false);

    $p1[] = allu_form_pdf_fill_color(0, 0, 0);
    $p1[] = allu_form_pdf_bold_text_command('APPLICATION FORM SUBMISSION', 28.5, $yt(395.8, 12), 12);

    $bullets2 = [
        'This application form can be completed electronically using a pdf reader. The current version of Adobe' . "\n" .
        'Reader, available free of charge from the Adobe website (https://get.adobe.com/reader) is' . "\n" .
        'recommended.',
        'The completed application form should be submitted with any supporting documents, by the applicant, to' . "\n" .
        'Medsafe by email (medicinescontrol@health.govt.nz). A copy of the form should be retained for the' . "\n" .
        'applicant\'s records.',
    ];

    $bulletY = 416.0;
    foreach ($bullets2 as $bullet) {
        $p1[] = allu_form_pdf_text_command("\x95", 44.4, $yt($bulletY, 11), 11);
        $bLines = explode("\n", $bullet);
        foreach ($bLines as $bl) {
            $p1[] = allu_form_pdf_text_command(trim($bl), 55.4, $yt($bulletY, 11), 11);
            $bulletY += 13.2;
        }
        $bulletY += 6;
    }

    $p1[] = allu_form_pdf_medsafe_footer(1, $totalPages, $pageW, $pageH, $pR, $pG, $pB);

    // ============================================================
    // PAGE 2: Section 1 - Applicant
    // ============================================================
    $p2 = [];

    // Section title
    $p2[] = allu_form_pdf_fill_color($pR, $pG, $pB);
    $p2[] = allu_form_pdf_bold_text_command('Section 1: Applicant', $margin, $yt(17.7, 18), 18);
    $p2[] = allu_form_pdf_text_command('The Applicant is the medical practitioner completing this form, who is applying for the Approval.', $margin, $yt(43.6, 10.5), 10.5);
    $p2[] = allu_form_pdf_fill_color(0, 0, 0);

    // Field labels with input boxes
    // [label, labelY_top, boxLeft, boxRight, isShortBox]
    $fieldDefs = [
        ['1.1. Title:', 78.5, 117.13, 240.17],
        ['1.2. First name:', 105.3, 117.13, 573.17],
        ['1.3. Preferred name:', 132.5, 117.59, 573.17],
        ['1.4. Surname:', 159.5, 117.13, 573.17],
    ];

    $doctorTitle = (string)($doctor['title'] ?? '');
    $firstName = (string)($doctor['first_name'] ?? '');
    $preferredName = (string)($doctor['preferred_name'] ?? '');
    $surname = (string)($doctor['surname'] ?? '');
    if ($firstName === '' && $surname === '') {
        $doctorName = (string)($doctor['name'] ?? '');
        $nameParts = explode(' ', $doctorName, 2);
        $firstName = $nameParts[0] ?? '';
        $surname = $nameParts[1] ?? '';
    }
    $fieldValues = [$doctorTitle, $firstName, $preferredName, $surname];

    foreach ($fieldDefs as $idx => $fd) {
        $p2[] = allu_form_pdf_text_command($fd[0], $bodyMargin, $yt($fd[1], 10), 10);
        $boxX = $fd[2];
        $boxW = $fd[3] - $fd[2];
        $boxY = $pageH - $fd[1] - 15.84;  // box top aligns with label
        $boxH = 19.84;
        $p2[] = allu_form_pdf_stroke_color(0, 0, 0);
        $p2[] = '0.50 w';
        $p2[] = allu_form_pdf_rect_command($boxX, $boxY, $boxW, $boxH, false);
        if (isset($fieldValues[$idx]) && $fieldValues[$idx] !== '') {
            $p2[] = allu_form_pdf_text_command($fieldValues[$idx], $boxX + 4, $boxY + 5, 10);
        }
    }

    // "Contact details" sub-heading
    $p2[] = allu_form_pdf_bold_text_command('Contact details', $margin, $yt(187.8, 10), 10);

    // Email and Phone fields
    $contactFields = [
        ['1.5. Email:', 213.5, 117.13, 573.17, (string)($doctor['email'] ?? '')],
        ['1.6. Phone:', 240.5, 117.13, 294.17, (string)($doctor['phone'] ?? '')],
    ];
    foreach ($contactFields as $cf) {
        $p2[] = allu_form_pdf_text_command($cf[0], $bodyMargin, $yt($cf[1], 10), 10);
        $boxX = $cf[2];
        $boxW = $cf[3] - $cf[2];
        $boxY = $pageH - $cf[1] - 15.84;
        $boxH = 19.84;
        $p2[] = allu_form_pdf_rect_command($boxX, $boxY, $boxW, $boxH, false);
        if ($cf[4] !== '') {
            $p2[] = allu_form_pdf_text_command($cf[4], $boxX + 4, $boxY + 5, 10);
        }
    }

    // "Health practitioner registration details" sub-heading
    $p2[] = allu_form_pdf_bold_text_command('Health practitioner registration details', $margin, $yt(268.7, 10), 10);

    // HPI-CPN field
    $cpnVal = (string)($doctor['cpn'] ?? '');
    $p2[] = allu_form_pdf_text_command('1.7. HPI-CPN:', $bodyMargin, $yt(294.5, 10), 10);
    $p2[] = allu_form_pdf_rect_command(117.13, $pageH - 294.5 - 15.84, 294.17 - 117.13, 19.84, false);
    if ($cpnVal !== '') {
        $p2[] = allu_form_pdf_text_command($cpnVal, 121.13, $pageH - 294.5 - 10.84, 10);
    }

    // Vocational scope question
    $p2[] = allu_form_pdf_text_command('1.8. Does your annual practicing certificate (APC) include vocational scope(s)?', $bodyMargin, $yt(325.5, 10), 10);

    // Checkboxes
    $vocScope = (string)($form['vocational_scope'] ?? '');
    $hasScope = $vocScope !== '';

    // No checkbox
    $p2[] = allu_form_pdf_stroke_color(0, 0, 0);
    $p2[] = allu_form_pdf_rect_command(47.83, $pageH - 355.5, 10, 10, false);
    if (!$hasScope) {
        $p2[] = allu_form_pdf_fill_color(0, 0, 0);
        $p2[] = allu_form_pdf_checkmark_command(48.5, $pageH - 354.5, 10);
    }
    $p2[] = allu_form_pdf_fill_color(0, 0, 0);
    $p2[] = allu_form_pdf_text_command('No', 63.4, $yt(343.0, 10), 10);

    // Yes checkbox
    $p2[] = allu_form_pdf_rect_command(47.83, $pageH - 373.5, 10, 10, false);
    if ($hasScope) {
        $p2[] = allu_form_pdf_fill_color(0, 0, 0);
        $p2[] = allu_form_pdf_checkmark_command(48.5, $pageH - 372.5, 10);
    }
    $p2[] = allu_form_pdf_fill_color(0, 0, 0);
    $p2[] = allu_form_pdf_text_command('Yes, please specify:', 63.1, $yt(361.0, 10), 10);

    // Vocational scope text box
    $p2[] = allu_form_pdf_rect_command(47.83, $pageH - 429.17, 573.17 - 47.83, 429.17 - 380.84, false);
    if ($hasScope) {
        $scopeLines = allu_form_pdf_word_wrap($vocScope, 90);
        $scopeY = 393;
        foreach ($scopeLines as $sl) {
            $p2[] = allu_form_pdf_text_command($sl, 52, $yt($scopeY, 9), 9);
            $scopeY += 12;
        }
    }

    // "Clinical expertise and training" sub-heading
    $p2[] = allu_form_pdf_bold_text_command('Clinical expertise and training', $margin, $yt(448.8, 10), 10);

    // Clinical experience question
    $p2[] = allu_form_pdf_text_command('1.9. Describe the clinical experience and training you hold that is applicable to the proposed use of the product:', $bodyMargin, $yt(469.5, 10), 10);

    // Large text box for clinical experience
    $p2[] = allu_form_pdf_rect_command(47.83, $pageH - 798.17, 573.17 - 47.83, 798.17 - 488.84, false);
    $expText = (string)($form['clinical_experience'] ?? '');
    if ($expText !== '') {
        $expLines = allu_form_pdf_word_wrap($expText, 90);
        $expY = 500;
        foreach ($expLines as $el) {
            if ($expY > 790) {
                break;
            }
            $p2[] = allu_form_pdf_text_command($el, 52, $yt($expY, 9), 9);
            $expY += 12;
        }
    }

    $p2[] = allu_form_pdf_medsafe_footer(2, $totalPages, $pageW, $pageH, $pR, $pG, $pB);

    // ============================================================
    // PAGE 3: Section 2 - Product Details
    // ============================================================
    $p3 = [];

    $p3[] = allu_form_pdf_fill_color($pR, $pG, $pB);
    $p3[] = allu_form_pdf_bold_text_command('Section 2: Product Details', $margin, $yt(17.7, 18), 18);
    $p3[] = allu_form_pdf_fill_color(0, 0, 0);

    // Question 2.1
    $p3[] = allu_form_pdf_text_command('2.1. This Application is being made to prescribe/supply/administer the following product(s):', $bodyMargin, $yt(68.3, 10), 10);

    // Product table - outer border
    $tableTop = 135.0;
    $tableBot = 437.66;
    $tableX = 45.0;
    $tableR = 567.83;
    $tableW_total = $tableR - $tableX;
    $tableH = $tableBot - $tableTop;
    $p3[] = allu_form_pdf_stroke_color(0, 0, 0);
    $p3[] = '0.50 w';
    $p3[] = allu_form_pdf_rect_command($tableX, $pageH - $tableBot, $tableW_total, $tableH, false);

    // Column positions
    $colX = [45.0, 224.97, 339.26, 453.55, 567.83];
    $colW = [];
    for ($c = 0; $c < 4; $c++) {
        $colW[] = $colX[$c + 1] - $colX[$c];
    }

    // Header row
    $hdrRowBot = 163.37;
    $p3[] = allu_form_pdf_rect_command($tableX, $pageH - $hdrRowBot, $colX[1] - $tableX, $hdrRowBot - $tableTop, false);

    // Column headers
    $p3[] = allu_form_pdf_bold_text_command('Product', 46.4, $yt(141.5, 10), 10);
    $p3[] = allu_form_pdf_bold_text_command('Component', 254.3, $yt(141.5, 10), 10);
    $p3[] = allu_form_pdf_bold_text_command('Strength', 375.8, $yt(141.5, 10), 10);
    $p3[] = allu_form_pdf_bold_text_command('Form', 498.2, $yt(141.5, 10), 10);

    // Data rows
    $rowTops = [163.37, 218.23, 273.08, 327.94, 382.80];
    $rowBots = [218.23, 273.08, 327.94, 382.80, 437.66];
    $maxRows = count($rowTops);

    for ($r = 0; $r < $maxRows; $r++) {
        $rTop = $rowTops[$r];
        $rBot = $rowBots[$r];
        $rH = $rBot - $rTop;
        for ($c = 0; $c < 4; $c++) {
            $p3[] = allu_form_pdf_rect_command($colX[$c], $pageH - $rBot, $colW[$c], $rH, false);
        }
        if (isset($products[$r])) {
            $row = $products[$r];
            $cellTextY = $rTop + 12;
            $p3[] = allu_form_pdf_text_command((string)($row['name'] ?? ''), $colX[0] + 4, $yt($cellTextY, 9), 9);
            $p3[] = allu_form_pdf_text_command((string)($row['component'] ?? ''), $colX[1] + 4, $yt($cellTextY, 9), 9);
            $p3[] = allu_form_pdf_text_command((string)($row['strength'] ?? ''), $colX[2] + 4, $yt($cellTextY, 9), 9);
            $p3[] = allu_form_pdf_text_command((string)($row['form'] ?? ''), $colX[3] + 4, $yt($cellTextY, 9), 9);
        }
    }

    // Question 2.2 - Sourcing
    $p3[] = allu_form_pdf_text_command('2.2. Describe where the above product(s) are intended to be sourced from:', $bodyMargin, $yt(469.5, 10), 10);

    // Sourcing text box
    $p3[] = allu_form_pdf_rect_command(47.83, $pageH - 798.17, 573.17 - 47.83, 798.17 - 488.84, false);
    $sourcingNotes = (string)($form['sourcing_notes'] ?? '');
    if ($sourcingNotes !== '') {
        $srcLines = allu_form_pdf_word_wrap($sourcingNotes, 90);
        $srcY = 500;
        foreach ($srcLines as $sl) {
            if ($srcY > 790) {
                break;
            }
            $cmds = allu_form_pdf_rich_text_line($sl, 52, $yt($srcY, 9), 9);
            foreach ($cmds as $cmd) {
                $p3[] = $cmd;
            }
            $srcY += 12;
        }
    }

    $p3[] = allu_form_pdf_medsafe_footer(3, $totalPages, $pageW, $pageH, $pR, $pG, $pB);

    // ============================================================
    // PAGE 4: Section 3 - Treatment Protocol
    // ============================================================
    $p4 = [];

    $p4[] = allu_form_pdf_fill_color($pR, $pG, $pB);
    $p4[] = allu_form_pdf_bold_text_command('Section 3: Treatment Protocol', $margin, $yt(17.9, 18), 18);
    $p4[] = allu_form_pdf_fill_color(0, 0, 0);

    // 3.1 Indication
    $p4[] = allu_form_pdf_text_command('3.1. What is the indication the product(s) are proposed to be used for?', $bodyMargin, $yt(64.5, 10), 10);
    $p4[] = allu_form_pdf_rect_command(47.83, $pageH - 177.17, 573.17 - 47.83, 177.17 - 83.84, false);
    if ($indication !== '') {
        $indLines = allu_form_pdf_word_wrap($indication, 90);
        $indY = 95;
        foreach ($indLines as $il) {
            $p4[] = allu_form_pdf_text_command($il, 52, $yt($indY, 9), 9);
            $indY += 12;
        }
    }

    // 3.2 Supporting evidence
    $p4[] = allu_form_pdf_text_command('3.2. Provide supporting evidence/information to support use of the product(s) for the intended indication.', $bodyMargin, $yt(199.5, 10), 10);
    $p4[] = allu_form_pdf_stroke_color(0, 0, 0);
    $p4[] = '0.50 w';
    $p4[] = allu_form_pdf_rect_command(47.83, $pageH - 310.17, 573.17 - 47.83, 310.17 - 218.84, false);
    $evText = (string)($form['supporting_evidence_notes'] ?? '');
    if ($evText !== '') {
        $evLines = allu_form_pdf_word_wrap($evText, 90);
        $evY = 230;
        foreach ($evLines as $el) {
            if ($evY > 302) {
                break;
            }
            $cmds = allu_form_pdf_rich_text_line($el, 52, $yt($evY, 9), 9);
            foreach ($cmds as $cmd) {
                $p4[] = $cmd;
            }
            $urlAnnots = allu_form_pdf_detect_url_annotations($el, 52, $yt($evY, 9), 9, $pageH);
            foreach ($urlAnnots as $ua) {
                $pageAnnotations[3][] = $ua;
            }
            $evY += 12;
        }
    }

    // 3.3 Treatment protocol
    $p4[] = allu_form_pdf_text_command('3.3. Provide a copy of the current treatment protocol.', $bodyMargin, $yt(332.5, 10), 10);
    $p4[] = allu_form_pdf_rect_command(47.83, $pageH - 443.17, 573.17 - 47.83, 443.17 - 351.84, false);
    $protText = (string)($form['treatment_protocol_notes'] ?? '');
    if ($protText !== '') {
        $protLines = allu_form_pdf_word_wrap($protText, 90);
        $protY = 363;
        foreach ($protLines as $pl) {
            if ($protY > 435) {
                break;
            }
            $cmds = allu_form_pdf_rich_text_line($pl, 52, $yt($protY, 9), 9);
            foreach ($cmds as $cmd) {
                $p4[] = $cmd;
            }
            $urlAnnots = allu_form_pdf_detect_url_annotations($pl, 52, $yt($protY, 9), 9, $pageH);
            foreach ($urlAnnots as $ua) {
                $pageAnnotations[3][] = $ua;
            }
            $protY += 12;
        }
    }

    // 3.4 Administering location
    $p4[] = allu_form_pdf_text_command('3.4. Describe where you will be administering and monitoring the treatment:', $bodyMargin, $yt(465.5, 10), 10);
    $p4[] = allu_form_pdf_rect_command(47.83, $pageH - 798.17, 573.17 - 47.83, 798.17 - 484.84, false);
    $adminText = (string)($form['admin_monitoring_notes'] ?? '');
    if ($adminText !== '') {
        $adminLines = allu_form_pdf_word_wrap($adminText, 90);
        $adminY = 496;
        foreach ($adminLines as $al) {
            if ($adminY > 790) {
                break;
            }
            $cmds = allu_form_pdf_rich_text_line($al, 52, $yt($adminY, 9), 9);
            foreach ($cmds as $cmd) {
                $p4[] = $cmd;
            }
            $urlAnnots = allu_form_pdf_detect_url_annotations($al, 52, $yt($adminY, 9), 9, $pageH);
            foreach ($urlAnnots as $ua) {
                $pageAnnotations[3][] = $ua;
            }
            $adminY += 12;
        }
    }

    $p4[] = allu_form_pdf_medsafe_footer(4, $totalPages, $pageW, $pageH, $pR, $pG, $pB);

    // ============================================================
    // PAGE 5: Section 4 - Scientific Peer Review
    // ============================================================
    $p5 = [];

    $p5[] = allu_form_pdf_fill_color($pR, $pG, $pB);
    $p5[] = allu_form_pdf_bold_text_command('Section 4: Scientific Peer Review', $margin, $yt(17.7, 18), 18);
    $p5[] = allu_form_pdf_fill_color(0, 0, 0);

    // 4.1 Question
    $p5[] = allu_form_pdf_text_command('4.1. Describe the scientific peer review activities that are implemented/proposed, and details of any support networks:', $bodyMargin, $yt(64.5, 10), 10);

    // Large text box
    $p5[] = allu_form_pdf_rect_command(47.83, $pageH - 726.17, 573.17 - 47.83, 726.17 - 119.84, false);
    $peerText = (string)($form['scientific_peer_review_notes'] ?? '');
    if ($peerText !== '') {
        $prLines = allu_form_pdf_word_wrap($peerText, 90);
        $prY = 132;
        foreach ($prLines as $pl) {
            if ($prY > 718) {
                break;
            }
            $cmds = allu_form_pdf_rich_text_line($pl, 52, $yt($prY, 9), 9);
            foreach ($cmds as $cmd) {
                $p5[] = $cmd;
            }
            $urlAnnots = allu_form_pdf_detect_url_annotations($pl, 52, $yt($prY, 9), 9, $pageH);
            foreach ($urlAnnots as $ua) {
                $pageAnnotations[4][] = $ua;
            }
            $prY += 12;
        }
    }

    $p5[] = allu_form_pdf_medsafe_footer(5, $totalPages, $pageW, $pageH, $pR, $pG, $pB);

    // ============================================================
    // PAGE 6: Section 5 - Declaration & Signature
    // ============================================================
    $p6 = [];

    $p6[] = allu_form_pdf_fill_color($pR, $pG, $pB);
    $p6[] = allu_form_pdf_bold_text_command('Section 5: Declaration', $margin, $yt(17.8, 18), 18);
    $p6[] = allu_form_pdf_fill_color(0, 0, 0);

    // Declaration border box (purple outline)
    $p6[] = allu_form_pdf_stroke_color($pR, $pG, $pB);
    $p6[] = '0.72 w';
    $p6[] = allu_form_pdf_rect_command(36.0, $pageH - 315.0, 540.0, 315.0 - 81.0, false);
    $p6[] = allu_form_pdf_stroke_color(0, 0, 0);
    $p6[] = '0.50 w';

    // 5.1 heading
    $p6[] = allu_form_pdf_fill_color($pR, $pG, $pB);
    $p6[] = allu_form_pdf_bold_text_command('5.1. Applicant declaration', $margin, $yt(55.3, 10), 10);
    $p6[] = allu_form_pdf_fill_color(0, 0, 0);

    // Declaration text
    $p6[] = allu_form_pdf_text_command('I confirm that I:', 46.4, $yt(88.9, 10), 10);
    $p6[] = allu_form_pdf_text_command('1. Solemnly and sincerely declare that the statements made in this Application are true and correct; and', 82.4, $yt(100.9, 10), 10);
    $p6[] = allu_form_pdf_text_command('2. Agree to provide any further information as required by Medsafe to assess the application.', 82.4, $yt(112.9, 10), 10);

    // Date field
    $p6[] = allu_form_pdf_text_command('Date:', 47.8, $yt(150.5, 10), 10);
    $dateVal = (string)($form['date'] ?? '');
    $p6[] = allu_form_pdf_rect_command(81.38, $pageH - 150.5 - 15.84, 231.17 - 81.38, 19.84, false);
    if ($dateVal !== '') {
        $p6[] = allu_form_pdf_text_command($dateVal, 85.38, $yt(153, 10), 10);
    }

    // Signature method labels
    $p6[] = allu_form_pdf_bold_text_command('Digital Signature', 55.4, $yt(223.8, 10), 10);
    $p6[] = allu_form_pdf_fill_color(0, 0, 0);
    $p6[] = allu_form_pdf_bold_text_command('OR', 213.0, $yt(257.8, 10), 10);

    $p6[] = allu_form_pdf_bold_text_command('Signature Image File', 235.4, $yt(223.8, 10), 10);
    $p6[] = allu_form_pdf_bold_text_command('OR', 393.0, $yt(257.8, 10), 10);

    $p6[] = allu_form_pdf_bold_text_command('Signature', 415.4, $yt(223.8, 10), 10);

    // Signature boxes
    $sigDrawn = (string)($form['signature_drawn'] ?? '');
    $sigBoxY = $pageH - 306.0;  // PDF coords for bottom of signature box
    $sigBoxH = 54.0;

    // Digital signature box (left) - navy blue per reference
    $p6[] = allu_form_pdf_stroke_color(0, 0, 0.502);
    $p6[] = allu_form_pdf_rect_command(54.0, $sigBoxY, 153.0, $sigBoxH, false);

    // Signature image file box (middle) - navy blue per reference
    $p6[] = allu_form_pdf_rect_command(234.0, $sigBoxY, 153.0, $sigBoxH, false);

    // Signature box (right) - black per reference
    $p6[] = allu_form_pdf_stroke_color(0, 0, 0);
    $p6[] = allu_form_pdf_rect_command(414.0, $sigBoxY, 153.0, $sigBoxH, false);

    $sigUploadPath = (string)($form['signature_upload'] ?? '');
    if ($sigDrawn !== '') {
        $p6[] = allu_form_pdf_fill_color(0, 0, 0);
        $p6[] = allu_form_pdf_text_command('[Signature provided]', 420, $sigBoxY + 20, 9);
    } elseif ($sigUploadPath !== '') {
        $p6[] = allu_form_pdf_fill_color(0, 0, 0);
        $p6[] = allu_form_pdf_text_command('[Signature provided]', 240, $sigBoxY + 20, 9);
    }

    $p6[] = allu_form_pdf_fill_color(0, 0, 0);
    $p6[] = allu_form_pdf_medsafe_footer(6, $totalPages, $pageW, $pageH, $pR, $pG, $pB);

    // Build streams
    $streams = [];
    $streams[] = allu_form_build_pdf_page_stream([], $p1);
    $streams[] = allu_form_build_pdf_page_stream([], $p2);
    $streams[] = allu_form_build_pdf_page_stream([], $p3);
    $streams[] = allu_form_build_pdf_page_stream([], $p4);
    $streams[] = allu_form_build_pdf_page_stream([], $p5);
    $streams[] = allu_form_build_pdf_page_stream([], $p6);

    return allu_form_write_multi_page_pdf($path, $streams, $sigDrawn, $sigBoxY, $sigUploadPath, $logoPath, $pageAnnotations);
}

function allu_form_build_submission_product_rows(array $submission): array
{
    $rows = [];
    $details = $submission['form']['product_details'] ?? [];

    if (is_array($details) && !empty($details)) {
        foreach ($details as $p) {
            if (!is_array($p)) {
                continue;
            }
            $rows[] = [
                'name' => (string)($p['name'] ?? ''),
                'component' => (string)($p['component'] ?? ''),
                'strength' => (string)($p['strength'] ?? ''),
                'form' => (string)($p['form'] ?? ''),
            ];
        }
        return $rows;
    }

    $selected = $submission['form']['products'] ?? [];
    $lookup = [];
    foreach (allu_form_get_products() as $p) {
        $lookup[(string)($p['id'] ?? '')] = $p;
    }
    foreach ($selected as $id) {
        $p = $lookup[(string)$id] ?? null;
        if (!$p) {
            continue;
        }
        $rows[] = [
            'name' => (string)($p['name'] ?? ''),
            'component' => (string)($p['component'] ?? ''),
            'strength' => (string)($p['strength'] ?? ''),
            'form' => (string)($p['form'] ?? ''),
        ];
    }

    return $rows;
}

function allu_form_write_multi_page_pdf(string $path, array $streams, string $signatureDrawn = '', float $sigBoxY = PDF_DEFAULT_SIGNATURE_Y, string $signatureUploadPath = '', string $logoPath = '', array $pageAnnotations = []): bool
{
    $pageCount = count($streams);
    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

    // Page object IDs start at 3
    $pageObjIds = [];
    for ($i = 0; $i < $pageCount; $i++) {
        $pageObjIds[] = 3 + $i;
    }
    $kidsStr = implode(' ', array_map(fn($id) => $id . ' 0 R', $pageObjIds));
    $objects[2] = '<< /Type /Pages /Kids [' . $kidsStr . '] /Count ' . $pageCount . ' >>';

    // Font objects – use IDs after pages
    $fontObjBase = 3 + $pageCount;
    $fontRegularId = $fontObjBase;
    $fontBoldId = $fontObjBase + 1;
    $fontDingbatsId = $fontObjBase + 2;
    $objects[$fontRegularId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[$fontBoldId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    $objects[$fontDingbatsId] = '<< /Type /Font /Subtype /Type1 /BaseFont /ZapfDingbats >>';
    $fontResStr = '<< /F1 ' . $fontRegularId . ' 0 R /F2 ' . $fontBoldId . ' 0 R /F3 ' . $fontDingbatsId . ' 0 R >>';

    // Stream object IDs start after fonts
    $streamObjBase = $fontObjBase + 3;
    for ($i = 0; $i < $pageCount; $i++) {
        $streamId = $streamObjBase + $i;
        $pageId = $pageObjIds[$i];
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font ' . $fontResStr . ' >> /Contents ' . $streamId . ' 0 R >>';
        $objects[$streamId] = "<< /Length " . strlen($streams[$i]) . " >>\nstream\n" . $streams[$i] . "\nendstream";
    }

    $nextObjNum = $streamObjBase + $pageCount;

    // Logo image on first page
    $logoImage = null;
    $logoObjNum = null;
    if ($logoPath !== '' && file_exists($logoPath)) {
        $logoImage = allu_form_build_jpeg_from_file($logoPath);
    }
    if ($logoImage) {
        $logoObjNum = $nextObjNum++;
        $objects[$logoObjNum] = $logoImage['object'];
        $firstPageId = $pageObjIds[0];
        $firstStreamId = $streamObjBase;
        $xobjects = '/LogoIm ' . $logoObjNum . ' 0 R';
        $objects[$firstPageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font ' . $fontResStr . ' /XObject << ' . $xobjects . ' >> >> /Contents ' . $firstStreamId . ' 0 R >>';
    }

    // Signature image on last page (drawn or uploaded)
    $signatureImage = allu_form_build_signature_jpeg_from_data_url($signatureDrawn);
    $sigIsUploaded = false;
    if (!$signatureImage && $signatureUploadPath !== '') {
        $sigFile = allu_form_get_submissions_dir() . '/' . $signatureUploadPath;
        if (file_exists($sigFile)) {
            $signatureImage = allu_form_build_jpeg_from_file($sigFile);
            $sigIsUploaded = true;
        }
    }
    if ($signatureImage) {
        $imageObjNum = $nextObjNum++;
        $objects[$imageObjNum] = $signatureImage['object'];
        $lastStreamIdx = $pageCount - 1;
        $lastStreamId = $streamObjBase + $lastStreamIdx;
        $lastPageId = $pageObjIds[$lastStreamIdx];
        $sigImgX = $sigIsUploaded ? 234 : 414;
        $streams[$lastStreamIdx] .= "\nq\n153 0 0 54 " . $sigImgX . " " . number_format($sigBoxY, 2, '.', '') . " cm\n/SigIm Do\nQ\n";
        $objects[$lastStreamId] = "<< /Length " . strlen($streams[$lastStreamIdx]) . " >>\nstream\n" . $streams[$lastStreamIdx] . "\nendstream";
        $xobjects = '/SigIm ' . $imageObjNum . ' 0 R';
        if ($logoObjNum !== null && $lastStreamIdx === 0) {
            $xobjects .= ' /LogoIm ' . $logoObjNum . ' 0 R';
        }
        $objects[$lastPageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font ' . $fontResStr . ' /XObject << ' . $xobjects . ' >> >> /Contents ' . $lastStreamId . ' 0 R >>';
    }

    // Add URL link annotations to pages
    foreach ($pageAnnotations as $pageIdx => $annots) {
        if (empty($annots) || !isset($pageObjIds[$pageIdx])) {
            continue;
        }
        $annotObjIds = [];
        foreach ($annots as $annot) {
            [$url, $ax, $ay, $aw, $ah] = $annot;
            $annotId = $nextObjNum++;
            $x1 = number_format($ax, 2, '.', '');
            $y1 = number_format($ay, 2, '.', '');
            $x2 = number_format($ax + $aw, 2, '.', '');
            $y2 = number_format($ay + $ah, 2, '.', '');
            $objects[$annotId] = '<< /Type /Annot /Subtype /Link /Rect [' . $x1 . ' ' . $y1 . ' ' . $x2 . ' ' . $y2 . '] /Border [0 0 0] /A << /S /URI /URI (' . allu_form_pdf_escape($url) . ') >> >>';
            $annotObjIds[] = $annotId . ' 0 R';
        }
        $pageId = $pageObjIds[$pageIdx];
        $existingPage = $objects[$pageId];
        // Inject /Annots before the final closing >>
        $annotsStr = ' /Annots [' . implode(' ', $annotObjIds) . ']';
        $lastClose = strrpos($existingPage, '>>');
        if ($lastClose !== false) {
            $objects[$pageId] = substr($existingPage, 0, $lastClose) . $annotsStr . ' >>';
        }
    }

    $pdf = allu_form_build_pdf_from_objects($objects, 1);
    return file_put_contents($path, $pdf) !== false;
}

function allu_form_build_signature_jpeg_from_data_url(string $dataUrl): ?array
{
    if ($dataUrl === '' || !str_starts_with($dataUrl, 'data:image/')) {
        return null;
    }
    $parts = explode(',', $dataUrl, 2);
    if (count($parts) !== 2) {
        return null;
    }

    $meta = strtolower($parts[0]);
    $binary = base64_decode($parts[1], true);
    if ($binary === false || $binary === '') {
        return null;
    }

    $size = function_exists('getimagesizefromstring') ? @getimagesizefromstring($binary) : false;
    $w = (int)($size[0] ?? 0);
    $h = (int)($size[1] ?? 0);

    $jpg = '';
    if (str_contains($meta, 'image/jpeg') || str_contains($meta, 'image/jpg')) {
        $jpg = $binary;
    } elseif (function_exists('imagecreatefromstring') && function_exists('imagejpeg') && function_exists('imagecreatetruecolor')) {
        $img = @imagecreatefromstring($binary);
        if ($img) {
            if ($w < 1 || $h < 1) {
                $w = imagesx($img);
                $h = imagesy($img);
            }
            $flattened = imagecreatetruecolor($w, $h);
            $white = imagecolorallocate($flattened, 255, 255, 255);
            imagefilledrectangle($flattened, 0, 0, $w, $h, $white);
            imagecopy($flattened, $img, 0, 0, 0, 0, $w, $h);
            ob_start();
            imagejpeg($flattened, null, 85);
            $jpg = (string)ob_get_clean();
            imagedestroy($flattened);
            imagedestroy($img);
        }
    }

    if ($jpg === '' || $w < 1 || $h < 1) {
        return null;
    }

    $object = '<< /Type /XObject /Subtype /Image /Width ' . $w . ' /Height ' . $h . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($jpg) . " >>
stream
" . $jpg . "
endstream";
    return ['object' => $object];
}

function allu_form_build_jpeg_from_file(string $filePath): ?array
{
    $realPath = realpath($filePath);
    if ($realPath === false || !file_exists($realPath)) {
        return null;
    }

    // Only allow files within the theme directory or uploads directory
    $allowedDirs = [realpath(__DIR__)];
    $uploadsDir = realpath(allu_form_get_data_dir());
    if ($uploadsDir !== false) {
        $allowedDirs[] = $uploadsDir;
    }
    if (function_exists('get_template_directory')) {
        $themeDir = realpath(get_template_directory());
        if ($themeDir !== false) {
            $allowedDirs[] = $themeDir;
        }
    }
    $allowed = false;
    foreach (array_filter($allowedDirs) as $dir) {
        if (strpos($realPath, $dir) === 0) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        return null;
    }

    $binary = file_get_contents($realPath);
    if ($binary === false || $binary === '') {
        return null;
    }

    $size = function_exists('getimagesizefromstring') ? @getimagesizefromstring($binary) : false;
    $w = (int)($size[0] ?? 0);
    $h = (int)($size[1] ?? 0);

    $jpg = '';
    $mime = $size['mime'] ?? '';
    if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
        $jpg = $binary;
    } elseif (function_exists('imagecreatefromstring') && function_exists('imagejpeg') && function_exists('imagecreatetruecolor')) {
        $img = @imagecreatefromstring($binary);
        if ($img) {
            if ($w < 1 || $h < 1) {
                $w = imagesx($img);
                $h = imagesy($img);
            }
            $flattened = imagecreatetruecolor($w, $h);
            $white = imagecolorallocate($flattened, 255, 255, 255);
            imagefilledrectangle($flattened, 0, 0, $w, $h, $white);
            imagecopy($flattened, $img, 0, 0, 0, 0, $w, $h);
            ob_start();
            imagejpeg($flattened, null, 85);
            $jpg = (string)ob_get_clean();
            imagedestroy($flattened);
            imagedestroy($img);
        }
    }

    if ($jpg === '' || $w < 1 || $h < 1) {
        return null;
    }

    $object = '<< /Type /XObject /Subtype /Image /Width ' . $w . ' /Height ' . $h . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($jpg) . " >>
stream
" . $jpg . "
endstream";
    return ['object' => $object];
}

function allu_form_build_pdf_page_stream(array $lines, array $extraCommands): string
{
    $commands = [];
    foreach ($lines as $line) {
        $commands[] = allu_form_pdf_text_command((string)$line[0], (float)$line[1], (float)$line[2], (float)$line[3]);
    }
    foreach ($extraCommands as $cmd) {
        $commands[] = $cmd;
    }
    return implode("
", $commands) . "
";
}

/**
 * Convert a reference-PDF top-of-text Y coordinate to a PDF baseline Y.
 *
 * PyMuPDF/reference positions report the bbox top (from page top).
 * PDF text commands need the baseline Y (from page bottom).
 * For Helvetica Type1 with WinAnsiEncoding the effective ascent that
 * positions the bbox top correctly is approximately 1.07 × fontSize.
 * This factor was determined empirically by comparing rendered glyph
 * positions with reference PDF coordinates across multiple font sizes
 * (8–26 pt) and matches to within 0.1 pt.
 */
function allu_form_pdf_y_from_top(float $topFromTop, float $fontSize, float $pageHeight = 841.89): float
{
    return $pageHeight - $topFromTop - $fontSize * 1.07;
}

function allu_form_pdf_text_command(string $text, float $x, float $y, float $size = 10): string
{
    return 'BT /F1 ' . number_format($size, 2, '.', '') . ' Tf 1 0 0 1 ' . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' Tm (' . allu_form_pdf_escape($text) . ') Tj ET';
}

function allu_form_pdf_rect_command(float $x, float $y, float $w, float $h, bool $fill = false): string
{
    $op = $fill ? 'b' : 'S';
    return number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' ' . number_format($w, 2, '.', '') . ' ' . number_format($h, 2, '.', '') . ' re ' . $op;
}

function allu_form_pdf_escape(string $text): string
{
    // Convert UTF-8 bullet (U+2022) to WinAnsiEncoding bullet (0x95)
    $text = str_replace("\xE2\x80\xA2", "\x95", $text);
    $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';
    return str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $text);
}

function allu_form_pdf_bold_text_command(string $text, float $x, float $y, float $size = 10): string
{
    return 'BT /F2 ' . number_format($size, 2, '.', '') . ' Tf 1 0 0 1 ' . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' Tm (' . allu_form_pdf_escape($text) . ') Tj ET';
}

function allu_form_pdf_checkmark_command(float $x, float $y, float $size = 10): string
{
    // Character '4' in ZapfDingbats renders as a checkmark (✔)
    return 'BT /F3 ' . number_format($size, 2, '.', '') . ' Tf 1 0 0 1 ' . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' Tm (4) Tj ET';
}

function allu_form_pdf_fill_color(float $r, float $g, float $b): string
{
    return number_format($r, 3, '.', '') . ' ' . number_format($g, 3, '.', '') . ' ' . number_format($b, 3, '.', '') . ' rg';
}

function allu_form_pdf_stroke_color(float $r, float $g, float $b): string
{
    return number_format($r, 3, '.', '') . ' ' . number_format($g, 3, '.', '') . ' ' . number_format($b, 3, '.', '') . ' RG';
}

function allu_form_pdf_filled_rect(float $x, float $y, float $w, float $h): string
{
    return number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' ' . number_format($w, 2, '.', '') . ' ' . number_format($h, 2, '.', '') . ' re f';
}

function allu_form_pdf_line_command(float $x1, float $y1, float $x2, float $y2): string
{
    return number_format($x1, 2, '.', '') . ' ' . number_format($y1, 2, '.', '') . ' m ' . number_format($x2, 2, '.', '') . ' ' . number_format($y2, 2, '.', '') . ' l S';
}

function allu_form_pdf_section_header(string $title, float $x, float $y, float $width, float $r = MEDSAFE_PURPLE_R, float $g = MEDSAFE_PURPLE_G, float $b = MEDSAFE_PURPLE_B): string
{
    $commands = [];
    $commands[] = allu_form_pdf_fill_color(0.882, 0.882, 0.882);
    $commands[] = allu_form_pdf_filled_rect($x, $y - 6, $width, 20);
    $commands[] = allu_form_pdf_fill_color($r, $g, $b);
    $commands[] = allu_form_pdf_filled_rect($x, $y - 6, 3, 20);
    $commands[] = allu_form_pdf_bold_text_command($title, $x + 10, $y, 11);
    $commands[] = allu_form_pdf_fill_color(0, 0, 0);
    return implode("\n", $commands);
}

function allu_form_pdf_label_value(string $label, string $value, float $x, float $y): string
{
    $commands = [];
    $commands[] = allu_form_pdf_fill_color(0.3, 0.3, 0.3);
    $commands[] = allu_form_pdf_bold_text_command($label . ':', $x, $y, 9);
    $commands[] = allu_form_pdf_fill_color(0, 0, 0);
    $commands[] = allu_form_pdf_text_command($value, $x + PDF_LABEL_VALUE_OFFSET, $y, 9);
    return implode("\n", $commands);
}

function allu_form_pdf_page_footer(int $pageNum, int $totalPages, float $pageWidth, float $r = MEDSAFE_PURPLE_R, float $g = MEDSAFE_PURPLE_G, float $b = MEDSAFE_PURPLE_B): string
{
    return allu_form_pdf_medsafe_footer($pageNum, $totalPages, $pageWidth, 841.89, $r, $g, $b);
}

function allu_form_pdf_medsafe_footer(int $pageNum, int $totalPages, float $pageWidth, float $pageHeight, float $r = MEDSAFE_PURPLE_R, float $g = MEDSAFE_PURPLE_G, float $b = MEDSAFE_PURPLE_B): string
{
    $commands = [];
    $footerText = 'Application Form: Approval to Prescribe/Supply/Administer (Form A3) version 1.0';
    $pageText = 'Page ' . $pageNum . ' of ' . $totalPages;
    // Reference: footer text at bbox_top=816.6 (8pt font)
    $footerY = allu_form_pdf_y_from_top(816.6, 8, $pageHeight);
    // Reference: page number at bbox_top=818.0 (8pt font)
    $pageNumY = allu_form_pdf_y_from_top(818.0, 8, $pageHeight);
    $commands[] = allu_form_pdf_fill_color($r, $g, $b);
    $commands[] = allu_form_pdf_text_command($footerText, 19.4, $footerY, 8);
    $commands[] = allu_form_pdf_text_command($pageText, $pageWidth - 60, $pageNumY, 8);
    $commands[] = allu_form_pdf_fill_color(0, 0, 0);
    return implode("\n", $commands);
}

function allu_form_pdf_word_wrap(string $text, int $maxChars): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    // Split on explicit newlines first to preserve line breaks
    $paragraphs = preg_split('/\r\n|\r|\n/', $text);
    $lines = [];
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') {
            $lines[] = '';
            continue;
        }
        $words = explode(' ', $paragraph);
        $current = '';
        foreach ($words as $word) {
            if ($current === '') {
                $current = $word;
            } elseif (mb_strlen($current, 'UTF-8') + 1 + mb_strlen($word, 'UTF-8') <= $maxChars) {
                $current .= ' ' . $word;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
    }
    return $lines;
}

/**
 * Render a single line with bold product-name prefix (bullet lines like "• Name: text")
 * and plain text for the rest.
 */
function allu_form_pdf_rich_text_line(string $line, float $x, float $y, float $size): array
{
    $commands = [];
    // Detect bullet-prefixed product lines: "• ProductName: rest of text"
    if (preg_match('/^(\x{2022}|\x{95}|•)\s*(.+?):\s*(.*)$/u', $line, $m)) {
        $boldPart = $m[1] . ' ' . $m[2] . ':';
        $plainPart = ' ' . $m[3];
        $commands[] = allu_form_pdf_bold_text_command($boldPart, $x, $y, $size);
        $boldWidth = mb_strlen($boldPart, 'UTF-8') * $size * PDF_CHAR_WIDTH_FACTOR;
        if (trim($m[3]) !== '') {
            $commands[] = allu_form_pdf_text_command(trim($plainPart), $x + $boldWidth, $y, $size);
        }
    } else {
        $commands[] = allu_form_pdf_text_command($line, $x, $y, $size);
    }
    return $commands;
}

/**
 * Detect URLs in a text line and return annotation rects for PDF link annotations.
 * Returns array of [url, linkX, linkY, linkW, linkH].
 */
function allu_form_pdf_detect_url_annotations(string $line, float $x, float $y, float $size, float $pageHeight): array
{
    $annotations = [];
    $charWidth = $size * PDF_CHAR_WIDTH_FACTOR;
    // Match http/https URLs terminated by whitespace, closing paren/bracket, or >
    if (preg_match_all('#https?://[^\s)\]>]+#i', $line, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $url = $match[0];
            $offset = $match[1];
            $prefix = substr($line, 0, $offset);
            $linkX = $x + mb_strlen($prefix, 'UTF-8') * $charWidth;
            $linkW = mb_strlen($url, 'UTF-8') * $charWidth;
            $linkY = $y - 2;
            $linkH = $size + 4;
            $annotations[] = [$url, $linkX, $linkY, $linkW, $linkH];
        }
    }
    return $annotations;
}

function allu_form_build_pdf_from_objects(array $objects, int $rootObj): string
{
    ksort($objects);
    $maxObject = max(array_keys($objects));
    $header = "%PDF-1.4
%âãÏÓ
";
    $body = '';
    $offsets = [];

    foreach ($objects as $num => $content) {
        $offsets[$num] = strlen($header . $body);
        $body .= $num . " 0 obj
" . trim($content) . "
endobj
";
    }

    $xrefPos = strlen($header . $body);
    $xref = "xref
0 " . ($maxObject + 1) . "
";
    $xref .= "0000000000 65535 f 
";
    for ($i = 1; $i <= $maxObject; $i++) {
        if (isset($offsets[$i])) {
            $xref .= sprintf("%010d 00000 n 
", $offsets[$i]);
        } else {
            $xref .= "0000000000 00000 f 
";
        }
    }

    $trailer = 'trailer << /Size ' . ($maxObject + 1) . ' /Root ' . $rootObj . " 0 R >>
";
    $trailer .= "startxref
" . $xrefPos . "
%%EOF
";

    return $header . $body . $xref . $trailer;
}

function allu_form_find_submission(string $id): ?array
{
    if ($id === '') {
        return null;
    }

    if (allu_form_is_wp_runtime()) {
        global $wpdb;
        $table = $wpdb->prefix . 'allu_submissions';
        $prodTable = $wpdb->prefix . 'allu_submission_products';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %s", $id), ARRAY_A);
        if ($row) {
            $prods = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$prodTable} WHERE submission_id = %s", $id), ARRAY_A);
            return allu_form_reconstruct_submission_from_row($row, $prods ?: []);
        }
        return null;
    }

    $db = allu_form_get_sqlite_db();
    $stmt = $db->prepare('SELECT * FROM submissions WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $prodStmt = $db->prepare('SELECT * FROM submission_products WHERE submission_id = ?');
        $prodStmt->execute([$id]);
        $prods = $prodStmt->fetchAll(PDO::FETCH_ASSOC);
        return allu_form_reconstruct_submission_from_row($row, $prods);
    }
    return null;
}

function allu_form_download_pdf(string $id): void
{
    $submission = allu_form_find_submission($id);
    if (!$submission) {
        http_response_code(404);
        echo 'Submission not found';
        return;
    }

    $file = allu_form_get_submissions_dir() . '/' . $submission['pdf_file'];
    if (!file_exists($file)) {
        http_response_code(404);
        echo 'PDF not found';
        return;
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    readfile($file);
}

function allu_form_download_support_document(string $submissionId, string $file): void
{
    $submission = allu_form_find_submission($submissionId);
    if (!$submission || ($submission['form']['peer_support_file'] ?? '') !== $file) {
        http_response_code(404);
        echo 'Support document not found';
        return;
    }

    $path = allu_form_get_submissions_dir() . '/' . $file;
    if (!file_exists($path)) {
        http_response_code(404);
        echo 'Missing file';
        return;
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    readfile($path);
}

function allu_form_email_pdf_to_doctor(string $submissionId): array
{
    $submission = allu_form_find_submission($submissionId);
    if (!$submission) {
        return [false, null, 'Submission not found.'];
    }

    $pdfPath = allu_form_get_submissions_dir() . '/' . $submission['pdf_file'];
    if (!file_exists($pdfPath)) {
        return [false, null, 'PDF missing.'];
    }

    $to = $submission['doctor']['email'];
    $subject = 'Prescription Application PDF - ' . $submission['id'];
    $message = "Please find your submitted application attached.\nSubmission: " . $submission['id'];

    $content = chunk_split(base64_encode(file_get_contents($pdfPath)));
    $separator = md5((string)time());
    $headers = "From: no-reply@exampleclinic.nz\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"$separator\"";

    $body = "--$separator\r\n";
    $body .= "Content-Type: text/plain; charset=\"utf-8\"\r\n\r\n";
    $body .= $message . "\r\n";
    $body .= "--$separator\r\n";
    $body .= "Content-Type: application/pdf; name=\"application.pdf\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= "Content-Disposition: attachment\r\n\r\n";
    $body .= $content . "\r\n";
    $body .= "--$separator--";

    @mail($to, $subject, $body, $headers);
    return [true, 'Email dispatch attempted using PHP mail().', null];
}

// ── Shortcode Handler ─────────────────────────────────────────────────────────

function allu_form_render_shortcode(): string
{
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }

    allu_form_bootstrap_storage();

    $raw_page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : 'form';
    $page = in_array($raw_page, ['form', 'admin'], true) ? $raw_page : 'form';
    $raw_action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : null;
    $allowed_actions = ['save_submission', 'save_product', 'delete_product', 'email_pdf', 'download_pdf', 'download_support'];
    $action = ($raw_action !== null && in_array($raw_action, $allowed_actions, true)) ? $raw_action : null;
    $message = null;
    $error = null;
    $doctor = allu_form_get_doctor_profile();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['allu_form_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['allu_form_nonce'])), 'allu_form_submission')) {
            $error = 'Security check failed. Please try again.';
        } else {
            if ($action === 'save_submission') {
                [$ok, $message, $error, $submissionId] = allu_form_save_submission($doctor, $_POST, $_FILES);
                if ($ok && $submissionId) {
                    $_SESSION['form_success'] = $message;
                    wp_redirect(add_query_arg(['page' => 'form', 'submitted' => '1', 'id' => $submissionId]));
                    exit;
                }
            }

            if ($action === 'save_product' && allu_form_is_admin_page() && !allu_form_is_wp_runtime()) {
                [$ok, $message, $error] = allu_form_save_product($_POST);
            }

            if ($action === 'delete_product' && allu_form_is_admin_page() && !allu_form_is_wp_runtime()) {
                [$ok, $message, $error] = allu_form_delete_product(sanitize_text_field(wp_unslash($_POST['product_id'] ?? '')));
            }

            if ($action === 'email_pdf' && allu_form_is_admin_page()) {
                [$ok, $message, $error] = allu_form_email_pdf_to_doctor(sanitize_text_field(wp_unslash($_POST['submission_id'] ?? '')));
            }
        }
    }

    if ($action === 'download_pdf') {
        allu_form_download_pdf(sanitize_text_field(wp_unslash($_GET['id'] ?? '')));
        exit;
    }

    if ($action === 'download_support') {
        allu_form_download_support_document(
            sanitize_text_field(wp_unslash($_GET['submission_id'] ?? '')),
            sanitize_text_field(wp_unslash($_GET['file'] ?? ''))
        );
        exit;
    }

    $doctorPrefs = allu_form_get_doctor_prefs((int)$doctor['id']);
    $products = allu_form_get_products();
    $submissions = allu_form_get_submissions();
    $submissionView = null;
    if (($page === 'form') && (isset($_GET['submitted']) && $_GET['submitted'] === '1')) {
        $submissionView = allu_form_find_submission(sanitize_text_field(wp_unslash($_GET['id'] ?? '')));
        if (!$submissionView) {
            $error = 'Submitted record not found. Please try again.';
        }
    }

    ob_start();
    ?>
<div class="container">
  <header class="topbar">
    <h1>Allu Prescription Form</h1>
  </header>

  <?php if (!empty($_SESSION['form_success'])): ?>
    <div class="alert success"><?= htmlspecialchars($_SESSION['form_success']) ?></div>
    <?php unset($_SESSION['form_success']); ?>
  <?php endif; ?>
  <?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <?php if ($page === 'admin'): ?>
    <?php if (allu_form_is_wp_runtime()): ?>
      <section class="card">
        <h2>WooCommerce Configuration</h2>
        <p>Products are loaded from WooCommerce. Edit each product under <strong>WooCommerce → Products</strong> and fill prescription custom fields (component, strength, form, sourced from, indications, and the indication mapping textareas).</p>
        <p>Doctor CPN is managed under <strong>Users → Profile</strong> via the new CPN field.</p>
      </section>
    <?php else: ?>
      <section class="card">
        <h2>Product Management</h2>
        <form method="post" action="?page=admin&action=save_product" class="grid">
          <?php wp_nonce_field('allu_form_submission', 'allu_form_nonce'); ?>
          <input name="name" placeholder="Product Name" required />
          <input name="component" placeholder="Component" required />
          <input name="strength" placeholder="Strength" required />
          <input name="form" placeholder="Form" required />
          <input name="source" placeholder="Source" required />
          <input name="indications" placeholder="Indications (comma separated)" />
          <label>Supporting Evidence Mapping (one per line: Indication | text)
            <textarea name="supporting_evidence_map" rows="4" placeholder="Depression | Evidence summary"></textarea>
          </label>
          <label>Treatment Protocol Mapping (one per line: Indication | text)
            <textarea name="treatment_protocol_map" rows="4" placeholder="Depression | Protocol summary"></textarea>
          </label>
          <label>Scientific Peer Review Mapping (one per line: Indication | text)
            <textarea name="scientific_peer_review_map" rows="4" placeholder="Depression | Peer review summary"></textarea>
          </label>
          <button type="submit">Add Product</button>
        </form>
      </section>
    <?php endif; ?>

    <section class="card">
      <h2>Submitted PDFs</h2>
      <table>
        <thead><tr><th>ID</th><th>Doctor</th><th>Date</th><th>PDF</th><th>Support</th><th>Email</th></tr></thead>
        <tbody>
        <?php foreach ($submissions as $s): ?>
          <tr>
            <td><?= htmlspecialchars($s['id']) ?></td>
            <td><?= htmlspecialchars($s['doctor']['name']) ?></td>
            <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($s['submitted_at']))) ?></td>
            <td><a href="?action=download_pdf&id=<?= urlencode($s['id']) ?>">Download PDF</a></td>
            <td>
              <?php if (!empty($s['form']['peer_support_file'])): ?>
                <a href="?action=download_support&submission_id=<?= urlencode($s['id']) ?>&file=<?= urlencode($s['form']['peer_support_file']) ?>">Download</a>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td>
              <form method="post" action="?page=admin&action=email_pdf">
                <?php wp_nonce_field('allu_form_submission', 'allu_form_nonce'); ?>
                <input type="hidden" name="submission_id" value="<?= htmlspecialchars($s['id']) ?>" />
                <button type="submit">Email PDF</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>

  <?php else: ?>
    <?php if ($submissionView): ?>
      <section class="card thank-you">
        <h2>Thank you! Your application has been submitted.</h2>
        <p>Your PDF has been generated in the exact Medsafe format and is ready for download.</p>
        <div class="thank-actions">
          <a class="btn-link" href="?action=download_pdf&id=<?= urlencode($submissionView['id']) ?>">Download PDF</a>
          <a class="btn-link ghost" href="?page=form">Create another submission</a>
        </div>
      </section>
    <?php else: ?>
    <form id="prescriptionForm" class="card form-shell" method="post" enctype="multipart/form-data" action="?page=form&action=save_submission" novalidate>
      <?php wp_nonce_field('allu_form_submission', 'allu_form_nonce'); ?>
      <div class="steps"><span class="active">1</span><span>2</span><span>3</span></div>
      <div class="step-labels"><span>Applicant Details</span><span>Clinical Details</span><span>Sign & Submit</span></div>

      <section class="step active" data-step="1">
        <div class="step-header"><h2>Step 1: Disclaimer & Applicant Details</h2><p>Review declaration and confirm your practitioner profile data.</p></div>
        <div class="disclaimer">
          <p>This digital interface is provided by Allu Therapeutics as a specialised tool to facilitate the compilation and generation of a formal application to Medsafe under Regulation 22 of the Misuse of Drugs Regulations 1977. Use of this platform does not constitute medical or regulatory advice. The Prescribing Doctor, as the Applicant, remains the primary Health Agency under the Health Information Privacy Code 2020 and bears sole legal and clinical responsibility for the accuracy of the protocol, the selection of patients, and the provision of unapproved controlled drugs. Allu Therapeutics acts as a secure data processor; all private clinical data is encrypted and held in strict confidence, accessible only to the authorised prescriber to support their professional obligations and mandatory safety reporting to the Ministry of Health. By utilising this facilitation tool, the prescriber acknowledges that Medsafe’s Ministerial approval is subject to their own clinical expertise, independent scientific peer review, and adherence to the applicable professional standards.</p>
        </div>
        <p style="font-size:12px;color:var(--muted);margin:6px 0 12px;">
          These details are fetched from your profile.
          <?php if (allu_form_is_wp_runtime()): ?>
            <a href="<?= esc_url(get_edit_profile_url()) ?>" target="_blank" style="color:var(--primary);">Edit your profile</a>
          <?php else: ?>
            <a href="#" style="color:var(--primary);">Edit your profile</a>
          <?php endif; ?>
        </p>
        <div class="grid two">
          <label>Title<input value="<?= htmlspecialchars($doctor['title']) ?>" readonly /></label>
          <label>First Name<input value="<?= htmlspecialchars($doctor['first_name']) ?>" readonly /></label>
          <label>Preferred Name<input value="<?= htmlspecialchars($doctor['preferred_name']) ?>" readonly /></label>
          <label>Surname<input value="<?= htmlspecialchars($doctor['surname']) ?>" readonly /></label>
          <label>Email<input value="<?= htmlspecialchars($doctor['email']) ?>" readonly /></label>
          <label>Phone<input value="<?= htmlspecialchars($doctor['phone']) ?>" readonly /></label>
          <label>HPI-CPN<input value="<?= htmlspecialchars($doctor['cpn']) ?>" readonly /></label>
        </div>
        <div class="grid two">
          <label>Does your annual practicing certificate (APC) include vocational scope(s)?
            <div style="display:flex;gap:16px;margin-top:4px;">
              <label style="flex-direction:row;align-items:center;font-weight:400;margin-top:0;">
                <input type="radio" name="vocational_scope_toggle" value="no" <?= (trim((string)($doctorPrefs['vocational_scope'] ?? '')) === '') ? 'checked' : '' ?> /> No
              </label>
              <label style="flex-direction:row;align-items:center;font-weight:400;margin-top:0;">
                <input type="radio" name="vocational_scope_toggle" value="yes" <?= (trim((string)($doctorPrefs['vocational_scope'] ?? '')) !== '') ? 'checked' : '' ?> /> Yes
              </label>
            </div>
            <div id="vocationalScopeTextWrap" class="<?= (trim((string)($doctorPrefs['vocational_scope'] ?? '')) === '') ? 'hidden' : '' ?>">
              <textarea name="vocational_scope" placeholder="Please specify your vocational scope(s)"><?= htmlspecialchars($doctorPrefs['vocational_scope']) ?></textarea>
            </div>
          </label>
          <label>Clinical Experience &amp; Training
            <textarea name="clinical_experience" required><?= htmlspecialchars($doctorPrefs['clinical_experience']) ?></textarea>
          </label>
        </div>
      </section>

      <section class="step" data-step="2" style="display:none;">
        <div class="step-header"><h2>Step 2: Product Details & Indication Mapping</h2><p>Select products and indication to auto-populate supporting sections.</p></div>

        <fieldset class="product-picker">
          <legend>Products</legend>
          <p class="hint">Select one or more products.</p>
          <div id="products" class="product-list" role="group" aria-label="Products">
            <?php foreach ($products as $p): ?>
              <label class="product-item">
                <input
                  class="product-check"
                  type="checkbox"
                  name="products[]"
                  value="<?= htmlspecialchars((string)$p['id']) ?>"
                  data-name="<?= htmlspecialchars($p['name']) ?>"
                  data-component="<?= htmlspecialchars($p['component']) ?>"
                  data-strength="<?= htmlspecialchars($p['strength']) ?>"
                  data-form="<?= htmlspecialchars($p['form']) ?>"
                  data-source="<?= htmlspecialchars($p['source']) ?>"
                  data-indications='<?= htmlspecialchars(json_encode($p['indications'] ?? []), ENT_QUOTES) ?>'
                  data-indication-map='<?= htmlspecialchars(json_encode($p['indication_map'] ?? []), ENT_QUOTES) ?>'
                />
                <span><?= htmlspecialchars($p['name']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </fieldset>

        <div class="grid two">
          <label>Component
            <div id="componentAutoDisplay" class="auto-display"></div>
            <textarea id="componentAuto" class="hidden" readonly></textarea>
          </label>
          <label>Strength
            <div id="strengthAutoDisplay" class="auto-display"></div>
            <textarea id="strengthAuto" class="hidden" readonly></textarea>
          </label>
          <label>Form
            <div id="formAutoDisplay" class="auto-display"></div>
            <textarea id="formAuto" class="hidden" readonly></textarea>
          </label>
          <label>Sourced from
            <div id="sourcingAutoDisplay" class="auto-display"></div>
            <textarea id="sourcingAuto" name="sourcing_notes" class="hidden"></textarea>
          </label>
        </div>

        <label class="hidden">Indication
          <select id="indicationSelect" name="indication" class="hidden">
            <option value="">Select indication</option>
          </select>
        </label>
        <div id="productIndicationsContainer"></div>
        <label id="indicationOtherWrap" class="hidden">Other indication
          <input type="text" name="indication_other" id="indicationOtherInput" placeholder="Enter custom indication" />
        </label>

        <label>Supporting Evidence
          <textarea id="supportingEvidenceAuto" name="supporting_evidence_notes"></textarea>
        </label>

        <label>Treatment Protocol
          <textarea id="treatmentProtocolAuto" name="treatment_protocol_notes"></textarea>
        </label>

        <label>Scientific Peer Review
          <textarea id="peerReviewAuto" name="scientific_peer_review_notes"></textarea>
        </label>

        <label>Admin &amp; Monitoring
          <textarea id="adminMonitoringNotes" name="admin_monitoring_notes" placeholder="Describe where you will be administering and monitoring the treatment"></textarea>
        </label>

      </section>

      <section class="step" data-step="3" style="display:none;">
        <div class="step-header"><h2>Step 3: Date, Signature & Submit</h2><p>Add date and a mandatory digital signature.</p></div>
        <label>Date
          <input type="date" name="application_date" value="<?= date('Y-m-d') ?>" required />
        </label>

        <fieldset class="signature-fieldset">
          <legend>Electronic Signature (required)</legend>
          <div class="signature-options">
            <label><input type="radio" name="signature_mode" value="draw" checked /> Draw signature</label>
            <label><input type="radio" name="signature_mode" value="upload" /> Upload signature image</label>
          </div>

          <div id="drawWrap">
            <canvas id="signaturePad" width="500" height="160"></canvas>
            <button id="clearSig" type="button">Clear</button>
            <input type="hidden" name="signature_drawn" id="signatureDrawn" />
          </div>

          <div id="uploadWrap" class="hidden">
            <input type="file" name="signature_upload" accept="image/*" id="signatureUploadInput" />
            <img id="signatureUploadPreview" style="display:none;max-width:500px;max-height:160px;margin-top:8px;border:1px solid #cdd7e5;border-radius:10px;" alt="Signature preview" />
          </div>
        </fieldset>
      </section>

      <div class="nav-buttons">
        <button type="button" id="prevBtn">Previous</button>
        <button type="button" id="nextBtn">Next</button>
        <button type="submit" id="submitBtn" class="hidden">Submit & Generate PDF</button>
      </div>
    </form>
    <?php endif; ?>
  <?php endif; ?>
</div>
<script>
const steps = [...document.querySelectorAll('.step')];
const badges = [...document.querySelectorAll('.steps span')];
let idx = 0;
const prevBtn = document.getElementById('prevBtn');
const nextBtn = document.getElementById('nextBtn');
const submitBtn = document.getElementById('submitBtn');

function showStep(i) {
  steps.forEach((s, n) => {
    const active = n === i;
    s.classList.toggle('active', active);
    s.style.display = active ? 'block' : 'none';
  });
  badges.forEach((b, n) => b.classList.toggle('active', n === i));
  prevBtn.style.visibility = i === 0 ? 'hidden' : 'visible';
  nextBtn.classList.toggle('hidden', i === steps.length - 1);
  submitBtn.classList.toggle('hidden', i !== steps.length - 1);
}

if (nextBtn) nextBtn.onclick = () => { if (idx < steps.length - 1) { idx++; showStep(idx); } };
if (prevBtn) prevBtn.onclick = () => { if (idx > 0) { idx--; showStep(idx); } };
if (steps.length) showStep(0);

const productWrap = document.getElementById('products');
const indicationSelect = document.getElementById('indicationSelect');
const indicationOtherWrap = document.getElementById('indicationOtherWrap');
const productIndicationsContainer = document.getElementById('productIndicationsContainer');

const decodeJsonData = (value, fallback = []) => {
  try { return JSON.parse(value || '[]'); } catch (e) { return fallback; }
};

const escapeHtml = (str) => {
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
};

function buildAutoDisplayHtml(selected, field) {
  return selected.map(o => {
    const name = escapeHtml(o.dataset.name || '');
    const val = escapeHtml(o.dataset[field] || '-');
    return `<div><span class="auto-product-name">${name}:</span> <span class="auto-product-value">${val}</span></div>`;
  }).join('');
}

function syncProductAuto() {
  if (!productWrap) return;
  const selected = [...document.querySelectorAll('.product-check:checked')];

  const components = selected.map(o => `\u2022 ${o.dataset.name}: ${o.dataset.component || '-'}`).join('\n');
  const strengths = selected.map(o => `\u2022 ${o.dataset.name}: ${o.dataset.strength || '-'}`).join('\n');
  const forms = selected.map(o => `\u2022 ${o.dataset.name}: ${o.dataset.form || '-'}`).join('\n');
  const sources = selected.map(o => `\u2022 ${o.dataset.name}: ${o.dataset.source || '-'}`).join('\n');

  document.getElementById('componentAuto').value = components;
  document.getElementById('strengthAuto').value = strengths;
  document.getElementById('formAuto').value = forms;
  document.getElementById('sourcingAuto').value = sources;

  document.getElementById('componentAutoDisplay').innerHTML = buildAutoDisplayHtml(selected, 'component');
  document.getElementById('strengthAutoDisplay').innerHTML = buildAutoDisplayHtml(selected, 'strength');
  document.getElementById('formAutoDisplay').innerHTML = buildAutoDisplayHtml(selected, 'form');
  document.getElementById('sourcingAutoDisplay').innerHTML = buildAutoDisplayHtml(selected, 'source');

  // Build per-product indication dropdowns
  if (productIndicationsContainer) {
    const existingValues = {};
    productIndicationsContainer.querySelectorAll('select[name^="product_indications"]').forEach(sel => {
      const pid = sel.getAttribute('data-product-id');
      if (pid) existingValues[pid] = sel.value;
    });
    const existingOthers = {};
    productIndicationsContainer.querySelectorAll('input[name^="product_indication_others"]').forEach(inp => {
      const pid = inp.getAttribute('data-product-id');
      if (pid) existingOthers[pid] = inp.value;
    });

    productIndicationsContainer.innerHTML = '';
    selected.forEach(o => {
      const pid = o.value;
      const pName = o.dataset.name;
      const indications = decodeJsonData(o.dataset.indications, []);

      const wrapper = document.createElement('div');
      wrapper.className = 'product-indication-row';
      wrapper.style.cssText = 'margin-top:10px;';

      const lbl = document.createElement('label');
      lbl.textContent = 'Indication for ' + pName;

      const sel = document.createElement('select');
      sel.name = 'product_indications[' + pid + ']';
      sel.setAttribute('data-product-id', pid);
      sel.required = true;

      const defaultOpt = document.createElement('option');
      defaultOpt.value = '';
      defaultOpt.textContent = 'Select indication';
      sel.appendChild(defaultOpt);

      indications.forEach(ind => {
        const opt = document.createElement('option');
        opt.value = ind;
        opt.textContent = ind;
        sel.appendChild(opt);
      });

      const otherOpt = document.createElement('option');
      otherOpt.value = 'Other';
      otherOpt.textContent = 'Other';
      sel.appendChild(otherOpt);

      if (existingValues[pid] && [...sel.options].some(op => op.value === existingValues[pid])) {
        sel.value = existingValues[pid];
      }

      const otherInput = document.createElement('input');
      otherInput.type = 'text';
      otherInput.name = 'product_indication_others[' + pid + ']';
      otherInput.setAttribute('data-product-id', pid);
      otherInput.placeholder = 'Enter custom indication';
      otherInput.style.cssText = 'margin-top:6px;';
      otherInput.className = (sel.value === 'Other') ? '' : 'hidden';
      if (existingOthers[pid]) otherInput.value = existingOthers[pid];

      sel.addEventListener('change', () => {
        otherInput.className = (sel.value === 'Other') ? '' : 'hidden';
        if (sel.value !== 'Other') otherInput.value = '';
        syncIndicationAuto();
      });

      lbl.appendChild(sel);
      lbl.appendChild(otherInput);
      wrapper.appendChild(lbl);
      productIndicationsContainer.appendChild(wrapper);
    });
  }

  syncIndicationAuto();
}

function syncIndicationAuto() {
  if (!productWrap) return;
  const selected = [...document.querySelectorAll('.product-check:checked')];

  const supporting = [];
  const protocol = [];
  const peerReview = [];

  selected.forEach(o => {
    const pid = o.value;
    const map = decodeJsonData(o.dataset.indicationMap, {});
    const sel = productIndicationsContainer ? productIndicationsContainer.querySelector('select[data-product-id="' + pid + '"]') : null;
    const indication = sel ? sel.value : '';
    const row = map[indication] || null;
    if (!row) return;
    supporting.push(`• ${o.dataset.name}: ${row.supporting_evidence || '-'}`);
    protocol.push(`• ${o.dataset.name}: ${row.treatment_protocol || '-'}`);
    peerReview.push(`• ${o.dataset.name}: ${row.scientific_peer_review || '-'}`);
  });

  document.getElementById('supportingEvidenceAuto').value = supporting.join('\n');
  document.getElementById('treatmentProtocolAuto').value = protocol.join('\n');
  document.getElementById('peerReviewAuto').value = peerReview.join('\n');
}

if (productWrap) {
  productWrap.addEventListener('change', syncProductAuto);
  syncProductAuto();
}
if (submitBtn) {
  submitBtn.addEventListener('click', (e) => {
    const errors = [];
    const selectedProducts = document.querySelectorAll('.product-check:checked');
    const vocationalToggle = document.querySelector('input[name="vocational_scope_toggle"]:checked')?.value || 'no';
    const vocational = document.querySelector('textarea[name="vocational_scope"]')?.value.trim();
    const experience = document.querySelector('textarea[name="clinical_experience"]')?.value.trim();
    const date = document.querySelector('input[name="application_date"]')?.value.trim();
    const mode = document.querySelector('input[name="signature_mode"]:checked')?.value || '';
    const drawn = document.getElementById('signatureDrawn')?.value || '';
    const uploadFile = document.querySelector('input[name="signature_upload"]')?.files?.length || 0;

    if (vocationalToggle === 'yes' && !vocational) errors.push('Please specify your vocational scope(s).');
    if (!experience) errors.push('Clinical Experience & Training is required.');
    if (!selectedProducts.length) errors.push('Please select at least one product.');

    // Validate per-product indications
    if (productIndicationsContainer) {
      selectedProducts.forEach(chk => {
        const pid = chk.value;
        const pName = chk.dataset.name;
        const sel = productIndicationsContainer.querySelector('select[data-product-id="' + pid + '"]');
        if (sel && !sel.value) errors.push('Please select an indication for ' + pName + '.');
        if (sel && sel.value === 'Other') {
          const otherInp = productIndicationsContainer.querySelector('input[data-product-id="' + pid + '"]');
          if (otherInp && !otherInp.value.trim()) errors.push('Please enter a custom indication for ' + pName + '.');
        }
      });
    }

    if (!date) errors.push('Application date is required.');
    if (mode === 'draw' && !drawn) errors.push('Please provide a drawn signature.');
    if (mode === 'upload' && !uploadFile) errors.push('Please upload a signature image.');

    if (errors.length) {
      e.preventDefault();
      const box = document.createElement('div');
      box.className = 'alert error';
      box.innerHTML = errors.map(msg => `<div>• ${msg}</div>`).join('');
      const form = document.getElementById('prescriptionForm');
      const existing = form.querySelector('.client-errors');
      if (existing) existing.remove();
      box.classList.add('client-errors');
      form.prepend(box);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  });
}

const drawWrap = document.getElementById('drawWrap');
const uploadWrap = document.getElementById('uploadWrap');
const vocScopeWrap = document.getElementById('vocationalScopeTextWrap');
document.querySelectorAll('input[name="vocational_scope_toggle"]').forEach(r => {
  r.addEventListener('change', () => {
    const yes = document.querySelector('input[name="vocational_scope_toggle"]:checked').value === 'yes';
    if (vocScopeWrap) {
      vocScopeWrap.classList.toggle('hidden', !yes);
      if (!yes) {
        const ta = vocScopeWrap.querySelector('textarea[name="vocational_scope"]');
        if (ta) ta.value = '';
      }
    }
  });
});
document.querySelectorAll('input[name="signature_mode"]').forEach(r => {
  r.addEventListener('change', () => {
    const draw = document.querySelector('input[name="signature_mode"]:checked').value === 'draw';
    drawWrap.classList.toggle('hidden', !draw);
    uploadWrap.classList.toggle('hidden', draw);
  });
});

const canvas = document.getElementById('signaturePad');
if (canvas) {
  const ctx = canvas.getContext('2d');
  let drawing = false;
  ctx.strokeStyle = '#111';
  ctx.lineWidth = 2;

  const resetSignatureCanvas = () => {
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.strokeStyle = '#111';
    ctx.lineWidth = 2;
  };

  resetSignatureCanvas();

  const pos = e => {
    const r = canvas.getBoundingClientRect();
    const p = e.touches ? e.touches[0] : e;
    return { x: p.clientX - r.left, y: p.clientY - r.top };
  };

  const start = e => { drawing = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); };
  const move = e => { if (!drawing) return; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); };
  const stop = () => { drawing = false; document.getElementById('signatureDrawn').value = canvas.toDataURL('image/jpeg', 0.9); };

  canvas.addEventListener('mousedown', start);
  canvas.addEventListener('mousemove', move);
  window.addEventListener('mouseup', stop);
  canvas.addEventListener('touchstart', start);
  canvas.addEventListener('touchmove', move);
  canvas.addEventListener('touchend', stop);

  document.getElementById('clearSig').addEventListener('click', () => {
    resetSignatureCanvas();
    document.getElementById('signatureDrawn').value = '';
  });
}

const sigUploadInput = document.getElementById('signatureUploadInput');
const sigUploadPreview = document.getElementById('signatureUploadPreview');
if (sigUploadInput && sigUploadPreview) {
  sigUploadInput.addEventListener('change', () => {
    const file = sigUploadInput.files[0];
    if (file) {
      const reader = new FileReader();
      reader.onload = (e) => {
        sigUploadPreview.src = e.target.result;
        sigUploadPreview.style.display = 'block';
      };
      reader.readAsDataURL(file);
    } else {
      sigUploadPreview.style.display = 'none';
      sigUploadPreview.src = '';
    }
  });
}
</script>
    <?php
    return ob_get_clean();
}
