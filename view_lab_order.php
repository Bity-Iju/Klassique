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
include './config/connection.php';

$message = '';
$order_details = null;
$ordered_tests = [];
$patient_age = 'N/A'; // Initialize patient age

if (isset($_GET['id'])) {
    $order_id = intval($_GET['id']);

    if ($order_id <= 0) {
        $message = "<div class='alert alert-danger'>Invalid Lab Order ID provided.</div>";
    } else {
        try {
            // Query to fetch lab order details and related patient information.
            // Uses column names as per pms_db.sql: 'address', 'contact_no', 'gender', 'dob', etc.
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
            $stmt_order->bindParam(1, $order_id, PDO::PARAM_INT);
            $stmt_order->execute();
            $order_details = $stmt_order->fetch(PDO::FETCH_ASSOC);

            if (!$order_details) {
                $message = "<div class='alert alert-warning'>Lab Order with ID " . htmlspecialchars($order_id) . " not found.</div>";
            } else {
                // Calculate age from DOB if DOB is available
                if (!empty($order_details['dob'])) {
                    $dob_datetime = new DateTime($order_details['dob']);
                    $current_date = new DateTime();
                    $age_interval = $current_date->diff($dob_datetime);
                    $patient_age = $age_interval->y; // Get years
                }

                // Fetch associated tests with prices
                $query_tests = "
                    SELECT
                        lt.test_name,
                        lt.price
                    FROM
                        lab_order_tests lot
                    JOIN
                        lab_tests lt ON lot.test_id = lt.test_id
                    WHERE
                        lot.lab_order_id = ?;
                ";
                $stmt_tests = $con->prepare($query_tests);
                $stmt_tests->bindParam(1, $order_id, PDO::PARAM_INT);
                $stmt_tests->execute();
                $ordered_tests = $stmt_tests->fetchAll(PDO::FETCH_ASSOC);
            }

        } catch (PDOException $ex) {
            $message = "<div class='alert alert-danger'>Database error: " . htmlspecialchars($ex->getMessage()) . "</div>";
            error_log("PDO Exception (view_lab_order): " . $ex->getMessage());
        } catch (Exception $e) {
            $message = "<div class='alert alert-danger'>An unexpected error occurred: " . htmlspecialchars($e->getMessage()) . "</div>";
            error_log("General Exception (view_lab_order): " . $e->getMessage());
        }
    }
} else {
    header("location: add_lab_order.php");
    exit;
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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>View Lab Order - KDCS</title>
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <?php include './config/site_css_links.php';?>
    <?php include './config/data_tables_css.php';?>
    <style>
        /* Custom styles for better readability and structure */
        .info-label {
            font-weight: bold;
            color: #333;
            min-width: 120px; /* Ensure labels align */
            display: inline-block;
        }
        .info-value {
            color: #555;
        }
        .table th, .table td {
            vertical-align: middle;
        }
        /* Flexbox for spacing buttons */
        .card-footer-buttons {
            display: flex;
            justify-content: flex-end; /* Align buttons to the right */
            gap: 10px; /* Space between buttons */
        }
        /* Print specific styles */
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            /* Hide non-print elements */
            .wrapper, .content-wrapper, .main-footer, .main-header, .main-sidebar, .content-header, .card-footer {
                display: none !important; /* Use !important to override AdminLTE defaults */
            }
            /* Ensure content to print is visible */
            .content {
                display: block !important;
                width: 100%;
                margin: 0;
                padding: 20px;
            }
            .card { /* Optional: remove shadows/borders for print */
                box-shadow: none !important;
                border: none !important;
                margin-bottom: 15px !important;
            }
            .card-header { /* Optional: simplify header for print */
                background-color: transparent !important;
                border-bottom: none !important;
                color: #333 !important;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 15px;
            }
            table, th, td {
                border: 1px solid #ddd;
            }
            th, td {
                padding: 8px;
                text-align: left;
            }
            th {
                background-color: #f2f2f2;
                text-align: center;
                color: #333; /* Ensure header text is black on print */
            }
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
                        <h1>Lab Order Details</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="add_lab_order.php">Lab Orders</a></li>
                            <li class="breadcrumb-item active">View Lab Order</li>
                        </ol>
                    </div>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <?php echo $message; ?>

                <?php if ($order_details): ?>
                <div class="row">
                    <div class="col-md-12">
                        <div class="card card-outline card-success shadow">
                            <div class="card-header">
                                <h3 class="card-title">Order Information (ID: <?php echo htmlspecialchars($order_details['lab_order_id']); ?>)</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><span class="info-label">Order Date:</span> <span class="info-value"><?php echo date('Y-m-d h:i A', strtotime($order_details['order_date'])); ?></span></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><span class="info-label">Ordered By:</span> <span class="info-value"><?php echo htmlspecialchars($order_details['ordered_by_username']); ?></span></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mt-3">
                    <div class="col-md-12">
                        <div class="card card-outline card-primary shadow">
                            <div class="card-header">
                                <h3 class="card-title">Patient Information</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
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
                        </div>
                    </div>
                </div>

                <div class="row mt-3">
                    <div class="col-md-12">
                        <div class="card card-outline card-info shadow">
                            <div class="card-header">
                                <h3 class="card-title">Ordered Lab Tests</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($ordered_tests)): ?>
                                    <table class="table table-bordered table-striped table-sm">
                                        <thead>
                                            <tr>
                                                <th style="width: 5%;">S.No</th>
                                                <th>Test Name</th>
                                                <th class="text-right">Price</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $serial_tests = 0;
                                            $total_order_price = 0;
                                            foreach ($ordered_tests as $test):
                                                $serial_tests++;
                                                $total_order_price += $test['price'];
                                            ?>
                                                <tr>
                                                    <td class="text-center"><?php echo $serial_tests; ?></td>
                                                    <td><?php echo htmlspecialchars($test['test_name']); ?></td>
                                                    <td class="text-right">₦<?php echo number_format($test['price'], 2); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th colspan="2" class="text-right">Total Order Price:</th>
                                                <th class="text-right">₦<?php echo number_format($total_order_price, 2); ?></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                <?php else: ?>
                                    <p class="text-muted">No lab tests found for this order.</p>
                                <?php endif; ?>
                            </div>
                            <div class="card-footer card-footer-buttons">
                                <button class="btn btn-secondary btn-flat" onclick="window.history.back();"><i class="fas fa-arrow-left"></i> Back to Orders</button>
                                <button type="button" class="btn btn-primary btn-flat" id="printOrderBtn"><i class="fas fa-print"></i> Print Order</button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
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
    showMenuSelected("#mnu_lab_orders", "#mi_view_lab_order");

    $(document).ready(function() {
        // Collect data for printing directly from PHP variables
        var orderDetails = <?php echo json_encode($order_details); ?>;
        var orderedTests = <?php echo json_encode($ordered_tests); ?>;
        var patientAge = <?php echo json_encode($patient_age); ?>;

        // Ensure these are defined for the print function scope
        var clinicName = '<?php echo htmlspecialchars(strtoupper($clinic_name)); ?>';
        var clinicAddress = '<?php echo htmlspecialchars($clinic_address); ?>';
        var clinicEmail = '<?php echo htmlspecialchars($clinic_email); ?>';
        var clinicPhone = '<?php echo htmlspecialchars($clinic_phone); ?>';

        // Dynamically get the base URL of your application.
        // IMPORTANT: If your application is in a subfolder (e.g., http://localhost/my_pms_app/),
        // you MUST adjust the `baseUrl` variable below.
        // Example for a subfolder named 'my_pms_app': var baseUrl = window.location.origin + '/my_pms_app';
        var baseUrl = window.location.origin;


        $('#printOrderBtn').on('click', function() {
            // Prepare logo HTML with ABSOLUTE paths
            var logoHtml =
                '<img src="' + baseUrl + '/dist/img/logo.png" style="float: left; width: 120px; height: 120px; margin-right: 15px; border-radius: 50%; object-fit: cover;">' +
                '<img src="' + baseUrl + '/dist/img/logo2.png" style="float: right; width: 120px; height: 120px; margin-left: 15px; border-radius: 50%; object-fit: cover;">';

            // Construct the full header HTML with icons
            var fullHeaderHtml =
                '<div style="text-align: center; margin-bottom: 20px; font-family: Arial, sans-serif; overflow: hidden; position: relative;">' +
                logoHtml +
                '<div style="display: inline-block; vertical-align: middle; max-width: calc(100% - 280px);">' +
                '<h2 style="margin: 0; padding: 0; color: #333;">' + clinicName + '</h2>' +
                '<p style="margin: 0; padding: 0; font-size: 0.9em; color: #555;">' + clinicAddress + '</p>' +
                '<p style="margin: 0; padding: 0; font-size: 0.9em; color: #555;">' +
                '<i class="fas fa-envelope"></i> ' + clinicEmail + ' | ' +
                '<i class="fas fa-phone"></i> ' + clinicPhone +
                '</p>' +
                '</div>' +
                '<h3 style="margin-top: 20px; color: #000; clear: both;">Lab Order Report</h3>' +
                '</div>';

            // Build Patient Details HTML
            var patientDetailsHtml = '';
            if (orderDetails) {
                patientDetailsHtml =
                    '<div style="text-align: center; margin-top: 15px; margin-bottom: 15px; border-top: 1px solid #eee; padding-top: 10px;">' +
                    '<h4 style="text-align: left; margin-bottom: 10px; color: #007bff;">Patient Information</h4>' +
                    '<table style="width:100%; border-collapse: collapse; font-size: 0.85em; margin: 0 auto;">' +
                    '<tr>' +
                    '<td style="width:25%; padding: 1px 3px;"><strong>Clinic ID:</strong> ' + (orderDetails.patient_display_id || 'N/A') + '</td>' +
                    '<td style="25%; padding: 1px 3px;"><strong>Name:</strong> ' + (orderDetails.patient_name || 'N/A') + '</td>' +
                    '<td style="25%; padding: 1px 3px;"><strong>Age:</strong> ' + patientAge + '</td>' +
                    '<td style="25%; padding: 1px 3px;"><strong>Gender:</strong> ' + (orderDetails.gender || 'N/A') + '</td>' +
                    '</tr>' +
                    '<tr>' +
                    '<td style="width:25%; padding: 1px 3px;"><strong>Phone:</strong> ' + (orderDetails.contact_no || 'N/A') + '</td>' +
                    '<td style="25%; padding: 1px 3px;"><strong>Marital Status:</strong> ' + (orderDetails.marital_status || 'N/A') + '</td>' +
                    '<td style="width:50%; padding: 1px 3px;" colspan="2"><strong>Address:</strong> ' + (orderDetails.address || 'N/A') + '</td>' +
                    '</tr>' +
                    '</table>' +
                    '</div>';
            }

            // Build Lab Order Specific Information
            var orderInfoHtml = '';
            if (orderDetails) {
                orderInfoHtml =
                    '<div style="text-align: center; margin-top: 15px; margin-bottom: 15px; border-top: 1px solid #eee; padding-top: 10px;">' +
                    '<h4 style="text-align: left; margin-bottom: 10px; color: #28a745;">Order Details</h4>' +
                    '<table style="width:100%; border-collapse: collapse; font-size: 0.85em; margin: 0 auto;">' +
                    '<tr>' +
                    '<td style="width:50%; padding: 1px 3px;"><strong>Order ID:</strong> ' + (orderDetails.lab_order_id || 'N/A') + '</td>' +
                    '<td style="50%; padding: 1px 3px;"><strong>Order Date:</strong> ' + (orderDetails.order_date ? moment(orderDetails.order_date).format('MM/DD/YYYY h:mm A') : 'N/A') + '</td>' +
                    '</tr>' +
                    '<tr>' +
                    '<td style="width:100%; padding: 1px 3px;" colspan="2"><strong>Ordered By:</strong> ' + (orderDetails.ordered_by_username || 'N/A') + '</td>' +
                    '</tr>' +
                    '</table>' +
                    '</div>';
            }

            // Build Ordered Lab Tests Table HTML
            var labTestsTableHtml = '<h4 style="text-align: center; margin-top: 20px;">Ordered Tests</h4>';
            labTestsTableHtml += '<table class="table table-bordered table-striped compact" style="font-size:inherit; width:100%; border-collapse: collapse;">';
            labTestsTableHtml += '<thead><tr style="background-color: #17a2b8; color: #000;">'; // Ensuring black text on header
            labTestsTableHtml += '<th class="p-1 text-center">S.No</th>';
            labTestsTableHtml += '<th class="p-1 text-center">Test Name</th>';
            labTestsTableHtml += '<th class="p-1 text-center">Price</th>';
            labTestsTableHtml += '</tr></thead>';
            labTestsTableHtml += '<tbody>';

            var totalOrderPrice = 0;
            if (orderedTests.length > 0) {
                $.each(orderedTests, function(idx, test){
                    totalOrderPrice += parseFloat(test.price);
                    labTestsTableHtml += '<tr>';
                    labTestsTableHtml += '<td class="text-center">' + (idx + 1) + '</td>';
                    labTestsTableHtml += '<td>' + (test.test_name || '') + '</td>';
                    labTestsTableHtml += '<td class="text-right">₦' + (parseFloat(test.price) ? parseFloat(test.price).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '0.00') + '</td>';
                    labTestsTableHtml += '</tr>';
                });
            } else {
                labTestsTableHtml += '<tr><td colspan="3" class="text-center">No lab tests found for this order.</td></tr>';
            }
            labTestsTableHtml += '</tbody>';
            labTestsTableHtml += '<tfoot>';
            labTestsTableHtml += '<tr>';
            labTestsTableHtml += '<th colspan="2" class="text-right">Total Order Price:</th>';
            labTestsTableHtml += '<th class="text-right">₦' + totalOrderPrice.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</th>';
            labTestsTableHtml += '</tr>';
            labTestsTableHtml += '</tfoot>';
            labTestsTableHtml += '</table>';


            // Open a new window/tab for printing
            var printWindow = window.open('', '_blank'); // Opens in a new tab

            printWindow.document.write('<html><head><title>Lab Order Report</title>');
            // Include FontAwesome CSS with ABSOLUTE path
            printWindow.document.write('<link rel="stylesheet" href="' + baseUrl + '/plugins/fontawesome-free/css/all.min.css">');
            // Include basic styles for tables and text for print
            printWindow.document.write('<style>');
            printWindow.document.write('body { font-family: Arial, sans-serif; margin: 20px; }');
            printWindow.document.write('table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }');
            printWindow.document.write('table, th, td { border: 1px solid #ddd; }');
            printWindow.document.write('th, td { padding: 8px; text-align: left; }');
            printWindow.document.write('th { background-color: #f2f2f2; text-align: center; color: #333; }');
            printWindow.document.write('.text-right { text-align: right; }');
            printWindow.document.write('.text-center { text-align: center; }');
            // Ensure header text is black for print (redundant if set in th, but harmless)
            printWindow.document.write('@media print { th { color: #333 !important; } }');
            printWindow.document.write('</style>');
            printWindow.document.write('</head><body>');
            printWindow.document.write(fullHeaderHtml);
            printWindow.document.write(patientDetailsHtml);
            printWindow.document.write(orderInfoHtml);
            printWindow.document.write(labTestsTableHtml);
            printWindow.document.write('</body></html>');
            printWindow.document.close(); // Important to close the document for content to render

            // Wait for images and styles to load before printing
            // This is crucial for logos and icons to appear correctly
            printWindow.onload = function() {
                // Introduce a slight delay for browser rendering before printing
                setTimeout(function() {
                    printWindow.print();
                }, 250); // 250 milliseconds delay
            };
            // Fallback: If onload doesn't fire (rare for dynamically created windows),
            // call print after a slightly longer delay.
            // This ensures that even if onload is problematic, the print dialog eventually shows up.
            setTimeout(function() {
                if (printWindow && !printWindow.closed) { // Check if window is still open
                    printWindow.print();
                }
            }, 1500); // 1.5 seconds delay as a last resort
        });
    });
</script>
</body>
</html>