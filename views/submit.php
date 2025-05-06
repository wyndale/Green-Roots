<?php
session_start();
require_once '../includes/config.php';

// Session timeout (30 minutes)
$timeout_duration = 1800;
if (!isset($_SESSION['last_activity'])) {
    $_SESSION['last_activity'] = time(); // Initialize if not set
} elseif ((time() - $_SESSION['last_activity']) > $timeout_duration) {
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

// Initialize messages
$submission_error = '';
$submission_success = '';

// Check for messages in session (from PRG redirect)
if (isset($_SESSION['submission_error'])) {
    $submission_error = $_SESSION['submission_error'];
    unset($_SESSION['submission_error']);
}
if (isset($_SESSION['submission_success'])) {
    $submission_success = $_SESSION['submission_success'];
    unset($_SESSION['submission_success']);
}

// Function to fetch planting site for a barangay
function getPlantingSite($pdo, $barangay_id)
{
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

    // Ensure only regular users can access this page
    if ($user['role'] !== 'user') {
        header('Location: ../access/access_denied.php?reason=role_mismatch');
        exit;
    }

    $barangay_id = $user['barangay_id'];

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

    // If no planting site, set error message without redirect
    if (!$planting_site) {
        $submission_error = 'No planting site designated for your barangay. Please contact an admin.';
    }

    // Generate CSRF token
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrf_token = $_SESSION['csrf_token'];

    // Handle tree planting submission (POST request)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_planting'])) {
        // Validate CSRF token
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $_SESSION['submission_error'] = 'Invalid CSRF token. Please try again.';
            header('Location: submit.php');
            exit;
        }

        $trees_planted = filter_input(INPUT_POST, 'trees_planted', FILTER_VALIDATE_INT);
        $submission_notes = filter_input(INPUT_POST, 'submission_notes', FILTER_SANITIZE_SPECIAL_CHARS);

        // Validate inputs
        if ($trees_planted === false || $trees_planted <= 0) {
            $_SESSION['submission_error'] = 'Please enter a valid number of trees planted (must be a positive integer).';
            header('Location: submit.php');
            exit;
        } elseif ($trees_planted > 100) {
            $_SESSION['submission_error'] = 'You cannot submit more than 100 trees at once. Please contact support for large submissions.';
            header('Location: submit.php');
            exit;
        } elseif (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['submission_error'] = 'Please upload a photo of the tree planting. Error code: ' . ($_FILES['photo']['error'] ?? 'N/A');
            error_log("File upload error for user $user_id: " . ($_FILES['photo']['error'] ?? 'No file uploaded'));
            header('Location: submit.php');
            exit;
        }

        // Validate photo
        $file_tmp = $_FILES['photo']['tmp_name'];
        $file_name = $_FILES['photo']['name'];
        $file_size = $_FILES['photo']['size'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif'];

        if (!in_array($file_ext, $allowed_exts)) {
            $_SESSION['submission_error'] = 'Only JPG, JPEG, PNG, GIF, WEBP, BMP, HEIC, and HEIF files are allowed for tree planting photos.';
            header('Location: submit.php');
            exit;
        } elseif ($file_size > 10 * 1024 * 1024) { // Reduced limit to 10MB
            $_SESSION['submission_error'] = 'Photo size must be less than 10MB.';
            header('Location: submit.php');
            exit;
        }

        // Check photo resolution
        $image_info = @getimagesize($file_tmp);
        if ($image_info === false) {
            $_SESSION['submission_error'] = 'Invalid image file. Please upload a valid image.';
            error_log("Invalid image file for user $user_id: Unable to get image size for $file_name");
            header('Location: submit.php');
            exit;
        }

        list($width, $height) = $image_info;
        if ($width < 800 || $height < 600) {
            $_SESSION['submission_error'] = 'Photo resolution must be at least 800x600 pixels for clear validation.';
            header('Location: submit.php');
            exit;
        }

        // Read photo data with stricter validation
        $photo_data = file_get_contents($file_tmp);
        if ($photo_data === false || empty($photo_data)) {
            $_SESSION['submission_error'] = 'Failed to read photo data. Please try a different image.';
            error_log("Failed to read photo data for user $user_id: $file_name | Size: $file_size bytes");
            header('Location: submit.php');
            exit;
        }

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
            $_SESSION['submission_error'] = 'This photo has already been submitted. Please upload a new photo.';
            header('Location: submit.php');
            exit;
        }

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
            if ($planting_site) {
                $distance_to_site = haversine_distance($latitude, $longitude, $planting_site['latitude'], $planting_site['longitude']);
                if ($distance_to_site > 0.2) { // 200 meters
                    $_SESSION['submission_error'] = 'The photo location is too far from the designated planting site (must be within 200 meters).';
                    // Log suspicious activity
                    $stmt = $pdo->prepare("
                        INSERT INTO activities (user_id, description, activity_type)
                        VALUES (:user_id, :description, 'suspicious')
                    ");
                    $stmt->execute([
                        'user_id' => $user_id,
                        'description' => "Photo location too far from planting site: $distance_to_site km."
                    ]);
                    header('Location: submit.php');
                    exit;
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
                $_SESSION['submission_error'] = 'Photo appears to be older than 8 hours. Please upload a recent photo.';
                header('Location: submit.php');
                exit;
            } elseif ($time_diff > 25200) { // 7 hours
                $proximity_note .= " Note: Photo is over 7 hours old. Flagged for review.";
                $is_flagged = 1;
            }
        } else {
            $proximity_note .= " Note: Photo lacks EXIF timestamp. Flagged for review.";
            $is_flagged = 1;
        }

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
                'submission_notes' => $submission_notes,
                'planting_site_id' => $planting_site['planting_site_id'],
                'flagged' => $is_flagged
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
            $_SESSION['submission_success'] = 'Tree planting submitted successfully! It is now pending validation.';
            header('Location: submit.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = $e->getMessage();
            $error_code = $e->getCode();
            $error_file = $e->getFile();
            $error_line = $e->getLine();
            $error_trace = $e->getTraceAsString();

            // Detailed logging for debugging
            $debug_log = sprintf(
                "Submission error for user %s: [%s] %s | File: %s (Line %d) | Trace: %s | File: %s | Size: %d bytes | Trees Planted: %d | Notes: %s",
                $user_id,
                $error_code,
                $error_message,
                $error_file,
                $error_line,
                $error_trace,
                $file_name,
                $file_size,
                $trees_planted,
                $submission_notes
            );
            error_log($debug_log);

            // Store detailed error in session for display after redirect
            $_SESSION['debug_error'] = sprintf(
                "Error: [%s] %s\nFile: %s (Line %d)\nTrace: %s",
                $error_code,
                $error_message,
                $error_file,
                $error_line,
                $error_trace
            );

            // Set user-friendly error message
            if (stripos($error_message, 'max_allowed_packet') !== false) {
                $_SESSION['submission_error'] = 'Photo size is too large for the server to handle. Please upload a smaller image (under 10MB).';
            } elseif (stripos($error_message, 'photo_data') !== false) {
                $_SESSION['submission_error'] = 'Invalid photo data. Please try a different image.';
            } elseif (stripos($error_message, 'photo_hash') !== false) {
                $_SESSION['submission_error'] = 'This photo has already been submitted. Please upload a new photo.';
            } else {
                $_SESSION['submission_error'] = 'Failed to submit tree planting. Please try again or save locally if you are offline.';
            }

            header('Location: submit.php');
            exit;
        }
    }

} catch (PDOException $e) {
    $error_message = "Error: " . $e->getMessage();
    error_log("Database error in submit.php: " . $e->getMessage());
}

