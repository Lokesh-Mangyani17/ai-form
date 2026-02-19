<?php
session_start();

define('DATA_DIR', __DIR__ . '/data');
define('SUBMISSIONS_DIR', DATA_DIR . '/submissions');
define('PRODUCTS_FILE', DATA_DIR . '/products.json');
define('DOCTOR_PREFS_FILE', DATA_DIR . '/doctor_prefs.json');

bootstrapStorage();

$mockDoctor = [
    'id' => 1001,
    'name' => 'Dr Jane Smith',
    'email' => 'jane.smith@exampleclinic.nz',
    'phone' => '+64 21 555 1234',
    'cpn' => 'CPN-778899',
];

$page = $_GET['page'] ?? 'form';
$action = $_GET['action'] ?? null;
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'save_submission') {
        [$ok, $message, $error] = saveSubmission($mockDoctor, $_POST, $_FILES);
    }

    if ($action === 'save_product' && isAdmin()) {
        [$ok, $message, $error] = saveProduct($_POST);
    }

    if ($action === 'delete_product' && isAdmin()) {
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

$doctorPrefs = getDoctorPrefs($mockDoctor['id']);
$products = getProducts();
$submissions = getSubmissions();

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
            ['id' => 'prd-001', 'name' => 'Psilocybin Oral Capsule', 'component' => 'Psilocybin', 'strength' => '25mg', 'form' => 'Capsule', 'source' => 'Medsafe-approved compounding supplier, NZ'],
            ['id' => 'prd-002', 'name' => 'Ketamine Sublingual Troche', 'component' => 'Ketamine Hydrochloride', 'strength' => '100mg', 'form' => 'Troche', 'source' => 'Hospital pharmacy supply chain'],
            ['id' => 'prd-003', 'name' => 'MDMA Assisted Therapy Dose', 'component' => 'MDMA', 'strength' => '80mg', 'form' => 'Oral tablet', 'source' => 'Named-patient import license'],
        ];
        file_put_contents(PRODUCTS_FILE, json_encode($seed, JSON_PRETTY_PRINT));
    }
    if (!file_exists(DOCTOR_PREFS_FILE)) {
        file_put_contents(DOCTOR_PREFS_FILE, json_encode([], JSON_PRETTY_PRINT));
    }
}

function isAdmin(): bool
{
    return ($_GET['page'] ?? 'form') === 'admin';
}

