<?php
session_start();
include './config/connection.php';

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
    if (preg_match('/<hr>\s*<h3>Caring for a BETTER Life\?<\/h3>\s*<p>.*?<\/p>\s*<p><strong>Email:\s*<\/strong>(.*?)\s*\|\s*<strong>Office No.:<\/strong>(.*?)\s*\|\s*<strong>Doctor\'s No.:\s*<\\/strong>(.*?)<\/p>/s', $index_content, $matches_contact)) {
        $clinic_email = trim($matches_contact[1]);
        $clinic_phone = trim($matches_contact[2]) . " / " . trim($matches_contact[3]);
    }
}
// --- END Fetch Clinic Details ---

$message = '';

// --- Handle AJAX Update Request ---
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_medicine') {
    // Enable error reporting for debugging (REMOVE IN PRODUCTION)
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    header('Content-Type: application/json');

    $response = ['status' => 'error', 'message' => ''];

    if (isset($_POST['id'], $_POST['medicine_name'], $_POST['stock_quantity'], $_POST['generic_name'], $_POST['status'], $_POST['expiry_date'])) {
        $medicineId = trim($_POST['id']);
        $medicineName = trim($_POST['medicine_name']);
        $stockQuantity = trim($_POST['stock_quantity']);
        $genericName = trim($_POST['generic_name']);
        $status = trim($_POST['status']);
        $expiryDate = trim($_POST['expiry_date']);

        if (empty($medicineId) || empty($medicineName) || empty($stockQuantity) || empty($genericName) || empty($status) || empty($expiryDate)) {
            $response['message'] = 'All fields (including ID) are required for update.';
            echo json_encode($response);
            exit;
        }

        try {
            $con->beginTransaction();

            $query = "UPDATE `medicines` SET `medicine_name` = :medicine_name, `stock_quantity` = :stock_quantity, `generic_name` = :generic_name, `status` = :status, `expiry_date` = :expiry_date WHERE `id` = :id";
            $stmt = $con->prepare($query);
            $stmt->execute([
                ':medicine_name' => ucwords(strtolower($medicineName)),
                ':stock_quantity' => $stockQuantity,
                ':generic_name' => $genericName,
                ':status' => $status,
                ':expiry_date' => $expiryDate,
                ':id' => $medicineId
            ]);

            $con->commit();
            $response['status'] = 'success';
            $response['message'] = 'Medicine updated successfully.';

        } catch (PDOException $ex) {
            $con->rollback();
            $response['message'] = 'Database update error: ' . $ex->getMessage() . ' SQLSTATE: ' . $ex->getCode();
        }
    } else {
        $response['message'] = 'Invalid request. Missing required POST data.';
    }

    echo json_encode($response);
    exit; // Exit after handling AJAX request
}
// --- END Handle AJAX Update Request ---


// --- Handle Form Submission for Adding New Medicine ---
if(isset($_POST['save_medicine'])) {
  $message = '';
  $medicineName = trim($_POST['medicine_name']);
  $medicineName = ucwords(strtolower($medicineName));

  $stockQuantity = trim($_POST['stock_quantity']);
  $genericName = trim($_POST['generic_name']);
  $status = trim($_POST['status']);
  $expiryDate = trim($_POST['expiry_date']);


  if($medicineName != '' && $stockQuantity != '' && $genericName != '' && $status != '' && $expiryDate != '') {
   $query = "INSERT INTO `medicines`(`medicine_name`, `stock_quantity`, `generic_name`, `status`, `expiry_date`) VALUES(:medicine_name, :stock_quantity, :generic_name, :status, :expiry_date)";

   try {

    $con->beginTransaction();

    $stmtMedicine = $con->prepare($query);
    $stmtMedicine->execute([
        ':medicine_name' => $medicineName,
        ':stock_quantity' => $stockQuantity,
        ':generic_name' => $genericName,
        ':status' => $status,
        ':expiry_date' => $expiryDate
    ]);

    $con->commit();

    $message = 'Medicine added successfully.';
  }catch(PDOException $ex) {
   $con->rollback();

   echo $ex->getMessage();
   echo $ex->getTraceAsString();
   exit;
 }

} else {
 $message = 'All fields are required. Empty form can not be submitted.';
}
header("Location:congratulation.php?goto_page=medicines.php&message=$message");
exit;
}
// --- END Handle Form Submission for Adding New Medicine ---

