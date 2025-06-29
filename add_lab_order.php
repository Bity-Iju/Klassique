<?php
session_start();

// --- BEGIN: DEVELOPMENT DEBUGGING SETTINGS ---
// IMPORTANT: REMOVE OR COMMENT OUT THESE LINES IN PRODUCTION!
ini_set('display_errors', 1);
error_reporting(E_ALL);
// --- END: DEVELOPMENT DEBUGGING SETTINGS ---

if (!isset($_SESSION['user_id'])) {
    header("location:index.php");
    exit;
}

// Include your database connection file
// Make sure this path is correct and connection.php establishes $con PDO object
include './config/connection.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate inputs
    $patient_id = isset($_POST['patient_id']) ? intval($_POST['patient_id']) : 0;
    $lab_tests = isset($_POST['lab_tests']) ? $_POST['lab_tests'] : []; // Array of selected lab tests
    $order_date = date('Y-m-d H:i:s'); // Current timestamp
    $ordered_by = $_SESSION['user_id']; // User who ordered the test

    // Server-side validation
    if ($patient_id <= 0) {
        $message = "<div class='alert alert-danger'>Please select a patient.</div>";
    } elseif (empty($lab_tests)) {
        $message = "<div class='alert alert-danger'>Please select at least one lab test.</div>";
    } else {
        try {
            $con->beginTransaction();

            // Insert into lab_orders table
            $stmt = $con->prepare("INSERT INTO lab_orders (patient_id, order_date, ordered_by_user_id) VALUES (?, ?, ?)");
            $stmt->bindParam(1, $patient_id, PDO::PARAM_INT);
            $stmt->bindParam(2, $order_date, PDO::PARAM_STR);
            $stmt->bindParam(3, $ordered_by, PDO::PARAM_INT);
            $stmt->execute();

            // Get the ID of the newly created lab order
            $order_id = $con->lastInsertId();

            // Check if lastInsertId returned a valid ID
            if (!$order_id) {
                throw new Exception("Failed to retrieve last insert ID for lab order. Check lab_orders table auto-increment setting.");
            }

            // Now insert the individual lab tests for this order into lab_order_tests
            $stmt_tests = $con->prepare("INSERT INTO lab_order_tests (lab_order_id, test_id) VALUES (?, ?)");
            foreach ($lab_tests as $test_id) {
                $clean_test_id = intval($test_id);
                if ($clean_test_id > 0) {
                    $stmt_tests->bindParam(1, $order_id, PDO::PARAM_INT);
                    $stmt_tests->bindParam(2, $clean_test_id, PDO::PARAM_INT);
                    $stmt_tests->execute();
                } else {
                    // Log invalid test IDs received from the form
                    error_log("Invalid test_id detected in POST (value was 0 or less): " . $test_id . " for patient_id: " . $patient_id . ". This test will be skipped.");
                }
            }

            $con->commit();
            header("Location: add_lab_order.php?message=" . urlencode("Lab order added successfully!"));
            exit;
        } catch (PDOException $ex) {
            $con->rollback();
            $message = "<div class='alert alert-danger'>Error adding lab order: " . htmlspecialchars($ex->getMessage()) . "</div>";
            error_log("PDO Exception (add_lab_order POST): " . $ex->getMessage() . " - Trace: " . $ex->getTraceAsString());
        } catch (Exception $e) {
            $con->rollback();
            $message = "<div class='alert alert-danger'>An unexpected error occurred: " . htmlspecialchars($e->getMessage()) . "</div>";
            error_log("General Exception (add_lab_order POST): " . $e->getMessage() . " - Trace: " . $e->getTraceAsString());
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


// Fetch patients for the dropdown, including patient_display_id
$patients = [];
try {
    $stmt_patients = $con->prepare("SELECT id, patient_name, patient_display_id FROM patients ORDER BY patient_name ASC");
    $stmt_patients->execute();
    $patients = $stmt_patients->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    error_log("Error fetching patients: " . $ex->getMessage());
    if (empty($message) || strpos($message, 'alert-danger') === false) {
        $message = "<div class='alert alert-danger'>Error fetching patients: " . htmlspecialchars($ex->getMessage()) . "</div>";
    }
}

// Fetch lab tests for the checkboxes, now including price
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

// Fetch existing lab orders for the table
$all_lab_orders = [];
try {
    $query_lab_orders = "
        SELECT
            lo.lab_order_id AS order_id,
            p.patient_display_id,  -- Select patient_display_id here
            p.patient_name,
            lo.order_date,
            u.display_name AS ordered_by_username,
            GROUP_CONCAT(lt.test_name SEPARATOR ', ') AS test_names,
            SUM(lt.price) AS total_order_price
        FROM
            lab_orders lo
        JOIN
            patients p ON lo.patient_id = p.id
        JOIN
            users u ON lo.ordered_by_user_id = u.id
        JOIN
            lab_order_tests lot ON lo.lab_order_id = lot.lab_order_id
        JOIN
            lab_tests lt ON lot.test_id = lt.test_id
        GROUP BY
            lo.lab_order_id, p.patient_display_id, p.patient_name, lo.order_date, u.display_name
        ORDER BY
            lo.order_date DESC;
    ";
    $stmt_all_lab_orders = $con->prepare($query_lab_orders);
    $stmt_all_lab_orders->execute();
    $all_lab_orders = $stmt_all_lab_orders->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $ex) {
    error_log("Error fetching lab orders for display: " . $ex->getMessage());
    if (empty($message) || strpos($message, 'alert-danger') === false) {
        $message = "<div class='alert alert-danger'>Error fetching lab orders for display: " . htmlspecialchars($ex->getMessage()) . "</div>";
    }
}

// Check for messages redirected from a previous submission
if(isset($_GET['message'])) {
  $message = "<div class='alert alert-success'>" . htmlspecialchars($_GET['message']) . "</div>";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add Lab Order - KDCS</title>
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <?php include './config/site_css_links.php';?>
    <?php include './config/data_tables_css.php';?>

    <style>
        /* Custom CSS to increase checkbox and label size */
        .table .form-check-input {
            width: 1.25em; /* Increase checkbox size */
            height: 1.25em; /* Increase checkbox size */
            /* Adjust vertical alignment to move it up */
            margin-top: -0.7em; /* Moves it up by approximately half its height */
            vertical-align: middle; /* Ensures consistent vertical alignment with text */
        }

        .table .form-check-label {
            font-size: 1.1em; /* Increase label font size */
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
                        <h1>Add New Lab Order</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Add Lab Order</li>
                        </ol>
                    </div>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-md-8 offset-md-2">
                        <div class="card card-success">
                            <div class="card-header">
                                <h3 class="card-title">Lab Order Details</h3>
                            </div>
                            <form action="add_lab_order.php" method="POST">
                                <div class="card-body">
                                    <?php echo $message; ?>
                                    <div class="form-group">
                                        <label for="patient_id">Select Patient:</label>
                                        <select class="form-control" id="patient_id" name="patient_id" required>
                                            <option value="">-- Select Patient --</option>
                                            <?php foreach ($patients as $patient): ?>
                                                <option value="<?php echo $patient['id']; ?>">
                                                    <?php echo htmlspecialchars($patient['patient_name']) . ' (Clinic ID: ' . htmlspecialchars($patient['patient_display_id'] ?: 'N/A') . ')'; ?>
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
                                                                        <input class="form-check-input lab-test-checkbox" type="checkbox" name="lab_tests[]" value="<?php echo $test['id']; ?>" id="test_<?php echo $test['id']; ?>" data-price="<?php echo htmlspecialchars($test['price']); ?>">
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
                                <div class="card-footer">
                                    <button type="submit" class="btn btn-success">Submit Lab Order</button>
                                    <button type="reset" class="btn btn-danger">Clear Form</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="content mt-4">
            <div class="container-fluid">
                <div class="card card-outline card-success rounded-0 shadow">
                    <div class="card-header">
                        <h3 class="card-title">All Lab Orders</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row table-responsive">
                            <table id="all_lab_orders_table" class="table table-striped dataTable table-bordered dtr-inline" role="grid" aria-describedby="all_lab_orders_info">
                                <colgroup>
                                    <col width="5%">   <col width="15%">   <col width="15%">  <col width="25%">  <col width="15%">  <col width="10%">  <col width="10%">  <col width="15%">  </colgroup>
                                <thead>
                                    <tr>
                                        <th class="text-center">S.No</th>
                                        <th>Clinic ID</th> <th>Patient Name</th>
                                        <th>Lab Tests</th>
                                        <th>Order Date</th>
                                        <th>Ordered By</th>
                                        <th>Total Price</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $serial = 0;
                                    if (!empty($all_lab_orders)) {
                                        foreach ($all_lab_orders as $row) {
                                            $serial++;
                                    ?>
                                            <tr>
                                                <td class="text-center"><?php echo $serial; ?></td>
                                                <td><?php echo htmlspecialchars($row['patient_display_id'] ?: 'N/A'); ?></td> <td><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                                <td><?php echo htmlspecialchars($row['test_names']); ?></td>
                                                <td><?php echo date('Y-m-d h:i A', strtotime($row['order_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($row['ordered_by_username']); ?></td>
                                                <td>₦<?php echo number_format($row['total_order_price'], 2); ?></td>
                                                <td class="text-center">
                                                    <a href="view_lab_order.php?id=<?php echo $row['order_id']; ?>" class="btn btn-info btn-sm btn-flat" title="View Details">
                                                        <i class="fa fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                    <?php
                                        }
                                    } else {
                                    ?>
                                        <tr>
                                            <td colspan="8" class="text-center">No lab orders found.</td> </tr>
                                    <?php
                                    }
                                    ?>
                                </tbody>
                            </table>
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
<?php include './config/data_tables_js.php';?>

<script>
    showMenuSelected("#mnu_lab_orders", "#mi_add_lab_order");

    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('message')) {
        var message = decodeURIComponent(urlParams.get('message'));
        if (typeof showCustomMessage === 'function') {
            showCustomMessage(message);
        } else {
            alert(message);
        }
    }

    $(function () {
        $("#all_lab_orders_table").DataTable({
            "responsive": true,
            "lengthChange": false,
            "autoWidth": false,
            "buttons": [
                "copy", "csv", "excel", "pdf",
                {
                    extend: 'print',
                    customize: function ( win ) {
                        var clinicName = '<?php echo htmlspecialchars(strtoupper($clinic_name)); ?>';
                        var clinicAddress = '<?php echo htmlspecialchars($clinic_address); ?>';
                        var clinicEmail = '<?php echo htmlspecialchars($clinic_email); ?>';
                        var clinicPhone = '<?php echo htmlspecialchars($clinic_phone); ?>';

                        // Get the base URL dynamically for print assets
                        var baseUrl = window.location.origin;
                        // IMPORTANT: Adjust this if your application is in a subfolder (e.g., http://localhost/my_pms_app/)
                        // Example: if (window.location.pathname.includes('/my_pms_app/')) { baseUrl += '/my_pms_app'; }
                        // console.log("Base URL for print assets: ", baseUrl); // For debugging print output

                        // Prepare logo HTML with ABSOLUTE paths
                        var logoHtml =
                            '<img src="' + baseUrl + '/dist/img/logo.png" style="float: left; width: 120px; height: 120px; margin-right: 15px; border-radius: 50%; object-fit: cover;">' +
                            '<img src="' + baseUrl + '/dist/img/logo2.png" style="float: right; width: 120px; height: 120px; margin-left: 15px; border-radius: 50%; object-fit: cover;">';


                        // Construct the full header HTML
                        var fullHeaderHtml =
                            '<div style="text-align: center; margin-bottom: 20px; font-family: Arial, sans-serif; overflow: hidden; position: relative;">' +
                                logoHtml +
                                '<div style="display: inline-block; vertical-align: middle; max-width: calc(100% - 280px);">' +
                                    '<h2 style="margin: 0; padding: 0; color: #333;">' + clinicName + '</h2>' +
                                    '<p style="margin: 0; padding: 0; font-size: 0.9em; color: #555;">' + clinicAddress + '</p>' +
                                    '<p style="margin: 0; padding: 0; font-size: 0.9em; color: #555;">Email: ' + clinicEmail + ' | Phone: ' + clinicPhone + '</p>' +
                                '</div>' +
                                '<h3 style="margin-top: 20px; color: #000; clear: both;">Lab Orders Report</h3>' +
                            '</div>';

                        $(win.document.body).find( 'h1' ).remove();
                        $(win.document.body).prepend(fullHeaderHtml);

                        // Include FontAwesome CSS in the print window's head for icons
                        $(win.document.head).append(
                            '<link rel="stylesheet" href="' + baseUrl + '/plugins/fontawesome-free/css/all.min.css">'
                        );

                        // Adjust table styling for print
                        $(win.document.body).find( 'table' )
                            .addClass( 'compact' )
                            .css( 'font-size', 'inherit' );

                        // Remove S.No. column and Action column from print output
                        $(win.document.body).find('table thead th:eq(0)').remove(); // S.No. header
                        $(win.document.body).find('table tbody tr').each(function(index){
                            $(this).find('td:eq(0)').remove(); // S.No. data cell
                            $(this).find('td:last').remove(); // Action column data cell
                        });
                        $(win.document.body).find('table thead th:last').remove(); // Action header

                        // Ensure table header text is black in print
                        $(win.document.body).find('table thead th').css('color', '#333');
                    }
                },
                "colvis"
            ]
        }).buttons().container().appendTo('#all_lab_orders_table_wrapper .col-md-6:eq(0)');

        function calculateTotalPrice() {
            let totalPrice = 0;
            $('.lab-test-checkbox:checked').each(function() {
                let dataPrice = $(this).data('price');
                console.log("Processing checkbox with data-price:", dataPrice);
                let price = parseFloat(dataPrice);
                if (!isNaN(price)) {
                    totalPrice += price;
                    console.log("Parsed price:", price, "Current total:", totalPrice);
                } else {
                    console.error("Invalid price detected for lab test:", dataPrice, "Price cannot be parsed as a number.");
                }
            });
            $('#total_price_display').text('₦' + totalPrice.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
            console.log("Final total price:", totalPrice);
        }

        $(document).on('change', '.lab-test-checkbox', calculateTotalPrice);
        calculateTotalPrice(); // Initial calculation on page load
    });
</script>
</body>
</html>