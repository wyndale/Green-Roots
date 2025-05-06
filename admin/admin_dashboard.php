<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in and has admin role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
// Fetch admin data
try {
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.email, u.profile_picture, u.default_profile_asset_id, u.role, u.barangay_id
        FROM users u 
        WHERE u.user_id = :user_id
    ");
    $stmt->execute(['user_id' => $user_id]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        session_destroy();
        header('Location: login.php');
        exit;
    }

    // Fetch profile picture (custom or default)
    if ($admin['profile_picture']) {
        $profile_picture_data = 'data:image/jpeg;base64,' . base64_encode($admin['profile_picture']);
    } elseif ($admin['default_profile_asset_id']) {
        $stmt = $pdo->prepare("SELECT asset_data, asset_type FROM assets WHERE asset_id = :asset_id");
        $stmt->execute(['asset_id' => $admin['default_profile_asset_id']]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($asset && $asset['asset_data']) {
            $mime_type = 'image/jpeg'; // Default
            if ($asset['asset_type'] === 'default_profile') {
                $mime_type = 'image/png';
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_buffer($finfo, $asset['asset_data']);
                finfo_close($finfo);
            }
            $profile_picture_data = "data:$mime_type;base64," . base64_encode($asset['asset_data']);
        } else {
            $profile_picture_data = '../assets/default_profile.jpg';
        }
    } else {
        $profile_picture_data = '../assets/default_profile.jpg';
    }

    // Fetch icon
    $stmt = $pdo->prepare("SELECT asset_data FROM assets WHERE asset_type = 'icon' LIMIT 1");
    $stmt->execute();
    $icon_data = $stmt->fetchColumn();
    $icon_base64 = $icon_data ? 'data:image/png;base64,' . base64_encode($icon_data) : '../assets/icon.png';

    // Fetch logo
    $stmt = $pdo->prepare("SELECT asset_data FROM assets WHERE asset_type = 'logo' LIMIT 1");
    $stmt->execute();
    $logo_data = $stmt->fetchColumn();
    $logo_base64 = $logo_data ? 'data:image/png;base64,' . base64_encode($logo_data) : '../assets/logo.png';

    // Fetch system-wide metrics
    $total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $total_validators = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'eco_validator'")->fetchColumn();
    $active_users = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM activities WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    $total_submissions = $pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
    $total_events = $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
    $pending_feedback = $pdo->query("SELECT COUNT(*) FROM feedback WHERE status = 'submitted'")->fetchColumn();
    $total_trees_planted = $pdo->query("SELECT SUM(trees_planted) FROM submissions WHERE status = 'approved'")->fetchColumn() ?? 0;

    // Fetch user growth data for the chart (last 6 months)
    $user_growth_data = [];
    for ($i = 5; $i >= 0; $i--) {
        $month_start = date('Y-m-01', strtotime("-$i months"));
        $month_end = date('Y-m-t', strtotime("-$i months"));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE created_at BETWEEN :start AND :end");
        $stmt->execute(['start' => $month_start, 'end' => $month_end]);
        $user_growth_data[] = $stmt->fetchColumn();
    }

    // Fetch recent events for management preview (last 3)
    $stmt = $pdo->prepare("
        SELECT event_id, title, event_date, location 
        FROM events 
        ORDER BY event_date DESC 
        LIMIT 3
    ");
    $stmt->execute();
    $recent_events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch recent vouchers for management preview (last 3)
    $stmt = $pdo->prepare("
        SELECT voucher_id, name, points_cost, expiry_date 
        FROM vouchers 
        ORDER BY created_at DESC 
        LIMIT 3
    ");
    $stmt->execute();
    $recent_vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle validator registration
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_validator'])) {
        // Validate CSRF token
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $register_error = 'Invalid CSRF token.';
        } else {
            $first_name = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $last_name = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
            $password = $_POST['password'];
            $confirm_password = $_POST['confirm_password'];
            $barangay_id = filter_input(INPUT_POST, 'barangay_id', FILTER_SANITIZE_NUMBER_INT);

            if (empty($first_name) || empty($last_name) || empty($username) || empty($email) || empty($password) || empty($confirm_password) || empty($barangay_id)) {
                $register_error = 'Please fill in all fields.';
            } elseif (strlen($first_name) < 2) {
                $register_error = 'First name must be at least 2 characters long.';
            } elseif (strlen($last_name) < 2) {
                $register_error = 'Last name must be at least 2 characters long.';
            } elseif (strlen($username) < 3) {
                $register_error = 'Username must be at least 3 characters long.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $register_error = 'Invalid email format.';
            } elseif (strlen($password) < 8) {
                $register_error = 'Password must be at least 8 characters long.';
            } elseif (!preg_match('/[A-Z]/', $password)) {
                $register_error = 'Password must contain at least one capital letter.';
            } elseif (!preg_match('/[0-9]/', $password)) {
                $register_error = 'Password must contain at least one number.';
            } elseif (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
                $register_error = 'Password must contain at least one special character.';
            } elseif ($password !== $confirm_password) {
                $register_error = 'Passwords do not match.';
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
                    $stmt->execute(['username' => $username]);
                    if ($stmt->fetchColumn() > 0) {
                        $register_error = 'Username already exists.';
                    } else {
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
                        $stmt->execute(['email' => $email]);
                        if ($stmt->fetchColumn() > 0) {
                            $register_error = 'Email already exists.';
                        } else {
                            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                            $stmt = $pdo->prepare("
                                INSERT INTO users (first_name, last_name, username, password, email, role, barangay_id)
                                VALUES (:first_name, :last_name, :username, :password, :email, 'eco_validator', :barangay_id)
                            ");
                            $stmt->execute([
                                'first_name' => $first_name,
                                'last_name' => $last_name,
                                'username' => $username,
                                'password' => $hashed_password,
                                'email' => $email,
                                'barangay_id' => $barangay_id
                            ]);

                            $user_id = $pdo->lastInsertId();
                            $stmt = $pdo->prepare("SELECT asset_id FROM assets WHERE asset_type = 'default_profile' LIMIT 1");
                            $stmt->execute();
                            $default_profile_asset_id = $stmt->fetchColumn();

                            if ($default_profile_asset_id) {
                                $stmt = $pdo->prepare("UPDATE users SET default_profile_asset_id = :asset_id WHERE user_id = :user_id");
                                $stmt->execute([
                                    'asset_id' => $default_profile_asset_id,
                                    'user_id' => $user_id
                                ]);
                            }

                            $register_success = 'Validator registered successfully!';
                        }
                    }
                } catch (PDOException $e) {
                    $register_error = 'An error occurred. Please try again later.';
                }
            }
        }
    }

    // Generate CSRF token if not set
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
    <title>Admin Dashboard - Green Roots</title>
    <link rel="icon" type="image/png" sizes="64x64" href="<?php echo $icon_base64; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: #fff;
            padding: 36px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            width: 90%;
            max-width: 500px;
            text-align: center;
            position: relative;
            animation: fadeIn 0.5s ease-in-out;
        }

        .modal-content h3 {
            font-size: 25px;
            color: #4CAF50;
            margin-bottom: 6px;
        }

        .modal-content .error, .modal-content .success {
            padding: 6px;
            border-radius: 5px;
            margin-bottom: 21px;
            text-align: center;
            display: none;
        }

        .modal-content .error {
            background: #fee2e2;
            color: #dc2626;
        }

        .modal-content .success {
            background: #d1fae5;
            color: #10b981;
        }

        .modal-content .error.show, .modal-content .success.show {
            display: block;
        }

        .modal-content .form-group {
            margin-bottom: 26px;
            text-align: left;
        }

        .modal-content .name-group {
            display: flex;
            gap: 10px;
            margin-bottom: 17px;
        }

        .modal-content .name-group .form-group {
            flex: 1;
            margin-bottom: 0;
        }

        .modal-content .location-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 26px;
        }

        .modal-content .input-wrapper {
            position: relative;
        }

        .modal-content .input-wrapper i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 12px;
        }

        .modal-content input[type="text"],
        .modal-content input[type="email"],
        .modal-content input[type="password"],
        .modal-content select {
            width: 100%;
            padding: 7px 7px 7px 31px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 12px;
            outline: none;
            background: transparent;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .modal-content .input-wrapper label {
            position: absolute;
            left: 31px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 13px;
            color: #666;
            background: #fff;
            padding: 0 5px;
            pointer-events: none;
            transition: all 0.2s ease;
        }

        .modal-content input:focus,
        .modal-content input:not(:placeholder-shown),
        .modal-content select:focus,
        .modal-content select:valid {
            border-color: #4CAF50;
            box-shadow: 0 0 5px rgba(76, 175, 80, 0.2);
        }

        .modal-content input:focus + label,
        .modal-content input:not(:placeholder-shown) + label,
        .modal-content select:focus + label,
        .modal-content select:valid + label {
            top: 0;
            transform: translateY(-50%);
            font-size: 11px;
            color: #4CAF50;
        }

        .modal-content input[type="submit"] {
            background: #4CAF50;
            color: #fff;
            padding: 10px;
            border: none;
            border-radius: 21px;
            width: 70%;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.3s, box-shadow 0.3s, background 0.3s;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .modal-content input[type="submit"]:hover {
            background: #388E3C;
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 4px 10px rgba(76, 175, 80, 0.4);
        }

        .modal-content input[type="submit"]:active {
            transform: translateY(0) scale(0.95);
        }

        .close-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 24px;
            cursor: pointer;
            color: #666;
        }

        @media (max-width: 768px) {
            .modal-content {
                padding: 21px;
                max-width: 90%;
            }

            .modal-content h3 {
                font-size: 22px;
            }

            .modal-content .error, .modal-content .success {
                margin-bottom: 16px;
                font-size: 9px;
                padding: 4px;
            }

            .modal-content .name-group {
                flex-direction: column;
                gap: 21px;
            }

            .modal-content .location-group {
                grid-template-columns: 1fr;
            }

            .modal-content .input-wrapper label {
                font-size: 10px;
                left: 26px;
            }

            .modal-content input[type="text"],
            .modal-content input[type="email"],
            .modal-content input[type="password"],
            .modal-content select {
                font-size: 10px;
                padding: 4px 4px 4px 26px;
            }

            .modal-content .input-wrapper i {
                font-size: 10px;
                left: 4px;
            }

            .modal-content input[type="submit"] {
                font-size: 10px;
                padding: 6px;
            }
        }

        @media (max-width: 480px) {
            .modal-content {
                padding: 16px;
            }

            .modal-content h3 {
                font-size: 18px;
            }

            .modal-content .error, .modal-content .success {
                font-size: 8px;
            }

            .modal-content .input-wrapper label {
                font-size: 9px;
                left: 22px;
            }

            .modal-content input[type="text"],
            .modal-content input[type="email"],
            .modal-content input[type="password"],
            .modal-content select {
                font-size: 9px;
                padding: 3px 3px 3px 22px;
            }

            .modal-content .input-wrapper i {
                font-size: 9px;
            }

            .modal-content input[type="submit"] {
                font-size: 9px;
                padding: 4px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <img src="<?php echo $logo_base64; ?>" alt="Logo" class="logo">
            <a href="admin_dashboard.php" title="Admin Dashboard"><i class="fas fa-tachometer-alt"></i></a>
            <a href="manage_barangays.php" title="Manage Barangays"><i class="fas fa-map"></i></a>
            <a href="manage_planting_sites.php" title="Manage Planting Sites"><i class="fas fa-seedling"></i></a>
            <a href="manage_rewards.php" title="Manage Rewards"><i class="fas fa-gifts"></i></a>
            <a href="manage_events.php" title="Manage Events"><i class="fas fa-calendar-alt"></i></a>
            <a href="manage_users.php" title="Manage Users"><i class="fas fa-users"></i></a>
            <a href="manage_validators.php" title="Manage Validators"><i class="fas fa-user-shield"></i></a>
            <a href="view_data.php" title="View All Data"><i class="fas fa-eye"></i></a>
            <a href="export_data.php" title="Export System Data"><i class="fas fa-download"></i></a>
        </div>
        <div class="main-content">
            <?php if (isset($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <div class="header">
                <h1>Green Roots - Admin Dashboard</h1>
                <div class="notification-search">
                    <div class="notification"><i class="fas fa-bell"></i></div>
                    <div class="search-bar">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" placeholder="Search" id="searchInput">
                        <div class="search-results" id="searchResults"></div>
                    </div>
                </div>
                <div class="profile" id="profileBtn">
                    <span><?php echo htmlspecialchars($admin['username']); ?></span>
                    <img src="<?php echo $profile_picture_data; ?>" alt="Profile">
                    <div class="profile-dropdown" id="profileDropdown">
                        <div class="email"><?php echo htmlspecialchars($admin['email']); ?></div>
                        <a href="account_settings.php">Account</a>
                        <a href="logout.php">Logout</a>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="details">
                    <div>
                        <p>Total Users</p>
                        <h2><?php echo $total_users; ?></h2>
                    </div>
                    <div>
                        <p>Total Validators</p>
                        <h2><?php echo $total_validators; ?></h2>
                    </div>
                </div>
            </div>
            <div class="stats-grid">
                <div class="stat-box">
                    <h3>User Growth (Last 6 Months)</h3>
                    <canvas id="userGrowthChart"></canvas>
                </div>
                <div class="stat-box">
                    <h3>System Metrics</h3>
                    <p>Active Users (Last 7 Days): <?php echo $active_users; ?></p>
                    <p>Total Submissions: <?php echo $total_submissions; ?></p>
                    <p>Total Events: <?php echo $total_events; ?></p>
                    <p>Pending Feedback: <?php echo $pending_feedback; ?></p>
                    <p>Total Trees Planted: <?php echo $total_trees_planted; ?></p>
                </div>
                <div class="stat-box">
                    <h3>Recent Events</h3>
                    <ul>
                        <?php if (empty($recent_events)): ?>
                            <li>No recent events</li>
                        <?php else: ?>
                            <?php foreach ($recent_events as $event): ?>
                                <li>
                                    <i class="fas fa-calendar-check"></i>
                                    <?php echo htmlspecialchars($event['title']); ?> - 
                                    <?php echo date('M d', strtotime($event['event_date'])); ?> at 
                                    <?php echo htmlspecialchars($event['location']); ?>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="stat-box">
                    <h3>Recent Vouchers</h3>
                    <ul>
                        <?php if (empty($recent_vouchers)): ?>
                            <li>No recent vouchers</li>
                        <?php else: ?>
                            <?php foreach ($recent_vouchers as $voucher): ?>
                                <li>
                                    <i class="fas fa-gift"></i>
                                    <?php echo htmlspecialchars($voucher['name']); ?> - 
                                    <?php echo htmlspecialchars($voucher['points_cost']); ?> points, 
                                    Expires: <?php echo date('M d', strtotime($voucher['expiry_date'])); ?>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            <a href="manage_barangays.php" class="download-btn">Manage Barangays</a>
            <a href="manage_planting_sites.php" class="download-btn">Manage Planting Sites</a>
            <a href="manage_rewards.php" class="download-btn">Manage Rewards</a>
            <a href="manage_events.php" class="download-btn">Manage Events</a>
            <a href="manage_users.php" class="download-btn">Manage Users</a>
            <a href="manage_validators.php" class="download-btn">Manage Validators</a>
            <a href="view_data.php" class="download-btn">View All Data</a>
            <a href="export_data.php" class="download-btn">Export System Data</a>
            <button class="download-btn" id="registerValidatorBtn">Register Validator</button>
        </div>
    </div>

    <!-- Validator Registration Modal -->
    <div id="registerValidatorModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal()">&times;</span>
            <h3>Register New Validator</h3>
            <div class="error <?php echo isset($register_error) ? 'show' : ''; ?>">
                <?php echo isset($register_error) ? htmlspecialchars($register_error) : ''; ?>
            </div>
            <div class="success <?php echo isset($register_success) ? 'show' : ''; ?>">
                <?php echo isset($register_success) ? htmlspecialchars($register_success) : ''; ?>
            </div>
            <form method="POST" action="" id="validatorForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="register_validator" value="1">
                <div class="name-group">
                    <div class="form-group">
                        <div class="input-wrapper">
                            <i class="fas fa-user"></i>
                            <input type="text" id="first_name" name="first_name" placeholder=" " required>
                            <label for="first_name">Enter your first name</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="input-wrapper">
                            <i class="fas fa-user"></i>
                            <input type="text" id="last_name" name="last_name" placeholder=" " required>
                            <label for="last_name">Enter your last name</label>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="username" name="username" placeholder=" " required>
                        <label for="username">Enter your username</label>
                    </div>
                </div>
                <div class="form-group">
                    <div class="input-wrapper">
                        <i class="fas fa-envelope"></i>
                        <input type="email" id="email" name="email" placeholder=" " required>
                        <label for="email">Enter your email</label>
                    </div>
                </div>
                <div class="form-group">
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" placeholder=" " required>
                        <label for="password">Enter your password</label>
                    </div>
                </div>
                <div class="form-group">
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder=" " required>
                        <label for="confirm_password">Confirm your password</label>
                    </div>
                </div>
                <div class="form-group location-group">
                    <div class="input-wrapper">
                        <i class="fas fa-map-marker-alt"></i>
                        <select id="region" name="region" required>
                            <option value="" disabled selected hidden></option>
                        </select>
                        <label for="region">Select your region</label>
                    </div>
                    <div class="input-wrapper">
                        <i class="fas fa-map-marker-alt"></i>
                        <select id="province" name="province" required>
                            <option value="" disabled selected hidden></option>
                        </select>
                        <label for="province">Select your province</label>
                    </div>
                    <div class="input-wrapper">
                        <i class="fas fa-map-marker-alt"></i>
                        <select id="city" name="city" required>
                            <option value="" disabled selected hidden></option>
                        </select>
                        <label for="city">Select your city</label>
                    </div>
                    <div class="input-wrapper">
                        <i class="fas fa-map-marker-alt"></i>
                        <select id="barangay" name="barangay" required>
                            <option value="" disabled selected hidden></option>
                        </select>
                        <label for="barangay">Select your barangay</label>
                    </div>
                    <input type="hidden" id="barangay_id" name="barangay_id" required>
                </div>
                <input type="submit" value="Register Validator">
            </form>
        </div>
    </div>

    <script>
        // Search bar functionality
        const functionalities = [
            { name: 'Admin Dashboard', url: 'admin_dashboard.php' },
            { name: 'Manage Barangays', url: 'manage_barangays.php' },
            { name: 'Manage Planting Sites', url: 'manage_planting_sites.php' },
            { name: 'Manage Rewards', url: 'manage_rewards.php' },
            { name: 'Manage Events', url: 'manage_events.php' },
            { name: 'Manage Users', url: 'manage_users.php' },
            { name: 'Manage Validators', url: 'manage_validators.php' },
            { name: 'View All Data', url: 'view_data.php' },
            { name: 'Export System Data', url: 'export_data.php' },
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

        // User Growth Chart
        const userGrowthData = <?php echo json_encode($user_growth_data); ?>;
        const months = Array.from({ length: 6 }, (_, i) => {
            const date = new Date();
            date.setMonth(date.getMonth() - (5 - i));
            return date.toLocaleString('default', { month: 'short' });
        });

        const ctx = document.getElementById('userGrowthChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [{
                    label: 'New Users',
                    data: userGrowthData,
                    borderColor: '#4CAF50',
                    backgroundColor: 'rgba(76, 175, 80, 0.2)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        // Validator Registration Modal
        const registerValidatorBtn = document.querySelector('#registerValidatorBtn');
        const registerValidatorModal = document.querySelector('#registerValidatorModal');
        const validatorForm = document.querySelector('#validatorForm');

        registerValidatorBtn.addEventListener('click', function() {
            registerValidatorModal.style.display = 'flex';
        });

        function closeModal() {
            registerValidatorModal.style.display = 'none';
            const error = registerValidatorModal.querySelector('.error');
            const success = registerValidatorModal.querySelector('.success');
            error.classList.remove('show');
            success.classList.remove('show');
            error.textContent = '';
            success.textContent = '';
            validatorForm.reset();
        }

        document.querySelector('.close-btn').addEventListener('click', closeModal);

        document.addEventListener('click', function(e) {
            if (e.target === registerValidatorModal) {
                closeModal();
            }
        });

        // Location selection AJAX
        const regionSelect = document.querySelector('#region');
        const provinceSelect = document.querySelector('#province');
        const citySelect = document.querySelector('#city');
        const barangaySelect = document.querySelector('#barangay');
        const barangayIdInput = document.querySelector('#barangay_id');

        async function fetchOptions(level, parent = '', parentType = 'province') {
            try {
                const response = await fetch(`../services/get_locations.php?level=${level}&parent=${encodeURIComponent(parent)}&parentType=${parentType}`);
                const data = await response.json();
                if (data.error) throw new Error(data.error);
                return data;
            } catch (error) {
                console.error('Error fetching data:', error);
                registerValidatorModal.querySelector('.error').textContent = 'Failed to load locations. Please try again.';
                registerValidatorModal.querySelector('.error').classList.add('show');
                return [];
            }
        }

        function populateSelect(selectElement, items, isBarangay = false) {
            selectElement.innerHTML = '<option value="" disabled selected hidden></option>';
            items.forEach(item => {
                const option = document.createElement('option');
                if (isBarangay) {
                    const displayName = item.name.replace(/\s*\([^)]+\)\s*$/, '');
                    option.textContent = displayName;
                    option.value = item.barangay_id;
                    option.dataset.fullName = item.name;
                } else {
                    option.textContent = item;
                    option.value = item;
                }
                selectElement.appendChild(option);
            });
            selectElement.disabled = false;
        }

        async function loadRegions() {
            const regions = await fetchOptions('regions');
            populateSelect(regionSelect, regions);
        }

        regionSelect.addEventListener('change', async () => {
            const region = regionSelect.value;
            provinceSelect.disabled = false;
            citySelect.disabled = true;
            barangaySelect.disabled = true;
            barangayIdInput.value = '';

            provinceSelect.innerHTML = '<option value="" disabled selected hidden></option>';
            citySelect.innerHTML = '<option value="" disabled selected hidden></option>';
            barangaySelect.innerHTML = '<option value="" disabled selected hidden></option>';

            if (region === 'National Capital Region (NCR)') {
                provinceSelect.disabled = true;
                provinceSelect.innerHTML = '<option value="" disabled selected hidden>No provinces in NCR</option>';
                const cities = await fetchOptions('cities', region, 'region');
                populateSelect(citySelect, cities);
                citySelect.disabled = false;
            } else {
                const provinces = await fetchOptions('provinces', region);
                populateSelect(provinceSelect, provinces);
            }
        });

        provinceSelect.addEventListener('change', async () => {
            const province = provinceSelect.value;
            citySelect.disabled = false;
            barangaySelect.disabled = true;
            barangayIdInput.value = '';

            citySelect.innerHTML = '<option value="" disabled selected hidden></option>';
            barangaySelect.innerHTML = '<option value="" disabled selected hidden></option>';

            const cities = await fetchOptions('cities', province);
            populateSelect(citySelect, cities);
        });

        citySelect.addEventListener('change', async () => {
            const city = citySelect.value;
            barangaySelect.disabled = false;
            barangayIdInput.value = '';

            barangaySelect.innerHTML = '<option value="" disabled selected hidden></option>';

            const barangays = await fetchOptions('barangays', city);
            populateSelect(barangaySelect, barangays, true);
        });

        barangaySelect.addEventListener('change', () => {
            barangayIdInput.value = barangaySelect.value;
        });

        loadRegions();

        // Client-side validation
        validatorForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const firstName = document.querySelector('#first_name').value;
            const lastName = document.querySelector('#last_name').value;
            const username = document.querySelector('#username').value;
            const email = document.querySelector('#email').value;
            const password = document.querySelector('#password').value;
            const confirmPassword = document.querySelector('#confirm_password').value;
            const barangayId = document.querySelector('#barangay_id').value;
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            let isValid = true;
            const errorElement = registerValidatorModal.querySelector('.error');
            errorElement.classList.remove('show');
            errorElement.textContent = '';

            if (!firstName || !lastName || !username || !email || !password || !confirmPassword || !barangayId) {
                isValid = false;
                errorElement.textContent = 'Please fill in all fields.';
            } else if (firstName.length < 2) {
                isValid = false;
                errorElement.textContent = 'First name must be at least 2 characters long.';
            } else if (lastName.length < 2) {
                isValid = false;
                errorElement.textContent = 'Last name must be at least 2 characters long.';
            } else if (!emailRegex.test(email)) {
                isValid = false;
                errorElement.textContent = 'Invalid email format.';
            } else if (password.length < 8) {
                isValid = false;
                errorElement.textContent = 'Password must be at least 8 characters long.';
            } else if (password !== confirmPassword) {
                isValid = false;
                errorElement.textContent = 'Passwords do not match.';
            } else if (isNaN(barangayId)) {
                isValid = false;
                errorElement.textContent = 'Please select a valid barangay.';
            }

            if (isValid) {
                this.submit();
            } else {
                errorElement.classList.add('show');
            }
        });
    </script>
</body>
</html>