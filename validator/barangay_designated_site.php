<?php
// Start session and handle PHP logic first
session_start();
require_once '../includes/config.php';

// // Session timeout (30 minutes)
// $timeout_duration = 1800;
// if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
//     session_unset();
//     session_destroy();
//     header('Location: ../views/login.php?timeout=1');
//     exit;
// }
// $_SESSION['last_activity'] = time();

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
$username = $_SESSION['username'];

// Function to fetch planting site for a barangay
function getPlantingSite($pdo, $barangay_id) {
    $stmt = $pdo->prepare("
        SELECT planting_site_id, latitude, longitude, updated_at 
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
        header('Location: ../views/login.php');
        exit;
    }

    $barangay_id = $user['barangay_id'];

    // Ensure only validators can access this page
    if ($user['role'] !== 'eco_validator') {
        header('Location: ' . ($user['role'] === 'admin' ? 'admin_dashboard.php' : 'dashboard.php'));
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
            $profile_picture_data = '../assets/default_profile.jpg';
        }
    } else {
        $profile_picture_data = '../assets/default_profile.jpg';
    }

    // Fetch favicon and logo
    $stmt = $pdo->prepare("SELECT asset_data FROM assets WHERE asset_type = 'favicon' LIMIT 1");
    $stmt->execute();
    $favicon_data = $stmt->fetchColumn();
    $favicon_base64 = $favicon_data ? 'data:image/png;base64,' . base64_encode($favicon_data) : '../assets/favicon.png';

    $stmt = $pdo->prepare("SELECT asset_data FROM assets WHERE asset_type = 'logo' LIMIT 1");
    $stmt->execute();
    $logo_data = $stmt->fetchColumn();
    $logo_base64 = $logo_data ? 'data:image/png;base64,' . base64_encode($logo_data) : '../assets/logo.png';

    // Fetch barangay name
    $stmt = $pdo->prepare("SELECT name FROM barangays WHERE barangay_id = :barangay_id LIMIT 1");
    $stmt->execute(['barangay_id' => $barangay_id]);
    $barangay = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch planting site
    $planting_site = getPlantingSite($pdo, $barangay_id);

    if (!$planting_site) {
        $error_message = 'No planting site designated for your barangay. Please contact an admin.';
    }

    $latitude = $planting_site['latitude'] ?? 8.461315; // Default to screenshot value if null
    $longitude = $planting_site['longitude'] ?? 124.786497; // Default to screenshot value if null

} catch (PDOException $e) {
    $error_message = "Error: " . $e->getMessage();
    error_log("Database error in barangay_designated_site.php: " . $e->getMessage());
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
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
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
            line-height: 1.6;
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

        .planting-site-card {
            background: linear-gradient(135deg, #e6f3e6 0%, #d4e9d4 100%);
            padding: 30px;
            border-radius: 20px;
            border: 1px solid #d0d9d0;
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.08);
            text-align: left;
            width: 100%;
            max-width: 600px;
            margin-top: 20px;
            animation: fadeIn 0.5s ease-in-out;
        }

        @keyframes fadeIn {
            0% { opacity: 0; transform: translateY(10px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        .planting-site-card h2 {
            font-size: 26px;
            font-weight: 600;
            color: #388E3C;
            margin-bottom: 25px;
            text-align: center;
            letter-spacing: 0.5px;
        }

        .planting-site-card p {
            display: flex;
            justify-content: space-between;
            font-size: 16px;
            color: #2d2d2d;
            margin: 12px 0;
            padding: 8px 12px;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 8px;
            transition: background 0.3s ease;
        }

        .planting-site-card p:hover {
            background: rgba(255, 255, 255, 0.9);
        }

        .planting-site-card p strong {
            font-weight: 600;
            color: #4CAF50;
            width: 40%;
        }

        .planting-site-card p span {
            width: 60%;
            text-align: right;
        }

        .planting-site-card a {
            display: inline-block;
            margin-top: 25px;
            padding: 12px 24px;
            background: #4CAF50;
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            font-size: 16px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
            width: 100%;
            text-align: center;
        }

        .planting-site-card a:hover {
            background: #388E3C;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(76, 175, 80, 0.4);
        }

        #map {
            height: 400px;
            width: 100%;
            max-width: 800px;
            margin-top: 20px;
            border-radius: 15px;
            overflow: hidden;
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
            animation: zoomIn 0.5s ease-in-out;
        }

        @keyframes zoomIn {
            0% { transform: scale(0.95); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }

        .leaflet-container {
            border-radius: 15px;
        }

        .leaflet-popup-content-wrapper {
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            background: #fff;
            padding: 10px;
        }

        .leaflet-popup-content {
            font-size: 14px;
            font-weight: 500;
            color: #333;
            margin: 0;
        }

        .leaflet-popup-tip {
            background: #fff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 15px;
            margin-top: 20px;
            text-align: center;
            font-size: 16px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        @media (max-width: 768px) {
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
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .header h1 {
                font-size: 24px;
            }

            .header .notification-search .search-bar {
                width: 100%;
                max-width: 200px;
            }

            .header .profile img {
                width: 40px;
                height: 40px;
            }

            .header .profile span {
                display: none;
            }

            .planting-site-card {
                padding: 20px;
                max-width: 100%;
            }

            .planting-site-card p {
                font-size: 14px;
                flex-direction: column;
                text-align: left;
            }

            .planting-site-card p strong {
                width: 100%;
                margin-bottom: 4px;
            }

            .planting-site-card p span {
                width: 100%;
                text-align: left;
            }

            .planting-site-card a {
                padding: 10px 20px;
                font-size: 14px;
            }

            #map {
                height: 300px;
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
            <div class="header">
                <h1>Designated Planting Site</h1>
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
            <div class="planting-site-card">
                <h2>Your Designated Planting Site</h2>
                <?php if (isset($error_message)): ?>
                    <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
                <?php else: ?>
                    <p><strong>Barangay:</strong> <span><?php echo htmlspecialchars($barangay['name'] ?? 'N/A'); ?></span></p>
                    <p><strong>Latitude:</strong> <span><?php echo htmlspecialchars($latitude); ?></span></p>
                    <p><strong>Longitude:</strong> <span><?php echo htmlspecialchars($longitude); ?></span></p>
                    <p><strong>Last Updated:</strong> <span><?php echo htmlspecialchars($planting_site['updated_at'] ? date('Y-m-d H:i:s', strtotime($planting_site['updated_at'])) : 'N/A'); ?></span></p>
                    <a href="https://www.openstreetmap.org/?mlat=<?php echo htmlspecialchars($latitude); ?>&mlon=<?php echo htmlspecialchars($longitude); ?>&zoom=15" target="_blank">View on OpenStreetMap</a>
                <?php endif; ?>
            </div>
            <div id="map"></div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Search bar functionality
        const functionalities = [
            { name: 'Dashboard', url: 'validator_dashboard.php' },
            { name: 'Pending Reviews', url: 'pending_reviews.php' },
            { name: 'Reviewed Submissions', url: 'reviewed_submissions.php' },
            { name: 'Barangay Map', url: 'barangay_map.php' },
            { name: 'Designated Site', url: 'barangay_designated_site.php' },
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

        // Initialize OpenStreetMap with custom marker
        const customIcon = L.icon({
            iconUrl: 'https://cdn-icons-png.flaticon.com/512/684/684908.png',
            iconSize: [38, 38],
            iconAnchor: [19, 38],
            popupAnchor: [0, -38]
        });

        const map = L.map('map').setView([<?php echo htmlspecialchars($latitude); ?>, <?php echo htmlspecialchars($longitude); ?>], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        // Add marker for planting site with custom icon
        L.marker([<?php echo htmlspecialchars($latitude); ?>, <?php echo htmlspecialchars($longitude); ?>], { icon: customIcon }).addTo(map)
            .bindPopup('Designated Planting Site for <?php echo htmlspecialchars($barangay['name'] ?? 'Your Barangay'); ?>')
            .openPopup();

        // AJAX for real-time updates
        function updatePlantingSite() {
            fetch('../services/fetch_designated_site.php?barangay_id=<?php echo $barangay_id; ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        console.error(data.error);
                        return;
                    }
                    document.querySelector('.planting-site-card p:nth-child(2)').innerHTML = `<strong>Barangay:</strong> <span>${data.barangay_name}</span>`;
                    document.querySelector('.planting-site-card p:nth-child(3)').innerHTML = `<strong>Latitude:</strong> <span>${data.latitude}</span>`;
                    document.querySelector('.planting-site-card p:nth-child(4)').innerHTML = `<strong>Longitude:</strong> <span>${data.longitude}</span>`;
                    document.querySelector('.planting-site-card p:nth-child(5)').innerHTML = `<strong>Last Updated:</strong> <span>${data.updated_at}</span>`;
                    const link = document.querySelector('.planting-site-card a');
                    link.href = `https://www.openstreetmap.org/?mlat=${data.latitude}&mlon=${data.longitude}&zoom=15`;
                    link.textContent = 'View on OpenStreetMap';

                    // Update map
                    map.setView([data.latitude, data.longitude], 13);
                    map.eachLayer(layer => {
                        if (layer instanceof L.Marker) map.removeLayer(layer);
                    });
                    L.marker([data.latitude, data.longitude], { icon: customIcon }).addTo(map)
                        .bindPopup(`Designated Planting Site for ${data.barangay_name}`)
                        .openPopup();
                })
                .catch(error => console.error('Error fetching data:', error));
        }

        // Update every 5 seconds
        setInterval(updatePlantingSite, 5000);
        // Initial load
        updatePlantingSite();
    </script>
</body>
</html>