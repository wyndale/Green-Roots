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

// Pagination settings
$entries_per_page = 10;
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'planting';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $entries_per_page;

// Date filters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Initialize arrays to store history data
$planting_history = [];
$reward_history = [];
$event_history = [];

// Total counts for pagination
$planting_total = 0;
$reward_total = 0;
$event_total = 0;

try {
    // Fetch user data including profile picture
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.email, u.profile_picture, u.default_profile_asset_id 
        FROM users u 
        WHERE u.user_id = :user_id
    ");
    $stmt->execute(['user_id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header('Location: login.php');
        exit;
    }

    // Fetch profile picture (custom or default)
    if ($user['profile_picture']) {
        $profile_picture_data = 'data:image/jpeg;base64,' . base64_encode($user['profile_picture']);
    } elseif ($user['default_profile_asset_id']) {
        $stmt = $pdo->prepare("SELECT asset_data, asset_type FROM assets WHERE asset_id = :asset_id");
        $stmt->execute(['asset_id' => $user['default_profile_asset_id']]);
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
            $profile_picture_data = 'default_profile.jpg';
        }
    } else {
        $profile_picture_data = 'default_profile.jpg';
    }

    // Fetch logo
    $stmt = $pdo->prepare("SELECT asset_data FROM assets WHERE asset_type = 'logo' LIMIT 1");
    $stmt->execute();
    $logo_data = $stmt->fetchColumn();
    $logo_base64 = $logo_data ? 'data:image/png;base64,' . base64_encode($logo_data) : 'logo.png';

    // Helper function to parse planting submission description
    function parsePlantingDescription($description) {
        // Example description: "Submitted 5 trees in Barangay 1"
        $pattern = '/Submitted (\d+) trees in (.*)/';
        if (preg_match($pattern, $description, $matches)) {
            return [
                'trees_planted' => (int)$matches[1],
                'barangay_name' => $matches[2],
                'status' => 'Approved' // Assuming activities are logged only after approval
            ];
        }
        return [
            'trees_planted' => 0,
            'barangay_name' => 'Unknown',
            'status' => 'Unknown'
        ];
    }

    // Helper function to parse reward description
    function parseRewardDescription($description) {
        // Example description: "Claimed a cash reward of ₱100 (100 eco points)"
        // or "Claimed a voucher reward: 10% off at Store (50 eco points)"
        $pattern_cash = '/Claimed a cash reward of ₱([\d.]+) \((\d+) eco points\)/';
        $pattern_voucher = '/Claimed a voucher reward: (.*?) \((\d+) eco points\)/';
        
        if (preg_match($pattern_cash, $description, $matches)) {
            return [
                'reward_type' => 'cash',
                'description' => "Cash Reward",
                'cash_value' => (float)$matches[1],
                'eco_points_required' => (int)$matches[2],
                'voucher_details' => null
            ];
        } elseif (preg_match($pattern_voucher, $description, $matches)) {
            return [
                'reward_type' => 'voucher',
                'description' => $matches[1],
                'cash_value' => null,
                'eco_points_required' => (int)$matches[2],
                'voucher_details' => $matches[1]
            ];
        }
        return [
            'reward_type' => 'unknown',
            'description' => $description,
            'cash_value' => null,
            'eco_points_required' => 0,
            'voucher_details' => null
        ];
    }

    // Fetch Planting History with Pagination and Date Filters
    if ($active_tab === 'planting') {
        $query = "SELECT COUNT(*) FROM activities WHERE user_id = :user_id AND activity_type = 'submission'";
        $params = ['user_id' => $user_id];
        if ($start_date) {
            $query .= " AND created_at >= :start_date";
            $params['start_date'] = $start_date . ' 00:00:00';
        }
        if ($end_date) {
            $query .= " AND created_at <= :end_date";
            $params['end_date'] = $end_date . ' 23:59:59';
        }
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $planting_total = $stmt->fetchColumn();
        $planting_pages = ceil($planting_total / $entries_per_page);

        $query = "
            SELECT activity_id, description, created_at 
            FROM activities 
            WHERE user_id = :user_id AND activity_type = 'submission'
        ";
        if ($start_date) {
            $query .= " AND created_at >= :start_date";
        }
        if ($end_date) {
            $query .= " AND created_at <= :end_date";
        }
        $query .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        $stmt->bindValue(':limit', $entries_per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $raw_planting_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse the description for each entry
        foreach ($raw_planting_history as $entry) {
            $parsed = parsePlantingDescription($entry['description']);
            $planting_history[] = [
                'activity_id' => $entry['activity_id'],
                'trees_planted' => $parsed['trees_planted'],
                'barangay_name' => $parsed['barangay_name'],
                'status' => $parsed['status'],
                'submitted_at' => $entry['created_at']
            ];
        }
    }

    // Fetch Reward History with Pagination and Date Filters
    if ($active_tab === 'reward') {
        $query = "SELECT COUNT(*) FROM activities WHERE user_id = :user_id AND activity_type = 'reward'";
        $params = ['user_id' => $user_id];
        if ($start_date) {
            $query .= " AND created_at >= :start_date";
            $params['start_date'] = $start_date . ' 00:00:00';
        }
        if ($end_date) {
            $query .= " AND created_at <= :end_date";
            $params['end_date'] = $end_date . ' 23:59:59';
        }
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $reward_total = $stmt->fetchColumn();
        $reward_pages = ceil($reward_total / $entries_per_page);

        $query = "
            SELECT activity_id, description, created_at 
            FROM activities 
            WHERE user_id = :user_id AND activity_type = 'reward'
        ";
        if ($start_date) {
            $query .= " AND created_at >= :start_date";
        }
        if ($end_date) {
            $query .= " AND created_at <= :end_date";
        }
        $query .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        $stmt->bindValue(':limit', $entries_per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $raw_reward_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse the description for each entry
        foreach ($raw_reward_history as $entry) {
            $parsed = parseRewardDescription($entry['description']);
            $reward_history[] = [
                'activity_id' => $entry['activity_id'],
                'reward_type' => $parsed['reward_type'],
                'description' => $parsed['description'],
                'cash_value' => $parsed['cash_value'],
                'eco_points_required' => $parsed['eco_points_required'],
                'voucher_details' => $parsed['voucher_details'],
                'claimed_at' => $entry['created_at']
            ];
        }
    }

    // Fetch Event History with Pagination and Date Filters
    if ($active_tab === 'event') {
        $query = "SELECT COUNT(*) FROM activities WHERE user_id = :user_id AND activity_type = 'event'";
        $params = ['user_id' => $user_id];
        if ($start_date) {
            $query .= " AND created_at >= :start_date";
            $params['start_date'] = $start_date . ' 00:00:00';
        }
        if ($end_date) {
            $query .= " AND created_at <= :end_date";
            $params['end_date'] = $end_date . ' 23:59:59';
        }
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $event_total = $stmt->fetchColumn();
        $event_pages = ceil($event_total / $entries_per_page);

        // Since the description contains the event ID (e.g., "Joined event with ID 6"),
        // we'll need to join with the events table to get event details
        $query = "
            SELECT a.activity_id, a.description, a.created_at, 
                   e.event_id, e.title, e.event_date, e.location, ep.confirmed_at 
            FROM activities a 
            LEFT JOIN event_participants ep ON ep.user_id = a.user_id AND ep.event_id = CAST(SUBSTRING_INDEX(a.description, ' ', -1) AS UNSIGNED)
            LEFT JOIN events e ON e.event_id = CAST(SUBSTRING_INDEX(a.description, ' ', -1) AS UNSIGNED)
            WHERE a.user_id = :user_id AND a.activity_type = 'event'
        ";
        if ($start_date) {
            $query .= " AND a.created_at >= :start_date";
        }
        if ($end_date) {
            $query .= " AND a.created_at <= :end_date";
        }
        $query .= " ORDER BY a.created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        $stmt->bindValue(':limit', $entries_per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $event_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    <title>History - Green Roots</title>
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
            background: #E8F5E9;
            color: #333;
            overflow-x: hidden;
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

        .sidebar a:hover,
        .sidebar a.active {
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
            margin-bottom: 20px;
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
            background: #E8F5E9;
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
            background: #E8F5E9;
        }

        .history-nav {
            width: 100%;
            max-width: 800px;
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            padding: 15px 0;
            display: flex;
            justify-content: space-around;
        }

        .history-nav a {
            color: #666;
            text-decoration: none;
            font-size: 16px;
            padding: 10px 20px;
            transition: color 0.3s, background 0.3s;
            border-radius: 10px;
        }

        .history-nav a.active {
            color: #fff;
            background: #4CAF50;
        }

        .history-nav a:hover {
            color: #4CAF50;
        }

        .history-section {
            background: #fff;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 800px;
            margin-bottom: 30px;
            height: 650px;
            display: flex;
            flex-direction: column;
        }

        .history-section h2 {
            font-size: 28px;
            color: #4CAF50;
            margin-bottom: 25px;
        }

        .filter-bar {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }

        .filter-bar label {
            font-size: 16px;
            color: #666;
        }

        .filter-bar input[type="date"] {
            padding: 8px;
            border: 1px solid #e0e7ff;
            border-radius: 5px;
            font-size: 14px;
        }

        .history-list {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 20px;
            padding-right: 10px;
        }

        .history-list::-webkit-scrollbar {
            width: 8px;
        }

        .history-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .history-list::-webkit-scrollbar-thumb {
            background: #4CAF50;
            border-radius: 10px;
        }

        .history-list::-webkit-scrollbar-thumb:hover {
            background: #388E3C;
        }

        .history-card {
            background: #fff;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            gap: 15px;
            border-left: 5px solid #4CAF50;
        }

        .history-details {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .history-detail-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 15px;
            color: #333;
            line-height: 1.6;
        }

        .history-detail-item i {
            color: #4CAF50;
        }

        .status-pending {
            color: #f59e0b;
            font-weight: bold;
        }

        .status-approved {
            color: #10b981;
            font-weight: bold;
        }

        .status-rejected {
            color: #dc2626;
            font-weight: bold;
        }

        .status-confirmed {
            color: #10b981;
            font-weight: bold;
        }

        .no-data {
            text-align: center;
            color: #666;
            font-style: italic;
            font-size: 16px;
            margin-top: 20px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }

        .pagination a {
            padding: 8px 12px;
            background: #fff;
            color: #4CAF50;
            text-decoration: none;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            transition: background 0.3s;
        }

        .pagination a:hover {
            background: #E8F5E9;
        }

        .pagination a.disabled {
            color: #ccc;
            pointer-events: none;
            background: #f5f5f5;
        }

        .pagination a.active {
            background: #4CAF50;
            color: #fff;
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
            max-width: 800px;
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

            .history-nav {
                flex-direction: column;
                padding: 10px;
            }

            .history-nav a {
                padding: 10px;
                font-size: 14px;
                border-bottom: 1px solid #e0e7ff;
            }

            .history-nav a:last-child {
                border-bottom: none;
            }

            .history-nav a.active {
                background: #4CAF50;
                color: #fff;
            }

            .history-section {
                padding: 20px;
                height: 500px;
            }

            .history-section h2 {
                font-size: 24px;
            }

            .filter-bar {
                flex-direction: column;
                gap: 10px;
            }

            .filter-bar input[type="date"] {
                width: 100%;
            }

            .history-card {
                padding: 15px;
                gap: 10px;
            }

            .history-detail-item {
                font-size: 13px;
            }

            .no-data {
                font-size: 14px;
            }

            .pagination a {
                padding: 6px 10px;
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
            <img src="<?php echo $logo_base64; ?>" alt="Green Roots Logo" class="logo">
            <a href="dashboard.php" title="Dashboard"><i class="fas fa-home"></i></a>
            <a href="submit.php" title="Submit Planting"><i class="fas fa-tree"></i></a>
            <a href="planting_site.php" title="Planting Site"><i class="fas fa-map-marker-alt"></i></a>
            <a href="leaderboard.php" title="Leaderboard"><i class="fas fa-trophy"></i></a>
            <a href="rewards.php" title="Rewards"><i class="fas fa-gift"></i></a>
            <a href="events.php" title="Events"><i class="fas fa-calendar-alt"></i></a>
            <a href="history.php" title="History" class="active"><i class="fas fa-history"></i></a>
            <a href="feedback.php" title="Feedback"><i class="fas fa-comment-dots"></i></a>
        </div>
        <div class="main-content">
            <?php if (isset($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <div class="header">
                <h1>History</h1>
                <div class="search-bar">
                    <input type="text" placeholder="Search functionalities..." id="searchInput" aria-label="Search functionalities">
                    <div class="search-results" id="searchResults"></div>
                </div>
                <div class="profile" id="profileBtn" tabindex="0" role="button" aria-label="Profile menu">
                    <span><?php echo htmlspecialchars($username); ?></span>
                    <img src="<?php echo $profile_picture_data; ?>" alt="Profile Picture">
                    <div class="profile-dropdown" id="profileDropdown">
                        <div class="email"><?php echo htmlspecialchars($user['email']); ?></div>
                        <a href="account_settings.php">Account</a>
                        <a href="logout.php">Logout</a>
                    </div>
                </div>
            </div>
            <div class="history-nav">
                <a href="?tab=planting" class="<?php echo $active_tab === 'planting' ? 'active' : ''; ?>">Planting History</a>
                <a href="?tab=reward" class="<?php echo $active_tab === 'reward' ? 'active' : ''; ?>">Reward History</a>
                <a href="?tab=event" class="<?php echo $active_tab === 'event' ? 'active' : ''; ?>">Event History</a>
            </div>
            <div class="history-section">
                <h2>
                    <?php
                        if ($active_tab === 'planting') echo 'Planting History';
                        elseif ($active_tab === 'reward') echo 'Reward History';
                        else echo 'Event History';
                    ?>
                </h2>
                <div class="filter-bar">
                    <div>
                        <label for="start_date">From:</label>
                        <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div>
                        <label for="end_date">To:</label>
                        <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                </div>
                <div class="history-list">
                    <?php if ($active_tab === 'planting'): ?>
                        <?php if (empty($planting_history)): ?>
                            <p class="no-data">No planting history available.</p>
                        <?php else: ?>
                            <?php foreach ($planting_history as $entry): ?>
                                <div class="history-card">
                                    <div class="history-details">
                                        <div class="history-detail-item">
                                            <i class="fas fa-calendar-alt"></i>
                                            <span><?php echo date('F j, Y, g:i A', strtotime($entry['submitted_at'])); ?></span>
                                        </div>
                                        <div class="history-detail-item">
                                            <i class="fas fa-tree"></i>
                                            <span>Trees Planted: <?php echo $entry['trees_planted']; ?></span>
                                        </div>
                                        <div class="history-detail-item">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <span><?php echo htmlspecialchars($entry['barangay_name']); ?></span>
                                        </div>
                                        <div class="history-detail-item">
                                            <i class="fas fa-info-circle"></i>
                                            <span>Status: <span class="status-<?php echo strtolower($entry['status']); ?>" title="Status: <?php echo $entry['status']; ?>"><?php echo $entry['status']; ?></span></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php elseif ($active_tab === 'reward'): ?>
                        <?php if (empty($reward_history)): ?>
                            <p class="no-data">No reward history available.</p>
                        <?php else: ?>
                            <?php foreach ($reward_history as $entry): ?>
                                <div class="history-card">
                                    <div class="history-details">
                                        <div class="history-detail-item">
                                            <i class="fas fa-calendar-alt"></i>
                                            <span><?php echo date('F j, Y, g:i A', strtotime($entry['claimed_at'])); ?></span>
                                        </div>
                                        <div class="history-detail-item">
                                            <i class="fas fa-gift"></i>
                                            <span>Reward Type: <?php echo ucfirst($entry['reward_type']); ?></span>
                                        </div>
                                        <div class="history-detail-item">
                                            <i class="fas fa-info-circle"></i>
                                            <span><?php echo htmlspecialchars($entry['description']); ?></span>
                                        </div>
                                        <div class="history-detail-item">
                                            <i class="fas fa-coins"></i>
                                            <span>Eco Points Used: <?php echo $entry['eco_points_required']; ?></span>
                                        </div>
                                        <?php if ($entry['reward_type'] === 'cash'): ?>
                                            <div class="history-detail-item">
                                                <i class="fas fa-money-bill-wave"></i>
                                                <span>Cash Value: ₱<?php echo number_format($entry['cash_value'], 2); ?></span>
                                            </div>
                                        <?php elseif ($entry['reward_type'] === 'voucher'): ?>
                                            <div class="history-detail-item">
                                                <i class="fas fa-ticket-alt"></i>
                                                <span>Voucher Details: <?php echo htmlspecialchars($entry['voucher_details'] ?? 'N/A'); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (empty($event_history)): ?>
                            <p class="no-data">No event history available.</p>
                        <?php else: ?>
                            <?php foreach ($event_history as $entry): ?>
                                <?php if (!$entry['event_id']) continue; // Skip if event details couldn't be fetched ?>
                                <div class="history-card">
                                    <div class="history-details">
                                        <div class="history-detail-item">
                                            <i class="fas fa-calendar-alt"></i>
                                            <span>Joined At: <?php echo date('F j, Y, g:i A', strtotime($entry['created_at'])); ?></span>
                                        </div>
                                        <div class="history-detail-item">
                                            <i class="fas fa-calendar-check"></i>
                                            <span>Event: <?php echo htmlspecialchars($entry['title']); ?></span>
                                        </div>
                                        <div class="history-detail-item">
                                            <i class="fas fa-calendar-day"></i>
                                            <span>Event Date: <?php echo date('F j, Y', strtotime($entry['event_date'])); ?></span>
                                        </div>
                                        <div class="history-detail-item">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <span>Location: <?php echo htmlspecialchars($entry['location']); ?></span>
                                        </div>
                                        <?php if ($entry['confirmed_at']): ?>
                                            <div class="history-detail-item">
                                                <i class="fas fa-check-circle"></i>
                                                <span class="status-confirmed">Confirmed At: <?php echo date('F j, Y, g:i A', strtotime($entry['confirmed_at'])); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="pagination">
                    <?php
                        $total_pages = 0;
                        if ($active_tab === 'planting') $total_pages = $planting_pages;
                        elseif ($active_tab === 'reward') $total_pages = $reward_pages;
                        else $total_pages = $event_pages;

                        $prev_page = $page - 1;
                        $next_page = $page + 1;
                        $query_params = http_build_query([
                            'tab' => $active_tab,
                            'start_date' => $start_date,
                            'end_date' => $end_date
                        ]);
                    ?>
                    <a href="?<?php echo $query_params; ?>&page=1" class="<?php echo $page <= 1 ? 'disabled' : ''; ?>" aria-label="First page">First</a>
                    <a href="?<?php echo $query_params; ?>&page=<?php echo $prev_page; ?>" class="<?php echo $page <= 1 ? 'disabled' : ''; ?>" aria-label="Previous page">Prev</a>
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <a href="?<?php echo $query_params; ?>&page=<?php echo $i; ?>" class="<?php echo $i === $page ? 'active' : ''; ?>" aria-label="Page <?php echo $i; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <a href="?<?php echo $query_params; ?>&page=<?php echo $next_page; ?>" class="<?php echo $page >= $total_pages ? 'disabled' : ''; ?>" aria-label="Next page">Next</a>
                    <a href="?<?php echo $query_params; ?>&page=<?php echo $total_pages; ?>" class="<?php echo $page >= $total_pages ? 'disabled' : ''; ?>" aria-label="Last page">Last</a>
                </div>
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

        profileBtn.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                profileDropdown.classList.toggle('active');
            }
        });

        document.addEventListener('click', function(e) {
            if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                profileDropdown.classList.remove('active');
            }
        });

        // Filter functionality (server-side)
        const startDateInput = document.querySelector('#start_date');
        const endDateInput = document.querySelector('#end_date');

        function applyFilters() {
            const params = new URLSearchParams(window.location.search);
            params.set('start_date', startDateInput.value);
            params.set('end_date', endDateInput.value);
            params.set('page', '1');
            window.location.search = params.toString();
        }

        startDateInput.addEventListener('change', applyFilters);
        endDateInput.addEventListener('change', applyFilters);
    </script>
</body>
</html>