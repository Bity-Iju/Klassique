<?php
// Temporarily enable detailed error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("location:index.php");
    exit;
}

include './config/connection.php';
// Ensure getGenderEnumOptions and getMaritalStatus are defined in common_functions.php
include './common_service/common_functions.php';

$message = '';
$generated_patient_display_id_for_form = ''; // Variable to hold the generated Clinic Number for display
$generated_cnic_for_form = ''; // Variable to hold the generated Unique ID (CNIC) for display on error

// Ensure PDO error mode is set to exception for better debugging
if (isset($con)) { $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }

// Function to generate the unique CNIC with mixed case alphanumeric characters
function generateUniqueCNIC($con) {
    $prefix = "KDCS-MUB1-";
    $is_unique = false;
    $generated_cnic = '';

    while (!$is_unique) {
        // Generate two parts for randomness
        $raw_random_chars = bin2hex(random_bytes(2)); // This gives 4 lowercase hex characters (e.g., "a3f7")
        $final_random_chars = '';

        // Iterate through the raw hex chars and randomly change case
        for ($i = 0; $i < strlen($raw_random_chars); $i++) {
            if (mt_rand(0, 1) === 0) { // 50% chance to be uppercase
                $final_random_chars .= strtoupper($raw_random_chars[$i]);
            } else { // 50% chance to be lowercase
                $final_random_chars .= strtolower($raw_random_chars[$i]);
            }
        }

        // Second part: 4 random digits, padded with leading zeros
        $random_part2 = sprintf('%04d', mt_rand(0, 9999));

        $temp_cnic = $prefix . $final_random_chars . '-' . $random_part2;

        // Check if this CNIC already exists in the database
        $stmt = $con->prepare("SELECT COUNT(*) FROM patients WHERE cnic = :cnic");
        $stmt->bindParam(':cnic', $temp_cnic);
        $stmt->execute();
        $count = $stmt->fetchColumn();

        if ($count == 0) {
            $generated_cnic = $temp_cnic;
            $is_unique = true;
        }
    }
    return $generated_cnic;
}


