<?php
/**
 * Auto Checkout Popup API
 * Handles popup display logic and auto-checkout processing
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once '../includes/functions.php';
require_once '../config/database.php';
require_once '../includes/reliable_auto_checkout.php';

if (!is_logged_in() || $_SESSION['role'] !== 'ADMIN') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$database = new Database();
$pdo = $database->getConnection();
$autoCheckout = new ReliableAutoCheckout($pdo);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'check_popup':
        // Check if popup should be shown
        $result = $autoCheckout->shouldShowPopup($_SESSION['user_id']);
        echo json_encode($result);
        break;
        
    case 'process_checkout':
        // Process auto-checkout when admin confirms
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'POST required']);
            exit;
        }
        
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
        
        $checkoutDate = $_POST['checkout_date'] ?? date('Y-m-d');
        $result = $autoCheckout->processAutoCheckout($_SESSION['user_id'], $checkoutDate);
        
        // Mark popup as shown and action taken
        $autoCheckout->markPopupShown($_SESSION['user_id'], $checkoutDate);
        
        echo json_encode($result);
        break;
        
    case 'get_status':
        // Get current auto-checkout status
        $status = $autoCheckout->getTodayStatus();
        echo json_encode($status);
        break;
        
    case 'mark_popup_shown':
        // Mark popup as shown (when admin sees it)
        $date = $_POST['date'] ?? date('Y-m-d');
        $autoCheckout->markPopupShown($_SESSION['user_id'], $date);
        echo json_encode(['success' => true]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
?>