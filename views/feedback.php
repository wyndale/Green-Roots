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

// Pagination settings for feedback history
$entries_per_page = 10;
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'submit';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $entries_per_page;

// Initialize variables
$feedback_history = [];
$feedback_total = 0;
$feedback_error = '';
$feedback_success = '';

// Check for session messages
if (isset($_SESSION['feedback_success'])) {
    $feedback_success = $_SESSION['feedback_success'];
    unset($_SESSION['feedback_success']);
}
if (isset($_SESSION['feedback_error'])) {
    $feedback_error = $_SESSION['feedback_error'];
    unset($_SESSION['feedback_error']);
}

// Retrieve form inputs from session if available
$form_inputs = isset($_SESSION['form_inputs']) ? $_SESSION['form_inputs'] : [
    'category' => '',
    'rating' => '',
    'comments' => '',
    'is_anonymous' => false
];
unset($_SESSION['form_inputs']);

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

    // Ensure only regular users can access this page
    if ($user['role'] !== 'user') {
        header('Location: ../access/access_denied.php');
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

    // Handle Feedback Submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $active_tab === 'submit') {
        // Check for recent submission (within 24 hours)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM feedback 
            WHERE user_id = :user_id 
            AND submitted_at >= NOW() - INTERVAL 24 HOUR
        ");
        $stmt->execute(['user_id' => $user_id]);
        $recent_submission = $stmt->fetchColumn();

        if ($recent_submission > 0) {
            $_SESSION['feedback_error'] = 'You can only submit feedback once every 24 hours.';
            $_SESSION['form_inputs'] = [
                'category' => $_POST['category'] ?? '',
                'rating' => isset($_POST['rating']) ? (int)$_POST['rating'] : 0,
                'comments' => trim($_POST['comments'] ?? ''),
                'is_anonymous' => isset($_POST['is_anonymous'])
            ];
        } else {
            $category = $_POST['category'] ?? '';
            $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
            $comments = trim($_POST['comments'] ?? '');
            $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;

            // Validate inputs
            if (!in_array($category, ['bug', 'feature', 'general'])) {
                $_SESSION['feedback_error'] = 'Please select a valid category.';
                $_SESSION['form_inputs'] = [
                    'category' => '',
                    'rating' => $rating,
                    'comments' => $comments,
                    'is_anonymous' => $is_anonymous
                ];
            } elseif ($rating < 1 || $rating > 5) {
                $_SESSION['feedback_error'] = 'Please provide a rating between 1 and 5.';
                $_SESSION['form_inputs'] = [
                    'category' => $category,
                    'rating' => 0,
                    'comments' => $comments,
                    'is_anonymous' => $is_anonymous
                ];
            } elseif (empty($comments)) {
                $_SESSION['feedback_error'] = 'Comments are required.';
                $_SESSION['form_inputs'] = [
                    'category' => $category,
                    'rating' => $rating,
                    'comments' => '',
                    'is_anonymous' => $is_anonymous
                ];
            } elseif (strlen($comments) > 1000) {
                $_SESSION['feedback_error'] = 'Comments cannot exceed 1000 characters.';
                $_SESSION['form_inputs'] = [
                    'category' => $category,
                    'rating' => $rating,
                    'comments' => $comments,
                    'is_anonymous' => $is_anonymous
                ];
            } else {
                // Insert feedback into the database
                $stmt = $pdo->prepare("
                    INSERT INTO feedback (user_id, category, rating, comments, is_anonymous, submitted_at) 
                    VALUES (:user_id, :category, :rating, :comments, :is_anonymous, NOW())
                ");
                $stmt->execute([
                    'user_id' => $user_id,
                    'category' => $category,
                    'rating' => $rating,
                    'comments' => $comments,
                    'is_anonymous' => $is_anonymous
                ]);

                // Log activity
                $stmt = $pdo->prepare("
                    INSERT INTO activities (user_id, description, activity_type, created_at) 
                    VALUES (:user_id, 'Submitted feedback', 'feedback', NOW())
                ");
                $stmt->execute(['user_id' => $user_id]);

                $_SESSION['feedback_success'] = 'Thanks you! Your feedback was submitted successfully—we appreciate it.';
            }
        }

        // Redirect to prevent form resubmission
        header('Location: feedback.php?tab=submit');
        exit;
    }

    // Fetch Feedback History with Pagination
    if ($active_tab === 'history') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM feedback WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $user_id]);
        $feedback_total = $stmt->fetchColumn();
        $feedback_pages = ceil($feedback_total / $entries_per_page);

        $stmt = $pdo->prepare("
            SELECT feedback_id, category, rating, comments, is_anonymous, status, submitted_at, response 
            FROM feedback 
            WHERE user_id = :user_id 
            ORDER BY submitted_at DESC 
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $entries_per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $feedback_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    <title>Feedback - Green Roots</title>
    <link rel="icon" type="image/png" href="<?php echo $favicon_base64; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/feedback.css">
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
            <a href="feedback.php" title="Feedback" class="active"><i class="fas fa-comment"></i></a>
        </div>
        <div class="main-content">
            <?php if (isset($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <div class="header">
                <h1>Feedback</h1>
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
                        <a href="account_settings.php" class="dropdown-link">Account</a>
                        <a href="logout.php" class="dropdown-link">Logout</a>
                    </div>
                </div>
            </div>
            <div class="feedback-nav">
                <a href="?tab=submit" class="<?php echo $active_tab === 'submit' ? 'active' : ''; ?>">
                    <i class="fas fa-comment"></i> Submit Feedback
                </a>
                <a href="?tab=history" class="<?php echo $active_tab === 'history' ? 'active' : ''; ?>">
                    <i class="fas fa-clock"></i> Feedback History
                </a>
            </div>
            <div class="feedback-section <?php echo $active_tab === 'history' ? 'history-section' : ''; ?>">
                <?php if ($active_tab === 'submit'): ?>
                    <h2><i class="fas fa-comment"></i> Submit Feedback</h2>
                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="category"><i class="fas fa-list"></i> Category</label>
                            <select id="category" name="category" required>
                                <option value="">Select a category</option>
                                <option value="bug" <?php echo $form_inputs['category'] === 'bug' ? 'selected' : ''; ?>>Bug Report</option>
                                <option value="feature" <?php echo $form_inputs['category'] === 'feature' ? 'selected' : ''; ?>>Feature Request</option>
                                <option value="general" <?php echo $form_inputs['category'] === 'general' ? 'selected' : ''; ?>>General Feedback</option>
                            </select>
                        </div>
                        <div class="rating-group">
                            <label><i class="fas fa-star"></i> Rating</label>
                            <div class="stars">
                                <input type="radio" id="star5" name="rating" value="5" <?php echo $form_inputs['rating'] == 5 ? 'checked' : ''; ?> required>
                                <label for="star5">★</label>
                                <input type="radio" id="star4" name="rating" value="4" <?php echo $form_inputs['rating'] == 4 ? 'checked' : ''; ?>>
                                <label for="star4">★</label>
                                <input type="radio" id="star3" name="rating" value="3" <?php echo $form_inputs['rating'] == 3 ? 'checked' : ''; ?>>
                                <label for="star3">★</label>
                                <input type="radio" id="star2" name="rating" value="2" <?php echo $form_inputs['rating'] == 2 ? 'checked' : ''; ?>>
                                <label for="star2">★</label>
                                <input type="radio" id="star1" name="rating" value="1" <?php echo $form_inputs['rating'] == 1 ? 'checked' : ''; ?>>
                                <label for="star1">★</label>
                            </div>
                            <span class="tooltip">Rate your experience (1-5 stars)</span>
                        </div>
                        <div class="form-group">
                            <label for="comments"><i class="fas fa-comment"></i> Comments</label>
                            <textarea id="comments" name="comments" required maxlength="1000" oninput="updateCharCounter()"><?php echo htmlspecialchars($form_inputs['comments']); ?></textarea>
                            <span id="charCounter" class="char-counter"><?php echo strlen($form_inputs['comments']); ?>/1000</span>
                        </div>
                        <div class="form-group checkbox">
                            <input type="checkbox" id="is_anonymous" name="is_anonymous" <?php echo $form_inputs['is_anonymous'] ? 'checked' : ''; ?>>
                            <label for="is_anonymous"><i class="fas fa-user-secret"></i> Submit anonymously</label>
                            <span class="tooltip">Your identity will not be linked to this feedback</span>
                        </div>
                        <input type="submit" value="Submit Feedback">
                    </form>
                <?php else: ?>
                    <h2><i class="fas fa-clock"></i> Feedback History</h2>
                    <div class="history-list">
                        <?php if (empty($feedback_history)): ?>
                            <p class="no-data">No feedback history available.</p>
                        <?php else: ?>
                            <?php foreach ($feedback_history as $entry): ?>
                                <div class="feedback-card">
                                    <div class="feedback-card-header">
                                        <p><strong>Date:</strong> <?php echo date('F j, Y, g:i A', strtotime($entry['submitted_at'])); ?></p>
                                        <span class="status status-<?php echo str_replace('_', '-', $entry['status']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $entry['status'])); ?>
                                        </span>
                                    </div>
                                    <div class="feedback-card-content" id="content-<?php echo $entry['feedback_id']; ?>">
                                        <p><strong>Category:</strong> <?php echo ucfirst($entry['category']); ?></p>
                                        <p><strong>Rating:</strong> <span class="rating"><?php echo str_repeat('★', $entry['rating']) . str_repeat('☆', 5 - $entry['rating']); ?></span></p>
                                        <p><strong>Comments:</strong> <?php echo htmlspecialchars($entry['comments']); ?></p>
                                        <p><strong>Anonymous:</strong> <?php echo $entry['is_anonymous'] ? 'Yes' : 'No'; ?></p>
                                        <?php if ($entry['response']): ?>
                                            <p><strong>Response:</strong> <?php echo htmlspecialchars($entry['response']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <button class="toggle-btn" onclick="toggleContent('content-<?php echo $entry['feedback_id']; ?>', this)">
                                        Show More
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="pagination">
                        <?php
                            $total_pages = $feedback_pages;
                            $prev_page = $page - 1;
                            $next_page = $page + 1;
                        ?>
                        <a href="?tab=history&page=1" class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">First</a>
                        <a href="?tab=history&page=<?php echo $prev_page; ?>" class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">Prev</a>
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <a href="?tab=history&page=<?php echo $i; ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                        <a href="?tab=history&page=<?php echo $next_page; ?>" class="<?php echo $page >= $total_pages ? 'disabled' : ''; ?>">Next</a>
                        <a href="?tab=history&page=<?php echo $total_pages; ?>" class="<?php echo $page >= $total_pages ? 'disabled' : ''; ?>">Last</a>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Modal for Success/Error Messages -->
            <?php if ($feedback_success || $feedback_error): ?>
                <div class="modal active" id="feedbackModal">
                    <div class="modal-content">
                        <span class="close-btn" onclick="closeModal('feedbackModal')">×</span>
                        <?php if ($feedback_success): ?>
                            <div class="success-message"><?php echo htmlspecialchars($feedback_success); ?></div>
                        <?php elseif ($feedback_error): ?>
                            <div class="error-message"><?php echo htmlspecialchars($feedback_error); ?></div>
                        <?php endif; ?>
                        <button onclick="closeModal('feedbackModal')">Close</button>
                    </div>
                </div>
            <?php endif; ?>
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
        const dropdownLinks = document.querySelectorAll('.dropdown-link');

        // Toggle dropdown on profile button click
        profileBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation(); // Prevent the click from bubbling up to document
            profileDropdown.classList.toggle('active');
        });

        // Handle clicks on dropdown links
        dropdownLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.stopPropagation(); // Prevent the click from bubbling up to document
                profileDropdown.classList.remove('active'); // Close the dropdown
                // The default behavior of the <a> tag (navigation) will proceed
            });
        });

        // Close dropdown if clicking outside
        document.addEventListener('click', function(e) {
            if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                profileDropdown.classList.remove('active');
            }
        });

        // Modal functionality
        function closeModal(modalId) {
            document.querySelector(`#${modalId}`).classList.remove('active');
        }

        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('active');
            }
        });

        // Character counter for comments
        function updateCharCounter() {
            const textarea = document.querySelector('#comments');
            const counter = document.querySelector('#charCounter');
            const count = textarea.value.length;
            counter.textContent = `${count}/1000`;
            if (count > 900) {
                counter.classList.add('warning');
            } else {
                counter.classList.remove('warning');
            }
        }

        // Toggle feedback card content
        function toggleContent(contentId, btn) {
            const content = document.getElementById(contentId);
            content.classList.toggle('expanded');
            btn.textContent = content.classList.contains('expanded') ? 'Show Less' : 'Show More';
        }

        // Initialize character counter on page load
        document.addEventListener('DOMContentLoaded', updateCharCounter);
    </script>
</body>
</html>