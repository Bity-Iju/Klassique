<?php
session_start();
// Redirect if user is not logged in
if(!(isset($_SESSION['user_id']))) {
  header("location:index.php");
  exit;
}

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config/connection.php'; // Include your database connection

// Handle AJAX request for fetching bill details for EDIT and VIEW
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'fetch_bill_details') {
    $bill_id = $_POST['bill_id'];
    $response = ['status' => 'error', 'message' => ''];

    try {
        // Fetch bill header details, including generated_by_user_id
        $stmt = $con->prepare("SELECT b.id AS bill_id, p.patient_name, b.patient_id, b.total_amount, b.paid_amount, b.due_amount, b.payment_status, b.payment_type, b.generated_by_user_id
                                FROM bills b
                                JOIN patients p ON b.patient_id = p.id
                                WHERE b.id = ?");
        $stmt->execute([$bill_id]);
        $bill_header = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($bill_header) {
            // Fetch bill items
            $stmt_items = $con->prepare("SELECT item_type, item_id, amount, quantity FROM bill_items WHERE bill_id = ?");
            $stmt_items->execute([$bill_id]);
            $bill_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

            $response['status'] = 'success';
            $response['bill_header'] = $bill_header;
            $response['bill_items'] = $bill_items;
        } else {
            $response['message'] = 'Bill not found.';
        }
    } catch (PDOException $e) {
        $response['message'] = 'Database error: ' . $e->getMessage();
        error_log("Bill Details Fetch Error: " . $e->getMessage()); // Log the error
    }
    echo json_encode($response);
    exit();
}

// Handle AJAX request for fetching all patients
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'fetch_all_patients') {
    $response = ['status' => 'error', 'message' => '', 'patients' => []];
    try {
        // Modified query to order by created_at in descending order (most recent first)
        // Also select clinic_number and unique_id
        $stmt = $con->query("SELECT id, patient_name, created_at, clinic_number, unique_id FROM patients ORDER BY created_at DESC"); // Added clinic_number and unique_id to select
        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response['status'] = 'success';
        $response['patients'] = $patients;
    } catch (PDOException $e) {
        $response['message'] = 'Database error: ' . $e->getMessage();
        error_log("Fetch All Patients Error: " . $e->getMessage()); // Log the error
    }
    echo json_encode($response);
    exit();
}

// Handle AJAX request for adding a new patient
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_new_patient') {
    $patient_name = trim($_POST['patient_name']);
    $address = trim($_POST['address']);
    $date_of_birth = trim($_POST['date_of_birth']);
    $gender = trim($_POST['gender']);
    $marital_status = trim($_POST['marital_status']);
    $phone_number = trim($_POST['phone_number']);
    $next_appointment_date = !empty($_POST['next_appointment_date']) ? $_POST['next_appointment_date'] : null;


    $response = ['status' => 'error', 'message' => ''];

    if (empty($patient_name) || empty($address) || empty($date_of_birth) || empty($gender) || empty($marital_status) || empty($phone_number)) {
        $response['message'] = 'Please fill all required patient details.';
    } else {
        try {
            // Include created_at in insert for new patients, and new fields
            $stmt = $con->prepare("INSERT INTO patients (patient_name, address, date_of_birth, gender, marital_status, phone_number, next_appointment_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            if ($stmt->execute([$patient_name, $address, $date_of_birth, $gender, $marital_status, $phone_number, $next_appointment_date])) {
                $new_patient_id = $con->lastInsertId();

                // --- MODIFICATION START ---
                // Generate Clinic Number without the year
                $clinic_number = "KDCS/MUB/" . str_pad($new_patient_id, 4, '0', STR_PAD_LEFT);

                // Generate Unique ID without the year
                $unique_id = "KLASS/PID/" . substr(md5(uniqid(rand(), true)), 0, 8);
                // --- MODIFICATION END ---

                // Update the patient record with generated IDs
                $stmt_update_ids = $con->prepare("UPDATE patients SET clinic_number = ?, unique_id = ? WHERE id = ?");
                $stmt_update_ids->execute([$clinic_number, $unique_id, $new_patient_id]);

                // Fetch the created_at timestamp and new IDs for the newly added patient
                $stmt_fetch_data = $con->prepare("SELECT created_at, clinic_number, unique_id FROM patients WHERE id = ?");
                $stmt_fetch_data->execute([$new_patient_id]);
                $new_patient_data = $stmt_fetch_data->fetch(PDO::FETCH_ASSOC);

                $response['status'] = 'success';
                $response['message'] = 'Patient added successfully!';
                $response['patient_id'] = $new_patient_id;
                $response['patient_name'] = $patient_name;
                $response['created_at'] = $new_patient_data['created_at']; // Pass created_at back
                $response['clinic_number'] = $new_patient_data['clinic_number']; // Pass generated clinic_number
                $response['unique_id'] = $new_patient_data['unique_id']; // Pass generated unique_id
            } else {
                $response['message'] = 'Failed to add patient.';
            }
        } catch (PDOException $e) {
            $response['message'] = 'Database error: ' . $e->getMessage();
            error_log("Add New Patient Error: " . $e->getMessage()); // Log the error
        }
    }
    echo json_encode($response);
    exit();
}

