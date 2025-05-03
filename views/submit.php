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

$submission_error = '';
$submission_success = '';

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

    // Fetch barangay name for logging
    $stmt = $pdo->prepare("SELECT name FROM barangays WHERE barangay_id = :barangay_id");
    $stmt->execute(['barangay_id' => $barangay_id]);
    $barangay = $stmt->fetch(PDO::FETCH_ASSOC);
    $barangay_name = $barangay ? $barangay['name'] : 'Unknown';

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

    // Fetch planting site for validation
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
                $submission_notes = filter_input(INPUT_POST, 'submission_notes', FILTER_SANITIZE_SPECIAL_CHARS);

                // Validate inputs
                if ($trees_planted === false || $trees_planted <= 0) {
                    $submission_error = 'Please enter a valid number of trees planted (must be a positive integer).';
                } elseif ($trees_planted > 100) {
                    $submission_error = 'You cannot submit more than 100 trees at once. Please contact support for large submissions.';
                } elseif (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                    $submission_error = 'Please upload a photo of the tree planting. Error code: ' . ($_FILES['photo']['error'] ?? 'N/A');
                    error_log("File upload error for user $user_id: " . ($_FILES['photo']['error'] ?? 'No file uploaded'));
                } else {
                    // Validate photo
                    $file_tmp = $_FILES['photo']['tmp_name'];
                    $file_name = $_FILES['photo']['name'];
                    $file_size = $_FILES['photo']['size'];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif'];

                    if (!in_array($file_ext, $allowed_exts)) {
                        $submission_error = 'Only JPG, JPEG, PNG, GIF, WEBP, BMP, HEIC, and HEIF files are allowed for tree planting photos.';
                    } elseif ($file_size > 10 * 1024 * 1024) { // Reduced limit to 10MB
                        $submission_error = 'Photo size must be less than 10MB.';
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
                                // Read photo data with stricter validation
                                $photo_data = file_get_contents($file_tmp);
                                if ($photo_data === false || empty($photo_data)) {
                                    $submission_error = 'Failed to read photo data. Please try a different image.';
                                    error_log("Failed to read photo data for user $user_id: $file_name | Size: $file_size bytes");
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
                                            AND latitude IS NOT NULL
                                            AND longitude IS NOT NULL
                                        ");
                                        $stmt->execute(['user_id' => $user_id]);
                                        $recent_locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                        $proximity_note = '';
                                        $exif = @exif_read_data($file_tmp);
                                        $latitude = null;
                                        $longitude = null;
                                        $is_flagged = 0;

                                        // Extract GPS from photo EXIF data
                                        if ($exif && isset($exif['GPSLatitude']) && isset($exif['GPSLongitude'])) {
                                            $latitude = exif_to_decimal($exif['GPSLatitude'], $exif['GPSLatitudeRef']);
                                            $longitude = exif_to_decimal($exif['GPSLongitude'], $exif['GPSLongitudeRef']);

                                            // Validate against planting site using EXIF GPS
                                            $distance_to_site = haversine_distance($latitude, $longitude, $planting_site['latitude'], $planting_site['longitude']);
                                            if ($distance_to_site > 0.2) { // 200 meters
                                                $submission_error = 'The photo location is too far from the designated planting site (must be within 200 meters).';
                                                // Log suspicious activity
                                                $stmt = $pdo->prepare("
                                                    INSERT INTO activities (user_id, description, activity_type)
                                                    VALUES (:user_id, :description, 'suspicious')
                                                ");
                                                $stmt->execute([
                                                    'user_id' => $user_id,
                                                    'description' => "Photo location too far from planting site: $distance_to_site km."
                                                ]);
                                            } else {
                                                // Check proximity to recent submissions
                                                foreach ($recent_locations as $loc) {
                                                    $distance = haversine_distance($latitude, $longitude, $loc['latitude'], $loc['longitude']);
                                                    if ($distance < 0.1) { // 100 meters
                                                        $proximity_note = "Note: This submission is within 100 meters of a previous submission within the last 24 hours.";
                                                        break;
                                                    }
                                                }
                                            }
                                        } else {
                                            // Flag submission for review if EXIF GPS data is missing
                                            $is_flagged = 1;
                                            $proximity_note = "Note: Photo lacks EXIF GPS data. Flagged for manual review.";
                                            error_log("Photo for user $user_id lacks EXIF GPS data: $file_name");
                                        }

                                        // Check photo timestamp via EXIF
                                        $photo_timestamp = null;
                                        if ($exif && isset($exif['DateTimeOriginal'])) {
                                            $photo_timestamp = date('Y-m-d H:i:s', strtotime($exif['DateTimeOriginal']));
                                            $time_diff = abs(strtotime('now') - strtotime($photo_timestamp));
                                            if ($time_diff > 28800) { // 8 hours
                                                $submission_error = 'Photo appears to be older than 8 hours. Please upload a recent photo.';
                                            } elseif ($time_diff > 25200) { // 7 hours
                                                $proximity_note .= " Note: Photo is over 7 hours old. Flagged for review.";
                                                $is_flagged = 1;
                                            }
                                        } else {
                                            $proximity_note .= " Note: Photo lacks EXIF timestamp. Flagged for review.";
                                            $is_flagged = 1;
                                        }

                                        if (!$submission_error) {
                                            // Get IP address and device info with fallbacks
                                            $ip_address = $_SERVER['REMOTE_ADDR'] ?: 'unknown';
                                            $device_info = $_SERVER['HTTP_USER_AGENT'] ?: 'unknown';

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
                                                        :photo_hash, :latitude, :longitude, NULL,
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
                                                    'ip_address' => $ip_address,
                                                    'device_info' => $device_info,
                                                    'submission_notes' => $submission_notes ?: null,
                                                    'planting_site_id' => $planting_site['planting_site_id'],
                                                    'flagged' => $is_flagged
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

                                                // Log the activity with detailed info
                                                $description = "Submitted $trees_planted trees in $barangay_name. $proximity_note";
                                                $stmt = $pdo->prepare("
                                                    INSERT INTO activities (
                                                        user_id, description, activity_type, trees_planted, location, status, created_at
                                                    ) VALUES (
                                                        :user_id, :description, 'submission', :trees_planted, :location, 'pending', NOW()
                                                    )
                                                ");
                                                $stmt->execute([
                                                    'user_id' => $user_id,
                                                    'description' => $description,
                                                    'trees_planted' => $trees_planted,
                                                    'location' => $barangay_name
                                                ]);

                                                // Commit transaction
                                                $pdo->commit();
                                                $submission_success = 'Tree planting submitted successfully! It is now pending validation.';
                                            } catch (Exception $e) {
                                                $pdo->rollBack();
                                                // More specific error message based on the exception
                                                $error_message = $e->getMessage();
                                                if (stripos($error_message, 'max_allowed_packet') !== false) {
                                                    $submission_error = 'Photo size is too large for the server to handle. Please upload a smaller image (under 10MB).';
                                                } elseif (stripos($error_message, 'photo_data') !== false) {
                                                    $submission_error = 'Invalid photo data. Please try a different image.';
                                                } else {
                                                    $submission_error = 'Failed to submit tree planting. Please try again or save locally if you are offline.';
                                                }
                                                error_log("Submission error for user $user_id: " . $error_message . " | File: $file_name | Size: $file_size bytes");
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
            transition: transform 0.3s, color 0.3s;
        }

        .sidebar a:hover {
            color: #4CAF50;
            animation: bounce 0.3s ease-out;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
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

        .header .notification-search {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .header .notification-search .notification {
            font-size: 24px;
            color: #666;
            cursor: pointer;
            transition: color 0.3s, transform 0.3s;
        }

        .header .notification-search .notification:hover {
            color: #4CAF50;
            transform: scale(1.1);
        }

        .header .notification-search .search-bar {
            display: flex;
            align-items: center;
            background: #fff;
            padding: 8px 15px;
            border-radius: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            position: relative;
            width: 300px;
        }

        .header .notification-search .search-bar i {
            margin-right: 10px;
            color: #666;
            font-size: 16px;
        }

        .header .notification-search .search-bar input {
            border: none;
            outline: none;
            padding: 5px;
            width: 90%;
            font-size: 16px;
        }

        .header .notification-search .search-bar .search-results {
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

        .header .notification-search .search-bar .search-results.active {
            display: block;
        }

        .header .notification-search .search-bar .search-results a {
            display: block;
            padding: 12px;
            color: #333;
            text-decoration: none;
            border-bottom: 1px solid #e0e7ff;
            font-size: 16px;
        }

        .header .notification-search .search-bar .search-results a:hover {
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
            background: linear-gradient(135deg, #E8F5E9, #C8E6C9);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            width: 100%;
            max-width: 600px;
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .submission-form .form-header {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 25px;
            position: relative;
        }

        .submission-form h2 {
            font-size: 28px;
            color: #2E7D32;
            text-align: center;
            margin-right: 10px;
        }

        .submission-form .info-icon {
            font-size: 20px;
            color: #666;
            cursor: pointer;
            transition: transform 0.3s;
        }

        .submission-form .info-icon.flipped {
            transform: rotateY(180deg);
        }

        .submission-form .guidelines-tooltip {
            position: absolute;
            top: -60px;
            left: 50%;
            transform: translateX(-50%);
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
            font-size: 17px;
            color: #2E7D32;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .submission-form input[type="number"],
        .submission-form textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #C8E6C9;
            border-radius: 10px;
            font-size: 16px;
            outline: none;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .submission-form input[type="number"]:focus,
        .submission-form textarea:focus {
            border-color: #2E7D32;
            box-shadow: 0 0 10px rgba(46, 125, 50, 0.3);
        }

        .submission-form input[type="number"]:hover,
        .submission-form textarea:hover {
            border-color: #81C784;
        }

        .submission-form .upload-area {
            width: 100%;
            height: 200px;
            border: 2px dashed #C8E6C9;
            border-radius: 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #fff;
            cursor: pointer;
            transition: border-color 0.3s, background 0.3s;
            position: relative;
            overflow: hidden;
        }

        .submission-form .upload-area:hover {
            border-color: #81C784;
            background: #F1F8E9;
        }

        .submission-form .upload-area.active {
            border-color: #2E7D32;
            animation: bounce 0.3s ease-out;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }

        .submission-form .upload-area .upload-icon {
            font-size: 40px;
            color: #4CAF50;
            margin-bottom: 10px;
        }

        .submission-form .upload-area .choose-btn {
            background: #4CAF50;
            color: #fff;
            padding: 8px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background 0.3s, transform 0.3s;
            margin-top: 10px;
            font-weight: bold;
        }

        .submission-form .upload-area .choose-btn:hover {
            background: #388E3C;
            transform: translateY(-2px);
        }

        .submission-form .upload-area .or-text {
            margin: 10px 0;
            color: #666;
            font-size: 16px;
        }

        .submission-form .upload-area .drag-text {
            color: #666;
            font-size: 16px;
            text-align: center;
        }

        .submission-form .upload-area input[type="file"] {
            display: none;
        }

        .submission-form #photo-preview {
            max-width: 100%;
            max-height: 180px;
            border-radius: 10px;
            margin-top: 10px;
            display: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .submission-form .upload-progress {
            width: 0;
            height: 5px;
            background: #4CAF50;
            position: absolute;
            bottom: 0;
            left: 0;
            transition: width 0.3s;
        }

        .submission-form input[type="submit"] {
            background: #4CAF50;
            color: #fff;
            border: none;
            cursor: pointer;
            transition: transform 0.3s, background 0.3s;
            padding: 12px;
            font-size: 16px;
            font-weight: bold;
            width: 100%;
            border-radius: 10px;
            margin-top: 10px;
        }

        .submission-form input[type="submit"]:hover {
            transform: translateY(-5px) scale(1.05);
            background: #388E3C;
        }

        .submission-form button#saveLocalBtn {
            background: #666;
            color: #fff;
            border: none;
            cursor: pointer;
            transition: transform 0.3s, box-shadow 0.3s;
            padding: 12px;
            font-size: 16px;
            width: 100%;
            border-radius: 10px;
            margin-top: 10px;
            display: none;
            animation: pulse 1.5s infinite;
        }

        .submission-form button#saveLocalBtn:hover {
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 5px 15px rgba(102, 102, 102, 0.4);
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(102, 102, 102, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(102, 102, 102, 0); }
            100% { box-shadow: 0 0 0 0 rgba(102, 102, 102, 0); }
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
                z-index: 100;
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
                flex-direction: row;
                align-items: center;
                gap: 15px;
                width: 100%;
            }

            .header h1 {
                font-size: 24px;
            }

            .header .notification-search {
                flex-direction: row;
                align-items: center;
                gap: 10px;
                width: auto;
                flex-grow: 1;
            }

            .header .notification-search .notification {
                font-size: 20px;
                flex-shrink: 0;
            }

            .header .notification-search .search-bar {
                width: 100%;
                max-width: 200px;
                padding: 5px 10px;
                flex-grow: 1;
            }

            .header .notification-search .search-bar i {
                font-size: 14px;
                margin-right: 5px;
            }

            .header .notification-search .search-bar input {
                font-size: 14px;
            }

            .header .notification-search .search-bar .search-results {
                top: 40px;
            }

            .header .profile {
                margin-top: 0;
                flex-shrink: 0;
            }

            .header .profile img {
                width: 40px;
                height: 40px;
            }

            .header .profile span {
                font-size: 16px;
                display: none; /* Hide the name to save space */
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
                top: -50px;
                width: 250px;
                font-size: 12px;
            }

            .submission-form label {
                font-size: 14px;
            }

            .submission-form input[type="number"],
            .submission-form textarea,
            .submission-form .upload-area {
                padding: 10px;
                font-size: 14px;
            }

            .submission-form input[type="submit"],
            .submission-form button#saveLocalBtn {
                padding: 10px;
                font-size: 14px;
            }

            .error-message {
                font-size: 14px;
            }
        }

        /* Additional media query for very small screens */
        @media (max-width: 480px) {
            .header h1 {
                font-size: 20px;
            }

            .header .notification-search {
                gap: 5px;
            }

            .header .notification-search .notification {
                font-size: 18px;
            }

            .header .notification-search .search-bar {
                max-width: 150px;
                padding: 4px 8px;
            }

            .header .notification-search .search-bar i {
                font-size: 12px;
                margin-right: 4px;
            }

            .header .notification-search .search-bar input {
                font-size: 12px;
            }

            .header .profile img {
                width: 35px;
                height: 35px;
            }

            .submission-form {
                padding: 15px;
            }

            .submission-form h2 {
                font-size: 20px;
            }

            .submission-form .info-icon {
                font-size: 16px;
            }

            .submission-form label {
                font-size: 12px;
            }

            .submission-form input[type="number"],
            .submission-form textarea,
            .submission-form .upload-area {
                padding: 8px;
                font-size: 12px;
            }

            .submission-form .upload-area .upload-icon {
                font-size: 30px;
            }

            .submission-form .upload-area .choose-btn {
                padding: 6px 15px;
                font-size: 12px;
            }

            .submission-form .upload-area .or-text,
            .submission-form .upload-area .drag-text {
                font-size: 12px;
            }

            .submission-form input[type="submit"],
            .submission-form button#saveLocalBtn {
                padding: 8px;
                font-size: 12px;
            }
        }
    </style>
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
                <h1>Submit Tree Planting</h1>
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
                            <li>Make sure you upload clear image (under 10MB).</li>
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
                    <div class="form-group">
                        <label for="trees_planted">Number of Trees Planted</label>
                        <input type="number" id="trees_planted" name="trees_planted" min="1" max="100" required>
                    </div>
                    <div class="form-group">
                        <label>Upload Photo</label>
                        <div class="upload-area" id="uploadArea">
                            <i class="fas fa-cloud-upload-alt upload-icon"></i>
                            <div class="choose-btn">Browse</div>
                            <div class="or-text">or</div>
                            <div class="drag-text">drag files here</div>
                            <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,image/heic,image/heif" required>
                            <div class="upload-progress"></div>
                        </div>
                        <img id="photo-preview" src="#" alt="Photo Preview">
                    </div>
                    <div class="form-group">
                        <label for="submission_notes">Additional Notes (Optional)</label>
                        <textarea id="submission_notes" name="submission_notes" rows="3" placeholder="e.g., Planted 5 mango trees near the river"></textarea>
                    </div>
                    <input type="submit" id="submitBtn" value="Submit Planting">
                    <button type="button" id="saveLocalBtn" style="display: none;">Save Locally (Offline)</button>
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

        // Guidelines tooltip and info icon flip functionality
        const infoIcon = document.querySelector('#infoIcon');
        const guidelinesTooltip = document.querySelector('#guidelinesTooltip');

        infoIcon.addEventListener('click', function() {
            this.classList.toggle('flipped');
            guidelinesTooltip.classList.toggle('active');
        });

        document.addEventListener('click', function(e) {
            if (!infoIcon.contains(e.target) && !guidelinesTooltip.contains(e.target)) {
                infoIcon.classList.remove('flipped');
                guidelinesTooltip.classList.remove('active');
            }
        });

        // Upload area functionality
        const uploadArea = document.querySelector('#uploadArea');
        const photoInput = document.querySelector('#photo');
        const photoPreview = document.querySelector('#photo-preview');
        const uploadProgress = document.querySelector('.upload-progress');

        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.style.borderColor = '#2E7D32';
            uploadArea.style.background = '#F1F8E9';
        });

        uploadArea.addEventListener('dragleave', (e) => {
            e.preventDefault();
            uploadArea.style.borderColor = '#C8E6C9';
            uploadArea.style.background = '#fff';
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            const file = e.dataTransfer.files[0];
            if (file && file.type.startsWith('image/')) {
                handleFileUpload(file);
            }
        });

        uploadArea.querySelector('.choose-btn').addEventListener('click', () => photoInput.click());

        photoInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file && file.type.startsWith('image/')) {
                handleFileUpload(file);
            }
        });

        function handleFileUpload(file) {
            uploadArea.classList.add('active');
            const reader = new FileReader();
            reader.onload = function(e) {
                photoPreview.src = e.target.result;
                photoPreview.style.display = 'block';
                uploadProgress.style.width = '100%';
                setTimeout(() => {
                    uploadProgress.style.width = '0';
                }, 300);
            };
            reader.readAsDataURL(file);
            setTimeout(() => uploadArea.classList.remove('active'), 300);
        }

        // Basic offline support
        const form = document.querySelector('#plantingForm');
        const submitBtn = document.querySelector('#submitBtn');
        const saveLocalBtn = document.querySelector('#saveLocalBtn');

        window.addEventListener('online', function() {
            saveLocalBtn.style.display = 'none';
            submitBtn.style.display = 'block';
            const savedSubmission = localStorage.getItem('pendingSubmission');
            if (savedSubmission) {
                if (confirm('You have a saved submission. Would you like to submit it now?')) {
                    const data = JSON.parse(savedSubmission);
                    document.querySelector('#trees_planted').value = data.trees_planted;
                    document.querySelector('#submission_notes').value = data.submission_notes;
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
                submission_notes: document.querySelector('#submission_notes').value
            };
            localStorage.setItem('pendingSubmission', JSON.stringify(formData));
            alert('Submission saved locally. Please submit when you are back online.');
        });

        if (!navigator.onLine) {
            saveLocalBtn.style.display = 'block';
            submitBtn.style.display = 'none';
        }
    </script>
</body>
</html>