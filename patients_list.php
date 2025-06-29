<?php
session_start();
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("location:index.php");
    exit;
}

// Include your database connection file
include './config/connection.php';
// Include common functions if needed (e.g., for showMenuSelected if this page uses it)
include './common_service/common_functions.php';

$whereClause = ''; // Initialize an empty WHERE clause
$pageTitle = 'All Registered Patients'; // Default title

// Check if a filter is set in the URL
if (isset($_GET['filter'])) {
    $filterType = $_GET['filter'];
    switch ($filterType) {
        case 'today':
            $whereClause = " WHERE DATE(registration_date) = CURDATE()";
            $pageTitle = 'Patients Registered Today';
            break;
        case 'week':
            // Mode 1 for YEARWEEK means week starts on Monday
            $whereClause = " WHERE YEARWEEK(registration_date, 1) = YEARWEEK(CURDATE(), 1)";
            $pageTitle = 'Patients Registered This Week';
            break;
        case 'month':
            $whereClause = " WHERE DATE_FORMAT(registration_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
            $pageTitle = 'Patients Registered This Month';
            break;
        // You can add more cases here if you have other custom filters
    }
}

$patients = []; // Initialize patients array

try {
    // Construct the SQL query with the dynamic WHERE clause
    // ORDER BY registration_date DESC to show most recent registrations first
    // Added id DESC as a secondary sort for consistent order on same-day registrations
    $stmt = $con->prepare("SELECT `id`, `patient_name`, `patient_display_id`, `gender`, `address`, `registration_date`, `dob`, `marital_status` FROM patients" . $whereClause . " ORDER BY registration_date DESC, id DESC");
    $stmt->execute();
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $ex) {
    // Log the error for debugging. Check your PHP error logs for these messages.
    error_log("PDO Error in patients_list.php: " . $ex->getMessage());
    // In a production environment, you might display a user-friendly error message
    // but avoid exposing sensitive database error details directly to the user.
    $patients = []; // Return empty array on error
}

// Function to calculate age from date of birth
function calculateAge($dob) {
    if (empty($dob) || $dob == '0000-00-00') {
        return 'N/A'; // Handle cases where DOB is not set
    }
    $birthDate = new DateTime($dob);
    $currentDate = new DateTime();
    $age = $birthDate->diff($currentDate)->y;
    return $age;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $pageTitle; ?> - KDCS</title>
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <link rel="stylesheet" href="plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="plugins/datatables-responsive/css/responsive.bootstrap4.min.css">
    <link rel="stylesheet" href="plugins/datatables-buttons/css/buttons.bootstrap4.min.css">
</head>
<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed">
<div class="wrapper">
    <?php include './config/header.php'; ?>
    <?php include './config/sidebar.php'; ?>

    <div class="content-wrapper">
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0"><?php echo $pageTitle; ?></h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Patients List</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <section class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Patient Records</h3>
                                <?php
                                // Display success or error messages from redirection
                                if (isset($_GET['message'])) {
                                    $msg_type = isset($_GET['status']) && $_GET['status'] == 'success' ? 'success' : 'danger';
                                    echo '<div class="alert alert-' . $msg_type . ' mt-3">' . htmlspecialchars(urldecode($_GET['message'])) . '</div>';
                                }
                                ?>
                            </div>
                            <div class="card-body">
                                <table id="patientTable" class="table table-bordered table-striped">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Patient Name</th>
                                            <th>Patient ID</th>
                                            <th>Gender</th>
                                            <th>Age</th>
                                            <th>Marital Status</th>
                                            <th>Address</th>
                                            <th>Registration Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($patients) > 0): ?>
                                            <?php foreach ($patients as $patient): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($patient['id']); ?></td>
                                                    <td><?php echo htmlspecialchars($patient['patient_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($patient['patient_display_id']); ?></td>
                                                    <td><?php echo htmlspecialchars($patient['gender']); ?></td>
                                                    <td><?php echo calculateAge($patient['dob']); ?></td>
                                                    <td><?php echo htmlspecialchars($patient['marital_status']); ?></td>
                                                    <td><?php echo htmlspecialchars($patient['address']); ?></td>
                                                    <td><?php echo htmlspecialchars($patient['registration_date']); ?></td>
                                                    <td>
                                                        <a href="edit_patient.php?patient_id=<?php echo htmlspecialchars($patient['id']); ?>" class="btn btn-info btn-sm">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </a>
                                                        </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="9">No patients found for this filter.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <?php include './config/footer.php'; ?>
</div>

<script src="plugins/jquery/jquery.min.js"></script>
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="dist/js/adminlte.min.js"></script>
<script src="plugins/datatables/jquery.dataTables.min.js"></script>
<script src="plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
<script src="plugins/datatables-responsive/js/dataTables.responsive.min.js"></script>
<script src="plugins/datatables-responsive/js/responsive.bootstrap4.min.js"></script>
<script src="plugins/datatables-buttons/js/dataTables.buttons.min.js"></script>
<script src="plugins/datatables-buttons/js/buttons.bootstrap4.min.js"></script>
<script src="plugins/jszip/jszip.min.js"></script>
<script src="plugins/pdfmake/pdfmake.min.js"></script>
<script src="plugins/pdfmake/vfs_fonts.js"></script>
<script src="plugins/datatables-buttons/js/buttons.html5.min.js"></script>
<script src="plugins/datatables-buttons/js/buttons.print.min.js"></script>
<script src="plugins/datatables-buttons/js/buttons.colVis.min.js"></script>

<script>
    $(document).ready(function() {
        // Initialize DataTables
        $("#patientTable").DataTable({
            "responsive": true,
            "lengthChange": false,
            "autoWidth": false,
            "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"]
        }).buttons().container().appendTo('#patientTable_wrapper .col-md-6:eq(0)');

        // Add menu highlighting for this page if it's part of the sidebar
        // showMenuSelected("#mnu_patients_list"); // Uncomment if you have a specific menu ID for patients list
    });
</script>
</body>
</html>