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

    // Fetch profile picture (custom or default)
    if ($user['profile_picture']) {
        $profile_picture_data = 'data:image/jpeg;base64,' . base64_encode($user['profile_picture']);
    } elseif ($user['default_profile_asset_id']) {
        $stmt = $pdo->prepare("SELECT asset_data, asset_type FROM assets WHERE asset_id = :asset_id");
        $stmt->execute(['asset_id' => $user['default_profile_asset_id']]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($asset && $asset['asset_data']) {
            // Determine MIME type based on asset_type or file content
            $mime_type = 'image/jpeg'; // Default
            if ($asset['asset_type'] === 'default_profile') {
                // Since you confirmed it's PNG
                $mime_type = 'image/png';
            } else {
                // Optionally, detect MIME type dynamically
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

    // Fetch icon
    $stmt = $pdo->prepare("SELECT asset_data FROM assets WHERE asset_type = 'icon' LIMIT 1");
    $stmt->execute();
    $icon_data = $stmt->fetchColumn();
    $icon_base64 = $icon_data ? 'data:image/png;base64,' . base64_encode($icon_data) : '../assets/icon.png';

    // Fetch logo
    $stmt = $pdo->prepare("SELECT asset_data FROM assets WHERE asset_type = 'logo' LIMIT 1");
    $stmt->execute();
    $logo_data = $stmt->fetchColumn();
    $logo_base64 = $logo_data ? 'data:image/png;base64,' . base64_encode($logo_data) : 'logo.png';

    // Calculate CO2 Offset: 1 tree offsets ~22 kg CO2 per year
    $co2_offset = $user['trees_planted'] * 22; // in kg
    $user['co2_offset'] = $co2_offset;

    // Fetch the user's rank within their barangay
    $stmt = $pdo->prepare("
        SELECT user_rank
        FROM (
            SELECT user_id, 
                   RANK() OVER (ORDER BY trees_planted DESC) as user_rank
            FROM users
            WHERE barangay_id = :barangay_id
        ) ranked_users
        WHERE user_id = :user_id
    ");
    $stmt->execute([
        'barangay_id' => $user['barangay_id'],
        'user_id' => $user_id
    ]);
    $user_rank = $stmt->fetchColumn();
    $user_rank_display = $user_rank !== false ? $user_rank : "Not Ranked";

    // Fetch the 3 most recent upcoming events
    $stmt = $pdo->prepare("
        SELECT title, event_date, location 
        FROM events 
        WHERE event_date >= CURDATE() 
        ORDER BY event_date ASC 
        LIMIT 3
    ");
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="icon" type="image/png" sizes="64x64" href="<?php echo $icon_base64; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            color: #4CAF50;
            animation: bounce 0.3s ease-out;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
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
            margin-bottom: 40px;
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

        .header .notification-search .search-bar .search-icon {
            font-size: 16px;
            color: #666;
            margin-right: 5px;
        }

        .header .notification-search .search-bar input {
            border: none;
            outline: none;
            padding: 5px;
            width: 90%; /* Adjusted for icon space */
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

        .card {
            background: rgb(187, 235, 191);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .card .details {
            display: flex;
            justify-content: space-between;
            font-size: 18px;
            color: #333;
        }

        .card .details h2 {
            font-size: 28px;
            color: rgb(55, 122, 57);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
        }

        .stat-box {
            background: #E8F5E9;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            cursor: pointer;
            transition: transform 0.2s;
        }

        .stat-box:hover {
            transform: scale(1.02);
        }

        .stat-box h3 {
            font-size: 20px;
            margin-bottom: 15px;
            color: #4CAF50;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stat-box h3 .info-icon {
            font-size: 16px;
            color: #666;
            cursor: help;
            position: relative;
        }

        .stat-box h3 .info-icon:hover .tooltip {
            display: block;
        }

        .stat-box h3 .tooltip {
            position: absolute;
            top: -40px;
            left: -10px; /* Align left edge of tooltip with left edge of icon */
            background: #333;
            color: #fff;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            white-space: nowrap;
            display: none;
            z-index: 10;
        }

        .stat-box h3 .tooltip::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 15px; /* Position arrow closer to the left to align with icon */
            transform: translateX(-50%);
            border-width: 5px;
            border-style: solid;
            border-color: #333 transparent transparent transparent;
        }

        .stat-box canvas {
            max-height: 150px;
            width: 100%; /* Ensure canvas scales with container */
        }

        .stat-box .podium {
            width: 120px;
            height: 120px;
            margin: 0 auto;
            position: relative;
            display: none; /* Already hidden */
        }

        .stat-box .rank-icon {
            width: 120px;
            height: 120px;
            margin: 0 auto;
            position: relative;
            text-align: center;
        }

        .stat-box .rank-icon i {
            font-size: 80px;
            color: #FFD700; /* Gold color for trophy */
            transition: transform 0.3s;
        }

        .stat-box .rank-icon i:hover {
            transform: scale(1.1);
        }

        .stat-box .rank-icon .rank {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 20px;
            color: #4CAF50;
            font-weight: bold;
        }

        .stat-box ul {
            list-style: none;
        }

        .stat-box ul li {
            padding: 12px 0;
            border-bottom: 1px solid #e0e7ff;
            font-size: 15px;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stat-box ul li i {
            color: #4CAF50;
        }

        .download-btn {
            display: block;
            background: #4CAF50;
            color: #fff;
            text-align: center;
            padding: 15px;
            border-radius: 15px;
            text-decoration: none;
            margin-top: 27px;
            font-size: 20px;
            font-weight: bold;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .download-btn:hover {
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 10px 20px rgba(76, 175, 80, 0.4);
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

            .card {
                padding: 20px;
            }

            .card .details {
                flex-direction: flex;
                gap: 15px;
                font-size: 16px;
            }

            .card .details h2 {
                font-size: 24px;
            }

            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); /* Adjusted min width for better responsiveness */
                gap: 20px;
            }

            .stat-box {
                padding: 15px; /* Reduced padding for smaller screens */
            }

            .stat-box h3 {
                font-size: 16px; /* Reduced font size */
            }

            .stat-box canvas {
                max-height: 100px; /* Reduced height */
                width: 100%; /* Ensure canvas scales */
            }

            .stat-box .rank-icon {
                width: 80px; /* Reduced size */
                height: 80px;
            }

            .stat-box .rank-icon i {
                font-size: 50px; /* Reduced size */
            }

            .stat-box .rank-icon .rank {
                font-size: 16px; /* Reduced font size */
            }

            .stat-box ul {
                font-size: 14px; /* Reduced font size for list */
            }

            .stat-box ul li {
                font-size: 14px;
                padding: 8px 0;
                display: flex;
                flex-wrap: wrap; /* Allow wrapping of long event text */
                gap: 5px;
            }

            .download-btn {
                padding: 10px;
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
                <h1>Green Roots</h1>
                <div class="notification-search">
                    <div class="notification"><i class="fas fa-bell"></i></div>
                    <div class="search-bar">
                        <i class="fas fa-search search-icon"></i>
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
                    <h3>
                        CO₂ Offset
                        <span class="info-icon">
                            <i class="fas fa-info-circle"></i>
                            <span class="tooltip">CO₂ Offset = The amount of pollution your trees have helped remove from the air!</span>
                        </span>
                    </h3>
                    <canvas id="co2Chart"></canvas>
                </div>
                <div class="stat-box" onclick="window.location.href='leaderboard.php'">
                    <h3>Your Rank in Barangay</h3>
                    <div class="rank-icon">
                        <i class="fas fa-trophy"></i>
                        <div class="rank"><?php echo $user_rank_display; ?></div>
                    </div>
                </div>
                <div class="stat-box" onclick="window.location.href='events.php'">
                    <h3>Upcoming Events</h3>
                    <ul>
                        <?php foreach ($events as $event): ?>
                            <li>
                                <i class="fas fa-calendar-check"></i>
                                <?php echo htmlspecialchars($event['title']); ?> - 
                                <?php echo date('M d', strtotime($event['event_date'])); ?> at 
                                <?php echo htmlspecialchars($event['location']); ?>
                            </li>
                        <?php endforeach; ?>
                        <?php if (empty($events)): ?>
                            <li>No upcoming events.</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            <a href="history.php" class="download-btn">View Submission History</a>
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

        // CO2 Offset Chart
        const co2Offset = <?php echo $co2_offset; ?>;
        const monthlyData = Array(12).fill(0).map((_, i) => co2Offset * (i + 1) / 12);
        const ctx = document.getElementById('co2Chart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                    label: 'CO₂ Offset (kg)',
                    data: monthlyData,
                    borderColor: '#4CAF50',
                    backgroundColor: 'rgba(76, 175, 80, 0.2)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
    </script>
</body>
</html>