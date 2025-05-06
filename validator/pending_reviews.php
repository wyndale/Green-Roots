<?php
// Start session and handle PHP logic first
session_start();
require_once '../includes/config.php';

// Restrict access to eco_validator role only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'eco_validator') {
    if (isset($_SESSION['user_id']) && ($_SESSION['role'] === 'user' || $_SESSION['role'] === 'admin')) {
        header('Location: ../access/access_denied.php');
        exit;
    }
    header('Location: ../views/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
try {
    // Fetch validator data
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.email, u.profile_picture, u.default_profile_asset_id, u.barangay_id, u.first_name,
               b.name as barangay_name, b.city as city_name, b.province as province_name, b.region as region_name
        FROM users u 
        LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
        WHERE u.user_id = :user_id
    ");
    $stmt->execute(['user_id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header('Location: ../views/login.php');
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
            $mime_type = 'image/jpeg';
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

    // Handle search with PRG pattern and persistence across pages
    $search_query = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
        $search_query = trim($_POST['search']);
        $_SESSION['validator_search'] = $search_query; // Persist search query across all validator pages
        $redirect_url = "pending_reviews.php?page=1&search=" . urlencode($search_query);
        header("Location: $redirect_url");
        exit;
    } elseif (isset($_GET['search'])) {
        $search_query = trim($_GET['search']);
        $_SESSION['validator_search'] = $search_query; // Update session with GET search
    } elseif (isset($_SESSION['validator_search'])) {
        $search_query = $_SESSION['validator_search']; // Use persisted search query
    }

    // Debug: Log search query
    error_log("Search Query: " . ($search_query ?: 'None'));

    // Search and pagination setup
    $items_per_page = 11;
    $current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($current_page - 1) * $items_per_page;

    // Debug: Log pagination details
    error_log("Pagination - Current Page: $current_page, Offset: $offset, Items per Page: $items_per_page");

    // Count total pending submissions
    $count_query = "
        SELECT COUNT(*) as total
        FROM submissions s
        LEFT JOIN users u ON s.user_id = u.user_id
        WHERE s.barangay_id = :barangay_id AND s.status = 'pending'
        AND (:search = '' OR u.username LIKE :search OR u.email LIKE :search)
    ";
    $count_stmt = $pdo->prepare($count_query);
    $count_params = [
        ':barangay_id' => $user['barangay_id'],
        ':search' => $search_query ? "%$search_query%" : ''
    ];
    $count_stmt->execute($count_params);
    $total_items = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = max(1, ceil($total_items / $items_per_page));

    // Debug: Log total items and pages
    error_log("Total Items: $total_items, Total Pages: $total_pages");

    // Fetch pending submissions
    $query = "
        SELECT s.submission_id, s.user_id, s.trees_planted, s.photo_data, s.latitude, s.longitude, s.submitted_at,
               s.status, s.submission_notes, s.flagged, u.username, u.email
        FROM submissions s
        LEFT JOIN users u ON s.user_id = u.user_id
        WHERE s.barangay_id = :barangay_id AND s.status = 'pending'
        AND (:search = '' OR u.username LIKE :search OR u.email LIKE :search)
        ORDER BY s.submitted_at DESC LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($query);
    $params = [
        ':barangay_id' => $user['barangay_id'],
        ':search' => $search_query ? "%$search_query%" : '',
        ':limit' => $items_per_page,
        ':offset' => $offset
    ];
    $stmt->bindValue(':barangay_id', $user['barangay_id'], PDO::PARAM_INT);
    $stmt->bindValue(':search', $search_query ? "%$search_query%" : '', PDO::PARAM_STR);
    $stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $pending_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Debug: Log the retrieved submissions
    $submission_ids = array_column($pending_submissions, 'submission_id');
    error_log("Retrieved submission IDs: " . json_encode($submission_ids));

    // Debug: Log user data for retrieved submissions
    foreach ($pending_submissions as $submission) {
        error_log("Submission ID: {$submission['submission_id']}, User ID: {$submission['user_id']}, Username: " . ($submission['username'] ?? 'NULL') . ", Email: " . ($submission['email'] ?? 'NULL'));
    }

    // Calculate eco points for each submission based on effort (trees planted)
    $base_points_per_tree = 50; // Base eco points per tree for planting effort
    foreach ($pending_submissions as &$submission) {
        $total_base_points = $submission['trees_planted'] * $base_points_per_tree;
        $buffer_multiplier = 1.2; // 20% buffer for fairness
        $reward_multiplier = 1.1; // 10% additional reward
        $buffered_points = $total_base_points * $buffer_multiplier;
        $eco_points = $buffered_points * $reward_multiplier;
        $submission['eco_points'] = round($eco_points);
    }

} catch (PDOException $e) {
    $error_message = "Error: " . $e->getMessage();
    error_log("PDOException: " . $e->getMessage());
} catch (Exception $e) {
    $error_message = "Unexpected error: " . $e->getMessage();
    error_log("Exception: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Reviews</title>
    <link rel="icon" type="image/png" sizes="64x64" href="<?php echo htmlspecialchars($icon_base64); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Arial", sans-serif;
        }

        body {
            background: #f5f7fa;
            color: #333;
            line-height: 1.6;
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
            color: #4caf50;
            animation: bounce 0.3s ease-out;
        }

        @keyframes bounce {
            0%,
            100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-5px);
            }
        }

        .main-content {
            flex: 1;
            padding: 40px;
            margin-left: 80px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .header h1 {
            font-size: 36px;
            color: #4caf50;
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
            color: #4caf50;
            transform: scale(1.1);
        }

        .header .notification-search .search-bar {
            display: flex;
            align-items: center;
            background: #fff;
            padding: 8px 15px;
            border-radius: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            width: 300px;
            position: relative;
        }

        .header .notification-search .search-bar .search-icon {
            font-size: 16px;
            color: #666;
            margin-right: 5px;
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

        .custom-search-filter {
            margin-bottom: 30px;
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
            max-width: 300px;
        }

        .custom-search-filter form {
            position: relative;
            width: 100%;
        }

        .custom-search-filter input {
            padding: 12px 40px 12px 16px;
            width: 100%;
            border: 2px solid #4caf50;
            border-radius: 25px;
            font-size: 16px;
            transition: border-color 0.3s, box-shadow 0.3s;
            outline: none;
        }

        .custom-search-filter input:focus {
            border-color: #388e3c;
            box-shadow: 0 0 5px rgba(76, 175, 80, 0.5);
        }

        .custom-search-filter button {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            transition: color 0.3s;
        }

        .custom-search-filter button i {
            font-size: 18px;
            color: #4caf50;
        }

        .custom-search-filter button:hover i {
            color: #388e3c;
        }

        .submission-table {
            width: 100%;
            height: auto;
            border-collapse: collapse;
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            overflow-x: auto;
        }

        .submission-table table {
            width: 100%;
            min-width: 1200px;
        }

        .submission-table th,
        .submission-table td {
            padding: 10px 15px;
            text-align: left;
            border-bottom: 1px solid #e0e7ff;
            vertical-align: middle;
            font-size: 12px;
            color: #333;
        }

        .submission-table th {
            background: #4caf50;
            color: #fff;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .submission-table tr {
            height: 50px;
        }

        .submission-table tr:hover {
            background: #f5f7fa;
        }

        .submission-table .flag-icon {
            color: #f44336;
            font-size: 18px;
            margin-right: 5px;
        }

        .submission-table .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 20px;
            cursor: pointer;
            font-size: 12px;
            background: #2196f3;
            color: #fff;
            transition: transform 0.3s, background 0.3s;
        }

        .submission-table .action-btn:hover {
            background: #1976d2;
            transform: scale(1.05);
        }

        .submission-table img {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 5px;
            cursor: pointer;
            transition: transform 0.3s;
        }

        .submission-table img:hover {
            transform: scale(1.1);
        }

        .submission-table a.location-link {
            color: #2196f3;
            text-decoration: none;
            font-weight: 500;
        }

        .submission-table a.location-link:hover {
            text-decoration: underline;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 10px;
            flex-wrap: wrap;
        }

        .pagination a {
            padding: 10px 15px;
            background: #4caf50;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            transition: background 0.3s;
        }

        .pagination a:hover {
            background: #388e3c;
        }

        .pagination a.disabled {
            background: #ccc;
            cursor: not-allowed;
            pointer-events: none;
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
            align-items: center;
            justify-content: center;
            overflow: auto;
        }

        .modal-content {
            background: #fff;
            padding: 25px;
            width: 90%;
            max-width: 500px;
            border-radius: 15px;
            text-align: center;
            position: relative;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }

        .modal-content .close {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 24px;
            cursor: pointer;
            color: #666;
            transition: color 0.3s;
        }

        .modal-content .close:hover {
            color: #f44336;
        }

        .modal-content h2 {
            font-size: 24px;
            color: #4caf50;
            margin-bottom: 20px;
        }

        .modal-content p {
            font-size: 16px;
            color: #333;
            margin-bottom: 20px;
        }

        .modal-content .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .modal-content .form-group label {
            display: block;
            font-size: 16px;
            color: #333;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .modal-content .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e7ff;
            border-radius: 10px;
            font-size: 16px;
            resize: vertical;
            transition: border-color 0.3s;
        }

        .modal-content .form-group textarea:focus {
            border-color: #4caf50;
            outline: none;
        }

        .modal-content .modal-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-size: 16px;
            margin: 5px;
            transition: background 0.3s, transform 0.3s;
        }

        .modal-content .approve-btn {
            background: #4caf50;
            color: #fff;
        }

        .modal-content .approve-btn:hover {
            background: #388e3c;
            transform: translateY(-2px);
        }

        .modal-content .reject-btn,
        .modal-content .cancel-btn {
            background: #f44336;
            color: #fff;
        }

        .modal-content .reject-btn:hover,
        .modal-content .cancel-btn:hover {
            background: #d32f2f;
            transform: translateY(-2px);
        }

        .modal-content .submit-btn {
            background: #4caf50;
            color: #fff;
        }

        .modal-content .submit-btn:hover {
            background: #388e3c;
            transform: translateY(-2px);
        }

        .modal-content .image-modal {
            max-width: 100%;
            max-height: 80vh;
            border-radius: 10px;
            object-fit: contain;
        }

        .error-message {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 15px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 16px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        /* Highlight the current page in the sidebar */
        .sidebar a.active {
            color: #4CAF50;
            background: #e0e7ff;
            border-radius: 10px;
            padding: 5px 10px;
        }

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
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .header h1 {
                font-size: 24px;
            }

            .header .notification-search {
                width: 100%;
            }

            .header .notification-search .search-bar {
                width: 100%;
                max-width: 200px;
            }

            .header .profile img {
                width: 40px;
                height: 40px;
            }

            .header .profile span {
                display: none;
            }

            .custom-search-filter {
                max-width: 100%;
            }

            .custom-search-filter input {
                width: 100%;
                font-size: 14px;
                padding: 10px 36px 10px 12px;
            }

            .custom-search-filter button {
                right: 6px;
            }

            .custom-search-filter button i {
                font-size: 16px;
            }

            .submission-table th,
            .submission-table td {
                padding: 8px 12px;
                font-size: 12px;
            }

            .submission-table img {
                width: 30px;
                height: 30px;
            }

            .submission-table .action-btn {
                padding: 4px 8px;
                font-size: 10px;
            }

            .modal-content {
                width: 90%;
                padding: 15px;
            }

            .modal-content h2 {
                font-size: 20px;
            }

            .modal-content p {
                font-size: 14px;
            }

            .modal-content .modal-btn {
                padding: 10px 20px;
                font-size: 14px;
            }
        }

        /* Accessibility and Focus States */
        button:focus,
        input:focus,
        textarea:focus {
            outline: 2px solid #4caf50;
            outline-offset: 2px;
        }

        a:focus {
            outline: 2px solid #2196f3;
            outline-offset: 2px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <img src="<?php echo htmlspecialchars($logo_base64); ?>" alt="Logo" class="logo">
            <a href="validator_dashboard.php?search=<?php echo urlencode($search_query); ?>" title="Dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) === 'validator_dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-home"></i></a>
            <a href="pending_reviews.php?search=<?php echo urlencode($search_query); ?>" title="Pending Reviews" class="<?php echo basename($_SERVER['PHP_SELF']) === 'pending_reviews.php' ? 'active' : ''; ?>"><i class="fas fa-tasks"></i></a>
            <a href="reviewed_submissions.php?search=<?php echo urlencode($search_query); ?>" title="Reviewed Submissions" class="<?php echo basename($_SERVER['PHP_SELF']) === 'reviewed_submissions.php' ? 'active' : ''; ?>"><i class="fas fa-check-circle"></i></a>
            <a href="barangay_designated_site.php?search=<?php echo urlencode($search_query); ?>" title="Barangay Map" class="<?php echo basename($_SERVER['PHP_SELF']) === 'barangay_designated_site.php' ? 'active' : ''; ?>"><i class="fas fa-map-marker-alt"></i></a>
        </div>
        <div class="main-content">
            <?php if (isset($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <div class="header">
                <h1>Pending Reviews</h1>
                <div class="notification-search">
                    <div class="search-bar">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" placeholder="Search" id="searchInput" value="<?php echo htmlspecialchars($search_query); ?>">
                        <div class="search-results" id="searchResults"></div>
                    </div>
                </div>
                <div class="profile" id="profileBtn">
                    <span><?php echo htmlspecialchars($user['first_name'] ?? 'User'); ?></span>
                    <img src="<?php echo htmlspecialchars($profile_picture_data); ?>" alt="Profile">
                    <div class="profile-dropdown" id="profileDropdown">
                        <div class="email"><?php echo htmlspecialchars($user['email']); ?></div>
                        <a href="account_settings.php?search=<?php echo urlencode($search_query); ?>">Account</a>
                        <a href="../views/logout.php">Logout</a>
                    </div>
                </div>
            </div>
            <div class="custom-search-filter">
                <form method="POST" action="">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search by username or email">
                    <button type="submit"><i class="fas fa-search"></i></button>
                </form>
            </div>
            <div class="submission-table">
                <table>
                    <thead>
                        <tr>
                            <th>Submission ID</th>
                            <th>Submitter</th>
                            <th>Submitted At</th>
                            <th>Location</th>
                            <th>Photo</th>
                            <th>Trees Planted</th>
                            <th>Eco Points</th>
                            <th>Notes</th>
                            <th>Status</th>
                            <th>Flag</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pending_submissions)): ?>
                            <tr>
                                <td colspan="11" style="text-align: center;">No pending submissions available.</td>
                            </tr>
                        <?php else: ?>
                            <?php
                            // Ensure no duplicates by tracking submission IDs
                            $displayed_submission_ids = [];
                            foreach ($pending_submissions as $submission):
                                if (in_array($submission['submission_id'], $displayed_submission_ids)) {
                                    continue; // Skip duplicates
                                }
                                $displayed_submission_ids[] = $submission['submission_id'];
                            ?>
                                <tr data-submission-id="<?php echo htmlspecialchars($submission['submission_id']); ?>">
                                    <td><?php echo htmlspecialchars($submission['submission_id']); ?></td>
                                    <td><?php echo htmlspecialchars($submission['username'] ?? 'Unknown User'); ?></td>
                                    <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($submission['submitted_at']))); ?></td>
                                    <td>
                                        <a href="https://www.openstreetmap.org/?mlat=<?php echo htmlspecialchars($submission['latitude'] ?? '0'); ?>&mlon=<?php echo htmlspecialchars($submission['longitude'] ?? '0'); ?>&zoom=15" target="_blank" class="location-link">
                                            <?php echo htmlspecialchars($submission['latitude'] ?? 'N/A'); ?>, <?php echo htmlspecialchars($submission['longitude'] ?? 'N/A'); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php if ($submission['photo_data']): ?>
                                            <img src="data:image/jpeg;base64,<?php echo base64_encode($submission['photo_data']); ?>" alt="Submission Photo" onclick="openImageModal(this.src)">
                                        <?php else: ?>
                                            No Photo
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($submission['trees_planted']); ?></td>
                                    <td><?php echo htmlspecialchars($submission['eco_points']); ?></td>
                                    <td><?php echo htmlspecialchars($submission['submission_notes'] ?? 'N/A'); ?></td>
                                    <td class="status"><?php echo htmlspecialchars(ucfirst($submission['status'])); ?></td>
                                    <td><?php if ($submission['flagged']): ?><i class="fas fa-flag flag-icon"></i><?php endif; ?></td>
                                    <td>
                                        <button class="action-btn" onclick="openActionModal(<?php echo htmlspecialchars($submission['submission_id']); ?>, <?php echo htmlspecialchars($submission['user_id'] ?? 'null'); ?>, <?php echo htmlspecialchars($submission['eco_points']); ?>, <?php echo htmlspecialchars($submission['trees_planted']); ?>)">Actions</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination">
                <a href="?page=<?php echo max(1, $current_page - 1); ?>&search=<?php echo urlencode($search_query); ?>" class="<?php echo ($current_page == 1 || $total_items == 0) ? 'disabled' : ''; ?>">Previous</a>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>" class="<?php echo $i == $current_page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <a href="?page=<?php echo min($total_pages, $current_page + 1); ?>&search=<?php echo urlencode($search_query); ?>" class="<?php echo ($current_page == $total_pages || $total_items == 0) ? 'disabled' : ''; ?>">Next</a>
            </div>
        </div>
    </div>

    <!-- Action Modal -->
    <div class="modal" id="actionModal">
        <div class="modal-content">
            <span class="close" onclick="closeActionModal()">×</span>
            <h2>Submission Action</h2>
            <p id="actionModalText"></p>
            <input type="hidden" id="actionSubmissionId">
            <input type="hidden" id="actionUserId">
            <input type="hidden" id="actionEcoPoints">
            <input type="hidden" id="actionTreesPlanted">
            <button class="modal-btn approve-btn" onclick="confirmAction('approve')">Approve</button>
            <button class="modal-btn reject-btn" onclick="openRejectModal()">Reject</button>
        </div>
    </div>

    <!-- Reject Modal -->
    <div class="modal" id="rejectModal">
        <div class="modal-content">
            <span class="close" onclick="closeRejectModal()">×</span>
            <h2>Reject Submission</h2>
            <form id="rejectForm">
                <input type="hidden" id="rejectSubmissionId">
                <div class="form-group">
                    <label for="rejectReason">Reason for Rejection</label>
                    <textarea id="rejectReason" rows="4" placeholder="Enter reason..." required></textarea>
                </div>
                <button type="button" class="modal-btn cancel-btn" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" class="modal-btn submit-btn">Submit</button>
            </form>
        </div>
    </div>

    <!-- Image Modal -->
    <div class="modal" id="imageModal">
        <div class="modal-content">
            <span class="close" onclick="closeImageModal()">×</span>
            <img id="modalImage" class="image-modal" src="" alt="Enlarged Photo">
        </div>
    </div>

    <script>
        // Search bar functionality
        const functionalities = [
            { name: 'Dashboard', url: 'validator_dashboard.php?search=<?php echo urlencode($search_query); ?>' },
            { name: 'Pending Reviews', url: 'pending_reviews.php?search=<?php echo urlencode($search_query); ?>' },
            { name: 'Reviewed Submissions', url: 'reviewed_submissions.php?search=<?php echo urlencode($search_query); ?>' },
            { name: 'Barangay Map', url: 'barangay_designated_site.php?search=<?php echo urlencode($search_query); ?>' },
            { name: 'Logout', url: '../views/logout.php' }
        ];

        const searchInput = document.querySelector('#searchInput');
        const searchResults = document.querySelector('#searchResults');
        const searchFormInput = document.querySelector('input[name="search"]');

        // Sync search inputs
        searchInput.value = searchFormInput.value = '<?php echo htmlspecialchars($search_query); ?>';

        searchInput.addEventListener('input', function(e) {
            const query = e.target.value.toLowerCase();
            searchResults.innerHTML = '';
            searchResults.classList.remove('active');
            searchFormInput.value = query; // Sync with form input

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

        searchFormInput.addEventListener('input', function(e) {
            const query = e.target.value.toLowerCase();
            searchInput.value = query; // Sync with header input
            if (query.length === 0) {
                $_SESSION['validator_search'] = ''; // Clear session if input is empty
                window.location.href = 'pending_reviews.php?page=1';
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

        // Action Modal functionality
        function openActionModal(submissionId, userId, ecoPoints, treesPlanted) {
            document.querySelector('#actionSubmissionId').value = submissionId;
            document.querySelector('#actionUserId').value = userId;
            document.querySelector('#actionEcoPoints').value = ecoPoints;
            document.querySelector('#actionTreesPlanted').value = treesPlanted;
            document.querySelector('#actionModalText').textContent = `Submission ID: ${submissionId} | Trees Planted: ${treesPlanted} | Eco Points: ${ecoPoints}`;
            document.querySelector('#actionModal').style.display = 'flex';
        }

        function closeActionModal() {
            document.querySelector('#actionModal').style.display = 'none';
            document.querySelector('#actionModalText').textContent = '';
            document.querySelector('#actionSubmissionId').value = '';
            document.querySelector('#actionUserId').value = '';
            document.querySelector('#actionEcoPoints').value = '';
            document.querySelector('#actionTreesPlanted').value = '';
        }

        function confirmAction(action) {
            const submissionId = document.querySelector('#actionSubmissionId').value;
            const userId = document.querySelector('#actionUserId').value;
            const ecoPoints = document.querySelector('#actionEcoPoints').value;
            const treesPlanted = document.querySelector('#actionTreesPlanted').value;
            document.querySelector('#actionModal').style.display = 'none';
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.innerHTML = `
                <div class="modal-content">
                    <span class="close" onclick="this.parentElement.parentElement.remove()">×</span>
                    <h2>Confirmation</h2>
                    <p>Are you sure you want to ${action} this submission?</p>
                    <button class="modal-btn approve-btn" onclick="proceedAction('${action}', ${submissionId}, ${userId}, ${ecoPoints}, ${treesPlanted});this.parentElement.parentElement.remove()">Yes</button>
                    <button class="modal-btn cancel-btn" onclick="this.parentElement.parentElement.remove()">No</button>
                </div>
            `;
            modal.style.display = 'flex';
            document.body.appendChild(modal);
        }

        function proceedAction(action, submissionId, userId, ecoPoints, treesPlanted) {
            const payload = {
                submission_id: submissionId,
                user_id: userId,
                eco_points: parseInt(ecoPoints),
                trees_planted: parseInt(treesPlanted),
                status: action === 'approve' ? 'approved' : 'rejected',
                validated_by: <?php echo json_encode($user_id); ?>,
                validated_at: new Date().toISOString()
            };

            if (action === 'reject') {
                const rejectReason = document.querySelector('#rejectReason')?.value;
                if (!rejectReason) {
                    const modal = document.createElement('div');
                    modal.className = 'modal';
                    modal.innerHTML = `
                        <div class="modal-content">
                            <span class="close" onclick="this.parentElement.parentElement.remove()">×</span>
                            <h2>Error</h2>
                            <p>Rejection reason is required.</p>
                            <button class="modal-btn cancel-btn" onclick="this.parentElement.parentElement.remove()">OK</button>
                        </div>
                    `;
                    modal.style.display = 'flex';
                    document.body.appendChild(modal);
                    return;
                }
                payload.rejection_reason = rejectReason;
            }

            fetch('../services/update_submission.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                const modal = document.createElement('div');
                modal.className = 'modal';
                if (data.success) {
                    // Update UI immediately
                    const row = document.querySelector(`tr[data-submission-id="${submissionId}"]`);
                    if (row) {
                        row.querySelector('.status').textContent = action === 'approve' ? 'Approved' : 'Rejected';
                        row.querySelector('.action-btn').style.display = 'none'; // Disable further actions
                    }
                    modal.innerHTML = `
                        <div class="modal-content">
                            <span class="close" onclick="this.parentElement.parentElement.remove()">×</span>
                            <h2>Success</h2>
                            <p>Submission ${action === 'approve' ? 'approved' : 'rejected'} successfully! ${action === 'approve' ? 'Awarded ' + ecoPoints + ' eco points.' : ''}</p>
                            <button class="modal-btn approve-btn" onclick="this.parentElement.parentElement.remove()">OK</button>
                        </div>
                    `;
                } else {
                    modal.innerHTML = `
                        <div class="modal-content">
                            <span class="close" onclick="this.parentElement.parentElement.remove()">×</span>
                            <h2>Error</h2>
                            <p>Error: ${data.error || 'Unknown error occurred'}</p>
                            <button class="modal-btn cancel-btn" onclick="this.parentElement.parentElement.remove()">OK</button>
                        </div>
                    `;
                }
                modal.style.display = 'flex';
                document.body.appendChild(modal);
            })
            .catch(error => {
                const modal = document.createElement('div');
                modal.className = 'modal';
                modal.innerHTML = `
                    <div class="modal-content">
                        <span class="close" onclick="this.parentElement.parentElement.remove()">×</span>
                        <h2>Error</h2>
                        <p>Error: ${error.message}</p>
                        <button class="modal-btn cancel-btn" onclick="this.parentElement.parentElement.remove()">OK</button>
                    </div>
                `;
                modal.style.display = 'flex';
                document.body.appendChild(modal);
            });
        }

        function openRejectModal() {
            const submissionId = document.querySelector('#actionSubmissionId').value;
            document.querySelector('#rejectSubmissionId').value = submissionId;
            document.querySelector('#actionModal').style.display = 'none';
            document.querySelector('#rejectModal').style.display = 'flex';
        }

        function closeRejectModal() {
            document.querySelector('#rejectModal').style.display = 'none';
            document.querySelector('#rejectForm').reset();
            document.querySelector('#rejectSubmissionId').value = '';
        }

        document.querySelector('#rejectForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const submissionId = document.querySelector('#rejectSubmissionId').value;
            const userId = document.querySelector('#actionUserId').value;
            const ecoPoints = document.querySelector('#actionEcoPoints').value;
            const treesPlanted = document.querySelector('#actionTreesPlanted').value;
            document.querySelector('#rejectModal').style.display = 'none';
            confirmAction('reject');
        });

        // Image Modal functionality
        function openImageModal(src) {
            const modalImage = document.querySelector('#modalImage');
            modalImage.src = src;
            document.querySelector('#imageModal').style.display = 'flex';
        }

        function closeImageModal() {
            const modalImage = document.querySelector('#modalImage');
            document.querySelector('#imageModal').style.display = 'none';
            modalImage.src = '';
        }
    </script>
</body>
</html>