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
    <link rel="stylesheet" href="../assets/css/register.css">
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
                    <label for="first_name">First Name</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="first_name" name="first_name" placeholder="Enter your first name" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="last_name" name="last_name" placeholder="Enter your last name" required>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" id="username" name="username" placeholder="Enter your username" required>
                </div>
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="email" name="email" placeholder="Enter your email" required>
                </div>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                </div>
            </div>
            <div class="form-group">
                <label for="barangay_id">Select Barangay</label>
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