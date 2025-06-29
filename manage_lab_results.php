<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("location:index.php");
    exit;
}

// Enable detailed error reporting for development (REMOVE IN PRODUCTION)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// =================================================================
// EMBEDDED DATABASE CONNECTION
// REPLACE WITH YOUR ACTUAL DATABASE CREDENTIALS
// =================================================================
$db_host = 'localhost'; // Your database host
$db_name = 'pms_db';    // Your database name
$db_user = 'root';      // Your database username
$db_pass = '';          // Your database password

try {
    $con = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // Return a JSON error for AJAX requests
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()]);
        exit;
    } else {
        die("Connection failed: " . $e->getMessage());
    }
}
// =================================================================

// --- Fetch Clinic Details by reading index.php content ---
$clinic_name = "KDCS Clinic (Default)";
$clinic_email = "info@kdcsclinic.com (Default)";
$clinic_address = "Address Not Found (Default)";
$clinic_phone = "Phone Not Found (Default)";

// Attempt to fetch clinic details from index.php
// This method is less robust and relies on specific HTML structure in index.php
// A more robust way would be to store these in a config file or dedicated DB table.
$index_content = file_get_contents('index.php');

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
// --- END Fetch Clinic Details ---


// --- Function to sanitize input data ---
function sanitize_input($data) {
    if ($data === null) {
        return '';
    }
    return htmlspecialchars(stripslashes(trim(strval($data))));
}

// --- Function to calculate age from DOB ---
function calculate_age($dob) {
    if (empty($dob) || $dob == '0000-00-00' || $dob === null) {
        return 'N/A';
    }
    try {
        $birthDate = new DateTime($dob);
        $today = new DateTime('today');
        $age = $birthDate->diff($today)->y;
        return $age . ' years';
    } catch (Exception $e) {
        return 'Invalid Date';
    }
}


// --- Handle DELETE Operation ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id_to_delete = filter_var($_GET['id'], FILTER_SANITIZE_NUMBER_INT);
    if ($id_to_delete) {
        try {
            $stmt = $con->prepare("DELETE FROM `lab_results` WHERE `id` = :id");
            $stmt->bindParam(':id', $id_to_delete, PDO::PARAM_INT);
            $stmt->execute();
            $_SESSION['success_message'] = "Lab result deleted successfully!";
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error deleting lab result: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "Invalid ID for deletion.";
    }
    header("location:manage_lab_results.php");
    exit;
}

