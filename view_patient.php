<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("location:index.php");
    exit;
}

include './config/connection.php';
include './common_service/common_functions.php'; // For dropdowns if needed for display, e.g., gender, marital status

$patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;
$patient_data = null;
$message = '';
$patient_age = 'N/A'; // Initialize age variable

if ($patient_id > 0) {
    try {
        $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Fetch patient details including the new patient_display_id
        $query = "SELECT `id`, `patient_name`, `address`, `cnic`, `dob`, `contact_no`, `gender`, `marital_status`, `next_appointment_date`, `patient_display_id` FROM `patients` WHERE `id` = :patient_id";
        $stmt = $con->prepare($query);
        $stmt->bindParam(':patient_id', $patient_id, PDO::PARAM_INT);
        $stmt->execute();
        $patient_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($patient_data) {
            // Calculate age in PHP
            if (!empty($patient_data['dob'])) {
                $dob_obj = new DateTime($patient_data['dob']);
                $today = new DateTime();
                $age_interval = $today->diff($dob_obj);
                $patient_age = $age_interval->y;
            }
        } else {
            $message = "<div class='alert alert-warning'>Patient not found.</div>";
        }

    } catch (PDOException $ex) {
        error_log("PDO Error in view_patient.php: " . $ex->getMessage());
        $message = "<div class='alert alert-danger'>Database error: " . htmlspecialchars($ex->getMessage()) . "</div>";
    }
} else {
    $message = "<div class='alert alert-danger'>Invalid patient ID.</div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include './config/site_css_links.php'; ?>
    <title>View Patient - KDCS</title>
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
                            <h1>View Patient Details</h1>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-right">
                                <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                                <li class="breadcrumb-item"><a href="patients_list.php">Patient List</a></li>
                                <li class="breadcrumb-item active">View Patient</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </section>

            <section class="content">
                <div class="card card-outline card-success rounded-0 shadow">
                    <div class="card-header">
                        <h3 class="card-title">Patient Information</h3>
                        <div class="card-tools">
                            <a href="patients_list.php" class="btn btn-success btn-sm"><i class="fa fa-arrow-left"></i> Back to List</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php echo $message; ?>
                        <?php if ($patient_data): ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>Clinic Number:</strong> <?php echo htmlspecialchars($patient_data['patient_display_id'] ?: 'N/A'); ?></p>
                                    <p><strong>Patient Name:</strong> <?php echo htmlspecialchars($patient_data['patient_name']); ?></p>
                                    <p><strong>Unique ID:</strong> <?php echo htmlspecialchars($patient_data['cnic']); ?></p>
                                    <p><strong>Age:</strong> <?php echo htmlspecialchars($patient_age); ?></p> 
                                    <p><strong>Gender:</strong> <?php echo htmlspecialchars($patient_data['gender']); ?></p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Date of Birth:</strong> <?php echo htmlspecialchars($patient_data['dob']); ?></p>
                                    <p><strong>Phone Number:</strong> <?php echo htmlspecialchars($patient_data['contact_no']); ?></p>
                                    <p><strong>Marital Status:</strong> <?php echo htmlspecialchars($patient_data['marital_status'] ?: 'N/A'); ?></p>
                                    <p><strong>Next Appointment Date:</strong> <?php echo htmlspecialchars($patient_data['next_appointment_date'] ?: 'N/A'); ?></p>
                                    <p><strong>Address:</strong> <?php echo htmlspecialchars($patient_data['address']); ?></p>
                                </div>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-12 text-right">
                                    <a href="edit_patient.php?patient_id=<?php echo htmlspecialchars($patient_data['id']); ?>" class="btn btn-success btn-sm btn-flat"><i class="fa fa-edit"></i> Edit Patient</a>
                                    <a href="delete_patient.php?patient_id=<?php echo htmlspecialchars($patient_data['id']); ?>" class="btn btn-danger btn-sm btn-flat" onclick="return confirm('Are you sure you want to delete this patient?');"><i class="fa fa-trash"></i> Delete Patient</a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>
        <?php include './config/footer.php'; ?>
    </div>
    <?php include './config/site_js_links.php'; ?>
    </body>
</html>