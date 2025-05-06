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

// Initialize variables
$eco_points = 0;
$vouchers = [];
$voucher_redeem_message = '';
$withdraw_message = '';
$redeem_error = '';
$paypal_email = '';
$withdrawal_attempts = isset($_SESSION['withdrawal_attempts']) ? $_SESSION['withdrawal_attempts'] : [];
$redeemed_vouchers = [];
$error_message = ''; // To store database errors

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Default placeholder image
$default_placeholder = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';

// Fetch default voucher image from assets table
$default_voucher_image = $default_placeholder;
try {
    $stmt = $pdo->prepare("SELECT asset_data FROM assets WHERE asset_type = 'default_voucher' LIMIT 1");
    $stmt->execute();
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($asset && $asset['asset_data']) {
        $default_voucher_image = 'data:image/jpeg;base64,' . base64_encode($asset['asset_data']);
    }
} catch (PDOException $e) {
    $error_message = "Error fetching default voucher image: " . $e->getMessage();
}

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

    $eco_points = $user['eco_points'];
    $paypal_email = $user['paypal_email'];

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

    // Fetch favicon and logo
    $stmt = $pdo->prepare("SELECT asset_data FROM assets WHERE asset_type = 'favicon' LIMIT 1");
    $stmt->execute();
    $favicon_data = $stmt->fetchColumn();
    $favicon_base64 = $favicon_data ? 'data:image/png;base64,' . base64_encode($favicon_data) : '../assets/favicon.png';

    $stmt = $pdo->prepare("SELECT asset_data FROM assets WHERE asset_type = 'logo' LIMIT 1");
    $stmt->execute();
    $logo_data = $stmt->fetchColumn();
    $logo_base64 = $logo_data ? 'data:image/png;base64,' . base64_encode($logo_data) : 'logo.png';

    // Determine the active section
    $section = isset($_GET['section']) ? $_GET['section'] : 'vouchers';
    if (!in_array($section, ['vouchers', 'redeemed', 'withdraw'])) {
        $section = 'vouchers';
    }

    // Pagination for vouchers
    $vouchers_per_page = 10;
    $current_page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $current_page = max(1, $current_page);
    $offset = ($current_page - 1) * $vouchers_per_page;

    // Fetch redeemed voucher IDs
    $stmt = $pdo->prepare("SELECT voucher_id FROM voucher_claims WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $user_id]);
    $redeemed_voucher_ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // Fetch total number of available vouchers
    if (empty($redeemed_voucher_ids)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM vouchers");
        $stmt->execute();
    } else {
        $placeholders = [];
        $params = [];
        foreach ($redeemed_voucher_ids as $index => $id) {
            $placeholder = ":id$index";
            $placeholders[] = $placeholder;
            $params[$placeholder] = $id;
        }
        $query = "SELECT COUNT(*) FROM vouchers WHERE voucher_id NOT IN (" . implode(',', $placeholders) . ")";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
    }
    $total_vouchers = $stmt->fetchColumn();
    $total_pages = ceil($total_vouchers / $vouchers_per_page);

    // Fetch available vouchers
    if (empty($redeemed_voucher_ids)) {
        $query = "SELECT voucher_id, name, description, points_cost, code, image, terms, partner, partner_contact, partner_website, expiry_date 
                  FROM vouchers 
                  ORDER BY points_cost ASC 
                  LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':limit', $vouchers_per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $placeholders = [];
        $params = [];
        foreach ($redeemed_voucher_ids as $index => $id) {
            $placeholder = ":id$index";
            $placeholders[] = $placeholder;
            $params[$placeholder] = $id;
        }
        $query = "SELECT voucher_id, name, description, points_cost, code, image, terms, partner, partner_contact, partner_website, expiry_date 
                  FROM vouchers 
                  WHERE voucher_id NOT IN (" . implode(',', $placeholders) . ") 
                  ORDER BY points_cost ASC 
                  LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($query);
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $vouchers_per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
    }
    $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert voucher images
    $default_jpeg_header = hex2bin('FFD8FFE000104A46494600010101006000600000');
    foreach ($vouchers as &$voucher) {
        if ($voucher['image'] === $default_jpeg_header || empty($voucher['image'])) {
            $voucher['image'] = $default_voucher_image;
        } else {
            $voucher['image'] = 'data:image/jpeg;base64,' . base64_encode($voucher['image']);
        }
    }
    unset($voucher);

    // Pagination for redeemed vouchers
    $redeemed_per_page = 10;
    $current_redeemed_page = isset($_GET['redeemed_page']) ? (int) $_GET['redeemed_page'] : 1;
    $current_redeemed_page = max(1, $current_redeemed_page);
    $redeemed_offset = ($current_redeemed_page - 1) * $redeemed_per_page;

    // Remove expired vouchers from the database
    $current_date = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("DELETE FROM voucher_claims WHERE expiry_date < :current_date");
    $stmt->execute(['current_date' => $current_date]);

    // Fetch total number of redeemed vouchers
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM voucher_claims WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $user_id]);
    $total_redeemed_vouchers = $stmt->fetchColumn();
    $total_redeemed_pages = ceil($total_redeemed_vouchers / $redeemed_per_page);

    // Fetch redeemed vouchers
    $stmt = $pdo->prepare("
        SELECT vc.voucher_id, vc.code, vc.qr_code, vc.redeemed_at, vc.expiry_date, v.name, v.description, v.points_cost, v.image, v.terms, v.partner, v.partner_contact, v.partner_website
        FROM voucher_claims vc
        JOIN vouchers v ON vc.voucher_id = v.voucher_id
        WHERE vc.user_id = :user_id
        ORDER BY vc.redeemed_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $redeemed_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $redeemed_offset, PDO::PARAM_INT);
    $stmt->execute();
    $redeemed_vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert images for redeemed vouchers
    foreach ($redeemed_vouchers as &$redeemed_voucher) {
        if ($redeemed_voucher['image'] === $default_jpeg_header || empty($redeemed_voucher['image'])) {
            $redeemed_voucher['image'] = $default_voucher_image;
        } else {
            $redeemed_voucher['image'] = 'data:image/jpeg;base64,' . base64_encode($redeemed_voucher['image']);
        }
    }
    unset($redeemed_voucher);

    // Handle Voucher Redemption
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_voucher'])) {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $_SESSION['redeem_error'] = 'Invalid CSRF token. Please try again.';
            $redirect_url = "rewards.php?section=vouchers";
            if ($current_page > 1) {
                $redirect_url .= "&page=$current_page";
            }
            $redirect_url .= "&error=csrf";
            header("Location: $redirect_url");
            exit;
        }

        $voucher_id = (int) $_POST['voucher_id'];

        $stmt = $pdo->prepare("
            SELECT name, description, points_cost, code, terms, partner, partner_contact, partner_website, expiry_date 
            FROM vouchers 
            WHERE voucher_id = :voucher_id
        ");
        $stmt->execute(['voucher_id' => $voucher_id]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$voucher) {
            $_SESSION['redeem_error'] = 'Invalid voucher selected.';
            $redirect_url = "rewards.php?section=vouchers";
            if ($current_page > 1) {
                $redirect_url .= "&page=$current_page";
            }
            $redirect_url .= "&error=invalid_voucher";
            header("Location: $redirect_url");
            exit;
        }

        $points_cost = $voucher['points_cost'];

        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM voucher_claims 
            WHERE user_id = :user_id 
            AND voucher_id = :voucher_id
        ");
        $stmt->execute([
            'user_id' => $user_id,
            'voucher_id' => $voucher_id
        ]);
        $redeem_count = $stmt->fetchColumn();

        if ($redeem_count >= 1) {
            $_SESSION['redeem_error'] = 'You have already redeemed this voucher. Limit: 1 per user.';
            $redirect_url = "rewards.php?section=vouchers";
            if ($current_page > 1) {
                $redirect_url .= "&page=$current_page";
            }
            $redirect_url .= "&error=redeem_limit";
            header("Location: $redirect_url");
            exit;
        }

        if ($eco_points < $points_cost) {
            $_SESSION['redeem_error'] = 'Insufficient eco points to redeem this voucher.';
            $redirect_url = "rewards.php?section=vouchers";
            if ($current_page > 1) {
                $redirect_url .= "&page=$current_page";
            }
            $redirect_url .= "&error=insufficient_points";
            header("Location: $redirect_url");
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE users SET eco_points = eco_points - :points WHERE user_id = :user_id");
            $stmt->execute(['points' => $points_cost, 'user_id' => $user_id]);

            $redeemed_at = date('Y-m-d H:i:s');
            $expiry_date = date('Y-m-d H:i:s', strtotime($redeemed_at . ' +30 days'));

            $qr_code_data = "voucher:{$voucher['code']}:user:{$user_id}:redeemed_at:{$redeemed_at}";

            $stmt = $pdo->prepare("
                INSERT INTO voucher_claims (
                    user_id, voucher_id, code, qr_code, redeemed_at, expiry_date
                ) VALUES (
                    :user_id, :voucher_id, :code, :qr_code, :redeemed_at, :expiry_date
                )
            ");
            $stmt->execute([
                'user_id' => $user_id,
                'voucher_id' => $voucher_id,
                'code' => $voucher['code'],
                'qr_code' => $qr_code_data,
                'redeemed_at' => $redeemed_at,
                'expiry_date' => $expiry_date
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO activities (
                    user_id, description, activity_type, reward_type, reward_value, eco_points, created_at
                ) VALUES (
                    :user_id, :description, 'reward', 'voucher', :reward_value, :eco_points, NOW()
                )
            ");
            $stmt->execute([
                'user_id' => $user_id,
                'description' => "Redeemed voucher: {$voucher['name']} for $points_cost points",
                'reward_value' => $voucher['name'],
                'eco_points' => $points_cost
            ]);

            $_SESSION['redeemed_voucher'] = [
                'name' => $voucher['name'],
                'code' => $voucher['code'],
                'points_cost' => $points_cost,
                'terms' => $voucher['terms'],
                'partner' => $voucher['partner'],
                'partner_contact' => $voucher['partner_contact'],
                'partner_website' => $voucher['partner_website'],
                'expiry_date' => $expiry_date,
                'redeemed_at' => $redeemed_at,
                'qr_code' => $qr_code_data
            ];

            $eco_points -= $points_cost;
            $pdo->commit();
            $voucher_redeem_message = "Successfully redeemed {$voucher['name']}!";
            $redirect_url = "rewards.php?section=vouchers";
            if ($current_page > 1) {
                $redirect_url .= "&page=$current_page";
            }
            $redirect_url .= "&success=voucher";
            header("Location: $redirect_url");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $redeem_error = "Error redeeming voucher: " . $e->getMessage();
        }
    }

    // Handle Cash Withdrawal
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['withdraw_cash'])) {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $_SESSION['redeem_error'] = 'Invalid CSRF token. Please try again.';
            $redirect_url = "rewards.php?section=withdraw";
            $redirect_url .= "&error=csrf_withdraw";
            header("Location: $redirect_url");
            exit;
        }

        $amount_points = (int) $_POST['amount_points'];
        $minimum_points = 100;

        $current_time = time();
        $withdrawal_attempts = array_filter($withdrawal_attempts, function ($timestamp) {
            return (time() - $timestamp) < 3600;
        });
        $_SESSION['withdrawal_attempts'] = $withdrawal_attempts;

        if (count($withdrawal_attempts) >= 5) {
            $_SESSION['redeem_error'] = 'Withdrawal limit reached. Please try again later.';
            $redirect_url = "rewards.php?section=withdraw";
            $redirect_url .= "&error=withdraw_limit";
            header("Location: $redirect_url");
            exit;
        }

        if ($amount_points < $minimum_points) {
            $_SESSION['redeem_error'] = "Minimum withdrawal is $minimum_points points.";
            $redirect_url = "rewards.php?section=withdraw";
            $redirect_url .= "&error=minimum_withdraw";
            header("Location: $redirect_url");
            exit;
        }

        if ($amount_points > $eco_points) {
            $_SESSION['redeem_error'] = 'Insufficient eco points for this withdrawal.';
            $redirect_url = "rewards.php?section=withdraw";
            $redirect_url .= "&error=insufficient_points_withdraw";
            header("Location: $redirect_url");
            exit;
        }

        if (!$paypal_email) {
            $_SESSION['redeem_error'] = 'Please set your PayPal email in Payment Methods to proceed.';
            $redirect_url = "rewards.php?section=withdraw";
            $redirect_url .= "&error=no_paypal_email";
            header("Location: $redirect_url");
            exit;
        }

        $cash_amount = $amount_points * 0.5;

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE users SET eco_points = eco_points - :points WHERE user_id = :user_id");
            $stmt->execute(['points' => $amount_points, 'user_id' => $user_id]);

            $stmt = $pdo->prepare("
                INSERT INTO activities (
                    user_id, description, activity_type, reward_type, reward_value, eco_points, created_at
                ) VALUES (
                    :user_id, :description, 'reward', 'cash', :reward_value, :eco_points, NOW()
                )
            ");
            $stmt->execute([
                'user_id' => $user_id,
                'description' => "Withdrew ₱$cash_amount ($amount_points points) via PayPal to $paypal_email",
                'reward_value' => "₱" . number_format($cash_amount, 2),
                'eco_points' => $amount_points
            ]);

            $_SESSION['withdrawal_success'] = [
                'cash_amount' => $cash_amount,
                'points' => $amount_points,
                'paypal_email' => $paypal_email,
                'withdrawn_at' => date('Y-m-d H:i:s')
            ];

            $withdrawal_attempts[] = $current_time;
            $_SESSION['withdrawal_attempts'] = $withdrawal_attempts;

            $eco_points -= $amount_points;
            $pdo->commit();
            $withdraw_message = "Successfully requested withdrawal of ₱$cash_amount to $paypal_email.";
            $redirect_url = "rewards.php?section=withdraw";
            $redirect_url .= "&success=withdrawal";
            header("Location: $redirect_url");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $redeem_error = "Error processing withdrawal: " . $e->getMessage();
        }
    }

    // Handle PayPal Email Update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_paypal_email'])) {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $_SESSION['redeem_error'] = 'Invalid CSRF token. Please try again.';
            $redirect_url = "rewards.php?section=withdraw";
            $redirect_url .= "&error=csrf_paypal";
            header("Location: $redirect_url");
            exit;
        }

        $new_paypal_email = trim($_POST['paypal_email']);

        if (empty($new_paypal_email)) {
            $_SESSION['redeem_error'] = 'PayPal email is required.';
            $redirect_url = "rewards.php?section=withdraw";
            $redirect_url .= "&error=empty_paypal_email";
            header("Location: $redirect_url");
            exit;
        }

        if (!filter_var($new_paypal_email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['redeem_error'] = 'Please enter a valid PayPal email address.';
            $redirect_url = "rewards.php?section=withdraw";
            $redirect_url .= "&error=invalid_paypal_email";
            header("Location: $redirect_url");
            exit;
        }

        $stmt = $pdo->prepare("UPDATE users SET paypal_email = :paypal_email WHERE user_id = :user_id");
        $stmt->execute([
            'paypal_email' => $new_paypal_email,
            'user_id' => $user_id
        ]);

        $paypal_email = $new_paypal_email;
        $withdraw_message = 'PayPal email updated successfully! You can now proceed with your withdrawal.';
        $redirect_url = "rewards.php?section=withdraw";
        $redirect_url .= "&success=paypal";
        header("Location: $redirect_url");
        exit;
    }

    // Handle success and error messages
    if (isset($_GET['success'])) {
        if ($_GET['success'] === 'withdrawal' && isset($_SESSION['withdrawal_success'])) {
            $withdraw_message = "Successfully requested withdrawal of ₱" . htmlspecialchars(number_format($_SESSION['withdrawal_success']['cash_amount'], 2)) . " to " . htmlspecialchars($_SESSION['withdrawal_success']['paypal_email']) . ".";
        } elseif ($_GET['success'] === 'voucher' && isset($_SESSION['redeemed_voucher'])) {
            $voucher_redeem_message = "Successfully redeemed " . htmlspecialchars($_SESSION['redeemed_voucher']['name']) . "!";
        } elseif ($_GET['success'] === 'paypal') {
            $withdraw_message = 'PayPal email updated successfully! You can now proceed with your withdrawal.';
        }
    }

    if (isset($_GET['error']) && isset($_SESSION['redeem_error'])) {
        $redeem_error = $_SESSION['redeem_error'];
        unset($_SESSION['redeem_error']);
    }

    // Clear error_message if no specific error is present
    if (empty($error_message) && !isset($_GET['error'])) {
        unset($_SESSION['error_message']);
    }

} catch (PDOException $e) {
    $error_message = "Database Error: " . $e->getMessage();
}