function getProducts(): array
{
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

function saveSubmission(array $doctor, array $post, array $files): array
{
    $selectedProducts = $post['products'] ?? [];
    if (empty($selectedProducts)) {
        return [false, null, 'Please select at least one product.'];
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
        return [false, null, 'Please provide a drawn signature.'];
    }
    if ($signatureMode === 'upload' && !$signatureUploadPath) {
        return [false, null, 'Please upload a signature image.'];
    }

    saveDoctorPrefs((int)$doctor['id'], $post);

    $id = 'sub-' . date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 6);
    $submission = [
        'id' => $id,
        'submitted_at' => date('c'),
        'doctor' => $doctor,
        'form' => [
            'vocational_scope' => trim($post['vocational_scope'] ?? ''),
            'clinical_experience' => trim($post['clinical_experience'] ?? ''),
            'products' => $selectedProducts,
            'sourcing_notes' => trim($post['sourcing_notes'] ?? ''),
            'protocol_notes' => trim($post['protocol_notes'] ?? ''),
            'peer_review_notes' => trim($post['peer_review_notes'] ?? ''),
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
    createTextPdf($submission, $pdfPath);
    $submission['pdf_file'] = basename($pdfPath);

    file_put_contents(SUBMISSIONS_DIR . '/' . $id . '.json', json_encode($submission, JSON_PRETTY_PRINT));

    return [true, 'Submission saved and PDF generated successfully.', null];
}

function createTextPdf(array $submission, string $path): void
{
    $lines = [];
    $lines[] = 'Approval to Prescribe/Supply/Administer - Application';
    $lines[] = 'Submission ID: ' . $submission['id'];
    $lines[] = 'Submitted: ' . $submission['submitted_at'];
    $lines[] = '';
    $lines[] = 'Applicant Details';
    $lines[] = 'Name: ' . $submission['doctor']['name'];
    $lines[] = 'Email: ' . $submission['doctor']['email'];
    $lines[] = 'Phone: ' . $submission['doctor']['phone'];
    $lines[] = 'CPN: ' . $submission['doctor']['cpn'];
    $lines[] = 'Vocational Scope: ' . $submission['form']['vocational_scope'];
    $lines[] = 'Clinical Experience & Training: ' . $submission['form']['clinical_experience'];
    $lines[] = '';
    $lines[] = 'Products: ' . implode(', ', $submission['form']['products']);
    $lines[] = 'Sourcing Notes: ' . $submission['form']['sourcing_notes'];
    $lines[] = 'Treatment Protocol Notes: ' . $submission['form']['protocol_notes'];
    $lines[] = 'Peer Review Notes: ' . $submission['form']['peer_review_notes'];
    $lines[] = 'Application Date: ' . $submission['form']['date'];
    $lines[] = 'Signature Mode: ' . $submission['form']['signature_mode'];
    $lines[] = 'Signature Stored: ' . ($submission['form']['signature_mode'] === 'draw' ? 'Drawn signature captured' : ($submission['form']['signature_upload'] ?? 'No'));

    $y = 760;
    $stream = "BT\n/F1 10 Tf\n";
    foreach ($lines as $line) {
        $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
        $stream .= sprintf("1 0 0 1 40 %d Tm (%s) Tj\n", $y, $safe);
        $y -= 14;
    }
    $stream .= "ET";

    $objects = [];
    $objects[] = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n";
    $objects[] = "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n";
    $objects[] = "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n";
    $objects[] = "4 0 obj << /Length " . strlen($stream) . " >> stream\n$stream\nendstream endobj\n";
    $objects[] = "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $obj) {
        $offsets[] = strlen($pdf);
        $pdf .= $obj;
    }

    $xrefPos = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    for ($i = 1; $i <= 5; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer << /Size 6 /Root 1 0 R >>\nstartxref\n$xrefPos\n%%EOF";

    file_put_contents($path, $pdf);
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
</head>
<body>
<div class="container">
  <header class="topbar">
    <h1>Prescription Form Development</h1>
    <nav>
      <a href="?page=form">Doctor Form</a>
      <a href="?page=admin">Admin</a>
    </nav>
  </header>

  <?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <?php if ($page === 'admin'): ?>
    <section class="card">
      <h2>Product Management</h2>
      <form method="post" action="?page=admin&action=save_product" class="grid">
        <input name="name" placeholder="Product Name" required />
        <input name="component" placeholder="Component" required />
        <input name="strength" placeholder="Strength" required />
        <input name="form" placeholder="Form" required />
        <input name="source" placeholder="Source" required />
        <button type="submit">Add Product</button>
      </form>

      <table>
        <thead><tr><th>Name</th><th>Component</th><th>Strength</th><th>Form</th><th>Source</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
          <tr>
            <td><?= htmlspecialchars($p['name']) ?></td>
            <td><?= htmlspecialchars($p['component']) ?></td>
            <td><?= htmlspecialchars($p['strength']) ?></td>
            <td><?= htmlspecialchars($p['form']) ?></td>
            <td><?= htmlspecialchars($p['source']) ?></td>
            <td>
              <form method="post" action="?page=admin&action=delete_product">
                <input type="hidden" name="product_id" value="<?= htmlspecialchars($p['id']) ?>" />
                <button class="danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>

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
    <form id="prescriptionForm" class="card form-shell" method="post" enctype="multipart/form-data" action="?page=form&action=save_submission">
      <div class="steps"><span class="active">1</span><span>2</span><span>3</span></div>
      <div class="step-labels"><span>Applicant Details</span><span>Clinical Details</span><span>Sign & Submit</span></div>

      <section class="step active" data-step="1">
        <div class="step-header"><h2>Step 1: Disclaimer & Applicant Details</h2><p>Review the legal declaration and confirm your practitioner information.</p></div>
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
          <label>Name<input value="<?= htmlspecialchars($mockDoctor['name']) ?>" readonly /></label>
          <label>Email<input value="<?= htmlspecialchars($mockDoctor['email']) ?>" readonly /></label>
          <label>Phone<input value="<?= htmlspecialchars($mockDoctor['phone']) ?>" readonly /></label>
          <label>CPN<input value="<?= htmlspecialchars($mockDoctor['cpn']) ?>" readonly /></label>
          <label>1.8 Vocational Scope
            <textarea name="vocational_scope" required><?= htmlspecialchars($doctorPrefs['vocational_scope']) ?></textarea>
          </label>
          <label>1.9 Clinical Experience & Training
            <textarea name="clinical_experience" required><?= htmlspecialchars($doctorPrefs['clinical_experience']) ?></textarea>
          </label>
        </div>
      </section>

      <section class="step" data-step="2">
        <div class="step-header"><h2>Step 2: Product Details, Treatment Protocol & Peer Review</h2><p>Select one or more products to auto-populate regulator-facing sections.</p></div>
        <label>Products (multi-select)
          <select id="products" name="products[]" multiple required>
            <?php foreach ($products as $p): ?>
              <option value="<?= htmlspecialchars($p['name']) ?>" data-source="<?= htmlspecialchars($p['source']) ?>" data-protocol="<?= htmlspecialchars($p['component'] . ' ' . $p['strength'] . ' via ' . $p['form']) ?>" data-peer="<?= htmlspecialchars('Peer-reviewed evidence for ' . $p['component']) ?>">
                <?= htmlspecialchars($p['name'] . ' — ' . $p['component'] . ' / ' . $p['strength'] . ' / ' . $p['form']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>2.2 Sourcing Details (auto + notes)
          <textarea id="sourcingAuto" readonly></textarea>
          <textarea name="sourcing_notes" placeholder="Add or amend sourcing notes (does not overwrite source data)"></textarea>
        </label>
        <label>3.1 – 3.4 Treatment Protocol (auto + notes)
          <textarea id="protocolAuto" readonly></textarea>
          <textarea name="protocol_notes" placeholder="Protocol notes"></textarea>
        </label>
        <label>4.1 Scientific Peer Review (auto + notes)
          <textarea id="peerAuto" readonly></textarea>
          <textarea name="peer_review_notes" placeholder="Peer review notes"></textarea>
        </label>
        <label>Upload peer review support document
          <input type="file" name="peer_support_doc" />
        </label>
      </section>

      <section class="step" data-step="3">
        <div class="step-header"><h2>Step 3: Date, Signature & Submit</h2><p>Add declaration date and a mandatory digital signature to complete submission.</p></div>
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
</div>
<script>
const steps = [...document.querySelectorAll('.step')];
const badges = [...document.querySelectorAll('.steps span')];
let idx = 0;
const prevBtn = document.getElementById('prevBtn');
const nextBtn = document.getElementById('nextBtn');
const submitBtn = document.getElementById('submitBtn');

function showStep(i) {
  steps.forEach((s, n) => s.classList.toggle('active', n === i));
  badges.forEach((b, n) => b.classList.toggle('active', n === i));
  prevBtn.style.visibility = i === 0 ? 'hidden' : 'visible';
  nextBtn.classList.toggle('hidden', i === steps.length - 1);
  submitBtn.classList.toggle('hidden', i !== steps.length - 1);
}

if (nextBtn) nextBtn.onclick = () => { if (idx < steps.length - 1) { idx++; showStep(idx); } };
if (prevBtn) prevBtn.onclick = () => { if (idx > 0) { idx--; showStep(idx); } };
if (steps.length) showStep(0);

const productSelect = document.getElementById('products');
if (productSelect) {
  const syncAutoText = () => {
    const selected = [...productSelect.selectedOptions];
    document.getElementById('sourcingAuto').value = selected.map(o => `• ${o.value}: ${o.dataset.source}`).join('\n');
    document.getElementById('protocolAuto').value = selected.map(o => `• ${o.value}: ${o.dataset.protocol}`).join('\n');
    document.getElementById('peerAuto').value = selected.map(o => `• ${o.value}: ${o.dataset.peer}`).join('\n');
  };
  productSelect.addEventListener('change', syncAutoText);
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
