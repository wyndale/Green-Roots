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
        SELECT u.user_id, u.username, u.email, u.profile_picture, u.default_profile_asset_id, u.first_name 
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

    // Fetch favicon and logo
    $stmt = $pdo->prepare("SELECT asset_data FROM assets WHERE asset_type = 'favicon' LIMIT 1");
    $stmt->execute();
    $favicon_data = $stmt->fetchColumn();
    $favicon_base64 = $favicon_data ? 'data:image/png;base64,' . base64_encode($favicon_data) : '../assets/favicon.png';

    $stmt = $pdo->prepare("SELECT asset_data FROM assets WHERE asset_type = 'logo' LIMIT 1");
    $stmt->execute();
    $logo_data = $stmt->fetchColumn();
    $logo_base64 = $logo_data ? 'data:image/png;base64,' . base64_encode($logo_data) : 'logo.png';

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
            SELECT activity_id, trees_planted, location, status, created_at 
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
        $planting_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            SELECT activity_id, reward_type, reward_value, eco_points, created_at 
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
        $reward_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Since rewards.php already uses PHP, no conversion needed
        foreach ($reward_history as &$entry) {
            if ($entry['reward_type'] === 'cash') {
                $numeric_value = floatval(str_replace('₱', '', $entry['reward_value']));
                $entry['reward_value'] = "₱" . number_format($numeric_value, 2);
            }
        }
        unset($entry);
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

        $query = "
            SELECT a.activity_id, a.event_title, a.event_date, a.location, a.created_at, ep.confirmed_at 
            FROM activities a 
            LEFT JOIN event_participants ep ON ep.user_id = a.user_id AND ep.event_id = (
                SELECT event_id 
                FROM events 
                WHERE title = a.event_title 
                LIMIT 1
            )
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
            overflow-y: auto;
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

        .sidebar a.active {
            color: #4CAF50;
        }

        .main-content {
            flex: 1;
            padding: 40px;
            margin-left: 80px;
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

        .history-section {
            background: linear-gradient(135deg, #E8F5E9, #C8E6C9);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            width: 100%;
            max-width: 1200px;
            margin-bottom: 30px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .history-section h2 {
            font-size: 28px;
            color: #2E7D32;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .history-nav {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .history-nav a {
            background: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 16px;
            text-decoration: none;
            transition: all 0.3s ease;
            flex: 1;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #666;
        }

        .history-nav a i {
            color: #666;
            transition: color 0.3s ease;
        }

        .history-nav a.active {
            background: #BBEBBF;
            color: #4CAF50;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        .history-nav a.active i {
            color: #4CAF50;
        }

        .history-nav a:hover {
            background: rgba(76, 175, 80, 0.1);
            color: #4CAF50;
        }

        .history-nav a:hover i {
            color: #4CAF50;
        }

        .filter-bar {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            align-items: center;
            background: linear-gradient(135deg, #E8F5E9, #C8E6C9);
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .filter-bar label {
            font-size: 16px;
            color: #2E7D32;
            font-weight: 500;
        }

        .filter-bar .date-input-container {
            position: relative;
            flex: 1;
            min-width: 200px;
        }

        .filter-bar .date-input-container i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #4CAF50;
            font-size: 16px;
            pointer-events: none;
        }

        .filter-bar input[type="date"] {
            width: 100%;
            padding: 10px 10px 10px 35px;
            border: 1px solid #4CAF50;
            border-radius: 8px;
            font-size: 14px;
            background: #fff;
            color: #333;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .filter-bar input[type="date"]::-webkit-calendar-picker-indicator {
            opacity: 0;
        }

        .filter-bar input[type="date"]:hover {
            border-color: #388E3C;
            box-shadow: 0 0 5px rgba(76, 175, 80, 0.3);
        }

        .filter-bar input[type="date"]:focus {
            outline: none;
            border-color: #388E3C;
            box-shadow: 0 0 8px rgba(76, 175, 80, 0.5);
        }

        .filter-bar button {
            background: linear-gradient(135deg, #4CAF50, #388E3C);
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: transform 0.1s, box-shadow 0.3s;
        }

        .filter-bar button i {
            font-size: 16px;
        }

        .filter-bar button:hover {
            transform: scale(1.03);
            box-shadow: 0 5px 15px rgba(76, 175, 80, 0.4);
        }

        .filter-bar button:active {
            transform: scale(0.98);
        }

        .history-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
            max-height: 500px;
            overflow-y: auto;
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
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.5s;
        }

        .no-data {
            text-align: center;
            color: #666;
            font-style: italic;
            font-size: 16px;
            margin-top: 20px;
        }

        .history-card:hover {
            transform: translateY(-5px);
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

        .status-pending-event {
            color: #f59e0b;
            font-weight: bold;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }

        .pagination a {
            padding: 8px 16px;
            background: #fff;
            border-radius: 5px;
            color: #666;
            text-decoration: none;
            transition: background 0.3s;
        }

        .pagination a:hover {
            background: #4CAF50;
            color: #fff;
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

            .history-section {
                padding: 20px;
            }

            .history-section h2 {
                font-size: 24px;
            }

            .history-nav {
                flex-direction: column;
                gap: 5px;
            }

            .history-nav a {
                padding: 8px 10px;
                font-size: 14px;
            }

            .history-nav a i {
                font-size: 14px;
            }

            .filter-bar {
                flex-direction: column;
                align-items: stretch;
                padding: 10px;
                gap: 10px;
            }

            .filter-bar label {
                font-size: 14px;
            }

            .filter-bar .date-input-container {
                min-width: 100%;
            }

            .filter-bar input[type="date"] {
                font-size: 14px;
                padding: 8px 8px 8px 30px;
            }

            .filter-bar .date-input-container i {
                font-size: 14px;
                left: 8px;
            }

            .filter-bar button {
                font-size: 14px;
                padding: 8px 15px;
            }

            .filter-bar button i {
                font-size: 14px;
            }

            .history-card {
                padding: 15px;
            }

            .history-detail-item {
                font-size: 14px;
            }

            .pagination a {
                padding: 6px 12px;
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

            .history-section {
                padding: 15px;
            }

            .history-section h2 {
                font-size: 20px;
            }

            .history-nav a {
                font-size: 12px;
                padding: 6px 8px;
            }

            .history-nav a i {
                font-size: 12px;
            }

            .filter-bar {
                padding: 8px;
                gap: 8px;
            }

            .filter-bar label {
                font-size: 12px;
            }

            .filter-bar input[type="date"] {
                font-size: 12px;
                padding: 6px 6px 6px 25px;
            }

            .filter-bar .date-input-container i {
                font-size: 12px;
                left: 6px;
            }

            .filter-bar button {
                font-size: 12px;
                padding: 6px 10px;
            }

            .filter-bar button i {
                font-size: 12px;
            }

            .history-detail-item {
                font-size: 12px;
            }

            .pagination a {
                padding: 4px 8px;
                font-size: 12px;
            }

            .error-message {
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
            <a href="history.php" title="History" class="active"><i class="fas fa-clock"></i></a>
            <a href="feedback.php" title="Feedback"><i class="fas fa-comment"></i></a>
        </div>

        <div class="main-content">
            <?php if (isset($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <div class="header">
                <h1>History</h1>
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

            <div class="history-section">
                <h2>
                    <?php
                    if ($active_tab === 'planting') echo '<i class="fas fa-seedling"></i> Planting History';
                    elseif ($active_tab === 'event') echo '<i class="fas fa-calendar-days"></i> Event History';
                    else echo '<i class="fas fa-gift"></i> Reward History';
                    ?>
                </h2>

                <div class="history-nav">
                    <a href="?tab=planting" class="<?php echo $active_tab === 'planting' ? 'active' : ''; ?>"><i class="fas fa-seedling"></i> Planting History</a>
                    <a href="?tab=event" class="<?php echo $active_tab === 'event' ? 'active' : ''; ?>"><i class="fas fa-calendar-days"></i> Event History</a>
                    <a href="?tab=reward" class="<?php echo $active_tab === 'reward' ? 'active' : ''; ?>"><i class="fas fa-gift"></i> Reward History</a>
                </div>

                <div class="filter-bar">
                    <label for="start_date">From:</label>
                    <div class="date-input-container">
                        <i class="fas fa-calendar-days"></i>
                        <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <label for="end_date">To:</label>
                    <div class="date-input-container">
                        <i class="fas fa-calendar-days"></i>
                        <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    <button onclick="applyDateFilter()"><i class="fas fa-filter"></i> Filter</button>
                </div>

                <div class="history-list">
                    <?php if ($active_tab === 'planting'): ?>
                        <?php if (empty($planting_history)): ?>
                            <p class="no-data">No planting history found.</p>
                        <?php else: ?>
                            <?php foreach ($planting_history as $entry): ?>
                                <div class="history-card">
                                    <div class="history-details">
                                        <div class="history-detail-item">
                                            <i class="fas fa-seedling"></i>
                                            Trees Planted: <?php echo htmlspecialchars($entry['trees_planted']); ?>
                                        </div>
                                        <div class="history-detail-item">
                                            <i class="fas fa-map-pin"></i>
                                            Location: <?php echo htmlspecialchars($entry['location']); ?>
                                        </div>
                                        <div class="history-detail-item">
                                            <i class="fas fa-info-circle"></i>
                                            Status: <span class="status-<?php echo htmlspecialchars($entry['status']); ?>"><?php echo htmlspecialchars(ucfirst($entry['status'])); ?></span>
                                        </div>
                                        <div class="history-detail-item">
                                            <i class="fas fa-calendar-days"></i>
                                            Date: <?php echo htmlspecialchars(date('F j, Y, g:i a', strtotime($entry['created_at']))); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php elseif ($active_tab === 'event'): ?>
                        <?php if (empty($event_history)): ?>
                            <p class="no-data">No event history found.</p>
                        <?php else: ?>
                            <?php foreach ($event_history as $entry): ?>
                                <div class="history-card">
                                    <div class="history-details">
                                        <div class="history-detail-item">
                                            <i class="fas fa-calendar-days"></i>
                                            Event: <?php echo htmlspecialchars($entry['event_title']); ?>
                                        </div>
                                        <div class="history-detail-item">
                                            <i class="fas fa-map-pin"></i>
                                            Location: <?php echo htmlspecialchars($entry['location']); ?>
                                        </div>
                                        <div class="history-detail-item">
                                            <i class="fas fa-calendar-day"></i>
                                            Event Date: <?php echo htmlspecialchars(date('F j, Y', strtotime($entry['event_date']))); ?>
                                        </div>
                                        <div class="history-detail-item">
                                            <i class="fas fa-info-circle"></i>
                                            Status: <span class="<?php echo $entry['confirmed_at'] ? 'status-confirmed' : 'status-pending-event'; ?>">
                                                <?php echo $entry['confirmed_at'] ? 'Confirmed' : 'Pending Confirmation'; ?>
                                            </span>
                                        </div>
                                        <div class="history-detail-item">
                                            <i class="fas fa-clock"></i>
                                            Joined At: <?php echo htmlspecialchars(date('F j, Y, g:i a', strtotime($entry['created_at']))); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (empty($reward_history)): ?>
                            <p class="no-data">No reward history found.</p>
                        <?php else: ?>
                            <?php foreach ($reward_history as $entry): ?>
                                <div class="history-card">
                                    <div class="history-details">
                                        <div class="history-detail-item">
                                            <i class="fas fa-gift"></i>
                                            Reward Type: <?php echo htmlspecialchars(ucfirst($entry['reward_type'])); ?>
                                        </div>
                                        <div class="history-detail-item">
                                            <i class="fas fa-tag"></i>
                                            Value: <?php echo htmlspecialchars($entry['reward_value']); ?>
                                        </div>
                                        <div class="history-detail-item">
                                            <i class="fas fa-coins"></i>
                                            Eco Points Used: <?php echo htmlspecialchars($entry['eco_points']); ?>
                                        </div>
                                        <div class="history-detail-item">
                                            <i class="fas fa-calendar-days"></i>
                                            Date: <?php echo htmlspecialchars(date('F j, Y, g:i a', strtotime($entry['created_at']))); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($active_tab === 'planting' && $planting_total > $entries_per_page): ?>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $planting_pages; $i++): ?>
                            <a href="?tab=planting&page=<?php echo $i; ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" class="<?php echo $page === $i ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                <?php elseif ($active_tab === 'event' && $event_total > $entries_per_page): ?>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $event_pages; $i++): ?>
                            <a href="?tab=event&page=<?php echo $i; ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" class="<?php echo $page === $i ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                <?php elseif ($active_tab === 'reward' && $reward_total > $entries_per_page): ?>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $reward_pages; $i++): ?>
                            <a href="?tab=reward&page=<?php echo $i; ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" class="<?php echo $page === $i ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
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

        // Apply date filter
        function applyDateFilter() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            const tab = '<?php echo $active_tab; ?>';
            let url = `?tab=${tab}`;
            if (startDate) url += `&start_date=${startDate}`;
            if (endDate) url += `&end_date=${endDate}`;
            window.location.href = url;
        }
    </script>
</body>
</html>