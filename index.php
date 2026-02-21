<?php
session_start();
bootstrapWordPressRuntimeIfAvailable();

define('DATA_DIR', __DIR__ . '/data');
define('SUBMISSIONS_DIR', DATA_DIR . '/submissions');
define('PRODUCTS_FILE', DATA_DIR . '/products.json');
define('DOCTOR_PREFS_FILE', DATA_DIR . '/doctor_prefs.json');
define('PDF_TEMPLATE_FILE', DATA_DIR . '/ApprovalToPrescribePsychedelics.pdf');
define('PDF_TEMPLATE_URL', 'https://www.medsafe.govt.nz/downloads/ApprovalToPrescribePsychedelics.pdf');
define('PDF_FIELD_MAP_FILE', DATA_DIR . '/pdf_field_map.json');
/** @var float Default Y position for signature box on page 6. */
const PDF_DEFAULT_SIGNATURE_Y = 536;
/** @var float Horizontal offset from label to value in label-value pairs. */
const PDF_LABEL_VALUE_OFFSET = 120;
/** @var int Total number of pages in the generated PDF. */
const PDF_TOTAL_PAGES = 6;
/** @var float Medsafe purple red component (#573F7F). */
const MEDSAFE_PURPLE_R = 0.341;
/** @var float Medsafe purple green component (#573F7F). */
const MEDSAFE_PURPLE_G = 0.247;
/** @var float Medsafe purple blue component (#573F7F). */
const MEDSAFE_PURPLE_B = 0.498;

bootstrapStorage();
registerWordPressHooks();

$page = $_GET['page'] ?? 'form';
$action = $_GET['action'] ?? null;
$message = null;
$error = null;
$doctor = getDoctorProfile();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'save_submission') {
        [$ok, $message, $error, $submissionId] = saveSubmission($doctor, $_POST, $_FILES);
        if ($ok && $submissionId) {
            $_SESSION['form_success'] = $message;
            header('Location: ?page=form&submitted=1&id=' . urlencode($submissionId));
            exit;
        }
    }

    if ($action === 'save_product' && isAdmin() && !isWordPressRuntime()) {
        [$ok, $message, $error] = saveProduct($_POST);
    }

    if ($action === 'delete_product' && isAdmin() && !isWordPressRuntime()) {
        [$ok, $message, $error] = deleteProduct($_POST['product_id'] ?? '');
    }

    if ($action === 'email_pdf' && isAdmin()) {
        [$ok, $message, $error] = emailPdfToDoctor($_POST['submission_id'] ?? '');
    }
}

if ($action === 'download_pdf') {
    downloadPdf($_GET['id'] ?? '');
    exit;
}

if ($action === 'download_support') {
    downloadSupportDocument($_GET['submission_id'] ?? '', $_GET['file'] ?? '');
    exit;
}

$doctorPrefs = getDoctorPrefs((int)$doctor['id']);
$products = getProducts();
$submissions = getSubmissions();
$submissionView = null;
if (($page === 'form') && ($_GET['submitted'] ?? '') === '1') {
    $submissionView = findSubmission($_GET['id'] ?? '');
    if (!$submissionView) {
        $error = 'Submitted record not found. Please try again.';
    }
}

function bootstrapWordPressRuntimeIfAvailable(): void
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

function isWordPressRuntime(): bool
{
    return function_exists('wp_get_current_user');
}

function registerWordPressHooks(): void
{
    if (!function_exists('add_action')) {
        return;
    }

    add_action('show_user_profile', 'renderCpnUserField');
    add_action('edit_user_profile', 'renderCpnUserField');
    add_action('personal_options_update', 'saveCpnUserField');
    add_action('edit_user_profile_update', 'saveCpnUserField');

    add_action('woocommerce_product_options_general_product_data', 'renderPrescriptionProductFields');
    add_action('woocommerce_process_product_meta', 'savePrescriptionProductFields');
}

function renderCpnUserField($user): void
{
    if (!function_exists('get_user_meta')) {
        return;
    }
    $cpn = get_user_meta($user->ID, 'cpn', true);
    $title = get_user_meta($user->ID, 'title', true);
    $preferredName = get_user_meta($user->ID, 'preferred_name', true);
    echo '<h2>Prescription Form Fields</h2>';
    echo '<table class="form-table">';
    echo '<tr><th><label for="allu_form_title">Title</label></th>';
    echo '<td><input type="text" name="title" id="allu_form_title" value="' . esc_attr($title) . '" class="regular-text" /></td></tr>';
    echo '<tr><th><label for="allu_form_preferred_name">Preferred Name</label></th>';
    echo '<td><input type="text" name="preferred_name" id="allu_form_preferred_name" value="' . esc_attr($preferredName) . '" class="regular-text" /></td></tr>';
    echo '<tr><th><label for="cpn">CPN</label></th>';
    echo '<td><input type="text" name="cpn" id="cpn" value="' . esc_attr($cpn) . '" class="regular-text" /></td></tr>';
    echo '</table>';
}

function saveCpnUserField(int $userId): void
{
    if (!function_exists('current_user_can') || !current_user_can('edit_user', $userId)) {
        return;
    }
    if (function_exists('update_user_meta')) {
        if (isset($_POST['cpn'])) {
            update_user_meta($userId, 'cpn', sanitize_text_field($_POST['cpn']));
        }
        if (isset($_POST['title'])) {
            update_user_meta($userId, 'title', sanitize_text_field($_POST['title']));
        }
        if (isset($_POST['preferred_name'])) {
            update_user_meta($userId, 'preferred_name', sanitize_text_field($_POST['preferred_name']));
        }
    }
}