// --- Fetch All Medicines for Display ---
try {
  $query = "select `id`, `medicine_name`, `stock_quantity`, `generic_name`, `status`, `expiry_date` from `medicines`
  order by `medicine_name` asc;";
  $stmt = $con->prepare($query);
  $stmt->execute();

} catch(PDOException $ex) {
  echo $ex->getMessage();
  echo $e->getTraceAsString();
  exit;
}
// --- END Fetch All Medicines for Display ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
 <?php include './config/site_css_links.php';?>


 <?php include './config/data_tables_css.php';?>
 <title>Medicines - Clinic's Patient Management System in PHP</title>
 <style>
    .content-wrapper {
        padding-top: 56px;
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

    /* Custom style for dark green button */
    .btn-dark-green {
        background-color: #006400; /* Dark Green */
        border-color: #006400;
        color: #fff;
    }
    .btn-dark-green:hover {
        background-color: #004d00; /* Slightly darker green on hover */
        border-color: #004d00;
        color: #fff;
    }
 </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed">
  <div class="wrapper">
    <?php include './config/header.php';
include './config/sidebar.php';?>
    <div class="content-wrapper">
      <section class="content-header">
        <div class="container-fluid">
          <div class="row mb-2">
            <div class="col-sm-6">
              <h1>Medicines</h1>
            </div>
          </div>
        </div></section>
      <section class="content">
        <div class="card card-outline card-success rounded-0 shadow">
          <div class="card-header">
            <h3 class="card-title">Add Medicine</h3>
            <div class="card-tools">
              <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
                <i class="fas fa-minus"></i>
              </button>
            </div>
          </div>
          <div class="card-body">
            <form method="post">
             <div class="row">
              <div class="col-lg-3 col-md-4 col-sm-4 col-xs-10">
                <label>Medicine Name</label>
                <input type="text" id="medicine_name" name="medicine_name" required="required"
                class="form-control form-control-sm rounded-0" />
              </div>
              <div class="col-lg-1 col-md-4 col-sm-4 col-xs-10">
                <label>Stock Quantity</label>
                <input type="number" id="stock_quantity" name="stock_quantity" required="required" min="0"
                class="form-control form-control-sm rounded-0" />
              </div>
              <div class="col-lg-2 col-md-4 col-sm-4 col-xs-10 ">
                <label>Generic Name</label>
                <input type="text" id="generic_name" name="generic_name" required="required"
                class="form-control form-control-sm rounded-0" />
              </div>
              <div class="col-lg-2 col-md-4 col-sm-4 col-xs-10 ">
                <label>Status</label>
                <select id="status" name="status" required="required"
                class="form-control form-control-sm rounded-0">
                    <option value="">--Select Status--</option>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
              </div>
              <div class="col-lg-2 col-md-4 col-sm-4 col-xs-10 ">
                <label>Expiry Date</label>
                <input type="date" id="expiry_date" name="expiry_date" required="required"
                class="form-control form-control-sm rounded-0" />
              </div>
              <div class="col-lg-2 col-md-2 col-sm-2 col-xs-2 ">
                <label>&nbsp;</label>
                <button type="submit" id="save_medicine"
                name="save_medicine" class="btn btn-success btn-sm btn-flat btn-block">Add Medicine</button>
              </div>
            </div>
          </form>
        </div>

      </div>
      </section>
    <section class="content">
      <div class="card card-outline card-success rounded-0 shadow">
        <div class="card-header">
          <h3 class="card-title">All Medicines</h3>

          <div class="card-tools">
            <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
              <i class="fas fa-minus"></i>
            </button>

          </div>
        </div>
        <div class="card-body">
         <div class="row table-responsive">

          <table id="all_medicines_table" class="table table-striped dataTable table-bordered dtr-inline"
          role="grid" aria-describedby="all_medicines_info">
          <colgroup>
            <col width="5%"> <col width="20%"> <col width="15%"> <col width="25%"> <col width="10%"> <col width="15%"> <col width="10%"> </colgroup>

          <thead>
            <tr>
             <th class="text-center">S.No</th>
             <th>Medicine Name</th>
             <th>Stock Quantity</th>
             <th>Generic Name</th>
             <th>Status</th>
             <th>Expiry Date</th>
             <th class="text-center">Action</th>
           </tr>
         </thead>

         <tbody>
          <?php
          $serial = 0;
          while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
           $serial++;
           ?>
           <tr>
             <td class="text-center"><?php echo $serial;?></td>
             <td><?php echo $row['medicine_name'];?></td>
             <td><?php echo $row['stock_quantity'];?></td>
             <td><?php echo $row['generic_name'];?></td>
             <td><?php echo $row['status'];?></td>
             <td><?php echo $row['expiry_date'];?></td>
             <td class="text-center">
              <button type="button" class="btn btn-success btn-sm btn-flat edit-medicine" data-id="<?php echo $row['id'];?>" data-toggle="modal" data-target="#editMedicineModal">
               <i class="fa fa-edit"></i>
             </button>
             <button class="btn btn-danger btn-sm btn-flat delete-medicine" data-id="<?php echo $row['id'];?>">
                <i class="fa fa-trash"></i>
             </button>
           </td>
         </tr>
       <?php } ?>
     </tbody>
   </table>
 </div>
