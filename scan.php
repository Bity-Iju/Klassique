<?php
// scan.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("location:index.php");
    exit;
}

require_once 'config/connection.php';

// Function to sanitize output
function sanitize_output($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// --- Handle Delete Scan Record ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_scan_record'])) {
    $scan_id = intval($_POST['scan_id']);

    try {
        $pdo->beginTransaction();

        // Get scan_order_id and scan_image_path before deleting the scan record
        $stmt_get_info = $pdo->prepare("SELECT scan_order_id, scan_image_path FROM scans WHERE id = ?");
        $stmt_get_info->execute([$scan_id]);
        $scan_info = $stmt_get_info->fetch(PDO::FETCH_ASSOC);

        if ($scan_info) {
            $scan_order_id = $scan_info['scan_order_id'];
            $scan_image_path = $scan_info['scan_image_path'];

            // Delete from 'scans' table
            $stmt_delete_scan = $pdo->prepare("DELETE FROM scans WHERE id = ?");
            $stmt_delete_scan->execute([$scan_id]);

            // Delete from 'scan_orders' table
            $stmt_delete_order = $pdo->prepare("DELETE FROM scan_orders WHERE id = ?");
            $stmt_delete_order->execute([$scan_order_id]);

            // Delete associated image file if it exists
            if ($scan_image_path && file_exists($scan_image_path)) {
                unlink($scan_image_path);
            }

            $pdo->commit();
            $_SESSION['success_message'] = "Scan record deleted successfully!";
        } else {
            $_SESSION['error_message'] = "Scan record not found.";
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
        error_log("Scan delete error: " . $e->getMessage());
    }
    header("Location: scan.php");
    exit;
}


// --- Fetch Patient Data from DB ---
$patient_data = [];
$stmt = $pdo->query("SELECT id, patient_name, gender, patient_display_id, dob FROM patients ORDER BY patient_name ASC");
while ($row = $stmt->fetch()) {
    // Calculate age from dob
    $age = '';
    if (!empty($row['dob']) && $row['dob'] !== '0000-00-00') {
        $dob = new DateTime($row['dob']);
        $now = new DateTime();
        $age = $now->diff($dob)->y;
    }
    $patient_data[$row['id']] = [
        'name' => $row['patient_name'],
        'age' => $age,
        'sex' => $row['gender'],
        'display_id' => $row['patient_display_id']
    ];
}

// --- Fetch Scan Categories from DB ---
$scan_categories = [];
$stmt = $pdo->query("SELECT id, name, value FROM scan_categories ORDER BY id ASC");
while ($row = $stmt->fetch()) {
    $scan_categories[] = $row;
}

// --- Handle New Scan Record Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_scan_record'])) {
    $selected_patient_id = intval($_POST['patient_id']);
    $scan_category_value = sanitize_output($_POST['scan_category']); // This is the 'value' from scan_categories, e.g., 'obstetric'
    $scan_date = sanitize_output($_POST['scan_date']);
    $patient_info = $patient_data[$selected_patient_id]; // This is needed to get patient_display_id later
    $scan_title = '';
    $findings_html = '';
    $scan_type_display = ''; // This will store the 'name' from scan_categories
    $conclusion = '';
    $scan_image_path = null; // Initialize image path

    // Find the display name for the selected category
    foreach ($scan_categories as $category) {
        $mapped_value = '';
        switch (strtolower($category['name'])) {
            case 'obstetric scan': $mapped_value = 'obstetric'; break;
            case 'abdominopelvic scan': $mapped_value = 'abdominopelvic'; break;
            case 'neck scan': $mapped_value = 'neck'; break;
            case 'scrotal uss': $mapped_value = 'scrotal'; break;
            case 'breast scan': $mapped_value = 'breast'; break;
            case 'abdominal scan': $mapped_value = 'abdominal'; break;
            case 'doppler renal scan': $mapped_value = 'doppler_renal'; break;
            case 'cardiac echo scan': $mapped_value = 'cardiac_echo'; break;
            case 'cranial scan': $mapped_value = 'cranial'; break;
            case 'follicular scan': $mapped_value = 'follicular'; break;
            case 'musculoskeletal scan': $mapped_value = 'musculoskeletal'; break;
            case 'pelvic scan': $mapped_value = 'pelvic'; break;
            case 'prostate scan': $mapped_value = 'prostate'; break;
            case 'renal scan': $mapped_value = 'renal'; break;
            case 'vascular doppler scan': $mapped_value = 'vascular_doppler'; break;
            default: $mapped_value = $category['value']; break;
        }

        if ($mapped_value === $scan_category_value) {
            $scan_type_display = $category['name']; // Use the full descriptive name from DB
            break;
        }
    }

    // --- Image Upload Handling ---
    if ($scan_category_value === 'obstetric' && isset($_FILES['obs_scan_image']) && $_FILES['obs_scan_image']['error'] === UPLOAD_ERR_OK) {
        $file_tmp_name = $_FILES['obs_scan_image']['tmp_name'];
        $file_name = uniqid('scan_') . '_' . basename($_FILES['obs_scan_image']['name']);
        $upload_dir = 'uploads/scans/'; // Ensure this directory exists and is writable
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $destination = $upload_dir . $file_name;

        if (move_uploaded_file($file_tmp_name, $destination)) {
            $scan_image_path = $destination;
        } else {
            $_SESSION['error_message'] = "Failed to upload image.";
        }
    }


    try {
        $pdo->beginTransaction();

        // --- Start: Insert into scan_orders first ---
        // Ensure scan_orders table has patient_id, order_date, scan_category, status columns
        $stmt_order = $pdo->prepare("INSERT INTO scan_orders (patient_id, order_date, scan_category, status) VALUES (?, ?, ?, ?)");
        $stmt_order->execute([
            $selected_patient_id,
            $scan_date, // Use the selected date from the form
            $scan_type_display, // Use the full descriptive name for scan_category in scan_orders
            'Completed' // Status can be 'Completed' as findings are being saved immediately
        ]);
        $new_scan_order_id = $pdo->lastInsertId();
        // --- End: Insert into scan_orders ---

        switch ($scan_category_value) { // Use $scan_category_value for matching form fields
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
                $twin1_efwt = sanitize_output($_POST['obs_twin1_efwt'] ?? ''); // Use null coalescing for optional fields
                $twin1_foetal_lie = sanitize_output($_POST['obs_twin1_foetal_lie'] ?? '');
                $twin1_presentation = sanitize_output($_POST['obs_twin1_presentation'] ?? '');
                $twin1_sex = sanitize_output($_POST['obs_twin1_sex'] ?? '');

                $twin2_efwt = sanitize_output($_POST['obs_twin2_efwt'] ?? '');
                $twin2_foetal_lie = sanitize_output($_POST['obs_twin2_foetal_lie'] ?? '');
                $twin2_presentation = sanitize_output($_POST['obs_twin2_presentation'] ?? '');
                $twin2_sex = sanitize_output($_POST['obs_twin2_sex'] ?? '');

                $findings_html = '
                    <p class="scan-info-line"><strong>U/ BLADDER:</strong> <span>' . $bladder . '</span></p>
                    <p class="scan-info-line"><strong>UTERUS:</strong> <span>' . $uterus . '</span></p>
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
                        </div>';
                    if (!empty($twin2_efwt)) { // Only show twin 2 if data is present
                        $findings_html .= '
                        <div class="scan-section">
                            <h4>TWIN 2</h4>
                            <p class="scan-info-line"><strong>E.F.W.T:</strong> <span>' . $twin2_efwt . '</span></p>
                            <p class="scan-info-line"><strong>FOETAL LIE:</strong> <span>' . $twin2_foetal_lie . '</span></p>
                            <p class="scan-info-line"><strong>PRESENTATION:</strong> <span>' . $twin2_presentation . '</span></p>
                            <p class="scan-info-line"><strong>SEX:</strong> <span>' . $twin2_sex . '</span></p>
                        </div>';
                    }
                } else {
                     $scan_type_display = 'Obstetric Scan (Single Fetus)'; // Refine display name if it's a single fetus
                }

                $findings_html .= '
                    <p class="scan-info-line"><strong>CERVIX:</strong> <span>' . $cervix . '</span></p>
                    <p class="scan-info-line"><strong>ADNEXAE:</strong> <span>' . $adnexae . '</span></p>
                ';
                // Move conclusion out of findings_html if it's stored separately in DB
                // Conclusion is now handled below the switch for all types
                break;

            case 'abdominopelvic':
                $scan_title = 'ABDOMINOPELVIC SCAN';
                $liver = sanitize_output($_POST['abdo_liver']);
                $heart = sanitize_output($_POST['abdo_heart']);
                $spleen = sanitize_output($_POST['abdo_spleen']);
                $pancreas = sanitize_output($_POST['abdo_pancreas']);
                $stomach = sanitize_output($_POST['abdo_stomach']);
                $kidneys = sanitize_output($_POST['abdo_kidneys']);
                $gallbladder = sanitize_output($_POST['abdo_gallbladder']);
                $peritoneal_cavity = sanitize_output($_POST['abdo_peritoneal_cavity']);
                $prostate = sanitize_output($_POST['abdo_prostate'] ?? '');
                $bladder = sanitize_output($_POST['abdo_bladder']);
                $uterus_abdo = sanitize_output($_POST['abdo_uterus'] ?? '');
                $crl = sanitize_output($_POST['abdo_crl'] ?? '');
                $ga = sanitize_output($_POST['abdo_ga'] ?? '');
                $edd = sanitize_output($_POST['abdo_edd'] ?? '');
                $amniotic_fluid = sanitize_output($_POST['abdo_amniotic_fluid'] ?? '');
                $ovaries = sanitize_output($_POST['abdo_ovaries'] ?? '');
                $adnexae_pod = sanitize_output($_POST['abdo_adnexae_pod'] ?? '');
                $conclusion = sanitize_output($_POST['abdo_conclusion']);

                $findings_html = '
                    <p class="scan-info-line"><strong>LIVER:</strong> <span>' . $liver . '</span></p>
                    <p class="scan-info-line"><strong>HEART:</strong> <span>' . $heart . '</span></p>
                    <p class="scan-info-line"><strong>SPLEEN:</strong> <span>' . $spleen . '</span></p>
                    <p class="scan-info-line"><strong>PANCREAS:</strong> <span>' . $pancreas . '</span></p>
                    <p class="scan-info-line"><strong>STOMACH:</strong> <span>' . $stomach . '</span></p>
                    <p class="scan-info-line"><strong>KIDNEYS:</strong> <span>' . $kidneys . '</span></p>
                    <p class="scan-info-line"><strong>GALLBLADDER:</strong> <span>' . $gallbladder . '</span></p>
                    <p class="scan-info-line"><strong>PERITONEAL CAVITY:</strong> <span>' . $peritoneal_cavity . '</span></p>
                    <p class="scan-info-line"><strong>BLADDER:</strong> <span>' . $bladder . '</span></p>
                ';
                if (!empty($prostate)) { // Specific to male abdominopelvic
                    $findings_html .= '<p class="scan-info-line"><strong>PROSTATE:</strong> <span>' . $prostate . '</span></p>';
                }
                if (!empty($uterus_abdo) || !empty($ovaries)) { // Specific to female abdominopelvic
                    $findings_html .= '
                        <p class="scan-info-line"><strong>UTERUS:</strong> <span>' . $uterus_abdo . '</span></p>
                        <p class="scan-info-line"><strong>CRL:</strong> <span>' . $crl . '</span></p>
                        <p class="scan-info-line"><strong>G.A:</strong> <span>' . $ga . '</span></p>
                        <p class="scan-info-line"><strong>EDD:</strong> <span>' . $edd . '</span></p>
                        <p class="scan-info-line"><strong>AMNIOTIC FLUID:</strong> <span>' . $amniotic_fluid . '</span></p>
                        <p class="scan-info-line"><strong>OVARIES:</strong> <span>' . $ovaries . '</span></p>
                        <p class="scan-info-line"><strong>ADNEXAE/POD:</strong> <span>' . $adnexae_pod . '</span></p>';
                }
                break;

            case 'neck':
                $scan_title = 'NECK USS';
                $soft_tissue = sanitize_output($_POST['neck_soft_tissue']);
                $thyroid = sanitize_output($_POST['neck_thyroid']);
                $conclusion = sanitize_output($_POST['neck_conclusion']);

                $findings_html = '';
                if (!empty($soft_tissue)) {
                    $findings_html .= '<p class="scan-info-line"><strong>SOFT TISSUE:</strong> <span>' . $soft_tissue . '</span></p>';
                }
                if (!empty($thyroid)) {
                    $findings_html .= '<p class="scan-info-line"><strong>THYROID:</strong> <span>' . $thyroid . '</span></p>';
                }
                break;

            case 'scrotal':
                $scan_title = 'SCROTAL USS';
                $scrotum_findings = sanitize_output($_POST['scrotal_findings']);
                $conclusion = sanitize_output($_POST['scrotal_conclusion']);
                $findings_html = '<p class="scan-info-line"><strong>SCROTUM:</strong> <span>' . $scrotum_findings . '</span></p>';
                break;

            case 'breast':
                $scan_title = 'BREAST SCAN';
                $right_breast = sanitize_output($_POST['breast_right']);
                $left_breast = sanitize_output($_POST['breast_left']);
                $conclusion = sanitize_output($_POST['breast_conclusion']);
                $findings_html = '
                    <p class="scan-info-line"><strong>RIGHT BREAST:</strong> <span>' . $right_breast . '</span></p>
                    <p class="scan-info-line"><strong>LEFT BREAST:</strong> <span>' . $left_breast . '</span></p>
                ';
                break;

            case 'abdominal':
                $scan_title = 'ABDOMINAL SCAN';
                $findings = sanitize_output($_POST['abdominal_findings']);
                $conclusion = sanitize_output($_POST['abdominal_conclusion']);
                $findings_html = '<p class="scan-info-line"><strong>Findings:</strong> <span>' . $findings . '</span></p>';
                break;

            case 'doppler_renal':
                $scan_title = 'DOPPLER RENAL SCAN';
                $findings = sanitize_output($_POST['doppler_renal_findings']);
                $conclusion = sanitize_output($_POST['doppler_renal_conclusion']);
                $findings_html = '<p class="scan-info-line"><strong>Findings:</strong> <span>' . $findings . '</span></p>';
                break;

            case 'cardiac_echo':
                $scan_title = 'CARDIAC ECHO SCAN';
                $findings = sanitize_output($_POST['cardiac_echo_findings']);
                $conclusion = sanitize_output($_POST['cardiac_echo_conclusion']);
                $findings_html = '<p class="scan-info-line"><strong>Findings:</strong> <span>' . $findings . '</span></p>';
                break;

            case 'cranial':
                $scan_title = 'CRANIAL SCAN';
                $findings = sanitize_output($_POST['cranial_findings']);
                $conclusion = sanitize_output($_POST['cranial_conclusion']);
                $findings_html = '<p class="scan-info-line"><strong>Findings:</strong> <span>' . $findings . '</span></p>';
                break;

            case 'follicular':
                $scan_title = 'FOLLICULAR SCAN';
                $findings = sanitize_output($_POST['follicular_findings']);
                $conclusion = sanitize_output($_POST['follicular_conclusion']);
                $findings_html = '<p class="scan-info-line"><strong>Findings:</strong> <span>' . $findings . '</span></p>';
                break;

            case 'musculoskeletal':
                $scan_title = 'MUSCULOSKELETAL SCAN';
                $findings = sanitize_output($_POST['musculoskeletal_findings']);
                $conclusion = sanitize_output($_POST['musculoskeletal_conclusion']);
                $findings_html = '<p class="scan-info-line"><strong>Findings:</strong> <span>' . $findings . '</span></p>';
                break;

            case 'pelvic':
                $scan_title = 'PELVIC SCAN';
                $findings = sanitize_output($_POST['pelvic_findings']);
                $conclusion = sanitize_output($_POST['pelvic_conclusion']);
                $findings_html = '<p class="scan-info-line"><strong>Findings:</strong> <span>' . $findings . '</span></p>';
                break;

            case 'prostate':
                $scan_title = 'PROSTATE SCAN';
                $findings = sanitize_output($_POST['prostate_findings']);
                $conclusion = sanitize_output($_POST['prostate_conclusion']);
                $findings_html = '<p class="scan-info-line"><strong>Findings:</strong> <span>' . $findings . '</span></p>';
                break;

            case 'renal':
                $scan_title = 'RENAL SCAN';
                $findings = sanitize_output($_POST['renal_findings']);
                $conclusion = sanitize_output($_POST['renal_conclusion']);
                $findings_html = '<p class="scan-info-line"><strong>Findings:</strong> <span>' . $findings . '</span></p>';
                break;

            case 'vascular_doppler':
                $scan_title = 'VASCULAR DOPPLER SCAN';
                $findings = sanitize_output($_POST['vascular_doppler_findings']);
                $conclusion = sanitize_output($_POST['vascular_doppler_conclusion']);
                $findings_html = '<p class="scan-info-line"><strong>Findings:</strong> <span>' . $findings . '</span></p>';
                break;
        }

        // Add conclusion to findings_html for all scan types
        $findings_html .= '<p class="scan-conclusion" style="color: ' . (strpos(strtolower($conclusion), 'iufd') !== false ? 'red' : 'inherit') . ';"><strong>CONCLUSION:</strong> <span>' . $conclusion . '</span></p>';


        // Insert new scan record into DB
        // IMPORTANT: Use $new_scan_order_id obtained from scan_orders insertion
        // Also, now include scan_image_path
        $stmt = $pdo->prepare("INSERT INTO scans (scan_order_id, patient_id, scan_title, findings, conclusion, performed_date, scan_image_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $new_scan_order_id, // Use the ID of the newly created scan order
            $selected_patient_id, // Store patient_id directly in scans table too for easier querying
            $scan_title,
            $findings_html,
            $conclusion,
            $scan_date,
            $scan_image_path
        ]);

        $pdo->commit();
        $_SESSION['success_message'] = "Scan record saved successfully!";
        header("Location: scan.php");
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
        error_log("Scan save error: " . $e->getMessage()); // Log the error
        header("Location: scan.php");
        exit;
    }
}

