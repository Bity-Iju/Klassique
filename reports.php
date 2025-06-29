<?php
session_start(); // Add this line at the very beginning to start the session

// --- START PHP Error Reporting (for debugging) ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// --- END PHP Error Reporting ---


// --- START: Emulating config/connection.php content ---
// Database connection parameters
$host = '127.0.0.1'; // Your database host. Common for local development.
$dbname = 'pms_db'; // Your database name.
$username = 'root'; // Your database username. // IMPORTANT: Replace with your actual username
$password = ''; // Your database password. IMPORTANT: If your 'root' user has a password, enter it here.

try {
    $con = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Enable exceptions for errors (FIXED: Removed extra PDO::)
    $con->setAttribute(PDO::ATTR_EMULATE_PREPARES, false); // Disable emulation for real prepared statements

    // CRUCIAL FIX: Set default fetch mode to return numeric values as native PHP types (int, float)
    // This prevents numbers from being fetched as strings, resolving the ₦N/A issue.
    // Ensure this is truly working by inspecting fetched data types if issues persist.
    $con->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);

    // Set default fetch mode to associative array for consistency
    $con->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo = $con; // Assign $con to $pdo for consistency with existing code
} catch (PDOException $e) {
    // In a real application, you'd log this error and show a user-friendly message.
    die("Database connection failed: " . $e->getMessage());
}
// --- END: Emulating config/connection.php content ---


