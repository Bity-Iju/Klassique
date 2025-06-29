<?php

function fetchPatients($con, $selectedPatientId = '') {
    $options = '';
    $query = "SELECT `id`, `patient_name` FROM `patients` ORDER BY `patient_name` ASC";
    $stmt = $con->prepare($query);
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $selected = ($selectedPatientId == $row['id']) ? 'selected' : '';
        $options .= '<option value="' . htmlspecialchars($row['id']) . '" ' . $selected . '>' . htmlspecialchars($row['patient_name']) . '</option>';
    }
    return $options;
}

function getMedicines($con, $selectedMedicineId = '') {
    $options = '';
    $query = "SELECT `id`, `medicine_name` FROM `medicines` ORDER BY `medicine_name` ASC";
    $stmt = $con->prepare($query);
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $selected = ($selectedMedicineId == $row['id']) ? 'selected' : '';
        $options .= '<option value="' . htmlspecialchars($row['id']) . '" ' . $selected . '>' . htmlspecialchars($row['medicine_name']) . '</option>';
    }
    return $options;
}

// Add other common functions here if needed
// function getPatientInfo($con, $patientId) { ... }
// function getPackingInfo($con, $medicineDetailId) { ... }

?>