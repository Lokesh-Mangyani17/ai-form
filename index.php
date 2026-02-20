<?php
session_start();

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
        'id' => '_prescription_indication_map',
        'label' => 'Indication Mapping JSON',
        'desc_tip' => true,
        'description' => 'JSON by indication: {"Depression":{"supporting_evidence":"...","treatment_protocol":"...","scientific_peer_review":"..."}}',
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
    if (isWordPressRuntime() && function_exists('is_user_logged_in') && is_user_logged_in()) {
        $user = wp_get_current_user();
        return [
            'id' => (int)$user->ID,
            'name' => trim($user->display_name ?: $user->user_firstname . ' ' . $user->user_lastname),
            'email' => (string)$user->user_email,
            'phone' => (string)get_user_meta($user->ID, 'billing_phone', true),
            'cpn' => (string)get_user_meta($user->ID, 'cpn', true),
        ];
    }

    return [
        'id' => 1001,
        'name' => 'Dr Jane Smith',
        'email' => 'jane.smith@exampleclinic.nz',
        'phone' => '+64 21 555 1234',
        'cpn' => 'CPN-778899',
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
        'indication_map' => [],
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

    if (!empty($files['peer_support_doc']['name'])) {
        $safeName = uniqid('support_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $files['peer_support_doc']['name']);
        $target = SUBMISSIONS_DIR . '/' . $safeName;
        move_uploaded_file($files['peer_support_doc']['tmp_name'], $target);
        $submission['form']['peer_support_file'] = $safeName;
    }

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
    if (!file_exists(PDF_TEMPLATE_FILE)) {
        return [false, 'Medsafe PDF template file is missing. Please upload ApprovalToPrescribePsychedelics.pdf into data/.'];
    }

    $ok = fillTemplatePdfFields(PDF_TEMPLATE_FILE, $path, buildPdfFieldValueMap($submission));
    if ($ok) {
        return [true, null];
    }

    return [false, 'Unable to write form fields into the Medsafe PDF template. Please ensure template has writable AcroForm text fields.'];
}

function buildPdfFieldValueMap(array $submission): array
{
    $indication = trim(($submission['form']['indication'] ?? '') . ' ' . ($submission['form']['indication_other'] ?? ''));
    $products = implode(', ', $submission['form']['product_names'] ?? []);

    return [
        'name' => $submission['doctor']['name'] ?? '',
        'email' => $submission['doctor']['email'] ?? '',
        'phone' => $submission['doctor']['phone'] ?? '',
        'cpn' => $submission['doctor']['cpn'] ?? '',
        'vocational_scope' => $submission['form']['vocational_scope'] ?? '',
        'clinical_experience' => $submission['form']['clinical_experience'] ?? '',
        'products' => $products,
        'indication' => $indication,
        'source' => $submission['form']['sourcing_notes'] ?? '',
        'supporting_evidence' => $submission['form']['supporting_evidence_notes'] ?? '',
        'treatment_protocol' => $submission['form']['treatment_protocol_notes'] ?? '',
        'scientific_peer_review' => $submission['form']['scientific_peer_review_notes'] ?? '',
        'date' => $submission['form']['date'] ?? '',
    ];
}

function fillTemplatePdfFields(string $templatePath, string $outputPath, array $values): bool
{
    $pdf = @file_get_contents($templatePath);
    if (!$pdf || !str_contains($pdf, '/AcroForm')) {
        return false;
    }

    preg_match_all('/(\d+)\s+0\s+obj\b(.*?)endobj/s', $pdf, $allObjs, PREG_SET_ORDER);
    if (empty($allObjs)) {
        return false;
    }

    $objects = [];
    $maxObject = 0;
    foreach ($allObjs as $obj) {
        $num = (int)$obj[1];
        $objects[$num] = $obj[2];
        $maxObject = max($maxObject, $num);
    }

    preg_match('/startxref\s*(\d+)\s*%%EOF/s', $pdf, $xrefMatch);
    $prevXref = isset($xrefMatch[1]) ? (int)$xrefMatch[1] : null;
    preg_match('/trailer\s*<<.*?\/Root\s+(\d+\s+\d+\s+R).*?>>/s', $pdf, $rootMatch);
    $rootRef = $rootMatch[1] ?? '1 0 R';

    $updates = [];
    $map = getPdfFieldMapping();
    $fallbackIndex = 0;

    foreach ($objects as $num => $content) {
        $fieldName = extractPdfFieldName($content);
        if ($fieldName === null || $fieldName === '') {
            continue;
        }

        $fieldValue = resolveFieldValue($fieldName, $values, $map, $fallbackIndex);
        if ($fieldValue === null) {
            continue;
        }

        $escaped = encodePdfString($fieldValue);
        $newContent = preg_replace('/\/V\s*\((?:\.|[^\)])*\)/s', '', $content);
        $newContent = preg_replace('/\/DV\s*\((?:\.|[^\)])*\)/s', '', $newContent);
        $newContent = preg_replace('/\/V\s*<(?:[^>])*?>/s', '', $newContent);
        $newContent = preg_replace('/\/DV\s*<(?:[^>])*?>/s', '', $newContent);

        if (preg_match('/>>\s*$/s', trim($newContent))) {
            $newContent = preg_replace('/>>\s*$/s', '/V (' . $escaped . ') /DV (' . $escaped . ') >>', trim($newContent));
            $updates[$num] = $newContent;
        }
    }

    if (empty($updates)) {
        return false;
    }

    $append = "
";
    $offsets = [];
    ksort($updates);
    foreach ($updates as $num => $content) {
        $offsets[$num] = strlen($pdf . $append);
        $append .= $num . " 0 obj
" . trim($content) . "
endobj
";
    }

    $xrefPos = strlen($pdf . $append);
    $append .= "xref
";

    $groups = [];
    $nums = array_keys($offsets);
    sort($nums);
    $start = null;
    $last = null;
    foreach ($nums as $num) {
        if ($start === null) {
            $start = $last = $num;
            continue;
        }
        if ($num === $last + 1) {
            $last = $num;
            continue;
        }
        $groups[] = [$start, $last];
        $start = $last = $num;
    }
    if ($start !== null) {
        $groups[] = [$start, $last];
    }

    foreach ($groups as [$gStart, $gEnd]) {
        $append .= $gStart . ' ' . ($gEnd - $gStart + 1) . "
";
        for ($i = $gStart; $i <= $gEnd; $i++) {
            $append .= sprintf("%010d 00000 n 
", $offsets[$i]);
        }
    }

    $append .= 'trailer << /Size ' . ($maxObject + 1) . ' /Root ' . $rootRef . ' /NeedAppearances true';
    if ($prevXref !== null) {
        $append .= ' /Prev ' . $prevXref;
    }
    $append .= " >>
startxref
" . $xrefPos . "
%%EOF";

    return file_put_contents($outputPath, $pdf . $append) !== false;
}

function extractPdfFieldName(string $content): ?string
{
    if (preg_match('/\/T\s*\((.*?)\)/s', $content, $m)) {
        return decodePdfString($m[1]);
    }
    if (preg_match('/\/T\s*<([0-9A-Fa-f]+)>/s', $content, $m)) {
        return decodePdfHexString($m[1]);
    }
    return null;
}

function getPdfFieldMapping(): array
{
    $default = [
        'applicantname' => 'name',
        'fullname' => 'name',
        'name' => 'name',
        'email' => 'email',
        'phone' => 'phone',
        'mobile' => 'phone',
        'cpn' => 'cpn',
        'vocationalscope' => 'vocational_scope',
        'clinicalexperience' => 'clinical_experience',
        'products' => 'products',
        'indication' => 'indication',
        'source' => 'source',
        'sourcedfrom' => 'source',
        'supportingevidence' => 'supporting_evidence',
        'treatmentprotocol' => 'treatment_protocol',
        'scientificpeerreview' => 'scientific_peer_review',
        'date' => 'date',
    ];

    if (!file_exists(PDF_FIELD_MAP_FILE)) {
        return $default;
    }

    $custom = json_decode((string)file_get_contents(PDF_FIELD_MAP_FILE), true);
    if (!is_array($custom)) {
        return $default;
    }

    foreach ($custom as $k => $v) {
        $key = (string)preg_replace('/[^a-z0-9]+/', '', strtolower((string)$k));
        $default[$key] = (string)$v;
    }

    return $default;
}

function decodePdfHexString(string $hex): string
{
    $bin = @hex2bin($hex);
    if ($bin === false) {
        return '';
    }
    if (str_starts_with($bin, "þÿ") || str_starts_with($bin, "ÿþ")) {
        $u = @iconv('UTF-16', 'UTF-8//IGNORE', $bin);
        if ($u !== false) {
            return $u;
        }
    }
    return preg_replace('/[^ -~]/', '', $bin) ?? '';
}

function resolveFieldValue(string $fieldName, array $values, array $map, int &$fallbackIndex): ?string
{
    $compact = (string)preg_replace('/[^a-z0-9]+/', '', strtolower($fieldName));

    if (isset($map[$compact]) && isset($values[$map[$compact]])) {
        return (string)$values[$map[$compact]];
    }

    foreach ($map as $fieldToken => $valueKey) {
        if (str_contains($compact, $fieldToken) && isset($values[$valueKey])) {
            return (string)$values[$valueKey];
        }
    }

    $keys = array_keys($values);
    while ($fallbackIndex < count($keys)) {
        $k = $keys[$fallbackIndex++];
        $v = (string)($values[$k] ?? '');
        if ($v !== '') {
            return $v;
        }
    }

    return '';
}

function decodePdfString(string $s): string
{
    return str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $s);
}

function encodePdfString(string $s): string
{
    return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $s);
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
    <h1>Prescription Form Development</h1>
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
        <p>Products are loaded from WooCommerce. Edit each product under <strong>WooCommerce → Products</strong> and fill prescription custom fields (component, strength, form, sourced from, indications, indication mapping JSON).</p>
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
          <p>1. This application is for prescribing/supplying/administering approved psychedelic-assisted treatment only.</p>
          <p>2. The applicant confirms all details provided are complete and accurate.</p>
          <p>3. Approval decisions are made by the relevant regulator and may require additional documents.</p>
          <p>4. Clinical governance must be maintained under local legislation and policy.</p>
          <p>5. Product use must align with approved indication and treatment protocols.</p>
          <p>6. Any adverse events must be reported through the appropriate channels.</p>
          <p>7. This digital form does not replace legal obligations for controlled medicines.</p>
          <p>8. Supporting evidence may be audited and requested post-submission.</p>
          <p>9. Submission implies consent to process data for compliance and regulatory review.</p>
          <p>10. Electronic signature carries the same intent as a handwritten declaration.</p>
        </div>
        <div class="grid two">
          <label>Name<input value="<?= htmlspecialchars($doctor['name']) ?>" readonly /></label>
          <label>Email<input value="<?= htmlspecialchars($doctor['email']) ?>" readonly /></label>
          <label>Phone<input value="<?= htmlspecialchars($doctor['phone']) ?>" readonly /></label>
          <label>CPN<input value="<?= htmlspecialchars($doctor['cpn']) ?>" readonly /></label>
          <label>1.8 Vocational Scope
            <textarea name="vocational_scope" required><?= htmlspecialchars($doctorPrefs['vocational_scope']) ?></textarea>
          </label>
          <label>1.9 Clinical Experience & Training
            <textarea name="clinical_experience" required><?= htmlspecialchars($doctorPrefs['clinical_experience']) ?></textarea>
          </label>
        </div>
      </section>

      <section class="step" data-step="2" style="display:none;">
        <div class="step-header"><h2>Step 2: Product Details & Indication Mapping</h2><p>Select products and indication to auto-populate supporting sections.</p></div>

        <fieldset class="product-picker">
          <legend>Products (multi-select)</legend>
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
          <label>Component (auto)
            <textarea id="componentAuto" readonly></textarea>
          </label>
          <label>Strength (auto)
            <textarea id="strengthAuto" readonly></textarea>
          </label>
          <label>Form (auto)
            <textarea id="formAuto" readonly></textarea>
          </label>
          <label>Sourced from (auto)
            <textarea id="sourcingAuto" readonly></textarea>
            <textarea name="sourcing_notes" placeholder="Add/amend sourcing notes"></textarea>
          </label>
        </div>

        <label>Indication (auto from selected products)
          <select id="indicationSelect" name="indication" required>
            <option value="">Select indication</option>
          </select>
        </label>
        <label id="indicationOtherWrap" class="hidden">Other indication
          <input type="text" name="indication_other" id="indicationOtherInput" placeholder="Enter custom indication" />
        </label>

        <label>Supporting Evidence (auto by indication)
          <textarea id="supportingEvidenceAuto" readonly></textarea>
          <textarea name="supporting_evidence_notes" placeholder="Add/amend supporting evidence notes"></textarea>
        </label>

        <label>Treatment Protocol (auto by indication)
          <textarea id="treatmentProtocolAuto" readonly></textarea>
          <textarea name="treatment_protocol_notes" placeholder="Add/amend treatment protocol notes"></textarea>
        </label>

        <label>Scientific Peer Review (auto by indication)
          <textarea id="peerReviewAuto" readonly></textarea>
          <textarea name="scientific_peer_review_notes" placeholder="Add/amend scientific peer review notes"></textarea>
        </label>

        <label>Upload peer review support document
          <input type="file" name="peer_support_doc" />
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

  const pos = e => {
    const r = canvas.getBoundingClientRect();
    const p = e.touches ? e.touches[0] : e;
    return { x: p.clientX - r.left, y: p.clientY - r.top };
  };

  const start = e => { drawing = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); };
  const move = e => { if (!drawing) return; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); };
  const stop = () => { drawing = false; document.getElementById('signatureDrawn').value = canvas.toDataURL('image/png'); };

  canvas.addEventListener('mousedown', start);
  canvas.addEventListener('mousemove', move);
  window.addEventListener('mouseup', stop);
  canvas.addEventListener('touchstart', start);
  canvas.addEventListener('touchmove', move);
  canvas.addEventListener('touchend', stop);

  document.getElementById('clearSig').addEventListener('click', () => {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    document.getElementById('signatureDrawn').value = '';
  });
}
</script>
</body>
</html>
