<?php
// test_patient_fetch.php

// Include your database connection
include './config/connection.php';

// Temporarily enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ensure the PDO connection attribute for errors is set
if (isset($con)) {
    $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Database connection object (\$con) is available.<br>";
} else {
    echo "ERROR: Database connection object (\$con) is NOT available. Check connection.php.<br>";
    exit(); // Stop execution if no connection
}

// --- Copy the fetchPatients function here directly for isolated testing ---
// Make sure this is the version with the try-catch block for PDO exceptions
function fetchPatientsTest($con_param, $selectedPatientId = '') {
    $options = '';
    $query = "SELECT `id`, `patient_name` FROM `patients` ORDER BY `patient_name` ASC";
    $stmt = $con_param->prepare($query);
    try {
        $stmt->execute();
    } catch (PDOException $ex) {
        // Echo the exact error message for debugging
        echo "PDO Exception in fetchPatientsTest: " . htmlspecialchars($ex->getMessage()) . "<br>";
        error_log("Error in fetchPatientsTest: " . $ex->getMessage());
        return '<option value="">ERROR: Could not load patients</option>';
    }

    $options = '<option value="">Select Patient</option>'; // Default option

    if ($stmt->rowCount() > 0) {
        echo "Patients found in database. Generating options...<br>";
    } else {
        echo "No patients found in the 'patients' table.<br>";
    }


    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $selected = ($selectedPatientId == $row['id']) ? 'selected' : '';
        $options .= '<option value="' . htmlspecialchars($row['id']) . '" ' . $selected . '>' . htmlspecialchars($row['patient_name']) . '</option>';
    }
    return $options;
}
// --- End fetchPatients function copy ---


echo "Attempting to fetch patients...<br>";

// Call the function using the $con from connection.php
$patientOptions = fetchPatientsTest($con);

echo "<h2>Generated Patient Options:</h2>";
echo "<select>";
echo $patientOptions;
echo "</select>";

echo "<br>Test complete.";

?>