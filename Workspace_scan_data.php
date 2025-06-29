<?php
// This file is a dedicated API endpoint for AJAX requests

// Ensure this path is correct for your database connection relative to this file
include './config/connection.php';

// Set header to indicate a JSON response
header('Content-Type: application/json');

// Check if a scan_type_id was sent (request for scan categories/areas)
if (isset($_POST['scan_type_id'])) {
    $scanTypeId = intval($_POST['scan_type_id']);

    try {
        // Query for ultrasound_categories using 'id' for PK and 'scan_type_id' FK
        $stmt = $con->prepare("SELECT id, category_name, price FROM ultrasound_categories WHERE scan_type_id = ? ORDER BY category_name ASC");
        $stmt->bindParam(1, $scanTypeId, PDO::PARAM_INT);
        $stmt->execute();
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'data' => $categories]);
    } catch (PDOException $e) {
        error_log("PDO Error fetching categories from fetch_scan_data.php: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Database error fetching categories.']);
    }
}
// Check if a category_id was sent (request for possible results)
elseif (isset($_POST['category_id'])) {
    $categoryId = intval($_POST['category_id']);

    try {
        // Query for ultrasound_results using 'id' for PK and 'ultrasound_category_id' FK
        $stmt = $con->prepare("SELECT id, description FROM ultrasound_results WHERE ultrasound_category_id = ? ORDER BY description ASC LIMIT 3");
        $stmt->bindParam(1, $categoryId, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'data' => $results]);
    } catch (PDOException $e) {
        error_log("PDO Error fetching results from fetch_scan_data.php: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Database error fetching results.']);
    }
}
// If no valid parameter is sent, return an invalid request error
else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request to fetch_scan_data.php.']);
}

exit; // Always exit after sending the JSON response in an API endpoint
?>