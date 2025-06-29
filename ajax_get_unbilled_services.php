<?php
// ajax_get_unbilled_services.php
session_start();
require_once 'connection.php';

header('Content-Type: application/json');

$patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;

$response = [
    'visits' => [],
    'prescriptions' => [],
    'scans' => [],
    'lab_orders' => []
];

if ($patient_id > 0) {
    try {
        // Fetch unbilled patient visits
        $stmt = $con->prepare("SELECT id, visit_date, disease, consultation_fee FROM patient_visits WHERE patient_id = ? AND billed_to_bill_id IS NULL");
        $stmt->execute([$patient_id]);
        $response['visits'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch unbilled prescriptions
        $stmt = $con->prepare("
            SELECT p.id, p.quantity, p.dosage, p.prescription_date, p.price_at_dispensing, m.medicine_name
            FROM prescriptions p
            JOIN patient_visits pv ON p.patient_visit_id = pv.id
            JOIN medicines m ON p.medicine_detail_id = m.id
            WHERE pv.patient_id = ? AND p.billed_to_bill_id IS NULL
        ");
        $stmt->execute([$patient_id]);
        $response['prescriptions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch unbilled scan orders
        $stmt = $con->prepare("
            SELECT so.scan_order_id, so.order_date, so.price_at_order, st.scan_name
            FROM scan_orders so
            JOIN scanning_types st ON so.scan_type_id = st.scan_type_id
            WHERE so.patient_id = ? AND so.billed_to_bill_id IS NULL
        ");
        $stmt->execute([$patient_id]);
        $response['scans'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch unbilled lab orders and their associated tests with prices
        $stmt = $con->prepare("
            SELECT lo.lab_order_id, lo.order_date
            FROM lab_orders lo
            WHERE lo.patient_id = ? AND lo.billed_to_bill_id IS NULL
        ");
        $stmt->execute([$patient_id]);
        $unbilled_lab_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($unbilled_lab_orders as $lo) {
            $stmt_tests = $con->prepare("
                SELECT lt.test_name, lt.price
                FROM lab_order_tests lot
                JOIN lab_tests lt ON lot.test_id = lt.test_id
                WHERE lot.lab_order_id = ?
            ");
            $stmt_tests->execute([$lo['lab_order_id']]);
            $tests = $stmt_tests->fetchAll(PDO::FETCH_ASSOC);

            $total_price_for_order = array_sum(array_column($tests, 'price'));

            $response['lab_orders'][] = [
                'lab_order_id' => $lo['lab_order_id'],
                'order_date' => $lo['order_date'],
                'tests' => $tests,
                'total_price' => $total_price_for_order
            ];
        }

    } catch (PDOException $e) {
        error_log("AJAX Error fetching unbilled services: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        exit();
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid patient ID.']);
    exit();
}

echo json_encode($response);
?>