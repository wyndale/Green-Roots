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

// Initialize arrays to store history data
$planting_history = [];
$reward_history = [];
$event_history = [];
$activity_history = [];

// Total counts for pagination
$planting_total = 0;
$reward_total = 0;
$event_total = 0;
$activity_total = 0;

try {
    // Fetch user data
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
    $profile_picture_data = $user['profile_picture'] ? 'data:image/jpeg;base64,' . base64_encode($user['profile_picture']) : 'profile.jpg';

    // Fetch Planting History with Pagination
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $user_id]);
    $planting_total = $stmt->fetchColumn();
    $planting_pages = ceil($planting_total / $entries_per_page);

    $stmt = $pdo->prepare("
        SELECT s.submission_id, s.trees_planted, s.submitted_at, s.status, b.name as barangay_name 
        FROM submissions s 
        JOIN barangays b ON s.barangay_id = b.barangay_id 
        WHERE s.user_id = :user_id 
        ORDER BY s.submitted_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $entries_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $planting_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Reward History with Pagination
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_rewards WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $user_id]);
    $reward_total = $stmt->fetchColumn();
    $reward_pages = ceil($reward_total / $entries_per_page);

    $stmt = $pdo->prepare("
        SELECT ur.user_reward_id, ur.claimed_at, r.reward_type, r.description, r.eco_points_required, r.cash_value, r.voucher_details 
        FROM user_rewards ur 
        JOIN rewards r ON ur.reward_id = r.reward_id 
        WHERE ur.user_id = :user_id 
        ORDER BY ur.claimed_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $entries_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $reward_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Event History with Pagination
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_participants WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $user_id]);
    $event_total = $stmt->fetchColumn();
    $event_pages = ceil($event_total / $entries_per_page);

    $stmt = $pdo->prepare("
        SELECT e.event_id, e.title, e.event_date, e.location, ep.joined_at 
        FROM event_participants ep 
        JOIN events e ON ep.event_id = e.event_id 
        WHERE ep.user_id = :user_id 
        ORDER BY ep.joined_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $entries_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $event_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Activity History with Pagination
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM activities WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $user_id]);
    $activity_total = $stmt->fetchColumn();
    $activity_pages = ceil($activity_total / $entries_per_page);

    $stmt = $pdo->prepare("
        SELECT activity_id, description, activity_type, created_at 
        FROM activities 
        WHERE user_id = :user_id 
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $entries_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $activity_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History - Tree Planting Initiative</title>
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
            padding: 20px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            border-radius: 0 20px 20px 0;
            box-shadow: 5px 0 15px rgba(0, 0, 0, 0.1);
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 100;
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
            margin-left: 80px; /* Match sidebar width */
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
            background: #4f46e5;
        }

        .history-nav a:hover {
            color: #4f46e5;
        }

        .history-section {
            background: linear-gradient(135deg, #ffffff 0%, #e0e7ff 100%);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 800px;
            margin-bottom: 30px;
            height: 650px; /* Fixed height to fit 10 entries */
            display: flex;
            flex-direction: column;
        }

        .history-section h2 {
            font-size: 28px;
            color: #1e3a8a;
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
            gap: 10px;
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
            background: #4f46e5;
            border-radius: 10px;
        }

        .history-list::-webkit-scrollbar-thumb:hover {
            background: #7c3aed;
        }

        .history-card {
            background: #fff;
            padding: 15px;
            border-radius: 15px;
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            gap: 5px;
            min-height: 120px; /* Adjusted to fit 10 entries in 650px */
        }

        .history-card p {
            font-size: 14px;
            color: #333;
            margin: 2px 0;
        }

        .history-card p strong {
            color: #1e3a8a;
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
            color: #4f46e5;
            text-decoration: none;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            transition: background 0.3s;
        }

        .pagination a:hover {
            background: #e0e7ff;
        }

        .pagination a.disabled {
            color: #ccc;
            pointer-events: none;
            background: #f5f5f5;
        }

        .pagination a.active {
            background: #4f46e5;
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
                bottom: 0;
                top: auto;
                height: auto;
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
                background: #4f46e5;
                color: #fff;
            }

            .history-section {
                padding: 20px;
                height: 500px; /* Adjusted for mobile */
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
                padding: 10px;
                min-height: 100px;
            }

            .history-card p {
                font-size: 12px;
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
                <h1>History</h1>
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
            <div class="history-nav">
                <a href="?tab=planting" class="<?php echo $active_tab === 'planting' ? 'active' : ''; ?>">Planting History</a>
                <a href="?tab=reward" class="<?php echo $active_tab === 'reward' ? 'active' : ''; ?>">Reward History</a>
                <a href="?tab=event" class="<?php echo $active_tab === 'event' ? 'active' : ''; ?>">Event History</a>
                <a href="?tab=activity" class="<?php echo $active_tab === 'activity' ? 'active' : ''; ?>">Activity History</a>
            </div>
            <div class="history-section">
                <h2>
                    <?php
                        if ($active_tab === 'planting') echo 'Planting History';
                        elseif ($active_tab === 'reward') echo 'Reward History';
                        elseif ($active_tab === 'event') echo 'Event History';
                        else echo 'Activity History';
                    ?>
                </h2>
                <div class="filter-bar">
                    <div>
                        <label for="start_date">From:</label>
                        <input type="date" id="start_date" name="start_date">
                    </div>
                    <div>
                        <label for="end_date">To:</label>
                        <input type="date" id="end_date" name="end_date">
                    </div>
                </div>
                <div class="history-list">
                    <?php if ($active_tab === 'planting'): ?>
                        <?php if (empty($planting_history)): ?>
                            <p class="no-data">No planting history available.</p>
                        <?php else: ?>
                            <?php foreach ($planting_history as $entry): ?>
                                <div class="history-card">
                                    <p><strong>Date:</strong> <?php echo date('F j, Y, g:i A', strtotime($entry['submitted_at'])); ?></p>
                                    <p><strong>Trees Planted:</strong> <?php echo $entry['trees_planted']; ?></p>
                                    <p><strong>Location:</strong> <?php echo htmlspecialchars($entry['barangay_name']); ?></p>
                                    <p><strong>Status:</strong> <span class="status-<?php echo strtolower($entry['status']); ?>"><?php echo $entry['status']; ?></span></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php elseif ($active_tab === 'reward'): ?>
                        <?php if (empty($reward_history)): ?>
                            <p class="no-data">No reward history available.</p>
                        <?php else: ?>
                            <?php foreach ($reward_history as $entry): ?>
                                <div class="history-card">
                                    <p><strong>Date:</strong> <?php echo date('F j, Y, g:i A', strtotime($entry['claimed_at'])); ?></p>
                                    <p><strong>Reward Type:</strong> <?php echo $entry['reward_type']; ?></p>
                                    <p><strong>Description:</strong> <?php echo htmlspecialchars($entry['description']); ?></p>
                                    <p><strong>Eco Points Used:</strong> <?php echo $entry['eco_points_required']; ?></p>
                                    <?php if ($entry['reward_type'] === 'cash'): ?>
                                        <p><strong>Cash Value:</strong> ₱<?php echo number_format($entry['cash_value'], 2); ?></p>
                                    <?php else: ?>
                                        <p><strong>Voucher Details:</strong> <?php echo htmlspecialchars($entry['voucher_details'] ?? 'N/A'); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php elseif ($active_tab === 'event'): ?>
                        <?php if (empty($event_history)): ?>
                            <p class="no-data">No event history available.</p>
                        <?php else: ?>
                            <?php foreach ($event_history as $entry): ?>
                                <div class="history-card">
                                    <p><strong>Date:</strong> <?php echo date('F j, Y, g:i A', strtotime($entry['joined_at'])); ?></p>
                                    <p><strong>Event:</strong> <?php echo htmlspecialchars($entry['title']); ?></p>
                                    <p><strong>Event Date:</strong> <?php echo date('F j, Y', strtotime($entry['event_date'])); ?></p>
                                    <p><strong>Location:</strong> <?php echo htmlspecialchars($entry['location']); ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (empty($activity_history)): ?>
                            <p class="no-data">No activity history available.</p>
                        <?php else: ?>
                            <?php foreach ($activity_history as $entry): ?>
                                <div class="history-card">
                                    <p><strong>Date:</strong> <?php echo date('F j, Y, g:i A', strtotime($entry['created_at'])); ?></p>
                                    <p><strong>Type:</strong> <?php echo ucfirst($entry['activity_type']); ?></p>
                                    <p><strong>Description:</strong> <?php echo htmlspecialchars($entry['description']); ?></p>
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
                        elseif ($active_tab === 'event') $total_pages = $event_pages;
                        else $total_pages = $activity_pages;

                        $prev_page = $page - 1;
                        $next_page = $page + 1;
                    ?>
                    <a href="?tab=<?php echo $active_tab; ?>&page=1" class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">First</a>
                    <a href="?tab=<?php echo $active_tab; ?>&page=<?php echo $prev_page; ?>" class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">Prev</a>
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <a href="?tab=<?php echo $active_tab; ?>&page=<?php echo $i; ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <a href="?tab=<?php echo $active_tab; ?>&page=<?php echo $next_page; ?>" class="<?php echo $page >= $total_pages ? 'disabled' : ''; ?>">Next</a>
                    <a href="?tab=<?php echo $active_tab; ?>&page=<?php echo $total_pages; ?>" class="<?php echo $page >= $total_pages ? 'disabled' : ''; ?>">Last</a>
                </div>
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

        // Filter functionality (client-side filtering by date)
        const startDateInput = document.querySelector('#start_date');
        const endDateInput = document.querySelector('#end_date');
        const historyCards = document.querySelectorAll('.history-card');

        function filterHistory() {
            const startDate = startDateInput.value ? new Date(startDateInput.value) : null;
            const endDate = endDateInput.value ? new Date(endDateInput.value) : null;

            historyCards.forEach(card => {
                const dateText = card.querySelector('p:first-child').textContent;
                const dateStr = dateText.replace('Date: ', '');
                const entryDate = new Date(dateStr);

                let show = true;
                if (startDate && entryDate < startDate) show = false;
                if (endDate && entryDate > new Date(endDate.setHours(23, 59, 59, 999))) show = false;

                card.style.display = show ? 'block' : 'none';
            });
        }

        startDateInput.addEventListener('change', filterHistory);
        endDateInput.addEventListener('change', filterHistory);
    </script>
</body>
</html>