if (isset($_POST['submit'])) {
    // Sanitize and validate inputs
    $patient_name = isset($_POST['patient_name']) ? htmlspecialchars(trim($_POST['patient_name'])) : '';
    $address = isset($_POST['address']) ? htmlspecialchars(trim($_POST['address'])) : '';
    // $patient_cnic is now auto-generated, not from POST
    $dob = isset($_POST['dob']) ? htmlspecialchars(trim($_POST['dob'])) : '';
    $contact_no = isset($_POST['contact_no']) ? htmlspecialchars(trim($_POST['contact_no'])) : '';
    $gender = isset($_POST['gender']) ? htmlspecialchars(trim($_POST['gender'])) : '';
    $marital_status = isset($_POST['marital_status']) ? htmlspecialchars(trim($_POST['marital_status'])) : '';
    $next_appointment_date = isset($_POST['next_appointment_date']) && !empty($_POST['next_appointment_date']) ? htmlspecialchars(trim($_POST['next_appointment_date'])) : null;

    // Repopulate form fields for sticky form on error
    $sticky_patient_name = $patient_name;
    $sticky_address = $address;
    $sticky_dob = $dob;
    $sticky_contact_no = $contact_no;
    $sticky_gender = $gender;
    $sticky_marital_status = $marital_status;
    $sticky_next_appointment_date = $next_appointment_date;
    $generated_cnic_for_form = ''; // Initialize as empty for now, filled on success

    // Basic validation (removed CNIC from required fields as it's auto-generated)
    if (empty($patient_name) || empty($address) || empty($dob) || empty($contact_no) || empty($gender) || empty($marital_status)) {
        $message = "<div class='alert alert-danger'>All required fields (Patient Name, Address, Date of Birth, Phone Number, Gender, Marital Status) must be filled.</div>";
    } else if (!preg_match("/^[0-9]{11}$/", $contact_no)) { // Simple 11-digit number check for Nigerian numbers
        $message = "<div class='alert alert-danger'>Invalid Contact Number. Please enter an 11-digit number.</div>";
    } else {
        try {
            $con->beginTransaction();

            // GENERATE THE UNIQUE ID (CNIC) HERE
            $patient_cnic_generated = generateUniqueCNIC($con); // This is the new CNIC

            // Prepare the insert query
            $query = "INSERT INTO `patients` (`patient_name`, `address`, `cnic`, `dob`, `contact_no`, `gender`, `marital_status`, `next_appointment_date`, `patient_display_id`)
                      VALUES (:patient_name, :address, :cnic, :dob, :contact_no, :gender, :marital_status, :next_appointment_date, :patient_display_id)";

            $stmt = $con->prepare($query);

            $stmt->bindParam(':patient_name', $patient_name, PDO::PARAM_STR);
            $stmt->bindParam(':address', $address, PDO::PARAM_STR);
            $stmt->bindParam(':cnic', $patient_cnic_generated, PDO::PARAM_STR); // Bind the auto-generated CNIC
            $stmt->bindParam(':dob', $dob, PDO::PARAM_STR);
            $stmt->bindParam(':contact_no', $contact_no, PDO::PARAM_STR);
            $stmt->bindParam(':gender', $gender, PDO::PARAM_STR);
            $stmt->bindParam(':marital_status', $marital_status, PDO::PARAM_STR);
            $stmt->bindValue(':patient_display_id', null, PDO::PARAM_STR); // Bind as NULL initially
            $stmt->bindParam(':next_appointment_date', $next_appointment_date, PDO::PARAM_STR);

            if ($stmt->execute()) {
                $last_inserted_id = $con->lastInsertId(); // Get the auto-generated `id`
                // Format the Clinic Number: "KDCS/MUB/0001" (padded to 4 digits)
                $generated_patient_display_id_value = "KDCS/MUB/" . str_pad($last_inserted_id, 4, '0', STR_PAD_LEFT);

                // Update the patient record with the newly generated formatted Clinic Number
                $update_query = "UPDATE `patients` SET `patient_display_id` = :patient_display_id WHERE `id` = :id";
                $update_stmt = $con->prepare($update_query);
                $update_stmt->bindParam(':patient_display_id', $generated_patient_display_id_value, PDO::PARAM_STR);
                $update_stmt->bindParam(':id', $last_inserted_id, PDO::PARAM_INT);

                if ($update_stmt->execute()) {
                    $con->commit();
                    $message = 'Patient registered successfully. Clinic Number: ' . $generated_patient_display_id_value . ' | Unique ID: ' . $patient_cnic_generated;
                    // Redirect to the congratulation page, which will then send to patients_list.php
                    header("location:congratulation.php?goto_page=patients_list.php&message=" . urlencode($message));
                    exit;
                } else {
                    $con->rollBack();
                    $message = "<div class='alert alert-danger'>Error updating Clinic Number.</div>";
                }
            } else {
                $con->rollBack();
                $message = "<div class='alert alert-danger'>Error registering patient.</div>";
            }

        } catch (PDOException $ex) {
            $con->rollBack();
            error_log("PDO Error in patients.php: " . $ex->getMessage());
            $message = "<div class='alert alert-danger'>Database error: " . htmlspecialchars($ex->getMessage()) . "</div>";
            // Check for unique constraint violation specifically on CNIC
            if ($ex->getCode() == '23000' && strpos($ex->getMessage(), 'Duplicate entry') !== false) {
                 $message = "<div class='alert alert-danger'>A patient with a similar auto-generated Unique ID already exists. Please try submitting again.</div>"; // Should be rare due to generation logic
            }
        }
    }
}

