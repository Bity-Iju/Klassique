<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("location:index.php");
    exit;
}
include './config/connection.php'; // Assuming connection.php exists and establishes $con

// --- Embedded Functions ---

function fetchPatients($con, $selectedPatientId = '') {
    $options = '';
    $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Modified query to order by 'id' in descending order to represent recent registration if no specific registration_date column
    // If you have a 'registration_date' or 'created_at' column, replace 'id' with that column name
    $query = "SELECT `id`, `patient_name`, `patient_display_id` FROM `patients` ORDER BY `id` DESC"; // Changed ORDER BY clause
    $stmt = $con->prepare($query);
    try {
        $stmt->execute();
    } catch (PDOException $ex) {
        echo "<option value=''>DATABASE ERROR: " . htmlspecialchars($ex->getMessage()) . "</option>";
        error_log("Error in fetchPatients: " . $ex->getMessage());
        return '<option value="">Error loading patients (Check PHP error log for details)</option>';
    }

    $options = '<option value="">Select Patient</option>';

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $selected = ($selectedPatientId == $row['id']) ? 'selected' : '';
        $patient_display_name = htmlspecialchars($row['patient_name']);
        $patient_clinic_number = htmlspecialchars($row['patient_display_id'] ?: 'N/A');
        $options .= '<option value="' . htmlspecialchars($row['id']) . '" ' . $selected . '>' . $patient_display_name . ' (' . $patient_clinic_number . ')</option>';
    }
    return $options;
}

function getMedicines($con, $selectedMedicineDetailId = '') {
    $options = '';
    $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Ensure error reporting
    $query = "SELECT m.medicine_name, md.id AS medicine_detail_id, md.packing
              FROM medicines m
              JOIN medicine_details md ON m.id = md.medicine_id
              ORDER BY m.medicine_name ASC, md.packing ASC";

    $stmt = $con->prepare($query);
    try {
        $stmt->execute();
    } catch(PDOException $ex) {
        error_log("Error in getMedicines: " . $ex->getMessage());
        return '<option value="">Error loading medicines</option>';
    }

    $options = '<option value="">Select Medicine</option>';

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $displayText = htmlspecialchars($row['medicine_name'] . ' (' . $row['packing'] . ')');
        $selected = ($selectedMedicineDetailId == $row['medicine_detail_id']) ? 'selected' : '';
        $options .= '<option value="' . htmlspecialchars($row['medicine_detail_id']) . '" ' . $selected . '>' . $displayText . '</option>';
    }
    return $options;
}

