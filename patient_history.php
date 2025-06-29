<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("location:index.php");
    exit;
}

include './config/connection.php';

// --- Fetch Clinic Details by reading index.php content --
// This part remains mostly the same, assuming index.php holds clinic info.
// The display name from session is now set in config/header.php generally.
$clinic_name = "KDCS Clinic (Default)";
$clinic_email = "info@kdcsclinic.com (Default)";
$clinic_address = "Address Not Found (Default)";
$clinic_phone = "Phone Not Found (Default)";

$index_content = file_get_contents('index.php');

if ($index_content !== false) {
    if (preg_match('/<h1><strong>(.*?)<\/strong><\/h1>/', $index_content, $matches)) {
        $clinic_name = strip_tags($matches[1]);
    }
    if (preg_match('/<hr>\s*<h3>Caring for a BETTER Life\?<\/h3>\s*<p>(.*?)<\/p>/s', $index_content, $matches)) {
        $clinic_address = strip_tags($matches[1]);
        $clinic_address = preg_replace('/^Visit us @\s*/', '', $clinic_address);
    }
    if (preg_match('/<hr>\s*<h3>Caring for a BETTER Life\?<\/h3>\s*<p>.*?<\/p>\s*<p><strong>Email:\s*<\/strong>(.*?)\s*\|\s*<strong>Office No.:<\/strong>(.*?)\s*\|\s*<strong>Doctor\'s No.:\s*<\/strong>(.*?)<\/p>/s', $index_content, $matches)) {
        $clinic_email = strip_tags($matches[1]);
        // $clinic_phone might need to combine office and doctor numbers, or pick one.
        $clinic_phone = strip_tags($matches[2]) . " | " . strip_tags($matches[3]);
    }
}
// ------------------------------------------------------------------------

// Set a page title for the header
$page_title = "Laboratory Results";

require_once 'config/header.php'; // Includes <head>, <body>, navbar, and sidebar opening tags

// Helper function to sanitize output
function sanitize_output($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

$message = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']); // Clear the message after displaying
}

// Function to fetch patients for dropdown
function fetchPatients($con, $selectedPatientId = '') {
    $options = '';
    $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $query = "SELECT `id`, `patient_name`, `patient_display_id` FROM `patients` ORDER BY `id` DESC";
    $stmt = $con->prepare($query);
    try {
        $stmt->execute();
    } catch (PDOException $ex) {
        error_log("Error in fetchPatients: " . $ex->getMessage());
        return '<option value="">Error loading patients</option>';
    }
    $options = '<option value="">Select Patient</option>';
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $selected = ($selectedPatientId == $row['id']) ? 'selected' : '';
        $options .= "<option value='{$row['id']}' {$selected}>" . sanitize_output($row['patient_name']) . " (Clinic ID: " . sanitize_output($row['patient_display_id']) . ")</option>";
    }
    return $options;
}

$patients_options = fetchPatients($con, $_GET['patient_id'] ?? '');

// Initialize variables for editing existing result
$is_edit_mode = false;
$lab_result_data = [];
$lab_result_id_from_url = $_GET['lab_result_id'] ?? null;
$patient_id_preselected = $_GET['patient_id'] ?? null;


