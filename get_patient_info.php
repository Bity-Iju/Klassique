<?php
// get_patient_info.php
include './config/connection.php'; // Include your database connection

header('Content-Type: application/json'); // Set header for JSON response

$patientId = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;

$patientData = [];

if ($patientId > 0) {
    try {
        $query = "SELECT `cnic`, `dob`, `contact_no`, `gender`, `marital_status`, `next_appointment_date` FROM `patients` WHERE `id` = :patient_id";
        $stmt = $con->prepare($query);
        $stmt->bindParam(':patient_id', $patientId, PDO::PARAM_INT);
        $stmt->execute();
        $patientData = $stmt->fetch(PDO::FETCH_ASSOC);

        // Handle null dates for JSON output
        if ($patientData && $patientData['next_appointment_date'] === null) {
            $patientData['next_appointment_date'] = '';
        }

    } catch (PDOException $e) {
        error_log("Error fetching patient info: " . $e->getMessage());
        // Return empty array or error indicator on failure
        $patientData = [];
    }
}

echo json_encode($patientData);
?>