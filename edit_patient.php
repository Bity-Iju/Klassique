<?php
ini_set('display_errors', 1); // Enable error display for debugging. REMOVE IN PRODUCTION!
error_reporting(E_ALL);     // Report all types of errors. REMOVE IN PRODUCTION!

session_start();
// Check login for all requests at the very beginning
if (!isset($_SESSION['user_id'])) {
    header("location:index.php");
    exit;
}

include './config/connection.php';
include './common_service/common_functions.php';

$message = '';

// --- HANDLE FORM SUBMISSION (POST Request) ---
// This block will execute if the form is submitted via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    // Sanitize and validate inputs
    $patient_id = isset($_POST['patient_id']) ? intval($_POST['patient_id']) : 0;
    $patient_name = isset($_POST['patient_name']) ? htmlspecialchars(trim($_POST['patient_name'])) : '';
    $address = isset($_POST['address']) ? htmlspecialchars(trim($_POST['address'])) : '';
    $patient_cnic = isset($_POST['patient_cnic']) ? htmlspecialchars(trim($_POST['patient_cnic'])) : '';
    $dob = isset($_POST['dob']) ? htmlspecialchars(trim($_POST['dob'])) : '';
    $contact_no = isset($_POST['contact_no']) ? htmlspecialchars(trim($_POST['contact_no'])) : '';
    $gender = isset($_POST['gender']) ? htmlspecialchars(trim($_POST['gender'])) : '';
    $marital_status = isset($_POST['marital_status']) ? htmlspecialchars(trim($_POST['marital_status'])) : '';
    $next_appointment_date = isset($_POST['next_appointment_date']) && !empty($_POST['next_appointment_date']) ? htmlspecialchars(trim($_POST['next_appointment_date'])) : null;

    // Basic validation
    if ($patient_id <= 0 || empty($patient_name) || empty($address) || empty($patient_cnic) || empty($dob) || empty($contact_no) || empty($gender) || empty($marital_status)) {
        $message = "<div class='alert alert-danger'>All required fields (Patient Name, Address, Unique ID, Date of Birth, Phone Number, Gender, Marital Status) must be filled and a valid Patient ID must be provided.</div>";
    } else {
        try {
            $con->beginTransaction();

            $query = "UPDATE `patients` SET
                        `patient_name` = :patient_name,
                        `address` = :address,
                        `cnic` = :cnic,
                        `dob` = :dob,
                        `contact_no` = :contact_no,
                        `gender` = :gender,
                        `marital_status` = :marital_status,
                        `next_appointment_date` = :next_appointment_date
                      WHERE `id` = :patient_id";

            $stmt = $con->prepare($query);

            $stmt->bindParam(':patient_name', $patient_name, PDO::PARAM_STR);
            $stmt->bindParam(':address', $address, PDO::PARAM_STR);
            $stmt->bindParam(':cnic', $patient_cnic, PDO::PARAM_STR);
            $stmt->bindParam(':dob', $dob, PDO::PARAM_STR);
            $stmt->bindParam(':contact_no', $contact_no, PDO::PARAM_STR);
            $stmt->bindParam(':gender', $gender, PDO::PARAM_STR);
            $stmt->bindParam(':marital_status', $marital_status, PDO::PARAM_STR);
            $stmt->bindParam(':next_appointment_date', $next_appointment_date, PDO::PARAM_STR);
            $stmt->bindParam(':patient_id', $patient_id, PDO::PARAM_INT);

            if ($stmt->execute()) {
                $con->commit();
                $message = 'Patient details updated successfully.';
                $redirect_message = urlencode($message);
                header("location:congratulation.php?goto_page=patients_list.php&message=" . $redirect_message);
                exit; // !!! IMPORTANT: Stop execution here !!!
            } else {
                $con->rollBack();
                $message = "<div class='alert alert-danger'>Error updating patient.</div>";
            }

        } catch (PDOException $ex) {
            $con->rollBack();
            error_log("PDO Error in edit_patient.php POST: " . $ex->getMessage());
            $message = "<div class='alert alert-danger'>Database error: " . htmlspecialchars($ex->getMessage()) . "</div>";
        }
    }
}

// --- HANDLE DISPLAYING THE FORM (GET Request or non-POST after error) ---
$patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;
$patient_data = null; // Initialize to null

