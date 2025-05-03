<?php
// Start session and handle PHP logic
session_start();
require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Initialize variables
$password_error = '';
$password_success = '';
$field_errors = [];

// Check for session messages
if (isset($_SESSION['password_success'])) {
    $password_success = $_SESSION['password_success'];
    unset($_SESSION['password_success']);
}
if (isset($_SESSION['field_errors'])) {
    $field_errors = $_SESSION['field_errors'];
    unset($_SESSION['field_errors']);
}

// Fetch user data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header('Location: login.php');
        exit;
    }

    // Fetch profile picture
    if ($user['profile_picture']) {
        $profile_picture_data = 'data:image/jpeg;base64,' . base64_encode($user['profile_picture']);
    } elseif ($user['default_profile_asset_id']) {
        $stmt = $pdo->prepare("SELECT asset_data, asset_type FROM assets WHERE asset_id = :asset_id");
        $stmt->execute(['asset_id' => $user['default_profile_asset_id']]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($asset && $asset['asset_data']) {
            $mime_type = $asset['asset_type'] === 'default_profile' ? 'image/png' : 'image/jpeg';
            $profile_picture_data = "data:$mime_type;base64," . base64_encode($asset['asset_data']);
        } else {
            $profile_picture_data = 'default_profile.jpg';
        }
    } else {
        $profile_picture_data = 'default_profile.jpg';
    }

    // Fetch favicon and logo
    $stmt = $pdo->prepare("SELECT asset_data FROM assets WHERE asset_type = 'favicon' LIMIT 1");
    $stmt->execute();
    $favicon_data = $stmt->fetchColumn();
    $favicon_base64 = $favicon_data ? 'data:image/png;base64,' . base64_encode($favicon_data) : '../assets/favicon.png';

    $stmt = $pdo->prepare("SELECT asset_data FROM assets WHERE asset_type = 'logo' LIMIT 1");
    $stmt->execute();
    $logo_data = $stmt->fetchColumn();
    $logo_base64 = $logo_data ? 'data:image/png;base64,' . base64_encode($logo_data) : 'logo.png';

    // Handle Password Change
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        // Validate inputs
        if (empty($current_password)) {
            $field_errors['current_password'] = 'Current password is required.';
        }
        if (empty($new_password)) {
            $field_errors['new_password'] = 'New password is required.';
        }
        if (empty($confirm_password)) {
            $field_errors['confirm_password'] = 'Please confirm your new password.';
        }

        if (empty($field_errors)) {
            // Verify current password
            if (!password_verify($current_password, $user['password'])) {
                $field_errors['current_password'] = 'Current password is incorrect.';
            }

            // Password strength validation
            if (strlen($new_password) < 8) {
                $field_errors['new_password'] = 'Password must be at least 8 characters long.';
            } elseif (!preg_match("/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/", $new_password)) {
                $field_errors['new_password'] = 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.';
            }

            if ($new_password !== $confirm_password) {
                $field_errors['confirm_password'] = 'New passwords do not match.';
            }

            if (empty($field_errors)) {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = :password WHERE user_id = :user_id");
                $stmt->execute([
                    'password' => $hashed_password,
                    'user_id' => $user_id
                ]);
                $_SESSION['password_success'] = 'Password updated successfully!';
            } else {
                $_SESSION['field_errors'] = $field_errors;
            }
        } else {
            $_SESSION['field_errors'] = $field_errors;
        }

        // Redirect to prevent form resubmission
        header('Location: password_security.php');
        exit;
    }

} catch (PDOException $e) {
    $error_message = "Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password & Security - Green Roots</title>
    <link rel="icon" type="image/png" href="<?php echo $favicon_base64; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }

        body {
            background: #f5f7fa;
            color: #333;
            overflow-x: hidden;
        }

        .container {
            display: flex;
            min-height: 100vh;
            max-width: 1920px;
            margin: 0 auto;
        }

        .sidebar {
            width: 80px;
            background: #fff;
            padding: 17px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            border-radius: 0 20px 20px 0;
            box-shadow: 5px 0 15px rgba(0, 0, 0, 0.1);
            position: fixed;
            top: 0;
            bottom: 0;
            overflow-y: auto;
        }

        .sidebar img.logo {
            width: 70px;
            margin-bottom: 20px;
        }

        .sidebar a {
            margin: 18px 0;
            color: #666;
            text-decoration: none;
            font-size: 24px;
            transition: transform 0.3s, color 0.3s;
        }

        .sidebar a:hover {
            color: #4CAF50;
            animation: bounce 0.3s ease-out;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }

        .sidebar a.active {
            color: #4CAF50;
        }

        .main-content {
            flex: 1;
            padding: 40px;
            margin-left: 80px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            width: 100%;
            position: relative;
        }

        .header h1 {
            font-size: 36px;
            color: #4CAF50;
        }

        .header .notification-search {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .header .notification-search .notification {
            font-size: 24px;
            color: #666;
            cursor: pointer;
            transition: color 0.3s, transform 0.3s;
        }

        .header .notification-search .notification:hover {
            color: #4CAF50;
            transform: scale(1.1);
        }

        .header .notification-search .search-bar {
            display: flex;
            align-items: center;
            background: #fff;
            padding: 8px 15px;
            border-radius: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            position: relative;
            width: 300px;
        }

        .header .notification-search .search-bar i {
            margin-right: 10px;
            color: #666;
            font-size: 16px;
        }

        .header .notification-search .search-bar input {
            border: none;
            outline: none;
            padding: 5px;
            width: 90%;
            font-size: 16px;
        }

        .header .notification-search .search-bar .search-results {
            position: absolute;
            top: 50px;
            left: 0;
            background: #fff;
            width: 100%;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            display: none;
            z-index: 10;
        }

        .header .notification-search .search-bar .search-results.active {
            display: block;
        }

        .header .notification-search .search-bar .search-results a {
            display: block;
            padding: 12px;
            color: #333;
            text-decoration: none;
            border-bottom: 1px solid #e0e7ff;
            font-size: 16px;
        }

        .header .notification-search .search-bar .search-results a:hover {
            background: #e0e7ff;
        }

        .header .profile {
            position: relative;
            display: flex;
            align-items: center;
            gap: 15px;
            cursor: pointer;
        }

        .header .profile:hover {
            opacity: 0.8;
        }

        .header .profile img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
        }

        .header .profile span {
            font-size: 18px;
        }

        .profile-dropdown {
            position: absolute;
            top: 60px;
            right: 0;
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            display: none;
            z-index: 10;
        }

        .profile-dropdown.active {
            display: block;
        }

        .profile-dropdown .email {
            padding: 15px 20px;
            color: #666;
            font-size: 16px;
            border-bottom: 1px solid #e0e7ff;
        }

        .profile-dropdown a {
            display: block;
            padding: 15px 20px;
            color: #333;
            text-decoration: none;
            font-size: 16px;
        }

        .profile-dropdown a:hover {
            background: #e0e7ff;
        }

        .account-nav {
            width: 100%;
            max-width: 800px;
            background: linear-gradient(135deg, #E8F5E9, #C8E6C9);
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            padding: 10px;
            display: flex;
            justify-content: space-around;
            gap: 10px;
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .account-nav a {
            color: #666;
            text-decoration: none;
            font-size: 16px;
            padding: 10px 20px;
            border-radius: 10px;
            transition: all 0.3s ease;
            flex: 1;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .account-nav a i {
            color: #666;
            transition: color 0.3s ease;
        }

        .account-nav a.active {
            background: #BBEBBF;
            color: #4CAF50;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        .account-nav a.active i {
            color: #4CAF50;
        }

        .account-nav a:hover {
            background: rgba(76, 175, 80, 0.1);
            color: #4CAF50;
        }

        .account-nav a:hover i {
            color: #4CAF50;
        }

        .account-section {
            background: linear-gradient(135deg, #E8F5E9, #C8E6C9);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            width: 100%;
            max-width: 800px;
            margin-bottom: 30px;
            animation: fadeIn 0.5s ease-in;
        }

        .account-section h2 {
            font-size: 28px;
            color: #2E7D32;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .account-section h2 i {
            color: #2E7D32;
        }

        .account-section .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 16px;
        }

        .account-section .success {
            background: #d1fae5;
            color: #10b981;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 16px;
        }

        .account-section .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        .account-section label {
            display: block;
            font-size: 16px;
            color: #666;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .account-section label i {
            color: #4CAF50;
        }

        .account-section .password-wrapper {
            position: relative;
            width: 100%;
        }

        .account-section input[type="password"],
        .account-section input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #e0e7ff;
            border-radius: 8px;
            font-size: 16px;
            outline: none;
            transition: border-color 0.3s, box-shadow 0.3s;
            background: #fff;
        }

        .account-section input[type="password"]:hover,
        .account-section input[type="text"]:hover {
            border-color: #4CAF50;
        }

        .account-section input[type="password"]:focus,
        .account-section input[type="text"]:focus {
            border-color: #4CAF50;
            box-shadow: 0 0 5px rgba(76, 175, 80, 0.3);
        }

        .account-section .password-wrapper .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #666;
            font-size: 16px;
            transition: color 0.3s;
        }

        .account-section .password-wrapper .toggle-password:hover {
            color: #4CAF50;
        }

        .account-section .field-error {
            color: #dc2626;
            font-size: 12px;
            margin-top: 5px;
            display: none;
        }

        .account-section .field-error.show {
            display: block;
        }

        .account-section .password-strength {
            margin-top: 5px;
            font-size: 12px;
            color: #666;
        }

        .account-section .password-strength.weak {
            color: #dc2626;
        }

        .account-section .password-strength.medium {
            color: #f59e0b;
        }

        .account-section .password-strength.strong {
            color: #10b981;
        }

        .account-section .button-group {
            display: flex;
            gap: 15px;
        }

        .account-section input[type="submit"],
        .account-section button {
            background: #4CAF50;
            color: #fff;
            border: none;
            cursor: pointer;
            transition: background 0.3s, transform 0.1s;
            padding: 12px;
            font-size: 16px;
            font-weight: bold;
            width: 100%;
            border-radius: 8px;
        }

        .account-section input[type="submit"]:hover,
        .account-section button:hover {
            background: #388E3C;
            transform: scale(1.02);
        }

        .account-section button.cancel {
            background: #666;
            font-weight: bold;
        }

        .account-section button.cancel:hover {
            background: #555;
        }

        .error-message {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 15px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 16px;
            width: 100%;
            max-width: 800px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        /* Mobile Responsive Design */
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                flex-direction: row;
                justify-content: space-around;
                position: fixed;
                top: auto;
                bottom: 0;
                border-radius: 15px 15px 0 0;
                box-shadow: 0 -5px 15px rgba(0, 0, 0, 0.1);
                padding: 10px 0;
                z-index: 100;
            }

            .sidebar img.logo {
                display: none;
            }

            .sidebar a {
                margin: 0 10px;
                font-size: 18px;
            }

            .main-content {
                padding: 20px;
                padding-bottom: 80px;
                margin-left: 0;
            }

            .header {
                flex-direction: row;
                align-items: center;
                gap: 15px;
                width: 100%;
            }

            .header h1 {
                font-size: 24px;
            }

            .header .notification-search {
                flex-direction: row;
                align-items: center;
                gap: 10px;
                width: auto;
                flex-grow: 1;
            }

            .header .notification-search .notification {
                font-size: 20px;
                flex-shrink: 0;
            }

            .header .notification-search .search-bar {
                width: 100%;
                max-width: 200px;
                padding: 5px 10px;
                flex-grow: 1;
            }

            .header .notification-search .search-bar i {
                font-size: 14px;
                margin-right: 5px;
            }

            .header .notification-search .search-bar input {
                font-size: 14px;
            }

            .header .notification-search .search-bar .search-results {
                top: 40px;
            }

            .header .profile {
                margin-top: 0;
                flex-shrink: 0;
            }

            .header .profile img {
                width: 40px;
                height: 40px;
            }

            .header .profile span {
                font-size: 16px;
                display: none; /* Hide the name to save space */
            }

            .profile-dropdown {
                top: 50px;
                width: 200px;
                right: 0;
            }

            .account-nav {
                flex-direction: column;
                padding: 5px;
                gap: 5px;
            }

            .account-nav a {
                padding: 8px 10px;
                font-size: 14px;
            }

            .account-nav a i {
                font-size: 14px;
            }

            .account-section {
                padding: 20px;
            }

            .account-section h2 {
                font-size: 24px;
            }

            .account-section label {
                font-size: 14px;
            }

            .account-section input[type="password"],
            .account-section input[type="text"] {
                padding: 10px;
                font-size: 14px;
            }

            .account-section .field-error {
                font-size: 10px;
            }

            .account-section .password-strength {
                font-size: 10px;
            }

            .account-section .password-wrapper .toggle-password {
                font-size: 14px;
            }

            .account-section input[type="submit"],
            .account-section button {
                padding: 10px;
                font-size: 14px;
            }

            .account-section .button-group {
                flex-direction: column;
                gap: 10px;
            }

            .error-message {
                font-size: 14px;
            }
        }

        /* Additional media query for very small screens */
        @media (max-width: 480px) {
            .header h1 {
                font-size: 20px;
            }

            .header .notification-search {
                gap: 5px;
            }

            .header .notification-search .notification {
                font-size: 18px;
            }

            .header .notification-search .search-bar {
                max-width: 150px;
                padding: 4px 8px;
            }

            .header .notification-search .search-bar i {
                font-size: 12px;
                margin-right: 4px;
            }

            .header .notification-search .search-bar input {
                font-size: 12px;
            }

            .header .profile img {
                width: 35px;
                height: 35px;
            }

            .account-section {
                padding: 15px;
            }

            .account-section h2 {
                font-size: 20px;
            }

            .account-nav a {
                font-size: 12px;
                padding: 6px 8px;
            }

            .account-nav a i {
                font-size: 12px;
            }

            .account-section label {
                font-size: 12px;
            }

            .account-section input[type="password"],
            .account-section input[type="text"] {
                font-size: 12px;
                padding: 8px;
            }

            .account-section .field-error {
                font-size: 10px;
            }

            .account-section .password-strength {
                font-size: 10px;
            }

            .account-section .password-wrapper .toggle-password {
                font-size: 12px;
            }

            .account-section input[type="submit"],
            .account-section button {
                font-size: 12px;
                padding: 8px;
            }

            .error-message {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <img src="<?php echo $logo_base64; ?>" alt="Logo" class="logo">
            <a href="dashboard.php" title="Dashboard"><i class="fas fa-home"></i></a>
            <a href="submit.php" title="Submit Planting"><i class="fas fa-leaf"></i></a>
            <a href="planting_site.php" title="Planting Site"><i class="fas fa-map-pin"></i></a>
            <a href="leaderboard.php" title="Leaderboard"><i class="fas fa-crown"></i></a>
            <a href="rewards.php" title="Rewards"><i class="fas fa-gift"></i></a>
            <a href="events.php" title="Events"><i class="fas fa-calendar-days"></i></a>
            <a href="history.php" title="History"><i class="fas fa-clock"></i></a>
            <a href="feedback.php" title="Feedback"><i class="fas fa-comment"></i></a>
        </div>
        <div class="main-content">
            <?php if (isset($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <div class="header">
                <h1>Password & Security</h1>
                <div class="notification-search">
                    <div class="notification"><i class="fas fa-bell"></i></div>
                    <div class="search-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search" id="searchInput">
                        <div class="search-results" id="searchResults"></div>
                    </div>
                </div>
                <div class="profile" id="profileBtn">
                    <span><?php echo htmlspecialchars($user['first_name']); ?></span>
                    <img src="<?php echo $profile_picture_data; ?>" alt="Profile">
                    <div class="profile-dropdown" id="profileDropdown">
                        <div class="email"><?php echo htmlspecialchars($user['email']); ?></div>
                        <a href="account_settings.php">Account</a>
                        <a href="logout.php">Logout</a>
                    </div>
                </div>
            </div>
            <div class="account-nav">
                <a href="account_settings.php"><i class="fas fa-user-cog"></i> Account Settings</a>
                <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
                <a href="password_security.php" class="active"><i class="fas fa-lock"></i> Password & Security</a>
                <a href="payment_methods.php"><i class="fas fa-credit-card"></i> Payment Methods</a>
            </div>
            <div class="account-section">
                <h2><i class="fas fa-lock"></i> Password & Security</h2>
                <?php if ($password_success): ?>
                    <div class="success"><?php echo htmlspecialchars($password_success); ?></div>
                <?php endif; ?>
                <form method="POST" action="">
                    <input type="hidden" name="change_password" value="1">
                    <div class="form-group">
                        <label for="current_password"><i class="fas fa-key"></i> Current Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="current_password" name="current_password" required>
                            <i class="fas fa-eye toggle-password" data-target="current_password"></i>
                        </div>
                        <?php if (isset($field_errors['current_password'])): ?>
                            <div class="field-error show"><?php echo htmlspecialchars($field_errors['current_password']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="new_password"><i class="fas fa-key"></i> New Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="new_password" name="new_password" required>
                            <i class="fas fa-eye toggle-password" data-target="new_password"></i>
                        </div>
                        <div class="password-strength" id="password-strength">Password strength: Weak</div>
                        <?php if (isset($field_errors['new_password'])): ?>
                            <div class="field-error show"><?php echo htmlspecialchars($field_errors['new_password']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password"><i class="fas fa-key"></i> Confirm New Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="confirm_password" name="confirm_password" required>
                            <i class="fas fa-eye toggle-password" data-target="confirm_password"></i>
                        </div>
                        <?php if (isset($field_errors['confirm_password'])): ?>
                            <div class="field-error show"><?php echo htmlspecialchars($field_errors['confirm_password']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="button-group">
                        <input type="submit" value="Change Password">
                        <button type="button" class="cancel" onclick="window.location.reload()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Search bar functionality
        const functionalities = [
            { name: 'Dashboard', url: 'dashboard.php' },
            { name: 'Submit Planting', url: 'submit.php' },
            { name: 'Planting Site', url: 'planting_site.php' },
            { name: 'Leaderboard', url: 'leaderboard.php' },
            { name: 'Rewards', url: 'rewards.php' },
            { name: 'Events', url: 'events.php' },
            { name: 'History', url: 'history.php' },
            { name: 'Feedback', url: 'feedback.php' },
            { name: 'Logout', url: 'logout.php' }
        ];

        const searchInput = document.querySelector('#searchInput');
        const searchResults = document.querySelector('#searchResults');

        searchInput.addEventListener('input', function(e) {
            const query = e.target.value.toLowerCase();
            searchResults.innerHTML = '';
            searchResults.classList.remove('active');

            if (query.length > 0) {
                const matches = functionalities.filter(func => 
                    func.name.toLowerCase().startsWith(query)
                );

                if (matches.length > 0) {
                    matches.forEach(func => {
                        const link = document.createElement('a');
                        link.href = func.url;
                        link.textContent = func.name;
                        searchResults.appendChild(link);
                    });
                    searchResults.classList.add('active');
                }
            }
        });

        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.classList.remove('active');
            }
        });

        // Profile dropdown functionality
        const profileBtn = document.querySelector('#profileBtn');
        const profileDropdown = document.querySelector('#profileDropdown');

        profileBtn.addEventListener('click', function() {
            profileDropdown.classList.toggle('active');
        });

        document.addEventListener('click', function(e) {
            if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                profileDropdown.classList.remove('active');
            }
        });

        // Show/Hide Password functionality
        const togglePasswordIcons = document.querySelectorAll('.toggle-password');
        togglePasswordIcons.forEach(icon => {
            icon.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const input = document.querySelector(`#${targetId}`);
                if (input.type === 'password') {
                    input.type = 'text';
                    this.classList.remove('fa-eye');
                    this.classList.add('fa-eye-slash');
                } else {
                    input.type = 'password';
                    this.classList.remove('fa-eye-slash');
                    this.classList.add('fa-eye');
                }
            });
        });

        // Password strength indicator
        const newPasswordInput = document.querySelector('#new_password');
        const passwordStrength = document.querySelector('#password-strength');

        newPasswordInput.addEventListener('input', function() {
            const password = this.value;
            let strength = 'Weak';
            let colorClass = 'weak';

            if (password.length >= 8) {
                if (/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/.test(password)) {
                    strength = 'Strong';
                    colorClass = 'strong';
                } else if (/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[A-Za-z\d]{8,}$/.test(password)) {
                    strength = 'Medium';
                    colorClass = 'medium';
                }
            }

            passwordStrength.textContent = `Password strength: ${strength}`;
            passwordStrength.className = `password-strength ${colorClass}`;
        });
    </script>
</body>
</html>