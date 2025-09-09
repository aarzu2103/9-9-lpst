<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

require_role('ADMIN');

$database = new Database();
$pdo = $database->getConnection();

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                $error = 'All password fields are required';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'New passwords do not match';
            } elseif (strlen($newPassword) < 6) {
                $error = 'Password must be at least 6 characters long';
            } else {
                // Verify current password
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($currentPassword, $user['password'])) {
                    try {
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $stmt->execute([$hashedPassword, $_SESSION['user_id']]);
                        
                        redirect_with_message('profile.php', 'Password changed successfully!', 'success');
                    } catch (Exception $e) {
                        $error = 'Failed to change password';
                    }
                } else {
                    $error = 'Current password is incorrect';
                }
            }
        }
    }
}

// Get admin statistics for today
$today = date('Y-m-d');
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_bookings,
        COUNT(CASE WHEN status = 'BOOKED' THEN 1 END) as active_bookings,
        COUNT(CASE WHEN status = 'PENDING' THEN 1 END) as pending_bookings,
        COUNT(CASE WHEN status = 'COMPLETED' THEN 1 END) as completed_bookings,
        COUNT(CASE WHEN is_paid = 1 THEN 1 END) as paid_bookings,
        COUNT(CASE WHEN is_paid = 0 THEN 1 END) as unpaid_bookings,
        SUM(CASE WHEN is_paid = 1 THEN total_amount ELSE 0 END) as total_revenue
    FROM bookings 
    WHERE admin_id = ? AND DATE(created_at) = ?
");
$stmt->execute([$_SESSION['user_id'], $today]);
$todayStats = $stmt->fetch();

