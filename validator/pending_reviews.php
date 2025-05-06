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

    // Handle search with PRG pattern and URL cleanup
    $search_query = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
        $search_query = trim($_POST['search']);
        // Redirect to GET request without search parameter to avoid form resubmission
        $redirect_url = "pending_reviews.php?page=1";
        $_SESSION['search_query'] = $search_query; // Store search query in session
        header("Location: $redirect_url");
        exit;
    } elseif (isset($_SESSION['search_query'])) {
        $search_query = $_SESSION['search_query'];
    }

    // Clear search query from session on page refresh (if no POST or GET search)
    if (!isset($_POST['search']) && !isset($_GET['search'])) {
        unset($_SESSION['search_query']);
    }

    // Search and pagination setup
    $items_per_page = 10;
    $current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($current_page - 1) * $items_per_page;

    // Count total pending submissions
    $count_stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT s.submission_id) as total
        FROM submissions s
        JOIN users u ON s.user_id = u.user_id
        WHERE s.barangay_id = :barangay_id AND s.status = 'pending'
        AND (:search = '' OR u.username LIKE :search OR u.email LIKE :search)
    ");
    $count_stmt->execute([
        ':barangay_id' => $user['barangay_id'],
        ':search' => $search_query ? "%$search_query%" : ''
    ]);
    $total_items = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = max(1, ceil($total_items / $items_per_page));

    // Fetch pending submissions with DISTINCT to avoid duplicates
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.submission_id, s.user_id, s.trees_planted, s.photo_data, s.latitude, s.longitude, s.submitted_at, s.status,
               s.submission_notes, s.flagged, u.username
        FROM submissions s
        JOIN users u ON s.user_id = u.user_id
        WHERE s.barangay_id = :barangay_id AND s.status = 'pending'
        AND (:search = '' OR u.username LIKE :search OR u.email LIKE :search)
        ORDER BY s.submission_id ASC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':barangay_id', $user['barangay_id'], PDO::PARAM_INT);
    $stmt->bindValue(':search', $search_query ? "%$search_query%" : '', PDO::PARAM_STR);
    $stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $pending_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate eco points for each submission based on effort (trees planted)
    $base_points_per_tree = 50; // Base eco points per tree for planting effort
    foreach ($pending_submissions as &$submission) {
        $total_base_points = $submission['trees_planted'] * $base_points_per_tree;
        $buffer_multiplier = 1.2; // 20% buffer for fairness
        $reward_multiplier = 1.1; // 10% additional reward
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
    <title>Pending Reviews</title>
    <link rel="icon" type="image/png" sizes="64x64" href="<?php echo htmlspecialchars($icon_base64); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/pending_reviews.css">
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
                <h1>Pending Reviews</h1>
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
                <form method="POST" action="">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search by username or email">
                    <button type="submit"><i class="fas fa-search"></i></button>
                </form>
            </div>
            <div class="submission-table">
                <table>
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
                            <th>Flag</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pending_submissions)): ?>
                            <tr>
                                <td colspan="11" style="text-align: center;">No pending submissions available.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pending_submissions as $submission): ?>
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
                                    <td><?php if ($submission['flagged']): ?><i class="fas fa-flag flag-icon"></i><?php endif; ?></td>
                                    <td>
                                        <button class="action-btn" onclick="openActionModal(<?php echo htmlspecialchars($submission['submission_id']); ?>, <?php echo htmlspecialchars($submission['user_id']); ?>, <?php echo htmlspecialchars($submission['eco_points']); ?>, <?php echo htmlspecialchars($submission['trees_planted']); ?>)">Actions</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination">
                <a href="?page=<?php echo max(1, $current_page - 1); ?>" class="<?php echo ($current_page == 1 || $total_items == 0) ? 'disabled' : ''; ?>">Previous</a>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>" class="<?php echo $i == $current_page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <a href="?page=<?php echo min($total_pages, $current_page + 1); ?>" class="<?php echo ($current_page == $total_pages || $total_items == 0) ? 'disabled' : ''; ?>">Next</a>
            </div>
        </div>
    </div>

    <!-- Action Modal -->
    <div class="modal" id="actionModal">
        <div class="modal-content">
            <span class="close" onclick="closeActionModal()">×</span>
            <h2>Submission Action</h2>
            <p id="actionModalText"></p>
            <input type="hidden" id="actionSubmissionId">
            <input type="hidden" id="actionUserId">
            <input type="hidden" id="actionEcoPoints">
            <input type="hidden" id="actionTreesPlanted">
            <button class="modal-btn approve-btn" onclick="confirmAction('approve')">Approve</button>
            <button class="modal-btn reject-btn" onclick="openRejectModal()">Reject</button>
        </div>
    </div>

    <!-- Reject Modal -->
    <div class="modal" id="rejectModal">
        <div class="modal-content">
            <span class="close" onclick="closeRejectModal()">×</span>
            <h2>Reject Submission</h2>
            <form id="rejectForm">
                <input type="hidden" id="rejectSubmissionId">
                <div class="form-group">
                    <label for="rejectReason">Reason for Rejection</label>
                    <textarea id="rejectReason" rows="4" placeholder="Enter reason..." required></textarea>
                </div>
                <button type="button" class="modal-btn cancel-btn" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" class="modal-btn submit-btn">Submit</button>
            </form>
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

        // Action Modal functionality
        function openActionModal(submissionId, userId, ecoPoints, treesPlanted) {
            document.querySelector('#actionSubmissionId').value = submissionId;
            document.querySelector('#actionUserId').value = userId;
            document.querySelector('#actionEcoPoints').value = ecoPoints;
            document.querySelector('#actionTreesPlanted').value = treesPlanted;
            document.querySelector('#actionModalText').textContent = `Submission ID: ${submissionId} | Trees Planted: ${treesPlanted} | Eco Points: ${ecoPoints}`;
            document.querySelector('#actionModal').style.display = 'flex';
        }

        function closeActionModal() {
            document.querySelector('#actionModal').style.display = 'none';
            document.querySelector('#actionModalText').textContent = '';
            document.querySelector('#actionSubmissionId').value = '';
            document.querySelector('#actionUserId').value = '';
            document.querySelector('#actionEcoPoints').value = '';
            document.querySelector('#actionTreesPlanted').value = '';
        }

        function confirmAction(action) {
            const submissionId = document.querySelector('#actionSubmissionId').value;
            const userId = document.querySelector('#actionUserId').value;
            const ecoPoints = document.querySelector('#actionEcoPoints').value;
            const treesPlanted = document.querySelector('#actionTreesPlanted').value;
            document.querySelector('#actionModal').style.display = 'none';
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.innerHTML = `
                <div class="modal-content">
                    <span class="close" onclick="this.parentElement.parentElement.remove()">×</span>
                    <h2>Confirmation</h2>
                    <p>Are you sure you want to ${action} this submission?</p>
                    <button class="modal-btn approve-btn" onclick="proceedAction('${action}', ${submissionId}, ${userId}, ${ecoPoints}, ${treesPlanted});this.parentElement.parentElement.remove()">Yes</button>
                    <button class="modal-btn cancel-btn" onclick="this.parentElement.parentElement.remove()">No</button>
                </div>
            `;
            modal.style.display = 'flex';
            document.body.appendChild(modal);
        }

        function proceedAction(action, submissionId, userId, ecoPoints, treesPlanted) {
            const payload = {
                submission_id: submissionId,
                user_id: userId,
                eco_points: parseInt(ecoPoints),
                trees_planted: parseInt(treesPlanted),
                status: action === 'approve' ? 'approved' : 'rejected',
                validated_by: <?php echo json_encode($user_id); ?>,
                validated_at: new Date().toISOString()
            };

            if (action === 'reject') {
                const rejectReason = document.querySelector('#rejectReason')?.value;
                if (!rejectReason) {
                    const modal = document.createElement('div');
                    modal.className = 'modal';
                    modal.innerHTML = `
                        <div class="modal-content">
                            <span class="close" onclick="this.parentElement.parentElement.remove()">×</span>
                            <h2>Error</h2>
                            <p>Rejection reason is required.</p>
                            <button class="modal-btn cancel-btn" onclick="this.parentElement.parentElement.remove()">OK</button>
                        </div>
                    `;
                    modal.style.display = 'flex';
                    document.body.appendChild(modal);
                    return;
                }
                payload.rejection_reason = rejectReason;
            }

            fetch('../services/update_submission.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                const modal = document.createElement('div');
                modal.className = 'modal';
                if (data.success) {
                    // Update UI immediately
                    const row = document.querySelector(`tr[data-submission-id="${submissionId}"]`);
                    if (row) {
                        row.querySelector('.status').textContent = action === 'approve' ? 'Approved' : 'Rejected';
                        row.querySelector('.action-btn').style.display = 'none'; // Disable further actions
                    }
                    modal.innerHTML = `
                        <div class="modal-content">
                            <span class="close" onclick="this.parentElement.parentElement.remove()">×</span>
                            <h2>Success</h2>
                            <p>Submission ${action === 'approve' ? 'approved' : 'rejected'} successfully! ${action === 'approve' ? 'Awarded ' + ecoPoints + ' eco points.' : ''}</p>
                            <button class="modal-btn approve-btn" onclick="this.parentElement.parentElement.remove()">OK</button>
                        </div>
                    `;
                } else {
                    modal.innerHTML = `
                        <div class="modal-content">
                            <span class="close" onclick="this.parentElement.parentElement.remove()">×</span>
                            <h2>Error</h2>
                            <p>Error: ${data.error || 'Unknown error occurred'}</p>
                            <button class="modal-btn cancel-btn" onclick="this.parentElement.parentElement.remove()">OK</button>
                        </div>
                    `;
                }
                modal.style.display = 'flex';
                document.body.appendChild(modal);
            })
            .catch(error => {
                const modal = document.createElement('div');
                modal.className = 'modal';
                modal.innerHTML = `
                    <div class="modal-content">
                        <span class="close" onclick="this.parentElement.parentElement.remove()">×</span>
                        <h2>Error</h2>
                        <p>Error: ${error.message}</p>
                        <button class="modal-btn cancel-btn" onclick="this.parentElement.parentElement.remove()">OK</button>
                    </div>
                `;
                modal.style.display = 'flex';
                document.body.appendChild(modal);
            });
        }

        function openRejectModal() {
            const submissionId = document.querySelector('#actionSubmissionId').value;
            document.querySelector('#rejectSubmissionId').value = submissionId;
            document.querySelector('#actionModal').style.display = 'none';
            document.querySelector('#rejectModal').style.display = 'flex';
        }

        function closeRejectModal() {
            document.querySelector('#rejectModal').style.display = 'none';
            document.querySelector('#rejectForm').reset();
            document.querySelector('#rejectSubmissionId').value = '';
        }

        document.querySelector('#rejectForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const submissionId = document.querySelector('#rejectSubmissionId').value;
            const userId = document.querySelector('#actionUserId').value;
            const ecoPoints = document.querySelector('#actionEcoPoints').value;
            const treesPlanted = document.querySelector('#actionTreesPlanted').value;
            document.querySelector('#rejectModal').style.display = 'none';
            confirmAction('reject');
        });

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
    </script>
</body>
</html>