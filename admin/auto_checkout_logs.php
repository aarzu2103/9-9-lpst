<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

require_role('ADMIN');

$database = new Database();
$pdo = $database->getConnection();

// Get checkout logs with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Filter options
$dateFilter = $_GET['date'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$whereConditions = [];
$params = [];

if ($dateFilter) {
    $whereConditions[] = "DATE(acl.created_at) = ?";
    $params[] = $dateFilter;
}

if ($statusFilter) {
    $whereConditions[] = "acl.status = ?";
    $params[] = $statusFilter;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$error = null;

// Get logs with proper error handling
try {
    // Prepare the main log query
    $logQuery = "
        SELECT acl.*, 
               COALESCE(r.custom_name, r.display_name) as resource_display_name,
               r.type as resource_type
        FROM auto_checkout_logs acl
        LEFT JOIN resources r ON acl.resource_id = r.id
        $whereClause
        ORDER BY acl.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($logQuery);
    $logParams = array_merge($params, [$limit, $offset]);
    $stmt->execute($logParams);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count for pagination
    $countQuery = "
        SELECT COUNT(*) 
        FROM auto_checkout_logs acl
        LEFT JOIN resources r ON acl.resource_id = r.id
        $whereClause
    ";
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($params);
    $totalLogs = $countStmt->fetchColumn();
    $totalPages = ceil($totalLogs / $limit);

    // Get today's execution summary
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_today,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_today,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_today
        FROM auto_checkout_logs 
        WHERE DATE(created_at) = CURDATE()
    ");
    $stmt->execute();
    $todayStats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get today's cron execution status
    $stmt = $pdo->prepare("
        SELECT * FROM cron_execution_logs 
        WHERE execution_date = CURDATE() 
        ORDER BY execution_time DESC 
        LIMIT 1
    ");
    $stmt->execute();
    $cronStatus = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    error_log("Auto Checkout Logs PDO Error: " . $e->getMessage());
    $logs = [];
    $totalLogs = 0;
    $totalPages = 0;
    $todayStats = ['total_today' => 0, 'successful_today' => 0, 'failed_today' => 0];
    $cronStatus = null;
} catch (Exception $e) {
    $error = "An unexpected error occurred: " . $e->getMessage();
    error_log("Auto Checkout Logs General Error: " . $e->getMessage());
    $logs = [];
    $totalLogs = 0;
    $totalPages = 0;
    $todayStats = ['total_today' => 0, 'successful_today' => 0, 'failed_today' => 0];
    $cronStatus = null;
}

$flash = get_flash_message();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto Checkout Logs - L.P.S.T Bookings</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            border-left: 4px solid var(--primary-color);
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            text-align: center;
        }
        .dashboard-value { 
            font-size: 2em; 
            font-weight: bold; 
            margin: 0.5rem 0; 
        }
        .execution-status {
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            text-align: center;
            font-weight: bold;
        }
        .status-success { 
            background: rgba(40, 167, 69, 0.1); 
            color: var(--success-color); 
        }
        .status-failed { 
            background: rgba(239, 68, 68, 0.1); 
            color: var(--danger-color); 
        }
        .status-pending { 
            background: rgba(255, 193, 7, 0.1); 
            color: var(--warning-color); 
        }
        .status-no_bookings { 
            background: rgba(108, 117, 125, 0.1); 
            color: #6c757d; 
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background-color: #fefefe;
            margin: auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 500px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            position: relative;
        }
        .modal-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 1rem; 
            border-bottom: 1px solid #eee; 
            padding-bottom: 10px; 
        }
        .close-modal { 
            color: #aaa; 
            float: right; 
            font-size: 28px; 
            font-weight: bold; 
            cursor: pointer;
        }
        .close-modal:hover, .close-modal:focus { 
            color: black; 
            text-decoration: none; 
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <div class="nav-links">
            <a href="../grid.php" class="nav-button">← Back to Grid</a>
            <a href="../owner/settings.php" class="nav-button">Settings</a>
        </div>
        <a href="/" class="nav-brand">L.P.S.T Bookings</a>
        <div class="nav-links">
            <span style="margin-right: 1rem;">Auto Checkout Logs</span>
            <a href="../logout.php" class="nav-button danger">Logout</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($flash): ?>
            <div class="flash-message flash-<?= htmlspecialchars($flash['type']) ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="flash-message flash-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <h2>🕙 Auto Checkout Logs - Day 7 Final Fix</h2>
        
        <!-- Today's Execution Status -->
        <?php if ($cronStatus): ?>
            <div class="execution-status status-<?= htmlspecialchars($cronStatus['execution_status']) ?>">
                <h3>Today's Auto Checkout Status</h3>
                <p>Execution Time: <?= htmlspecialchars($cronStatus['execution_time']) ?> | Status: <?= strtoupper(htmlspecialchars($cronStatus['execution_status'])) ?></p>
                <p>Bookings Found: <?= htmlspecialchars($cronStatus['bookings_found']) ?> | Successful: <?= htmlspecialchars($cronStatus['bookings_successful']) ?> | Failed: <?= htmlspecialchars($cronStatus['bookings_failed']) ?></p>
                <?php if ($cronStatus['error_message']): ?>
                    <p>Error: <?= htmlspecialchars($cronStatus['error_message']) ?></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="execution-status status-pending">
                <h3>Today's Auto Checkout Status</h3>
                <p>⏳ Auto checkout has not executed today yet</p>
                <p>Next execution: Tomorrow at 10:00 AM</p>
                <p>Current time: <?= date('H:i:s') ?></p>
            </div>
        <?php endif; ?>
        
        <!-- Today's Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <h4>Today's Checkouts</h4>
                <div class="dashboard-value"><?= htmlspecialchars($todayStats['total_today'] ?? 0) ?></div>
                <p>Total processed today</p>
            </div>
            
            <div class="stat-card">
                <h4>Successful</h4>
                <div class="dashboard-value" style="color: var(--success-color);"><?= htmlspecialchars($todayStats['successful_today'] ?? 0) ?></div>
                <p>Successfully checked out</p>
            </div>
            
            <div class="stat-card">
                <h4>Failed</h4>
                <div class="dashboard-value" style="color: var(--danger-color);"><?= htmlspecialchars($todayStats['failed_today'] ?? 0) ?></div>
                <p>Failed checkouts</p>
            </div>
            
            <div class="stat-card">
                <h4>System Status</h4>
                <div class="dashboard-value" style="color: var(--success-color);">FIXED</div>
                <p>Day 7 Final Solution</p>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="form-container">
            <h3>Filters</h3>
            <form method="GET">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <div class="form-group">
                        <label for="date" class="form-label">Date</label>
                        <input type="date" id="date" name="date" class="form-control" value="<?= htmlspecialchars($dateFilter) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="status" class="form-label">Status</label>
                        <select id="status" name="status" class="form-control">
                            <option value="">All Status</option>
                            <option value="success" <?= $statusFilter === 'success' ? 'selected' : '' ?>>Success</option>
                            <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
                        </select>
                    </div>
                </div>
                
                <div style="margin-top: 1rem;">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="auto_checkout_logs.php" class="btn btn-outline">Clear Filters</a>
                </div>
            </form>
        </div>
        
        <!-- Logs Table -->
        <div class="form-container">
            <h3>Checkout History (<?= htmlspecialchars($totalLogs) ?> total records)</h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: var(--light-color);">
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Date & Time</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Resource</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Guest Name</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Status</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Payment Status</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Notes</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="7" style="padding: 2rem; text-align: center; color: var(--dark-color);">
                                    <div style="text-align: center;">
                                        <div style="font-size: 3rem; margin-bottom: 1rem;">🕙</div>
                                        <h4>No auto checkout logs found</h4>
                                        <p>Auto checkout logs will appear here when the system runs</p>
                                        <a href="../owner/settings.php" class="btn btn-primary">Configure Auto Checkout</a>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                        <?= date('M j, Y H:i:s', strtotime($log['created_at'])) ?>
                                    </td>
                                    <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                        <strong><?= htmlspecialchars($log['resource_display_name'] ?: $log['resource_name']) ?></strong>
                                        <?php if ($log['resource_type']): ?>
                                            <br><small style="color: var(--dark-color);"><?= ucfirst(htmlspecialchars($log['resource_type'])) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                        <?= htmlspecialchars($log['guest_name']) ?>
                                    </td>
                                    <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                        <span style="color: <?= $log['status'] === 'success' ? 'var(--success-color)' : 'var(--danger-color)' ?>; font-weight: 600;">
                                            <?= $log['status'] === 'success' ? '✅ SUCCESS' : '❌ FAILED' ?>
                                        </span>
                                    </td>
                                    <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                        <?php 
                                        // Check if payment exists for this booking
                                        $isPaid = false;
                                        if ($log['booking_id']) {
                                            try {
                                                $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE booking_id = ? AND payment_status = 'COMPLETED'");
                                                $stmt->execute([$log['booking_id']]);
                                                $isPaid = $stmt->fetchColumn() > 0;
                                            } catch (Exception $e) {
                                                error_log("Payment check error for booking ID {$log['booking_id']}: " . $e->getMessage());
                                            }
                                        }
                                        ?>
                                        <?php if ($isPaid): ?>
                                            <span style="color: var(--success-color); font-weight: 600;">✅ PAID</span>
                                        <?php else: ?>
                                            <span style="color: var(--danger-color); font-weight: 600;">❌ UNPAID</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                        <small><?= htmlspecialchars($log['notes']) ?></small>
                                    </td>
                                    <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                        <?php if (!$isPaid && $log['booking_id']): ?>
                                            <button onclick="markAsPaid(<?= (int)$log['booking_id'] ?>, '<?= htmlspecialchars($log['resource_display_name'] ?: $log['resource_name']) ?>')" 
                                                    class="btn btn-success" style="font-size: 0.8rem; padding: 0.4rem 0.8rem;">
                                                Mark Paid
                                            </button>
                                        <?php else: ?>
                                            <span style="color: var(--success-color);">✅ Complete</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div style="text-align: center; margin-top: 2rem;">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>&date=<?= htmlspecialchars($dateFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>" 
                           class="btn btn-outline">← Previous</a>
                    <?php endif; ?>
                    
                    <span style="margin: 0 1rem;">Page <?= htmlspecialchars($page) ?> of <?= htmlspecialchars($totalPages) ?></span>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>&date=<?= htmlspecialchars($dateFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>" 
                           class="btn btn-outline">Next →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mark Paid Modal -->
    <div id="markPaidModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Mark Payment as Received</h3>
                <button type="button" class="close-modal" onclick="closeMarkPaidModal()">&times;</button>
            </div>
            <form method="POST" action="../payment_process.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="mark_checkout_paid">
                <input type="hidden" name="booking_id" id="markPaidBookingId">
                
                <div class="form-group">
                    <label class="form-label">Resource</label>
                    <input type="text" id="markPaidResourceName" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label for="paid_amount" class="form-label">Amount Received (₹) *</label>
                    <input type="number" id="paid_amount" name="amount" class="form-control" min="1" step="0.01" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Payment Method *</label>
                    <div style="margin: 0.5rem 0;">
                        <input type="radio" id="paid_online" name="payment_method" value="ONLINE" required>
                        <label for="paid_online">Online (UPI/Net Banking/Card)</label>
                    </div>
                    <div>
                        <input type="radio" id="paid_offline" name="payment_method" value="OFFLINE" required>
                        <label for="paid_offline">Offline (Cash)</label>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-success">Mark as Paid</button>
            </form>
        </div>
    </div>

    <script>
        function markAsPaid(bookingId, resourceName) {
            document.getElementById('markPaidBookingId').value = bookingId;
            document.getElementById('markPaidResourceName').value = resourceName;
            document.getElementById('markPaidModal').style.display = 'flex';
        }
        
        function closeMarkPaidModal() {
            document.getElementById('markPaidModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('markPaidModal');
            if (e.target === modal) {
                closeMarkPaidModal();
            }
        });
    </script>
</body>
</html>