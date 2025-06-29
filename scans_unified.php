<?php
// Check if a session is NOT already active before starting one
// This prevents the "Notice: session_start(): Ignoring session_start() because a session is already active" error
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("location:index.php");
    exit;
}

// Include your database connection file
require_once './config/connection.php';

// Ensure $con from connection.php is available as $pdo for consistency
// IMPORTANT: Confirm your config/connection.php has:
// $con->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
// This is critical for fetching numeric values as actual numbers.
$pdo = $con;

// --- Fetch Clinic Details by reading index.php content ---
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


$message = '';
$action = $_GET['action'] ?? 'list'; // Default action is 'list'
$id = $_GET['id'] ?? null; // ID for edit/delete operations

// --- AJAX Request Handler for Ultrasound Data ---
if ($action === 'fetch_ultrasound_data') {
    header('Content-Type: application/json');
    if (isset($_GET['type'])) {
        if ($_GET['type'] == 'categories' && isset($_GET['scan_type_id'])) {
            $scan_type_id = $_GET['scan_type_id'];
            try {
                // Ensure 'price' column is correctly selected here
                $stmt = $pdo->prepare("SELECT id, category_name, price FROM ultrasound_categories WHERE scan_type_id = ? ORDER BY category_name");
                $stmt->execute([$scan_type_id]);
                $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($categories);
            } catch (PDOException $e) {
                error_log("AJAX Error (categories): " . $e->getMessage());
                echo json_encode(['error' => $e->getMessage()]);
            }
        } elseif ($_GET['type'] == 'results' && isset($_GET['ultrasound_category_id'])) {
            $ultrasound_category_id = $_GET['ultrasound_category_id'];
            try {
                $stmt = $pdo->prepare("SELECT id, description FROM ultrasound_results WHERE ultrasound_category_id = ? ORDER BY description");
                $stmt->execute([$ultrasound_category_id]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($results);
            } catch (PDOException $e) {
                error_log("AJAX Error (results): " . $e->getMessage());
                echo json_encode(['error' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['error' => 'Invalid request parameters for ultrasound data.']);
        }
    } else {
        echo json_encode(['error' => 'No type specified for ultrasound data fetch.']);
    }
    exit; // Terminate script after sending JSON response
}

// --- Main Application Logic ---

// Fetch common dropdown data needed for add/edit forms
$patients = [];
$scan_types = [];
$users = [];
try {
    $stmt_patients = $pdo->query("SELECT id, patient_name FROM patients ORDER BY patient_name");
    $patients = $stmt_patients->fetchAll(PDO::FETCH_ASSOC);

    $stmt_scan_types = $pdo->query("SELECT id, scan_name FROM scanning_types ORDER BY scan_name");
    $scan_types = $stmt_scan_types->fetchAll(PDO::FETCH_ASSOC);

    $stmt_users = $pdo->query("SELECT id, display_name FROM users WHERE user_type IN ('Doctor', 'Admin', 'user') ORDER BY display_name");
    $users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "<div class='alert alert-danger'>Error fetching dropdown data: " . htmlspecialchars($e->getMessage()) . "</div>";
    error_log("Error fetching dropdown data in scans_unified.php: " . $e->getMessage());
}

// --- Handle Form Submissions (Add/Edit) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $patient_id = $_POST['patient_id'] ?? null;
    $scan_type_id = $_POST['scan_type_id'] ?? null;
    $ordered_by_user_id = $_POST['ordered_by_user_id'] ?? null;
    $ultrasound_category_id = empty($_POST['ultrasound_category_id']) ? null : $_POST['ultrasound_category_id']; // Treat empty as NULL
    $ultrasound_result_id = empty($_POST['ultrasound_result_id']) ? null : $_POST['ultrasound_result_id'];     // Treat empty as NULL
    // Ensure total_price is treated as a number, defaulting to 0.00 if empty/invalid
    $total_price = filter_var($_POST['total_price'] ?? 0.00, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    if ($total_price === null) { // If validation failed, set to 0.00
        $total_price = 0.00;
    }

    $result_notes = $_POST['result_notes'] ?? null;

    // Validate inputs (only patient_id, scan_type_id, ordered_by_user_id are strictly required from this section)
    if (empty($patient_id) || empty($scan_type_id) || empty($ordered_by_user_id)) {
        $message = "<div class='alert alert-danger'>Please fill in all required fields (Patient, Scan Type, Ordered By).</div>";
    } else {
        // Cast total_price to float to ensure it's stored as a number (already handled by filter_var)
        //$total_price = (float)$total_price; // No longer needed due to filter_var

        if ($action == 'add_submit') {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO scan_orders (patient_id, scan_type_id, ordered_by_user_id, ultrasound_category_id, ultrasound_result_id, total_price, result_notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?);
                ");
                $stmt->execute([$patient_id, $scan_type_id, $ordered_by_user_id, $ultrasound_category_id, $ultrasound_result_id, $total_price, $result_notes]);
                header("Location: scans_unified.php?status=added");
                exit();
            } catch (PDOException $e) {
                $message = "<div class='alert alert-danger'>Error adding scan: " . htmlspecialchars($e->getMessage()) . "</div>";
                error_log("Error adding scan in scans_unified.php: " . $e->getMessage());
            }
        } elseif ($action == 'edit_submit' && $id) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE scan_orders
                    SET
                        patient_id = ?,
                        scan_type_id = ?,
                        ordered_by_user_id = ?,
                        ultrasound_category_id = ?,
                        ultrasound_result_id = ?,
                        total_price = ?,
                        result_notes = ?
                    WHERE
                        id = ?;
                ");
                $stmt->execute([$patient_id, $scan_type_id, $ordered_by_user_id, $ultrasound_category_id, $ultrasound_result_id, $total_price, $result_notes, $id]);
                header("Location: scans_unified.php?status=updated");
                exit();
            } catch (PDOException $e) {
                $message = "<div class='alert alert-danger'>Error updating scan: " . htmlspecialchars($e->getMessage()) . "</div>";
                error_log("Error updating scan in scans_unified.php: " . $e->getMessage());
            }
        }
    }
}