// Get recent bookings by this admin
$stmt = $pdo->prepare("
    SELECT b.*, r.display_name 
    FROM bookings b 
    JOIN resources r ON b.resource_id = r.id 
    WHERE b.admin_id = ? 
    ORDER BY b.created_at DESC 
    LIMIT 10
");
$stmt->execute([$_SESSION['user_id']]);
$recentBookings = $stmt->fetchAll();

// Get admin info
$stmt = $pdo->prepare("SELECT username, created_at FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$adminInfo = $stmt->fetch();

$flash = get_flash_message();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile - L.P.S.T Bookings</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <nav class="top-nav">
        <div class="nav-links">
            <a href="../grid.php" class="nav-button">← Back to Dashboard</a>
        </div>
        <a href="/" class="nav-brand">L.P.S.T Bookings</a>
        <div class="nav-links">
            <span style="margin-right: 1rem;">Admin Profile</span>
            <a href="../logout.php" class="nav-button danger">Logout</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($flash): ?>
            <div class="flash-message flash-<?= $flash['type'] ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="flash-message flash-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <h2>Admin Profile - <?= htmlspecialchars($adminInfo['username']) ?></h2>
        
        <!-- Profile Info -->
        <div class="form-container">
            <h3>Profile Information</h3>
            <div class="dashboard-card">
                <p><strong>Username:</strong> <?= htmlspecialchars($adminInfo['username']) ?></p>
                <p><strong>Role:</strong> Administrator</p>
                <p><strong>Account Created:</strong> <?= date('M j, Y', strtotime($adminInfo['created_at'])) ?></p>
            </div>
        </div>
        
        <!-- Today's Activity -->
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3>Today's Bookings</h3>
                <div class="dashboard-value"><?= $todayStats['total_bookings'] ?></div>
                <p>Total bookings created</p>
            </div>
            
            <div class="dashboard-card">
                <h3>Active Bookings</h3>
                <div class="dashboard-value"><?= $todayStats['active_bookings'] ?></div>
                <p>Currently active</p>
            </div>
            
            <div class="dashboard-card">
                <h3>Completed Today</h3>
                <div class="dashboard-value"><?= $todayStats['completed_bookings'] ?></div>
                <p>Successfully completed</p>
            </div>
            
            <div class="dashboard-card">
                <h3>Pending Actions</h3>
                <div class="dashboard-value" style="color: var(--danger-color);"><?= $todayStats['pending_bookings'] ?></div>
                <p>Require attention</p>
            </div>
            
            <div class="dashboard-card">
                <h3>Paid Bookings</h3>
                <div class="dashboard-value" style="color: var(--success-color);"><?= $todayStats['paid_bookings'] ?></div>
                <p>Payment received</p>
            </div>
            
            <div class="dashboard-card">
                <h3>Revenue Today</h3>
                <div class="dashboard-value"><?= format_currency($todayStats['total_revenue'] ?: 0) ?></div>
                <p>Total collected</p>
            </div>
        </div>
        
        <!-- Change Password -->
        <div class="form-container">
            <h3>Change Password</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="change_password">
                
                <div class="form-group">
                    <label for="current_password" class="form-label">Current Password *</label>
                    <input type="password" id="current_password" name="current_password" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="new_password" class="form-label">New Password *</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" required
                           minlength="6">
                </div>
                
                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm New Password *</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required
                           minlength="6">
                </div>
                
                <button type="submit" class="btn btn-primary">Change Password</button>
            </form>
        </div>
        
        <!-- Recent Activity -->
        <div class="form-container">
            <h3>Recent Bookings by You (Last 10)</h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: var(--light-color);">
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Resource</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Room Number</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Client</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Status</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Auto Checkout</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentBookings as $booking): ?>
                            <tr>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <?= htmlspecialchars($booking['custom_name'] ?: $booking['display_name']) ?>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <strong style="color: var(--primary-color);">
                                        <?php 
                                        // Extract actual room number from display name or custom name
                                        $roomNumber = '';
                                        if ($booking['custom_name']) {
                                            $roomNumber = $booking['custom_name'];
                                        } else {
                                            preg_match('/(\d+)/', $booking['display_name'], $matches);
                                            $roomNumber = $matches[0] ?? $booking['identifier'];
                                        }
                                        echo htmlspecialchars($roomNumber);
                                        ?>
                                    </strong>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <?= htmlspecialchars($booking['client_name']) ?>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <span class="status-badge status-<?= strtolower($booking['status']) ?>">
                                        <?= $booking['status'] ?>
                                    </span>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <span style="color: var(--success-color); font-weight: 600;">
                                        ✅ 10:00 AM Daily
                                    </span>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <?= date('M j, g:i A', strtotime($booking['created_at'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Auto Checkout Information for Admin -->
        <div class="form-container">
            <h3>🕙 Auto Checkout Information</h3>
            <div style="background: rgba(37, 99, 235, 0.1); padding: 1.5rem; border-radius: 8px;">
                <?php
                // Get auto checkout settings
                $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('auto_checkout_enabled', 'auto_checkout_time', 'last_auto_checkout_run')");
                $autoSettings = [];
                while ($row = $stmt->fetch()) {
                    $autoSettings[$row['setting_key']] = $row['setting_value'];
                }
                $autoEnabled = ($autoSettings['auto_checkout_enabled'] ?? '1') === '1';
                $autoTime = $autoSettings['auto_checkout_time'] ?? '10:00';
                $lastRun = $autoSettings['last_auto_checkout_run'] ?? '';
                ?>
                
                <h4 style="color: var(--primary-color);">System Status (View Only)</h4>
                <ul style="color: var(--dark-color);">
                    <li><strong>Auto Checkout:</strong> <?= $autoEnabled ? '✅ ENABLED' : '❌ DISABLED' ?></li>
                    <li><strong>Daily Time:</strong> <?= $autoTime ?> (Asia/Kolkata)</li>
                    <li><strong>Next Run:</strong> Tomorrow at <?= $autoTime ?></li>
                    <li><strong>Current Time:</strong> <?= date('H:i') ?></li>
                    <?php if ($lastRun): ?>
                        <li><strong>Last Run:</strong> <?= date('M j, Y H:i', strtotime($lastRun)) ?></li>
                    <?php endif; ?>
                    <li><strong>Payment Mode:</strong> Manual - You mark payments after auto checkout</li>
                    <li><strong>Default Checkout:</strong> All your bookings default to 10:00 AM checkout</li>
                </ul>
                
                <div style="margin-top: 1rem; padding: 1rem; background: rgba(255, 255, 255, 0.8); border-radius: 4px;">
                    <p style="margin: 0; color: var(--dark-color); font-weight: 600;">
                        ℹ️ Note: Only the owner can modify auto checkout settings. Contact owner to change auto checkout time or disable the system.
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>