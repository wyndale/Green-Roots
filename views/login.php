<?php
    session_start();
    require_once '../includes/config.php';

    // Initialize error message
    $error = '';

    // Check for session-stored error message or timeout
    if (isset($_SESSION['login_error'])) {
        $error = $_SESSION['login_error'];
        unset($_SESSION['login_error']);
    } elseif (isset($_GET['timeout']) && $_GET['timeout'] == 1) {
        $error = 'Your session has timed out. Please log in again.';
    }

    // Brute-force protection: Track failed attempts
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['last_attempt_time'] = time();
    }

    // Generate CSRF token if not set
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validate CSRF token
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $_SESSION['login_error'] = 'Invalid CSRF token.';
        } else {
            // Check for too many failed attempts
            if ($_SESSION['login_attempts'] >= 5 && (time() - $_SESSION['last_attempt_time']) < 300) {
                $_SESSION['login_error'] = 'Too many failed attempts. Please try again in 5 minutes.';
            } else {
                // Reset attempts if enough time has passed
                if ((time() - $_SESSION['last_attempt_time']) >= 300) {
                    $_SESSION['login_attempts'] = 0;
                }

                // Sanitize and validate inputs
                $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_SPECIAL_CHARS);
                $password = $_POST['password'];

                if (empty($username) || empty($password)) {
                    $_SESSION['login_error'] = 'Please fill in all fields.';
                } else {
                    try {
                        // Fetch user from database
                        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
                        $stmt->execute(['username' => $username]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($user && password_verify($password, $user['password'])) {
                            // Reset login attempts on successful login
                            $_SESSION['login_attempts'] = 0;
                            $_SESSION['last_attempt_time'] = time();

                            // Regenerate session ID to prevent fixation
                            session_regenerate_id(true);

                            // Set session variables
                            $_SESSION['user_id'] = $user['user_id'];
                            $_SESSION['username'] = $user['username'];
                            $_SESSION['role'] = $user['role'];
                            $_SESSION['last_activity'] = time(); // Set last activity time

                            // Redirect based on role
                            if ($user['role'] === 'eco_validator') {
                                header('Location: ../validator/validator_dashboard.php');
                            } elseif ($user['role'] === 'admin') {
                                header('Location: ../admin/admin_dashboard.php');
                            } else {
                                header('Location: dashboard.php');
                            }
                            exit;
                        } else {
                            // Increment failed attempts
                            $_SESSION['login_attempts']++;
                            $_SESSION['last_attempt_time'] = time();
                            $_SESSION['login_error'] = 'Invalid username or password.';
                        }
                    } catch (PDOException $e) {
                        $_SESSION['login_error'] = 'An error occurred. Please try again later.';
                    }
                }
            }
        }

        // Redirect to prevent form resubmission
        header('Location: login.php');
        exit;
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Green Roots - Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #E8F5E9 0%, #C8E6C9 100%);
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
            animation: gradientAnimation 15s ease infinite;
            background-size: 200% 200%;
        }

        @keyframes gradientAnimation {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .login-container {
            background: #fff;
            padding: 50px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            text-align: center;
            position: relative;
            overflow: hidden;
            opacity: 0;
            animation: fadeIn 1s ease-in-out forwards;
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(76, 175, 80, 0.1) 0%, transparent 70%);
            transform: rotate(45deg);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-container h2 {
            font-size: 28px;
            color: #4CAF50;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .login-container .subtitle {
            font-size: 15px;
            color: #5a5959;
            margin-bottom: 30px;
            position: relative;
            z-index: 1;
        }

        .login-container .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 25px;
            text-align: center;
            display: none;
            position: relative;
            z-index: 1;
            animation: fadeIn 0.5s ease-in-out;
        }

        .login-container .error.show {
            display: block;
        }

        .login-container .form-group {
            margin-bottom: 30px;
            position: relative;
            text-align: left;
        }

        .login-container .input-wrapper {
            position: relative;
        }

        .login-container .input-wrapper i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 16px;
            z-index: 1;
        }

        .login-container .input-wrapper input {
            width: 100%;
            padding: 10px 10px 10px 35px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 14px;
            outline: none;
            background: transparent;
            transition: border-color 0.3s, box-shadow 0.3s;
            position: relative;
            z-index: 0;
            opacity: 0;
            animation: slideUp 0.5s ease-in-out forwards;
        }

        .login-container .input-wrapper input:nth-child(1) {
            animation-delay: 0.2s;
        }

        .login-container .input-wrapper input:nth-child(2) {
            animation-delay: 0.4s;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-container .input-wrapper label {
            position: absolute;
            left: 35px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 14px;
            color: #666;
            letter-spacing: 0.4px;
            padding: 0 5px;
            pointer-events: none;
            transition: all 0.2s ease;
            z-index: 1;
        }

        .login-container .input-wrapper input:focus,
        .login-container .input-wrapper input:not(:placeholder-shown) {
            border-color: #4CAF50;
            box-shadow: 0 0 5px rgba(76, 175, 80, 0.2);
        }

        .login-container .input-wrapper input:focus + label,
        .login-container .input-wrapper input:not(:placeholder-shown) + label {
            top: 0;
            transform: translateY(-50%);
            font-size: 12px;
            color: #4CAF50;
            background: #fff;
        }

        .login-container input[type="submit"] {
            background: #4CAF50;
            color: #fff;
            padding: 12px;
            margin-top: 12px;
            border: none;
            border-radius: 25px;
            width: 100%;
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 0.4px;
            cursor: pointer;
            transition: transform 0.3s, box-shadow 0.3s, background 0.3s;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            animation: bounceIn 0.5s ease-out;
            position: relative;
            z-index: 1;
        }

        @keyframes bounceIn {
            0% { transform: scale(0.9); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .login-container input[type="submit"]:hover {
            background: #388E3C;
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 5px 15px rgba(76, 175, 80, 0.4);
            animation: bounce 0.3s ease-out;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(-2px) scale(1.05); }
            50% { transform: translateY(-5px) scale(1.1); }
        }

        .login-container input[type="submit"]:active {
            transform: translateY(0) scale(0.95);
        }

        .login-container .forgot-password {
            color: #2E7D32;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            position: absolute;
            right: 0;
            top: calc(100% + 10px);
            transition: color 0.3s, transform 0.3s, box-shadow 0.3s;
            z-index: 1;
        }

        .login-container .forgot-password:hover {
            color: #1B5E20;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(76, 175, 80, 0.4);
        }

        .login-container .separator {
            margin: 25px 0;
            position: relative;
            text-align: center;
            color: #666;
            font-size: 14px;
            z-index: 1;
        }

        .login-container .separator::before,
        .login-container .separator::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 40%;
            height: 1px;
            background: #e0e7ff;
        }

        .login-container .separator::before {
            left: 0;
        }

        .login-container .separator::after {
            right: 0;
        }

        .login-container .third-party-login {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 30px;
            position: relative;
            z-index: 1;
        }

        .login-container .third-party-login a {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            text-decoration: none;
            transition: transform 0.3s, box-shadow 0.3s, background 0.3s;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .login-container .third-party-login a.google {
            background: rgb(9, 95, 12);
            color: #fff;
            border: 1px solid #dadce0;
        }

        .login-container .third-party-login a.google:hover {
            background: rgb(56, 152, 59);
            transform: scale(1.1);
            box-shadow: 0 4px 10px rgba(219, 68, 55, 0.3);
        }

        .login-container .third-party-login a.facebook {
            background: rgb(9, 95, 12);
            color: #fff;
        }

        .login-container .third-party-login a.facebook:hover {
            background: rgb(56, 152, 59);
            transform: scale(1.1);
            box-shadow: 0 4px 10px rgba(66, 103, 178, 0.3);
        }

        .login-container .third-party-login a i {
            font-size: 18px;
        }

        .login-container .links {
            text-align: center;
            margin-top: 15px;
            position: relative;
            z-index: 1;
        }

        .login-container .links p {
            font-size: 14px;
            color: #666;
        }

        .login-container .links a {
            color: #4CAF50;
            text-decoration: none;
            font-weight: bold;
            transition: color 0.3s, box-shadow 0.3s;
        }

        .login-container .links a:hover {
            color: #388E3C;
            box-shadow: 0 4px 10px rgba(76, 175, 80, 0.4);
        }

        /* Mobile Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 15px;
            }

            .login-container {
                padding: 25px;
                max-width: 90%;
            }

            .login-container h2 {
                font-size: 26px;
                margin-bottom: 8px;
            }

            .login-container .subtitle {
                font-size: 13px;
                margin-bottom: 25px;
            }

            .login-container .error {
                margin-bottom: 20px;
                font-size: 13px;
                padding: 8px;
            }

            .login-container .form-group {
                margin-bottom: 25px;
            }

            .login-container .input-wrapper label {
                font-size: 12px;
                left: 30px;
            }

            .login-container .input-wrapper input {
                font-size: 14px;
                padding: 8px 8px 8px 30px;
            }

            .login-container .input-wrapper i {
                font-size: 14px;
                left: 8px;
            }

            .login-container .input-wrapper input:focus + label,
            .login-container .input-wrapper input:not(:placeholder-shown) + label {
                font-size: 10px;
            }

            .login-container input[type="submit"] {
                font-size: 14px;
                padding: 10px;
            }

            .login-container .forgot-password {
                font-size: 13px;
                top: calc(100% + 8px);
            }

            .login-container .separator {
                margin: 20px 0;
                font-size: 13px;
            }

            .login-container .third-party-login {
                gap: 15px;
                margin-bottom: 25px;
            }

            .login-container .third-party-login a {
                width: 35px;
                height: 35px;
            }

            .login-container .third-party-login a i {
                font-size: 16px;
            }

            .login-container .links p,
            .login-container .links a {
                font-size: 13px;
            }
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 20px;
            }

            .login-container h2 {
                font-size: 22px;
            }

            .login-container .subtitle {
                font-size: 13px;
            }

            .login-container .error {
                font-size: 12px;
            }

            .login-container .input-wrapper label {
                font-size: 11px;
                left: 28px;
            }

            .login-container .input-wrapper input {
                font-size: 13px;
                padding: 7px 7px 7px 28px;
            }

            .login-container .input-wrapper i {
                font-size: 13px;
            }

            .login-container .input-wrapper input:focus + label,
            .login-container .input-wrapper input:not(:placeholder-shown) + label {
                font-size: 9px;
            }

            .login-container input[type="submit"] {
                font-size: 13px;
                padding: 8px;
            }

            .login-container .forgot-password {
                font-size: 12px;
            }

            .login-container .separator {
                font-size: 12px;
            }

            .login-container .third-party-login a {
                width: 32px;
                height: 32px;
            }

            .login-container .third-party-login a i {
                font-size: 14px;
            }

            .login-container .links p,
            .login-container .links a {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Welcome Back!</h2>
        <p class="subtitle">Join the Green Roots community and plant for a better future.</p>
        <div class="error <?php echo $error ? 'show' : ''; ?>">
            <?php echo htmlspecialchars($error); ?>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="form-group">
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" id="username" name="username" placeholder=" " required>
                    <label for="username">Enter your username</label>
                </div>
            </div>
            <div class="form-group">
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" placeholder=" " required>
                    <label for="password">Enter your password</label>
                </div>
                <a href="#" title="Forgot Password functionality will be implemented later" class="forgot-password">Forgot Password?</a>
            </div>
            <input type="submit" value="Login">
            <div class="separator">or continue with</div>
            <div class="third-party-login">
                <a href="#" class="google" title="Google login will be implemented later">
                    <i class="fab fa-google"></i>
                </a>
                <a href="#" class="facebook" title="Facebook login will be implemented later">
                    <i class="fab fa-facebook-f"></i>
                </a>
            </div>
        </form>
        <div class="links">
            <p>Not a member? <a href="register.php">Register now</a></p>
        </div>
    </div>

    <script>
        // Basic client-side validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const username = document.querySelector('#username').value;
            const password = document.querySelector('#password').value;

            if (!username || !password) {
                e.preventDefault();
                document.querySelector('.error').textContent = 'Please fill in all fields.';
                document.querySelector('.error').classList.add('show');
            }
        });
    </script>
</body>
</html>