<?php
// Start session and handle PHP logic first
session_start();
require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

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

    // Fetch barangay ranking
    $stmt = $pdo->prepare("
        SELECT r.rank_position 
        FROM rankings r
        JOIN barangays b ON r.barangay_id = b.barangay_id
        WHERE b.barangay_id = :barangay_id
    ");
    $stmt->execute(['barangay_id' => $user['barangay_id']]);
    $ranking = $stmt->fetchColumn() ?: 'N/A';

    // Fetch upcoming events
    $stmt = $pdo->prepare("
        SELECT title, event_date, location 
        FROM events 
        WHERE event_date >= CURDATE() 
        ORDER BY event_date ASC 
        LIMIT 3
    ");
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch recent activities
    $stmt = $pdo->prepare("
        SELECT description, activity_type, created_at 
        FROM activities 
        WHERE user_id = :user_id 
        ORDER BY created_at DESC 
        LIMIT 3
    ");
    $stmt->execute(['user_id' => $user_id]);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle profile update
    $profile_error = '';
    $profile_success = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
        $new_username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
        $new_email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);

        // Validate inputs
        if (empty($new_username) || empty($new_email)) {
            $profile_error = 'Please fill in all fields.';
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $profile_error = 'Invalid email format.';
        } else {
            // Check if username or email is already taken by another user
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM users 
                WHERE (username = :username OR email = :email) 
                AND user_id != :user_id
            ");
            $stmt->execute([
                'username' => $new_username,
                'email' => $new_email,
                'user_id' => $user_id
            ]);
            if ($stmt->fetchColumn() > 0) {
                $profile_error = 'Username or email already taken.';
            } else {
                // Update user information
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET username = :username, email = :email 
                    WHERE user_id = :user_id
                ");
                $stmt->execute([
                    'username' => $new_username,
                    'email' => $new_email,
                    'user_id' => $user_id
                ]);

                // Update session username
                $_SESSION['username'] = $new_username;
                $username = $new_username;
                $user['email'] = $new_email;

                $profile_success = 'Profile updated successfully!';
            }
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
    <title>Dashboard - Tree Planting Initiative</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #e0e7ff, #f5f7fa);
            color: #333;
        }

        .container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 80px;
            background: #fff;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }

        .sidebar img.logo {
            width: 40px;
            margin-bottom: 20px;
        }

        .sidebar a {
            margin: 15px 0;
            color: #666;
            text-decoration: none;
            font-size: 24px;
        }

        .sidebar a:hover {
            color: #4f46e5;
        }

        .main-content {
            flex: 1;
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            position: relative;
        }

        .header h1 {
            font-size: 28px;
            color: #1e3a8a;
        }

        .header .search-bar {
            display: flex;
            align-items: center;
            background: #fff;
            padding: 5px 10px;
            border-radius: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            position: relative;
        }

        .header .search-bar input {
            border: none;
            outline: none;
            padding: 5px;
            width: 200px;
        }

        .header .search-bar .search-results {
            position: absolute;
            top: 40px;
            left: 0;
            background: #fff;
            width: 100%;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            display: none;
            z-index: 10;
        }

        .header .search-bar .search-results.active {
            display: block;
        }

        .header .search-bar .search-results a {
            display: block;
            padding: 10px;
            color: #333;
            text-decoration: none;
            border-bottom: 1px solid #e0e7ff;
        }

        .header .search-bar .search-results a:hover {
            background: #e0e7ff;
        }

        .header .profile {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }

        .header .profile:hover {
            opacity: 0.8;
        }

        .header .profile img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 100;
        }

        .modal.active {
            display: block;
        }

        .modal-content {
            background: #fff;
            padding: 20px;
            border-radius: 15px;
            width: 90%;
            max-width: 400px;
            margin: 100px auto;
            position: relative;
        }

        .modal-content .close-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 24px;
            cursor: pointer;
            color: #666;
        }

        .modal-content h2 {
            font-size: 24px;
            color: #1e3a8a;
            margin-bottom: 20px;
        }

        .modal-content .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            display: none;
        }

        .modal-content .success {
            background: #d1fae5;
            color: #10b981;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            display: none;
        }

        .modal-content .error.show,
        .modal-content .success.show {
            display: block;
        }

        .modal-content .form-group {
            margin-bottom: 20px;
        }

        .modal-content label {
            display: block;
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }

        .modal-content input {
            width: 100%;
            padding: 10px;
            border: 1px solid #e0e7ff;
            border-radius: 5px;
            font-size: 16px;
            outline: none;
        }

        .modal-content input:focus {
            border-color: #4f46e5;
        }

        .modal-content input[type="submit"] {
            background: #4f46e5;
            color: #fff;
            border: none;
            cursor: pointer;
            transition: background 0.3s;
        }

        .modal-content input[type="submit"]:hover {
            background: #7c3aed;
        }

        .card {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: #fff;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            margin-bottom: 20px;
        }

        .card .details {
            display: flex;
            justify-content: space-between;
            font-size: 16px;
        }

        .card .details h2 {
            font-size: 24px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .stat-box {
            background: #fff;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-box h3 {
            font-size: 18px;
            margin-bottom: 10px;
            color: #1e3a8a;
        }

        .stat-box .chart {
            height: 100px;
            background: #e0e7ff;
            border-radius: 10px;
        }

        .stat-box .podium {
            width: 100px;
            height: 100px;
            margin: 0 auto;
            position: relative;
        }

        .stat-box .podium .steps {
            display: flex;
            justify-content: center;
            align-items: flex-end;
            height: 70px;
        }

        .stat-box .podium .step {
            background: #e0e7ff;
            border-radius: 5px;
        }

        .stat-box .podium .step.left {
            width: 30px;
            height: 40px;
        }

        .stat-box .podium .step.center {
            width: 40px;
            height: 70px;
            background: #d1d5db;
        }

        .stat-box .podium .step.right {
            width: 30px;
            height: 50px;
        }

        .stat-box .podium .rank {
            position: absolute;
            top: 30px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 18px;
            color: #1e3a8a;
            font-weight: bold;
        }

        .stat-box ul {
            list-style: none;
        }

        .stat-box ul li {
            padding: 10px 0;
            border-bottom: 1px solid #e0e7ff;
        }

        .recent-activities ul li {
            display: flex;
            justify-content: space-between;
        }

        .download-btn {
            display: block;
            background: #4f46e5;
            color: #fff;
            text-align: center;
            padding: 10px;
            border-radius: 10px;
            text-decoration: none;
            margin-top: 20px;
        }

        .download-btn:hover {
            background: #7c3aed;
        }

        .error-message {
            background: #fee2e2;
            color: #dc2626;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
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
                box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            }

            .sidebar img.logo {
                display: none;
            }

            .main-content {
                padding-bottom: 80px;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
            }

            .header .search-bar {
                width: 100%;
            }

            .header .search-bar input {
                width: 100%;
            }

            .header .profile {
                margin-top: 10px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <img src="logo.png" alt="Logo" class="logo">
            <a href="dashboard.php" title="Dashboard">🏠</a>
            <a href="submit.php" title="Submit Planting">🌳</a>
            <a href="leaderboard.php" title="Leaderboard">📊</a>
            <a href="rewards.php" title="Rewards">🎁</a>
            <a href="events.php" title="Events">📅</a>
            <a href="logout.php" title="Logout">🚪</a>
        </div>
        <div class="main-content">
            <?php if (isset($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <div class="header">
                <h1>Tree Planting Dashboard</h1>
                <div class="search-bar">
                    <input type="text" placeholder="Search functionalities..." id="searchInput">
                    <div class="search-results" id="searchResults"></div>
                </div>
                <div class="profile" id="profileBtn">
                    <span><?php echo htmlspecialchars($username); ?></span>
                    <img src="profile.jpg" alt="Profile">
                </div>
            </div>
            <div class="card">
                <div class="details">
                    <div>
                        <p>Trees Planted</p>
                        <h2><?php echo $user['trees_planted']; ?></h2>
                    </div>
                    <div>
                        <p>Eco Points</p>
                        <h2><?php echo $user['eco_points']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="stats-grid">
                <div class="stat-box">
                    <h3>CO₂ Offset</h3>
                    <p><?php echo $user['co2_offset']; ?> kg</p>
                    <div class="chart"></div>
                </div>
                <div class="stat-box">
                    <h3>Barangay Ranking</h3>
                    <div class="podium">
                        <div class="steps">
                            <div class="step left"></div>
                            <div class="step center"></div>
                            <div class="step right"></div>
                        </div>
                        <div class="rank">#<?php echo $ranking; ?></div>
                    </div>
                </div>
                <div class="stat-box">
                    <h3>Upcoming Events</h3>
                    <ul>
                        <?php foreach ($events as $event): ?>
                            <li>
                                <?php echo htmlspecialchars($event['title']); ?> - 
                                <?php echo date('M d', strtotime($event['event_date'])); ?> at 
                                <?php echo htmlspecialchars($event['location']); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="stat-box recent-activities">
                    <h3>Recent Activities</h3>
                    <ul>
                        <?php foreach ($activities as $activity): ?>
                            <li>
                                <span><?php echo htmlspecialchars($activity['description']); ?></span>
                                <span><?php echo date('M d', strtotime($activity['created_at'])); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <a href="history.php" class="download-btn">View Submission History</a>
        </div>
    </div>

    <!-- Profile Edit Modal -->
    <div class="modal" id="profileModal">
        <div class="modal-content">
            <span class="close-btn" id="closeModal">&times;</span>
            <h2>Edit Profile</h2>
            <?php if ($profile_error): ?>
                <div class="error show"><?php echo htmlspecialchars($profile_error); ?></div>
            <?php endif; ?>
            <?php if ($profile_success): ?>
                <div class="success show"><?php echo htmlspecialchars($profile_success); ?></div>
            <?php endif; ?>
            <form method="POST" action="">
                <input type="hidden" name="update_profile" value="1">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                </div>
                <input type="submit" value="Update Profile">
            </form>
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
            { name: 'Submission History', url: 'history.php' },
            { name: 'Logout', url: 'logout.php' }
        ];

        const searchInput = document.querySelector('#searchInput');
        const searchResults = document.querySelector('#searchResults');

        searchInput.addEventListener('input', function(e) {
            const query = e.target.value.toLowerCase();
            searchResults.innerHTML = '';
            searchResults.classList.remove('active');

            if (query) {
                const matches = functionalities.filter(func => 
                    func.name.toLowerCase().includes(query)
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

        // Profile edit modal functionality
        const profileBtn = document.querySelector('#profileBtn');
        const profileModal = document.querySelector('#profileModal');
        const closeModal = document.querySelector('#closeModal');

        profileBtn.addEventListener('click', function() {
            profileModal.classList.add('active');
        });

        closeModal.addEventListener('click', function() {
            profileModal.classList.remove('active');
        });

        // Close modal when clicking outside
        profileModal.addEventListener('click', function(e) {
            if (e.target === profileModal) {
                profileModal.classList.remove('active');
            }
        });
    </script>
</body>
</html>