<?php
session_start(); // Add this line at the very top
if(!(isset($_SESSION['user_id']))) {
  header("location:index.php");
  exit;
}

include './config/connection.php';
include './common_service/common_functions.php'; // Ensure this file exists and contains getMedicines()

// --- AJAX Handlers ---

// Handle AJAX request to fetch all medicine details from database
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'fetch_medicine_details') {
    $response = ['status' => 'error', 'message' => '', 'medicine_details' => []];
    try {
        $stmt = $con->query("SELECT md.id, m.medicine_name, md.medicine_id, md.packing
                             FROM medicine_details AS md
                             JOIN medicines AS m ON md.medicine_id = m.id
                             ORDER BY m.medicine_name ASC, md.packing ASC");
        $medicine_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response['status'] = 'success';
        $response['medicine_details'] = $medicine_details;
    } catch (PDOException $e) {
        $response['message'] = 'Database error: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit();
}

// Handle AJAX request to add/update medicine detail (packing) in database
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && ($_POST['action'] == 'add_medicine_detail' || $_POST['action'] == 'update_medicine_detail')) {
    $medicine_detail_id = $_POST['medicine_detail_id'] ?? null;
    $medicine_id = $_POST['medicine_id'];
    $packing = trim($_POST['packing']);

    $response = ['status' => 'error', 'message' => ''];

    if (empty($medicine_id) || empty($packing)) {
        $response['message'] = 'Please select a medicine and provide packing details.';
        echo json_encode($response);
        exit();
    }

    try {
        if ($_POST['action'] == 'add_medicine_detail') {
            // Check for duplicate packing for the same medicine
            $check_stmt = $con->prepare("SELECT COUNT(*) FROM medicine_details WHERE medicine_id = ? AND packing = ?");
            $check_stmt->execute([$medicine_id, $packing]);
            if ($check_stmt->fetchColumn() > 0) {
                $response['message'] = 'This packing already exists for the selected medicine.';
                echo json_encode($response);
                exit();
            }

            $stmt = $con->prepare("INSERT INTO medicine_details (medicine_id, packing) VALUES (?, ?)");
            if ($stmt->execute([$medicine_id, $packing])) {
                $response['status'] = 'success';
                $response['message'] = 'Packing added successfully!';
            } else {
                $response['message'] = 'Failed to add packing.';
            }
        } else { // update_medicine_detail
            // Check for duplicate packing for the same medicine (excluding current medicine detail being updated)
            $check_stmt = $con->prepare("SELECT COUNT(*) FROM medicine_details WHERE medicine_id = ? AND packing = ? AND id != ?");
            $check_stmt->execute([$medicine_id, $packing, $medicine_detail_id]);
            if ($check_stmt->fetchColumn() > 0) {
                $response['message'] = 'This packing already exists for the selected medicine.';
                echo json_encode($response);
                exit();
            }

            $stmt = $con->prepare("UPDATE medicine_details SET medicine_id = ?, packing = ? WHERE id = ?");
            if ($stmt->execute([$medicine_id, $packing, $medicine_detail_id])) {
                $response['status'] = 'success';
                $response['message'] = 'Packing updated successfully!';
            } else {
                $response['message'] = 'Failed to update packing.';
            }
        }
    } catch (PDOException $e) {
        $response['message'] = 'Database error: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit();
}

// Handle AJAX request to delete medicine detail from database
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_medicine_detail') {
    $medicine_detail_id = $_POST['medicine_detail_id'];
    $response = ['status' => 'error', 'message' => ''];

    try {
        $stmt = $con->prepare("DELETE FROM medicine_details WHERE id = ?");
        if ($stmt->execute([$medicine_detail_id])) {
            $response['status'] = 'success';
            $response['message'] = 'Packing deleted successfully!';
        } else {
            $response['message'] = 'Failed to delete packing.';
        }
    } catch (PDOException $e) {
        $response['message'] = 'Database error: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit();
}

// --- HTML and Page Display ---

// Get medicines for the dropdown in the modal
$medicines_dropdown_options = getMedicines($con);

include_once('./config/site_css_links.php');
include_once('./config/data_tables_css.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medicine Details - Klassique Diagnostics</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>

        .content-wrapper {
            /* Adjusted padding-top from 56px to 30px */
            padding-top: 30px;
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
<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed">
<div class="wrapper">
  <?php include_once('./config/header.php'); ?>
  <?php include_once('./config/sidebar.php'); ?>

  <div class="content-wrapper">
    <section class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1>Medicine Details</h1>
          </div>
          <div class="col-sm-6 d-flex justify-content-end align-items-center">
            <ol class="breadcrumb float-sm-right ml-2">
              <li class="breadcrumb-item"><a href="#">Home</a></li>
              <li class="breadcrumb-item active">Medicine Details</li>
            </ol>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-md-12">
            <div class="card card-outline card-success shadow">
              <div class="card-header">
                <h3 class="card-title">Medicine Packings</h3>
                <button class="btn btn-success btn-sm float-right" data-toggle="modal" data-target="#medicineDetailModal" id="addMedicineDetailBtn"><i class="fas fa-plus"></i> Add New Packing</button>
              </div>
              <div class="card-body">
                <table id="medicineDetailsTable" class="table table-bordered table-striped">
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>Medicine Name</th>
                      <th>Packing</th>
                      <th>Action</th>
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
    </section>
  </div>
</div>

<div class="modal fade" id="medicineDetailModal" tabindex="-1" role="dialog" aria-labelledby="medicineDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="medicineDetailModalLabel">Add New Packing</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="medicineDetailForm">
                    <input type="hidden" id="medicine_detail_id" name="medicine_detail_id">
                    <div class="form-group">
                        <label for="medicine_id">Select Medicine</label>
                        <select id="medicine_id" name="medicine_id" class="form-control form-control-sm rounded-0" required="required">
                            <?php echo $medicines_dropdown_options;?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="packing">Packing</label>
                        <input type="text" class="form-control" id="packing" name="packing" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" id="saveMedicineDetailBtn">Save Packing</button>
            </div>
        </div>
    </div>
</div>

<?php include './config/footer.php'; ?>
<?php include './config/site_js_links.php'; ?>
<?php include './config/data_tables_js.php'; ?>
<script>
$(document).ready(function() {
    let medicineDetailsDataTable; // Declare it outside to keep its scope

    function fetchMedicineDetails() {
        $.ajax({
            url: '<?php echo basename(__FILE__); ?>',
            type: 'POST',
            data: { action: 'fetch_medicine_details' },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    if ($.fn.DataTable.isDataTable('#medicineDetailsTable')) { // Check if DataTable is already initialized
                        medicineDetailsDataTable.destroy(); // Destroy existing DataTable instance
                    }
                    $('#medicineDetailsTable tbody').empty();
                    response.medicine_details.forEach(detail => {
                        $('#medicineDetailsTable tbody').append(`
                            <tr>
                                <td>${detail.id}</td>
                                <td>${detail.medicine_name}</td>
                                <td>${detail.packing}</td>
                                <td>
                                    <button class="btn btn-success btn-sm edit-medicine-detail"
                                        data-id="${detail.id}"
                                        data-medicine-id="${detail.medicine_id}"
                                        data-packing="${detail.packing}">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="btn btn-danger btn-sm delete-medicine-detail" data-id="${detail.id}">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </td>
                            </tr>
                        `);
                    });

                    // Initialize DataTable after data is loaded and appended
                    medicineDetailsDataTable = $('#medicineDetailsTable').DataTable({
                        "paging": true,
                        "lengthChange": false,
                        "searching": true,
                        "ordering": true,
                        "info": true,
                        "autoWidth": false,
                        "responsive": true,
                        "order": [[1, 'asc'], [2, 'asc']], // Order by medicine name, then packing
                        "buttons": [ // Add buttons here
                            {
                                extend: 'print',
                                customize: function ( win ) {
                                    // Clinic details are no longer dynamically fetched from index.php
                                    // They will use default values or can be set here if needed.
                                    var clinicName = 'KDCS Clinic'; // Default value
                                    var clinicAddress = '123 Main St, City, Country'; // Default value
                                    var clinicEmail = 'info@kdcsclinic.com'; // Default value
                                    var clinicPhone = '+1234567890'; // Default value

                                    var logoHtml =
                                        '<img src="dist/img/logo.png" style="float: left; width: 120px; height: 120px; margin-right: 15px; border-radius: 50%; object-fit: cover;">' +
                                        '<img src="dist/img/logo2.png" style="float: right; width: 120px; height: 120px; margin-left: 15px; border-radius: 50%; object-fit: cover;">';

                                    var fullHeaderHtml =
                                        '<div style="text-align: center; margin-bottom: 20px; font-family: Arial, sans-serif; overflow: hidden; position: relative;">' +
                                            logoHtml +
                                            '<div style="display: inline-block; vertical-align: middle; max-width: calc(100% - 280px);">' +
                                                '<h2 style="margin: 0; padding: 0; color: #333;">' + clinicName + '</h2>' +
                                                '<p style="margin: 0; padding: 0; font-size: 0.9em; color: #555;">' + clinicAddress + '</p>' +
                                                '<p style="margin: 0; padding: 0; font-size: 0.9em; color: #555;"><i class="fas fa-envelope"></i> ' + clinicEmail + ' | <i class="fas fa-phone"></i> ' + clinicPhone + '</p>' +
                                            '</div>' +
                                            '<h3 style="margin-top: 20px; color: #000; clear: both;">Medicine Details Report</h3>' +
                                        '</div>';

                                    $(win.document.body).find( 'h1' ).remove();
                                    $(win.document.body).prepend(fullHeaderHtml);

                                    var newTable = '<table class="compact" style="font-size:inherit; width:100%; border-collapse: collapse;">';
                                    newTable += '<thead><tr>';
                                    newTable += '<th style="border: 1px solid #ccc; padding: 8px; text-align: left; width: 7%;">S.No</th>';
                                    newTable += '<th style="border: 1px solid #ccc; padding: 8px; text-align: left;">Medicine Name</th>';
                                    newTable += '<th style="border: 1px solid #ccc; padding: 8px; text-align: left;">Packing</th>';
                                    newTable += '</tr></thead><tbody>';

                                    var serialNum = 0;
                                    $('#medicineDetailsTable tbody tr').each(function(){
                                        serialNum++;
                                        var medicineName = $(this).find('td:eq(1)').text();
                                        var packing = $(this).find('td:eq(2)').text();

                                        newTable += '<tr>';
                                        newTable += '<td style="border: 1px solid #ccc; padding: 8px; text-align: center; width: 7%;">' + serialNum + '</td>';
                                        newTable += '<td style="border: 1px solid #ccc; padding: 8px;">' + medicineName + '</td>';
                                        newTable += '<td style="border: 1px solid #ccc; padding: 8px;">' + packing + '</td>';
                                        newTable += '</tr>';
                                    });
                                    newTable += '</tbody></table>';

                                    $(win.document.body).find('table').replaceWith(newTable);
                                    $(win.document.body).find('.dataTables_info, .dataTables_paginate, .dataTables_length, .dataTables_filter').remove();
                                }
                            },
                            "colvis"
                        ]
                    });
                    // Append buttons to the wrapper
                    medicineDetailsDataTable.buttons().container().appendTo('#medicineDetailsTable_wrapper .col-md-6:eq(0)');

                } else {
                    Swal.fire('Error', 'Failed to fetch medicine details: ' + response.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                Swal.fire('AJAX Error', 'Could not fetch medicine details: ' + error, 'error');
                console.error(xhr.responseText);
            }
        });
    }

    // Open Add Medicine Detail Modal
    $('#addMedicineDetailBtn').on('click', function() {
        $('#medicineDetailModalLabel').text('Add New Packing');
        $('#medicineDetailForm')[0].reset(); // Clear form
        $('#medicine_detail_id').val(''); // Clear hidden ID for add mode
        $('#saveMedicineDetailBtn').text('Save Packing');
        $('#medicine_id').prop('disabled', false); // Enable medicine selection for new entry
    });

    // Save Medicine Detail (Add/Edit)
    $('#saveMedicineDetailBtn').on('click', function() {
        const medicineDetailId = $('#medicine_detail_id').val();
        const medicineId = $('#medicine_id').val();
        const packing = $('#packing').val();

        if (medicineId === '' || packing.trim() === '') {
            Swal.fire('Validation Error', 'Please select a medicine and provide packing details.', 'error');
            return;
        }

        const action = medicineDetailId ? 'update_medicine_detail' : 'add_medicine_detail';
        const data = {
            action: action,
            medicine_id: medicineId,
            packing: packing
        };
        if (medicineDetailId) {
            data.medicine_detail_id = medicineDetailId;
        }

        $.ajax({
            url: '<?php echo basename(__FILE__); ?>',
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    Swal.fire('Success!', response.message, 'success');
                    $('#medicineDetailModal').modal('hide');
                    fetchMedicineDetails(); // Reload table
                } else {
                    Swal.fire('Error!', response.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                Swal.fire('AJAX Error!', 'Could not save packing: ' + error, 'error');
                console.error(xhr.responseText);
            }
        });
    });

    // Edit Medicine Detail
    $(document).on('click', '.edit-medicine-detail', function() {
        const medicineDetailId = $(this).data('id');
        const medicineId = $(this).data('medicine-id');
        const packing = $(this).data('packing');

        $('#medicineDetailModalLabel').text('Edit Packing');
        $('#medicine_detail_id').val(medicineDetailId);
        $('#medicine_id').val(medicineId).prop('disabled', true); // Disable medicine selection when editing
        $('#packing').val(packing);
        $('#saveMedicineDetailBtn').text('Update Packing');
        $('#medicineDetailModal').modal('show');
    });

    // Delete Medicine Detail
    $(document).on('click', '.delete-medicine-detail', function() {
        const medicineDetailId = $(this).data('id');
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
                    data: { action: 'delete_medicine_detail', medicine_detail_id: medicineDetailId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            Swal.fire('Deleted!', response.message, 'success');
                            fetchMedicineDetails(); // Reload table
                        } else {
                            Swal.fire('Error!', response.message, 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.fire('AJAX Error!', 'Could not delete packing: ' + error, 'error');
                        console.error(xhr.responseText);
                    }
                });
            }
        });
    });

    // Initial fetch on page load
    fetchMedicineDetails();

});
</script>
</body>
</html>