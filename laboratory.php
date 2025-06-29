<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("location:index.php");
    exit;
}

include './config/connection.php';

// --- Fetch Clinic Details by reading index.php content --
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
    if (preg_match('/<hr>\s*<h3>Caring for a BETTER Life\?<\/h3>\s*<p>.*?<\/p>\s*<p><strong>Email:\s*<\/strong>(.*?)\s*\|\s*<strong>Office No.:<\/strong>(.*?)\s*\|\s*<strong>Doctor\'s No.:\s*<\/strong>(.*?)<\/p>/s', $index_content, $matches_contact)) {
        $clinic_email = trim($matches_contact[1]);
        $clinic_phone = trim($matches_contact[2]) . " / " . trim($matches_contact[3]);
    }
}
// --- END Fetch Clinic Details ---

// --- Database Connection Attribute (Good practice to ensure it's set) ---
if (isset($con)) {
    $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

// --- Fetch logged-in user's username for 'Ordered By' display ---
$loggedInUserId = $_SESSION['user_id'];
$loggedInUserName = 'N/A'; // Default value

try {
    // Fetch the username of the logged-in user
    $query = "SELECT `username` FROM `users` WHERE `id` = :userId LIMIT 1";
    $stmt = $con->prepare($query);
    $stmt->bindParam(':userId', $loggedInUserId, PDO::PARAM_INT);
    $stmt->execute();
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $loggedInUserName = htmlspecialchars($row['username']);
    }
} catch (PDOException $e) {
    error_log("Error fetching logged-in user: " . $e->getMessage());
    $loggedInUserName = 'Error fetching user';
}


