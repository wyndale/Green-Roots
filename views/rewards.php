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
$eco_points = 0;
$vouchers = [];
$redeem_message = '';
$redeem_error = '';

// Fetch user data
try {
    $stmt = $pdo->prepare("
        SELECT user_id, username, email, profile_picture, role, eco_points 
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

    $eco_points = $user['eco_points'];
    $profile_picture_data = $user['profile_picture'] ? 'data:image/jpeg;base64,' . base64_encode($user['profile_picture']) : 'profile.jpg';

    // Fetch available vouchers
    $stmt = $pdo->prepare("SELECT voucher_id, name, points_cost, code FROM vouchers ORDER BY points_cost ASC");
    $stmt->execute();
    $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle Voucher Redemption
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_voucher'])) {
        $voucher_id = (int)$_POST['voucher_id'];

        // Fetch the selected voucher
        $stmt = $pdo->prepare("SELECT name, points_cost, code FROM vouchers WHERE voucher_id = :voucher_id");
        $stmt->execute(['voucher_id' => $voucher_id]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($voucher) {
            $points_cost = $voucher['points_cost'];

            if ($eco_points >= $points_cost) {
                // Deduct points
                $stmt = $pdo->prepare("UPDATE users SET eco_points = eco_points - :points WHERE user_id = :user_id");
                $stmt->execute(['points' => $points_cost, 'user_id' => $user_id]);

                // Log activity
                $stmt = $pdo->prepare("
                    INSERT INTO activities (user_id, description, activity_type, created_at) 
                    VALUES (:user_id, :description, 'reward', NOW())
                ");
                $stmt->execute([
                    'user_id' => $user_id,
                    'description' => "Redeemed voucher: {$voucher['name']} for $points_cost points"
                ]);

                // Update eco points for display
                $eco_points -= $points_cost;

                $redeem_message = "Successfully redeemed {$voucher['name']}! Your voucher code is: {$voucher['code']}";
            } else {
                $redeem_error = 'Insufficient eco points to redeem this voucher.';
            }
        } else {
            $redeem_error = 'Invalid voucher selected.';
        }
    }

    // Handle Cash Withdrawal
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['withdraw_cash'])) {
        $amount_points = (int)$_POST['amount_points'];
        $paypal_email = trim($_POST['paypal_email']);

        // Validate PayPal email format
        if (!filter_var($paypal_email, FILTER_VALIDATE_EMAIL)) {
            $redeem_error = 'Please enter a valid PayPal email address.';
        } elseif ($amount_points <= 0) {
            $redeem_error = 'Please enter a valid amount to withdraw.';
        } elseif ($amount_points > $eco_points) {
            $redeem_error = 'Insufficient eco points for this withdrawal.';
        } else {
            // Conversion rate: 100 points = $1
            $cash_amount = $amount_points / 100;

            // In a real scenario, integrate PayPal Payouts API here
            // For now, simulate the withdrawal by logging the transaction
            $stmt = $pdo->prepare("UPDATE users SET eco_points = eco_points - :points WHERE user_id = :user_id");
            $stmt->execute(['points' => $amount_points, 'user_id' => $user_id]);

            // Log activity
            $stmt = $pdo->prepare("
                INSERT INTO activities (user_id, description, activity_type, created_at) 
                VALUES (:user_id, :description, 'reward', NOW())
            ");
            $stmt->execute([
                'user_id' => $user_id,
                'description' => "Withdrew $cash_amount USD ($amount_points points) via PayPal to $paypal_email"
            ]);

            // Update eco points for display
            $eco_points -= $amount_points;

            $redeem_message = "Successfully requested withdrawal of $cash_amount USD to $paypal_email. You will receive the funds shortly.";
        }
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
    <title>Rewards - Tree Planting Initiative</title>
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
            margin-left: 80px;
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

        .rewards-section {
            background: linear-gradient(135deg, #ffffff 0%, #e0e7ff 100%);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 800px;
            margin-bottom: 30px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .rewards-section h2 {
            font-size: 28px;
            color: #1e3a8a;
            margin-bottom: 15px;
        }

        .eco-points {
            background: #fff;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.05);
            text-align: center;
            font-size: 20px;
            color: #1e3a8a;
        }

        .eco-points span {
            font-weight: bold;
            color: #10b981;
        }

        .redeem-options {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .redeem-option {
            background: #fff;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.05);
        }

        .redeem-option h3 {
            font-size: 22px;
            color: #1e3a8a;
            margin-bottom: 15px;
        }

        .voucher-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 15px;
        }

        .voucher-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border: 1px solid #e0e7ff;
            border-radius: 5px;
        }

        .voucher-item span {
            font-size: 16px;
        }

        .voucher-item span.points {
            color: #f59e0b;
            font-weight: bold;
        }

        .redeem-option select {
            width: 100%;
            padding: 8px;
            border: 1px solid #e0e7ff;
            border-radius: 5px;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .redeem-option input[type="email"],
        .redeem-option input[type="number"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #e0e7ff;
            border-radius: 5px;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .redeem-option button {
            background: #4f46e5;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .redeem-option button:hover {
            background: #7c3aed;
        }

        .redeem-message {
            background: #d1fae5;
            color: #10b981;
            padding: 12px;
            border-radius: 5px;
            text-align: center;
            font-size: 16px;
        }

        .redeem-error {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 5px;
            text-align: center;
            font-size: 16px;
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

            .rewards-section {
                padding: 20px;
            }

            .rewards-section h2 {
                font-size: 24px;
            }

            .eco-points {
                font-size: 18px;
            }

            .redeem-option {
                padding: 15px;
            }

            .redeem-option h3 {
                font-size: 18px;
            }

            .voucher-item span {
                font-size: 14px;
            }

            .redeem-option select,
            .redeem-option input[type="email"],
            .redeem-option input[type="number"] {
                font-size: 13px;
            }

            .redeem-option button {
                font-size: 14px;
                padding: 8px 15px;
            }

            .redeem-message,
            .redeem-error {
                font-size: 14px;
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
            <?php if ($redeem_message): ?>
                <div class="redeem-message"><?php echo htmlspecialchars($redeem_message); ?></div>
            <?php endif; ?>
            <?php if ($redeem_error): ?>
                <div class="redeem-error"><?php echo htmlspecialchars($redeem_error); ?></div>
            <?php endif; ?>
            <div class="header">
                <h1>Rewards</h1>
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
            <div class="rewards-section">
                <h2>Redeem Your Rewards</h2>
                <div class="eco-points">
                    Your Eco Points: <span><?php echo $eco_points; ?></span>
                </div>
                <div class="redeem-options">
                    <!-- Voucher Redemption -->
                    <div class="redeem-option">
                        <h3>Exchange for Vouchers</h3>
                        <div class="voucher-list">
                            <?php foreach ($vouchers as $voucher): ?>
                                <div class="voucher-item">
                                    <span><?php echo htmlspecialchars($voucher['name']); ?></span>
                                    <span class="points"><?php echo $voucher['points_cost']; ?> points</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <form method="POST" action="">
                            <select name="voucher_id" required>
                                <option value="">Select a voucher</option>
                                <?php foreach ($vouchers as $voucher): ?>
                                    <option value="<?php echo $voucher['voucher_id']; ?>">
                                        <?php echo htmlspecialchars($voucher['name']) . " (" . $voucher['points_cost'] . " points)"; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="redeem_voucher">Redeem Voucher</button>
                        </form>
                    </div>
                    <!-- Cash Withdrawal -->
                    <div class="redeem-option">
                        <h3>Withdraw as Cash via PayPal</h3>
                        <p>Conversion Rate: 100 Eco Points = 1 USD</p>
                        <form method="POST" action="">
                            <input type="email" name="paypal_email" placeholder="Enter your PayPal email" required>
                            <input type="number" name="amount_points" placeholder="Enter points to withdraw (e.g., 500)" min="100" step="100" required>
                            <button type="submit" name="withdraw_cash">Withdraw Cash</button>
                        </form>
                    </div>
                </div>
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