// Handle AJAX requests or print requests
// Changed from $_GET['print_action'] to solely rely on $_POST['action']
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    $response = ['data' => []]; // Default response structure

    $from_date = $_POST['from_date'] ?? null; // Now from POST
    $to_date = $_POST['to_date'] ?? null;   // Now from POST

    $db_from_date = $from_date ? date('Y-m-d', strtotime($from_date)) : null;
    $db_to_date = $to_date ? date('Y-m-d', strtotime($to_date)) : null;

    // DataTables server-side parameters (all from POST)
    $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
    $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
    $length = isset($_POST['length']) ? intval($_POST['length']) : 5; // Default items per page (CHANGED TO 5)
    $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';

    $orderColumnIndex = 0;
    $orderDirection = 'asc';

    // Initialize $columns here to ensure it's always an array
    $columns = [];

    $baseQuery = "";
    $countQuery = ""; // Initialize $countQuery here

    // Variable to signal if we should render HTML print view
    $isPrintAction = false;
    $report_title = "";
    $report_columns = [];
    $params = []; // Moved params initialization here

    // FIX: Add more robust checks for $_POST['order'] and column index
    if (isset($_POST['order']) && is_array($_POST['order']) && !empty($_POST['order']) && isset($_POST['order'][0]['column'])) {
        $orderColumnIndex = intval($_POST['order'][0]['column']);
        $orderDirection = $_POST['order'][0]['dir'];
    }


    switch ($action) {
        case 'fetch_lab_orders_report':
            $columns = ['id', 'patient_id', 'patient_name', 'result_value', 'result_unit', 'normal_range', 'result_notes', 'result_date', 'ordered_by_user'];
            $baseQuery = "
                SELECT
                    lr.id AS id,
                    p.patient_display_id AS patient_id,
                    p.patient_name,
                    lr.result_value,
                    lr.result_unit,
                    lr.normal_range,
                    lr.result_notes,
                    lr.result_date,
                    u.username AS ordered_by_user
                FROM
                    lab_results lr
                JOIN
                    patients p ON lr.patient_id = p.id
                LEFT JOIN
                    users u ON lr.ordered_by_user_id = u.id
                WHERE 1=1
            ";
            $countQuery = "
                SELECT COUNT(*)
                FROM
                    lab_results lr
                JOIN
                    patients p ON lr.patient_id = p.id
                LEFT JOIN
                    users u ON lr.ordered_by_user_id = u.id
                WHERE 1=1
            ";
            break;

        case 'fetch_scan_orders_report':
            $columns = ['scan_order_id', 'patient_id', 'patient_name', 'scan_name', 'category_name', 'result_description', 'total_price', 'order_date', 'result_notes'];
            $baseQuery = "
                SELECT
                    s.id AS scan_order_id,
                    p.patient_display_id AS patient_id,
                    p.patient_name,
                    s.scan_title AS scan_name, /* Using scan_title from scans */
                    so.scan_category AS category_name, /* Using scan_category from scan_orders */
                    s.findings AS result_description, /* Using findings from scans */
                    so.total_price,
                    s.performed_date AS order_date, /* Using performed_date from scans */
                    s.conclusion AS result_notes /* Using conclusion from scans */
                FROM
                    scans s
                JOIN
                    patients p ON s.patient_id = p.id
                LEFT JOIN
                    scan_orders so ON s.scan_order_id = so.id /* Join to get total_price, category, notes */
                WHERE 1=1
            ";
            $countQuery = "
                SELECT COUNT(*)
                FROM
                    scans s
                JOIN
                    patients p ON s.patient_id = p.id
                LEFT JOIN
                    scan_orders so ON s.scan_order_id = so.id
                WHERE 1=1
            ";
            break;

        case 'fetch_patients_registered_report':
            $columns = ['patient_display_id', 'patient_name', 'gender', 'age', 'marital_status', 'contact_no', 'address', 'registration_date'];
            $baseQuery = "
                SELECT
                    p.patient_display_id,
                    p.patient_name,
                    p.gender,
                    TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) AS age, /* Calculated age */
                    p.marital_status,
                    p.contact_no,
                    p.address,
                    p.registration_date
                FROM
                    patients p
                WHERE 1=1
            ";
            $countQuery = "
                SELECT COUNT(*)
                FROM
                    patients p
                WHERE 1=1
            ";
            break;

        case 'fetch_billing_payment_report':
            $columns = ['bill_id', 'patient_id', 'patient_name', 'total_amount', 'paid_amount', 'due_amount', 'payment_status', 'payment_type', 'generated_by_user', 'bill_date'];
            $baseQuery = "
                SELECT
                    b.id AS bill_id,
                    p.patient_display_id AS patient_id,
                    p.patient_name,
                    b.total_amount,
                    b.paid_amount,
                    b.due_amount,
                    b.payment_status,
                    b.payment_type,
                    u.username AS generated_by_user,
                    b.bill_date
                FROM
                    bills b
                JOIN
                    patients p ON b.patient_id = p.id
                LEFT JOIN
                    users u ON b.generated_by_user_id = u.id
                WHERE 1=1
            ";
            $countQuery = "
                SELECT COUNT(*)
                FROM
                    bills b
                JOIN
                    patients p ON b.patient_id = p.id
                LEFT JOIN
                    users u ON b.generated_by_user_id = u.id
                WHERE 1=1
            ";
            break;

        // New print actions (now handled via POST)
        case 'print_lab_orders_report':
            $isPrintAction = true;
            $report_title = "Lab Results Report";
            $report_columns = ['Patient ID', 'Patient Name',  'Result Date', 'Ordered By'];
            $columns = ['patient_id', 'patient_name',  'result_date', 'ordered_by_user'];
            $baseQuery = "
                SELECT
                    lr.id AS id,
                    p.patient_display_id AS patient_id,
                    p.patient_name,
                    lr.result_value,
                    lr.result_unit,
                    lr.normal_range,
                    lr.result_notes,
                    lr.result_date,
                    u.username AS ordered_by_user
                FROM
                    lab_results lr
                JOIN
                    patients p ON lr.patient_id = p.id
                LEFT JOIN
                    users u ON lr.ordered_by_user_id = u.id
                WHERE 1=1
            ";
            $countQuery = "
                SELECT COUNT(*)
                FROM
                    lab_results lr
                JOIN
                    patients p ON lr.patient_id = p.id
                LEFT JOIN
                    users u ON lr.ordered_by_user_id = u.id
                WHERE 1=1
            ";
            break;

        case 'print_scan_orders_report':
            $isPrintAction = true;
            $report_title = "Scan Orders Report";
            $report_columns = ['Patient ID', 'Patient Name', 'Scan Type', 'Category', 'Order Date', 'Result Notes'];
            $columns = ['patient_id', 'patient_name', 'scan_name', 'category_name', 'order_date', 'result_notes'];
            $baseQuery = "
                SELECT
                    s.id AS scan_order_id,
                    p.patient_display_id AS patient_id,
                    p.patient_name,
                    s.scan_title AS scan_name, /* Using scan_title from scans */
                    so.scan_category AS category_name, /* Using scan_category from scan_orders */
                    s.findings AS result_description, /* Using findings from scans */
                    so.total_price,
                    s.performed_date AS order_date, /* Using performed_date from scans */
                    s.conclusion AS result_notes /* Using conclusion from scans */
                FROM
                    scans s
                JOIN
                    patients p ON s.patient_id = p.id
                LEFT JOIN
                    scan_orders so ON s.scan_order_id = so.id /* Join to get total_price, category, notes */
                WHERE 1=1
            ";
            $countQuery = "
                SELECT COUNT(*)
                FROM
                    scans s
                JOIN
                    patients p ON s.patient_id = p.id
                LEFT JOIN
                    scan_orders so ON s.scan_order_id = so.id
                WHERE 1=1
            ";
            break;

        case 'print_patients_registered_report':
            $isPrintAction = true;
            $report_title = "Patients Registered Report";
            $report_columns = ['Patient ID', 'Patient Name', 'Gender', 'Age', 'Marital Status', 'Contact Number', 'Address', 'Registration Date'];
            $columns = ['patient_display_id', 'patient_name', 'gender', 'age', 'marital_status', 'contact_no', 'address', 'registration_date'];
            $baseQuery = "
                SELECT
                    p.patient_display_id,
                    p.patient_name,
                    p.gender,
                    TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) AS age, /* Calculated age */
                    p.marital_status,
                    p.contact_no,
                    p.address,
                    p.registration_date
                FROM
                    patients p
                WHERE 1=1
            ";
            $countQuery = "
                SELECT COUNT(*)
                FROM
                    patients p
                WHERE 1=1
            ";
            break;

        case 'print_billing_payment_report':
            $isPrintAction = true;
            $report_title = "Billing & Payment Report";
            $report_columns = [
             'Patient ID', 'Patient Name', 'Total (₦)', 'Paid (₦)',
                'Due (₦)', 'Status', 'Payment Type', 'Generated By', 'Date'
            ];
            $columns = ['patient_id', 'patient_name', 'total_amount', 'paid_amount', 'due_amount', 'payment_status', 'payment_type', 'generated_by_user', 'bill_date'];
            $baseQuery = "
                SELECT
                    b.id AS bill_id,
                    p.patient_display_id AS patient_id,
                    p.patient_name,
                    b.total_amount,
                    b.paid_amount,
                    b.due_amount,
                    b.payment_status,
                    b.payment_type,
                    u.username AS generated_by_user,
                    b.bill_date
                FROM
                    bills b
                JOIN
                    patients p ON b.patient_id = p.id
                LEFT JOIN
                    users u ON b.generated_by_user_id = u.id
                WHERE 1=1
            ";
            $countQuery = "
                SELECT COUNT(*)
                FROM
                    bills b
                JOIN
                    patients p ON b.patient_id = p.id
                LEFT JOIN
                    users u ON b.generated_by_user_id = u.id
                WHERE 1=1
            ";
            break;


        default:
            // For unknown actions, just exit or return an error
            error_log("Unknown action received: " . ($action ?? 'NULL') . " from " . $_SERVER['REQUEST_METHOD'] . " request.");
            header('Content-Type: application/json');
            echo json_encode(['data' => [], 'error' => 'Unknown action or invalid request']);
            exit;
    }

    try {
        // CRITICAL CHECK: Ensure $columns array is not empty after the switch
        if (empty($columns)) { // Apply this check for all actions now
            error_log("Critical Error: \$columns array is empty after switch for action: " . ($action ?? 'NULL') . ". This should not happen if action is valid.");
            if (!$isPrintAction) { // Only send JSON error if not a print action
                header('Content-Type: application/json');
                echo json_encode([
                    "draw" => $draw,
                    "recordsTotal" => 0,
                    "recordsFiltered" => 0,
                    "data" => [],
                    "error" => "Internal server error: report columns not defined or invalid action leading to empty columns."
                ]);
            } else {
                $report_data = []; // Ensure data is empty for print error
                $db_error_message = "Error: Report columns not defined for print action.";
            }
            exit;
        }

        // Now, $columns is guaranteed not to be empty, so $columns[0] is safe to access.
        $orderColumn = $columns[$orderColumnIndex]; // Use $orderColumnIndex determined from POST


        // Adjust order column for specific reports to avoid ambiguity with multiple 'id' columns
        if (strpos($action, 'lab_orders') !== false && $orderColumn === 'id') {
            $orderColumn = 'lr.id'; // Explicitly use lab_results.id for ordering
        } elseif (strpos($action, 'scan_orders') !== false && $orderColumn === 'scan_order_id') {
             $orderColumn = 's.id'; // Explicitly use scans.id for ordering
        }
        // For patients registered report, if 'age' is the order column, we need to order by the calculated age.
        // If 'marital_status' is selected, it should be p.marital_status.
        if (strpos($action, 'patients_registered') !== false) {
            if ($orderColumn === 'age') {
                $orderColumn = 'TIMESTAMPDIFF(YEAR, p.dob, CURDATE())';
            } else if ($orderColumn === 'marital_status') {
                $orderColumn = 'p.marital_status';
            } else if ($orderColumn === 'patient_display_id') {
                $orderColumn = 'p.patient_display_id';
            } else if ($orderColumn === 'patient_name') {
                $orderColumn = 'p.patient_name';
            } else if ($orderColumn === 'gender') {
                $orderColumn = 'p.gender';
            } else if ($orderColumn === 'contact_no') {
                $orderColumn = 'p.contact_no';
            } else if ($orderColumn === 'address') {
                $orderColumn = 'p.address';
            } else if ($orderColumn === 'registration_date') {
                $orderColumn = 'p.registration_date';
            }
        }


        $whereClause = [];
        $whereParams = [];

        // Add date range filtering
        if ($db_from_date && $db_to_date) {
            // Determine the correct date column based on the action
            $dateColumn = '';
            if (strpos($action, 'lab_orders') !== false) {
                $dateColumn = 'lr.result_date'; // Changed to lab_results.result_date
            } elseif (strpos($action, 'scan_orders') !== false) {
                $dateColumn = 's.performed_date';
            } elseif (strpos($action, 'patients_registered') !== false) {
                $dateColumn = 'p.registration_date';
            } elseif (strpos($action, 'billing_payment') !== false) {
                $dateColumn = 'b.bill_date';
            }

            if ($dateColumn) {
                $whereClause[] = "DATE({$dateColumn}) BETWEEN ? AND ?";
                $whereParams[] = $db_from_date;
                $whereParams[] = $db_to_date;
            }
        }

        // Add global search filter for DataTables AJAX requests
        // This now applies to print actions too as search value is passed
        if (!empty($searchValue)) { // Remove the strpos($action, 'fetch_') === 0 check
            $searchParts = [];
            foreach ($columns as $column) {
                // Adjust column names for search to match the actual database columns if necessary
                $dbColumn = $column; // Default to the alias
                if (strpos($action, 'lab_orders') !== false) {
                    if ($column === 'id') $dbColumn = 'lr.id';
                    else if ($column === 'patient_id') $dbColumn = 'p.patient_display_id';
                    else if ($column === 'patient_name') $dbColumn = 'p.patient_name';
                    else if ($column === 'result_value') $dbColumn = 'lr.result_value';
                    else if ($column === 'result_unit') $dbColumn = 'lr.result_unit';
                    else if ($column === 'normal_range') $dbColumn = 'lr.normal_range';
                    else if ($column === 'result_notes') $dbColumn = 'lr.result_notes';
                    else if ($column === 'result_date') $dbColumn = 'lr.result_date'; // Changed to result_date
                    else if ($column === 'ordered_by_user') $dbColumn = 'u.username';
                } elseif (strpos($action, 'scan_orders') !== false) {
                    if ($column === 'scan_order_id') $dbColumn = 's.id';
                    else if ($column === 'patient_id') $dbColumn = 'p.patient_display_id';
                    else if ($column === 'patient_name') $dbColumn = 'p.patient_name';
                    else if ($column === 'scan_name') $dbColumn = 's.scan_title';
                    else if ($column === 'category_name') $dbColumn = 'so.scan_category';
                    else if ($column === 'result_description') $dbColumn = 's.findings';
                    else if ($column === 'total_price') $dbColumn = 'so.total_price';
                    else if ($column === 'order_date') $dbColumn = 's.performed_date';
                    else if ($column === 'result_notes') $dbColumn = 's.conclusion';
                } elseif (strpos($action, 'patients_registered') !== false) {
                    if ($column === 'patient_display_id') $dbColumn = 'p.patient_display_id';
                    else if ($column === 'patient_name') $dbColumn = 'p.patient_name';
                    else if ($column === 'gender') $dbColumn = 'p.gender';
                    else if ($column === 'age') $dbColumn = 'TIMESTAMPDIFF(YEAR, p.dob, CURDATE())'; // Search on calculated age
                    else if ($column === 'marital_status') $dbColumn = 'p.marital_status';
                    else if ($column === 'contact_no') $dbColumn = 'p.contact_no';
                    else if ($column === 'address') $dbColumn = 'p.address';
                    else if ($column === 'registration_date') $dbColumn = 'p.registration_date';
                }
                // Add other report specific column adjustments here if needed for search
                $searchParts[] = "{$dbColumn} LIKE ?";
                $whereParams[] = '%' . $searchValue . '%';
            }
            if (!empty($searchParts)) {
                $whereClause[] = "(" . implode(" OR ", $searchParts) . ")";
            }
        }

        $fullWhereClause = '';
        if (!empty($whereClause)) {
            $fullWhereClause = " AND " . implode(" AND ", $whereClause);
        }

        // 1. Get total records (before filtering)
        if (empty($countQuery)) {
            throw new Exception("Count query is empty for action: " . $action);
        }
        $stmtTotal = $pdo->prepare(str_replace("WHERE 1=1", "", $countQuery)); // Remove dummy WHERE 1=1 for total count
        $stmtTotal->execute();
        $recordsTotal = $stmtTotal->fetchColumn();

        // 2. Get filtered records count
        $stmtFiltered = $pdo->prepare($countQuery . $fullWhereClause);
        $stmtFiltered->execute($whereParams);
        $recordsFiltered = $stmtFiltered->fetchColumn();


        // 3. Get data with pagination and ordering
        if (is_null($orderColumn)) {
             throw new Exception("Order column is not defined for action: " . $action);
        }

        $orderBy = " ORDER BY {$orderColumn} {$orderDirection}";

        $limitClause = "";
        // Apply limit/offset for both fetch and print actions, as print should reflect the displayed table.
        if ($length != -1) { // -1 means all records, so no limit
            $limitClause = " LIMIT {$length} OFFSET {$start}";
        }

        $finalQuery = $baseQuery . $fullWhereClause . $orderBy . $limitClause;

        // --- START DEBUG LOGGING ---
        if (strpos($action, 'patients_registered_report') !== false) {
            error_log("Executing patients registered report query:");
            error_log("Query: " . $finalQuery);
            error_log("Params: " . print_r($whereParams, true));
        }
        // --- END DEBUG LOGGING ---

        $stmt = $pdo->prepare($finalQuery);
        $stmt->execute($whereParams);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($isPrintAction) { // If it's a print action, set $_GET for HTML rendering and fall through
            $_GET['print_action'] = str_replace('print_', '', $action); // e.g., 'lab_orders_report'
            $_GET['from'] = $from_date; // Pass original from/to for the print header
            $_GET['to'] = $to_date;
            // Do NOT exit here, allow script to continue to HTML generation below.
        } else { // If it's a regular fetch AJAX request, send JSON and exit
            header('Content-Type: application/json');
            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => $recordsTotal,
                "recordsFiltered" => $recordsFiltered,
                "data" => $report_data
            ]);
            exit; // Exit only for AJAX requests
        }

    } catch (PDOException $e) {
        error_log("Database error for action '{$action}': " . $e->getMessage());
        if (!$isPrintAction) {
            header('Content-Type: application/json');
            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => 0,
                "recordsFiltered" => 0,
                "data" => [],
                "error" => 'Database error: ' . $e->getMessage()
            ]);
        } else {
            $report_data = [];
            $db_error_message = 'Database error: ' . $e->getMessage();
        }
        exit;
    } catch (Exception $e) {
        error_log("Error: " . $e->getMessage());
        if (!$isPrintAction) {
            header('Content-Type: application/json');
            echo json_encode([
                "draw" => $draw,
                "recordsTotal" => 0,
                "recordsFiltered" => 0,
                "data" => [],
                "error" => 'Application error: ' . $e->getMessage()
            ]);
        } else {
            $report_data = [];
            $db_error_message = 'Application error: ' . $e->getMessage();
        }
        exit;
    }
}

