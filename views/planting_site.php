<?php
session_start();
require_once '../includes/config.php';

// Session timeout (30 minutes)
$timeout_duration = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['last_activity'] = time();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Function to fetch planting site for a barangay
function getPlantingSite($pdo, $barangay_id) {
    $stmt = $pdo->prepare("
        SELECT planting_site_id, latitude, longitude 
        FROM planting_sites 
        WHERE barangay_id = :barangay_id 
        ORDER BY updated_at DESC 
        LIMIT 1
    ");
    $stmt->execute(['barangay_id' => $barangay_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

try {
    // Fetch user data
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header('Location: login.php');
        exit;
    }

    $barangay_id = $user['barangay_id'];

    // Ensure only regular users can access this page
    if ($user['role'] !== 'user') {
        header('Location: ' . ($user['role'] === 'admin' ? 'admin_dashboard.php' : 'validator_dashboard.php'));
        exit;
    }

    // Fetch profile picture
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

    // Fetch planting site
    $planting_site = getPlantingSite($pdo, $barangay_id);

    if (!$planting_site) {
        $error_message = 'No planting site designated for your barangay. Please contact an admin.';
    }

} catch (PDOException $e) {
    $error_message = "Error: " . $e->getMessage();
    error_log("Database error in planting_site.php: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Designated Planting Site - Green Roots</title>
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

        .planting-site-container {
            background: linear-gradient(135deg, #E8F5E9, #C8E6C9);
            padding: 60px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            width: 100%;
            max-width: 600px;
            text-align: center;
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .planting-site-container h2 {
            font-size: 28px;
            color: #2E7D32;
            margin-bottom: 25px;
        }

        .planting-site-container .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 16px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .planting-site-details {
            background: #fff;
            padding: 25px;
            border-radius: 10px;
            font-size: 16px;
            color: #666;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .planting-site-details p {
            margin-bottom: 15px;
        }

        .planting-site-details a {
            color: #4CAF50;
            font-weight: bold;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 5px;
            transition: transform 0.3s, background-color 0.3s;
            display: inline-block;
            background: rgba(76, 175, 80, 0.1);
        }

        .planting-site-details a:hover {
            transform: translateY(-5px) scale(1.05);
            background-color: rgba(76, 175, 80, 0.3);
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
            max-width: 600px;
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

            .planting-site-container {
                padding: 20px;
            }

            .planting-site-container h2 {
                font-size: 24px;
            }

            .planting-site-details {
                font-size: 14px;
                padding: 20px;
            }

            .planting-site-details a {
                padding: 8px 15px;
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

            .planting-site-container {
                padding: 15px;
            }

            .planting-site-container h2 {
                font-size: 20px;
            }

            .planting-site-details {
                font-size: 12px;
                padding: 15px;
            }

            .planting-site-details a {
                padding: 6px 12px;
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
            <a href="history.php" title="History"><i class="fas fa-clock"></i></a>
            <a href="feedback.php" title="Feedback"><i class="fas fa-comment"></i></a>
        </div>
        <div class="main-content">
            <div class="header">
                <h1>Designated Planting Site</h1>
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
            <div class="planting-site-container">
                <h2>Your Designated Planting Site</h2>
                <?php if (isset($error_message)): ?>
                    <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
                <?php elseif ($planting_site): ?>
                    <div class="planting-site-details">
                        <p><strong>Latitude:</strong> <?php echo htmlspecialchars($planting_site['latitude']); ?></p>
                        <p><strong>Longitude:</strong> <?php echo htmlspecialchars($planting_site['longitude']); ?></p>
                        <p>
                            <a href="https://www.openstreetmap.org/?mlat=<?php echo htmlspecialchars($planting_site['latitude']); ?>&mlon=<?php echo htmlspecialchars($planting_site['longitude']); ?>#map=15/<?php echo htmlspecialchars($planting_site['latitude']); ?>/<?php echo htmlspecialchars($planting_site['longitude']); ?>" target="_blank">
                                View on Open Street Map
                            </a>
                        </p>
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
    </script>
</body>
</html>