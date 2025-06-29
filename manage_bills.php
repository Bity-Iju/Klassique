<?php
session_start();
if(!(isset($_SESSION['user_id']))) {
  header("location:index.php");
  exit;
}

require_once 'config/connection.php'; // Include your database connection

$page_title = "Manage Bills"; // For the page title

// --- Fetch Clinic Details by reading index.php content ---
$clinic_name = "KLASSIQUE DIAGNOSTICS (Default)";
$clinic_email = "info@kdcsclinic.com (Default)";
$clinic_address = "Address Not Found (Default)";
$clinic_phone = "Phone Not Found (Default)";

// Attempt to read clinic details from index.php
// Suppress warnings if file doesn't exist to prevent output interference with AJAX
$index_content = @file_get_contents('index.php');

if ($index_content !== false) {
    if (preg_match('/<h1><strong>(.*?)<\/strong><\/h1>/', $index_content, $matches)) {
        $clinic_name = strip_tags($matches[1]);
    }
    if (preg_match('/<hr>\s*<h3>Caring for a BETTER Life\?<\/h3>\s*<p>(.*?)<\/p>/s', $index_content, $matches)) {
        $clinic_address = strip_tags($matches[1]);
        $clinic_address = preg_replace('/^Visit us @\s*/', '', $clinic_address);
    }
    // Updated regex to capture all phone numbers if present, or adjust based on your index.php format
    if (preg_match('/<hr>\s*<h3>Caring for a BETTER Life\?<\/h3>\s*<p>.*?<\/p>\s*<p><strong>Email:\s*<\/strong>(.*?)\s*\|\s*<strong>Office No.:<\/strong>(.*?)\s*(?:\|\s*<strong>Doctor\'s No.:<\/strong>(.*?))?<\/p>/s', $index_content, $matches_contact)) {
        $clinic_email = trim($matches_contact[1]);
        $office_no = trim($matches_contact[2]);
        $doctor_no = isset($matches_contact[3]) ? trim($matches_contact[3]) : '';
        $clinic_phone = $office_no;
        if (!empty($doctor_no) && $office_no !== $doctor_no) { // Avoid duplicating if numbers are the same
            $clinic_phone .= " / " . $doctor_no;
        }
    }
}
// --- END Fetch Clinic Details ---

