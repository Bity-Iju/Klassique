<?php
session_start();

// Include your database connection (similar to how it's done in reports.php)
// You might have a separate connection.php file, or copy the relevant part
// from reports.php if it's not a separate file.
// For demonstration, I'll emulate the connection as seen in reports.php.

// --- START: Emulating config/connection.php content (or include your actual file) ---
$host = '127.0.0.1';
$dbname = 'pms_db';
$username = 'root'; // IMPORTANT: Use your actual username
$password = '';     // IMPORTANT: Use your actual password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false); // For numeric types
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
// --- END: Emulating config/connection.php content ---

// Check if the request method is POST (assuming form submission)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Get and Sanitize Input Data
    // Replace with the actual fields you expect from your update form
    $scanOrderId = filter_input(INPUT_POST, 'scan_order_id', FILTER_SANITIZE_NUMBER_INT);
    $patientId = filter_input(INPUT_POST, 'patient_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS); // patient_display_id from frontend
    $scanTypeId = filter_input(INPUT_POST, 'scan_type_id', FILTER_SANITIZE_NUMBER_INT);
    $ultrasoundCategoryId = filter_input(INPUT_POST, 'ultrasound_category_id', FILTER_SANITIZE_NUMBER_INT);
    $ultrasoundResultId = filter_input(INPUT_POST, 'ultrasound_result_id', FILTER_SANITIZE_NUMBER_INT);
    $totalPrice = filter_input(INPUT_POST, 'total_price', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $orderDate = filter_input(INPUT_POST, 'order_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $resultNotes = filter_input(INPUT_POST, 'result_notes', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $patientName = filter_input(INPUT_POST, 'patient_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS); // You might need this if you update patient name as well

    // You'll need to fetch the actual patient ID from the patients table
    // based on patient_display_id if you are updating patient details
    $actualPatientId = null;
    if ($patientId) {
        $stmtPatient = $pdo->prepare("SELECT id FROM patients WHERE patient_display_id = ?");
        $stmtPatient->execute([$patientId]);
        $fetchedPatient = $stmtPatient->fetch();
        if ($fetchedPatient) {
            $actualPatientId = $fetchedPatient['id'];
        }
    }


    // 2. Validate Data
    // Basic validation example
    if (empty($scanOrderId) || empty($actualPatientId) || empty($scanTypeId) || empty($totalPrice) || empty($orderDate)) {
        // You might use SweetAlert2 or redirect with an error message
        echo json_encode(['status' => 'error', 'message' => 'Missing required fields.']);
        exit;
    }

    // Convert date to MySQL format if necessary
    $orderDate = date('Y-m-d', strtotime($orderDate));

    try {
        $pdo->beginTransaction(); // Start a transaction for data integrity

        // Update scan_orders table
        $updateScanOrderSql = "
            UPDATE scan_orders
            SET
                patient_id = ?,
                scan_type_id = ?,
                ultrasound_category_id = ?,
                ultrasound_result_id = ?,
                total_price = ?,
                order_date = ?,
                result_notes = ?
            WHERE
                id = ?;
        ";
        $stmtScanOrder = $pdo->prepare($updateScanOrderSql);
        $stmtScanOrder->execute([
            $actualPatientId,
            $scanTypeId,
            $ultrasoundCategoryId,
            $ultrasoundResultId,
            $totalPrice,
            $orderDate,
            $resultNotes,
            $scanOrderId
        ]);

        // Optional: Update patient name if needed (e.g., if you have a separate patient update form or combined)
        // If 'patient_name' can be updated directly from this form
        if ($patientName && $actualPatientId) {
            $updatePatientSql = "
                UPDATE patients
                SET patient_name = ?
                WHERE id = ?;
            ";
            $stmtPatientUpdate = $pdo->prepare($updatePatientSql);
            $stmtPatientUpdate->execute([$patientName, $actualPatientId]);
        }

        $pdo->commit(); // Commit the transaction

        // Send a success response
        echo json_encode(['status' => 'success', 'message' => 'Scan order updated successfully!']);

    } catch (PDOException $e) {
        $pdo->rollBack(); // Rollback on error
        error_log("Error updating scan order: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        $pdo->rollBack(); // Rollback on general error
        error_log("General error updating scan order: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred: ' . $e->getMessage()]);
    }

} else {
    // If not a POST request, perhaps show an error or redirect
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
?>