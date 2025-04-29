<?php
    session_start();
    require_once '../includes/config.php';

    // Initialize error message
    $error = '';

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
            $error = 'Invalid CSRF token.';
        } else {
            // Check for too many failed attempts
            if ($_SESSION['login_attempts'] >= 5 && (time() - $_SESSION['last_attempt_time']) < 300) {
                $error = 'Too many failed attempts. Please try again in 5 minutes.';
            } else {
                // Reset attempts if enough time has passed
                if ((time() - $_SESSION['last_attempt_time']) >= 300) {
                    $_SESSION['login_attempts'] = 0;
                }

                // Sanitize and validate inputs
                $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_SPECIAL_CHARS);
                $password = $_POST['password'];

                if (empty($username) || empty($password)) {
                    $error = 'Please fill in all fields.';
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

                            // Redirect based on role
                            if ($user['role'] === 'validator') {
                                header('Location: validate.php');
                            } else {
                                header('Location: dashboard.php');
                            }
                            exit;
                        } else {
                            // Increment failed attempts
                            $_SESSION['login_attempts']++;
                            $_SESSION['last_attempt_time'] = time();
                            $error = 'Invalid username or password.';
                        }
                    } catch (PDOException $e) {
                        $error = 'An error occurred. Please try again later.';
                    }
                }
            }
        }
    }
    ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Green Roots - Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/login.css">
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
                <label for="username">Username</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" id="username" name="username" required>
                </div>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" required>
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