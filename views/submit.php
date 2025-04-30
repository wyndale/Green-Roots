<?php
session_start();
require_once '../includes/config.php';

// Session timeout (30 minutes)
$timeout_duration = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['last_activity'] = time();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Function to fetch planting site for a barangay
function getPlantingSite($pdo, $barangay_id) {
    $stmt = $pdo->prepare("
        SELECT planting_site_id, latitude, longitude 
        FROM planting_sites 
        WHERE barangay_id = :barangay_id 
        ORDER BY updated_at DESC 
        LIMIT 1
    ");
    $stmt->execute(['barangay_id' => $barangay_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
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

    $barangay_id = $user['barangay_id'];

    // Ensure only regular users can access this page
    if ($user['role'] !== 'user') {
        header('Location: ' . ($user['role'] === 'admin' ? 'admin_dashboard.php' : 'validator_dashboard.php'));
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

    // Fetch planting site for validation (not display)
    $planting_site = getPlantingSite($pdo, $barangay_id);

    if (!$planting_site) {
        $submission_error = 'No planting site designated for your barangay. Please contact an admin.';
    } else {
        // Generate CSRF token
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $csrf_token = $_SESSION['csrf_token'];

        // Handle tree planting submission
        $submission_error = $submission_error ?? '';
        $submission_success = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_planting'])) {
            // Validate CSRF token
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                $submission_error = 'Invalid CSRF token. Please try again.';
            } else {
                $trees_planted = filter_input(INPUT_POST, 'trees_planted', FILTER_VALIDATE_INT);
                $latitude = filter_input(INPUT_POST, 'latitude', FILTER_VALIDATE_FLOAT);
                $longitude = filter_input(INPUT_POST, 'longitude', FILTER_VALIDATE_FLOAT);
                $location_accuracy = filter_input(INPUT_POST, 'location_accuracy', FILTER_VALIDATE_FLOAT);
                $submission_notes = filter_input(INPUT_POST, 'submission_notes', FILTER_SANITIZE_STRING);

                // Validate inputs
                if ($trees_planted === false || $trees_planted <= 0) {
                    $submission_error = 'Please enter a valid number of trees planted (must be a positive integer).';
                } elseif ($trees_planted > 100) {
                    $submission_error = 'You cannot submit more than 100 trees at once. Please contact support for large submissions.';
                } elseif (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                    $submission_error = 'Please upload a photo of the tree planting. Error code: ' . ($_FILES['photo']['error'] ?? 'N/A');
                    error_log("File upload error for user $user_id: " . ($_FILES['photo']['error'] ?? 'No file uploaded'));
                } elseif ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                    $submission_error = 'Unable to capture valid GPS coordinates. Please enable location services and try again.';
                } elseif ($location_accuracy === false || $location_accuracy > 50) {
                    $submission_error = 'Location accuracy is too low (must be within 50 meters). Please try again in an area with better GPS signal.';
                } else {
                    // Validate photo
                    $file_tmp = $_FILES['photo']['tmp_name'];
                    $file_name = $_FILES['photo']['name'];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif'];

                    if (!in_array($file_ext, $allowed_exts)) {
                        $submission_error = 'Only JPG, JPEG, PNG, GIF, WEBP, BMP, HEIC, and HEIF files are allowed for tree planting photos.';
                    } elseif ($_FILES['photo']['size'] > 50 * 1024 * 1024) {
                        $submission_error = 'Photo size must be less than 50MB.';
                    } else {
                        // Check photo resolution
                        $image_info = @getimagesize($file_tmp);
                        if ($image_info === false) {
                            $submission_error = 'Invalid image file. Please upload a valid image.';
                            error_log("Invalid image file for user $user_id: Unable to get image size for $file_name");
                        } else {
                            list($width, $height) = $image_info;
                            if ($width < 800 || $height < 600) {
                                $submission_error = 'Photo resolution must be at least 800x600 pixels for clear validation.';
                            } else {
                                // Read photo data
                                $photo_data = file_get_contents($file_tmp);
                                if ($photo_data === false) {
                                    $submission_error = 'Failed to read photo data. Please try again.';
                                    error_log("Failed to read photo data for user $user_id: $file_name");
                                } else {
                                    // Generate photo hash
                                    $photo_hash = hash('sha256', $photo_data);

                                    // Check for duplicate submissions
                                    $stmt = $pdo->prepare("
                                        SELECT COUNT(*) 
                                        FROM submissions 
                                        WHERE user_id = :user_id 
                                        AND photo_hash = :photo_hash
                                    ");
                                    $stmt->execute([
                                        'user_id' => $user_id,
                                        'photo_hash' => $photo_hash
                                    ]);
                                    if ($stmt->fetchColumn() > 0) {
                                        $submission_error = 'This photo has already been submitted. Please upload a new photo.';
                                    } else {
                                        // Check proximity to recent submissions (for logging, not flagging)
                                        $stmt = $pdo->prepare("
                                            SELECT latitude, longitude 
                                            FROM submissions 
                                            WHERE user_id = :user_id 
                                            AND submitted_at >= NOW() - INTERVAL 24 HOUR
                                        ");
                                        $stmt->execute(['user_id' => $user_id]);
                                        $recent_locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                        $proximity_note = '';
                                        foreach ($recent_locations as $loc) {
                                            $distance = haversine_distance($latitude, $longitude, $loc['latitude'], $loc['longitude']);
                                            if ($distance < 0.1) { // 100 meters
                                                $proximity_note = "Note: This submission is within 100 meters of a previous submission within the last 24 hours.";
                                                break;
                                            }
                                        }

                                        // Validate against planting site
                                        $distance_to_site = haversine_distance($latitude, $longitude, $planting_site['latitude'], $planting_site['longitude']);
                                        if ($distance_to_site > 0.2) { // 200 meters
                                            $submission_error = 'Your location is too far from the designated planting site (must be within 200 meters).';
                                            // Log suspicious activity
                                            $stmt = $pdo->prepare("
                                                INSERT INTO activities (user_id, description, activity_type)
                                                VALUES (:user_id, :description, 'suspicious')
                                            ");
                                            $stmt->execute([
                                                'user_id' => $user_id,
                                                'description' => "Submission location too far from planting site: $distance_to_site km."
                                            ]);
                                        }

                                        // Check photo timestamp via EXIF
                                        $exif = @exif_read_data($file_tmp);
                                        $photo_timestamp = null;
                                        if ($exif && isset($exif['DateTimeOriginal'])) {
                                            $photo_timestamp = date('Y-m-d H:i:s', strtotime($exif['DateTimeOriginal']));
                                            $time_diff = abs(strtotime('now') - strtotime($photo_timestamp));
                                            if ($time_diff > 3600) {
                                                $submission_error = 'Photo appears to be older than 1 hour. Please upload a recent photo.';
                                            }
                                        }

                                        // Validate geolocation against EXIF data
                                        if (!$submission_error && $exif && isset($exif['GPSLatitude']) && isset($exif['GPSLongitude'])) {
                                            $exif_lat = exif_to_decimal($exif['GPSLatitude'], $exif['GPSLatitudeRef']);
                                            $exif_lon = exif_to_decimal($exif['GPSLongitude'], $exif['GPSLongitudeRef']);
                                            $distance = haversine_distance($latitude, $longitude, $exif_lat, $exif_lon);
                                            if ($distance > 0.1) {
                                                $submission_error = 'Photo GPS coordinates do not match your current location (difference > 100 meters).';
                                                // Log suspicious activity
                                                $stmt = $pdo->prepare("
                                                    INSERT INTO activities (user_id, description, activity_type)
                                                    VALUES (:user_id, :description, 'suspicious')
                                                ");
                                                $stmt->execute([
                                                    'user_id' => $user_id,
                                                    'description' => "Geolocation mismatch detected in submission: $distance km difference"
                                                ]);
                                            }
                                        }

                                        if (!$submission_error) {
                                            // Get IP address and device info
                                            $ip_address = $_SERVER['REMOTE_ADDR'];
                                            $device_info = $_SERVER['HTTP_USER_AGENT'];

                                            // Begin transaction
                                            $pdo->beginTransaction();
                                            try {
                                                // Insert submission into database
                                                $stmt = $pdo->prepare("
                                                    INSERT INTO submissions (
                                                        user_id, barangay_id, trees_planted, photo_data, photo_timestamp,
                                                        photo_hash, latitude, longitude, location_accuracy,
                                                        device_location_timestamp, ip_address, device_info, submission_notes,
                                                        planting_site_id, status, submitted_at, flagged
                                                    ) VALUES (
                                                        :user_id, :barangay_id, :trees_planted, :photo_data, NOW(),
                                                        :photo_hash, :latitude, :longitude, :location_accuracy,
                                                        NOW(), :ip_address, :device_info, :submission_notes,
                                                        :planting_site_id, 'pending', NOW(), :flagged
                                                    )
                                                ");
                                                $stmt->execute([
                                                    'user_id' => $user_id,
                                                    'barangay_id' => $barangay_id,
                                                    'trees_planted' => $trees_planted,
                                                    'photo_data' => $photo_data,
                                                    'photo_hash' => $photo_hash,
                                                    'latitude' => $latitude,
                                                    'longitude' => $longitude,
                                                    'location_accuracy' => $location_accuracy,
                                                    'ip_address' => $ip_address,
                                                    'device_info' => $device_info,
                                                    'submission_notes' => $submission_notes ?: null,
                                                    'planting_site_id' => $planting_site['planting_site_id'],
                                                    'flagged' => $proximity_note ? 1 : 0
                                                ]);

                                                // Update user's trees_planted and co2_offset (pending approval)
                                                $new_trees_planted = $user['trees_planted'] + $trees_planted;
                                                $co2_per_tree = 22;
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
                                                $description = "Submitted a tree planting of $trees_planted trees.";
                                                if ($proximity_note) {
                                                    $description .= " $proximity_note";
                                                }
                                                $stmt = $pdo->prepare("
                                                    INSERT INTO activities (user_id, description, activity_type)
                                                    VALUES (:user_id, :description, 'submission')
                                                ");
                                                $stmt->execute([
                                                    'user_id' => $user_id,
                                                    'description' => $description
                                                ]);

                                                // Commit transaction
                                                $pdo->commit();
                                                $submission_success = 'Tree planting submitted successfully! It is now pending validation.';
                                            } catch (Exception $e) {
                                                $pdo->rollBack();
                                                $submission_error = 'Failed to submit tree planting. Please try again or save locally if you are offline.';
                                                echo $e->getMessage();
                                                error_log("Submission error for user $user_id: " . $e->getMessage() . " | File: $file_name | Size: " . $_FILES['photo']['size']);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

} catch (PDOException $e) {
    $error_message = "Error: " . $e->getMessage();
    error_log("Database error in submit.php: " . $e->getMessage());
}

// Helper functions
function exif_to_decimal($value, $ref) {
    $dms = array_map('floatval', explode(',', str_replace(' ', '', $value)));
    $deg = $dms[0] + ($dms[1] / 60) + ($dms[2] / 3600);
    return ($ref == 'S' || $ref == 'W') ? -$deg : $deg;
}

function haversine_distance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371; // km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c; // Distance in km
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Tree Planting - Green Roots</title>
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
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-left: 80px;
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

        .submission-form .form-header {
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            margin-bottom: 25px;
        }

        .submission-form h2 {
            font-size: 28px;
            color: #4CAF50;
            text-align: center;
        }

        .submission-form .info-icon {
            position: absolute;
            right: 0;
            font-size: 20px;
            color: #666;
            cursor: pointer;
            transition: color 0.3s;
        }

        .submission-form .info-icon:hover {
            color: #4CAF50;
        }

        .submission-form .guidelines-tooltip {
            position: absolute;
            top: 40px;
            right: 0;
            background: #E8F5E9;
            padding: 15px;
            border-radius: 5px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            width: 300px;
            font-size: 14px;
            color: #666;
            display: none;
            z-index: 10;
        }

        .submission-form .guidelines-tooltip.active {
            display: block;
        }

        .submission-form .guidelines-tooltip ul {
            list-style-type: disc;
            padding-left: 20px;
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
        .submission-form input[type="file"],
        .submission-form textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #e0e7ff;
            border-radius: 5px;
            font-size: 16px;
            outline: none;
        }

        .submission-form input:focus,
        .submission-form textarea:focus {
            border-color: #4CAF50;
        }

        .submission-form #photo-preview {
            margin-top: 10px;
            max-width: 100%;
            max-height: 200px;
            display: none;
            border-radius: 5px;
        }

        .submission-form input[type="submit"] {
            background: #4CAF50;
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
            background: #388E3C;
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

            .submission-form {
                padding: 20px;
            }

            .submission-form h2 {
                font-size: 24px;
            }

            .submission-form .info-icon {
                font-size: 18px;
            }

            .submission-form .guidelines-tooltip {
                top: 35px;
                right: -10px;
                width: 250px;
                font-size: 12px;
            }

            .submission-form label {
                font-size: 14px;
            }

            .submission-form input[type="number"],
            .submission-form input[type="file"],
            .submission-form textarea {
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
                <div class="form-header">
                    <h2>Submit Your Tree Planting</h2>
                    <i class="fas fa-info-circle info-icon" id="infoIcon"></i>
                    <div class="guidelines-tooltip" id="guidelinesTooltip">
                        <p><strong>Submission Guidelines:</strong></p>
                        <ul>
                            <li>Ensure your photo clearly shows the planted trees.</li>
                            <li>Take the photo at the planting location with location services enabled.</li>
                            <li>Use a recent photo (taken within the last 8 hours).</li>
                            <li>Make sure you upload clear image.</li>
                        </ul>
                    </div>
                </div>
                <?php if ($submission_error): ?>
                    <div class="error show"><?php echo htmlspecialchars($submission_error); ?></div>
                <?php endif; ?>
                <?php if ($submission_success): ?>
                    <div class="success show"><?php echo htmlspecialchars($submission_success); ?></div>
                <?php endif; ?>
                <form id="plantingForm" method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="submit_planting" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="latitude" id="latitude">
                    <input type="hidden" name="longitude" id="longitude">
                    <input type="hidden" name="location_accuracy" id="location_accuracy">
                    <div class="form-group">
                        <label for="trees_planted">Number of Trees Planted</label>
                        <input type="number" id="trees_planted" name="trees_planted" min="1" max="100" required>
                    </div>
                    <div class="form-group">
                        <label for="photo">Upload Photo</label>
                        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,image/heic,image/heif" required>
                        <img id="photo-preview" src="#" alt="Photo Preview">
                    </div>
                    <div class="form-group">
                        <label for="submission_notes">Additional Notes (Optional)</label>
                        <textarea id="submission_notes" name="submission_notes" rows="3" placeholder="e.g., Planted 5 mango trees near the river"></textarea>
                    </div>
                    <input type="submit" id="submitBtn" value="Submit Planting">
                    <button type="button" id="saveLocalBtn" style="display: none; background: #666; color: #fff; padding: 12px; border-radius: 5px; width: 100%; margin-top: 10px;">Save Locally (Offline)</button>
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
            { name: 'Feedback', url: 'feedback.php' }
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

        // Guidelines tooltip functionality
        const infoIcon = document.querySelector('#infoIcon');
        const guidelinesTooltip = document.querySelector('#guidelinesTooltip');

        infoIcon.addEventListener('click', function() {
            guidelinesTooltip.classList.toggle('active');
        });

        document.addEventListener('click', function(e) {
            if (!infoIcon.contains(e.target) && !guidelinesTooltip.contains(e.target)) {
                guidelinesTooltip.classList.remove('active');
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

        // Basic offline support
        const form = document.querySelector('#plantingForm');
        const submitBtn = document.querySelector('#submitBtn');
        const saveLocalBtn = document.querySelector('#saveLocalBtn');

        window.addEventListener('online', function() {
            saveLocalBtn.style.display = 'none';
            submitBtn.style.display = 'block';
            // Check for locally saved submissions
            const savedSubmission = localStorage.getItem('pendingSubmission');
            if (savedSubmission) {
                if (confirm('You have a saved submission. Would you like to submit it now?')) {
                    const data = JSON.parse(savedSubmission);
                    // Populate form and submit
                    document.querySelector('#trees_planted').value = data.trees_planted;
                    document.querySelector('#submission_notes').value = data.submission_notes;
                    document.querySelector('#latitude').value = data.latitude;
                    document.querySelector('#longitude').value = data.longitude;
                    document.querySelector('#location_accuracy').value = data.location_accuracy;
                    // Note: Photo cannot be auto-submitted due to security restrictions
                    alert('Please re-upload the photo to submit.');
                }
            }
        });

        window.addEventListener('offline', function() {
            saveLocalBtn.style.display = 'block';
            submitBtn.style.display = 'none';
        });

        saveLocalBtn.addEventListener('click', function() {
            const formData = {
                trees_planted: document.querySelector('#trees_planted').value,
                submission_notes: document.querySelector('#submission_notes').value,
                latitude: document.querySelector('#latitude').value,
                longitude: document.querySelector('#longitude').value,
                location_accuracy: document.querySelector('#location_accuracy').value
            };
            localStorage.setItem('pendingSubmission', JSON.stringify(formData));
            alert('Submission saved locally. Please submit when you are back online.');
        });

        // Check initial connectivity state
        if (!navigator.onLine) {
            saveLocalBtn.style.display = 'block';
            submitBtn.style.display = 'none';
        }
    </script>
</body>
</html>