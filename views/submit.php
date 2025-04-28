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

    // Convert profile picture to base64 for display
    $profile_picture_data = $user['profile_picture'] ? 'data:image/jpeg;base64,' . base64_encode($user['profile_picture']) : 'profile.jpg';

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

        .submission-form {
            background: #fff;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
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

        .error-message {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 15px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 16px;
            width: 100%;
            max-width: 600px;
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
                <h1>Submit Tree Planting</h1>
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