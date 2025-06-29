<?php
// scan.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("location:index.php");
    exit;
}

// Include database connection
require_once 'config/connection.php'; // Updated path as requested

// Function to sanitize output
function sanitize_output($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// --- Fetch Patient Data from Database ---
$patient_data = [];
try {
    $stmt = $pdo->query("SELECT id, name, age, gender as sex, patient_id as display_id FROM patients");
    while ($row = $stmt->fetch()) {
        $patient_data[$row['id']] = $row;
    }
} catch (\PDOException $e) {
    // Log the error or handle it appropriately
    error_log("Error fetching patient data: " . $e->getMessage());
    // Fallback or display an error to the user
    echo "<p style='color:red;'>Could not load patient data. Please try again later.</p>";
}

// --- Fetch Scan Categories from Database ---
$scan_categories = [];
try {
    $stmt = $pdo->query("SELECT id, name, name as value FROM ultrasound_categories");
    $scan_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    error_log("Error fetching scan categories: " . $e->getMessage());
    echo "<p style='color:red;'>Could not load scan categories. Please try again later.</p>";
}

// --- Fetch All Scan Records from Database ---
$all_scan_records = [];
try {
    $stmt = $pdo->query("SELECT sr.id, sr.patient_id, p.name AS patient_name, p.age AS patient_age, p.gender AS patient_sex, p.patient_id AS patient_display_id, sr.scan_date, sr.scan_type_display, sr.scan_title, sr.findings FROM scan_records sr JOIN patients p ON sr.patient_id = p.id ORDER BY sr.scan_date DESC");
    $all_scan_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    error_log("Error fetching scan records: " . $e->getMessage());
    echo "<p style='color:red;'>Could not load scan records. Please try again later.</p>";
}

// --- Handle New Scan Record Submission (to Database) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_scan_record'])) {
    $selected_patient_id = intval($_POST['patient_id']);
    $scan_category = sanitize_output($_POST['scan_category']);
    $scan_date = sanitize_output($_POST['scan_date']);

    // Fetch patient info from the database (or from the $patient_data array already loaded)
    $patient_info = $patient_data[$selected_patient_id] ?? null;

    if (!$patient_info) {
        $_SESSION['error_message'] = "Invalid patient selected.";
        header("Location: scan.php");
        exit;
    }

    $scan_title = '';
    $findings_html = '';
    $scan_type_display = ''; // Initialize scan_type_display

    // Find the display name for the selected category from the $scan_categories array
    foreach ($scan_categories as $category) {
        if ($category['value'] === $scan_category) {
            $scan_type_display = $category['name'];
            break;
        }
    }

    switch ($scan_category) {
        case 'obstetric':
            $scan_title = 'OBSTETRIC SCAN';
            $uterus = sanitize_output($_POST['obs_uterus']);
            $fl = sanitize_output($_POST['obs_fl']);
            $foetal_age = sanitize_output($_POST['obs_foetal_age']);
            $edd_scan = sanitize_output($_POST['obs_edd_scan']);
            $fluid_vol = sanitize_output($_POST['obs_fluid_vol']);
            $fh_rate = sanitize_output($_POST['obs_fh_rate']);
            $placenta = sanitize_output($_POST['obs_placenta']);
            $f_lie = sanitize_output($_POST['obs_f_lie']);
            $presentation = sanitize_output($_POST['obs_presentation']);
            $ef_weight = sanitize_output($_POST['obs_ef_weight']);
            $sex = sanitize_output($_POST['obs_sex']);
            $cervix = sanitize_output($_POST['obs_cervix']);
            $bladder = sanitize_output($_POST['obs_u_bladder']);
            $adnexae = sanitize_output($_POST['obs_adnexae']);
            $conclusion = sanitize_output($_POST['obs_conclusion']);
            $twin1_efwt = sanitize_output($_POST['obs_twin1_efwt']);
            $twin1_foetal_lie = sanitize_output($_POST['obs_twin1_foetal_lie']);
            $twin1_presentation = sanitize_output($_POST['obs_twin1_presentation']);
            $twin1_sex = sanitize_output($_POST['obs_twin1_sex']);

            $twin2_efwt = sanitize_output($_POST['obs_twin2_efwt']);
            $twin2_foetal_lie = sanitize_output($_POST['obs_twin2_foetal_lie']);
            $twin2_presentation = sanitize_output($_POST['obs_twin2_presentation']);
            $twin2_sex = sanitize_output($_POST['obs_twin2_sex']);

            $findings_html = '
                <h4>U/ BLADDER:</h4><p>' . $bladder . '</p>
                <h4>UTERUS:</h4><p>' . $uterus . '</p>
                <p class="scan-info-line"><strong>FL:</strong> <span>' . $fl . '</span></p>
                <p class="scan-info-line"><strong>FOETAL AGE:</strong> <span>' . $foetal_age . '</span></p>
                <p class="scan-info-line"><strong>EDD SCAN:</strong> <span>' . $edd_scan . '</span></p>
                <p class="scan-info-line"><strong>FLUID VOLUME:</strong> <span>' . $fluid_vol . '</span></p>
                <p class="scan-info-line"><strong>F.H.RATE:</strong> <span>' . $fh_rate . '</span></p>
                <p class="scan-info-line"><strong>PLACENTA:</strong> <span>' . $placenta . '</span></p>
                <p class="scan-info-line"><strong>F.LIE:</strong> <span>' . $f_lie . '</span></p>
                <p class="scan-info-line"><strong>PRESENTATION:</strong> <span>' . $presentation . '</span></p>
                <p class="scan-info-line"><strong>E.F.W.T:</strong> <span>' . $ef_weight . '</span></p>
                <p class="scan-info-line"><strong>SEX:</strong> <span>' . $sex . '</span></p>
            ';

            if (!empty($twin1_efwt) || !empty($twin2_efwt)) {
                $scan_type_display = 'Obstetric Scan (Twin Gestation)'; // Refine display name if it's a twin gestation
                $findings_html .= '
                    <div class="scan-section">
                        <h4>TWIN 1</h4>
                        <p class="scan-info-line"><strong>E.F.W.T:</strong> <span>' . $twin1_efwt . '</span></p>
                        <p class="scan-info-line"><strong>FOETAL LIE:</strong> <span>' . $twin1_foetal_lie . '</span></p>
                        <p class="scan-info-line"><strong>PRESENTATION:</strong> <span>' . $twin1_presentation . '</span></p>
                        <p class="scan-info-line"><strong>SEX:</strong> <span>' . $twin1_sex . '</span></p>
                    </div>
                    <div class="scan-section">
                        <h4>TWIN 2</h4>
                        <p class="scan-info-line"><strong>E.F.W.T:</strong> <span>' . $twin2_efwt . '</span></p>
                        <p class="scan-info-line"><strong>FOETAL LIE:</strong> <span>' . $twin2_foetal_lie . '</span></p>
                        <p class="scan-info-line"><strong>PRESENTATION:</strong> <span>' . $twin2_presentation . '</span></p>
                        <p class="scan-info-line"><strong>SEX:</strong> <span>' . $twin2_sex . '</span></p>
                    </div>';
            } else {
                   $scan_type_display = 'Obstetric Scan (Single Fetus)'; // Refine display name if it's a single fetus
            }

            $findings_html .= '
                <h4>CERVIX:</h4><p>' . $cervix . '</p>
                <h4>ADNEXAE:</h4><p>' . $adnexae . '</p>
                <p class="scan-conclusion" style="color: ' . (strpos(strtolower($conclusion), 'iufd') !== false ? 'red' : 'inherit') . ';"><strong>' . $conclusion . '</strong></p>
            ';
            break;

        case 'abdominopelvic':
            $scan_title = 'ABDOMINOPELVIC SCAN<br>ULTRASOUND FINDINGS';
            $liver = sanitize_output($_POST['abdo_liver']);
            $heart = sanitize_output($_POST['abdo_heart']);
            $spleen = sanitize_output($_POST['abdo_spleen']);
            $pancreas = sanitize_output($_POST['abdo_pancreas']);
            $stomach = sanitize_output($_POST['abdo_stomach']);
            $kidneys = sanitize_output($_POST['abdo_kidneys']);
            $gallbladder = sanitize_output($_POST['abdo_gallbladder']);
            $peritoneal_cavity = sanitize_output($_POST['abdo_peritoneal_cavity']);
            $prostate = sanitize_output($_POST['abdo_prostate']);
            $bladder = sanitize_output($_POST['abdo_bladder']);
            $uterus_abdo = sanitize_output($_POST['abdo_uterus']);
            $crl = sanitize_output($_POST['abdo_crl']);
            $ga = sanitize_output($_POST['abdo_ga']);
            $edd = sanitize_output($_POST['abdo_edd']);
            $amniotic_fluid = sanitize_output($_POST['abdo_amniotic_fluid']);
            $ovaries = sanitize_output($_POST['abdo_ovaries']);
            $adnexae_pod = sanitize_output($_POST['abdo_adnexae_pod']);
            $conclusion_abdo = sanitize_output($_POST['abdo_conclusion']);

            $findings_html = '
                <p class="scan-info-line"><strong>Liver:</strong> <span>' . $liver . '</span></p>
                <p class="scan-info-line"><strong>Heart:</strong> <span>' . $heart . '</span></p>
                <p class="scan-info-line"><strong>Spleen:</strong> <span>' . $spleen . '</span></p>
                <p class="scan-info-line"><strong>Pancreas:</strong> <span>' . $pancreas . '</span></p>
                <p class="scan-info-line"><strong>Stomach:</strong> <span>' . $stomach . '</span></p>
                <p class="scan-info-line"><strong>Kidneys:</strong> <span>' . $kidneys . '</span></p>
                <p class="scan-info-line"><strong>Gallbladder:</strong> <span>' . $gallbladder . '</span></p>
                <p class="scan-info-line"><strong>Peritoneal Cavity:</strong> <span>' . $peritoneal_cavity . '</span></p>
                <p class="scan-info-line"><strong>PELVIC</strong></p>
            ';
            if (!empty($prostate)) { // Specific to male abdominopelvic
                $findings_html .= '<p class="scan-info-line"><strong>Prostate:</strong> <span>' . $prostate . '</span></p>';
            }
            $findings_html .= '<p class="scan-info-line"><strong>Bladder:</strong> <span>' . $bladder . '</span></p>';

            if (!empty($uterus_abdo) || !empty($ovaries)) { // Specific to female abdominopelvic
                $findings_html .= '
                    <p class="scan-info-line"><strong>UTERUS:</strong> <span>' . $uterus_abdo . '</span></p>
                    <p class="scan-info-line"><strong>CRL</strong> <span>' . $crl . '</span> <strong>G.A of</strong> <span>' . $ga . '</span> <strong>EDD=</strong><span>' . $edd . '</span></p>
                    <p class="scan-info-line"><strong>Amniotic fluid=</strong> <span>' . $amniotic_fluid . '</span></p>
                    <p class="scan-info-line"><strong>Urinary Bladder:</strong> <span>' . $bladder . '</span></p>
                    <p class="scan-info-line"><strong>OVARIES:</strong> <span>' . $ovaries . '</span></p>
                    <p class="scan-info-line"><strong>ADNEXAE/POD:</strong> <span>' . $adnexae_pod . '</span></p>';
            }

            $findings_html .= '<h4>CONCLUSIONS:</h4><p class="scan-conclusion">' . $conclusion_abdo . '</p>';
            break;

        case 'neck':
            $scan_title = 'Neck SCAN<br>ULTRASOUND FINDINGS';
            $soft_tissue = sanitize_output($_POST['neck_soft_tissue']);
            $thyroid = sanitize_output($_POST['neck_thyroid']);
            $conclusion_neck = sanitize_output($_POST['neck_conclusion']);

            $findings_html = '';
            if (!empty($soft_tissue)) {
                $findings_html .= '<p class="scan-info-line"><strong>SOFT TISSUE:</strong> <span>' . $soft_tissue . '</span></p>';
            }
            if (!empty($thyroid)) {
                $scan_title = 'NECK USS'; // Override title if thyroid is entered
                $findings_html .= '<p class="scan-info-line"><strong>THYROID:</strong> <span>' . $thyroid . '</span></p>';
            }
            $findings_html .= '<p class="scan-conclusion"><strong>' . $conclusion_neck . '</strong></p>';
            break;

        case 'scrotal':
            $scan_title = 'SCROTAL USS';
            $scrotum_findings = sanitize_output($_POST['scrotal_findings']);
            $conclusion_scrotal = sanitize_output($_POST['scrotal_conclusion']);
            $findings_html = '
                <p class="scan-info-line"><strong>SCROTUM:</strong> <span>' . $scrotum_findings . '</span></p>
                <p class="scan-conclusion"><strong>' . $conclusion_scrotal . '</strong></p>
            ';
            break;

        case 'breast':
            $scan_title = 'BREAST SCAN';
            $right_breast = sanitize_output($_POST['breast_right']);
            $left_breast = sanitize_output($_POST['breast_left']);
            $conclusion_breast = sanitize_output($_POST['breast_conclusion']);
            $findings_html = '
                <p class="scan-info-line"><strong>RIGHT BREAST:</strong> <span>' . $right_breast . '</span></p>
                <p class="scan-info-line"><strong>LEFT BREAST:</strong> <span>' . $left_breast . '</span></p>
                <p class="scan-conclusion"><strong>' . $conclusion_breast . '</strong></p>
            ';
            break;
    }

    // Insert new record into the database
    try {
        $stmt = $pdo->prepare("INSERT INTO scan_records (patient_id, scan_date, scan_type_display, scan_title, findings, generated_by_user_id) VALUES (?, ?, ?, ?, ?, ?)");
        // You'll need to determine the `generated_by_user_id`. Assuming $_SESSION['user_id'] is appropriate.
        $generated_by_user_id = $_SESSION['user_id'] ?? 1; // Default to 1 or handle appropriately if user ID is not in session
        $stmt->execute([$selected_patient_id, $scan_date, $scan_type_display, $scan_title, $findings_html, $generated_by_user_id]);
        $new_id = $pdo->lastInsertId();
        $_SESSION['success_message'] = "Scan record saved successfully! (ID: {$new_id})";
        header("Location: scan.php");
        exit;
    } catch (\PDOException $e) {
        error_log("Error saving scan record: " . $e->getMessage());
        $_SESSION['error_message'] = "Failed to save scan record: " . $e->getMessage();
        header("Location: scan.php"); // Redirect even on error to show message
        exit;
    }
}