// Check for debug error message to display
$debug_error = isset($_SESSION['debug_error']) ? $_SESSION['debug_error'] : '';
if ($debug_error) {
    echo "<script>alert('" . addslashes($debug_error) . "');</script>";
    unset($_SESSION['debug_error']);
}

// Helper functions
function exif_to_decimal($value, $ref)
{
    $dms = array_map('floatval', explode(',', str_replace(' ', '', $value)));
    $deg = $dms[0] + ($dms[1] / 60) + ($dms[2] / 3600);
    return ($ref == 'S' || $ref == 'W') ? -$deg : $deg;
}

function haversine_distance($lat1, $lon1, $lat2, $lon2)
{
    $earth_radius = 6371; // km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
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
    <link rel="stylesheet" href="../assets/css/submit.css">
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
                            <input type="file" id="photo" name="photo"
                                accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,image/heic,image/heif"
                                required>
                            <div class="upload-progress"></div>
                        </div>
                        <img id="photo-preview" src="#" alt="Photo Preview">
                    </div>
                    <div class="form-group">
                        <label for="submission_notes">Additional Notes</label>
                        <textarea id="submission_notes" name="submission_notes" rows="3" required
                            placeholder="e.g., Planted 5 mango trees near the river"></textarea>
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

        searchInput.addEventListener('input', function (e) {
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

        document.addEventListener('click', function (e) {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.classList.remove('active');
            }
        });

        // Profile dropdown functionality
        const profileBtn = document.querySelector('#profileBtn');
        const profileDropdown = document.querySelector('#profileDropdown');

        profileBtn.addEventListener('click', function () {
            profileDropdown.classList.toggle('active');
        });

        document.addEventListener('click', function (e) {
            if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                profileDropdown.classList.remove('active');
            }
        });

        // Guidelines tooltip and info icon flip functionality
        const infoIcon = document.querySelector('#infoIcon');
        const guidelinesTooltip = document.querySelector('#guidelinesTooltip');

        infoIcon.addEventListener('click', function () {
            this.classList.toggle('flipped');
            guidelinesTooltip.classList.toggle('active');
        });

        document.addEventListener('click', function (e) {
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
            reader.onload = function (e) {
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

        window.addEventListener('online', function () {
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

        window.addEventListener('offline', function () {
            saveLocalBtn.style.display = 'block';
            submitBtn.style.display = 'none';
        });

        saveLocalBtn.addEventListener('click', function () {
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