<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Green Roots - Login</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #e0e7ff, #f5f7fa);
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .login-container {
            background: #fff;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }

        .login-container h2 {
            font-size: 28px;
            color: #1e3a8a;
            text-align: center;
            margin-bottom: 20px;
        }

        .login-container .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            display: none;
        }

        .login-container .error.show {
            display: block;
        }

        .login-container .form-group {
            margin-bottom: 20px;
        }

        .login-container label {
            display: block;
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }

        .login-container input[type="text"],
        .login-container input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #e0e7ff;
            border-radius: 5px;
            font-size: 16px;
            outline: none;
            transition: border 0.3s;
        }

        .login-container input[type="text"]:focus,
        .login-container input[type="password"]:focus {
            border-color: #4f46e5;
        }

        .login-container input[type="submit"] {
            background: #4f46e5;
            color: #fff;
            padding: 10px;
            border: none;
            border-radius: 5px;
            width: 100%;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .login-container input[type="submit"]:hover {
            background: #7c3aed;
        }

        .login-container .links {
            text-align: center;
            margin-top: 20px;
        }

        .login-container .links a {
            color: #4f46e5;
            text-decoration: none;
            font-size: 14px;
        }

        .login-container .links a:hover {
            text-decoration: underline;
        }

        /* Mobile Responsive Design */
        @media (max-width: 768px) {
            .login-container {
                padding: 20px;
                max-width: 90%;
            }

            .login-container h2 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
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
                $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
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

    <div class="login-container">
        <h2>Login</h2>
        <div class="error <?php echo $error ? 'show' : ''; ?>">
            <?php echo htmlspecialchars($error); ?>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <input type="submit" value="Login">
        </form>
        <div class="links">
            <p>Don't have an account? <a href="register.php">Register here</a></p>
            <p><a href="../index.php">Back to Home</a></p>
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