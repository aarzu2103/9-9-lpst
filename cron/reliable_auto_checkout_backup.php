<?php
/**
 * Reliable Auto Checkout Backup Cron Job
 * 
 * This is a backup verification system that runs every 30 minutes
 * between 10:00-11:00 AM to ensure auto-checkout happens even if
 * no admin logs in.
 * 
 * HOSTINGER CRON COMMAND:
 * */30 10-11 * * * /usr/bin/php /home/u261459251/domains/lpstnashik.in/public_html/cron/reliable_auto_checkout_backup.php
 */

// Set timezone FIRST
date_default_timezone_set('Asia/Kolkata');

// Create logs directory
$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Logging function
function logMessage($message, $level = 'INFO') {
    global $logDir;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] BACKUP CRON: $message";
    
    $logFile = $logDir . '/reliable_auto_checkout_' . date('Y-m-d') . '.log';
    file_put_contents($logFile, $logMessage . "\n", FILE_APPEND | LOCK_EX);
    
    // Output for manual runs
    if (isset($_GET['manual']) || php_sapi_name() !== 'cli') {
        echo $logMessage . "<br>\n";
    }
}

$isManualRun = isset($_GET['manual']) || php_sapi_name() !== 'cli';

if ($isManualRun) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>Backup Auto Checkout</title></head><body>";
    echo "<h2>🔄 Backup Auto Checkout Verification</h2>";
    echo "<p><strong>Current Time:</strong> " . date('H:i:s') . " (Asia/Kolkata)</p>";
}

logMessage("=== BACKUP AUTO CHECKOUT VERIFICATION STARTED ===");
logMessage("Current time: " . date('H:i:s'));
logMessage("Execution mode: " . ($isManualRun ? 'MANUAL' : 'AUTOMATIC CRON'));

// Database connection
try {
    $host = 'localhost';
    $dbname = 'u261459251_patel';
    $username = 'u261459251_levagt';
    $password = 'GtPatelsamaj@0330';
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    $pdo->exec("SET time_zone = '+05:30'");
    logMessage("Database connection successful");
    
} catch(PDOException $e) {
    $error = "Database connection failed: " . $e->getMessage();
    logMessage($error, 'CRITICAL');
    
    if ($isManualRun) {
        echo "<p style='color:red;'>$error</p></body></html>";
    }
    exit(1);
}

// Load and execute backup verification
try {
    require_once dirname(__DIR__) . '/includes/reliable_auto_checkout.php';
    
    $autoCheckout = new ReliableAutoCheckout($pdo);
    logMessage("ReliableAutoCheckout class loaded");
    
    // Execute backup verification
    $result = $autoCheckout->backupCronExecution();
    
    logMessage("Backup verification result: " . json_encode($result));
    
    if ($isManualRun) {
        echo "<h3>Backup Verification Results:</h3>";
        echo "<div style='background: #f8f9fa; padding: 1rem; border-radius: 8px; border: 1px solid #ddd;'>";
        echo "<pre>" . json_encode($result, JSON_PRETTY_PRINT) . "</pre>";
        echo "</div>";
        
        if ($result['success']) {
            echo "<p style='color:green;'>✅ Backup verification successful</p>";
            echo "<p>Action: " . ($result['action'] ?? 'completed') . "</p>";
        } else {
            echo "<p style='color:red;'>❌ Backup verification failed</p>";
            echo "<p>Error: " . ($result['message'] ?? 'Unknown error') . "</p>";
        }
        
        echo "<div style='margin-top: 2rem;'>";
        echo "<a href='../admin/auto_checkout_logs.php' style='background:#007bff; color:white; padding:10px 20px; text-decoration:none; border-radius:5px; margin-right:10px;'>📋 View Logs</a>";
        echo "<a href='../owner/settings.php' style='background:#28a745; color:white; padding:10px 20px; text-decoration:none; border-radius:5px; margin-right:10px;'>⚙️ Settings</a>";
        echo "<a href='../grid.php' style='background:#6c757d; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>🏠 Dashboard</a>";
        echo "</div>";
        echo "</body></html>";
    }
    
    exit($result['success'] ? 0 : 1);
    
} catch (Exception $e) {
    $errorMessage = "Backup cron critical error: " . $e->getMessage();
    logMessage($errorMessage, 'CRITICAL');
    
    if ($isManualRun) {
        echo "<p style='color:red;'>Critical Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "</body></html>";
    }
    
    exit(1);
}
?>