// Get dropdown options for gender and marital status (for sticky form if an error occurred)
// Ensure these functions exist in common_functions.php
$gender_options = getGenderEnumOptions(isset($sticky_gender) ? $sticky_gender : '');
$marital_status_options = getMaritalStatus(isset($sticky_marital_status) ? $sticky_marital_status : '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include './config/site_css_links.php'; ?>
    <title>Register Patient - KDCS</title>
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
                            <h1>Register New Patient</h1>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-right">
                                <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                                <li class="breadcrumb-item active">Register Patient</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </section>

            <section class="content">
                <div class="card card-outline card-success rounded-0 shadow">
                    <div class="card-header">
                        <h3 class="card-title">Patient Details</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="post" action="patients.php">
                            <?php echo $message; ?>
                            <div class="row">
                                <div class="col-lg-2 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="patient_display_id_field">Clinic Number</label>
                                        <input type="text" class="form-control form-control-sm rounded-0" id="patient_display_id_field" value="<?php echo htmlspecialchars($generated_patient_display_id_for_form); ?>" readonly placeholder="Will be auto-generated">
                                    </div>
                                </div>
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="patient_name">Patient Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control form-control-sm rounded-0" placeholder="Bity Iju" id="patient_name" name="patient_name" required value="<?php echo isset($sticky_patient_name) ? htmlspecialchars($sticky_patient_name) : ''; ?>">
                                    </div>
                                </div>
                                <div class="col-lg-6 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="address">Address <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control form-control-sm rounded-0" placeholder="X40 Barama, near Gipalma junction" id="address" name="address" required value="<?php echo isset($sticky_address) ? htmlspecialchars($sticky_address) : ''; ?>">
                                    </div>
                                </div>
                                <div class="col-lg-2 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="patient_cnic_display">Unique ID</label>
                                        <input type="text" class="form-control form-control-sm rounded-0" id="patient_cnic_display" value="<?php echo htmlspecialchars($generated_cnic_for_form); ?>" readonly placeholder="Will be auto-generated">
                                    </div>
                                </div>
                                <div class="col-lg-2 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label>Date of Birth <span class="text-danger">*</span></label>
                                        <div class="input-group date" id="dob_picker" data-target-input="nearest">
                                            <input type="date" class="form-control form-control-sm rounded-0 datetimepicker-input" data-target="#dob_picker" name="dob" id="dob" required autocomplete="off" value="<?php echo isset($sticky_dob) ? htmlspecialchars($sticky_dob) : ''; ?>"/>
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
                                        <label for="gender">Gender <span class="text-danger">*</span></label>
                                        <select class="form-control form-control-sm rounded-0" id="gender" name="gender" required>
                                            <?php echo $gender_options; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-lg-2 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="marital_status">Marital Status <span class="text-danger">*</span></label>
                                        <select class="form-control form-control-sm rounded-0" id="marital_status" name="marital_status" required>
                                            <?php echo $marital_status_options; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="contact_no">Phone Number <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control form-control-sm rounded-0" id="contact_no" name="contact_no" required value="<?php echo isset($sticky_contact_no) ? htmlspecialchars($sticky_contact_no) : ''; ?>" pattern="[0-9]{11}" title="Please enter an 11-digit phone number (e.g., 08012345678)">
                                    </div>
                                </div>

                                <div class="col-lg-2 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label>Next Appointment Date (Optional)</label>
                                        <div class="input-group date" id="next_appointment_date_picker" data-target-input="nearest">
                                            <input type="date" class="form-control form-control-sm rounded-0 datetimepicker-input" data-target="#next_appointment_date_picker" name="next_appointment_date" id="next_appointment_date" autocomplete="off" value="<?php echo isset($sticky_next_appointment_date) ? htmlspecialchars($sticky_next_appointment_date) : ''; ?>"/>
                                            <div class="input-group-append" data-target="#next_appointment_date_picker" data-toggle="datetimepicker">
                                                <div class="input-group-text"><i class="fa fa-calendar"></i></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-lg-12 text-center">
                                    <button type="submit" id="submit" name="submit" class="btn btn-success btn-sm btn-flat col-lg-2 col-md-4 col-lg-2">Register Patient</button>
                                    <button type="reset" class="btn btn-danger btn-sm btn-flat col-lg-2 col-md-4 col-lg-2" align>Clear Form</button>
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
    <script>
        $(function () {
            // Initialize Datepicker for Date of Birth
            $('#dob_picker').datetimepicker({
                format: 'YYYY-MM-DD',
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

            // Initialize Datepicker for Next Appointment Date
            $('#next_appointment_date_picker').datetimepicker({
                format: 'YYYY-MM-DD',
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
                },
                minDate: moment().startOf('day') // Prevent selecting past dates for next appointment
            });

            // Function to calculate age
            function calculateAge() {
                var dobString = $('#dob').val();
                if (dobString) {
                    var dob = moment(dobString, 'YYYY-MM-DD');
                    if (dob.isValid()) {
                        var today = moment();
                        var age = today.diff(dob, 'years');
                        $('#age').val(age);
                    } else {
                        $('#age').val(''); // Clear age if DOB is invalid
                    }
                } else {
                    $('#age').val(''); // Clear age if DOB is empty
                }
            }

            // Call calculateAge on page load if DOB is already present (e.g., sticky form)
            calculateAge();

            // Call calculateAge whenever the date of birth changes
            $('#dob_picker').on('change.datetimepicker', function (e) {
                calculateAge();
            });
        });

        // Highlight sidebar menu item
        showMenuSelected("#mnu_patients", "#mi_add_patient"); // Adjust IDs based on your sidebar structure

        // Display message if present (e.g., from redirection)
        // This is usually handled by the congratulation.php and then by patients_list.php
        // var message = '<?php //echo isset($_GET['message']) ? htmlspecialchars($_GET['message']) : '';?>';
        // if(message !== '') {
        //     alert(message);
        // }
    </script>
</body>
</html>