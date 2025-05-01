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
$voucher_redeem_message = ''; // For voucher redemption success
$withdraw_message = '';       // For withdrawal success
$redeem_error = '';
$paypal_email = '';
$withdrawal_attempts = isset($_SESSION['withdrawal_attempts']) ? $_SESSION['withdrawal_attempts'] : [];

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Default placeholder image (1x1 transparent pixel) as a fallback
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
    $redeem_error = "Error fetching default voucher image: " . $e->getMessage();
}

// Fetch user data
try {
    $stmt = $pdo->prepare("
        SELECT user_id, username, email, profile_picture, eco_points, paypal_email 
        FROM users 
        WHERE user_id = :user_id
    ");
    $stmt->execute(['user_id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header('Location: login.php');
        exit;
    }

    $eco_points = $user['eco_points'];
    $paypal_email = $user['paypal_email'];

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

    // Pagination for vouchers
    $vouchers_per_page = 10; // Display 10 vouchers per page for a 5x2 grid
    $current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $current_page = max(1, $current_page); // Ensure page is at least 1
    $offset = ($current_page - 1) * $vouchers_per_page;

    // Fetch total number of vouchers
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vouchers");
    $stmt->execute();
    $total_vouchers = $stmt->fetchColumn();
    $total_pages = ceil($total_vouchers / $vouchers_per_page);

    // Fetch available vouchers for the current page
    $stmt = $pdo->prepare("
        SELECT voucher_id, name, description, points_cost, code, image, terms, partner, partner_contact, partner_website, expiry_date 
        FROM vouchers 
        ORDER BY points_cost ASC 
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $vouchers_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert voucher images to base64, use default if necessary
    $default_jpeg_header = hex2bin('FFD8FFE000104A46494600010101006000600000');
    foreach ($vouchers as &$voucher) {
        if ($voucher['image'] === $default_jpeg_header || empty($voucher['image'])) {
            $voucher['image'] = $default_voucher_image;
        } else {
            $voucher['image'] = 'data:image/jpeg;base64,' . base64_encode($voucher['image']);
        }
    }
    unset($voucher);

    // Handle Voucher Redemption
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_voucher'])) {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $redeem_error = 'Invalid CSRF token. Please try again.';
        } else {
            $voucher_id = (int)$_POST['voucher_id'];

            // Fetch the selected voucher
            $stmt = $pdo->prepare("
                SELECT name, description, points_cost, code, terms, partner, partner_contact, partner_website, expiry_date 
                FROM vouchers 
                WHERE voucher_id = :voucher_id
            ");
            $stmt->execute(['voucher_id' => $voucher_id]);
            $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($voucher) {
                $points_cost = $voucher['points_cost'];

                // Check redemption limit (e.g., max 1 per user per voucher)
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM activities 
                    WHERE user_id = :user_id 
                    AND description LIKE :description
                ");
                $stmt->execute([
                    'user_id' => $user_id,
                    'description' => "%Redeemed voucher: {$voucher['name']}%"
                ]);
                $redeem_count = $stmt->fetchColumn();

                if ($redeem_count >= 1) {
                    $redeem_error = 'You have already redeemed this voucher. Limit: 1 per user.';
                } elseif ($eco_points < $points_cost) {
                    $redeem_error = 'Insufficient eco points to redeem this voucher.';
                } else {
                    // Start transaction
                    $pdo->beginTransaction();
                    try {
                        // Deduct points
                        $stmt = $pdo->prepare("UPDATE users SET eco_points = eco_points - :points WHERE user_id = :user_id");
                        $stmt->execute(['points' => $points_cost, 'user_id' => $user_id]);

                        // Calculate expiry date (e.g., 30 days from redemption)
                        $redeemed_at = date('Y-m-d H:i:s');
                        $expiry_date = date('Y-m-d H:i:s', strtotime($redeemed_at . ' +30 days'));

                        // Log activity
                        $stmt = $pdo->prepare("
                            INSERT INTO activities (user_id, description, activity_type, created_at) 
                            VALUES (:user_id, :description, 'reward', NOW())
                        ");
                        $stmt->execute([
                            'user_id' => $user_id,
                            'description' => "Redeemed voucher: {$voucher['name']} for $points_cost points"
                        ]);

                        // Store voucher details in session for PDF generation
                        $_SESSION['redeemed_voucher'] = [
                            'name' => $voucher['name'],
                            'code' => $voucher['code'],
                            'points_cost' => $points_cost,
                            'terms' => $voucher['terms'],
                            'partner' => $voucher['partner'],
                            'partner_contact' => $voucher['partner_contact'],
                            'partner_website' => $voucher['partner_website'],
                            'expiry_date' => $expiry_date,
                            'redeemed_at' => $redeemed_at
                        ];

                        $eco_points -= $points_cost;
                        $pdo->commit();
                        $voucher_redeem_message = "Successfully redeemed {$voucher['name']}!";
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $redeem_error = "Error redeeming voucher: " . $e->getMessage();
                    }
                }
            } else {
                $redeem_error = 'Invalid voucher selected.';
            }
        }
    }

    // Handle Cash Withdrawal
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['withdraw_cash'])) {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $redeem_error = 'Invalid CSRF token. Please try again.';
        } else {
            $amount_points = (int)$_POST['amount_points'];
            $minimum_points = 100; // Set minimum withdrawal to 100 points

            // Rate limiting: max 3 withdrawals per hour
            $current_time = time();
            $withdrawal_attempts = array_filter($withdrawal_attempts, function($timestamp) {
                return (time() - $timestamp) < 3600; // Within 1 hour
            });
            $_SESSION['withdrawal_attempts'] = $withdrawal_attempts;

            if (count($withdrawal_attempts) >= 3) {
                $redeem_error = 'Withdrawal limit reached. Please try again later.';
            } elseif ($amount_points < $minimum_points) {
                $redeem_error = "Minimum withdrawal is $minimum_points points.";
            } elseif ($amount_points > $eco_points) {
                $redeem_error = 'Insufficient eco points for this withdrawal.';
            } elseif (!$paypal_email) {
                $redeem_error = 'Please set your PayPal email in Payment Methods to proceed.';
            } else {
                // Conversion rate: 100 points = $1
                $cash_amount = $amount_points / 100;

                // Start transaction
                $pdo->beginTransaction();
                try {
                    // Deduct points
                    $stmt = $pdo->prepare("UPDATE users SET eco_points = eco_points - :points WHERE user_id = :user_id");
                    $stmt->execute(['points' => $amount_points, 'user_id' => $user_id]);

                    // Log activity
                    $stmt = $pdo->prepare("
                        INSERT INTO activities (user_id, description, activity_type, created_at) 
                        VALUES (:user_id, :description, 'reward', NOW())
                    ");
                    $stmt->execute([
                        'user_id' => $user_id,
                        'description' => "Withdrew $cash_amount USD ($amount_points points) via PayPal to $paypal_email"
                    ]);

                    // Store withdrawal details in session for success modal
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
                    $withdraw_message = "Successfully requested withdrawal of $cash_amount USD to $paypal_email.";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $redeem_error = "Error processing withdrawal: " . $e->getMessage();
                }
            }
        }
    }

    // Handle PayPal Email Update from Modal
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_paypal_email'])) {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $redeem_error = 'Invalid CSRF token. Please try again.';
        } else {
            $new_paypal_email = trim($_POST['paypal_email']);

            if (empty($new_paypal_email)) {
                $redeem_error = 'PayPal email is required.';
            } elseif (!filter_var($new_paypal_email, FILTER_VALIDATE_EMAIL)) {
                $redeem_error = 'Please enter a valid PayPal email address.';
            } else {
                $stmt = $pdo->prepare("UPDATE users SET paypal_email = :paypal_email WHERE user_id = :user_id");
                $stmt->execute([
                    'paypal_email' => $new_paypal_email,
                    'user_id' => $user_id
                ]);

                $paypal_email = $new_paypal_email;
                $withdraw_message = 'PayPal email updated successfully! You can now proceed with your withdrawal.';
            }
        }
    }

} catch (PDOException $e) {
    $error_message = "Error: " . $e->getMessage();
}