// --- Handle Delete Operation ---
if ($action == 'delete' && $id) {
    try {
        $stmt = $pdo->prepare("DELETE FROM scan_orders WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: scans_unified.php?status=deleted");
        exit();
    }
    catch (PDOException $e) {
        $message = "<div class='alert alert-danger'>Error deleting scan: " . htmlspecialchars($e->getMessage()) . "</div>";
        error_log("Error deleting scan in scans_unified.php: " . $e->getMessage());
        $action = 'list'; // Revert to list view if deletion fails
    }
}

// --- Fetch Data for Specific Views ---
$scan_orders = [];
$scan_order_details = null; // For edit view

if ($action == 'list') {
    try {
        $stmt = $pdo->query("
            SELECT
                so.id AS scan_order_id,
                p.patient_display_id AS patient_id, /* Directly fetch patient_display_id */
                p.patient_name,
                st.scan_name,
                uc.category_name,
                ur.description AS result_description, /* Fetch result description */
                so.total_price, /* Will be fetched as numeric due to PDO::ATTR_STRINGIFY_FETCHES = false in connection.php */
                so.order_date,
                so.result_notes,
                so.ultrasound_result_id, -- Fetch this to check for pending status
                so.ultrasound_category_id -- Fetch this as well
            FROM
                scan_orders so
            JOIN
                patients p ON so.patient_id = p.id
            JOIN
                scanning_types st ON so.scan_type_id = st.id
            LEFT JOIN
                ultrasound_categories uc ON so.ultrasound_category_id = uc.id
            LEFT JOIN
                ultrasound_results ur ON so.ultrasound_result_id = ur.id
            ORDER BY
                so.order_date DESC;
        ");
        $scan_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $message = "<div class='alert alert-danger'>Error fetching scan orders: " . htmlspecialchars($e->getMessage()) . "</div>";
        error_log("Error fetching scan orders for list view in scans_unified.php: " . $e->getMessage());
    }
} elseif ($action == 'edit' && $id) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                so.id AS scan_order_id,
                so.patient_id, /* Keep patient_id here for dropdown selection */
                p.patient_display_id AS patient_display_id, /* Fetch for display if needed elsewhere */
                p.patient_name, /* Fetch for display */
                st.scan_name, /* Fetch for display */
                uc.category_name, /* Fetch for display */
                ur.description AS result_description, /* Fetch for display */
                so.scan_type_id,
                so.ordered_by_user_id,
                so.ultrasound_category_id,
                so.ultrasound_result_id,
                so.total_price, /* Will be fetched as numeric */
                so.result_notes
            FROM
                scan_orders so
            JOIN
                patients p ON so.patient_id = p.id
            JOIN
                scanning_types st ON so.scan_type_id = st.id
            LEFT JOIN
                ultrasound_categories uc ON so.ultrasound_category_id = uc.id
            LEFT JOIN
                ultrasound_results ur ON so.ultrasound_result_id = ur.id
            WHERE
                so.id = ?;
        ");
        $stmt->execute([$id]);
        $scan_order_details = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$scan_order_details) {
            // Enhanced logging for debugging "not found"
            error_log("Scan order with ID: " . $id . " not found in database or JOIN failed. This usually indicates a missing patient_id or scan_type_id in master tables for this scan order, or the ID doesn't exist.");
            $message = "<div class='alert alert-danger'>Scan order with ID <strong>" . htmlspecialchars($id) . "</strong> not found. This might be due to a deleted patient or scan type associated with this order.</div>";
            $action = 'list'; // Revert to list if ID is invalid
        }
    } catch (PDOException $e) {
        $message = "<div class='alert alert-danger'>Database error fetching scan order details for edit: " . htmlspecialchars($e->getMessage()) . "</div>";
        error_log("PDO Error fetching scan order details for edit in scans_unified.php: " . $e->getMessage());
        $action = 'list'; // Revert to list on error
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php
        if ($action == 'list') echo 'Manage Scan Orders';
        elseif ($action == 'add') echo 'Add New Scan Order';
        elseif ($action == 'edit') echo 'Edit Scan Order';
        else echo 'Scan Management';
    ?></title>
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <link rel="stylesheet" href="plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="plugins/datatables-responsive/css/responsive.bootstrap4.min.css">
    <link rel="stylesheet" href="plugins/datatables-buttons/css/buttons.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

    <style>
        /* General page scrollability (AdminLTE template usually handles this well by default) */
        html, body {
            height: 100%;
        }
        .wrapper {
            min-height: 100%;
            display: flex;
            flex-direction: column;
        }
        .content-wrapper {
            flex-grow: 1;
        }

        /* Styling for the main heading (default AdminLTE/Bootstrap look) */
        .card-header h3.card-title {
            font-weight: 500; /* Default font-weight for h3 */
            color: inherit; /* Inherit color from parent */
            font-size: 1.25rem; /* Default h3 font-size */
            text-align: left;
        }

        /* Standard Bootstrap form-group spacing */
        .form-group {
            margin-bottom: 1rem;
        }

        /* Labels are visible above inputs (default Bootstrap) */
        .form-group label {
            font-weight: bold; /* Keep bold for clarity */
            color: #333;
            margin-bottom: 0.5rem;
            display: block;
            font-size: 1rem;
        }

        /* Standard Bootstrap styling for input, select, and textarea fields */
        .form-control {
            background-color: #fff;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            padding: 0.375rem 0.75rem;
            box-shadow: none;
            color: #495057;
            font-size: 1rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
            width: 100%;
        }

        /* Focus state for input and select fields (standard Bootstrap) */
        .form-control:focus {
            border-color: #80bdff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
            outline: none;
        }

        /* Placeholder text styling (standard Bootstrap) */
        .form-control::placeholder {
            color: #6c757d;
            opacity: 1;
        }
        .form-control::-webkit-input-placeholder {
            color: #6c757d;
        }
        .form-control:-ms-input-placeholder {
            color: #6c757d;
        }
        .form-control::-ms-input-placeholder {
            color: #6c757d;
        }

        /* Adjust textarea specifically (standard Bootstrap) */
        textarea.form-control {
            resize: vertical;
            min-height: calc(1.5em + 0.75rem + 2px);
        }

        /* Standard Bootstrap .btn-primary */
        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
            border-radius: 0.25rem;
            padding: 0.5rem 1rem;
            font-size: 1rem;
            font-weight: 400;
            color: #fff;
            box-shadow: none;
            transition: background-color 0.15s ease-in-out, border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
            text-transform: none;
            letter-spacing: normal;
        }

        .btn-primary:hover {
            background-color: #0069d9;
            border-color: #0062cc;
            color: #fff;
        }

        /* Standard Bootstrap .btn-secondary */
        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
            color: #fff;
            border-radius: 0.25rem;
            padding: 0.5rem 1rem;
            font-size: 1rem;
            font-weight: 400;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
            border-color: #545b62;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed">
