<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Green Root - Register</title>
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

        .register-container {
            background: #fff;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }

        .register-container h2 {
            font-size: 28px;
            color: #1e3a8a;
            text-align: center;
            margin-bottom: 20px;
        }

        .register-container .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            display: none;
        }

        .register-container .success {
            background: #d1fae5;
            color: #10b981;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            display: none;
        }

        .register-container .error.show,
        .register-container .success.show {
            display: block;
        }

        .register-container .form-group {
            margin-bottom: 20px;
        }

        .register-container label {
            display: block;
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }

        .register-container input[type="text"],
        .register-container input[type="email"],
        .register-container input[type="password"],
        .register-container select {
            width: 100%;
            padding: 10px;
            border: 1px solid #e0e7ff;
            border-radius: 5px;
            font-size: 16px;
            outline: none;
            transition: border 0.3s;
        }

        .register-container input[type="text"]:focus,
        .register-container input[type="email"]:focus,
        .register-container input[type="password"]:focus,
        .register-container select:focus {
            border-color: #4f46e5;
        }

        .register-container input[type="submit"] {
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

        .register-container input[type="submit"]:hover {
            background: #7c3aed;
        }

        .register-container .links {
            text-align: center;
            margin-top: 20px;
        }

        .register-container .links a {
            color: #4f46e5;
            text-decoration: none;
            font-size: 14px;
        }

        .register-container .links a:hover {
            text-decoration: underline;
        }

        /* Mobile Responsive Design */
        @media (max-width: 768px) {
            .register-container {
                padding: 20px;
                max-width: 90%;
            }

            .register-container h2 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
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
            $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
            $password = $_POST['password'];
            $confirm_password = $_POST['confirm_password'];
            $barangay_id = filter_input(INPUT_POST, 'barangay_id', FILTER_SANITIZE_NUMBER_INT);

            // Basic validation
            if (empty($username) || empty($email) || empty($password) || empty($confirm_password) || empty($barangay_id)) {
                $error = 'Please fill in all fields.';
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
                                INSERT INTO users (username, password, email, role, barangay_id)
                                VALUES (:username, :password, :email, 'user', :barangay_id)
                            ");
                            $stmt->execute([
                                'username' => $username,
                                'password' => $hashed_password,
                                'email' => $email,
                                'barangay_id' => $barangay_id
                            ]);

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

    <div class="register-container">
        <h2>Register</h2>
        <div class="error <?php echo $error ? 'show' : ''; ?>">
            <?php echo htmlspecialchars($error); ?>
        </div>
        <div class="success <?php echo $success ? 'show' : ''; ?>">
            <?php echo $success; ?>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            <div class="form-group">
                <label for="barangay_id">Location</label>
                <select id="barangay_id" name="barangay_id" required>
                    <option value="">Select Location</option>
                </select>
            </div>
            <input type="submit" value="Register">
        </form>
        <div class="links">
            <p>Already have an account? <a href="login.php">Login here</a></p>
            <p><a href="../index.php">Back to Home</a></p>
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
            const username = document.querySelector('#username').value;
            const email = document.querySelector('#email').value;
            const password = document.querySelector('#password').value;
            const confirmPassword = document.querySelector('#confirm_password').value;
            const barangayId = document.querySelector('#barangay_id').value;

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            if (!username || !email || !password || !confirmPassword || !barangayId) {
                e.preventDefault();
                document.querySelector('.error').textContent = 'Please fill in all fields.';
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