<?php
/**
 * Direct Cron Test Script
 * This file tests the cron functionality directly
 */

date_default_timezone_set('Asia/Kolkata');

echo "<!DOCTYPE html><html><head><title>Direct Cron Test</title>";
echo "<style>body{font-family:Arial;margin:20px;line-height:1.6;} .success{color:green;} .error{color:red;} .warning{color:orange;} .info{color:blue;}</style>";
echo "</head><body>";

echo "<h1>🔧 Direct Cron Test for Auto Checkout</h1>";
echo "<p class='info'>Test Time: " . date('Y-m-d H:i:s') . " (Asia/Kolkata)</p>";

// Test database connection
echo "<h3>1. Testing Database Connection...</h3>";
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
    echo "<p class='success'>✅ Database connection successful!</p>";
    
} catch(PDOException $e) {
    echo "<p class='error'>❌ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</body></html>";
    exit;
}

// Check active bookings
echo "<h3>2. Checking Active Bookings...</h3>";
try {
    $stmt = $pdo->query("
        SELECT b.*, r.display_name, r.custom_name, r.type
        FROM bookings b 
        JOIN resources r ON b.resource_id = r.id 
        WHERE b.status IN ('BOOKED', 'PENDING')
        ORDER BY b.check_in DESC
    ");
    $activeBookings = $stmt->fetchAll();
    
    echo "<p class='success'>✅ Found " . count($activeBookings) . " active bookings</p>";
    
    if (!empty($activeBookings)) {
        echo "<table border='1' style='border-collapse:collapse; width:100%; margin:10px 0;'>";
        echo "<tr style='background:#f0f0f0;'><th>ID</th><th>Resource</th><th>Client</th><th>Check-in</th><th>Status</th></tr>";
        
        foreach ($activeBookings as $booking) {
            $resourceName = $booking['custom_name'] ?: $booking['display_name'];
            $checkInTime = date('M j, H:i', strtotime($booking['check_in']));
            
            echo "<tr>";
            echo "<td>{$booking['id']}</td>";
            echo "<td>{$resourceName}</td>";
            echo "<td>{$booking['client_name']}</td>";
            echo "<td>{$checkInTime}</td>";
            echo "<td>{$booking['status']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Error checking bookings: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Test auto checkout execution
echo "<h3>3. Testing Auto Checkout Execution...</h3>";
try {
    require_once 'includes/auto_checkout.php';
    
    $autoCheckout = new AutoCheckout($pdo);
    echo "<p class='success'>✅ AutoCheckout class loaded successfully</p>";
    
    // Force test execution
    $result = $autoCheckout->testAutoCheckout();
    
    echo "<h4>Test Results:</h4>";
    echo "<pre style='background:#f8f9fa; padding:15px; border-radius:8px; border:1px solid #ddd;'>";
    echo json_encode($result, JSON_PRETTY_PRINT);
    echo "</pre>";
    
    if ($result['status'] === 'completed') {
        echo "<p class='success'>✅ Auto checkout test completed successfully!</p>";
        echo "<p>Checked out: " . ($result['checked_out'] ?? 0) . " bookings</p>";
        echo "<p>Failed: " . ($result['failed'] ?? 0) . " bookings</p>";
    } else {
        echo "<p class='warning'>⚠️ Test result: " . $result['status'] . "</p>";
        if (isset($result['message'])) {
            echo "<p>Message: " . htmlspecialchars($result['message']) . "</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Auto checkout test failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre style='background:#f8f9fa; padding:15px; border-radius:8px; border:1px solid #ddd;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}

// Show recent logs
echo "<h3>4. Recent Auto Checkout Logs...</h3>";
try {
    $stmt = $pdo->prepare("
        SELECT acl.*, r.display_name, r.custom_name
        FROM auto_checkout_logs acl
        LEFT JOIN resources r ON acl.resource_id = r.id
        ORDER BY acl.created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $logs = $stmt->fetchAll();
    
    if (empty($logs)) {
        echo "<p class='warning'>⚠️ No auto checkout logs found yet.</p>";
    } else {
        echo "<p class='success'>✅ Recent auto checkout logs:</p>";
        echo "<table border='1' style='border-collapse:collapse; width:100%; margin:10px 0;'>";
        echo "<tr style='background:#f0f0f0;'><th>Time</th><th>Resource</th><th>Guest</th><th>Status</th><th>Notes</th></tr>";
        
        foreach ($logs as $log) {
            $resourceName = $log['custom_name'] ?: $log['display_name'] ?: $log['resource_name'];
            $time = date('M j, H:i', strtotime($log['created_at']));
            $status = $log['status'] === 'success' ? '✅' : '❌';
            
            echo "<tr>";
            echo "<td>{$time}</td>";
            echo "<td>{$resourceName}</td>";
            echo "<td>{$log['guest_name']}</td>";
            echo "<td>{$status} {$log['status']}</td>";
            echo "<td>" . htmlspecialchars($log['notes']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Error reading logs: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h3>5. Navigation Links</h3>";
echo "<div style='margin: 20px 0;'>";
echo "<a href='admin/manual_checkout_test.php' style='background:#007bff; color:white; padding:10px 20px; text-decoration:none; border-radius:5px; margin-right:10px;'>🧪 Manual Test Page</a>";
echo "<a href='admin/auto_checkout_settings.php' style='background:#28a745; color:white; padding:10px 20px; text-decoration:none; border-radius:5px; margin-right:10px;'>⚙️ Settings</a>";
echo "<a href='grid.php' style='background:#6c757d; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>🏠 Back to Grid</a>";
echo "</div>";

echo "<div style='background:#d4edda; padding:15px; border-radius:8px; margin-top:20px;'>";
echo "<h4 style='color:#155724;'>✅ Direct Cron Test Complete!</h4>";
echo "<p>This test simulates what happens when the cron job runs. Use the manual test page for more detailed testing options.</p>";
echo "</div>";

echo "</body></html>";
?>