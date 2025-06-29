<?php
// delete_user.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("location:index.php"); // Redirect if not logged in
    exit;
}

include './config/connection.php'; // Adjust path as needed

$message = '';

if (isset($_GET['user_id']) && !empty($_GET['user_id'])) {
    $userId = $_GET['user_id'];

    try {
        $con->beginTransaction();

        // Check if there are any related records in other tables that might prevent deletion
        // Example: If a user created certain reports or other entities, you might need to
        // set ON DELETE CASCADE in your database schema or delete related records first.
        // For simplicity, we are just deleting the user here. If foreign key constraint
        // prevents deletion, a PDOException will be caught.

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
        error_log("Database Error in delete_user.php: " . $ex->getMessage());
        $message = "Error deleting user: " . htmlspecialchars($ex->getMessage());
        // Specific error for foreign key constraint violation
        if ($ex->getCode() == '23000') {
             $message = "Cannot delete user. Related records (e.g., created reports) exist. Please check your database integrity rules.";
        }
    }
} else {
    $message = "No user ID provided for deletion.";
}

header("location:users.php?message=" . urlencode($message));
exit;
?>