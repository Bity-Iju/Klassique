<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("location:index.php");
    exit;
}

include './config/connection.php';
include './common_service/common_functions.php';

// Initialize counts
$total_patients = 0;
$total_lab_orders = 0;
$total_scan_orders = 0;
$pending_lab_orders = 0;
$pending_scan_orders = 0; // Initialize to 0 or an appropriate default
$patients_today = 0;
$patients_this_week = 0;
$patients_this_month = 0;
$latest_patients = []; // Initialize array for latest patients

try {
    // Fetch total patients
    $stmt_patients = $con->prepare("SELECT COUNT(id) AS total FROM patients");
    $stmt_patients->execute();
    $total_patients = $stmt_patients->fetch(PDO::FETCH_ASSOC)['total'];

    // Fetch total lab orders
    $stmt_lab_orders = $con->prepare("SELECT COUNT(id) AS total FROM lab_results");
    $stmt_lab_orders->execute();
    $total_lab_orders = $stmt_lab_orders->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Fetch pending lab orders
    // IMPORTANT: The 'lab_orders' table in your pms_db.sql does NOT have a 'status' column.
    // This query is commented out. If you want to track pending lab orders,
    // you must ADD a 'status' column (e.g., VARCHAR(50) DEFAULT 'Pending') to your 'lab_orders' table.
    // Once added, you can uncomment this block and set the correct status values.
    /*
    $stmt_pending_lab = $con->prepare("SELECT COUNT(id) AS total FROM lab_orders WHERE status = 'Pending' OR status = 'Awaiting Results'");
    $stmt_pending_lab->execute();
    $pending_lab_orders = $stmt_pending_lab->fetch(PDO::FETCH_ASSOC)['total'];
    */

    // Fetch total scan orders
    $stmt_scan_orders = $con->prepare("SELECT COUNT(id) AS total FROM scans");
    $stmt_scan_orders->execute();
    $total_scan_orders = $stmt_scan_orders->fetch(PDO::FETCH_ASSOC)['total'];

    // Fetch pending scan orders
    // PROBLEM LINE COMMENTED OUT: The 'scan_notes' column consistently causes a 'Column not found' error.
    // If you want this count to work, you MUST ensure 'scan_notes' (or another status column)
    // actually exists in your live 'scans' table.
    /*
    $stmt_pending_scan = $con->prepare("SELECT COUNT(id) AS total FROM scans WHERE scan_notes IS NULL OR scan_notes = ''");
    $stmt_pending_scan->execute();
    $pending_scan_orders = $stmt_pending_scan->fetch(PDO::FETCH_ASSOC)['total'];
    */
    // Since the query is commented out, $pending_scan_orders will retain its initialized value (0).

    // Fetch patients today - UPDATED to use 'registration_date'
    $stmt_patients_today = $con->prepare("SELECT COUNT(id) AS total FROM patients WHERE DATE(registration_date) = CURDATE()");
    $stmt_patients_today->execute();
    $patients_today = $stmt_patients_today->fetch(PDO::FETCH_ASSOC)['total'];

    // Fetch patients this week - UPDATED to use 'registration_date'
    $stmt_patients_this_week = $con->prepare("SELECT COUNT(id) AS total FROM patients WHERE YEARWEEK(registration_date, 1) = YEARWEEK(CURDATE(), 1)");
    $stmt_patients_this_week->execute();
    $patients_this_week = $stmt_patients_this_week->fetch(PDO::FETCH_ASSOC)['total'];

    // Fetch patients this month - UPDATED to use 'registration_date'
    $stmt_patients_this_month = $con->prepare("SELECT COUNT(id) AS total FROM patients WHERE DATE_FORMAT(registration_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')");
    $stmt_patients_this_month->execute();
    $patients_this_month = $stmt_patients_this_month->fetch(PDO::FETCH_ASSOC)['total'];

    // Fetch last 10 registered patients - UPDATED to use 'registration_date'
    $stmt_latest_patients = $con->prepare("SELECT `id`, `patient_name`, `cnic`, `gender`, `address`, `registration_date` FROM patients ORDER BY id DESC LIMIT 10");
    $stmt_latest_patients->execute();
    $latest_patients = $stmt_latest_patients->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $ex) {
    error_log("PDO Error in dashboard.php: " . $ex->getMessage());
    // Fallback to 0 on error
    $total_patients = 0;
    $total_lab_orders = 0;
    $total_scan_orders = 0;
    $pending_lab_orders = 0;
    $pending_scan_orders = 0;
    $patients_today = 0;
    $patients_this_week = 0;
    $patients_this_month = 0;
    $latest_patients = []; // Ensure it's empty on error
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - KDCS</title>
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">

    

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
                        <h1 class="m-0">Dashboard</h1>
                    </div><div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="#">Home</a></li>
                            <li class="breadcrumb-item active">Dashboard</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
        <section class="content">
            <div class="container-fluid">
                <?php
                // Display error messages from access control redirects
                if (isset($_GET['error'])) {
                    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
                    echo htmlspecialchars($_GET['error']);
                    echo '<button type="button" class="close" data-dismiss=\"alert\" aria-label=\"Close\"><span aria-hidden=\"true\">&times;</span></button></div>';
                }
                ?>
                <div class="row">
                    <div class="col-lg-2 col-6">
                        <div class="small-box bg-warning">
                            <div class="inner">
                                <h3><?php echo htmlspecialchars($patients_today); ?></h3>
                                <p>Patients Today</p>
                            </div>
                            <div class="icon">
                                <i class="ion fas fa-calendar-day"></i>
                            </div>
                            <a href="patients_list.php?filter=today" class="small-box-footer">More info <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                     <div class="col-lg-2 col-6">
                        <div class="small-box bg-secondary">
                            <div class="inner">
                                <h3><?php echo htmlspecialchars($patients_this_week); ?></h3>
                                <p>Patients This Week</p>
                            </div>
                            <div class="icon">
                                <i class="ion fas fa-calendar-week"></i>
                            </div>
                            <a href="patients_list.php?filter=week" class="small-box-footer">More info <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                    <div class="col-lg-2 col-6">
                        <div class="small-box bg-dark">
                            <div class="inner">
                                <h3><?php echo htmlspecialchars($patients_this_month); ?></h3>
                                <p>Patients This Month</p>
                            </div>
                            <div class="icon">
                                <i class="ion fas fa-calendar-alt"></i>
                            </div>
                            <a href="patients_list.php?filter=month" class="small-box-footer">More info <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                    <div class="col-lg-2 col-6">
                        <div class="small-box bg-primary">
                            <div class="inner">
                                <h3><?php echo htmlspecialchars($total_patients); ?></h3>
                                <p>Total Patients</p>
                            </div>
                            <div class="icon">
                                <i class="ion fas fa-user-injured"></i>
                            </div>
                            <a href="patients_list.php" class="small-box-footer">More info <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                    <div class="col-lg-2 col-6">
                        <div class="small-box bg-info">
                            <div class="inner">
                                <h3><?php echo htmlspecialchars($total_lab_orders); ?></h3>
                                <p>Total Lab Orders (<?php echo htmlspecialchars($pending_lab_orders); ?> Pending)</p>
                            </div>
                            <div class="icon">
                                <i class="ion fas fa-flask"></i>
                            </div>
                            <a href="manage_lab_results.php" class="small-box-footer">More info <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                    <div class="col-lg-2 col-6">
                        <div class="small-box bg-success">
                            <div class="inner">
                                <h3><?php echo htmlspecialchars($total_scan_orders); ?></h3>
                                <p>Total Scan Orders (<?php echo htmlspecialchars($pending_scan_orders); ?> Pending)</p>
                            </div>
                            <div class="icon">
                                <i class="ion fas fa-x-ray"></i>
                            </div>
                            <a href="scan.php" class="small-box-footer">More info <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>

                    </div>
                <div class="row">
                    <div class="col-md-12">
                        <div class="card card-success card-outline">
                            <div class="card-header">
                                <h3 class="card-title">Quick Actions</h3>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h4>Laboratory Actions</h4>
                                        <div class="list-group">
                                            <a href="laboratory.php" class="list-group-item list-group-item-action"><i class="fas fa-plus-circle mr-2"></i> Add New Lab Order</a>
                                            <a href="manage_lab_results.php" class="list-group-item list-group-item-action"><i class="fas fa-tasks mr-2"></i> Manage Lab Orders</a>
                                            </div>
                                    </div>
                                    <div class="col-md-6">
                                        <h4>Scanning Actions</h4>
                                        <div class="list-group">
                                            <a href="scan.php?action=add" class="list-group-item list-group-item-action"><i class="fas fa-plus-circle mr-2"></i> Add New Scan Order</a>
                                            <a href="scan.php" class="list-group-item list-group-item-action"><i class="fas fa-tasks mr-2"></i> Manage Scan Orders</a>
                                            </div>
                                    </div>
                                </div>
                                <hr>
                                <div class="row mt-4">
                                    <div class="col-md-12">
                                        <h4>Latest Registered Patients</h4>
                                        <table class="table table-bordered table-striped">
                                            <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Patient Name</th>
                                                    <th>Unique ID</th>
                                                    <th>Gender</th>
                                                    <th>Address</th>
                                                    <th>Registered On</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($latest_patients) > 0): ?>
                                                    <?php foreach ($latest_patients as $patient): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($patient['id']); ?></td>
                                                            <td><?php echo htmlspecialchars($patient['patient_name']); ?></td>
                                                            <td><?php echo htmlspecialchars($patient['cnic']); ?></td>
                                                            <td><?php echo htmlspecialchars($patient['gender']); ?></td>
                                                            <td><?php echo htmlspecialchars($patient['address']); ?></td>
                                                            <td><?php echo htmlspecialchars($patient['registration_date']); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="6">No patients registered yet.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                </div></section>
        </div>
    <?php include './config/footer.php'; ?>
</div>
<script src="plugins/jquery/jquery.min.js"></script>
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="dist/js/adminlte.min.js"></script>
<script>
    $(document).ready(function() {
        // Highlight sidebar menu item for Dashboard
        showMenuSelected("#mnu_dashboard"); // Ensure this function is defined in common_functions.php or elsewhere.
    });
</script>
</body>
</html>