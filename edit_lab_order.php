<?php
session_start();

// --- BEGIN: DEVELOPMENT DEBUGGING SETTINGS ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
// --- END: DEVELOPMENT DEBUGGING SETTINGS ---

if (!isset($_SESSION['user_id'])) {
    header("location:index.php");
    exit;
}

include './config/connection.php';

$message = '';
$order_id_to_edit = 0;
$order_details = [];
$ordered_tests_ids = []; // Array to hold IDs of tests already in this order
$patient_age = 'N/A'; // Initialize patient age
$success_redirect_message = ''; // New variable to hold success message for JS redirect

// Fetch order details if an ID is provided via GET (for initial load)
if (isset($_GET['id'])) {
    $order_id_to_edit = intval($_GET['id']);

    if ($order_id_to_edit <= 0) {
        $message = "<div class='alert alert-danger'>Invalid Lab Order ID provided for editing.</div>";
    } else {
        try {
            // Fetch lab order details and associated patient information
            $query_order = "
                SELECT
                    lo.lab_order_id,
                    lo.patient_id,
                    p.patient_name,
                    p.address,
                    p.contact_no,
                    p.dob,
                    p.gender,
                    p.patient_display_id,
                    p.marital_status,
                    lo.order_date,
                    lo.ordered_by_user_id,
                    u.display_name AS ordered_by_username
                FROM
                    lab_orders lo
                JOIN
                    patients p ON lo.patient_id = p.id
                JOIN
                    users u ON lo.ordered_by_user_id = u.id
                WHERE
                    lo.lab_order_id = ?;
            ";
            $stmt_order = $con->prepare($query_order);
            $stmt_order->bindParam(1, $order_id_to_edit, PDO::PARAM_INT);
            $stmt_order->execute();
            $order_details = $stmt_order->fetch(PDO::FETCH_ASSOC);

            if (!$order_details) {
                $message = "<div class='alert alert-warning'>Lab Order with ID " . htmlspecialchars($order_id_to_edit) . " not found.</div>";
                $order_id_to_edit = 0; // Reset to invalid to prevent further processing
            } else {
                // Calculate age from DOB if DOB is available
                if (!empty($order_details['dob'])) {
                    $dob_datetime = new DateTime($order_details['dob']);
                    $current_date = new DateTime();
                    $age_interval = $current_date->diff($dob_datetime);
                    $patient_age = $age_interval->y;
                }

                // Fetch tests currently associated with this lab order
                $query_current_tests = "
                    SELECT
                        test_id
                    FROM
                        lab_order_tests
                    WHERE
                        lab_order_id = ?;
                ";
                $stmt_current_tests = $con->prepare($query_current_tests);
                $stmt_current_tests->bindParam(1, $order_id_to_edit, PDO::PARAM_INT);
                $stmt_current_tests->execute();
                $ordered_tests_ids = $stmt_current_tests->fetchAll(PDO::FETCH_COLUMN, 0);
            }

        } catch (PDOException $ex) {
            $message = "<div class='alert alert-danger'>Database error fetching order details: " . htmlspecialchars($ex->getMessage()) . "</div>";
            error_log("PDO Exception (edit_lab_order fetch): " . $ex->getMessage());
        } catch (Exception $e) {
            $message = "<div class='alert alert-danger'>An unexpected error occurred: " . htmlspecialchars($e->getMessage()) . "</div>";
            error_log("General Exception (edit_lab_order fetch): " . $e->getMessage());
        }
    }
}