</div>

</div>
</section>
</div>
<?php
include './config/footer.php';

$message = '';
if(isset($_GET['message'])) {
  $message = $_GET['message'];
}
?>
</div>

<div class="modal fade" id="editMedicineModal" tabindex="-1" role="dialog" aria-labelledby="editMedicineModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content rounded-0">
      <div class="modal-header">
        <h5 class="modal-title" id="editMedicineModalLabel">Edit Medicine</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <form id="editMedicineForm">
          <input type="hidden" id="edit_medicine_id" name="id">
          <input type="hidden" name="action" value="update_medicine">
          <div class="row">
            <div class="col-lg-4 col-md-6 col-sm-12">
              <label>Medicine Name</label>
              <input type="text" id="edit_medicine_name" name="medicine_name" required="required"
                     class="form-control form-control-sm rounded-0" />
            </div>
            <div class="col-lg-2 col-md-6 col-sm-12">
              <label>Stock Quantity</label>
              <input type="number" id="edit_stock_quantity" name="stock_quantity" required="required" min="0"
                     class="form-control form-control-sm rounded-0" />
            </div>
            <div class="col-lg-3 col-md-6 col-sm-12">
              <label>Generic Name</label>
              <input type="text" id="edit_generic_name" name="generic_name" required="required"
                     class="form-control form-control-sm rounded-0" />
            </div>
            <div class="col-lg-3 col-md-6 col-sm-12">
              <label>Status</label>
              <select id="edit_status" name="status" required="required"
                      class="form-control form-control-sm rounded-0">
                  <option value="">--Select Status--</option>
                  <option value="Active">Active</option>
                  <option value="Inactive">Inactive</option>
              </select>
            </div>
            <div class="col-lg-4 col-md-6 col-sm-12 mt-3">
              <label>Expiry Date</label>
              <input type="date" id="edit_expiry_date" name="expiry_date" required="required"
                     class="form-control form-control-sm rounded-0" />
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary rounded-0" data-dismiss="modal">Close</button>
        <button type="button" class="btn btn-dark-green rounded-0" id="updateMedicineBtn">Save Changes</button>
      </div>
    </div>
  </div>
</div>
<?php include './config/site_js_links.php'; ?>
<?php include './config/data_tables_js.php'; ?>


