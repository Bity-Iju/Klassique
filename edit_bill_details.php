<?php
session_start();
if(!(isset($_SESSION['user_id']))) {
  header("location:index.php");
  exit;
}

require_once 'config/connection.php'; // Include your database connection

$page_title = "Edit Bill"; // For the page title

$bill_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($bill_id === 0) {
    $_SESSION['error_message'] = "Invalid bill ID provided.";
    header("location:manage_bills.php");
    exit;
}

// Fetch bill details
try {
    $stmt = $con->prepare("SELECT b.id AS bill_id, p.patient_name, b.patient_id, b.total_amount, b.paid_amount, b.due_amount, b.payment_status, b.payment_type, b.created_at
                            FROM bills b
                            JOIN patients p ON b.patient_id = p.id
                            WHERE b.id = ?");
    $stmt->execute([$bill_id]);
    $bill = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$bill) {
        $_SESSION['error_message'] = "Bill not found.";
        header("location:manage_bills.php");
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error fetching bill details: " . $e->getMessage();
    header("location:manage_bills.php");
    exit;
}

// Handle form submission for updating bill
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_bill'])) {
    $new_total_amount = $_POST['total_amount'];
    $new_paid_amount = $_POST['paid_amount'];
    $new_payment_type = $_POST['payment_type'];

    // Calculate new due amount
    $new_due_amount = $new_total_amount - $new_paid_amount;

    // Determine new payment status
    $new_payment_status = 'Pending';
    if ($new_paid_amount >= $new_total_amount) {
        $new_payment_status = 'Paid';
    } elseif ($new_paid_amount > 0 && $new_paid_amount < $new_total_amount) {
        $new_payment_status = 'Partially Paid';
    }


    try {
        $stmt = $con->prepare("UPDATE bills SET total_amount = ?, paid_amount = ?, due_amount = ?, payment_status = ?, payment_type = ? WHERE id = ?");
        $stmt->execute([$new_total_amount, $new_paid_amount, $new_due_amount, $new_payment_status, $new_payment_type, $bill_id]);

        $_SESSION['success_message'] = "Bill updated successfully!";
        header("location:manage_bills.php");
        exit;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating bill: " . $e->getMessage();
        header("location:edit_bill_details.php?id=" . $bill_id); // Redirect back to edit page on error
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo $page_title; ?> - KDCS</title>

  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
  <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
  <link rel="stylesheet" href="dist/css/adminlte.min.css">
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
            <h1><?php echo $page_title; ?></h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
              <li class="breadcrumb-item"><a href="manage_bills.php">Manage Bills</a></li>
              <li class="breadcrumb-item active"><?php echo $page_title; ?></li>
            </ol>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-md-8 offset-md-2">
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
            <div class="card card-primary">
              <div class="card-header">
                <h3 class="card-title">Edit Bill Details for Bill ID: <?php echo htmlspecialchars($bill['bill_id']); ?></h3>
              </div>
              <form action="edit_bill_details.php?id=<?php echo htmlspecialchars($bill['bill_id']); ?>" method="POST">
                <div class="card-body">
                  <div class="form-group">
                    <label for="patient_name">Patient Name</label>
                    <input type="text" class="form-control" id="patient_name" value="<?php echo htmlspecialchars($bill['patient_name']); ?>" disabled>
                  </div>
                  <div class="form-group">
                    <label for="total_amount">Total Amount (₦)</label>
                    <input type="number" step="0.01" class="form-control" id="total_amount" name="total_amount" value="<?php echo htmlspecialchars($bill['total_amount']); ?>" required>
                  </div>
                  <div class="form-group">
                    <label for="paid_amount">Paid Amount (₦)</label>
                    <input type="number" step="0.01" class="form-control" id="paid_amount" name="paid_amount" value="<?php echo htmlspecialchars($bill['paid_amount']); ?>" required>
                  </div>
                  <div class="form-group">
                    <label for="payment_type">Payment Type</label>
                    <select class="form-control" id="payment_type" name="payment_type" required>
                      <option value="Cash" <?php echo ($bill['payment_type'] == 'Cash') ? 'selected' : ''; ?>>Cash</option>
                      <option value="POS" <?php echo ($bill['payment_type'] == 'POS') ? 'selected' : ''; ?>>POS</option>
                      <option value="Transfer" <?php echo ($bill['payment_type'] == 'Transfer') ? 'selected' : ''; ?>>Transfer</option>
                      <option value="Cheque" <?php echo ($bill['payment_type'] == 'Cheque') ? 'selected' : ''; ?>>Cheque</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label>Current Payment Status</label>
                    <p class="form-control-static"><?php echo htmlspecialchars($bill['payment_status']); ?></p>
                  </div>
                </div>
                <div class="card-footer">
                  <button type="submit" name="update_bill" class="btn btn-primary">Update Bill</button>
                  <a href="manage_bills.php" class="btn btn-secondary">Cancel</a>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
  <?php include 'config/footer.php'; ?>
  <aside class="control-sidebar control-sidebar-dark"></aside>
</div>

<script src="plugins/jquery/jquery.min.js"></script>
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="dist/js/adminlte.min.js"></script>
<script>
  $(function () {
    // Highlight sidebar menu item (adjust ID if necessary)
    showMenuSelected("#mnu_bills");
  });
</script>
</body>
</html>