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

    // Convert profile picture to base64 for display
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
    <title>Rewards - Green Roots</title>
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
            transition: color 0.3s;
        }

        .sidebar a:hover {
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
            margin-bottom: 20px;
            width: 100%;
            max-width: 1200px;
            position: relative;
        }

        .header h1 {
            font-size: 36px;
            color: #4CAF50;
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
            background: #E8F5E9;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 1200px;
            margin-bottom: 30px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .rewards-section h2 {
            font-size: 28px;
            color: #4CAF50;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .eco-points {
            background: rgb(187, 235, 191);
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
            font-size: 24px;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .eco-points i {
            color: #4CAF50;
            font-size: 28px;
        }

        .eco-points span {
            font-weight: bold;
            color: #4CAF50;
        }

        .redeem-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .redeem-option {
            background: #fff;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }

        .redeem-option:hover {
            transform: translateY(-5px);
        }

        .redeem-option h3 {
            font-size: 22px;
            color: #4CAF50;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .redeem-option h3 i {
            color: #4CAF50;
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
            padding: 12px;
            border: 1px solid #e0e7ff;
            border-radius: 8px;
            transition: background 0.3s ease;
        }

        .voucher-item:hover {
            background: rgba(76, 175, 80, 0.1);
        }

        .voucher-item span {
            font-size: 16px;
        }

        .voucher-item span.points {
            color: #388E3C;
            font-weight: bold;
        }

        .redeem-option p {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }

        .redeem-option select,
        .redeem-option input[type="email"],
        .redeem-option input[type="number"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #e0e7ff;
            border-radius: 5px;
            font-size: 16px;
            margin-bottom: 10px;
            outline: none;
        }

        .redeem-option button {
            background: #4CAF50;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
            width: 100%;
        }

        .redeem-option button:hover {
            background: #388E3C;
        }

        .error-message,
        .redeem-error {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 15px;
            text-align: center;
            font-size: 16px;
            width: 100%;
            max-width: 800px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: #fff;
            padding: 30px;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
            text-align: center;
        }

        .modal-content .redeem-message {
            font-size: 16px;
            color: #4CAF50;
            margin-bottom: 20px;
        }

        .modal-content .close-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 24px;
            color: #666;
            cursor: pointer;
            transition: color 0.3s;
        }

        .modal-content .close-btn:hover {
            color: #dc2626;
        }

        .modal-content button {
            background: #4CAF50;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .modal-content button:hover {
            background: #388E3C;
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
                font-size: 20px;
            }

            .eco-points i {
                font-size: 24px;
            }

            .redeem-options {
                grid-template-columns: 1fr;
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
                font-size: 14px;
                padding: 8px;
            }

            .redeem-option button {
                font-size: 14px;
                padding: 8px 15px;
            }

            .modal-content {
                width: 95%;
                padding: 20px;
            }

            .modal-content .redeem-message {
                font-size: 14px;
            }

            .modal-content button {
                font-size: 14px;
                padding: 8px 15px;
            }

            .error-message,
            .redeem-error {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <img src="<?php echo $logo_base64; ?>" alt="Logo" class="logo">
            <a href="dashboard.php" title="Dashboard"><i class="fas fa-home"></i></a>
            <a href="submit.php" title="Submit Planting"><i class="fas fa-tree"></i></a>
            <a href="planting_site.php" title="Planting Site"><i class="fas fa-map-marker-alt"></i></a>
            <a href="leaderboard.php" title="Leaderboard"><i class="fas fa-trophy"></i></a>
            <a href="rewards.php" title="Rewards"><i class="fas fa-gift"></i></a>
            <a href="events.php" title="Events"><i class="fas fa-calendar-alt"></i></a>
            <a href="history.php" title="History"><i class="fas fa-history"></i></a>
            <a href="feedback.php" title="Feedback"><i class="fas fa-comment-dots"></i></a>
        </div>
        <div class="main-content">
            <?php if (isset($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
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
                    <i class="fas fa-leaf"></i>
                    Your Eco Points: <span><?php echo $eco_points; ?></span>
                </div>
                <div class="redeem-options">
                    <!-- Voucher Redemption -->
                    <div class="redeem-option">
                        <h3><i class="fas fa-ticket-alt"></i> Exchange for Vouchers</h3>
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
                                        <?php echo htmlspecialchars($voucher['name']) . " - " . $voucher['points_cost'] . " points"; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="redeem_voucher">Redeem Voucher</button>
                        </form>
                    </div>
                    <!-- Cash Withdrawal -->
                    <div class="redeem-option">
                        <h3><i class="fas fa-wallet"></i> Withdraw as Cash via PayPal</h3>
                        <p>Conversion Rate: 100 Eco Points = 1 USD</p>
                        <form method="POST" action="">
                            <input type="email" name="paypal_email" placeholder="Enter your PayPal email" required>
                            <input type="number" name="amount_points" placeholder="Enter points to withdraw (e.g., 500)" min="100" step="100" required>
                            <button type="submit" name="withdraw_cash">Withdraw Cash</button>
                        </form>
                    </div>
                </div>
            </div>
            <!-- Modal for Success Message -->
            <?php if ($redeem_message): ?>
                <div class="modal active" id="redeemModal">
                    <div class="modal-content">
                        <span class="close-btn" onclick="closeModal('redeemModal')">×</span>
                        <div class="redeem-message"><?php echo htmlspecialchars($redeem_message); ?></div>
                        <button onclick="closeModal('redeemModal')">Close</button>
                    </div>
                </div>
            <?php endif; ?>
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

        // Modal functionality
        function closeModal(modalId) {
            document.querySelector(`#${modalId}`).classList.remove('active');
        }

        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('active');
            }
        });
    </script>
</body>
</html>