// --- Clinic details ---
// This section assumes index.php exists and contains the details.
// If index.php also needs to be dynamic, consider storing these in DB.
$clinic_name = "KDCS Clinic";
$clinic_address = "Address Not Found (Default)";
$clinic_email = "info@kdcsclinic.com (Default)";
$clinic_phone = "Phone Not Found (Default)";

$index_content = @file_get_contents('index.php'); // Use @to suppress warning if file not found
if ($index_content !== false) {
    if (preg_match('/<h1><strong>(.*?)<\/strong><\/h1>/', $index_content, $matches)) {
        $clinic_name = strip_tags($matches[1]);
    }
    if (preg_match('/<hr>\s*<h3>Caring for a BETTER Life\?<\/h3>\s*<p>(.*?)<\/p>/s', $index_content, $matches)) {
        $clinic_address = strip_tags($matches[1]);
        $clinic_address = preg_replace('/^Visit us @\s*/', '', $clinic_address);
    }
    if (preg_match('/<hr>\s*<h3>Caring for a BETTER Life\?<\/h3>\s*<p>.*?<\/p>\s*<p><strong>Email:\s*<\/strong>(.*?)\s*\|\s*<strong>Office No.:<\/strong>(.*?)\s*\|\s*<strong>Doctor\'s No.:\s*<\/strong>(.*?)<\/p>/s', $index_content, $matches_contact)) {
        $clinic_email = trim($matches_contact[1]);
        $office_no = trim($matches_contact[2]);
        $doctor_no = trim($matches_contact[3]);
        $clinic_phone = $office_no;
        if (!empty($doctor_no)) {
            $clinic_phone .= " / " . $doctor_no;
        }
    }
}

