<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("location:index.php");
    exit;
}

include './config/connection.php';
include './common_service/common_functions.php';

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$message = '';

if ($order_id > 0) {
    if (isset($_POST['confirm_delete'])) {
        try {
            $con->beginTransaction();

            // Delete associated tests first (due to foreign key constraints)
            $stmt_delete_tests = $con->prepare("DELETE FROM lab_order_tests WHERE lab_order_id = :order_id");
            $stmt_delete_tests->bindParam(':order_id', $order_id, PDO::PARAM_INT);
            $stmt_delete_tests->execute();

            // Then delete the main lab order
            $stmt_delete_order = $con->prepare("DELETE FROM lab_orders WHERE lab_order_id = :order_id");
            $stmt_delete_order->bindParam(':order_id', $order_id, PDO::PARAM_INT);
            $stmt_delete_order->execute();

            $con->commit();
            $message = "Lab Order (ID: $order_id) and its associated tests deleted successfully.";
            header("location:laboratory.php?message=" . urlencode($message));
            exit;

        } catch (PDOException $ex) {
            $con->rollBack();
            error_log("PDO Error in delete_lab_order.php: " . $ex->getMessage());
            $message = "<div class='alert alert-danger'>Database error: " . $ex->getMessage() . "</div>";
        }
    } else {
        // Display confirmation form
        $message = "<div class='alert alert-warning'>Are you sure you want to delete Lab Order ID: <strong>" . htmlspecialchars($order_id) . "</strong>? This action cannot be undone.</div>";
    }
} else {
    $message = "<div class='alert alert-danger'>Invalid Lab Order ID.</div>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Delete Lab Order - KDCS</title>
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
</head>
<body class="hold-transition sidebar-mini">
<div class="wrapper">
    <?php include 'config/header.php'; ?>
    <?php include 'config/sidebar.php'; ?>

    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1>Delete Lab Order</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="laboratory.php">Manage Lab Orders</a></li>
                            <li class="breadcrumb-item active">Delete Lab Order</li>
                        </ol>
                    </div>
                </div>
            </div></section>

        <section class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-md-8 offset-md-2">
                        <div class="card card-danger">
                            <div class="card-header">
                                <h3 class="card-title">Confirm Deletion</h3>
                            </div>
                            <div class="card-body">
                                <?php echo $message; ?>
                                <?php if ($order_id > 0 && !isset($_POST['confirm_delete'])): // Show form only if not confirmed yet ?>
                                    <form action="delete_lab_order.php?order_id=<?php echo htmlspecialchars($order_id); ?>" method="POST">
                                        <input type="hidden" name="confirm_delete" value="1">
                                        <button type="submit" class="btn btn-danger">Yes, Delete This Order</button>
                                        <a href="laboratory.php" class="btn btn-secondary">No, Go Back</a>
                                    </form>
                                <?php endif; ?>
                            </div>
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
<script>
    $(document).ready(function() {
        // Highlight sidebar menu item
        // showMenuSelected("#mnu_laboratory", "#mi_manage_lab_orders"); // Uncomment if you have this function
    });
</script>
</body>
</html>