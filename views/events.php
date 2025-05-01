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

// Function to generate QR code data
function generateQRCodeData($user_id, $event_id) {
    return hash('sha256', $user_id . $event_id . time());
}

// Fetch user data including barangay and profile picture
try {
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.email, u.profile_picture, u.default_profile_asset_id, u.role, u.barangay_id, b.name as barangay_name 
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

    // Fetch logo
    $stmt = $pdo->prepare("SELECT asset_data FROM assets WHERE asset_type = 'logo' LIMIT 1");
    $stmt->execute();
    $logo_data = $stmt->fetchColumn();
    $logo_base64 = $logo_data ? 'data:image/png;base64,' . base64_encode($logo_data) : 'logo.png';

    // Fetch barangays for filter
    $stmt = $pdo->prepare("SELECT barangay_id, name FROM barangays ORDER BY name");
    $stmt->execute();
    $barangays = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle Join Event
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join_event'])) {
        $event_id = (int)$_POST['event_id'];

        // Check if the event has available spots (future enhancement)
        $stmt = $pdo->prepare("SELECT capacity, (SELECT COUNT(*) FROM event_participants WHERE event_id = :event_id) as participant_count FROM events WHERE event_id = :event_id");
        $stmt->execute(['event_id' => $event_id]);
        $event_capacity = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($event_capacity && $event_capacity['capacity'] && $event_capacity['participant_count'] >= $event_capacity['capacity']) {
            if (isset($_POST['ajax'])) {
                echo json_encode(['success' => false, 'message' => 'This event has reached its capacity.']);
                exit;
            }
            $join_message = 'This event has reached its capacity.';
        } else {
            // Check if the user has already joined the event
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM event_participants 
                WHERE event_id = :event_id AND user_id = :user_id
            ");
            $stmt->execute(['event_id' => $event_id, 'user_id' => $user_id]);
            $already_joined = $stmt->fetchColumn();

            if ($already_joined) {
                if (isset($_POST['ajax'])) {
                    echo json_encode(['success' => false, 'message' => 'You have already joined this event.']);
                    exit;
                }
                $join_message = 'You have already joined this event.';
            } else {
                // Generate QR code data
                $qr_code = generateQRCodeData($user_id, $event_id);

                // Add user to event participants with QR code
                $stmt = $pdo->prepare("
                    INSERT INTO event_participants (event_id, user_id, qr_code, joined_at) 
                    VALUES (:event_id, :user_id, :qr_code, NOW())
                ");
                $stmt->execute([
                    'event_id' => $event_id,
                    'user_id' => $user_id,
                    'qr_code' => $qr_code
                ]);

                // Log activity
                $stmt = $pdo->prepare("
                    INSERT INTO activities (user_id, description, activity_type, created_at) 
                    VALUES (:user_id, :description, 'event', NOW())
                ");
                $stmt->execute([
                    'user_id' => $user_id,
                    'description' => "Joined event with ID $event_id"
                ]);

                // Fetch event details for the modal
                $stmt = $pdo->prepare("
                    SELECT e.title, e.event_date, e.location, e.capacity, 
                           (SELECT COUNT(*) FROM event_participants WHERE event_id = e.event_id) as participant_count,
                           b.name as barangay_name 
                    FROM events e 
                    LEFT JOIN barangays b ON e.barangay_id = b.barangay_id 
                    WHERE e.event_id = :event_id
                ");
                $stmt->execute(['event_id' => $event_id]);
                $event = $stmt->fetch(PDO::FETCH_ASSOC);

                // Prepare response for AJAX
                if (isset($_POST['ajax'])) {
                    echo json_encode([
                        'success' => true,
                        'user' => [
                            'username' => $user['username'],
                            'email' => $user['email']
                        ],
                        'event' => $event,
                        'qr_code' => $qr_code
                    ]);
                    exit;
                }

                $join_message = 'Successfully joined the event! Check your QR code in My Events.';
            }
        }
    }

    // Handle View QR Code Request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['view_qr_code'])) {
        $event_id = (int)$_POST['event_id'];

        // Fetch event details and QR code for the user
        $stmt = $pdo->prepare("
            SELECT e.title, e.event_date, e.location, e.capacity,
                   (SELECT COUNT(*) FROM event_participants WHERE event_id = e.event_id) as participant_count,
                   b.name as barangay_name, ep.qr_code 
            FROM events e 
            JOIN event_participants ep ON e.event_id = ep.event_id 
            LEFT JOIN barangays b ON e.barangay_id = b.barangay_id 
            WHERE e.event_id = :event_id AND ep.user_id = :user_id
        ");
        $stmt->execute(['event_id' => $event_id, 'user_id' => $user_id]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($event) {
            echo json_encode([
                'success' => true,
                'user' => [
                    'username' => $user['username'],
                    'email' => $user['email']
                ],
                'event' => [
                    'title' => $event['title'],
                    'event_date' => $event['event_date'],
                    'location' => $event['location'],
                    'barangay_name' => $event['barangay_name'],
                    'capacity' => $event['capacity'],
                    'participant_count' => $event['participant_count']
                ],
                'qr_code' => $event['qr_code']
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Event not found or you are not a participant.']);
        }
        exit;
    }

    // Fetch Upcoming Events with Pagination (filtered by user's barangay)
    if ($active_tab === 'upcoming') {
        $query = "
            SELECT e.event_id, e.title, e.description, e.event_date, e.location, e.capacity,
                   (SELECT COUNT(*) FROM event_participants WHERE event_id = e.event_id) as participant_count,
                   b.name as barangay_name 
            FROM events e 
            LEFT JOIN barangays b ON e.barangay_id = b.barangay_id 
            WHERE e.event_date >= CURDATE()
        ";
        $params = [];

        // Filter by user's barangay (unless admin)
        if ($user['role'] !== 'admin') {
            $query .= " AND e.barangay_id = :user_barangay_id";
            $params['user_barangay_id'] = $user['barangay_id'];
        }

        // Apply filters
        if ($filter_date) {
            $query .= " AND e.event_date = :filter_date";
            $params['filter_date'] = $filter_date;
        }
        if ($filter_barangay && $user['role'] === 'admin') {
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
            SELECT e.event_id, e.title, e.description, e.event_date, e.location, e.capacity,
                   (SELECT COUNT(*) FROM event_participants WHERE event_id = e.event_id) as participant_count,
                   b.name as barangay_name, ep.joined_at, ep.qr_code, ep.confirmed_at 
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
    <title>Events - Green Roots</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://unpkg.com/qrcodejs@1.0.0/qrcode.min.js" onload="qrCodeLoaded = true; console.log('QRCode library loaded from UNPKG');" onerror="loadLocalQRCode()"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jsPDF.umd.min.js"></script>
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
            transition: color 0.3s;
        }

        .sidebar a:hover,
        .sidebar a.active {
            color: #4CAF50;
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
            color: #4CAF50;
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
            background: #E8F5E9;
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
            background: #E8F5E9;
        }

        .events-nav {
            width: 100%;
            max-width: 800px;
            background: #BBEBBF;
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
            font-weight: bold;
            padding: 10px 20px;
            transition: color 0.5s, background 0.5s;
            border-radius: 10px;
        }

        .events-nav a.active {
            color: #fff;
            background: #4CAF50;
        }

        .events-nav a:hover {
            color: #4CAF50;
            background:rgb(156, 214, 161);
        }

        .events-section {
            background: #E8F5E9;
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
            color: #4CAF50;
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
            gap: 20px;
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
            background: #4CAF50;
            border-radius: 10px;
        }

        .events-list::-webkit-scrollbar-thumb:hover {
            background: #388E3C;
        }

        .event-card {
            background: #fff;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            gap: 15px;
            border-left: 5px solid #4CAF50;
        }

        .event-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 10px;
        }

        .event-card-header h3 {
            font-size: 18px;
            color: #4CAF50;
            margin: 0;
            word-wrap: break-word;
            max-width: 70%;
        }

        .event-card-body {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .event-details {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .event-detail-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 15px;
            color: #333;
            line-height: 1.6;
        }

        .event-detail-item i {
            color: #4CAF50;
        }

        .event-detail-item.description {
            display: block;
            margin-top: 5px;
        }

        .event-detail-item.capacity {
            font-weight: bold;
            color: #666;
        }

        .event-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
        }

        .event-card .join-btn {
            background: #4CAF50;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            font-size: 14px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .event-card .join-btn:hover {
            background: #388E3C;
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

        .status-confirmed {
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
            color: #4CAF50;
            text-decoration: none;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            transition: background 0.3s;
        }

        .pagination a:hover {
            background: #E8F5E9;
        }

        .pagination a.disabled {
            color: #ccc;
            pointer-events: none;
            background: #f5f5f5;
        }

        .pagination a.active {
            background: #4CAF50;
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

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: #fff;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            position: relative;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }

        .modal-content h3 {
            font-size: 24px;
            color: #4CAF50;
            margin-bottom: 20px;
            text-align: center;
        }

        .modal-content .modal-details {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 20px;
        }

        .modal-detail-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 16px;
            color: #333;
            line-height: 1.6;
        }

        .modal-detail-item i {
            color: #4CAF50;
        }

        .modal-content .qr-code {
            margin: 20px 0;
            text-align: center;
        }

        .modal-content .download-btn {
            background: #4CAF50;
            color: #fff;
            border: none;
            padding: 12px 25px;
            border-radius: 25px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s;
            display: block;
            margin: 0 auto;
        }

        .modal-content .download-btn:hover {
            background: #388E3C;
        }

        .modal-content .close-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 24px;
            color: #666;
            cursor: pointer;
        }

        .modal-content .close-btn:hover {
            color: #dc2626;
        }

        /* Loading Spinner for AJAX */
        .loading-spinner {
            display: none;
            text-align: center;
            margin: 20px 0;
        }

        .loading-spinner.active {
            display: block;
        }

        .loading-spinner::after {
            content: '';
            display: inline-block;
            width: 24px;
            height: 24px;
            border: 3px solid #4CAF50;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
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
                background: #4CAF50;
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
                padding: 15px;
                gap: 10px;
            }

            .event-card-header h3 {
                font-size: 16px;
                max-width: 100%;
            }

            .event-detail-item {
                font-size: 13px;
            }

            .event-actions {
                justify-content: center;
            }

            .event-card .join-btn {
                width: 100%;
                padding: 8px;
                font-size: 12px;
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

            .modal-content {
                padding: 20px;
                width: 95%;
            }

            .modal-content h3 {
                font-size: 20px;
            }

            .modal-detail-item {
                font-size: 14px;
            }

            .modal-content .download-btn {
                font-size: 14px;
                padding: 8px 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <img src="<?php echo $logo_base64; ?>" alt="Green Roots Logo" class="logo">
            <a href="dashboard.php" title="Dashboard"><i class="fas fa-home"></i></a>
            <a href="submit.php" title="Submit Planting"><i class="fas fa-tree"></i></a>
            <a href="planting_site.php" title="Planting Site"><i class="fas fa-map-marker-alt"></i></a>
            <a href="leaderboard.php" title="Leaderboard"><i class="fas fa-trophy"></i></a>
            <a href="rewards.php" title="Rewards"><i class="fas fa-gift"></i></a>
            <a href="events.php" title="Events" class="active"><i class="fas fa-calendar-alt"></i></a>
            <a href="history.php" title="History"><i class="fas fa-history"></i></a>
            <a href="feedback.php" title="Feedback"><i class="fas fa-comment-dots"></i></a>
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
                    <img src="<?php echo $profile_picture_data; ?>" alt="Profile Picture">
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
                <?php if ($active_tab === 'upcoming'): ?>
                    <h2>Upcoming Events</h2>
                    <div class="filter-bar">
                        <div>
                            <label for="filter_date">Date:</label>
                            <input type="date" id="filter_date" name="filter_date" value="<?php echo htmlspecialchars($filter_date); ?>">
                        </div>
                        <?php if ($user['role'] === 'admin'): ?>
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
                        <?php endif; ?>
                    </div>
                    <div class="events-list">
                        <?php if (empty($upcoming_events)): ?>
                            <p class="no-data">No upcoming events available in your barangay (<?php echo htmlspecialchars($user['barangay_name'] ?? 'N/A'); ?>).</p>
                        <?php else: ?>
                            <?php foreach ($upcoming_events as $event): ?>
                                <?php
                                    $event_date = new DateTime($event['event_date']);
                                    $today = new DateTime();
                                    $status = $event_date->format('Y-m-d') === $today->format('Y-m-d') ? 'ongoing' : ($event_date > $today ? 'upcoming' : 'past');
                                    $has_joined = in_array($event['event_id'], $joined_events);
                                    $spots_left = $event['capacity'] ? ($event['capacity'] - $event['participant_count']) : 'Unlimited';
                                ?>
                                <div class="event-card">
                                    <div class="event-card-header">
                                        <h3><?php echo htmlspecialchars($event['title']); ?></h3>
                                        <span class="event-status status-<?php echo $status; ?>"><?php echo ucfirst($status); ?></span>
                                    </div>
                                    <div class="event-card-body">
                                        <div class="event-details">
                                            <div class="event-detail-item">
                                                <i class="fas fa-calendar-alt"></i>
                                                <span><?php echo date('F j, Y', strtotime($event['event_date'])); ?></span>
                                            </div>
                                            <div class="event-detail-item">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <span><?php echo htmlspecialchars($event['location']); ?></span>
                                            </div>
                                            <div class="event-detail-item">
                                                <i class="fas fa-map"></i>
                                                <span><?php echo htmlspecialchars($event['barangay_name'] ?? 'N/A'); ?></span>
                                            </div>
                                            <?php if ($event['capacity']): ?>
                                                <div class="event-detail-item capacity">
                                                    <i class="fas fa-users"></i>
                                                    <span>Spots Left: <?php echo $spots_left; ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="event-detail-item description">
                                                <?php echo htmlspecialchars($event['description'] ?? 'No description available.'); ?>
                                            </div>
                                        </div>
                                        <div class="event-actions">
                                            <?php if (!$has_joined && $status !== 'past' && ($spots_left === 'Unlimited' || $spots_left > 0)): ?>
                                                <button class="join-btn" data-event-id="<?php echo $event['event_id']; ?>">Join Event</button>
                                            <?php else: ?>
                                                <button class="join-btn disabled" disabled>
                                                    <?php echo $has_joined ? 'Joined' : ($status === 'past' ? 'Event Ended' : 'No Spots Left'); ?>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <h2>My Events</h2>
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
                        <?php if (empty($my_events)): ?>
                            <p class="no-data">You haven’t joined any events yet.</p>
                        <?php else: ?>
                            <?php foreach ($my_events as $event): ?>
                                <?php
                                    $event_date = new DateTime($event['event_date']);
                                    $today = new DateTime();
                                    $status = $event_date->format('Y-m-d') === $today->format('Y-m-d') ? 'ongoing' : ($event_date > $today ? 'upcoming' : 'past');
                                    $spots_left = $event['capacity'] ? ($event['capacity'] - $event['participant_count']) : 'Unlimited';
                                ?>
                                <div class="event-card">
                                    <div class="event-card-header">
                                        <h3><?php echo htmlspecialchars($event['title']); ?></h3>
                                        <span class="event-status status-<?php echo $status; ?>"><?php echo ucfirst($status); ?></span>
                                    </div>
                                    <div class="event-card-body">
                                        <div class="event-details">
                                            <div class="event-detail-item">
                                                <i class="fas fa-calendar-alt"></i>
                                                <span><?php echo date('F j, Y', strtotime($event['event_date'])); ?></span>
                                            </div>
                                            <div class="event-detail-item">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <span><?php echo htmlspecialchars($event['location']); ?></span>
                                            </div>
                                            <div class="event-detail-item">
                                                <i class="fas fa-map"></i>
                                                <span><?php echo htmlspecialchars($event['barangay_name'] ?? 'N/A'); ?></span>
                                            </div>
                                            <?php if ($event['capacity']): ?>
                                                <div class="event-detail-item capacity">
                                                    <i class="fas fa-users"></i>
                                                    <span>Spots Left: <?php echo $spots_left; ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="event-detail-item description">
                                                <?php echo htmlspecialchars($event['description'] ?? 'No description available.'); ?>
                                            </div>
                                            <div class="event-detail-item">
                                                <i class="fas fa-clock"></i>
                                                <span>Joined At: <?php echo date('F j, Y, g:i A', strtotime($event['joined_at'])); ?></span>
                                            </div>
                                            <?php if ($event['confirmed_at']): ?>
                                                <div class="event-detail-item">
                                                    <i class="fas fa-check-circle"></i>
                                                    <span class="status-confirmed">Confirmed At: <?php echo date('F j, Y, g:i A', strtotime($event['confirmed_at'])); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="event-actions">
                                            <?php if (!$event['confirmed_at']): ?>
                                                <button class="join-btn view-qr-btn" data-event-id="<?php echo $event['event_id']; ?>">View Ticket</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
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
            <div class="loading-spinner" id="loadingSpinner"></div>
        </div>
    </div>

    <!-- Modal for QR Code -->
    <div class="modal" id="qrModal">
        <div class="modal-content">
            <span class="close-btn" id="closeModal">×</span>
            <h3>Event Ticket</h3>
            <div class="modal-details">
                <div class="modal-detail-item">
                    <i class="fas fa-user"></i>
                    <span id="modalUsername"></span>
                </div>
                <div class="modal-detail-item">
                    <i class="fas fa-envelope"></i>
                    <span id="modalEmail"></span>
                </div>
                <div class="modal-detail-item">
                    <i class="fas fa-calendar-alt"></i>
                    <span id="modalEventTitle"></span> - <span id="modalEventDate"></span>
                </div>
                <div class="modal-detail-item">
                    <i class="fas fa-map-marker-alt"></i>
                    <span id="modalEventLocation"></span>, <span id="modalEventBarangay"></span>
                </div>
            </div>
            <div class="qr-code" id="modalQRCode"></div>
            <button class="download-btn" id="downloadBtn">Download Ticket</button>
        </div>
    </div>

    <script>
        // Flag to track if QRCode library loaded
        let qrCodeLoaded = false;

        // Fallback to local QRCode script
        function loadLocalQRCode() {
            console.warn('UNPKG failed to load QRCode library. Attempting to load local fallback...');
            const script = document.createElement('script');
            script.src = '../assets/qrcode.min.js'; // Ensure this file exists
            script.onload = () => {
                qrCodeLoaded = true;
                console.log('Local QRCode library loaded successfully.');
            };
            script.onerror = () => {
                qrCodeLoaded = false;
                console.error('Failed to load local QRCode library. Please ensure ../assets/qrcode.min.js exists.');
            };
            document.head.appendChild(script);
        }

        // Search bar functionality
        const functionalities = [
            { name: 'Dashboard', url: 'dashboard.php' },
            { name: 'Submit Planting', url: 'submit.php' },
            { name: 'Planting Site', url: 'planting_site.php' },
            { name: 'Leaderboard', url: 'leaderboard.php' },
            { name: 'Rewards', url: 'rewards.php' },
            { name: 'Events', url: 'events.php' },
            { name: 'History', url: 'history.php' },
            { name: 'Feedback', url: 'feedback.php' }
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
            if (filterBarangay) {
                params.set('filter_barangay', filterBarangay.value);
            }
            params.set('page', '1');
            window.location.search = params.toString();
        }

        filterDate.addEventListener('change', applyFilters);
        if (filterBarangay) {
            filterBarangay.addEventListener('change', applyFilters);
        }

        // Modal functionality
        const modal = document.querySelector('#qrModal');
        const closeModal = document.querySelector('#closeModal');
        const modalUsername = document.querySelector('#modalUsername');
        const modalEmail = document.querySelector('#modalEmail');
        const modalEventTitle = document.querySelector('#modalEventTitle');
        const modalEventDate = document.querySelector('#modalEventDate');
        const modalEventLocation = document.querySelector('#modalEventLocation');
        const modalEventBarangay = document.querySelector('#modalEventBarangay');
        const modalQRCode = document.querySelector('#modalQRCode');
        const downloadBtn = document.querySelector('#downloadBtn');
        const loadingSpinner = document.querySelector('#loadingSpinner');

        // Function to generate QR code with retry mechanism
        function generateQRCode(element, data, retries = 5, delay = 2000) {
            console.log(`Attempting to generate QR code. Retries left: ${retries}, QRCode library loaded: ${qrCodeLoaded}`);
            if (retries <= 0 || !qrCodeLoaded) {
                console.error('QRCode library failed to load after multiple attempts.');
                element.innerHTML = '<p style="color: #dc2626;">Error: Unable to generate QR code. Please reload the page and try again.</p>';
                return;
            }

            if (typeof QRCode === 'undefined') {
                console.log(`QRCode library not loaded yet. Retrying in ${delay}ms... (${retries} attempts left)`);
                setTimeout(() => generateQRCode(element, data, retries - 1, delay), delay);
                return;
            }

            console.log('Generating QR code...');
            element.innerHTML = '';
            try {
                new QRCode(element, {
                    text: data,
                    width: 150,
                    height: 150,
                    colorDark: "#000000",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.H
                });
                console.log('QR code generated successfully.');
            } catch (error) {
                console.error('Failed to generate QR code:', error);
                element.innerHTML = '<p style="color: #dc2626;">Error: Failed to generate QR code. Please try again.</p>';
            }
        }

        // Handle Join Event buttons
        document.querySelectorAll('.join-btn:not(.view-qr-btn)').forEach(button => {
            button.addEventListener('click', function() {
                const eventId = this.getAttribute('data-event-id');
                if (this.classList.contains('disabled')) return;

                // Show loading spinner
                loadingSpinner.classList.add('active');

                // AJAX request to join event
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `join_event=true&event_id=${eventId}&ajax=true`
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    loadingSpinner.classList.remove('active');
                    if (data.success) {
                        // Populate modal
                        modalUsername.textContent = data.user.username;
                        modalEmail.textContent = data.user.email;
                        modalEventTitle.textContent = data.event.title;
                        modalEventDate.textContent = new Date(data.event.event_date).toLocaleDateString('en-US', {
                            month: 'long',
                            day: 'numeric',
                            year: 'numeric'
                        });
                        modalEventLocation.textContent = data.event.location;
                        modalEventBarangay.textContent = data.event.barangay_name || 'N/A';
                        
                        // Generate QR code
                        generateQRCode(modalQRCode, data.qr_code);

                        // Show modal
                        modal.style.display = 'flex';

                        // Store data for PDF generation
                        downloadBtn.onclick = () => generatePDF(data);
                    } else {
                        alert(data.message || 'Failed to join event.');
                    }
                })
                .catch(error => {
                    loadingSpinner.classList.remove('active');
                    console.error('Error:', error);
                    alert('An error occurred while joining the event: ' + error.message);
                });
            });
        });

        // Handle View QR Code buttons in My Events
        document.querySelectorAll('.view-qr-btn').forEach(button => {
            button.addEventListener('click', function() {
                const eventId = this.getAttribute('data-event-id');

                // Show loading spinner
                loadingSpinner.classList.add('active');

                // AJAX request to view QR code
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `view_qr_code=true&event_id=${eventId}`
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    loadingSpinner.classList.remove('active');
                    if (data.success) {
                        // Populate modal
                        modalUsername.textContent = data.user.username;
                        modalEmail.textContent = data.user.email;
                        modalEventTitle.textContent = data.event.title;
                        modalEventDate.textContent = new Date(data.event.event_date).toLocaleDateString('en-US', {
                            month: 'long',
                            day: 'numeric',
                            year: 'numeric'
                        });
                        modalEventLocation.textContent = data.event.location;
                        modalEventBarangay.textContent = data.event.barangay_name || 'N/A';
                        
                        // Generate QR code
                        generateQRCode(modalQRCode, data.qr_code);

                        // Show modal
                        modal.style.display = 'flex';

                        // Store data for PDF generation
                        downloadBtn.onclick = () => generatePDF(data);
                    } else {
                        alert(data.message || 'Failed to fetch ticket.');
                    }
                })
                .catch(error => {
                    loadingSpinner.classList.remove('active');
                    console.error('Error:', error);
                    alert('An error occurred while fetching the ticket: ' + error.message);
                });
            });
        });

        // Close modal
        closeModal.addEventListener('click', () => {
            modal.style.display = 'none';
        });

        window.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        });

        // Generate PDF with retry mechanism for QRCode
        function generatePDF(data) {
            if (typeof window.jspdf === 'undefined') {
                alert('PDF library not loaded. Please try again.');
                return;
            }
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();

            // Set up the ticket design
            doc.setFontSize(20);
            doc.setTextColor(76, 175, 80); // #4CAF50
            doc.text('Tree Planting Event Ticket', 105, 20, { align: 'center' });

            doc.setFontSize(12);
            doc.setTextColor(51, 51, 51); // #333
            doc.text('Green Roots Initiative', 105, 30, { align: 'center' });

            // Event Details
            doc.setFontSize(12);
            doc.text(`User: ${data.user.username}`, 20, 50);
            doc.text(`Email: ${data.user.email}`, 20, 60);
            doc.text(`Event: ${data.event.title}`, 20, 70);
            doc.text(`Date: ${new Date(data.event.event_date).toLocaleDateString('en-US', {
                month: 'long',
                day: 'numeric',
                year: 'numeric'
            })}`, 20, 80);
            doc.text(`Location: ${data.event.location}`, 20, 90);
            doc.text(`Barangay: ${data.event.barangay_name || 'N/A'}`, 20, 100);

            // Generate QR code for PDF with retry mechanism
            const canvas = document.createElement('canvas');
            function generateQRCodeForPDF(retries = 5, delay = 2000) {
                console.log(`Attempting to generate QR code for PDF. Retries left: ${retries}, QRCode library loaded: ${qrCodeLoaded}`);
                if (retries <= 0 || !qrCodeLoaded) {
                    doc.setTextColor(220, 38, 38); // #dc2626
                    doc.text('Error: Unable to generate QR code.', 20, 120);
                    doc.save(`Event_${data.event.title}_Ticket.pdf`);
                    return;
                }

                if (typeof QRCode === 'undefined') {
                    console.log(`QRCode library not loaded for PDF generation. Retrying in ${delay}ms... (${retries} attempts left)`);
                    setTimeout(() => generateQRCodeForPDF(retries - 1, delay), delay);
                    return;
                }

                try {
                    new QRCode(canvas, {
                        text: data.qr_code,
                        width: 100,
                        height: 100,
                        colorDark: "#000000",
                        colorLight: "#ffffff",
                        correctLevel: QRCode.CorrectLevel.H
                    });

                    const qrImage = canvas.toDataURL('image/png');
                    doc.addImage(qrImage, 'PNG', 80, 110, 50, 50);

                    // Add a border around the QR code
                    doc.setDrawColor(76, 175, 80); // #4CAF50
                    doc.setLineWidth(0.5);
                    doc.rect(80, 110, 50, 50);

                    // Add a note
                    doc.setFontSize(10);
                    doc.setTextColor(102, 102, 102); // #666
                    doc.text('Present this QR code at the event for attendance verification.', 105, 170, { align: 'center' });

                    doc.save(`Event_${data.event.title}_Ticket.pdf`);
                    console.log('PDF generated successfully with QR code.');
                } catch (error) {
                    console.error('Failed to generate QR code for PDF:', error);
                    doc.setTextColor(220, 38, 38);
                    doc.text('Error: Unable to generate QR code.', 20, 120);
                    doc.save(`Event_${data.event.title}_Ticket.pdf`);
                }
            }

            generateQRCodeForPDF();
        }

        // Accessibility: Close modal with Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.style.display === 'flex') {
                modal.style.display = 'none';
            }
        });

        // Accessibility: Focus on modal when opened
        modal.addEventListener('transitionend', () => {
            if (modal.style.display === 'flex') {
                document.querySelector('.modal-content').focus();
            }
        });
    </script>
</body>
</html>