<div class="wrapper">
    <?php include 'config/header.php'; ?>
    <?php include 'config/sidebar.php'; ?>

    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1><?php
                            if ($action == 'list') echo 'Manage Scan Orders';
                            elseif ($action == 'add') echo 'Add New Scan Order';
                            elseif ($action == 'edit') echo 'Edit Scan Order';
                            else echo 'Scan Management';
                        ?></h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active"><?php
                                if ($action == 'list') echo 'Manage Scan Orders';
                                elseif ($action == 'add') echo 'Add New Scan Order';
                                elseif ($action == 'edit') echo 'Edit Scan Order';
                                else echo 'Scan Management';
                            ?></li>
                        </ol>
                    </div>
                </div>
            </div></section>

        <section class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <?php
                                        if ($action == 'list') echo 'List of Scan Orders';
                                        elseif ($action == 'add') echo 'New Scan Order Details';
                                        elseif ($action == 'edit') echo 'Edit Scan Order Details';
                                    ?>
                                </h3>
                                <?php if ($action == 'list'): ?>
                                    <div class="card-tools">
                                        <a href="scans_unified.php?action=add" class="btn btn-success btn-sm">Add New Scan Order</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($message)): ?>
                                    <?php echo $message; ?>
                                <?php endif; ?>

                                <?php if (isset($_GET['status'])): ?>
                                    <?php if ($_GET['status'] == 'added'): ?>
                                        <div class="alert alert-success">Scan order added successfully!</div>
                                    <?php elseif ($_GET['status'] == 'updated'): ?>
                                        <div class="alert alert-success">Scan order updated successfully!</div>
                                    <? elseif ($_GET['status'] == 'deleted'): ?>
                                        <div class="alert alert-success">Scan order deleted successfully!</div>
                                    <?php elseif ($_GET['status'] == 'invalid_id'): ?>
                                        <div class="alert alert-danger">Invalid scan order ID provided.</div>
                                    <?php elseif ($_GET['status'] == 'not_found'): ?>
                                        <div class="alert alert-danger">Scan order not found.</div>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($action == 'list'): ?>
                                    <?php if (empty($scan_orders)): ?>
                                        <p class="text-center">No scan orders found.</p>
                                    <?php else: ?>
                                        <table id="scanOrdersTable" class="table table-bordered table-striped">
                                            <thead>
                                                <tr>
                                                    <th>S.No</th>
                                                    <th>Patient ID</th><th>Patient Name</th>
                                                    <th>Scan Type</th>
                                                    <th>Category</th>
                                                    <th>Price (₦)</th>
                                                    <th>Order Date</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $serial_number = 0; // Initialize serial number
                                                foreach ($scan_orders as $order):
                                                    $serial_number++; // Increment for each row
                                                ?>
                                                <tr>
                                                    <td><?php echo $serial_number; ?></td>
                                                    <td><?php echo $order['patient_id']; ?></td>
                                                    <td><?php echo $order['patient_name']; ?></td>
                                                    <td><?php echo $order['scan_name']; ?></td>
                                                    <td><?php echo $order['category_name']; ?></td>
                                                    <td><?php echo number_format($order['total_price'], 2); ?></td>
                                                    <td><?php echo date('Y-m-d h:i A', strtotime($order['order_date'])); ?></td>
                                                    <td>
                                                        <?php
                                                        // Determine status based on result_notes or ultrasound_result_id
                                                        $status_text = 'Pending';
                                                        $status_class = 'badge bg-warning';
                                                        if (!empty($order['result_notes']) || !empty($order['ultrasound_result_id'])) {
                                                            $status_text = 'Completed';
                                                            $status_class = 'badge bg-success';
                                                        }
                                                        ?>
                                                        <span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                                    </td>
                                                    <td>
                                                        <a href="scans_unified.php?action=edit&id=<?php echo htmlspecialchars($order['scan_order_id']); ?>" class="btn btn-primary btn-sm" title="Edit/Complete Scan">
                                                            <?php if ($status_text === 'Pending'): ?>
                                                                <i class="fas fa-clipboard-check"></i> Complete
                                                            <?php else: ?>
                                                                <i class="fas fa-edit"></i> Edit
                                                            <?php endif; ?>
                                                        </a>
                                                        <a href="scans_unified.php?action=delete&id=<?php echo htmlspecialchars($order['scan_order_id']); ?>" class="btn btn-danger btn-sm delete-btn" title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>

                                <?php elseif ($action == 'add' || $action == 'edit'): ?>
                                    <form action="scans_unified.php?action=<?php echo $action; ?>_submit<?php echo ($action == 'edit' ? '&id=' . htmlspecialchars($id) : ''); ?>" method="POST">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="patient_id">Patient:</label>
                                                    <select id="patient_id" name="patient_id" class="form-control" required>
                                                        <option value="">Select Patient</option>
                                                        <?php foreach ($patients as $patient): ?>
                                                            <option value="<?php echo htmlspecialchars($patient['id']); ?>" <?php echo ($action == 'edit' && $patient['id'] == $scan_order_details['patient_id']) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($patient['patient_name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="scan_type_id">Scan Type:</label>
                                                    <select id="scan_type_id" name="scan_type_id" class="form-control" required onchange="fetchUltrasoundCategories()">
                                                        <option value="">Select Scan Type</option>
                                                        <?php foreach ($scan_types as $scan_type): ?>
                                                            <option value="<?php echo htmlspecialchars($scan_type['id']); ?>" <?php echo ($action == 'edit' && $scan_type['id'] == $scan_order_details['scan_type_id']) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($scan_type['scan_name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="ultrasound_category_id">Scan Category:</label>
                                                    <select id="ultrasound_category_id" name="ultrasound_category_id" class="form-control" onchange="fetchUltrasoundResultsAndPrice()">
                                                        <option value="">Select Scan Category</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="ultrasound_result_id">Possible Scan Result:</label>
                                                    <select id="ultrasound_result_id" name="ultrasound_result_id" class="form-control">
                                                        <option value="">Select Possible Scan Result</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="total_price">Total Price (₦):</label>
                                                    <input type="number" id="total_price" name="total_price" step="0.01" class="form-control"
                                                    value="<?php echo ($action == 'edit' ? htmlspecialchars($scan_order_details['total_price']) : '0.00'); ?>" required placeholder="Enter Total Price">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="ordered_by_user_id">Ordered By (User):</label>
                                                    <select id="ordered_by_user_id" name="ordered_by_user_id" class="form-control" required>
                                                        <option value="">Ordered By (User)</option>
                                                        <?php foreach ($users as $user): ?>
                                                            <option value="<?php echo htmlspecialchars($user['id']); ?>" <?php echo ($action == 'edit' && $user['id'] == $scan_order_details['ordered_by_user_id']) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($user['display_name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label for="result_notes">Result Notes:</label>
                                            <textarea id="result_notes" name="result_notes" rows="4" class="form-control" placeholder="Enter Result Notes"><?php echo ($action == 'edit' ? htmlspecialchars($scan_order_details['result_notes'] ?? '') : ''); ?></textarea>
                                        </div>

                                        <div class="form-group text-right">
                                            <button type="submit" class="btn btn-success"><?php echo ($action == 'edit' ? 'Save Changes' : 'Add Scan Order'); ?></button>
                                            <a href="scans_unified.php?action=list" class="btn btn-danger">Cancel</a>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
    <?php include 'config/footer.php'; ?>
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
<script src="plugins/pdfmake/pdfmake/build/pdfmake.min.js"></script>
<script src="plugins/pdfmake/pdfmake/build/vfs_fonts.js"></script>
<script src="plugins/datatables-buttons/js/buttons.html5.min.js"></script>
<script src="plugins/datatables-buttons/js/buttons.print.min.js"></script>
<script src="plugins/datatables-buttons/js/buttons.colVis.min.js"></script>
<script src="plugins/moment/moment.min.js"></script>


<script>
    $(function () {
        // Initialize DataTables only for the list view
        <?php if ($action == 'list'): ?>
        var scanOrdersTable = $("#scanOrdersTable").DataTable({
            "responsive": true,
            "lengthChange": false,
            "autoWidth": false,
            "paging": true,
            "ordering": true,
            "info": true,
            "searching": true,
            // Define columns and their data source explicitly
            "columns": [
                { "data": null, "title": "S.No", "orderable": false, "searchable": false, "render": function (data, type, row, meta) {
                    return meta.row + 1; // S.No based on row index
                }}, // S.No column
                { "data": "patient_id", "title": "Patient ID" },
                { "data": "patient_name", "title": "Patient Name" },
                { "data": "scan_name", "title": "Scan Type" },
                { "data": "category_name", "title": "Category", "defaultContent": "N/A" },
                // Removed "Result Description" column definition
                { "data": "total_price", "title": "Price (₦)", "render": function(data, type, row) {
                    // Displays "₦0.00" if data is not a valid number
                    return '₦' + (typeof data === 'number' && !isNaN(data) ? parseFloat(data).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '0.00');
                }}, // Price
                { "data": "order_date", "title": "Order Date", "render": function(data, type, row) { return moment(data).format('YYYY-MM-DD hh:mm A');}}, // Order Date
                {
                    "data": null,
                    "title": "Status",
                    "render": function(data, type, row) {
                        // Determine status based on result_notes or ultrasound_result_id
                        let status_text = 'Pending';
                        let status_class = 'badge bg-warning';
                        if (row.result_notes !== null && row.result_notes !== '' || row.ultrasound_result_id !== null) {
                            status_text = 'Completed';
                            status_class = 'badge bg-success';
                        }
                        return '<span class="' + status_class + '">' + status_text + '</span>';
                    }
                },
                {
                    "data": null,
                    "title": "Actions",
                    "render": function(data, type, row) {
                        let actionsHtml = '';
                        // Determine status for the button text
                        let status_text = 'Pending';
                        if (row.result_notes !== null && row.result_notes !== '' || row.ultrasound_result_id !== null) {
                            status_text = 'Completed';
                        }

                        if (status_text === 'Pending') {
                            actionsHtml += '<a href="scans_unified.php?action=edit&id=' + row.scan_order_id + '" class="btn btn-primary btn-sm" title="Complete Scan"><i class="fas fa-clipboard-check"></i> Complete</a> ';
                        } else {
                            actionsHtml += '<a href="scans_unified.php?action=edit&id=' + row.scan_order_id + '" class="btn btn-info btn-sm" title="Edit Details"><i class="fas fa-edit"></i> Edit</a> ';
                        }
                        actionsHtml += '<a href="scans_unified.php?action=delete&id=' + row.scan_order_id + '" class="btn btn-danger btn-sm delete-btn" title="Delete"><i class="fas fa-trash"></i></a>';
                        return actionsHtml;
                    },
                    "orderable": false, "searchable": false
                } // Actions
            ],
            "buttons": [
                {
                    extend: 'print',
                    text: '<i class="fas fa-print"></i> Print', // Text and icon for the button
                    className: 'btn-primary', // Standard Bootstrap primary button style
                    customize: function ( win ) {
                        var clinicName = '<?php echo htmlspecialchars(strtoupper($clinic_name)); ?>';
                        var clinicAddress = '<?php echo htmlspecialchars($clinic_address); ?>';
                        var clinicEmail = '<?php echo htmlspecialchars($clinic_email); ?>';
                        var clinicPhone = '<?php echo htmlspecialchars($clinic_phone); ?>';

                        // Prepare logo HTML for side-by-side display
                        var logoHtml = `
                            <div style="display: flex; align-items: center; justify-content: center; margin-bottom: 10px;">
                                <img src="dist/img/logo.png" alt="Logo 1" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; margin-right: 20px;">
                                <div style="text-align: center; flex-grow: 1;">
                                    <h2 style="margin: 0; padding: 0; color: #333;">${clinicName}</h2>
                                    <p style="margin: 0; padding: 0; font-size: 0.9em; color: #555;">${clinicAddress}</p>
                                    <p style="margin: 0; padding: 0; font-size: 0.9em; color: #555;">
                                        <i class="fas fa-envelope"></i> ${clinicEmail} |
                                        <i class="fas fa-phone"></i> ${clinicPhone}
                                    </p>
                                </div>
                                <img src="dist/img/logo2.png" alt="Logo 2" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; margin-left: 20px;">
                            </div>
                        `;

                        // Construct the full header HTML with clinic details and logos
                        var fullHeaderHtml = `
                            <div style="text-align: center; margin-bottom: 20px; font-family: Arial, sans-serif;">
                                ${logoHtml}
                                <h3 style="margin-top: 20px; color: #000; border-bottom: 2px solid #333; padding-bottom: 10px;">Scan Orders Report</h3>
                            </div>
                        `;

                        // Get the table data from the DataTable instance
                        var tableData = scanOrdersTable.rows({ filter: 'applied' }).data().toArray();

                        // Generate the table HTML for printing
                        var printTableHtml = '<table class="table table-bordered table-striped compact" style="font-size:inherit; width:100%; border-collapse: collapse;">';
                        printTableHtml += '<thead><tr style="background-color: #f2f2f2; color: #333;">'; // Adjusted header style for print
                        // Manually add headers to match the desired print output (excluding Actions)
                        printTableHtml += '<th style="padding: 8px; text-align: left; border: 1px solid #ddd;">S.No</th>'; // Changed for print
                        printTableHtml += '<th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Patient ID</th>';
                        printTableHtml += '<th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Patient Name</th>';
                        printTableHtml += '<th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Scan Type</th>';
                        printTableHtml += '<th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Category</th>';
                        // Removed "Result Description" from print header
                        printTableHtml += '<th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Price (₦)</th>';
                        printTableHtml += '<th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Order Date</th>';
                        printTableHtml += '<th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Status</th>';
                        printTableHtml += '</tr></thead><tbody>';

                        if (tableData.length > 0) {
                            $.each(tableData, function(idx, row){
                                // Data from row should now be correctly typed (number for total_price, string for patient_id)
                                const formattedPrice = (typeof row.total_price === 'number' && !isNaN(row.total_price)) ?
                                    '₦' + parseFloat(row.total_price).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) :
                                    '₦0.00'; // Displays "₦0.00" in print view as well
                                const formattedPatientId = row.patient_id || 'N/A';
                                const formattedOrderDate = row.order_date ? moment(row.order_date).format('MM/DD/YYYY hh:mm A') : 'N/A';

                                // Determine status for print
                                let status_text = 'Pending';
                                if (row.result_notes !== null && row.result_notes !== '' || row.ultrasound_result_id !== null) {
                                    status_text = 'Completed';
                                }

                                printTableHtml += '<tr>';
                                printTableHtml += '<td style="padding: 8px; text-align: left; border: 1px solid #ddd;">' + (idx + 1) + '</td>'; // S.No for print
                                printTableHtml += '<td style="padding: 8px; text-align: left; border: 1px solid #ddd;">' + formattedPatientId + '</td>';
                                printTableHtml += '<td style="padding: 8px; text-align: left; border: 1px solid #ddd;">' + (row.patient_name || 'N/A') + '</td>';
                                printTableHtml += '<td style="padding: 8px; text-align: left; border: 1px solid #ddd;">' + (row.scan_name || 'N/A') + '</td>';
                                printTableHtml += '<td style="padding: 8px; text-align: left; border: 1px solid #ddd;">' + (row.category_name || 'N/A') + '</td>';
                                // Removed result_description from print table body
                                printTableHtml += '<td style="padding: 8px; text-align: left; border: 1px solid #ddd;">' + formattedPrice + '</td>';
                                printTableHtml += '<td style="padding: 8px; text-align: left; border: 1px solid #ddd;">' + formattedOrderDate + '</td>';
                                printTableHtml += '<td style="padding: 8px; text-align: left; border: 1px solid #ddd;">' + status_text + '</td>';
                                printTableHtml += '</tr>';
                            });
                        } else {
                            printTableHtml += '<tr><td colspan="8" style="padding: 8px; text-align: center; border: 1px solid #ddd;">No scan orders to display.</td></tr>'; // colspan adjusted
                        }
                        printTableHtml += '</tbody></table>';

                        $(win.document.body).html(fullHeaderHtml + printTableHtml);

                        // Ensure borders are visible in print by applying inline styles for table cells
                        $(win.document.body).find('table').css('border-collapse', 'collapse');
                        $(win.document.body).find('th, td').css('border', '1px solid #000');
                    }
                }
            ]
        });

        // Append buttons after DataTable initialization
        scanOrdersTable.buttons().container().appendTo('#scanOrdersTable_wrapper .col-md-6:eq(0)');

        // Add event listeners for dynamically created buttons (Edit/Delete)
        // This is necessary because the action column is now dynamic and not directly rendered by PHP.
        // It's important to use event delegation for these
        $('#scanOrdersTable tbody').on('click', '.btn-primary.btn-sm, .btn-info.btn-sm', function (e) {
            e.preventDefault(); // Prevent default link behavior
            var data = scanOrdersTable.row($(this).parents('tr')).data();
            window.location.href = 'scans_unified.php?action=edit&id=' + data.scan_order_id;
        });

        $('#scanOrdersTable tbody').on('click', '.delete-btn', function (e) {
            e.preventDefault(); // Prevent default link behavior
            var data = scanOrdersTable.row($(this).parents('tr')).data();
            if (confirm('Are you sure you want to delete this scan order?')) {
                window.location.href = 'scans_unified.php?action=delete&id=' + data.scan_order_id;
            }
        });

        <?php endif; ?>
        // Highlight active menu item in sidebar
        // showMenuSelected("#mnu_scans", "#mi_manage_scans"); // Uncomment and ensure this function exists
    });

    // Function to fetch ultrasound categories dynamically
    function fetchUltrasoundCategories(selectedCategoryId = null) {
        const scanTypeId = document.getElementById('scan_type_id').value;
        const categorySelect = document.getElementById('ultrasound_category_id');
        const resultSelect = document.getElementById('ultrasound_result_id');
        const totalPriceInput = document.getElementById('total_price');

        categorySelect.innerHTML = '<option value="">Select Ultrasound Category</option>';
        resultSelect.innerHTML = '<option value="">Select Ultrasound Result</option>';

        // Determine if in edit mode and if total_price is already set by DB value
        // This variable is used to prevent the price from being reset if it's already loaded from the database in edit mode.
        const isEditModeAndPriceAlreadySet = <?php echo json_encode($action == 'edit' && isset($scan_order_details['total_price']) && $scan_order_details['total_price'] !== ''); ?>;

        // If not in edit mode with a pre-set price, or if a new category is being selected, clear price
        // Ensure price defaults to 0.00 for new entries
        if (!isEditModeAndPriceAlreadySet || (isEditModeAndPriceAlreadySet && selectedCategoryId === null && scanTypeId !== '')) {
             totalPriceInput.value = '0.00'; // Set default to 0.00
        }


        if (scanTypeId) {
            fetch(`scans_unified.php?action=fetch_ultrasound_data&type=categories&scan_type_id=${scanTypeId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok ' + response.statusText);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        console.error('Error fetching categories:', data.error);
                        // Optionally display error to user
                        return;
                    }
                    data.forEach(category => {
                        const option = document.createElement('option');
                        option.value = category.id;
                        option.textContent = category.category_name;
                        option.setAttribute('data-price', category.price); // Store price
                        if (selectedCategoryId !== null && category.id == selectedCategoryId) {
                            option.selected = true;
                        }
                        categorySelect.appendChild(option);
                    });

                    // Update total price based on selected category, if applicable
                    const currentSelectedCategoryOption = categorySelect.options[categorySelect.selectedIndex];
                    if (currentSelectedCategoryOption && currentSelectedCategoryOption.dataset.price && !isEditModeAndPriceAlreadySet) {
                        totalPriceInput.value = parseFloat(currentSelectedCategoryOption.dataset.price).toFixed(2);
                    } else if (!isEditModeAndPriceAlreadySet && categorySelect.value === '') { // If no category selected, set price to 0.00 in add mode
                        totalPriceInput.value = '0.00'; // Set default to 0.00
                    }


                    if (selectedCategoryId !== null) {
                        // After categories are fetched, check if the initialResultId should be used for results
                        const initialResultId = <?php echo json_encode($scan_order_details['ultrasound_result_id'] ?? null); ?>;
                        fetchUltrasoundResultsAndPrice(initialResultId);
                    }
                })
                .catch(error => console.error('Network or parsing error fetching categories:', error));
        }
    }

    // Function to fetch ultrasound results and update price dynamically
    function fetchUltrasoundResultsAndPrice(selectedResultId = null) {
        const categorySelect = document.getElementById('ultrasound_category_id');
        const ultrasoundCategoryId = categorySelect.value;
        const resultSelect = document.getElementById('ultrasound_result_id');
        const totalPriceInput = document.getElementById('total_price');

        resultSelect.innerHTML = '<option value="">Select Ultrasound Result</option>';

        const selectedCategoryOption = categorySelect.options[categorySelect.selectedIndex];
        // Only update total_price based on category selection if it's not already set in edit mode
        const isEditModeAndPriceAlreadySet = <?php echo json_encode($action == 'edit' && isset($scan_order_details['total_price']) && $scan_order_details['total_price'] !== ''); ?>;

        if (selectedCategoryOption && selectedCategoryOption.dataset.price && !isEditModeAndPriceAlreadySet) {
            totalPriceInput.value = parseFloat(selectedCategoryOption.dataset.price).toFixed(2);
        } else if (!isEditModeAndPriceAlreadySet && categorySelect.value === '') { // If no category selected, set price to 0.00 in add mode
            totalPriceInput.value = '0.00'; // Set default to 0.00
        }

        if (ultrasoundCategoryId) {
            fetch(`scans_unified.php?action=fetch_ultrasound_data&type=results&ultrasound_category_id=${ultrasoundCategoryId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok ' + response.statusText);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        console.error('Error fetching results:', data.error);
                        // Optionally display error to user
                        return;
                    }
                    data.forEach(result => {
                        const option = document.createElement('option');
                        option.value = result.id;
                        option.textContent = result.description;
                        if (selectedResultId !== null && result.id == selectedResultId) {
                            option.selected = true;
                        }
                        resultSelect.appendChild(option);
                    });
                })
                .catch(error => console.error('Network or parsing error fetching results:', error));
        }
    }

    // Initial load for edit mode (when action is 'edit')
    document.addEventListener('DOMContentLoaded', function() {
        const currentAction = "<?php echo $action; ?>";
        if (currentAction === 'edit') {
            const initialCategoryId = <?php echo json_encode($scan_order_details['ultrasound_category_id'] ?? null); ?>;
            fetchUltrasoundCategories(initialCategoryId);
            // fetchUltrasoundResultsAndPrice will be called inside fetchUltrasoundCategories after categories are loaded
        }
    });
</script>
</body>
</html>