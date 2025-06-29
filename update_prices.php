<?php
session_start();
if(!(isset($_SESSION['user_id']))) {
  header("location:index.php");
  exit;
}

require_once 'config/connection.php'; // Include your database connection

// --- AJAX Handlers ---

// Handle AJAX request to fetch all services from services.json
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'fetch_services') {
    $jsonFile = 'services.json';
    $response = ['status' => 'error', 'message' => '', 'services' => []];

    if (file_exists($jsonFile)) {
        $jsonData = file_get_contents($jsonFile);
        $services = json_decode($jsonData, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $response['status'] = 'success';
            $response['services'] = $services;
        } else {
            $response['message'] = 'Error decoding services.json: ' . json_last_error_msg();
        }
    } else {
        $response['message'] = 'services.json not found.';
    }
    echo json_encode($response);
    exit();
}

// Handle AJAX request to add/update service in services.json
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && ($_POST['action'] == 'add_service' || $_POST['action'] == 'update_service')) {
    $jsonFile = 'services.json';
    $service_id = $_POST['service_id'] ?? null;
    $service_name = trim($_POST['service_name']);
    $service_price = floatval($_POST['service_price']);
    $icon_class = trim($_POST['icon_class']);

    $response = ['status' => 'error', 'message' => ''];
    $services = [];

    if (file_exists($jsonFile)) {
        $jsonData = file_get_contents($jsonFile);
        $services = json_decode($jsonData, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $response['message'] = 'Error decoding services.json.';
            echo json_encode($response);
            exit();
        }
    }

    if (empty($service_name) || $service_price < 0) {
        $response['message'] = 'Service name cannot be empty and price must be non-negative.';
        echo json_encode($response);
        exit();
    }

    if ($_POST['action'] == 'add_service') {
        // Generate new ID for service
        $new_id = 1;
        if (!empty($services)) {
            $ids = array_column($services, 'id');
            $new_id = max($ids) + 1;
        }
        $services[] = [
            'id' => $new_id,
            'name' => $service_name,
            'price' => $service_price,
            'icon_class' => $icon_class
        ];
        $response['message'] = 'Service added successfully!';
    } else { // update_service
        $found = false;
        foreach ($services as &$service) {
            if ($service['id'] == $service_id) {
                $service['name'] = $service_name;
                $service['price'] = $service_price;
                $service['icon_class'] = $icon_class;
                $found = true;
                break;
            }
        }
        unset($service); // Unset reference
        if ($found) {
            $response['message'] = 'Service updated successfully!';
        } else {
            $response['message'] = 'Service not found.';
            echo json_encode($response);
            exit();
        }
    }

    if (file_put_contents($jsonFile, json_encode($services, JSON_PRETTY_PRINT))) {
        $response['status'] = 'success';
    } else {
        $response['message'] = 'Failed to write to services.json.';
    }
    echo json_encode($response);
    exit();
}

// Handle AJAX request to delete service from services.json
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_service') {
    $jsonFile = 'services.json';
    $service_id = $_POST['service_id'];
    $response = ['status' => 'error', 'message' => ''];
    $services = [];

    if (file_exists($jsonFile)) {
        $jsonData = file_get_contents($jsonFile);
        $services = json_decode($jsonData, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $response['message'] = 'Error decoding services.json.';
            echo json_encode($response);
            exit();
        }
    }

    $initial_count = count($services);
    $services = array_filter($services, function($service) use ($service_id) {
        return $service['id'] != $service_id;
    });

    if (count($services) < $initial_count) {
        if (file_put_contents($jsonFile, json_encode($services, JSON_PRETTY_PRINT))) {
            $response['status'] = 'success';
            $response['message'] = 'Service deleted successfully!';
        } else {
            $response['message'] = 'Failed to write to services.json.';
        }
    } else {
        $response['message'] = 'Service not found.';
    }
    echo json_encode($response);
    exit();
}