// Get the current page filename for sidebar highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Define an array of pages that belong to the Scanning menu to open the treeview
$scanning_pages = ['scan.php']; // Adjusted to 'scan.php' for this file

// The rest of your HTML and form structure would follow here,
// using the dynamically loaded $patient_data and $scan_categories arrays.
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scan Records - KDCS Clinic</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 900px; margin: auto; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="date"], textarea, select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .btn {
            background-color: #4CAF50;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        .btn-primary { background-color: #007bff; }
        .btn-info { background-color: #17a2b8; }
        .btn-danger { background-color: #dc3545; }
        .alert { padding: 10px; margin-bottom: 20px; border-radius: 4px; }
        .alert-success { background-color: #d4edda; color: #155724; border-color: #c3e6cb; }
        .alert-danger { background-color: #f8d7da; color: #721c24; border-color: #f5c6cb; }

        /* Styles for scan record display */
        .scan-record-card {
            border: 1px solid #ddd;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            background-color: #fff;
            position: relative; /* For watermark */
            overflow: hidden; /* For watermark */
        }
        .scan-record-card h3 {
            color: #333;
            margin-top: 0;
            margin-bottom: 15px;
            text-align: center;
            position: relative;
            z-index: 1;
        }
        .scan-record-card .patient-info {
            background-color: #f9f9f9;
            border: 1px dashed #eee;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            position: relative;
            z-index: 1;
        }
        .scan-record-card .patient-info p {
            margin: 5px 10px;
            flex: 1 1 45%; /* Allows two columns on wider screens */
        }
        .scan-info-line {
            display: flex;
            margin-bottom: 5px;
            position: relative;
            z-index: 1;
        }
        .scan-info-line strong {
            flex-basis: 150px; /* Fixed width for labels */
            margin-right: 10px;
            color: #555;
        }
        .scan-info-line span {
            flex-grow: 1;
            color: #000;
        }
        .scan-section {
            border: 1px dashed #ccc;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 5px;
            position: relative;
            z-index: 1;
        }
        .scan-section:last-child {
            border-bottom: none;
        }
        .scan-section h4 {
            font-size: 1.1em;
            border-bottom: 1px solid #ccc;
            padding-bottom: 5px;
            margin-bottom: 10px;
        }
        .scan-conclusion {
            font-weight: bold;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px dashed #ccc;
            color: #000;
            position: relative;
            z-index: 1;
        }
        .scan-watermark {
            font-size: 5em;
            opacity: 0.1;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-weight: bold;
            color: rgba(0,0,0,0.1);
            z-index: 0;
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Manage Scan Records</h1>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <?= $_SESSION['success_message']; ?>
                <?php unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger">
                <?= $_SESSION['error_message']; ?>
                <?php unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>

        <h2>Add New Scan Record</h2>
        <form method="POST">
            <div class="form-group">
                <label for="patient_id">Select Patient:</label>
                <select name="patient_id" id="patient_id" class="form-control" required>
                    <option value="">Select Patient:</option>
                    <?php foreach ($patient_data as $id => $patient): ?>
                        <option value="<?= sanitize_output($id); ?>"><?= sanitize_output($patient['name']); ?> (<?= sanitize_output($patient['display_id']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="scan_category">Select Scan Category:</label>
                <select name="scan_category" id="scan_category" class="form-control" required>
                    <option value="">Select Scan Category:</option>
                    <?php foreach ($scan_categories as $category): ?>
                        <option value="<?= sanitize_output($category['value']); ?>"><?= sanitize_output($category['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="scan_date">Scan Date:</label>
                <input type="date" name="scan_date" id="scan_date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
            </div>

            <div id="scan_details_form">
                <p>Select a Scan Category to view relevant fields.</p>
            </div>

            <button type="submit" name="save_scan_record" class="btn btn-primary">Save Scan Record</button>
        </form>

        <hr>

        <h2>Existing Scan Records</h2>
        <?php if (empty($all_scan_records)): ?>
            <p>No scan records found.</p>
        <?php else: ?>
            <?php foreach ($all_scan_records as $record): ?>
                <div class="scan-record-card">
                    <div class="scan-watermark"><?= $clinic_name; ?></div>
                    <h3><?= sanitize_output($record['scan_title']); ?></h3>
                    <div class="patient-info">
                        <p><strong>Patient Name:</strong> <?= sanitize_output($record['patient_name']); ?></p>
                        <p><strong>Patient ID:</strong> <?= sanitize_output($record['patient_display_id']); ?></p>
                        <p><strong>Age:</strong> <?= sanitize_output($record['patient_age']); ?></p>
                        <p><strong>Sex:</strong> <?= sanitize_output($record['patient_sex']); ?></p>
                        <p><strong>Scan Date:</strong> <?= date('l, F d, Y', strtotime(sanitize_output($record['scan_date']))); ?></p>
                        <p><strong>Scan Type:</strong> <?= sanitize_output($record['scan_type_display']); ?></p>
                    </div>
                    <div class="scan-findings">
                        <?= $record['findings']; // Findings are already HTML, so no sanitization here if trusted source ?>
                    </div>
                    <button class="btn btn-info print-button" data-record-id="<?= sanitize_output($record['id']); ?>">Print Scan</button>
                    </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const scanCategorySelect = document.getElementById('scan_category');
            const scanDetailsForm = document.getElementById('scan_details_form');

            scanCategorySelect.addEventListener('change', function() {
                const category = this.value;
                let fieldsHtml = '';

                switch (category) {
                    case 'obstetric':
                        fieldsHtml = `
                            <h3>Obstetric Scan Details</h3>
                            <div class="form-group">
                                <label for="obs_uterus">Uterus:</label>
                                <textarea name="obs_uterus" id="obs_uterus" class="form-control" required>There is a single live foetus in utero, presenting cephalic, with F.H.R of 145bpm.</textarea>
                            </div>
                            <div class="form-group">
                                <label for="obs_fl">FL:</label>
                                <input type="text" name="obs_fl" id="obs_fl" class="form-control" value="6.5cm">
                            </div>
                            <div class="form-group">
                                <label for="obs_foetal_age">Foetal Age:</label>
                                <input type="text" name="obs_foetal_age" id="obs_foetal_age" class="form-control" value="38wks 0D">
                            </div>
                            <div class="form-group">
                                <label for="obs_edd_scan">EDD Scan:</label>
                                <input type="text" name="obs_edd_scan" id="obs_edd_scan" class="form-control" value="${new Date(new Date().setFullYear(new Date().getFullYear() + 1)).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' }).replace(/\//g, ':')}&plusmn;2wks">
                            </div>
                            <div class="form-group">
                                <label for="obs_fluid_vol">Fluid Volume:</label>
                                <input type="text" name="obs_fluid_vol" id="obs_fluid_vol" class="form-control" value="Adequate">
                            </div>
                            <div class="form-group">
                                <label for="obs_fh_rate">F.H. Rate:</label>
                                <input type="text" name="obs_fh_rate" id="obs_fh_rate" class="form-control" value="Normal">
                            </div>
                            <div class="form-group">
                                <label for="obs_placenta">Placenta:</label>
                                <input type="text" name="obs_placenta" id="obs_placenta" class="form-control" value="Posterior">
                            </div>
                            <div class="form-group">
                                <label for="obs_f_lie">F. Lie:</label>
                                <input type="text" name="obs_f_lie" id="obs_f_lie" class="form-control" value="Longitudinal">
                            </div>
                            <div class="form-group">
                                <label for="obs_presentation">Presentation:</label>
                                <input type="text" name="obs_presentation" id="obs_presentation" class="form-control" value="CEPHALIC">
                            </div>
                            <div class="form-group">
                                <label for="obs_ef_weight">E.F.W.T:</label>
                                <input type="text" name="obs_ef_weight" id="obs_ef_weight" class="form-control" value="3.0kg">
                            </div>
                            <div class="form-group">
                                <label for="obs_sex">Sex:</label>
                                <input type="text" name="obs_sex" id="obs_sex" class="form-control" value="Female">
                            </div>
                            <h4>Twin 1 (Optional)</h4>
                            <div class="form-group">
                                <label for="obs_twin1_efwt">E.F.W.T:</label>
                                <input type="text" name="obs_twin1_efwt" id="obs_twin1_efwt" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="obs_twin1_foetal_lie">Foetal Lie:</label>
                                <input type="text" name="obs_twin1_foetal_lie" id="obs_twin1_foetal_lie" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="obs_twin1_presentation">Presentation:</label>
                                <input type="text" name="obs_twin1_presentation" id="obs_twin1_presentation" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="obs_twin1_sex">Sex:</label>
                                <input type="text" name="obs_twin1_sex" id="obs_twin1_sex" class="form-control">
                            </div>
                            <h4>Twin 2 (Optional)</h4>
                            <div class="form-group">
                                <label for="obs_twin2_efwt">E.F.W.T:</label>
                                <input type="text" name="obs_twin2_efwt" id="obs_twin2_efwt" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="obs_twin2_foetal_lie">Foetal Lie:</label>
                                <input type="text" name="obs_twin2_foetal_lie" id="obs_twin2_foetal_lie" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="obs_twin2_presentation">Presentation:</label>
                                <input type="text" name="obs_twin2_presentation" id="obs_twin2_presentation" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="obs_twin2_sex">Sex:</label>
                                <input type="text" name="obs_twin2_sex" id="obs_twin2_sex" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="obs_cervix">Cervix:</label>
                                <textarea name="obs_cervix" id="obs_cervix" class="form-control" required>The internal OS is closed</textarea>
                            </div>
                            <div class="form-group">
                                <label for="obs_u_bladder">U/ Bladder:</label>
                                <textarea name="obs_u_bladder" id="obs_u_bladder" class="form-control" required>The bladder wall is normal, no calculi.</textarea>
                            </div>
                            <div class="form-group">
                                <label for="obs_adnexae">Adnexae:</label>
                                <textarea name="obs_adnexae" id="obs_adnexae" class="form-control" required>Free.</textarea>
                            </div>
                            <div class="form-group">
                                <label for="obs_conclusion">Conclusion:</label>
                                <textarea name="obs_conclusion" id="obs_conclusion" class="form-control" required>VIABLE 3RD TRIMESTER GESTATION.</textarea>
                            </div>
                        `;
                        break;
                    case 'abdominopelvic':
                        fieldsHtml = `
                            <h3>Abdominopelvic Scan Details</h3>
                            <div class="form-group">
                                <label for="abdo_liver">Liver:</label>
                                <input type="text" name="abdo_liver" id="abdo_liver" class="form-control" value="The liver is within normal craniocaudal span.">
                            </div>
                            <div class="form-group">
                                <label for="abdo_heart">Heart:</label>
                                <input type="text" name="abdo_heart" id="abdo_heart" class="form-control" value="The heart is normal in size, normal rate">
                            </div>
                            <div class="form-group">
                                <label for="abdo_spleen">Spleen:</label>
                                <input type="text" name="abdo_spleen" id="abdo_spleen" class="form-control" value="Normal size, normal echo texture.">
                            </div>
                            <div class="form-group">
                                <label for="abdo_pancreas">Pancreas:</label>
                                <input type="text" name="abdo_pancreas" id="abdo_pancreas" class="form-control" value="Normal size, normal echo texture.">
                            </div>
                            <div class="form-group">
                                <label for="abdo_stomach">Stomach:</label>
                                <input type="text" name="abdo_stomach" id="abdo_stomach" class="form-control" value="Normal wall thickness, no polyp.">
                            </div>
                            <div class="form-group">
                                <label for="abdo_kidneys">Kidneys:</label>
                                <input type="text" name="abdo_kidneys" id="abdo_kidneys" class="form-control" value="Normal size in both sides with normal parenchymal echo texture.">
                            </div>
                            <div class="form-group">
                                <label for="abdo_gallbladder">Gallbladder:</label>
                                <input type="text" name="abdo_gallbladder" id="abdo_gallbladder" class="form-control" value="Normal size, no thickening, no stones.">
                            </div>
                            <div class="form-group">
                                <label for="abdo_peritoneal_cavity">Peritoneal Cavity:</label>
                                <input type="text" name="abdo_peritoneal_cavity" id="abdo_peritoneal_cavity" class="form-control" value="Normal gut, no distention, no ascites, no mass.">
                            </div>
                            <h4>Pelvic Details</h4>
                            <div class="form-group">
                                <label for="abdo_prostate">Prostate (Male):</label>
                                <input type="text" name="abdo_prostate" id="abdo_prostate" class="form-control" value="Normal size, normal parenchymal echo texture.">
                            </div>
                            <div class="form-group">
                                <label for="abdo_bladder">Bladder:</label>
                                <input type="text" name="abdo_bladder" id="abdo_bladder" class="form-control" value="Uniformly filled, Normal in size, no thickening.">
                            </div>
                            <div class="form-group">
                                <label for="abdo_uterus">Uterus (Female):</label>
                                <input type="text" name="abdo_uterus" id="abdo_uterus" class="form-control" value="Normal size, normal echo texture.">
                            </div>
                             <div class="form-group">
                                <label for="abdo_crl">CRL:</label>
                                <input type="text" name="abdo_crl" id="abdo_crl" class="form-control" value="">
                            </div>
                            <div class="form-group">
                                <label for="abdo_ga">G.A:</label>
                                <input type="text" name="abdo_ga" id="abdo_ga" class="form-control" value="">
                            </div>
                            <div class="form-group">
                                <label for="abdo_edd">EDD:</label>
                                <input type="text" name="abdo_edd" id="abdo_edd" class="form-control" value="">
                            </div>
                            <div class="form-group">
                                <label for="abdo_amniotic_fluid">Amniotic Fluid:</label>
                                <input type="text" name="abdo_amniotic_fluid" id="abdo_amniotic_fluid" class="form-control" value="">
                            </div>
                            <div class="form-group">
                                <label for="abdo_ovaries">Ovaries (Female):</label>
                                <input type="text" name="abdo_ovaries" id="abdo_ovaries" class="form-control" value="NORMAL SIZE, NO CYST SEEN">
                            </div>
                            <div class="form-group">
                                <label for="abdo_adnexae_pod">Adnexae/POD (Female):</label>
                                <textarea name="abdo_adnexae_pod" id="abdo_adnexae_pod" class="form-control">Free.</textarea>
                            </div>
                            <div class="form-group">
                                <label for="abdo_conclusion">Conclusion:</label>
                                <textarea name="abdo_conclusion" id="abdo_conclusion" class="form-control" required>Features consistent with NORMAL SCAN.</textarea>
                            </div>
                        `;
                        break;
                    case 'neck':
                        fieldsHtml = `
                            <h3>Neck Scan Details</h3>
                            <div class="form-group">
                                <label for="neck_soft_tissue">Soft Tissue:</label>
                                <input type="text" name="neck_soft_tissue" id="neck_soft_tissue" class="form-control" value="Normal soft tissue of the neck.">
                            </div>
                            <div class="form-group">
                                <label for="neck_thyroid">Thyroid (Optional):</label>
                                <textarea name="neck_thyroid" id="neck_thyroid" class="form-control" placeholder="E.g., The thyroid is normal in size and measures 5 x 3cm and 6 x 2cm on the right and left respectively. No areas of distortion, nodules, cyst, calcification nor intra-parenchymal lymphadenopathy. The great vessels of the neck are neither dilated nor compressed."></textarea>
                            </div>
                            <div class="form-group">
                                <label for="neck_conclusion">Conclusion:</label>
                                <textarea name="neck_conclusion" id="neck_conclusion" class="form-control" required>NORMAL SCAN.</textarea>
                            </div>
                        `;
                        break;
                    case 'scrotal':
                        fieldsHtml = `
                            <h3>Scrotal USS Details</h3>
                            <div class="form-group">
                                <label for="scrotal_findings">Scrotum Findings:</label>
                                <textarea name="scrotal_findings" id="scrotal_findings" class="form-control" required>Both testes are normal in size and measure 30.6mm x 15.5mm and 31.2 x 15.3mm on the right and left respectively. No areas of distortion, cyst, nodules, and no calcification visualized. The mediastinum and the scrotal skin are within normal limit.</textarea>
                            </div>
                            <div class="form-group">
                                <label for="scrotal_conclusion">Conclusion:</label>
                                <textarea name="scrotal_conclusion" id="scrotal_conclusion" class="form-control" required>Features consistent with NORMAL SCAN.</textarea>
                            </div>
                        `;
                        break;
                    case 'breast':
                        fieldsHtml = `
                            <h3>Breast Scan Details</h3>
                            <div class="form-group">
                                <label for="breast_right">Right Breast Findings:</label>
                                <textarea name="breast_right" id="breast_right" class="form-control" required>Normal glandular breast tissue and lactiferous ducts. No mass no, cyst. Normal pectoralis fascia and muscles.</textarea>
                            </div>
                            <div class="form-group">
                                <label for="breast_left">Left Breast Findings:</label>
                                <textarea name="breast_left" id="breast_left" class="form-control" required>Normal glandular breast tissue and lactiferous ducts. No mass no, cyst. Normal pectoralis fascia and muscles.</textarea>
                            </div>
                            <div class="form-group">
                                <label for="breast_conclusion">Conclusion:</label>
                                <textarea name="breast_conclusion" id="breast_conclusion" class="form-control" required>Features consistent with NORMAL SCAN.</textarea>
                            </div>
                        `;
                        break;
                    default:
                        fieldsHtml = '<p>Select a Scan Category to view relevant fields.</p>';
                        break;
                }
                scanDetailsForm.innerHTML = fieldsHtml;
            });

            // Trigger change on load if a category is pre-selected (e.g., after form submission with error)
            if (scanCategorySelect.value) {
                scanCategorySelect.dispatchEvent(new Event('change'));
            }


            // Print functionality
            document.querySelectorAll('.print-button').forEach(button => {
                button.addEventListener('click', function() {
                    const recordId = this.dataset.recordId;
                    const recordCard = this.closest('.scan-record-card');
                    if (recordCard) {
                        printScanRecord(recordCard.innerHTML);
                    }
                });
            });

            function printScanRecord(contentToPrint) {
                const printWindow = window.open('', '', 'height=600,width=800');
                printWindow.document.write('<html><head><title>Print Scan Record</title>');
                printWindow.document.write(`
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; color: #333; }
                        .scan-record-card {
                            border: 1px solid #ddd;
                            padding: 20px;
                            margin-bottom: 20px;
                            border-radius: 8px;
                            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                            background-color: #fff;
                            position: relative;
                            overflow: hidden;
                        }
                        .scan-record-card h3 {
                            color: #333;
                            margin-top: 0;
                            margin-bottom: 15px;
                            text-align: center;
                            position: relative;
                            z-index: 1;
                        }
                        .scan-record-card .patient-info {
                            background-color: #f9f9f9;
                            border: 1px dashed #eee;
                            padding: 10px;
                            margin-bottom: 15px;
                            border-radius: 4px;
                            display: flex;
                            justify-content: space-between;
                            flex-wrap: wrap;
                            position: relative;
                            z-index: 1;
                        }
                        .scan-record-card .patient-info p {
                            margin: 5px 10px;
                            flex: 1 1 45%;
                        }
                        .scan-info-line {
                            display: flex;
                            margin-bottom: 5px;
                            position: relative;
                            z-index: 1;
                        }
                        .scan-info-line strong {
                            flex-basis: 150px;
                            margin-right: 10px;
                            color: #555;
                        }
                        .scan-info-line span {
                            flex-grow: 1;
                            color: #000;
                        }
                        .scan-section {
                            border: 1px dashed #ccc;
                            padding: 15px;
                            margin-bottom: 15px;
                            border-radius: 5px;
                            position: relative;
                            z-index: 1;
                        }
                        .scan-section:last-child {
                            border-bottom: none;
                        }
                        .scan-section h4 {
                            font-size: 1.1em;
                            border-bottom: 1px solid #ccc;
                            padding-bottom: 5px;
                            margin-bottom: 10px;
                        }
                        .scan-conclusion {
                            font-weight: bold;
                            margin-top: 20px;
                            padding-top: 10px;
                            border-top: 1px dashed #ccc;
                            color: #000;
                            position: relative;
                            z-index: 1;
                        }
                        .scan-watermark {
                            font-size: 5em;
                            opacity: 0.1;
                            position: absolute;
                            top: 50%;
                            left: 50%;
                            transform: translate(-50%, -50%) rotate(-45deg);
                            font-weight: bold;
                            color: rgba(0,0,0,0.1);
                            z-index: 0;
                            white-space: nowrap;
                        }
                    `);
                printWindow.document.write('</style></head><body>');
                printWindow.document.write(contentToPrint);
                printWindow.document.write('</body></html>');
                printWindow.document.close();
                printWindow.focus();
                printWindow.print();
                printWindow.onafterprint = function() {
                    printWindow.close();
                };
            }
        });
    </script>
</body>
</html>