// Handle AJAX request for saving/updating a bill
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_bill') {
    $bill_id = isset($_POST['bill_id']) && $_POST['bill_id'] !== '' ? $_POST['bill_id'] : null;
    $patient_id = $_POST['patient_id'];
    $total_amount = $_POST['total_amount'];
    $paid_amount = $_POST['paid_amount'];
    $due_amount = $_POST['due_amount'];
    $payment_status = $_POST['payment_status'];
    $payment_type = $_POST['payment_type'];
    $generated_by_user_id = $_SESSION['user_id']; // Use session user ID
    $bill_items = json_decode($_POST['bill_items'], true);

    $response = ['status' => 'error', 'message' => ''];

    try {
        $con->beginTransaction();

        if ($bill_id) {
            // Update existing bill, including generated_by_user_id
            $stmt = $con->prepare("UPDATE bills SET patient_id = ?, total_amount = ?, paid_amount = ?, due_amount = ?, payment_status = ?, payment_type = ?, generated_by_user_id = ? WHERE id = ?");
            $stmt->execute([$patient_id, $total_amount, $paid_amount, $due_amount, $payment_status, $payment_type, $generated_by_user_id, $bill_id]);

            // Delete existing items for the bill
            $stmt_delete_items = $con->prepare("DELETE FROM bill_items WHERE bill_id = ?");
            $stmt_delete_items->execute([$bill_id]);
        } else {
            // Insert new bill, including generated_by_user_id
            $stmt = $con->prepare("INSERT INTO bills (patient_id, total_amount, paid_amount, due_amount, payment_status, payment_type, generated_by_user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$patient_id, $total_amount, $paid_amount, $due_amount, $payment_status, $payment_type, $generated_by_user_id]);
            $bill_id = $con->lastInsertId();
        }

        // Insert new bill items
        $stmt_items = $con->prepare("INSERT INTO bill_items (bill_id, item_type, item_id, amount, quantity) VALUES (?, ?, ?, ?, ?)");
        foreach ($bill_items as $item) {
            $stmt_items->execute([$bill_id, $item['item_type'], $item['id'], $item['price'], $item['quantity']]);
        }

        $con->commit();
        $response['status'] = 'success';
        $response['message'] = $bill_id ? 'Bill updated successfully!' : 'Bill saved successfully!';
        $response['bill_id'] = $bill_id; // Return the bill ID for printing
    } catch (PDOException $e) {
        $con->rollBack();
        $response['message'] = 'Database error: ' . $e->getMessage();
        error_log("Save Bill Error: " . $e->getMessage()); // Log the error
    }
    echo json_encode($response);
    exit();
}

// Handle AJAX request for deleting a bill
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_bill') {
    $bill_id = $_POST['bill_id'];
    $response = ['status' => 'error', 'message' => ''];

    try {
        $con->beginTransaction();
        // Delete bill items first
        $stmt_items = $con->prepare("DELETE FROM bill_items WHERE bill_id = ?");
        $stmt_items->execute([$bill_id]);

        // Then delete the bill
        $stmt = $con->prepare("DELETE FROM bills WHERE id = ?");
        $stmt->execute([$bill_id]);

        $con->commit();
        $response['status'] = 'success';
        $response['message'] = 'Bill deleted successfully!';
    } catch (PDOException $e) {
        $con->rollBack();
        $response['message'] = 'Database error: ' . $e->getMessage();
        error_log("Delete Bill Error: " . $e->getMessage()); // Log the error
    }
    echo json_encode($response);
    exit();
}

include_once('./config/head.php');
include_once('./config/header.php');
include_once('./config/sidebar.php');

$page_title = "Manage Bills";

function loadServices() {
    $jsonFile = 'services.json';
    if (file_exists($jsonFile)) {
        $jsonData = file_get_contents($jsonFile);
        $servicesArray = json_decode($jsonData, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $services = [];
            foreach ($servicesArray as $service) {
                $services[$service['id']] = $service;
            }
            return $services;
        }
    }
    return [];
}

function loadTestTypes($con) {
    try {
        $stmt = $con->query("SELECT test_id, test_name, price, icon_class FROM test_types ORDER BY test_name ASC");
        $testTypesArray = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $testTypes = [];
        foreach ($testTypesArray as $testType) {
            $testTypes[$testType['test_id']] = [
                'id' => $testType['test_id'],
                'name' => $testType['test_name'],
                'price' => (float)$testType['price'],
                'icon_class' => $testType['icon_class']
            ];
        }
        return $testTypes;
    } catch (PDOException $e) {
        error_log("Error loading test types from DB: " . $e->getMessage());
        return [];
    }
}

$services = loadServices();
$testTypes = loadTestTypes($con);
$orderItems = [];