if ($patient_id > 0) {
    try {
        $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $query = "SELECT `id`, `patient_name`, `address`, `cnic`, `dob`, `contact_no`, `gender`, `marital_status`, `next_appointment_date`, `patient_display_id` FROM `patients` WHERE `id` = :patient_id";
        $stmt = $con->prepare($query);
        $stmt->bindParam(':patient_id', $patient_id, PDO::PARAM_INT);
        $stmt->execute();
        $patient_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$patient_data) {
            $message = "<div class='alert alert-warning'>Patient not found.</div>";
            $patient_id = 0; // Invalidate ID if not found
        }
    } catch (PDOException $ex) {
        error_log("PDO Error fetching patient for edit form: " . $ex->getMessage());
        $message = "<div class='alert alert-danger'>Database error: " . htmlspecialchars($ex->getMessage()) . "</div>";
    }
} else {
    // Only set this message if it's not a POST request that already set a message
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $message = "<div class='alert alert-danger'>No patient ID provided.</div>";
    }
}

// Prepare options for dropdowns, prioritizing fetched data
$current_gender = $patient_data['gender'] ?? '';
$current_marital_status = $patient_data['marital_status'] ?? '';
$current_patient_name = $patient_data['patient_name'] ?? '';
$current_address = $patient_data['address'] ?? '';
$current_patient_cnic = $patient_data['cnic'] ?? '';
$current_dob = $patient_data['dob'] ?? '';
$current_contact_no = $patient_data['contact_no'] ?? '';
$current_next_appointment_date = $patient_data['next_appointment_date'] ?? '';
$current_patient_display_id = $patient_data['patient_display_id'] ?? 'N/A';

$gender_options = getGenderEnumOptions($current_gender);
$marital_status_options = getMaritalStatus($current_marital_status);

