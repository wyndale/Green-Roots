<?php
session_start();

// Determine redirection based on user role
$redirect_url = 'login.php';
$redirect_text = 'Go to Login';

if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        $redirect_url = '../admin/admin_dashboard.php';
        $redirect_text = 'Go to Admin Dashboard';
    } elseif ($_SESSION['role'] === 'eco_validator') {
        $redirect_url = '../validator/validator_dashboard.php';
        $redirect_text = 'Go to Validator Dashboard';
    } else {
        $redirect_url = '../views/dashboard.php';
        $redirect_text = 'Go to Dashboard';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied</title>
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

        .access-denied-container {
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

        .access-denied-container::before {
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

        .access-denied-container h2 {
            font-size: 28px;
            color: #dc2626;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .access-denied-container .subtitle {
            font-size: 15px;
            color: #5a5959;
            margin-bottom: 30px;
            position: relative;
            z-index: 1;
        }

        .access-denied-container .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 25px;
            text-align: center;
            position: relative;
            z-index: 1;
            animation: fadeIn 0.5s ease-in-out;
        }

        .access-denied-container a.redirect-btn {
            display: inline-block;
            background: #4CAF50;
            color: #fff;
            padding: 12px 24px;
            margin-top: 12px;
            border: none;
            border-radius: 25px;
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 0.4px;
            text-decoration: none;
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

        .access-denied-container a.redirect-btn:hover {
            background: #388E3C;
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 5px 15px rgba(76, 175, 80, 0.4);
            animation: bounce 0.3s ease-out;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(-2px) scale(1.05); }
            50% { transform: translateY(-5px) scale(1.1); }
        }

        .access-denied-container a.redirect-btn:active {
            transform: translateY(0) scale(0.95);
        }

        /* Mobile Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 15px;
            }

            .access-denied-container {
                padding: 25px;
                max-width: 90%;
            }

            .access-denied-container h2 {
                font-size: 26px;
                margin-bottom: 8px;
            }

            .access-denied-container .subtitle {
                font-size: 13px;
                margin-bottom: 25px;
            }

            .access-denied-container .error {
                margin-bottom: 20px;
                font-size: 13px;
                padding: 8px;
            }

            .access-denied-container a.redirect-btn {
                font-size: 14px;
                padding: 10px 20px;
            }
        }

        @media (max-width: 480px) {
            .access-denied-container {
                padding: 20px;
            }

            .access-denied-container h2 {
                font-size: 22px;
            }

            .access-denied-container .subtitle {
                font-size: 13px;
            }

            .access-denied-container .error {
                font-size: 12px;
            }

            .access-denied-container a.redirect-btn {
                font-size: 13px;
                padding: 8px 16px;
            }
        }
    </style>
</head>
<body>
    <div class="access-denied-container">
        <h2>Access Denied</h2>
        <p class="subtitle">You do not have permission to access this page.</p>
        <div class="error">
            <i class="fas fa-exclamation-triangle"></i> Unauthorized Access
        </div>
        <a href="<?php echo htmlspecialchars($redirect_url); ?>" class="redirect-btn"><?php echo htmlspecialchars($redirect_text); ?></a>
    </div>
</body>
</html>