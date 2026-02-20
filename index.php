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
    echo '<h2>Prescription Form Fields</h2>';
    echo '<table class="form-table"><tr>';
    echo '<th><label for="cpn">CPN</label></th>';
    echo '<td><input type="text" name="cpn" id="cpn" value="' . esc_attr($cpn) . '" class="regular-text" /></td>';
    echo '</tr></table>';
}

function saveCpnUserField(int $userId): void
{
    if (!function_exists('current_user_can') || !current_user_can('edit_user', $userId)) {
        return;
    }
    if (isset($_POST['cpn']) && function_exists('update_user_meta')) {
        update_user_meta($userId, 'cpn', sanitize_text_field($_POST['cpn']));
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
            $name = trim((string)($user->display_name ?? ''));
            if ($name === '') {
                $name = trim(((string)($user->user_firstname ?? '')) . ' ' . ((string)($user->user_lastname ?? '')));
            }

            return [
                'id' => (int)$user->ID,
                'name' => $name,
                'email' => (string)($user->user_email ?? ''),
                'phone' => function_exists('get_user_meta') ? (string)get_user_meta($user->ID, 'billing_phone', true) : '',
                'cpn' => function_exists('get_user_meta') ? (string)get_user_meta($user->ID, 'cpn', true) : '',
            ];
        }
    }

    return [
        'id' => 0,
        'name' => '',
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

    if (trim((string)($post['vocational_scope'] ?? '')) === '') {
        return [false, null, 'Vocational Scope is required.', null];
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

    $indication = trim($post['indication'] ?? '');
    $indicationOther = trim($post['indication_other'] ?? '');
    if ($indication === '') {
        return [false, null, 'Please select an indication.', null];
    }
    if ($indication === 'Other' && $indicationOther === '') {
        return [false, null, 'Please enter a custom indication.', null];
    }

    saveDoctorPrefs((int)$doctor['id'], $post);

    $selectedDetails = buildSelectedProductDetails($selectedProducts);
    $productNames = array_map(fn($p) => $p['name'], $selectedDetails);

    $id = 'sub-' . date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 6);
    $submission = [
        'id' => $id,
        'submitted_at' => date('c'),
        'doctor' => $doctor,
        'form' => [
            'vocational_scope' => trim($post['vocational_scope'] ?? ''),
            'clinical_experience' => trim($post['clinical_experience'] ?? ''),
            'products' => $selectedProducts,
            'product_names' => $productNames,
            'product_details' => $selectedDetails,
            'indication' => $indication,
            'indication_other' => $indicationOther,
            'sourcing_notes' => trim($post['sourcing_notes'] ?? ''),
            'supporting_evidence_notes' => trim($post['supporting_evidence_notes'] ?? ''),
            'treatment_protocol_notes' => trim($post['treatment_protocol_notes'] ?? ''),
            'scientific_peer_review_notes' => trim($post['scientific_peer_review_notes'] ?? ''),
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

    $margin = 50;
    $pageW = 595;
    $contentW = $pageW - $margin * 2;

    // -- Page 1: Title + Applicant Details --
    $p1 = [];
    $p1[] = pdfFillColor(0.16, 0.24, 0.44);
    $p1[] = pdfFilledRect(0, 792, $pageW, 50);
    $p1[] = pdfFillColor(1, 1, 1);
    $p1[] = pdfBoldTextCommand('PRESCRIPTION APPLICATION', $margin, 810, 16);
    $p1[] = pdfTextCommand('Submission Summary', $margin, 796, 10);
    $p1[] = pdfFillColor(0, 0, 0);

    $p1[] = pdfFillColor(0.3, 0.3, 0.3);
    $p1[] = pdfTextCommand('Submission ID: ' . (string)($submission['id'] ?? ''), $margin, 770, 8);
    $p1[] = pdfTextCommand('Date Submitted: ' . (string)($submission['submitted_at'] ?? ''), $margin, 758, 8);
    $p1[] = pdfFillColor(0, 0, 0);

    $p1[] = pdfSectionHeader('Applicant Details', $margin, 730, $contentW);

    $fields1 = [
        ['Name', (string)($doctor['name'] ?? '')],
        ['Email', (string)($doctor['email'] ?? '')],
        ['Phone', (string)($doctor['phone'] ?? '')],
        ['CPN', (string)($doctor['cpn'] ?? '')],
        ['Vocational Scope', (string)($form['vocational_scope'] ?? '')],
        ['Selected Indication', $indication],
        ['Application Date', (string)($form['date'] ?? '')],
    ];
    $fieldY = 708;
    foreach ($fields1 as $f) {
        $p1[] = pdfLabelValue($f[0], $f[1], $margin, $fieldY);
        $fieldY -= 20;
    }

    $fieldY -= 6;
    $p1[] = pdfFillColor(0.16, 0.24, 0.44);
    $p1[] = pdfBoldTextCommand('Clinical Experience & Training', $margin, $fieldY, 10);
    $p1[] = pdfFillColor(0, 0, 0);
    $fieldY -= 16;
    $expText = (string)($form['clinical_experience'] ?? '');
    $expLines = pdfWordWrap($expText, 80);
    foreach ($expLines as $line) {
        $p1[] = pdfTextCommand($line, $margin + 4, $fieldY, 9);
        $fieldY -= 14;
    }

    $p1[] = pdfPageFooter(1, 3, $pageW);

    // -- Page 2: Product Details --
    $p2 = [];
    $p2[] = pdfFillColor(0.16, 0.24, 0.44);
    $p2[] = pdfFilledRect(0, 792, $pageW, 50);
    $p2[] = pdfFillColor(1, 1, 1);
    $p2[] = pdfBoldTextCommand('PRESCRIPTION APPLICATION', $margin, 810, 16);
    $p2[] = pdfTextCommand('Product Details', $margin, 796, 10);
    $p2[] = pdfFillColor(0, 0, 0);

    $p2[] = pdfSectionHeader('Selected Products', $margin, 762, $contentW);

    $tableX = $margin;
    $tableY = 740;
    $tableW = [190, 130, 85, 90];
    $headerH = 22;
    $rowH = 22;
    $maxRows = 16;

    $headers = ['Product', 'Component', 'Strength', 'Form'];
    $p2[] = pdfFillColor(0.16, 0.24, 0.44);
    $x = $tableX;
    $totalTableW = array_sum($tableW);
    $p2[] = pdfFilledRect($tableX, $tableY - $headerH, $totalTableW, $headerH);
    $p2[] = pdfFillColor(1, 1, 1);
    for ($i = 0; $i < count($tableW); $i++) {
        $p2[] = pdfBoldTextCommand($headers[$i], $x + 6, $tableY - 15, 9);
        $x += $tableW[$i];
    }
    $p2[] = pdfFillColor(0, 0, 0);

    $rowsToRender = array_slice($products, 0, $maxRows);
    if (empty($rowsToRender)) {
        $rowsToRender[] = ['name' => '-', 'component' => '-', 'strength' => '-', 'form' => '-'];
    }

    $y = $tableY - $headerH;
    $rowIdx = 0;
    foreach ($rowsToRender as $row) {
        $y -= $rowH;
        if ($rowIdx % 2 === 0) {
            $p2[] = pdfFillColor(0.94, 0.95, 0.97);
            $p2[] = pdfFilledRect($tableX, $y, $totalTableW, $rowH);
            $p2[] = pdfFillColor(0, 0, 0);
        }
        $p2[] = pdfStrokeColor(0.8, 0.8, 0.8);
        $p2[] = pdfLineCommand($tableX, $y, $tableX + $totalTableW, $y);
        $p2[] = pdfStrokeColor(0, 0, 0);

        $x = $tableX;
        $cells = [
            (string)($row['name'] ?? ''),
            (string)($row['component'] ?? ''),
            (string)($row['strength'] ?? ''),
            (string)($row['form'] ?? ''),
        ];
        for ($i = 0; $i < count($tableW); $i++) {
            $p2[] = pdfTextCommand($cells[$i], $x + 6, $y + 7, 9);
            $x += $tableW[$i];
        }
        $rowIdx++;
    }
    $p2[] = pdfStrokeColor(0.8, 0.8, 0.8);
    $p2[] = pdfLineCommand($tableX, $y, $tableX + $totalTableW, $y);
    $p2[] = pdfStrokeColor(0, 0, 0);

    if (count($products) > $maxRows) {
        $p2[] = pdfFillColor(0.4, 0.4, 0.4);
        $p2[] = pdfTextCommand('+ ' . (count($products) - $maxRows) . ' additional product(s) not shown', $tableX, $y - 16, 8);
        $p2[] = pdfFillColor(0, 0, 0);
    }

    $notesY = $y - 40;
    $sourcingNotes = (string)($form['sourcing_notes'] ?? '');
    if ($sourcingNotes !== '') {
        $p2[] = pdfSectionHeader('Sourcing Notes', $margin, $notesY, $contentW);
        $notesY -= 20;
        $noteLines = pdfWordWrap($sourcingNotes, 80);
        foreach ($noteLines as $line) {
            $p2[] = pdfTextCommand($line, $margin + 4, $notesY, 9);
            $notesY -= 14;
        }
    }

    $p2[] = pdfPageFooter(2, 3, $pageW);

    // -- Page 3: Clinical Documentation & Signature --
    $p3 = [];
    $p3[] = pdfFillColor(0.16, 0.24, 0.44);
    $p3[] = pdfFilledRect(0, 792, $pageW, 50);
    $p3[] = pdfFillColor(1, 1, 1);
    $p3[] = pdfBoldTextCommand('PRESCRIPTION APPLICATION', $margin, 810, 16);
    $p3[] = pdfTextCommand('Clinical Documentation & Signature', $margin, 796, 10);
    $p3[] = pdfFillColor(0, 0, 0);

    $p3[] = pdfSectionHeader('Clinical Documentation', $margin, 762, $contentW);

    $docFields = [
        ['Supporting Evidence', (string)($form['supporting_evidence_notes'] ?? '')],
        ['Treatment Protocol', (string)($form['treatment_protocol_notes'] ?? '')],
        ['Scientific Peer Review', (string)($form['scientific_peer_review_notes'] ?? '')],
    ];
    $docY = 740;
    foreach ($docFields as $df) {
        $p3[] = pdfFillColor(0.16, 0.24, 0.44);
        $p3[] = pdfBoldTextCommand($df[0], $margin, $docY, 10);
        $p3[] = pdfFillColor(0, 0, 0);
        $docY -= 16;
        $wrappedLines = pdfWordWrap($df[1], 80);
        foreach ($wrappedLines as $wl) {
            $p3[] = pdfTextCommand($wl, $margin + 4, $docY, 9);
            $docY -= 14;
        }
        $docY -= 6;
    }

    $docY -= 10;
    $p3[] = pdfSectionHeader('Signature', $margin, $docY, $contentW);
    $docY -= 22;
    $sigMode = (string)($form['signature_mode'] ?? '');
    $sigDrawn = (string)($form['signature_drawn'] ?? '');
    $sigUpload = (string)($form['signature_upload'] ?? 'Not uploaded');
    $p3[] = pdfLabelValue('Signature Method', $sigMode !== '' ? ucfirst($sigMode) : 'Not specified', $margin, $docY);
    $docY -= 20;
    $p3[] = pdfLabelValue('Signature Drawn', $sigDrawn !== '' ? 'Provided' : 'Not provided', $margin, $docY);
    $docY -= 20;
    $p3[] = pdfLabelValue('Signature Upload', $sigUpload, $margin, $docY);
    $docY -= 30;

    // Signature image placeholder box
    $sigBoxY = $docY - 70;
    $p3[] = pdfStrokeColor(0.7, 0.7, 0.7);
    $p3[] = '1.00 w';
    $p3[] = pdfRectCommand($margin, $sigBoxY, 220, 70, false);
    $p3[] = '0.50 w';
    $p3[] = pdfStrokeColor(0, 0, 0);
    if ($sigDrawn === '') {
        $p3[] = pdfFillColor(0.6, 0.6, 0.6);
        $p3[] = pdfTextCommand('Signature area', $margin + 60, $sigBoxY + 30, 9);
        $p3[] = pdfFillColor(0, 0, 0);
    }

    $declY = $sigBoxY - 30;
    $p3[] = pdfFillColor(0.95, 0.95, 0.95);
    $p3[] = pdfFilledRect($margin, $declY - 10, $contentW, 28);
    $p3[] = pdfFillColor(0.2, 0.2, 0.2);
    $p3[] = pdfTextCommand('Declaration: All submitted fields have been included in this PDF export.', $margin + 8, $declY, 9);
    $p3[] = pdfFillColor(0, 0, 0);

    $p3[] = pdfPageFooter(3, 3, $pageW);

    $stream1 = buildPdfPageStream([], $p1);
    $stream2 = buildPdfPageStream([], $p2);
    $stream3 = buildPdfPageStream([], $p3);

    return writeSimpleThreePagePdf($path, $stream1, $stream2, $stream3, (string)($form['signature_drawn'] ?? ''), $sigBoxY);
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

function writeSimpleThreePagePdf(string $path, string $stream1, string $stream2, string $stream3, string $signatureDrawn = '', float $sigBoxY = 548): bool
{
    $fontRes = '<< /F1 6 0 R /F2 10 0 R >>';
    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids [3 0 R 4 0 R 5 0 R] /Count 3 >>';
    $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font ' . $fontRes . ' >> /Contents 7 0 R >>';
    $objects[4] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font ' . $fontRes . ' >> /Contents 8 0 R >>';
    $objects[5] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font ' . $fontRes . ' >> /Contents 9 0 R >>';
    $objects[6] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[10] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

    $signatureImage = buildSignatureJpegObjectFromDataUrl($signatureDrawn);
    if ($signatureImage) {
        $imageObjNum = 11;
        $objects[$imageObjNum] = $signatureImage['object'];
        $stream3 .= "\nq\n220 0 0 70 50 " . number_format($sigBoxY, 2, '.', '') . " cm\n/SigIm Do\nQ\n";
        $objects[5] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font ' . $fontRes . ' /XObject << /SigIm ' . $imageObjNum . ' 0 R >> >> /Contents 9 0 R >>';
    }

    $objects[7] = "<< /Length " . strlen($stream1) . " >>\nstream\n" . $stream1 . "\nendstream";
    $objects[8] = "<< /Length " . strlen($stream2) . " >>\nstream\n" . $stream2 . "\nendstream";
    $objects[9] = "<< /Length " . strlen($stream3) . " >>\nstream\n" . $stream3 . "\nendstream";

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
    $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';
    return str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $text);
}

function pdfBoldTextCommand(string $text, float $x, float $y, float $size = 10): string
{
    return 'BT /F2 ' . number_format($size, 2, '.', '') . ' Tf 1 0 0 1 ' . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' Tm (' . pdfEscape($text) . ') Tj ET';
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

function pdfSectionHeader(string $title, float $x, float $y, float $width): string
{
    $commands = [];
    $commands[] = pdfFillColor(0.92, 0.94, 0.97);
    $commands[] = pdfFilledRect($x, $y - 6, $width, 20);
    $commands[] = pdfFillColor(0.16, 0.24, 0.44);
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
    $commands[] = pdfTextCommand($value, $x + 140, $y, 9);
    return implode("\n", $commands);
}

function pdfPageFooter(int $pageNum, int $totalPages, float $pageWidth): string
{
    $commands = [];
    $commands[] = pdfStrokeColor(0.8, 0.8, 0.8);
    $commands[] = pdfLineCommand(50, 40, $pageWidth - 50, 40);
    $commands[] = pdfStrokeColor(0, 0, 0);
    $commands[] = pdfFillColor(0.5, 0.5, 0.5);
    $commands[] = pdfTextCommand('Page ' . $pageNum . ' of ' . $totalPages, $pageWidth / 2 - 20, 26, 8);
    $commands[] = pdfTextCommand('Prescription Application', 50, 26, 8);
    $commands[] = pdfFillColor(0, 0, 0);
    return implode("\n", $commands);
}

function pdfWordWrap(string $text, int $maxChars): array
{
    $text = trim($text);
    if ($text === '') {
        return ['N/A'];
    }
    $words = explode(' ', $text);
    $lines = [];
    $current = '';
    foreach ($words as $word) {
        if ($current === '') {
            $current = $word;
        } elseif (strlen($current) + 1 + strlen($word) <= $maxChars) {
            $current .= ' ' . $word;
        } else {
            $lines[] = $current;
            $current = $word;
        }
    }
    if ($current !== '') {
        $lines[] = $current;
    }
    return $lines;
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
        <div class="grid two">
          <label>Name<input value="<?= htmlspecialchars($doctor['name']) ?>" readonly /></label>
          <label>Email<input value="<?= htmlspecialchars($doctor['email']) ?>" readonly /></label>
          <label>Phone<input value="<?= htmlspecialchars($doctor['phone']) ?>" readonly /></label>
          <label>CPN<input value="<?= htmlspecialchars($doctor['cpn']) ?>" readonly /></label>
          <label>Vocational Scope
            <textarea name="vocational_scope" required><?= htmlspecialchars($doctorPrefs['vocational_scope']) ?></textarea>
          </label>
          <label>Clinical Experience & Training
            <textarea name="clinical_experience" required><?= htmlspecialchars($doctorPrefs['clinical_experience']) ?></textarea>
          </label>
        </div>
      </section>

      <section class="step" data-step="2" style="display:none;">
        <div class="step-header"><h2>Step 2: Product Details & Indication Mapping</h2><p>Select products and indication to auto-populate supporting sections.</p></div>

        <fieldset class="product-picker">
          <legend>Products</legend>
          <p class="hint">Tick one or more products. No Ctrl/Cmd key needed.</p>
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
            <textarea id="componentAuto" readonly></textarea>
          </label>
          <label>Strength
            <textarea id="strengthAuto" readonly></textarea>
          </label>
          <label>Form
            <textarea id="formAuto" readonly></textarea>
          </label>
          <label>Sourced from
            <textarea id="sourcingAuto" name="sourcing_notes"></textarea>
          </label>
        </div>

        <label>Indication
          <select id="indicationSelect" name="indication" required>
            <option value="">Select indication</option>
          </select>
        </label>
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
            <input type="file" name="signature_upload" accept="image/*" />
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

const decodeJsonData = (value, fallback = []) => {
  try { return JSON.parse(value || '[]'); } catch (e) { return fallback; }
};

function syncProductAuto() {
  if (!productWrap) return;
  const selected = [...document.querySelectorAll('.product-check:checked')];

  const components = selected.map(o => `• ${o.dataset.name}: ${o.dataset.component || '-'}`).join('\n');
  const strengths = selected.map(o => `• ${o.dataset.name}: ${o.dataset.strength || '-'}`).join('\n');
  const forms = selected.map(o => `• ${o.dataset.name}: ${o.dataset.form || '-'}`).join('\n');
  const sources = selected.map(o => `• ${o.dataset.name}: ${o.dataset.source || '-'}`).join('\n');

  document.getElementById('componentAuto').value = components;
  document.getElementById('strengthAuto').value = strengths;
  document.getElementById('formAuto').value = forms;
  document.getElementById('sourcingAuto').value = sources;

  const indicationSet = new Set();
  selected.forEach(o => decodeJsonData(o.dataset.indications, []).forEach(i => indicationSet.add(i)));

  if (indicationSelect) {
    const current = indicationSelect.value;
    indicationSelect.innerHTML = '<option value="">Select indication</option>';
    [...indicationSet].forEach(ind => {
      const opt = document.createElement('option');
      opt.value = ind;
      opt.textContent = ind;
      indicationSelect.appendChild(opt);
    });
    const otherOpt = document.createElement('option');
    otherOpt.value = 'Other';
    otherOpt.textContent = 'Other';
    indicationSelect.appendChild(otherOpt);
    if ([...indicationSelect.options].some(o => o.value === current)) indicationSelect.value = current;
  }

  syncIndicationAuto();
}

function syncIndicationAuto() {
  if (!productWrap || !indicationSelect) return;
  const selected = [...document.querySelectorAll('.product-check:checked')];
  const indication = indicationSelect.value;

  const supporting = [];
  const protocol = [];
  const peerReview = [];

  selected.forEach(o => {
    const map = decodeJsonData(o.dataset.indicationMap, {});
    const row = map[indication] || null;
    if (!row) return;
    supporting.push(`• ${o.dataset.name}: ${row.supporting_evidence || '-'}`);
    protocol.push(`• ${o.dataset.name}: ${row.treatment_protocol || '-'}`);
    peerReview.push(`• ${o.dataset.name}: ${row.scientific_peer_review || '-'}`);
  });

  document.getElementById('supportingEvidenceAuto').value = supporting.join('\n');
  document.getElementById('treatmentProtocolAuto').value = protocol.join('\n');
  document.getElementById('peerReviewAuto').value = peerReview.join('\n');

  if (indication === 'Other') {
    indicationOtherWrap.classList.remove('hidden');
  } else {
    indicationOtherWrap.classList.add('hidden');
    document.getElementById('indicationOtherInput').value = '';
  }
}

if (productWrap) {
  productWrap.addEventListener('change', syncProductAuto);
  syncProductAuto();
}
if (indicationSelect) {
  indicationSelect.addEventListener('change', syncIndicationAuto);
}
if (submitBtn) {
  submitBtn.addEventListener('click', (e) => {
    const errors = [];
    const selectedProducts = document.querySelectorAll('.product-check:checked').length;
    const vocational = document.querySelector('textarea[name="vocational_scope"]')?.value.trim();
    const experience = document.querySelector('textarea[name="clinical_experience"]')?.value.trim();
    const date = document.querySelector('input[name="application_date"]')?.value.trim();
    const indication = indicationSelect ? indicationSelect.value : '';
    const mode = document.querySelector('input[name="signature_mode"]:checked')?.value || '';
    const drawn = document.getElementById('signatureDrawn')?.value || '';
    const uploadFile = document.querySelector('input[name="signature_upload"]')?.files?.length || 0;

    if (!vocational) errors.push('Vocational Scope is required.');
    if (!experience) errors.push('Clinical Experience & Training is required.');
    if (!selectedProducts) errors.push('Please select at least one product.');
    if (!indication) errors.push('Please select an indication.');
    if (indication === 'Other' && !document.getElementById('indicationOtherInput')?.value.trim()) errors.push('Please enter a custom indication.');
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
</script>
</body>
</html>
