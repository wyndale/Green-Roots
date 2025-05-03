<?php
    session_start();
    require_once '../includes/config.php';

    // Initialize messages
    $error = '';
    $success = '';

    // Check for session-stored messages
    if (isset($_SESSION['register_error'])) {
        $error = $_SESSION['register_error'];
        unset($_SESSION['register_error']);
    }
    if (isset($_SESSION['register_success'])) {
        $success = $_SESSION['register_success'];
        unset($_SESSION['register_success']);
    }

    // Generate CSRF token if not set
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validate CSRF token
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $_SESSION['register_error'] = 'Invalid CSRF token.';
        } else {
            // Sanitize and validate inputs
            $first_name = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $last_name = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
            $password = $_POST['password'];
            $confirm_password = $_POST['confirm_password'];
            $barangay_id = filter_input(INPUT_POST, 'barangay_id', FILTER_SANITIZE_NUMBER_INT);

            // Basic validation
            if (empty($first_name) || empty($last_name) || empty($username) || empty($email) || empty($password) || empty($confirm_password) || empty($barangay_id)) {
                $_SESSION['register_error'] = 'Please fill in all fields.';
            } elseif (strlen($first_name) < 2) {
                $_SESSION['register_error'] = 'First name must be at least 2 characters long.';
            } elseif (strlen($last_name) < 2) {
                $_SESSION['register_error'] = 'Last name must be at least 2 characters long.';
            } elseif (strlen($username) < 3) {
                $_SESSION['register_error'] = 'Username must be at least 3 characters long.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['register_error'] = 'Invalid email format.';
            } elseif (strlen($password) < 8) {
                $_SESSION['register_error'] = 'Password must be at least 8 characters long.';
            } elseif (!preg_match('/[A-Z]/', $password)) {
                $_SESSION['register_error'] = 'Password must contain at least one capital letter.';
            } elseif (!preg_match('/[0-9]/', $password)) {
                $_SESSION['register_error'] = 'Password must contain at least one number.';
            } elseif (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
                $_SESSION['register_error'] = 'Password must contain at least one special character.';
            } elseif ($password !== $confirm_password) {
                $_SESSION['register_error'] = 'Passwords do not match.';
            } else {
                try {
                    // Check if username already exists
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
                    $stmt->execute(['username' => $username]);
                    if ($stmt->fetchColumn() > 0) {
                        $_SESSION['register_error'] = 'Username already exists.';
                    } else {
                        // Check if email already exists
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
                        $stmt->execute(['email' => $email]);
                        if ($stmt->fetchColumn() > 0) {
                            $_SESSION['register_error'] = 'Email already exists.';
                        } else {
                            // Hash the password
                            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                            // Insert user into database with default role 'user'
                            $stmt = $pdo->prepare("
                                INSERT INTO users (first_name, last_name, username, password, email, role, barangay_id)
                                VALUES (:first_name, :last_name, :username, :password, :email, 'user', :barangay_id)
                            ");
                            $stmt->execute([
                                'first_name' => $first_name,
                                'last_name' => $last_name,
                                'username' => $username,
                                'password' => $hashed_password,
                                'email' => $email,
                                'barangay_id' => $barangay_id
                            ]);

                            // Get the newly inserted user_id
                            $user_id = $pdo->lastInsertId();

                            // Fetch the default profile picture asset_id
                            $stmt = $pdo->prepare("SELECT asset_id FROM assets WHERE asset_type = 'default_profile' LIMIT 1");
                            $stmt->execute();
                            $default_profile_asset_id = $stmt->fetchColumn();

                            if ($default_profile_asset_id) {
                                // Update the newly created user with the default profile picture asset_id
                                $stmt = $pdo->prepare("UPDATE users SET default_profile_asset_id = :asset_id WHERE user_id = :user_id");
                                $stmt->execute([
                                    'asset_id' => $default_profile_asset_id,
                                    'user_id' => $user_id
                                ]);
                            } else {
                                // Log an error or notify admin that default profile picture is missing
                                error_log("Default profile picture not found in assets table during registration for user_id: $user_id");
                            }

                            $_SESSION['register_success'] = 'Registration successful! You can now <a href="login.php">login</a>.';
                        }
                    }
                } catch (PDOException $e) {
                    $_SESSION['register_error'] = 'An error occurred. Please try again later.';
                }
            }
        }

        // Redirect to prevent form resubmission
        header('Location: register.php');
        exit;
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Green Roots - Register</title>
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
            padding: 16px;
        }

        .register-container {
            background: #fff;
            padding: 36px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 500px;
            text-align: center;
            position: relative;
            overflow: hidden;
            opacity: 0;
            animation: fadeIn 1s ease-in-out forwards;
        }

        .register-container::before {
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

        .register-container h2 {
            font-size: 25px;
            color: #4CAF50;
            margin-bottom: 6px;
            position: relative;
            z-index: 1;
        }

        .register-container .subtitle {
            font-size: 14px;
            color: #666;
            margin-bottom: 26px;
            position: relative;
            z-index: 1;
        }

        .register-container .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 6px;
            border-radius: 5px;
            margin-bottom: 21px;
            text-align: center;
            display: none;
            position: relative;
            z-index: 1;
            animation: fadeIn 0.5s ease-in-out;
        }

        .register-container .success {
            background: #d1fae5;
            color: #10b981;
            padding: 6px;
            border-radius: 5px;
            margin-bottom: 21px;
            text-align: center;
            display: none;
            position: relative;
            z-index: 1;
            animation: fadeIn 0.5s ease-in-out;
        }

        .register-container .error.show,
        .register-container .success.show {
            display: block;
        }

        .register-container .form-group {
            margin-bottom: 26px;
            position: relative;
            text-align: left;
        }

        .register-container .name-group {
            display: flex;
            gap: 10px;
            margin-bottom: 17px;
        }

        .register-container .name-group .form-group {
            flex: 1;
            margin-bottom: 0;
        }

        .register-container .location-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 26px;
        }

        .register-container .input-wrapper {
            position: relative;
        }

        .register-container .input-wrapper i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 12px;
            z-index: 1;
        }

        .register-container input[type="text"],
        .register-container input[type="email"],
        .register-container input[type="password"],
        .register-container select {
            width: 100%;
            padding: 7px 7px 7px 31px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 12px;
            outline: none;
            background: transparent;
            transition: border-color 0.3s, box-shadow 0.3s;
            position: relative;
            z-index: 0;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            opacity: 0;
            animation: slideUp 0.5s ease-in-out forwards;
            animation-delay: 0.2s;
        }

        .register-container .input-wrapper label {
            position: absolute;
            left: 31px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 13px;
            color: #666;
            letter-spacing: 0.4px;
            background: #fff;
            padding: 0 5px;
            pointer-events: none;
            transition: all 0.2s ease;
            z-index: 1;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .register-container .input-wrapper input:focus,
        .register-container .input-wrapper input:not(:placeholder-shown),
        .register-container .input-wrapper select:focus,
        .register-container .input-wrapper select:valid {
            border-color: #4CAF50;
            box-shadow: 0 0 5px rgba(76, 175, 80, 0.2);
        }

        .register-container .input-wrapper input:focus + label,
        .register-container .input-wrapper input:not(:placeholder-shown) + label,
        .register-container .input-wrapper select:focus + label,
        .register-container .input-wrapper select:valid + label {
            top: 0;
            transform: translateY(-50%);
            font-size: 11px;
            color: #4CAF50;
        }

        .register-container input[type="submit"] {
            background: #4CAF50;
            color: #fff;
            padding: 10px;
            border: none;
            border-radius: 21px;
            width: 70%;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.3s, box-shadow 0.3s, background 0.3s;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            position: relative;
            z-index: 1;
            animation: bounceIn 0.5s ease-out;
        }

        @keyframes bounceIn {
            0% { transform: scale(0.9); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .register-container input[type="submit"]:hover {
            background: #388E3C;
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 4px 10px rgba(76, 175, 80, 0.4);
            animation: bounce 0.3s ease-out;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(-2px) scale(1.05); }
            50% { transform: translateY(-5px) scale(1.1); }
        }

        .register-container input[type="submit"]:active {
            transform: translateY(0) scale(0.95);
        }

        .register-container .links {
            text-align: center;
            margin-top: 20px;
            position: relative;
            z-index: 1;
        }

        .register-container .links p {
            font-size: 12px;
            color: #666;
            margin-bottom: 6px;
        }

        .register-container .links a {
            color: #4CAF50;
            text-decoration: none;
            font-weight: bold;
            transition: color 0.3s;
        }

        .register-container .links a:hover {
            color: #388E3C    }

        .register-container .success a {
            color: #030a03;
            text-decoration: none;
            font-weight: bold;
            transition: color 0.3s;
        }

        .register-container .success a:hover {
            color: #388E3C;
        }

        /* Mobile Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 11px;
            }

            .register-container {
                padding: 21px;
                max-width: 90%;
            }

            .register-container h2 {
                font-size: 22px;
                margin-bottom: 4px;
            }

            .register-container .subtitle {
                font-size: 10px;
                margin-bottom: 21px;
            }

            .register-container .error,
            .register-container .success {
                margin-bottom: 16px;
                font-size: 9px;
                padding: 4px;
            }

            .register-container .form-group,
            .register-container .name-group {
                margin-bottom: 21px;
            }

            .register-container .name-group {
                flex-direction: column;
                gap: 21px;
            }

            .register-container .location-group {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .register-container .input-wrapper label {
                font-size: 10px;
                left: 26px;
            }

            .register-container input[type="text"],
            .register-container input[type="email"],
            .register-container input[type="password"],
            .register-container select {
                font-size: 10px;
                padding: 4px 4px 4px 26px;
            }

            .register-container .input-wrapper i {
                font-size: 10px;
                left: 4px;
            }

            .register-container .input-wrapper input:focus + label,
            .register-container .input-wrapper input:not(:placeholder-shown) + label,
            .register-container .input-wrapper select:focus + label,
            .register-container .input-wrapper select:valid + label {
                font-size: 8px;
            }

            .register-container input[type="submit"] {
                font-size: 10px;
                padding: 6px;
            }

            .register-container .links p,
            .register-container .links a {
                font-size: 9px;
            }
        }

        @media (max-width: 480px) {
            .register-container {
                padding: 16px;
            }

            .register-container h2 {
                font-size: 18px;
            }

            .register-container .subtitle {
                font-size: 9px;
            }

            .register-container .error,
            .register-container .success {
                font-size: 8px;
            }

            .register-container .input-wrapper label {
                font-size: 9px;
                left: 22px;
            }

            .register-container input[type="text"],
            .register-container input[type="email"],
            .register-container input[type="password"],
            .register-container select {
                font-size: 9px;
                padding: 3px 3px 3px 22px;
            }

            .register-container .input-wrapper i {
                font-size: 9px;
            }

            .register-container .input-wrapper input:focus + label,
            .register-container .input-wrapper input:not(:placeholder-shown) + label,
            .register-container .input-wrapper select:focus + label,
            .register-container .input-wrapper select:valid + label {
                font-size: 7px;
            }

            .register-container input[type="submit"] {
                font-size: 9px;
                padding: 4px;
            }

            .register-container .links p,
            .register-container .links a {
                font-size: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <h2>Join Green Roots!</h2>
        <p class="subtitle">Create an account to start planting for a better future.</p>
        <div class="error <?php echo $error ? 'show' : ''; ?>">
            <?php echo htmlspecialchars($error); ?>
        </div>
        <div class="success <?php echo $success ? 'show' : ''; ?>">
            <?php echo $success; ?>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="name-group">
                <div class="form-group">
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="first_name" name="first_name" placeholder=" " required>
                        <label for="first_name">Enter your first name</label>
                    </div>
                </div>
                <div class="form-group">
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="last_name" name="last_name" placeholder=" " required>
                        <label for="last_name">Enter your last name</label>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" id="username" name="username" placeholder=" " required>
                    <label for="username">Enter your username</label>
                </div>
            </div>
            <div class="form-group">
                <div class="input-wrapper">
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="email" name="email" placeholder=" " required>
                    <label for="email">Enter your email</label>
                </div>
            </div>
            <div class="form-group">
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" placeholder=" " required>
                    <label for="password">Enter your password</label>
                </div>
            </div>
            <div class="form-group">
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder=" " required>
                    <label for="confirm_password">Confirm your password</label>
                </div>
            </div>
            <div class="form-group location-group">
                <div class="input-wrapper">
                    <i class="fas fa-map-marker-alt"></i>
                    <select id="region" name="region" required>
                        <option value="" disabled selected hidden></option>
                    </select>
                    <label for="region">Select your region</label>
                </div>
                <div class="input-wrapper">
                    <i class="fas fa-map-marker-alt"></i>
                    <select id="province" name="province" required>
                        <option value="" disabled selected hidden></option>
                    </select>
                    <label for="province">Select your province</label>
                </div>
                <div class="input-wrapper">
                    <i class="fas fa-map-marker-alt"></i>
                    <select id="city" name="city" required>
                        <option value="" disabled selected hidden></option>
                    </select>
                    <label for="city">Select your city</label>
                </div>
                <div class="input-wrapper">
                    <i class="fas fa-map-marker-alt"></i>
                    <select id="barangay" name="barangay" required>
                        <option value="" disabled selected hidden></option>
                    </select>
                    <label for="barangay">Select your barangay</label>
                </div>
                <input type="hidden" id="barangay_id" name="barangay_id" required>
            </div>
            <input type="submit" value="Register">
        </form>
        <div class="links">
            <p>Already have an account? <a href="login.php">Login here</a></p>
        </div>
    </div>

    <script>
        const regionSelect = document.querySelector('#region');
        const provinceSelect = document.querySelector('#province');
        const citySelect = document.querySelector('#city');
        const barangaySelect = document.querySelector('#barangay');
        const barangayIdInput = document.querySelector('#barangay_id');

        // Function to fetch data via AJAX
        async function fetchOptions(level, parent = '', parentType = 'province') {
            try {
                const response = await fetch(`../services/get_locations.php?level=${level}&parent=${encodeURIComponent(parent)}&parentType=${parentType}`);
                const data = await response.json();
                if (data.error) {
                    throw new Error(data.error);
                }
                return data;
            } catch (error) {
                console.error('Error fetching data:', error);
                document.querySelector('.error').textContent = 'Failed to load locations. Please try again.';
                document.querySelector('.error').classList.add('show');
                return [];
            }
        }

        // Function to populate a select element
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

        // Load regions on page load
        async function loadRegions() {
            const regions = await fetchOptions('regions');
            populateSelect(regionSelect, regions);
        }

        // Handle region change
        regionSelect.addEventListener('change', async () => {
            const region = regionSelect.value;
            provinceSelect.disabled = false;
            citySelect.disabled = true;
            barangaySelect.disabled = true;
            barangayIdInput.value = '';

            // Reset subsequent selects
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

        // Handle province change
        provinceSelect.addEventListener('change', async () => {
            const province = provinceSelect.value;
            citySelect.disabled = false;
            barangaySelect.disabled = true;
            barangayIdInput.value = '';

            // Reset subsequent selects
            citySelect.innerHTML = '<option value="" disabled selected hidden></option>';
            barangaySelect.innerHTML = '<option value="" disabled selected hidden></option>';

            const cities = await fetchOptions('cities', province);
            populateSelect(citySelect, cities);
        });

        // Handle city change
        citySelect.addEventListener('change', async () => {
            const city = citySelect.value;
            barangaySelect.disabled = false;
            barangayIdInput.value = '';

            // Reset barangay select
            barangaySelect.innerHTML = '<option value="" disabled selected hidden></option>';

            const barangays = await fetchOptions('barangays', city);
            populateSelect(barangaySelect, barangays, true);
        });

        // Handle barangay change
        barangaySelect.addEventListener('change', () => {
            barangayIdInput.value = barangaySelect.value;
        });

        // Initial load
        loadRegions();

        // Client-side validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const firstName = document.querySelector('#first_name').value;
            const lastName = document.querySelector('#last_name').value;
            const username = document.querySelector('#username').value;
            const email = document.querySelector('#email').value;
            const password = document.querySelector('#password').value;
            const confirmPassword = document.querySelector('#confirm_password').value;
            const barangayId = document.querySelector('#barangay_id').value;

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            if (!firstName || !lastName || !username || !email || !password || !confirmPassword || !barangayId) {
                e.preventDefault();
                document.querySelector('.error').textContent = 'Please fill in all fields.';
                document.querySelector('.error').classList.add('show');
            } else if (firstName.length < 2) {
                e.preventDefault();
                document.querySelector('.error').textContent = 'First name must be at least 2 characters long.';
                document.querySelector('.error').classList.add('show');
            } else if (lastName.length < 2) {
                e.preventDefault();
                document.querySelector('.error').textContent = 'Last name must be at least 2 characters long.';
                document.querySelector('.error').classList.add('show');
            } else if (!emailRegex.test(email)) {
                e.preventDefault();
                document.querySelector('.error').textContent = 'Invalid email format.';
                document.querySelector('.error').classList.add('show');
            } else if (password.length < 8) {
                e.preventDefault();
                document.querySelector('.error').textContent = 'Password must be at least 8 characters long.';
                document.querySelector('.error').classList.add('show');
            } else if (password !== confirmPassword) {
                e.preventDefault();
                document.querySelector('.error').textContent = 'Passwords do not match.';
                document.querySelector('.error').classList.add('show');
            } else if (isNaN(barangayId)) {
                e.preventDefault();
                document.querySelector('.error').textContent = 'Please select a valid barangay.';
                document.querySelector('.error').classList.add('show');
            }
        });
    </script>
</body>
</html>