// If print_action is set (either from initial GET or from POST processing above), we render the print view
if (isset($_GET['print_action'])) { // This block remains and now receives 'print_action' from the POST processing logic
    $print_action = $_GET['print_action'];
    $from_date = $_GET['from'] ?? 'N/A'; // Use the 'from' date passed from POST processing
    $to_date = $_GET['to'] ?? 'N/A';     // Use the 'to' date passed from POST processing

    // clinic details (re-read from index.php as done in manage_bills.php)
    $clinic_name = "KLASSIQUE DIAGNOSTICS (Default)";
    $clinic_email = "careklas7@gmail.com";
    $clinic_address = "Address Not Found (Default)";
    $clinic_phone = "+234(0)814 856 4676, +234(0)902 115 6143";

    $index_content = file_get_contents('index.php');
    if ($index_content !== false) {
        if (preg_match('/<h1><strong>(.*?)<\/strong><\/h1>/', $index_content, $matches)) {
            $clinic_name = strip_tags($matches[1]);
        }
        if (preg_match('/<hr>\s*<h3>Caring for a BETTER Life\?<\/h3>\s*<p>(.*?)<\/p>/s', $index_content, $matches)) {
            $clinic_address = strip_tags($matches[1]);
            $clinic_address = preg_replace('/^Visit us @\s*/', '', $clinic_address);
        }
        if (preg_match('/<hr>\s*<h3>Caring for a BETTER Life\?<\/h3>\s*<p>.*?<\/p>\s*<p><strong>Email:\s*<\/strong>(.*?)\s*\|\s*<strong>Office No.:<\/strong>(.*?)\s*(?:\|\s*<strong>Doctor\'s No.:<\/strong>(.*?))?<\/p>/s', $index_content, $matches_contact)) {
            $clinic_email = trim($matches_contact[1]);
            $office_no = trim($matches_contact[2]);
            $doctor_no = isset($matches_contact[3]) ? trim($matches_contact[3]) : '';
            $clinic_phone = $office_no;
            if (!empty($doctor_no) && $office_no !== $doctor_no) {
                $clinic_phone .= " / " . $doctor_no;
            }
        }
    }

    // $report_data should already be populated from the initial `if (isset($_POST['action']))` block
    // No need to re-fetch if it was successfully fetched above.
    // However, if there was an error in the previous block, $report_data might be empty.
    // The relevant `$report_columns` and `$report_title` are also set in the switch block.

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo $report_title; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <style>
        body {
            background-color: #e6ffe6; /* Light green combination */}
            .wrapper, .content-wrapper, .card-header, .card-body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            margin: 25mm; /* Increased margin for print on all sides */
        }
        /* START: Print Styles */
        @media print {
            body, .wrapper, .content-wrapper, .card, .card-header, .card-body {
                background-color: #fff !important; /* Ensure white background for printing */
                -webkit-print-color-adjust: exact !important; /* For WebKit browsers like Chrome/Safari */
                color-adjust: exact !important; /* Standard property */
            }
            .print-header {
                display: flex; /* Make the header a flex container */
                align-items: center; /* Vertically align items in the center */
                justify-content: space-between; /* Distribute items with space between them: logo1 | clinic-details | logo2 */
                margin-bottom: 20px;
                border-bottom: 1px solid #ccc;
                padding-bottom: 10px;
                /* overflow: hidden; Removed, might not be necessary with flex */
            }

            .print-header .print-logo { /* New class for logos */
                width: 70px; /* Reduced size from 100px to give more space to clinic details */
                height: 70px; /* Reduced size from 100px */
                border-radius: 50%;
                object-fit: cover;
                /* No specific margin-right needed if justify-content: space-between is used */
            }

            .print-header .clinic-details {
                text-align: center; /* Center align text info */
                flex-grow: 3; /* Increased from 1 to make it take more space, aiming for ~50% */
                margin: 0 20px; /* Add horizontal margin to separate from logos */
            }
            .print-header .clinic-details h2 { margin: 0; color: #333; font-size: 2.2em; /* Slightly smaller font for clinic name */ }
            .print-header .clinic-details p { margin: 0; font-size: 0.9em; color: #555; } /* Slightly smaller font for address/contact */

            .report-title { text-align: center; margin: 20px 0; font-size: 1.3em; font-weight: bold; clear: both; }
            .report-date-range { text-align: center; margin-bottom: 20px; font-size: 0.9em; color: #666; }
            table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            th, td { border: 1px solid #000; padding: 6px; text-align: left; font-size: 0.9em; } /* Increased font-size from 0.7em to 0.9em */
            th { background-color: #f0f0f0; text-align: center; }
            .footer { text-align: center; margin-top: 30px; font-size: 0.8em; color: #777; }
        }
    </style>
</head>
<body>
    <div class="print-header">
        <img src="dist/img/logo.png" alt="Clinic Logo" class="print-logo">
        <div class="clinic-details">
            <h2><?php echo htmlspecialchars($clinic_name); ?></h2>
            <p><?php echo htmlspecialchars($clinic_address); ?></p>
            <p>Email: <?php echo htmlspecialchars($clinic_email); ?> | Phone: <?php htmlspecialchars($clinic_phone); ?></p>
        </div>
        <img src="dist/img/logo2.png" alt="Second Logo" class="print-logo"> </div>
    <h3 class="report-title"><?php echo htmlspecialchars($report_title); ?></h3>
    <p class="report-date-range">From: <?php echo htmlspecialchars($from_date); ?> To: <?php echo htmlspecialchars($to_date); ?></p>
    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <?php foreach ($report_columns as $col): ?>
                    <th><?php echo htmlspecialchars($col); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($report_data)): ?>
                <?php foreach ($report_data as $row): ?>
                    <tr>
                        <?php
                        // Dynamically display columns based on action
                        switch ($print_action) { // This now uses the value set from $_POST
                            case 'lab_orders_report': ?>
                                <td><?php echo htmlspecialchars($row['patient_id']); ?></td>
                                <td><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['result_date']); ?></td>
                                <td><?php echo htmlspecialchars($row['ordered_by_user']); ?></td>
                                <?php break;
                            case 'scan_orders_report': ?>
                                <td><?php echo htmlspecialchars($row['patient_id']); ?></td>
                                <td><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['scan_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['category_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['order_date']); ?></td>
                                <td><?php echo htmlspecialchars($row['result_notes']); ?></td>
                                <?php break;
                            case 'patients_registered_report': ?>
                                <td><?php echo htmlspecialchars($row['patient_display_id']); ?></td>
                                <td><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['gender']); ?></td>
                                <td><?php echo htmlspecialchars($row['age']); ?></td>
                                <td><?php echo htmlspecialchars($row['marital_status']); ?></td>
                                <td><?php echo htmlspecialchars($row['contact_no']); ?></td>
                                <td><?php echo htmlspecialchars($row['address']); ?></td>
                                <td><?php echo htmlspecialchars($row['registration_date']); ?></td>
                                <?php break;
                            case 'billing_payment_report': ?>
                                <td><?php echo htmlspecialchars($row['patient_id']); ?></td>
                                <td><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                <td><?php echo number_format($row['total_amount'], 2); ?></td>
                                <td><?php echo number_format($row['paid_amount'], 2); ?></td>
                                <td><?php echo number_format($row['due_amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($row['payment_status']); ?></td>
                                <td><?php echo htmlspecialchars($row['payment_type']); ?></td>
                                <td><?php echo htmlspecialchars($row['generated_by_user']); ?></td>
                                <td><?php echo htmlspecialchars($row['bill_date']); ?></td>
                                <?php break;
                        } ?>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="<?php echo count($report_columns); ?>" class="text-center">No data found for the selected date range or filters. <?php echo isset($db_error_message) ? 'Error: ' . htmlspecialchars($db_error_message) : ''; ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="footer">
        <p>Report generated on <?php echo date('Y-m-d H:i:s'); ?></p>
        <p>Software by Bity Iju</p>
    </div>
    <script>
        window.onload = function() {
            window.print();
            // Attempt to close the window after print dialog is initiated
            // This might not work in all browsers due to security restrictions
            // but is a common practice for print-specific tabs.
            window.onafterprint = function() {
                window.close();
            };
        };
    </script>
</body>
</html>
<?php exit; // Exit after printing HTML
}

// If not a print action or AJAX request, render the main reports page
include_once('./config/head.php');
include_once('./config/header.php');
include_once('./config/sidebar.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Klassique Diagnostics</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/1.7.1/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker.min.css">
    <style>
        body {
            background-color: #e6ffe6; /* Light green combination */
        }
        .wrapper, .content-wrapper, .card-header, .card-body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            /* No margin for these in the main view */
        }
        .report-section {
            margin-bottom: 30px;
            padding: 20px;
            border: 1px solid #e3e3e3;
            border-radius: 8px;
            background-color: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .date-filter-container {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap; /* Allow wrapping on smaller screens */
        }
        .date-filter-container .form-group {
            margin-bottom: 0; /* Remove default form-group margin */
            min-width: 150px; /* Ensure date pickers have enough space */
        }
        .date-filter-container .btn {
            padding: 8px 15px;
            height: 38px; /* Match height of input fields */
        }
        .report-table-container {
            overflow-x: auto; /* Enable horizontal scrolling for tables */
        }
        .dataTables_wrapper .row:nth-child(2) { /* Targeting the row that contains the table */
            overflow-x: auto;
        }
        .dataTables_filter {
            margin-bottom: 10px; /* Space between search and table */
        }

        /* --- Custom Styles for Dark Green Theme --- */

        /* Active Tab Color */
        .nav-pills .nav-link.active {
            background-color: #0A603F; /* Dark green */
            color: #fff; /* White text for contrast */
        }

        /* Inactive Tab Hover Color */
        .nav-pills .nav-link:hover:not(.active) {
            color: #0A603F; /* Dark green text on hover for inactive */
        }
        .nav-pills .nav-link:focus:not(.active) {
            color: #0A603F; /* Dark green text on focus for inactive */
        }

        /* Primary Button Color (Generate Report) */
        .btn-primary {
            background-color: #0A603F; /* Dark green */
            border-color: #0A603F; /* Dark green border */
            color: #fff; /* White text */
        }

        .btn-primary:hover,
        .btn-primary:focus,
        .btn-primary:active {
            background-color: #084D32; /* Slightly darker green on hover/focus/active */
            border-color: #084D32;
            color: #fff;
        }

        /* Secondary Button Color (Print PDF) */
        .btn-secondary {
            background-color: #1B5E20; /* A different shade of dark green */
            border-color: #1B5E20;
            color: #fff;
        }

        .btn-secondary:hover,
        .btn-secondary:focus,
        .btn-secondary:active {
            background-color: #154D19; /* Slightly darker green on hover/focus/active */
            border-color: #154D19;
            color: #fff;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini">
    <div class="wrapper">
        <div class="preloader flex-column justify-content-center align-items-center">
            <img class="animation__shake" src="dist/img/AdminLTELogo.png" alt="AdminLTELogo" height="60" width="60">
        </div>

        <div class="content-wrapper">
            <div class="content-header">
                <div class="container-fluid">
                    <div class="row mb-2">
                        <div class="col-sm-6">
                            <h1 class="m-0">Reports</h1>
                        </div><div class="col-sm-6">
                            <ol class="breadcrumb float-sm-right">
                                <li class="breadcrumb-item"><a href="#">Home</a></li>
                                <li class="breadcrumb-item active">Reports</li>
                            </ol>
                        </div></div></div></div>
            <section class="content">
                <div class="container-fluid">
                    <div class="card card-success card-outline">
                        <div class="card-header p-0 pt-1 border-bottom-0">
                            <ul class="nav nav-pills float-right" id="report-tabs" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link active" id="lab-orders-tab" data-toggle="pill" href="#lab-orders" role="tab" aria-controls="lab-orders" aria-selected="true">Lab Results Report</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="scan-orders-tab" data-toggle="pill" href="#scan-orders" role="tab" aria-controls="scan-orders" aria-selected="false">Scan Orders</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="patients-registered-tab" data-toggle="pill" href="#patients-registered" role="tab" aria-controls="patients-registered" aria-selected="false">Patients Registered</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="billing-payment-tab" data-toggle="pill" href="#billing-payment" role="tab" aria-controls="billing-payment" aria-selected="false">Billing & Payment</a>
                                </li>
                            </ul>
                        </div>
                        <div class="card-body">
                            <div class="tab-content" id="report-tabs-content">
                                <div class="tab-pane fade show active" id="lab-orders" role="tabpanel" aria-labelledby="lab-orders-tab">
                                    <div class="report-section">
                                        <h4>Lab Results Report</h4>
                                        <div class="date-filter-container">
                                            <div class="form-group">
                                                <label for="lab_order_from">From Date:</label>
                                                <input type="text" class="form-control datepicker" id="lab_order_from" placeholder="Select start date" autocomplete="off">
                                            </div>
                                            <div class="form-group">
                                                <label for="lab_order_to">To Date:</label>
                                                <input type="text" class="form-control datepicker" id="lab_order_to" placeholder="Select end date" autocomplete="off">
                                            </div>
                                            <button class="btn btn-primary" id="generate_lab_order_report">Generate Report</button>
                                            <button class="btn btn-secondary" id="print_lab_order_pdf"><i class="fa fa-print"></i> Print PDF</button>
                                        </div>
                                        <div class="report-table-container">
                                            <table id="lab_order_report_table" class="table table-bordered table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>Patient ID</th>
                                                        <th>Patient Name</th>
                                                        <th>Result Date</th>
                                                        <th>Ordered By</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <div class="tab-pane fade" id="scan-orders" role="tabpanel" aria-labelledby="scan-orders-tab">
                                    <div class="report-section">
                                        <h4>Scan Orders Report</h4>
                                        <div class="date-filter-container">
                                            <div class="form-group">
                                                <label for="scan_order_from">From Date:</label>
                                                <input type="text" class="form-control datepicker" id="scan_order_from" placeholder="Select start date" autocomplete="off">
                                            </div>
                                            <div class="form-group">
                                                <label for="scan_order_to">To Date:</label>
                                                <input type="text" class="form-control datepicker" id="scan_order_to" placeholder="Select end date" autocomplete="off">
                                            </div>
                                            <button class="btn btn-primary" id="generate_scan_order_report">Generate Report</button>
                                            <button class="btn btn-secondary" id="print_scan_order_pdf"><i class="fa fa-print"></i> Print PDF</button>
                                        </div>
                                        <div class="report-table-container">
                                            <table id="scan_order_report_table" class="table table-bordered table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>Patient ID</th>
                                                        <th>Patient Name</th>
                                                        <th>Scan Type</th>
                                                        <th>Category</th>
                                                        <th>Performed Date</th>
                                                        <th>Notes</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <div class="tab-pane fade" id="patients-registered" role="tabpanel" aria-labelledby="patients-registered-tab">
                                    <div class="report-section">
                                        <h4>Patients Registered Report</h4>
                                        <div class="date-filter-container">
                                            <div class="form-group">
                                                <label for="patient_registered_from">From Date:</label>
                                                <input type="text" class="form-control datepicker" id="patient_registered_from" placeholder="Select start date" autocomplete="off">
                                            </div>
                                            <div class="form-group">
                                                <label for="patient_registered_to">To Date:</label>
                                                <input type="text" class="form-control datepicker" id="patient_registered_to" placeholder="Select end date" autocomplete="off">
                                            </div>
                                            <button class="btn btn-success" id="generate_patients_registered_report">Generate Report</button>
                                            <button class="btn btn-secondary" id="print_patients_registered_pdf"><i class="fa fa-print"></i> Print PDF</button>
                                        </div>
                                        <div class="report-table-container">
                                            <table id="patients_registered_report_table" class="table table-bordered table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>Patient ID</th>
                                                        <th>Patient Name</th>
                                                        <th>Gender</th>
                                                        <th>Age</th>
                                                        <th>Marital Status</th>
                                                        <th>Contact No.</th>
                                                        <th>Address</th>
                                                        <th>Registration Date</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <div class="tab-pane fade" id="billing-payment" role="tabpanel" aria-labelledby="billing-payment-tab">
                                    <div class="report-section">
                                        <h4>Billing & Payment Report</h4>
                                        <div class="date-filter-container">
                                            <div class="form-group">
                                                <label for="billing_payment_from">From Date:</label>
                                                <input type="text" class="form-control datepicker" id="billing_payment_from" placeholder="Select start date" autocomplete="off">
                                            </div>
                                            <div class="form-group">
                                                <label for="billing_payment_to">To Date:</label>
                                                <input type="text" class="form-control datepicker" id="billing_payment_to" placeholder="Select end date" autocomplete="off">
                                            </div>
                                            <button class="btn btn-success" id="generate_billing_payment_report">Generate Report</button>
                                            <button class="btn btn-secondary" id="print_billing_payment_pdf"><i class="fa fa-print"></i> Print PDF</button>
                                        </div>
                                        <div class="report-table-container">
                                            <table id="billing_payment_report_table" class="table table-bordered table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>Patient ID</th>
                                                        <th>Patient Name</th>
                                                        <th>Total Amount (₦)</th>
                                                        <th>Paid Amount (₦)</th>
                                                        <th>Due Amount (₦)</th>
                                                        <th>Payment Status</th>
                                                        <th>Payment Type</th>
                                                        <th>Generated By</th>
                                                        <th>Bill Date</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
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
        <?php include_once('./config/footer.php'); ?>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.datatables.net/buttons/1.7.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/1.7.1/js/buttons.print.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>
    <script>
    $(document).ready(function() {
        // Datepicker initialization
        $('.datepicker').datepicker({
            format: 'yyyy-mm-dd',
            autoclose: true,
            todayHighlight: true
        });

        // Function to show custom SweetAlert2 message
        function showCustomMessage(message) {
            Swal.fire({
                icon: 'warning',
                title: 'Missing Dates',
                text: message,
                confirmButtonColor: '#3085d6',
                confirmButtonText: 'OK'
            });
        }

        // Function to get formatted date from input
        function getFormattedDate(selector) {
            var date = $(selector).val();
            return date; // Datepicker already formats it as 'yyyy-mm-dd'
        }

        // Common function to create and submit form for printing
        function printReport(actionType, fromDateSelector, toDateSelector, dataTableInstance) {
            var from = getFormattedDate(fromDateSelector);
            var to = getFormattedDate(toDateSelector);

            if (from && to) {
                var form = $('<form>', {
                    'action': 'reports.php',
                    'method': 'post',
                    'target': '_blank' // Open in new tab
                }).append($('<input>', {
                    'name': 'action',
                    'type': 'hidden',
                    'value': actionType
                })).append($('<input>', {
                    'name': 'from_date',
                    'type': 'hidden',
                    'value': from
                })).append($('<input>', {
                    'name': 'to_date',
                    'type': 'hidden',
                    'value': to
                }));

                // Get current DataTable state
                var tableInfo = dataTableInstance.page.info();
                var order = dataTableInstance.order();
                var search = dataTableInstance.search();

                // Append DataTables parameters for server-side processing
                form.append($('<input>', {'name': 'draw', 'type': 'hidden', 'value': tableInfo.draw || 1}));
                form.append($('<input>', {'name': 'start', 'type': 'hidden', 'value': tableInfo.start}));
                form.append($('<input>', {'name': 'length', 'type': 'hidden', 'value': tableInfo.length})); // Use current page length
                form.append($('<input>', {'name': 'search[value]', 'type': 'hidden', 'value': search}));
                if (order.length > 0) {
                    form.append($('<input>', {'name': 'order[0][column]', 'type': 'hidden', 'value': order[0][0]}));
                    form.append($('<input>', {'name': 'order[0][dir]', 'type': 'hidden', 'value': order[0][1]}));
                }

                // Append the form to the body and submit it
                $('body').append(form);
                form.submit();
                form.remove(); // Clean up the form
            } else {
                showCustomMessage('Please select both From and To dates for this report.');
            }
        }


        // START: Lab Orders Report JavaScript (Now Lab Results Report)
        var labOrderTable = $('#lab_order_report_table').DataTable({
            "processing": true,
            "serverSide": true,
            "ajax": {
                "url": "reports.php",
                "type": "POST",
                "data": function (d) {
                    d.action = 'fetch_lab_orders_report';
                    d.from_date = getFormattedDate("#lab_order_from");
                    d.to_date = getFormattedDate("#lab_order_to");
                }
            },
            // Updated columns to match lab_results table general fields
            "columns": [
                { "data": "patient_id" },
                { "data": "patient_name" },
                { "data": "result_date" }, // Changed to result_date
                { "data": "ordered_by_user" }
            ],
            "paging": true,
            "lengthChange": true,
            "searching": true,
            "ordering": true,
            "info": true,
            "autoWidth": false,
            "responsive": true
        });

        $('#generate_lab_order_report').on('click', function() {
            var from = getFormattedDate("#lab_order_from");
            var to = getFormattedDate("#lab_order_to");
            if (from && to) {
                labOrderTable.ajax.reload();
            } else {
                showCustomMessage('Please select both From and To dates for Lab Results Report.');
            }
        });

        $("#print_lab_order_pdf").click(function() {
            printReport('print_lab_orders_report', '#lab_order_from', '#lab_order_to', labOrderTable);
        });
        // END: Lab Orders Report JavaScript

        // START: Scan Orders Report JavaScript
        var scanOrderTable = $('#scan_order_report_table').DataTable({
            "processing": true,
            "serverSide": true,
            "ajax": {
                "url": "reports.php",
                "type": "POST",
                "data": function (d) {
                    d.action = 'fetch_scan_orders_report';
                    d.from_date = getFormattedDate("#scan_order_from");
                    d.to_date = getFormattedDate("#scan_order_to");
                }
            },
            "columns": [
                { "data": "patient_id" },
                { "data": "patient_name" },
                { "data": "scan_name" },
                { "data": "category_name" },
                { "data": "order_date" },
                { "data": "result_notes" }
            ],
            "paging": true,
            "lengthChange": true,
            "searching": true,
            "ordering": true,
            "info": true,
            "autoWidth": false,
            "responsive": true
        });

        $('#generate_scan_order_report').on('click', function() {
            var from = getFormattedDate("#scan_order_from");
            var to = getFormattedDate("#scan_order_to");
            if (from && to) {
                scanOrderTable.ajax.reload();
            } else {
                showCustomMessage('Please select both From and To dates for Scan Orders Report.');
            }
        });

        $("#print_scan_order_pdf").click(function() {
            printReport('print_scan_orders_report', '#scan_order_from', '#scan_order_to', scanOrderTable);
        });
        // END: Scan Orders Report JavaScript

        // START: Patients Registered Report JavaScript
        var patientsRegisteredTable = $('#patients_registered_report_table').DataTable({
            "processing": true,
            "serverSide": true,
            "ajax": {
                "url": "reports.php",
                "type": "POST",
                "data": function (d) {
                    d.action = 'fetch_patients_registered_report';
                    d.from_date = getFormattedDate("#patient_registered_from");
                    d.to_date = getFormattedDate("#patient_registered_to");
                }
            },
            "columns": [
                { "data": "patient_display_id" },
                { "data": "patient_name" },
                { "data": "gender" },
                { "data": "age" }, // Added age
                { "data": "marital_status" }, // Added marital_status
                { "data": "contact_no" },
                { "data": "address" },
                { "data": "registration_date" }
            ],
            "paging": true,
            "lengthChange": true,
            "searching": true,
            "ordering": true,
            "info": true,
            "autoWidth": false,
            "responsive": true
        });

        $('#generate_patients_registered_report').on('click', function() {
            var from = getFormattedDate("#patient_registered_from");
            var to = getFormattedDate("#patient_registered_to");
            if (from && to) {
                patientsRegisteredTable.ajax.reload();
            } else {
                showCustomMessage('Please select both From and To dates for Patients Registered Report.');
            }
        });

        $("#print_patients_registered_pdf").click(function() {
            printReport('print_patients_registered_report', '#patient_registered_from', '#patient_registered_to', patientsRegisteredTable);
        });
        // END: Patients Registered Report JavaScript

        // START: Billing & Payment Report JavaScript
        var billingPaymentTable = $('#billing_payment_report_table').DataTable({
            "processing": true,
            "serverSide": true,
            "ajax": {
                "url": "reports.php",
                "type": "POST",
                "data": function (d) {
                    d.action = 'fetch_billing_payment_report';
                    d.from_date = getFormattedDate("#billing_payment_from");
                    d.to_date = getFormattedDate("#billing_payment_to");
                }
            },
            "columns": [
                { "data": "patient_id" },
                { "data": "patient_name" },
                { "data": "total_amount", "render": function(data, type, row) { return '₦' + parseFloat(data).toFixed(2); } },
                { "data": "paid_amount", "render": function(data, type, row) { return '₦' + parseFloat(data).toFixed(2); } },
                { "data": "due_amount", "render": function(data, type, row) { return '₦' + parseFloat(data).toFixed(2); } },
                { "data": "payment_status" },
                { "data": "payment_type" },
                { "data": "generated_by_user" },
                { "data": "bill_date" }
            ],
            "paging": true,
            "lengthChange": true,
            "searching": true,
            "ordering": true,
            "info": true,
            "autoWidth": false,
            "responsive": true
        });

        $('#generate_billing_payment_report').on('click', function() {
            var from = getFormattedDate("#billing_payment_from");
            var to = getFormattedDate("#billing_payment_to");
            if (from && to) {
                billingPaymentTable.ajax.reload();
            } else {
                showCustomMessage('Please select both From and To dates for Billing & Payment Report.');
            }
        });

        $("#print_billing_payment_pdf").click(function() {
            printReport('print_billing_payment_report', '#billing_payment_from', '#billing_payment_to', billingPaymentTable);
        });
        // END: Billing & Payment Report JavaScript

        // --- Tab Handling ---
        $('a[data-toggle="pill"]').on('shown.bs.tab', function (e) {
            // Adjust DataTable columns on tab show
            $.fn.dataTable.tables( {visible: true, api: true} ).columns.adjust();
        });
    });
    </script>
</body>
</html>