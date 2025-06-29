<?php
// users.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("location:index.php");
    exit;
}

include './config/connection.php';
include './common_service/common_functions.php'; // For any helper functions

$message = '';
$current_action = 'list'; // Default action is to list users
$user_to_edit = null; // Holds user data if action is 'edit' or 'view'

// --- Fetch Clinic Details by reading index.php content (Replicate from reports.php) ---
$clinic_name = "KDCS Clinic (Default)";
$clinic_email = "info@kdcsclinic.com (Default)";
$clinic_address = "Address Not Found (Default)";
$clinic_phone = "Phone Not Found (Default)";

$index_content = @file_get_contents('index.php');

if ($index_content !== false) {
    if (preg_match('/<h1><strong>(.*?)<\/strong><\/h1>/', $index_content, $matches)) {
        $clinic_name = strip_tags($matches[1]);
    }
    if (preg_match('/<hr>\s*<h3>Caring for a BETTER Life\?<\/h3>\s*<p>(.*?)<\/p>/s', $index_content, $matches)) {
        $clinic_address = strip_tags($matches[1]);
        $clinic_address = preg_replace('/^Visit us @\s*/', '', $clinic_address);
    }
    if (preg_match('/<hr>\s*<h3>Caring for a BETTER Life\?<\/h3>\s*<p>.*?<\/p>\s*<p><strong>Email:\s*<\/strong>(.*?)\s*\|\s*<strong>Office No.:<\/strong>(.*?)\s*\|\s*<strong>Doctor\'s No.:\s*<\/strong>(.*?)<\/p>/s', $index_content, $matches_contact)) {
        $clinic_email = trim($matches_contact[1]);
        $clinic_phone = trim($matches_contact[2]) . " / " . trim($matches_contact[3]);
    }
}
// --- END Fetch Clinic Details ---


// --- Handle POST requests (Save, Update) ---
if(isset($_POST['save_user'])) {
    $displayName = $_POST['display_name'];
    $username = $_POST['username'];
    $password = $_POST['password'];
    $email = $_POST['email'];
    $userType = $_POST['user_type'];

    $encryptedPassword = md5($password);

    try {
        $con->beginTransaction();

        $check_query = "SELECT COUNT(*) FROM `users` WHERE `username` = :username";
        $check_stmt = $con->prepare($check_query);
        $check_stmt->bindParam(':username', $username, PDO::PARAM_STR);
        $check_stmt->execute();
        $count = $check_stmt->fetchColumn();

        if ($count > 0) {
            $message = 'This username already exists. Please choose a different one.';
        } else {
            $query = "INSERT INTO `users`(`display_name`, `username`, `password`, `email`, `user_type`)
                      VALUES(:display_name, :username, :encrypted_password, :email, :user_type);";
            $stmtUser = $con->prepare($query);
            $stmtUser->bindParam(':display_name', $displayName, PDO::PARAM_STR);
            $stmtUser->bindParam(':username', $username, PDO::PARAM_STR);
            $stmtUser->bindParam(':encrypted_password', $encryptedPassword, PDO::PARAM_STR);
            $stmtUser->bindParam(':email', $email, PDO::PARAM_STR);
            $stmtUser->bindParam(':user_type', $userType, PDO::PARAM_STR);
            $stmtUser->execute();
            $con->commit();
            $message = 'User registered successfully!';
        }
    } catch(PDOException $ex) {
        $con->rollback();
        error_log("PDO Error in users.php (save_user): " . $ex->getMessage());
        $message = 'Database error: ' . htmlspecialchars($ex->getMessage());
        if ($ex->getCode() == '23000' && strpos($ex->getMessage(), 'email') !== false) {
            $message = 'This email address is already registered.';
        }
    }
    header("location:users.php?message=" . urlencode($message));
    exit;
}

