<?php
// fetch_unbilled_services_ajax.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require_once 'config/connection.php'; // Your database connection

$patient_id = $_GET['patient_id'] ?? null;

if (!$patient_id) {
    echo json_encode(['status' => 'error', 'message' => 'Patient ID is required.']);
    exit;
}

$response = [
    'status' => 'success',
    'services' => [] // This will hold all billable items
];

try {
    // 1. Fetch unbilled patient visits (patient-specific)
    $stmt_visits = $con->prepare("SELECT id, consultation_fee, visit_date FROM patient_visits WHERE patient_id = ? AND billed_to_bill_id IS NULL ORDER BY visit_date ASC");
    $stmt_visits->execute([$patient_id]);
    while ($row = $stmt_visits->fetch(PDO::FETCH_ASSOC)) {
        $response['services'][] = [
            'type' => 'visit',
            'id' => $row['id'],
            'amount' => floatval($row['consultation_fee']),
            'description' => "Consultation (" . date('Y-m-d', strtotime($row['visit_date'])) . ")"
        ];
    }

    // 2. Fetch unbilled prescriptions (patient-specific)
    $stmt_prescriptions = $con->prepare("SELECT id, total_price, prescription_date FROM prescriptions WHERE patient_id = ? AND billed_to_bill_id IS NULL ORDER BY prescription_date ASC");
    $stmt_prescriptions->execute([$patient_id]);
    while ($row = $stmt_prescriptions->fetch(PDO::FETCH_ASSOC)) {
        $response['services'][] = [
            'type' => 'prescription',
            'id' => $row['id'],
            'amount' => floatval($row['total_price']),
            'description' => "Prescription (" . date('Y-m-d', strtotime($row['prescription_date'])) . ")"
        ];
    }

    // 3. Fetch unbilled scans (patient-specific)
    $stmt_scans = $con->prepare("SELECT id, scan_cost, scan_date FROM scans WHERE patient_id = ? AND billed_to_bill_id IS NULL ORDER BY scan_date ASC");
    $stmt_scans->execute([$patient_id]);
    while ($row = $stmt_scans->fetch(PDO::FETCH_ASSOC)) {
        $response['services'][] = [
            'type' => 'scan',
            'id' => $row['id'],
            'amount' => floatval($row['scan_cost']),
            'description' => "Scan (" . date('Y-m-d', strtotime($row['scan_date'])) . ")"
        ];
    }

    // 4. Fetch *existing unbilled* lab orders (patient-specific)
    // These are lab orders that have already been placed for the patient but not yet billed.
    // If you always create a new lab_order entry upon billing, this section might become redundant.
    $stmt_lab_orders_existing = $con->prepare("SELECT lo.id, lo.total_amount, lo.order_date, ltc.test_name
                                                FROM lab_orders lo
                                                LEFT JOIN lab_test_catalog ltc ON lo.test_catalog_id = ltc.id
                                                WHERE lo.patient_id = ? AND lo.billed_to_bill_id IS NULL ORDER BY lo.order_date ASC");
    $stmt_lab_orders_existing->execute([$patient_id]);
    while ($row = $stmt_lab_orders_existing->fetch(PDO::FETCH_ASSOC)) {
        $test_name = $row['test_name'] ?? 'Generic Lab Order';
        $response['services'][] = [
            'type' => 'lab_order', // Mark as 'lab_order' to signify it's an existing order
            'id' => $row['id'],
            'amount' => floatval($row['total_amount']),
            'description' => "Lab Order - " . htmlspecialchars($test_name) . " (" . date('Y-m-d', strtotime($row['order_date'])) . ")"
        ];
    }


    // 5. Fetch all predefined lab tests from lab_test_catalog (static options)
    // These are tests that can be 'ordered' and billed directly from this form.
    $stmt_catalog_lab_tests = $con->prepare("SELECT id, test_name, price FROM lab_test_catalog ORDER BY test_name ASC");
    $stmt_catalog_lab_tests->execute();
    while ($row = $stmt_catalog_lab_tests->fetch(PDO::FETCH_ASSOC)) {
        $response['services'][] = [
            'type' => 'new_lab_test', // Differentiate this type
            'id' => $row['id'], // This is the catalog ID
            'amount' => floatval($row['price']),
            'description' => htmlspecialchars($row['test_name']) . " (New Lab Test)"
        ];
    }

    // Optional: Sort all services by type and then description or date for better display
    usort($response['services'], function($a, $b) {
        $typeOrder = ['visit' => 1, 'prescription' => 2, 'scan' => 3, 'lab_order' => 4, 'new_lab_test' => 5];
        $typeA = $typeOrder[$a['type']] ?? 99;
        $typeB = $typeOrder[$b['type']] ?? 99;

        if ($typeA !== $typeB) {
            return $typeA - $typeB;
        }
        return strcmp($a['description'], $b['description']);
    });


} catch (PDOException $e) {
    error_log("Error fetching services: " . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()];
}

echo json_encode($response);
exit;