function renderPrescriptionProductFields(): void
{
    if (!function_exists('woocommerce_wp_text_input') || !function_exists('woocommerce_wp_textarea_input')) {
        return;
    }

    echo '<div class="options_group">';
    woocommerce_wp_text_input(['id' => '_prescription_component', 'label' => 'Prescription Component']);
    woocommerce_wp_text_input(['id' => '_prescription_strength', 'label' => 'Prescription Strength']);
    woocommerce_wp_text_input(['id' => '_prescription_form', 'label' => 'Prescription Form']);
    woocommerce_wp_text_input(['id' => '_prescription_source', 'label' => 'Sourced From']);
    woocommerce_wp_text_input(['id' => '_prescription_indications', 'label' => 'Indications (comma separated)']);
    woocommerce_wp_textarea_input([
        'id' => '_prescription_supporting_evidence_map',
        'label' => 'Supporting Evidence Mapping',
        'desc_tip' => true,
        'description' => 'One per line: Indication | supporting evidence text',
    ]);
    woocommerce_wp_textarea_input([
        'id' => '_prescription_treatment_protocol_map',
        'label' => 'Treatment Protocol Mapping',
        'desc_tip' => true,
        'description' => 'One per line: Indication | treatment protocol text',
    ]);
    woocommerce_wp_textarea_input([
        'id' => '_prescription_scientific_peer_review_map',
        'label' => 'Scientific Peer Review Mapping',
        'desc_tip' => true,
        'description' => 'One per line: Indication | scientific peer review text',
    ]);
    woocommerce_wp_textarea_input([
        'id' => '_prescription_indication_map',
        'label' => 'Indication Mapping JSON (optional)',
        'desc_tip' => true,
        'description' => 'Optional advanced JSON. Usually not needed if using mapping textareas above.',
    ]);
    echo '</div>';
}

function savePrescriptionProductFields(int $productId): void
{
    if (!function_exists('update_post_meta')) {
        return;
    }

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
        if (isset($_POST[$key])) {
            update_post_meta($productId, $key, wp_kses_post(wp_unslash($_POST[$key])));
        }
    }
}

function getDoctorProfile(): array
{
    if (isWordPressRuntime()) {
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

function bootstrapStorage(): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0777, true);
    }
    if (!is_dir(SUBMISSIONS_DIR)) {
        mkdir(SUBMISSIONS_DIR, 0777, true);
    }
    if (!file_exists(PRODUCTS_FILE)) {
        $seed = [
            ['id' => 'prd-001', 'name' => 'Psilocybin Oral Capsule', 'component' => 'Psilocybin', 'strength' => '25mg', 'form' => 'Capsule', 'source' => 'Medsafe-approved compounding supplier, NZ', 'indications' => ['Depression'], 'indication_map' => ['Depression' => ['supporting_evidence' => 'Default supporting evidence', 'treatment_protocol' => 'Default treatment protocol', 'scientific_peer_review' => 'Default peer review']]],
        ];
        file_put_contents(PRODUCTS_FILE, json_encode($seed, JSON_PRETTY_PRINT));
    }
    if (!file_exists(DOCTOR_PREFS_FILE)) {
        file_put_contents(DOCTOR_PREFS_FILE, json_encode([], JSON_PRETTY_PRINT));
    }

    ensurePdfTemplate();
}

function ensurePdfTemplate(): void
{
    if (file_exists(PDF_TEMPLATE_FILE)) {
        return;
    }

    $pdf = @file_get_contents(PDF_TEMPLATE_URL);
    if ($pdf && str_starts_with($pdf, '%PDF')) {
        file_put_contents(PDF_TEMPLATE_FILE, $pdf);
    }
}

function isAdmin(): bool
{
    return ($_GET['page'] ?? 'form') === 'admin';
}

function parseIndicationLineMap(string $raw): array
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