// Handle AJAX requests
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');

    // AJAX handler for fetching bill details (for View Modal)
    if (isset($_GET['action']) && $_GET['action'] == 'fetch_bill_details' && isset($_GET['bill_id'])) {
        $bill_id = $_GET['bill_id'];
        try {
            // Fetch bill header, patient, and user details
            $stmt_bill = $con->prepare("SELECT
                                            b.id AS bill_id,
                                            b.patient_id,
                                            p.patient_name,
                                            p.contact_no AS patient_phone,
                                            b.total_amount,
                                            b.paid_amount,
                                            b.due_amount,
                                            b.payment_status,
                                            b.payment_type,
                                            u.username AS generated_by_user,
                                            b.created_at
                                        FROM bills b
                                        JOIN patients p ON b.patient_id = p.id
                                        LEFT JOIN users u ON b.generated_by_user_id = u.id
                                        WHERE b.id = ?");
            $stmt_bill->execute([$bill_id]);
            $bill_data = $stmt_bill->fetch(PDO::FETCH_ASSOC);

            if (!$bill_data) {
                echo json_encode(['status' => 'error', 'message' => 'Bill not found.']);
                exit();
            }

            // Fetch bill items
            $stmt_items = $con->prepare("SELECT
                                            bi.quantity,
                                            bi.price_at_time_of_bill,
                                            t.test_name
                                        FROM bill_items bi
                                        JOIN tests t ON bi.test_id = t.id
                                        WHERE bi.bill_id = ?");
            $stmt_items->execute([$bill_id]);
            $bill_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'bill' => $bill_data,
                'items' => $bill_items,
            ]);
            exit();

        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
            exit();
        }
    }

    // AJAX handler for updating bill (for Edit Modal)
    if (isset($_POST['action']) && $_POST['action'] == 'update_bill' && isset($_POST['bill_id'])) { // Handle Bill Edit
        $bill_id = $_POST['bill_id'];
        $new_total_amount = filter_var($_POST['total_amount'], FILTER_VALIDATE_FLOAT);
        $new_payment_type = htmlspecialchars($_POST['payment_type'], ENT_QUOTES, 'UTF-8');

        // Basic validation
        if ($new_total_amount === false || $new_total_amount < 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid total amount provided.']);
            exit();
        }

        try {
            $con->beginTransaction();
            // First, get current paid_amount to recalculate due_amount and payment_status
            $stmt_current_bill = $con->prepare("SELECT paid_amount FROM bills WHERE id = ?");
            $stmt_current_bill->execute([$bill_id]);
            $current_bill = $stmt_current_bill->fetch(PDO::FETCH_ASSOC);

            if ($current_bill) {
                $current_paid_amount = $current_bill['paid_amount'];
                $new_due_amount = $new_total_amount - $current_paid_amount;
                // Determine payment status based on new total and paid amount
                $new_payment_status = 'Unpaid'; // Default
                if ($new_due_amount <= 0) {
                    $new_payment_status = 'Paid';
                } elseif ($current_paid_amount > 0) {
                    $new_payment_status = 'Partially Paid';
                }

                $stmt = $con->prepare("UPDATE bills SET total_amount = ?, due_amount = ?, payment_type = ?, payment_status = ? WHERE id = ?");
                if ($stmt->execute([$new_total_amount, $new_due_amount, $new_payment_type, $new_payment_status, $bill_id])) {
                    $con->commit();
                    echo json_encode(['status' => 'success', 'message' => 'Bill updated successfully!']);
                    exit();
                } else {
                    throw new PDOException("Failed to execute update statement.");
                }

            } else {
                $con->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Bill not found for update.']);
                exit();
            }
        } catch (PDOException $e) {
            $con->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
            exit();
        }
    }

    // AJAX handler for deleting bill
    if (isset($_POST['action']) && $_POST['action'] == 'delete_bill' && isset($_POST['bill_id'])) {
        $bill_id = $_POST['bill_id'];
        try {
            $con->beginTransaction();
            $stmt_items = $con->prepare("DELETE FROM bill_items WHERE bill_id = ?");
            $stmt_items->execute([$bill_id]);

            $stmt_payments = $con->prepare("DELETE FROM payments WHERE bill_id = ?");
            $stmt_payments->execute([$bill_id]);

            $stmt = $con->prepare("DELETE FROM bills WHERE id = ?");
            if ($stmt->execute([$bill_id])) {
                $con->commit();
                echo json_encode(['status' => 'success', 'message' => 'Bill and its items have been deleted.']);
                exit();
            } else {
                throw new PDOException("Failed to execute delete statement.");
            }
        } catch (PDOException $e) {
            $con->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
            exit();
        }
    }
    // Existing mark as paid handler, but also changed to JSON response
    if (isset($_POST['mark_as_paid']) && isset($_POST['bill_id'])) {
        $bill_id = $_POST['bill_id'];
        $amount_paid = $_POST['amount_paid']; // Amount to be paid for this transaction
        $payment_method = $_POST['payment_method'] ?? 'Cash';
        $received_by_user_id = $_SESSION['user_id'];

        try {
            $con->beginTransaction();

            $stmt = $con->prepare("INSERT INTO payments (bill_id, amount_paid, payment_method, received_by_user_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$bill_id, $amount_paid, $payment_method, $received_by_user_id]);

            $stmt = $con->prepare("UPDATE bills SET paid_amount = paid_amount + ?, payment_type = ?, payment_status = CASE WHEN (total_amount - (paid_amount + ?)) <= 0 THEN 'Paid' ELSE 'Partially Paid' END WHERE id = ?");
            if ($stmt->execute([$amount_paid, $payment_method, $amount_paid, $bill_id])) {
                $con->commit();
                echo json_encode(['status' => 'success', 'message' => 'Payment recorded successfully!']);
                exit();
            } else {
                throw new PDOException("Failed to execute update statement.");
            }
        } catch (PDOException $e) {
            $con->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
            exit();
        }
    }
}