// Handle form submission (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order'])) {
    $order_id_to_edit = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $patient_id = isset($_POST['patient_id']) ? intval($_POST['patient_id']) : 0;
    $lab_tests = isset($_POST['lab_tests']) ? $_POST['lab_tests'] : []; // Array of selected lab tests
    $order_date_updated = date('Y-m-d H:i:s'); // Update date to now or allow user to pick if needed
    $ordered_by_user_id_updated = $_SESSION['user_id']; // User who is updating

    if ($order_id_to_edit <= 0) {
        $message = "<div class='alert alert-danger'>Invalid Lab Order ID for update.</div>";
    } elseif ($patient_id <= 0) {
        $message = "<div class='alert alert-danger'>Please select a patient.</div>";
    } elseif (empty($lab_tests)) {
        $message = "<div class='alert alert-danger'>Please select at least one lab test.</div>";
    } else {
        try {
            $con->beginTransaction();

            // 1. Update the lab_orders table (if patient_id, order_date, or ordered_by can be changed)
            $stmt_update_order = $con->prepare("UPDATE lab_orders SET patient_id = ?, order_date = ?, ordered_by_user_id = ? WHERE lab_order_id = ?");
            $stmt_update_order->bindParam(1, $patient_id, PDO::PARAM_INT);
            $stmt_update_order->bindParam(2, $order_date_updated, PDO::PARAM_STR);
            $stmt_update_order->bindParam(3, $ordered_by_user_id_updated, PDO::PARAM_INT);
            $stmt_update_order->bindParam(4, $order_id_to_edit, PDO::PARAM_INT);
            $stmt_update_order->execute();

            // 2. Clear existing tests for this order from lab_order_tests
            $stmt_delete_tests = $con->prepare("DELETE FROM lab_order_tests WHERE lab_order_id = ?");
            $stmt_delete_tests->bindParam(1, $order_id_to_edit, PDO::PARAM_INT);
            $stmt_delete_tests->execute();

            // 3. Insert the new/updated selection of lab tests
            $stmt_insert_tests = $con->prepare("INSERT INTO lab_order_tests (lab_order_id, test_id) VALUES (?, ?)");
            foreach ($lab_tests as $test_id) {
                $clean_test_id = intval($test_id);
                if ($clean_test_id > 0) {
                    $stmt_insert_tests->bindParam(1, $order_id_to_edit, PDO::PARAM_INT);
                    $stmt_insert_tests->bindParam(2, $clean_test_id, PDO::PARAM_INT);
                    $stmt_insert_tests->execute();
                } else {
                    error_log("Invalid test_id detected in POST during update (value was 0 or less): " . $test_id . " for order_id: " . $order_id_to_edit . ". This test will be skipped.");
                }
            }

            $con->commit();
            // Set the success message to be picked up by JavaScript
            $success_redirect_message = "Lab order updated successfully!";
            // DO NOT use header() here, let JS handle redirection after confirmation.

        } catch (PDOException $ex) {
            $con->rollback();
            $message = "<div class='alert alert-danger'>Error updating lab order: " . htmlspecialchars($ex->getMessage()) . "</div>";
            error_log("PDO Exception (edit_lab_order POST): " . $ex->getMessage() . " - Trace: " . $ex->getTraceAsString());
        } catch (Exception $e) {
            $con->rollback();
            $message = "<div class='alert alert-danger'>An unexpected error occurred: " . htmlspecialchars($e->getMessage()) . "</div>";
            error_log("General Exception (edit_lab_order POST): " . $e->getMessage() . " - Trace: " . $e->getTraceAsString());
        }
    }

    // Re-fetch data after a failed POST to ensure sticky form and patient info are correct
    // This block is crucial if an error occurs so the form reloads with the correct state.
    if (!empty($message) && $order_id_to_edit > 0) {
        try {
            $query_order = "
                SELECT
                    lo.lab_order_id,
                    lo.patient_id,
                    p.patient_name,
                    p.address,
                    p.contact_no,
                    p.dob,
                    p.gender,
                    p.patient_display_id,
                    p.marital_status,
                    lo.order_date,
                    lo.ordered_by_user_id,
                    u.display_name AS ordered_by_username
                FROM
                    lab_orders lo
                JOIN
                    patients p ON lo.patient_id = p.id
                JOIN
                    users u ON lo.ordered_by_user_id = u.id
                WHERE
                    lo.lab_order_id = ?;
            ";
            $stmt_order = $con->prepare($query_order);
            $stmt_order->bindParam(1, $order_id_to_edit, PDO::PARAM_INT);
            $stmt_order->execute();
            $order_details = $stmt_order->fetch(PDO::FETCH_ASSOC);

            if ($order_details) {
                if (!empty($order_details['dob'])) {
                    $dob_datetime = new DateTime($order_details['dob']);
                    $current_date = new DateTime();
                    $age_interval = $current_date->diff($dob_datetime);
                    $patient_age = $age_interval->y;
                }
            }

            // Also re-fetch current selected tests for checkboxes
            $query_current_tests = "
                SELECT test_id FROM lab_order_tests WHERE lab_order_id = ?;
            ";
            $stmt_current_tests = $con->prepare($query_current_tests);
            $stmt_current_tests->bindParam(1, $order_id_to_edit, PDO::PARAM_INT);
            $stmt_current_tests->execute();
            $ordered_tests_ids = $stmt_current_tests->fetchAll(PDO::FETCH_COLUMN, 0);

        } catch (PDOException $e) {
            error_log("Error re-fetching order details after failed POST: " . $e->getMessage());
        }
    }
}


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


