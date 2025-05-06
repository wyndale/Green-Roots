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

// Fetch user data including barangay and profile picture
try {
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.email, u.profile_picture, u.default_profile_asset_id, u.role, u.barangay_id, u.first_name,
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

    // Now that $user is defined, we can safely use it
    $filter_region = $user['region_name']; // Fixed to user's region

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

    // Fetch favicon and logo
    $stmt = $pdo->prepare("SELECT asset_data FROM assets WHERE asset_type = 'favicon' LIMIT 1");
    $stmt->execute();
    $favicon_data = $stmt->fetchColumn();
    $favicon_base64 = $favicon_data ? 'data:image/png;base64,' . base64_encode($favicon_data) : '../assets/favicon.png';

    $stmt = $pdo->prepare("SELECT asset_data FROM assets WHERE asset_type = 'logo' LIMIT 1");
    $stmt->execute();
    $logo_data = $stmt->fetchColumn();
    $logo_base64 = $logo_data ? 'data:image/png;base64,' . base64_encode($logo_data) : 'logo.png';
} catch (PDOException $e) {
    $error_message = "Error: " . $e->getMessage();
    // Since $user might not be defined, we should exit early if there's an error here
    $user = null;
    $filter_region = ''; // Fallback value
}

// Pagination settings
$events_per_page = 10;
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'upcoming';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $events_per_page;

// Filters
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';
$filter_province = isset($_GET['filter_province']) ? $_GET['filter_province'] : '';
$filter_city = isset($_GET['filter_city']) ? $_GET['filter_city'] : '';
$filter_barangay = isset($_GET['filter_barangay']) ? $_GET['filter_barangay'] : '';

// Initialize variables
$upcoming_events = [];
$my_events = [];
$upcoming_total = 0;
$my_events_total = 0;
$provinces = [];
$cities = [];
$barangays = [];
$join_message = '';
$join_message_type = '';

// Check for session messages
if (isset($_SESSION['join_message'])) {
    $join_message = $_SESSION['join_message'];
    $join_message_type = $_SESSION['join_message_type'] ?? 'error';
    unset($_SESSION['join_message']);
    unset($_SESSION['join_message_type']);
}

// Function to generate QR code data
function generateQRCodeData($user_id, $event_id)
{
    return hash('sha256', $user_id . $event_id . time());
}

