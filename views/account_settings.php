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
$account_error = '';
$account_success = '';
$field_errors = [];

// Check for session messages
if (isset($_SESSION['account_success'])) {
    $account_success = $_SESSION['account_success'];
    unset($_SESSION['account_success']);
}
if (isset($_SESSION['account_error'])) {
    $account_error = $_SESSION['account_error'];
    unset($_SESSION['account_error']);
}
if (isset($_SESSION['field_errors'])) {
    $field_errors = $_SESSION['field_errors'];
    unset($_SESSION['field_errors']);
}

// Retrieve form inputs from session if available
$form_inputs = isset($_SESSION['form_inputs']) ? $_SESSION['form_inputs'] : [];
unset($_SESSION['form_inputs']);

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
    
    // Fetch the user's selected barangay details (if any)
    $user_barangay = null;
    if ($user['barangay_id']) {
        $stmt = $pdo->prepare("
            SELECT name, city, province, region, country 
            FROM barangays 
            WHERE barangay_id = :barangay_id
        ");
        $stmt->execute(['barangay_id' => $user['barangay_id']]);
        $user_barangay = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Handle Account Settings update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
        $new_username = trim($_POST['username']);
        $new_email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $new_phone = trim($_POST['phone_number']);
        $new_first_name = trim($_POST['first_name']);
        $new_last_name = trim($_POST['last_name']);

        // Store form inputs for repopulation
        $form_inputs = [
            'username' => $new_username,
            'email' => $new_email,
            'phone_number' => $new_phone,
            'first_name' => $new_first_name,
            'last_name' => $new_last_name
        ];

        // Validate inputs
        if (empty($new_username)) {
            $field_errors['username'] = 'Username is required.';
        }
        if (empty($new_email)) {
            $field_errors['email'] = 'Email is required.';
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $field_errors['email'] = 'Invalid email format.';
        }
        if (!empty($new_phone) && !preg_match('/^\+?\d{1,4}[\s-]?\d{1,15}$/', $new_phone)) {
            $field_errors['phone_number'] = 'Invalid phone number format.';
        }
        if (empty($new_first_name)) {
            $field_errors['first_name'] = 'First name is required.';
        }
        if (empty($new_last_name)) {
            $field_errors['last_name'] = 'Last name is required.';
        }

        if (empty($field_errors)) {
            // Check if username or email is already taken by another user
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM users 
                WHERE (username = :username OR email = :email) 
                AND user_id != :user_id
            ");
            $stmt->execute([
                'username' => $new_username,
                'email' => $new_email,
                'user_id' => $user_id
            ]);
            if ($stmt->fetchColumn() > 0) {
                $_SESSION['account_error'] = 'Username or email already taken.';
                $_SESSION['form_inputs'] = $form_inputs;
            } else {
                // Update user information
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET username = :username, email = :email, phone_number = :phone_number, 
                        first_name = :first_name, last_name = :last_name
                    WHERE user_id = :user_id
                ");
                $stmt->execute([
                    'username' => $new_username,
                    'email' => $new_email,
                    'phone_number' => $new_phone ?: NULL,
                    'first_name' => $new_first_name,
                    'last_name' => $new_last_name,
                    'user_id' => $user_id
                ]);

                // Update session username
                $_SESSION['username'] = $new_username;
                $_SESSION['account_success'] = 'Account settings updated successfully!';
            }
        } else {
            $_SESSION['field_errors'] = $field_errors;
            $_SESSION['form_inputs'] = $form_inputs;
        }

        // Redirect to prevent form resubmission
        header('Location: account_settings.php');
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
    <title>Account Settings - Green Roots</title>
    <link rel="icon" type="image/png" href="<?php echo $favicon_base64; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/account_settings.css">
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
                <h1>Account Settings</h1>
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
                <a href="account_settings.php" class="active"><i class="fas fa-user-cog"></i> Account Settings</a>
                <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
                <a href="password_security.php"><i class="fas fa-lock"></i> Password & Security</a>
                <a href="payment_methods.php"><i class="fas fa-credit-card"></i> Payment Methods</a>
            </div>
            <div class="account-section">
                <h2><i class="fas fa-user-cog"></i> Account Settings</h2>
                <?php if ($account_error): ?>
                    <div class="error"><?php echo htmlspecialchars($account_error); ?></div>
                <?php endif; ?>
                <?php if ($account_success): ?>
                    <div class="success"><?php echo htmlspecialchars($account_success); ?></div>
                <?php endif; ?>
                <form method="POST" action="">
                    <input type="hidden" name="update_account" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name"><i class="fas fa-user"></i> First Name</label>
                            <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($form_inputs['first_name'] ?? ($user['first_name'] ?? '')); ?>" required>
                            <?php if (isset($field_errors['first_name'])): ?>
                                <div class="field-error show"><?php echo htmlspecialchars($field_errors['first_name']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="last_name"><i class="fas fa-user"></i> Last Name</label>
                            <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($form_inputs['last_name'] ?? ($user['last_name'] ?? '')); ?>" required>
                            <?php if (isset($field_errors['last_name'])): ?>
                                <div class="field-error show"><?php echo htmlspecialchars($field_errors['last_name']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="username"><i class="fas fa-at"></i> Username</label>
                        <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($form_inputs['username'] ?? ($user['username'] ?? '')); ?>" required>
                        <?php if (isset($field_errors['username'])): ?>
                            <div class="field-error show"><?php echo htmlspecialchars($field_errors['username']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="email"><i class="fas fa-envelope"></i> Email</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($form_inputs['email'] ?? ($user['email'] ?? '')); ?>" required>
                        <?php if (isset($field_errors['email'])): ?>
                            <div class="field-error show"><?php echo htmlspecialchars($field_errors['email']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="phone_number"><i class="fas fa-phone"></i> Phone Number (Optional)</label>
                        <input type="text" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($form_inputs['phone_number'] ?? ($user['phone_number'] ?? '')); ?>">
                        <?php if (isset($field_errors['phone_number'])): ?>
                            <div class="field-error show"><?php echo htmlspecialchars($field_errors['phone_number']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group readonly">
                        <label for="barangay"><i class="fas fa-map-pin"></i> Barangay</label>
                        <input type="text" id="barangay" value="<?php echo htmlspecialchars($user_barangay['name'] ?? 'Not specified'); ?>" readonly>
                        <span class="tooltip">This field is set during registration</span>
                    </div>
                    <div class="form-row">
                        <div class="form-group readonly">
                            <label for="city"><i class="fas fa-city"></i> City</label>
                            <input type="text" id="city" value="<?php echo htmlspecialchars($user['city'] ?? ($user_barangay['city'] ?? 'Not specified')); ?>" readonly>
                            <span class="tooltip">This field is set during registration</span>
                        </div>
                        <div class="form-group readonly">
                            <label for="province"><i class="fas fa-map"></i> Province</label>
                            <input type="text" id="province" value="<?php echo htmlspecialchars($user['province'] ?? ($user_barangay['province'] ?? 'Not specified')); ?>" readonly>
                            <span class="tooltip">This field is set during registration</span>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group readonly">
                            <label for="region"><i class="fas fa-globe"></i> Region</label>
                            <input type="text" id="region" value="<?php echo htmlspecialchars($user['region'] ?? ($user_barangay['region'] ?? 'Not specified')); ?>" readonly>
                            <span class="tooltip">This field is set during registration</span>
                        </div>
                        <div class="form-group readonly">
                            <label for="country"><i class="fas fa-flag"></i> Country</label>
                            <input type="text" id="country" value="<?php echo htmlspecialchars($user['country'] ?? ($user_barangay['country'] ?? 'Not specified')); ?>" readonly>
                            <span class="tooltip">This field is set during registration</span>
                        </div>
                    </div>
                    <div class="button-group">
                        <input type="submit" value="Update Account Settings">
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