// Handle PDF Download for Voucher
if (isset($_GET['download_pdf']) && isset($_SESSION['redeemed_voucher'])) {
    require_once '../vendor/autoload.php'; // Include TCPDF library (assumed to be installed via Composer)
    $voucher = $_SESSION['redeemed_voucher'];

    // Create new PDF document
    $pdf = new \TCPDF();
    $pdf->SetCreator('Green Roots');
    $pdf->SetAuthor('Green Roots');
    $pdf->SetTitle('Voucher Redemption');
    $pdf->SetMargins(15, 15, 15);
    $pdf->AddPage();

    // Set font
    $pdf->SetFont('helvetica', '', 12);

    // Add logo (placeholder)
    $pdf->Image('https://via.placeholder.com/50?text=Logo', 15, 15, 30, 30);

    // Title
    $pdf->SetXY(15, 50);
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'Green Roots Voucher', 0, 1, 'C');

    // Voucher Details
    $pdf->SetFont('helvetica', '', 12);
    $pdf->SetXY(15, 70);
    $pdf->Cell(0, 10, "Voucher Name: " . htmlspecialchars($voucher['name']), 0, 1);
    $pdf->Cell(0, 10, "Voucher Code: " . htmlspecialchars($voucher['code']), 0, 1);
    $pdf->Cell(0, 10, "Points Cost: " . htmlspecialchars($voucher['points_cost']) . " Eco Points", 0, 1);
    $pdf->Cell(0, 10, "Redeemed At: " . htmlspecialchars($voucher['redeemed_at']), 0, 1);
    $pdf->Cell(0, 10, "Expires On: " . htmlspecialchars($voucher['expiry_date']), 0, 1);

    // Partner Details
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, "Business Partner Information", 0, 1);
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 10, "Partner: " . htmlspecialchars($voucher['partner']), 0, 1);
    $pdf->Cell(0, 10, "Contact: " . htmlspecialchars($voucher['partner_contact']), 0, 1);
    $pdf->Cell(0, 10, "Website: " . htmlspecialchars($voucher['partner_website']), 0, 1);

    // Terms
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, "Terms and Conditions", 0, 1);
    $pdf->SetFont('helvetica', '', 12);
    $pdf->MultiCell(0, 10, htmlspecialchars($voucher['terms']), 0, 1);

    // QR Code Placeholder
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'I', 12);
    $pdf->Cell(0, 10, "[QR Code for Authentication]", 0, 1, 'C');

    // Output PDF
    $pdf->Output('voucher_' . $voucher['code'] . '.pdf', 'D');
    exit;
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
            transition: color 0.3s, transform 0.2s;
        }

        .sidebar a:hover {
            color: #4CAF50;
            transform: scale(1.1);
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
            max-width: 1200px;
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
            background: #e0e7ff;
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
            background: #e0e7ff;
        }

        .rewards-section {
            background: #E8F5E9;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 1200px;
            margin-bottom: 30px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .rewards-section h2 {
            font-size: 28px;
            color: #4CAF50;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .reward-nav {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .reward-nav button {
            background: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            flex: 1;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #666;
        }

        .reward-nav button i {
            color: #666;
            transition: color 0.3s ease;
        }

        .reward-nav button.active {
            background: rgb(187, 235, 191);
            color: #4CAF50;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        .reward-nav button.active i {
            color: #4CAF50;
        }

        .reward-nav button:hover {
            background: rgba(76, 175, 80, 0.1);
            color: #4CAF50;
        }

        .reward-nav button:hover i {
            color: #4CAF50;
        }

        .eco-points {
            background: rgb(187, 235, 191);
            padding: 15px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
            font-size: 18px;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .eco-points i {
            color: #4CAF50;
            font-size: 20px;
        }

        .eco-points span {
            font-weight: bold;
            color: #4CAF50;
        }

        .redeem-options {
            display: none;
        }

        .redeem-options.active {
            display: block;
        }

        .voucher-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr); /* 5 columns */
            grid-template-rows: repeat(2, auto); /* 2 rows */
            gap: 20px;
            margin-bottom: 20px;
        }

        .voucher-card {
            background: #fff;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.2s;
            cursor: pointer;
        }

        .voucher-card:hover {
            transform: translateY(-5px);
        }

        .voucher-card img {
            width: 100%;
            height: 120px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 10px;
        }

        .voucher-card h4 {
            font-size: 16px;
            color: #4CAF50;
            margin-bottom: 5px;
        }

        .voucher-card p {
            font-size: 14px;
            color: #388E3C;
            font-weight: bold;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }

        .pagination a {
            padding: 8px 16px;
            background: #fff;
            border-radius: 5px;
            color: #666;
            text-decoration: none;
            transition: background 0.3s;
        }

        .pagination a:hover {
            background: #4CAF50;
            color: #fff;
        }

        .pagination a.active {
            background: #4CAF50;
            color: #fff;
        }

        .redeem-option {
            background: #fff;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .redeem-option h3 {
            font-size: 22px;
            color: #4CAF50;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .redeem-option .info-container {
            position: relative;
            display: inline-block;
            margin-left: 5px;
        }

        .redeem-option .info-icon {
            font-size: 14px;
            color: #666;
            cursor: pointer;
            border: 1px solid #ccc;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .redeem-option .info-tooltip {
            visibility: hidden;
            width: 200px;
            background: #333;
            color: #fff;
            text-align: center;
            border-radius: 5px;
            padding: 8px;
            position: absolute;
            z-index: 1;
            top: -40px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 12px;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .redeem-option .info-container:hover .info-tooltip {
            visibility: visible;
            opacity: 1;
        }

        .redeem-option p {
            font-size: 14px;
            color: #666;
            margin-bottom: 15px;
        }

        .redeem-option input[type="number"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #e0e7ff;
            border-radius: 5px;
            font-size: 16px;
            margin-bottom: 15px;
            outline: none;
        }

        .redeem-option button {
            background: #4CAF50;
            color: #fff;
            border: none;
            padding: 12px 20px;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s, transform 0.1s;
            width: 100%;
        }

        .redeem-option button:hover {
            background: #388E3C;
            transform: scale(1.02);
        }

        .redeem-option button:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        .error-message,
        .redeem-error {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 15px;
            text-align: center;
            font-size: 16px;
            width: 100%;
            max-width: 800px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
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
            justify-content: center;
            align-items: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: #fff;
            padding: 30px;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
            text-align: left;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .modal-content h3 {
            font-size: 24px;
            color: #4CAF50;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-content p {
            font-size: 16px;
            color: #333;
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .modal-content p strong {
            color: #4CAF50;
        }

        .modal-content .redeem-message,
        .modal-content .withdraw-message {
            font-size: 16px;
            color: #4CAF50;
            margin-bottom: 20px;
            text-align: center;
        }

        .modal-content .close-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 24px;
            color: #666;
            cursor: pointer;
            transition: color 0.3s;
        }

        .modal-content .close-btn:hover {
            color: #dc2626;
        }

        .modal-content button {
            background: #4CAF50;
            color: #fff;
            border: none;
            padding: 12px 20px;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s, transform 0.1s;
            margin: 5px;
            width: 100%;
        }

        .modal-content button:hover {
            background: #388E3C;
            transform: scale(1.02);
        }

        .modal-content button.secondary {
            background: #666;
        }

        .modal-content button.secondary:hover {
            background: #555;
        }

        .modal-content .spinner {
            display: none;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #4CAF50;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 10px auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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

            .rewards-section {
                padding: 20px;
            }

            .rewards-section h2 {
                font-size: 24px;
            }

            .reward-nav {
                flex-direction: column;
                gap: 5px;
            }

            .reward-nav button {
                padding: 8px 10px;
                font-size: 14px;
            }

            .reward-nav button i {
                font-size: 14px;
            }

            .eco-points {
                font-size: 16px;
            }

            .eco-points i {
                font-size: 18px;
            }

            .voucher-grid {
                grid-template-columns: repeat(2, 1fr); /* On mobile, reduce to 2 columns */
                grid-template-rows: repeat(5, auto); /* Adjust rows to fit 10 items */
            }

            .voucher-card img {
                height: 100px;
            }

            .voucher-card h4 {
                font-size: 14px;
            }

            .voucher-card p {
                font-size: 12px;
            }

            .pagination a {
                padding: 6px 12px;
                font-size: 14px;
            }

            .redeem-option {
                padding: 15px;
            }

            .redeem-option h3 {
                font-size: 18px;
            }

            .redeem-option input[type="number"] {
                font-size: 14px;
                padding: 8px;
            }

            .redeem-option button {
                font-size: 14px;
                padding: 8px 15px;
            }

            .modal-content {
                width: 95%;
                padding: 20px;
            }

            .modal-content h3 {
                font-size: 20px;
            }

            .modal-content p {
                font-size: 14px;
            }

            .modal-content .redeem-message,
            .modal-content .withdraw-message {
                font-size: 14px;
            }

            .modal-content button {
                font-size: 14px;
                padding: 8px 15px;
            }

            .error-message,
            .redeem-error {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <img src="<?php echo $logo_base64; ?>" alt="Logo" class="logo">
            <a href="dashboard.php" title="Dashboard"><i class="fas fa-home"></i></a>
            <a href="submit.php" title="Submit Planting"><i class="fas fa-tree"></i></a>
            <a href="planting_site.php" title="Planting Site"><i class="fas fa-map-marker-alt"></i></a>
            <a href="leaderboard.php" title="Leaderboard"><i class="fas fa-trophy"></i></a>
            <a href="rewards.php" title="Rewards"><i class="fas fa-gift"></i></a>
            <a href="events.php" title="Events"><i class="fas fa-calendar-alt"></i></a>
            <a href="history.php" title="History"><i class="fas fa-history"></i></a>
            <a href="feedback.php" title="Feedback"><i class="fas fa-comment-dots"></i></a>
        </div>
        <div class="main-content">
            <?php if (isset($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <?php if ($redeem_error): ?>
                <div class="redeem-error"><?php echo htmlspecialchars($redeem_error); ?></div>
            <?php endif; ?>
            <div class="header">
                <h1>Rewards</h1>
                <div class="search-bar">
                    <input type="text" placeholder="Search functionalities..." id="searchInput">
                    <div class="search-results" id="searchResults"></div>
                </div>
                <div class="profile" id="profileBtn">
                    <span><?php echo htmlspecialchars($username); ?></span>
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
                    <button id="voucherBtn" class="active"><i class="fas fa-ticket-alt"></i> Exchange for Vouchers</button>
                    <button id="cashBtn"><i class="fas fa-wallet"></i> Withdraw as Cash</button>
                </div>
                <!-- Voucher Redemption -->
                <div class="redeem-options active" id="voucherSection">
                    <div class="voucher-grid">
                        <?php foreach ($vouchers as $voucher): ?>
                            <div class="voucher-card" onclick="openVoucherModal(<?php echo $voucher['voucher_id']; ?>, '<?php echo htmlspecialchars(json_encode($voucher), ENT_QUOTES); ?>')">
                                <img src="<?php echo htmlspecialchars($voucher['image']); ?>" alt="<?php echo htmlspecialchars($voucher['name']); ?>">
                                <h4><?php echo htmlspecialchars($voucher['name']); ?></h4>
                                <p><?php echo $voucher['points_cost']; ?> points</p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>" class="<?php echo $i === $current_page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                </div>
                <!-- Cash Withdrawal -->
                <div class="redeem-options" id="cashSection">
                    <div class="redeem-option">
                        <h3><i class="fas fa-wallet"></i> Withdraw as Cash via PayPal</h3>
                        <p>
                            Enter the amount to withdraw in Eco Points (Minimum: 100 points)
                            <span class="info-container">
                                <span class="info-icon">i</span>
                                <span class="info-tooltip">Conversion Rate: 100 Eco Points = 1 USD</span>
                            </span>
                        </p>
                        <p><strong>Current PayPal Email:</strong> <?php echo $paypal_email ? htmlspecialchars($paypal_email) : 'Not set'; ?></p>
                        <form id="withdrawForm" method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="number" name="amount_points" placeholder="Enter points to withdraw (e.g., 123)" min="100" required>
                            <button type="button" onclick="openWithdrawModal(<?php echo $eco_points; ?>, '<?php echo htmlspecialchars($paypal_email); ?>')">Withdraw Cash</button>
                        </form>
                    </div>
                </div>
            </div>
            <!-- Voucher Details Modal -->
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
            <!-- Voucher Confirm Modal -->
            <div class="modal" id="confirmModal">
                <div class="modal-content">
                    <span class="close-btn" onclick="closeModal('confirmModal')">×</span>
                    <h3><i class="fas fa-check-circle"></i> Confirm Redemption</h3>
                    <p>Are you sure you want to redeem <strong id="confirmVoucherName"></strong> for <strong id="confirmVoucherPoints"></strong> Eco Points?</p>
                    <form id="redeemForm" method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="voucher_id" id="confirmVoucherId">
                        <button type="submit" name="redeem_voucher" onclick="showSpinner('redeemSpinner')">Confirm</button>
                        <button type="button" class="secondary" onclick="closeModal('confirmModal')">Cancel</button>
                        <div class="spinner" id="redeemSpinner"></div>
                    </form>
                </div>
            </div>
            <!-- Voucher Success Modal -->
            <?php if ($voucher_redeem_message && isset($_SESSION['redeemed_voucher'])): ?>
                <div class="modal active" id="voucherSuccessModal">
                    <div class="modal-content">
                        <span class="close-btn" onclick="closeModal('voucherSuccessModal')">×</span>
                        <div class="redeem-message"><?php echo htmlspecialchars($voucher_redeem_message); ?></div>
                        <p><strong>Voucher Code:</strong> <?php echo htmlspecialchars($_SESSION['redeemed_voucher']['code']); ?></p>
                        <p><strong>Expires On:</strong> <?php echo htmlspecialchars($_SESSION['redeemed_voucher']['expiry_date']); ?></p>
                        <button onclick="window.location.href='?download_pdf=1'">Download PDF</button>
                        <button class="secondary" onclick="closeModal('voucherSuccessModal')">Close</button>
                    </div>
                </div>
            <?php endif; ?>
            <!-- Withdraw Modal -->
            <div class="modal" id="withdrawModal">
                <div class="modal-content">
                    <span class="close-btn" onclick="closeModal('withdrawModal')">×</span>
                    <h3><i class="fas fa-wallet"></i> Confirm Withdrawal</h3>
                    <p><strong>Amount to Withdraw:</strong> <span id="withdrawAmount"></span> USD</p>
                    <p><strong>Eco Points:</strong> <span id="withdrawPoints"></span> points</p>
                    <p><strong>PayPal Email:</strong> <span id="withdrawEmail"></span></p>
                    <form id="withdrawConfirmForm" method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="amount_points" id="withdrawPointsInput">
                        <button type="submit" name="withdraw_cash" onclick="showSpinner('withdrawSpinner')">Confirm Withdrawal</button>
                        <button type="button" class="secondary" onclick="closeModal('withdrawModal')">Cancel</button>
                        <div class="spinner" id="withdrawSpinner"></div>
                    </form>
                </div>
            </div>
            <!-- Withdrawal Success Modal -->
            <?php if ($withdraw_message && isset($_SESSION['withdrawal_success'])): ?>
                <div class="modal active" id="withdrawSuccessModal">
                    <div class="modal-content">
                        <span class="close-btn" onclick="closeModal('withdrawSuccessModal')">×</span>
                        <div class="withdraw-message"><?php echo htmlspecialchars($withdraw_message); ?></div>
                        <p><strong>Amount:</strong> <?php echo htmlspecialchars(number_format($_SESSION['withdrawal_success']['cash_amount'], 2)); ?> USD</p>
                        <p><strong>Points Deducted:</strong> <?php echo htmlspecialchars($_SESSION['withdrawal_success']['points']); ?> points</p>
                        <p><strong>PayPal Email:</strong> <?php echo htmlspecialchars($_SESSION['withdrawal_success']['paypal_email']); ?></p>
                        <p><strong>Withdrawn At:</strong> <?php echo htmlspecialchars($_SESSION['withdrawal_success']['withdrawn_at']); ?></p>
                        <button class="secondary" onclick="closeModal('withdrawSuccessModal')">Close</button>
                    </div>
                </div>
            <?php endif; ?>
            <!-- PayPal Email Modal -->
            <div class="modal" id="paypalModal">
                <div class="modal-content">
                    <span class="close-btn" onclick="closeModal('paypalModal')">×</span>
                    <h3><i class="fas fa-envelope"></i> Set PayPal Email</h3>
                    <p>Please provide your PayPal email to proceed with the withdrawal.</p>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="email" name="paypal_email" placeholder="Enter your PayPal email" required style="width: 100%; padding: 12px; border: 1px solid #e0e7ff; border-radius: 5px; font-size: 16px; margin-bottom: 15px; outline: none;">
                        <button type="submit" name="update_paypal_email">Save</button>
                        <button type="button" class="secondary" onclick="closeModal('paypalModal')">Cancel</button>
                    </form>
                </div>
            </div>
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

        profileBtn.addEventListener('click', function() {
            profileDropdown.classList.toggle('active');
        });

        document.addEventListener('click', function(e) {
            if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                profileDropdown.classList.remove('active');
            }
        });

        // Reward navigation functionality
        const voucherBtn = document.querySelector('#voucherBtn');
        const cashBtn = document.querySelector('#cashBtn');
        const voucherSection = document.querySelector('#voucherSection');
        const cashSection = document.querySelector('#cashSection');

        voucherBtn.addEventListener('click', function() {
            voucherBtn.classList.add('active');
            cashBtn.classList.remove('active');
            voucherSection.classList.add('active');
            cashSection.classList.remove('active');
        });

        cashBtn.addEventListener('click', function() {
            cashBtn.classList.add('active');
            voucherBtn.classList.remove('active');
            cashSection.classList.add('active');
            voucherSection.classList.remove('active');
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

        // Voucher modal functionality
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

        // Withdraw modal functionality
        function openWithdrawModal(ecoPoints, paypalEmail) {
            const amountPoints = document.querySelector('input[name="amount_points"]').value;
            const minimumPoints = 100; // Minimum withdrawal points
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
            const cashAmount = (amountPoints / 100).toFixed(2);
            document.querySelector('#withdrawAmount').textContent = cashAmount;
            document.querySelector('#withdrawPoints').textContent = amountPoints;
            document.querySelector('#withdrawEmail').textContent = paypalEmail;
            document.querySelector('#withdrawPointsInput').value = amountPoints;
            document.querySelector('#withdrawModal').classList.add('active');
        }

        // Spinner functionality
        function showSpinner(spinnerId) {
            const spinner = document.querySelector(`#${spinnerId}`);
            spinner.style.display = 'block';
        }
    </script>
</body>
</html>