if(isset($_POST['update_user'])) {
    $userId = $_POST['user_id'] ?? null;
    $displayName = $_POST['display_name'] ?? null;
    $username = $_POST['username'] ?? null;
    $email = $_POST['email'] ?? null;
    $userType = $_POST['user_type'] ?? null;
    $newPassword = $_POST['password'] ?? null;

    if (!$userId || !$displayName || !$username || !$email || !$userType) {
        $message = 'Missing required fields for update.';
    } else {
        try {
            $con->beginTransaction();

            $check_query = "SELECT COUNT(*) FROM `users` WHERE `username` = :username AND `id` != :id";
            $check_stmt = $con->prepare($check_query);
            $check_stmt->bindParam(':username', $username, PDO::PARAM_STR);
            $check_stmt->bindParam(':id', $userId, PDO::PARAM_INT);
            $check_stmt->execute();
            $count = $check_stmt->fetchColumn();

            if ($count > 0) {
                $message = 'This username is already taken by another user. Please choose a different one.';
            } else {
                $setClauses = [];
                $params = [':id' => $userId];

                $setClauses[] = "`display_name` = :display_name";
                $params[':display_name'] = $displayName;

                $setClauses[] = "`username` = :username";
                $params[':username'] = $username;

                $setClauses[] = "`email` = :email";
                $params[':email'] = $email;

                $setClauses[] = "`user_type` = :user_type";
                $params[':user_type'] = $userType;

                if (!empty($newPassword)) {
                    $setClauses[] = "`password` = :password";
                    $params[':password'] = md5($newPassword);
                }

                if (empty($setClauses)) {
                    $message = 'No changes detected to update.';
                } else {
                    $query = "UPDATE `users` SET " . implode(', ', $setClauses) . " WHERE `id` = :id";
                    $stmt = $con->prepare($query);
                    foreach ($params as $key => $value) {
                        $stmt->bindValue($key, $value);
                    }
                    $stmt->execute();
                    $con->commit();
                    $message = 'User updated successfully.';
                }
            }
        } catch (PDOException $ex) {
            $con->rollback();
            error_log("PDO Error in users.php (update_user): " . $ex->getMessage());
            $message = 'Database error during update: ' . htmlspecialchars($ex->getMessage());
            if ($ex->getCode() == '23000' && strpos($ex->getMessage(), 'email') !== false) {
                $message = 'This email address is already registered for another user.';
            }
        }
    }
    header("location:users.php?message=" . urlencode($message));
    exit;
}

// --- Handle GET requests for actions (View, Edit, Delete) and Print ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $current_action = $_GET['action'];
    $userId = $_GET['id'];

    if ($current_action == 'delete') {
        try {
            $con->beginTransaction();
            $query = "DELETE FROM `users` WHERE `id` = :id";
            $stmt = $con->prepare($query);
            $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            $con->commit();
            if ($stmt->rowCount() > 0) {
                $message = "User deleted successfully.";
            } else {
                $message = "User not found or already deleted.";
            }
        } catch (PDOException $ex) {
            $con->rollback();
            error_log("PDO Error in users.php (delete_user): " . $ex->getMessage());
            $message = "Error deleting user: " . htmlspecialchars($ex->getMessage());
            if ($ex->getCode() == '23000') {
                 $message = "Cannot delete user. Related records (e.g., created reports) exist. Please check your database integrity rules.";
            }
        }
        header("location:users.php?message=" . urlencode($message));
        exit;
    } elseif ($current_action == 'view' || $current_action == 'edit') {
        try {
            $query = "SELECT `id`, `display_name`, `username`, `email`, `user_type` FROM `users` WHERE `id` = :id";
            $stmt = $con->prepare($query);
            $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            $user_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user_to_edit) {
                $message = "User not found.";
                $current_action = 'list'; // Fallback to list if user not found
            }
        } catch (PDOException $ex) {
            error_log("PDO Error in users.php (fetch single user): " . $ex->getMessage());
            $message = "Database error fetching user details: " . htmlspecialchars($ex->getMessage());
            $current_action = 'list'; // Fallback to list on error
        }
    }
}