// --- Normal full page rendering ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include './config/site_css_links.php'; ?>
    <title>Edit Patient - KDCS</title>
    <link rel="stylesheet" href="plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css">
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
                            <h1>Edit Patient Details</h1>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-right">
                                <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                                <li class="breadcrumb-item"><a href="patients_list.php">Patient List</a></li>
                                <li class="breadcrumb-item active">Edit Patient</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </section>

            <section class="content">
                <div class="card card-outline card-success rounded-0 shadow">
                    <div class="card-header">
                        <h3 class="card-title">Update Patient Information</h3>
                    </div>
                    <div class="card-body">
                        <?php echo $message; ?>
                        <?php if ($patient_id > 0 && $patient_data): // Check for $patient_data here as well ?>
                            <form method="post" action="edit_patient.php">
                                <input type="hidden" name="patient_id" value="<?php echo htmlspecialchars($patient_id); ?>">
                                <div class="row">
                                    <div class="col-lg-2 col-md-6 col-sm-12">
                                        <div class="form-group">
                                            <label for="patient_display_id_field">Clinic Number</label>
                                            <input type="text" class="form-control form-control-sm rounded-0" id="patient_display_id_field" value="<?php echo htmlspecialchars($current_patient_display_id); ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="col-lg-4 col-md-6 col-sm-12">
                                        <div class="form-group">
                                            <label for="patient_name">Patient Name</label>
                                            <input type="text" class="form-control form-control-sm rounded-0" placeholder="Patient Name" id="patient_name" name="patient_name" required value="<?php echo htmlspecialchars($current_patient_name); ?>">
                                        </div>
                                    </div>
                                    <div class="col-lg-6 col-md-6 col-sm-12">
                                        <div class="form-group">
                                            <label for="address">Address</label>
                                            <input type="text" class="form-control form-control-sm rounded-0" placeholder="Address" id="address" name="address" required value="<?php echo htmlspecialchars($current_address); ?>">
                                        </div>
                                    </div>
                                    <div class="col-lg-2 col-md-6 col-sm-12">
                                        <div class="form-group">
                                            <label for="patient_cnic">Unique ID</label>
                                            <input type="text" class="form-control form-control-sm rounded-0" placeholder="e.g., KDCS-MUB1-0000-0000" id="patient_cnic" name="patient_cnic" required value="<?php echo htmlspecialchars($current_patient_cnic); ?>">
                                        </div>
                                    </div>
                                    <div class="col-lg-2 col-md-6 col-sm-12">
                                        <div class="form-group">
                                            <label>Date of Birth</label>
                                            <div class="input-group date" id="dob_picker" data-target-input="nearest">
                                                <input type="date" class="form-control form-control-sm rounded-0" data-target="#dob_picker" name="dob" id="dob" required autocomplete="off" value="<?php echo htmlspecialchars($current_dob); ?>"/>
                                                <div class="input-group-append" data-target="#dob_picker" data-toggle="datetimepicker">
                                                    <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-1 col-md-6 col-sm-12">
                                        <div class="form-group">
                                            <label for="age">Age</label>
                                            <input type="text" class="form-control form-control-sm rounded-0" id="age" name="age" readonly>
                                        </div>
                                    </div>
                                    <div class="col-lg-2 col-md-6 col-sm-12">
                                        <div class="form-group">
                                            <label for="gender">Gender</label>
                                            <select class="form-control form-control-sm rounded-0" id="gender" name="gender" required>
                                                <?php echo $gender_options; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-lg-2 col-md-6 col-sm-12">
                                        <div class="form-group">
                                            <label for="marital_status">Marital Status</label>
                                            <select class="form-control form-control-sm rounded-0" id="marital_status" name="marital_status" required>
                                                <?php echo $marital_status_options; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-lg-3 col-md-6 col-sm-12">
                                        <div class="form-group">
                                            <label for="contact_no">Phone Number</label>
                                            <input type="text" class="form-control form-control-sm rounded-0" id="contact_no" name="contact_no" required value="<?php echo htmlspecialchars($current_contact_no); ?>">
                                        </div>
                                    </div>

                                    <div class="col-lg-2 col-md-6 col-sm-12">
                                        <div class="form-group">
                                            <label>Next Appointment Date</label>
                                            <div class="input-group date" id="next_appointment_date_picker" data-target-input="nearest">
                                                <input type="date" class="form-control form-control-sm rounded-0" data-target="#next_appointment_date_picker" name="next_appointment_date" id="next_appointment_date" autocomplete="off" value="<?php echo htmlspecialchars($current_next_appointment_date); ?>"/>
                                                <div class="input-group-append" data-target="#next_appointment_date_picker" data-toggle="datetimepicker">
                                                    <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-lg-12 text-right">
                                        <button type="submit" id="submit" name="submit" class="btn btn-success btn-sm btn-flat">Update Patient</button>
                                        <a href="patients_list.php" class="btn btn-danger btn-sm btn-flat">Cancel</a>
                                    </div>
                                </div>
                            </form>
                        <?php else: ?>
                            <p>No patient selected for editing or an error occurred. Please go back to the <a href="patients_list.php">Patient List</a>.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>
        <?php include './config/footer.php'; ?>
    </div>
    <?php include './config/site_js_links.php'; ?>
    <script src="plugins/moment/moment.min.js"></script>
    <script src="plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js"></script>
    <script>
        $(function () {
            $('#dob_picker').datetimepicker({
                format: 'YYYY-MM-DD',
                icons: { time: 'fa fa-clock', date: 'fa fa-calendar', up: 'fa fa-arrow-up', down: 'fa fa-arrow-down', previous: 'fa fa-chevron-left', next: 'fa fa-chevron-right', today: 'fa fa-calendar-check-o', clear: 'fa fa-trash', close: 'fa fa-times' }
            });

            $('#next_appointment_date_picker').datetimepicker({
                format: 'YYYY-MM-DD',
                icons: { time: 'fa fa-clock', date: 'fa fa-calendar', up: 'fa fa-arrow-up', down: 'fa fa-arrow-down', previous: 'fa fa-chevron-left', next: 'fa fa-chevron-right', today: 'fa fa-calendar-check-o', clear: 'fa fa-trash', close: 'fa fa-times' },
                minDate: moment().startOf('day')
            });

            function calculateAge() {
                var dobString = $('#dob').val();
                if (dobString) {
                    var dob = moment(dobString, 'YYYY-MM-DD');
                    if (dob.isValid()) {
                        var today = moment();
                        var age = today.diff(dob, 'years');
                        $('#age').val(age);
                    } else { $('#age').val(''); }
                } else { $('#age').val(''); }
            }
            calculateAge(); // Call on load
            $('#dob_picker').on('change.datetimepicker', function (e) { calculateAge(); });
        });
    </script>
</body>
</html>