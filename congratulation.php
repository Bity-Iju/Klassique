<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("location:index.php");
    exit;
}

// Get the message and the page to go to from the URL parameters
$message = isset($_GET['message']) ? htmlspecialchars($_GET['message']) : 'Operation completed successfully.';
$goto_page = isset($_GET['goto_page']) ? htmlspecialchars($_GET['goto_page']) : 'dashboard.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Success! - KDCS</title>
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
</head>
<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed">
<div class="wrapper">
    <?php include './config/header.php'; ?>
    <?php include './config/sidebar.php'; ?>

    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <div class="row mb-6">
                    <div class="col-sm-6">
                        <h1></h1>
                    </div>
                    <div class="col-sm-5">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="#">Home</a></li>
                            <li class="breadcrumb-item active">Success</li>
                        </ol>
                    </div>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-md-6 offset-md-3">
                        <div class="card card-success">
                            <div class="card-header">
                                <h3 class="card-title">Operation Successful</h3>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-secondary text-center" role="alert">
                                    <i class="icon fas fa-check-circle"></i>
                                    <h4><?php echo $message; ?></h4>
                                </div>
                                <div class="text-center">
                                    <a href="<?php echo $goto_page; ?>" class="btn btn-success btn-lg mt-2">
                                        Go to <b><?php echo ucwords(str_replace('_', ' ', str_replace('.php', '', $goto_page))); ?></b>
                                    </a>
                                    <a href="bill.php" class="btn btn-info btn-lg mt-2 ml-2">
                                        Back to Billing
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        </div>
    <?php include './config/footer.php'; ?>
</div>
<script src="plugins/jquery/jquery.min.js"></script>
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="dist/js/adminlte.min.js"></script>
</body>
</html>