date_default_timezone_set('Africa/Lagos');
$currentDateTime = date('m/d/Y H:i:s');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Klassique Diagnostics</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        /* Ensures content-wrapper has correct padding for fixed header and sidebar */
        .content-wrapper {
            padding-top: 56px; /* Adjust based on your header height if different */
            margin-left: 250px; /* Adjust based on your sidebar width if different */
        }
        /* AdminLTE's .layout-fixed already handles the main-sidebar position correctly */

        /* DataTables button styling */
        .dataTables_wrapper .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
            line-height: 1.5;
            border-radius: 0.2rem;
        }

        /* Custom Styles for Manage Prices link hover */
        .nav-sidebar .nav-item .nav-link[href*="update_prices.php"]:hover {
            color: #fff !important;
        }

        /* General styles */
        .info-box .info-box-icon {
            border-radius: 0.25rem;
            width: 70px;
            height: 100%;
            text-align: center;
            font-size: 2.5rem;
            line-height: 1;
            background-color: rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .product-grid, .test-type-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 10px;
            max-height: 70vh;
            overflow-y: auto;
            padding-right: 5px;
            margin-bottom: 20px;
        }
        .product-card, .test-type-card {
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            padding: 8px;
            text-align: center;
            cursor: pointer;
            transition: transform 0.2s;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
            height: 120px;
        }
        .product-card:hover, .test-type-card:hover { transform: translateY(-3px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .product-card .product-icon, .test-type-card .test-icon {
            font-size: 3em;
            color:rgb(6, 112, 24);
            margin-bottom: 5px;
        }
        .test-type-card .test-icon {
            font-size: 1.5em; /* Reduced from 3em to 1.5em (50%) */
            color:rgb(7, 105, 12);
            margin-bottom: 5px;
        }
        .product-card .name, .test-type-card .name {
            font-weight: bold;
            font-size: 0.85em;
            line-height: 1.2;
            flex-grow: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 5px;
        }
        .product-card .price-display, .test-type-card .price-display {
            font-size: 0.8em;
            color: #28a745;
            font-weight: bold;
        }
        .order-table table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .order-table th, .order-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .order-table th { background-color: #f2f2f2; }
        .order-table .quantity-input { width: 60px; text-align: center; }
        .total-summary { text-align: right; margin-top: 20px; }
        .total-summary div { margin-bottom: 5px; }
        .total-payable { background-color:rgb(10, 160, 10); color: white; padding: 15px; border-radius: 8px; text-align: center; font-size: 24px; font-weight: bold; margin-top: 20px; cursor: pointer; }
        .payment-buttons { display: flex; justify-content: space-around; margin-top: 30px; }
        .payment-buttons button { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; margin: 0 5px; }
        .payment-buttons .credit-sale { background-color: #dc3545; color: white; }
        .payment-buttons .multiple-pay { background-color: #6c758d; color: white; }
        .payment-buttons .cash { background-color: #28a745; color: white; }
        .manage-prices-link {
            display: block;
            margin-top: 15px;
            padding: 10px 15px;
            background-color:rgb(6, 93, 41);
            color: white;
            text-align: center;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
        }
        .manage-prices-link:hover {
            background-color:rgb(11, 169, 66);
            color: white;
        }
        #patient_id_select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            background-color: #fff;
            margin-bottom: 20px;
        }
        /* Styles for the new 3-column summary table layout */
        .table-summary-3-cols th, .table-summary-3-cols td {
            vertical-align: middle;
            padding: 0.5rem;
        }
        .table-summary-3-cols th {
            text-align: right;
            white-space: nowrap;
        }
        .table-summary-3-cols td {
            text-align: left;
        }

        /* Styles for the receipt preview in modal */
        #receiptPrintArea {
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 10px;
            margin: 0 auto;
            padding: 5mm;
            box-sizing: border-box; /* Include padding in element's total width and height */
        }
        #receiptPrintArea .receipt-header,
        #receiptPrintArea .receipt-footer {
            text-align: center;
            margin-bottom: 5px;
        }
        #receiptPrintArea .receipt-header h3 {
            margin: 0;
            font-size: 12px;
        }
        #receiptPrintArea .receipt-details,
        #receiptPrintArea .receipt-summary {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px;
        }
        #receiptPrintArea .receipt-details th,
        #receiptPrintArea .receipt-details td,
        #receiptPrintArea .receipt-summary th,
        #receiptPrintArea .receipt-summary td {
            padding: 1px 0;
            text-align: left;
        }
        #receiptPrintArea .receipt-details td:nth-child(2) {
            text-align: center;
        }
        #receiptPrintArea .receipt-details td:nth-child(3),
        #receiptPrintArea .receipt-details td:nth-child(4) {
            text-align: right;
        }
        #receiptPrintArea .receipt-summary td:nth-child(2) {
            text-align: right;
        }
        #receiptPrintArea .divider {
            border-top: 1px dashed #000;
            margin: 5px 0;
        }
        #receiptPrintArea .thank-you {
            margin-top: 10px;
            font-weight: bold;
            text-align: center;
        }

        /* Media query for printing only the receipt area */
        @media print {
            body > *:not(#printPreviewModal) {
                display: none !important;
            }
            #printPreviewModal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                margin: 0;
                padding: 0;
                overflow: hidden;
                display: block !important; /* Ensure modal is visible */
                background: none; /* No background when printing */
            }
            #printPreviewModal .modal-dialog {
                width: auto !important;
                max-width: 80mm !important; /* Set width for POS receipt */
                margin: 0 auto;
                padding: 0;
            }
            #printPreviewModal .modal-content {
                border: none;
                box-shadow: none;
                background: none;
                border-radius: 0;
            }
            #printPreviewModal .modal-header,
            #printPreviewModal .modal-footer {
                display: none; /* Hide header and footer of the modal itself */
            }
            #printPreviewModal .modal-body {
                padding: 0;
            }
            #receiptPrintArea {
                width: 100% !important;
                margin: 0;
                padding: 0; /* Remove padding for print to let @page margin handle it */
            }
            @page {
                margin: 5mm; /* Apply page margins for print */
            }
        }

        /* Add this CSS inside your <style> tag or in a separate CSS file */

        /* Main dark green color */
        :root {
            --main-green: #145a32;
            --light-green: #e6ffe6;
            --accent-green: #117a65;
        }

        /* Card and background adjustments */
        .card,

        .card-body,
        .content-wrapper,
        body {
            background-color: #ffffff !important; /* Changed to white */
            color: var(--main-green) !important;
        }

        /* Headings and titles */
        h1, h2, h3, h4, h5, h6,
        .card-title
         {
            color: var(--main-green) !important;
        }

        /* Table headers and cells */
        .table th,
        .table td,
        .table thead th {
            color: var(--main-green) !important;
            border-color: var(--main-green) !important;
            background-color: #ffffff !important; /* Changed to white */
        }

        /* Buttons */
        .btn-success,
        .btn-primary,
        .btn-info,
        .btn-secondary,
        .btn {
            background-color: var(--main-green) !important;
            border-color: var(--main-green) !important;
            color: #fff !important;
        }
        .btn-success:hover,
        .btn-primary:hover,
        .btn-info:hover,
        .btn-secondary:hover,
        .btn:hover {
            background-color: var(--accent-green) !important;
            border-color: var(--accent-green) !important;
            color: #fff !important;
        }

        /* Specifically for btn-danger to be red */
        .btn-danger {
            background-color: #dc3545 !important; /* Standard Bootstrap red */
            border-color: #dc3545 !important;
            color: #fff !important;
        }
        .btn-danger:hover {
            background-color: #c82333 !important; /* Slightly darker red on hover */
            border-color: #bd2130 !important;
        }


        /* Inputs and selects */
        .form-control,
        .select2-container--default .select2-selection--single {
            background-color: #f4fef6 !important;
            color: var(--main-green) !important;
            border-color: var(--main-green) !important;
        }
        .form-control:focus,
        .select2-container--default .select2-selection--single:focus {
            border-color: var(--accent-green) !important;
            box-shadow: 0 0 0 0.2rem rgba(20,90,50,0.15) !important;
        }

        /* Product/Test cards */
        .product-card,
        .test-type-card {
            border: 1px solid var(--main-green) !important;
            background: #ffffff !important; /* Changed to white */
            color: var(--main-green) !important;
        }
        .product-card .product-icon,
        .test-type-card .test-icon {
            color: var(--main-green) !important;
        }
        .product-card .price-display,
        .test-type-card .price-display {
            color: var(--accent-green) !important;
        }

        /* Breadcrumbs and nav */
        .breadcrumb,
        .breadcrumb-item,
        .breadcrumb-item.active,
        .breadcrumb-item a {
            color: var(--main-green) !important;
        }

        /* Modal header/footer */
        .modal-header,
        .modal-footer {
            background: #ffffff !important; /* Changed to white */
            color: var(--main-green) !important;
            border-color: var(--main-green) !important;
        }

        /* Receipt print area */
        #receiptPrintArea,
        #receiptPrintArea .receipt-header,
        #receiptPrintArea .receipt-footer,
        #receiptPrintArea .receipt-details th,
        #receiptPrintArea .receipt-details td,
        #receiptPrintArea .receipt-summary th,
        #receiptPrintArea .receipt-summary td {
            color: var(--main-green) !important;
        }

        /* SweetAlert2 buttons (override) */
        .swal2-confirm,
        .swal2-cancel,
        .swal2-styled {
            background-color: var(--main-green) !important;
            border: none !important;
        }

        /* Select2 dropdown */
        .select2-container--default .select2-selection--single {
            border-color: var(--main-green) !important;
            color: var(--main-green) !important;
        }
        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: var(--main-green) !important;
            color: #fff !important;
        }

        /* Remove borders for all tables */
        table,
        .table,
        .table th,
        .table td,
        .table thead th,
        .table-bordered,
        .table-bordered th,
        .table-bordered td {
            border: none !important;
            box-shadow: none !important;
        }

        /* New styles for the patient registration modal layout */
        .patient-form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }

        .patient-form-grid .form-group {
            margin-bottom: 0; /* Remove default form-group margin */
        }
        .patient-form-grid .input-full-width {
            grid-column: 1 / -1; /* Span full width */
        }
        .patient-form-grid .input-half-width {
            grid-column: span 1; /* Span half width */
        }

        /* Custom style for auto-generated fields */
        .auto-generated-field {
            background-color: #e9ecef !important; /* Light grey background */
            color: #6c757d !important; /* Muted text color */
            font-style: italic;
        }

        /* Adjust registration button (modal footer) */
        .modal-footer {
            padding-top: 20px; /* Add padding to push buttons down */
        }

        /* Adjust patient selection field size */
        #patient_id_select {
            min-height: 45px; /* Increase height */
            padding: 0.375rem 0.75rem; /* Adjust padding if needed */
            font-size: 1rem; /* Ensure readable font size */
        }
        /* Ensure select2 matches the input height */
        .select2-container--default .select2-selection--single {
            height: 45px !important;
            padding-top: 0.375rem !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 43px !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 38px !important;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

  <?php include_once('./config/header.php'); ?>
  <?php include_once('./config/sidebar.php'); ?>

  <div class="content-wrapper">
    <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark"><?php echo $page_title; ?></h1>
          </div><div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="#">Home</a></li>
              <li class="breadcrumb-item active"><?php echo $page_title; ?></li>
            </ol>
          </div></div></div></div>
    <div class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-md-7">
            <div class="card card-primary card-outline">
              <div class="card-header">
                <h3 class="card-title">New Bill</h3>
              </div>
              <div class="card-body">
                <div class="form-group">
                    <a href="patients.php" class="btn btn-info btn-sm float-right mb-2">
                        <i class="fas fa-plus"></i> Register New Patient
                    </a>
                </div>
                <div class="form-group">
                    <label for="patient_id_select">Select Patient:</label>
                    <div class="input-group">
                        <select id="patient_id_select" class="form-control select2" style="width: 100%;">
                            <option value="">-- Select Patient --</option>
                        </select>
                    </div>
                </div>

                <div class="order-table">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Product/Test</th>
                                <th style="width: 120px;">Qty</th>
                                <th>Price</th>
                                <th>Subtotal</th>
                                <th style="width: 50px;"></th>
                            </tr>
                        </thead>
                        <tbody id="order-items-body">
                            </tbody>
                    </table>
                </div>

                <div class="row mt-3">
                    <div class="col-md-12">
                        <table class="table table-sm text-right table-summary-3-cols">
                            <tbody>
                                <tr>
                                    <th style="width: 15%;">Total Items:</th>
                                    <td style="width: 18%;"><span id="total-items">0</span></td>

                                    <th style="width: 15%;">Total Amount:</th>
                                    <td style="width: 18%;">₦ <span id="grand-total">0.00</span></td>

                                    <th style="width: 15%;">Paid Amount:</th>
                                    <td style="width: 18%;">
                                        <input type="number" id="paid_amount_input" class="form-control form-control-sm text-right" value="0.00" min="0" step="0.01">
                                    </td>
                                </tr>
                                <tr>
                                    <th>Payment Type:</th>
                                    <td>
                                        <select id="payment_type_select" class="form-control form-control-sm">
                                            <option value="Cash">Cash</option>
                                            <option value="Card">Card</option>
                                            <option value="Transfer">Transfer</option>
                                            <option value="Credit">Credit</option>
                                        </select>
                                    </td>

                                    <th>Due Amount:</th>
                                    <td>₦ <span id="due_amount_display">0.00</span></td>
                                    </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="row mt-3">
                    <div class="col-12 text-right">
                        <button class="btn btn-success btn-lg" id="save_bill_btn"><i class="fas fa-save"></i> Save Bill</button>
                        <button class="btn btn-primary btn-lg d-none" id="update_bill_btn"><i class="fas fa-sync-alt"></i> Update Bill</button>
                        <button class="btn btn-info btn-lg d-none" id="print_bill_btn"><i class="fas fa-print"></i> Print Bill</button>
                        <button class="btn btn-danger btn-lg" id="clear_bill_btn"><i class="fas fa-eraser"></i> Clear Bill</button>
                    </div>
                </div>
              </div>
            </div>
          </div>

          <div class="col-md-5">
            <div class="card card-info card-outline">
              <div class="card-header">
                <h3 class="card-title">Items</h3>
              </div>
              <div class="card-body">
                <h4>Services / Products</h4>
                <div class="product-grid">
                    <?php foreach ($services as $id => $service): ?>
                        <div class="product-card"
                             data-item-type="service"
                             data-item-id="<?php echo htmlspecialchars($id); ?>"
                             data-item-name="<?php echo htmlspecialchars($service['name']); ?>"
                             data-item-price="<?php echo htmlspecialchars($service['price']); ?>">
                            <i class="product-icon <?php echo htmlspecialchars($service['icon_class'] ?? 'fa-solid fa-flask'); ?>"></i>
                            <div class="name"><?php echo htmlspecialchars($service['name']); ?></div>
                            <div class="price-display">₦ <?php echo number_format($service['price'], 2); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <a href="update_prices.php" class="manage-prices-link">Manage Prices</a>

                <h4 class="mt-4">Test Types</h4>
                <div class="test-type-grid">
                    <?php foreach ($testTypes as $id => $testType): ?>
                        <div class="test-type-card"
                             data-item-type="test_type"
                             data-item-id="<?php echo htmlspecialchars($id); ?>"
                             data-item-name="<?php echo htmlspecialchars($testType['name']); ?>"
                             data-item-price="<?php echo htmlspecialchars($testType['price']); ?>">
                            <i class="test-icon <?php echo htmlspecialchars($testType['icon_class'] ?? 'fa-solid fa-vial'); ?>"></i>
                            <div class="name"><?php echo htmlspecialchars($testType['name']); ?></div>
                            <div class="price-display">₦ <?php echo number_format($testType['price'], 2); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="printPreviewModal" tabindex="-1" role="dialog" aria-labelledby="printPreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="printPreviewModalLabel">Bill Receipt Preview</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="receiptPrintArea">
                    </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="printReceiptBtn">Print Receipt</button>
            </div>
        </div>
    </div>
</div>

<?php include_once('./config/footer.php'); ?>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    let currentEditingBillId = null;

    // Initialize Select2
    $('#patient_id_select').select2();

    function updateOrderTotals() {
        let totalItems = 0;
        let grandTotal = 0;

        $('#order-items-body tr').each(function() {
            totalItems++;
            const quantity = parseInt($(this).find('.quantity-input').val());
            const price = parseFloat($(this).data('item-price'));
            const subtotal = quantity * price;

            $(this).find('.subtotal-cell').text('₦ ' + subtotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
            grandTotal += subtotal;
        });

        $('#total-items').text(totalItems);
        $('#grand-total').text(grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

        updatePaidDueAmount();
    }

    function updatePaidDueAmount() {
        const grandTotal = parseFloat($('#grand-total').text().replace(/[^0-9.-]+/g,""));
        let paidAmount = parseFloat($('#paid_amount_input').val());

        if (isNaN(paidAmount) || paidAmount < 0) {
            paidAmount = 0;
            $('#paid_amount_input').val('0.00');
        }

        let dueAmount = grandTotal - paidAmount;

        $('#due_amount_display').text(dueAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

        let paymentStatus = 'Due';
        if (paidAmount >= grandTotal) {
            paymentStatus = 'Paid';
        } else if (paidAmount > 0 && paidAmount < grandTotal) {
            paymentStatus = 'Partially Paid';
        }
        // The payment status is now only determined for internal logic (saving to DB)
        // and no longer sets a UI element.
    }

    $(document).on('change', '.quantity-input', function() {
        updateOrderTotals();
    });

    $('#paid_amount_input').on('input', function() {
        updatePaidDueAmount();
    });

    $(document).on('click', '.remove-item-btn', function() {
        $(this).closest('tr').remove();
        updateOrderTotals();
    });

    function addItemToBill(itemId, itemName, itemPrice, itemType) {
        let itemExists = false;
        $('#order-items-body tr').each(function() {
            if ($(this).data('item-id') == itemId && $(this).data('item-type') == itemType) {
                const currentQuantityInput = $(this).find('.quantity-input');
                currentQuantityInput.val(parseInt(currentQuantityInput.val()) + 1);
                itemExists = true;
                return false;
            }
        });

        if (!itemExists) {
            const newRow = `
                <tr data-item-id="${itemId}" data-item-name="${itemName}" data-item-price="${itemPrice}" data-item-type="${itemType}">
                    <td>${itemName}</td>
                    <td>
                        <input type="number" class="form-control form-control-sm quantity-input" value="1" min="1">
                    </td>
                    <td>₦ ${itemPrice.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                    <td class="subtotal-cell">₦ ${itemPrice.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                    <td><button class="btn btn-danger btn-sm remove-item-btn"><i class="fas fa-times"></i></button></td>
                </tr>
            `;
            $('#order-items-body').append(newRow);
        }
        updateOrderTotals();
    }

    $(document).on('click', '.product-card, .test-type-card', function() {
        const itemId = $(this).data('item-id');
        const itemName = $(this).data('item-name');
        const itemPrice = parseFloat($(this).data('item-price'));
        const itemType = $(this).data('item-type');
        addItemToBill(itemId, itemName, itemPrice, itemType);
    });

    $('#clear_bill_btn').on('click', function() {
        Swal.fire({
            title: 'Are you sure?',
            text: "Do you want to clear the current bill?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, clear it!'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#order-items-body').empty();
                $('#patient_id_select').val('').trigger('change');
                $('#paid_amount_input').val('0.00'); // Reset paid amount
                currentEditingBillId = null;
                $('#save_bill_btn').removeClass('d-none');
                $('#update_bill_btn').addClass('d-none');
                $('#print_bill_btn').addClass('d-none'); // Hide print button
                updateOrderTotals();
                Swal.fire('Cleared!', 'The bill has been cleared.', 'success');
            }
        });
    });

    $('#save_bill_btn, #update_bill_btn').on('click', function() {
        const patientId = $('#patient_id_select').val();
        const totalAmount = parseFloat($('#grand-total').text().replace(/[^0-9.-]+/g,""));
        const paidAmount = parseFloat($('#paid_amount_input').val());
        const dueAmount = parseFloat($('#due_amount_display').text().replace(/[^0-9.-]+/g,""));

        let paymentStatus = 'Due'; // Determine payment status based on amounts
        if (paidAmount >= totalAmount) {
            paymentStatus = 'Paid';
        } else if (paidAmount > 0 && paidAmount < totalAmount) {
            paymentStatus = 'Partially Paid';
        }

        const paymentType = $('#payment_type_select').val();
        const userId = <?php echo json_encode($_SESSION['user_id'] ?? null); ?>;
        const actionType = $(this).attr('id') === 'save_bill_btn' ? 'save_bill' : 'save_bill'; // 'save_bill' covers both new and update via bill_id

        const billItems = [];
        $('#order-items-body tr').each(function() {
            billItems.push({
                id: $(this).data('item-id'),
                price: parseFloat($(this).data('item-price')),
                quantity: parseInt($(this).find('.quantity-input').val()),
                item_type: $(this).data('item-type')
            });
        });

        if (!patientId) {
            Swal.fire('Error', 'Please select a patient.', 'error');
            return;
        }
        if (billItems.length === 0) {
            Swal.fire('Error', 'Please add items to the bill.', 'error');
            return;
        }
        if (paidAmount > totalAmount) {
            Swal.fire('Error', 'Paid amount cannot exceed total amount.', 'error');
            return;
        }
        if (userId === null || userId === '') {
            Swal.fire('Authentication Error', 'User session not found. Please log in again.', 'error');
            return;
        }

        const dataToSend = {
            action: actionType,
            patient_id: patientId,
            total_amount: totalAmount,
            paid_amount: paidAmount,
            due_amount: dueAmount,
            payment_status: paymentStatus, // Send the calculated status
            payment_type: paymentType,
            user_id: userId,
            bill_items: JSON.stringify(billItems)
        };

        if (currentEditingBillId && actionType === 'save_bill') {
            dataToSend.bill_id = currentEditingBillId;
        }

        $.ajax({
            url: '<?php echo basename(__FILE__); ?>',
            type: 'POST',
            data: dataToSend,
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    Swal.fire({
                        title: 'Success!',
                        text: response.message,
                        icon: 'success',
                        timer: 2000,
                        timerProgressBar: true,
                        showConfirmButton: false
                    }).then(() => {
                        // Trigger print functionality after successful save
                        if (response.bill_id) { // Ensure bill_id is returned for new bills
                            printBillReceipt(response.bill_id);
                        } else if (currentEditingBillId) { // For updates, use currentEditingBillId
                            printBillReceipt(currentEditingBillId);
                        } else {
                            console.error('Bill ID not available for printing.');
                        }
                        // Redirect to manage_bills.php after the SweetAlert closes and print is initiated
                        window.location.href = 'manage_bills.php';
                    });
                } else {
                    Swal.fire('Error!', response.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                Swal.fire('AJAX Error!', 'Could not process bill. Server response: ' + xhr.responseText, 'error');
                console.error(xhr.responseText);
            }
        });
    });

    // Handle click for the new Print Bill button
    $('#print_bill_btn').on('click', function() {
        if (currentEditingBillId) {
            printBillReceipt(currentEditingBillId);
        } else {
            Swal.fire('Info', 'Please select or save a bill first to print.', 'info');
        }
    });

    function fetchAllPatients() {
        return $.ajax({ // Return the AJAX promise
            url: '<?php echo basename(__FILE__); ?>',
            type: 'POST',
            data: { action: 'fetch_all_patients' },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    const select = $('#patient_id_select');
                    select.empty().append('<option value="">-- Select Patient --</option>');
                    response.patients.forEach(patient => {
                        // --- MODIFICATION START ---
                        let patientIdentifier = patient.clinic_number;
                        if (patientIdentifier) {
                            // Regex to find and remove /YYYY/ pattern if it exists after KDCS/MUB/
                            // This handles IDs like "KDCS/MUB/2025/0001" and changes them to "KDCS/MUB/0001"
                            patientIdentifier = patientIdentifier.replace(/^(KDCS\/MUB\/)(\d{4}\/)(.*)$/, '$1$3');
                        } else {
                            // Fallback if clinic_number is null or empty, generating without the year
                            patientIdentifier = `KDCS/MUB/${String(patient.id).padStart(4, '0')}`;
                        }
                        // --- MODIFICATION END ---
                        select.append(`<option value="${patient.id}">${patient.patient_name} (${patientIdentifier})</option>`);
                    });
                    console.log('Patients fetched and populated:', response.patients); // Debugging line
                } else {
                    console.error('Error fetching patients:', response.message);
                    Swal.fire('Error', 'Error fetching patients: ' + response.message, 'error'); // Show error to user
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error fetching patients:', error, xhr.responseText);
                Swal.fire('AJAX Error!', 'Could not fetch patient list. Check console for details.', 'error'); // Show error to user
            }
        });
    }

    function fetchBillDetailsForEdit(billId) {
        $.ajax({
            url: '<?php echo basename(__FILE__); ?>',
            type: 'POST',
            data: { action: 'fetch_bill_details', bill_id: billId },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    const billHeader = response.bill_header;
                    const billItems = response.bill_items;

                    currentEditingBillId = billHeader.bill_id;

                    // Use a small timeout for Select2 to ensure it's fully rendered before setting value
                    setTimeout(() => {
                        $('#patient_id_select').val(billHeader.patient_id).trigger('change');
                    }, 50);

                    $('#paid_amount_input').val(parseFloat(billHeader.paid_amount).toFixed(2));
                    $('#payment_type_select').val(billHeader.payment_type);
                    // Removed: $('#payment_status_select_display').val(billHeader.payment_status);

                    $('#order-items-body').empty();
                    billItems.forEach(item => {
                        const itemType = item.item_type;
                        const itemId = item.item_id;
                        let itemName = 'Unknown Item';
                        let itemPrice = parseFloat(item.amount);

                        // Assuming services and testTypes are globally available from PHP
                        if (itemType === 'service' && window.services && window.services[itemId]) {
                            itemName = window.services[itemId].name;
                        } else if (itemType === 'test_type' && window.testTypes && window.testTypes[itemId]) {
                            itemName = window.testTypes[itemId].name;
                        }

                        const newRow = `
                            <tr data-item-id="${itemId}" data-item-name="${itemName}" data-item-price="${itemPrice}" data-item-type="${itemType}">
                                <td>${itemName}</td>
                                <td>
                                    <input type="number" class="form-control form-control-sm quantity-input" value="${item.quantity}" min="1">
                                </td>
                                <td>₦ ${itemPrice.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                                <td class="subtotal-cell">₦ ${(item.quantity * itemPrice).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                                <td><button class="btn btn-danger btn-sm remove-item-btn"><i class="fas fa-times"></i></button></td>
                            </tr>
                        `;
                        $('#order-items-body').append(newRow);
                    });
                    updateOrderTotals();

                    $('#save_bill_btn').addClass('d-none');
                    $('#update_bill_btn').removeClass('d-none');
                    $('#print_bill_btn').removeClass('d-none'); // Show print button
                } else {
                    Swal.fire('Error', 'Failed to load bill details: ' + response.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                Swal.fire('AJAX Error!', 'Could not fetch bill details: ' + error, 'error');
                console.error(xhr.responseText);
            }
        });
    }

    // Function to calculate age - This is now unused as patient modal is removed, but kept for reference
    function calculateAge(dobString) {
        if (!dobString) return '';
        const dob = new Date(dobString);
        const today = new Date();
        let age = today.getFullYear() - dob.getFullYear();
        const m = today.getMonth() - dob.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) {
            age--;
        }
        return age > 0 ? `${age} years` : (age === 0 ? 'Less than 1 year' : '');
    }

    // Modified to be a reusable function for printing, now showing a preview modal
    function printBillReceipt(billId) {
        if (!billId) {
            Swal.fire('Error', 'No bill ID provided for printing.', 'error');
            return;
        }

        $.ajax({
            url: '<?php echo basename(__FILE__); ?>',
            type: 'POST',
            data: { action: 'fetch_bill_details', bill_id: billId },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    const bill = response.bill_header;
                    const items = response.bill_items;

                    // Make services and testTypes globally accessible in the JS for the printing function
                    // These variables are already set in PHP and passed to the global window scope by your script.
                    // This line ensures they are available within this function's scope, though redundant if already global.
                    // window.services = <?php echo json_encode($services); ?>; // Already globally available from PHP
                    // window.testTypes = <?php echo json_encode($testTypes); ?>; // Already globally available from PHP

                    let receiptContent = `
                        <div class="receipt-header">
                            <h3>KLASSIQUE DIAGNOSTICS & CLINICAL SERVICES</h3>
                            <p>Mubi, Adamawa State</p>
                            <p>Phone: +234 814 856 4676</p>
                            <p>Email: careklas7@gmail.com</p>
                            <div class="divider"></div>
                        </div>
                        <div class="receipt-info">
                            <p><strong>Bill ID:</strong> ${bill.bill_id}</p>
                            <p><strong>Patient:</strong> ${bill.patient_name}</p>
                            <p><strong>Date:</strong> <?php echo date('Y-m-d H:i'); ?></p>
                            <div class="divider"></div>
                        </div>
                        <table class="receipt-details">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Qty</th>
                                    <th>Rate</th>
                                    <th>Amt</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;
                    items.forEach(item => {
                        let itemName = 'Unknown Item';
                        // Use the globally available services and testTypes for item name resolution
                        if (item.item_type === 'service' && typeof window.services !== 'undefined' && window.services[item.item_id]) {
                            itemName = window.services[item.item_id].name;
                        } else if (item.item_type === 'test_type' && typeof window.testTypes !== 'undefined' && window.testTypes[item.item_id]) {
                            itemName = window.testTypes[item.item_id].name;
                        }

                        const rate = parseFloat(item.amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        const itemAmount = (item.quantity * item.amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        receiptContent += `
                            <tr>
                                <td>${itemName}</td>
                                <td>${item.quantity}</td>
                                <td>${rate}</td>
                                <td>${itemAmount}</td>
                            </tr>
                        `;
                    });
                    receiptContent += `
                            </tbody>
                        </table>
                        <div class="divider"></div>
                        <table class="receipt-summary">
                            <tr><th>Total:</th><td>₦ ${parseFloat(bill.total_amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td></tr>
                            <tr><th>Paid:</th><td>₦ ${parseFloat(bill.paid_amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td></tr>
                            <tr><th>Due:</th><td>₦ ${parseFloat(bill.due_amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td></tr>
                            <tr><th>Status:</th><td>${bill.payment_status}</td></tr>
                            <tr><th>Payment Type:</th><td>${bill.payment_type}</td></tr>
                        </table>
                        <div class="divider"></div>
                        <p class="thank-you">Thank you for your patronage!</p>
                        <div class="receipt-footer">
                            <p>Software by Bity Iju</p>
                        </div>
                    `;

                    $('#receiptPrintArea').html(receiptContent);
                    $('#printPreviewModal').modal('show');

                } else {
                    Swal.fire('Error', 'Could not fetch bill details for printing: ' + response.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                Swal.fire('AJAX Error!', 'Could not fetch bill details for printing: ' + error, 'error');
            }
        });
    }

    // Handle print button click inside the preview modal
    $('#printReceiptBtn').on('click', function() {
        window.print();
    });

    updateOrderTotals();
    // Ensure patients are loaded on document ready and handle potential bill ID from URL
    fetchAllPatients().done(function() {
        const urlParams = new URLSearchParams(window.location.search);
        const billIdFromUrl = urlParams.get('bill_id');
        if (billIdFromUrl) {
            fetchBillDetailsForEdit(billIdFromUrl);
        }
    });
});
</script>
</body>
</html>