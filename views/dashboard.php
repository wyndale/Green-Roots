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
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.email, u.profile_picture, u.default_profile_asset_id, u.role, u.barangay_id, u.trees_planted, u.eco_points, u.first_name,
               b.name as barangay_name, b.city as city_name, b.province as province_name, b.region as region_name
        FROM users u 
        LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
        WHERE u.user_id = :user_id
    ");
    $stmt->execute(['user_id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header('Location: login.php');
        exit;
    }

    // Ensure only regular users can access this page
    if ($user['role'] !== 'user') {
        header('Location: ../access/access_denied.php');
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

    // Fetch the 3 most recent upcoming events in the user's region
    $stmt = $pdo->prepare("
        SELECT e.title, e.event_date, e.location 
        FROM events e
        LEFT JOIN barangays b ON e.barangay_id = b.barangay_id
        WHERE e.event_date >= CURDATE() 
        AND b.region = :region
        ORDER BY e.event_date ASC 
        LIMIT 3
    ");
    $stmt->execute(['region' => $user['region_name']]);
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
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                            <span class="tooltip">CO₂ Offset = The amount of pollution your trees have helped remove
                                from the air!</span>
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
                        <?php if (empty($events)): ?>
                            <li>No upcoming events in your region
                                <?php echo htmlspecialchars($user['region_name'] ?? 'N/A'); ?></li>
                        <?php else: ?>
                            <?php foreach ($events as $event): ?>
                                <li>
                                    <i class="fas fa-calendar-check"></i>
                                    <?php echo htmlspecialchars($event['title']); ?> -
                                    <?php echo date('M d', strtotime($event['event_date'])); ?> at
                                    <?php echo htmlspecialchars($event['location']); ?>
                                </li>
                            <?php endforeach; ?>
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

        searchInput.addEventListener('input', function (e) {
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

        document.addEventListener('click', function (e) {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.classList.remove('active');
            }
        });

        // Profile dropdown functionality
        const profileBtn = document.querySelector('#profileBtn');
        const profileDropdown = document.querySelector('#profileDropdown');

        profileBtn.addEventListener('click', function () {
            profileDropdown.classList.toggle('active');
        });

        document.addEventListener('click', function (e) {
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