function buildIndicationMapFromInputs(array $data): array
{
    $supporting = parseIndicationLineMap((string)($data['supporting_evidence_map'] ?? ''));
    $protocol = parseIndicationLineMap((string)($data['treatment_protocol_map'] ?? ''));
    $peer = parseIndicationLineMap((string)($data['scientific_peer_review_map'] ?? ''));

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

function getProducts(): array
{
    if (isWordPressRuntime() && function_exists('wc_get_products')) {
        $wcProducts = wc_get_products(['status' => 'publish', 'limit' => -1]);
        $mapped = [];
        foreach ($wcProducts as $product) {
            $pid = $product->get_id();
            $indicationMapRaw = (string)get_post_meta($pid, '_prescription_indication_map', true);
            $indicationMap = json_decode($indicationMapRaw, true);
            if (!is_array($indicationMap)) {
                $indicationMap = [];
            }

            $uiMap = buildIndicationMapFromInputs([
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

    return json_decode(file_get_contents(PRODUCTS_FILE), true) ?: [];
}

function saveProduct(array $data): array
{
    $name = trim($data['name'] ?? '');
    if ($name === '') {
        return [false, null, 'Product name is required.'];
    }

    $products = getProducts();
    $products[] = [
        'id' => 'prd-' . substr(md5(uniqid('', true)), 0, 8),
        'name' => $name,
        'component' => trim($data['component'] ?? ''),
        'strength' => trim($data['strength'] ?? ''),
        'form' => trim($data['form'] ?? ''),
        'source' => trim($data['source'] ?? ''),
        'indications' => array_values(array_filter(array_map('trim', explode(',', (string)($data['indications'] ?? ''))))),
        'indication_map' => buildIndicationMapFromInputs($data),
    ];
    file_put_contents(PRODUCTS_FILE, json_encode($products, JSON_PRETTY_PRINT));
    return [true, 'Product added successfully.', null];
}

function deleteProduct(string $id): array
{
    if ($id === '') {
        return [false, null, 'Missing product id.'];
    }
    $products = array_values(array_filter(getProducts(), fn($p) => ($p['id'] ?? '') !== $id));
    file_put_contents(PRODUCTS_FILE, json_encode($products, JSON_PRETTY_PRINT));
    return [true, 'Product deleted.', null];
}

function getDoctorPrefs(int $doctorId): array
{
    $prefs = json_decode(file_get_contents(DOCTOR_PREFS_FILE), true) ?: [];
    return $prefs[$doctorId] ?? ['vocational_scope' => '', 'clinical_experience' => ''];
}

function saveDoctorPrefs(int $doctorId, array $payload): void
{
    $prefs = json_decode(file_get_contents(DOCTOR_PREFS_FILE), true) ?: [];
    $prefs[$doctorId] = [
        'vocational_scope' => trim($payload['vocational_scope'] ?? ''),
        'clinical_experience' => trim($payload['clinical_experience'] ?? ''),
    ];
    file_put_contents(DOCTOR_PREFS_FILE, json_encode($prefs, JSON_PRETTY_PRINT));
}

function getSubmissions(): array
{
    $records = [];
    foreach (glob(SUBMISSIONS_DIR . '/*.json') as $file) {
        $entry = json_decode(file_get_contents($file), true);
        if ($entry) {
            $records[] = $entry;
        }
    }
    usort($records, fn($a, $b) => strcmp($b['submitted_at'], $a['submitted_at']));
    return $records;
}

function buildSelectedProductDetails(array $selectedProductIds): array
{
    $catalog = getProducts();
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

function saveSubmission(array $doctor, array $post, array $files): array
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
        $target = SUBMISSIONS_DIR . '/' . $sigName;
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

    $selectedDetails = buildSelectedProductDetails($selectedProducts);
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
    saveDoctorPrefs((int)$doctor['id'], $post);

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

    $pdfPath = SUBMISSIONS_DIR . '/' . $id . '.pdf';
    [$pdfOk, $pdfError] = createRegulatorPdf($submission, $pdfPath);
    if (!$pdfOk) {
        return [false, null, $pdfError, null];
    }
    $submission['pdf_file'] = basename($pdfPath);

    file_put_contents(SUBMISSIONS_DIR . '/' . $id . '.json', json_encode($submission, JSON_PRETTY_PRINT));

    return [true, 'Submission saved and PDF generated successfully.', null, $id];
}

function createRegulatorPdf(array $submission, string $path): array
{
    $ok = generateSubmissionPdfFromScratch($submission, $path);
    if ($ok) {
        return [true, null];
    }

    return [false, 'Unable to generate PDF file.'];
}

function generateSubmissionPdfFromScratch(array $submission, string $path): bool
{
    $doctor = $submission['doctor'] ?? [];
    $form = $submission['form'] ?? [];
    $products = buildSubmissionProductRows($submission);

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
    $yt = fn(float $topY, float $size) => pdfYFromTop($topY, $size, $pageH);

    // ============================================================
    // PAGE 1: Static Information Page
    // ============================================================
    $p1 = [];

    // MEDSAFE logo image (will be embedded as XObject)
    // Logo placement command - will be replaced with actual image in writeMultiPagePdf
    $logoPath = __DIR__ . '/medsafe-logo.jpg';
    $logoCmd = '';
    if (file_exists($logoPath)) {
        // Place logo at top-left, scaled to approximately 160x50
        $logoCmd = "q\n160 0 0 50 18 " . number_format($pageH - 80, 2, '.', '') . " cm\n/LogoIm Do\nQ";
        $p1[] = $logoCmd;
    } else {
        // Fallback to text if logo not available
        $p1[] = pdfFillColor($pR, $pG, $pB);
        $p1[] = pdfBoldTextCommand('MEDSAFE', 48, $yt(30, 20), 20);
        $p1[] = pdfFillColor(0.3, 0.3, 0.3);
        $p1[] = pdfTextCommand('New Zealand Medicines and', 48, $yt(50, 7), 7);
        $p1[] = pdfTextCommand('Medical Devices Safety Authority', 48, $yt(58, 7), 7);
    }

    // "Application Form" title (top-right)
    $p1[] = pdfFillColor($pR, $pG, $pB);
    $p1[] = pdfBoldTextCommand('Application Form', 352.6, $yt(30.7, 26), 26);

    // Title block with purple border
    $titleBoxTop = 90.065;
    $titleBoxBot = 189.0;
    $p1[] = pdfStrokeColor($pR, $pG, $pB);
    $p1[] = '0.50 w';
    $p1[] = pdfRectCommand(18.0, $pageH - $titleBoxBot, $contentR - 18.0, $titleBoxBot - $titleBoxTop, false);

    // Title text inside box
    $p1[] = pdfFillColor($pR, $pG, $pB);
    $p1[] = pdfBoldTextCommand('Approval to Prescribe/Supply/Administer', 28.4, $yt(95.6, 22), 22);
    $p1[] = pdfTextCommand('Application for a New Approval (Psychedelic-assisted therapy)', 28.4, $yt(136.5, 16), 16);
    $p1[] = pdfTextCommand('Misuse of Drugs Regulations 1977', 28.4, $yt(167.2, 11), 11);

    // "INFORMATION FOR APPLICANTS" box
    $infoBoxTop = 207.0;
    $infoBoxBot = 378.0;
    $p1[] = pdfStrokeColor(0, 0, 0);
    $p1[] = '0.50 w';
    $p1[] = pdfRectCommand(18.0, $pageH - $infoBoxBot, $contentR - 18.0, $infoBoxBot - $infoBoxTop, false);

    $p1[] = pdfFillColor(0, 0, 0);
    $p1[] = pdfBoldTextCommand('INFORMATION FOR APPLICANTS', 28.7, $yt(206.8, 12), 12);

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
        $p1[] = pdfTextCommand("\x95", 44.4, $yt($bulletY, 11), 11);
        $bLines = explode("\n", $bullet);
        foreach ($bLines as $bl) {
            $p1[] = pdfTextCommand(trim($bl), 55.4, $yt($bulletY, 11), 11);
            $bulletY += 13.2;
        }
        $bulletY += 6;
    }

    // "APPLICATION FORM SUBMISSION" box
    $subBoxTop = 396.0;
    $subBoxBot = 522.0;
    $p1[] = pdfStrokeColor(0, 0, 0);
    $p1[] = pdfRectCommand(18.0, $pageH - $subBoxBot, $contentR - 18.0, $subBoxBot - $subBoxTop, false);

    $p1[] = pdfFillColor(0, 0, 0);
    $p1[] = pdfBoldTextCommand('APPLICATION FORM SUBMISSION', 28.5, $yt(395.8, 12), 12);

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
        $p1[] = pdfTextCommand("\x95", 44.4, $yt($bulletY, 11), 11);
        $bLines = explode("\n", $bullet);
        foreach ($bLines as $bl) {
            $p1[] = pdfTextCommand(trim($bl), 55.4, $yt($bulletY, 11), 11);
            $bulletY += 13.2;
        }
        $bulletY += 6;
    }

    $p1[] = pdfMedsafeFooter(1, $totalPages, $pageW, $pageH, $pR, $pG, $pB);

    // ============================================================
    // PAGE 2: Section 1 - Applicant
    // ============================================================
    $p2 = [];

    // Section title
    $p2[] = pdfFillColor($pR, $pG, $pB);
    $p2[] = pdfBoldTextCommand('Section 1: Applicant', $margin, $yt(17.7, 18), 18);
    $p2[] = pdfTextCommand('The Applicant is the medical practitioner completing this form, who is applying for the Approval.', $margin, $yt(43.6, 10.5), 10.5);
    $p2[] = pdfFillColor(0, 0, 0);

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
        $p2[] = pdfTextCommand($fd[0], $bodyMargin, $yt($fd[1], 10), 10);
        $boxX = $fd[2];
        $boxW = $fd[3] - $fd[2];
        $boxY = $pageH - $fd[1] - 15.84;  // box top aligns with label
        $boxH = 19.84;
        $p2[] = pdfStrokeColor(0, 0, 0);
        $p2[] = '0.50 w';
        $p2[] = pdfRectCommand($boxX, $boxY, $boxW, $boxH, false);
        if (isset($fieldValues[$idx]) && $fieldValues[$idx] !== '') {
            $p2[] = pdfTextCommand($fieldValues[$idx], $boxX + 4, $boxY + 5, 10);
        }
    }

    // "Contact details" sub-heading
    $p2[] = pdfBoldTextCommand('Contact details', $margin, $yt(187.8, 10), 10);

    // Email and Phone fields
    $contactFields = [
        ['1.5. Email:', 213.5, 117.13, 573.17, (string)($doctor['email'] ?? '')],
        ['1.6. Phone:', 240.5, 117.13, 294.17, (string)($doctor['phone'] ?? '')],
    ];
    foreach ($contactFields as $cf) {
        $p2[] = pdfTextCommand($cf[0], $bodyMargin, $yt($cf[1], 10), 10);
        $boxX = $cf[2];
        $boxW = $cf[3] - $cf[2];
        $boxY = $pageH - $cf[1] - 15.84;
        $boxH = 19.84;
        $p2[] = pdfRectCommand($boxX, $boxY, $boxW, $boxH, false);
        if ($cf[4] !== '') {
            $p2[] = pdfTextCommand($cf[4], $boxX + 4, $boxY + 5, 10);
        }
    }

    // "Health practitioner registration details" sub-heading
    $p2[] = pdfBoldTextCommand('Health practitioner registration details', $margin, $yt(268.7, 10), 10);

    // HPI-CPN field
    $cpnVal = (string)($doctor['cpn'] ?? '');
    $p2[] = pdfTextCommand('1.7. HPI-CPN:', $bodyMargin, $yt(294.5, 10), 10);
    $p2[] = pdfRectCommand(117.13, $pageH - 294.5 - 15.84, 294.17 - 117.13, 19.84, false);
    if ($cpnVal !== '') {
        $p2[] = pdfTextCommand($cpnVal, 121.13, $pageH - 294.5 - 10.84, 10);
    }

    // Vocational scope question
    $p2[] = pdfTextCommand('1.8. Does your annual practicing certificate (APC) include vocational scope(s)?', $bodyMargin, $yt(325.5, 10), 10);

    // Checkboxes
    $vocScope = (string)($form['vocational_scope'] ?? '');
    $hasScope = $vocScope !== '';

    // No checkbox
    $p2[] = pdfStrokeColor(0, 0, 0);
    $p2[] = pdfRectCommand(47.83, $pageH - 355.5, 10, 10, false);
    if (!$hasScope) {
        $p2[] = pdfFillColor(0, 0, 0);
        $p2[] = pdfCheckmarkCommand(48.5, $pageH - 354.5, 10);
    }
    $p2[] = pdfFillColor(0, 0, 0);
    $p2[] = pdfTextCommand('No', 63.4, $yt(343.0, 10), 10);

    // Yes checkbox
    $p2[] = pdfRectCommand(47.83, $pageH - 373.5, 10, 10, false);
    if ($hasScope) {
        $p2[] = pdfFillColor(0, 0, 0);
        $p2[] = pdfCheckmarkCommand(48.5, $pageH - 372.5, 10);
    }
    $p2[] = pdfFillColor(0, 0, 0);
    $p2[] = pdfTextCommand('Yes, please specify:', 63.1, $yt(361.0, 10), 10);

    // Vocational scope text box
    $p2[] = pdfRectCommand(47.83, $pageH - 429.17, 573.17 - 47.83, 429.17 - 380.84, false);
    if ($hasScope) {
        $scopeLines = pdfWordWrap($vocScope, 90);
        $scopeY = 393;
        foreach ($scopeLines as $sl) {
            $p2[] = pdfTextCommand($sl, 52, $yt($scopeY, 9), 9);
            $scopeY += 12;
        }
    }

    // "Clinical expertise and training" sub-heading
    $p2[] = pdfBoldTextCommand('Clinical expertise and training', $margin, $yt(448.8, 10), 10);

    // Clinical experience question
    $p2[] = pdfTextCommand('1.9. Describe the clinical experience and training you hold that is applicable to the proposed use of the product:', $bodyMargin, $yt(469.5, 10), 10);

    // Large text box for clinical experience
    $p2[] = pdfRectCommand(47.83, $pageH - 798.17, 573.17 - 47.83, 798.17 - 488.84, false);
    $expText = (string)($form['clinical_experience'] ?? '');
    if ($expText !== '') {
        $expLines = pdfWordWrap($expText, 90);
        $expY = 500;
        foreach ($expLines as $el) {
            if ($expY > 790) {
                break;
            }
            $p2[] = pdfTextCommand($el, 52, $yt($expY, 9), 9);
            $expY += 12;
        }
    }

    $p2[] = pdfMedsafeFooter(2, $totalPages, $pageW, $pageH, $pR, $pG, $pB);

    // ============================================================
    // PAGE 3: Section 2 - Product Details
    // ============================================================
    $p3 = [];

    $p3[] = pdfFillColor($pR, $pG, $pB);
    $p3[] = pdfBoldTextCommand('Section 2: Product Details', $margin, $yt(17.7, 18), 18);
    $p3[] = pdfFillColor(0, 0, 0);

    // Question 2.1
    $p3[] = pdfTextCommand('2.1. This Application is being made to prescribe/supply/administer the following product(s):', $bodyMargin, $yt(68.3, 10), 10);

    // Product table - outer border
    $tableTop = 135.0;
    $tableBot = 437.66;
    $tableX = 45.0;
    $tableR = 567.83;
    $tableW_total = $tableR - $tableX;
    $tableH = $tableBot - $tableTop;
    $p3[] = pdfStrokeColor(0, 0, 0);
    $p3[] = '0.50 w';
    $p3[] = pdfRectCommand($tableX, $pageH - $tableBot, $tableW_total, $tableH, false);

    // Column positions
    $colX = [45.0, 224.97, 339.26, 453.55, 567.83];
    $colW = [];
    for ($c = 0; $c < 4; $c++) {
        $colW[] = $colX[$c + 1] - $colX[$c];
    }

    // Header row
    $hdrRowBot = 163.37;
    $p3[] = pdfRectCommand($tableX, $pageH - $hdrRowBot, $colX[1] - $tableX, $hdrRowBot - $tableTop, false);

    // Column headers
    $p3[] = pdfBoldTextCommand('Product', 46.4, $yt(141.5, 10), 10);
    $p3[] = pdfBoldTextCommand('Component', 254.3, $yt(141.5, 10), 10);
    $p3[] = pdfBoldTextCommand('Strength', 375.8, $yt(141.5, 10), 10);
    $p3[] = pdfBoldTextCommand('Form', 498.2, $yt(141.5, 10), 10);

    // Data rows
    $rowTops = [163.37, 218.23, 273.08, 327.94, 382.80];
    $rowBots = [218.23, 273.08, 327.94, 382.80, 437.66];
    $maxRows = count($rowTops);

    for ($r = 0; $r < $maxRows; $r++) {
        $rTop = $rowTops[$r];
        $rBot = $rowBots[$r];
        $rH = $rBot - $rTop;
        for ($c = 0; $c < 4; $c++) {
            $p3[] = pdfRectCommand($colX[$c], $pageH - $rBot, $colW[$c], $rH, false);
        }
        if (isset($products[$r])) {
            $row = $products[$r];
            $cellTextY = $rTop + 12;
            $p3[] = pdfTextCommand((string)($row['name'] ?? ''), $colX[0] + 4, $yt($cellTextY, 9), 9);
            $p3[] = pdfTextCommand((string)($row['component'] ?? ''), $colX[1] + 4, $yt($cellTextY, 9), 9);
            $p3[] = pdfTextCommand((string)($row['strength'] ?? ''), $colX[2] + 4, $yt($cellTextY, 9), 9);
            $p3[] = pdfTextCommand((string)($row['form'] ?? ''), $colX[3] + 4, $yt($cellTextY, 9), 9);
        }
    }

    // Question 2.2 - Sourcing
    $p3[] = pdfTextCommand('2.2. Describe where the above product(s) are intended to be sourced from:', $bodyMargin, $yt(469.5, 10), 10);

    // Sourcing text box
    $p3[] = pdfRectCommand(47.83, $pageH - 798.17, 573.17 - 47.83, 798.17 - 488.84, false);
    $sourcingNotes = (string)($form['sourcing_notes'] ?? '');
    if ($sourcingNotes !== '') {
        $srcLines = pdfWordWrap($sourcingNotes, 90);
        $srcY = 500;
        foreach ($srcLines as $sl) {
            if ($srcY > 790) {
                break;
            }
            $cmds = pdfRichTextLine($sl, 52, $yt($srcY, 9), 9);
            foreach ($cmds as $cmd) {
                $p3[] = $cmd;
            }
            $srcY += 12;
        }
    }

    $p3[] = pdfMedsafeFooter(3, $totalPages, $pageW, $pageH, $pR, $pG, $pB);

    // ============================================================
    // PAGE 4: Section 3 - Treatment Protocol
    // ============================================================
    $p4 = [];

    $p4[] = pdfFillColor($pR, $pG, $pB);
    $p4[] = pdfBoldTextCommand('Section 3: Treatment Protocol', $margin, $yt(17.9, 18), 18);
    $p4[] = pdfFillColor(0, 0, 0);

    // 3.1 Indication
    $p4[] = pdfTextCommand('3.1. What is the indication the product(s) are proposed to be used for?', $bodyMargin, $yt(64.5, 10), 10);
    $p4[] = pdfRectCommand(47.83, $pageH - 177.17, 573.17 - 47.83, 177.17 - 83.84, false);
    if ($indication !== '') {
        $indLines = pdfWordWrap($indication, 90);
        $indY = 95;
        foreach ($indLines as $il) {
            $p4[] = pdfTextCommand($il, 52, $yt($indY, 9), 9);
            $indY += 12;
        }
    }

    // 3.2 Supporting evidence
    $p4[] = pdfTextCommand('3.2. Provide supporting evidence/information to support use of the product(s) for the intended indication.', $bodyMargin, $yt(199.5, 10), 10);
    $p4[] = pdfStrokeColor(0, 0, 0);
    $p4[] = '0.50 w';
    $p4[] = pdfRectCommand(47.83, $pageH - 310.17, 573.17 - 47.83, 310.17 - 218.84, false);
    $evText = (string)($form['supporting_evidence_notes'] ?? '');
    if ($evText !== '') {
        $evLines = pdfWordWrap($evText, 90);
        $evY = 230;
        foreach ($evLines as $el) {
            if ($evY > 302) {
                break;
            }
            $cmds = pdfRichTextLine($el, 52, $yt($evY, 9), 9);
            foreach ($cmds as $cmd) {
                $p4[] = $cmd;
            }
            $urlAnnots = pdfDetectUrlAnnotations($el, 52, $yt($evY, 9), 9, $pageH);
            foreach ($urlAnnots as $ua) {
                $pageAnnotations[3][] = $ua;
            }
            $evY += 12;
        }
    }

    // 3.3 Treatment protocol
    $p4[] = pdfTextCommand('3.3. Provide a copy of the current treatment protocol.', $bodyMargin, $yt(332.5, 10), 10);
    $p4[] = pdfRectCommand(47.83, $pageH - 443.17, 573.17 - 47.83, 443.17 - 351.84, false);
    $protText = (string)($form['treatment_protocol_notes'] ?? '');
    if ($protText !== '') {
        $protLines = pdfWordWrap($protText, 90);
        $protY = 363;
        foreach ($protLines as $pl) {
            if ($protY > 435) {
                break;
            }
            $cmds = pdfRichTextLine($pl, 52, $yt($protY, 9), 9);
            foreach ($cmds as $cmd) {
                $p4[] = $cmd;
            }
            $urlAnnots = pdfDetectUrlAnnotations($pl, 52, $yt($protY, 9), 9, $pageH);
            foreach ($urlAnnots as $ua) {
                $pageAnnotations[3][] = $ua;
            }
            $protY += 12;
        }
    }

    // 3.4 Administering location
    $p4[] = pdfTextCommand('3.4. Describe where you will be administering and monitoring the treatment:', $bodyMargin, $yt(465.5, 10), 10);
    $p4[] = pdfRectCommand(47.83, $pageH - 798.17, 573.17 - 47.83, 798.17 - 484.84, false);
    $adminText = (string)($form['admin_monitoring_notes'] ?? '');
    if ($adminText !== '') {
        $adminLines = pdfWordWrap($adminText, 90);
        $adminY = 496;
        foreach ($adminLines as $al) {
            if ($adminY > 790) {
                break;
            }
            $cmds = pdfRichTextLine($al, 52, $yt($adminY, 9), 9);
            foreach ($cmds as $cmd) {
                $p4[] = $cmd;
            }
            $urlAnnots = pdfDetectUrlAnnotations($al, 52, $yt($adminY, 9), 9, $pageH);
            foreach ($urlAnnots as $ua) {
                $pageAnnotations[3][] = $ua;
            }
            $adminY += 12;
        }
    }

    $p4[] = pdfMedsafeFooter(4, $totalPages, $pageW, $pageH, $pR, $pG, $pB);

    // ============================================================
    // PAGE 5: Section 4 - Scientific Peer Review
    // ============================================================
    $p5 = [];

    $p5[] = pdfFillColor($pR, $pG, $pB);
    $p5[] = pdfBoldTextCommand('Section 4: Scientific Peer Review', $margin, $yt(17.7, 18), 18);
    $p5[] = pdfFillColor(0, 0, 0);

    // 4.1 Question
    $p5[] = pdfTextCommand('4.1. Describe the scientific peer review activities that are implemented/proposed, and details of any support networks:', $bodyMargin, $yt(64.5, 10), 10);

    // Large text box
    $p5[] = pdfRectCommand(47.83, $pageH - 726.17, 573.17 - 47.83, 726.17 - 119.84, false);
    $peerText = (string)($form['scientific_peer_review_notes'] ?? '');
    if ($peerText !== '') {
        $prLines = pdfWordWrap($peerText, 90);
        $prY = 132;
        foreach ($prLines as $pl) {
            if ($prY > 718) {
                break;
            }
            $cmds = pdfRichTextLine($pl, 52, $yt($prY, 9), 9);
            foreach ($cmds as $cmd) {
                $p5[] = $cmd;
            }
            $urlAnnots = pdfDetectUrlAnnotations($pl, 52, $yt($prY, 9), 9, $pageH);
            foreach ($urlAnnots as $ua) {
                $pageAnnotations[4][] = $ua;
            }
            $prY += 12;
        }
    }

    $p5[] = pdfMedsafeFooter(5, $totalPages, $pageW, $pageH, $pR, $pG, $pB);

    // ============================================================
    // PAGE 6: Section 5 - Declaration & Signature
    // ============================================================
    $p6 = [];

    $p6[] = pdfFillColor($pR, $pG, $pB);
    $p6[] = pdfBoldTextCommand('Section 5: Declaration', $margin, $yt(17.8, 18), 18);
    $p6[] = pdfFillColor(0, 0, 0);

    // Declaration border box (purple outline)
    $p6[] = pdfStrokeColor($pR, $pG, $pB);
    $p6[] = '0.72 w';
    $p6[] = pdfRectCommand(36.0, $pageH - 315.0, 540.0, 315.0 - 81.0, false);
    $p6[] = pdfStrokeColor(0, 0, 0);
    $p6[] = '0.50 w';

    // 5.1 heading
    $p6[] = pdfFillColor($pR, $pG, $pB);
    $p6[] = pdfBoldTextCommand('5.1. Applicant declaration', $margin, $yt(55.3, 10), 10);
    $p6[] = pdfFillColor(0, 0, 0);

    // Declaration text
    $p6[] = pdfTextCommand('I confirm that I:', 46.4, $yt(88.9, 10), 10);
    $p6[] = pdfTextCommand('1. Solemnly and sincerely declare that the statements made in this Application are true and correct; and', 82.4, $yt(100.9, 10), 10);
    $p6[] = pdfTextCommand('2. Agree to provide any further information as required by Medsafe to assess the application.', 82.4, $yt(112.9, 10), 10);

    // Date field
    $p6[] = pdfTextCommand('Date:', 47.8, $yt(150.5, 10), 10);
    $dateVal = (string)($form['date'] ?? '');
    $p6[] = pdfRectCommand(81.38, $pageH - 150.5 - 15.84, 231.17 - 81.38, 19.84, false);
    if ($dateVal !== '') {
        $p6[] = pdfTextCommand($dateVal, 85.38, $yt(153, 10), 10);
    }

    // Signature method labels
    $p6[] = pdfBoldTextCommand('Digital Signature', 55.4, $yt(223.8, 10), 10);
    $p6[] = pdfFillColor(0, 0, 0);
    $p6[] = pdfBoldTextCommand('OR', 213.0, $yt(257.8, 10), 10);

    $p6[] = pdfBoldTextCommand('Signature Image File', 235.4, $yt(223.8, 10), 10);
    $p6[] = pdfBoldTextCommand('OR', 393.0, $yt(257.8, 10), 10);

    $p6[] = pdfBoldTextCommand('Signature', 415.4, $yt(223.8, 10), 10);

    // Signature boxes
    $sigDrawn = (string)($form['signature_drawn'] ?? '');
    $sigBoxY = $pageH - 306.0;  // PDF coords for bottom of signature box
    $sigBoxH = 54.0;

    // Digital signature box (left) - navy blue per reference
    $p6[] = pdfStrokeColor(0, 0, 0.502);
    $p6[] = pdfRectCommand(54.0, $sigBoxY, 153.0, $sigBoxH, false);

    // Signature image file box (middle) - navy blue per reference
    $p6[] = pdfRectCommand(234.0, $sigBoxY, 153.0, $sigBoxH, false);

    // Signature box (right) - black per reference
    $p6[] = pdfStrokeColor(0, 0, 0);
    $p6[] = pdfRectCommand(414.0, $sigBoxY, 153.0, $sigBoxH, false);

    $sigUploadPath = (string)($form['signature_upload'] ?? '');
    if ($sigDrawn !== '') {
        $p6[] = pdfFillColor(0, 0, 0);
        $p6[] = pdfTextCommand('[Signature provided]', 420, $sigBoxY + 20, 9);
    } elseif ($sigUploadPath !== '') {
        $p6[] = pdfFillColor(0, 0, 0);
        $p6[] = pdfTextCommand('[Signature provided]', 240, $sigBoxY + 20, 9);
    }

    $p6[] = pdfFillColor(0, 0, 0);
    $p6[] = pdfMedsafeFooter(6, $totalPages, $pageW, $pageH, $pR, $pG, $pB);

    // Build streams
    $streams = [];
    $streams[] = buildPdfPageStream([], $p1);
    $streams[] = buildPdfPageStream([], $p2);
    $streams[] = buildPdfPageStream([], $p3);
    $streams[] = buildPdfPageStream([], $p4);
    $streams[] = buildPdfPageStream([], $p5);
    $streams[] = buildPdfPageStream([], $p6);

    return writeMultiPagePdf($path, $streams, $sigDrawn, $sigBoxY, $sigUploadPath, $logoPath, $pageAnnotations);
}

function buildSubmissionProductRows(array $submission): array
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
    foreach (getProducts() as $p) {
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

function writeMultiPagePdf(string $path, array $streams, string $signatureDrawn = '', float $sigBoxY = PDF_DEFAULT_SIGNATURE_Y, string $signatureUploadPath = '', string $logoPath = '', array $pageAnnotations = []): bool
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
        $logoImage = buildJpegObjectFromFile($logoPath);
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
    $signatureImage = buildSignatureJpegObjectFromDataUrl($signatureDrawn);
    $sigIsUploaded = false;
    if (!$signatureImage && $signatureUploadPath !== '') {
        $sigFile = SUBMISSIONS_DIR . '/' . $signatureUploadPath;
        if (file_exists($sigFile)) {
            $signatureImage = buildJpegObjectFromFile($sigFile);
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
            $objects[$annotId] = '<< /Type /Annot /Subtype /Link /Rect [' . $x1 . ' ' . $y1 . ' ' . $x2 . ' ' . $y2 . '] /Border [0 0 0] /A << /S /URI /URI (' . pdfEscape($url) . ') >> >>';
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

    $pdf = buildPdfFromObjects($objects, 1);
    return file_put_contents($path, $pdf) !== false;
}

function buildSignatureJpegObjectFromDataUrl(string $dataUrl): ?array
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

function buildJpegObjectFromFile(string $filePath): ?array
{
    $realPath = realpath($filePath);
    if ($realPath === false || !file_exists($realPath)) {
        return null;
    }

    // Only allow files within the application directory
    $appDir = realpath(__DIR__);
    if ($appDir === false || strpos($realPath, $appDir) !== 0) {
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

function buildPdfPageStream(array $lines, array $extraCommands): string
{
    $commands = [];
    foreach ($lines as $line) {
        $commands[] = pdfTextCommand((string)$line[0], (float)$line[1], (float)$line[2], (float)$line[3]);
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
function pdfYFromTop(float $topFromTop, float $fontSize, float $pageHeight = 841.89): float
{
    return $pageHeight - $topFromTop - $fontSize * 1.07;
}

function pdfTextCommand(string $text, float $x, float $y, float $size = 10): string
{
    return 'BT /F1 ' . number_format($size, 2, '.', '') . ' Tf 1 0 0 1 ' . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' Tm (' . pdfEscape($text) . ') Tj ET';
}

function pdfRectCommand(float $x, float $y, float $w, float $h, bool $fill = false): string
{
    $op = $fill ? 'b' : 'S';
    return number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' ' . number_format($w, 2, '.', '') . ' ' . number_format($h, 2, '.', '') . ' re ' . $op;
}

function pdfEscape(string $text): string
{
    // Convert UTF-8 bullet (U+2022) to WinAnsiEncoding bullet (0x95)
    $text = str_replace("\xE2\x80\xA2", "\x95", $text);
    $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';
    return str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $text);
}

function pdfBoldTextCommand(string $text, float $x, float $y, float $size = 10): string
{
    return 'BT /F2 ' . number_format($size, 2, '.', '') . ' Tf 1 0 0 1 ' . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' Tm (' . pdfEscape($text) . ') Tj ET';
}

function pdfCheckmarkCommand(float $x, float $y, float $size = 10): string
{
    // Character '4' in ZapfDingbats renders as a checkmark (✔)
    return 'BT /F3 ' . number_format($size, 2, '.', '') . ' Tf 1 0 0 1 ' . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' Tm (4) Tj ET';
}

function pdfFillColor(float $r, float $g, float $b): string
{
    return number_format($r, 3, '.', '') . ' ' . number_format($g, 3, '.', '') . ' ' . number_format($b, 3, '.', '') . ' rg';
}

function pdfStrokeColor(float $r, float $g, float $b): string
{
    return number_format($r, 3, '.', '') . ' ' . number_format($g, 3, '.', '') . ' ' . number_format($b, 3, '.', '') . ' RG';
}

function pdfFilledRect(float $x, float $y, float $w, float $h): string
{
    return number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' ' . number_format($w, 2, '.', '') . ' ' . number_format($h, 2, '.', '') . ' re f';
}

function pdfLineCommand(float $x1, float $y1, float $x2, float $y2): string
{
    return number_format($x1, 2, '.', '') . ' ' . number_format($y1, 2, '.', '') . ' m ' . number_format($x2, 2, '.', '') . ' ' . number_format($y2, 2, '.', '') . ' l S';
}

function pdfSectionHeader(string $title, float $x, float $y, float $width, float $r = MEDSAFE_PURPLE_R, float $g = MEDSAFE_PURPLE_G, float $b = MEDSAFE_PURPLE_B): string
{
    $commands = [];
    $commands[] = pdfFillColor(0.882, 0.882, 0.882);
    $commands[] = pdfFilledRect($x, $y - 6, $width, 20);
    $commands[] = pdfFillColor($r, $g, $b);
    $commands[] = pdfFilledRect($x, $y - 6, 3, 20);
    $commands[] = pdfBoldTextCommand($title, $x + 10, $y, 11);
    $commands[] = pdfFillColor(0, 0, 0);
    return implode("\n", $commands);
}

function pdfLabelValue(string $label, string $value, float $x, float $y): string
{
    $commands = [];
    $commands[] = pdfFillColor(0.3, 0.3, 0.3);
    $commands[] = pdfBoldTextCommand($label . ':', $x, $y, 9);
    $commands[] = pdfFillColor(0, 0, 0);
    $commands[] = pdfTextCommand($value, $x + PDF_LABEL_VALUE_OFFSET, $y, 9);
    return implode("\n", $commands);
}

function pdfPageFooter(int $pageNum, int $totalPages, float $pageWidth, float $r = MEDSAFE_PURPLE_R, float $g = MEDSAFE_PURPLE_G, float $b = MEDSAFE_PURPLE_B): string
{
    return pdfMedsafeFooter($pageNum, $totalPages, $pageWidth, 841.89, $r, $g, $b);
}

function pdfMedsafeFooter(int $pageNum, int $totalPages, float $pageWidth, float $pageHeight, float $r = MEDSAFE_PURPLE_R, float $g = MEDSAFE_PURPLE_G, float $b = MEDSAFE_PURPLE_B): string
{
    $commands = [];
    $footerText = 'Application Form: Approval to Prescribe/Supply/Administer (Form A3) version 1.0';
    $pageText = 'Page ' . $pageNum . ' of ' . $totalPages;
    // Reference: footer text at bbox_top=816.6 (8pt font)
    $footerY = pdfYFromTop(816.6, 8, $pageHeight);
    // Reference: page number at bbox_top=818.0 (8pt font)
    $pageNumY = pdfYFromTop(818.0, 8, $pageHeight);
    $commands[] = pdfFillColor($r, $g, $b);
    $commands[] = pdfTextCommand($footerText, 19.4, $footerY, 8);
    $commands[] = pdfTextCommand($pageText, $pageWidth - 60, $pageNumY, 8);
    $commands[] = pdfFillColor(0, 0, 0);
    return implode("\n", $commands);
}

function pdfWordWrap(string $text, int $maxChars): array
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
function pdfRichTextLine(string $line, float $x, float $y, float $size): array
{
    $commands = [];
    // Detect bullet-prefixed product lines: "• ProductName: rest of text"
    if (preg_match('/^(\x{2022}|\x{95}|•)\s*(.+?):\s*(.*)$/u', $line, $m)) {
        $boldPart = $m[1] . ' ' . $m[2] . ':';
        $plainPart = ' ' . $m[3];
        $commands[] = pdfBoldTextCommand($boldPart, $x, $y, $size);
        // Approximate bold part width: each character ~0.55 * size for Helvetica-Bold
        $boldWidth = mb_strlen($boldPart, 'UTF-8') * $size * 0.55;
        if (trim($m[3]) !== '') {
            $commands[] = pdfTextCommand(trim($plainPart), $x + $boldWidth, $y, $size);
        }
    } else {
        $commands[] = pdfTextCommand($line, $x, $y, $size);
    }
    return $commands;
}

/**
 * Detect URLs in a text line and return annotation rects for PDF link annotations.
 * Returns array of [url, linkX, linkY, linkW, linkH].
 */
function pdfDetectUrlAnnotations(string $line, float $x, float $y, float $size, float $pageHeight): array
{
    $annotations = [];
    $charWidth = $size * 0.55;
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

function buildPdfFromObjects(array $objects, int $rootObj): string
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

function findSubmission(string $id): ?array
{
    $file = SUBMISSIONS_DIR . '/' . $id . '.json';
    if (!file_exists($file)) {
        return null;
    }
    return json_decode(file_get_contents($file), true);
}

function downloadPdf(string $id): void
{
    $submission = findSubmission($id);
    if (!$submission) {
        http_response_code(404);
        echo 'Submission not found';
        return;
    }

    $file = SUBMISSIONS_DIR . '/' . $submission['pdf_file'];
    if (!file_exists($file)) {
        http_response_code(404);
        echo 'PDF not found';
        return;
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    readfile($file);
}

function downloadSupportDocument(string $submissionId, string $file): void
{
    $submission = findSubmission($submissionId);
    if (!$submission || ($submission['form']['peer_support_file'] ?? '') !== $file) {
        http_response_code(404);
        echo 'Support document not found';
        return;
    }

    $path = SUBMISSIONS_DIR . '/' . $file;
    if (!file_exists($path)) {
        http_response_code(404);
        echo 'Missing file';
        return;
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    readfile($path);
}

function emailPdfToDoctor(string $submissionId): array
{
    $submission = findSubmission($submissionId);
    if (!$submission) {
        return [false, null, 'Submission not found.'];
    }

    $pdfPath = SUBMISSIONS_DIR . '/' . $submission['pdf_file'];
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
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Prescription Form</title>
  <link rel="stylesheet" href="style.css" />
  <style><?php echo file_exists(__DIR__ . '/style.css') ? file_get_contents(__DIR__ . '/style.css') : ''; ?></style>
</head>
<body>
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
    <?php if (isWordPressRuntime()): ?>
      <section class="card">
        <h2>WooCommerce Configuration</h2>
        <p>Products are loaded from WooCommerce. Edit each product under <strong>WooCommerce → Products</strong> and fill prescription custom fields (component, strength, form, sourced from, indications, and the indication mapping textareas).</p>
        <p>Doctor CPN is managed under <strong>Users → Profile</strong> via the new CPN field.</p>
      </section>
    <?php else: ?>
      <section class="card">
        <h2>Product Management</h2>
        <form method="post" action="?page=admin&action=save_product" class="grid">
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
      <div class="steps"><span class="active">1</span><span>2</span><span>3</span></div>
      <div class="step-labels"><span>Applicant Details</span><span>Clinical Details</span><span>Sign & Submit</span></div>

      <section class="step active" data-step="1">
        <div class="step-header"><h2>Step 1: Disclaimer & Applicant Details</h2><p>Review declaration and confirm your practitioner profile data.</p></div>
        <div class="disclaimer">
          <p>This digital interface is provided by Allu Therapeutics as a specialised tool to facilitate the compilation and generation of a formal application to Medsafe under Regulation 22 of the Misuse of Drugs Regulations 1977. Use of this platform does not constitute medical or regulatory advice. The Prescribing Doctor, as the Applicant, remains the primary Health Agency under the Health Information Privacy Code 2020 and bears sole legal and clinical responsibility for the accuracy of the protocol, the selection of patients, and the provision of unapproved controlled drugs. Allu Therapeutics acts as a secure data processor; all private clinical data is encrypted and held in strict confidence, accessible only to the authorised prescriber to support their professional obligations and mandatory safety reporting to the Ministry of Health. By utilising this facilitation tool, the prescriber acknowledges that Medsafe’s Ministerial approval is subject to their own clinical expertise, independent scientific peer review, and adherence to the applicable professional standards.</p>
        </div>
        <p style="font-size:12px;color:var(--muted);margin:6px 0 12px;">
          These details are fetched from your profile.
          <?php if (isWordPressRuntime()): ?>
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
</body>
</html>