// --- Handle Form Submission ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $patient_id = $_POST['patient_id'] ?? null;
    $ordered_by_user_id = $_POST['ordered_by'] ?? null; // This will now come from the hidden input
    $selected_test_types = $_POST['selected_test_types'] ?? []; // Array of checked test types

    // Validate essential fields
    if (empty($patient_id) || empty($ordered_by_user_id)) {
        $_SESSION['error_message'] = "Patient and 'Ordered By' fields are required.";
    } else {
        try {
            $con->beginTransaction();

            $sql = "INSERT INTO `lab_results` (
                `patient_id`, `ordered_by_user_id`,
                `haematology_pcv`, `haematology_blood_group`, `haematology_genotype`, `haematology_esr`, `haematology_wbc`,
                `bacteriology_h_pylori`,
                `parasitology_mps`, `parasitology_skin_snip`, `parasitology_wet_prep`,
                `virology_hbsag`, `virology_hcv`, `virology_rvs`, `virology_syphilis`,
                `clinical_chemistry_rbs`, `clinical_chemistry_serum_pt`,
                `urinalysis_blood`, `urinalysis_ketone`, `urinalysis_glucose`, `urinalysis_protein`, `urinalysis_leucocytes`,
                `urinalysis_ascorbic_acid`, `urinalysis_urobilinogen`, `urinalysis_ph`, `urinalysis_nitrite`, `urinalysis_bilirubin`,
                `urine_microscopy_notes`,
                `widal_ao_1`, `widal_bo_1`, `widal_co_1`, `widal_do_1`, `widal_ah_1`, `widal_bh_1`, `widal_ch_1`, `widal_dh_1`,
                `stool_macroscopy_notes`, `stool_microscopy_notes`,
                `semen_production_time`, `semen_collection_time`, `semen_examination_time`, `semen_volume`, `semen_ph`,
                `semen_appearance`, `semen_viscosity`, `semen_pus_cell`, `semen_bacterial_cells`, `semen_e_coli`,
                `semen_morphology_normal`, `semen_morphology_abnormal`,
                `comment_notes`
            ) VALUES (
                :patient_id, :ordered_by_user_id,
                :haematology_pcv, :haematology_blood_group, :haematology_genotype, :haematology_esr, :haematology_wbc,
                :bacteriology_h_pylori,
                :parasitology_mps, :parasitology_skin_snip, :parasitology_wet_prep,
                :virology_hbsag, :virology_hcv, :virology_rvs, :virology_syphilis,
                :clinical_chemistry_rbs, :clinical_chemistry_serum_pt,
                :urinalysis_blood, :urinalysis_ketone, :urinalysis_glucose, :urinalysis_protein, :urinalysis_leucocytes,
                :urinalysis_ascorbic_acid, :urinalysis_urobilinogen, :urinalysis_ph, :urinalysis_nitrite, :urinalysis_bilirubin,
                :urine_microscopy_notes,
                :widal_ao_1, :widal_bo_1, :widal_co_1, :widal_do_1, :widal_ah_1, :widal_bh_1, :widal_ch_1, :widal_dh_1,
                :stool_macroscopy_notes, :stool_microscopy_notes,
                :semen_production_time, :semen_collection_time, :semen_examination_time, :semen_volume, :semen_ph,
                :semen_appearance, :semen_viscosity, :semen_pus_cell, :semen_bacterial_cells, :semen_e_coli,
                :semen_morphology_normal, :semen_morphology_abnormal,
                :comment_notes
            )";

            $stmt = $con->prepare($sql);

            // Bind parameters - use null for fields that are NOT in $_POST (i.e., section was not displayed/checked)
            // Use in_array() to check if the test type was selected via checkbox
            $stmt->bindParam(':patient_id', $patient_id, PDO::PARAM_INT);
            $stmt->bindParam(':ordered_by_user_id', $ordered_by_user_id, PDO::PARAM_INT);

            // HAEMATOLOGY
            $stmt->bindValue(':haematology_pcv', in_array('haematology', $selected_test_types) ? ($_POST['haematology_pcv'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':haematology_blood_group', in_array('haematology', $selected_test_types) ? ($_POST['haematology_blood_group'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':haematology_genotype', in_array('haematology', $selected_test_types) ? ($_POST['haematology_genotype'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':haematology_esr', in_array('haematology', $selected_test_types) ? ($_POST['haematology_esr'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':haematology_wbc', in_array('haematology', $selected_test_types) ? ($_POST['haematology_wbc'] ?? null) : null, PDO::PARAM_STR);

            // BACTERIOLOGY
            $stmt->bindValue(':bacteriology_h_pylori', in_array('bacteriology', $selected_test_types) ? ($_POST['bacteriology_h_pylori'] ?? null) : null, PDO::PARAM_STR);

            // PARASITOLOGY
            $stmt->bindValue(':parasitology_mps', in_array('parasitology', $selected_test_types) ? ($_POST['parasitology_mps'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':parasitology_skin_snip', in_array('parasitology', $selected_test_types) ? ($_POST['parasitology_skin_snip'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':parasitology_wet_prep', in_array('parasitology', $selected_test_types) ? ($_POST['parasitology_wet_prep'] ?? null) : null, PDO::PARAM_STR);

            // VIROLOGY
            $stmt->bindValue(':virology_hbsag', in_array('virology', $selected_test_types) ? ($_POST['virology_hbsag'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':virology_hcv', in_array('virology', $selected_test_types) ? ($_POST['virology_hcv'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':virology_rvs', in_array('virology', $selected_test_types) ? ($_POST['virology_rvs'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':virology_syphilis', in_array('virology', $selected_test_types) ? ($_POST['virology_syphilis'] ?? null) : null, PDO::PARAM_STR);

            // CLINICAL CHEMISTRY
            $stmt->bindValue(':clinical_chemistry_rbs', in_array('clinical_chemistry', $selected_test_types) ? ($_POST['clinical_chemistry_rbs'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':clinical_chemistry_serum_pt', in_array('clinical_chemistry', $selected_test_types) ? ($_POST['clinical_chemistry_serum_pt'] ?? null) : null, PDO::PARAM_STR);

            // URINALYSIS
            $stmt->bindValue(':urinalysis_blood', in_array('urinalysis', $selected_test_types) ? ($_POST['urinalysis_blood'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':urinalysis_ketone', in_array('urinalysis', $selected_test_types) ? ($_POST['urinalysis_ketone'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':urinalysis_glucose', in_array('urinalysis', $selected_test_types) ? ($_POST['urinalysis_glucose'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':urinalysis_protein', in_array('urinalysis', $selected_test_types) ? ($_POST['urinalysis_protein'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':urinalysis_leucocytes', in_array('urinalysis', $selected_test_types) ? ($_POST['urinalysis_leucocytes'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':urinalysis_ascorbic_acid', in_array('urinalysis', $selected_test_types) ? ($_POST['urinalysis_ascorbic_acid'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':urinalysis_urobilinogen', in_array('urinalysis', $selected_test_types) ? ($_POST['urinalysis_urobilinogen'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':urinalysis_ph', in_array('urinalysis', $selected_test_types) ? ($_POST['urinalysis_ph'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':urinalysis_nitrite', in_array('urinalysis', $selected_test_types) ? ($_POST['urinalysis_nitrite'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':urinalysis_bilirubin', in_array('urinalysis', $selected_test_types) ? ($_POST['urinalysis_bilirubin'] ?? null) : null, PDO::PARAM_STR);

            // URINE MICROSCOPY
            $stmt->bindValue(':urine_microscopy_notes', in_array('urine_microscopy', $selected_test_types) ? ($_POST['urine_microscopy_notes'] ?? null) : null, PDO::PARAM_STR);

            // WIDAL .TEST
            $stmt->bindValue(':widal_ao_1', in_array('widal_test', $selected_test_types) ? ($_POST['widal_ao_1'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':widal_bo_1', in_array('widal_test', $selected_test_types) ? ($_POST['widal_bo_1'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':widal_co_1', in_array('widal_test', $selected_test_types) ? ($_POST['widal_co_1'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':widal_do_1', in_array('widal_test', $selected_test_types) ? ($_POST['widal_do_1'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':widal_ah_1', in_array('widal_test', $selected_test_types) ? ($_POST['widal_ah_1'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':widal_bh_1', in_array('widal_test', $selected_test_types) ? ($_POST['widal_bh_1'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':widal_ch_1', in_array('widal_test', $selected_test_types) ? ($_POST['widal_ch_1'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':widal_dh_1', in_array('widal_test', $selected_test_types) ? ($_POST['widal_dh_1'] ?? null) : null, PDO::PARAM_STR);

            // STOOL
            // If either stool checkbox is checked, save their respective notes
            $stmt->bindValue(':stool_macroscopy_notes', (in_array('stool_macroscopy', $selected_test_types) || in_array('stool_microscopy', $selected_test_types)) ? ($_POST['stool_macroscopy_notes'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':stool_microscopy_notes', (in_array('stool_macroscopy', $selected_test_types) || in_array('stool_microscopy', $selected_test_types)) ? ($_POST['stool_microscopy_notes'] ?? null) : null, PDO::PARAM_STR);

            // SEMEN FLUID ANALYSIS
            $stmt->bindValue(':semen_production_time', in_array('semen_fluid_analysis', $selected_test_types) ? ($_POST['semen_production_time'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':semen_collection_time', in_array('semen_fluid_analysis', $selected_test_types) ? ($_POST['semen_collection_time'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':semen_examination_time', in_array('semen_fluid_analysis', $selected_test_types) ? ($_POST['semen_examination_time'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':semen_volume', in_array('semen_fluid_analysis', $selected_test_types) ? ($_POST['semen_volume'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':semen_ph', in_array('semen_fluid_analysis', $selected_test_types) ? ($_POST['semen_ph'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':semen_appearance', in_array('semen_fluid_analysis', $selected_test_types) ? ($_POST['semen_appearance'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':semen_viscosity', in_array('semen_fluid_analysis', $selected_test_types) ? ($_POST['semen_viscosity'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':semen_pus_cell', in_array('semen_fluid_analysis', $selected_test_types) ? ($_POST['semen_pus_cell'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':semen_bacterial_cells', in_array('semen_fluid_analysis', $selected_test_types) ? ($_POST['semen_bacterial_cells'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':semen_e_coli', in_array('semen_fluid_analysis', $selected_test_types) ? ($_POST['semen_e_coli'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':semen_morphology_normal', in_array('semen_fluid_analysis', $selected_test_types) ? ($_POST['semen_morphology_normal'] ?? null) : null, PDO::PARAM_STR);
            $stmt->bindValue(':semen_morphology_abnormal', in_array('semen_fluid_analysis', $selected_test_types) ? ($_POST['semen_morphology_abnormal'] ?? null) : null, PDO::PARAM_STR);


            // COMMENT (always submitted regardless of checkbox because it's always visible)
            $stmt->bindValue(':comment_notes', $_POST['comment_notes'] ?? null, PDO::PARAM_STR);


            $stmt->execute();
            $con->commit();

            $_SESSION['success_message'] = "Lab results saved successfully!";
            // CHANGE START: Redirect to manage_lab_results.php instead of laboratory.php
            header("location:manage_lab_results.php");
            // CHANGE END
            exit;

        } catch (PDOException $e) {
            $con->rollBack();
            error_log("Database error: " . $e->getMessage());
            $_SESSION['error_message'] = "Failed to save lab results. Error: " . $e->getMessage();
        }
    }
}

// --- Fetch patients for dropdown (Executed always when page loads) ---
$patients_options = '';
try {
    $query = "SELECT `id`, `patient_name`, `patient_display_id` FROM `patients` ORDER BY `patient_name` ASC";
    $stmt = $con->prepare($query);
    $stmt->execute();
    $patients_options .= '<option value="">Select Patient</option>';
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $patients_options .= '<option value="' . htmlspecialchars($row['id']) . '">' . htmlspecialchars($row['patient_name']) . ' (' . htmlspecialchars($row['patient_display_id'] ?: 'N/A') . ')</option>';
    }
} catch (PDOException $e) {
    error_log("Error fetching patients: " . $e->getMessage());
    $patients_options = '<option value="">Error loading patients</option>';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include './config/site_css_links.php';?>
    <title>Laboratory Test Results - KDCS</title>
    <style>
  

        body, .card, .lab-section, .card-title, .card-header, .card-body, h1, h2, h3, h4, h5, h6,
label, .form-control, .input-group-text, .table, th, td, .alert, .btn, select, textarea, option,
.form-check-label, .form-check-input, #test-types-table th, #test-types-table td {
    color: #145a32 !important; /* Dark green text */
    border-color: #145a32 !important;
}

.card,
.lab-section,
.card-header,
.card-body {
    background-color: #e9f7ef !important; /* Very light green background */
    border-color: #145a32 !important;
}

.card-title,
.card-header,
h1, h2, h3, h4, h5, h6 {
    color: #145a32 !important;
}

.btn-primary,
.btn,
.btn:focus,
.btn:active {
    background-color: #145a32 !important;
    border-color: #145a32 !important;
    color: #fff !important;
}

.btn-primary:hover,
.btn:hover {
    background-color: #117a65 !important;
    border-color: #117a65 !important;
    color: #fff !important;
}

.alert-success,
.alert-danger,
.alert {
    background-color: #d4efdf !important;
    color: #145a32 !important;
    border-color: #145a32 !important;
}

input[type="text"],
select,
textarea {
    background-color: #f4fef6 !important;
    color: #145a32 !important;
    border-color: #145a32 !important;
}

input[type="checkbox"].form-check-input {
    accent-color: #145a32 !important;
    border-color: #145a32 !important;
}

#test-types-table th,
#test-types-table td {
    background-color: #e9f7ef !important;
    color: #145a32 !important;
    border-color: #145a32 !important;
}

::-webkit-input-placeholder { color: #145a32 !important; }
::-moz-placeholder { color: #145a32 !important; }
:-ms-input-placeholder { color: #145a32 !important; }
::placeholder { color: #145a32 !important; }

/* Place this in your <style> tag in <head> after other styles */

/* Scope all color changes to content-wrapper only */
.content-wrapper,
.content-wrapper .card,
.content-wrapper .lab-section,
.content-wrapper .card-title,
.content-wrapper .card-header,
.content-wrapper .card-body,
.content-wrapper h1,
.content-wrapper h2,
.content-wrapper h3,
.content-wrapper h4,
.content-wrapper h5,
.content-wrapper h6,
.content-wrapper label,
.content-wrapper .form-control,
.content-wrapper .input-group-text,
.content-wrapper .table,
.content-wrapper th,
.content-wrapper td,
.content-wrapper .alert,
.content-wrapper .btn,
.content-wrapper select,
.content-wrapper textarea,
.content-wrapper option,
/* .content-wrapper .form-check-label, */ /* Remove or comment out */
/* .content-wrapper .form-check-input, */ /* Remove or comment out */
.content-wrapper #test-types-table th,
.content-wrapper #test-types-table td {
    color: #145a32 !important; /* Dark green text */
    border-color: #145a32 !important;
}

.content-wrapper .card,
.content-wrapper .lab-section,
.content-wrapper .card-header,
.content-wrapper .card-body {
    background-color: #e9f7ef !important; /* Very light green background */
    border-color: #145a32 !important;
}

.content-wrapper .card-title,
.content-wrapper .card-header,
.content-wrapper h1,
.content-wrapper h2,
.content-wrapper h3,
.content-wrapper h4,
.content-wrapper h5,
.content-wrapper h6 {
    color: #145a32 !important;
}

.content-wrapper .btn-primary,
.content-wrapper .btn,
.content-wrapper .btn:focus,
.content-wrapper .btn:active {
    background-color: #145a32 !important;
    border-color: #145a32 !important;
    color: #fff !important;
}

.content-wrapper .btn-primary:hover,
.content-wrapper .btn:hover {
    background-color: #117a65 !important;
    border-color: #117a65 !important;
    color: #fff !important;
}

.content-wrapper .alert-success,
.content-wrapper .alert-danger,
.content-wrapper .alert {
    background-color: #d4efdf !important;
    color: #145a32 !important;
    border-color: #145a32 !important;
}

.content-wrapper input[type="text"],
.content-wrapper select,
.content-wrapper textarea {
    background-color: #f4fef6 !important;
    color: #145a32 !important;
    border-color: #145a32 !important;
}

.content-wrapper input[type="checkbox"].form-check-input {
    accent-color: #145a32 !important;
    border-color: #145a32 !important;
}

.content-wrapper #test-types-table th,
.content-wrapper #test-types-table td {
    background-color: #e9f7ef !important;
    color: #145a32 !important;
    border-color: #145a32 !important;
}

.content-wrapper ::-webkit-input-placeholder { color: #145a32 !important; }
.content-wrapper ::-moz-placeholder { color: #145a32 !important; }
.content-wrapper :-ms-input-placeholder { color: #145a32 !important; }
.content-wrapper ::placeholder { color: #145a32 !important; }

/* Custom Toggle Switch Styles */
.content-wrapper .custom-toggle-container {
    display: flex; /* Use flexbox to align checkbox and label */
    align-items: center; /* Vertically center them */
    cursor: pointer;
    user-select: none; /* Prevent text selection */
    margin-bottom: 5px; /* Add some spacing between toggles */
}

.content-wrapper .custom-toggle-container input[type="checkbox"] {
    /* Hide the default checkbox */
    display: none;
}

.content-wrapper .custom-toggle-label {
    position: relative;
    padding-left: 50px; /* Space for the custom toggle switch */
    line-height: 24px; /* Align text with the toggle height */
    color: #145a32 !important; /* Ensure text color matches theme */
    font-weight: normal !important; /* Reset font-weight from Bootstrap */
    cursor: pointer;
}

.content-wrapper .custom-toggle-label::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    width: 40px; /* Width of the toggle track */
    height: 24px; /* Height of the toggle track */
    background-color: #ccc; /* Grey background when off */
    border-radius: 12px; /* Pill shape */
    transition: background-color 0.3s;
}

.content-wrapper .custom-toggle-label::after {
    content: '';
    position: absolute;
    left: 2px;
    top: 2px;
    width: 20px; /* Size of the toggle handle/circle */
    height: 20px; /* Size of the toggle handle/circle */
    background-color: white;
    border-radius: 50%; /* Circle shape for the handle */
    transition: left 0.3s;
}

/* When the checkbox is checked, style the toggle */
.content-wrapper .custom-toggle-container input[type="checkbox"]:checked + .custom-toggle-label::before {
    background-color: #145a32 !important; /* Dark green when on */
}

.content-wrapper .custom-toggle-container input[type="checkbox"]:checked + .custom-toggle-label::after {
    left: 18px; /* Move the handle to the right */
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
                        <h1>Laboratory Test Results</h1>
                    </div>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="card card-outline card-primary rounded-0 shadow">
                <div class="card-header">
                    <h3 class="card-title">Enter Lab Results</h3>
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
                    ?>
                    <form id="labResultForm" method="POST" action="laboratory.php">
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label for="patient_id">Select Patient:</label>
                                <select id="patient_id" name="patient_id" class="form-control form-control-sm rounded-0" required>
                                    <?php echo $patients_options; ?>
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label for="ordered_by_display">Ordered By:</label>
                                <input type="text" id="ordered_by_display" class="form-control form-control-sm rounded-0" value="<?php echo $loggedInUserName; ?>" disabled>
                                <input type="hidden" id="ordered_by" name="ordered_by" value="<?php echo $loggedInUserId; ?>">
                            </div>
                        </div>

                        <div class="row mb-4 justify-content-center">
    <div class="col-lg-10 mx-auto">
        <label class="mb-2" style="font-size:1.1em;font-weight:bold;display:block;text-align:center;">Select Test Type(s):</label>
        <div class="row"> <div class="col-md-2 col-sm-4 mb-2">
                <div class="custom-toggle-container">
                    <input class="form-check-input test-type-checkbox" type="checkbox" id="check_haematology" name="selected_test_types[]" value="haematology">
                    <label for="check_haematology" class="mb-0 custom-toggle-label">Haematology</label>
                </div>
            </div>
            <div class="col-md-2 col-sm-4 mb-2">
                <div class="custom-toggle-container">
                    <input class="form-check-input test-type-checkbox" type="checkbox" id="check_bacteriology" name="selected_test_types[]" value="bacteriology">
                    <label for="check_bacteriology" class="mb-0 custom-toggle-label">Bacteriology</label>
                </div>
            </div>
            <div class="col-md-2 col-sm-4 mb-2">
                <div class="custom-toggle-container">
                    <input class="form-check-input test-type-checkbox" type="checkbox" id="check_parasitology" name="selected_test_types[]" value="parasitology">
                    <label for="check_parasitology" class="mb-0 custom-toggle-label">Parasitology</label>
                </div>
            </div>
            <div class="col-md-2 col-sm-4 mb-2">
                <div class="custom-toggle-container">
                    <input class="form-check-input test-type-checkbox" type="checkbox" id="check_virology" name="selected_test_types[]" value="virology">
                    <label for="check_virology" class="mb-0 custom-toggle-label">Virology</label>
                </div>
            </div>
            <div class="col-md-2 col-sm-4 mb-2">
                <div class="custom-toggle-container">
                    <input class="form-check-input test-type-checkbox" type="checkbox" id="check_clinical_chemistry" name="selected_test_types[]" value="clinical_chemistry">
                    <label for="check_clinical_chemistry" class="mb-0 custom-toggle-label">Clinical Chemistry</label>
                </div>
            </div>
            <div class="col-md-2 col-sm-4 mb-2">
                <div class="custom-toggle-container">
                    <input class="form-check-input test-type-checkbox" type="checkbox" id="check_urinalysis" name="selected_test_types[]" value="urinalysis">
                    <label for="check_urinalysis" class="mb-0 custom-toggle-label">Urinalysis</label>
                </div>
            </div>
            <div class="col-md-2 col-sm-4 mb-2">
                <div class="custom-toggle-container">
                    <input class="form-check-input test-type-checkbox" type="checkbox" id="check_urine_microscopy" name="selected_test_types[]" value="urine_microscopy">
                    <label for="check_urine_microscopy" class="mb-0 custom-toggle-label">Urine Microscopy</label>
                </div>
            </div>
            <div class="col-md-2 col-sm-4 mb-2">
                <div class="custom-toggle-container">
                    <input class="form-check-input test-type-checkbox" type="checkbox" id="check_widal_test" name="selected_test_types[]" value="widal_test">
                    <label for="check_widal_test" class="mb-0 custom-toggle-label">Widal Test</label>
                </div>
            </div>
            <div class="col-md-2 col-sm-4 mb-2">
                <div class="custom-toggle-container">
                    <input class="form-check-input test-type-checkbox stool-test-checkbox" type="checkbox" id="check_stool_macroscopy" name="selected_test_types[]" value="stool_macroscopy">
                    <label for="check_stool_macroscopy" class="mb-0 custom-toggle-label">Stool Macroscopy</label>
                </div>
            </div>
            <div class="col-md-2 col-sm-4 mb-2">
                <div class="custom-toggle-container">
                    <input class="form-check-input test-type-checkbox stool-test-checkbox" type="checkbox" id="check_stool_microscopy" name="selected_test_types[]" value="stool_microscopy">
                    <label for="check_stool_microscopy" class="mb-0 custom-toggle-label">Stool Microscopy</label>
                </div>
            </div>
            <div class="col-md-2 col-sm-4 mb-2">
                <div class="custom-toggle-container">
                    <input class="form-check-input test-type-checkbox" type="checkbox" id="check_semen_fluid_analysis" name="selected_test_types[]" value="semen_fluid_analysis">
                    <label for="check_semen_fluid_analysis" class="mb-0 custom-toggle-label">Semen Fluid Analysis</label>
                </div>
            </div>
        </div> </div>
</div>
                        <hr>

                        <div id="haematology_section" class="lab-section lab-section-toggle">
                            <h4>HAEMATOLOGY</h4>
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label for="haematology_pcv">PCV:</label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" id="haematology_pcv" name="haematology_pcv" class="form-control form-control-sm rounded-0" placeholder="e.g., 40">
                                        <div class="input-group-append">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="haematology_blood_group">BLOOD GROUP:</label>
                                    <select id="haematology_blood_group" name="haematology_blood_group" class="form-control form-control-sm rounded-0">
                                        <option value="">Select</option>
                                        <option value="A+">A+</option>
                                        <option value="A-">A-</option>
                                        <option value="B+">B+</option>
                                        <option value="B-">B-</option>
                                        <option value="AB+">AB+</option>
                                        <option value="AB-">AB-</option>
                                        <option value="O+">O+</option>
                                        <option value="O-">O-</option>
                                    </select>
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="haematology_genotype">GENOTYPE:</label>
                                    <select id="haematology_genotype" name="haematology_genotype" class="form-control form-control-sm rounded-0">
                                        <option value="">Select</option>
                                        <option value="AA">AA</option>
                                        <option value="AS">AS</option>
                                        <option value="SS">SS</option>
                                        <option value="AC">AC</option>
                                        <option value="SC">SC</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label for="haematology_esr">ESR:</label>
                                    <input type="text" id="haematology_esr" name="haematology_esr" class="form-control form-control-sm rounded-0" placeholder="e.g., 10">
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="haematology_wbc">WBC:</label>
                                    <input type="text" id="haematology_wbc" name="haematology_wbc" class="form-control form-control-sm rounded-0" placeholder="e.g., 7.5">
                                </div>
                            </div>
                        </div>

                        <div id="bacteriology_section" class="lab-section lab-section-toggle">
                            <h4>BACTERIOLOGY</h4>
                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label for="bacteriology_h_pylori">H.PYLORI:</label>
                                    <select id="bacteriology_h_pylori" name="bacteriology_h_pylori" class="form-control form-control-sm rounded-0">
                                        <option value="">Select</option>
                                        <option value="Positive">Positive</option>
                                        <option value="Negative">Negative</option>
                                        <option value="Indeterminate">Indeterminate</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div id="parasitology_section" class="lab-section lab-section-toggle">
                            <h4>PARASITOLOGY</h4>
                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label for="parasitology_mps">MPS:</label>
                                    <select id="parasitology_mps" name="parasitology_mps" class="form-control form-control-sm rounded-0">
                                        <option value="">Select</option>
                                        <option value="Negative">Negative</option>
                                        <option value="Positive">Positive</option>
                                        <option value="Not Done">Not Done</option>
                                    </select>
                                </div>
                                <div class="col-md-6 form-group">
                                    <label for="parasitology_skin_snip">SKIN SNIP:</label>
                                    <select id="parasitology_skin_snip" name="parasitology_skin_snip" class="form-control form-control-sm rounded-0">
                                        <option value="">Select</option>
                                        <option value="Positive">Positive</option>
                                        <option value="Negative">Negative</option>
                                        <option value="Not Done">Not Done</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label for="parasitology_wet_prep">WET PREP:</label>
                                    <select id="parasitology_wet_prep" name="parasitology_wet_prep" class="form-control form-control-sm rounded-0">
                                        <option value="">Select</option>
                                        <option value="Positive">Positive</option>
                                        <option value="Negative">Negative</option>
                                        <option value="Not Done">Not Done</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div id="virology_section" class="lab-section lab-section-toggle">
                            <h4>VIROLOGY</h4>
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label for="virology_hbsag">HBsAg:</label>
                                    <select id="virology_hbsag" name="virology_hbsag" class="form-control form-control-sm rounded-0">
                                        <option value="">Select</option>
                                        <option value="Reactive">Reactive</option>
                                        <option value="Non-Reactive">Non-Reactive</option>
                                    </select>
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="virology_hcv">HCV:</label>
                                    <select id="virology_hcv" name="virology_hcv" class="form-control form-control-sm rounded-0">
                                        <option value="">Select</option>
                                        <option value="Reactive">Reactive</option>
                                        <option value="Non-Reactive">Non-Reactive</option>
                                    </select>
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="virology_rvs">RVS:</label>
                                    <select id="virology_rvs" name="virology_rvs" class="form-control form-control-sm rounded-0">
                                        <option value="">Select</option>
                                        <option value="Reactive">Reactive</option>
                                        <option value="Non-Reactive">Non-Reactive</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label for="virology_syphilis">SYPHILIS:</label>
                                    <select id="virology_syphilis" name="virology_syphilis" class="form-control form-control-sm rounded-0">
                                        <option value="">Select</option>
                                        <option value="Reactive">Reactive</option>
                                        <option value="Non-Reactive">Non-Reactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div id="clinical_chemistry_section" class="lab-section lab-section-toggle">
                            <h4>CLINICAL CHEMISTRY</h4>
                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label for="clinical_chemistry_rbs">RBS:</label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" id="clinical_chemistry_rbs" name="clinical_chemistry_rbs" class="form-control form-control-sm rounded-0" placeholder="e.g., 95">
                                        <div class="input-group-append">
                                            <span class="input-group-text">mg/dL</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 form-group">
                                    <label for="clinical_chemistry_serum_pt">SERUM PT:</label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" id="clinical_chemistry_serum_pt" name="clinical_chemistry_serum_pt" class="form-control form-control-sm rounded-0" placeholder="e.g., 12.5">
                                        <div class="input-group-append">
                                            <span class="input-group-text">seconds</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="urinalysis_section" class="lab-section lab-section-toggle">
                            <h4>URINALYSIS</h4>
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label for="urinalysis_blood">Blood:</label>
                                    <select id="urinalysis_blood" name="urinalysis_blood" class="form-control form-control-sm rounded-0">
                                        <option value="">Select</option>
                                        <option value="Negative">Negative</option>
                                        <option value="Trace">Trace</option>
                                        <option value="1+">1+</option>
                                        <option value="2+">2+</option>
                                        <option value="3+">3+</option>
                                        <option value="4+">4+</option>
                                    </select>
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="urinalysis_ketone">Ketone:</label>
                                    <select id="urinalysis_ketone" name="urinalysis_ketone" class="form-control form-control-sm rounded-0">
                                        <option value="">Select</option>
                                        <option value="Negative">Negative</option>
                                        <option value="Trace">Trace</option>
                                        <option value="1+">1+</option>
                                        <option value="2+">2+</option>
                                        <option value="3+">3+</option>
                                        <option value="4+">4+</option>
                                    </select>
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="urinalysis_glucose">Glucose:</label>
                                    <select id="urinalysis_glucose" name="urinalysis_glucose" class="form-control form-control-sm rounded-0">
                                        <option value="">Select</option>
                                        <option value="Negative">Negative</option>
                                        <option value="Trace">Trace</option>
                                        <option value="1+">1+</option>
                                        <option value="2+">2+</option>
                                        <option value="3+">3+</option>
                                        <option value="4+">4+</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label for="urinalysis_protein">Protein:</label>
                                    <select id="urinalysis_protein" name="urinalysis_protein" class="form-control form-control-sm rounded-0">
                                        <option value="">Select</option>
                                        <option value="Negative">Negative</option>
                                        <option value="Trace">Trace</option>
                                        <option value="1+">1+</option>
                                        <option value="2+">2+</option>
                                        <option value="3+">3+</option>
                                        <option value="4+">4+</option>
                                    </select>
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="urinalysis_leucocytes">Leucocytes:</label>
                                    <select id="urinalysis_leucocytes" name="urinalysis_leucocytes" class="form-control form-control-sm rounded-0">
                                        <option value="">Select</option>
                                        <option value="Negative">Negative</option>
                                        <option value="Trace">Trace</option>
                                        <option value="1+">1+</option>
                                        <option value="2+">2+</option>
                                        <option value="3+">3+</option>
                                        <option value="4+">4+</option>
                                    </select>
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="urinalysis_ascorbic_acid">Ascorbic Acid:</label>
                                    <select id="urinalysis_ascorbic_acid" name="urinalysis_ascorbic_acid" class="form-control form-control-sm rounded-0">
                                        <option value="">Select</option>
                                        <option value="Positive">Positive</option>
                                        <option value="Negative">Negative</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label for="urinalysis_urobilinogen">Urobilinogen:</label>
                                    <select id="urinalysis_urobilinogen" name="urinalysis_urobilinogen" class="form-control form-control-sm rounded-0">
                                        <option value="">Select</option>
                                        <option value="Normal">Normal</option>
                                        <option value="1+">1+</option>
                                        <option value="2+">2+</option>
                                        <option value="3+">3+</option>
                                    </select>
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="urinalysis_ph">PH:</label>
                                    <input type="text" id="urinalysis_ph" name="urinalysis_ph" class="form-control form-control-sm rounded-0" placeholder="e.g., 6.0">
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="urinalysis_nitrite">Nitrite:</label>
                                    <select id="urinalysis_nitrite" name="urinalysis_nitrite" class="form-control form-control-sm rounded-0">
                                        <option value="">Select</option>
                                        <option value="Positive">Positive</option>
                                        <option value="Negative">Negative</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label for="urinalysis_bilirubin">Bilirubin:</label>
                                    <select id="urinalysis_bilirubin" name="urinalysis_bilirubin" class="form-control form-control-sm rounded-0">
                                        <option value="">Select</option>
                                        <option value="Positive">Positive</option>
                                        <option value="Negative">Negative</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div id="urine_microscopy_section" class="lab-section lab-section-toggle">
                            <h4>URINE MICROSCOPY</h4>
                            <div class="form-group">
                                <label for="urine_microscopy_notes">Notes:</label>
                                <textarea id="urine_microscopy_notes" name="urine_microscopy_notes" class="form-control form-control-sm rounded-0" rows="3"></textarea>
                            </div>
                        </div>

                        <div id="widal_test_section" class="lab-section lab-section-toggle">
                            <h4>WIDAL .TEST</h4>
                            <div class="table-responsive">
                                <table class="table table-bordered widal-table">
                                    <thead>
                                        <tr>
                                            <th>Antigen</th>
                                            <th>Result (1/...)</th>
                                            <th>Antigen</th>
                                            <th>Result (1/...)</th>
                                            <th>Antigen</th>
                                            <th>Result (1/...)</th>
                                            <th>Antigen</th>
                                            <th>Result (1/...)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><strong>AO</strong></td>
                                            <td><input type="text" name="widal_ao_1" class="form-control form-control-sm" placeholder="e.g., 80"></td>
                                            <td><strong>BO</strong></td>
                                            <td><input type="text" name="widal_bo_1" class="form-control form-control-sm" placeholder="e.g., 40"></td>
                                            <td><strong>CO</strong></td>
                                            <td><input type="text" name="widal_co_1" class="form-control form-control-sm" placeholder="e.g., 20"></td>
                                            <td><strong>DO</strong></td>
                                            <td><input type="text" name="widal_do_1" class="form-control form-control-sm" placeholder="e.g., 160"></td>
                                        </tr>
                                        <tr>
                                            <td><strong>AH</strong></td>
                                            <td><input type="text" name="widal_ah_1" class="form-control form-control-sm" placeholder="e.g., 80"></td>
                                            <td><strong>BH</strong></td>
                                            <td><input type="text" name="widal_bh_1" class="form-control form-control-sm" placeholder="e.g., 40"></td>
                                            <td><strong>CH</strong></td>
                                            <td><input type="text" name="widal_ch_1" class="form-control form-control-sm" placeholder="e.g., 20"></td>
                                            <td><strong>DH</strong></td>
                                            <td><input type="text" name="widal_dh_1" class="form-control form-control-sm" placeholder="e.g., 160"></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="row lab-section-toggle" id="stool_sections">
                            <div class="col-md-6">
                                <div id="stool_macroscopy_section" class="lab-section">
                                    <h4>STOOL MACROSCOPY</h4>
                                    <div class="form-group">
                                        <label for="stool_macroscopy_notes">Notes:</label>
                                        <textarea id="stool_macroscopy_notes" name="stool_macroscopy_notes" class="form-control form-control-sm rounded-0" rows="5"></textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div id="stool_microscopy_section" class="lab-section">
                                    <h4>STOOL MICROSCOPY</h4>
                                    <div class="form-group">
                                        <label for="stool_microscopy_notes">Notes:</label>
                                        <textarea id="stool_microscopy_notes" name="stool_microscopy_notes" class="form-control form-control-sm rounded-0" rows="5"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="semen_fluid_analysis_section" class="lab-section lab-section-toggle">
                            <h4>SEMEN FLUID ANALYSIS</h4>
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label for="semen_production_time">PRODUCTION TIME:</label>
                                    <input type="text" id="semen_production_time" name="semen_production_time" class="form-control form-control-sm rounded-0" placeholder="e.g., 9:00 AM">
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="semen_collection_time">COLLECTION TIME:</label>
                                    <input type="text" id="semen_collection_time" name="semen_collection_time" class="form-control form-control-sm rounded-0" placeholder="e.g., 9:15 AM">
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="semen_examination_time">EXAMINATION TIME:</label>
                                    <input type="text" id="semen_examination_time" name="semen_examination_time" class="form-control form-control-sm rounded-0" placeholder="e.g., 9:30 AM">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label for="semen_volume">VOLUME:</label>
                                    <input type="text" id="semen_volume" name="semen_volume" class="form-control form-control-sm rounded-0">
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="semen_ph">PH:</label>
                                    <input type="text" id="semen_ph" name="semen_ph" class="form-control form-control-sm rounded-0">
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="semen_appearance">APPEARANCE:</label>
                                    <input type="text" id="semen_appearance" name="semen_appearance" class="form-control form-control-sm rounded-0">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label for="semen_viscosity">VISCOSITY:</label>
                                    <input type="text" id="semen_viscosity" name="semen_viscosity" class="form-control form-control-sm rounded-0">
                                </div>
                            </div>

                            <h5 class="mt-4">MICROSCOPY</h5>
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label for="semen_pus_cell">PUS CELL:</label>
                                    <input type="text" id="semen_pus_cell" name="semen_pus_cell" class="form-control form-control-sm rounded-0">
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="semen_bacterial_cells">BACTERIAL CELLS:</label>
                                    <input type="text" id="semen_bacterial_cells" name="semen_bacterial_cells" class="form-control form-control-sm rounded-0">
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="semen_e_coli">E-COLI:</label>
                                    <input type="text" id="semen_e_coli" name="semen_e_coli" class="form-control form-control-sm rounded-0">
                                </div>
                            </div>

                            <h5 class="mt-4">MORPHOLOGY</h5>
                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label for="semen_morphology_normal">NORMAL:</label>
                                    <input type="text" id="semen_morphology_normal" name="semen_morphology_normal" class="form-control form-control-sm rounded-0">
                                </div>
                                <div class="col-md-6 form-group">
                                    <label for="semen_morphology_abnormal">ABNORMAL:</label>
                                    <input type="text" id="semen_morphology_abnormal" name="semen_morphology_abnormal" class="form-control form-control-sm rounded-0">
                                </div>
                            </div>
                        </div>
                        <div id="comment_section" class="lab-section">
                            <h4>COMMENT</h4>
                            <div class="form-group">
                                <label for="comment_notes">Comment:</label>
                                <textarea id="comment_notes" name="comment_notes" class="form-control form-control-sm rounded-0" rows="4"></textarea>
                            </div>
                        </div>


                        <div class="row">
                            <div class="col-md-12 text-right">
                                <button type="submit" class="btn btn-primary btn-flat rounded-0">Save Lab Results</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </section>
    </div>

    <?php include './config/footer.php'; ?>
</div>

<?php include './config/site_js_links.php'; ?>
<script>
    $(document).ready(function() {
        // Set the active menu item for laboratory.php (Enter Results)
        showMenuSelected("#mnu_laboratory", "#mi_add_lab_order");

        // Map checkbox values to section IDs
        const sectionMap = {
            'haematology': 'haematology_section',
            'bacteriology': 'bacteriology_section',
            'parasitology': 'parasitology_section',
            'virology': 'virology_section',
            'clinical_chemistry': 'clinical_chemistry_section',
            'urinalysis': 'urinalysis_section',
            'urine_microscopy': 'urine_microscopy_section',
            'widal_test': 'widal_test_section',
            'semen_fluid_analysis': 'semen_fluid_analysis_section' // Add new section map
            // 'stool_macroscopy' and 'stool_microscopy' are handled by 'stool_sections'
        };

        // Function to show/hide sections based on checkbox state
        function toggleLabSections() {
            // Hide all toggleable sections first
            $('.lab-section-toggle').hide();

            // Track if any stool checkbox is checked
            let anyStoolChecked = false;

            // Iterate over all test type checkboxes to determine which sections to show
            $('.test-type-checkbox').each(function() {
                const type = $(this).val();
                const isChecked = $(this).is(':checked');

                // Special handling for stool tests: if either is checked, show the combined section
                if (type === 'stool_macroscopy' || type === 'stool_microscopy') {
                    if (isChecked) {
                        anyStoolChecked = true;
                    }
                } else { // For all other tests
                    const sectionId = sectionMap[type];
                    if (sectionId && isChecked) {
                        $('#' + sectionId).show();
                    }
                }
            });

            // After checking all checkboxes, toggle the combined stool section
            if (anyStoolChecked) {
                $('#stool_sections').show();
            } else {
                $('#stool_sections').hide();
            }
        }

        // --- Stool Checkbox Sync Logic ---
        // When stool_macroscopy is changed, sync stool_microscopy
        $('#check_stool_macroscopy').on('change', function() {
            const isChecked = $(this).is(':checked');
            // If stool_macroscopy is checked, ensure stool_microscopy is also checked
            // If stool_macroscopy is unchecked, also uncheck stool_microscopy
            $('#check_stool_microscopy').prop('checked', isChecked);
            toggleLabSections(); // Re-evaluate section visibility
        });

        // When stool_microscopy is changed, sync stool_macroscopy
        $('#check_stool_microscopy').on('change', function() {
            const isChecked = $(this).is(':checked');
            // If stool_microscopy is checked, ensure stool_macroscopy is also checked
            // If stool_microscopy is unchecked, also uncheck stool_macroscopy
            $('#check_stool_macroscopy').prop('checked', isChecked);
            toggleLabSections(); // Re-evaluate section visibility
        });

        // Attach change event listener to all test type checkboxes (including stool ones)
        $('.test-type-checkbox').on('change', toggleLabSections);

        // Initial call to set visibility when the page loads
        toggleLabSections();


        $('#labResultForm').on('submit', function(e) {
            // Basic client-side validation (can be enhanced)
            if ($('#patient_id').val() === '') {
                alert('Please select a patient.');
                e.preventDefault();
                return false;
            }
            // 'ordered_by' is now a hidden field and should always have a value
            // if ($('#ordered_by').val() === '') {
            //     alert('Please select who ordered the test.');
            //     e.preventDefault();
            //     return false;
            // }
            // Add more specific validation if needed for individual fields
        });
    });
</script>
</body>
</html>