// Handle POST for saving/updating lab result
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect form data
    $patient_id = $_POST['patient_id'] ?? null;
    $lab_result_id = $_POST['lab_result_id'] ?? null; // For updating
    $ordered_by_user_id = $_SESSION['user_id'] ?? null; // Logged-in user

    // General Lab Comments
    $comment_notes = $_POST['comment_notes'] ?? '';

    // Initialize arrays for lab data (empty if not checked/provided)
    $hematology = [];
    $urine = [];
    $stool = [];
    $microbiology = [];
    $chemistry = [];
    $serology = [];
    $ultrasound = [];
    $xray = [];
    $ecg = [];

    // Populate data based on checked sections
    if (isset($_POST['check_hematology'])) {
        $hematology = [
            'hb' => $_POST['hb'] ?? null,
            'rbc' => $_POST['rbc'] ?? null,
            'wbc' => $_POST['wbc'] ?? null,
            'plt' => $_POST['plt'] ?? null,
            'esr' => $_POST['esr'] ?? null,
            'mcv' => $_POST['mcv'] ?? null,
            'mch' => $_POST['mch'] ?? null,
            'mchc' => $_POST['mchc'] ?? null,
            'neutrophils' => $_POST['neutrophils'] ?? null,
            'lymphocytes' => $_POST['lymphocytes'] ?? null,
            'monocytes' => $_POST['monocytes'] ?? null,
            'eosinophils' => $_POST['eosinophils'] ?? null,
            'basophils' => $_POST['basophils'] ?? null,
            'retics' => $_POST['retics'] ?? null,
            'blood_group' => $_POST['blood_group'] ?? null,
            'coagulation_pt' => $_POST['coagulation_pt'] ?? null,
            'coagulation_aptt' => $_POST['coagulation_aptt'] ?? null,
        ];
    }
    if (isset($_POST['check_urine'])) {
        $urine = [
            'color' => $_POST['urine_color'] ?? null,
            'appearance' => $_POST['urine_appearance'] ?? null,
            'ph' => $_POST['urine_ph'] ?? null,
            'specific_gravity' => $_POST['urine_specific_gravity'] ?? null,
            'proteins' => $_POST['urine_proteins'] ?? null,
            'glucose' => $_POST['urine_glucose'] ?? null,
            'ketones' => $_POST['urine_ketones'] ?? null,
            'blood' => $_POST['urine_blood'] ?? null,
            'leukocytes' => $_POST['urine_leukocytes'] ?? null,
            'nitrite' => $_POST['urine_nitrite'] ?? null,
            'urobilinogen' => $_POST['urine_urobilinogen'] ?? null,
            'bilirubin' => $_POST['urine_bilirubin'] ?? null,
            'micro_rbc' => $_POST['urine_micro_rbc'] ?? null,
            'micro_wbc' => $_POST['urine_micro_wbc'] ?? null,
            'micro_epithelial_cells' => $_POST['urine_micro_epithelial_cells'] ?? null,
            'micro_casts' => $_POST['urine_micro_casts'] ?? null,
            'micro_crystals' => $_POST['urine_micro_crystals'] ?? null,
            'micro_bacteria' => $_POST['urine_micro_bacteria'] ?? null,
            'micro_yeast' => $_POST['urine_micro_yeast'] ?? null,
            'other_micro_findings' => $_POST['urine_other_micro_findings'] ?? null,
        ];
    }
    if (isset($_POST['check_stool_macroscopy'])) { // Stool microscopy implies macroscopy is also checked
        $stool = [
            'color' => $_POST['stool_color'] ?? null,
            'consistency' => $_POST['stool_consistency'] ?? null,
            'blood' => $_POST['stool_blood'] ?? null,
            'mucus' => $_POST['stool_mucus'] ?? null,
            'macroscopy_other' => $_POST['stool_macroscopy_other'] ?? null,
        ];
    }
    if (isset($_POST['check_stool_microscopy'])) {
         // This ensures stool array is initialized even if only microscopy is sent (shouldn't happen with JS sync)
        if (empty($stool)) { $stool = []; }
        $stool = array_merge($stool, [
            'micro_rbc' => $_POST['stool_micro_rbc'] ?? null,
            'micro_wbc' => $_POST['stool_micro_wbc'] ?? null,
            'micro_ova_cysts' => $_POST['stool_micro_ova_cysts'] ?? null,
            'micro_yeast' => $_POST['stool_micro_yeast'] ?? null,
            'micro_fat_globules' => $_POST['stool_micro_fat_globules'] ?? null,
            'micro_starch_granules' => $_POST['stool_micro_starch_granules'] ?? null,
            'micro_muscle_fibers' => $_POST['stool_micro_muscle_fibers'] ?? null,
            'micro_other_findings' => $_POST['stool_micro_other_findings'] ?? null,
        ]);
    }
    if (isset($_POST['check_microbiology'])) {
        $microbiology = [
            'culture_source' => $_POST['culture_source'] ?? null,
            'culture_results' => $_POST['culture_results'] ?? null,
            'antibiotic_sensitivity' => $_POST['antibiotic_sensitivity'] ?? null,
            'gram_stain_result' => $_POST['gram_stain_result'] ?? null,
            'afb_stain_result' => $_POST['afb_stain_result'] ?? null,
        ];
    }
    if (isset($_POST['check_chemistry'])) {
        $chemistry = [
            'glucose_fbs' => $_POST['glucose_fbs'] ?? null,
            'glucose_rbs' => $_POST['glucose_rbs'] ?? null,
            'glucose_2hr_pp' => $_POST['glucose_2hr_pp'] ?? null,
            'hba1c' => $_POST['hba1c'] ?? null,
            'urea' => $_POST['urea'] ?? null,
            'creatinine' => $_POST['creatinine'] ?? null,
            'uric_acid' => $_POST['uric_acid'] ?? null,
            'cholesterol_total' => $_POST['cholesterol_total'] ?? null,
            'triglycerides' => $_POST['triglycerides'] ?? null,
            'hdl' => $_POST['hdl'] ?? null,
            'ldl' => $_POST['ldl'] ?? null,
            'sgpt_alt' => $_POST['sgpt_alt'] ?? null,
            'sgot_ast' => $_POST['sgot_ast'] ?? null,
            'alp' => $_POST['alp'] ?? null,
            'bilirubin_total' => $_POST['bilirubin_total'] ?? null,
            'bilirubin_direct' => $_POST['bilirubin_direct'] ?? null,
            'total_protein' => $_POST['total_protein'] ?? null,
            'albumin' => $_POST['albumin'] ?? null,
            'globulin' => $_POST['globulin'] ?? null,
            'sodium' => $_POST['sodium'] ?? null,
            'potassium' => $_POST['potassium'] ?? null,
            'chloride' => $_POST['chloride'] ?? null,
            'calcium' => $_POST['calcium'] ?? null,
            'phosphorus' => $_POST['phosphorus'] ?? null,
            'amylase' => $_POST['amylase'] ?? null,
            'lipase' => $_POST['lipase'] ?? null,
            'thyroid_tsh' => $_POST['thyroid_tsh'] ?? null,
            'thyroid_t3' => $_POST['thyroid_t3'] ?? null,
            'thyroid_t4' => $_POST['thyroid_t4'] ?? null,
        ];
    }
    if (isset($_POST['check_serology'])) {
        $serology = [
            'widal_o' => $_POST['widal_o'] ?? null,
            'widal_h' => $_POST['widal_h'] ?? null,
            'widal_ah' => $_POST['widal_ah'] ?? null,
            'widal_bh' => $_POST['widal_bh'] ?? null,
            'rpr_vdrl' => $_POST['rpr_vdrl'] ?? null,
            'hcv' => $_POST['hcv'] ?? null,
            'hbsag' => $_POST['hbsag'] ?? null,
            'hiv_1_2' => $_POST['hiv_1_2'] ?? null,
            'aso_titer' => $_POST['aso_titer'] ?? null,
            'crp' => $_POST['crp'] ?? null,
            'rf_factor' => $_POST['rf_factor'] ?? null,
            'typhidot_igm' => $_POST['typhidot_igm'] ?? null,
            'typhidot_igg' => $_POST['typhidot_igg'] ?? null,
            'malaria_mp' => $_POST['malaria_mp'] ?? null,
            'dengue_ns1' => $_POST['dengue_ns1'] ?? null,
            'dengue_igm' => $_POST['dengue_igm'] ?? null,
            'dengue_igg' => $_POST['dengue_igg'] ?? null,
            'troponin_i' => $_POST['troponin_i'] ?? null,
            'ck_mb' => $_POST['ck_mb'] ?? null,
        ];
    }
    if (isset($_POST['check_ultrasound'])) {
        $ultrasound = [
            'ultrasound_findings' => $_POST['ultrasound_findings'] ?? null,
            'ultrasound_impression' => $_POST['ultrasound_impression'] ?? null,
        ];
    }
    if (isset($_POST['check_xray'])) {
        $xray = [
            'xray_findings' => $_POST['xray_findings'] ?? null,
            'xray_impression' => $_POST['xray_impression'] ?? null,
        ];
    }
    if (isset($_POST['check_ecg'])) {
        $ecg = [
            'ecg_findings' => $_POST['ecg_findings'] ?? null,
            'ecg_impression' => $_POST['ecg_impression'] ?? null,
        ];
    }

    try {
        $con->beginTransaction();

        $stmt = null;
        if ($lab_result_id) { // Update existing record
            $stmt = $con->prepare("UPDATE lab_results SET patient_id = ?, comment_notes = ?, hematology = ?, urine = ?, stool = ?, microbiology = ?, chemistry = ?, serology = ?, ultrasound = ?, xray = ?, ecg = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([
                $patient_id,
                $comment_notes,
                json_encode($hematology),
                json_encode($urine),
                json_encode($stool),
                json_encode($microbiology),
                json_encode($chemistry),
                json_encode($serology),
                json_encode($ultrasound),
                json_encode($xray),
                json_encode($ecg),
                $lab_result_id
            ]);
            $_SESSION['message'] = "Lab result updated successfully!";
        } else { // Insert new record
            $stmt = $con->prepare("INSERT INTO lab_results (patient_id, ordered_by_user_id, comment_notes, hematology, urine, stool, microbiology, chemistry, serology, ultrasound, xray, ecg) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $patient_id,
                $ordered_by_user_id,
                $comment_notes,
                json_encode($hematology),
                json_encode($urine),
                json_encode($stool),
                json_encode($microbiology),
                json_encode($chemistry),
                json_encode($serology),
                json_encode($ultrasound),
                json_encode($xray),
                json_encode($ecg)
            ]);
            $_SESSION['message'] = "Lab result saved successfully!";
        }
        $con->commit();
        header("location: laboratory.php?patient_id=" . $patient_id); // Redirect to prevent re-submission
        exit;
    } catch (PDOException $e) {
        $con->rollBack();
        error_log("Database error saving lab result: " . $e->getMessage());
        $message = "<div class='alert alert-danger'>Error saving lab result: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// Fetch existing lab result for editing/viewing
if ($lab_result_id_from_url) {
    $is_edit_mode = true;
    try {
        $stmt = $con->prepare("SELECT * FROM lab_results WHERE id = ?");
        $stmt->execute([$lab_result_id_from_url]);
        $lab_result_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($lab_result_data) {
            // Decode JSON strings back to arrays
            $lab_result_data['hematology'] = json_decode($lab_result_data['hematology'] ?? '{}', true);
            $lab_result_data['urine'] = json_decode($lab_result_data['urine'] ?? '{}', true);
            $lab_result_data['stool'] = json_decode($lab_result_data['stool'] ?? '{}', true);
            $lab_result_data['microbiology'] = json_decode($lab_result_data['microbiology'] ?? '{}', true);
            $lab_result_data['chemistry'] = json_decode($lab_result_data['chemistry'] ?? '{}', true);
            $lab_result_data['serology'] = json_decode($lab_result_data['serology'] ?? '{}', true);
            $lab_result_data['ultrasound'] = json_decode($lab_result_data['ultrasound'] ?? '{}', true);
            $lab_result_data['xray'] = json_decode($lab_result_data['xray'] ?? '{}', true);
            $lab_result_data['ecg'] = json_decode($lab_result_data['ecg'] ?? '{}', true);

            // Pre-select patient dropdown
            $patient_id_preselected = $lab_result_data['patient_id'];
            $patients_options = fetchPatients($con, $patient_id_preselected);

        } else {
            $message = "<div class='alert alert-warning'>Lab result not found.</div>";
            $is_edit_mode = false;
        }
    } catch (PDOException $e) {
        error_log("Database error fetching lab result: " . $e->getMessage());
        $message = "<div class='alert alert-danger'>Error fetching lab result: " . htmlspecialchars($e->getMessage()) . "</div>";
        $is_edit_mode = false;
    }
}


?>
<div class="container-fluid py-4">
    <?php if ($message): ?>
        <?php echo $message; ?>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0"><i class="fas fa-flask"></i> <?php echo $is_edit_mode ? 'View/Edit Lab Result' : 'New Lab Result'; ?></h2>
        <a href="patient_history.php<?php echo $patient_id_preselected ? '?patient_id=' . $patient_id_preselected : ''; ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> Back to Patient History
        </a>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Patient Information</h5>
        </div>
        <div class="card-body">
            <div class="form-group row mb-3">
                <label for="patient_id" class="col-md-3 col-form-label">Select Patient:</label>
                <div class="col-md-9">
                    <select class="form-control" id="patient_id" name="patient_id" required <?php echo $is_edit_mode ? 'disabled' : ''; ?>>
                        <?php echo $patients_options; ?>
                    </select>
                    <?php if ($is_edit_mode): // Add hidden field for disabled select ?>
                        <input type="hidden" name="patient_id" value="<?php echo sanitize_output($patient_id_preselected); ?>">
                    <?php endif; ?>
                </div>
            </div>
            <div class="card bg-light p-3">
                <div class="row">
                    <div class="col-md-6 mb-2"><strong>Patient Name:</strong> <span id="display_patient_name">N/A</span></div>
                    <div class="col-md-6 mb-2"><strong>Clinic ID:</strong> <span id="display_clinic_id">N/A</span></div>
                    <div class="col-md-6 mb-2"><strong>CNIC:</strong> <span id="display_cnic">N/A</span></div>
                    <div class="col-md-6 mb-2"><strong>Date of Birth:</strong> <span id="display_dob">N/A</span></div>
                    <div class="col-md-6 mb-2"><strong>Contact No:</strong> <span id="display_contact_no">N/A</span></div>
                    <div class="col-md-6 mb-2"><strong>Gender:</strong> <span id="display_gender">N/A</span></div>
                    <div class="col-md-6 mb-2"><strong>Marital Status:</strong> <span id="display_marital_status">N/A</span></div>
                </div>
            </div>
        </div>
    </div>

    <form id="labResultForm" method="POST" action="laboratory.php">
        <input type="hidden" name="lab_result_id" value="<?php echo $lab_result_id_from_url; ?>">
        <input type="hidden" name="ordered_by_user_id" value="<?php echo $_SESSION['user_id']; ?>">

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0">Lab Test Details</h5>
            </div>
            <div class="card-body">
                <div class="form-group mb-3">
                    <label for="comment_notes">General Comments / Notes:</label>
                    <textarea class="form-control" id="comment_notes" name="comment_notes" rows="3" <?php echo $is_edit_mode ? 'readonly' : ''; ?>><?php echo sanitize_output($lab_result_data['comment_notes'] ?? ''); ?></textarea>
                </div>

                <hr class="my-4">

                <div class="form-group mb-4">
                    <label class="form-label d-block mb-2">Select Test Types to Include:</label>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input test-type-checkbox" type="checkbox" id="check_hematology" name="check_hematology" value="1" data-target="#hematologyCard" <?php echo !empty($lab_result_data['hematology']) || $is_edit_mode ? 'checked' : ''; ?> <?php echo $is_edit_mode ? 'disabled' : ''; ?>>
                        <label class="form-check-label" for="check_hematology">Hematology</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input test-type-checkbox" type="checkbox" id="check_urine" name="check_urine" value="1" data-target="#urineCard" <?php echo !empty($lab_result_data['urine']) || $is_edit_mode ? 'checked' : ''; ?> <?php echo $is_edit_mode ? 'disabled' : ''; ?>>
                        <label class="form-check-label" for="check_urine">Urine Analysis</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input test-type-checkbox" type="checkbox" id="check_stool_macroscopy" name="check_stool_macroscopy" value="1" data-target="#stoolCard" <?php echo !empty($lab_result_data['stool']) || $is_edit_mode ? 'checked' : ''; ?> <?php echo $is_edit_mode ? 'disabled' : ''; ?>>
                        <label class="form-check-label" for="check_stool_macroscopy">Stool Analysis</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input test-type-checkbox" type="checkbox" id="check_microbiology" name="check_microbiology" value="1" data-target="#microbiologyCard" <?php echo !empty($lab_result_data['microbiology']) || $is_edit_mode ? 'checked' : ''; ?> <?php echo $is_edit_mode ? 'disabled' : ''; ?>>
                        <label class="form-check-label" for="check_microbiology">Microbiology</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input test-type-checkbox" type="checkbox" id="check_chemistry" name="check_chemistry" value="1" data-target="#chemistryCard" <?php echo !empty($lab_result_data['chemistry']) || $is_edit_mode ? 'checked' : ''; ?> <?php echo $is_edit_mode ? 'disabled' : ''; ?>>
                        <label class="form-check-label" for="check_chemistry">Clinical Chemistry</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input test-type-checkbox" type="checkbox" id="check_serology" name="check_serology" value="1" data-target="#serologyCard" <?php echo !empty($lab_result_data['serology']) || $is_edit_mode ? 'checked' : ''; ?> <?php echo $is_edit_mode ? 'disabled' : ''; ?>>
                        <label class="form-check-label" for="check_serology">Serology</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input test-type-checkbox" type="checkbox" id="check_ultrasound" name="check_ultrasound" value="1" data-target="#ultrasoundCard" <?php echo !empty($lab_result_data['ultrasound']) || $is_edit_mode ? 'checked' : ''; ?> <?php echo $is_edit_mode ? 'disabled' : ''; ?>>
                        <label class="form-check-label" for="check_ultrasound">Ultrasound</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input test-type-checkbox" type="checkbox" id="check_xray" name="check_xray" value="1" data-target="#xrayCard" <?php echo !empty($lab_result_data['xray']) || $is_edit_mode ? 'checked' : ''; ?> <?php echo $is_edit_mode ? 'disabled' : ''; ?>>
                        <label class="form-check-label" for="check_xray">X-Ray</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input test-type-checkbox" type="checkbox" id="check_ecg" name="check_ecg" value="1" data-target="#ecgCard" <?php echo !empty($lab_result_data['ecg']) || $is_edit_mode ? 'checked' : ''; ?> <?php echo $is_edit_mode ? 'disabled' : ''; ?>>
                        <label class="form-check-label" for="check_ecg">ECG</label>
                    </div>
                </div>

                <div class="card mb-3 test-section <?php echo (!empty($lab_result_data['hematology']) || $is_edit_mode) ? 'd-block' : 'd-none'; ?>" id="hematologyCard">
                    <div class="card-header bg-info text-white" data-toggle="collapse" data-target="#hematologyCollapse" aria-expanded="true" aria-controls="hematologyCollapse">
                        <h6 class="mb-0"><i class="fas fa-tint"></i> Hematology <i class="fas fa-chevron-down float-right collapse-icon"></i></h6>
                    </div>
                    <div id="hematologyCollapse" class="collapse <?php echo (!empty($lab_result_data['hematology']) || $is_edit_mode) ? 'show' : ''; ?>">
                        <div class="card-body">
                            <div class="row">
                                <div class="form-group col-md-4">
                                    <label for="hb">HB (g/dL):</label>
                                    <input type="text" class="form-control" id="hb" name="hb" value="<?php echo sanitize_output($lab_result_data['hematology']['hb'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="rbc">RBC (x10^12/L):</label>
                                    <input type="text" class="form-control" id="rbc" name="rbc" value="<?php echo sanitize_output($lab_result_data['hematology']['rbc'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="wbc">WBC (x10^9/L):</label>
                                    <input type="text" class="form-control" id="wbc" name="wbc" value="<?php echo sanitize_output($lab_result_data['hematology']['wbc'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="plt">PLT (x10^9/L):</label>
                                    <input type="text" class="form-control" id="plt" name="plt" value="<?php echo sanitize_output($lab_result_data['hematology']['plt'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="esr">ESR (mm/hr):</label>
                                    <input type="text" class="form-control" id="esr" name="esr" value="<?php echo sanitize_output($lab_result_data['hematology']['esr'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="mcv">MCV (fL):</label>
                                    <input type="text" class="form-control" id="mcv" name="mcv" value="<?php echo sanitize_output($lab_result_data['hematology']['mcv'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="mch">MCH (pg):</label>
                                    <input type="text" class="form-control" id="mch" name="mch" value="<?php echo sanitize_output($lab_result_data['hematology']['mch'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="mchc">MCHC (g/dL):</label>
                                    <input type="text" class="form-control" id="mchc" name="mchc" value="<?php echo sanitize_output($lab_result_data['hematology']['mchc'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="neutrophils">Neutrophils (%):</label>
                                    <input type="text" class="form-control" id="neutrophils" name="neutrophils" value="<?php echo sanitize_output($lab_result_data['hematology']['neutrophils'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="lymphocytes">Lymphocytes (%):</label>
                                    <input type="text" class="form-control" id="lymphocytes" name="lymphocytes" value="<?php echo sanitize_output($lab_result_data['hematology']['lymphocytes'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="monocytes">Monocytes (%):</label>
                                    <input type="text" class="form-control" id="monocytes" name="monocytes" value="<?php echo sanitize_output($lab_result_data['hematology']['monocytes'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="eosinophils">Eosinophils (%):</label>
                                    <input type="text" class="form-control" id="eosinophils" name="eosinophils" value="<?php echo sanitize_output($lab_result_data['hematology']['eosinophils'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="basophils">Basophils (%):</label>
                                    <input type="text" class="form-control" id="basophils" name="basophils" value="<?php echo sanitize_output($lab_result_data['hematology']['basophils'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="retics">Retics (%):</label>
                                    <input type="text" class="form-control" id="retics" name="retics" value="<?php echo sanitize_output($lab_result_data['hematology']['retics'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="blood_group">Blood Group:</label>
                                    <input type="text" class="form-control" id="blood_group" name="blood_group" value="<?php echo sanitize_output($lab_result_data['hematology']['blood_group'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="coagulation_pt">Coagulation PT (seconds):</label>
                                    <input type="text" class="form-control" id="coagulation_pt" name="coagulation_pt" value="<?php echo sanitize_output($lab_result_data['hematology']['coagulation_pt'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="coagulation_aptt">Coagulation APTT (seconds):</label>
                                    <input type="text" class="form-control" id="coagulation_aptt" name="coagulation_aptt" value="<?php echo sanitize_output($lab_result_data['hematology']['coagulation_aptt'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3 test-section <?php echo (!empty($lab_result_data['urine']) || $is_edit_mode) ? 'd-block' : 'd-none'; ?>" id="urineCard">
                    <div class="card-header bg-info text-white" data-toggle="collapse" data-target="#urineCollapse" aria-expanded="true" aria-controls="urineCollapse">
                        <h6 class="mb-0"><i class="fas fa-flask-water"></i> Urine Analysis <i class="fas fa-chevron-down float-right collapse-icon"></i></h6>
                    </div>
                    <div id="urineCollapse" class="collapse <?php echo (!empty($lab_result_data['urine']) || $is_edit_mode) ? 'show' : ''; ?>">
                        <div class="card-body">
                            <h6>Macroscopic Examination</h6>
                            <div class="row">
                                <div class="form-group col-md-4">
                                    <label for="urine_color">Color:</label>
                                    <input type="text" class="form-control" id="urine_color" name="urine_color" value="<?php echo sanitize_output($lab_result_data['urine']['color'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="urine_appearance">Appearance:</label>
                                    <input type="text" class="form-control" id="urine_appearance" name="urine_appearance" value="<?php echo sanitize_output($lab_result_data['urine']['appearance'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="urine_ph">pH:</label>
                                    <input type="text" class="form-control" id="urine_ph" name="urine_ph" value="<?php echo sanitize_output($lab_result_data['urine']['ph'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="urine_specific_gravity">Specific Gravity:</label>
                                    <input type="text" class="form-control" id="urine_specific_gravity" name="urine_specific_gravity" value="<?php echo sanitize_output($lab_result_data['urine']['specific_gravity'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                            </div>
                            <h6 class="mt-3">Chemical Examination</h6>
                            <div class="row">
                                <div class="form-group col-md-4">
                                    <label for="urine_proteins">Proteins:</label>
                                    <input type="text" class="form-control" id="urine_proteins" name="urine_proteins" value="<?php echo sanitize_output($lab_result_data['urine']['proteins'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="urine_glucose">Glucose:</label>
                                    <input type="text" class="form-control" id="urine_glucose" name="urine_glucose" value="<?php echo sanitize_output($lab_result_data['urine']['glucose'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="urine_ketones">Ketones:</label>
                                    <input type="text" class="form-control" id="urine_ketones" name="urine_ketones" value="<?php echo sanitize_output($lab_result_data['urine']['ketones'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="urine_blood">Blood:</label>
                                    <input type="text" class="form-control" id="urine_blood" name="urine_blood" value="<?php echo sanitize_output($lab_result_data['urine']['blood'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="urine_leukocytes">Leukocytes:</label>
                                    <input type="text" class="form-control" id="urine_leukocytes" name="urine_leukocytes" value="<?php echo sanitize_output($lab_result_data['urine']['leukocytes'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="urine_nitrite">Nitrite:</label>
                                    <input type="text" class="form-control" id="urine_nitrite" name="urine_nitrite" value="<?php echo sanitize_output($lab_result_data['urine']['nitrite'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="urine_urobilinogen">Urobilinogen:</label>
                                    <input type="text" class="form-control" id="urine_urobilinogen" name="urine_urobilinogen" value="<?php echo sanitize_output($lab_result_data['urine']['urobilinogen'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="urine_bilirubin">Bilirubin:</label>
                                    <input type="text" class="form-control" id="urine_bilirubin" name="urine_bilirubin" value="<?php echo sanitize_output($lab_result_data['urine']['bilirubin'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                            </div>
                            <h6 class="mt-3">Microscopic Examination</h6>
                            <div class="row">
                                <div class="form-group col-md-4">
                                    <label for="urine_micro_rbc">RBC (HPF):</label>
                                    <input type="text" class="form-control" id="urine_micro_rbc" name="urine_micro_rbc" value="<?php echo sanitize_output($lab_result_data['urine']['micro_rbc'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="urine_micro_wbc">WBC (HPF):</label>
                                    <input type="text" class="form-control" id="urine_micro_wbc" name="urine_micro_wbc" value="<?php echo sanitize_output($lab_result_data['urine']['micro_wbc'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="urine_micro_epithelial_cells">Epithelial Cells:</label>
                                    <input type="text" class="form-control" id="urine_micro_epithelial_cells" name="urine_micro_epithelial_cells" value="<?php echo sanitize_output($lab_result_data['urine']['micro_epithelial_cells'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="urine_micro_casts">Casts:</label>
                                    <input type="text" class="form-control" id="urine_micro_casts" name="urine_micro_casts" value="<?php echo sanitize_output($lab_result_data['urine']['micro_casts'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="urine_micro_crystals">Crystals:</label>
                                    <input type="text" class="form-control" id="urine_micro_crystals" name="urine_micro_crystals" value="<?php echo sanitize_output($lab_result_data['urine']['micro_crystals'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="urine_micro_bacteria">Bacteria:</label>
                                    <input type="text" class="form-control" id="urine_micro_bacteria" name="urine_micro_bacteria" value="<?php echo sanitize_output($lab_result_data['urine']['micro_bacteria'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="urine_micro_yeast">Yeast:</label>
                                    <input type="text" class="form-control" id="urine_micro_yeast" name="urine_micro_yeast" value="<?php echo sanitize_output($lab_result_data['urine']['micro_yeast'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-8">
                                    <label for="urine_other_micro_findings">Other Microscopic Findings:</label>
                                    <input type="text" class="form-control" id="urine_other_micro_findings" name="urine_other_micro_findings" value="<?php echo sanitize_output($lab_result_data['urine']['other_micro_findings'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3 test-section <?php echo (!empty($lab_result_data['stool']) || $is_edit_mode) ? 'd-block' : 'd-none'; ?>" id="stoolCard">
                    <div class="card-header bg-info text-white" data-toggle="collapse" data-target="#stoolCollapse" aria-expanded="true" aria-controls="stoolCollapse">
                        <h6 class="mb-0"><i class="fas fa-poo"></i> Stool Analysis <i class="fas fa-chevron-down float-right collapse-icon"></i></h6>
                    </div>
                    <div id="stoolCollapse" class="collapse <?php echo (!empty($lab_result_data['stool']) || $is_edit_mode) ? 'show' : ''; ?>">
                        <div class="card-body">
                            <div class="form-check mb-3">
                                <input class="form-check-input test-type-checkbox" type="checkbox" id="check_stool_microscopy" name="check_stool_microscopy" value="1" data-target="#stoolMicroscopySection" <?php echo !empty($lab_result_data['stool']['micro_rbc']) || !empty($lab_result_data['stool']['micro_wbc']) || $is_edit_mode ? 'checked' : ''; ?> <?php echo $is_edit_mode ? 'disabled' : ''; ?>>
                                <label class="form-check-label" for="check_stool_microscopy">Include Microscopic Examination</label>
                            </div>
                            <h6>Macroscopic Examination</h6>
                            <div class="row">
                                <div class="form-group col-md-4">
                                    <label for="stool_color">Color:</label>
                                    <input type="text" class="form-control" id="stool_color" name="stool_color" value="<?php echo sanitize_output($lab_result_data['stool']['color'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="stool_consistency">Consistency:</label>
                                    <input type="text" class="form-control" id="stool_consistency" name="stool_consistency" value="<?php echo sanitize_output($lab_result_data['stool']['consistency'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="stool_blood">Blood:</label>
                                    <input type="text" class="form-control" id="stool_blood" name="stool_blood" value="<?php echo sanitize_output($lab_result_data['stool']['blood'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="stool_mucus">Mucus:</label>
                                    <input type="text" class="form-control" id="stool_mucus" name="stool_mucus" value="<?php echo sanitize_output($lab_result_data['stool']['mucus'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-8">
                                    <label for="stool_macroscopy_other">Other Macroscopic Findings:</label>
                                    <input type="text" class="form-control" id="stool_macroscopy_other" name="stool_macroscopy_other" value="<?php echo sanitize_output($lab_result_data['stool']['macroscopy_other'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                            </div>
                            <div id="stoolMicroscopySection" class="mt-3 <?php echo (!empty($lab_result_data['stool']['micro_rbc']) || !empty($lab_result_data['stool']['micro_wbc']) || $is_edit_mode) ? 'd-block' : 'd-none'; ?>">
                                <h6>Microscopic Examination</h6>
                                <div class="row">
                                    <div class="form-group col-md-4">
                                        <label for="stool_micro_rbc">RBC (HPF):</label>
                                        <input type="text" class="form-control" id="stool_micro_rbc" name="stool_micro_rbc" value="<?php echo sanitize_output($lab_result_data['stool']['micro_rbc'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label for="stool_micro_wbc">WBC (HPF):</label>
                                        <input type="text" class="form-control" id="stool_micro_wbc" name="stool_micro_wbc" value="<?php echo sanitize_output($lab_result_data['stool']['micro_wbc'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label for="stool_micro_ova_cysts">Ova/Cysts:</label>
                                        <input type="text" class="form-control" id="stool_micro_ova_cysts" name="stool_micro_ova_cysts" value="<?php echo sanitize_output($lab_result_data['stool']['micro_ova_cysts'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label for="stool_micro_yeast">Yeast:</label>
                                        <input type="text" class="form-control" id="stool_micro_yeast" name="stool_micro_yeast" value="<?php echo sanitize_output($lab_result_data['stool']['micro_yeast'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label for="stool_micro_fat_globules">Fat Globules:</label>
                                        <input type="text" class="form-control" id="stool_micro_fat_globules" name="stool_micro_fat_globules" value="<?php echo sanitize_output($lab_result_data['stool']['micro_fat_globules'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label for="stool_micro_starch_granules">Starch Granules:</label>
                                        <input type="text" class="form-control" id="stool_micro_starch_granules" name="stool_micro_starch_granules" value="<?php echo sanitize_output($lab_result_data['stool']['micro_starch_granules'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label for="stool_micro_muscle_fibers">Muscle Fibers:</label>
                                        <input type="text" class="form-control" id="stool_micro_muscle_fibers" name="stool_micro_muscle_fibers" value="<?php echo sanitize_output($lab_result_data['stool']['micro_muscle_fibers'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                    </div>
                                    <div class="form-group col-md-8">
                                        <label for="stool_micro_other_findings">Other Microscopic Findings:</label>
                                        <input type="text" class="form-control" id="stool_micro_other_findings" name="stool_micro_other_findings" value="<?php echo sanitize_output($lab_result_data['stool']['micro_other_findings'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3 test-section <?php echo (!empty($lab_result_data['microbiology']) || $is_edit_mode) ? 'd-block' : 'd-none'; ?>" id="microbiologyCard">
                    <div class="card-header bg-info text-white" data-toggle="collapse" data-target="#microbiologyCollapse" aria-expanded="true" aria-controls="microbiologyCollapse">
                        <h6 class="mb-0"><i class="fas fa-bacteria"></i> Microbiology <i class="fas fa-chevron-down float-right collapse-icon"></i></h6>
                    </div>
                    <div id="microbiologyCollapse" class="collapse <?php echo (!empty($lab_result_data['microbiology']) || $is_edit_mode) ? 'show' : ''; ?>">
                        <div class="card-body">
                            <div class="row">
                                <div class="form-group col-md-6">
                                    <label for="culture_source">Culture Source:</label>
                                    <input type="text" class="form-control" id="culture_source" name="culture_source" value="<?php echo sanitize_output($lab_result_data['microbiology']['culture_source'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="culture_results">Culture Results:</label>
                                    <input type="text" class="form-control" id="culture_results" name="culture_results" value="<?php echo sanitize_output($lab_result_data['microbiology']['culture_results'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-12">
                                    <label for="antibiotic_sensitivity">Antibiotic Sensitivity:</label>
                                    <textarea class="form-control" id="antibiotic_sensitivity" name="antibiotic_sensitivity" rows="3" <?php echo $is_edit_mode ? 'readonly' : ''; ?>><?php echo sanitize_output($lab_result_data['microbiology']['antibiotic_sensitivity'] ?? ''); ?></textarea>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="gram_stain_result">Gram Stain Result:</label>
                                    <input type="text" class="form-control" id="gram_stain_result" name="gram_stain_result" value="<?php echo sanitize_output($lab_result_data['microbiology']['gram_stain_result'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="afb_stain_result">AFB Stain Result:</label>
                                    <input type="text" class="form-control" id="afb_stain_result" name="afb_stain_result" value="<?php echo sanitize_output($lab_result_data['microbiology']['afb_stain_result'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3 test-section <?php echo (!empty($lab_result_data['chemistry']) || $is_edit_mode) ? 'd-block' : 'd-none'; ?>" id="chemistryCard">
                    <div class="card-header bg-info text-white" data-toggle="collapse" data-target="#chemistryCollapse" aria-expanded="true" aria-controls="chemistryCollapse">
                        <h6 class="mb-0"><i class="fas fa-vials"></i> Clinical Chemistry <i class="fas fa-chevron-down float-right collapse-icon"></i></h6>
                    </div>
                    <div id="chemistryCollapse" class="collapse <?php echo (!empty($lab_result_data['chemistry']) || $is_edit_mode) ? 'show' : ''; ?>">
                        <div class="card-body">
                            <div class="row">
                                <div class="form-group col-md-4">
                                    <label for="glucose_fbs">Glucose FBS (mg/dL):</label>
                                    <input type="text" class="form-control" id="glucose_fbs" name="glucose_fbs" value="<?php echo sanitize_output($lab_result_data['chemistry']['glucose_fbs'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="glucose_rbs">Glucose RBS (mg/dL):</label>
                                    <input type="text" class="form-control" id="glucose_rbs" name="glucose_rbs" value="<?php echo sanitize_output($lab_result_data['chemistry']['glucose_rbs'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="glucose_2hr_pp">Glucose 2hr PP (mg/dL):</label>
                                    <input type="text" class="form-control" id="glucose_2hr_pp" name="glucose_2hr_pp" value="<?php echo sanitize_output($lab_result_data['chemistry']['glucose_2hr_pp'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="hba1c">HbA1c (%):</label>
                                    <input type="text" class="form-control" id="hba1c" name="hba1c" value="<?php echo sanitize_output($lab_result_data['chemistry']['hba1c'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="urea">Urea (mg/dL):</label>
                                    <input type="text" class="form-control" id="urea" name="urea" value="<?php echo sanitize_output($lab_result_data['chemistry']['urea'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="creatinine">Creatinine (mg/dL):</label>
                                    <input type="text" class="form-control" id="creatinine" name="creatinine" value="<?php echo sanitize_output($lab_result_data['chemistry']['creatinine'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="uric_acid">Uric Acid (mg/dL):</label>
                                    <input type="text" class="form-control" id="uric_acid" name="uric_acid" value="<?php echo sanitize_output($lab_result_data['chemistry']['uric_acid'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="cholesterol_total">Cholesterol Total (mg/dL):</label>
                                    <input type="text" class="form-control" id="cholesterol_total" name="cholesterol_total" value="<?php echo sanitize_output($lab_result_data['chemistry']['cholesterol_total'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="triglycerides">Triglycerides (mg/dL):</label>
                                    <input type="text" class="form-control" id="triglycerides" name="triglycerides" value="<?php echo sanitize_output($lab_result_data['chemistry']['triglycerides'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="hdl">HDL (mg/dL):</label>
                                    <input type="text" class="form-control" id="hdl" name="hdl" value="<?php echo sanitize_output($lab_result_data['chemistry']['hdl'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="ldl">LDL (mg/dL):</label>
                                    <input type="text" class="form-control" id="ldl" name="ldl" value="<?php echo sanitize_output($lab_result_data['chemistry']['ldl'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="sgpt_alt">SGPT (ALT) (U/L):</label>
                                    <input type="text" class="form-control" id="sgpt_alt" name="sgpt_alt" value="<?php echo sanitize_output($lab_result_data['chemistry']['sgpt_alt'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="sgot_ast">SGOT (AST) (U/L):</label>
                                    <input type="text" class="form-control" id="sgot_ast" name="sgot_ast" value="<?php echo sanitize_output($lab_result_data['chemistry']['sgot_ast'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="alp">ALP (U/L):</label>
                                    <input type="text" class="form-control" id="alp" name="alp" value="<?php echo sanitize_output($lab_result_data['chemistry']['alp'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="bilirubin_total">Bilirubin Total (mg/dL):</label>
                                    <input type="text" class="form-control" id="bilirubin_total" name="bilirubin_total" value="<?php echo sanitize_output($lab_result_data['chemistry']['bilirubin_total'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="bilirubin_direct">Bilirubin Direct (mg/dL):</label>
                                    <input type="text" class="form-control" id="bilirubin_direct" name="bilirubin_direct" value="<?php echo sanitize_output($lab_result_data['chemistry']['bilirubin_direct'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="total_protein">Total Protein (g/dL):</label>
                                    <input type="text" class="form-control" id="total_protein" name="total_protein" value="<?php echo sanitize_output($lab_result_data['chemistry']['total_protein'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="albumin">Albumin (g/dL):</label>
                                    <input type="text" class="form-control" id="albumin" name="albumin" value="<?php echo sanitize_output($lab_result_data['chemistry']['albumin'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="globulin">Globulin (g/dL):</label>
                                    <input type="text" class="form-control" id="globulin" name="globulin" value="<?php echo sanitize_output($lab_result_data['chemistry']['globulin'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="sodium">Sodium (mEq/L):</label>
                                    <input type="text" class="form-control" id="sodium" name="sodium" value="<?php echo sanitize_output($lab_result_data['chemistry']['sodium'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="potassium">Potassium (mEq/L):</label>
                                    <input type="text" class="form-control" id="potassium" name="potassium" value="<?php echo sanitize_output($lab_result_data['chemistry']['potassium'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="chloride">Chloride (mEq/L):</label>
                                    <input type="text" class="form-control" id="chloride" name="chloride" value="<?php echo sanitize_output($lab_result_data['chemistry']['chloride'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="calcium">Calcium (mg/dL):</label>
                                    <input type="text" class="form-control" id="calcium" name="calcium" value="<?php echo sanitize_output($lab_result_data['chemistry']['calcium'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="phosphorus">Phosphorus (mg/dL):</label>
                                    <input type="text" class="form-control" id="phosphorus" name="phosphorus" value="<?php echo sanitize_output($lab_result_data['chemistry']['phosphorus'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="amylase">Amylase (U/L):</label>
                                    <input type="text" class="form-control" id="amylase" name="amylase" value="<?php echo sanitize_output($lab_result_data['chemistry']['amylase'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="lipase">Lipase (U/L):</label>
                                    <input type="text" class="form-control" id="lipase" name="lipase" value="<?php echo sanitize_output($lab_result_data['chemistry']['lipase'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="thyroid_tsh">Thyroid TSH (µIU/mL):</label>
                                    <input type="text" class="form-control" id="thyroid_tsh" name="thyroid_tsh" value="<?php echo sanitize_output($lab_result_data['chemistry']['thyroid_tsh'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="thyroid_t3">Thyroid T3 (ng/dL):</label>
                                    <input type="text" class="form-control" id="thyroid_t3" name="thyroid_t3" value="<?php echo sanitize_output($lab_result_data['chemistry']['thyroid_t3'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="thyroid_t4">Thyroid T4 (µg/dL):</label>
                                    <input type="text" class="form-control" id="thyroid_t4" name="thyroid_t4" value="<?php echo sanitize_output($lab_result_data['chemistry']['thyroid_t4'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3 test-section <?php echo (!empty($lab_result_data['serology']) || $is_edit_mode) ? 'd-block' : 'd-none'; ?>" id="serologyCard">
                    <div class="card-header bg-info text-white" data-toggle="collapse" data-target="#serologyCollapse" aria-expanded="true" aria-controls="serologyCollapse">
                        <h6 class="mb-0"><i class="fas fa-microscope"></i> Serology <i class="fas fa-chevron-down float-right collapse-icon"></i></h6>
                    </div>
                    <div id="serologyCollapse" class="collapse <?php echo (!empty($lab_result_data['serology']) || $is_edit_mode) ? 'show' : ''; ?>">
                        <div class="card-body">
                            <div class="row">
                                <div class="form-group col-md-4">
                                    <label for="widal_o">Widal 'O':</label>
                                    <input type="text" class="form-control" id="widal_o" name="widal_o" value="<?php echo sanitize_output($lab_result_data['serology']['widal_o'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="widal_h">Widal 'H':</label>
                                    <input type="text" class="form-control" id="widal_h" name="widal_h" value="<?php echo sanitize_output($lab_result_data['serology']['widal_h'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="widal_ah">Widal 'AH':</label>
                                    <input type="text" class="form-control" id="widal_ah" name="widal_ah" value="<?php echo sanitize_output($lab_result_data['serology']['widal_ah'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="widal_bh">Widal 'BH':</label>
                                    <input type="text" class="form-control" id="widal_bh" name="widal_bh" value="<?php echo sanitize_output($lab_result_data['serology']['widal_bh'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="rpr_vdrl">RPR/VDRL:</label>
                                    <input type="text" class="form-control" id="rpr_vdrl" name="rpr_vdrl" value="<?php echo sanitize_output($lab_result_data['serology']['rpr_vdrl'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="hcv">HCV:</label>
                                    <input type="text" class="form-control" id="hcv" name="hcv" value="<?php echo sanitize_output($lab_result_data['serology']['hcv'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="hbsag">HBsAg:</label>
                                    <input type="text" class="form-control" id="hbsag" name="hbsag" value="<?php echo sanitize_output($lab_result_data['serology']['hbsag'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="hiv_1_2">HIV 1 & 2:</label>
                                    <input type="text" class="form-control" id="hiv_1_2" name="hiv_1_2" value="<?php echo sanitize_output($lab_result_data['serology']['hiv_1_2'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="aso_titer">ASO Titer:</label>
                                    <input type="text" class="form-control" id="aso_titer" name="aso_titer" value="<?php echo sanitize_output($lab_result_data['serology']['aso_titer'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="crp">CRP:</label>
                                    <input type="text" class="form-control" id="crp" name="crp" value="<?php echo sanitize_output($lab_result_data['serology']['crp'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="rf_factor">RF Factor:</label>
                                    <input type="text" class="form-control" id="rf_factor" name="rf_factor" value="<?php echo sanitize_output($lab_result_data['serology']['rf_factor'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="typhidot_igm">Typhidot IgM:</label>
                                    <input type="text" class="form-control" id="typhidot_igm" name="typhidot_igm" value="<?php echo sanitize_output($lab_result_data['serology']['typhidot_igm'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="typhidot_igg">Typhidot IgG:</label>
                                    <input type="text" class="form-control" id="typhidot_igg" name="typhidot_igg" value="<?php echo sanitize_output($lab_result_data['serology']['typhidot_igg'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="malaria_mp">Malaria MP:</label>
                                    <input type="text" class="form-control" id="malaria_mp" name="malaria_mp" value="<?php echo sanitize_output($lab_result_data['serology']['malaria_mp'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="dengue_ns1">Dengue NS1:</label>
                                    <input type="text" class="form-control" id="dengue_ns1" name="dengue_ns1" value="<?php echo sanitize_output($lab_result_data['serology']['dengue_ns1'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="dengue_igm">Dengue IgM:</label>
                                    <input type="text" class="form-control" id="dengue_igm" name="dengue_igm" value="<?php echo sanitize_output($lab_result_data['serology']['dengue_igm'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="dengue_igg">Dengue IgG:</label>
                                    <input type="text" class="form-control" id="dengue_igg" name="dengue_igg" value="<?php echo sanitize_output($lab_result_data['serology']['dengue_igg'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="troponin_i">Troponin I:</label>
                                    <input type="text" class="form-control" id="troponin_i" name="troponin_i" value="<?php echo sanitize_output($lab_result_data['serology']['troponin_i'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="ck_mb">CK-MB:</label>
                                    <input type="text" class="form-control" id="ck_mb" name="ck_mb" value="<?php echo sanitize_output($lab_result_data['serology']['ck_mb'] ?? ''); ?>" <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3 test-section <?php echo (!empty($lab_result_data['ultrasound']) || $is_edit_mode) ? 'd-block' : 'd-none'; ?>" id="ultrasoundCard">
                    <div class="card-header bg-info text-white" data-toggle="collapse" data-target="#ultrasoundCollapse" aria-expanded="true" aria-controls="ultrasoundCollapse">
                        <h6 class="mb-0"><i class="fas fa-head-side-medical"></i> Ultrasound <i class="fas fa-chevron-down float-right collapse-icon"></i></h6>
                    </div>
                    <div id="ultrasoundCollapse" class="collapse <?php echo (!empty($lab_result_data['ultrasound']) || $is_edit_mode) ? 'show' : ''; ?>">
                        <div class="card-body">
                            <div class="form-group mb-3">
                                <label for="ultrasound_findings">Findings:</label>
                                <textarea class="form-control" id="ultrasound_findings" name="ultrasound_findings" rows="4" <?php echo $is_edit_mode ? 'readonly' : ''; ?>><?php echo sanitize_output($lab_result_data['ultrasound']['ultrasound_findings'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label for="ultrasound_impression">Impression:</label>
                                <textarea class="form-control" id="ultrasound_impression" name="ultrasound_impression" rows="3" <?php echo $is_edit_mode ? 'readonly' : ''; ?>><?php echo sanitize_output($lab_result_data['ultrasound']['ultrasound_impression'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3 test-section <?php echo (!empty($lab_result_data['xray']) || $is_edit_mode) ? 'd-block' : 'd-none'; ?>" id="xrayCard">
                    <div class="card-header bg-info text-white" data-toggle="collapse" data-target="#xrayCollapse" aria-expanded="true" aria-controls="xrayCollapse">
                        <h6 class="mb-0"><i class="fas fa-bone"></i> X-Ray <i class="fas fa-chevron-down float-right collapse-icon"></i></h6>
                    </div>
                    <div id="xrayCollapse" class="collapse <?php echo (!empty($lab_result_data['xray']) || $is_edit_mode) ? 'show' : ''; ?>">
                        <div class="card-body">
                            <div class="form-group mb-3">
                                <label for="xray_findings">Findings:</label>
                                <textarea class="form-control" id="xray_findings" name="xray_findings" rows="4" <?php echo $is_edit_mode ? 'readonly' : ''; ?>><?php echo sanitize_output($lab_result_data['xray']['xray_findings'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label for="xray_impression">Impression:</label>
                                <textarea class="form-control" id="xray_impression" name="xray_impression" rows="3" <?php echo $is_edit_mode ? 'readonly' : ''; ?>><?php echo sanitize_output($lab_result_data['xray']['xray_impression'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3 test-section <?php echo (!empty($lab_result_data['ecg']) || $is_edit_mode) ? 'd-block' : 'd-none'; ?>" id="ecgCard">
                    <div class="card-header bg-info text-white" data-toggle="collapse" data-target="#ecgCollapse" aria-expanded="true" aria-controls="ecgCollapse">
                        <h6 class="mb-0"><i class="fas fa-heartbeat"></i> ECG <i class="fas fa-chevron-down float-right collapse-icon"></i></h6>
                    </div>
                    <div id="ecgCollapse" class="collapse <?php echo (!empty($lab_result_data['ecg']) || $is_edit_mode) ? 'show' : ''; ?>">
                        <div class="card-body">
                            <div class="form-group mb-3">
                                <label for="ecg_findings">Findings:</label>
                                <textarea class="form-control" id="ecg_findings" name="ecg_findings" rows="4" <?php echo $is_edit_mode ? 'readonly' : ''; ?>><?php echo sanitize_output($lab_result_data['ecg']['ecg_findings'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label for="ecg_impression">Impression:</label>
                                <textarea class="form-control" id="ecg_impression" name="ecg_impression" rows="3" <?php echo $is_edit_mode ? 'readonly' : ''; ?>><?php echo sanitize_output($lab_result_data['ecg']['ecg_impression'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end mt-4">
                    <?php if (!$is_edit_mode): ?>
                        <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Lab Result</button>
                    <?php else: ?>
                        <button type="button" class="btn btn-primary mr-2" id="editLabResultBtn"><i class="fas fa-edit"></i> Edit Lab Result</button>
                        <button type="button" class="btn btn-info" id="printLabResultBtn"><i class="fas fa-print"></i> Print Result</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </form>
</div>

<div class="modal fade" id="printPreviewModal" tabindex="-1" role="dialog" aria-labelledby="printPreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="printPreviewModalLabel">Lab Result Print Preview</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="receiptPrintArea">
                </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="printActualResultBtn"><i class="fas fa-print"></i> Print</button>
            </div>
        </div>
    </div>
</div>

<?php require_once 'config/footer.php'; // Includes closing tags and JS ?>

<style>
    /* Custom styles for print output */
    @media print {
        body * {
            visibility: hidden;
        }
        #receiptPrintArea, #receiptPrintArea * {
            visibility: visible;
        }
        #receiptPrintArea {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            padding: 20px;
            font-family: sans-serif;
            font-size: 14px;
        }
        .no-print {
            display: none !important;
        }
        .modal-footer {
            display: none !
        }
        /* Style for data grids in print */
        .print-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }
        .print-grid div strong {
            display: block;
            margin-bottom: 5px;
            color: #333;
        }
        .print-section-header {
            background-color: #f2f2f2;
            padding: 8px;
            margin-top: 15px;
            margin-bottom: 10px;
            border-bottom: 1px solid #ddd;
            font-weight: bold;
        }
        .print-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        .print-table th, .print-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .print-table th {
            background-color: #f8f8f8;
        }
        .clinic-info {
            text-align: center;
            margin-bottom: 20px;
        }
        .clinic-info h3 {
            margin: 0;
            color: #333;
        }
        .clinic-info p {
            margin: 0;
            font-size: 12px;
            color: #666;
        }
    }
</style>

<script>
    $(document).ready(function() {
        // Initialize Select2 for patient dropdown
        $('#patient_id').select2({
            placeholder: "Select a patient",
            allowClear: true // Option to clear selection
        });

        // Function to fetch patient details via AJAX
        function fetchPatientDetails(patientId) {
            if (patientId) {
                $.ajax({
                    url: 'common_service/fetch_patient_details.php', // Ensure this file exists and returns patient data
                    type: 'GET',
                    data: { patient_id: patientId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            $('#display_patient_name').text(response.patient_name);
                            $('#display_clinic_id').text(response.patient_display_id);
                            $('#display_cnic').text(response.cnic || 'N/A');
                            $('#display_dob').text(response.dob || 'N/A');
                            $('#display_contact_no').text(response.contact_no || 'N/A');
                            $('#display_gender').text(response.gender || 'N/A');
                            $('#display_marital_status').text(response.marital_status || 'N/A');
                        } else {
                            // Clear fields on error
                            $('#display_patient_name, #display_clinic_id, #display_cnic, #display_dob, #display_contact_no, #display_gender, #display_marital_status').text('N/A');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("Error fetching patient info:", error);
                        $('#display_patient_name, #display_clinic_id, #display_cnic, #display_dob, #display_contact_no, #display_gender, #display_marital_status').text('N/A');
                    }
                });
            } else {
                // Clear fields if no patient selected
                $('#display_patient_name, #display_clinic_id, #display_cnic, #display_dob, #display_contact_no, #display_gender, #display_marital_status').text('N/A');
            }
        }

        // Event listener for patient dropdown change
        $('#patient_id').on('change', function() {
            fetchPatientDetails($(this).val());
        });

        // Function to toggle lab sections visibility (modified for Bootstrap collapse)
        function toggleLabSections() {
            $('.test-type-checkbox').each(function() {
                const targetId = $(this).data('target');
                const $targetCard = $(targetId);
                const $collapseDiv = $targetCard.find('.collapse');

                if ($(this).is(':checked')) {
                    $targetCard.removeClass('d-none').addClass('d-block');
                    $collapseDiv.collapse('show'); // Show the collapse section
                } else {
                    $targetCard.removeClass('d-block').addClass('d-none');
                    $collapseDiv.collapse('hide'); // Hide the collapse section
                }
            });

            // Ensure stool_macroscopy is checked/unchecked with stool_microscopy
            // This needs to be done *after* initial toggle to ensure correct state.
            if ($('#check_stool_microscopy').is(':checked')) {
                $('#check_stool_macroscopy').prop('checked', true).trigger('change'); // Trigger change to show macroscopy card
            } else if (!<?php echo json_encode($is_edit_mode); ?>) { // Only uncheck if not in edit mode (where it might be checked due to existing data)
                // If microscopy is unchecked, and it's not edit mode, uncheck macroscopy
                // This prevents macroscopy remaining visible if microscopy is de-selected.
                 // Removed auto-uncheck for macroscopy here to simplify and avoid conflicts in edit mode.
                 // Macro & Micro will be handled by data-driven checks on load.
            }
        }

        // When stool_microscopy is changed, sync stool_macroscopy
        $('#check_stool_microscopy').on('change', function() {
            const isChecked = $(this).is(':checked');
            // If stool_microscopy is checked, ensure stool_macroscopy is also checked
            // If stool_microscopy is unchecked, also uncheck stool_macroscopy only if not in view mode
            if(isChecked && !$('#check_stool_macroscopy').is(':checked')) {
                 $('#check_stool_macroscopy').prop('checked', true).trigger('change');
            } else if (!isChecked && !<?php echo json_encode($is_edit_mode); ?>) {
                // If stool microscopy is unchecked and it's not edit mode, hide/uncheck macroscopy.
                // Re-evaluate if macroscopy should truly hide if its own data is empty.
                // For simplicity, let's just ensure its checkbox is in sync.
            }
             toggleLabSections(); // Re-evaluate section visibility
        });

        // Attach change event listener to all test type checkboxes (including stool ones)
        $('.test-type-checkbox').on('change', toggleLabSections);

        // Initial calls on page load
        const patientIdFromUrl = $('#patient_id').val();
        if (patientIdFromUrl) {
            fetchPatientDetails(patientIdFromUrl);
        }

        toggleLabSections(); // Initial call to set visibility when the page loads

        // --- Handle Edit Mode Actions ---
        const isEditMode = <?php echo json_encode($is_edit_mode); ?>;
        if (isEditMode) {
            // Disable patient select permanently in edit mode
            $('#patient_id').prop('disabled', true);
            // Disable checkboxes if in view/edit mode
            $('.test-type-checkbox').prop('disabled', true);
        }

        // Enable editing fields when "Edit Lab Result" button is clicked
        $('#editLabResultBtn').on('click', function() {
            $('#labResultForm input, #labResultForm textarea').prop('readonly', false);
            $('.test-type-checkbox').prop('disabled', false); // Re-enable checkboxes for editing
            $(this).hide(); // Hide the edit button
            $('#printLabResultBtn').hide(); // Hide print button
            // Show Save button (create a save button if not already present)
            let saveBtn = '<button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Changes</button>';
            $(this).after(saveBtn); // Add save button next to hidden edit button
        });

        // --- Print Functionality ---
        $('#printLabResultBtn').on('click', function() {
            // Generate content for printing
            let printContent = `
                <div class="clinic-info">
                    <h3><strong><?php echo sanitize_output($clinic_name); ?></strong></h3>
                    <p><strong>Email:</strong> <?php echo sanitize_output($clinic_email); ?> | <strong>Phone:</strong> <?php echo sanitize_output($clinic_phone); ?></p>
                    <p><?php echo sanitize_output($clinic_address); ?></p>
                    <hr>
                </div>
                <h4 class="text-center mb-4">LABORATORY RESULT</h4>
                <p><strong>Patient Name:</strong> ${$('#display_patient_name').text()}</p>
                <p><strong>Clinic ID:</strong> ${$('#display_clinic_id').text()}</p>
                <p><strong>Lab Result ID:</strong> <?php echo sanitize_output($lab_result_id_from_url); ?></p>
                <p><strong>Date:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
                <p><strong>General Comments:</strong> ${$('#comment_notes').val() || 'N/A'}</p>
                <hr>
            `;

            // Function to generate print-friendly table for a section
            function generatePrintSection(sectionId, title, data, fields) {
                if (Object.keys(data).length === 0) return ''; // Don't print if no data

                let sectionHtml = `<h5 class="print-section-header">${title}</h5><div class="print-grid">`;
                let hasData = false;
                for (const key in fields) {
                    if (data[key]) {
                        hasData = true;
                        sectionHtml += `<div><strong>${fields[key]}:</strong> <span>${data[key]}</span></div>`;
                    }
                }
                sectionHtml += `</div>`;
                return hasData ? sectionHtml : ''; // Only return section if it has data
            }

            // Hematology
            printContent += generatePrintSection('hematology', 'Hematology', <?php echo json_encode($lab_result_data['hematology'] ?? (object)[]); ?>, {
                hb: 'HB', rbc: 'RBC', wbc: 'WBC', plt: 'PLT', esr: 'ESR', mcv: 'MCV', mch: 'MCH', mchc: 'MCHC',
                neutrophils: 'Neutrophils', lymphocytes: 'Lymphocytes', monocytes: 'Monocytes', eosinophils: 'Eosinophils', basophils: 'Basophils', retics: 'Retics',
                blood_group: 'Blood Group', coagulation_pt: 'Coagulation PT', coagulation_aptt: 'Coagulation APTT'
            });

            // Urine
            printContent += generatePrintSection('urine', 'Urine Analysis', <?php echo json_encode($lab_result_data['urine'] ?? (object)[]); ?>, {
                color: 'Color', appearance: 'Appearance', ph: 'pH', specific_gravity: 'Specific Gravity',
                proteins: 'Proteins', glucose: 'Glucose', ketones: 'Ketones', blood: 'Blood', leukocytes: 'Leukocytes', nitrite: 'Nitrite', urobilinogen: 'Urobilinogen', bilirubin: 'Bilirubin',
                micro_rbc: 'Micro RBC', micro_wbc: 'Micro WBC', micro_epithelial_cells: 'Epithelial Cells', micro_casts: 'Casts', micro_crystals: 'Crystals', micro_bacteria: 'Bacteria', micro_yeast: 'Yeast', other_micro_findings: 'Other Micro Findings'
            });

            // Stool
            printContent += generatePrintSection('stool', 'Stool Analysis', <?php echo json_encode($lab_result_data['stool'] ?? (object)[]); ?>, {
                color: 'Color', consistency: 'Consistency', blood: 'Blood', mucus: 'Mucus', macroscopy_other: 'Other Macroscopic',
                micro_rbc: 'Micro RBC', micro_wbc: 'Micro WBC', micro_ova_cysts: 'Ova/Cysts', micro_yeast: 'Yeast', micro_fat_globules: 'Fat Globules', micro_starch_granules: 'Starch Granules', micro_muscle_fibers: 'Muscle Fibers', micro_other_findings: 'Other Micro Findings'
            });

            // Microbiology
            printContent += generatePrintSection('microbiology', 'Microbiology', <?php echo json_encode($lab_result_data['microbiology'] ?? (object)[]); ?>, {
                culture_source: 'Culture Source', culture_results: 'Culture Results', antibiotic_sensitivity: 'Antibiotic Sensitivity', gram_stain_result: 'Gram Stain', afb_stain_result: 'AFB Stain'
            });

            // Chemistry
            printContent += generatePrintSection('chemistry', 'Clinical Chemistry', <?php echo json_encode($lab_result_data['chemistry'] ?? (object)[]); ?>, {
                glucose_fbs: 'Glucose FBS', glucose_rbs: 'Glucose RBS', glucose_2hr_pp: 'Glucose 2hr PP', hba1c: 'HbA1c',
                urea: 'Urea', creatinine: 'Creatinine', uric_acid: 'Uric Acid',
                cholesterol_total: 'Cholesterol Total', triglycerides: 'Triglycerides', hdl: 'HDL', ldl: 'LDL',
                sgpt_alt: 'SGPT (ALT)', sgot_ast: 'SGOT (AST)', alp: 'ALP',
                bilirubin_total: 'Bilirubin Total', bilirubin_direct: 'Bilirubin Direct',
                total_protein: 'Total Protein', albumin: 'Albumin', globulin: 'Globulin',
                sodium: 'Sodium', potassium: 'Potassium', chloride: 'Chloride', calcium: 'Calcium', phosphorus: 'Phosphorus',
                amylase: 'Amylase', lipase: 'Lipase',
                thyroid_tsh: 'TSH', thyroid_t3: 'T3', thyroid_t4: 'T4'
            });

            // Serology
            printContent += generatePrintSection('serology', 'Serology', <?php echo json_encode($lab_result_data['serology'] ?? (object)[]); ?>, {
                widal_o: 'Widal O', widal_h: 'Widal H', widal_ah: 'Widal AH', widal_bh: 'Widal BH',
                rpr_vdrl: 'RPR/VDRL', hcv: 'HCV', hbsag: 'HBsAg', hiv_1_2: 'HIV 1&2', aso_titer: 'ASO Titer', crp: 'CRP', rf_factor: 'RF Factor',
                typhidot_igm: 'Typhidot IgM', typhidot_igg: 'Typhidot IgG', malaria_mp: 'Malaria MP',
                dengue_ns1: 'Dengue NS1', dengue_igm: 'Dengue IgM', dengue_igg: 'Dengue IgG',
                troponin_i: 'Troponin I', ck_mb: 'CK-MB'
            });

            // Ultrasound
            printContent += generatePrintSection('ultrasound', 'Ultrasound', <?php echo json_encode($lab_result_data['ultrasound'] ?? (object)[]); ?>, {
                ultrasound_findings: 'Findings', ultrasound_impression: 'Impression'
            });

            // X-Ray
            printContent += generatePrintSection('xray', 'X-Ray', <?php echo json_encode($lab_result_data['xray'] ?? (object)[]); ?>, {
                xray_findings: 'Findings', xray_impression: 'Impression'
            });

            // ECG
            printContent += generatePrintSection('ecg', 'ECG', <?php echo json_encode($lab_result_data['ecg'] ?? (object)[]); ?>, {
                ecg_findings: 'Findings', ecg_impression: 'Impression'
            });


            $('#receiptPrintArea').html(printContent);
            $('#printPreviewModal').modal('show');
        });

        // Actual print from modal
        $('#printActualResultBtn').on('click', function() {
            const contentToPrint = document.getElementById('receiptPrintArea').innerHTML;
            const printWindow = window.open('', '', 'height=600,width=800');
            printWindow.document.write('<html><head><title>Print Lab Result</title>');
            printWindow.document.write('<link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">'); // Include Bootstrap CSS
            printWindow.document.write('<style>');
            printWindow.document.write(`
                body { font-family: Arial, sans-serif; margin: 20px; }
                .clinic-info { text-align: center; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
                .clinic-info h3 { margin: 0; color: #333; }
                .clinic-info p { margin: 0; font-size: 12px; color: #666; }
                h4 { color: #007bff; margin-top: 20px; margin-bottom: 15px; text-align: center; }
                .print-section-header {
                    background-color: #e9ecef; /* Light gray background */
                    padding: 8px 15px;
                    margin-top: 20px;
                    margin-bottom: 10px;
                    border-radius: 4px;
                    font-weight: bold;
                    color: #333;
                }
                .print-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); /* Adjusted for better spacing */
                    gap: 15px;
                    margin-bottom: 20px;
                    padding: 0 15px;
                }
                .print-grid div {
                    padding: 5px 0;
                    border-bottom: 1px dashed #eee; /* Light separator */
                }
                .print-grid div:last-child {
                    border-bottom: none;
                }
                .print-grid div strong {
                    display: block;
                    font-size: 0.9em;
                    color: #555;
                }
                .print-grid div span {
                    font-size: 1em;
                    color: #000;
                    font-weight: 500;
                }
                @media print {
                    .modal-footer { display: none; }
                    body { margin: 0; }
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