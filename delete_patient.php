<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("location:index.php");
    exit;
}

include './config/connection.php';

$patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;
$message = '';

if ($patient_id > 0) {
    try {
        $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $con->beginTransaction();

        // Optional: Check for related records (e.g., prescriptions, visits)
        // If you have foreign key constraints with ON DELETE CASCADE,
        // deleting the patient will automatically delete related records.
        // If not, you might need to delete related records first or set them to NULL.
        // For simplicity, this example assumes CASCADE or no critical child dependencies.

        $query = "DELETE FROM `patients` WHERE `id` = :patient_id";
        $stmt = $con->prepare($query);
        $stmt->bindParam(':patient_id', $patient_id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            if ($stmt->rowCount() > 0) {
                $con->commit();
                $message = 'Patient deleted successfully.';
            } else {
                $con->rollBack();
                $message = 'Patient not found or already deleted.';
            }
        } else {
            $con->rollBack();
            $message = 'Error deleting patient.';
        }

    } catch (PDOException $ex) {
        $con->rollBack();
        error_log("PDO Error in delete_patient.php: " . $ex->getMessage());
        $message = 'Database error during deletion: ' . htmlspecialchars($ex->getMessage());
    }
} else {
    $message = 'Invalid patient ID for deletion.';
}

// Redirect back to the patient list with a message
header("location:patients_list.php?message=" . urlencode($message));
exit;
?>