// --- Fetch All Scan Records from DB ---
$all_scan_records = [];
// Select scan_order_id (which stores patient_id) to link scan records to patients for display
// **IMPORTANT**: Added `s.patient_id` and `s.scan_image_path` to the SELECT query.
$stmt = $pdo->query("SELECT s.id, s.scan_order_id, s.scan_title, s.findings, s.conclusion, s.performed_date, s.scan_image_path,
                            p.patient_name, p.gender, p.dob, p.patient_display_id
                     FROM scans s
                     JOIN patients p ON s.patient_id = p.id
                     ORDER BY s.id DESC");
while ($row = $stmt->fetch()) {
    $row['age'] = ''; // Calculate age for display
    if (!empty($row['dob']) && $row['dob'] !== '0000-00-00') {
        $dob = new DateTime($row['dob']);
        $now = new DateTime();
        $row['age'] = $now->diff($dob)->y;
    }
    $all_scan_records[] = $row;
}

// --- Clinic details (these variables are no longer used in the modal display) ---
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <img src="./dist/img/favicon.ico" alt="Logo" class="brand-image img-circle elevation-3" style="opacity: .8; width: 30px; height: 30px; margin-right: 10px;">
    <title>Manage Scans - KDCS</title>

    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
    <link rel="stylesheet" href="plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css">
    <link rel="stylesheet" href="plugins/icheck-bootstrap/icheck-bootstrap.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <link rel="stylesheet" href="plugins/overlayScrollbars/css/OverlayScrollbars.min.css">
    <link rel="stylesheet" href="plugins/daterangepicker/daterangepicker.css">
    <link rel="stylesheet" href="plugins/summernote/summernote-bs4.css">
    <link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700" rel="stylesheet">
    <link rel="stylesheet" href="plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="plugins/datatables-responsive/css/responsive.bootstrap4.min.css">
    <style>
        .card-header {
            background-color:rgb(9, 108, 12);
            color: white;
        }
        .form-group label {
            font-weight: bold;
        }
        .scan-result-container {
            border: 1px solid #ddd;
            padding: 20px;
            margin-top: 20px;
            border-radius: 8px;
            background-color: #f9f9f9;
            min-height: 400px;
            position: relative;
        }
        .scan-watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 8em;
            color: rgba(0, 0, 0, 0.1);
            font-weight: bold;
            pointer-events: none;
            z-index: 0;
            white-space: nowrap;
        }
        .scan-content h4, .scan-content h5 {
            color: #000;
            margin-top: 15px;
            margin-bottom: 8px;
        }
        .scan-content h4 {
            font-size: 1.3em;
            border-bottom: 1px solid #ccc;
            padding-bottom: 5px;
        }
        .scan-content p {
            margin-bottom: 5px;
            line-height: 1.5;
        }
        .scan-conclusion {
            font-weight: bold;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px dashed #ccc;
        }
        .scan-title {
            text-align: center;
            font-weight: bold;
            margin-bottom: 15px;
            font-size: 1.2em;
            text-decoration: underline;
        }
        .patient-info-header {
            background-color: #e9ecef;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: bold;
            font-size: 1.1em;
        }
        .patient-info-header span {
            margin-right: 20px;
        }
        .scan-info-line {
            display: flex;
            justify-content: flex-start;
            margin-bottom: 5px;
            gap: 10px;
        }
        .scan-info-line strong {
            display: inline-block;
            min-width: 150px;
            flex-shrink: 0;
        }
        .scan-info-line span {
            flex-grow: 1;
        }
        .scan-section {
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
            background-color:rgb(249, 249, 249);
            margin-bottom: 20px;
        }
        .scan-section h4 {
            border-bottom: 2px solid rgb(7, 100, 13);
            padding-bottom: 5px;
            margin-bottom: 15px;
            color:rgb(8, 146, 27);
        }

        /* Styles for table-like input fields */
        .form-row-table {
            display: flex;
            align-items: center;
            margin-bottom: 10px; /* Spacing between rows */
        }
        .form-row-table label {
            flex: 0 0 180px; /* Fixed width for labels */
            margin-right: 10px;
            text-align: right;
        }
        .form-row-table .form-control-wrapper {
            flex-grow: 1;
        }
        .form-row-table .form-control {
            width: 100%; /* Ensure input takes full available width */
        }

        /* Image Upload Section Styles */
        .image-drop-area {
            border: 2px dashedrgb(16, 157, 70);
            background-color: #f8f9fa;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            border-radius: 8px;
            position: relative;
            min-height: 200px; /* Adjust as needed */
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        .image-drop-area p {
            font-size: 1.2em;
            color:rgb(13, 134, 57);
            margin-bottom: 10px;
        }
        .image-drop-area img {
            max-width: 100%;
            max-height: 250px; /* Limit image preview height */
            height: auto;
            margin-top: 15px;
            border-radius: 5px;
            box-shadow: 0 0 8px rgba(0,0,0,0.1);
            object-fit: contain; /* Ensure image fits without cropping */
        }
        .image-drop-area.drag-over {
            background-color: #e2f0ff;
            border-color:rgb(5, 136, 32);
        }
        .scan-image-display img {
            max-width: 100%;
            height: auto;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-top: 15px;
        }


        /* Print Specific Styles */
        @media print {
            body {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                margin: 0 !important;
                padding: 20px !important;
                box-sizing: border-box;
                font-family: Arial, sans-serif;
                font-size: 10pt;
                background-color: #ffffff !important; /* Ensure white background for print */
            }
            .wrapper, .content-wrapper, .main-footer, .main-header, .main-sidebar, .card-header, .card-body, .modal-footer, .modal-header .close, .no-print, .dt-buttons, #newRecordFormSection, #scanRecordsTable_wrapper .row:first-child {
                display: none !important;
            }
            .scan-result-container {
                border: none !important;
                padding: 0 !important;
                margin: 0 !important;
                background: none !important;
                min-height: auto !important;
                position: static !important;
            }
            .scan-result-container > div {
                display: block !important;
                margin: 0 auto;
                width: 100%;
                max-width: 800px;
                page-break-inside: avoid;
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
            /* REMOVED: .print-header-content */
            /* REMOVED: .print-header-content img */
            /* REMOVED: .print-header-content > div */
            /* REMOVED: .print-header-content h2 */
            /* REMOVED: .print-header-content p */
            .print-title {
                margin-top: 15px;
                color: #000;
                font-size: 1.5em;
                font-weight: bold;
                text-align: center;
                margin-bottom: 20px;
            }
            .patient-info-header {
                background-color: #f9f9f9 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                border: 1px solid #ccc;
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 10px 15px;
                border-radius: 5px;
                margin-bottom: 20px;
                font-weight: bold;
                font-size: 1.1em;
            }
            .patient-info-header span {
                margin-right: 20px;
            }
            .scan-info-line {
                display: flex;
                justify-content: flex-start;
                margin-bottom: 5px;
                gap: 10px;
            }
            .scan-info-line strong {
                display: inline-block;
                min-width: 150px;
                flex-shrink: 0;
            }
            .scan-info-line span {
                flex-grow: 1;
            }
            .scan-section {
                margin-bottom: 15px;
                padding-bottom: 10px;
                border-bottom: 1px dashed #eee;
                page-break-inside: avoid;
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
            }
             .scan-image-display {
                text-align: center;
                margin-top: 20px;
             }
            .scan-image-display img {
                max-width: 100%;
                height: auto;
                border: 1px solid #ddd;
                border-radius: 5px;
                margin-top: 15px;
            }
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed">
<div class="wrapper">

    <?php include './config/header.php'; ?>
    <?php include './config/sidebar.php'; ?>

    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1>Manage Scan Results</h1>
                    </div>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="card card-outline card-success rounded-0 shadow">
                <div class="card-header">
                    <h3 class="card-title">Add New Scan Record</h3>
                </div>
                <div class="card-body">
                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['error_message'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                    <?php endif; ?>

                    <form id="newScanRecordForm" method="POST" action="scan.php" enctype="multipart/form-data">
                        <input type="hidden" name="save_scan_record" value="1">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="newPatientSelect">Select Patient:</label>
                                    <select class="form-control" id="newPatientSelect" name="patient_id" required>
                                        <option value="">-- Select Patient --</option>
                                        <?php foreach ($patient_data as $id => $details): ?>
                                            <option value="<?php echo $id; ?>"
                                                    data-age="<?php echo sanitize_output($details['age']); ?>"
                                                    data-sex="<?php echo sanitize_output($details['sex']); ?>"
                                                    data-display-id="<?php echo sanitize_output($details['display_id']); ?>">
                                                <?php echo sanitize_output($details['name']); ?> (<?php echo sanitize_output($details['display_id']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="newScanCategorySelect">Select Scan Category:</label>
                                    <select class="form-control" id="newScanCategorySelect" name="scan_category" required>
                                        <option value="">-- Select Category --</option>
                                        <?php foreach ($scan_categories as $category):
                                            $option_value = '';
                                            switch (strtolower($category['name'])) {
                                                case 'obstetric scan': $option_value = 'obstetric'; break;
                                                case 'abdominopelvic scan': $option_value = 'abdominopelvic'; break;
                                                case 'neck scan': $option_value = 'neck'; break;
                                                case 'scrotal uss': $option_value = 'scrotal'; break;
                                                case 'breast scan': $option_value = 'breast'; break;
                                                case 'abdominal scan': $option_value = 'abdominal'; break;
                                                case 'doppler renal scan': $option_value = 'doppler_renal'; break;
                                                case 'cardiac echo scan': $option_value = 'cardiac_echo'; break;
                                                case 'cranial scan': $option_value = 'cranial'; break;
                                                case 'follicular scan': $option_value = 'follicular'; break;
                                                case 'musculoskeletal scan': $option_value = 'musculoskeletal'; break;
                                                case 'pelvic scan': $option_value = 'pelvic'; break;
                                                case 'prostate scan': $option_value = 'prostate'; break;
                                                case 'renal scan': $option_value = 'renal'; break;
                                                case 'vascular doppler scan': $option_value = 'vascular_doppler'; break;
                                                default: $option_value = $category['value']; break;
                                            }
                                        ?>
                                            <option value="<?php echo sanitize_output($option_value); ?>">
                                                <?php echo sanitize_output($category['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                             <div class="col-md-4">
                                <div class="form-group">
                                    <label for="scanDate">Scan Date:</label>
                                    <input type="date" class="form-control" id="scanDate" name="scan_date" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                        </div>

                        <div id="dynamicScanFields" style="display:none;">
                            </div>

                        <div class="row">
                            <div class="col-12 text-right">
                                <button type="submit" class="btn btn-success" id="saveScanButton" disabled>Save Scan Record</button>
                                <button type="reset" class="btn btn-secondary">Clear Form</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card card-outline card-success rounded-0 shadow mt-4">
                <div class="card-header">
                    <h3 class="card-title">Existing Scan Records</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="scanRecordsTable" class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Patient ID</th>
                                    <th>Patient Name</th>
                                    <th>Age</th>
                                    <th>Sex</th>
                                    <th>Scan Title</th>
                                    <th>Scan Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach ($all_scan_records as $record): ?>
                                    <tr>
                                        <td><?php echo $i++; ?></td>
                                        <td><?php echo sanitize_output($record['patient_display_id']); ?></td>
                                        <td><?php echo sanitize_output($record['patient_name']); ?></td>
                                        <td><?php echo sanitize_output($record['age']); ?></td>
                                        <td><?php echo sanitize_output($record['gender']); ?></td>
                                        <td><?php echo sanitize_output($record['scan_title']); ?></td>
                                        <td><?php echo sanitize_output($record['performed_date']); ?></td>
                                        <td>
                                            <button class="btn btn-info btn-sm view-scan-btn"
                                                    data-id="<?php echo $record['id']; ?>"
                                                    data-patient-name="<?php echo sanitize_output($record['patient_name']); ?>"
                                                    data-patient-age="<?php echo sanitize_output($record['age']); ?>"
                                                    data-patient-sex="<?php echo sanitize_output($record['gender']); ?>"
                                                    data-patient-display-id="<?php echo sanitize_output($record['patient_display_id']); ?>"
                                                    data-scan-date="<?php echo sanitize_output($record['performed_date']); ?>"
                                                    data-scan-title="<?php echo sanitize_output($record['scan_title']); ?>"
                                                    data-findings="<?php echo base64_encode($record['findings']); // Base64 encode findings to pass safely ?>"
                                                    data-image-path="<?php echo sanitize_output($record['scan_image_path']); ?>"
                                                    title="View Scan Details">
                                                <i class="fa fa-eye"></i> View
                                            </button>
                                            <button class="btn btn-primary btn-sm print-scan-btn"
                                                    data-id="<?php echo $record['id']; ?>"
                                                    data-patient-name="<?php echo sanitize_output($record['patient_name']); ?>"
                                                    data-patient-age="<?php echo sanitize_output($record['age']); ?>"
                                                    data-patient-sex="<?php echo sanitize_output($record['gender']); ?>"
                                                    data-patient-display-id="<?php echo sanitize_output($record['patient_display_id']); ?>"
                                                    data-scan-date="<?php echo sanitize_output($record['performed_date']); ?>"
                                                    data-scan-title="<?php echo sanitize_output($record['scan_title']); ?>"
                                                    data-findings="<?php echo base64_encode($record['findings']); ?>"
                                                    data-image-path="<?php echo sanitize_output($record['scan_image_path']); ?>"
                                                    title="Print Scan Result">
                                                <i class="fa fa-print"></i> Print
                                            </button>
                                            <button class="btn btn-danger btn-sm delete-scan-btn"
                                                    data-id="<?php echo $record['id']; ?>"
                                                    title="Delete Scan Record">
                                                <i class="fa fa-trash"></i> Delete
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <?php include './config/footer.php'; ?>
</div>

<div class="modal fade" id="viewScanModal" tabindex="-1" role="dialog" aria-labelledby="viewScanModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="viewScanModalLabel">Scan Result Details</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="print-area">
                <div id="viewScanContent">
                    <h3 class="print-title">ULTRASOUND SCAN REPORT</h3>

                    <div class="patient-info-header">
                        <span id="modalPatientName"></span>
                        <span id="modalPatientAge"></span>
                        <span id="modalPatientSex"></span>
                        <span id="modalPatientID"></span>
                    </div>

                    <p style="text-align: right;"><strong>Date:</strong> <span id="modalScanDate"></span></p>

                    <div id="modalScanDetails">
                        </div>
                    </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" id="printModalScanResult"><i class="fa fa-print"></i> Print</button>
            </div>
        </div>
    </div>
</div>


<script src="plugins/jquery/jquery.min.js"></script>
<script src="plugins/jquery-ui/jquery-ui.min.js"></script>
<script>
$.widget.bridge('uibutton', $.ui.button)
</script>
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="plugins/chart.js/Chart.min.js"></script>
<script src="plugins/sparklines/sparkline.js"></script>
<script src="plugins/jqvmap/jquery.vmap.min.js"></script>
<script src="plugins/jqvmap/maps/jquery.vmap.usa.js"></script>
<script src="plugins/jquery-knob/jquery.knob.min.js"></script>
<script src="plugins/moment/moment.min.js"></script>
<script src="plugins/daterangepicker/daterangepicker.js"></script>
<script src="plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js"></script>
<script src="plugins/summernote/summernote-bs4.js"></script> <script src="plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js"></script>
<script src="dist/js/adminlte.js"></script>
<script src="dist/js/pages/dashboard.js"></script>
<script src="dist/js/demo.js"></script>
<script src="plugins/datatables/jquery.dataTables.min.js"></script>
<script src="plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
<script src="plugins/datatables-responsive/js/dataTables.responsive.min.js"></script>
<script src="plugins/datatables-responsive/js/responsive.bootstrap4.min.js"></script>

<script>
    // Custom function for sidebar highlighting.
    function showMenuSelected(menuId, submenuId) {
        $(menuId).addClass('active');
        $(submenuId).addClass('active');
        $(menuId).closest('.has-treeview').addClass('menu-open');
    }

    $(document).ready(function() {
        showMenuSelected("#mnu_scanning", "#mi_manage_scans");

        // Initialize DataTable for existing records
        $('#scanRecordsTable').DataTable({
            "paging": true,
            "lengthChange": true,
            "searching": true,
            "ordering": true,
            "info": true,
            "autoWidth": false,
            "responsive": true,
            "order": [[0, "asc"]]
        });

        // --- New Record Form Logic ---
        const $newPatientSelect = $('#newPatientSelect');
        const $newScanCategorySelect = $('#newScanCategorySelect');
        const $dynamicScanFields = $('#dynamicScanFields');
        const $saveScanButton = $('#saveScanButton');

        function createFormRow(label, name, type = 'text', placeholder = '', isTextArea = false) {
            let inputElement;
            if (isTextArea) {
                inputElement = `<textarea name="${name}" class="form-control" placeholder="${placeholder}" rows="3"></textarea>`;
            } else {
                inputElement = `<input type="${type}" name="${name}" class="form-control" placeholder="${placeholder}">`;
            }
            return `
                <div class="form-row-table">
                    <label>${label}:</label>
                    <div class="form-control-wrapper">
                        ${inputElement}
                    </div>
                </div>
            `;
        }

        // Existing forms
        function generateObstetricFields() {
            return `
                <div class="row">
                    <div class="col-md-6">
                        <div class="scan-section">
                            <h4>Obstetric Scan Details</h4>
                            ${createFormRow('U/ BLADDER', 'obs_u_bladder', 'text', 'Enter U/ Bladder findings')}
                            ${createFormRow('UTERUS', 'obs_uterus', 'text', 'Enter Uterus findings')}
                            ${createFormRow('FL', 'obs_fl', 'text', 'Enter Foetal Length (e.g., 62.9mm)')}
                            ${createFormRow('FOETAL AGE', 'obs_foetal_age', 'text', 'Enter Foetal Age (e.g., 32wks OD)')}
                            ${createFormRow('EDD SCAN', 'obs_edd_scan', 'text', 'Enter EDD Scan (e.g., 17-03-2025 ±2wks)')}
                            ${createFormRow('FLUID VOLUME', 'obs_fluid_vol', 'text', 'Enter Fluid Volume (e.g., Adequate)')}
                            ${createFormRow('F.H.RATE', 'obs_fh_rate', 'text', 'Enter F.H.Rate (e.g., Normal)')}
                            ${createFormRow('PLACENTA', 'obs_placenta', 'text', 'Enter Placenta findings (e.g., Posterior)')}
                            ${createFormRow('F.LIE', 'obs_f_lie', 'text', 'Enter Foetal Lie (e.g., Longitudinal)')}
                            ${createFormRow('PRESENTATION', 'obs_presentation', 'text', 'Enter Presentation (e.g., Cephalic)')}
                            ${createFormRow('E.F.W.T', 'obs_ef_weight', 'text', 'Enter E.F.W.T (e.g., 2.0kg)')}
                            ${createFormRow('SEX', 'obs_sex', 'text', 'Enter Sex (e.g., Male)')}
                            ${createFormRow('CERVIX', 'obs_cervix', 'text', 'Enter Cervix findings (e.g., The Internal OS is closed)')}
                            ${createFormRow('ADNEXAE', 'obs_adnexae', 'text', 'Enter Adnexae findings (e.g., Free)')}

                            <hr>
                            <h5>Twin 1 (Optional)</h5>
                            ${createFormRow('Twin 1 E.F.W.T', 'obs_twin1_efwt', 'text', 'Enter Twin 1 E.F.W.T')}
                            ${createFormRow('Twin 1 FOETAL LIE', 'obs_twin1_foetal_lie', 'text', 'Enter Twin 1 Foetal Lie')}
                            ${createFormRow('Twin 1 PRESENTATION', 'obs_twin1_presentation', 'text', 'Enter Twin 1 Presentation')}
                            ${createFormRow('Twin 1 SEX', 'obs_twin1_sex', 'text', 'Enter Twin 1 Sex')}
                            <hr>
                            <h5>Twin 2 (Optional)</h5>
                            ${createFormRow('Twin 2 E.F.W.T', 'obs_twin2_efwt', 'text', 'Enter Twin 2 E.F.W.T')}
                            ${createFormRow('Twin 2 FOETAL LIE', 'obs_twin2_foetal_lie', 'text', 'Enter Twin 2 Foetal Lie')}
                            ${createFormRow('Twin 2 PRESENTATION', 'obs_twin2_presentation', 'text', 'Enter Twin 2 Presentation')}
                            ${createFormRow('Twin 2 SEX', 'obs_twin2_sex', 'text', 'Enter Twin 2 Sex')}
                            <hr>
                            ${createFormRow('CONCLUSION', 'obs_conclusion', 'text', 'Enter Conclusion (e.g., VIABLE 3RD TRIMESTER GESTATION)', true)}
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="scan-section">
                            <h4>Scan Image</h4>
                            <div class="image-drop-area" id="obsImageDropArea">
                                <p>Drag Picture Here</p>
                                <input type="file" name="obs_scan_image" id="obsScanImageInput" accept="image/*" style="display: none;">
                                <button type="button" class="btn btn-info mt-2" id="browseImageBtn"><i class="fa fa-folder-open"></i> Browse Image</button>
                                <div id="imagePreview" class="mt-3" style="max-width: 100%; overflow: hidden;">
                                    <img src="" alt="Image Preview" style="max-width: 100%; height: auto; display: none;">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        function generateAbdominopelvicFields() {
            return `
                <div class="scan-section">
                    <h4>Abdominopelvic Scan Details</h4>
                    ${createFormRow('LIVER', 'abdo_liver', 'text', 'Enter Liver findings')}
                    ${createFormRow('HEART', 'abdo_heart', 'text', 'Enter Heart findings')}
                    ${createFormRow('SPLEEN', 'abdo_spleen', 'text', 'Enter Spleen findings')}
                    ${createFormRow('PANCREAS', 'abdo_pancreas', 'text', 'Enter Pancreas findings')}
                    ${createFormRow('STOMACH', 'abdo_stomach', 'text', 'Enter Stomach findings')}
                    ${createFormRow('KIDNEYS', 'abdo_kidneys', 'text', 'Enter Kidneys findings')}
                    ${createFormRow('GALLBLADDER', 'abdo_gallbladder', 'text', 'Enter Gallbladder findings')}
                    ${createFormRow('PERITONEAL CAVITY', 'abdo_peritoneal_cavity', 'text', 'Enter Peritoneal Cavity findings')}
                    <hr>
                    <h5>PELVIC (For Male)</h5>
                    ${createFormRow('PROSTATE', 'abdo_prostate', 'text', 'Enter Prostate findings')}
                    <hr>
                    <h5>PELVIC (For Female)</h5>
                    ${createFormRow('UTERUS', 'abdo_uterus', 'text', 'Enter Uterus findings')}
                    ${createFormRow('CRL', 'abdo_crl', 'text', 'Enter Crown-Rump Length')}
                    ${createFormRow('G.A', 'abdo_ga', 'text', 'Enter Gestational Age')}
                    ${createFormRow('EDD', 'abdo_edd', 'text', 'Enter Estimated Due Date')}
                    ${createFormRow('AMNIOTIC FLUID', 'abdo_amniotic_fluid', 'text', 'Enter Amniotic Fluid')}
                    ${createFormRow('OVARIES', 'abdo_ovaries', 'text', 'Enter Ovaries findings')}
                    ${createFormRow('ADNEXAE/POD', 'abdo_adnexae_pod', 'text', 'Enter Adnexae/POD findings')}
                    <hr>
                    ${createFormRow('BLADDER', 'abdo_bladder', 'text', 'Enter Bladder findings')}
                    ${createFormRow('CONCLUSIONS', 'abdo_conclusion', 'text', 'Enter Conclusion', true)}
                </div>
            `;
        }

        function generateNeckFields() {
            return `
                <div class="scan-section">
                    <h4>Neck Scan Details</h4>
                    ${createFormRow('SOFT TISSUE', 'neck_soft_tissue', 'text', 'Enter Soft Tissue findings')}
                    ${createFormRow('THYROID', 'neck_thyroid', 'text', 'Enter Thyroid findings')}
                    ${createFormRow('CONCLUSION', 'neck_conclusion', 'text', 'Enter Conclusion', true)}
                </div>
            `;
        }

        function generateScrotalFields() {
            return `
                <div class="scan-section">
                    <h4>Scrotal Scan Details</h4>
                    ${createFormRow('SCROTUM FINDINGS', 'scrotal_findings', 'text', 'Enter Scrotal findings')}
                    ${createFormRow('CONCLUSION', 'scrotal_conclusion', 'text', 'Enter Conclusion', true)}
                </div>
            `;
        }

        function generateBreastFields() {
            return `
                <div class="scan-section">
                    <h4>Breast Scan Details</h4>
                    ${createFormRow('RIGHT BREAST', 'breast_right', 'text', 'Enter Right Breast findings')}
                    ${createFormRow('LEFT BREAST', 'breast_left', 'text', 'Enter Left Breast findings')}
                    ${createFormRow('CONCLUSION', 'breast_conclusion', 'text', 'Enter Conclusion', true)}
                </div>
            `;
        }

        function generateAbdominalFields() {
            return `
                <div class="scan-section">
                    <h4>Abdominal Scan Details</h4>
                    ${createFormRow('Findings', 'abdominal_findings', 'text', 'Enter abdominal scan findings', true)}
                    ${createFormRow('Conclusion', 'abdominal_conclusion', 'text', 'Enter conclusion', true)}
                </div>
            `;
        }

        function generateDopplerRenalFields() {
            return `
                <div class="scan-section">
                    <h4>Doppler Renal Scan Details</h4>
                    ${createFormRow('Findings', 'doppler_renal_findings', 'text', 'Enter doppler renal scan findings', true)}
                    ${createFormRow('Conclusion', 'doppler_renal_conclusion', 'text', 'Enter conclusion', true)}
                </div>
            `;
        }

        function generateCardiacEchoFields() {
            return `
                <div class="scan-section">
                    <h4>Cardiac Echo Scan Details</h4>
                    ${createFormRow('Findings', 'cardiac_echo_findings', 'text', 'Enter cardiac echo scan findings', true)}
                    ${createFormRow('Conclusion', 'cardiac_echo_conclusion', 'text', 'Enter conclusion', true)}
                </div>
            `;
        }

        function generateCranialFields() {
            return `
                <div class="scan-section">
                    <h4>Cranial Scan Details</h4>
                    ${createFormRow('Findings', 'cranial_findings', 'text', 'Enter cranial scan findings', true)}
                    ${createFormRow('Conclusion', 'cranial_conclusion', 'text', 'Enter conclusion', true)}
                </div>
            `;
        }

        function generateFollicularFields() {
            return `
                <div class="scan-section">
                    <h4>Follicular Scan Details</h4>
                    ${createFormRow('Findings', 'follicular_findings', 'text', 'Enter follicular scan findings', true)}
                    ${createFormRow('Conclusion', 'follicular_conclusion', 'text', 'Enter conclusion', true)}
                </div>
            `;
        }

        function generateMusculoskeletalFields() {
            return `
                <div class="scan-section">
                    <h4>Musculoskeletal Scan Details</h4>
                    ${createFormRow('Findings', 'musculoskeletal_findings', 'text', 'Enter musculoskeletal scan findings', true)}
                    ${createFormRow('Conclusion', 'musculoskeletal_conclusion', 'text', 'Enter conclusion', true)}
                </div>
            `;
        }

        function generatePelvicFields() {
            return `
                <div class="scan-section">
                    <h4>Pelvic Scan Details</h4>
                    ${createFormRow('Findings', 'pelvic_findings', 'text', 'Enter pelvic scan findings', true)}
                    ${createFormRow('Conclusion', 'pelvic_conclusion', 'text', 'Enter conclusion', true)}
                </div>
            `;
        }

        function generateProstateFields() {
            return `
                <div class="scan-section">
                    <h4>Prostate Scan Details</h4>
                    ${createFormRow('Findings', 'prostate_findings', 'text', 'Enter prostate scan findings', true)}
                    ${createFormRow('Conclusion', 'prostate_conclusion', 'text', 'Enter conclusion', true)}
                </div>
            `;
        }

        function generateRenalFields() {
            return `
                <div class="scan-section">
                    <h4>Renal Scan Details</h4>
                    ${createFormRow('Findings', 'renal_findings', 'text', 'Enter renal scan findings', true)}
                    ${createFormRow('Conclusion', 'renal_conclusion', 'text', 'Enter conclusion', true)}
                </div>
            `;
        }

        function generateVascularDopplerFields() {
            return `
                <div class="scan-section">
                    <h4>Vascular Doppler Scan Details</h4>
                    ${createFormRow('Findings', 'vascular_doppler_findings', 'text', 'Enter vascular doppler scan findings', true)}
                    ${createFormRow('Conclusion', 'vascular_doppler_conclusion', 'text', 'Enter conclusion', true)}
                </div>
            `;
        }

        $newScanCategorySelect.on('change', function() {
            const selectedCategory = $(this).val();
            $dynamicScanFields.empty(); // Clear previous fields
            $saveScanButton.prop('disabled', true); // Disable save button initially

            if (selectedCategory) {
                switch (selectedCategory) {
                    case 'obstetric':
                        $dynamicScanFields.html(generateObstetricFields());
                        // Attach image upload handlers for Obstetric Scan
                        attachImageUploadHandlers();
                        break;
                    case 'abdominopelvic':
                        $dynamicScanFields.html(generateAbdominopelvicFields());
                        break;
                    case 'neck':
                        $dynamicScanFields.html(generateNeckFields());
                        break;
                    case 'scrotal':
                        $dynamicScanFields.html(generateScrotalFields());
                        break;
                    case 'breast':
                        $dynamicScanFields.html(generateBreastFields());
                        break;
                    case 'abdominal':
                        $dynamicScanFields.html(generateAbdominalFields());
                        break;
                    case 'doppler_renal':
                        $dynamicScanFields.html(generateDopplerRenalFields());
                        break;
                    case 'cardiac_echo':
                        $dynamicScanFields.html(generateCardiacEchoFields());
                        break;
                    case 'cranial':
                        $dynamicScanFields.html(generateCranialFields());
                        break;
                    case 'follicular':
                        $dynamicScanFields.html(generateFollicularFields());
                        break;
                    case 'musculoskeletal':
                        $dynamicScanFields.html(generateMusculoskeletalFields());
                        break;
                    case 'pelvic':
                        $dynamicScanFields.html(generatePelvicFields());
                        break;
                    case 'prostate':
                        $dynamicScanFields.html(generateProstateFields());
                        break;
                    case 'renal':
                        $dynamicScanFields.html(generateRenalFields());
                        break;
                    case 'vascular_doppler':
                        $dynamicScanFields.html(generateVascularDopplerFields());
                        break;
                    default:
                        // No fields
                        break;
                }
                $dynamicScanFields.show();
                $saveScanButton.prop('disabled', false); // Enable save button
            } else {
                $dynamicScanFields.hide();
            }
        });

        // Function to attach image upload handlers
        function attachImageUploadHandlers() {
            // Click on browse button
            $dynamicScanFields.on('click', '#browseImageBtn', function() {
                $('#obsScanImageInput').click();
            });

            // Handle file selection and preview
            $dynamicScanFields.on('change', '#obsScanImageInput', function() {
                const file = this.files[0];
                const $imagePreview = $(this).closest('.image-drop-area').find('#imagePreview img');
                const $imagePreviewDiv = $(this).closest('.image-drop-area').find('#imagePreview');

                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        $imagePreview.attr('src', e.target.result).show();
                        $imagePreviewDiv.show();
                    };
                    reader.readAsDataURL(file);
                } else {
                    $imagePreview.attr('src', '').hide();
                    $imagePreviewDiv.hide();
                }
            });

            // Drag and drop functionality
            $dynamicScanFields.on('dragover', '#obsImageDropArea', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).addClass('drag-over');
            });

            $dynamicScanFields.on('dragleave', '#obsImageDropArea', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('drag-over');
            });

            $dynamicScanFields.on('drop', '#obsImageDropArea', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('drag-over');

                const files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) {
                    $('#obsScanImageInput')[0].files = files; // Set files to the input
                    $('#obsScanImageInput').trigger('change'); // Trigger change event for preview
                }
            });
        }


        // Enable save button if both patient and category are selected
        function toggleSaveButton() {
            if ($newPatientSelect.val() && $newScanCategorySelect.val()) {
                $saveScanButton.prop('disabled', false);
            } else {
                $saveScanButton.prop('disabled', true);
            }
        }
        $newPatientSelect.on('change', toggleSaveButton);
        $newScanCategorySelect.on('change', toggleSaveButton);
        // Initial check on page load
        toggleSaveButton();


        // --- View/Print Existing Records Logic ---
        $('.view-scan-btn').on('click', function() {
            const data = $(this).data(); // Get all data attributes
            populateAndShowModal(data);
        });

        // Handler for the new Print button directly in the table
        $('.print-scan-btn').on('click', function() {
            const data = $(this).data();
            populateAndShowModal(data, true); // Pass true to indicate direct print
        });

        function populateAndShowModal(data, directPrint = false) {
            $('#modalPatientName').text(`PATIENT NAME: ${data.patientName || 'N/A'}`);
            $('#modalPatientAge').text(`AGE: ${data.patientAge || 'N/A'}`);
            $('#modalPatientSex').text(`SEX: ${data.patientSex || 'N/A'}`);
            $('#modalPatientID').text(`CLINIC ID: ${data.patientDisplayId || 'N/A'}`);
            
            $('#modalScanDate').text(data.scanDate);

            // Decode the base64 encoded findings
            const decodedFindings = atob(data.findings);
            const imagePath = data.imagePath; // Get image path

            let imageHtml = '';
            if (imagePath) {
                imageHtml = `<div class="scan-image-display"><img src="${imagePath}" alt="Scan Image"></div>`;
            }

            $('#modalScanDetails').html(`
                <div class="scan-section">
                    <h4 class="scan-title">${data.scanTitle}</h4>
                    ${decodedFindings}
                    ${imageHtml}
                </div>
            `);

            if (directPrint) {
                // If direct print, open modal and then immediately trigger print
                $('#viewScanModal').modal('show');
                $('#viewScanModal').on('shown.bs.modal', function () {
                    triggerPrint();
                    $('#viewScanModal').off('shown.bs.modal'); // Remove handler to prevent multiple triggers
                });
            } else {
                $('#viewScanModal').modal('show');
            }
        }


        // Handle "Print" button within the modal
        $('#printModalScanResult').on('click', function() {
            triggerPrint();
        });

        function triggerPrint() {
            var contentToPrint = $('#viewScanContent').html();
            var printWindow = window.open('', '_blank');

            printWindow.document.write('<html><head><title>Scan Result Print</title>');
            printWindow.document.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">');
            printWindow.document.write('<style>');
            // Embed all necessary print CSS directly
            printWindow.document.write(`
                body {
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                    margin: 0 !important;
                    padding: 20px !important;
                    box-sizing: border-box;
                    font-family: Arial, sans-serif;
                    font-size: 10pt;
                }
                h4, h5 { color: #000 !important; }
                h4 { text-align: center; margin-bottom: 15px; font-size: 1.5em; }
                h5 { border-bottom: 1px solid #ddd; padding-bottom: 8px; margin-bottom: 15px; font-size: 1.2em; }
                p { margin-bottom: 5px; line-height: 1.4; }

                .print-title {
                    margin-top: 15px;
                    color: #000;
                    font-size: 1.5em;
                    font-weight: bold;
                    text-align: center;
                    margin-bottom: 20px;
                }
                .patient-info-header {
                    background-color: #f9f9f9 !important;
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                    border: 1px solid #ccc;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 10px 15px;
                    border-radius: 5px;
                    margin-bottom: 20px;
                    font-weight: bold;
                    font-size: 1.1em;
                }
                .patient-info-header span {
                    margin-right: 20px;
                }
                .scan-info-line {
                    display: flex;
                    justify-content: flex-start;
                    margin-bottom: 5px;
                    gap: 10px;
                }
                .scan-info-line strong {
                    display: inline-block;
                    min-width: 150px;
                    flex-shrink: 0;
                }
                .scan-info-line span {
                    flex-grow: 1;
                }
                .scan-section {
                    margin-bottom: 15px;
                    padding-bottom: 10px;
                    border-bottom: 1px dashed #eee;
                    page-break-inside: avoid;
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
                }
                 .scan-image-display {
                    text-align: center;
                    margin-top: 20px;
                 }
                .scan-image-display img {
                    max-width: 100%;
                    height: auto;
                    border: 1px solid #ddd;
                    border-radius: 5px;
                    margin-top: 15px;
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

        // --- Delete Scan Record Logic ---
        $(document).on('click', '.delete-scan-btn', function() {
            const scanId = $(this).data('id');
            if (confirm('Are you sure you want to delete this scan record? This action cannot be undone.')) {
                // Create a form dynamically and submit it
                const form = $('<form>', {
                    'action': 'scan.php',
                    'method': 'POST',
                    'style': 'display:none;'
                }).append($('<input>', {
                    'type': 'hidden',
                    'name': 'delete_scan_record',
                    'value': '1'
                })).append($('<input>', {
                    'type': 'hidden',
                    'name': 'scan_id',
                    'value': scanId
                }));
                $('body').append(form);
                form.submit();
            }
        });
    });
</script>
</body>
</html>