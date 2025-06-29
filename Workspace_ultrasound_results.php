<?php
// fetch_ultrasound_results.php
session_start();
header('Content-Type: application/json'); // Indicate that the response is JSON

// Include your database connection
include './config/connection.php'; // Adjust path if necessary

$response = [];

if (isset($_POST['category_id']) && !empty($_POST['category_id'])) {
    $categoryId = intval($_POST['category_id']);

    try {
        // Use the getUltrasoundResultsForCategory function from common_functions.php
        $results = getUltrasoundResultsForCategory($con, $categoryId);
        $response = $results;

    } catch (PDOException $e) {
        error_log("Error fetching ultrasound results (PDOException): " . $e->getMessage());
        $response = ['error' => 'Database error fetching results.'];
    } catch (Exception $e) {
        error_log("General Error fetching ultrasound results: " . $e->getMessage());
        $response = ['error' => 'An unexpected error occurred.'];
    }
} else {
    $response = ['error' => 'No category ID provided.'];
}

echo json_encode($response);
?>