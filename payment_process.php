<?php
require_once 'includes/functions.php';
require_once 'config/database.php';

require_role('ADMIN');

$database = new Database();
$pdo = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    redirect_with_message('grid.php', 'Invalid request', 'error');
}

$action = $_POST['action'] ?? '';
$bookingId = $_POST['booking_id'] ?? '';
$amount = floatval($_POST['amount'] ?? 0);
$paymentMethod = $_POST['payment_method'] ?? 'OFFLINE';

if ($action === 'mark_checkout_paid') {
    // Handle marking auto checkout as paid
    if (empty($bookingId) || $amount <= 0) {
        redirect_with_message('admin/auto_checkout_logs.php', 'Invalid payment details', 'error');
    }
    
    try {
        // Get booking details
        $stmt = $pdo->prepare("
            SELECT b.*, r.display_name, r.custom_name 
            FROM bookings b 
            JOIN resources r ON b.resource_id = r.id 
            WHERE b.id = ?
        ");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch();
        
        if (!$booking) {
            redirect_with_message('admin/auto_checkout_logs.php', 'Booking not found', 'error');
        }
        
        $resourceName = $booking['custom_name'] ?: $booking['display_name'];
        
        // Mark booking as paid
        $stmt = $pdo->prepare("
            UPDATE bookings 
            SET is_paid = 1, total_amount = ?
            WHERE id = ?
        ");
        $stmt->execute([$amount, $bookingId]);
        
        // Record the payment
        $stmt = $pdo->prepare("
            INSERT INTO payments (booking_id, resource_id, amount, payment_method, payment_status, admin_id, payment_notes) 
            VALUES (?, ?, ?, ?, 'COMPLETED', ?, ?)
        ");
        $stmt->execute([
            $bookingId, 
            $booking['resource_id'], 
            $amount, 
            $paymentMethod,
            $_SESSION['user_id'],
            "Manual payment after auto checkout for {$resourceName} - Method: {$paymentMethod}"
        ]);
        
        redirect_with_message('admin/auto_checkout_logs.php', 'Payment marked successfully! Amount: ₹' . number_format($amount, 2), 'success');
        
    } catch (Exception $e) {
        redirect_with_message('admin/auto_checkout_logs.php', 'Payment recording failed', 'error');
    }
    
} else {
    // Handle regular payments
    $resourceId = $_POST['resource_id'] ?? '';
    
    if (empty($resourceId) || $amount <= 0) {
        redirect_with_message('grid.php', 'Invalid payment details', 'error');
    }
}

if ($paymentMethod === 'manual' || $paymentMethod === 'OFFLINE') {
    // Manual payment - just record it
    $resourceName = '';
    $stmt = $pdo->prepare("SELECT display_name, custom_name FROM resources WHERE id = ?");
    $stmt->execute([$resourceId]);
    $resource = $stmt->fetch();
    if ($resource) {
        $resourceName = $resource['custom_name'] ?: $resource['display_name'];
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO payments (resource_id, amount, payment_method, payment_status, admin_id, payment_notes) 
            VALUES (?, ?, ?, 'COMPLETED', ?, ?)
        ");
        $stmt->execute([$resourceId, $amount, $paymentMethod, $_SESSION['user_id'], "Manual payment for $resourceName"]);
        
        redirect_with_message('grid.php', 'Manual payment recorded successfully!', 'success');
    } catch (Exception $e) {
        redirect_with_message('grid.php', 'Payment recording failed', 'error');
    }
} else {
    // UPI payment
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('upi_id', 'upi_name')");
    $stmt->execute();
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    $upiId = $settings['upi_id'] ?: 'owner@upi';
    $upiName = $settings['upi_name'] ?: 'L.P.S.T Bookings';

    // Create payment record
    try {
        $stmt = $pdo->prepare("
            INSERT INTO payments (resource_id, amount, payment_method, payment_status, admin_id) 
            VALUES (?, ?, 'UPI', 'PENDING', ?)
        ");
        $stmt->execute([$resourceId, $amount, $_SESSION['user_id']]);
        
        // Generate UPI payment URL
        $upiUrl = "upi://pay?pa=" . urlencode($upiId) . "&pn=" . urlencode($upiName) . "&am=" . $amount . "&cu=INR&tn=Room%20Payment";
        
        // Redirect to payment confirmation page
        $_SESSION['payment_amount'] = $amount;
        $_SESSION['payment_resource'] = $resourceId;
        $_SESSION['payment_upi_id'] = $upiId;
        $_SESSION['payment_upi_name'] = $upiName;
        $_SESSION['upi_url'] = $upiUrl;
        
        header('Location: payment_confirm.php');
        exit;
        
    } catch (Exception $e) {
        redirect_with_message('grid.php', 'Payment processing failed', 'error');
    }
}
?>