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

// Pagination settings for feedback history
$entries_per_page = 10;
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'submit';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $entries_per_page;

// Initialize variables
$feedback_history = [];
$feedback_total = 0;
$feedback_error = '';
$feedback_success = '';

// Fetch user data
try {
    $stmt = $pdo->prepare("
        SELECT user_id, username, email, profile_picture 
        FROM users 
        WHERE user_id = :user_id
    ");
    $stmt->execute(['user_id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header('Location: login.php');
        exit;
    }

    // Convert profile picture to base64 for display
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

    // Handle Feedback Submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $active_tab === 'submit') {
        // Check for recent submission (within 24 hours)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM feedback 
            WHERE user_id = :user_id 
            AND submitted_at >= NOW() - INTERVAL 24 HOUR
        ");
        $stmt->execute(['user_id' => $user_id]);
        $recent_submission = $stmt->fetchColumn();

        if ($recent_submission > 0) {
            $feedback_error = 'You can only submit feedback once every 24 hours.';
        } else {
            $category = $_POST['category'] ?? '';
            $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
            $comments = trim($_POST['comments'] ?? '');
            $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;

            // Validate inputs
            if (!in_array($category, ['bug', 'feature', 'general'])) {
                $feedback_error = 'Please select a valid category.';
            } elseif ($rating < 1 || $rating > 5) {
                $feedback_error = 'Please provide a rating between 1 and 5.';
            } elseif (empty($comments)) {
                $feedback_error = 'Comments are required.';
            } elseif (strlen($comments) > 1000) {
                $feedback_error = 'Comments cannot exceed 1000 characters.';
            } else {
                // Insert feedback into the database
                $stmt = $pdo->prepare("
                    INSERT INTO feedback (user_id, category, rating, comments, is_anonymous, submitted_at) 
                    VALUES (:user_id, :category, :rating, :comments, :is_anonymous, NOW())
                ");
                $stmt->execute([
                    'user_id' => $user_id,
                    'category' => $category,
                    'rating' => $rating,
                    'comments' => $comments,
                    'is_anonymous' => $is_anonymous
                ]);

                // Log activity
                $stmt = $pdo->prepare("
                    INSERT INTO activities (user_id, description, activity_type, created_at) 
                    VALUES (:user_id, 'Submitted feedback', 'feedback', NOW())
                ");
                $stmt->execute(['user_id' => $user_id]);

                $feedback_success = 'Thanks you! Your feedback was submitted successfully—we appreciate it.';
            }
        }
    }

    // Fetch Feedback History with Pagination
    if ($active_tab === 'history') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM feedback WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $user_id]);
        $feedback_total = $stmt->fetchColumn();
        $feedback_pages = ceil($feedback_total / $entries_per_page);

        $stmt = $pdo->prepare("
            SELECT feedback_id, category, rating, comments, is_anonymous, status, submitted_at, response 
            FROM feedback 
            WHERE user_id = :user_id 
            ORDER BY submitted_at DESC 
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $entries_per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $feedback_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    <title>Feedback - Green Roots</title>
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
            margin-left: 80px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            width: 100%;
            max-width: 1200px;
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

        .feedback-nav {
            width: 100%;
            max-width: 1200px;
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            padding: 10px;
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        .feedback-nav a {
            color: #666;
            text-decoration: none;
            font-size: 16px;
            padding: 10px 20px;
            border-radius: 10px;
            transition: all 0.3s ease;
            flex: 1;
            text-align: center;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .feedback-nav a i {
            color: #666;
            transition: color 0.3s ease;
        }

        .feedback-nav a.active {
            background: rgb(187, 235, 191);
            color: #4CAF50;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        .feedback-nav a.active i {
            color: #4CAF50;
        }

        .feedback-nav a:hover {
            background: rgba(76, 175, 80, 0.1);
            color: #4CAF50;
        }

        .feedback-nav a:hover i {
            color: #4CAF50;
        }

        .feedback-section {
            background: #E8F5E9;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 1200px;
            margin-bottom: 30px;
        }

        .feedback-section h2 {
            font-size: 28px;
            color: #4CAF50;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .feedback-section h2 i {
            color: #4CAF50;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-group label {
            display: block;
            font-size: 16px;
            color: #666;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .form-group label i {
            color: #4CAF50;
        }

        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #e0e7ff;
            border-radius: 8px;
            font-size: 16px;
            outline: none;
            transition: border-color 0.3s, box-shadow 0.3s;
            background: #fff;
        }

        .form-group select:hover,
        .form-group textarea:hover {
            border-color: #4CAF50;
        }

        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #4CAF50;
            box-shadow: 0 0 5px rgba(76, 175, 80, 0.3);
        }

        .form-group textarea {
            height: 150px;
            resize: vertical;
        }

        .form-group .char-counter {
            position: absolute;
            bottom: -20px;
            right: 5px;
            font-size: 12px;
            color: #666;
        }

        .form-group .char-counter.warning {
            color: #dc2626;
        }

        .rating-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            position: relative;
        }

        .rating-group label {
            font-size: 16px;
            color: #666;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .rating-group label i {
            color: #4CAF50;
        }

        .rating-group .stars {
            display: flex;
            gap: 5px;
            background: rgb(187, 235, 191);
            padding: 5px 10px;
            border-radius: 8px;
        }

        .rating-group .stars input {
            display: none;
        }

        .rating-group .stars label {
            font-size: 24px;
            color: #ccc;
            cursor: pointer;
            transition: color 0.3s;
            margin-bottom: 0;
        }

        .rating-group .stars label:hover,
        .rating-group .stars label:hover ~ label,
        .rating-group .stars input:checked ~ label {
            color: #f59e0b;
        }

        .rating-group .tooltip {
            position: absolute;
            top: -30px;
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: #fff;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            display: none;
            z-index: 10;
        }

        .rating-group:hover .tooltip {
            display: block;
        }

        .form-group.checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
        }

        .form-group.checkbox input {
            width: auto;
            cursor: pointer;
        }

        .form-group.checkbox label {
            margin-bottom: 0;
            cursor: pointer;
        }

        .form-group.checkbox .tooltip {
            position: absolute;
            top: -30px;
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: #fff;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            display: none;
            z-index: 10;
        }

        .form-group.checkbox:hover .tooltip {
            display: block;
        }

        .feedback-section input[type="submit"] {
            background: #4CAF50;
            color: #fff;
            border: none;
            padding: 12px;
            border-radius: 8px;
            font-size: 16px;
            width: 100%;
            cursor: pointer;
            transition: background 0.3s, transform 0.1s;
        }

        .feedback-section input[type="submit"]:hover {
            background: #388E3C;
            transform: scale(1.02);
        }

        .history-section {
            height: 650px;
            display: flex;
            flex-direction: column;
        }

        .history-list {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 15px;
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

        .feedback-card {
            background: #fff;
            padding: 15px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .feedback-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }

        .feedback-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .feedback-card-header p {
            font-size: 14px;
            color: #666;
        }

        .feedback-card-header .status {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-submitted {
            background: #fef3c7;
            color: #f59e0b;
        }

        .status-under_review {
            background: #dbeafe;
            color: #3b82f6;
        }

        .status-resolved {
            background: #d1fae5;
            color: #10b981;
        }

        .feedback-card-content {
            max-height: 100px;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .feedback-card-content.expanded {
            max-height: 500px;
        }

        .feedback-card-content p {
            font-size: 14px;
            color: #333;
            margin: 5px 0;
        }

        .feedback-card-content p strong {
            color: #4CAF50;
        }

        .feedback-card-content .rating {
            color: #f59e0b;
        }

        .toggle-btn {
            background: none;
            border: none;
            color: #4CAF50;
            font-size: 14px;
            cursor: pointer;
            margin-top: 5px;
            text-align: left;
            transition: color 0.3s;
        }

        .toggle-btn:hover {
            color: #388E3C;
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
            border-radius: 25px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            min-width: 40px;
            text-align: center;
        }

        .pagination a:hover {
            background: rgba(76, 175, 80, 0.1);
        }

        .pagination a.disabled {
            color: #ccc;
            pointer-events: none;
            background: #f5f5f5;
        }

        .pagination a.active {
            background: #4CAF50;
            color: #fff;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
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
            max-width: 1200px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
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
            justify-content: center;
            align-items: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: #fff;
            padding: 30px;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
            text-align: center;
        }

        .modal-content .success-message {
            font-size: 16px;
            color: #4CAF50;
            margin-bottom: 20px;
        }

        .modal-content .error-message {
            font-size: 16px;
            color: #dc2626;
            margin-bottom: 20px;
        }

        .modal-content .close-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 24px;
            color: #666;
            cursor: pointer;
            transition: color 0.3s;
        }

        .modal-content .close-btn:hover {
            color: #dc2626;
        }

        .modal-content button {
            background: #4CAF50;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .modal-content button:hover {
            background: #388E3C;
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

            .feedback-nav {
                flex-direction: row;
                padding: 5px;
                gap: 5px;
            }

            .feedback-nav a {
                padding: 8px 10px;
                font-size: 14px;
            }

            .feedback-nav a i {
                font-size: 14px;
            }

            .feedback-section {
                padding: 20px;
            }

            .feedback-section h2 {
                font-size: 24px;
            }

            .form-group label,
            .rating-group label {
                font-size: 14px;
            }

            .form-group select,
            .form-group textarea {
                font-size: 14px;
                padding: 10px;
            }

            .form-group .char-counter {
                font-size: 10px;
                bottom: -18px;
            }

            .rating-group .stars label {
                font-size: 20px;
            }

            .rating-group .tooltip {
                font-size: 10px;
                top: -25px;
            }

            .form-group.checkbox .tooltip {
                font-size: 10px;
                top: -25px;
            }

            .feedback-section input[type="submit"] {
                font-size: 14px;
                padding: 10px;
            }

            .history-section {
                height: 500px;
            }

            .feedback-card {
                padding: 10px;
            }

            .feedback-card-header p {
                font-size: 12px;
            }

            .feedback-card-header .status {
                font-size: 10px;
                padding: 4px 8px;
            }

            .feedback-card-content p {
                font-size: 12px;
            }

            .toggle-btn {
                font-size: 12px;
            }

            .no-data {
                font-size: 14px;
            }

            .pagination a {
                padding: 6px 10px;
                font-size: 14px;
                min-width: 35px;
            }

            .error-message {
                font-size: 14px;
            }

            .modal-content {
                width: 95%;
                padding: 20px;
            }

            .modal-content .success-message,
            .modal-content .error-message {
                font-size: 14px;
            }

            .modal-content button {
                font-size: 14px;
                padding: 8px 15px;
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
                <h1>Feedback</h1>
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
            <div class="feedback-nav">
                <a href="?tab=submit" class="<?php echo $active_tab === 'submit' ? 'active' : ''; ?>">
                    <i class="fas fa-comment-dots"></i> Submit Feedback
                </a>
                <a href="?tab=history" class="<?php echo $active_tab === 'history' ? 'active' : ''; ?>">
                    <i class="fas fa-history"></i> Feedback History
                </a>
            </div>
            <div class="feedback-section <?php echo $active_tab === 'history' ? 'history-section' : ''; ?>">
                <?php if ($active_tab === 'submit'): ?>
                    <h2><i class="fas fa-comment-dots"></i> Submit Feedback</h2>
                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="category"><i class="fas fa-list"></i> Category</label>
                            <select id="category" name="category" required>
                                <option value="">Select a category</option>
                                <option value="bug">Bug Report</option>
                                <option value="feature">Feature Request</option>
                                <option value="general">General Feedback</option>
                            </select>
                        </div>
                        <div class="rating-group">
                            <label><i class="fas fa-star"></i> Rating</label>
                            <div class="stars">
                                <input type="radio" id="star5" name="rating" value="5" required>
                                <label for="star5">★</label>
                                <input type="radio" id="star4" name="rating" value="4">
                                <label for="star4">★</label>
                                <input type="radio" id="star3" name="rating" value="3">
                                <label for="star3">★</label>
                                <input type="radio" id="star2" name="rating" value="2">
                                <label for="star2">★</label>
                                <input type="radio" id="star1" name="rating" value="1">
                                <label for="star1">★</label>
                            </div>
                            <span class="tooltip">Rate your experience (1-5 stars)</span>
                        </div>
                        <div class="form-group">
                            <label for="comments"><i class="fas fa-comment"></i> Comments</label>
                            <textarea id="comments" name="comments" required maxlength="1000" oninput="updateCharCounter()"></textarea>
                            <span id="charCounter" class="char-counter">0/1000</span>
                        </div>
                        <div class="form-group checkbox">
                            <input type="checkbox" id="is_anonymous" name="is_anonymous">
                            <label for="is_anonymous"><i class="fas fa-user-secret"></i> Submit anonymously</label>
                            <span class="tooltip">Your identity will not be linked to this feedback</span>
                        </div>
                        <input type="submit" value="Submit Feedback">
                    </form>
                <?php else: ?>
                    <h2><i class="fas fa-history"></i> Feedback History</h2>
                    <div class="history-list">
                        <?php if (empty($feedback_history)): ?>
                            <p class="no-data">No feedback history available.</p>
                        <?php else: ?>
                            <?php foreach ($feedback_history as $entry): ?>
                                <div class="feedback-card">
                                    <div class="feedback-card-header">
                                        <p><strong>Date:</strong> <?php echo date('F j, Y, g:i A', strtotime($entry['submitted_at'])); ?></p>
                                        <span class="status status-<?php echo str_replace('_', '-', $entry['status']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $entry['status'])); ?>
                                        </span>
                                    </div>
                                    <div class="feedback-card-content" id="content-<?php echo $entry['feedback_id']; ?>">
                                        <p><strong>Category:</strong> <?php echo ucfirst($entry['category']); ?></p>
                                        <p><strong>Rating:</strong> <span class="rating"><?php echo str_repeat('★', $entry['rating']) . str_repeat('☆', 5 - $entry['rating']); ?></span></p>
                                        <p><strong>Comments:</strong> <?php echo htmlspecialchars($entry['comments']); ?></p>
                                        <p><strong>Anonymous:</strong> <?php echo $entry['is_anonymous'] ? 'Yes' : 'No'; ?></p>
                                        <?php if ($entry['response']): ?>
                                            <p><strong>Response:</strong> <?php echo htmlspecialchars($entry['response']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <button class="toggle-btn" onclick="toggleContent('content-<?php echo $entry['feedback_id']; ?>', this)">
                                        Show More
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="pagination">
                        <?php
                            $total_pages = $feedback_pages;
                            $prev_page = $page - 1;
                            $next_page = $page + 1;
                        ?>
                        <a href="?tab=history&page=1" class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">First</a>
                        <a href="?tab=history&page=<?php echo $prev_page; ?>" class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">Prev</a>
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <a href="?tab=history&page=<?php echo $i; ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                        <a href="?tab=history&page=<?php echo $next_page; ?>" class="<?php echo $page >= $total_pages ? 'disabled' : ''; ?>">Next</a>
                        <a href="?tab=history&page=<?php echo $total_pages; ?>" class="<?php echo $page >= $total_pages ? 'disabled' : ''; ?>">Last</a>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Modal for Success/Error Messages -->
            <?php if ($feedback_success): ?>
                <div class="modal active" id="feedbackModal">
                    <div class="modal-content">
                        <span class="close-btn" onclick="closeModal('feedbackModal')">×</span>
                        <div class="success-message"><?php echo htmlspecialchars($feedback_success); ?></div>
                        <button onclick="closeModal('feedbackModal')">Close</button>
                    </div>
                </div>
            <?php elseif ($feedback_error): ?>
                <div class="modal active" id="feedbackModal">
                    <div class="modal-content">
                        <span class="close-btn" onclick="closeModal('feedbackModal')">×</span>
                        <div class="error-message"><?php echo htmlspecialchars($feedback_error); ?></div>
                        <button onclick="closeModal('feedbackModal')">Close</button>
                    </div>
                </div>
            <?php endif; ?>
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

        // Modal functionality
        function closeModal(modalId) {
            document.querySelector(`#${modalId}`).classList.remove('active');
        }

        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('active');
            }
        });

        // Character counter for comments
        function updateCharCounter() {
            const textarea = document.querySelector('#comments');
            const counter = document.querySelector('#charCounter');
            const count = textarea.value.length;
            counter.textContent = `${count}/1000`;
            if (count > 900) {
                counter.classList.add('warning');
            } else {
                counter.classList.remove('warning');
            }
        }

        // Toggle feedback card content
        function toggleContent(contentId, btn) {
            const content = document.getElementById(contentId);
            content.classList.toggle('expanded');
            btn.textContent = content.classList.contains('expanded') ? 'Show Less' : 'Show More';
        }

        // Initialize character counter on page load
        document.addEventListener('DOMContentLoaded', updateCharCounter);
    </script>
</body>
</html>