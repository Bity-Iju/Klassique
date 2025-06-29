<?php
session_start();
// Adjust path to connection.php based on where your 'ajax' folder is located relative to 'config'
include '../config/connection.php'; 

$response = ['status' => 'error', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id'])) {
    $medicine_id = $_POST['id'];

    try {
        $con->beginTransaction();

        $stmt = $con->prepare("DELETE FROM medicines WHERE id = ?");
        if ($stmt->execute([$medicine_id])) {
            $con->commit();
            $response['status'] = 'success';
            $response['message'] = 'Medicine deleted successfully!';
        } else {
            $con->rollback();
            $response['message'] = 'Failed to delete medicine.';
        }
    } catch (PDOException $e) {
        $con->rollback();
        $response['message'] = 'Database error: ' . $e->getMessage();
    }
} else {
    $response['message'] = 'Invalid request.';
}

echo json_encode($response);
exit();
?>