// --- Print Action Handler (New) ---
if (isset($_GET['print_action'])) {
    $action = $_GET['print_action'];
    $report_message = '';
    $report_data = [];
    $report_title = '';

    if ($action === 'all_users_report') {
        $report_title = "All Users Report";
        $report_columns = [
            'S.No', 'Display Name', 'Username', 'Email', 'User Type'
        ];
        $query = "SELECT `id`, `display_name`, `username`, `email`, `user_type` FROM `users` ORDER BY `display_name` ASC;";
        try {
            $stmt = $con->prepare($query);
            $stmt->execute();
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $report_message = "<div class='alert alert-danger'>Database error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        $report_message = "<div class='alert alert-danger'>Invalid report action requested.</div>";
    }

    // Now, render the print-specific HTML for the All Users report
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($report_title); ?></title>
        <link rel="stylesheet" href="plugins/bootstrap/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px; }
            .report-title { text-align: center; margin-top: 20px; margin-bottom: 20px; font-size: 1.5em; color: #000; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 0.9em; }
            th { background-color: #e6ffe6; color: #333; }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .no-print { display: none; }

            /* New Flexbox styling for header */
            .header-content {
                display: flex; /* Enable Flexbox */
                align-items: center; /* Vertically align items in the center */
                justify-content: center; /* Center the entire flex container */
                margin-bottom: 10px; /* Space between header content and border */
            }

            .header-content img {
                width: 100px; /* Adjust size as needed */
                height: 100px; /* Adjust size as needed */
                border-radius: 50%;
                object-fit: cover;
            }

            .header-info {
                flex-grow: 1; /* Allows this div to take up available space */
                text-align: center; /* Center text within this div */
                margin: 0 15px; /* Add horizontal margin between image and text, and text and image */
            }

            /* Adjust image margins specifically for flexbox */
            .header-content img:first-child {
                margin-right: 20px; /* Space after the first logo */
            }

            .header-content img:last-child {
                margin-left: 20px; /* Space before the last logo */
            }


            /* --- IMPORTANT: Force print visibility and borders for tables --- */
            @media print {
                .no-print { display: none !important; }
                body { margin: 0; padding: 0; }
                .header { border-bottom: 1px solid #ccc; padding-bottom: 5px; margin-bottom: 10px; }
                /* Ensure flexbox properties are retained for print */
                .header-content {
                    display: flex !important;
                    align-items: center !important;
                    justify-content: center !important;
                }
                .header-content img {
                    width: 80px !important; /* Slightly smaller logos for print */
                    height: 80px !important;
                }
                .header-info {
                    flex-grow: 1 !important;
                    margin: 0 10px !important; /* Adjust margins for print */
                }
                .header-content img:first-child {
                    margin-right: 15px !important;
                }
                .header-content img:last-child {
                    margin-left: 15px !important;
                }

                table {
                    page-break-inside: auto;
                    border-collapse: collapse !important; /* Ensure borders are not collapsed away */
                    width: 100% !important; /* Ensure table takes full width */
                }
                th, td {
                    border: 1px solid #000 !important; /* Force black borders for visibility */
                    padding: 5px !important; /* Ensure padding is not stripped */
                    background-color: #fff !important; /* Ensure no strange background colors */
                    color: #000 !important; /* Ensure text is black */
                    display: table-cell !important; /* Ensure cells are displayed */
                    vertical-align: top !important; /* Align content to top */
                }
                thead { display: table-header-group !important; }
                tfoot { display: table-footer-group !important; }
                tr { page-break-inside: avoid !important; page-break-after: auto !important; }
            }
            /* --- END IMPORTANT: Force print visibility and borders for tables --- */
        </style>
    </head>
    <body>
        <div class="header">
            <div class="header-content">
                <img src="dist/img/logo.png" alt="Logo 1">
                <div class="header-info">
                    <h2 style="margin: 0; padding: 0; color: #333;"><?php echo htmlspecialchars(strtoupper($clinic_name)); ?></h2>
                    <p style="margin: 0; padding: 0; font-size: 0.9em; color: #555;"><?php echo htmlspecialchars($clinic_address); ?></p>
                    <p style="margin: 0; padding: 0; font-size: 0.9em; color: #555;">
                        <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($clinic_email); ?> |
                        <i class="fas fa-phone"></i> <?php echo htmlspecialchars($clinic_phone); ?>
                    </p>
                </div>
                <img src="dist/img/logo2.png" alt="Logo 2">
            </div>
        </div>

        <div class="report-title">
            <h3><?php echo htmlspecialchars($report_title); ?></h3>
        </div>

        <?php if (!empty($report_message)): ?>
            <?php echo $report_message; ?>
        <?php else: ?>
            <?php if (empty($report_data)): ?>
                <p class="text-center">No users found.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <?php foreach ($report_columns as $col): ?>
                                <th><?php echo htmlspecialchars($col); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $serial = 0;
                        foreach ($report_data as $row):
                            $serial++;
                        ?>
                        <tr>
                            <td><?php echo $serial; ?></td>
                            <td><?php echo htmlspecialchars($row['display_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['username']); ?></td>
                            <td><?php echo htmlspecialchars($row['email']); ?></td>
                            <td><?php echo htmlspecialchars($row['user_type']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>

        <div class="text-center mt-4 no-print">
            <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> Print Report</button>
        </div>

        <script src="plugins/jquery/jquery.min.js"></script>
        <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
    exit; // Crucial to exit after printing HTML
}
// --- END Print Action Handler ---

// --- Fetch all users for the table display (always needed for the list view) ---
$queryUsers = "SELECT `id`, `display_name`, `username`, `email`, `user_type` FROM `users` ORDER BY `display_name` ASC;";
$stmtUsers = '';

try {
    $stmtUsers = $con->prepare($queryUsers);
    $stmtUsers->execute();

} catch(PDOException $ex) {
      error_log("PDO Error in users.php (fetch all users): " . $ex->getMessage());
      $message = "<div class='alert alert-danger'>Error loading user list: " . htmlspecialchars($ex->getMessage()) . "</div>";
    }

// Check for messages from redirection (re-gets message if set)
if(isset($_GET['message'])) {
    $message = "<div class='alert alert-info alert-dismissible fade show'>" . htmlspecialchars($_GET['message']) . "<button type='button' class='close' data-dismiss='alert' aria-label='Close'><span aria-hidden='true'>&times;</span></button></div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
 <?php include './config/site_css_links.php';?>
 <?php include './config/data_tables_css.php';?>
 <title>Users - Clinic's Patient Management System in PHP</title>
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
              <h1>Users</h1>
            </div>
          </div>
        </div></section>
      <section class="content">
        <?php if (!empty($message)): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <?php if ($current_action == 'edit' && $user_to_edit): ?>
            <div class="card card-outline card-success rounded-0 shadow">
                <div class="card-header">
                    <h3 class="card-title">Edit User: <?php echo htmlspecialchars($user_to_edit['display_name']); ?></h3>
                    <div class="card-tools">
                        <a href="users.php" class="btn btn-tool" title="Back to List">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <form method="post" action="users.php">
                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user_to_edit['id']); ?>">
                        <div class="row">
                            <div class="col-lg-6 col-md-6 col-sm-12">
                                <div class="form-group">
                                    <label>Display Name</label>
                                    <input type="text" name="display_name" required="required"
                                           class="form-control form-control-sm rounded-0"
                                           value="<?php echo htmlspecialchars($user_to_edit['display_name']); ?>" />
                                </div>
                            </div>
                            <div class="col-lg-6 col-md-6 col-sm-12">
                                <div class="form-group">
                                    <label>Username</label>
                                    <input type="text" name="username" required="required"
                                           class="form-control form-control-sm rounded-0"
                                           value="<?php echo htmlspecialchars($user_to_edit['username']); ?>" />
                                </div>
                            </div>
                            <div class="col-lg-6 col-md-6 col-sm-12">
                                <div class="form-group">
                                    <label>Email</label>
                                    <input type="email" name="email" required="required"
                                           class="form-control form-control-sm rounded-0"
                                           value="<?php echo htmlspecialchars($user_to_edit['email']); ?>" />
                                </div>
                            </div>
                            <div class="col-lg-6 col-md-6 col-sm-12">
                                <div class="form-group">
                                    <label>User Type</label>
                                    <select name="user_type" required="required"
                                            class="form-control form-control-sm rounded-0">
                                        <option value="">Select Role</option>
                                        <option value="Admin" <?php echo ($user_to_edit['user_type'] == 'Admin') ? 'selected' : ''; ?>>Admin</option>
                                        <option value="Secretary" <?php echo ($user_to_edit['user_type'] == 'Secretary') ? 'selected' : ''; ?>>Secretary</option>
                                        <option value="Scanning Room" <?php echo ($user_to_edit['user_type'] == 'Scanning Room') ? 'selected' : ''; ?>>Scanning Room</option>
                                        <option value="Lab Technician" <?php echo ($user_to_edit['user_type'] == 'Lab Technician') ? 'selected' : ''; ?>>Lab Technician</option>
                                        <option value="Doctor" <?php echo ($user_to_edit['user_type'] == 'Doctor') ? 'selected' : ''; ?>>Doctor</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-lg-6 col-md-6 col-sm-12">
                                <div class="form-group">
                                    <label>New Password (leave blank to keep current)</label>
                                    <input type="password" name="password"
                                           class="form-control form-control-sm rounded-0" />
                                </div>
                            </div>
                            <div class="col-lg-12 text-right">
                                <label>&nbsp;</label>
                                <button type="submit" name="update_user" class="btn btn-success btn-sm btn-flat">Save Changes</button>
                                <a href="users.php" class="btn btn-danger btn-sm btn-flat">Cancel</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php elseif ($current_action == 'view' && $user_to_edit): ?>
            <div class="card card-outline card-success rounded-0 shadow">
                <div class="card-header">
                    <h3 class="card-title">User Details: <?php echo htmlspecialchars($user_to_edit['display_name']); ?></h3>
                    <div class="card-tools">
                        <a href="users.php" class="btn btn-tool" title="Back to List">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>ID:</strong> <?php echo htmlspecialchars($user_to_edit['id']); ?></p>
                            <p><strong>Display Name:</strong> <?php echo htmlspecialchars($user_to_edit['display_name']); ?></p>
                            <p><strong>Username:</strong> <?php echo htmlspecialchars($user_to_edit['username']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Email:</strong> <?php echo htmlspecialchars($user_to_edit['email']); ?></p>
                            <p><strong>User Type:</strong> <?php echo htmlspecialchars($user_to_edit['user_type']); ?></p>
                        </div>
                    </div>
                    <div class="mt-3 text-right">
                        <a href="users.php?action=edit&id=<?php echo htmlspecialchars($user_to_edit['id']); ?>" class="btn btn-primary btn-sm btn-flat">Edit User</a>
                        <a href="users.php" class="btn btn-danger btn-sm btn-flat">Back to List</a>
                    </div>
                </div>
            </div>
        <?php else: // Default list view with Add User form ?>
            <div class="card card-outline card-success rounded-0 shadow">
              <div class="card-header">
                <h3 class="card-title">Add User</h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
                    <i class="fas fa-minus"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
                <form method="post" action="users.php">
                 <div class="row">

                  <div class="col-lg-4 col-md-4 col-sm-4 col-xs-10">
                    <label>Display Name</label>
                    <input type="text" id="display_name" name="display_name" required="required"
                    class="form-control form-control-sm rounded-0" />
                  </div>

                  <div class="col-lg-4 col-md-4 col-sm-4 col-xs-10">
                    <label>Username</label>
                    <input type="text" id="username" name="username" required="required"
                    class="form-control form-control-sm rounded-0" />
                  </div>

                  <div class="col-lg-4 col-md-4 col-sm-4 col-xs-10">
                    <label>Password</label>
                    <input type="password" id="password"
                    name="password" required="required"
                    class="form-control form-control-sm rounded-0" />
                  </div>

                  <div class="col-lg-4 col-md-4 col-sm-4 col-xs-10">
                    <label>Email</label>
                    <input type="email" id="email"
                    name="email" required="required"
                    class="form-control form-control-sm rounded-0" />
                  </div>

                  <div class="col-lg-4 col-md-4 col-sm-4 col-xs-10">
                    <label>User Type</label>
                    <select id="user_type" name="user_type" required="required"
                    class="form-control form-control-sm rounded-0">
                      <option value="">Select User Type</option>
                      <option value="Admin">Admin</option>
                      <option value="Secretary">Secretary</option>
                      <option value="Scanning Room">Scanning Room</option>
                      <option value="Lab Technician">Lab Technician</option>
                      <option value="Doctor">Doctor</option>
                    </select>
                  </div>

                  <div class="col-lg-1 col-md-2 col-sm-2 col-xs-2">
                    <label>&nbsp;</label>
                    <button type="submit" id="save_user"
                    name="save_user" class="btn btn-success btn-sm btn-flat btn-block">Save</button>
                  </div>
                </div>
              </form>
            </div>

          </div>
          <div class="card card-outline card-success rounded-0 shadow">
            <div class="card-header">
              <h3 class="card-title">All Users</h3>
              <div class="card-tools">
                <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
                  <i class="fas fa-minus"></i>
                </button>
              </div>
            </div>
            <div class="card-body">
             <div class="row table-responsive">

              <table id="all_users"
              class="table table-striped dataTable table-bordered dtr-inline"
              role="grid" aria-describedby="all_users_info">
              <colgroup>
                <col width="5%">
                <col width="25%">
                <col width="20%">
                <col width="25%">
                <col width="15%">
                <col width="10%">
              </colgroup>
              <thead>
                <tr>
                 <th class="p-1 text-center">S.No</th>
                 <th class="p-1 text-center">Display Name</th>
                 <th class="p-1 text-center">Username</th>
                 <th class="p-1 text-center">Email</th>
                 <th class="p-1 text-center">User Type</th>
                 <th class="p-1 text-center">Action</th>
               </tr>
             </thead>

             <tbody>
              <?php
              $serial = 0;
              if (!isset($_GET['print_action']) && $stmtUsers) {
                  try {
                    $stmtUsers->execute();
                    while($row = $stmtUsers->fetch(PDO::FETCH_ASSOC)) {
                     $serial++;
                     ?>
                     <tr>
                       <td class="px-2 py-1 align-middle text-center"><?php echo $serial;?></td>
                       <td class="px-2 py-1 align-middle"><?php echo htmlspecialchars($row['display_name']);?></td>
                       <td class="px-2 py-1 align-middle"><?php echo htmlspecialchars($row['username']);?></td>
                       <td class="px-2 py-1 align-middle"><?php echo htmlspecialchars($row['email']);?></td>
                       <td class="px-2 py-1 align-middle"><?php echo htmlspecialchars($row['user_type']);?></td>

                       <td class="px-2 py-1 align-middle text-center">
                          <a href="users.php?action=view&id=<?php echo htmlspecialchars($row['id']); ?>" class="btn btn-info btn-sm btn-flat" title="View Details">
                            <i class="fa fa-eye"></i>
                          </a>
                          <a href="users.php?action=edit&id=<?php echo htmlspecialchars($row['id']); ?>" class="btn btn-success btn-sm btn-flat" title="Edit User">
                            <i class="fa fa-edit"></i>
                          </a>
                          <a href="users.php?action=delete&id=<?php echo htmlspecialchars($row['id']); ?>" class="btn btn-danger btn-sm btn-flat delete_user_confirm" title="Delete User">
                            <i class="fa fa-trash"></i>
                          </a>
                        </td>
                   </tr>
                 <?php }
                  } catch (PDOException $e) {
                      echo "<tr><td colspan='6'><div class='alert alert-danger'>Error fetching users for table: " . htmlspecialchars($e->getMessage()) . "</div></td></tr>";
                  }
              }
              ?>
         </tbody>
       </table>
     </div>
    </div>
    </div>
    <?php endif; ?>

    </section>
    </div>
    <?php
include './config/footer.php';
?>
</div>
<?php include './config/site_js_links.php'; ?>
<?php include './config/data_tables_js.php'; ?>
<script src="plugins/moment/moment.min.js"></script>
<script src="plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js"></script>
<script src="plugins/datatables-buttons/js/buttons.print.min.js"></script>


<script>
  showMenuSelected("#mnu_users", "");

  $(document).ready(function() {

    // Initialize DataTables for users
    if ($('#all_users').length) {
        $("#all_users").DataTable({
            "responsive": true,
            "lengthChange": false,
            "autoWidth": false,
            "buttons": [
                // Only include the 'print' button here from DataTables if desired
                {
                    extend: 'print',
                    text: '<i class="fas fa-print"></i> Print Table', // Text for the DataTables print button
                    exportOptions: {
                        columns: [0, 1, 2, 3, 4] // Exclude the Action column
                    },
                    customize: function (win) {
                        $(win.document.body).find('h1').css('text-align', 'center').text('All Users Report'); // Customize title
                        $(win.document.body).find('table')
                            .addClass('compact')
                            .css('font-size', 'inherit');

                        // Add clinic details and logos for DataTables print
                        var clinicName = "<?php echo strtoupper(addslashes($clinic_name)); ?>";
                        var clinicAddress = "<?php echo addslashes($clinic_address); ?>";
                        var clinicEmail = "<?php echo addslashes($clinic_email); ?>";
                        var clinicPhone = "<?php echo addslashes($clinic_phone); ?>";

                        var headerHtml = `
                            <div style="display: flex; align-items: center; justify-content: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px;">
                                <img src="dist/img/logo.png" alt="Logo 1" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; margin-right: 20px;">
                                <div style="text-align: center; flex-grow: 1;">
                                    <h2 style="margin: 0; padding: 0; color: #333;">${clinicName}</h2>
                                    <p style="margin: 0; padding: 0; font-size: 0.9em; color: #555;">${clinicAddress}</p>
                                    <p style="margin: 0; padding: 0; font-size: 0.9em; color: #555;">
                                        <i class="fas fa-envelope"></i> ${clinicEmail} |
                                        <i class="fas fa-phone"></i> ${clinicPhone}
                                    </p>
                                </div>
                                <img src="dist/img/logo2.png" alt="Logo 2" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; margin-left: 20px;">
                            </div>
                        `;

                        $(win.document.body).prepend(headerHtml); // Add header at the very top
                    }
                },
            ]
        }).buttons().container().appendTo('#all_users_wrapper .col-md-6:eq(0)');
    }


    // JavaScript for delete confirmation for direct link
    $(".delete_user_confirm").on("click", function(e) {
        e.preventDefault();
        var deleteUrl = $(this).attr("href");
        if (confirm("Are you sure you want to delete this user? This action cannot be undone.")) {
            window.location.href = deleteUrl;
        }
    });

    // Only keep this direct print button, which opens the PHP-generated print page.
    $("#print_all_users_direct").click(function() {
        var win = window.open("users.php?print_action=all_users_report", "_blank");
        if(win) {
            win.focus();
            win.print();
        } else {
            alert('Please allow popups for printing.');
        }
    });

  });
</script>
</body>
</html>