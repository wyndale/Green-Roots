<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Green Roots</title>
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
            overflow-x: hidden;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 0;
        }

        .header h1 {
            font-size: 28px;
            color: #1e3a8a;
        }

        .header .nav-links a {
            margin-left: 20px;
            text-decoration: none;
            color: #4f46e5;
            font-weight: bold;
        }

        .hero {
            text-align: center;
            padding: 50px 0;
        }

        .hero h2 {
            font-size: 36px;
            color: #1e3a8a;
            margin-bottom: 20px;
        }

        .hero p {
            font-size: 18px;
            color: #666;
            margin-bottom: 30px;
        }

        .hero .cta-btn {
            background: #4f46e5;
            color: #fff;
            padding: 15px 30px;
            border-radius: 25px;
            text-decoration: none;
            font-size: 18px;
            transition: background 0.3s;
        }

        .hero .cta-btn:hover {
            background: #7c3aed;
        }

        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            padding: 50px 0;
        }

        .feature-box {
            background: #fff;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .feature-box h3 {
            font-size: 20px;
            color: #1e3a8a;
            margin-bottom: 10px;
        }

        .feature-box p {
            color: #666;
        }

        .footer {
            text-align: center;
            padding: 20px 0;
            color: #666;
        }

        /* Mobile Responsive Design */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 10px;
            }

            .header .nav-links {
                display: flex;
                gap: 15px;
            }

            .hero h2 {
                font-size: 28px;
            }

            .hero p {
                font-size: 16px;
            }

            .features {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Green Roots</h1>
            <div class="nav-links">
                <a href="/green-roots/views/login.php">Login</a>
                <a href="/green-roots/views/register.php">Register</a>
            </div>
        </div>

        <div class="hero">
            <h2>Plant Trees, Earn Rewards, Save the Planet</h2>
            <p>Join our initiative to combat deforestation and climate change 
                while earning money and rewards by planting trees in your community.</p>
            <a href="views/register.php" class="cta-btn">Get Started</a>
        </div>

        <div class="features">
            <div class="feature-box">
                <h3>Plant & Earn</h3>
                <p>Plant trees, submit your entries, and earn money or rewards after validation.</p>
            </div>
            <div class="feature-box">
                <h3>Compete & Rank</h3>
                <p>Join the leaderboard and see how your barangay ranks in the Philippines.</p>
            </div>
            <div class="feature-box">
                <h3>Join Events</h3>
                <p>Participate in tree planting events and contribute to a greener future.</p>
            </div>
        </div>

        <div class="footer">
            <p>&copy; 2025 Green Roots - Tree Planting Initiative. All rights reserved.</p>
        </div>
    </div>
</body>
</html>