if (isset($_GET['error']) && isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rewards - Green Roots</title>
    <link rel="icon" type="image/png" href="<?php echo $favicon_base64; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/rewards.css">
</head>

<body>
    <div class="container">
        <div class="sidebar">
            <img src="<?php echo $logo_base64; ?>" alt="Logo" class="logo">
            <a href="dashboard.php" title="Dashboard"><i class="fas fa-home"></i></a>
            <a href="submit.php" title="Submit Planting"><i class="fas fa-leaf"></i></a>
            <a href="planting_site.php" title="Planting Site"><i class="fas fa-map-pin"></i></a>
            <a href="leaderboard.php" title="Leaderboard"><i class="fas fa-crown"></i></a>
            <a href="rewards.php?section=vouchers" title="Rewards"><i class="fas fa-gift"></i></a>
            <a href="events.php" title="Events"><i class="fas fa-calendar-days"></i></a>
            <a href="history.php" title="History"><i class="fas fa-clock"></i></a>
            <a href="feedback.php" title="Feedback"><i class="fas fa-comment"></i></a>
        </div>
        <div class="main-content">
            <?php if ($error_message): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <?php if ($redeem_error): ?>
                <div class="redeem-error"><?php echo htmlspecialchars($redeem_error); ?></div>
            <?php endif; ?>
            <div class="header">
                <h1>Rewards</h1>
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
            <div class="rewards-section">
                <h2><i class="fas fa-gift"></i> Redeem Your Rewards</h2>
                <div class="eco-points">
                    <i class="fas fa-leaf"></i>
                    Your Eco Points: <span><?php echo $eco_points; ?></span>
                </div>
                <div class="reward-nav">
                    <button id="voucherBtn" class="<?php echo $section === 'vouchers' ? 'active' : ''; ?>"><i
                            class="fas fa-ticket-alt"></i> Exchange for Vouchers</button>
                    <button id="redeemedBtn" class="<?php echo $section === 'redeemed' ? 'active' : ''; ?>"><i
                            class="fas fa-check"></i> Redeemed Vouchers</button>
                    <button id="cashBtn" class="<?php echo $section === 'withdraw' ? 'active' : ''; ?>"><i
                            class="fas fa-wallet"></i> Withdraw as Cash</button>
                </div>
                <div class="redeem-options <?php echo $section === 'vouchers' ? 'active' : ''; ?>" id="voucherSection">
                    <div class="voucher-grid">
                        <?php if (empty($vouchers)): ?>
                            <p>No available vouchers to redeem. You may have redeemed all available vouchers.</p>
                        <?php else: ?>
                            <?php foreach ($vouchers as $voucher): ?>
                                <div class="voucher-card"
                                    onclick="openVoucherModal(<?php echo $voucher['voucher_id']; ?>, '<?php echo htmlspecialchars(json_encode($voucher), ENT_QUOTES); ?>')">
                                    <img src="<?php echo htmlspecialchars($voucher['image']); ?>"
                                        alt="<?php echo htmlspecialchars($voucher['name']); ?>">
                                    <h4><?php echo htmlspecialchars($voucher['name']); ?></h4>
                                    <p><?php echo $voucher['points_cost']; ?> points</p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($vouchers)): ?>
                        <div class="pagination">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?section=vouchers&page=<?php echo $i; ?>"
                                    class="<?php echo $i === $current_page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="redeem-options <?php echo $section === 'redeemed' ? 'active' : ''; ?>" id="redeemedSection">
                    <div class="voucher-grid">
                        <?php if (empty($redeemed_vouchers)): ?>
                            <p>You have not redeemed any vouchers yet.</p>
                        <?php else: ?>
                            <?php foreach ($redeemed_vouchers as $redeemed_voucher): ?>
                                <div class="voucher-card"
                                    onclick="openRedeemedVoucherModal('<?php echo htmlspecialchars(json_encode($redeemed_voucher), ENT_QUOTES); ?>')">
                                    <img src="<?php echo htmlspecialchars($redeemed_voucher['image']); ?>"
                                        alt="<?php echo htmlspecialchars($redeemed_voucher['name']); ?>">
                                    <h4><?php echo htmlspecialchars($redeemed_voucher['name']); ?></h4>
                                    <p>Redeemed on: <?php echo htmlspecialchars($redeemed_voucher['redeemed_at']); ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($redeemed_vouchers)): ?>
                        <div class="pagination">
                            <?php for ($i = 1; $i <= $total_redeemed_pages; $i++): ?>
                                <a href="?section=redeemed&redeemed_page=<?php echo $i; ?>"
                                    class="<?php echo $i === $current_redeemed_page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="redeem-options <?php echo $section === 'withdraw' ? 'active' : ''; ?>" id="cashSection">
                    <div class="redeem-option">
                        <h3><i class="fas fa-wallet"></i> Withdraw as Cash via PayPal</h3>
                        <p>
                            Enter the amount to withdraw in Eco Points (Minimum: 100 points)
                            <span class="info-container">
                                <span class="info-icon">i</span>
                                <span class="info-tooltip">Conversion Rate: 1 Eco Point = ₱0.50</span>
                            </span>
                        </p>
                        <p><strong>Current PayPal Email:</strong>
                            <?php echo $paypal_email ? htmlspecialchars($paypal_email) : 'Not set'; ?></p>
                        <form id="withdrawForm" method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="number" name="amount_points" placeholder="Enter points to withdraw" min="100"
                                required>
                            <button type="button"
                                onclick="openWithdrawModal(<?php echo $eco_points; ?>, '<?php echo htmlspecialchars($paypal_email); ?>')">Withdraw
                                Cash</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="modal" id="voucherModal">
                <div class="modal-content">
                    <span class="close-btn" onclick="closeModal('voucherModal')">×</span>
                    <h3><i class="fas fa-ticket-alt"></i> <span id="voucherModalTitle"></span></h3>
                    <p><strong>Description:</strong> <span id="voucherModalDesc"></span></p>
                    <p><strong>Cost:</strong> <span id="voucherModalPoints"></span> Eco Points</p>
                    <p><strong>Terms & Conditions:</strong> <span id="voucherModalTerms"></span></p>
                    <button onclick="openConfirmModal()">Exchange Voucher</button>
                    <button class="secondary" onclick="closeModal('voucherModal')">Close</button>
                </div>
            </div>
            <div class="modal" id="confirmModal">
                <div class="modal-content">
                    <span class="close-btn" onclick="closeModal('confirmModal')">×</span>
                    <h3><i class="fas fa-check-circle"></i> Confirm Redemption</h3>
                    <p>Are you sure you want to redeem <strong id="confirmVoucherName"></strong> for <strong
                            id="confirmVoucherPoints"></strong> Eco Points?</p>
                    <form id="redeemForm" method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="voucher_id" id="confirmVoucherId">
                        <button type="submit" name="redeem_voucher"
                            onclick="showSpinner('redeemSpinner')">Confirm</button>
                        <button type="button" class="secondary" onclick="closeModal('confirmModal')">Cancel</button>
                        <div class="spinner" id="redeemSpinner"></div>
                    </form>
                </div>
            </div>
            <?php if ($voucher_redeem_message && isset($_SESSION['redeemed_voucher'])): ?>
                <div class="modal active" id="voucherSuccessModal">
                    <div class="modal-content">
                        <span class="close-btn" onclick="closeSuccessModal('voucherSuccessModal')">×</span>
                        <div class="redeem-message"><?php echo htmlspecialchars($voucher_redeem_message); ?></div>
                        <p><strong>Voucher Code:</strong>
                            <?php echo htmlspecialchars($_SESSION['redeemed_voucher']['code']); ?></p>
                        <p><strong>Expires On:</strong>
                            <?php echo htmlspecialchars($_SESSION['redeemed_voucher']['expiry_date']); ?></p>
                        <div id="voucherQRCode"></div>
                        <button onclick="generateVoucherPDF()">Download EcoVoucher Pass</button>
                        <button class="secondary" onclick="closeSuccessModal('voucherSuccessModal')">Close</button>
                    </div>
                </div>
            <?php endif; ?>
            <div class="modal" id="redeemedVoucherModal">
                <div class="modal-content">
                    <span class="close-btn" onclick="closeModal('redeemedVoucherModal')">×</span>
                    <h3><i class="fas fa-ticket-alt"></i> <span id="redeemedVoucherModalTitle"></span></h3>
                    <p><strong>Voucher Code:</strong> <span id="redeemedVoucherModalCode"></span></p>
                    <p><strong>Description:</strong> <span id="redeemedVoucherModalDesc"></span></p>
                    <p><strong>Cost:</strong> <span id="redeemedVoucherModalPoints"></span> Eco Points</p>
                    <p><strong>Redeemed At:</strong> <span id="redeemedVoucherModalRedeemedAt"></span></p>
                    <p><strong>Expires On:</strong> <span id="redeemedVoucherModalExpiry"></span></p>
                    <div id="redeemedVoucherQRCode"></div>
                    <button onclick="generateRedeemedVoucherPDF()">Download EcoVoucher Pass</button>
                    <button class="secondary" onclick="closeModal('redeemedVoucherModal')">Close</button>
                </div>
            </div>
            <div class="modal" id="withdrawModal">
                <div class="modal-content">
                    <span class="close-btn" onclick="closeModal('withdrawModal')">×</span>
                    <h3><i class="fas fa-wallet"></i> Confirm Withdrawal</h3>
                    <p><strong>Amount to Withdraw:</strong> ₱<span id="withdrawAmount"></span></p>
                    <p><strong>Eco Points:</strong> <span id="withdrawPoints"></span> points</p>
                    <p><strong>PayPal Email:</strong> <span id="withdrawEmail"></span></p>
                    <form id="withdrawConfirmForm" method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="amount_points" id="withdrawPointsInput">
                        <button type="submit" name="withdraw_cash" onclick="showSpinner('withdrawSpinner')">Confirm
                            Withdrawal</button>
                        <button type="button" class="secondary" onclick="closeModal('withdrawModal')">Cancel</button>
                        <div class="spinner" id="withdrawSpinner"></div>
                    </form>
                </div>
            </div>
            <?php if ($withdraw_message && isset($_SESSION['withdrawal_success'])): ?>
                <div class="modal active" id="withdrawSuccessModal">
                    <div class="modal-content">
                        <span class="close-btn" onclick="closeSuccessModal('withdrawSuccessModal')">×</span>
                        <div class="withdraw-message"><?php echo htmlspecialchars($withdraw_message); ?></div>
                        <p><strong>Amount:</strong>
                            ₱<?php echo htmlspecialchars(number_format($_SESSION['withdrawal_success']['cash_amount'], 2)); ?>
                        </p>
                        <p><strong>Points Deducted:</strong>
                            <?php echo htmlspecialchars($_SESSION['withdrawal_success']['points']); ?> points</p>
                        <p><strong>PayPal Email:</strong>
                            <?php echo htmlspecialchars($_SESSION['withdrawal_success']['paypal_email']); ?></p>
                        <p><strong>Withdrawn At:</strong>
                            <?php echo htmlspecialchars($_SESSION['withdrawal_success']['withdrawn_at']); ?></p>
                        <button class="secondary" onclick="closeSuccessModal('withdrawSuccessModal')">Close</button>
                    </div>
                </div>
            <?php endif; ?>
            <div class="modal" id="paypalModal">
                <div class="modal-content">
                    <span class="close-btn" onclick="closeModal('paypalModal')">×</span>
                    <h3><i class="fas fa-envelope"></i> Set PayPal Email</h3>
                    <p>Please provide your PayPal email to proceed with the withdrawal.</p>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="email" name="paypal_email" placeholder="Enter your PayPal email" required>
                        <button type="submit" name="update_paypal_email">Save</button>
                        <button type="button" class="secondary" onclick="closeModal('paypalModal')">Cancel</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="../assets/js/jsPDF-3.0.1/dist/jspdf.umd.min.js"></script>
    <script>
        const functionalities = [
            { name: 'Dashboard', url: 'dashboard.php' },
            { name: 'Submit Planting', url: 'submit.php' },
            { name: 'Planting Site', url: 'planting_site.php' },
            { name: 'Leaderboard', url: 'leaderboard.php' },
            { name: 'Rewards', url: 'rewards.php?section=vouchers' },
            { name: 'Events', url: 'events.php' },
            { name: 'History', url: 'history.php' },
            { name: 'Feedback', url: 'feedback.php' },
            { name: 'Logout', url: 'logout.php' }
        ];

        const searchInput = document.querySelector('#searchInput');
        const searchResults = document.querySelector('#searchResults');

        searchInput.addEventListener('input', function (e) {
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

        document.addEventListener('click', function (e) {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.classList.remove('active');
            }
        });

        const profileBtn = document.querySelector('#profileBtn');
        const profileDropdown = document.querySelector('#profileDropdown');

        profileBtn.addEventListener('click', function () {
            profileDropdown.classList.toggle('active');
        });

        document.addEventListener('click', function (e) {
            if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                profileDropdown.classList.remove('active');
            }
        });

        const voucherBtn = document.querySelector('#voucherBtn');
        const redeemedBtn = document.querySelector('#redeemedBtn');
        const cashBtn = document.querySelector('#cashBtn');
        const voucherSection = document.querySelector('#voucherSection');
        const redeemedSection = document.querySelector('#redeemedSection');
        const cashSection = document.querySelector('#cashSection');

        function updateURL(section, page = null) {
            const url = new URL(window.location);
            url.searchParams.set('section', section);
            url.searchParams.delete('error');
            url.searchParams.delete('success');
            if (page) {
                if (section === 'vouchers') {
                    url.searchParams.set('page', page);
                } else if (section === 'redeemed') {
                    url.searchParams.set('redeemed_page', page);
                }
            } else {
                if (section === 'vouchers' && !url.searchParams.has('page')) {
                    url.searchParams.delete('page');
                } else if (section === 'redeemed' && !url.searchParams.has('redeemed_page')) {
                    url.searchParams.delete('redeemed_page');
                }
            }
            window.history.pushState({}, '', url);
        }

        voucherBtn.addEventListener('click', function () {
            voucherBtn.classList.add('active');
            redeemedBtn.classList.remove('active');
            cashBtn.classList.remove('active');
            voucherSection.classList.add('active');
            redeemedSection.classList.remove('active');
            cashSection.classList.remove('active');
            updateURL('vouchers');
        });

        redeemedBtn.addEventListener('click', function () {
            redeemedBtn.classList.add('active');
            voucherBtn.classList.remove('active');
            cashBtn.classList.remove('active');
            redeemedSection.classList.add('active');
            voucherSection.classList.remove('active');
            cashSection.classList.remove('active');
            updateURL('redeemed');
        });

        cashBtn.addEventListener('click', function () {
            cashBtn.classList.add('active');
            voucherBtn.classList.remove('active');
            redeemedBtn.classList.remove('active');
            cashSection.classList.add('active');
            voucherSection.classList.remove('active');
            redeemedSection.classList.remove('active');
            updateURL('withdraw');
        });

        function closeModal(modalId) {
            document.querySelector(`#${modalId}`).classList.remove('active');
        }

        document.addEventListener('click', function (e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('active');
            }
        });

        let selectedVoucher = null;

        function openVoucherModal(voucherId, voucherJson) {
            selectedVoucher = JSON.parse(voucherJson);
            document.querySelector('#voucherModalTitle').textContent = selectedVoucher.name;
            document.querySelector('#voucherModalDesc').textContent = selectedVoucher.description;
            document.querySelector('#voucherModalPoints').textContent = selectedVoucher.points_cost;
            document.querySelector('#voucherModalTerms').textContent = selectedVoucher.terms;
            document.querySelector('#voucherModal').classList.add('active');
        }

        function openConfirmModal() {
            if (selectedVoucher) {
                document.querySelector('#confirmVoucherName').textContent = selectedVoucher.name;
                document.querySelector('#confirmVoucherPoints').textContent = selectedVoucher.points_cost;
                document.querySelector('#confirmVoucherId').value = selectedVoucher.voucher_id;
                document.querySelector('#confirmModal').classList.add('active');
            }
        }

        let selectedRedeemedVoucher = null;

        function openRedeemedVoucherModal(voucherJson) {
            selectedRedeemedVoucher = JSON.parse(voucherJson);
            document.querySelector('#redeemedVoucherModalTitle').textContent = selectedRedeemedVoucher.name;
            document.querySelector('#redeemedVoucherModalCode').textContent = selectedRedeemedVoucher.code;
            document.querySelector('#redeemedVoucherModalDesc').textContent = selectedRedeemedVoucher.description;
            document.querySelector('#redeemedVoucherModalPoints').textContent = selectedRedeemedVoucher.points_cost;
            document.querySelector('#redeemedVoucherModalRedeemedAt').textContent = selectedRedeemedVoucher.redeemed_at;
            document.querySelector('#redeemedVoucherModalExpiry').textContent = selectedRedeemedVoucher.expiry_date;
            const qrCodeElement = document.querySelector('#redeemedVoucherQRCode');
            generateQRCode(qrCodeElement, selectedRedeemedVoucher.qr_code);
            document.querySelector('#redeemedVoucherModal').classList.add('active');
        }

        function openWithdrawModal(ecoPoints, paypalEmail) {
            const amountPoints = document.querySelector('input[name="amount_points"]').value;
            const minimumPoints = 100;
            if (!amountPoints || amountPoints < minimumPoints) {
                alert(`Please enter an amount of at least ${minimumPoints} points to withdraw.`);
                return;
            }
            if (amountPoints > ecoPoints) {
                alert('Insufficient eco points for this withdrawal.');
                return;
            }
            if (!paypalEmail) {
                document.querySelector('#paypalModal').classList.add('active');
                return;
            }
            const cashAmount = (amountPoints * 0.5).toFixed(2);
            document.querySelector('#withdrawAmount').textContent = cashAmount;
            document.querySelector('#withdrawPoints').textContent = amountPoints;
            document.querySelector('#withdrawEmail').textContent = paypalEmail;
            document.querySelector('#withdrawPointsInput').value = amountPoints;
            document.querySelector('#withdrawModal').classList.add('active');
        }

        function showSpinner(spinnerId) {
            const spinner = document.querySelector(`#${spinnerId}`);
            spinner.style.display = 'block';
        }

        function generateQRCode(element, data) {
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
                element.innerHTML = '<p>Error: Failed to generate QR code. Please try again.</p>';
            }
        }

        window.addEventListener('load', function () {
            const voucherSuccessModal = document.querySelector('#voucherSuccessModal');
            if (voucherSuccessModal && voucherSuccessModal.classList.contains('active')) {
                const voucherQRCode = document.querySelector('#voucherQRCode');
                const qrCodeData = "<?php echo isset($_SESSION['redeemed_voucher']['qr_code']) ? htmlspecialchars($_SESSION['redeemed_voucher']['qr_code']) : ''; ?>";
                if (qrCodeData) {
                    generateQRCode(voucherQRCode, qrCodeData);
                }
            }
        });

        function generateVoucherPDF() {
            const voucherData = <?php echo isset($_SESSION['redeemed_voucher']) ? json_encode($_SESSION['redeemed_voucher']) : 'null'; ?>;
            if (!voucherData) {
                alert('Voucher data not available.');
                return;
            }

            const loadingSpinner = document.querySelector('#redeemSpinner');
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
                doc.text('EcoVoucher Pass', 105, 25, { align: 'center' });

                doc.setFontSize(14);
                doc.setFont('helvetica', 'normal');
                doc.setTextColor(...secondaryColor);
                doc.text('Presented by Green Roots Initiative', 105, 35, { align: 'center' });

                // Draw horizontal divider
                doc.setDrawColor(...primaryColor);
                doc.setLineWidth(0.5);
                doc.line(20, 40, 190, 40);

                // Voucher Info
                doc.setFontSize(12);
                doc.setTextColor(...labelColor);
                doc.text('Voucher Name:', 20, 55);
                doc.text('Voucher Code:', 20, 65);
                doc.text('Points Cost:', 20, 75);
                doc.text('Redeemed At:', 20, 85);
                doc.text('Expires On:', 20, 95);

                doc.setFont('helvetica', 'bold');
                doc.setTextColor(...secondaryColor);
                doc.text(`${voucherData.name}`, 50, 55);
                doc.text(`${voucherData.code}`, 50, 65);
                doc.text(`${voucherData.points_cost} Eco Points`, 50, 75);
                doc.text(`${voucherData.redeemed_at}`, 50, 85);
                doc.text(`${voucherData.expiry_date}`, 50, 95);

                // Partner Info
                doc.setFontSize(12);
                doc.setTextColor(...labelColor);
                doc.text('Partner:', 20, 115);
                doc.text('Contact:', 20, 125);
                doc.text('Website:', 20, 135);

                doc.setFont('helvetica', 'bold');
                doc.setTextColor(...secondaryColor);
                doc.text(`${voucherData.partner}`, 50, 115);
                doc.text(`${voucherData.partner_contact}`, 50, 125);
                doc.text(`${voucherData.partner_website}`, 50, 135);

                // Terms
                doc.setFontSize(12);
                doc.setTextColor(...labelColor);
                doc.text('Terms and Conditions:', 20, 155);

                doc.setFont('helvetica', 'normal');
                doc.setTextColor(...secondaryColor);
                const termsLines = doc.splitTextToSize(voucherData.terms, 170);
                doc.text(termsLines, 20, 165);

                // QR Code from Modal
                try {
                    const qrCanvas = document.querySelector('#voucherQRCode canvas');
                    if (!qrCanvas) throw new Error('QR code canvas not found in modal');

                    const qrImage = qrCanvas.toDataURL('image/png');
                    if (qrImage === 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAJIAAACWCAYAAABs0x1ZAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAABeSURBVHhe7cEBDQAAAMKg909tDjtwABS7sYMgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICB7B8HbgD4qS5cAAAAASUVORK5CYII=') {
                        throw new Error('QR code canvas is empty');
                    }

                    doc.addImage(qrImage, 'PNG', 80, 190 + termsLines.length * 7, 50, 50);
                    doc.setDrawColor(...primaryColor);
                    doc.rect(80, 190 + termsLines.length * 7, 50, 50);

                    // Note
                    doc.setFontSize(10);
                    doc.setTextColor(102, 102, 102);
                    doc.setFont('helvetica', 'italic');
                    doc.text('Scan this QR code for quick authentication.', 105, 250 + termsLines.length * 7, { align: 'center' });

                    doc.save(`EcoVoucher_${voucherData.code}_Pass.pdf`);
                    console.log('PDF generated successfully with QR code.');
                } catch (error) {
                    console.error('Failed to add QR code to PDF:', error);
                    doc.setTextColor(220, 38, 38);
                    doc.setFont('helvetica', 'bold');
                    doc.text('⚠ Unable to include QR code.', 20, 200 + termsLines.length * 7);
                    doc.save(`EcoVoucher_${voucherData.code}_Pass.pdf`);
                    alert('Failed to include QR code in the PDF. The EcoVoucher Pass has been downloaded without it.');
                }

            } catch (error) {
                console.error('Failed to generate PDF:', error);
                alert('Error: Failed to generate PDF. Please try again: ' + error.message);
            } finally {
                loadingSpinner.classList.remove('active');
            }
        }

        function generateRedeemedVoucherPDF() {
            if (!selectedRedeemedVoucher) {
                alert('Redeemed voucher data not available.');
                return;
            }

            const loadingSpinner = document.querySelector('#redeemSpinner');
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
                doc.text('EcoVoucher Pass', 105, 25, { align: 'center' });

                doc.setFontSize(14);
                doc.setFont('helvetica', 'normal');
                doc.setTextColor(...secondaryColor);
                doc.text('Presented by Green Roots Initiative', 105, 35, { align: 'center' });

                // Draw horizontal divider
                doc.setDrawColor(...primaryColor);
                doc.setLineWidth(0.5);
                doc.line(20, 40, 190, 40);

                // Voucher Info
                doc.setFontSize(12);
                doc.setTextColor(...labelColor);
                doc.text('Voucher Name:', 20, 55);
                doc.text('Voucher Code:', 20, 65);
                doc.text('Points Cost:', 20, 75);
                doc.text('Redeemed At:', 20, 85);
                doc.text('Expires On:', 20, 95);

                doc.setFont('helvetica', 'bold');
                doc.setTextColor(...secondaryColor);
                doc.text(`${selectedRedeemedVoucher.name}`, 50, 55);
                doc.text(`${selectedRedeemedVoucher.code}`, 50, 65);
                doc.text(`${selectedRedeemedVoucher.points_cost} Eco Points`, 50, 75);
                doc.text(`${selectedRedeemedVoucher.redeemed_at}`, 50, 85);
                doc.text(`${selectedRedeemedVoucher.expiry_date}`, 50, 95);

                // Partner Info
                doc.setFontSize(12);
                doc.setTextColor(...labelColor);
                doc.text('Partner:', 20, 115);
                doc.text('Contact:', 20, 125);
                doc.text('Website:', 20, 135);

                doc.setFont('helvetica', 'bold');
                doc.setTextColor(...secondaryColor);
                doc.text(`${selectedRedeemedVoucher.partner}`, 50, 115);
                doc.text(`${selectedRedeemedVoucher.partner_contact}`, 50, 125);
                doc.text(`${selectedRedeemedVoucher.partner_website}`, 50, 135);

                // Terms
                doc.setFontSize(12);
                doc.setTextColor(...labelColor);
                doc.text('Terms and Conditions:', 20, 155);

                doc.setFont('helvetica', 'normal');
                doc.setTextColor(...secondaryColor);
                const termsLines = doc.splitTextToSize(selectedRedeemedVoucher.terms, 170);
                doc.text(termsLines, 20, 165);

                // QR Code from Modal
                try {
                    const qrCanvas = document.querySelector('#redeemedVoucherQRCode canvas');
                    if (!qrCanvas) throw new Error('QR code canvas not found in modal');

                    const qrImage = qrCanvas.toDataURL('image/png');
                    if (qrImage === 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAJIAAACWCAYAAABs0x1ZAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAABeSURBVHhe7cEBDQAAAMKg909tDjtwABS7sYMgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICB7B8HbgD4qS5cAAAAASUVORK5CYII=') {
                        throw new Error('QR code canvas is empty');
                    }

                    doc.addImage(qrImage, 'PNG', 80, 190 + termsLines.length * 7, 50, 50);
                    doc.setDrawColor(...primaryColor);
                    doc.rect(80, 190 + termsLines.length * 7, 50, 50);

                    // Note
                    doc.setFontSize(10);
                    doc.setTextColor(102, 102, 102);
                    doc.setFont('helvetica', 'italic');
                    doc.text('Scan this QR code for quick authentication.', 105, 250 + termsLines.length * 7, { align: 'center' });

                    doc.save(`EcoVoucher_${selectedRedeemedVoucher.code}_Pass.pdf`);
                    console.log('PDF generated successfully with QR code.');
                } catch (error) {
                    console.error('Failed to add QR code to PDF:', error);
                    doc.setTextColor(220, 38, 38);
                    doc.setFont('helvetica', 'bold');
                    doc.text('⚠ Unable to include QR code.', 20, 200 + termsLines.length * 7);
                    doc.save(`EcoVoucher_${selectedRedeemedVoucher.code}_Pass.pdf`);
                    alert('Failed to include QR code in the PDF. The EcoVoucher Pass has been downloaded without it.');
                }

            } catch (error) {
                console.error('Failed to generate PDF:', error);
                alert('Error: Failed to generate PDF. Please try again: ' + error.message);
            } finally {
                loadingSpinner.classList.remove('active');
            }
        }

        function closeSuccessModal(modalId) {
            document.querySelector(`#${modalId}`).classList.remove('active');
            <?php unset($_SESSION['redeemed_voucher']);
            unset($_SESSION['withdrawal_success']); ?>
        }
    </script>
</body>

</html>