// Fetch bills data along with patient and user details for the main table display
try {
    $stmt = $con->query("SELECT
                            b.id AS bill_id,
                            p.id AS patient_id,
                            p.patient_name,
                            b.total_amount,
                            b.paid_amount,
                            b.due_amount,
                            b.payment_status,
                            b.payment_type,
                            u.username AS generated_by_user,
                            b.created_at
                         FROM bills b
                         JOIN patients p ON b.patient_id = p.id
                         LEFT JOIN users u ON b.generated_by_user_id = u.id
                         ORDER BY b.created_at DESC");
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching bills for main display: " . $e->getMessage());
    $bills = []; // Initialize as empty array on error
}

// Includes for HTML structure
include_once('./config/head.php');
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
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/1.7.1/css/buttons.dataTables.min.css">
    <style>
        /* Your CSS styles here */
        :root {
            --main-green: #145a32;
            --accent-green: #117a65;
        }

        /* Backgrounds and text */
        body,
        .content-wrapper,
        .card {
            background-color: #fff !important; /* Changed to white */
            color: var(--main-green) !important;
        }

        /* Headings and titles */
        h1, h2, h3, h4, h5, h6,
        .card-title{
            color: var(--main-green) !important;
        }

        /* Table headers and cells */
        .table th,
        .table td,
        .table thead th {
            color: var(--main-green) !important;
            background-color:rgb(246, 254, 247) !important;
            border-color: var(--main-green) !important;
        }

        /* Table stripes */
        .table tbody tr:nth-child(odd) {
            background-color: #f2f2f2 !important; /* Light gray for odd rows */
        }
        .table tbody tr:nth-child(even) {
            background-color: #ffffff !important; /* White for even rows */
        }

        /* Remove table borders if you want a cleaner look */
        .table,
        .table-bordered,
        .table th,
        .table td {
            border: none !important;
            box-shadow: none !important;
        }

        /* Buttons */
        .btn-success,
        .btn-primary,
        .btn-info,
        .btn-secondary,
        .btn-danger,
        .btn {
            background-color: var(--main-green) !important;
            border-color: var(--main-green) !important;
            color: #fff !important;
        }
        .btn-success:hover,
        .btn-primary:hover,
        .btn-info:hover,
        .btn-secondary:hover,
        .btn-danger:hover,
        .btn:hover {
            background-color: var(--accent-green) !important;
            border-color: var(--accent-green) !important;
            color: #fff !important;
        }

        /* Always keep delete button red */
        .btn-danger, .btn-danger:hover, .btn-danger:focus {
            background-color: #dc3545 !important;
            border-color: #dc3545 !important;
            color: #fff !important;
        }

        /* Breadcrumbs */
        .breadcrumb,
        .breadcrumb-item,
        .breadcrumb-item.active,
        .breadcrumb-item a {
            color: var(--main-green) !important;
        }

        /* DataTables buttons */
        .dt-button,
        .buttons-html5,
        .buttons-print,
        .buttons-colvis {
            background-color: var(--main-green) !important;
            border-color: var(--main-green) !important;
            color: #fff !important;
        }
        .dt-button:hover,
        .buttons-html5:hover,
        .buttons-print:hover,
        .buttons-colvis:hover {
            background-color: var(--accent-green) !important;
            border-color: var(--accent-green) !important;
            color: #fff !important;
        }

        /* Modal header/footer */
        .modal-header,
        .modal-footer {
            background: #fff !important; /* Changed to white */
            color: var(--main-green) !important;
            border-color: var(--main-green) !important;
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
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="#">Home</a></li>
              <li class="breadcrumb-item active"><?php echo $page_title; ?></li>
            </ol>
          </div>
        </div>
      </div>
    </div>

    <div class="content">
      <div class="container-fluid">
        <div class="card card-success card-outline">
          <div class="card-header">
            <h3 class="card-title">All Bills</h3>
            <div id="billsTableButtons" class="float-right"></div>
          </div>
          <div class="card-body">
            <table id="billsTable" class="table table-bordered table-hover">
              <thead>
                <tr>
                  <th>Patient ID</th>
                  <th>Patient Name</th>
                  <th>Total Amount</th>
                  <th>Paid Amount</th>
                  <th>Due Amount</th>
                  <th>Payment Type</th>
                  <th>Generated By</th>
                  <th>Date</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($bills)): ?>
                    <?php foreach ($bills as $bill): ?>
                        <tr>
                            <td><?php echo sprintf('KDCS/MUB/%04d', htmlspecialchars($bill['patient_id'])); ?></td>
                            <td><?php echo htmlspecialchars($bill['patient_name']); ?></td>
                            <td>₦<?php echo number_format($bill['total_amount'], 2); ?></td>
                            <td>₦<?php echo number_format($bill['paid_amount'], 2); ?></td>
                            <td>₦<?php echo number_format($bill['due_amount'], 2); ?></td>
                            <td><?php echo htmlspecialchars($bill['payment_type']); ?></td>
                            <td><?php echo htmlspecialchars($bill['generated_by_user'] ?: 'N/A'); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($bill['created_at'])); ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-success view-bill-in-modal-btn" data-bill-id="<?php echo htmlspecialchars($bill['bill_id']); ?>" title="View Details">
                                    <i class="fa fa-eye"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-primary edit-bill-btn"
                                        data-bill-id="<?php echo htmlspecialchars($bill['bill_id']); ?>"
                                        data-patient-name="<?php echo htmlspecialchars($bill['patient_name']); ?>"
                                        data-total-amount="<?php echo htmlspecialchars($bill['total_amount']); ?>"
                                        data-payment-type="<?php echo htmlspecialchars($bill['payment_type']); ?>"
                                        title="Edit Bill">
                                    <i class="fa fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-danger delete-bill-btn" data-bill-id="<?php echo htmlspecialchars($bill['bill_id']); ?>" title="Delete">
                                    <i class="fa fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?PHP else: ?>
                    <tr><td colspan="9" class="text-center">No bills found.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php include_once('./config/footer.php'); ?>

  <div class="modal fade" id="paymentModal" tabindex="-1" role="dialog" aria-labelledby="paymentModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="paymentModalLabel">Record Payment for Bill #<span id="modalBillId"></span></h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <form id="paymentForm" method="POST" action="">
          <div class="modal-body">
            <input type="hidden" name="bill_id" id="modalHiddenBillId">
            <div class="form-group">
              <label>Total Amount:</label>
              <p class="form-control-static">₦<span id="modalTotalAmount"></span></p>
            </div>
            <div class="form-group">
              <label>Paid Amount:</label>
              <p class="form-control-static">₦<span id="modalPaidAmount"></span></p>
            </div>
            <div class="form-group">
              <label>Due Amount:</label>
              <p class="form-control-static">₦<span id="modalDueAmount"></span></p>
            </div>
            <div class="form-group">
              <label for="amount_paid">Amount to Pay:</label>
              <input type="number" class="form-control" id="amount_paid" name="amount_paid" step="0.01" min="0" required>
            </div>
            <div class="form-group">
              <label for="payment_method">Payment Method:</label>
              <select class="form-control" id="payment_method" name="payment_method">
                <option value="Cash">Cash</option>
                <option value="Card">Card</option>
                <option value="Transfer">Transfer</option>
              </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            <button type="submit" name="mark_as_paid" class="btn btn-primary">Record Payment</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="editBillModal" tabindex="-1" role="dialog" aria-labelledby="editBillModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="editBillModalLabel">Edit Bill #<span id="editModalBillId"></span></h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <form id="editBillForm" method="POST" action="">
          <div class="modal-body">
            <input type="hidden" name="bill_id" id="editModalHiddenBillId">
            <div class="form-group">
              <label>Patient Name:</label>
              <p class="form-control-static" id="editModalPatientName"></p>
            </div>
            <div class="form-group">
              <label for="edit_total_amount">Total Amount:</label>
              <input type="number" class="form-control" id="edit_total_amount" name="total_amount" step="0.01" min="0" required>
            </div>
            <div class="form-group">
              <label for="edit_payment_type">Payment Type:</label>
              <select class="form-control" id="edit_payment_type" name="payment_type">
                <option value="Cash">Cash</option>
                <option value="Card">Card</option>
                <option value="Transfer">Transfer</option>
                <option value="Unpaid">Unpaid</option>
                <option value="Partially Paid">Partially Paid</option>
                <option value="Paid">Paid</option>
              </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            <button type="submit" name="update_bill" class="btn btn-primary">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

    <div class="modal fade" id="viewBillDetailsModal" tabindex="-1" role="dialog" aria-labelledby="viewBillDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewBillDetailsModalLabel">Bill Details #<span id="viewModalBillId"></span></h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <h6>Patient Information:</h6>
                    <p><strong>Name:</strong> <span id="viewModalPatientName"></span></p>
                    <p><strong>Phone:</strong> <span id="viewModalPatientPhone"></span></p>
                    <hr>
                    <h6>Bill Summary:</h6>
                    <p><strong>Total Amount:</strong> ₦<span id="viewModalTotalAmount"></span></p>
                    <p><strong>Paid Amount:</strong> ₦<span id="viewModalPaidAmount"></span></p>
                    <p><strong>Due Amount:</strong> ₦<span id="viewModalDueAmount"></span></p>
                    <p><strong>Payment Status:</strong> <span id="viewModalPaymentStatus"></span></p>
                    <p><strong>Payment Type:</strong> <span id="viewModalPaymentType"></span></p>
                    <p><strong>Generated By:</strong> <span id="viewModalGeneratedByUser"></span></p>
                    <p><strong>Date:</strong> <span id="viewModalCreatedAt"></span></p>
                    <hr>
               <!-- <h6>Bill Items:</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm">
                            <thead>
                                <tr>
                                    <th>Test Name</th>
                                    <th>Quantity</th>
                                    <th>Price</th>
                                    <th>Subtotal</th>
                                </tr>
                            </thead>
                            <tbody id="viewModalBillItems">
                                </tbody>
                        </table>
                    </div>-->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="viewModalPrintButton">
                        <i class="fa fa-print"></i> Print This Bill
                    </button>
                </div>
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