// --- Handle FETCH for EDIT/VIEW Operation (AJAX request) ---
if (isset($_GET['action']) && $_GET['action'] === 'fetch_single' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id_to_fetch = filter_var($_GET['id'], FILTER_SANITIZE_NUMBER_INT);
    if ($id_to_fetch) {
        try {
            $stmt = $con->prepare("
                SELECT
                    lr.*,
                    p.patient_name,
                    p.patient_display_id,
                    p.dob,
                    p.gender,
                    p.marital_status,
                    p.contact_no,
                    (SELECT pv.weight FROM patient_visits pv WHERE pv.patient_id = p.id ORDER BY pv.visit_date DESC, pv.id DESC LIMIT 1) AS last_weight,
                    u.display_name AS ordered_by_user_name
                FROM
                    `lab_results` lr
                JOIN
                    `patients` p ON lr.patient_id = p.id
                LEFT JOIN
                    `users` u ON lr.ordered_by_user_id = u.id
                WHERE lr.id = :id
            ");
            $stmt->bindParam(':id', $id_to_fetch, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                $default_values = [
                    'haematology_pcv' => '', 'haematology_blood_group' => '', 'haematology_genotype' => '', 'haematology_esr' => '', 'haematology_wbc' => '',
                    'bacteriology_h_pylori' => '',
                    'parasitology_mps' => '', 'parasitology_skin_snip' => '', 'parasitology_wet_prep' => '',
                    'virology_hbsag' => '', 'virology_hcv' => '', 'virology_rvs' => '', 'virology_syphilis' => '',
                    'clinical_chemistry_rbs' => '', 'clinical_chemistry_serum_pt' => '',
                    'urinalysis_blood' => '', 'urinalysis_ketone' => '', 'urinalysis_glucose' => '', 'urinalysis_protein' => '', 'urinalysis_leucocytes' => '', 'urinalysis_ascorbic_acid' => '', 'urinalysis_urobilinogen' => '', 'urinalysis_ph' => '', 'urinalysis_nitrite' => '', 'urinalysis_bilirubin' => '',
                    'urine_microscopy_notes' => '',
                    'widal_ao_1' => '', 'widal_bo_1' => '', 'widal_co_1' => '', 'widal_do_1' => '', 'widal_ah_1' => '', 'widal_bh_1' => '', 'widal_ch_1' => '', 'widal_dh_1' => '',
                    'stool_macroscopy_notes' => '', 'stool_microscopy_notes' => '',
                    'comment_notes' => '',
                    // Semen Fluid Analysis default values
                    'semen_production_time' => '',
                    'semen_collection_time' => '',
                    'semen_examination_time' => '',
                    'semen_volume' => '',
                    'semen_ph' => '',
                    'semen_appearance' => '',
                    'semen_viscosity' => '',
                    'semen_pus_cell' => '',
                    'semen_bacterial_cells' => '',
                    'semen_e_coli' => '',
                    'semen_morphology_normal' => '',
                    'semen_morphology_abnormal' => '',
                    'result_date' => date('Y-m-d H:i:s'),
                    'gender' => '', 'marital_status' => '', 'contact_no' => '', 'last_weight' => ''
                ];
                $result = array_merge($default_values, $result);

                $result['patient_age'] = calculate_age($result['dob']);
                echo json_encode(['status' => 'success', 'data' => $result]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Record not found.']);
            }
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error during fetch: ' . $e->getMessage()]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Server-side processing error: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid ID.']);
    }
    exit;
}

// --- Handle UPDATE Operation ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_lab_result'])) {
    $id_to_update = filter_var($_POST['result_id'], FILTER_SANITIZE_NUMBER_INT);

    $update_fields = [];
    $update_values = [];

    $update_fields[] = "`patient_id` = :patient_id";
    $update_values[':patient_id'] = filter_var($_POST['patient_id'], FILTER_SANITIZE_NUMBER_INT);
    $update_fields[] = "`ordered_by_user_id` = :ordered_by_user_id";
    $update_values[':ordered_by_user_id'] = filter_var($_POST['ordered_by_user_id'], FILTER_SANITIZE_NUMBER_INT);
    $update_fields[] = "`result_date` = :result_date";
    $update_values[':result_date'] = date('Y-m-d H:i:s');

    // Haematology
    $update_fields[] = "`haematology_pcv` = :haematology_pcv";
    $update_values[':haematology_pcv'] = sanitize_input($_POST['haematology_pcv']);
    $update_fields[] = "`haematology_blood_group` = :haematology_blood_group";
    $update_values[':haematology_blood_group'] = sanitize_input($_POST['haematology_blood_group']);
    $update_fields[] = "`haematology_genotype` = :haematology_genotype";
    $update_values[':haematology_genotype'] = sanitize_input($_POST['haematology_genotype']);
    $update_fields[] = "`haematology_esr` = :haematology_esr";
    $update_values[':haematology_esr'] = sanitize_input($_POST['haematology_esr']);
    $update_fields[] = "`haematology_wbc` = :haematology_wbc";
    $update_values[':haematology_wbc'] = sanitize_input($_POST['haematology_wbc']);

    // Bacteriology
    $update_fields[] = "`bacteriology_h_pylori` = :bacteriology_h_pylori";
    $update_values[':bacteriology_h_pylori'] = sanitize_input($_POST['bacteriology_h_pylori']);

    // Parasitology
    $update_fields[] = "`parasitology_mps` = :parasitology_mps";
    $update_values[':parasitology_mps'] = sanitize_input($_POST['parasitology_mps']);
    $update_fields[] = "`parasitology_skin_snip` = :parasitology_skin_snip";
    $update_values[':parasitology_skin_snip'] = sanitize_input($_POST['parasitology_skin_snip']);
    $update_fields[] = "`parasitology_wet_prep` = :parasitology_wet_prep";
    $update_values[':parasitology_wet_prep'] = sanitize_input($_POST['parasitology_wet_prep']);

    // Virology
    $update_fields[] = "`virology_hbsag` = :virology_hbsag";
    $update_values[':virology_hbsag'] = sanitize_input($_POST['virology_hbsag']);
    $update_fields[] = "`virology_hcv` = :virology_hcv";
    $update_values[':virology_hcv'] = sanitize_input($_POST['virology_hcv']);
    $update_fields[] = "`virology_rvs` = :virology_rvs";
    $update_values[':virology_rvs'] = sanitize_input($_POST['virology_rvs']);
    $update_fields[] = "`virology_syphilis` = :virology_syphilis";
    $update_values[':virology_syphilis'] = sanitize_input($_POST['virology_syphilis']);

    // Clinical Chemistry
    $update_fields[] = "`clinical_chemistry_rbs` = :clinical_chemistry_rbs";
    $update_values[':clinical_chemistry_rbs'] = sanitize_input($_POST['clinical_chemistry_rbs']);
    $update_fields[] = "`clinical_chemistry_serum_pt` = :clinical_chemistry_serum_pt";
    $update_values[':clinical_chemistry_serum_pt'] = sanitize_input($_POST['clinical_chemistry_serum_pt']);

    // Urinalysis
    $update_fields[] = "`urinalysis_blood` = :urinalysis_blood";
    $update_values[':urinalysis_blood'] = sanitize_input($_POST['urinalysis_blood']);
    $update_fields[] = "`urinalysis_ketone` = :urinalysis_ketone";
    $update_values[':urinalysis_ketone'] = sanitize_input($_POST['urinalysis_ketone']);
    $update_fields[] = "`urinalysis_glucose` = :urinalysis_glucose";
    $update_values[':urinalysis_glucose'] = sanitize_input($_POST['urinalysis_glucose']);
    $update_fields[] = "`urinalysis_protein` = :urinalysis_protein";
    $update_values[':urinalysis_protein'] = sanitize_input($_POST['urinalysis_protein']);
    $update_fields[] = "`urinalysis_leucocytes` = :urinalysis_leucocytes";
    $update_values[':urinalysis_leucocytes'] = sanitize_input($_POST['urinalysis_leucocytes']);
    $update_fields[] = "`urinalysis_ascorbic_acid` = :urinalysis_ascorbic_acid";
    $update_values[':urinalysis_ascorbic_acid'] = sanitize_input($_POST['urinalysis_ascorbic_acid']);
    $update_fields[] = "`urinalysis_urobilinogen` = :urinalysis_urobilinogen";
    $update_values[':urinalysis_urobilinogen'] = sanitize_input($_POST['urinalysis_urobilinogen']);
    $update_fields[] = "`urinalysis_ph` = :urinalysis_ph";
    $update_values[':urinalysis_ph'] = sanitize_input($_POST['urinalysis_ph']);
    $update_fields[] = "`urinalysis_nitrite` = :urinalysis_nitrite";
    $update_values[':urinalysis_nitrite'] = sanitize_input($_POST['urinalysis_nitrite']);
    $update_fields[] = "`urinalysis_bilirubin` = :urinalysis_bilirubin";
    $update_values[':urinalysis_bilirubin'] = sanitize_input($_POST['urinalysis_bilirubin']);

    // Urine Microscopy
    $update_fields[] = "`urine_microscopy_notes` = :urine_microscopy_notes";
    $update_values[':urine_microscopy_notes'] = sanitize_input($_POST['urine_microscopy_notes']);

    // WIDAL .TEST
    $update_fields[] = "`widal_ao_1` = :widal_ao_1";
    $update_values[':widal_ao_1'] = sanitize_input($_POST['widal_ao_1']);
    $update_fields[] = "`widal_bo_1` = :widal_bo_1";
    $update_values[':widal_bo_1'] = sanitize_input($_POST['widal_bo_1']);
    $update_fields[] = "`widal_co_1` = :widal_co_1";
    $update_values[':widal_co_1'] = sanitize_input($_POST['widal_co_1']);
    $update_fields[] = "`widal_do_1` = :widal_do_1";
    $update_values[':widal_do_1'] = sanitize_input($_POST['widal_do_1']);
    $update_fields[] = "`widal_ah_1` = :widal_ah_1";
    $update_values[':widal_ah_1'] = sanitize_input($_POST['widal_ah_1']);
    $update_fields[] = "`widal_bh_1` = :widal_bh_1";
    $update_values[':widal_bh_1'] = sanitize_input($_POST['widal_bh_1']);
    $update_fields[] = "`widal_ch_1` = :widal_ch_1";
    $update_values[':widal_ch_1'] = sanitize_input($_POST['widal_ch_1']);
    $update_fields[] = "`widal_dh_1` = :widal_dh_1";
    $update_values[':widal_dh_1'] = sanitize_input($_POST['widal_dh_1']);

    // Stool
    $update_fields[] = "`stool_macroscopy_notes` = :stool_macroscopy_notes";
    $update_values[':stool_macroscopy_notes'] = sanitize_input($_POST['stool_macroscopy_notes']);
    $update_fields[] = "`stool_microscopy_notes` = :stool_microscopy_notes";
    $update_values[':stool_microscopy_notes'] = sanitize_input($_POST['stool_microscopy_notes']);

    // Comment
    $update_fields[] = "`comment_notes` = :comment_notes";
    $update_values[':comment_notes'] = sanitize_input($_POST['comment_notes']);

    // Semen Fluid Analysis
    $update_fields[] = "`semen_production_time` = :semen_production_time";
    $update_values[':semen_production_time'] = sanitize_input($_POST['semen_production_time']);
    $update_fields[] = "`semen_collection_time` = :semen_collection_time";
    $update_values[':semen_collection_time'] = sanitize_input($_POST['semen_collection_time']);
    $update_fields[] = "`semen_examination_time` = :semen_examination_time";
    $update_values[':semen_examination_time'] = sanitize_input($_POST['semen_examination_time']);
    $update_fields[] = "`semen_volume` = :semen_volume";
    $update_values[':semen_volume'] = sanitize_input($_POST['semen_volume']);
    $update_fields[] = "`semen_ph` = :semen_ph";
    $update_values[':semen_ph'] = sanitize_input($_POST['semen_ph']);
    $update_fields[] = "`semen_appearance` = :semen_appearance";
    $update_values[':semen_appearance'] = sanitize_input($_POST['semen_appearance']);
    $update_fields[] = "`semen_viscosity` = :semen_viscosity";
    $update_values[':semen_viscosity'] = sanitize_input($_POST['semen_viscosity']);
    $update_fields[] = "`semen_pus_cell` = :semen_pus_cell";
    $update_values[':semen_pus_cell'] = sanitize_input($_POST['semen_pus_cell']);
    $update_fields[] = "`semen_bacterial_cells` = :semen_bacterial_cells";
    $update_values[':semen_bacterial_cells'] = sanitize_input($_POST['semen_bacterial_cells']);
    $update_fields[] = "`semen_e_coli` = :semen_e_coli";
    $update_values[':semen_e_coli'] = sanitize_input($_POST['semen_e_coli']);
    $update_fields[] = "`semen_morphology_normal` = :semen_morphology_normal";
    $update_values[':semen_morphology_normal'] = sanitize_input($_POST['semen_morphology_normal']);
    $update_fields[] = "`semen_morphology_abnormal` = :semen_morphology_abnormal";
    $update_values[':semen_morphology_abnormal'] = sanitize_input($_POST['semen_morphology_abnormal']);

    try {
        $sql = "UPDATE `lab_results` SET " . implode(', ', $update_fields) . " WHERE `id` = :id_to_update";
        $stmt = $con->prepare($sql);
        $update_values[':id_to_update'] = $id_to_update; // Add ID for WHERE clause
        $stmt->execute($update_values);

        if ($stmt->rowCount() > 0) {
            $_SESSION['success_message'] = "Lab result updated successfully!";
        } else {
            $_SESSION['info_message'] = "No changes made to lab result or record not found.";
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating lab result: " . $e->getMessage();
    }
    header("location:manage_lab_results.php");
    exit;
}

// --- Fetch all lab results for display (only orders submitted) ---
$all_lab_results = [];
try {
    $stmt = $con->prepare("
        SELECT
            lr.id,
            lr.result_date,
            lr.comment_notes,
            p.patient_display_id,
            p.patient_name,
            p.dob,
            u.display_name AS ordered_by_user_name
        FROM
            `lab_results` lr
        JOIN
            `patients` p ON lr.patient_id = p.id
        LEFT JOIN
            `users` u ON lr.ordered_by_user_id = u.id
        WHERE
            lr.patient_id IS NOT NULL AND lr.ordered_by_user_id IS NOT NULL
        ORDER BY
            lr.result_date DESC
    ");
    $stmt->execute();
    $all_lab_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error fetching lab results: " . $e->getMessage();
}

// --- Fetch patients for dropdown (for edit form) ---
$patients_options = '';
try {
    $query = "SELECT `id`, `patient_name`, `patient_display_id` FROM `patients` ORDER BY `patient_name` ASC";
    $stmt = $con->prepare($query);
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $patients_options .= '<option value="' . htmlspecialchars($row['id']) . '">' . htmlspecialchars($row['patient_name']) . ' (' . htmlspecialchars($row['patient_display_id'] ?: 'N/A') . ')</option>';
    }
} catch (PDOException $e) {
    error_log("Error fetching patients for dropdown: " . $e->getMessage());
    $patients_options = '<option value="">Error loading patients</option>';
}

// --- Fetch users (doctors/staff) for 'Ordered By' dropdown (for edit form) ---
$users_options = '';
try {
    $query = "SELECT `id`, `username`, `display_name` FROM `users` WHERE `user_type` = 'doctor' OR `user_type` = 'admin' OR `user_type` = 'lab_tech' ORDER BY `username` ASC";
    $stmt = $con->prepare($query);
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $users_options .= '<option value="' . htmlspecialchars($row['id']) . '">' . htmlspecialchars($row['display_name'] ?: $row['username']) . '</option>';
    }
} catch (PDOException $e) {
    error_log("Error fetching users for dropdown: " . $e->getMessage());
    $users_options = '<option value="">Error loading staff</option>';
}