<script>
  showMenuSelected("#mnu_medicines", "#mi_medicines");

  var message = '<?php echo $message;?>';

  if(message !== '') {
    showCustomMessage(message);
  }

  $(function () {
    $("#all_medicines_table").DataTable({ "responsive": true, "lengthChange": false, "autoWidth": false,
      "buttons": [
        {
          extend: 'print',
          customize: function ( win ) {
            var clinicName = '<?php echo htmlspecialchars(strtoupper($clinic_name)); ?>';
            var clinicAddress = '<?php echo htmlspecialchars($clinic_address); ?>';
            var clinicEmail = '<?php echo htmlspecialchars($clinic_email); ?>';
            var clinicPhone = '<?php echo htmlspecialchars($clinic_phone); ?>';

            // Prepare logo HTML
            var logoHtml =
                '<img src="dist/img/logo.png" style="float: left; width: 120px; height: 120px; margin-right: 15px; border-radius: 50%; object-fit: cover;">' +
                '<img src="dist/img/logo2.png" style="float: right; width: 120px; height: 120px; margin-left: 15px; border-radius: 50%; object-fit: cover;">';


            // Construct the full header HTML
            var fullHeaderHtml =
                '<div style="text-align: center; margin-bottom: 20px; font-family: Arial, sans-serif; overflow: hidden; position: relative;">' +
                    logoHtml +
                    '<div style="display: inline-block; vertical-align: middle; max-width: calc(100% - 280px);">' +
                        '<h2 style="margin: 0; padding: 0; color: #333;">' + clinicName + '</h2>' +
                        '<p style="margin: 0; padding: 0; font-size: 0.9em; color: #555;">' + clinicAddress + '</p>' +
                        '<p style="margin: 0; padding: 0; font-size: 0.9em; color: #555;"><i class="fas fa-envelope"></i> ' + clinicEmail + ' | <i class="fas fa-phone"></i> ' + clinicPhone + '</p>' +
                    '</div>' +
                    '<h3 style="margin-top: 20px; color: #000; clear: both;">All Medicines Report</h3>' +
                '</div>';


            $(win.document.body).find( 'h1' ).remove();
            $(win.document.body).prepend(fullHeaderHtml);

            // Apply column-count to the body for two-column layout
            $(win.document.body).css({
                // Removed column-count as it was not explicitly requested to be re-added
            });

            // Re-create the table content with S.No., Medicine Name, Stock, Generic, Status, Expiry Date
            var newTable = '<table class="compact" style="font-size:inherit; width:100%; border-collapse: collapse;">';
            newTable += '<thead><tr>';
            newTable += '<th style="border: 1px solid #ccc; padding: 8px; text-align: left; width: 5%;">S.No</th>';
            newTable += '<th style="border: 1px solid #ccc; padding: 8px; text-align: left; width: 20%;">Medicine Name</th>';
            newTable += '<th style="border: 1px solid #ccc; padding: 8px; text-align: left; width: 15%;">Stock Quantity</th>';
            newTable += '<th style="border: 1px solid #ccc; padding: 8px; text-align: left; width: 25%;">Generic Name</th>';
            newTable += '<th style="border: 1px solid #ccc; padding: 8px; text-align: left; width: 10%;">Status</th>';
            newTable += '<th style="border: 1px solid #ccc; padding: 8px; text-align: left; width: 15%;">Expiry Date</th>';
            newTable += '</tr></thead><tbody>';

            var serialNum = 0;
            $(win.document.body).find('table tbody tr').each(function(){
                serialNum++;
                var medicineName = $(this).find('td:eq(1)').text();
                var stockQuantity = $(this).find('td:eq(2)').text();
                var genericName = $(this).find('td:eq(3)').text();
                var status = $(this).find('td:eq(4)').text();
                var expiryDate = $(this).find('td:eq(5)').text();

                newTable += '<tr>';
                newTable += '<td style="border: 1px solid #ccc; padding: 8px; text-align: center; width: 5%;">' + serialNum + '</td>';
                newTable += '<td style="border: 1px solid #ccc; padding: 8px; width: 20%;">' + medicineName + '</td>';
                newTable += '<td style="border: 1px solid #ccc; padding: 8px; width: 15%;">' + stockQuantity + '</td>';
                newTable += '<td style="border: 1px solid #ccc; padding: 8px; width: 25%;">' + genericName + '</td>';
                newTable += '<td style="border: 1px solid #ccc; padding: 8px; width: 10%;">' + status + '</td>';
                newTable += '<td style="border: 1px solid #ccc; padding: 8px; width: 15%;">' + expiryDate + '</td>';
                newTable += '</tr>';
            });
            newTable += '</tbody></table>';

            // Replace the original table with the new one
            $(win.document.body).find('table').replaceWith(newTable);

            // Optionally, remove the DataTables search/pagination controls for print
            $(win.document.body).find('.dataTables_info, .dataTables_paginate, .dataTables_length, .dataTables_filter').remove();
          }
        },
        "colvis"
      ]
    }).buttons().container().appendTo('#all_medicines_table_wrapper .col-md-6:eq(0)');

  });

  $(document).ready(function() {

    $("#medicine_name").blur(function() {
      var medicineName = $(this).val().trim();
      $(this).val(medicineName);

      if(medicineName !== '') {
        $.ajax({
          url: "ajax/check_medicine_name.php",
          type: 'GET',
          data: {
            'medicine_name': medicineName
          },
          cache:false,
          async:false,
          success: function (count, status, xhr) {
            if(count > 0) {
              showCustomMessage("This medicine name has already been stored. Please choose another name");
              $("#save_medicine").attr("disabled", "disabled");
            } else {
              $("#save_medicine").removeAttr("disabled");
            }
          },
          error: function (jqXhr, textStatus, errorMessage) {
            showCustomMessage(errorMessage);
          }
        });
      }

    });

    // JavaScript for Delete Confirmation
    $(document).on('click', '.delete-medicine', function() {
        const medicineId = $(this).data('id');
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
                    url: 'ajax/delete_medicine.php', // Assuming this file exists and works
                    type: 'POST',
                    data: { id: medicineId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            Swal.fire('Deleted!', response.message, 'success');
                            // Reload the page to reflect changes
                            location.reload();
                        } else {
                            Swal.fire('Error!', response.message, 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.fire('AJAX Error!', 'Could not delete medicine: ' + error, 'error');
                        console.error(xhr.responseText); // Log the full error response
                    }
                });
            }
        });
    });

    // Handle click on edit medicine button
    $(document).on('click', '.edit-medicine', function() {
        const medicineId = $(this).data('id');
        console.log('Edit button clicked for ID:', medicineId); // Debugging: Check if ID is captured

        $.ajax({
            url: 'ajax/get_medicine_details.php', // This still requires a separate AJAX file
            type: 'GET',
            data: { id: medicineId },
            dataType: 'json',
            success: function(response) {
                console.log('AJAX response for get_medicine_details:', response); // Debugging: See the full response

                if (response.status === 'success') {
                    // Populate the modal fields
                    $('#edit_medicine_id').val(response.data.id);
                    $('#edit_medicine_name').val(response.data.medicine_name);
                    $('#edit_stock_quantity').val(response.data.stock_quantity);
                    $('#edit_generic_name').val(response.data.generic_name);
                    $('#edit_status').val(response.data.status);
                    $('#edit_expiry_date').val(response.data.expiry_date);
                    // Show the modal
                    $('#editMedicineModal').modal('show');
                } else {
                    Swal.fire('Error!', response.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                Swal.fire('AJAX Error!', 'Could not fetch medicine details: ' + error, 'error');
                console.error('AJAX Error details for get_medicine_details:', xhr.responseText);
            }
        });
    });

    // Handle click on "Save Changes" button in the modal
    $('#updateMedicineBtn').on('click', function() {
        const formData = $('#editMedicineForm').serialize(); // Serialize the form data
        console.log('Form Data for Update:', formData); // Debugging: Check form data being sent

        $.ajax({
            url: 'medicines.php', // Submitting back to the same page
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                console.log('AJAX response for update_medicine_details:', response); // Debugging: See the full update response

                if (response.status === 'success') {
                    Swal.fire('Updated!', response.message, 'success').then(() => {
                        $('#editMedicineModal').modal('hide');
                        location.reload(); // Reload the page to see the updated data
                    });
                } else {
                    Swal.fire('Error!', response.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                Swal.fire('AJAX Error!', 'Could not update medicine: ' + error, 'error');
                console.error('AJAX Error details for update_medicine_details:', xhr.responseText);
            }
        });
    });
  });
</script>
</body>
</html>