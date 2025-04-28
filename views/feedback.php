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
    $profile_picture_data = $user['profile_picture'] ? 'data:image/jpeg;base64,' . base64_encode($user['profile_picture']) : 'profile.jpg';

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

                $feedback_success = 'Feedback submitted successfully! Thank you for your input.';
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
    <title>Feedback - Tree Planting Initiative</title>
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

        .feedback-nav {
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

        .feedback-nav a {
            color: #666;
            text-decoration: none;
            font-size: 16px;
            padding: 10px 20px;
            transition: color 0.3s, background 0.3s;
            border-radius: 10px;
        }

        .feedback-nav a.active {
            color: #fff;
            background: #4f46e5;
        }

        .feedback-nav a:hover {
            color: #4f46e5;
        }

        .feedback-section {
            background: linear-gradient(135deg, #ffffff 0%, #f5f7fa 100%);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 800px;
            margin-bottom: 30px;
        }

        .feedback-section h2 {
            font-size: 28px;
            color: #1e3a8a;
            margin-bottom: 25px;
        }

        .feedback-section .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            display: none;
            font-size: 16px;
        }

        .feedback-section .success {
            background: #d1fae5;
            color: #10b981;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            display: none;
            font-size: 16px;
        }

        .feedback-section .error.show,
        .feedback-section .success.show {
            display: block;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 16px;
            color: #666;
            margin-bottom: 8px;
        }

        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #e0e7ff;
            border-radius: 5px;
            font-size: 16px;
            outline: none;
        }

        .form-group textarea {
            height: 150px;
            resize: vertical;
        }

        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #4f46e5;
        }

        .rating-group {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .rating-group label {
            font-size: 16px;
            color: #666;
            margin-right: 10px;
        }

        .rating-group .stars {
            display: flex;
            gap: 5px;
        }

        .rating-group .stars input {
            display: none;
        }

        .rating-group .stars label {
            font-size: 24px;
            color: #ccc;
            cursor: pointer;
            transition: color 0.3s;
        }

        .rating-group .stars input:checked ~ label,
        .rating-group .stars label:hover,
        .rating-group .stars label:hover ~ label {
            color: #f59e0b;
        }

        .form-group.checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group.checkbox input {
            width: auto;
        }

        .feedback-section input[type="submit"] {
            background: #4f46e5;
            color: #fff;
            border: none;
            padding: 12px;
            border-radius: 5px;
            font-size: 16px;
            width: 100%;
            cursor: pointer;
            transition: background 0.3s;
        }

        .feedback-section input[type="submit"]:hover {
            background: #7c3aed;
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

        .feedback-card {
            background: #fff;
            padding: 15px;
            border-radius: 15px;
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            gap: 5px;
            min-height: 120px;
        }

        .feedback-card p {
            font-size: 14px;
            color: #333;
            margin: 2px 0;
        }

        .feedback-card p strong {
            color: #1e3a8a;
        }

        .status-submitted {
            color: #f59e0b;
            font-weight: bold;
        }

        .status-under_review {
            color: #3b82f6;
            font-weight: bold;
        }

        .status-resolved {
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

            .feedback-nav {
                flex-direction: column;
                padding: 10px;
            }

            .feedback-nav a {
                padding: 10px;
                font-size: 14px;
                border-bottom: 1px solid #e0e7ff;
            }

            .feedback-nav a:last-child {
                border-bottom: none;
            }

            .feedback-nav a.active {
                background: #4f46e5;
                color: #fff;
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
                padding: 8px;
            }

            .rating-group .stars label {
                font-size: 20px;
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
                min-height: 100px;
            }

            .feedback-card p {
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
                <a href="?tab=submit" class="<?php echo $active_tab === 'submit' ? 'active' : ''; ?>">Submit Feedback</a>
                <a href="?tab=history" class="<?php echo $active_tab === 'history' ? 'active' : ''; ?>">Feedback History</a>
            </div>
            <div class="feedback-section <?php echo $active_tab === 'history' ? 'history-section' : ''; ?>">
                <?php if ($active_tab === 'submit'): ?>
                    <h2>Submit Feedback</h2>
                    <?php if ($feedback_error): ?>
                        <div class="error show"><?php echo htmlspecialchars($feedback_error); ?></div>
                    <?php endif; ?>
                    <?php if ($feedback_success): ?>
                        <div class="success show"><?php echo htmlspecialchars($feedback_success); ?></div>
                    <?php endif; ?>
                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="category">Category</label>
                            <select id="category" name="category" required>
                                <option value="">Select a category</option>
                                <option value="bug">Bug Report</option>
                                <option value="feature">Feature Request</option>
                                <option value="general">General Feedback</option>
                            </select>
                        </div>
                        <div class="rating-group">
                            <label>Rating</label>
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
                        </div>
                        <div class="form-group">
                            <label for="comments">Comments</label>
                            <textarea id="comments" name="comments" required maxlength="1000"></textarea>
                        </div>
                        <div class="form-group checkbox">
                            <input type="checkbox" id="is_anonymous" name="is_anonymous">
                            <label for="is_anonymous">Submit anonymously</label>
                        </div>
                        <input type="submit" value="Submit Feedback">
                    </form>
                <?php else: ?>
                    <h2>Feedback History</h2>
                    <div class="history-list">
                        <?php if (empty($feedback_history)): ?>
                            <p class="no-data">No feedback history available.</p>
                        <?php else: ?>
                            <?php foreach ($feedback_history as $entry): ?>
                                <div class="feedback-card">
                                    <p><strong>Date:</strong> <?php echo date('F j, Y, g:i A', strtotime($entry['submitted_at'])); ?></p>
                                    <p><strong>Category:</strong> <?php echo ucfirst($entry['category']); ?></p>
                                    <p><strong>Rating:</strong> <?php echo str_repeat('★', $entry['rating']) . str_repeat('☆', 5 - $entry['rating']); ?></p>
                                    <p><strong>Comments:</strong> <?php echo htmlspecialchars($entry['comments']); ?></p>
                                    <p><strong>Anonymous:</strong> <?php echo $entry['is_anonymous'] ? 'Yes' : 'No'; ?></p>
                                    <p><strong>Status:</strong> <span class="status-<?php echo str_replace('_', '-', $entry['status']); ?>"><?php echo ucfirst(str_replace('_', ' ', $entry['status'])); ?></span></p>
                                    <?php if ($entry['response']): ?>
                                        <p><strong>Response:</strong> <?php echo htmlspecialchars($entry['response']); ?></p>
                                    <?php endif; ?>
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
    </script>
</body>
</html>