// Get the current page filename for sidebar highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Define an array of pages that belong to the Lab Tests menu to open the treeview
$lab_tests_pages = ['add_lab_order.php', 'laboratory.php', 'manage_lab_results.php'];

// Define an array of pages that belong to the Patients menu to open the treeview
$patients_pages = ['patients.php', 'new_prescription.php', 'patient_history.php', 'patients_list.php'];

// Define an array of pages that belong to the Medicines menu to open the treeview
$medicines_pages = ['medicines.php', 'medicine_details.php'];

// Define an array of pages that belong to the Scanning menu to open the treeview
$scanning_pages = ['scans_unified.php'];

// Define an array of pages that belong to the Billing & Payments menu to open the treeview
$billing_pages = ['create_bill.php', 'manage_bills.php', 'manage_payments.php', 'view_bill_details.php'];

// Define an array of pages that belong to the Reports menu to open the treeview
$reports_pages = ['reports.php'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Lab Results - KDCS</title>

    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
    <link rel="stylesheet" href="plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css">
    <link rel="stylesheet" href="plugins/icheck-bootstrap/icheck-bootstrap.min.css">
    <link rel="stylesheet" href="plugins/jqvmap/jqvmap.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <link rel="stylesheet" href="plugins/overlayScrollbars/css/OverlayScrollbars.min.css">
    <link rel="stylesheet" href="plugins/daterangepicker/daterangepicker.css">
    <link rel="stylesheet" href="plugins/summernote/summernote-bs4.css">
    <link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700" rel="stylesheet">
    <link rel="stylesheet" href="plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="plugins/datatables-responsive/css/responsive.bootstrap4.min.css">
    <style>
            
        .lab-data-cell {
            white-space: nowrap;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .action-buttons button {
            margin-right: 5px;
            margin-bottom: 5px;
        }
        .lab-section {
            margin-bottom: 25px;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
            background-color: #f9f9f9;
        }
        .lab-section h4 {
            border-bottom: 2px solid #007bff;
            padding-bottom: 5px;
            margin-bottom: 15px;
            color: #007bff;
        }
        .form-group label {
            font-weight: bold;
            margin-bottom: 5px;
            display: block;
        }
        .form-control-sm {
            height: calc(1.8125rem + 2px);
            padding: .25rem .5rem;
            font-size: .875rem;
            line-height: 1.5;
            border-radius: .2rem;
        }
        .input-group-text {
            height: calc(1.8125rem + 2px);
            font-size: .875rem;
        }
        .widal-table th, .widal-table td {
            padding: 5px;
            vertical-align: middle;
            text-align: center;
        }
        .widal-table strong {
            display: block;
        }
        .widal-table input {
            width: 100%;
            text-align: center;
        }
        textarea {
            resize: vertical;
        }
        /* Styles for Print View */
        @media print {
            body {
                -webkit-print-color-adjust: exact !important; /* For Chrome/Safari */
                print-color-adjust: exact !important; /* For Firefox */
                margin: 0 !important; /* Remove default page margins */
                padding: 0 !important;
                font-size: 10pt; /* Smaller base font for print */
            }
            .wrapper, .content-wrapper, .main-footer, .main-header, .main-sidebar, .card-header, .card-body, .modal-footer, .modal-header .close, .no-print, .dt-buttons {
                display: none !important;
            }
            #print-area {
                visibility: visible !important;
                position: static !important; /* Remove absolute positioning */
                left: auto !important;
                top: auto !important;
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important; /* Remove padding here */
                box-sizing: border-box;
                float: none !important;
            }
            .modal-content {
                border: none !important;
                box-shadow: none !important;
            }

            /* Header for print */
            .print-header-content {
                display: flex; /* Use flex for layout */
                align-items: center; /* Vertically align items */
                justify-content: center;
                margin-bottom: 15px; /* More space below header */
                text-align: center;
                border-bottom: 2px solid #333; /* Stronger border for header */
                padding-bottom: 10px;
            }
            .print-header-content img {
                width: 70px;   /* Slightly larger logos for clarity */
                height: 70px;
                border-radius: 50%;
                object-fit: cover;
                margin: 0 15px; /* More margin around logos */
            }
            .print-header-content > div {
                flex-grow: 1; /* Allow content to take available space */
            }
            .print-header-content h2 {
                margin: 0; padding: 0; color: #333; font-size: 1.4em; font-weight: bold;
            }
            .print-header-content p {
                margin: 0; padding: 0; font-size: 0.85em; color: #555; line-height: 1.3;
            }
            .print-title {
                margin-top: 15px;
                color: #000;
                font-size: 1.5em; /* Larger title */
                font-weight: bold;
                text-align: center;
                margin-bottom: 20px;
            }

            /* Patient Info Table for Print */
            .patient-info-print-table {
                width: 95% !important; /* Adjust width to fit page better */
                margin: 15px auto !important; /* Center the table */
                border-collapse: collapse;
                font-size: 10pt; /* Smaller font for patient info */
            }
            .patient-info-print-table td {
                padding: 3px 5px !important; /* Reduced padding */
                border: 1px solid #ccc !important; /* Add borders for structure */
                vertical-align: top;
            }
            .patient-info-print-table strong {
                white-space: nowrap; /* Prevent label from wrapping */
            }

            /* Lab Sections for Print */
            .lab-section {
                border: 1px solid #ddd;
                padding: 10px; /* Reduced padding */
                margin-bottom: 15px; /* Reduced margin */
                page-break-inside: avoid; /* Keep sections together if possible */
            }
            .lab-section h5 {
                color: #000 !important;
                border-bottom: 1px solid #ccc !important; /* Lighter border */
                padding-bottom: 5px;
                margin-bottom: 10px; /* Reduced margin */
                font-size: 1.1em; /* Adjusted font size */
                font-weight: bold;
            }
            .lab-section p {
                font-size: 10pt;
                line-height: 1.4;
            }
            .lab-section table {
                width: 100% !important;
                border-collapse: collapse;
                margin-bottom: 10px;
                font-size: 10pt; /* Match section font size */
            }
            .lab-section table, .lab-section table th, .lab-section table td {
                border: 1px solid #ccc !important; /* Light borders for results */
                padding: 5px !important; /* Consistent padding */
            }
            .lab-section table th {
                background-color: #f9f9f9 !important; /* Lighter background */
                font-weight: bold;
            }
            .widal-table th, .widal-table td {
                text-align: center;
            }

            /* Doctor Signature */
            .doctor-signature-block {
                margin-top: 30px; /* Adjusted spacing */
                width: 300px; /* Fixed width */
                text-align: center;
                float: right;
                page-break-before: avoid; /* Try to keep with preceding content */
            }
            .doctor-signature-block p {
                margin: 0;
                padding: 0;
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
                        <h1>Manage Lab Results</h1>
                    </div>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="card card-outline card-success rounded-0 shadow">
                <div class="card-header">
                    <h3 class="card-title">All Lab Results</h3>
                </div>
                <div class="card-body">
                    <?php
                    // Display success or error messages
                    if (isset($_SESSION['success_message'])) {
                        echo '<div class="alert alert-success alert-dismissible fade show" role="alert">' . $_SESSION['success_message'] . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>';
                        unset($_SESSION['success_message']);
                    }
                    if (isset($_SESSION['error_message'])) {
                        echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">' . $_SESSION['error_message'] . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>';
                        unset($_SESSION['error_message']);
                    }
                    if (isset($_SESSION['info_message'])) {
                        echo '<div class="alert alert-info alert-dismissible fade show" role="alert">' . $_SESSION['info_message'] . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>';
                        unset($_SESSION['info_message']);
                    }
                    ?>
                    <div class="table-responsive">
                        <table id="labResultsTable" class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Patient ID</th>
                                    <th>Patient Name</th>
                                    <th>Age</th>
                                    <th>Ordered By</th>
                                    <th>Order Date</th>
                                    <th>Comment</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($all_lab_results)): ?>
                                    <?php $i = 1; foreach ($all_lab_results as $result): ?>
                                        <tr>
                                            <td><?php echo $i++; ?></td>
                                            <td><?php echo htmlspecialchars($result['patient_display_id'] ?: 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($result['patient_name']); ?></td>
                                            <td><?php echo calculate_age($result['dob']); ?></td>
                                            <td><?php echo htmlspecialchars($result['ordered_by_user_name'] ?: 'N/A'); ?></td>
                                            <td><?php echo date('Y-m-d', strtotime($result['result_date'])); ?></td>
                                            <td class="lab-data-cell"><?php echo htmlspecialchars($result['comment_notes']); ?></td>
                                            <td class="action-buttons">
                                                <button class="btn btn-info btn-sm view-btn" data-id="<?php echo $result['id']; ?>" title="View Details"><i class="fa fa-eye"></i></button>
                                                <button class="btn btn-success btn-sm edit-btn" data-id="<?php echo $result['id']; ?>" title="Edit Record"><i class="fa fa-edit"></i></button>
                                                <a href="?action=delete&id=<?php echo $result['id']; ?>" class="btn btn-danger btn-sm delete-btn" onclick="return confirm('Are you sure you want to delete this record?');" title="Delete Record"><i class="fa fa-trash"></i></a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center">No lab results found for submitted orders.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <?php include './config/footer.php'; ?>
</div>

<div class="modal fade" id="editLabResultModal" tabindex="-1" role="dialog" aria-labelledby="editLabResultModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document"> <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="editLabResultModalLabel">Edit Lab Result</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="editLabResultForm" method="POST" action="manage_lab_results.php">
                <div class="modal-body">
                    <input type="hidden" name="update_lab_result" value="1">
                    <input type="hidden" name="result_id" id="edit_result_id">

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label for="edit_patient_id">Patient:</label>
                            <select id="edit_patient_id" name="patient_id" class="form-control form-control-sm rounded-0" required>
                                <?php echo $patients_options; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="edit_ordered_by_user_id">Ordered By:</label>
                            <select id="edit_ordered_by_user_id" name="ordered_by_user_id" class="form-control form-control-sm rounded-0" required>
                                <?php echo $users_options; ?>
                            </select>
                        </div>
                    </div>
                    <hr>

                    <div class="lab-section">
                        <h4>HAEMATOLOGY</h4>
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label for="edit_haematology_pcv">PCV:</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" id="edit_haematology_pcv" name="haematology_pcv" class="form-control form-control-sm rounded-0">
                                    <div class="input-group-append"><span class="input-group-text">%</span></div>
                                </div>
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="edit_haematology_blood_group">BLOOD GROUP:</label>
                                <select id="edit_haematology_blood_group" name="haematology_blood_group" class="form-control form-control-sm rounded-0">
                                    <option value="">Select</option>
                                    <option value="A+">A+</option><option value="A-">A-</option><option value="B+">B+</option>
                                    <option value="B-">B-</option><option value="AB+">AB+</option><option value="AB-">AB-</option>
                                    <option value="O+">O+</option><option value="O-">O-</option>
                                </select>
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="edit_haematology_genotype">GENOTYPE:</label>
                                <select id="edit_haematology_genotype" name="haematology_genotype" class="form-control form-control-sm rounded-0">
                                    <option value="">Select</option>
                                    <option value="AA">AA</option><option value="AS">AS</option><option value="SS">SS</option>
                                    <option value="AC">AC</option><option value="SC">SC</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label for="edit_haematology_esr">ESR:</label>
                                <input type="text" id="edit_haematology_esr" name="haematology_esr" class="form-control form-control-sm rounded-0">
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="edit_haematology_wbc">WBC:</label>
                                <input type="text" id="edit_haematology_wbc" name="haematology_wbc" class="form-control form-control-sm rounded-0">
                            </div>
                        </div>
                    </div>

                    <div class="lab-section">
                        <h4>BACTERIOLOGY</h4>
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label for="edit_bacteriology_h_pylori">H.PYLORI:</label>
                                <select id="edit_bacteriology_h_pylori" name="bacteriology_h_pylori" class="form-control form-control-sm rounded-0">
                                    <option value="">Select</option>
                                    <option value="Positive">Positive</option><option value="Negative">Negative</option>
                                    <option value="Indeterminate">Indeterminate</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="lab-section">
                        <h4>PARASITOLOGY</h4>
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label for="edit_parasitology_mps">MPS:</label>
                                <select id="edit_parasitology_mps" name="parasitology_mps" class="form-control form-control-sm rounded-0">
                                    <option value="">Select</option>
                                    <option value="Negative">Negative</option><option value="Positive">Positive</option>
                                    <option value="Not Done">Not Done</option>
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label for="edit_parasitology_skin_snip">SKIN SNIP:</label>
                                <select id="edit_parasitology_skin_snip" name="parasitology_skin_snip" class="form-control form-control-sm rounded-0">
                                    <option value="">Select</option>
                                    <option value="Positive">Positive</option><option value="Negative">Negative</option>
                                    <option value="Not Done">Not Done</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label for="edit_parasitology_wet_prep">WET PREP:</label>
                                <select id="edit_parasitology_wet_prep" name="parasitology_wet_prep" class="form-control form-control-sm rounded-0">
                                    <option value="">Select</option>
                                    <option value="Positive">Positive</option><option value="Negative">Negative</option>
                                    <option value="Not Done">Not Done</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="lab-section">
                        <h4>VIROLOGY</h4>
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label for="edit_virology_hbsag">HBsAg:</label>
                                <select id="edit_virology_hbsag" name="virology_hbsag" class="form-control form-control-sm rounded-0">
                                    <option value="">Select</option>
                                    <option value="Reactive">Reactive</option><option value="Non-Reactive">Non-Reactive</option>
                                </select>
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="edit_virology_hcv">HCV:</label>
                                <select id="edit_virology_hcv" name="virology_hcv" class="form-control form-control-sm rounded-0">
                                    <option value="">Select</option>
                                    <option value="Reactive">Reactive</option><option value="Non-Reactive">Non-Reactive</option>
                                </select>
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="edit_virology_rvs">RVS:</label>
                                <select id="edit_virology_rvs" name="virology_rvs" class="form-control form-control-sm rounded-0">
                                    <option value="">Select</option>
                                    <option value="Reactive">Reactive</option><option value="Non-Reactive">Non-Reactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label for="edit_virology_syphilis">SYPHILIS:</label>
                                <select id="edit_virology_syphilis" name="virology_syphilis" class="form-control form-control-sm rounded-0">
                                    <option value="">Select</option>
                                    <option value="Reactive">Reactive</option><option value="Non-Reactive">Non-Reactive</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="lab-section">
                        <h4>CLINICAL CHEMISTRY</h4>
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label for="edit_clinical_chemistry_rbs">RBS:</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" id="edit_clinical_chemistry_rbs" name="clinical_chemistry_rbs" class="form-control form-control-sm rounded-0">
                                    <div class="input-group-append"><span class="input-group-text">mg/dL</span></div>
                                </div>
                            </div>
                            <div class="col-md-6 form-group">
                                <label for="edit_clinical_chemistry_serum_pt">SERUM PT:</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" id="edit_clinical_chemistry_serum_pt" name="clinical_chemistry_serum_pt" class="form-control form-control-sm rounded-0">
                                    <div class="input-group-append"><span class="input-group-text">seconds</span></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="lab-section">
                        <h4>URINALYSIS</h4>
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label for="edit_urinalysis_blood">Blood:</label>
                                <select id="edit_urinalysis_blood" name="urinalysis_blood" class="form-control form-control-sm rounded-0">
                                    <option value="">Select</option>
                                    <option value="Negative">Negative</option><option value="Trace">Trace</option>
                                    <option value="1+">1+</option><option value="2+">2+</option><option value="3+">3+</option>
                                    <option value="4+">4+</option>
                                </select>
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="edit_urinalysis_ketone">Ketone:</label>
                                <select id="edit_urinalysis_ketone" name="urinalysis_ketone" class="form-control form-control-sm rounded-0">
                                    <option value="">Select</option>
                                    <option value="Negative">Negative</option><option value="Trace">Trace</option>
                                    <option value="1+">1+</option><option value="2+">2+</option><option value="3+">3+</option>
                                    <option value="4+">4+</option>
                                </select>
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="edit_urinalysis_glucose">Glucose:</label>
                                <select id="edit_urinalysis_glucose" name="urinalysis_glucose" class="form-control form-control-sm rounded-0">
                                    <option value="">Select</option>
                                    <option value="Negative">Negative</option><option value="Trace">Trace</option>
                                    <option value="1+">1+</option><option value="2+">2+</option><option value="3+">3+</option>
                                    <option value="4+">4+</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label for="edit_urinalysis_protein">Protein:</label>
                                <select id="edit_urinalysis_protein" name="urinalysis_protein" class="form-control form-control-sm rounded-0">
                                    <option value="">Select</option>
                                    <option value="Negative">Negative</option><option value="Trace">Trace</option>
                                    <option value="1+">1+</option><option value="2+">2+</option><option value="3+">3+</option>
                                    <option value="4+">4+</option>
                                </select>
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="edit_urinalysis_leucocytes">Leucocytes:</label>
                                <select id="edit_urinalysis_leucocytes" name="urinalysis_leucocytes" class="form-control form-control-sm rounded-0">
                                    <option value="">Select</option>
                                    <option value="Negative">Negative</option><option value="Trace">Trace</option>
                                    <option value="1+">1+</option><option value="2+">2+</option><option value="3+">3+</option>
                                    <option value="4+">4+</option>
                                    </select>
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="edit_urinalysis_ascorbic_acid">Ascorbic Acid:</label>
                                <select id="edit_urinalysis_ascorbic_acid" name="urinalysis_ascorbic_acid" class="form-control form-control-sm rounded-0">
                                    <option value="">Select</option>
                                    <option value="Positive">Positive</option><option value="Negative">Negative</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label for="edit_urinalysis_urobilinogen">Urobilinogen:</label>
                                <select id="edit_urinalysis_urobilinogen" name="urinalysis_urobilinogen" class="form-control form-control-sm rounded-0">
                                    <option value="">Select</option>
                                    <option value="Normal">Normal</option><option value="1+">1+</option><option value="2+">2+</option>
                                    <option value="3+">3+</option>
                                </select>
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="edit_urinalysis_ph">PH:</label>
                                <input type="text" id="edit_urinalysis_ph" name="urinalysis_ph" class="form-control form-control-sm rounded-0">
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="edit_urinalysis_nitrite">Nitrite:</label>
                                <select id="edit_urinalysis_nitrite" name="urinalysis_nitrite" class="form-control form-control-sm rounded-0">
                                    <option value="">Select</option>
                                    <option value="Positive">Positive</option><option value="Negative">Negative</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label for="edit_urinalysis_bilirubin">Bilirubin:</label>
                                <select id="edit_urinalysis_bilirubin" name="urinalysis_bilirubin" class="form-control form-control-sm rounded-0">
                                    <option value="">Select</option>
                                    <option value="Positive">Positive</option><option value="Negative">Negative</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="lab-section">
                        <h4>URINE MICROSCOPY</h4>
                        <div class="form-group">
                            <label for="edit_urine_microscopy_notes">Notes:</label>
                            <textarea id="edit_urine_microscopy_notes" name="urine_microscopy_notes" class="form-control form-control-sm rounded-0" rows="3"></textarea>
                        </div>
                    </div>

                    <div class="lab-section">
                        <h4>WIDAL .TEST</h4>
                        <div class="table-responsive">
                            <table class="table table-bordered widal-table">
                                <thead>
                                    <tr>
                                        <th>Antigen</th><th>Result (1/...)</th>
                                        <th>Antigen</th><th>Result (1/...)</th>
                                        <th>Antigen</th><th>Result (1/...)</th>
                                        <th>Antigen</th><th>Result (1/...)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>AO</strong></td><td><input type="text" name="widal_ao_1" id="edit_widal_ao_1" class="form-control form-control-sm"></td>
                                        <td><strong>BO</strong></td><td><input type="text" name="widal_bo_1" id="edit_widal_bo_1" class="form-control form-control-sm"></td>
                                        <td><strong>CO</strong></td><td><input type="text" name="widal_co_1" id="edit_widal_co_1" class="form-control form-control-sm"></td>
                                        <td><strong>DO</strong></td><td><input type="text" name="widal_do_1" id="edit_widal_do_1" class="form-control form-control-sm"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>AH</strong></td><td><input type="text" name="widal_ah_1" id="edit_widal_ah_1" class="form-control form-control-sm"></td>
                                        <td><strong>BH</strong></td><td><input type="text" name="widal_bh_1" id="edit_widal_bh_1" class="form-control form-control-sm"></td>
                                        <td><strong>CH</strong></td><td><input type="text" name="widal_ch_1" id="edit_widal_ch_1" class="form-control form-control-sm"></td>
                                        <td><strong>DH</strong></td><td><input type="text" name="widal_dh_1" id="edit_widal_dh_1" class="form-control form-control-sm"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="lab-section">
                                <h4>STOOL MACROSCOPY</h4>
                                <div class="form-group">
                                    <label for="edit_stool_macroscopy_notes">Notes:</label>
                                    <textarea id="edit_stool_macroscopy_notes" name="stool_macroscopy_notes" class="form-control form-control-sm rounded-0" rows="5"></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="lab-section">
                                <h4>STOOL MICROSCOPY</h4>
                                <div class="form-group">
                                    <label for="edit_stool_microscopy_notes">Notes:</label>
                                    <textarea id="edit_stool_microscopy_notes" name="stool_microscopy_notes" class="form-control form-control-sm rounded-0" rows="5"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="lab-section">
                        <h4>COMMENT</h4>
                        <div class="form-group">
                            <label for="edit_comment_notes">Comment:</label>
                            <textarea id="edit_comment_notes" name="comment_notes" class="form-control form-control-sm rounded-0" rows="4"></textarea>
                        </div>
                    </div>

                    <div class="lab-section">
                        <h4>SEMEN FLUID ANALYSIS</h4>
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label for="edit_semen_production_time">PRODUCTION TIME:</label>
                                <input type="text" id="edit_semen_production_time" name="semen_production_time" class="form-control form-control-sm rounded-0" placeholder="e.g., 9:00 AM">
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="edit_semen_collection_time">COLLECTION TIME:</label>
                                <input type="text" id="edit_semen_collection_time" name="semen_collection_time" class="form-control form-control-sm rounded-0" placeholder="e.g., 9:15 AM">
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="edit_semen_examination_time">EXAMINATION TIME:</label>
                                <input type="text" id="edit_semen_examination_time" name="semen_examination_time" class="form-control form-control-sm rounded-0" placeholder="e.g., 9:30 AM">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label for="edit_semen_volume">VOLUME:</label>
                                <input type="text" id="edit_semen_volume" name="semen_volume" class="form-control form-control-sm rounded-0">
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="edit_semen_ph">PH:</label>
                                <input type="text" id="edit_semen_ph" name="semen_ph" class="form-control form-control-sm rounded-0">
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="edit_semen_appearance">APPEARANCE:</label>
                                <input type="text" id="edit_semen_appearance" name="semen_appearance" class="form-control form-control-sm rounded-0">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label for="edit_semen_viscosity">VISCOSITY:</label>
                                <input type="text" id="edit_semen_viscosity" name="semen_viscosity" class="form-control form-control-sm rounded-0">
                            </div>
                        </div>

                        <h5 class="mt-4">MICROSCOPY</h5>
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label for="edit_semen_pus_cell">PUS CELL:</label>
                                <input type="text" id="edit_semen_pus_cell" name="semen_pus_cell" class="form-control form-control-sm rounded-0">
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="edit_semen_bacterial_cells">BACTERIAL CELLS:</label>
                                <input type="text" id="edit_semen_bacterial_cells" name="semen_bacterial_cells" class="form-control form-control-sm rounded-0">
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="edit_semen_e_coli">E-COLI:</label>
                                <input type="text" id="edit_semen_e_coli" name="semen_e_coli" class="form-control form-control-sm rounded-0">
                            </div>
                        </div>

                        <h5 class="mt-4">MORPHOLOGY</h5>
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label for="edit_semen_morphology_normal">NORMAL:</label>
                                <input type="text" id="edit_semen_morphology_normal" name="semen_morphology_normal" class="form-control form-control-sm rounded-0">
                            </div>
                            <div class="col-md-6 form-group">
                                <label for="edit_semen_morphology_abnormal">ABNORMAL:</label>
                                <input type="text" id="edit_semen_morphology_abnormal" name="semen_morphology_abnormal" class="form-control form-control-sm rounded-0">
                            </div>
                        </div>
                    </div>
                    </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="viewLabResultModal" tabindex="-1" role="dialog" aria-labelledby="viewLabResultModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="viewLabResultModalLabel">Lab Result Details</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="print-area">
                <div id="viewLabResultContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" id="printLabResult"><i class="fa fa-print"></i> Print</button>
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
<script src="plugins/summernote/summernote-bs4.min.js"></script>
<script src="plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js"></script>
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
        // Call the embedded sidebar highlighting function.
        showMenuSelected("#mnu_laboratory", "#mi_manage_lab_results");

        // Initialize DataTable
        $('#labResultsTable').DataTable({
            "paging": true,
            "lengthChange": true,
            "searching": true,
            "ordering": true,
            "info": true,
            "autoWidth": false,
            "responsive": true,
            "order": [[5, "desc"]] // Order by Order Date (column 5) descending
        });

        // Helper function to check if a value is effectively empty
        function isEmptyValue(value) {
            return value === null || (typeof value === 'string' && value.trim() === '') || (typeof value === 'number' && isNaN(value));
        }

        // Helper function to check if any field in an array has a non-empty value
        function hasAnyDataInGroup(fields, dataObject) {
            return fields.some(field =>
                dataObject.hasOwnProperty(field) && !isEmptyValue(dataObject[field])
            );
        }

        // Handle Edit button click
        $('.edit-btn').on('click', function() {
            const resultId = $(this).data('id');
            $('#edit_result_id').val(resultId); // Set the hidden ID field

            console.log('Fetching data for edit modal, ID:', resultId); // Debugging

            $.ajax({
                url: 'manage_lab_results.php',
                type: 'GET',
                data: { action: 'fetch_single', id: resultId },
                dataType: 'json',
                success: function(response) {
                    console.log('AJAX Success for Edit:', response); // Debugging
                    if (response.status === 'success') {
                        const data = response.data;
                        console.log('Data received for Edit:', data); // Debugging

                        // Populate select elements
                        $('#edit_patient_id').val(data.patient_id).trigger('change');
                        $('#edit_ordered_by_user_id').val(data.ordered_by_user_id).trigger('change');

                        // Populate all input/textarea fields
                        $('#edit_haematology_pcv').val(data.haematology_pcv);
                        $('#edit_haematology_blood_group').val(data.haematology_blood_group);
                        $('#edit_haematology_genotype').val(data.haematology_genotype);
                        $('#edit_haematology_esr').val(data.haematology_esr);
                        $('#edit_haematology_wbc').val(data.haematology_wbc);

                        $('#edit_bacteriology_h_pylori').val(data.bacteriology_h_pylori);

                        $('#edit_parasitology_mps').val(data.parasitology_mps);
                        $('#edit_parasitology_skin_snip').val(data.parasitology_skin_snip);
                        $('#edit_parasitology_wet_prep').val(data.parasitology_wet_prep);

                        $('#edit_virology_hbsag').val(data.virology_hbsag);
                        $('#edit_virology_hcv').val(data.virology_hcv);
                        $('#edit_virology_rvs').val(data.virology_rvs);
                        $('#edit_virology_syphilis').val(data.virology_syphilis);

                        $('#edit_clinical_chemistry_rbs').val(data.clinical_chemistry_rbs);
                        $('#edit_clinical_chemistry_serum_pt').val(data.clinical_chemistry_serum_pt);

                        $('#edit_urinalysis_blood').val(data.urinalysis_blood);
                        $('#edit_urinalysis_ketone').val(data.urinalysis_ketone);
                        $('#edit_urinalysis_glucose').val(data.urinalysis_glucose);
                        $('#edit_urinalysis_protein').val(data.urinalysis_protein);
                        $('#edit_urinalysis_leucocytes').val(data.urinalysis_leucocytes);
                        $('#edit_urinalysis_ascorbic_acid').val(data.urinalysis_ascorbic_acid);
                        $('#edit_urinalysis_urobilinogen').val(data.urinalysis_urobilinogen);
                        $('#edit_urinalysis_ph').val(data.urinalysis_ph);
                        $('#edit_urinalysis_nitrite').val(data.urinalysis_nitrite);
                        $('#edit_urinalysis_bilirubin').val(data.urinalysis_bilirubin);

                        $('#edit_urine_microscopy_notes').val(data.urine_microscopy_notes);

                        $('#edit_widal_ao_1').val(data.widal_ao_1);
                        $('#edit_widal_bo_1').val(data.widal_bo_1);
                        $('#edit_widal_co_1').val(data.widal_co_1);
                        $('#edit_widal_do_1').val(data.widal_do_1);
                        $('#edit_widal_ah_1').val(data.widal_ah_1);
                        $('#edit_widal_bh_1').val(data.widal_bh_1);
                        $('#edit_widal_ch_1').val(data.widal_ch_1);
                        $('#edit_widal_dh_1').val(data.widal_dh_1);

                        $('#edit_stool_macroscopy_notes').val(data.stool_macroscopy_notes);
                        $('#edit_stool_microscopy_notes').val(data.stool_microscopy_notes);

                        $('#edit_comment_notes').val(data.comment_notes);

                        // Populate Semen Fluid Analysis fields
                        $('#edit_semen_production_time').val(data.semen_production_time);
                        $('#edit_semen_collection_time').val(data.semen_collection_time);
                        $('#edit_semen_examination_time').val(data.semen_examination_time);
                        $('#edit_semen_volume').val(data.semen_volume);
                        $('#edit_semen_ph').val(data.semen_ph);
                        $('#edit_semen_appearance').val(data.semen_appearance);
                        $('#edit_semen_viscosity').val(data.semen_viscosity);
                        $('#edit_semen_pus_cell').val(data.semen_pus_cell);
                        $('#edit_semen_bacterial_cells').val(data.semen_bacterial_cells);
                        $('#edit_semen_e_coli').val(data.semen_e_coli);
                        $('#edit_semen_morphology_normal').val(data.semen_morphology_normal);
                        $('#edit_semen_morphology_abnormal').val(data.semen_morphology_abnormal);

                        $('#editLabResultModal').modal('show');
                    } else {
                        alert('Error fetching data for edit: ' + response.message);
                        console.error('Error response for Edit:', response); // Debugging
                    }
                },
                error: function(xhr, status, error) {
                    alert('AJAX Error for Edit: Could not fetch data. See console for details.');
                    console.error("AJAX Error for Edit: ", status, error, xhr.responseText); // Debugging
                }
            });
        });

        // Handle View button click - Dynamic display of only administered tests
        $('.view-btn').on('click', function() {
            const resultId = $(this).data('id');
            console.log('Attempting to fetch data for view modal, ID:', resultId);

            $.ajax({
                url: 'manage_lab_results.php',
                type: 'GET',
                data: { action: 'fetch_single', id: resultId },
                dataType: 'json',
                success: function(response) {
                    console.log('AJAX Success for View, full response:', response);
                    if (response.status === 'success') {
                        const data = response.data;
                        console.log('Parsed data for View:', data);

                        // --- Clinic Details for Print ---
                        const clinicName = '<?php echo htmlspecialchars(strtoupper($clinic_name)); ?>';
                        const clinicAddress = '<?php echo htmlspecialchars($clinic_address); ?>';
                        const clinicEmail = '<?php echo htmlspecialchars($clinic_email); ?>';
                        const clinicPhone = '<?php echo htmlspecialchars($clinic_phone); ?>';

                        // --- Patient Details for Print ---
                        const patientName = data.patient_name || 'N/A';
                        const clinicId = data.patient_display_id || 'N/A';
                        const age = data.patient_age || 'N/A';
                        const contactNo = data.contact_no || 'N/A';
                        const gender = data.gender || 'N/A';
                        const maritalStatus = data.marital_status || 'N/A';
                        const lastWeight = data.last_weight ? data.last_weight + ' kg' : 'N/A';

                        let headerContent = `
                            <div class="print-header-content">
                                <img src="dist/img/logo.png" alt="Logo 1">
                                <div style="flex-grow: 1;">
                                    <h2 style="margin: 0; padding: 0; color: #333;">${clinicName}</h2>
                                    <p style="margin: 0; padding: 0; font-size: 0.9em; color: #555;">${clinicAddress}</p>
                                    <p style="margin: 0; padding: 0; font-size: 0.9em; color: #555;">
                                        <i class="fas fa-envelope"></i> ${clinicEmail} |
                                        <i class="fas fa-phone"></i> ${clinicPhone}
                                    </p>
                                </div>
                                <img src="dist/img/logo2.png" alt="Logo 2">
                            </div>
                            <h3 class="print-title">Lab Result Report</h3>
                        `;

                        let patientDetailsHtml = `
                            <div style="text-align: center; margin-top: 15px; margin-bottom: 15px;">
                                <table class="patient-info-print-table">
                                    <tr>
                                        <td><strong>Clinic ID:</strong></td>
                                        <td>${clinicId}</td>
                                        <td><strong>Name:</strong></td>
                                        <td>${patientName}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Age:</strong></td>
                                        <td>${age}</td>
                                        <td><strong>Gender:</strong></td>
                                        <td>${gender}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Phone:</strong></td>
                                        <td>${contactNo}</td>
                                        <td><strong>Marital Status:</strong></td>
                                        <td>${maritalStatus}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Last Weight:</strong></td>
                                        <td>${lastWeight}</td>
                                        <td colspan="2"></td>
                                    </tr>
                                </table>
                            </div>
                        `;


                        let content = headerContent + patientDetailsHtml + '<hr>'; // Start with header and patient info

                        let anySpecificLabDataFound = false;

                        // HAEMATOLOGY
                        const haematologyFields = [
                            'haematology_pcv', 'haematology_blood_group', 'haematology_genotype',
                            'haematology_esr', 'haematology_wbc'
                        ];
                        if (hasAnyDataInGroup(haematologyFields, data)) {
                            anySpecificLabDataFound = true;
                            content += `
                                <div class="lab-section">
                                    <h5>HAEMATOLOGY</h5>
                                    <table class="table table-bordered table-sm">
                                        ${!isEmptyValue(data.haematology_pcv) ? `<tr><th>PCV</th><td>${data.haematology_pcv} %</td></tr>` : ''}
                                        ${!isEmptyValue(data.haematology_blood_group) ? `<tr><th>Blood Group</th><td>${data.haematology_blood_group}</td></tr>` : ''}
                                        ${!isEmptyValue(data.haematology_genotype) ? `<tr><th>Genotype</th><td>${data.haematology_genotype}</td></tr>` : ''}
                                        ${!isEmptyValue(data.haematology_esr) ? `<tr><th>ESR</th><td>${data.haematology_esr}</td></tr>` : ''}
                                        ${!isEmptyValue(data.haematology_wbc) ? `<tr><th>WBC</th><td>${data.haematology_wbc}</td></tr>` : ''}
                                    </table>
                                </div>
                            `;
                        }

                        // BACTERIOLOGY
                        if (!isEmptyValue(data.bacteriology_h_pylori)) {
                            anySpecificLabDataFound = true;
                            content += `
                                <div class="lab-section">
                                    <h5>BACTERIOLOGY</h5>
                                    <table class="table table-bordered table-sm">
                                        <tr><th>H.PYLORI</th><td>${data.bacteriology_h_pylori}</td></tr>
                                    </table>
                                </div>
                            `;
                        }

                        // PARASITOLOGY
                        const parasitologyFields = [
                            'parasitology_mps', 'parasitology_skin_snip', 'parasitology_wet_prep'
                        ];
                        if (hasAnyDataInGroup(parasitologyFields, data)) {
                            anySpecificLabDataFound = true;
                            content += `
                                <div class="lab-section">
                                    <h5>PARASITOLOGY</h5>
                                    <table class="table table-bordered table-sm">
                                        ${!isEmptyValue(data.parasitology_mps) ? `<tr><th>MPS</th><td>${data.parasitology_mps}</td></tr>` : ''}
                                        ${!isEmptyValue(data.parasitology_skin_snip) ? `<tr><th>SKIN SNIP</th><td>${data.parasitology_skin_snip}</td></tr>` : ''}
                                        ${!isEmptyValue(data.parasitology_wet_prep) ? `<tr><th>WET PREP</th><td>${data.parasitology_wet_prep}</td></tr>` : ''}
                                    </table>
                                </div>
                            `;
                        }

                        // VIROLOGY
                        const virologyFields = [
                            'virology_hbsag', 'virology_hcv', 'virology_rvs', 'virology_syphilis'
                        ];
                        if (hasAnyDataInGroup(virologyFields, data)) {
                            anySpecificLabDataFound = true;
                            content += `
                                <div class="lab-section">
                                    <h5>VIROLOGY</h5>
                                    <table class="table table-bordered table-sm">
                                        ${!isEmptyValue(data.virology_hbsag) ? `<tr><th>HBsAg</th><td>${data.virology_hbsag}</td></tr>` : ''}
                                        ${!isEmptyValue(data.virology_hcv) ? `<tr><th>HCV</th><td>${data.virology_hcv}</td></tr>` : ''}
                                        ${!isEmptyValue(data.virology_rvs) ? `<tr><th>RVS</th><td>${data.virology_rvs}</td></tr>` : ''}
                                        ${!isEmptyValue(data.virology_syphilis) ? `<tr><th>SYPHILIS</th><td>${data.virology_syphilis}</td></tr>` : ''}
                                    </table>
                                </div>
                            `;
                        }

                        // CLINICAL CHEMISTRY
                        const clinicalChemistryFields = [
                            'clinical_chemistry_rbs', 'clinical_chemistry_serum_pt'
                        ];
                        if (hasAnyDataInGroup(clinicalChemistryFields, data)) {
                            anySpecificLabDataFound = true;
                            content += `
                                <div class="lab-section">
                                    <h5>CLINICAL CHEMISTRY</h5>
                                    <table class="table table-bordered table-sm">
                                        ${!isEmptyValue(data.clinical_chemistry_rbs) ? `<tr><th>RBS</th><td>${data.clinical_chemistry_rbs} mg/dL</td></tr>` : ''}
                                        ${!isEmptyValue(data.clinical_chemistry_serum_pt) ? `<tr><th>SERUM PT</th><td>${data.clinical_chemistry_serum_pt} seconds</td></tr>` : ''}
                                    </table>
                                </div>
                            `;
                        }

                        // URINALYSIS - Adjusted to 3 rows
                        const urinalysisFields = [
                            'urinalysis_blood', 'urinalysis_ketone', 'urinalysis_glucose',
                            'urinalysis_protein', 'urinalysis_leucocytes', 'urinalysis_ascorbic_acid',
                            'urinalysis_urobilinogen', 'urinalysis_ph', 'urinalysis_nitrite', 'urinalysis_bilirubin'
                        ];
                        if (hasAnyDataInGroup(urinalysisFields, data)) {
                            anySpecificLabDataFound = true;
                            content += `
                                <div class="lab-section">
                                    <h5>URINALYSIS</h5>
                                    <table class="table table-bordered table-sm">
                                        <tr>
                                            ${!isEmptyValue(data.urinalysis_blood) ? `<th>Blood</th><td>${data.urinalysis_blood}</td>` : '<td></td><td></td>'}
                                            ${!isEmptyValue(data.urinalysis_ketone) ? `<th>Ketone</th><td>${data.urinalysis_ketone}</td>` : '<td></td><td></td>'}
                                            ${!isEmptyValue(data.urinalysis_glucose) ? `<th>Glucose</th><td>${data.urinalysis_glucose}</td>` : '<td></td><td></td>'}
                                        </tr>
                                        <tr>
                                            ${!isEmptyValue(data.urinalysis_protein) ? `<th>Protein</th><td>${data.urinalysis_protein}</td>` : '<td></td><td></td>'}
                                            ${!isEmptyValue(data.urinalysis_leucocytes) ? `<th>Leucocytes</th><td>${data.urinalysis_leucocytes}</td>` : '<td></td><td></td>'}
                                            ${!isEmptyValue(data.urinalysis_ascorbic_acid) ? `<th>Ascorbic Acid</th><td>${data.urinalysis_ascorbic_acid}</td>` : '<td></td><td></td>'}
                                        </tr>
                                        <tr>
                                            ${!isEmptyValue(data.urinalysis_urobilinogen) ? `<th>Urobilinogen</th><td>${data.urinalysis_urobilinogen}</td>` : '<td></td><td></td>'}
                                            ${!isEmptyValue(data.urinalysis_ph) ? `<th>PH</th><td>${data.urinalysis_ph}</td>` : '<td></td><td></td>'}
                                            ${!isEmptyValue(data.urinalysis_nitrite) ? `<th>Nitrite</th><td>${data.urinalysis_nitrite}</td>` : '<td></td><td></td>'}
                                        </tr>
                                        <tr>
                                            ${!isEmptyValue(data.urinalysis_bilirubin) ? `<th>Bilirubin</th><td>${data.urinalysis_bilirubin}</td>` : '<td></td><td></td>'}
                                            <td colspan="4"></td>
                                        </tr>
                                    </table>
                                </div>
                            `;
                        }

                        // URINE MICROSCOPY
                        if (!isEmptyValue(data.urine_microscopy_notes)) {
                            anySpecificLabDataFound = true;
                            content += `
                                <div class="lab-section">
                                    <h5>URINE MICROSCOPY</h5>
                                    <p><strong>Notes:</strong> ${data.urine_microscopy_notes}</p>
                                </div>
                            `;
                        }

                        // WIDAL .TEST
                        const widalFields = [
                            'widal_ao_1', 'widal_bo_1', 'widal_co_1', 'widal_do_1',
                            'widal_ah_1', 'widal_bh_1', 'widal_ch_1', 'widal_dh_1'
                        ];
                        if (hasAnyDataInGroup(widalFields, data)) {
                            anySpecificLabDataFound = true;
                            content += `
                                <div class="lab-section">
                                    <h5>WIDAL .TEST</h5>
                                    <table class="table table-bordered table-sm">
                                        <tr>
                                            ${!isEmptyValue(data.widal_ao_1) ? `<th>AO</th><td>1/${data.widal_ao_1}</td>` : '<td></td><td></td>'}
                                            ${!isEmptyValue(data.widal_bo_1) ? `<th>BO</th><td>1/${data.widal_bo_1}</td>` : '<td></td><td></td>'}
                                            ${!isEmptyValue(data.widal_co_1) ? `<th>CO</th><td>1/${data.widal_co_1}</td>` : '<td></td><td></td>'}
                                            ${!isEmptyValue(data.widal_do_1) ? `<th>DO</th><td>1/${data.widal_do_1}</td>` : '<td></td><td></td>'}
                                        </tr>
                                        <tr>
                                            ${!isEmptyValue(data.widal_ah_1) ? `<th>AH</th><td>1/${data.widal_ah_1}</td>` : '<td></td><td></td>'}
                                            ${!isEmptyValue(data.widal_bh_1) ? `<th>BH</th><td>1/${data.widal_bh_1}</td>` : '<td></td><td></td>'}
                                            ${!isEmptyValue(data.widal_ch_1) ? `<th>CH</th><td>1/${data.widal_ch_1}</td>` : '<td></td><td></td>'}
                                            ${!isEmptyValue(data.widal_dh_1) ? `<th>DH</th><td>1/${data.widal_dh_1}</td>` : '<td></td><td></td>'}
                                        </tr>
                                    </table>
                                </div>
                            `;
                        }

                        // STOOL MACROSCOPY
                        if (!isEmptyValue(data.stool_macroscopy_notes)) {
                            anySpecificLabDataFound = true;
                            content += `
                                <div class="lab-section">
                                    <h5>STOOL MACROSCOPY</h5>
                                    <p><strong>Notes:</strong> ${data.stool_macroscopy_notes}</p>
                                </div>
                            `;
                        }

                        // STOOL MICROSCOPY
                        if (!isEmptyValue(data.stool_microscopy_notes)) {
                            anySpecificLabDataFound = true;
                            content += `
                                <div class="lab-section">
                                    <h5>STOOL MICROSCOPY</h5>
                                    <p><strong>Notes:</strong> ${data.stool_microscopy_notes}</p>
                                </div>
                            `;
                        }

                        // SEMEN FLUID ANALYSIS
                        const semenFields = [
                            'semen_production_time', 'semen_collection_time', 'semen_examination_time',
                            'semen_volume', 'semen_ph', 'semen_appearance', 'semen_viscosity',
                            'semen_pus_cell', 'semen_bacterial_cells', 'semen_e_coli',
                            'semen_morphology_normal', 'semen_morphology_abnormal'
                        ];
                        if (hasAnyDataInGroup(semenFields, data)) {
                            anySpecificLabDataFound = true;
                            content += `
                                <div class="lab-section">
                                    <h5>SEMEN FLUID ANALYSIS</h5>
                                    <table class="table table-bordered table-sm">
                                        ${!isEmptyValue(data.semen_production_time) ? `<tr><th>PRODUCTION TIME</th><td>${data.semen_production_time}</td></tr>` : ''}
                                        ${!isEmptyValue(data.semen_collection_time) ? `<tr><th>COLLECTION TIME</th><td>${data.semen_collection_time}</td></tr>` : ''}
                                        ${!isEmptyValue(data.semen_examination_time) ? `<tr><th>EXAMINATION TIME</th><td>${data.semen_examination_time}</td></tr>` : ''}
                                        ${!isEmptyValue(data.semen_volume) ? `<tr><th>VOLUME</th><td>${data.semen_volume}</td></tr>` : ''}
                                        ${!isEmptyValue(data.semen_ph) ? `<tr><th>PH</th><td>${data.semen_ph}</td></tr>` : ''}
                                        ${!isEmptyValue(data.semen_appearance) ? `<tr><th>APPEARANCE</th><td>${data.semen_appearance}</td></tr>` : ''}
                                        ${!isEmptyValue(data.semen_viscosity) ? `<tr><th>VISCOSITY</th><td>${data.semen_viscosity}</td></tr>` : ''}
                                    </table>
                                    <h6 class="mt-3">MICROSCOPY</h6>
                                    <table class="table table-bordered table-sm">
                                        ${!isEmptyValue(data.semen_pus_cell) ? `<tr><th>PUS CELL</th><td>${data.semen_pus_cell}</td></tr>` : ''}
                                        ${!isEmptyValue(data.semen_bacterial_cells) ? `<tr><th>BACTERIAL CELLS</th><td>${data.semen_bacterial_cells}</td></tr>` : ''}
                                        ${!isEmptyValue(data.semen_e_coli) ? `<tr><th>E-COLI</th><td>${data.semen_e_coli}</td></tr>` : ''}
                                    </table>
                                    <h6 class="mt-3">MORPHOLOGY</h6>
                                    <table class="table table-bordered table-sm">
                                        ${!isEmptyValue(data.semen_morphology_normal) ? `<tr><th>NORMAL</th><td>${data.semen_morphology_normal}</td></tr>` : ''}
                                        ${!isEmptyValue(data.semen_morphology_abnormal) ? `<tr><th>ABNORMAL</th><td>${data.semen_morphology_abnormal}</td></tr>` : ''}
                                    </table>
                                </div>
                            `;
                        }

                        // COMMENT (always show if there's a comment, even if other tests are empty)
                        if (!isEmptyValue(data.comment_notes)) {
                            anySpecificLabDataFound = true; // A comment counts as "data found"
                            content += `
                                <div class="lab-section">
                                    <h5>COMMENT</h5>
                                    <p><strong>Comment:</strong> ${data.comment_notes}</p>
                                </div>
                            `;
                        }
                        
                        // Final check: if no specific lab data was found after checking all sections
                        if (!anySpecificLabDataFound) {
                             content += `<div class="alert alert-info text-center mt-4">No specific lab results recorded for this patient or order.</div>`;
                        }

                        // Add Doctor Signature block (at the very end of content string)
                        content += `
                            <div class="doctor-signature-block" style="margin-top: 50px;">
                                <p style="margin-bottom: 5px;">Sign:_______________________</p>
                                <p style="margin-top: 0; font-size: 1.2em; font-weight: bold;">Dr. Joel Luka</p>
                            </div>
                        `;


                        $('#viewLabResultContent').html(content);
                        $('#viewLabResultModal').modal('show');
                    } else {
                        alert('Error fetching data for view: ' + response.message);
                        console.error('Error response for View:', response);
                    }
                },
                error: function(xhr, status, error) {
                    alert('AJAX Error for View: Could not fetch data. See console for details.');
                    console.error("AJAX Error for View: ", status, error, xhr.responseText);
                    console.error("Raw response text (might be HTML if PHP error):", xhr.responseText);
                }
            });
        });

        // Handle Print button click within the view modal
        $('#printLabResult').on('click', function() {
            var contentToPrint = $('#viewLabResultContent').html(); // Get content from the dynamically populated div
            var printWindow = window.open('', '_blank', 'height=800,width=1000'); // Increased size for better view

            printWindow.document.write('<html><head><title>Lab Result Print</title>');
            printWindow.document.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">'); // Include Font Awesome
            printWindow.document.write('<style>');
            printWindow.document.write(`
                body {
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                    margin: 0 !important;
                    padding: 20px !important; /* Global padding for the printed page content */
                    box-sizing: border-box;
                    font-family: Arial, sans-serif;
                    font-size: 10pt;
                }
                h4, h5 { color: #000 !important; }
                h4 { text-align: center; margin-bottom: 15px; font-size: 1.5em; }
                h5 { border-bottom: 1px solid #ddd; padding-bottom: 8px; margin-bottom: 15px; font-size: 1.2em; }
                p { margin-bottom: 5px; line-height: 1.4; }
                
                /* Specific styles for print layout */
                .print-header-content {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    margin-bottom: 15px;
                    text-align: center;
                    border-bottom: 2px solid #333;
                    padding-bottom: 10px;
                }
                .print-header-content img {
                    width: 70px;
                    height: 70px;
                    border-radius: 50%;
                    object-fit: cover;
                    margin: 0 15px;
                }
                .print-header-content > div {
                    flex-grow: 1;
                }
                .print-header-content h2 {
                    margin: 0; padding: 0; color: #333; font-size: 1.4em; font-weight: bold;
                }
                .print-header-content p {
                    margin: 0; padding: 0; font-size: 0.85em; color: #555; line-height: 1.3;
                }
                .print-title {
                    margin-top: 15px;
                    color: #000;
                    font-size: 1.5em;
                    font-weight: bold;
                    text-align: center;
                    margin-bottom: 20px;
                }

                .patient-info-print-table {
                    width: 95% !important;
                    margin: 15px auto !important;
                    border-collapse: collapse;
                    font-size: 10pt;
                }
                .patient-info-print-table td {
                    padding: 3px 5px !important;
                    border: 1px solid #ccc !important;
                    vertical-align: top;
                }
                .patient-info-print-table strong {
                    white-space: nowrap;
                }

                .lab-section {
                    border: 1px solid #ddd;
                    padding: 10px;
                    margin-bottom: 15px;
                    page-break-inside: avoid;
                }
                .lab-section h5 {
                    color: #000 !important;
                    border-bottom: 1px solid #ccc !important;
                    padding-bottom: 5px;
                    margin-bottom: 10px;
                    font-size: 1.1em;
                    font-weight: bold;
                }
                .lab-section p {
                    font-size: 10pt;
                    line-height: 1.4;
                }
                .lab-section table {
                    width: 100% !important;
                    border-collapse: collapse;
                    margin-bottom: 10px;
                    font-size: 10pt;
                }
                .lab-section table, .lab-section table th, .lab-section table td {
                    border: 1px solid #ccc !important;
                    padding: 5px !important;
                }
                .lab-section table th {
                    background-color: #f9f9f9 !important;
                    font-weight: bold;
                }
                .widal-table th, .widal-table td {
                    text-align: center;
                }

                .doctor-signature-block {
                    margin-top: 30px;
                    width: 300px;
                    text-align: center;
                    float: right;
                    page-break-before: avoid;
                }
                .doctor-signature-block p {
                    margin: 0;
                    padding: 0;
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
        });
    });
</script>
</body>
</html>