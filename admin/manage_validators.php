<?php
session_start();
require_once '../includes/config.php';

// Prevent redirect loops by tracking redirect attempts
if (!isset($_SESSION['redirect_count'])) {
    $_SESSION['redirect_count'] = 0;
}
$_SESSION['redirect_count']++;
if ($_SESSION['redirect_count'] > 5) {
    die('Error: Too many redirects detected. Please check your session or server configuration.');
}

// Check if user is logged in and has admin role, but skip during POST form handling
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin')) {
    $_SESSION['redirect_count'] = 0;
    header('Location: ../views/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
// Fetch admin data
try {
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.email, u.profile_picture, u.default_profile_asset_id
        FROM users u 
        WHERE u.user_id = :user_id
    ");
    $stmt->execute(['user_id' => $user_id]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        session_destroy();
        $_SESSION['redirect_count'] = 0;
        header('Location: ../views/login.php');
        exit;
    }

    // Fetch profile picture (custom or default)
    if ($admin['profile_picture']) {
        $profile_picture_data = 'data:image/jpeg;base64,' . base64_encode($admin['profile_picture']);
    } elseif ($admin['default_profile_asset_id']) {
        $stmt = $pdo->prepare("SELECT asset_data, asset_type FROM assets WHERE asset_id = :asset_id");
        $stmt->execute(['asset_id' => $admin['default_profile_asset_id']]);
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

    // Pagination setup
    $validators_per_page = 5;
    $current_page = isset($_GET['page']) ? max(1, filter_input(INPUT_GET, 'page', FILTER_SANITIZE_NUMBER_INT)) : 1;
    $offset = ($current_page - 1) * $validators_per_page;

    // Fetch total number of validators for pagination
    $sql_count = "
        SELECT COUNT(*) 
        FROM users u
        LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
        WHERE u.role = 'eco_validator'
    ";
    $conditions = [];
    $params = [];
    if (isset($_GET['name']) && !empty($_GET['name'])) {
        $name = filter_input(INPUT_GET, 'name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $conditions[] = "(u.first_name LIKE :name OR u.last_name LIKE :name OR u.username LIKE :name)";
        $params['name'] = "%$name%";
    }
    if (isset($_GET['email']) && !empty($_GET['email'])) {
        $email = filter_input(INPUT_GET, 'email', FILTER_SANITIZE_EMAIL);
        $conditions[] = "u.email LIKE :email";
        $params['email'] = "%$email%";
    }
    if (isset($_GET['region']) && !empty($_GET['region'])) {
        $region = filter_input(INPUT_GET, 'region', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $conditions[] = "b.region = :region";
        $params['region'] = $region;
    }
    if (!empty($conditions)) {
        $sql_count .= " AND " . implode(" AND ", $conditions);
    }
    $stmt = $pdo->prepare($sql_count);
    $stmt->execute($params);
    $total_validators = $stmt->fetchColumn();
    $total_pages = ceil($total_validators / $validators_per_page);

    // Fetch validators with pagination
    $sql = "
        SELECT 
            u.user_id, u.first_name, u.last_name, u.username, u.email, u.phone_number, u.barangay_id,
            b.name AS barangay_name, b.city, b.province, b.region, b.country
        FROM users u
        LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
        WHERE u.role = 'eco_validator'
    ";
    if (!empty($conditions)) {
        $sql .= " AND " . implode(" AND ", $conditions);
    }
    $sql .= " LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $validators_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }
    $stmt->execute();
    $validators = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch regions for filter dropdown
    $stmt = $pdo->prepare("SELECT DISTINCT region FROM barangays ORDER BY region");
    $stmt->execute();
    $regions = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Handle validator registration (POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_validator'])) {
        $response = ['success' => false, 'message' => ''];
        
        // Validate CSRF token
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $response['message'] = 'Invalid CSRF token.';
        } else {
            $first_name = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $last_name = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
            $phone_number = filter_input(INPUT_POST, 'phone_number', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $password = $_POST['password'];
            $confirm_password = $_POST['confirm_password'];
            $barangay_id = filter_input(INPUT_POST, 'barangay_id', FILTER_SANITIZE_NUMBER_INT);

            // Server-side validation
            if (empty($first_name) || empty($last_name) || empty($username) || empty($email) || empty($phone_number) || empty($password) || empty($confirm_password) || empty($barangay_id)) {
                $response['message'] = 'Please fill in all fields.';
            } elseif (strlen($first_name) < 2) {
                $response['message'] = 'First name must be at least 2 characters long.';
            } elseif (strlen($last_name) < 2) {
                $response['message'] = 'Last name must be at least 2 characters long.';
            } elseif (strlen($username) < 3) {
                $response['message'] = 'Username must be at least 3 characters long.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = 'Invalid email format.';
            } elseif (!preg_match('/^\+?\d{10,15}$/', $phone_number)) {
                $response['message'] = 'Invalid phone number format.';
            } elseif (strlen($password) < 8) {
                $response['message'] = 'Password must be at least 8 characters long.';
            } elseif (!preg_match('/[A-Z]/', $password)) {
                $response['message'] = 'Password must contain at least one capital letter.';
            } elseif (!preg_match('/[0-9]/', $password)) {
                $response['message'] = 'Password must contain at least one number.';
            } elseif (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
                $response['message'] = 'Password must contain at least one special character.';
            } elseif ($password !== $confirm_password) {
                $response['message'] = 'Passwords do not match.';
            } else {
                try {
                    // Check for existing username
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
                    $stmt->execute(['username' => $username]);
                    if ($stmt->fetchColumn() > 0) {
                        $response['message'] = 'Username already exists.';
                    } else {
                        // Check for existing email
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
                        $stmt->execute(['email' => $email]);
                        if ($stmt->fetchColumn() > 0) {
                            $response['message'] = 'Email already exists.';
                        } else {
                            // Insert new validator
                            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                            $stmt = $pdo->prepare("
                                INSERT INTO users (first_name, last_name, username, password, email, phone_number, role, barangay_id)
                                VALUES (:first_name, :last_name, :username, :password, :email, :phone_number, 'eco_validator', :barangay_id)
                            ");
                            $stmt->execute([
                                'first_name' => $first_name,
                                'last_name' => $last_name,
                                'username' => $username,
                                'password' => $hashed_password,
                                'email' => $email,
                                'phone_number' => $phone_number,
                                'barangay_id' => $barangay_id
                            ]);

                            $new_user_id = $pdo->lastInsertId();
                            $stmt = $pdo->prepare("SELECT asset_id FROM assets WHERE asset_type = 'default_profile' LIMIT 1");
                            $stmt->execute();
                            $default_profile_asset_id = $stmt->fetchColumn();

                            if ($default_profile_asset_id) {
                                $stmt = $pdo->prepare("UPDATE users SET default_profile_asset_id = :asset_id WHERE user_id = :user_id");
                                $stmt->execute([
                                    'asset_id' => $default_profile_asset_id,
                                    'user_id' => $new_user_id
                                ]);
                            }

                            $response['success'] = true;
                            $response['message'] = 'Validator registered successfully!';
                        }
                    }
                } catch (PDOException $e) {
                    $response['message'] = 'Database error: ' . $e->getMessage();
                }
            }
        }
        
        // Output JSON response for AJAX
        if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }
        
        // Store message and redirect with register=true for non-AJAX fallback
        if ($response['success']) {
            $_SESSION['register_success'] = $response['message'];
        } else {
            $_SESSION['register_error'] = $response['message'];
        }
        $_SESSION['redirect_count'] = 0;
        header('Location: manage_validators.php?register=true');
        exit;
    }

    // Handle validator deletion (POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_validator'])) {
        $validator_id = filter_input(INPUT_POST, 'validator_id', FILTER_SANITIZE_NUMBER_INT);
        if ($validator_id) {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = :user_id AND role = 'eco_validator'");
                $stmt->execute(['user_id' => $validator_id]);
                $_SESSION['manage_success'] = 'Validator deleted successfully.';
            } catch (PDOException $e) {
                $_SESSION['manage_error'] = 'Error deleting validator: ' . $e->getMessage();
            }
        }
        // Redirect to implement PRG pattern
        $_SESSION['redirect_count'] = 0;
        header('Location: manage_validators.php');
        exit;
    }

    // Handle validator editing (POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_validator'])) {
        $validator_id = filter_input(INPUT_POST, 'validator_id', FILTER_SANITIZE_NUMBER_INT);
        $first_name = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $last_name = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $phone_number = filter_input(INPUT_POST, 'phone_number', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $barangay_id = filter_input(INPUT_POST, 'barangay_id', FILTER_SANITIZE_NUMBER_INT);

        if (empty($first_name) || empty($last_name) || empty($username) || empty($email) || empty($phone_number) || empty($barangay_id)) {
            $_SESSION['manage_error'] = 'Please fill in all fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['manage_error'] = 'Invalid email format.';
        } elseif (!preg_match('/^\+?\d{10,15}$/', $phone_number)) {
            $_SESSION['manage_error'] = 'Invalid phone number format.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET first_name = :first_name, last_name = :last_name, username = :username, email = :email, phone_number = :phone_number, barangay_id = :barangay_id
                    WHERE user_id = :user_id AND role = 'eco_validator'
                ");
                $stmt->execute([
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'username' => $username,
                    'email' => $email,
                    'phone_number' => $phone_number,
                    'barangay_id' => $barangay_id,
                    'user_id' => $validator_id
                ]);
                $_SESSION['manage_success'] = 'Validator updated successfully.';
            } catch (PDOException $e) {
                $_SESSION['manage_error'] = 'Error updating validator: ' . $e->getMessage();
            }
        }
        // Redirect to implement PRG pattern
        $_SESSION['redirect_count'] = 0;
        header('Location: manage_validators.php');
        exit;
    }

    // Generate CSRF token if not set
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    // Display and clear session messages
    $error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : null;
    $manage_error = isset($_SESSION['manage_error']) ? $_SESSION['manage_error'] : null;
    $manage_success = isset($_SESSION['manage_success']) ? $_SESSION['manage_success'] : null;
    $register_error = isset($_SESSION['register_error']) ? $_SESSION['register_error'] : null;
    $register_success = isset($_SESSION['register_success']) ? $_SESSION['register_success'] : null;

    // Clear session messages after displaying
    unset($_SESSION['error_message']);
    unset($_SESSION['manage_error']);
    unset($_SESSION['manage_success']);
    unset($_SESSION['register_error']);
    unset($_SESSION['register_success']);
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}

$_SESSION['redirect_count'] = 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Validators - Green Roots</title>
    <link rel="icon" type="image/png" sizes="64x64" href="<?php echo $icon_base64; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/manage_validators.css">
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
            <a href="manage_user_feedback.php" title="User Feedback Management"><i class="fas fa-comments"></i></a>
            <a href="view_data.php" title="View All Data"><i class="fas fa-eye"></i></a>
            <a href="export_data.php" title="Export System Data"><i class="fas fa-download"></i></a>
        </div>
        <div class="main-content">
            <?php if (isset($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <?php if (isset($manage_error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($manage_error); ?></div>
            <?php endif; ?>
            <?php if (isset($manage_success)): ?>
                <div class="success-message"><?php echo htmlspecialchars($manage_success); ?></div>
            <?php endif; ?>
            <div class="header">
                <h1>Manage Validators</h1>
                <div class="notification-search">
                    <div class="search-bar">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" placeholder="Search" id="searchInput">
                        <div class="search-results" id="searchResults"></div>
                    </div>
                </div>
                <div class="profile" id="profileBtn">
                    <span><?php echo htmlspecialchars($admin['username']); ?></span>
                    <img src="<?php echo $profile_picture_data; ?>" alt="Profile">
                    <div class="profile-dropdown" id="profileDropdown">
                        <div class="email"><?php echo htmlspecialchars($admin['email']); ?></div>
                        <a href="account_settings.php">Account</a>
                        <a href="logout.php">Logout</a>
                    </div>
                </div>
            </div>
            <button class="custom-btn" id="registerValidatorBtn">Register New Validator</button>
            <div class="filter-container">
                <div class="filter-group">
                    <i class="fas fa-user"></i>
                    <input type="text" id="filterName" placeholder=" " value="<?php echo isset($_GET['name']) ? htmlspecialchars($_GET['name']) : ''; ?>">
                    <label for="filterName">Name</label>
                </div>
                <div class="filter-group">
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="filterEmail" placeholder=" " value="<?php echo isset($_GET['email']) ? htmlspecialchars($_GET['email']) : ''; ?>">
                    <label for="filterEmail">Email</label>
                </div>
                <div class="filter-group">
                    <i class="fas fa-map-marker-alt"></i>
                    <select id="filterRegion">
                        <option value="">All Regions</option>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?php echo htmlspecialchars($region); ?>" <?php echo isset($_GET['region']) && $_GET['region'] === $region ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($region); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label for="filterRegion">Region</label>
                </div>
            </div>
            <table class="validators-table">
                <thead>
                    <tr>
                        <th>First Name</th>
                        <th>Last Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Address</th>
                        <th>Phone Number</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($validators)): ?>
                        <tr>
                            <td colspan="7">No validators found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($validators as $validator): ?>
                            <?php
                                $address = [];
                                if ($validator['barangay_name']) $address[] = $validator['barangay_name'];
                                if ($validator['city']) $address[] = $validator['city'];
                                if ($validator['province']) $address[] = $validator['province'];
                                if ($validator['region']) $address[] = $validator['region'];
                                if ($validator['country']) $address[] = $validator['country'];
                                $address_str = implode(', ', $address);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($validator['first_name']); ?></td>
                                <td><?php echo htmlspecialchars($validator['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($validator['username']); ?></td>
                                <td><?php echo htmlspecialchars($validator['email']); ?></td>
                                <td><?php echo htmlspecialchars($address_str ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($validator['phone_number'] ?: 'N/A'); ?></td>
                                <td>
                                    <button class="custom-btn" onclick="openActionModal(<?php echo htmlspecialchars(json_encode($validator)); ?>)">Action</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php
                        $query_params = $_GET;
                        unset($query_params['page']);
                        $base_url = 'manage_validators.php?' . http_build_query($query_params);
                        if (!empty($query_params)) {
                            $base_url .= '&';
                        }
                    ?>
                    <?php if ($current_page > 1): ?>
                        <a href="<?php echo $base_url; ?>page=<?php echo $current_page - 1; ?>">Previous</a>
                    <?php else: ?>
                        <a class="disabled">Previous</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="<?php echo $base_url; ?>page=<?php echo $i; ?>" <?php echo $i === $current_page ? 'class="active"' : ''; ?>><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <?php if ($current_page < $total_pages): ?>
                        <a href="<?php echo $base_url; ?>page=<?php echo $current_page + 1; ?>">Next</a>
                    <?php else: ?>
                        <a class="disabled">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Validator Registration Modal -->
    <div id="registerValidatorModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal('registerValidatorModal')">×</span>
            <h3>Register New Validator</h3>
            <div class="error <?php echo isset($register_error) ? 'show' : ''; ?>">
                <?php echo isset($register_error) ? htmlspecialchars($register_error) : ''; ?>
            </div>
            <div class="success <?php echo isset($register_success) ? 'show' : ''; ?>">
                <?php echo isset($register_success) ? htmlspecialchars($register_success) : ''; ?>
            </div>
            <form method="POST" action="" id="validatorForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="register_validator" value="1">
                <input type="hidden" name="ajax" value="1">
                <div class="name-group">
                    <div class="form-group">
                        <div class="input-wrapper">
                            <i class="fas fa-user"></i>
                            <input type="text" id="first_name" name="first_name" placeholder=" " required>
                            <label for="first_name">Enter first name</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="input-wrapper">
                            <i class="fas fa-user"></i>
                            <input type="text" id="last_name" name="last_name" placeholder=" " required>
                            <label for="last_name">Enter last name</label>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="username" name="username" placeholder=" " required>
                        <label for="username">Enter username</label>
                    </div>
                </div>
                <div class="form-group">
                    <div class="input-wrapper">
                        <i class="fas fa-envelope"></i>
                        <input type="email" id="email" name="email" placeholder=" " required>
                        <label for="email">Enter email</label>
                    </div>
                </div>
                <div class="form-group">
                    <div class="input-wrapper">
                        <i class="fas fa-phone"></i>
                        <input type="tel" id="phone_number" name="phone_number" placeholder=" " required>
                        <label for="phone_number">Enter phone number</label>
                    </div>
                </div>
                <div class="form-group">
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" placeholder=" " required>
                        <label for="password">Enter password</label>
                    </div>
                </div>
                <div class="form-group">
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder=" " required>
                        <label for="confirm_password">Confirm password</label>
                    </div>
                </div>
                <div class="form-group location-group">
                    <div class="input-wrapper">
                        <i class="fas fa-map-marker-alt"></i>
                        <select id="region" name="region" required>
                            <option value="" disabled selected hidden></option>
                        </select>
                        <label for="region">Select region</label>
                    </div>
                    <div class="input-wrapper">
                        <i class="fas fa-map-marker-alt"></i>
                        <select id="province" name="province" required>
                            <option value="" disabled selected hidden></option>
                        </select>
                        <label for="province">Select province</label>
                    </div>
                    <div class="input-wrapper">
                        <i class="fas fa-map-marker-alt"></i>
                        <select id="city" name="city" required>
                            <option value="" disabled selected hidden></option>
                        </select>
                        <label for="city">Select city</label>
                    </div>
                    <div class="input-wrapper">
                        <i class="fas fa-map-marker-alt"></i>
                        <select id="barangay" name="barangay" required>
                            <option value="" disabled selected hidden></option>
                        </select>
                        <label for="barangay">Select barangay</label>
                    </div>
                    <input type="hidden" id="barangay_id" name="barangay_id" required>
                </div>
                <input type="submit" value="Register Validator">
            </form>
        </div>
    </div>

    <!-- Edit Validator Modal -->
    <div id="editValidatorModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal('editValidatorModal')">×</span>
            <h3>Edit Validator</h3>
            <div class="error"></div>
            <div class="success"></div>
            <form method="POST" action="" id="editValidatorForm">
                <input type="hidden" name="validator_id" id="edit_validator_id">
                <input type="hidden" name="edit_validator" value="1">
                <div class="name-group">
                    <div class="form-group">
                        <div class="input-wrapper">
                            <i class="fas fa-user"></i>
                            <input type="text" id="edit_first_name" name="first_name" placeholder=" " required>
                            <label for="edit_first_name">Enter first name</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="input-wrapper">
                            <i class="fas fa-user"></i>
                            <input type="text" id="edit_last_name" name="last_name" placeholder=" " required>
                            <label for="edit_last_name">Enter last name</label>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="edit_username" name="username" placeholder=" " required>
                        <label for="edit_username">Enter username</label>
                    </div>
                </div>
                <div class="form-group">
                    <div class="input-wrapper">
                        <i class="fas fa-envelope"></i>
                        <input type="email" id="edit_email" name="email" placeholder=" " required>
                        <label for="edit_email">Enter email</label>
                    </div>
                </div>
                <div class="form-group">
                    <div class="input-wrapper">
                        <i class="fas fa-phone"></i>
                        <input type="tel" id="edit_phone_number" name="phone_number" placeholder=" " required>
                        <label for="edit_phone_number">Enter phone number</label>
                    </div>
                </div>
                <div class="form-group location-group">
                    <div class="input-wrapper">
                        <i class="fas fa-map-marker-alt"></i>
                        <select id="edit_region" name="region" required>
                            <option value="" disabled selected hidden></option>
                        </select>
                        <label for="edit_region">Select region</label>
                    </div>
                    <div class="input-wrapper">
                        <i class="fas fa-map-marker-alt"></i>
                        <select id="edit_province" name="province" required>
                            <option value="" disabled selected hidden></option>
                        </select>
                        <label for="edit_province">Select province</label>
                    </div>
                    <div class="input-wrapper">
                        <i class="fas fa-map-marker-alt"></i>
                        <select id="edit_city" name="city" required>
                            <option value="" disabled selected hidden></option>
                        </select>
                        <label for="edit_city">Select city</label>
                    </div>
                    <div class="input-wrapper">
                        <i class="fas fa-map-marker-alt"></i>
                        <select id="edit_barangay" name="barangay" required>
                            <option value="" disabled selected hidden></option>
                        </select>
                        <label for="edit_barangay">Select barangay</label>
                    </div>
                    <input type="hidden" id="edit_barangay_id" name="barangay_id" required>
                </div>
                <input type="submit" value="Update Validator">
            </form>
        </div>
    </div>

    <!-- Action Modal -->
    <div id="actionModal" class="modal">
        <div class="modal-content action-modal-content">
            <span class="close-btn" onclick="closeModal('actionModal')">×</span>
            <h3>Validator Actions</h3>
            <button class="custom-btn" onclick="openEditModalFromAction()">Update</button>
            <button class="custom-btn delete" onclick="deleteValidator()">Delete</button>
            <form method="POST" action="" id="deleteValidatorForm" style="display:none;">
                <input type="hidden" name="validator_id" id="action_validator_id">
                <input type="hidden" name="delete_validator" value="1">
            </form>
        </div>
    </div>

    <script>
        // Search bar functionality
        const functionalities = [
            { name: 'Admin Dashboard', url: 'admin_dashboard.php' },
            { name: 'Manage Barangays', url: 'manage_barangays.php' },
            { name: 'Manage Planting Sites', url: 'manage_planting_sites.php' },
            { name: 'Manage Rewards', url: 'manage_rewards.php' },
            { name: 'Manage Events', url: 'manage_events.php' },
            { name: 'Manage Users', url: 'manage_users.php' },
            { name: 'Manage Validators', url: 'manage_validators.php' },
            { name: 'View All Data', url: 'view_data.php' },
            { name: 'Export System Data', url: 'export_data.php' },
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

        // Modal handling
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.style.display = 'none';
            const error = modal.querySelector('.error');
            const success = modal.querySelector('.success');
            if (error) error.classList.remove('show');
            if (success) success.classList.remove('show');
            if (error) error.textContent = '';
            if (success) success.textContent = '';
            const form = modal.querySelector('form');
            if (form) form.reset();
            // Remove ?register=true from URL
            if (modalId === 'registerValidatorModal' && window.location.search.includes('register=true')) {
                const url = new URL(window.location);
                url.searchParams.delete('register');
                history.replaceState(null, '', url.toString());
            }
        }

        // Register Validator Modal
        const registerValidatorBtn = document.querySelector('#registerValidatorBtn');
        const registerValidatorModal = document.querySelector('#registerValidatorModal');
        const validatorForm = document.querySelector('#validatorForm');

        registerValidatorBtn.addEventListener('click', function() {
            registerValidatorModal.style.display = 'flex';
            // Add ?register=true to URL
            const url = new URL(window.location);
            url.searchParams.set('register', 'true');
            history.pushState(null, '', url.toString());
        });

        document.addEventListener('click', function(e) {
            if (e.target === registerValidatorModal) {
                closeModal('registerValidatorModal');
            }
        });

        // Check if ?register=true is in URL on page load
        window.addEventListener('load', function() {
            if (new URLSearchParams(window.location.search).get('register') === 'true') {
                registerValidatorModal.style.display = 'flex';
            }
        });

        // AJAX form submission for validator registration
        validatorForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(validatorForm);
            const errorElement = registerValidatorModal.querySelector('.error');
            const successElement = registerValidatorModal.querySelector('.success');
            
            errorElement.classList.remove('show');
            successElement.classList.remove('show');
            errorElement.textContent = '';
            successElement.textContent = '';

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    successElement.textContent = result.message;
                    successElement.classList.add('show');
                    validatorForm.reset();
                    setTimeout(() => {
                        window.location.href = 'manage_validators.php?register=true';
                    }, 2000);
                } else {
                    errorElement.textContent = result.message;
                    errorElement.classList.add('show');
                }
            } catch (error) {
                errorElement.textContent = 'An error occurred. Please try again.';
                errorElement.classList.add('show');
            }
        });

        // Location selection AJAX for Register Modal
        const regionSelect = document.querySelector('#region');
        const provinceSelect = document.querySelector('#province');
        const citySelect = document.querySelector('#city');
        const barangaySelect = document.querySelector('#barangay');
        const barangayIdInput = document.querySelector('#barangay_id');

        async function fetchOptions(level, parent = '', parentType = 'province', modalPrefix = '') {
            try {
                const response = await fetch(`../services/get_locations.php?level=${level}&parent=${encodeURIComponent(parent)}&parentType=${parentType}`);
                const data = await response.json();
                if (data.error) throw new Error(data.error);
                return data;
            } catch (error) {
                console.error('Error fetching data:', error);
                const errorElement = document.querySelector(`#${modalPrefix}registerValidatorModal .error`);
                errorElement.textContent = 'Failed to load locations. Please try again.';
                errorElement.classList.add('show');
                return [];
            }
        }

        function populateSelect(selectElement, items, isBarangay = false) {
            selectElement.innerHTML = '<option value="" disabled selected hidden></option>';
            items.forEach(item => {
                const option = document.createElement('option');
                if (isBarangay) {
                    const displayName = item.name.replace(/\s*\([^)]+\)\s*$/, '');
                    option.textContent = displayName;
                    option.value = item.barangay_id;
                    option.dataset.fullName = item.name;
                } else {
                    option.textContent = item;
                    option.value = item;
                }
                selectElement.appendChild(option);
            });
            selectElement.disabled = false;
        }

        async function loadRegions(modalPrefix = '') {
            const regions = await fetchOptions('regions', '', 'province', modalPrefix);
            const regionSelect = document.querySelector(`#${modalPrefix}region`);
            populateSelect(regionSelect, regions);
        }

        regionSelect.addEventListener('change', async () => {
            const region = regionSelect.value;
            provinceSelect.disabled = false;
            citySelect.disabled = true;
            barangaySelect.disabled = true;
            barangayIdInput.value = '';

            provinceSelect.innerHTML = '<option value="" disabled selected hidden></option>';
            citySelect.innerHTML = '<option value="" disabled selected hidden></option>';
            barangaySelect.innerHTML = '<option value="" disabled selected hidden></option>';

            if (region === 'National Capital Region (NCR)') {
                provinceSelect.disabled = true;
                provinceSelect.innerHTML = '<option value="" disabled selected hidden>No provinces in NCR</option>';
                const cities = await fetchOptions('cities', region, 'region');
                populateSelect(citySelect, cities);
                citySelect.disabled = false;
            } else {
                const provinces = await fetchOptions('provinces', region);
                populateSelect(provinceSelect, provinces);
            }
        });

        provinceSelect.addEventListener('change', async () => {
            const province = provinceSelect.value;
            citySelect.disabled = false;
            barangaySelect.disabled = true;
            barangayIdInput.value = '';

            citySelect.innerHTML = '<option value="" disabled selected hidden></option>';
            barangaySelect.innerHTML = '<option value="" disabled selected hidden></option>';

            const cities = await fetchOptions('cities', province);
            populateSelect(citySelect, cities);
        });

        citySelect.addEventListener('change', async () => {
            const city = citySelect.value;
            barangaySelect.disabled = false;
            barangayIdInput.value = '';

            barangaySelect.innerHTML = '<option value="" disabled selected hidden></option>';

            const barangays = await fetchOptions('barangays', city);
            populateSelect(barangaySelect, barangays, true);
        });

        barangaySelect.addEventListener('change', () => {
            barangayIdInput.value = barangaySelect.value;
        });

        // Validator registration client-side validation
        validatorForm.addEventListener('submit', function(e) {
            const firstName = document.querySelector('#first_name').value;
            const lastName = document.querySelector('#last_name').value;
            const username = document.querySelector('#username').value;
            const email = document.querySelector('#email').value;
            const phoneNumber = document.querySelector('#phone_number').value;
            const password = document.querySelector('#password').value;
            const confirmPassword = document.querySelector('#confirm_password').value;
            const barangayId = document.querySelector('#barangay_id').value;
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            const phoneRegex = /^\+?\d{10,15}$/;

            let isValid = true;
            const errorElement = registerValidatorModal.querySelector('.error');
            errorElement.classList.remove('show');
            errorElement.textContent = '';

            if (!firstName || !lastName || !username || !email || !phoneNumber || !password || !confirmPassword || !barangayId) {
                isValid = false;
                errorElement.textContent = 'Please fill in all fields.';
            } else if (firstName.length < 2) {
                isValid = false;
                errorElement.textContent = 'First name must be at least 2 characters long.';
            } else if (lastName.length < 2) {
                isValid = false;
                errorElement.textContent = 'Last name must be at least 2 characters long.';
            } else if (username.length < 3) {
                isValid = false;
                errorElement.textContent = 'Username must be at least 3 characters long.';
            } else if (!emailRegex.test(email)) {
                isValid = false;
                errorElement.textContent = 'Invalid email format.';
            } else if (!phoneRegex.test(phoneNumber)) {
                isValid = false;
                errorElement.textContent = 'Invalid phone number format.';
            } else if (password.length < 8) {
                isValid = false;
                errorElement.textContent = 'Password must be at least 8 characters long.';
            } else if (!/[A-Z]/.test(password)) {
                isValid = false;
                errorElement.textContent = 'Password must contain at least one capital letter.';
            } else if (!/[0-9]/.test(password)) {
                isValid = false;
                errorElement.textContent = 'Password must contain at least one number.';
            } else if (!/[!@#$%^&*(),.?":{}|<>]/.test(password)) {
                isValid = false;
                errorElement.textContent = 'Password must contain at least one special character.';
            } else if (password !== confirmPassword) {
                isValid = false;
                errorElement.textContent = 'Passwords do not match.';
            } else if (isNaN(barangayId)) {
                isValid = false;
                errorElement.textContent = 'Please select a valid barangay.';
            }

            if (!isValid) {
                e.preventDefault();
                errorElement.classList.add('show');
            }
        });

        // Edit Validator Modal
        const editValidatorModal = document.querySelector('#editValidatorModal');
        const editValidatorForm = document.querySelector('#editValidatorForm');
        const editRegionSelect = document.querySelector('#edit_region');
        const editProvinceSelect = document.querySelector('#edit_province');
        const editCitySelect = document.querySelector('#edit_city');
        const editBarangaySelect = document.querySelector('#edit_barangay');
        const editBarangayIdInput = document.querySelector('#edit_barangay_id');

        function openEditModal(validator) {
            document.querySelector('#edit_validator_id').value = validator.user_id;
            document.querySelector('#edit_first_name').value = validator.first_name;
            document.querySelector('#edit_last_name').value = validator.last_name;
            document.querySelector('#edit_username').value = validator.username;
            document.querySelector('#edit_email').value = validator.email;
            document.querySelector('#edit_phone_number').value = validator.phone_number || '';

            // Load regions and pre-select the validator's location
            loadRegions('edit_').then(() => {
                if (validator.barangay_name && validator.city && validator.region) {
                    populateEditLocationFields(validator);
                } else {
                    console.warn('Incomplete location data for validator:', validator);
                }
            });

            editValidatorModal.style.display = 'flex';
        }

        async function populateEditLocationFields(validator) {
            try {
                const regionSelect = document.querySelector('#edit_region');
                const provinceSelect = document.querySelector('#edit_province');
                const citySelect = document.querySelector('#edit_city');
                const barangaySelect = document.querySelector('#edit_barangay');
                const barangayIdInput = document.querySelector('#edit_barangay_id');

                // Set region
                regionSelect.value = validator.region;
                if (!regionSelect.value) {
                    throw new Error('Region not found in options');
                }

                // Handle NCR (no provinces)
                if (validator.region === 'National Capital Region (NCR)') {
                    provinceSelect.disabled = true;
                    provinceSelect.innerHTML = '<option value="" disabled selected hidden>No provinces in NCR</option>';
                    const cities = await fetchOptions('cities', validator.region, 'region', 'edit_');
                    populateSelect(citySelect, cities);
                    citySelect.disabled = false;
                } else {
                    const provinces = await fetchOptions('provinces', validator.region, 'province', 'edit_');
                    populateSelect(provinceSelect, provinces);
                    provinceSelect.value = validator.province || '';
                }

                // Set city
                const cities = await fetchOptions('cities', validator.province || validator.region, validator.province ? 'province' : 'region', 'edit_');
                populateSelect(citySelect, cities);
                citySelect.value = validator.city;
                if (!citySelect.value) {
                    throw new Error('City not found in options');
                }

                // Set barangay
                const barangays = await fetchOptions('barangays', validator.city, 'city', 'edit_');
                populateSelect(barangaySelect, barangays, true);
                barangaySelect.value = validator.barangay_id;
                if (!barangaySelect.value) {
                    throw new Error('Barangay not found in options');
                }

                barangayIdInput.value = validator.barangay_id;
            } catch (error) {
                console.error('Error populating location fields:', error);
                const errorElement = editValidatorModal.querySelector('.error');
                errorElement.textContent = 'Failed to load location data. Please select manually.';
                errorElement.classList.add('show');
            }
        }

        editRegionSelect.addEventListener('change', async () => {
            const region = editRegionSelect.value;
            editProvinceSelect.disabled = false;
            editCitySelect.disabled = true;
            editBarangaySelect.disabled = true;
            editBarangayIdInput.value = '';

            editProvinceSelect.innerHTML = '<option value="" disabled selected hidden></option>';
            editCitySelect.innerHTML = '<option value="" disabled selected hidden></option>';
            editBarangaySelect.innerHTML = '<option value="" disabled selected hidden></option>';

            if (region === 'National Capital Region (NCR)') {
                editProvinceSelect.disabled = true;
                editProvinceSelect.innerHTML = '<option value="" disabled selected hidden>No provinces in NCR</option>';
                const cities = await fetchOptions('cities', region, 'region', 'edit_');
                populateSelect(editCitySelect, cities);
                editCitySelect.disabled = false;
            } else {
                const provinces = await fetchOptions('provinces', region, 'province', 'edit_');
                populateSelect(editProvinceSelect, provinces);
            }
        });

        editProvinceSelect.addEventListener('change', async () => {
            const province = editProvinceSelect.value;
            editCitySelect.disabled = false;
            editBarangaySelect.disabled = true;
            editBarangayIdInput.value = '';

            editCitySelect.innerHTML = '<option value="" disabled selected hidden></option>';
            editBarangaySelect.innerHTML = '<option value="" disabled selected hidden></option>';

            const cities = await fetchOptions('cities', province, 'province', 'edit_');
            populateSelect(editCitySelect, cities);
        });

        editCitySelect.addEventListener('change', async () => {
            const city = editCitySelect.value;
            editBarangaySelect.disabled = false;
            editBarangayIdInput.value = '';

            editBarangaySelect.innerHTML = '<option value="" disabled selected hidden></option>';

            const barangays = await fetchOptions('barangays', city, 'city', 'edit_');
            populateSelect(editBarangaySelect, barangays, true);
        });

        editBarangaySelect.addEventListener('change', () => {
            editBarangayIdInput.value = editBarangaySelect.value;
        });

        // Edit validator client-side validation
        editValidatorForm.addEventListener('submit', function(e) {
            const firstName = document.querySelector('#edit_first_name').value;
            const lastName = document.querySelector('#edit_last_name').value;
            const username = document.querySelector('#edit_username').value;
            const email = document.querySelector('#edit_email').value;
            const phoneNumber = document.querySelector('#edit_phone_number').value;
            const barangayId = document.querySelector('#edit_barangay_id').value;
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            const phoneRegex = /^\+?\d{10,15}$/;

            let isValid = true;
            const errorElement = editValidatorModal.querySelector('.error');
            errorElement.classList.remove('show');
            errorElement.textContent = '';

            if (!firstName || !lastName || !username || !email || !phoneNumber || !barangayId) {
                isValid = false;
                errorElement.textContent = 'Please fill in all fields.';
            } else if (firstName.length < 2) {
                isValid = false;
                errorElement.textContent = 'First name must be at least 2 characters long.';
            } else if (lastName.length < 2) {
                isValid = false;
                errorElement.textContent = 'Last name must be at least 2 characters long.';
            } else if (username.length < 3) {
                isValid = false;
                errorElement.textContent = 'Username must be at least 3 characters long.';
            } else if (!emailRegex.test(email)) {
                isValid = false;
                errorElement.textContent = 'Invalid email format.';
            } else if (!phoneRegex.test(phoneNumber)) {
                isValid = false;
                errorElement.textContent = 'Invalid phone number format.';
            } else if (isNaN(barangayId)) {
                isValid = false;
                errorElement.textContent = 'Please select a valid barangay.';
            }

            if (!isValid) {
                e.preventDefault();
                errorElement.classList.add('show');
            }
        });

        document.addEventListener('click', function(e) {
            if (e.target === editValidatorModal) {
                closeModal('editValidatorModal');
            }
        });

        // Action Modal
        const actionModal = document.querySelector('#actionModal');
        let currentValidator = null;

        function openActionModal(validator) {
            currentValidator = validator;
            document.querySelector('#action_validator_id').value = validator.user_id;
            actionModal.style.display = 'flex';
        }

        function openEditModalFromAction() {
            closeModal('actionModal');
            openEditModal(currentValidator);
        }

        function deleteValidator() {
            if (confirm('Are you sure you want to delete this validator?')) {
                document.querySelector('#deleteValidatorForm').submit();
            }
        }

        document.addEventListener('click', function(e) {
            if (e.target === actionModal) {
                closeModal('actionModal');
            }
        });

        // Filter functionality
        const filterName = document.querySelector('#filterName');
        const filterEmail = document.querySelector('#filterEmail');
        const filterRegion = document.querySelector('#filterRegion');

        function applyFilters() {
            const params = new URLSearchParams();
            if (filterName.value) params.append('name', filterName.value);
            if (filterEmail.value) params.append('email', filterEmail.value);
            if (filterRegion.value) params.append('region', filterRegion.value);
            params.append('page', '1'); // Reset to page 1 on filter change
            window.location.search = params.toString();
        }

        filterName.addEventListener('input', () => {
            clearTimeout(filterName.timeout);
            filterName.timeout = setTimeout(applyFilters, 500);
        });

        filterEmail.addEventListener('input', () => {
            clearTimeout(filterEmail.timeout);
            filterEmail.timeout = setTimeout(applyFilters, 500);
        });

        filterRegion.addEventListener('change', applyFilters);

        // Load regions for both modals
        loadRegions();
        loadRegions('edit_');
    </script>
</body>
</html>