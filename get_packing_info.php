<?php
include './config/connection.php';

// Ensure the database connection is available
if (!isset($con) || !$con) {
    echo json_encode(['error' => 'Database connection not established.']);
    exit;
}

if (isset($_GET['medicine_detail_id'])) {
    $medicineDetailId = $_GET['medicine_detail_id'];

    // Query to fetch packing based on medicine_detail_id
    $query = "SELECT `packing` FROM `medicine_details` WHERE `id` = :medicine_detail_id";

    try {
        $stmt = $con->prepare($query);
        $stmt->bindParam(':medicine_detail_id', $medicineDetailId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            echo json_encode(['packing' => $result['packing']]);
        } else {
            echo json_encode(['packing' => 'N/A']); // Or handle as an error
        }

    } catch (PDOException $ex) {
        error_log("Error fetching packing info: " . $ex->getMessage());
        echo json_encode(['error' => 'Database error: ' . $ex->getMessage()]);
    }
} else {
    echo json_encode(['error' => 'No medicine_detail_id provided.']);
}
?>