// --- NEW EMBEDDED AJAX HANDLERS ---
// This handles the AJAX request for patient info and medicine packing
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Invalid action or data.'];

    if ($_GET['action'] == 'get_patient_info' && isset($_GET['patient_id'])) {
        $patientId = $_GET['patient_id'];
        try {
            // !!! IMPORTANT: Replace 'cnic' with the EXACT column name from your database if different.
            // Example: 'patient_cnic'
            $query = "SELECT patient_name, dob, contact_no, gender, marital_status, next_appointment_date, patient_display_id, cnic FROM patients WHERE id = :patient_id";
            $stmt = $con->prepare($query);
            $stmt->bindParam(':patient_id', $patientId, PDO::PARAM_INT);
            $stmt->execute();
            $patientInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($patientInfo) {
                $response = $patientInfo; // Return the associative array directly
            } else {
                $response = null; // Or an empty object {}
            }
        } catch (PDOException $e) {
            error_log("Error fetching patient info (embedded): " . $e->getMessage());
            $response = ['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()];
        }
    } elseif ($_GET['action'] == 'get_packing_info' && isset($_GET['medicine_detail_id'])) {
        $medicineDetailId = $_GET['medicine_detail_id'];
        try {
            $query = "SELECT packing FROM medicine_details WHERE id = :medicine_detail_id";
            $stmt = $con->prepare($query);
            $stmt->bindParam(':medicine_detail_id', $medicineDetailId, PDO::PARAM_INT);
            $stmt->execute();
            $packingInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($packingInfo) {
                $response = $packingInfo;
            } else {
                $response = null;
            }
        } catch (PDOException $e) {
            error_log("Error fetching packing info (embedded): " . $e->getMessage());
            $response = ['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    echo json_encode($response);
    exit; // Terminate script after AJAX response
}
// --- END NEW EMBEDDED AJAX HANDLERS ---


$message = '';

if (isset($_POST['submit'])) {

    $patientId = $_POST['patient'];
    $visitDate = $_POST['visit_date'];
    $nextVisitDate = $_POST['next_visit_date'];
    $height = $_POST['height']; // Changed from bp to height
    $weight = $_POST['weight'];
    $disease = $_POST['disease'];
    $notes = isset($_POST['notes']) ? htmlspecialchars(trim($_POST['notes'])) : null; // Added notes field

    $medicineDetailIds = $_POST['medicineDetailIds'];
    $quantities = $_POST['quantities'];
    $dosages = $_POST['dosages']; // This now contains combined frequency and instructions

    // Convert dates from MM/DD/YYYY to YYYY-MM-DD for database
    $visitDateFormatted = DateTime::createFromFormat('m/d/Y', $visitDate)->format('Y-m-d');

    if (!empty($nextVisitDate)) {
        $nextVisitDateFormatted = DateTime::createFromFormat('m/d/Y', $nextVisitDate)->format('Y-m-d');
    } else {
        $nextVisitDateFormatted = null; // Set to null if empty for database
    }


    try {
        $con->beginTransaction();

        // 1. Store a row in patient_visits
        // Changed bp to height in the SQL query
        $queryVisit = "INSERT INTO `patient_visits`(`visit_date`, `next_visit_date`, `height`, `weight`, `disease`, `patient_id`) VALUES (:visit_date, :next_visit_date, :height, :weight, :disease, :patient_id)";

        $stmtVisit = $con->prepare($queryVisit);
        $stmtVisit->bindParam(':visit_date', $visitDateFormatted, PDO::PARAM_STR);
        $stmtVisit->bindParam(':next_visit_date', $nextVisitDateFormatted, PDO::PARAM_STR);
        $stmtVisit->bindParam(':height', $height, PDO::PARAM_STR); // Bind height
        $stmtVisit->bindParam(':weight', $weight, PDO::PARAM_STR);
        $stmtVisit->bindParam(':disease', $disease, PDO::PARAM_STR);
        $stmtVisit->bindParam(':patient_id', $patientId, PDO::PARAM_INT);

        if ($stmtVisit->execute()) {
            $patientVisitId = $con->lastInsertId();

            // Prepare a statement to get medicine_id from medicine_details
            $queryGetMedicineId = "SELECT `medicine_id` FROM `medicine_details` WHERE `id` = :medicine_detail_id";
            $stmtGetMedicineId = $con->prepare($queryGetMedicineId);

            // 2. Store medicines in prescriptions and patient_medication_history
            $queryPrescription = "INSERT INTO `prescriptions`(`medicine_detail_id`, `quantity`, `dosage`, `patient_visit_id`, `prescription_date`) VALUES (:medicine_detail_id, :quantity, :dosage, :patient_visit_id, :prescription_date)";
            $stmtPrescription = $con->prepare($queryPrescription);

            $queryMedHistory = "INSERT INTO `patient_medication_history`(`patient_id`, `visit_date`, `disease`, `medicine_id`, `packing_id`, `quantity`, `dosage`, `notes`) VALUES (:patient_id, :visit_date, :disease, :medicine_id, :packing_id, :quantity, :dosage, :notes)";
            $stmtMedHistory = $con->prepare($queryMedHistory);


            foreach ($medicineDetailIds as $key => $medicineDetailId) {
                // Fetch medicine_id from medicine_details
                $stmtGetMedicineId->bindParam(':medicine_detail_id', $medicineDetailId, PDO::PARAM_INT);
                $stmtGetMedicineId->execute();
                $medicineRow = $stmtGetMedicineId->fetch(PDO::FETCH_ASSOC);
                $medicineId = $medicineRow['medicine_id'] ?? null;

                // Insert into prescriptions table
                $stmtPrescription->bindParam(':medicine_detail_id', $medicineDetailId, PDO::PARAM_INT);
                $stmtPrescription->bindParam(':quantity', $quantities[$key], PDO::PARAM_INT);
                $stmtPrescription->bindParam(':dosage', $dosages[$key], PDO::PARAM_STR);
                $stmtPrescription->bindParam(':patient_visit_id', $patientVisitId, PDO::PARAM_INT);
                $stmtPrescription->bindParam(':prescription_date', $visitDateFormatted, PDO::PARAM_STR);
                $stmtPrescription->execute();

                // Insert into patient_medication_history table
                $stmtMedHistory->bindParam(':patient_id', $patientId, PDO::PARAM_INT);
                $stmtMedHistory->bindParam(':visit_date', $visitDateFormatted, PDO::PARAM_STR);
                $stmtMedHistory->bindParam(':disease', $disease, PDO::PARAM_STR);
                $stmtMedHistory->bindParam(':medicine_id', $medicineId, PDO::PARAM_INT);
                $stmtMedHistory->bindParam(':packing_id', $medicineDetailId, PDO::PARAM_INT);
                $stmtMedHistory->bindParam(':quantity', $quantities[$key], PDO::PARAM_INT);
                $stmtMedHistory->bindParam(':dosage', $dosages[$key], PDO::PARAM_STR);
                $stmtMedHistory->bindParam(':notes', $notes, PDO::PARAM_STR); // New: Bind notes
                $stmtMedHistory->execute();
            }

            $con->commit();
            $message = 'Prescription added successfully!';
            header("location:congratulation.php?goto_page=new_prescription.php&message=" . urlencode($message));
            exit;
        } else {
            $con->rollBack();
            $message = "<div class='alert alert-danger'>Error adding patient visit.</div>";
        }

    } catch (PDOException $ex) {
        $con->rollBack();
        error_log("PDO Error in new_prescription.php: " . $ex->getMessage());
        $message = "<div class='alert alert-danger'>Database error: " . htmlspecialchars($ex->getMessage()) . "</div>";
    }
}

// Pass the database connection $con to the functions
$patients = fetchPatients($con, isset($_POST['patient']) ? $_POST['patient'] : '');
$medicines = getMedicines($con, isset($_POST['medicine']) ? $_POST['medicine'] : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include './config/site_css_links.php'; ?>
    <title>New Prescription - KDCS</title>
    <link rel="stylesheet" href="plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css">
    <link rel="stylesheet" href="plugins/select2/css/select2.min.css">
    <link rel="stylesheet" href="plugins/select2-bootstrap4-theme/select2-bootstrap4.min.css">


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
                            <h1>Add New Prescription</h1>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-right">
                                <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                                <li class="breadcrumb-item active">New Prescription</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </section>

            <section class="content">
                <div class="card card-outline card-success rounded-0 shadow">
                    <div class="card-header">
                        <h3 class="card-title">Prescription Details</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="post" action="new_prescription.php">
                            <?php echo $message; ?>
                            <div class="row">
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="patient">Select Patient</label>
                                        <select class="form-control form-control-sm rounded-0 select2" id="patient" name="patient" required>
                                            <?php echo $patients; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label>Visit Date</label>
                                        <div class="input-group date" id="visit_date_picker" data-target-input="nearest">
                                            <input type="text" class="form-control form-control-sm rounded-0 datetimepicker-input" data-target="#visit_date_picker" name="visit_date" required autocomplete="off" value="<?php echo date('m/d/Y'); ?>"/>
                                            <div class="input-group-append" data-target="#visit_date_picker" data-toggle="datetimepicker">
                                                <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label>Next Visit Date (Optional)</label>
                                        <div class="input-group date" id="next_visit_date_picker" data-target-input="nearest">
                                            <input type="text" class="form-control form-control-sm rounded-0 datetimepicker-input" data-target="#next_visit_date_picker" name="next_visit_date" autocomplete="off"/>
                                            <div class="input-group-append" data-target="#next_visit_date_picker" data-toggle="datetimepicker">
                                                <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-12">
                                    <h5>Patient Information:</h5>
                                    <div class="card card-info card-outline rounded-0 shadow-sm">
                                        <div class="card-body row">
                                            <div class="col-md-6">
                                                <p><strong>Unique ID:</strong> <span id="display_cnic">N/A</span></p>
                                                <p><strong>Date of Birth:</strong> <span id="display_dob">N/A</span></p>
                                                <p><strong>Phone Number:</strong> <span id="display_contact_no">N/A</span></p>
                                            </div>
                                            <div class="col-md-6">
                                                <p><strong>Gender:</strong> <span id="display_gender">N/A</span></p>
                                                <p><strong>Marital Status:</strong> <span id="display_marital_status">N/A</span></p>
                                                <p><strong>Next Appt. (from record):</strong> <span id="display_next_appointment_date">N/A</span></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-lg-3 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="height">Height (cm)</label> <input type="text" class="form-control form-control-sm rounded-0" id="height" name="height" placeholder="e.g., 175"> </div>
                                </div>
                                <div class="col-lg-3 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="weight">Weight (kg)</label>
                                        <input type="text" class="form-control form-control-sm rounded-0" id="weight" name="weight" placeholder="e.g., 70.5">
                                    </div>
                                </div>
                                <div class="col-lg-6 col-md-12 col-sm-12">
                                    <div class="form-group">
                                        <label for="disease">Disease/Diagnosis</label>
                                        <input type="text" class="form-control form-control-sm rounded-0" id="disease" name="disease" placeholder="e.g., Malaria">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-lg-12 col-md-12 col-sm-12">
                                    <div class="form-group">
                                        <label for="notes">Notes/Remarks</label>
                                        <textarea class="form-control form-control-sm rounded-0" id="notes" name="notes" rows="3" placeholder="Any additional notes about the visit or prescription..."></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-lg-12 col-md-12 col-sm-12">
                                    <div class="form-group">
                                        <label for="medicine">Add Medicine</label>
                                        <select class="form-control form-control-sm rounded-0 select2" id="medicine">
                                            <?php echo $medicines; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row align-items-end"> <div class="col-lg-1 col-md-4 col-sm-12">
                                    <div class="form-group">
                                        <label for="packing">Packing</label>
                                        <input type="text" class="form-control form-control-sm rounded-0" id="packing" readonly>
                                    </div>
                                </div>
                                <div class="col-lg-1 col-md-4 col-sm-12">
                                    <div class="form-group">
                                        <label for="quantity">Quantity</label>
                                        <input type="number" class="form-control form-control-sm rounded-0" id="quantity" min="1">
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-4 col-sm-12">
                                    <div class="form-group">
                                        <label for="frequency">Frequency</label>
                                        <select class="form-control form-control-sm rounded-0" id="frequency">
                                            <option value="">Select Frequency</option>
                                            <option value="QD">QD (Once a day)</option>
                                            <option value="BID">BID (Twice a day)</option>
                                            <option value="TID">TID (Three times a day)</option>
                                            <option value="QID">QID (Four times a day)</option>
                                            <option value="PRN">PRN (As needed)</option>
                                            <option value="HS">HS (At bedtime)</option>
                                            <option value="AC">AC (Before meals)</option>
                                            <option value="PC">PC (After meals)</option>
                                            <option value="STAT">STAT (Immediately)</option>
                                            <option value="Q4H">Q4H (Every 4 hours)</option>
                                            <option value="Q6H">Q6H (Every 6 hours)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-lg-5 col-md-8 col-sm-12"> <div class="form-group">
                                        <label for="dosage">Additional Instructions (Optional)</label>
                                        <input type="text" class="form-control form-control-sm rounded-0" id="dosage" placeholder="e.g., with water, 30 mins before food">
                                    </div>
                                </div>
                                <div class="col-lg-2 col-md-4 col-sm-12">
                                    <div class="form-group">
                                        <label>&nbsp;</label> <button type="button" class="btn btn-success btn-sm btn-flat btn-block" id="add_medicine">Add Medicine</button>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-lg-12 col-md-12 col-sm-12">
                                    <table class="table table-bordered table-striped text-center table-hover">
                                        <thead>
                                            <tr class="bg-success">
                                                <th width="5%">S.No</th>
                                                <th>Medicine</th>
                                                <th>Packing</th>
                                                <th>Quantity</th>
                                                <th>Frequency/Instructions</th> <th width="10%">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="current_medicines_list">
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-lg-12 text-right">
                                    <button type="submit" id="submit" name="submit" class="btn btn-success btn-sm btn-flat">Save Prescription</button>
                                    <button type="reset" class="btn btn-danger btn-sm btn-flat">Clear Form</button>
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
    <script src="plugins/moment/moment.min.js"></script>
    <script src="plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js"></script>
    <script src="plugins/select2/js/select2.full.min.js"></script>
    <script>
        var serial = 1;
        var inputs = '';

        function deleteCurrentRow(obj) {
            $(obj).closest('tr').remove();
            serial--;
        }

        $(function () {
            $('.select2').select2({
                theme: 'bootstrap4'
            });

            $('#visit_date_picker').datetimepicker({
                format: 'MM/DD/YYYY',
                icons: {
                    time: 'fa fa-clock',
                    date: 'fa fa-calendar',
                    up: 'fa fa-arrow-up',
                    down: 'fa fa-arrow-down',
                    previous: 'fa fa-chevron-left',
                    next: 'fa fa-chevron-right',
                    today: 'fa fa-calendar-check-o',
                    clear: 'fa fa-trash',
                    close: 'fa fa-times'
                }
            });

            $('#next_visit_date_picker').datetimepicker({
                format: 'MM/DD/YYYY',
                icons: {
                    time: 'fa fa-clock',
                    date: 'fa fa-calendar',
                    up: 'fa fa-arrow-up',
                    down: 'fa fa-arrow-down',
                    previous: 'fa fa-chevron-left',
                    next: 'fa fa-chevron-right',
                    today: 'fa fa-calendar-check-o',
                    clear: 'fa fa-trash',
                    close: 'fa fa-times'
                }
            });

            $("#add_medicine").click(function () {
                var medicineId = $("#medicine").val(); // This is medicine_detail_id
                var medicineName = $("#medicine option:selected").text();
                var packing = $("#packing").val();
                var quantity = $("#quantity").val();
                var frequency = $("#frequency").val(); // NEW: Get the selected frequency
                var additionalDosage = $("#dosage").val(); // This is now 'additional instructions'

                // Construct the full dosage string
                var fullDosage = frequency;
                if (additionalDosage) {
                    if (fullDosage) { // If frequency is selected, add additional instructions
                        fullDosage += " (" + additionalDosage + ")";
                    } else { // If no frequency, just use additional instructions
                        fullDosage = additionalDosage;
                    }
                }

                if (medicineId === '' || packing === '' || quantity === '' || frequency === '') { // Frequency is now required
                    alert("Please fill all medicine details including frequency.");
                    return;
                }

                var oldData = $("#current_medicines_list").html();

                inputs = '';
                // The hidden input should pass the medicine_detail_id, which is what `medicineId` holds
                inputs = inputs + '<input type="hidden" name="medicineDetailIds[]" value="' + medicineId + '" />';
                inputs = inputs + '<input type="hidden" name="quantities[]" value="' + quantity + '" />';
                inputs = inputs + '<input type="hidden" name="dosages[]" value="' + fullDosage + '" />'; // Store the combined dosage

                var tr = '<tr>';
                tr = tr + '<td class="px-2 py-1 align-middle">' + serial + '</td>';
                tr = tr + '<td class="px-2 py-1 align-middle">' + medicineName + '</td>';
                tr = tr + '<td class="px-2 py-1 align-middle">' + packing + '</td>';
                tr = tr + '<td class="px-2 py-1 align-middle">' + quantity + '</td>';
                tr = tr + '<td class="px-2 py-1 align-middle">' + fullDosage + inputs + '</td>'; // Display the combined dosage
                tr = tr + '<td class="px-2 py-1 align-middle text-center"><button type="button" class="btn btn-outline-danger btn-sm rounded-0" onclick="deleteCurrentRow(this);"><i class="fa fa-times"></i></button></td>';
                tr = tr + '</tr>';
                oldData = oldData + tr;
                serial++;

                $("#current_medicines_list").html(oldData);

                $("#medicine").val('').trigger('change');
                $("#packing").val('');
                $("#quantity").val('');
                $("#frequency").val(''); // Clear the new frequency dropdown
                $("#dosage").val(''); // Clear the additional instructions field
            });

            $("#medicine").change(function () {
                var medicineDetailId = $(this).val();
                if (medicineDetailId) {
                    $.ajax({
                        url: 'new_prescription.php?action=get_packing_info&medicine_detail_id=' + medicineDetailId, // Point to self with action
                        type: 'GET',
                        dataType: 'json',
                        success: function (response) {
                            $("#packing").val(response.packing);
                            $("#quantity").val('1'); // Default quantity
                            $("#dosage").val(''); // Clear dosage
                        },
                        error: function (xhr, status, error) {
                            console.error("Error fetching packing info:", error);
                            $("#packing").val('');
                            $("#quantity").val('');
                            $("#dosage").val('');
                        }
                    });
                } else {
                    $("#packing").val('');
                    $("#quantity").val('');
                    $("#dosage").val('');
                }
            });

            // AJAX to fetch patient details when patient is selected
            $("#patient").change(function () {
                var patientId = $(this).val();
                if (patientId) {
                    $.ajax({
                        url: 'new_prescription.php?action=get_patient_info&patient_id=' + patientId, // Point to self with action
                        type: 'GET',
                        dataType: 'json',
                        success: function (response) {
                            if (response) {
                                // !!! IMPORTANT: Update 'response.cnic' below if your DB column name is different
                                // For example, if your DB column is 'patient_cnic', use 'response.patient_cnic'
                                $('#display_cnic').text(response.cnic ? response.cnic : 'N/A');
                                $('#display_dob').text(response.dob ? moment(response.dob).format('MM/DD/YYYY') : 'N/A');
                                $('#display_contact_no').text(response.contact_no ? response.contact_no : 'N/A');
                                $('#display_gender').text(response.gender ? response.gender : 'N/A');
                                $('#display_marital_status').text(response.marital_status ? response.marital_status : 'N/A');
                                $('#display_next_appointment_date').text(response.next_appointment_date ? moment(response.next_appointment_date).format('MM/DD/YYYY') : 'N/A');
                            } else {
                                // Clear fields if no patient found or error
                                $('#display_cnic').text('N/A');
                                $('#display_dob').text('N/A');
                                $('#display_contact_no').text('N/A');
                                $('#display_gender').text('N/A');
                                $('#display_marital_status').text('N/A');
                                $('#display_next_appointment_date').text('N/A');
                            }
                        },
                        error: function (xhr, status, error) {
                            console.error("Error fetching patient info:", error);
                            // Clear fields on error
                            $('#display_cnic').text('N/A');
                            $('#display_dob').text('N/A');
                            $('#display_contact_no').text('N/A');
                            $('#display_gender').text('N/A');
                            $('#display_marital_status').text('N/A');
                            $('#display_next_appointment_date').text('N/A');
                        }
                    });
                } else {
                    // Clear fields if no patient selected
                    $('#display_cnic').text('N/A');
                    $('#display_dob').text('N/A');
                    $('#display_contact_no').text('N/A');
                    $('#display_gender').text('N/A');
                    $('#display_marital_status').text('N/A');
                    $('#display_next_appointment_date').text('N/A');
                }
            });

            // Trigger change event on load if patient is already selected (for sticky form)
            if ($("#patient").val()) {
                $("#patient").trigger('change');
            }
        });

        var message = '<?php echo isset($_GET['message']) ? htmlspecialchars($_GET['message']) : '';?>';
        if(message !== '') {
            if (typeof showCustomMessage === 'function') {
                showCustomMessage(message);
            } else {
                alert(message);
            }
        }
    </script>
</body>
</html>