// Fetch all patients for the dropdown (sticky selection will be applied via JS)
$patients = [];
try {
    $stmt_patients = $con->prepare("SELECT id, patient_name, patient_display_id FROM patients ORDER BY patient_name ASC");
    $stmt_patients->execute();
    $patients = $stmt_patients->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    error_log("Error fetching patients for dropdown: " . $ex->getMessage());
    if (empty($message) || strpos($message, 'alert-danger') === false) {
        $message = "<div class='alert alert-danger'>Error fetching patients: " . htmlspecialchars($ex->getMessage()) . "</div>";
    }
}

// Fetch all available lab tests for the checkboxes
$available_lab_tests = [];
try {
    $stmt_tests = $con->prepare("SELECT test_id AS id, test_name, price FROM lab_tests ORDER BY test_name ASC");
    $stmt_tests->execute();
    $available_lab_tests = $stmt_tests->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    error_log("Error fetching lab tests: " . $ex->getMessage());
    if (empty($message) || strpos($message, 'alert-danger') === false) {
        $message = "<div class='alert alert-danger'>Error fetching lab tests: " . htmlspecialchars($ex->getMessage()) . "</div>";
    }
}

// Check for messages redirected from a previous submission
// This part might now be redundant if JS handles all redirects, but kept for initial page load messages
if(isset($_GET['message'])) {
  $message = "<div class='alert alert-success'>" . htmlspecialchars($_GET['message']) . "</div>";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Lab Order - KDCS</title>
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <?php include './config/site_css_links.php';?>
    <?php include './config/data_tables_css.php';?>

    <style>
        /* Custom CSS for layout and checkbox/label size */
        .info-label {
            font-weight: bold;
            color: #333;
            min-width: 120px;
            display: inline-block;
        }
        .info-value {
            color: #555;
        }
        .table .form-check-input {
            width: 1.25em;
            height: 1.25em;
            margin-top: -0.7em;
            vertical-align: middle;
        }
        .table .form-check-label {
            font-size: 1.1em;
        }
        .card-footer-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        /* Print specific styles (if a direct print button is added later, similar to view_lab_order) */
        @media print {
            body { margin: 0; padding: 0; }
            .wrapper, .content-wrapper, .main-footer, .main-header, .main-sidebar, .content-header, .card-footer {
                display: none;
            }
            .content {
                display: block !important;
                width: 100%;
                margin: 0;
                padding: 20px;
            }
            .card {
                box-shadow: none !important;
                border: 1px solid #ddd;
                margin-bottom: 20px;
            }
            .card-header {
                background-color: #f8f9fa;
                border-bottom: 1px solid #dee2e6;
                color: #333;
            }
            table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
            table, th, td { border: 1px solid #ddd; }
            th, td { padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; text-align: center; color: #333; }
            .text-right { text-align: right; }
            .text-center { text-align: center; }
            .h4 { margin-top: 20px; margin-bottom: 10px; }
            img { max-width: 120px; max-height: 120px; border-radius: 50%; object-fit: cover; }
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
                        <h1>Edit Lab Order</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="add_lab_order.php">Lab Orders</a></li>
                            <li class="breadcrumb-item active">Edit Lab Order</li>
                        </ol>
                    </div>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-md-8 offset-md-2">
                        <div class="card card-warning">
                            <div class="card-header">
                                <h3 class="card-title">Edit Lab Order Details (ID: KDCS <?php echo htmlspecialchars($order_id_to_edit); ?>)</h3>
                            </div>
                            <?php if ($order_id_to_edit > 0 && !empty($order_details)): // Only show form if a valid order is loaded ?>
                            <form action="edit_lab_order.php" method="POST">
                                <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($order_id_to_edit); ?>">
                                <div class="card-body">
                                    <?php echo $message; ?>

                                    <div class="card card-outline card-primary shadow-sm mb-3">
                                        <div class="card-header">
                                            <h5 class="card-title">Patient Information</h5>
                                        </div>
                                        <div class="card-body row">
                                            <div class="col-md-6">
                                                <p><span class="info-label">Patient ID:</span> <span class="info-value"><?php echo htmlspecialchars($order_details['patient_id']); ?></span></p>
                                                <p><span class="info-label">Clinic ID:</span> <span class="info-value"><?php echo htmlspecialchars($order_details['patient_display_id'] ?: 'N/A'); ?></span></p>
                                                <p><span class="info-label">Patient Name:</span> <span class="info-value"><?php echo htmlspecialchars($order_details['patient_name']); ?></span></p>
                                                <p><span class="info-label">Contact:</span> <span class="info-value"><?php echo htmlspecialchars($order_details['contact_no']); ?></span></p>
                                            </div>
                                            <div class="col-md-6">
                                                <p><span class="info-label">Address:</span> <span class="info-value"><?php echo htmlspecialchars($order_details['address']); ?></span></p>
                                                <p><span class="info-label">Age:</span> <span class="info-value"><?php echo htmlspecialchars($patient_age); ?></span></p>
                                                <p><span class="info-label">Gender:</span> <span class="info-value"><?php echo htmlspecialchars($order_details['gender']); ?></span></p>
                                                <p><span class="info-label">Marital Status:</span> <span class="info-value"><?php echo htmlspecialchars($order_details['marital_status'] ?: 'N/A'); ?></span></p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="patient_id">Change Patient (if applicable):</label>
                                        <select class="form-control" id="patient_id" name="patient_id" required>
                                            <?php foreach ($patients as $patient): ?>
                                                <option value="<?php echo $patient['id']; ?>"
                                                    <?php echo ($patient['id'] == $order_details['patient_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($patient['patient_name']) . ' (' . htmlspecialchars($patient['patient_display_id'] ?: 'N/A') . ')'; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label>Select Lab Tests:</label>
                                        <?php if (!empty($available_lab_tests)): ?>
                                            <div class="table-responsive">
                                                <table class="table table-bordered table-striped table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>Lab Test Name</th>
                                                            <th class="text-right">Price</th>
                                                            <th class="text-center">Select</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($available_lab_tests as $test): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($test['test_name']); ?></td>
                                                                <td class="text-right">₦<?php echo number_format($test['price'], 2); ?></td>
                                                                <td class="text-center">
                                                                    <div class="form-check d-inline-block">
                                                                        <input class="form-check-input lab-test-checkbox" type="checkbox" name="lab_tests[]" value="<?php echo $test['id']; ?>" id="test_<?php echo $test['id']; ?>" data-price="<?php echo htmlspecialchars($test['price']); ?>"
                                                                            <?php echo in_array($test['id'], $ordered_tests_ids) ? 'checked' : ''; ?>>
                                                                        <label class="form-check-label sr-only" for="test_<?php echo $test['id']; ?>">
                                                                            <?php echo htmlspecialchars($test['test_name']); ?>
                                                                        </label>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-danger">No lab tests available. Please add lab tests first.</p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="form-group">
                                        <label>Total Price:</label>
                                        <div id="total_price_display" class="form-control-plaintext font-weight-bold">₦0.00</div>
                                    </div>
                                </div>
                                <div class="card-footer card-footer-buttons">
                                    <button type="submit" name="update_order" class="btn btn-success">Update Lab Order</button>
                                    <button type="button" class="btn btn-danger" onclick="window.location.href='laboratory.php';">Cancel</button>
                                </div>
                            </form>
                            <?php else: ?>
                                <div class="card-body">
                                    <p><?php echo $message; ?></p>
                                    <a href="laboratory.php" class="btn btn-primary">Go to Lab Orders List</a>
                                </div>
                            <?php endif; ?>
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
<?php include './config/site_js_links.php';?>
<script src="plugins/moment/moment.min.js"></script>

<script>
    showMenuSelected("#mnu_lab_orders", "#mi_add_lab_order"); // Assuming edit shares menu item with add

    var successfulUpdate = <?php echo json_encode($success_redirect_message); ?>;

    $(function () {
        // Display custom message and redirect on successful update
        if (successfulUpdate) {
            if (typeof showCustomMessage === 'function') {
                // Assuming showCustomMessage can take a callback
                showCustomMessage(successfulUpdate, function() {
                    window.location.href = 'laboratory.php';
                });
            } else {
                alert(successfulUpdate);
                window.location.href = 'laboratory.php';
            }
        }

        // Handle initial messages from GET (e.g., from previous page)
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('message') && !successfulUpdate) { // Only display if not already handled by successfulUpdate
            var message = decodeURIComponent(urlParams.get('message'));
            if (typeof showCustomMessage === 'function') {
                showCustomMessage(message);
            } else {
                alert(message);
            }
        }

        function calculateTotalPrice() {
            let totalPrice = 0;
            $('.lab-test-checkbox:checked').each(function() {
                let dataPrice = $(this).data('price');
                let price = parseFloat(dataPrice);
                if (!isNaN(price)) {
                    totalPrice += price;
                } else {
                    console.error("Invalid price detected for lab test:", dataPrice, "Price cannot be parsed as a number.");
                }
            });
            $('#total_price_display').text('₦' + totalPrice.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        }

        $(document).on('change', '.lab-test-checkbox', calculateTotalPrice);
        calculateTotalPrice(); // Initial calculation on page load
    });
</script>
</body>
</html>