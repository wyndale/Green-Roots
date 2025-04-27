<?php
// Start session and handle PHP logic first
session_start();
require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Fetch user data and rankings
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header('Location: login.php');
        exit;
    }

    $barangay_id = $user['barangay_id'];

    // Fetch the user's rank within their barangay
    $stmt = $pdo->prepare("
        SELECT user_rank
        FROM (
            SELECT user_id, 
                   RANK() OVER (ORDER BY trees_planted DESC) as user_rank
            FROM users
            WHERE barangay_id = :barangay_id
        ) ranked_users
        WHERE user_id = :user_id
    ");
    $stmt->execute([
        'barangay_id' => $barangay_id,
        'user_id' => $user_id
    ]);
    $user_rank = $stmt->fetchColumn();
    $user_rank_display = $user_rank !== false ? "#$user_rank" : "Not Ranked";

    // Fetch top users in the barangay (including rank)
    $stmt = $pdo->prepare("
        SELECT 
            user_id,
            username,
            trees_planted,
            RANK() OVER (ORDER BY trees_planted DESC) as user_rank
        FROM users
        WHERE barangay_id = :barangay_id
        ORDER BY user_rank ASC
        LIMIT 10
    ");
    $stmt->execute(['barangay_id' => $barangay_id]);
    $top_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch barangay details
    $stmt = $pdo->prepare("SELECT name, city, province FROM barangays WHERE barangay_id = :barangay_id");
    $stmt->execute(['barangay_id' => $barangay_id]);
    $barangay = $stmt->fetch(PDO::FETCH_ASSOC);
    $barangay_name = $barangay['name'];

    // Fetch barangay rankings (top 10)
    $stmt = $pdo->prepare("
        SELECT r.ranking_id, r.barangay_id, r.total_trees_planted, r.rank_position, b.name, b.city, b.province
        FROM rankings r
        JOIN barangays b ON r.barangay_id = b.barangay_id
        ORDER BY r.rank_position ASC
        LIMIT 10
    ");
    $stmt->execute();
    $barangay_rankings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch the top 1 barangay (for minimal display)
    $stmt = $pdo->prepare("
        SELECT r.ranking_id, r.barangay_id, r.total_trees_planted, r.rank_position, b.name, b.city, b.province
        FROM rankings r
        JOIN barangays b ON r.barangay_id = b.barangay_id
        WHERE r.rank_position = 1
        LIMIT 1
    ");
    $stmt->execute();
    $top_barangay = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch the user's barangay rank
    $stmt = $pdo->prepare("
        SELECT rank_position, total_trees_planted
        FROM rankings
        WHERE barangay_id = :barangay_id
    ");
    $stmt->execute(['barangay_id' => $barangay_id]);
    $user_barangay_rank = $stmt->fetch(PDO::FETCH_ASSOC);
    $user_barangay_rank_display = $user_barangay_rank ? "#{$user_barangay_rank['rank_position']}" : "Not Ranked";

    // Fetch regional rankings (group by city and province)
    $stmt = $pdo->prepare("
        SELECT 
            b.city, 
            b.province, 
            SUM(r.total_trees_planted) as total_trees,
            RANK() OVER (ORDER BY SUM(r.total_trees_planted) DESC) as region_rank
        FROM rankings r
        JOIN barangays b ON r.barangay_id = b.barangay_id
        GROUP BY b.city, b.province
        ORDER BY region_rank ASC
        LIMIT 10
    ");
    $stmt->execute();
    $regional_rankings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch the top 1 region (for minimal display)
    $stmt = $pdo->prepare("
        SELECT 
            b.city, 
            b.province, 
            SUM(r.total_trees_planted) as total_trees,
            RANK() OVER (ORDER BY SUM(r.total_trees_planted) DESC) as region_rank
        FROM rankings r
        JOIN barangays b ON r.barangay_id = b.barangay_id
        GROUP BY b.city, b.province
        ORDER BY region_rank ASC
        LIMIT 1
    ");
    $stmt->execute();
    $top_region = $stmt->fetch(PDO::FETCH_ASSOC);

    // Find the user's region rank
    $stmt = $pdo->prepare("
        SELECT 
            region_rank
        FROM (
            SELECT 
                b.city, 
                b.province, 
                SUM(r.total_trees_planted) as total_trees,
                RANK() OVER (ORDER BY SUM(r.total_trees_planted) DESC) as region_rank
            FROM rankings r
            JOIN barangays b ON r.barangay_id = b.barangay_id
            GROUP BY b.city, b.province
        ) ranked_regions
        WHERE city = :city AND province = :province
    ");
    $stmt->execute([
        'city' => $barangay['city'],
        'province' => $barangay['province']
    ]);
    $user_region_rank = $stmt->fetchColumn();
    $user_region_rank_display = $user_region_rank !== false ? "#$user_region_rank" : "Not Ranked";

    // Define the uploads directory path
    $upload_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
    $upload_dir_relative = '../uploads/';

    // Create the uploads directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            $profile_error = 'Failed to create uploads directory. Please contact the administrator.';
        }
    }

    // Handle profile update (same as dashboard.php and submit.php)
    $profile_error = '';
    $profile_success = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
        $new_username = trim($_POST['username']);
        $new_email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $new_phone = trim($_POST['phone_number']);

        // Validate inputs
        if (empty($new_username) || empty($new_email)) {
            $profile_error = 'Username and email are required.';
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $profile_error = 'Invalid email format.';
        } elseif (!empty($new_phone) && !preg_match('/^\+?\d{1,4}[\s-]?\d{1,15}$/', $new_phone)) {
            $profile_error = 'Invalid phone number format.';
        } else {
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
                $profile_error = 'Username or email already taken.';
            } else {
                // Handle profile picture upload
                $profile_picture = $user['profile_picture'];
                if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                    $file_tmp = $_FILES['profile_picture']['tmp_name'];
                    $file_name = $_FILES['profile_picture']['name'];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'ico', 'webp', 'svg'];

                    if (!in_array($file_ext, $allowed_exts)) {
                        $profile_error = 'Only JPG, JPEG, PNG, GIF, ICO, WEBP, and SVG files are allowed.';
                    } elseif ($_FILES['profile_picture']['size'] > 15 * 1024 * 1024) { // 15MB limit
                        $profile_error = 'File size must be less than 15MB.';
                    } else {
                        $new_file_name = 'profile_' . $user_id . '.' . $file_ext;
                        $upload_path = $upload_dir . $new_file_name;
                        $upload_path_relative = $upload_dir_relative . $new_file_name;

                        // Check if the directory is writable
                        if (!is_writable($upload_dir)) {
                            $profile_error = 'Uploads directory is not writable. Please contact the administrator.';
                        } elseif (move_uploaded_file($file_tmp, $upload_path)) {
                            $profile_picture = $upload_path_relative;
                        } else {
                            $profile_error = 'Failed to upload profile picture. Please try again.';
                        }
                    }
                }

                if (!$profile_error) {
                    // Update user information
                    $stmt = $pdo->prepare("
                        UPDATE users 
                        SET username = :username, email = :email, phone_number = :phone_number, profile_picture = :profile_picture
                        WHERE user_id = :user_id
                    ");
                    $stmt->execute([
                        'username' => $new_username,
                        'email' => $new_email,
                        'phone_number' => $new_phone ?: NULL, // Set to NULL if empty
                        'profile_picture' => $profile_picture,
                        'user_id' => $user_id
                    ]);

                    // Update session username
                    $_SESSION['username'] = $new_username;
                    $username = $new_username;
                    $user['email'] = $new_email;
                    $user['phone_number'] = $new_phone;
                    $user['profile_picture'] = $profile_picture;

                    $profile_success = 'Profile updated successfully!';
                }
            }
        }
    }

    // Handle password change
    $password_error = '';
    $password_success = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        // Validate inputs
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $password_error = 'Please fill in all fields.';
        } elseif ($new_password !== $confirm_password) {
            $password_error = 'New passwords do not match.';
        } elseif (strlen($new_password) < 8) {
            $password_error = 'New password must be at least 8 characters long.';
        } else {
            // Verify current password
            if (!password_verify($current_password, $user['password'])) {
                $password_error = 'Current password is incorrect.';
            } else {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = :password WHERE user_id = :user_id");
                $stmt->execute([
                    'password' => $hashed_password,
                    'user_id' => $user_id
                ]);
                $password_success = 'Password updated successfully!';
            }
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
    <title>Leaderboard - Tree Planting Initiative</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #e0e7ff, #f5f7fa);
            color: #333;
        }

        .container {
            display: flex;
            min-height: 100vh;
            max-width: 1920px;
            margin: 0 auto;
        }

        .sidebar {
            width: 100px;
            background: #fff;
            padding: 30px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }

        .sidebar img.logo {
            width: 60px;
            margin-bottom: 30px;
        }

        .sidebar a {
            margin: 20px 0;
            color: #666;
            text-decoration: none;
            font-size: 28px;
        }

        .sidebar a:hover {
            color: #4f46e5;
        }

        .main-content {
            flex: 1;
            padding: 40px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
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
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
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
            border-radius: 10px;
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

        .card {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: #fff;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            margin-bottom: 30px;
        }

        .card h2 {
            font-size: 28px;
            margin-bottom: 15px;
        }

        .card p {
            font-size: 18px;
        }

        .ranking-section {
            margin-bottom: 40px;
        }

        .ranking-card {
            background: #fff;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            cursor: pointer;
            transition: transform 0.2s;
        }

        .ranking-card:hover {
            transform: translateY(-5px);
        }

        .ranking-card h2 {
            font-size: 24px;
            color: #1e3a8a;
            margin-bottom: 15px;
        }

        .ranking-card p {
            font-size: 16px;
            color: #666;
        }

        .ranking-table {
            background: #fff;
            padding: 20px;
            border-radius: 15px;
        }

        .ranking-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .ranking-table th, .ranking-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e0e7ff;
            font-size: 16px;
        }

        .ranking-table th {
            background: #f5f7fa;
            color: #1e3a8a;
        }

        .ranking-table tr.highlight {
            background: #d1fae5;
            font-weight: bold;
        }

        .error-message {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 16px;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 100;
        }

        .modal.active {
            display: block;
        }

        .modal-content {
            background: #fff;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
            margin: 100px auto;
            position: relative;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-content::-webkit-scrollbar {
            width: 8px;
        }

        .modal-content::-webkit-scrollbar-track {
            background: #f5f7fa;
            border-radius: 4px;
        }

        .modal-content::-webkit-scrollbar-thumb {
            background: #4f46e5;
            border-radius: 4px;
        }

        .modal-content::-webkit-scrollbar-thumb:hover {
            background: #7c3aed;
        }

        .modal-content .close-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 28px;
            cursor: pointer;
            color: #666;
        }

        .modal-content h2 {
            font-size: 28px;
            color: #1e3a8a;
            margin-bottom: 25px;
        }

        .modal-content .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            display: none;
            font-size: 16px;
        }

        .modal-content .success {
            background: #d1fae5;
            color: #10b981;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            display: none;
            font-size: 16px;
        }

        .modal-content .error.show,
        .modal-content .success.show {
            display: block;
        }

        .modal-content .form-group {
            margin-bottom: 25px;
        }

        .modal-content label {
            display: block;
            font-size: 16px;
            color: #666;
            margin-bottom: 8px;
        }

        .modal-content input {
            width: 100%;
            padding: 12px;
            border: 1px solid #e0e7ff;
            border-radius: 5px;
            font-size: 16px;
            outline: none;
        }

        .modal-content input:focus {
            border-color: #4f46e5;
        }

        .modal-content input[type="submit"] {
            background: #4f46e5;
            color: #fff;
            border: none;
            cursor: pointer;
            transition: background 0.3s;
            padding: 12px;
            font-size: 16px;
        }

        .modal-content input[type="submit"]:hover {
            background: #7c3aed;
        }

        .modal-content .change-password-btn {
            display: block;
            background: #4f46e5;
            color: #fff;
            text-align: center;
            padding: 12px;
            border-radius: 5px;
            text-decoration: none;
            margin-bottom: 15px;
            cursor: pointer;
            font-size: 16px;
        }

        .modal-content .change-password-btn:hover {
            background: #7c3aed;
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
                box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
                padding: 10px 0;
            }

            .sidebar img.logo {
                display: none;
            }

            .main-content {
                padding: 20px;
                padding-bottom: 80px;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
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

            .header .profile {
                margin-top: 15px;
            }

            .header .profile img {
                width: 40px;
                height: 40px;
            }

            .header .profile span {
                font-size: 16px;
            }

            .card {
                padding: 20px;
            }

            .card h2 {
                font-size: 24px;
            }

            .card p {
                font-size: 16px;
            }

            .ranking-card h2 {
                font-size: 20px;
            }

            .ranking-card p {
                font-size: 14px;
            }

            .modal-content {
                padding: 20px;
                margin: 80px auto;
                max-width: 90%;
            }

            .modal-content h2 {
                font-size: 24px;
            }

            .modal-content .ranking-table {
                padding: 15px;
            }

            .modal-content .ranking-table th, .modal-content .ranking-table td {
                padding: 10px;
                font-size: 14px;
            }

            .modal-content label {
                font-size: 14px;
            }

            .modal-content input {
                padding: 10px;
                font-size: 14px;
            }

            .modal-content .change-password-btn,
            .modal-content input[type="submit"] {
                padding: 10px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <img src="logo.png" alt="Logo" class="logo">
            <a href="dashboard.php" title="Dashboard">🏠</a>
            <a href="submit.php" title="Submit Planting">🌳</a>
            <a href="leaderboard.php" title="Leaderboard">📊</a>
            <a href="rewards.php" title="Rewards">🎁</a>
            <a href="events.php" title="Events">📅</a>
            <a href="logout.php" title="Logout">🚪</a>
        </div>
        <div class="main-content">
            <?php if (isset($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <div class="header">
                <h1>Leaderboard</h1>
                <div class="search-bar">
                    <input type="text" placeholder="Search functionalities..." id="searchInput">
                    <div class="search-results" id="searchResults"></div>
                </div>
                <div class="profile" id="profileBtn">
                    <span><?php echo htmlspecialchars($username); ?></span>
                    <img src="<?php echo $user['profile_picture'] ? htmlspecialchars($user['profile_picture']) : 'profile.jpg'; ?>" alt="Profile">
                </div>
            </div>
            <div class="card">
                <h2>Your Rankings</h2>
                <p>Rank in <?php echo htmlspecialchars($barangay_name); ?>: <?php echo htmlspecialchars(str_replace('#', '', $user_rank_display)); ?></p>
                <p><?php echo htmlspecialchars($barangay_name); ?> Rank: <?php echo htmlspecialchars(str_replace('#', '', $user_barangay_rank_display)); ?> with <?php echo htmlspecialchars($user_barangay_rank['total_trees_planted'] ?? 0); ?> trees</p>
                <p>Region <?php echo htmlspecialchars($barangay['city'] . ', ' . $barangay['province']); ?> Rank: <?php echo htmlspecialchars(str_replace('#', '', $user_region_rank_display)); ?></p>
            </div>
            <div class="ranking-section">
                <div class="ranking-card" id="usersRankBtn">
                    <h2>Top Users in <?php echo htmlspecialchars($barangay_name); ?></h2>
                    <p>Your Rank: <?php echo htmlspecialchars(str_replace('#', '', $user_rank_display)); ?></p>
                </div>
            </div>
            <div class="ranking-section">
                <div class="ranking-card" id="barangaysRankBtn">
                    <h2>Top Barangays</h2>
                    <?php if ($top_barangay): ?>
                        <p>#1: <?php echo htmlspecialchars($top_barangay['name']); ?>, <?php echo htmlspecialchars($top_barangay['city'] . ', ' . $top_barangay['province']); ?> (<?php echo htmlspecialchars($top_barangay['total_trees_planted']); ?> trees)</p>
                    <?php else: ?>
                        <p>No barangay rankings available.</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ranking-section">
                <div class="ranking-card" id="regionsRankBtn">
                    <h2>Top Regions</h2>
                    <?php if ($top_region): ?>
                        <p>#1: <?php echo htmlspecialchars($top_region['city'] . ', ' . $top_region['province']); ?> (<?php echo htmlspecialchars($top_region['total_trees']); ?> trees)</p>
                    <?php else: ?>
                        <p>No regional rankings available.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Users Modal -->
    <div class="modal" id="usersRankModal">
        <div class="modal-content">
            <span class="close-btn" id="closeUsersRankModal">×</span>
            <h2>Top Users in <?php echo htmlspecialchars($barangay_name); ?></h2>
            <div class="ranking-table">
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Username</th>
                            <th>Trees Planted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_users as $top_user): ?>
                            <tr <?php echo $top_user['user_id'] == $user_id ? 'class="highlight"' : ''; ?>>
                                <td><?php echo htmlspecialchars($top_user['user_rank']); ?></td>
                                <td><?php echo htmlspecialchars($top_user['username']); ?></td>
                                <td><?php echo htmlspecialchars($top_user['trees_planted']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Top Barangays Modal -->
    <div class="modal" id="barangaysRankModal">
        <div class="modal-content">
            <span class="close-btn" id="closeBarangaysRankModal">×</span>
            <h2>Top Barangays</h2>
            <div class="ranking-table">
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Barangay</th>
                            <th>City, Province</th>
                            <th>Total Trees Planted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($barangay_rankings as $ranking): ?>
                            <tr <?php echo $ranking['barangay_id'] == $barangay_id ? 'class="highlight"' : ''; ?>>
                                <td><?php echo htmlspecialchars($ranking['rank_position']); ?></td>
                                <td><?php echo htmlspecialchars($ranking['name']); ?></td>
                                <td><?php echo htmlspecialchars($ranking['city'] . ', ' . $ranking['province']); ?></td>
                                <td><?php echo htmlspecialchars($ranking['total_trees_planted']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Top Regions Modal -->
    <div class="modal" id="regionsRankModal">
        <div class="modal-content">
            <span class="close-btn" id="closeRegionsRankModal">×</span>
            <h2>Top Regions</h2>
            <div class="ranking-table">
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>City, Province</th>
                            <th>Total Trees Planted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($regional_rankings as $region): ?>
                            <tr <?php echo ($region['city'] == $barangay['city'] && $region['province'] == $barangay['province']) ? 'class="highlight"' : ''; ?>>
                                <td><?php echo htmlspecialchars($region['region_rank']); ?></td>
                                <td><?php echo htmlspecialchars($region['city'] . ', ' . $region['province']); ?></td>
                                <td><?php echo htmlspecialchars($region['total_trees']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Profile Edit Modal -->
    <div class="modal" id="profileModal">
        <div class="modal-content">
            <span class="close-btn" id="closeProfileModal">×</span>
            <h2>Edit Profile</h2>
            <?php if ($profile_error): ?>
                <div class="error show"><?php echo htmlspecialchars($profile_error); ?></div>
            <?php endif; ?>
            <?php if ($profile_success): ?>
                <div class="success show"><?php echo htmlspecialchars($profile_success); ?></div>
            <?php endif; ?>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="update_profile" value="1">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="phone_number">Phone Number (Optional)</label>
                    <input type="text" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($user['phone_number'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="profile_picture">Profile Picture</label>
                    <input type="file" id="profile_picture" name="profile_picture" accept="image/*">
                </div>
                <button type="button" class="change-password-btn" id="changePasswordBtn">Change Password</button>
                <input type="submit" value="Update Profile">
            </form>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal" id="passwordModal">
        <div class="modal-content">
            <span class="close-btn" id="closePasswordModal">×</span>
            <h2>Change Password</h2>
            <?php if ($password_error): ?>
                <div class="error show"><?php echo htmlspecialchars($password_error); ?></div>
            <?php endif; ?>
            <?php if ($password_success): ?>
                <div class="success show"><?php echo htmlspecialchars($password_success); ?></div>
            <?php endif; ?>
            <form method="POST" action="">
                <input type="hidden" name="change_password" value="1">
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                <input type="submit" value="Change Password">
            </form>
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
            { name: 'Submission History', url: 'history.php' },
            { name: 'Logout', url: 'logout.php' }
        ];

        const searchInput = document.querySelector('#searchInput');
        const searchResults = document.querySelector('#searchResults');

        searchInput.addEventListener('input', function(e) {
            const query = e.target.value.toLowerCase();
            searchResults.innerHTML = '';
            searchResults.classList.remove('active');

            if (query) {
                const matches = functionalities.filter(func => 
                    func.name.toLowerCase().includes(query)
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

        // Profile edit modal functionality
        const profileBtn = document.querySelector('#profileBtn');
        const profileModal = document.querySelector('#profileModal');
        const closeProfileModal = document.querySelector('#closeProfileModal');

        profileBtn.addEventListener('click', function() {
            profileModal.classList.add('active');
        });

        closeProfileModal.addEventListener('click', function() {
            profileModal.classList.remove('active');
        });

        profileModal.addEventListener('click', function(e) {
            if (e.target === profileModal) {
                profileModal.classList.remove('active');
            }
        });

        // Change password modal functionality
        const changePasswordBtn = document.querySelector('#changePasswordBtn');
        const passwordModal = document.querySelector('#passwordModal');
        const closePasswordModal = document.querySelector('#closePasswordModal');

        changePasswordBtn.addEventListener('click', function() {
            profileModal.classList.remove('active');
            passwordModal.classList.add('active');
        });

        closePasswordModal.addEventListener('click', function() {
            passwordModal.classList.remove('active');
        });

        passwordModal.addEventListener('click', function(e) {
            if (e.target === passwordModal) {
                passwordModal.classList.remove('active');
            }
        });

        // Top Users modal functionality
        const usersRankBtn = document.querySelector('#usersRankBtn');
        const usersRankModal = document.querySelector('#usersRankModal');
        const closeUsersRankModal = document.querySelector('#closeUsersRankModal');

        usersRankBtn.addEventListener('click', function() {
            usersRankModal.classList.add('active');
        });

        closeUsersRankModal.addEventListener('click', function() {
            usersRankModal.classList.remove('active');
        });

        usersRankModal.addEventListener('click', function(e) {
            if (e.target === usersRankModal) {
                usersRankModal.classList.remove('active');
            }
        });

        // Top Barangays modal functionality
        const barangaysRankBtn = document.querySelector('#barangaysRankBtn');
        const barangaysRankModal = document.querySelector('#barangaysRankModal');
        const closeBarangaysRankModal = document.querySelector('#closeBarangaysRankModal');

        barangaysRankBtn.addEventListener('click', function() {
            barangaysRankModal.classList.add('active');
        });

        closeBarangaysRankModal.addEventListener('click', function() {
            barangaysRankModal.classList.remove('active');
        });

        barangaysRankModal.addEventListener('click', function(e) {
            if (e.target === barangaysRankModal) {
                barangaysRankModal.classList.remove('active');
            }
        });

        // Top Regions modal functionality
        const regionsRankBtn = document.querySelector('#regionsRankBtn');
        const regionsRankModal = document.querySelector('#regionsRankModal');
        const closeRegionsRankModal = document.querySelector('#closeRegionsRankModal');

        regionsRankBtn.addEventListener('click', function() {
            regionsRankModal.classList.add('active');
        });

        closeRegionsRankModal.addEventListener('click', function() {
            regionsRankModal.classList.remove('active');
        });

        regionsRankModal.addEventListener('click', function(e) {
            if (e.target === regionsRankModal) {
                regionsRankModal.classList.remove('active');
            }
        });
    </script>
</body>
</html>