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
            $profile_picture_data = '../assets/default_profile.jpg';
        }
    } else {
        $profile_picture_data = '../assets/default_profile.jpg';
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
    $logo_base64 = $logo_data ? 'data:image/png;base64,' . base64_encode($logo_data) : '../assets/logo.png';

    // Handle search and filter
    $search_query = '';
    $status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
        $search_query = trim($_POST['search']);
        $_SESSION['search_query_reviewed'] = $search_query;
        header("Location: reviewed_submissions.php?page=1&status=$status_filter");
        exit;
    } elseif (isset($_SESSION['search_query_reviewed'])) {
        $search_query = $_SESSION['search_query_reviewed'];
    }

    // Pagination setup
    $items_per_page = 10;
    $current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($current_page - 1) * $items_per_page;

    // Count total reviewed submissions
    $count_query = "
        SELECT COUNT(*) as total
        FROM submissions s
        JOIN users u ON s.user_id = u.user_id
        WHERE s.barangay_id = :barangay_id AND s.status IN ('approved', 'rejected')
        AND (:search = '' OR u.username LIKE :search OR u.email LIKE :search)
    ";
    if ($status_filter !== 'all') {
        $count_query .= " AND s.status = :status";
    }
    $count_stmt = $pdo->prepare($count_query);
    $count_params = [
        ':barangay_id' => $user['barangay_id'],
        ':search' => $search_query ? "%$search_query%" : ''
    ];
    if ($status_filter !== 'all') {
        $count_params[':status'] = $status_filter;
    }
    $count_stmt->execute($count_params);
    $total_items = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = max(1, ceil($total_items / $items_per_page));

    // Fetch reviewed submissions
    $query = "
        SELECT s.submission_id, s.user_id, s.trees_planted, s.photo_data, s.latitude, s.longitude, s.submitted_at, s.status,
               s.submission_notes, s.flagged, s.rejection_reason, u.username
        FROM submissions s
        JOIN users u ON s.user_id = u.user_id
        WHERE s.barangay_id = :barangay_id AND s.status IN ('approved', 'rejected')
        AND (:search = '' OR u.username LIKE :search OR u.email LIKE :search)
    ";
    if ($status_filter !== 'all') {
        $query .= " AND s.status = :status";
    }
    $query .= " GROUP BY s.submission_id ORDER BY s.submitted_at DESC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($query);
    $params = [
        ':barangay_id' => $user['barangay_id'],
        ':search' => $search_query ? "%$search_query%" : '',
        ':limit' => $items_per_page,
        ':offset' => $offset
    ];
    if ($status_filter !== 'all') {
        $params[':status'] = $status_filter;
    }
    $stmt->bindValue(':barangay_id', $user['barangay_id'], PDO::PARAM_INT);
    $stmt->bindValue(':search', $search_query ? "%$search_query%" : '', PDO::PARAM_STR);
    $stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    if ($status_filter !== 'all') {
        $stmt->bindValue(':status', $status_filter, PDO::PARAM_STR);
    }
    $stmt->execute();
    $reviewed_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate eco points for each submission
    $base_points_per_tree = 50;
    foreach ($reviewed_submissions as &$submission) {
        $total_base_points = $submission['trees_planted'] * $base_points_per_tree;
        $buffer_multiplier = 1.2;
        $reward_multiplier = 1.1;
        $buffered_points = $total_base_points * $buffer_multiplier;
        $eco_points = $buffered_points * $reward_multiplier;
        $submission['eco_points'] = round($eco_points);
    }

} catch (PDOException $e) {
    $error_message = "Error: " . $e->getMessage();
} catch (Exception $e) {
    $error_message = "Unexpected error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviewed Submissions</title>
    <link rel="icon" type="image/png" sizes="64x64" href="<?php echo htmlspecialchars($icon_base64); ?>">
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
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
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

        .custom-search-filter {
            margin-bottom: 20px;
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }

        .custom-search-filter form {
            position: relative;
            width: 100%;
            max-width: 300px;
        }

        .custom-search-filter input {
            padding: 12px 40px 12px 16px;
            width: 100%;
            border: 2px solid #4CAF50;
            border-radius: 25px;
            font-size: 16px;
            transition: border-color 0.3s, box-shadow 0.3s;
            outline: none;
        }

        .custom-search-filter input:focus {
            border-color: #388E3C;
            box-shadow: 0 0 5px rgba(76, 175, 80, 0.5);
        }

        .custom-search-filter button {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            transition: color 0.3s;
        }

        .custom-search-filter button i {
            font-size: 18px;
            color: #4CAF50;
        }

        .custom-search-filter button:hover i {
            color: #388E3C;
        }

        .custom-search-filter select {
            padding: 12px 16px;
            border: 2px solid #4CAF50;
            border-radius: 25px;
            font-size: 16px;
            background: #fff;
            cursor: pointer;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .custom-search-filter select:focus {
            border-color: #388E3C;
            box-shadow: 0 0 5px rgba(76, 175, 80, 0.5);
            outline: none;
        }

        .submission-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            overflow-x: auto;
        }

        .submission-table table {
            width: 100%;
            min-width: 1200px;
        }

        .submission-table th,
        .submission-table td {
            padding: 10px 15px;
            text-align: left;
            border-bottom: 1px solid #e0e7ff;
            vertical-align: middle;
            font-size: 14px;
            color: #333;
        }

        .submission-table th {
            background: #4CAF50;
            color: #fff;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .submission-table tr {
            height: 50px;
        }

        .submission-table tr:hover {
            background: #f5f7fa;
        }

        .submission-table .flag-icon {
            color: #F44336;
            font-size: 18px;
            margin-right: 5px;
        }

        .submission-table img {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 5px;
            cursor: pointer;
            transition: transform 0.3s;
        }

        .submission-table img:hover {
            transform: scale(1.1);
        }

        .submission-table a.location-link {
            color: #2196F3;
            text-decoration: none;
            font-weight: 500;
        }

        .submission-table a.location-link:hover {
            text-decoration: underline;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 10px;
            flex-wrap: wrap;
        }

        .pagination a {
            padding: 10px 15px;
            background: #4CAF50;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            transition: background 0.3s;
        }

        .pagination a:hover {
            background: #388E3C;
        }

        .pagination a.disabled {
            background: #ccc;
            cursor: not-allowed;
            pointer-events: none;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            overflow: auto;
        }

        .modal-content {
            background: #fff;
            padding: 25px;
            width: 90%;
            max-width: 500px;
            border-radius: 15px;
            text-align: center;
            position: relative;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }

        .modal-content .close {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 24px;
            cursor: pointer;
            color: #666;
            transition: color 0.3s;
        }

        .modal-content .close:hover {
            color: #F44336;
        }

        .modal-content h2 {
            font-size: 24px;
            color: #4CAF50;
            margin-bottom: 20px;
        }

        .modal-content p {
            font-size: 16px;
            color: #333;
            margin-bottom: 20px;
        }

        .modal-content .image-modal {
            max-width: 100%;
            max-height: 80vh;
            border-radius: 10px;
            object-fit: contain;
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
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .header h1 {
                font-size: 24px;
            }

            .header .notification-search {
                width: 100%;
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

            .custom-search-filter {
                flex-direction: column;
                align-items: flex-start;
            }

            .custom-search-filter form {
                max-width: 100%;
            }

            .custom-search-filter select {
                width: 100%;
                max-width: 300px;
            }

            .submission-table th,
            .submission-table td {
                padding: 8px 12px;
                font-size: 12px;
            }

            .submission-table img {
                width: 30px;
                height: 30px;
            }

            .modal-content {
                width: 90%;
                padding: 15px;
            }

            .modal-content h2 {
                font-size: 20px;
            }

            .modal-content p {
                font-size: 14px;
            }
        }

        /* Accessibility and Focus States */
        button:focus,
        input:focus,
        select:focus {
            outline: 2px solid #4CAF50;
            outline-offset: 2px;
        }

        a:focus {
            outline: 2px solid #2196F3;
            outline-offset: 2px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <img src="<?php echo htmlspecialchars($logo_base64); ?>" alt="Logo" class="logo">
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
                <h1>Reviewed Submissions</h1>
                <div class="notification-search">
                    <div class="search-bar">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" placeholder="Search" id="searchInput">
                        <div class="search-results" id="searchResults"></div>
                    </div>
                </div>
                <div class="profile" id="profileBtn">
                    <span><?php echo htmlspecialchars($user['first_name'] ?? 'User'); ?></span>
                    <img src="<?php echo htmlspecialchars($profile_picture_data); ?>" alt="Profile">
                    <div class="profile-dropdown" id="profileDropdown">
                        <div class="email"><?php echo htmlspecialchars($user['email']); ?></div>
                        <a href="account_settings.php">Account</a>
                        <a href="../views/logout.php">Logout</a>
                    </div>
                </div>
            </div>
            <div class="custom-search-filter">
                <form method="POST" action="" id="searchForm">
                    <input type="text" name="search" id="searchField" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search by username or email">
                    <button type="submit"><i class="fas fa-search"></i></button>
                </form>
                <select id="statusFilter" onchange="updateStatusFilter()">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Reviewed</option>
                    <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>
            <div class="submission-table">
                <table id="reviewedTable">
                    <thead>
                        <tr>
                            <th>Submission ID</th>
                            <th>Submitter</th>
                            <th>Submitted At</th>
                            <th>Location</th>
                            <th>Photo</th>
                            <th>Trees Planted</th>
                            <th>Eco Points</th>
                            <th>Notes</th>
                            <th>Status</th>
                            <th>Rejection Reason</th>
                            <th>Flag</th>
                        </tr>
                    </thead>
                    <tbody id="reviewedTableBody">
                        <?php if (empty($reviewed_submissions)): ?>
                            <tr>
                                <td colspan="11" style="text-align: center;">No reviewed submissions available.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($reviewed_submissions as $submission): ?>
                                <tr data-submission-id="<?php echo htmlspecialchars($submission['submission_id']); ?>">
                                    <td><?php echo htmlspecialchars($submission['submission_id']); ?></td>
                                    <td><?php echo htmlspecialchars($submission['username']); ?></td>
                                    <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($submission['submitted_at']))); ?></td>
                                    <td>
                                        <a href="https://www.openstreetmap.org/?mlat=<?php echo htmlspecialchars($submission['latitude'] ?? '0'); ?>&mlon=<?php echo htmlspecialchars($submission['longitude'] ?? '0'); ?>&zoom=15" target="_blank" class="location-link">
                                            <?php echo htmlspecialchars($submission['latitude'] ?? 'N/A'); ?>, <?php echo htmlspecialchars($submission['longitude'] ?? 'N/A'); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php if ($submission['photo_data']): ?>
                                            <img src="data:image/jpeg;base64,<?php echo base64_encode($submission['photo_data']); ?>" alt="Submission Photo" onclick="openImageModal(this.src)">
                                        <?php else: ?>
                                            No Photo
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($submission['trees_planted']); ?></td>
                                    <td><?php echo htmlspecialchars($submission['eco_points']); ?></td>
                                    <td><?php echo htmlspecialchars($submission['submission_notes'] ?? 'N/A'); ?></td>
                                    <td class="status"><?php echo htmlspecialchars(ucfirst($submission['status'])); ?></td>
                                    <td><?php echo htmlspecialchars($submission['rejection_reason'] ?? 'N/A'); ?></td>
                                    <td><?php if ($submission['flagged']): ?><i class="fas fa-flag flag-icon"></i><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination">
                <a href="?page=<?php echo max(1, $current_page - 1); ?>&status=<?php echo $status_filter; ?>" class="<?php echo ($current_page == 1 || $total_items == 0) ? 'disabled' : ''; ?>">Previous</a>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&status=<?php echo $status_filter; ?>" class="<?php echo $i == $current_page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <a href="?page=<?php echo min($total_pages, $current_page + 1); ?>&status=<?php echo $status_filter; ?>" class="<?php echo ($current_page == $total_pages || $total_items == 0) ? 'disabled' : ''; ?>">Next</a>
            </div>
        </div>
    </div>

    <!-- Image Modal -->
    <div class="modal" id="imageModal">
        <div class="modal-content">
            <span class="close" onclick="closeImageModal()">×</span>
            <img id="modalImage" class="image-modal" src="" alt="Enlarged Photo">
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

        // Status filter update
        function updateStatusFilter() {
            const status = document.querySelector('#statusFilter').value;
            window.location.href = `reviewed_submissions.php?page=1&status=${status}`;
        }

        // AJAX for real-time updates
        function updateReviewedTable() {
            const status = document.querySelector('#statusFilter').value;
            const search = document.querySelector('#searchField').value;
            fetch(`../services/fetch_reviewed.php?barangay_id=<?php echo $user['barangay_id']; ?>&status=${status}&search=${encodeURIComponent(search)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        console.error(data.error);
                        return;
                    }
                    const tbody = document.getElementById('reviewedTableBody');
                    tbody.innerHTML = '';
                    if (data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="11" style="text-align: center;">No reviewed submissions available.</td></tr>';
                    } else {
                        data.forEach(submission => {
                            const row = document.createElement('tr');
                            row.dataset.submissionId = submission.submission_id;
                            row.innerHTML = `
                                <td>${submission.submission_id}</td>
                                <td>${submission.username}</td>
                                <td>${new Date(submission.submitted_at).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: 'numeric' })}</td>
                                <td><a href="https://www.openstreetmap.org/?mlat=${submission.latitude || 0}&mlon=${submission.longitude || 0}&zoom=15" target="_blank" class="location-link">${submission.latitude || 'N/A'}, ${submission.longitude || 'N/A'}</a></td>
                                <td>${submission.photo_data ? `<img src="data:image/jpeg;base64,${submission.photo_data}" alt="Submission Photo" onclick="openImageModal(this.src)">` : 'No Photo'}</td>
                                <td>${submission.trees_planted}</td>
                                <td>${submission.eco_points}</td>
                                <td>${submission.submission_notes || 'N/A'}</td>
                                <td class="status">${submission.status.charAt(0).toUpperCase() + submission.status.slice(1)}</td>
                                <td>${submission.rejection_reason || 'N/A'}</td>
                                <td>${submission.flagged ? '<i class="fas fa-flag flag-icon"></i>' : ''}</td>
                            `;
                            tbody.appendChild(row);
                        });
                    }
                })
                .catch(error => console.error('Error fetching data:', error));
        }

        // Update table every 5 seconds
        setInterval(updateReviewedTable, 2000);
        // Initial load
        updateReviewedTable();

        // Image Modal functionality
        function openImageModal(src) {
            const modalImage = document.querySelector('#modalImage');
            modalImage.src = src;
            document.querySelector('#imageModal').style.display = 'flex';
        }

        function closeImageModal() {
            const modalImage = document.querySelector('#modalImage');
            document.querySelector('#imageModal').style.display = 'none';
            modalImage.src = '';
        }

        // Sync search input with session
        document.addEventListener('DOMContentLoaded', function() {
            const searchField = document.querySelector('#searchField');
            searchField.value = '<?php echo htmlspecialchars($search_query); ?>';
        });
    </script>
</body>
</html>