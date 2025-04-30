<?php
    session_start();
    require_once '../includes/config.php';

    // Initialize messages
    $error = '';
    $success = '';

    // Generate CSRF token if not set
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validate CSRF token
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $error = 'Invalid CSRF token.';
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
                $error = 'Please fill in all fields.';
            } elseif (strlen($first_name) < 2) {
                $error = 'First name must be at least 2 characters long.';
            } elseif (strlen($last_name) < 2) {
                $error = 'Last name must be at least 2 characters long.';
            } elseif (strlen($username) < 3) {
                $error = 'Username must be at least 3 characters long.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email format.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters long.';
            } elseif (!preg_match('/[A-Z]/', $password)) {
                $error = 'Password must contain at least one capital letter.';
            } elseif (!preg_match('/[0-9]/', $password)) {
                $error = 'Password must contain at least one number.';
            } elseif (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
                $error = 'Password must contain at least one special character.';
            } elseif ($password !== $confirm_password) {
                $error = 'Passwords do not match.';
            } else {
                try {
                    // Check if username already exists
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
                    $stmt->execute(['username' => $username]);
                    if ($stmt->fetchColumn() > 0) {
                        $error = 'Username already exists.';
                    } else {
                        // Check if email already exists
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
                        $stmt->execute(['email' => $email]);
                        if ($stmt->fetchColumn() > 0) {
                            $error = 'Email already exists.';
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
                                // Optionally, you can set a default value or take other actions
                            }

                            $success = 'Registration successful! You can now <a href="login.php">login</a>.';
                        }
                    }
                } catch (PDOException $e) {
                    $error = 'An error occurred. Please try again later.';
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
            background: #E8F5E9; /* Matching login.php background */
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 16px; /* Reduced from 20px by 4px */
        }

        .register-container {
            background: #fff;
            padding: 36px; /* Reduced from 40px by 4px */
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 500px; /* Increased from 400px to 500px */
            text-align: center;
        }

        .register-container h2 {
            font-size: 25px; /* Reduced from 32px by 4px */
            color: #4CAF50;
            margin-bottom: 6px; /* Reduced from 10px by 4px */
        }

        .register-container .subtitle {
            font-size: 14px; /* Reduced from 16px by 4px */
            color: #666;
            margin-bottom: 26px; /* Reduced from 30px by 4px */
        }

        .register-container .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 6px; /* Reduced from 10px by 4px */
            border-radius: 5px;
            margin-bottom: 21px; /* Reduced from 25px by 4px */
            text-align: center;
            display: none;
        }

        .register-container .success {
            background: #d1fae5;
            color: #10b981;
            padding: 6px; /* Reduced from 10px by 4px */
            border-radius: 5px;
            margin-bottom: 21px; /* Reduced from 25px by 4px */
            text-align: center;
            display: none;
        }

        .register-container .error.show,
        .register-container .success.show {
            display: block;
        }

        .register-container .form-group {
            margin-bottom: 26px; /* Reduced from 30px by 4px */
            position: relative;
            text-align: left;
        }

        .register-container .name-group {
            display: flex;
            gap: 10px;
            margin-bottom: 17px; /* Reduced from 30px by 4px */
        }

        .register-container .name-group .form-group {
            flex: 1;
            margin-bottom: 0;
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
            font-size: 12px; /* Reduced from 16px by 4px */
            z-index: 1; /* Ensure icon stays above the input background */
        }

        .register-container input[type="text"],
        .register-container input[type="email"],
        .register-container input[type="password"] {
            width: 100%;
            padding: 7px 7px 7px 31px; /* Reduced from 10px to 6px, adjusted for icon */
            border: 1px solid #dadce0; /* Google's border color */
            border-radius: 4px; /* Slightly rounded corners like Google */
            font-size: 13px; /* Reduced from 16px by 4px */
            outline: none;
            background: transparent;
            transition: border-color 0.3s;
            position: relative;
            z-index: 0; /* Ensure input stays below the label */
        }

        .register-container .input-wrapper label {
            position: absolute;
            left: 31px; /* Adjusted for icon */
            top: 50%;
            transform: translateY(-50%);
            font-size: 13px; /* Reduced from 14px by 4px */
            color: #666;
            letter-spacing: 0.4px;
            background: #fff; /* Background to cover the border when label moves up */
            padding: 0 5px;
            pointer-events: none; /* Prevent label from interfering with input */
            transition: all 0.2s ease;
            z-index: 1; /* Ensure label stays above the input */
        }

        .register-container .input-wrapper input:focus,
        .register-container .input-wrapper input:not(:placeholder-shown) {
            border-color: #4CAF50; /* Matching focus color */
        }

        .register-container .input-wrapper input:focus + label,
        .register-container .input-wrapper input:not(:placeholder-shown) + label {
            top: 0;
            transform: translateY(-50%);
            font-size: 11px; /* Reduced from 13px by 2px */
            color: #4CAF50; /* Matching focus color for label */
        }

        .register-container select {
            width: 100%;
            padding: 6px; /* Reduced from 10px by 4px */
            border: 1px solid #dadce0; /* Google's border color */
            border-radius: 4px; /* Slightly rounded corners like Google */
            font-size: 13px; /* Reduced from 16px by 4px */
            outline: none;
            background: transparent;
            appearance: none;
            background: url('data:image/svg+xml;utf8,<svg fill="%23999" height="20" viewBox="0 0 24 24" width="20" xmlns="http://www.w3.org/2000/svg"><path d="M7 10l5 5 5-5z"/></svg>') no-repeat right 6px center; /* Adjusted size and position */
            background-size: 12px; /* Reduced from 16px by 4px */
            transition: border-color 0.3s;
        }

        .register-container select:focus {
            border-color: #4CAF50; /* Matching focus color */
        }

        .register-container input[type="submit"] {
            background: #4CAF50;
            color: #fff;
            padding: 10px; /* Reduced from 12px by 4px */
            border: none;
            border-radius: 21px; /* Reduced from 25px by 4px */
            width: 70%;
            font-size: 14px; /* Reduced from 16px by 4px */
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.3s, box-shadow 0.3s, background 0.3s;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .register-container input[type="submit"]:hover {
            background: #388E3C;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(76, 175, 80, 0.4);
        }

        .register-container .links {
            text-align: center;
            margin-top: 20px; /* Reduced from 15px by 4px */
        }

        .register-container .links p {
            font-size: 12px; /* Reduced from 14px by 4px */
            color: #666;
            margin-bottom: 6px; /* Reduced from 10px by 4px */
        }

        .register-container .links a {
            color: #4CAF50;
            text-decoration: none;
            font-weight: bold;
            transition: color 0.3s;
        }

        .register-container .links a:hover {
            color: #388E3C;
        }

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
                padding: 11px; /* Reduced from 15px by 4px */
            }

            .register-container {
                padding: 21px; /* Reduced from 25px by 4px */
                max-width: 90%;
            }

            .register-container h2 {
                font-size: 22px; /* Reduced from 26px by 4px */
                margin-bottom: 4px; /* Reduced from 8px by 4px */
            }

            .register-container .subtitle {
                font-size: 10px; /* Reduced from 14px by 4px */
                margin-bottom: 21px; /* Reduced from 25px by 4px */
            }

            .register-container .error,
            .register-container .success {
                margin-bottom: 16px; /* Reduced from 20px by 4px */
                font-size: 9px; /* Reduced from 13px by 4px */
                padding: 4px; /* Reduced from 8px by 4px */
            }

            .register-container .form-group,
            .register-container .name-group {
                margin-bottom: 21px; /* Reduced from 25px by 4px */
            }

            .register-container .name-group {
                flex-direction: column;
                gap: 21px; /* Reduced from 25px by 4px */
            }

            .register-container .input-wrapper label {
                font-size: 10px; /* Reduced from 13px by 4px */
                left: 26px; /* Adjusted for icon */
            }

            .register-container input[type="text"],
            .register-container input[type="email"],
            .register-container input[type="password"],
            .register-container select {
                font-size: 10px; /* Reduced from 14px by 4px */
                padding: 4px 4px 4px 26px; /* Reduced padding, adjusted for icon */
            }

            .register-container select {
                padding: 4px; /* Reduced from 8px by 4px */
                background-size: 10px; /* Reduced from 14px by 4px */
            }

            .register-container .input-wrapper i {
                font-size: 10px; /* Reduced from 14px by 4px */
                left: 4px; /* Reduced from 8px by 4px */
            }

            .register-container .input-wrapper input:focus + label,
            .register-container .input-wrapper input:not(:placeholder-shown) + label {
                font-size: 8px; /* Reduced from 10px by 2px */
            }

            .register-container input[type="submit"] {
                font-size: 10px; /* Reduced from 14px by 4px */
                padding: 6px; /* Reduced from 10px by 4px */
            }

            .register-container .links p,
            .register-container .links a {
                font-size: 9px; /* Reduced from 13px by 4px */
            }
        }

        @media (max-width: 480px) {
            .register-container {
                padding: 16px; /* Reduced from 20px by 4px */
            }

            .register-container h2 {
                font-size: 18px; /* Reduced from 22px by 4px */
            }

            .register-container .subtitle {
                font-size: 9px; /* Reduced from 13px by 4px */
            }

            .register-container .error,
            .register-container .success {
                font-size: 8px; /* Reduced from 12px by 4px */
            }

            .register-container .input-wrapper label {
                font-size: 9px; /* Reduced from 12px by 4px */
                left: 22px; /* Adjusted for icon */
            }

            .register-container input[type="text"],
            .register-container input[type="email"],
            .register-container input[type="password"],
            .register-container select {
                font-size: 9px; /* Reduced from 13px by 4px */
                padding: 3px 3px 3px 22px; /* Reduced padding, adjusted for icon */
            }

            .register-container select {
                padding: 3px; /* Reduced from 7px by 4px */
                background-size: 8px; /* Reduced from 12px by 4px */
            }

            .register-container .input-wrapper i {
                font-size: 9px; /* Reduced from 13px by 4px */
            }

            .register-container .input-wrapper input:focus + label,
            .register-container .input-wrapper input:not(:placeholder-shown) + label {
                font-size: 7px; /* Reduced from 9px by 2px */
            }

            .register-container input[type="submit"] {
                font-size: 9px; /* Reduced from 13px by 4px */
                padding: 4px; /* Reduced from 8px by 4px */
            }

            .register-container .links p,
            .register-container .links a {
                font-size: 8px; /* Reduced from 12px by 4px */
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
            <div class="form-group">
                <select id="barangay_id" name="barangay_id" required>
                    <option value="">Select Location</option>
                </select>
            </div>
            <input type="submit" value="Register">
        </form>
        <div class="links">
            <p>Already have an account? <a href="login.php">Login here</a></p>
        </div>
    </div>

    <script>
        const locationSelect = document.querySelector('#barangay_id');
        let currentLevel = 'region';
        let selectedRegion = '';
        let selectedProvince = '';
        let selectedCity = '';

        // Function to fetch data via AJAX
        async function fetchOptions(level, parent = '') {
            try {
                const response = await fetch(`../services/get_locations.php?level=${level}&parent=${encodeURIComponent(parent)}`);
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

        // Initial load of regions
        async function loadRegions() {
            const regions = await fetchOptions('regions');
            locationSelect.innerHTML = '<option value="">Select Location</option>';
            const optgroup = document.createElement('optgroup');
            optgroup.label = 'Regions';
            regions.forEach(region => {
                const option = document.createElement('option');
                option.value = `region:${region}`;
                option.textContent = region;
                optgroup.appendChild(option);
            });
            locationSelect.appendChild(optgroup);
        }

        // Load regions on page load
        loadRegions();

        locationSelect.addEventListener('change', async function() {
            const value = this.value;
            if (!value) return;

            const [level, selected] = value.split(':');

            if (level === 'region') {
                selectedRegion = selected;
                selectedProvince = '';
                selectedCity = '';
                currentLevel = 'province';

                const provinces = await fetchOptions('provinces', selectedRegion);
                this.innerHTML = '<option value="">Select Province</option>';
                const optgroup = document.createElement('optgroup');
                optgroup.label = `${selectedRegion} > Provinces`;
                provinces.forEach(province => {
                    const option = document.createElement('option');
                    option.value = `province:${province}`;
                    option.textContent = province;
                    optgroup.appendChild(option);
                });
                this.appendChild(optgroup);
            } else if (level === 'province') {
                selectedProvince = selected;
                selectedCity = '';
                currentLevel = 'city';

                const cities = await fetchOptions('cities', selectedProvince);
                this.innerHTML = '<option value="">Select City</option>';
                const optgroup = document.createElement('optgroup');
                optgroup.label = `${selectedRegion} > ${selectedProvince} > Cities`;
                cities.forEach(city => {
                    const option = document.createElement('option');
                    option.value = `city:${city}`;
                    option.textContent = city;
                    optgroup.appendChild(option);
                });
                this.appendChild(optgroup);
            } else if (level === 'city') {
                selectedCity = selected;
                currentLevel = 'barangay';

                const barangays = await fetchOptions('barangays', selectedCity);
                this.innerHTML = '<option value="">Select Barangay</option>';
                const optgroup = document.createElement('optgroup');
                optgroup.label = `${selectedRegion} > ${selectedProvince} > ${selectedCity} > Barangays`;
                barangays.forEach(barangay => {
                    const option = document.createElement('option');
                    option.value = barangay.barangay_id;
                    option.textContent = barangay.name;
                    optgroup.appendChild(option);
                });
                this.appendChild(optgroup);
            }
        });

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