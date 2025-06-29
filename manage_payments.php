<?php
session_start();
if(!(isset($_SESSION['user_id']))) {
  header("location:index.php");
  exit;
}

require_once 'config/connection.php'; // Include your database connection

$page_title = "Manage Payments"; // For the page title

// Handle payment deletion (use with caution in a real system as it affects bill reconciliation)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_payment']) && isset($_POST['payment_id'])) {
    $payment_id = $_POST['payment_id'];
    try {
        $con->beginTransaction();

        // Get payment details to reverse the bill's paid_amount
        $stmt = $con->prepare("SELECT bill_id, amount_paid FROM payments WHERE payment_id = ?");
        $stmt->execute([$payment_id]);
        $payment_details = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($payment_details) {
            $bill_id = $payment_details['bill_id'];
            $amount_paid_to_reverse = $payment_details['amount_paid'];

            // Delete the payment record
            $stmt = $con->prepare("DELETE FROM payments WHERE payment_id = ?");
            $stmt->execute([$payment_id]);

            // Update the associated bill's paid_amount and status
            $stmt = $con->prepare("UPDATE bills SET paid_amount = paid_amount - ?, status = CASE WHEN (total_amount - (paid_amount - ?)) > 0 THEN 'Partially Paid' ELSE 'Pending' END WHERE bill_id = ?");
            $stmt->execute([$amount_paid_to_reverse, $amount_paid_to_reverse, $bill_id]);
        }

        $con->commit();
        $_SESSION['success_message'] = "Payment deleted successfully!";
        header("Location: manage_payments.php");
        exit();

    } catch (PDOException $e) {
        $con->rollBack();
        error_log("Payment deletion error: " . $e->getMessage());
        $_SESSION['error_message'] = "Failed to delete payment: " . $e->getMessage();
    }
}


// Pagination setup
$limit = 10; // Number of entries to show in a page.
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Fetch total number of payments for pagination
$total_payments = 0;
try {
    $stmt = $con->query("SELECT COUNT(*) FROM payments");
    $total_payments = $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Error fetching total payments: " . $e->getMessage());
}
$total_pages = ceil($total_payments / $limit);


// Fetch payments with bill details and user who received payment
$payments = [];
try {
    $stmt = $con->prepare("
        SELECT
            py.payment_id,
            py.payment_date,
            py.amount_paid,
            py.payment_method,
            py.transaction_id,
            b.bill_id,
            p.patient_name,
            u.display_name AS received_by_user
        FROM payments py
        JOIN bills b ON py.bill_id = b.bill_id
        JOIN patients p ON b.patient_id = p.id
        LEFT JOIN users u ON py.received_by_user_id = u.id
        ORDER BY py.payment_date DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching payments: " . $e->getMessage());
    $_SESSION['error_message'] = "Error loading payments.";
}

// Get the current page filename to set active menu item
$current_page = basename($_SERVER['PHP_SELF']);

?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title><?php echo $page_title; ?> | PMS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
  <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
  <link rel="stylesheet" href="plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" href="plugins/datatables-responsive/css/responsive.bootstrap4.min.css">
  <link rel="stylesheet" href="dist/css/adminlte.min.css">
  <link rel="stylesheet" href="plugins/overlayScrollbars/css/OverlayScrollbars.min.css">
  <link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700" rel="stylesheet">
  <link rel="stylesheet" href="custom.css">
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

  <?php include 'config/header.php'; // Assuming you have a navbar.php ?>
  <?php include 'config/sidebar.php'; ?>

  <div class="content-wrapper">
    <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark"><?php echo $page_title; ?></h1>
          </div><div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
              <li class="breadcrumb-item active"><?php echo $page_title; ?></li>
            </ol>
          </div></div></div></div>
    <section class="content">
      <div class="container-fluid">
        <?php
        if (isset($_SESSION['success_message'])) {
            echo '<div class="alert alert-success alert-dismissible fade show" role="alert">' . $_SESSION['success_message'] . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>';
            unset($_SESSION['success_message']);
        }
        if (isset($_SESSION['error_message'])) {
            echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">' . $_SESSION['error_message'] . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>';
            unset($_SESSION['error_message']);
        }
        ?>
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">All Payments</h3>
          </div>
          <div class="card-body">
            <table id="paymentsTable" class="table table-bordered table-striped">
              <thead>
              <tr>
                <th>Payment ID</th>
                <th>Bill ID</th>
                <th>Patient Name</th>
                <th>Payment Date</th>
                <th>Amount Paid</th>
                <th>Method</th>
                <th>Transaction ID</th>
                <th>Received By</th>
                <th>Actions</th>
              </tr>
              </thead>
              <tbody>
              <?php if (count($payments) > 0): ?>
                <?php foreach ($payments as $payment): ?>
                  <tr>
                    <td><?php echo $payment['payment_id']; ?></td>
                    <td><a href="view_bill_details.php?bill_id=<?php echo $payment['bill_id']; ?>" title="View Bill"><?php echo $payment['bill_id']; ?></a></td>
                    <td><?php echo htmlspecialchars($payment['patient_name']); ?></td>
                    <td><?php echo date('Y-m-d H:i', strtotime($payment['payment_date'])); ?></td>
                    <td>₦<?php echo number_format($payment['amount_paid'], 2); ?></td>
                    <td><?php echo htmlspecialchars($payment['payment_method'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($payment['transaction_id'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($payment['received_by_user'] ?? 'N/A'); ?></td>
                    <td>
                      <form action="manage_payments.php" method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this payment? This will affect the associated bill\'s paid amount.');">
                          <input type="hidden" name="payment_id" value="<?php echo $payment['payment_id']; ?>">
                          <button type="submit" name="delete_payment" class="btn btn-danger btn-sm" title="Delete Payment"><i class="fas fa-trash"></i></button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="9" class="text-center">No payments found.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
            <nav aria-label="Page navigation example">
                <ul class="pagination justify-content-center">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="manage_payments.php?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
          </div>
          </div>
        </div>
    </section>
    </div>
  <?php include 'config/footer.php'; // Assuming you have a footer.php ?>

  <aside class="control-sidebar control-sidebar-dark">
    </aside>
  </div>
<script src="plugins/jquery/jquery.min.js"></script>
<script src="plugins/jquery-ui/jquery-ui.min.js"></script>
<script>
  $.widget.bridge('uibutton', $.ui.button)
</script>
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="plugins/datatables/jquery.dataTables.min.js"></script>
<script src="plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
<script src="plugins/datatables-responsive/js/dataTables.responsive.min.js"></script>
<script src="plugins/datatables-responsive/js/responsive.bootstrap4.min.js"></script>
<script src="plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js"></script>
<script src="dist/js/adminlte.js"></script>
<script src="dist/js/demo.js"></script>

<script>
  $(function () {
    $("#paymentsTable").DataTable({
      "responsive": true,
      "autoWidth": false,
      "paging": false, // Disable DataTables pagination since we use custom PHP pagination
      "info": false, // Disable DataTables info
      "searching": true // Enable searching
    });
  });
</script>
</body>
</html>