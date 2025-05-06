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
$payment_error = '';
$payment_success = '';
$field_errors = [];

// Check for session messages
if (isset($_SESSION['payment_success'])) {
    $payment_success = $_SESSION['payment_success'];
    unset($_SESSION['payment_success']);
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

    // Handle PayPal Email Update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment'])) {
        $paypal_email = trim($_POST['paypal_email']);

        // Validate PayPal email
        if (empty($paypal_email)) {
            $field_errors['paypal_email'] = 'PayPal email is required.';
        } elseif (!filter_var($paypal_email, FILTER_VALIDATE_EMAIL)) {
            $field_errors['paypal_email'] = 'Please enter a valid email address for PayPal.';
        } else {
            // Update the database
            $stmt = $pdo->prepare("UPDATE users SET paypal_email = :paypal_email WHERE user_id = :user_id");
            $stmt->execute([
                'paypal_email' => $paypal_email,
                'user_id' => $user_id
            ]);

            $user['paypal_email'] = $paypal_email;
            $_SESSION['payment_success'] = 'Payment method updated successfully!';
        }

        if (!empty($field_errors)) {
            $_SESSION['field_errors'] = $field_errors;
        }

        // Redirect to prevent form resubmission
        header('Location: payment_methods.php');
        exit;
    }

    // Handle PayPal Email Removal
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_payment'])) {
        $stmt = $pdo->prepare("UPDATE users SET paypal_email = NULL WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $user_id]);
        $user['paypal_email'] = NULL;
        $_SESSION['payment_success'] = 'Payment method removed successfully!';

        // Redirect to prevent form resubmission
        header('Location: payment_methods.php');
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
    <title>Payment Methods - Green Roots</title>
    <link rel="icon" type="image/png" href="<?php echo $favicon_base64; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/payment_methods.css">
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
                <h1>Payment Methods</h1>
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
                <a href="password_security.php"><i class="fas fa-lock"></i> Password & Security</a>
                <a href="payment_methods.php" class="active"><i class="fas fa-credit-card"></i> Payment Methods</a>
            </div>
            <div class="account-section">
                <h2><i class="fas fa-credit-card"></i> Payment Methods</h2>
                <?php if ($payment_success): ?>
                    <div class="success"><?php echo htmlspecialchars($payment_success); ?></div>
                <?php endif; ?>
                <div class="current-method">
                    <p class="<?php echo $user['paypal_email'] ? '' : 'not-set'; ?>">
                        <i class="fab fa-paypal"></i>
                        <strong>PayPal Email:</strong> 
                        <?php echo $user['paypal_email'] ? htmlspecialchars($user['paypal_email']) : 'Not set'; ?>
                    </p>
                    <?php if ($user['paypal_email']): ?>
                        <form method="POST" action="" style="display: inline;">
                            <input type="hidden" name="remove_payment" value="1">
                            <button type="submit" class="remove-btn">Remove</button>
                        </form>
                    <?php endif; ?>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="update_payment" value="1">
                    <div class="form-group">
                        <label for="paypal_email"><i class="fab fa-paypal"></i> PayPal Email</label>
                        <input type="email" id="paypal_email" name="paypal_email" value="<?php echo htmlspecialchars($user['paypal_email'] ?? ''); ?>" placeholder="Enter your PayPal email" required>
                        <?php if (isset($field_errors['paypal_email'])): ?>
                            <div class="field-error show"><?php echo htmlspecialchars($field_errors['paypal_email']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="button-group">
                        <input type="submit" value="Update Payment Method">
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
    </script>
</body>
</html>