<?php
session_start();
if (!(isset($_SESSION['user_id']))) {
    header("location:index.php");
    exit;
}

require_once 'config/connection.php'; // Include your database connection

$page_title = "Bill Receipt"; // For the page title

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error_message'] = "Invalid bill ID.";
    header("location:manage_bills.php");
    exit;
}

$bill_id = $_GET['id'];

// Fetch bill details for display
try {
    $stmt = $con->prepare("SELECT b.id AS bill_id, p.patient_name, p.id AS patient_id, b.total_amount, b.paid_amount, b.due_amount, b.payment_status, b.payment_type, b.created_at
                            FROM bills b
                            JOIN patients p ON b.patient_id = p.id
                            WHERE b.id = ?");
    $stmt->execute([$bill_id]);
    $bill = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$bill) {
        $_SESSION['error_message'] = "Bill not found.";
        header("location:manage_bills.php");
        exit;
    }

} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error fetching bill details: " . $e->getMessage();
    header("location:manage_bills.php");
    exit;
}

// Format patient ID with leading zeros to ensure four digits
$formatted_patient_id = sprintf("%04d", $bill['patient_id']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo $page_title; ?></title>

  <style>
    body {
      font-family: 'monospace', 'Courier New', monospace; /* Monospace font for receipt look */
      font-size: 11px; /* Slightly smaller font for compactness */
      margin: 0;
      padding: 5px; /* Reduced padding */
      width: 280px; /* Narrower width for POS receipt printers */
      max-width: 100%;
      box-sizing: border-box;
    }
    .receipt-container {
      /* Removed dashed border for a cleaner, shorter look */
      padding: 5px;
    }
    .header, .footer {
      text-align: center;
      margin-bottom: 8px; /* Reduced margin */
    }
    .header h3 {
      margin-bottom: 2px;
      font-size: 14px; /* Smaller header font */
    }
    .header p {
      margin: 0;
      line-height: 1.2;
    }
    .line-separator {
      border-top: 1px dashed #333;
      margin: 5px 0;
    }
    .details, .summary {
      margin-top: 8px;
      margin-bottom: 8px;
    }
    .item-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 2px; /* Reduced spacing between lines */
    }
    .total-line {
      font-weight: bold;
      border-top: none; /* Removed redundant top border here */
      padding-top: 5px;
      margin-top: 5px;
    }
    .thank-you {
      text-align: center;
      margin-top: 8px;
    }
    /* Hide non-print elements */
    @media print {
      body {
        width: auto; /* Allow printer to determine width */
        margin: 0;
        padding: 0;
      }
      /* Ensure no margins/headers/footers are added by the browser */
      @page {
        margin: 0;
      }
    }
  </style>
</head>
<body>

<div class="receipt-container">
    <div class="header">
        <h3>Klassique Diagnoses & Clinical Services</h3>
        <p>No.23, Klassique Diagnostic house, Wuro Bulude B,</p>
        <p>Adjacent Federal Medical Center (FMC) Mubi, Adamawa State</p>
        <p>Tel: +234 (0) 814 856 4676, +234 (0) 902 115 6143</p>
    </div>

    <div class="line-separator"></div>

    <div class="details">
        <div class="item-row">
            <span>Receipt No:</span>
            <span><?php echo htmlspecialchars($bill['bill_id']); ?></span>
        </div>
        <div class="item-row">
            <span>Date:</span>
            <span><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($bill['created_at']))); ?></span>
        </div>
        <div class="item-row">
            <span>Patient:</span>
            <span><?php echo htmlspecialchars($bill['patient_name']); ?></span>
        </div>
        <div class="item-row">
            <span>Patient ID:</span>
            <span>KDCS/MUB/<?php echo htmlspecialchars($formatted_patient_id); ?></span>
        </div>
    </div>

    <div class="line-separator"></div>

    <div class="items-section">
        <div class="item-row">
            <span><strong>Description</strong></span>
            <span><strong>Amount (₦)</strong></span>
        </div>
        <div class="line-separator"></div>
        <div class="item-row">
            <span>Consultation/Service</span>
            <span><?php echo number_format($bill['total_amount'], 2); ?></span>
        </div>
        </div>

    <div class="line-separator"></div>

    <div class="summary">
        <div class="item-row total-line">
            <span>TOTAL:</span>
            <span>₦<?php echo number_format($bill['total_amount'], 2); ?></span>
        </div>
        <div class="item-row">
            <span>PAID:</span>
            <span>₦<?php echo number_format($bill['paid_amount'], 2); ?></span>
        </div>
        <div class="item-row">
            <span>DUE:</span>
            <span>₦<?php echo number_format($bill['due_amount'], 2); ?></span>
        </div>
        <div class="item-row">
            <span>STATUS:</span>
            <span><?php echo htmlspecialchars($bill['payment_status']); ?></span>
        </div>
        <div class="item-row">
            <span>TYPE:</span>
            <span><?php echo htmlspecialchars($bill['payment_type'] ?? 'N/A'); ?></span>
        </div>
    </div>

    <div class="line-separator"></div>

    <div class="footer">
        <div class="thank-you">
            <p>THANK YOU!</p>
        </div>
    </div>
</div>

<script>
    // Automatically trigger print dialog when the page loads
    window.onload = function() {
        window.print();
    };

    // Redirect back to manage_bills.php after print dialog is closed
    window.onafterprint = function() {
        window.location.href = 'manage_bills.php';
    };
</script>
</body>
</html>