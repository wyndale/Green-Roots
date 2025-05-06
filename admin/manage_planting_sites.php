<?php
session_start();
require_once '../includes/config.php';

// Initialize variables
$is_post_action = false;
$error = $_SESSION['error'] ?? null;
$success = $_SESSION['success'] ?? null;

// Check if user is logged in and has admin role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header('Location: ../views/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    // Fetch admin data
    $stmt = $pdo->prepare("
        SELECT u.username, u.email, u.profile_picture, u.default_profile_asset_id
        FROM users u 
        WHERE u.user_id = :user_id
    ");
    $stmt->execute(['user_id' => $user_id]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        session_destroy();
        header('Location: ../views/login.php');
        exit;
    }

    // Fetch profile picture
    if ($admin['profile_picture']) {
        $profile_picture_data = 'data:image/jpeg;base64,' . base64_encode($admin['profile_picture']);
    } elseif ($admin['default_profile_asset_id']) {
        $stmt = $pdo->prepare("SELECT asset_data, asset_type FROM assets WHERE asset_id = :asset_id");
        $stmt->execute(['asset_id' => $admin['default_profile_asset_id']]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        $profile_picture_data = $asset && $asset['asset_data'] 
            ? "data:image/" . ($asset['asset_type'] === 'default_profile' ? 'png' : 'jpeg') . ";base64," . base64_encode($asset['asset_data'])
            : '../assets/default_profile.jpg';
    } else {
        $profile_picture_data = '../assets/default_profile.jpg';
    }

    // Fetch icon and logo
    $stmt = $pdo->prepare("SELECT asset_data, asset_type FROM assets WHERE asset_type IN ('icon', 'logo')");
    $stmt->execute();
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $icon_base64 = '../assets/icon.png';
    $logo_base64 = '../assets/logo.png';
    foreach ($assets as $asset) {
        if ($asset['asset_type'] === 'icon') {
            $icon_base64 = 'data:image/png;base64,' . base64_encode($asset['asset_data']);
        } elseif ($asset['asset_type'] === 'logo') {
            $logo_base64 = 'data:image/png;base64,' . base64_encode($asset['asset_data']);
        }
    }

    // Pagination setup
    $per_page = 25; // Increased for better UX
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($page - 1) * $per_page;

    // Search and filter
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $filter_region = isset($_GET['filter_region']) ? trim($_GET['filter_region']) : '';
    $filter_province = isset($_GET['filter_province']) ? trim($_GET['filter_province']) : '';
    $filter_city = isset($_GET['filter_city']) ? trim($_GET['filter_city']) : '';

    // Fetch filter options
    $regions = $pdo->query("SELECT DISTINCT region FROM barangays ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);
    $provinces = [];
    if ($filter_region) {
        $stmt = $pdo->prepare("SELECT DISTINCT province FROM barangays WHERE region = :region ORDER BY province");
        $stmt->execute([':region' => $filter_region]);
        $provinces = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $cities = [];
    if ($filter_province) {
        $stmt = $pdo->prepare("SELECT DISTINCT city FROM barangays WHERE province = :province ORDER BY city");
        $stmt->execute([':province' => $filter_province]);
        $cities = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Handle AJAX requests for dynamic filters
    if (isset($_GET['get_provinces'])) {
        $region = $_GET['get_provinces'];
        $stmt = $pdo->prepare("SELECT DISTINCT province FROM barangays WHERE region = :region ORDER BY province");
        $stmt->execute([':region' => $region]);
        header('Content-Type: application/json');
        echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
        exit;
    }

    if (isset($_GET['get_cities'])) {
        $province = $_GET['get_cities'];
        $stmt = $pdo->prepare("SELECT DISTINCT city FROM barangays WHERE province = :province ORDER BY city");
        $stmt->execute([':province' => $province]);
        header('Content-Type: application/json');
        echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
        exit;
    }

    // Optimized query for planting sites
    $sql = "
        SELECT ps.planting_site_id, ps.barangay_id, ps.latitude, ps.longitude, ps.updated_at, ps.updated_by,
               b.name AS barangay_name, b.city, b.province, b.region, b.country,
               u.username AS updated_by_name
        FROM planting_sites ps
        LEFT JOIN barangays b ON ps.barangay_id = b.barangay_id
        LEFT JOIN users u ON ps.updated_by = u.user_id
    ";
    $params = [];
    $where = [];

    if ($search) {
        $where[] = "(b.name LIKE :search OR b.city LIKE :search OR b.province LIKE :search OR b.region LIKE :search)";
        $params[':search'] = "%$search%";
    }
    if ($filter_region) {
        $where[] = "b.region = :region";
        $params[':region'] = $filter_region;
    }
    if ($filter_province) {
        $where[] = "b.province = :province";
        $params[':province'] = $filter_province;
    }
    if ($filter_city) {
        $where[] = "b.city = :city";
        $params[':city'] = $filter_city;
    }

    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    // Count total records
    $count_sql = "SELECT COUNT(*) FROM planting_sites ps LEFT JOIN barangays b ON ps.barangay_id = b.barangay_id" . (!empty($where) ? " WHERE " . implode(" AND ", $where) : "");
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = max(1, ceil($total_records / $per_page));

    // Fetch planting sites with pagination
    $sql .= " ORDER BY ps.updated_at DESC LIMIT :offset, :per_page";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $planting_sites = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Fetch unassigned barangays (limited for performance)
    $unassigned_sql = "
        SELECT b.barangay_id, b.name, b.city, b.province, b.region, b.country
        FROM barangays b
        WHERE b.barangay_id NOT IN (SELECT barangay_id FROM planting_sites)
        LIMIT 50"; // Limit to prevent overload
    $stmt = $pdo->prepare($unassigned_sql);
    $stmt->execute();
    $unassigned_barangays = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Handle CRUD operations
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'], $csrf_token)) {
            $_SESSION['error'] = 'Invalid CSRF token.';
            $is_post_action = true;
        } else {
            if (isset($_POST['create_site'])) {
                $barangay_id = filter_input(INPUT_POST, 'barangay_id', FILTER_VALIDATE_INT);
                $latitude = filter_input(INPUT_POST, 'latitude', FILTER_VALIDATE_FLOAT);
                $longitude = filter_input(INPUT_POST, 'longitude', FILTER_VALIDATE_FLOAT);

                if ($barangay_id && $latitude !== false && $longitude !== false && $latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180) {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO planting_sites (barangay_id, latitude, longitude, updated_by)
                            VALUES (:barangay_id, :latitude, :longitude, :updated_by)
                        ");
                        $stmt->execute([
                            ':barangay_id' => $barangay_id,
                            ':latitude' => $latitude,
                            ':longitude' => $longitude,
                            ':updated_by' => $user_id
                        ]);
                        $_SESSION['success'] = 'Planting site created successfully.';
                    } catch (PDOException $e) {
                        $_SESSION['error'] = 'Error creating planting site: ' . $e->getMessage();
                    }
                } else {
                    $_SESSION['error'] = 'Invalid input. Ensure all fields are valid and within range.';
                }
                $is_post_action = true;
            } elseif (isset($_POST['update_site'])) {
                $planting_site_id = filter_input(INPUT_POST, 'planting_site_id', FILTER_VALIDATE_INT);
                $barangay_id = filter_input(INPUT_POST, 'barangay_id', FILTER_VALIDATE_INT);
                $latitude = filter_input(INPUT_POST, 'latitude', FILTER_VALIDATE_FLOAT);
                $longitude = filter_input(INPUT_POST, 'longitude', FILTER_VALIDATE_FLOAT);

                if ($planting_site_id && $barangay_id && $latitude !== false && $longitude !== false && $latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180) {
                    try {
                        $stmt = $pdo->prepare("
                            UPDATE planting_sites 
                            SET barangay_id = :barangay_id, latitude = :latitude, longitude = :longitude, updated_by = :updated_by
                            WHERE planting_site_id = :planting_site_id
                        ");
                        $stmt->execute([
                            ':planting_site_id' => $planting_site_id,
                            ':barangay_id' => $barangay_id,
                            ':latitude' => $latitude,
                            ':longitude' => $longitude,
                            ':updated_by' => $user_id
                        ]);
                        $_SESSION['success'] = 'Planting site updated successfully.';
                    } catch (PDOException $e) {
                        $_SESSION['error'] = 'Error updating planting site: ' . $e->getMessage();
                    }
                } else {
                    $_SESSION['error'] = 'Invalid input. Ensure all fields are valid and within range.';
                }
                $is_post_action = true;
            } elseif (isset($_POST['delete_site'])) {
                $planting_site_id = filter_input(INPUT_POST, 'planting_site_id', FILTER_VALIDATE_INT);
                if ($planting_site_id) {
                    try {
                        $stmt = $pdo->prepare("DELETE FROM planting_sites WHERE planting_site_id = :planting_site_id");
                        $stmt->execute([':planting_site_id' => $planting_site_id]);
                        $_SESSION['success'] = 'Planting site deleted successfully.';
                    } catch (PDOException $e) {
                        $_SESSION['error'] = 'Error deleting planting site: ' . $e->getMessage();
                    }
                }
                $is_post_action = true;
            }
        }

        if ($is_post_action) {
            $query = http_build_query(array_filter([
                'search' => $search,
                'filter_region' => $filter_region,
                'filter_province' => $filter_province,
                'filter_city' => $filter_city,
                'page' => $page
            ]));
            header('Location: manage_planting_sites.php' . ($query ? '?' . $query : ''));
            exit;
        }
    }

    // Generate CSRF token
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    // Clear messages
    unset($_SESSION['error']);
    unset($_SESSION['success']);

} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Planting Sites - Green Roots</title>
    <link rel="icon" type="image/png" sizes="64x64" href="<?php echo $icon_base64; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background: #f4f4f4;
        }

        .container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 70px;
            background: #fff;
            padding: 20px 0;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .sidebar .logo {
            width: 50px;
            margin-bottom: 20px;
        }

        .sidebar a {
            color: #333;
            margin: 10px 0;
            font-size: 24px;
            text-decoration: none;
        }

        .sidebar a:hover {
            color: #4CAF50;
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
        }

        .header h1 {
            color: #4CAF50;
            margin: 0;
        }

        .profile {
            display: flex;
            align-items: center;
            cursor: pointer;
            position: relative;
        }

        .profile img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-left: 10px;
        }

        .profile-dropdown {
            display: none;
            position: absolute;
            top: 50px;
            right: 0;
            background: #fff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            border-radius: 4px;
            padding: 10px;
            z-index: 1000;
        }

        .profile-dropdown a {
            display: block;
            padding: 5px 10px;
            color: #333;
            text-decoration: none;
        }

        .profile-dropdown a:hover {
            background: #f5f5f5;
        }

        .profile:hover .profile-dropdown {
            display: block;
        }

        .error-message {
            background: #fee2e2;
            color: #dc2626;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .success-message {
            background: #d1fae5;
            color: #10b981;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .filters {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filters input, .filters select {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            min-width: 150px;
        }

        .filters button {
            padding: 8px 15px;
            background: #4CAF50;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .filters button:hover {
            background: #388E3C;
        }

        .custom-btn {
            padding: 8px 15px;
            background: #4CAF50;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .custom-btn:hover {
            background: #388E3C;
        }

        .custom-btn.delete {
            background: #dc2626;
        }

        .custom-btn.delete:hover {
            background: #b91c1c;
        }

        .planting-sites-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: #fff;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .planting-sites-table th, .planting-sites-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            font-size: 14px;
        }

        .planting-sites-table th {
            background: #4CAF50;
            color: #fff;
        }

        .planting-sites-table tr:hover {
            background: #f5f5f5;
        }

        .planting-sites-table tr.unassigned {
            background: #ffebee;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            width: 90%;
            max-width: 500px;
            text-align: center;
            position: relative;
        }

        .modal-content h3 {
            color: #4CAF50;
            margin-bottom: 20px;
        }

        .modal-content .error, .modal-content .success {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
            display: none;
        }

        .modal-content .error {
            background: #fee2e2;
            color: #dc2626;
        }

        .modal-content .success {
            background: #d1fae5;
            color: #10b981;
        }

        .modal-content .error.show, .modal-content .success.show {
            display: block;
        }

        .modal-content .form-group {
            margin-bottom: 15px;
            text-align: left;
        }

        .modal-content label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .modal-content input[type="number"], .modal-content select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }

        .modal-content button {
            background: #4CAF50;
            color: #fff;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
            margin-top: 10px;
        }

        .modal-content button:hover {
            background: #388E3C;
        }

        .close-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 24px;
            cursor: pointer;
            color: #333;
        }

        .pagination {
            margin-top: 20px;
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .pagination a {
            padding: 8px 12px;
            text-decoration: none;
            color: #4CAF50;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .pagination a.active {
            background: #4CAF50;
            color: #fff;
            border-color: #4CAF50;
        }

        .pagination a:hover {
            background: #f5f5f5;
        }

        @media (max-width: 768px) {
            .planting-sites-table th, .planting-sites-table td {
                padding: 8px;
                font-size: 12px;
            }

            .filters {
                flex-direction: column;
            }

            .filters input, .filters select, .filters button {
                width: 100%;
                box-sizing: border-box;
            }

            .custom-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <img src="<?php echo $logo_base64; ?>" alt="Logo" class="logo">
            <a href="admin_dashboard.php" title="Admin Dashboard"><i class="fas fa-tachometer-alt"></i></a>
            <a href="manage_barangays.php" title="Manage Barangays"><i class="fas fa-map"></i></a>
            <a href="manage_planting_sites.php" title="Manage Planting Sites"><i class="fas fa-seedling"></i></a>
            <a href="manage_rewards.php" title="Manage Rewards"><i class="fas fa-gifts"></i></a>
            <a href="manage_events.php" title="Manage Events"><i class="fas fa-calendar-alt"></i></a>
            <a href="manage_users.php" title="Manage Users"><i class="fas fa-users"></i></a>
            <a href="manage_validators.php" title="Manage Validators"><i class="fas fa-user-shield"></i></a>
            <a href="view_data.php" title="View All Data"><i class="fas fa-eye"></i></a>
            <a href="export_data.php" title="Export System Data"><i class="fas fa-download"></i></a>
        </div>
        <div class="main-content">
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <div class="header">
                <h1>Manage Planting Sites</h1>
                <div class="profile">
                    <span><?php echo htmlspecialchars($admin['username']); ?></span>
                    <img src="<?php echo $profile_picture_data; ?>" alt="Profile">
                    <div class="profile-dropdown">
                        <div class="email"><?php echo htmlspecialchars($admin['email']); ?></div>
                        <a href="account_settings.php">Account</a>
                        <a href="logout.php">Logout</a>
                    </div>
                </div>
            </div>
            <div class="filters">
                <input type="text" id="searchInput" name="search" placeholder="Search by name, city, province, or region" value="<?php echo htmlspecialchars($search); ?>">
                <select id="filterRegion" name="filter_region">
                    <option value="">All Regions</option>
                    <?php foreach ($regions as $region): ?>
                        <option value="<?php echo htmlspecialchars($region); ?>" <?php echo $filter_region === $region ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($region); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select id="filterProvince" name="filter_province">
                    <option value="">All Provinces</option>
                    <?php foreach ($provinces as $province): ?>
                        <option value="<?php echo htmlspecialchars($province); ?>" <?php echo $filter_province === $province ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($province); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select id="filterCity" name="filter_city">
                    <option value="">All Cities</option>
                    <?php foreach ($cities as $city): ?>
                        <option value="<?php echo htmlspecialchars($city); ?>" <?php echo $filter_city === $city ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($city); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button onclick="applyFilters()">Apply Filters</button>
                <button onclick="clearFilters()">Clear Filters</button>
            </div>
            <button class="custom-btn" onclick="openCreateModal()">Add New Planting Site</button>
            <table class="planting-sites-table">
                <thead>
                    <tr>
                        <th>Barangay</th>
                        <th>City</th>
                        <th>Province</th>
                        <th>Region</th>
                        <th>Latitude</th>
                        <th>Longitude</th>
                        <th>Updated At</th>
                        <th>Updated By</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($planting_sites as $site): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($site['barangay_name'] ?: 'Unassigned'); ?></td>
                            <td><?php echo htmlspecialchars($site['city'] ?: 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($site['province'] ?: 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($site['region'] ?: 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($site['latitude'] ?: 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($site['longitude'] ?: 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($site['updated_at'] ?: 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($site['updated_by_name'] ?: 'N/A'); ?></td>
                            <td>
                                <button class="custom-btn" onclick='openEditModal(<?php echo json_encode($site); ?>)'>Edit</button>
                                <button class="custom-btn delete" onclick="deleteSite(<?php echo $site['planting_site_id']; ?>)">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($planting_sites)): ?>
                        <tr>
                            <td colspan="9">No planting sites found.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($unassigned_barangays as $barangay): ?>
                        <tr class="unassigned">
                            <td><?php echo htmlspecialchars($barangay['name']); ?></td>
                            <td><?php echo htmlspecialchars($barangay['city']); ?></td>
                            <td><?php echo htmlspecialchars($barangay['province']); ?></td>
                            <td><?php echo htmlspecialchars($barangay['region']); ?></td>
                            <td colspan="5">No planting site assigned</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="pagination">
                <?php
                $max_pages = min($total_pages, 10); // Limit visible pages
                $start_page = max(1, $page - 5);
                $end_page = min($total_pages, $start_page + $max_pages - 1);
                if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&filter_region=<?php echo urlencode($filter_region); ?>&filter_province=<?php echo urlencode($filter_province); ?>&filter_city=<?php echo urlencode($filter_city); ?>">Previous</a>
                <?php endif; ?>
                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&filter_region=<?php echo urlencode($filter_region); ?>&filter_province=<?php echo urlencode($filter_province); ?>&filter_city=<?php echo urlencode($filter_city); ?>"
                       class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&filter_region=<?php echo urlencode($filter_region); ?>&filter_province=<?php echo urlencode($filter_province); ?>&filter_city=<?php echo urlencode($filter_city); ?>">Next</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Create Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal('createModal')">×</span>
            <h3>Add New Planting Site</h3>
            <div class="error"></div>
            <div class="success"></div>
            <form method="POST" id="createForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="create_site" value="1">
                <div class="form-group">
                    <label for="create_barangay">Barangay</label>
                    <select id="create_barangay" name="barangay_id" required>
                        <option value="">Select Barangay</option>
                        <!-- Populated dynamically via AJAX -->
                    </select>
                </div>
                <div class="form-group">
                    <label for="create_latitude">Latitude</label>
                    <input type="number" step="0.00000001" id="create_latitude" name="latitude" required>
                </div>
                <div class="form-group">
                    <label for="create_longitude">Longitude</label>
                    <input type="number" step="0.00000001" id="create_longitude" name="longitude" required>
                </div>
                <button type="submit">Create</button>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal('editModal')">×</span>
            <h3>Edit Planting Site</h3>
            <div class="error"></div>
            <div class="success"></div>
            <form method="POST" id="editForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="update_site" value="1">
                <input type="hidden" id="edit_planting_site_id" name="planting_site_id">
                <div class="form-group">
                    <label for="edit_barangay">Barangay</label>
                    <select id="edit_barangay" name="barangay_id" required>
                        <option value="">Select Barangay</option>
                        <!-- Populated dynamically via AJAX -->
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit_latitude">Latitude</label>
                    <input type="number" step="0.00000001" id="edit_latitude" name="latitude" required>
                </div>
                <div class="form-group">
                    <label for="edit_longitude">Longitude</label>
                    <input type="number" step="0.00000001" id="edit_longitude" name="longitude" required>
                </div>
                <button type="submit">Update</button>
            </form>
        </div>
    </div>

    <script>
        // Dynamic filter updates
        const filterRegion = document.getElementById('filterRegion');
        const filterProvince = document.getElementById('filterProvince');
        const filterCity = document.getElementById('filterCity');

        async function fetchProvinces(region) {
            try {
                const response = await fetch(`manage_planting_sites.php?get_provinces=${encodeURIComponent(region)}`);
                if (!response.ok) throw new Error('Network response was not ok');
                const provinces = await response.json();
                filterProvince.innerHTML = '<option value="">All Provinces</option>';
                provinces.forEach(province => {
                    const option = document.createElement('option');
                    option.value = province;
                    option.textContent = province;
                    filterProvince.appendChild(option);
                });
            } catch (error) {
                console.error('Error fetching provinces:', error);
                filterProvince.innerHTML = '<option value="">Error loading provinces</option>';
            }
        }

        async function fetchCities(province) {
            try {
                const response = await fetch(`manage_planting_sites.php?get_cities=${encodeURIComponent(province)}`);
                if (!response.ok) throw new Error('Network response was not ok');
                const cities = await response.json();
                filterCity.innerHTML = '<option value="">All Cities</option>';
                cities.forEach(city => {
                    const option = document.createElement('option');
                    option.value = city;
                    option.textContent = city;
                    filterCity.appendChild(option);
                });
            } catch (error) {
                console.error('Error fetching cities:', error);
                filterCity.innerHTML = '<option value="">Error loading cities</option>';
            }
        }

        filterRegion.addEventListener('change', async () => {
            const region = filterRegion.value;
            filterProvince.innerHTML = '<option value="">All Provinces</option>';
            filterCity.innerHTML = '<option value="">All Cities</option>';
            if (region) await fetchProvinces(region);
            applyFilters();
        });

        filterProvince.addEventListener('change', async () => {
            const province = filterProvince.value;
            filterCity.innerHTML = '<option value="">All Cities</option>';
            if (province) await fetchCities(province);
            applyFilters();
        });

        filterCity.addEventListener('change', applyFilters);

        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const region = filterRegion.value;
            const province = filterProvince.value;
            const city = filterCity.value;
            const params = new URLSearchParams();
            if (search) params.append('search', search);
            if (region) params.append('filter_region', region);
            if (province) params.append('filter_province', province);
            if (city) params.append('filter_city', city);
            window.location.href = `manage_planting_sites.php?${params.toString()}`;
        }

        function clearFilters() {
            window.location.href = 'manage_planting_sites.php';
        }

        // Lazy load barangays for modals
        async function loadBarangays(selectId, selectedId = '') {
            try {
                const response = await fetch('manage_planting_sites.php?get_barangays=1');
                if (!response.ok) throw new Error('Network response was not ok');
                const barangays = await response.json();
                const select = document.getElementById(selectId);
                select.innerHTML = '<option value="">Select Barangay</option>';
                barangays.forEach(barangay => {
                    const option = document.createElement('option');
                    option.value = barangay.barangay_id;
                    option.textContent = `${barangay.name}, ${barangay.city}, ${barangay.province}, ${barangay.region}`;
                    if (barangay.barangay_id == selectedId) option.selected = true;
                    select.appendChild(option);
                });
            } catch (error) {
                console.error('Error loading barangays:', error);
                document.getElementById(selectId).innerHTML = '<option value="">Error loading barangays</option>';
            }
        }

        function openCreateModal() {
            document.getElementById('createModal').style.display = 'flex';
            document.getElementById('createForm').reset();
            loadBarangays('create_barangay');
        }

        function openEditModal(site) {
            document.getElementById('editModal').style.display = 'flex';
            document.getElementById('edit_planting_site_id').value = site.planting_site_id;
            document.getElementById('edit_latitude').value = site.latitude || '';
            document.getElementById('edit_longitude').value = site.longitude || '';
            loadBarangays('edit_barangay', site.barangay_id);
        }

        function deleteSite(plantingSiteId) {
            if (confirm('Are you sure you want to delete this planting site?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'manage_planting_sites.php';
                const inputs = [
                    { name: 'csrf_token', value: '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>' },
                    { name: 'delete_site', value: '1' },
                    { name: 'planting_site_id', value: plantingSiteId },
                    { name: 'search', value: '<?php echo htmlspecialchars($search); ?>' },
                    { name: 'filter_region', value: '<?php echo htmlspecialchars($filter_region); ?>' },
                    { name: 'filter_province', value: '<?php echo htmlspecialchars($filter_province); ?>' },
                    { name: 'filter_city', value: '<?php echo htmlspecialchars($filter_city); ?>' },
                    { name: 'page', value: '<?php echo $page; ?>' }
                ];
                inputs.forEach(input => {
                    const el = document.createElement('input');
                    el.type = 'hidden';
                    el.name = input.name;
                    el.value = input.value;
                    form.appendChild(el);
                });
                document.body.appendChild(form);
                form.submit();
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.style.display = 'none';
            const error = modal.querySelector('.error');
            const success = modal.querySelector('.success');
            if (error) {
                error.classList.remove('show');
                error.textContent = '';
            }
            if (success) {
                success.classList.remove('show');
                success.textContent = '';
            }
            const form = modal.querySelector('form');
            if (form) form.reset();
        }

        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) closeModal(modal.id);
            });
        });

        // Form validation
        ['createForm', 'editForm'].forEach(formId => {
            document.getElementById(formId).addEventListener('submit', function (e) {
                const prefix = formId === 'createForm' ? 'create' : 'edit';
                const barangay = document.getElementById(`${prefix}_barangay`).value;
                const latitude = parseFloat(document.getElementById(`${prefix}_latitude`).value);
                const longitude = parseFloat(document.getElementById(`${prefix}_longitude`).value);
                const error = document.getElementById(`${prefix}Modal`).querySelector('.error');
                error.classList.remove('show');
                error.textContent = '';

                if (!barangay || isNaN(latitude) || isNaN(longitude)) {
                    e.preventDefault();
                    error.textContent = 'All fields are required.';
                    error.classList.add('show');
                } else if (latitude < -90 || latitude > 90) {
                    e.preventDefault();
                    error.textContent = 'Latitude must be between -90 and 90.';
                    error.classList.add('show');
                } else if (longitude < -180 || longitude > 180) {
                    e.preventDefault();
                    error.textContent = 'Longitude must be between -180 and 180.';
                    error.classList.add('show');
                }
            });
        });

        // Handle AJAX for barangays
        <?php
        if (isset($_GET['get_barangays'])) {
            $stmt = $pdo->prepare("
                SELECT barangay_id, name, city, province, region
                FROM barangays
                ORDER BY name
                LIMIT 1000
            ");
            $stmt->execute();
            header('Content-Type: application/json');
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }
        ?>
    </script>
</body>
</html>