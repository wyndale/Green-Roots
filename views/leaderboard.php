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

// Initialize variables with default values
$barangay_name = 'Unknown Barangay';
$region_name = 'Unknown Region';
$user_barangay_rank = 'N/A';
$barangay_rank = 'N/A';
$barangay_total_trees = 0;
$barangay_last_updated = 'N/A';
$region_rank = 'N/A';
$region_total_trees = 0;
$user_in_barangay = null;
$user_barangay = null;
$user_region = null;

try {
    // Fetch user data
    $stmt = $pdo->prepare("
        SELECT user_id, username, email, profile_picture, barangay_id, trees_planted 
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

    // Update Rankings Table in Real-Time
    // Step 1: Aggregate total trees planted per barangay
    $stmt = $pdo->prepare("
        SELECT barangay_id, SUM(trees_planted) as total_trees 
        FROM users 
        WHERE barangay_id IS NOT NULL 
        GROUP BY barangay_id
    ");
    $stmt->execute();
    $barangay_totals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Step 2: Update or insert into rankings table
    foreach ($barangay_totals as $total) {
        $barangay_id = $total['barangay_id'];
        $total_trees = $total['total_trees'];

        // Check if barangay exists in rankings
        $stmt = $pdo->prepare("SELECT ranking_id FROM rankings WHERE barangay_id = :barangay_id");
        $stmt->execute(['barangay_id' => $barangay_id]);
        $ranking = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($ranking) {
            // Update existing record
            $stmt = $pdo->prepare("
                UPDATE rankings 
                SET total_trees_planted = :total_trees, updated_at = NOW() 
                WHERE barangay_id = :barangay_id
            ");
            $stmt->execute([
                'total_trees' => $total_trees,
                'barangay_id' => $barangay_id
            ]);
        } else {
            // Insert new record
            $stmt = $pdo->prepare("
                INSERT INTO rankings (barangay_id, total_trees_planted, updated_at) 
                VALUES (:barangay_id, :total_trees, NOW())
            ");
            $stmt->execute([
                'barangay_id' => $barangay_id,
                'total_trees' => $total_trees
            ]);
        }
    }

    // Step 3: Assign ranks based on total trees planted
    $stmt = $pdo->prepare("
        UPDATE rankings r
        JOIN (
            SELECT barangay_id, 
                   RANK() OVER (ORDER BY total_trees_planted DESC) as rank_position
            FROM rankings
        ) ranked ON r.barangay_id = ranked.barangay_id
        SET r.rank_position = ranked.rank_position
    ");
    $stmt->execute();

    // Fetch user's barangay data
    $barangay_id = $user['barangay_id'];
    if ($barangay_id) {
        // Fetch barangay details
        $stmt = $pdo->prepare("
            SELECT name, city, province, region, country 
            FROM barangays 
            WHERE barangay_id = :barangay_id
        ");
        $stmt->execute(['barangay_id' => $barangay_id]);
        $location = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($location) {
            $barangay_name = $location['name'];
            $region_name = $location['region'];
            $city_name = $location['city'];
            $province_name = $location['province'];
            $country_name = $location['country'];
        }

        // Fetch user's ranking in barangay
        $stmt = $pdo->prepare("
            SELECT user_id, username, trees_planted, 
                   RANK() OVER (ORDER BY trees_planted DESC) as user_rank 
            FROM users 
            WHERE barangay_id = :barangay_id
        ");
        $stmt->execute(['barangay_id' => $barangay_id]);
        $user_rankings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($user_rankings as $ranking) {
            if ($ranking['user_id'] == $user_id) {
                $user_barangay_rank = $ranking['user_rank'];
                $user_in_barangay = $ranking;
                break;
            }
        }

        // Fetch barangay's total trees, rank, and last updated time
        $stmt = $pdo->prepare("
            SELECT total_trees_planted, rank_position, updated_at 
            FROM rankings 
            WHERE barangay_id = :barangay_id
        ");
        $stmt->execute(['barangay_id' => $barangay_id]);
        $barangay_ranking = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($barangay_ranking) {
            $barangay_total_trees = $barangay_ranking['total_trees_planted'];
            $barangay_rank = $barangay_ranking['rank_position'];
            $barangay_last_updated = date('M d, Y H:i', strtotime($barangay_ranking['updated_at']));
            $user_barangay = [
                'name' => $barangay_name,
                'total_trees_planted' => $barangay_total_trees,
                'rank_position' => $barangay_rank
            ];
        }

        // Fetch region ranking
        $stmt = $pdo->prepare("
            SELECT r.barangay_id, r.total_trees_planted, r.rank_position 
            FROM rankings r 
            JOIN barangays b ON r.barangay_id = b.barangay_id 
            WHERE b.region = :region_name
        ");
        $stmt->execute(['region_name' => $region_name]);
        $region_rankings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $region_total_trees = 0;
        foreach ($region_rankings as $ranking) {
            $region_total_trees += $ranking['total_trees_planted'];
            if ($ranking['barangay_id'] == $barangay_id) {
                $region_rank = $ranking['rank_position'];
            }
        }

        $user_region = [
            'name' => $region_name,
            'total_trees' => $region_total_trees
        ];

        // Fetch all users in barangay for modal
        $stmt = $pdo->prepare("
            SELECT username, trees_planted, 
                   RANK() OVER (ORDER BY trees_planted DESC) as user_rank 
            FROM users 
            WHERE barangay_id = :barangay_id 
            ORDER BY trees_planted DESC
        ");
        $stmt->execute(['barangay_id' => $barangay_id]);
        $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch all barangays for modal and card
    $stmt = $pdo->prepare("
        SELECT b.name, r.total_trees_planted, r.rank_position 
        FROM rankings r 
        JOIN barangays b ON r.barangay_id = b.barangay_id 
        ORDER BY r.rank_position ASC
    ");
    $stmt->execute();
    $all_barangays = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all regions for modal and card
    $stmt = $pdo->prepare("
        SELECT b.region as name, SUM(r.total_trees_planted) as total_trees 
        FROM rankings r 
        JOIN barangays b ON r.barangay_id = b.barangay_id 
        GROUP BY b.region 
        ORDER BY total_trees DESC
    ");
    $stmt->execute();
    $all_regions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Determine region rank for display
    $region_rank_position = 'N/A';
    foreach ($all_regions as $index => $region) {
        if ($region['name'] === $region_name) {
            $region_rank_position = $index + 1;
            $user_region['rank_position'] = $region_rank_position;
            break;
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
    <title>Leaderboard - Green Roots</title>
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
            transition: color 0.3s;
        }

        .sidebar a:hover {
            color: #4CAF50;
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

        .leaderboard-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
            width: 100%;
            max-width: 1200px;
        }

        .leaderboard-section {
            background: #E8F5E9;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }

        .leaderboard-section.your-rankings {
            background: rgb(187, 235, 191);
        }

        .leaderboard-section.clickable {
            cursor: pointer;
        }

        .leaderboard-section.clickable:hover {
            transform: translateY(-5px);
        }

        .leaderboard-section h2 {
            font-size: 28px;
            color:rgb(54, 134, 56);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .leaderboard-section p {
            font-size: 16px;
            font-weight: bold;
            color: #666;
            margin-bottom: 10px;
        }

        .last-updated {
            font-size: 14px;
            color: #999;
            font-style: italic;
            margin-top: 10px;
        }

        .leaderboard-list {
            list-style: none;
            padding: 0;
            max-height: 200px;
            overflow-y: auto;
        }

        .leaderboard-list li {
            padding: 15px;
            border-bottom: 1px solid #e0e7ff;
            font-size: 16px;
            color: #333;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.3s ease;
        }

        .leaderboard-list li:hover {
            background: rgba(76, 175, 80, 0.1);
        }

        .leaderboard-list li.authenticated-user {
            background: rgb(187, 235, 191);
            font-weight: bold;
        }

        .leaderboard-list li:last-child {
            border-bottom: none;
        }

        .leaderboard-list .no-data {
            text-align: center;
            color: #666;
            font-style: italic;
        }

        .leaderboard-list .rank-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .leaderboard-list .rank-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            font-size: 14px;
            font-weight: bold;
            color: #fff;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            text-shadow: 0 1px 1px rgba(0, 0, 0, 0.3);
        }

        .leaderboard-list .rank-badge.gold {
            background: linear-gradient(135deg, #FFD700 0%, #FFC107 100%);
        }

        .leaderboard-list .rank-badge.silver {
            background: linear-gradient(135deg, #C0C0C0 0%, #A9A9A9 100%);
        }

        .leaderboard-list .rank-badge.bronze {
            background: linear-gradient(135deg, #CD7F32 0%, #B87333 100%);
        }

        .leaderboard-list .rank-badge.platinum {
            background: linear-gradient(135deg, #E5E4E2 0%, #D3D2D0 100%);
        }

        .leaderboard-list .rank-badge.titanium {
            background: linear-gradient(135deg, #878681 0%, #6F6E6A 100%);
        }

        .leaderboard-list .rank-badge.iron {
            background: linear-gradient(135deg, #D7D9D7 0%, #B0B2B0 100%);
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
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
        }

        .modal-content h2 {
            font-size: 28px;
            color: #4CAF50;
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

        .modal-content .modal-search {
            margin-bottom: 20px;
        }

        .modal-content .modal-search input {
            width: 100%;
            padding: 10px;
            border: 1px solid #e0e7ff;
            border-radius: 5px;
            font-size: 16px;
            outline: none;
        }

        .modal-content .leaderboard-list {
            max-height: none;
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
                margin-top:-rdf0;
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

            .leaderboard-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .leaderboard-section {
                padding: 20px;
            }

            .leaderboard-section h2 {
                font-size: 24px;
            }

            .leaderboard-section p {
                font-size: 14px;
            }

            .last-updated {
                font-size: 12px;
            }

            .leaderboard-list li {
                font-size: 14px;
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }

            .leaderboard-list .rank-badge {
                width: 24px;
                height: 24px;
                font-size: 12px;
            }

            .modal-content {
                width: 95%;
                padding: 20px;
            }

            .modal-content h2 {
                font-size: 24px;
            }

            .modal-content .modal-search input {
                padding: 8px;
                font-size: 14px;
            }

            .modal-content .leaderboard-list li {
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
                <h1>Leaderboard</h1>
                <div class="search-bar">
                    <input type="text" placeholder="Search functionalities..." id="searchInput">
                    <div class="search-results" id="searchResults"></div>
                </div>
                <div class="profile" id="profileBtn">
                    <span><?php echo htmlspecialchars($username); ?></span>
                    <img src="<?php echo $profile_picture_data; ?>" alt="Profile">
                    <div class="profile-dropdown" id="profileDropdown">
                        <div class="email"><?php echo htmlspecialchars($user['email']); ?></div>
                        <div class="last-updated">Last Updated: <?php echo $barangay_last_updated; ?></div>
                        <a href="account_settings.php">Account</a>
                        <a href="logout.php">Logout</a>
                    </div>
                </div>
            </div>
            <div class="leaderboard-grid">
                <div class="leaderboard-section your-rankings">
                    <h2>Your Rankings</h2>
                    <p>Rank in <?php echo htmlspecialchars($barangay_name); ?>: <?php echo $user_barangay_rank; ?></p>
                    <p><?php echo htmlspecialchars($barangay_name); ?> Rank: <?php echo $barangay_rank; ?> with <?php echo $barangay_total_trees; ?> trees</p>
                    <p><?php echo htmlspecialchars($region_name); ?> Rank: <?php echo $region_rank; ?></p>
                    <div class="last-updated">Last Updated: <?php echo $barangay_last_updated; ?></div>
                </div>
                <div class="leaderboard-section clickable" id="topUsersCard">
                    <h2>Top Users in <?php echo htmlspecialchars($barangay_name); ?></h2>
                    <ul class="leaderboard-list">
                        <?php if (!$user_in_barangay): ?>
                            <li class="no-data">No ranking available.</li>
                        <?php else: ?>
                            <li class="authenticated-user">
                                <div class="rank-container">
                                    <span class="rank-badge <?php echo $user_in_barangay['user_rank'] == 1 ? 'gold' : ($user_in_barangay['user_rank'] == 2 ? 'silver' : ($user_in_barangay['user_rank'] == 3 ? 'bronze' : ($user_in_barangay['user_rank'] == 4 ? 'platinum' : ($user_in_barangay['user_rank'] == 5 ? 'titanium' : 'iron')))); ?>">
                                        <?php echo $user_in_barangay['user_rank']; ?>
                                    </span>
                                    <span><?php echo htmlspecialchars($user_in_barangay['username']); ?></span>
                                </div>
                                <span><?php echo $user_in_barangay['trees_planted']; ?> trees</span>
                            </li>
                        <?php endif; ?>
                    </ul>
                    <div class="last-updated">Last Updated: <?php echo $barangay_last_updated; ?></div>
                </div>
                <div class="leaderboard-section clickable" id="topBarangaysCard">
                    <h2>Top Barangays</h2>
                    <ul class="leaderboard-list">
                        <?php if (!$user_barangay): ?>
                            <li class="no-data">No barangay ranking available.</li>
                        <?php else: ?>
                            <li class="authenticated-user">
                                <div class="rank-container">
                                    <span class="rank-badge <?php echo $user_barangay['rank_position'] == 1 ? 'gold' : ($user_barangay['rank_position'] == 2 ? 'silver' : ($user_barangay['rank_position'] == 3 ? 'bronze' : ($user_barangay['rank_position'] == 4 ? 'platinum' : ($user_barangay['rank_position'] == 5 ? 'titanium' : 'iron')))); ?>">
                                        <?php echo $user_barangay['rank_position']; ?>
                                    </span>
                                    <span><?php echo htmlspecialchars($user_barangay['name']); ?></span>
                                </div>
                                <span><?php echo $user_barangay['total_trees_planted']; ?> trees</span>
                            </li>
                        <?php endif; ?>
                    </ul>
                    <div class="last-updated">Last Updated: <?php echo $barangay_last_updated; ?></div>
                </div>
                <div class="leaderboard-section clickable" id="topRegionsCard">
                    <h2>Top Regions</h2>
                    <ul class="leaderboard-list">
                        <?php if (!$user_region || $region_rank_position === 'N/A'): ?>
                            <li class="no-data">No region ranking available.</li>
                        <?php else: ?>
                            <li class="authenticated-user">
                                <div class="rank-container">
                                    <span class="rank-badge <?php echo $user_region['rank_position'] == 1 ? 'gold' : ($user_region['rank_position'] == 2 ? 'silver' : ($user_region['rank_position'] == 3 ? 'bronze' : ($user_region['rank_position'] == 4 ? 'platinum' : ($user_region['rank_position'] == 5 ? 'titanium' : 'iron')))); ?>">
                                        <?php echo $user_region['rank_position']; ?>
                                    </span>
                                    <span><?php echo htmlspecialchars($user_region['name']); ?></span>
                                </div>
                                <span><?php echo $user_region['total_trees']; ?> trees</span>
                            </li>
                        <?php endif; ?>
                    </ul>
                    <div class="last-updated">Last Updated: <?php echo $barangay_last_updated; ?></div>
                </div>
            </div>

            <!-- Modal for Top Users -->
            <div class="modal" id="topUsersModal">
                <div class="modal-content">
                    <span class="close-btn" onclick="closeModal('topUsersModal')">×</span>
                    <h2>All Users in <?php echo htmlspecialchars($barangay_name); ?></h2>
                    <div class="modal-search">
                        <input type="text" placeholder="Search users..." id="usersSearchInput">
                    </div>
                    <ul class="leaderboard-list" id="usersList">
                        <?php if (empty($all_users)): ?>
                            <li class="no-data">No users available.</li>
                        <?php else: ?>
                            <?php foreach ($all_users as $user): ?>
                                <li class="<?php echo $user['username'] === $username ? 'authenticated-user' : ''; ?>" data-name="<?php echo htmlspecialchars($user['username']); ?>">
                                    <div class="rank-container">
                                        <span class="rank-badge <?php echo $user['user_rank'] == 1 ? 'gold' : ($user['user_rank'] == 2 ? 'silver' : ($user['user_rank'] == 3 ? 'bronze' : ($user['user_rank'] == 4 ? 'platinum' : ($user['user_rank'] == 5 ? 'titanium' : 'iron')))); ?>">
                                            <?php echo $user['user_rank']; ?>
                                        </span>
                                        <span><?php echo htmlspecialchars($user['username']); ?></span>
                                    </div>
                                    <span><?php echo $user['trees_planted']; ?> trees</span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Modal for Top Barangays -->
            <div class="modal" id="topBarangaysModal">
                <div class="modal-content">
                    <span class="close-btn" onclick="closeModal('topBarangaysModal')">×</span>
                    <h2>All Barangays</h2>
                    <div class="modal-search">
                        <input type="text" placeholder="Search barangays..." id="barangaysSearchInput">
                    </div>
                    <ul class="leaderboard-list" id="barangaysList">
                        <?php if (empty($all_barangays)): ?>
                            <li class="no-data">No barangay rankings available.</li>
                        <?php else: ?>
                            <?php foreach ($all_barangays as $barangay): ?>
                                <li class="<?php echo $barangay['name'] === $barangay_name ? 'authenticated-user' : ''; ?>" data-name="<?php echo htmlspecialchars($barangay['name']); ?>">
                                    <div class="rank-container">
                                        <span class="rank-badge <?php echo $barangay['rank_position'] == 1 ? 'gold' : ($barangay['rank_position'] == 2 ? 'silver' : ($barangay['rank_position'] == 3 ? 'bronze' : ($barangay['rank_position'] == 4 ? 'platinum' : ($barangay['rank_position'] == 5 ? 'titanium' : 'iron')))); ?>">
                                            <?php echo $barangay['rank_position']; ?>
                                        </span>
                                        <span><?php echo htmlspecialchars($barangay['name']); ?></span>
                                    </div>
                                    <span><?php echo $barangay['total_trees_planted']; ?> trees</span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Modal for Top Regions -->
            <div class="modal" id="topRegionsModal">
                <div class="modal-content">
                    <span class="close-btn" onclick="closeModal('topRegionsModal')">×</span>
                    <h2>All Regions</h2>
                    <div class="modal-search">
                        <input type="text" placeholder="Search regions..." id="regionsSearchInput">
                    </div>
                    <ul class="leaderboard-list" id="regionsList">
                        <?php if (empty($all_regions)): ?>
                            <li class="no-data">No region rankings available.</li>
                        <?php else: ?>
                            <?php foreach ($all_regions as $index => $region): ?>
                                <li class="<?php echo $region['name'] === $region_name ? 'authenticated-user' : ''; ?>" data-name="<?php echo htmlspecialchars($region['name']); ?>">
                                    <div class="rank-container">
                                        <span class="rank-badge <?php echo ($index + 1) == 1 ? 'gold' : (($index + 1) == 2 ? 'silver' : (($index + 1) == 3 ? 'bronze' : (($index + 1) == 4 ? 'platinum' : (($index + 1) == 5 ? 'titanium' : 'iron')))); ?>">
                                            <?php echo $index + 1; ?>
                                        </span>
                                        <span><?php echo htmlspecialchars($region['name']); ?></span>
                                    </div>
                                    <span><?php echo $region['total_trees']; ?> trees</span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Search bar functionality for navigation
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
        const topUsersCard = document.querySelector('#topUsersCard');
        const topBarangaysCard = document.querySelector('#topBarangaysCard');
        const topRegionsCard = document.querySelector('#topRegionsCard');
        const topUsersModal = document.querySelector('#topUsersModal');
        const topBarangaysModal = document.querySelector('#topBarangaysModal');
        const topRegionsModal = document.querySelector('#topRegionsModal');

        topUsersCard.addEventListener('click', function() {
            topUsersModal.classList.add('active');
        });

        topBarangaysCard.addEventListener('click', function() {
            topBarangaysModal.classList.add('active');
        });

        topRegionsCard.addEventListener('click', function() {
            topRegionsModal.classList.add('active');
        });

        function closeModal(modalId) {
            document.querySelector(`#${modalId}`).classList.remove('active');
        }

        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('active');
            }
        });

        // Modal search functionality
        function setupModalSearch(inputId, listId) {
            const searchInput = document.querySelector(`#${inputId}`);
            const listItems = document.querySelectorAll(`#${listId} li:not(.no-data)`);

            searchInput.addEventListener('input', function(e) {
                const query = e.target.value.toLowerCase();
                listItems.forEach(item => {
                    const name = item.getAttribute('data-name').toLowerCase();
                    if (name.includes(query)) {
                        item.style.display = 'flex';
                    } else {
                        item.style.display = 'none';
                    }
                });

                const list = document.querySelector(`#${listId}`);
                const noData = list.querySelector('.no-data');
                const visibleItems = Array.from(listItems).filter(item => item.style.display !== 'none');
                if (visibleItems.length === 0 && !noData) {
                    const noResult = document.createElement('li');
                    noResult.className = 'no-data';
                    noResult.textContent = 'No matching results.';
                    list.appendChild(noResult);
                } else if (visibleItems.length > 0 && noData) {
                    noData.remove();
                }
            });
        }

        setupModalSearch('usersSearchInput', 'usersList');
        setupModalSearch('barangaysSearchInput', 'barangaysList');
        setupModalSearch('regionsSearchInput', 'regionsList');
    </script>
</body>
</html>