// Handle AJAX request to fetch all test types from database
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'fetch_test_types') {
    $response = ['status' => 'error', 'message' => '', 'test_types' => []];
    try {
        $stmt = $con->query("SELECT test_id, test_name, price, icon_class FROM test_types ORDER BY test_name ASC");
        $test_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response['status'] = 'success';
        $response['test_types'] = $test_types;
    } catch (PDOException $e) {
        $response['message'] = 'Database error: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit();
}

// Handle AJAX request to add/update test type in database
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && ($_POST['action'] == 'add_test_type' || $_POST['action'] == 'update_test_type')) {
    $test_id = $_POST['test_id'] ?? null;
    $test_name = trim($_POST['test_name']);
    $price = floatval($_POST['price']);
    $icon_class = trim($_POST['icon_class']);

    $response = ['status' => 'error', 'message' => ''];

    if (empty($test_name) || $price < 0) {
        $response['message'] = 'Test name cannot be empty and price must be non-negative.';
        echo json_encode($response);
        exit();
    }

    try {
        if ($_POST['action'] == 'add_test_type') {
            $stmt = $con->prepare("INSERT INTO test_types (test_name, price, icon_class) VALUES (?, ?, ?)");
            if ($stmt->execute([$test_name, $price, $icon_class])) {
                $response['status'] = 'success';
                $response['message'] = 'Test type added successfully!';
            } else {
                $response['message'] = 'Failed to add test type.';
            }
        } else { // update_test_type
            $stmt = $con->prepare("UPDATE test_types SET test_name = ?, price = ?, icon_class = ? WHERE test_id = ?");
            if ($stmt->execute([$test_name, $price, $icon_class, $test_id])) {
                $response['status'] = 'success';
                $response['message'] = 'Test type updated successfully!';
            } else {
                $response['message'] = 'Failed to update test type.';
            }
        }
    } catch (PDOException $e) {
        $response['message'] = 'Database error: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit();
}

// Handle AJAX request to delete test type from database
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_test_type') {
    $test_id = $_POST['test_id'];
    $response = ['status' => 'error', 'message' => ''];

    try {
        $stmt = $con->prepare("DELETE FROM test_types WHERE test_id = ?");
        if ($stmt->execute([$test_id])) {
            $response['status'] = 'success';
            $response['message'] = 'Test type deleted successfully!';
        } else {
            $response['message'] = 'Failed to delete test type.';
        }
    } catch (PDOException $e) {
        $response['message'] = 'Database error: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit();
}


// --- HTML and Page Display ---

// Ensure these paths are correct for your project structure
include_once('./config/head.php');
include_once('./config/header.php');
include_once('./config/sidebar.php');

$page_title = "Manage Prices"; // For the page title
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Klassique Diagnostics</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* Reverted custom CSS for sidebar position to allow AdminLTE to manage it properly */
        /* Removed position: absolute; from .main-sidebar to prevent content overflow */
        /* Removed body.layout-fixed specific rules as they were redundant/problematic */

        .content-wrapper {
            padding-top: 56px; /* Keep initial padding for fixed header if applicable */
            /* AdminLTE's default CSS should handle margin-left based on sidebar state */
            /* If margin-left is still needed to push content, ensure it's not conflicting */
        }
        .dataTables_wrapper .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
            line-height: 1.5;
            border-radius: 0.2rem;
        }
        .card-header .btn {
            margin-left: 10px;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini">
<div class="wrapper">

  <?php include_once('./config/header.php'); ?>
  <?php include_once('./config/sidebar.php'); ?>

  <div class="content-wrapper">
    <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark"><?php echo $page_title; ?></h1>
          </div>
          <div class="col-sm-6 d-flex justify-content-end align-items-center">
            <a href="bill.php" class="btn btn-danger btn-sm"><i class="fas fa-arrow-left"></i> Back to Billing</a>
            <ol class="breadcrumb float-sm-right ml-2">
              <li class="breadcrumb-item"><a href="#">Home</a></li>
              <li class="breadcrumb-item active"><?php echo $page_title; ?></li>
            </ol>
          </div>
        </div>
      </div>
    </div>

    <div class="content">
      <div class="container-fluid">
        <div class="row">
            <div class="col-md-6">
                <div class="card card-info card-outline">
                    <div class="card-header">
                        <h3 class="card-title">Services</h3>
                        <button class="btn btn-info btn-sm float-right" data-toggle="modal" data-target="#serviceModal" id="addServiceBtn"><i class="fas fa-plus"></i> Add New Service</button>
                    </div>
                    <div class="card-body">
                        <table id="servicesTable" class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Price</th>
                                    <th>Icon</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card card-success card-outline">
                    <div class="card-header">
                        <h3 class="card-title">Test Types</h3>
                        <button class="btn btn-success btn-sm float-right" data-toggle="modal" data-target="#testTypeModal" id="addTestTypeBtn"><i class="fas fa-plus"></i> Add New Test Type</button>
                    </div>
                    <div class="card-body">
                        <table id="testTypesTable" class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Price</th>
                                    <th>Icon</th>
                                    <th>Actions</th>
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
  </div>
</div> <div class="modal fade" id="serviceModal" tabindex="-1" role="dialog" aria-labelledby="serviceModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="serviceModalLabel">Add New Service</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="serviceForm">
                    <input type="true" id="service_id" name="service_id">
                    <div class="form-group">
                        <label for="service_name">Service Name</label>
                        <input type="text" class="form-control" id="service_name" name="service_name" required>
                    </div>
                    <div class="form-group">
                        <label for="service_price">Price</label>
                        <input type="number" class="form-control" id="service_price" name="service_price" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label for="service_icon_class">Icon Class (e.g., fas fa-stethoscope)</label>
                        <input type="text" class="form-control" id="service_icon_class" name="icon_class" placeholder="fas fa-flask">
                        <small class="form-text text-muted">Find icons at <a href="https://fontawesome.com/icons" target="_blank">Font Awesome</a></small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-info" id="saveServiceBtn">Save Service</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="testTypeModal" tabindex="-1" role="dialog" aria-labelledby="testTypeModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="testTypeModalLabel">Add New Test Type</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="testTypeForm">
                    <input type="true" id="test_id" name="test_id">
                    <div class="form-group">
                        <label for="test_name">Test Type Name</label>
                        <input type="text" class="form-control" id="test_name" name="test_name" required>
                    </div>
                    <div class="form-group">
                        <label for="test_price">Price</label>
                        <input type="number" class="form-control" id="test_price" name="price" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label for="test_icon_class">Icon Class (e.g., fas fa-vial)</label>
                        <input type="text" class="form-control" id="test_icon_class" name="icon_class" placeholder="fas fa-vial">
                        <small class="form-text text-muted">Find icons at <a href="https://fontawesome.com/icons" target="_blank">Font Awesome</a></small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" id="saveTestTypeBtn">Save Test Type</button>
            </div>
        </div>
    </div>
</div>


<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    let servicesDataTable;
    let testTypesDataTable;

    // --- Service Functions ---

    function fetchServices() {
        $.ajax({
            url: '<?php echo basename(__FILE__); ?>',
            type: 'POST',
            data: { action: 'fetch_services' },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    if (servicesDataTable) {
                        servicesDataTable.destroy();
                    }
                    $('#servicesTable tbody').empty();
                    response.services.forEach(service => {
                        $('#servicesTable tbody').append(`
                            <tr>
                                <td>${service.id}</td>
                                <td>${service.name}</td>
                                <td>₦ ${parseFloat(service.price).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                                <td><i class="${service.icon_class}"></i></td>
                                <td>
                                    <button class="btn btn-info btn-sm edit-service"
                                        data-id="${service.id}"
                                        data-name="${service.name}"
                                        data-price="${service.price}"
                                        data-icon="${service.icon_class}">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="btn btn-danger btn-sm delete-service" data-id="${service.id}">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </td>
                            </tr>
                        `);
                    });
                    servicesDataTable = $('#servicesTable').DataTable({
                        "paging": true,
                        "lengthChange": false,
                        "searching": true,
                        "ordering": true,
                        "info": true,
                        "autoWidth": false,
                        "responsive": true,
                        "order": [[1, 'asc']] // Order by name column by default
                    });
                } else {
                    Swal.fire('Error', 'Failed to fetch services: ' + response.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                Swal.fire('AJAX Error', 'Could not fetch services: ' + error, 'error');
                console.error(xhr.responseText);
            }
        });
    }

    // Open Add Service Modal
    $('#addServiceBtn').on('click', function() {
        $('#serviceModalLabel').text('Add New Service');
        $('#serviceForm')[0].reset(); // Clear form
        $('#service_id').val(''); // Clear hidden ID for add mode
        $('#saveServiceBtn').text('Save Service');
    });

    // Save Service (Add/Edit)
    $('#saveServiceBtn').on('click', function() {
        const serviceId = $('#service_id').val();
        const serviceName = $('#service_name').val();
        const servicePrice = $('#service_price').val();
        const serviceIcon = $('#service_icon_class').val();

        if (serviceName === '' || servicePrice === '' || parseFloat(servicePrice) < 0) {
            Swal.fire('Validation Error', 'Please fill in all required fields and ensure price is valid.', 'error');
            return;
        }

        const action = serviceId ? 'update_service' : 'add_service';
        const data = {
            action: action,
            service_name: serviceName,
            service_price: servicePrice,
            icon_class: serviceIcon
        };
        if (serviceId) {
            data.service_id = serviceId;
        }

        $.ajax({
            url: '<?php echo basename(__FILE__); ?>',
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    Swal.fire('Success!', response.message, 'success');
                    $('#serviceModal').modal('hide');
                    fetchServices(); // Reload table
                } else {
                    Swal.fire('Error!', response.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                Swal.fire('AJAX Error!', 'Could not save service: ' + error, 'error');
                console.error(xhr.responseText);
            }
        });
    });

    // Edit Service
    $(document).on('click', '.edit-service', function() {
        const serviceId = $(this).data('id');
        const serviceName = $(this).data('name');
        const servicePrice = $(this).data('price');
        const serviceIcon = $(this).data('icon');

        $('#serviceModalLabel').text('Edit Service');
        $('#service_id').val(serviceId);
        $('#service_name').val(serviceName);
        $('#service_price').val(servicePrice);
        $('#service_icon_class').val(serviceIcon);
        $('#saveServiceBtn').text('Update Service');
        $('#serviceModal').modal('show');
    });

    // Delete Service
    $(document).on('click', '.delete-service', function() {
        const serviceId = $(this).data('id');
        Swal.fire({
            title: 'Are you sure?',
            text: "You won't be able to revert this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: '<?php echo basename(__FILE__); ?>',
                    type: 'POST',
                    data: { action: 'delete_service', service_id: serviceId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            Swal.fire('Deleted!', response.message, 'success');
                            fetchServices(); // Reload table
                        } else {
                            Swal.fire('Error!', response.message, 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.fire('AJAX Error!', 'Could not delete service: ' + error, 'error');
                        console.error(xhr.responseText);
                    }
                });
            }
        });
    });

    // --- Test Type Functions ---

    function fetchTestTypes() {
        $.ajax({
            url: '<?php echo basename(__FILE__); ?>',
            type: 'POST',
            data: { action: 'fetch_test_types' },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    if (testTypesDataTable) {
                        testTypesDataTable.destroy();
                    }
                    $('#testTypesTable tbody').empty();
                    response.test_types.forEach(testType => {
                        $('#testTypesTable tbody').append(`
                            <tr>
                                <td>${testType.test_id}</td>
                                <td>${testType.test_name}</td>
                                <td>₦ ${parseFloat(testType.price).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                                <td><i class="${testType.icon_class}"></i></td>
                                <td>
                                    <button class="btn btn-success btn-sm edit-test-type"
                                        data-id="${testType.test_id}"
                                        data-name="${testType.test_name}"
                                        data-price="${testType.price}"
                                        data-icon="${testType.icon_class}">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="btn btn-danger btn-sm delete-test-type" data-id="${testType.test_id}">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </td>
                            </tr>
                        `);
                    });
                    testTypesDataTable = $('#testTypesTable').DataTable({
                        "paging": true,
                        "lengthChange": false,
                        "searching": true,
                        "ordering": true,
                        "info": true,
                        "autoWidth": false,
                        "responsive": true,
                        "order": [[1, 'asc']] // Order by name column by default
                    });
                } else {
                    Swal.fire('Error', 'Failed to fetch test types: ' + response.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                Swal.fire('AJAX Error', 'Could not fetch test types: ' + error, 'error');
                console.error(xhr.responseText);
            }
        });
    }

    // Open Add Test Type Modal
    $('#addTestTypeBtn').on('click', function() {
        $('#testTypeModalLabel').text('Add New Test Type');
        $('#testTypeForm')[0].reset(); // Clear form
        $('#test_id').val(''); // Clear hidden ID for add mode
        $('#saveTestTypeBtn').text('Save Test Type');
    });

    // Save Test Type (Add/Edit)
    $('#saveTestTypeBtn').on('click', function() {
        const testId = $('#test_id').val();
        const testName = $('#test_name').val();
        const testPrice = $('#test_price').val();
        const testIcon = $('#test_icon_class').val();

        if (testName === '' || testPrice === '' || parseFloat(testPrice) < 0) {
            Swal.fire('Validation Error', 'Please fill in all required fields and ensure price is valid.', 'error');
            return;
        }

        const action = testId ? 'update_test_type' : 'add_test_type';
        const data = {
            action: action,
            test_name: testName,
            price: testPrice,
            icon_class: testIcon
        };
        if (testId) {
            data.test_id = testId;
        }

        $.ajax({
            url: '<?php echo basename(__FILE__); ?>',
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    Swal.fire('Success!', response.message, 'success');
                    $('#testTypeModal').modal('hide');
                    fetchTestTypes(); // Reload table
                } else {
                    Swal.fire('Error!', response.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                Swal.fire('AJAX Error!', 'Could not save test type: ' + error, 'error');
                console.error(xhr.responseText);
            }
        });
    });

    // Edit Test Type
    $(document).on('click', '.edit-test-type', function() {
        const testId = $(this).data('id');
        const testName = $(this).data('name');
        const testPrice = $(this).data('price');
        const testIcon = $(this).data('icon');

        $('#testTypeModalLabel').text('Edit Test Type');
        $('#test_id').val(testId);
        $('#test_name').val(testName);
        $('#test_price').val(testPrice);
        $('#test_icon_class').val(testIcon);
        $('#saveTestTypeBtn').text('Update Test Type');
        $('#testTypeModal').modal('show');
    });

    // Delete Test Type
    $(document).on('click', '.delete-test-type', function() {
        const testId = $(this).data('id');
        Swal.fire({
            title: 'Are you sure?',
            text: "You won't be able to revert this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: '<?php echo basename(__FILE__); ?>',
                    type: 'POST',
                    data: { action: 'delete_test_type', test_id: testId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            Swal.fire('Deleted!', response.message, 'success');
                            fetchTestTypes(); // Reload table
                        } else {
                            Swal.fire('Error!', response.message, 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.fire('AJAX Error!', 'Could not delete test type: ' + error, 'error');
                        console.error(xhr.responseText);
                    }
                });
            }
        });
    });

    // Initial fetch on page load
    fetchServices();
    fetchTestTypes();
});
</script>
</body>
</html>