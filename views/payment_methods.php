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

// Fetch user data
try {
    $stmt = $pdo->prepare("
        SELECT user_id, username, email, profile_picture, paypal_email 
        FROM users 
        WHERE user_id = :user_id
    ");
    $stmt->execute(['user_id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header('Location: login.php');
        exit;
    }

    // Convert profile picture to base64 for display
    $profile_picture_data = $user['profile_picture'] ? 'data:image/jpeg;base64,' . base64_encode($user['profile_picture']) : 'profile.jpg';

    // Handle PayPal Email Update
    $payment_error = '';
    $payment_success = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment'])) {
        $paypal_email = trim($_POST['paypal_email']);

        // Validate PayPal email
        if (!empty($paypal_email) && !filter_var($paypal_email, FILTER_VALIDATE_EMAIL)) {
            $payment_error = 'Please enter a valid email address for PayPal.';
        } else {
            // Update the database
            $stmt = $pdo->prepare("UPDATE users SET paypal_email = :paypal_email WHERE user_id = :user_id");
            $stmt->execute([
                'paypal_email' => $paypal_email ?: NULL,
                'user_id' => $user_id
            ]);

            $user['paypal_email'] = $paypal_email;
            $payment_success = 'Payment method updated successfully!';
        }
    }

    // Handle PayPal Email Removal
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_payment'])) {
        $stmt = $pdo->prepare("UPDATE users SET paypal_email = NULL WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $user_id]);
        $user['paypal_email'] = NULL;
        $payment_success = 'Payment method removed successfully!';
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
    <title>Payment Methods - Tree Planting Initiative</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
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
            padding: 20px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            border-radius: 0 20px 20px 0;
            box-shadow: 5px 0 15px rgba(0, 0, 0, 0.1);
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 100;
        }

        .sidebar img.logo {
            width: 50px;
            margin-bottom: 40px;
        }

        .sidebar a {
            margin: 20px 0;
            color: #666;
            text-decoration: none;
            font-size: 24px;
            transition: color 0.3s;
        }

        .sidebar a:hover {
            color: #4f46e5;
        }

        .main-content {
            flex: 1;
            padding: 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-left: 80px; /* Match sidebar width */
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            width: 100%;
            position: relative;
        }

        .header h1 {
            font-size: 36px;
            color: #1e3a8a;
        }

        .header .search-bar {
            display: flex;
            align-items: center;
            background: #fff;
            padding: 8px 15px;
            border-radius: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            position: relative;
            width: 300px;
        }

        .header .search-bar input {
            border: none;
            outline: none;
            padding: 5px;
            width: 100%;
            font-size: 16px;
        }

        .header .search-bar .search-results {
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

        .header .search-bar .search-results.active {
            display: block;
        }

        .header .search-bar .search-results a {
            display: block;
            padding: 12px;
            color: #333;
            text-decoration: none;
            border-bottom: 1px solid #e0e7ff;
            font-size: 16px;
        }

        .header .search-bar .search-results a:hover {
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
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            padding: 15px 0;
            display: flex;
            justify-content: space-around;
        }

        .account-nav a {
            color: #666;
            text-decoration: none;
            font-size: 16px;
            padding: 10px 20px;
            transition: color 0.3s;
        }

        .account-nav a.active {
            color: #4f46e5;
            border-bottom: 2px solid #4f46e5;
        }

        .account-nav a:hover {
            color: #4f46e5;
        }

        .account-section {
            background: #fff;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 800px;
            margin-bottom: 30px;
        }

        .account-section h2 {
            font-size: 28px;
            color: #1e3a8a;
            margin-bottom: 25px;
        }

        .account-section .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            display: none;
            font-size: 16px;
        }

        .account-section .success {
            background: #d1fae5;
            color: #10b981;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            display: none;
            font-size: 16px;
        }

        .account-section .error.show,
        .account-section .success.show {
            display: block;
        }

        .account-section .form-group {
            margin-bottom: 25px;
        }

        .account-section .current-method {
            margin-bottom: 25px;
            padding: 15px;
            background: #f5f5f5;
            border-radius: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .account-section .current-method p {
            margin: 0;
            font-size: 16px;
            color: #666;
        }

        .account-section label {
            display: block;
            font-size: 16px;
            color: #666;
            margin-bottom: 8px;
        }

        .account-section input[type="email"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #e0e7ff;
            border-radius: 5px;
            font-size: 16px;
            outline: none;
        }

        .account-section input:focus {
            border-color: #4f46e5;
        }

        .account-section input[type="submit"] {
            background: #4f46e5;
            color: #fff;
            border: none;
            cursor: pointer;
            transition: background 0.3s;
            padding: 12px;
            font-size: 16px;
            width: 100%;
            border-radius: 5px;
        }

        .account-section input[type="submit"]:hover {
            background: #7c3aed;
        }

        .account-section .remove-btn {
            background: #dc2626;
            color: #fff;
            border: none;
            cursor: pointer;
            transition: background 0.3s;
            padding: 8px 16px;
            font-size: 14px;
            border-radius: 5px;
        }

        .account-section .remove-btn:hover {
            background: #b91c1c;
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
                bottom: 0;
                top: auto;
                height: auto;
                border-radius: 15px 15px 0 0;
                box-shadow: 0 -5px 15px rgba(0, 0, 0, 0.1);
                padding: 10px 0;
            }

            .sidebar img.logo {
                display: none;
            }

            .sidebar a {
                margin: 0 15px;
                font-size: 20px;
            }

            .main-content {
                padding: 20px;
                padding-bottom: 80px;
                margin-left: 0;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .header h1 {
                font-size: 28px;
            }

            .header .search-bar {
                width: 100%;
                padding: 5px 10px;
            }

            .header .search-bar input {
                width: 100%;
                font-size: 14px;
            }

            .header .search-bar .search-results {
                top: 40px;
            }

            .header .profile {
                margin-top: 0;
            }

            .header .profile img {
                width: 40px;
                height: 40px;
            }

            .header .profile span {
                font-size: 16px;
            }

            .profile-dropdown {
                top: 50px;
                width: 200px;
                right: 0;
            }

            .account-nav {
                flex-direction: column;
                padding: 10px;
            }

            .account-nav a {
                padding: 10px;
                font-size: 14px;
                border-bottom: 1px solid #e0e7ff;
            }

            .account-nav a:last-child {
                border-bottom: none;
            }

            .account-nav a.active {
                border-bottom: 2px solid #4f46e5;
            }

            .account-section {
                padding: 20px;
            }

            .account-section h2 {
                font-size: 24px;
            }

            .account-section .current-method {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .account-section .current-method p {
                font-size: 14px;
            }

            .account-section label {
                font-size: 14px;
            }

            .account-section input[type="email"] {
                padding: 10px;
                font-size: 14px;
            }

            .account-section input[type="submit"] {
                padding: 10px;
                font-size: 14px;
            }

            .account-section .remove-btn {
                padding: 6px 12px;
                font-size: 12px;
            }

            .error-message {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <img src="logo.png" alt="Logo" class="logo">
            <a href="dashboard.php" title="Dashboard"><i class="fas fa-home"></i></a>
            <a href="submit.php" title="Submit Planting"><i class="fas fa-tree"></i></a>
            <a href="leaderboard.php" title="Leaderboard"><i class="fas fa-trophy"></i></a>
            <a href="rewards.php" title="Rewards"><i class="fas fa-gift"></i></a>
            <a href="events.php" title="Events"><i class="fas fa-calendar-alt"></i></a>
            <a href="history.php" title="History"><i class="fas fa-history"></i></a>
            <a href="feedback.php" title="Feedback"><i class="fas fa-comment-dots"></i></a>
            <a href="logout.php" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
        <div class="main-content">
            <?php if (isset($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <div class="header">
                <h1>Payment Methods</h1>
                <div class="search-bar">
                    <input type="text" placeholder="Search functionalities..." id="searchInput">
                    <div class="search-results" id="searchResults"></div>
                </div>
                <div class="profile" id="profileBtn">
                    <span><?php echo htmlspecialchars($username); ?></span>
                    <img src="<?php echo $profile_picture_data; ?>" alt="Profile">
                    <div class="profile-dropdown" id="profileDropdown">
                        <div class="email"><?php echo htmlspecialchars($user['email']); ?></div>
                        <a href="account_settings.php">Account</a>
                        <a href="logout.php">Logout</a>
                    </div>
                </div>
            </div>
            <div class="account-nav">
                <a href="account_settings.php">Account Settings</a>
                <a href="profile.php">Profile</a>
                <a href="password_security.php">Password & Security</a>
                <a href="payment_methods.php" class="active">Payment Methods</a>
            </div>
            <div class="account-section">
                <?php if ($payment_error): ?>
                    <div class="error show"><?php echo htmlspecialchars($payment_error); ?></div>
                <?php endif; ?>
                <?php if ($payment_success): ?>
                    <div class="success show"><?php echo htmlspecialchars($payment_success); ?></div>
                <?php endif; ?>
                <div class="current-method">
                    <p>
                        <strong>PayPal Email:</strong> 
                        <?php echo $user['paypal_email'] ? htmlspecialchars($user['paypal_email']) : 'Not set'; ?>
                    </p>
                    <?php if ($user['paypal_email']): ?>
                        <form method="POST" action="" style="display: inline;">
                            <input type="hidden" name="remove_payment" value="1">
                            <input type="submit" value="Remove" class="remove-btn">
                        </form>
                    <?php endif; ?>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="update_payment" value="1">
                    <div class="form-group">
                        <label for="paypal_email">PayPal Email</label>
                        <input type="email" id="paypal_email" name="paypal_email" value="<?php echo htmlspecialchars($user['paypal_email'] ?? ''); ?>" placeholder="Enter your PayPal email">
                    </div>
                    <input type="submit" value="Update Payment Method">
                </form>
            </div>
        </div>
    </div>

    <script>
        // Search bar functionality
        const functionalities = [
            { name: 'Dashboard', url: 'dashboard.php' },
            { name: 'Submit Planting', url: 'submit.php' },
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
    </script>
</body>
</html>