try {
    // Fetch cascading dropdown options based on user's region
    if ($user) { // Ensure $user exists before proceeding
        $stmt = $pdo->prepare("SELECT DISTINCT province FROM barangays WHERE region = :region AND province IS NOT NULL ORDER BY province");
        $stmt->execute(['region' => $user['region_name']]);
        $provinces = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($filter_province) {
            $stmt = $pdo->prepare("SELECT DISTINCT city FROM barangays WHERE region = :region AND province = :province AND city IS NOT NULL ORDER BY city");
            $stmt->execute(['region' => $user['region_name'], 'province' => $filter_province]);
            $cities = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        if ($filter_city) {
            $stmt = $pdo->prepare("SELECT DISTINCT name FROM barangays WHERE region = :region AND province = :province AND city = :city AND name IS NOT NULL ORDER BY name");
            $stmt->execute(['region' => $user['region_name'], 'province' => $filter_province, 'city' => $filter_city]);
            $barangays = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        // Handle Join Event
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join_event'])) {
            $event_id = (int)$_POST['event_id'];

            // Check if the event has available spots
            $stmt = $pdo->prepare("SELECT capacity, (SELECT COUNT(*) FROM event_participants WHERE event_id = :event_id) as participant_count FROM events WHERE event_id = :event_id");
            $stmt->execute(['event_id' => $event_id]);
            $event_capacity = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($event_capacity && $event_capacity['capacity'] && $event_capacity['participant_count'] >= $event_capacity['capacity']) {
                if (isset($_POST['ajax'])) {
                    echo json_encode(['success' => false, 'message' => 'This event has reached its capacity.']);
                    exit;
                }
                $_SESSION['join_message'] = 'This event has reached its capacity.';
                $_SESSION['join_message_type'] = 'error';
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
                    $_SESSION['join_message'] = 'You have already joined this event.';
                    $_SESSION['join_message_type'] = 'error';
                } else {
                    // Fetch event details for logging
                    $stmt = $pdo->prepare("
                        SELECT title, event_date, location 
                        FROM events 
                        WHERE event_id = :event_id
                    ");
                    $stmt->execute(['event_id' => $event_id]);
                    $event = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($event) {
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

                        // Log activity with detailed info
                        $stmt = $pdo->prepare("
                            INSERT INTO activities (
                                user_id, description, activity_type, event_title, event_date, location, created_at
                            ) VALUES (
                                :user_id, :description, 'event', :event_title, :event_date, :location, NOW()
                            )
                        ");
                        $stmt->execute([
                            'user_id' => $user_id,
                            'description' => "Joined event: {$event['title']}",
                            'event_title' => $event['title'],
                            'event_date' => $event['event_date'],
                            'location' => $event['location']
                        ]);

                        // For AJAX requests, return event details for the modal
                        if (isset($_POST['ajax'])) {
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

                        // For non-AJAX requests, set success message and redirect
                        $_SESSION['join_message'] = 'Successfully joined the event! Check your QR code in My Events.';
                        $_SESSION['join_message_type'] = 'success';
                    } else {
                        if (isset($_POST['ajax'])) {
                            echo json_encode(['success' => false, 'message' => 'Event not found.']);
                            exit;
                        }
                        $_SESSION['join_message'] = 'Event not found.';
                        $_SESSION['join_message_type'] = 'error';
                    }
                }
            }

            // Redirect for non-AJAX requests to prevent form resubmission
            header('Location: events.php?tab=upcoming');
            exit;
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

        // Fetch Upcoming Events with Pagination (filtered by user's region)
        if ($active_tab === 'upcoming') {
            $query = "
                SELECT e.event_id, e.title, e.description, e.event_date, e.location, e.capacity,
                       (SELECT COUNT(*) FROM event_participants WHERE event_id = e.event_id) as participant_count,
                       b.name as barangay_name 
                FROM events e 
                LEFT JOIN barangays b ON e.barangay_id = b.barangay_id
                WHERE e.event_date >= CURDATE() AND b.region = :region
            ";
            $params = ['region' => $user['region_name']];

            // Filter by selected province, city, and barangay within Region X
            if ($filter_province) {
                $query .= " AND b.province = :province";
                $params['province'] = $filter_province;
            }
            if ($filter_city) {
                $query .= " AND b.city = :city";
                $params['city'] = $filter_city;
            }
            if ($filter_barangay) {
                $query .= " AND b.name = :barangay";
                $params['barangay'] = $filter_barangay;
            }

            // Apply date filter
            if ($filter_date) {
                $query .= " AND e.event_date = :filter_date";
                $params['filter_date'] = $filter_date;
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
    <link rel="icon" type="image/png" href="<?php echo $favicon_base64; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/events.css">
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jsPDF.umd.min.js" defer></script>
</head>

<body>
    <div class="container">
        <div class="sidebar">
            <img src="<?php echo $logo_base64; ?>" alt="Green Roots Logo" class="logo">
            <a href="dashboard.php" title="Dashboard"><i class="fas fa-home"></i></a>
            <a href="submit.php" title="Submit Planting"><i class="fas fa-leaf"></i></a>
            <a href="planting_site.php" title="Planting Site"><i class="fas fa-map-pin"></i></a>
            <a href="leaderboard.php" title="Leaderboard"><i class="fas fa-crown"></i></a>
            <a href="rewards.php" title="Rewards"><i class="fas fa-gift"></i></a>
            <a href="events.php" title="Events" class="active"><i class="fas fa-calendar-days"></i></a>
            <a href="history.php" title="History"><i class="fas fa-clock"></i></a>
            <a href="feedback.php" title="Feedback"><i class="fas fa-comment"></i></a>
        </div>
        <div class="main-content">
            <?php if (isset($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <?php if ($join_message): ?>
                <div class="<?php echo $join_message_type === 'success' ? 'join-message' : 'join-error'; ?>">
                    <?php echo htmlspecialchars($join_message); ?>
                </div>
            <?php endif; ?>
            <div class="header">
                <h1>Events</h1>
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
                            <label for="filter_date"><i class="fas fa-calendar-alt"></i> Date</label>
                            <input type="date" id="filter_date" name="filter_date" value="<?php echo htmlspecialchars($filter_date); ?>">
                        </div>
                        <div>
                            <label for="filter_province"><i class="fas fa-map"></i> Province</label>
                            <select id="filter_province" name="filter_province">
                                <option value="">Select Province</option>
                                <?php foreach ($provinces as $province): ?>
                                    <option value="<?php echo htmlspecialchars($province); ?>" <?php echo $filter_province === $province ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($province); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="filter_city"><i class="fas fa-map"></i> City</label>
                            <select id="filter_city" name="filter_city">
                                <option value="">Select City</option>
                                <?php foreach ($cities as $city): ?>
                                    <option value="<?php echo htmlspecialchars($city); ?>" <?php echo $filter_city === $city ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($city); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="filter_barangay"><i class="fas fa-map"></i> Barangay</label>
                            <select id="filter_barangay" name="filter_barangay">
                                <option value="">Select Barangay</option>
                                <?php foreach ($barangays as $barangay): ?>
                                    <option value="<?php echo htmlspecialchars($barangay); ?>" <?php echo $filter_barangay === $barangay ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($barangay); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="events-list">
                        <?php if (empty($upcoming_events)): ?>
                            <p class="no-data">No upcoming events available.</p>
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
                            <label for="filter_date"><i class="fas fa-calendar-alt"></i> Date</label>
                            <input type="date" id="filter_date" name="filter_date" value="<?php echo htmlspecialchars($filter_date); ?>">
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
                        'filter_province' => $filter_province,
                        'filter_city' => $filter_city,
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

    <script src="../assets/js/jsPDF-3.0.1/dist/jspdf.umd.min.js"></script>
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

// Filter functionality
const filterDate = document.querySelector('#filter_date');
const filterProvince = document.querySelector('#filter_province');
const filterCity = document.querySelector('#filter_city');
const filterBarangay = document.querySelector('#filter_barangay');

function updateCityOptions() {
    const province = filterProvince.value;
    const url = `?tab=upcoming&filter_date=${filterDate.value}&filter_province=${province}&filter_city=&filter_barangay=`;
    window.location.href = url;
}

function updateBarangayOptions() {
    const city = filterCity.value;
    const url = `?tab=upcoming&filter_date=${filterDate.value}&filter_province=${filterProvince.value}&filter_city=${city}&filter_barangay=`;
    window.location.href = url;
}

function applyFilters() {
    const params = new URLSearchParams(window.location.search);
    params.set('filter_date', filterDate.value);
    params.set('filter_province', filterProvince.value);
    params.set('filter_city', filterCity.value);
    params.set('filter_barangay', filterBarangay.value);
    params.set('page', '1');
    window.location.search = params.toString();
}

filterDate.addEventListener('change', applyFilters);
if (filterProvince) {
    filterProvince.addEventListener('change', function() {
        updateCityOptions();
    });
}
if (filterCity) {
    filterCity.addEventListener('change', function() {
        updateBarangayOptions();
    });
}
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

// Ensure QRCode library is loaded before generating QR codes
function waitForQRCode(callback) {
    if (typeof QRCode !== 'undefined') {
        callback();
    } else {
        const maxAttempts = 10;
        let attempts = 0;
        const interval = setInterval(() => {
            attempts++;
            if (typeof QRCode !== 'undefined') {
                clearInterval(interval);
                callback();
            } else if (attempts >= maxAttempts) {
                clearInterval(interval);
                console.error('QRCode library failed to load after maximum attempts.');
                modalQRCode.innerHTML = '<p style="color: #dc2626;">Error: Unable to generate QR code. Please try again later.</p>';
                loadingSpinner.classList.remove('active');
            }
        }, 500);
    }
}

// Generate QR code
function generateQRCode(element, data) {
    waitForQRCode(() => {
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
    });
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
                loadingSpinner.classList.remove('active');
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
                loadingSpinner.classList.remove('active');
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

// Generate PDF
function generatePDF(data) {
    loadingSpinner.classList.add('active');

    try {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();

        // Fonts and colors
        const primaryColor = [76, 175, 80]; // #4CAF50
        const secondaryColor = [51, 51, 51]; // #333
        const labelColor = [120, 120, 120]; // #777

        // Header
        doc.setFontSize(22);
        doc.setTextColor(...primaryColor);
        doc.setFont('helvetica', 'bold');
        doc.text('Tree Planting Event Ticket', 105, 25, { align: 'center' });

        doc.setFontSize(14);
        doc.setFont('helvetica', 'normal');
        doc.setTextColor(...secondaryColor);
        doc.text('Presented by Green Roots Initiative', 105, 35, { align: 'center' });

        // Draw horizontal divider
        doc.setDrawColor(...primaryColor);
        doc.setLineWidth(0.5);
        doc.line(20, 40, 190, 40);

        // User & Event Info
        doc.setFontSize(12);
        doc.setTextColor(...labelColor);
        doc.text('Name:', 20, 55);
        doc.text('Email:', 20, 65);
        doc.text('Event:', 20, 75);
        doc.text('Date:', 20, 85);
        doc.text('Location:', 20, 95);
        doc.text('Barangay:', 20, 105);

        doc.setFont('helvetica', 'bold');
        doc.setTextColor(...secondaryColor);
        doc.text(`${data.user.username}`, 50, 55);
        doc.text(`${data.user.email}`, 50, 65);
        doc.text(`${data.event.title}`, 50, 75);
        doc.text(
            new Date(data.event.event_date).toLocaleDateString('en-US', {
                month: 'long',
                day: 'numeric',
                year: 'numeric',
            }),
            50, 85
        );
        doc.text(`${data.event.location}`, 50, 95);
        doc.text(`${data.event.barangay_name || 'N/A'}`, 50, 105);

        // QR Code
        try {
            const qrCanvas = modalQRCode.querySelector('canvas');
            if (!qrCanvas) throw new Error('QR code canvas not found in modal');

            const qrImage = qrCanvas.toDataURL('image/png');
            if (qrImage === 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAJIAAACWCAYAAABs0x1ZAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAABeSURBVHhe7cEBDQAAAMKg909tDjtwABS7sYMgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICB7B8HbgD4qS5cAAAAASUVORK5CYII=') {
                throw new Error('QR code canvas is empty');
            }

            doc.addImage(qrImage, 'PNG', 80, 120, 50, 50);
            doc.setDrawColor(...primaryColor);
            doc.rect(80, 120, 50, 50);

            // Note
            doc.setFontSize(10);
            doc.setTextColor(102, 102, 102);
            doc.setFont('helvetica', 'italic');
            doc.text('Present this QR code at the event for attendance verification.', 105, 180, { align: 'center' });

            doc.save(`Event_${data.event.title}_Ticket.pdf`);
            console.log('PDF generated successfully with QR code.');
        } catch (error) {
            console.error('Failed to add QR code to PDF:', error);
            doc.setTextColor(220, 38, 38);
            doc.setFont('helvetica', 'bold');
            doc.text('Unable to include QR code.', 20, 130);
            doc.save(`Event_${data.event.title}_Ticket.pdf`);
            alert('Failed to include QR code in the PDF. The ticket has been downloaded without it.');
        }

        loadingSpinner.classList.remove('active');
    } catch (error) {
        console.error('Failed to generate PDF:', error);
        loadingSpinner.classList.remove('active');
        alert('Error: Failed to generate PDF');
    }
}
    </script>
</body>
</html>