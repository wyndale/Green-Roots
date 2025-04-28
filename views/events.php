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
$events_per_page = 10;
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'upcoming';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $events_per_page;

// Filters
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';
$filter_barangay = isset($_GET['filter_barangay']) ? (int)$_GET['filter_barangay'] : 0;

// Initialize variables
$upcoming_events = [];
$my_events = [];
$upcoming_total = 0;
$my_events_total = 0;
$barangays = [];
$join_message = '';

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
    $profile_picture_data = $user['profile_picture'] ? 'data:image/jpeg;base64,' . base64_encode($user['profile_picture']) : 'profile.jpg';

    // Fetch barangays for filter
    $stmt = $pdo->prepare("SELECT barangay_id, name FROM barangays ORDER BY name");
    $stmt->execute();
    $barangays = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle Join Event
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join_event'])) {
        $event_id = (int)$_POST['event_id'];

        // Check if the user has already joined the event
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM event_participants 
            WHERE event_id = :event_id AND user_id = :user_id
        ");
        $stmt->execute(['event_id' => $event_id, 'user_id' => $user_id]);
        $already_joined = $stmt->fetchColumn();

        if ($already_joined) {
            $join_message = 'You have already joined this event.';
        } else {
            // Add user to event participants
            $stmt = $pdo->prepare("
                INSERT INTO event_participants (event_id, user_id, joined_at) 
                VALUES (:event_id, :user_id, NOW())
            ");
            $stmt->execute(['event_id' => $event_id, 'user_id' => $user_id]);

            // Log activity
            $stmt = $pdo->prepare("
                INSERT INTO activities (user_id, description, activity_type, created_at) 
                VALUES (:user_id, :description, 'event', NOW())
            ");
            $stmt->execute([
                'user_id' => $user_id,
                'description' => "Joined event with ID $event_id"
            ]);

            $join_message = 'Successfully joined the event!';
        }
    }

    // Fetch Upcoming Events with Pagination
    if ($active_tab === 'upcoming') {
        $query = "
            SELECT e.event_id, e.title, e.description, e.event_date, e.location, b.name as barangay_name 
            FROM events e 
            LEFT JOIN barangays b ON e.barangay_id = b.barangay_id 
            WHERE e.event_date >= CURDATE()
        ";
        $params = [];

        // Apply filters
        if ($filter_date) {
            $query .= " AND e.event_date = :filter_date";
            $params['filter_date'] = $filter_date;
        }
        if ($filter_barangay) {
            $query .= " AND e.barangay_id = :filter_barangay";
            $params['filter_barangay'] = $filter_barangay;
        }

        // Count total for pagination
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $upcoming_total = $stmt->rowCount();
        $upcoming_pages = ceil($upcoming_total / $events_per_page);

        // Fetch events with limit and offset
        $query .= " ORDER BY e.event_date ASC LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        $stmt->bindValue(':limit', $events_per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $upcoming_events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Check which events the user has joined
        $joined_events = [];
        if (!empty($upcoming_events)) {
            $event_ids = array_column($upcoming_events, 'event_id');
            $placeholders = implode(',', array_fill(0, count($event_ids), '?'));
            $stmt = $pdo->prepare("
                SELECT event_id 
                FROM event_participants 
                WHERE event_id IN ($placeholders) AND user_id = ?
            ");
            $params = array_merge($event_ids, [$user_id]);
            $stmt->execute($params);
            $joined_events = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'event_id');
        }
    }

    // Fetch My Events with Pagination
    if ($active_tab === 'my_events') {
        $query = "
            SELECT e.event_id, e.title, e.description, e.event_date, e.location, b.name as barangay_name, ep.joined_at 
            FROM events e 
            JOIN event_participants ep ON e.event_id = ep.event_id 
            LEFT JOIN barangays b ON e.barangay_id = b.barangay_id 
            WHERE ep.user_id = :user_id
        ";
        $params = ['user_id' => $user_id];

        if ($filter_date) {
            $query .= " AND e.event_date = :filter_date";
            $params['filter_date'] = $filter_date;
        }
        if ($filter_barangay) {
            $query .= " AND e.barangay_id = :filter_barangay";
            $params['filter_barangay'] = $filter_barangay;
        }

        // Count total for pagination
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $my_events_total = $stmt->rowCount();
        $my_events_pages = ceil($my_events_total / $events_per_page);

        // Fetch events with limit and offset
        $query .= " ORDER BY e.event_date DESC LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        $stmt->bindValue(':limit', $events_per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $my_events = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    <title>Events - Tree Planting Initiative</title>
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

        .events-nav {
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

        .events-nav a {
            color: #666;
            text-decoration: none;
            font-size: 16px;
            padding: 10px 20px;
            transition: color 0.3s, background 0.3s;
            border-radius: 10px;
        }

        .events-nav a.active {
            color: #fff;
            background: #4f46e5;
        }

        .events-nav a:hover {
            color: #4f46e5;
        }

        .events-section {
            background: linear-gradient(135deg, #ffffff 0%, #e0e7ff 100%);
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

        .events-section h2 {
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

        .filter-bar input[type="date"],
        .filter-bar select {
            padding: 8px;
            border: 1px solid #e0e7ff;
            border-radius: 5px;
            font-size: 14px;
        }

        .events-list {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding-right: 10px;
        }

        .events-list::-webkit-scrollbar {
            width: 8px;
        }

        .events-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .events-list::-webkit-scrollbar-thumb {
            background: #4f46e5;
            border-radius: 10px;
        }

        .events-list::-webkit-scrollbar-thumb:hover {
            background: #7c3aed;
        }

        .event-card {
            background: #fff;
            padding: 15px;
            border-radius: 15px;
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            gap: 5px;
            min-height: 120px;
        }

        .event-card p {
            font-size: 14px;
            color: #333;
            margin: 2px 0;
        }

        .event-card p strong {
            color: #1e3a8a;
        }

        .event-card .join-btn {
            background: #4f46e5;
            color: #fff;
            border: none;
            padding: 8px;
            border-radius: 5px;
            font-size: 14px;
            cursor: pointer;
            margin-top: 10px;
            transition: background 0.3s;
            text-align: center;
        }

        .event-card .join-btn:hover {
            background: #7c3aed;
        }

        .event-card .join-btn.disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        .event-status {
            font-weight: bold;
        }

        .status-upcoming {
            color: #10b981;
        }

        .status-ongoing {
            color: #f59e0b;
        }

        .status-past {
            color: #dc2626;
        }

        .no-data {
            text-align: center;
            color: #666;
            font-style: italic;
            font-size: 16px;
            margin-top: 20px;
        }

        .join-message {
            background: #d1fae5;
            color: #10b981;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 16px;
        }

        .join-error {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 16px;
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

            .events-nav {
                flex-direction: column;
                padding: 10px;
            }

            .events-nav a {
                padding: 10px;
                font-size: 14px;
                border-bottom: 1px solid #e0e7ff;
            }

            .events-nav a:last-child {
                border-bottom: none;
            }

            .events-nav a.active {
                background: #4f46e5;
                color: #fff;
            }

            .events-section {
                padding: 20px;
                height: 500px;
            }

            .events-section h2 {
                font-size: 24px;
            }

            .filter-bar {
                flex-direction: column;
                gap: 10px;
            }

            .filter-bar input[type="date"],
            .filter-bar select {
                width: 100%;
            }

            .event-card {
                padding: 10px;
                min-height: 100px;
            }

            .event-card p {
                font-size: 12px;
            }

            .event-card .join-btn {
                font-size: 12px;
                padding: 6px;
            }

            .no-data {
                font-size: 14px;
            }

            .join-message,
            .join-error {
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
            <?php if ($join_message): ?>
                <div class="<?php echo strpos($join_message, 'Success') !== false ? 'join-message' : 'join-error'; ?>">
                    <?php echo htmlspecialchars($join_message); ?>
                </div>
            <?php endif; ?>
            <div class="header">
                <h1>Events</h1>
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
            <div class="events-nav">
                <a href="?tab=upcoming" class="<?php echo $active_tab === 'upcoming' ? 'active' : ''; ?>">Upcoming Events</a>
                <a href="?tab=my_events" class="<?php echo $active_tab === 'my_events' ? 'active' : ''; ?>">My Events</a>
            </div>
            <div class="events-section">
                <h2>
                    <?php
                        if ($active_tab === 'upcoming') echo 'Upcoming Events';
                        else echo 'My Events';
                    ?>
                </h2>
                <div class="filter-bar">
                    <div>
                        <label for="filter_date">Date:</label>
                        <input type="date" id="filter_date" name="filter_date" value="<?php echo htmlspecialchars($filter_date); ?>">
                    </div>
                    <div>
                        <label for="filter_barangay">Barangay:</label>
                        <select id="filter_barangay" name="filter_barangay">
                            <option value="">All Barangays</option>
                            <?php foreach ($barangays as $barangay): ?>
                                <option value="<?php echo $barangay['barangay_id']; ?>" <?php echo $filter_barangay == $barangay['barangay_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($barangay['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="events-list">
                    <?php if ($active_tab === 'upcoming'): ?>
                        <?php if (empty($upcoming_events)): ?>
                            <p class="no-data">No upcoming events available.</p>
                        <?php else: ?>
                            <?php foreach ($upcoming_events as $event): ?>
                                <?php
                                    $event_date = new DateTime($event['event_date']);
                                    $today = new DateTime();
                                    $status = $event_date->format('Y-m-d') === $today->format('Y-m-d') ? 'ongoing' : ($event_date > $today ? 'upcoming' : 'past');
                                    $has_joined = in_array($event['event_id'], $joined_events);
                                ?>
                                <div class="event-card">
                                    <p><strong>Title:</strong> <?php echo htmlspecialchars($event['title']); ?></p>
                                    <p><strong>Date:</strong> <?php echo date('F j, Y', strtotime($event['event_date'])); ?></p>
                                    <p><strong>Location:</strong> <?php echo htmlspecialchars($event['location']); ?></p>
                                    <p><strong>Barangay:</strong> <?php echo htmlspecialchars($event['barangay_name'] ?? 'N/A'); ?></p>
                                    <p><strong>Description:</strong> <?php echo htmlspecialchars($event['description'] ?? 'No description available.'); ?></p>
                                    <p><strong>Status:</strong> <span class="event-status status-<?php echo $status; ?>"><?php echo ucfirst($status); ?></span></p>
                                    <?php if (!$has_joined && $status !== 'past'): ?>
                                        <form method="POST" action="">
                                            <input type="hidden" name="event_id" value="<?php echo $event['event_id']; ?>">
                                            <button type="submit" name="join_event" class="join-btn">Join Event</button>
                                        </form>
                                    <?php else: ?>
                                        <button class="join-btn disabled" disabled>
                                            <?php echo $has_joined ? 'Joined' : 'Event Ended'; ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (empty($my_events)): ?>
                            <p class="no-data">You haven’t joined any events yet.</p>
                        <?php else: ?>
                            <?php foreach ($my_events as $event): ?>
                                <?php
                                    $event_date = new DateTime($event['event_date']);
                                    $today = new DateTime();
                                    $status = $event_date->format('Y-m-d') === $today->format('Y-m-d') ? 'ongoing' : ($event_date > $today ? 'upcoming' : 'past');
                                ?>
                                <div class="event-card">
                                    <p><strong>Title:</strong> <?php echo htmlspecialchars($event['title']); ?></p>
                                    <p><strong>Date:</strong> <?php echo date('F j, Y', strtotime($event['event_date'])); ?></p>
                                    <p><strong>Location:</strong> <?php echo htmlspecialchars($event['location']); ?></p>
                                    <p><strong>Barangay:</strong> <?php echo htmlspecialchars($event['barangay_name'] ?? 'N/A'); ?></p>
                                    <p><strong>Description:</strong> <?php echo htmlspecialchars($event['description'] ?? 'No description available.'); ?></p>
                                    <p><strong>Status:</strong> <span class="event-status status-<?php echo $status; ?>"><?php echo ucfirst($status); ?></span></p>
                                    <p><strong>Joined At:</strong> <?php echo date('F j, Y, g:i A', strtotime($event['joined_at'])); ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="pagination">
                    <?php
                        $total_pages = $active_tab === 'upcoming' ? $upcoming_pages : $my_events_pages;
                        $prev_page = $page - 1;
                        $next_page = $page + 1;
                        $query_params = http_build_query([
                            'tab' => $active_tab,
                            'filter_date' => $filter_date,
                            'filter_barangay' => $filter_barangay
                        ]);
                    ?>
                    <a href="?<?php echo $query_params; ?>&page=1" class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">First</a>
                    <a href="?<?php echo $query_params; ?>&page=<?php echo $prev_page; ?>" class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">Prev</a>
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <a href="?<?php echo $query_params; ?>&page=<?php echo $i; ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <a href="?<?php echo $query_params; ?>&page=<?php echo $next_page; ?>" class="<?php echo $page >= $total_pages ? 'disabled' : ''; ?>">Next</a>
                    <a href="?<?php echo $query_params; ?>&page=<?php echo $total_pages; ?>" class="<?php echo $page >= $total_pages ? 'disabled' : ''; ?>">Last</a>
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

        // Filter functionality
        const filterDate = document.querySelector('#filter_date');
        const filterBarangay = document.querySelector('#filter_barangay');

        function applyFilters() {
            const params = new URLSearchParams(window.location.search);
            params.set('filter_date', filterDate.value);
            params.set('filter_barangay', filterBarangay.value);
            params.set('page', '1'); // Reset to first page on filter change
            window.location.search = params.toString();
        }

        filterDate.addEventListener('change', applyFilters);
        filterBarangay.addEventListener('change', applyFilters);
    </script>
</body>
</html>