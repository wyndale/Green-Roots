<?php
// Start session and handle PHP logic first
session_start();
require_once '../includes/config.php';

// Restrict access to eco_validator role only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'eco_validator') {
    if (isset($_SESSION['user_id']) && ($_SESSION['role'] === 'user' || $_SESSION['role'] === 'admin')) {
        header('Location: ../access/access_denied.php');
        exit;
    }
    header('Location: ../views/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
try {
    // Fetch validator data
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.email, u.profile_picture, u.default_profile_asset_id, u.barangay_id, u.first_name,
               b.name as barangay_name, b.city as city_name, b.province as province_name, b.region as region_name
        FROM users u 
        LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
        WHERE u.user_id = :user_id
    ");
    $stmt->execute(['user_id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header('Location: ../views/login.php');
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
            $mime_type = 'image/jpeg';
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

    // Fetch stats
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM users WHERE barangay_id = :barangay_id AND role = 'user') as user_count,
            (SELECT COUNT(*) FROM submissions WHERE barangay_id = :barangay_id AND status = 'pending') as pending_count,
            (SELECT COUNT(*) FROM submissions WHERE barangay_id = :barangay_id AND status = 'approved') as approved_count,
            (SELECT COUNT(*) FROM submissions WHERE barangay_id = :barangay_id AND flagged = 1) as flagged_count
    ");
    $stmt->execute(['barangay_id' => $user['barangay_id']]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch the most recent submission
    $stmt = $pdo->prepare("
        SELECT s.submission_id, s.user_id, s.trees_planted, s.photo_data, s.latitude, s.longitude, s.submitted_at, s.status, u.first_name
        FROM submissions s
        JOIN users u ON s.user_id = u.user_id
        WHERE s.barangay_id = :barangay_id
        ORDER BY s.submitted_at DESC
        LIMIT 1
    ");
    $stmt->execute(['barangay_id' => $user['barangay_id']]);
    $recent_submission = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validator Dashboard</title>
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
            transition: transform 0.5s;
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
            left: -10px;
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
            left: 15px;
            transform: translateX(-50%);
            border-width: 5px;
            border-style: solid;
            border-color: #333 transparent transparent transparent;
        }

        .stat-box ul {
            list-style: none;
        }

        .stat-box ul li {
            padding: 12px 0;
            border-bottom: 1px solid #e0e7ff;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stat-box ul li i {
            color: #4CAF50;
        }

        .submission-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            margin-top: 20px;
        }

        .submission-table th,
        .submission-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e0e7ff;
        }

        .submission-table th {
            background: #4CAF50;
            color: #fff;
            font-weight: bold;
        }

        .submission-table tr:hover {
            background: #f5f7fa;
        }

        .submission-table img {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 5px;
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
            }

            .header h1 {
                font-size: 24px;
            }

            .header .notification-search {
                flex-direction: row;
                gap: 10px;
                width: auto;
                flex-grow: 1;
            }

            .header .notification-search .search-bar {
                width: 100%;
                max-width: 200px;
                padding: 5px 10px;
            }

            .header .notification-search .search-bar input {
                font-size: 14px;
            }

            .header .profile img {
                width: 40px;
                height: 40px;
            }

            .header .profile span {
                display: none;
            }

            .profile-dropdown {
                top: 50px;
                width: 200px;
            }

            .card {
                padding: 20px;
            }

            .card .details {
                flex-direction: column;
                gap: 15px;
                font-size: 16px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .submission-table {
                font-size: 14px;
            }

            .submission-table th,
            .submission-table td {
                padding: 10px;
            }

            .submission-table img {
                width: 40px;
                height: 40px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <img src="<?php echo $logo_base64; ?>" alt="Logo" class="logo">
            <a href="validator_dashboard.php" title="Dashboard"><i class="fas fa-home"></i></a>
            <a href="pending_reviews.php" title="Pending Reviews"><i class="fas fa-tasks"></i></a>
            <a href="reviewed_submissions.php" title="Reviewed Submissions"><i class="fas fa-check-circle"></i></a>
            <a href="barangay_designated_site.php" title="Barangay Map"><i class="fas fa-map-marker-alt"></i></a>
        </div>
        <div class="main-content">
            <?php if (isset($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <div class="header">
                <h1>Validator Dashboard</h1>
                <div class="notification-search">
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
                        <a href="../views/logout.php">Logout</a>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="details">
                    <div>
                        <p>Assigned Barangay</p>
                        <h2><?php echo htmlspecialchars($user['barangay_name']); ?></h2>
                    </div>
                    <div>
                        <p>Users Assigned</p>
                        <h2><?php echo $stats['user_count']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="stats-grid">
                <div class="stat-box" onclick="window.location.href='pending_reviews.php'">
                    <h3>Pending Submissions</h3>
                    <p style="font-size: 24px; color: #FF9800;"><?php echo $stats['pending_count']; ?></p>
                </div>
                <div class="stat-box" onclick="window.location.href='reviewed_submissions.php?status=approved'">
                    <h3>Approved Submissions</h3>
                    <p style="font-size: 24px; color: #4CAF50;"><?php echo $stats['approved_count']; ?></p>
                </div>
                <div class="stat-box" onclick="window.location.href='reviewed_submissions.php?status=flagged'">
                    <h3>Flagged Submissions</h3>
                    <p style="font-size: 24px; color: #F44336;"><?php echo $stats['flagged_count']; ?></p>
                </div>
            </div>
            <?php if ($recent_submission): ?>
                <div class="stat-box" onclick="window.location.href='pending_reviews.php?id=<?php echo $recent_submission['submission_id']; ?>'">
                    <h3>Most Recent Submission</h3>
                    <table class="submission-table">
                        <thead>
                            <tr>
                                <th>Submission ID</th>
                                <th>User</th>
                                <th>Trees Planted</th>
                                <th>Photo</th>
                                <th>Latitude</th>
                                <th>Longitude</th>
                                <th>Submitted At</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?php echo htmlspecialchars($recent_submission['submission_id']); ?></td>
                                <td><?php echo htmlspecialchars($recent_submission['first_name']); ?></td>
                                <td><?php echo htmlspecialchars($recent_submission['trees_planted']); ?></td>
                                <td>
                                    <?php if ($recent_submission['photo_data']): ?>
                                        <img src="data:image/jpeg;base64,<?php echo base64_encode($recent_submission['photo_data']); ?>" alt="Submission Photo">
                                    <?php else: ?>
                                        No Photo
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($recent_submission['latitude'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($recent_submission['longitude'] ?? 'N/A'); ?></td>
                                <td><?php echo date('M d, Y H:i', strtotime($recent_submission['submitted_at'])); ?></td>
                                <td><?php echo ucfirst(htmlspecialchars($recent_submission['status'])); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="stat-box">
                    <h3>Most Recent Submission</h3>
                    <p>No recent submissions available.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Search bar functionality
        const functionalities = [
            { name: 'Dashboard', url: 'validator_dashboard.php' },
            { name: 'Pending Reviews', url: 'pending_reviews.php' },
            { name: 'Reviewed Submissions', url: 'reviewed_submissions.php' },
            { name: 'Barangay Map', url: 'barangay_map.php' },
            { name: 'Logout', url: '../views/logout.php' }
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