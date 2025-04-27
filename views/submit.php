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

    $barangay_id = $user['barangay_id'];

    // Define the uploads directory path
    $upload_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
    $upload_dir_relative = '../uploads/';

    // Create the uploads directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            $submission_error = 'Failed to create uploads directory. Please contact the administrator.';
        }
    }

    // Handle tree planting submission
    $submission_error = '';
    $submission_success = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_planting'])) {
        $trees_planted = filter_input(INPUT_POST, 'trees_planted', FILTER_VALIDATE_INT);
        $latitude = filter_input(INPUT_POST, 'latitude', FILTER_VALIDATE_FLOAT);
        $longitude = filter_input(INPUT_POST, 'longitude', FILTER_VALIDATE_FLOAT);
        $location_accuracy = filter_input(INPUT_POST, 'location_accuracy', FILTER_VALIDATE_FLOAT);

        // Validate inputs
        if ($trees_planted === false || $trees_planted <= 0) {
            $submission_error = 'Please enter a valid number of trees planted (must be a positive integer).';
        } elseif (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $submission_error = 'Please upload a photo of the tree planting.';
        } elseif ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            $submission_error = 'Unable to capture valid GPS coordinates. Please enable location services and try again.';
        } else {
            // Validate photo
            $file_tmp = $_FILES['photo']['tmp_name'];
            $file_name = $_FILES['photo']['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];

            if (!in_array($file_ext, $allowed_exts)) {
                $submission_error = 'Only JPG, JPEG, PNG, and GIF files are allowed for tree planting photos.';
            } elseif ($_FILES['photo']['size'] > 15 * 1024 * 1024) { // 15MB limit
                $submission_error = 'Photo size must be less than 15MB.';
            } else {
                // Generate a unique file name
                $new_file_name = 'submission_' . $user_id . '_' . time() . '.' . $file_ext;
                $upload_path = $upload_dir . $new_file_name;
                $upload_path_relative = $upload_dir_relative . $new_file_name;

                // Check if the directory is writable
                if (!is_writable($upload_dir)) {
                    $submission_error = 'Uploads directory is not writable. Please contact the administrator.';
                } elseif (move_uploaded_file($file_tmp, $upload_path)) {
                    // Generate photo hash
                    $photo_hash = hash_file('sha256', $upload_path);

                    // Get IP address
                    $ip_address = $_SERVER['REMOTE_ADDR'];

                    // Insert submission into database
                    $stmt = $pdo->prepare("
                        INSERT INTO submissions (
                            user_id, barangay_id, trees_planted, photo_path, photo_timestamp,
                            photo_hash, latitude, longitude, location_accuracy,
                            device_location_timestamp, ip_address, status
                        ) VALUES (
                            :user_id, :barangay_id, :trees_planted, :photo_path, NOW(),
                            :photo_hash, :latitude, :longitude, :location_accuracy,
                            NOW(), :ip_address, 'pending'
                        )
                    ");
                    $stmt->execute([
                        'user_id' => $user_id,
                        'barangay_id' => $barangay_id,
                        'trees_planted' => $trees_planted,
                        'photo_path' => $upload_path_relative,
                        'photo_hash' => $photo_hash,
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'location_accuracy' => $location_accuracy,
                        'ip_address' => $ip_address
                    ]);

                    // Update user's trees_planted and co2_offset
                    $new_trees_planted = $user['trees_planted'] + $trees_planted;
                    $co2_per_tree = 22; // Approx. 22 kg CO2 offset per tree per year
                    $new_co2_offset = $user['co2_offset'] + ($trees_planted * $co2_per_tree);

                    $stmt = $pdo->prepare("
                        UPDATE users 
                        SET trees_planted = :trees_planted, co2_offset = :co2_offset
                        WHERE user_id = :user_id
                    ");
                    $stmt->execute([
                        'trees_planted' => $new_trees_planted,
                        'co2_offset' => $new_co2_offset,
                        'user_id' => $user_id
                    ]);

                    // Log the activity
                    $stmt = $pdo->prepare("
                        INSERT INTO activities (user_id, description, activity_type)
                        VALUES (:user_id, :description, 'submission')
                    ");
                    $stmt->execute([
                        'user_id' => $user_id,
                        'description' => "Submitted a tree planting of $trees_planted trees."
                    ]);

                    $submission_success = 'Tree planting submitted successfully! It is now pending validation.';
                } else {
                    $submission_error = 'Failed to upload photo. Please try again.';
                }
            }
        }
    }

    // Handle profile update (same as dashboard.php)
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
    <title>Submit Tree Planting - Tree Planting Initiative</title>
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
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
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

        .submission-form {
            background: #fff;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 600px;
        }

        .submission-form h2 {
            font-size: 28px;
            color: #1e3a8a;
            margin-bottom: 25px;
            text-align: center;
        }

        .submission-form .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            display: none;
            font-size: 16px;
        }

        .submission-form .success {
            background: #d1fae5;
            color: #10b981;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            display: none;
            font-size: 16px;
        }

        .submission-form .error.show,
        .submission-form .success.show {
            display: block;
        }

        .submission-form .form-group {
            margin-bottom: 25px;
        }

        .submission-form label {
            display: block;
            font-size: 16px;
            color: #666;
            margin-bottom: 8px;
        }

        .submission-form input[type="number"],
        .submission-form input[type="file"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #e0e7ff;
            border-radius: 5px;
            font-size: 16px;
            outline: none;
        }

        .submission-form input:focus {
            border-color: #4f46e5;
        }

        .submission-form #photo-preview {
            margin-top: 10px;
            max-width: 100%;
            max-height: 200px;
            display: none;
            border-radius: 5px;
        }

        .submission-form input[type="submit"] {
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

        .submission-form input[type="submit"]:hover {
            background: #7c3aed;
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
            max-width: 500px;
            margin: 150px auto;
            position: relative;
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

        .error-message {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 16px;
            width: 100%;
            max-width: 600px;
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

            .submission-form {
                padding: 20px;
            }

            .submission-form h2 {
                font-size: 24px;
            }

            .submission-form label {
                font-size: 14px;
            }

            .submission-form input[type="number"],
            .submission-form input[type="file"] {
                padding: 10px;
                font-size: 14px;
            }

            .submission-form input[type="submit"] {
                padding: 10px;
                font-size: 14px;
            }

            .modal-content {
                padding: 20px;
                margin: 100px auto;
                max-width: 90%;
            }

            .modal-content h2 {
                font-size: 24px;
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
                <h1>Submit Tree Planting</h1>
                <div class="search-bar">
                    <input type="text" placeholder="Search functionalities..." id="searchInput">
                    <div class="search-results" id="searchResults"></div>
                </div>
                <div class="profile" id="profileBtn">
                    <span><?php echo htmlspecialchars($username); ?></span>
                    <img src="<?php echo $user['profile_picture'] ? htmlspecialchars($user['profile_picture']) : 'profile.jpg'; ?>" alt="Profile">
                </div>
            </div>
            <div class="submission-form">
                <h2>Submit Your Tree Planting</h2>
                <?php if ($submission_error): ?>
                    <div class="error show"><?php echo htmlspecialchars($submission_error); ?></div>
                <?php endif; ?>
                <?php if ($submission_success): ?>
                    <div class="success show"><?php echo htmlspecialchars($submission_success); ?></div>
                <?php endif; ?>
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="submit_planting" value="1">
                    <input type="hidden" name="latitude" id="latitude">
                    <input type="hidden" name="longitude" id="longitude">
                    <input type="hidden" name="location_accuracy" id="location_accuracy">
                    <div class="form-group">
                        <label for="trees_planted">Number of Trees Planted</label>
                        <input type="number" id="trees_planted" name="trees_planted" min="1" required>
                    </div>
                    <div class="form-group">
                        <label for="photo">Upload Photo (JPG, PNG, GIF)</label>
                        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/gif" required>
                        <img id="photo-preview" src="#" alt="Photo Preview">
                    </div>
                    <input type="submit" value="Submit Planting">
                </form>
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

        // Photo preview functionality
        const photoInput = document.querySelector('#photo');
        const photoPreview = document.querySelector('#photo-preview');

        photoInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    photoPreview.src = e.target.result;
                    photoPreview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            } else {
                photoPreview.style.display = 'none';
            }
        });

        // Capture GPS coordinates
        window.addEventListener('load', function() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        document.querySelector('#latitude').value = position.coords.latitude;
                        document.querySelector('#longitude').value = position.coords.longitude;
                        document.querySelector('#location_accuracy').value = position.coords.accuracy || '';
                    },
                    function(error) {
                        console.error('Geolocation error:', error);
                        alert('Unable to capture GPS coordinates. Please enable location services and try again.');
                    },
                    {
                        enableHighAccuracy: true,
                        timeout: 10000,
                        maximumAge: 0
                    }
                );
            } else {
                alert('Geolocation is not supported by your browser. Please use a modern browser to submit tree plantings.');
            }
        });
    </script>
</body>
</html>