<script src="https://cdn.datatables.net/buttons/1.7.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/1.7.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/1.7.1/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/buttons/1.7.1/js/buttons.colVis.min.js"></script> <script>
$(document).ready(function() {
    // Initialize DataTables with Buttons
    var billsTable = $('#billsTable').DataTable({
      "paging": true,
      "lengthChange": true,
      "searching": true,
      "ordering": true,
      "info": true,
      "autoWidth": false,
      "responsive": true,
      "dom": 'lfrtip', // 'B' (buttons) is removed from here as we append them manually
      "buttons": [
        {
          extend: 'copy',
          text: '<i class="fas fa-copy"></i> Copy',
          className: 'btn btn-secondary btn-sm'
        },
        {
          extend: 'csv',
          text: '<i class="fas fa-file-csv"></i> CSV',
          className: 'btn btn-secondary btn-sm'
        },
        {
          extend: 'excel',
          text: '<i class="fas fa-file-excel"></i> Excel',
          className: 'btn btn-secondary btn-sm'
        },
        {
          extend: 'pdf',
          text: '<i class="fas fa-file-pdf"></i> PDF',
          className: 'btn btn-secondary btn-sm',
          orientation: 'landscape',
          pageSize: 'A4'
        },
        // Removed the 'print' button from here as requested previously
        {
          extend: 'colvis',
          text: '<i class="fas fa-columns"></i> Columns',
          className: 'btn btn-info btn-sm'
        }
      ]
    });

    // Manually append buttons to the dedicated container
    billsTable.buttons().container().appendTo('#billsTableButtons');


    // The event listener for .pay-bill-btn is no longer needed as the button is removed.
    // However, the payment modal and its form submission logic are kept in case they are used elsewhere.
    /*
    $('#billsTable').on('click', '.pay-bill-btn', function() { // Use event delegation
        var billId = $(this).data('bill-id');
        var totalAmount = parseFloat($(this).data('bill-total'));
        var paidAmount = parseFloat($(this).data('bill-paid'));
        var dueAmount = totalAmount - paidAmount;

        $('#modalBillId').text(billId);
        $('#modalHiddenBillId').val(billId);
        $('#modalTotalAmount').text(totalAmount.toFixed(2));
        $('#modalPaidAmount').text(paidAmount.toFixed(2));
        $('#modalDueAmount').text(dueAmount.toFixed(2));
        $('#amount_paid').val(dueAmount.toFixed(2)); // Pre-fill with remaining due
        $('#amount_paid').attr('max', dueAmount.toFixed(2)); // Set max to due amount

        $('#paymentModal').modal('show');
    });
    */

    // Ensure amount_paid does not exceed due amount (existing functionality, remains)
    $('#amount_paid').on('input', function() {
        var current_value = parseFloat($(this).val());
        var max_value = parseFloat($(this).attr('max'));
        if (current_value > max_value) {
            $(this).val(max_value.toFixed(2));
        }
    });

    // Handle AJAX form submission for payment modal
    $('#paymentForm').on('submit', function(e) {
        e.preventDefault(); // Prevent default form submission

        var formData = $(this).serialize(); // Serialize form data
        $.ajax({
            url: 'manage_bills.php', // Submit to the same page
            type: 'POST',
            data: formData,
            dataType: 'json', // Expect JSON response
            success: function(response) {
                if (response.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: response.message,
                        showConfirmButton: false,
                        timer: 1500
                    }).then(function() {
                        window.location.href = 'manage_bills.php'; // Reload page
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: response.message,
                        showConfirmButton: true
                    });
                }
            },
            error: function(xhr, status, error) {
                Swal.fire(
                    'Error!',
                    'An error occurred: ' + error + '. Check browser console for details.',
                    'error'
                );
                console.error("AJAX Error for payment form:", status, error, xhr.responseText);
            }
        });
    });


    // Handle delete button click using SweetAlert2 and AJAX
    $('#billsTable').on('click', '.delete-bill-btn', function(e) { // Use event delegation
        e.preventDefault(); // Prevent default button action
        var billId = $(this).data('bill-id');

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
                // User confirmed, send AJAX request
                $.ajax({
                    url: 'manage_bills.php', // Target the current page for deletion
                    type: 'POST',
                    data: {
                        action: 'delete_bill', // Added action for consistent handling
                        bill_id: billId
                    },
                    dataType: 'json', // Expect JSON response
                    success: function(response) {
                        if (response.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Deleted!',
                                text: response.message,
                                showConfirmButton: false,
                                timer: 1500
                            }).then(function() {
                                window.location.href = 'manage_bills.php'; // Reload page to show updated table
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: response.message,
                                showConfirmButton: true
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.fire(
                            'Error!',
                            'An error occurred during deletion: ' + error + '. Check browser console for details.',
                            'error'
                        );
                        console.error("AJAX Error for delete bill:", status, error, xhr.responseText);
                    }
                });
            }
        });
    });

    // Handle Edit button click to populate and show modal
    $('#billsTable').on('click', '.edit-bill-btn', function() { // Use event delegation
        var billId = $(this).data('bill-id');
        var patientName = $(this).data('patient-name');
        var totalAmount = parseFloat($(this).data('total-amount'));
        var paymentType = $(this).data('payment-type');

        $('#editModalBillId').text(billId);
        $('#editModalHiddenBillId').val(billId);
        $('#editModalPatientName').text(patientName);
        $('#edit_total_amount').val(totalAmount.toFixed(2));
        $('#edit_payment_type').val(paymentType); // Set selected option

        $('#editBillModal').modal('show');
    });

    // Handle AJAX form submission for Edit Bill Modal
    $('#editBillForm').on('submit', function(e) {
        e.preventDefault(); // Prevent default form submission

        var formData = $(this).serialize();
        // Add the action parameter manually
        formData += '&action=update_bill';

        $.ajax({
            url: 'manage_bills.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Updated!',
                        text: response.message,
                        showConfirmButton: false,
                        timer: 1500
                    }).then(function() {
                        window.location.href = 'manage_bills.php';
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: response.message,
                        showConfirmButton: true
                    });
                }
            },
            error: function(xhr, status, error) {
                Swal.fire(
                    'Error!',
                    'An error occurred during update: ' + error + '. Check browser console for details.',
                    'error'
                );
                console.error("AJAX Error for edit bill form:", status, error, xhr.responseText);
            }
        });
    });

    // PHP variables for clinic details passed to JavaScript (for print function)
    const clinicName = <?php echo json_encode($clinic_name); ?>;
    const clinicAddress = <?php echo json_encode($clinic_address); ?>;
    const clinicEmail = <?php echo json_encode($clinic_email); ?>;
    const clinicPhone = <?php echo json_encode($clinic_phone); ?>;


    // Handle View Bill Details button click to populate and show modal
    $('#billsTable').on('click', '.view-bill-in-modal-btn', function() {
        var billId = $(this).data('bill-id');
        
        // Clear previous content
        $('#viewModalBillItems').empty();

        $.ajax({
            url: 'manage_bills.php', // Target the current page
            type: 'GET', // Use GET for fetching data
            data: {
                action: 'fetch_bill_details', // Custom action parameter
                bill_id: billId
            },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    var bill = response.bill;
                    var items = response.items;

                    // Populate Bill Summary
                    $('#viewModalBillId').text(bill.bill_id);
                    $('#viewModalPatientName').text(bill.patient_name);
                    $('#viewModalPatientPhone').text(bill.patient_phone || 'N/A');
                    $('#viewModalTotalAmount').text(parseFloat(bill.total_amount).toFixed(2));
                    $('#viewModalPaidAmount').text(parseFloat(bill.paid_amount).toFixed(2));
                    $('#viewModalDueAmount').text(parseFloat(bill.due_amount).toFixed(2));
                    $('#viewModalPaymentStatus').text(bill.payment_status);
                    $('#viewModalPaymentType').text(bill.payment_type);
                    $('#viewModalGeneratedByUser').text(bill.generated_by_user || 'N/A');
                    $('#viewModalCreatedAt').text(new Date(bill.created_at).toLocaleString());

                    // Populate Bill Items
                    if (items.length > 0) {
                        $.each(items, function(index, item) {
                            var subtotal = item.quantity * item.price_at_time_of_bill;
                            $('#viewModalBillItems').append(
                                '<tr>' +
                                '<td>' + item.test_name + '</td>' +
                                '<td>' + item.quantity + '</td>' +
                                '<td>₦' + parseFloat(item.price_at_time_of_bill).toFixed(2) + '</td>' +
                                '<td>₦' + subtotal.toFixed(2) + '</td>' +
                                '</tr>'
                            );
                        });
                    } else {
                        $('#viewModalBillItems').append('<tr><td colspan="4" class="text-center">No items found for this bill.</td></tr>');
                    }

                    // Attach print functionality to the modal's print button for POS type
                    $('#viewModalPrintButton').off('click').on('click', function() {
                        var printContents = `
                            <!DOCTYPE html>
                            <html>
                            <head>
                                <title>Receipt - Bill #${bill.bill_id}</title>
                                <style>
                                    body {
                                        font-family: 'Consolas', 'Courier New', monospace;
                                        font-size: 11px;
                                        width: 80mm; /* Typical POS receipt width */
                                        margin: 0;
                                        padding: 0;
                                    }
                                    .receipt-container {
                                        width: 100%;
                                        padding: 10px;
                                        box-sizing: border-box;
                                    }
                                    .center { text-align: center; }
                                    .right { text-align: right; }
                                    .bold { font-weight: bold; }
                                    .divider { border-top: 1px dashed black; margin: 5px 0; }
                                    table {
                                        width: 100%;
                                        border-collapse: collapse;
                                    }
                                    table th, table td {
                                        padding: 2px 0;
                                        text-align: left;
                                    }
                                    .item-table th:nth-child(1) { width: 50%; }
                                    .item-table th:nth-child(2), .item-table td:nth-child(2) { text-align: center; width: 15%; }
                                    .item-table th:nth-child(3), .item-table td:nth-child(3) { text-align: right; width: 20%; }
                                    .item-table th:nth-child(4), .item-table td:nth-child(4) { text-align: right; width: 15%; }
                                </style>
                            </head>
                            <body>
                                <div class="receipt-container">
                                    <div class="center">
                                        <h3 class="bold" style="margin-bottom: 5px;"><?php echo $clinic_name; ?></h3>
                                        <p style="margin: 0;"><?php echo $clinic_address; ?></p>
                                        <p style="margin: 0;">Email: <?php echo $clinic_email; ?></p>
                                        <p style="margin: 0;">Phone: <?php echo $clinic_phone; ?></p>
                                    </div>

                                    <div class="divider"></div>

                                    <p style="margin: 5px 0;"><strong>Bill ID:</strong> #${bill.bill_id}</p>
                                    <p style="margin: 5px 0;"><strong>Patient ID:</strong> KDCS/MUB/${String(bill.patient_id).padStart(4, '0')}</p>
                                    <p style="margin: 5px 0;"><strong>Patient:</strong> ${bill.patient_name}</p>
                                    <p style="margin: 5px 0;"><strong>Date:</strong> ${new Date(bill.created_at).toLocaleString()}</p>
                                    <p style="margin: 5px 0;"><strong>Generated By:</strong> ${bill.generated_by_user || 'N/A'}</p>

                                    <div class="divider"></div>

                                    <table class="item-table">
                                        <thead>
                                            <tr>
                                                <th>Item</th>
                                                <th class="center">Qty</th>
                                                <th class="right">Price</th>
                                                <th class="right">Amt</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${items.map(item => `
                                                <tr>
                                                    <td>${item.test_name}</td>
                                                    <td class="center">${item.quantity}</td>
                                                    <td class="right">₦${parseFloat(item.price_at_time_of_bill).toFixed(2)}</td>
                                                    <td class="right">₦${(item.quantity * item.price_at_time_of_bill).toFixed(2)}</td>
                                                </tr>
                                            `).join('')}
                                        </tbody>
                                    </table>

                                    <div class="divider"></div>

                                    <p class="right bold" style="margin: 5px 0;">Total: ₦${parseFloat(bill.total_amount).toFixed(2)}</p>
                                    <p class="right" style="margin: 5px 0;">Paid: ₦${parseFloat(bill.paid_amount).toFixed(2)}</p>
                                    <p class="right" style="margin: 5px 0;">Due: ₦${parseFloat(bill.due_amount).toFixed(2)}</p>
                                    <p class="right" style="margin: 5px 0;">Status: ${bill.payment_status}</p>
                                    <p class="right" style="margin: 5px 0;">Payment Type: ${bill.payment_type}</p>

                                    <div class="divider"></div>
                                    <div class="center">
                                        <p style="margin: 5px 0;">Thank You For Your Patronage!</p>
                                    </div>
                                </div>
                            </body>
                            </html>
                        `;

                        var printWindow = window.open('', '_blank');
                        printWindow.document.write(printContents);
                        printWindow.document.close();

                        printWindow.onload = function() {
                            printWindow.focus();
                            printWindow.print();
                            // Do not reload the page, simply close the print window
                            printWindow.close();
                        };
                    });

                    $('#viewBillDetailsModal').modal('show');
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: response.message,
                        showConfirmButton: true
                    });
                }
            },
            error: function(xhr, status, error) {
                Swal.fire(
                    'Error!',
                    'Could not load bill details: ' + error + '. Check browser console for details.',
                    'error'
                );
                console.error("AJAX Error for fetch bill details:", status, error, xhr.responseText);
            }
        });
    });
});
</script>
</body>
</html>