-- Table for barangays
CREATE TABLE barangays (
    barangay_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    city VARCHAR(100) NOT NULL,
    province VARCHAR(100) NOT NULL,
    region VARCHAR(100) NOT NULL,
    country VARCHAR(100) NOT NULL,
    UNIQUE(name, city, province)
);

-- Table for users (both regular users and validators)
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    role ENUM('user', 'validator') DEFAULT 'user',
    barangay_id INT NOT NULL,
    eco_points INT DEFAULT 0,
    trees_planted INT DEFAULT 0,
    co2_offset DECIMAL(10, 2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id),
    INDEX idx_username (username),
    INDEX idx_email (email)
);

-- Table for tree planting submissions (with enhanced anti-fake measures)
CREATE TABLE submissions (
    submission_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    barangay_id INT NOT NULL,
    trees_planted INT NOT NULL,
    photo_path VARCHAR(255) NOT NULL,
    photo_timestamp DATETIME, -- Timestamp when the photo was taken
    photo_hash VARCHAR(64), -- Hash of photo metadata to detect reuse
    latitude DECIMAL(9, 6) NOT NULL,
    longitude DECIMAL(9, 6) NOT NULL,
    location_accuracy DECIMAL(5, 2), -- GPS accuracy in meters
    device_location_timestamp DATETIME, -- Timestamp when location was captured
    device_id VARCHAR(100), -- Device identifier to detect spam
    ip_address VARCHAR(45), -- IP address to track submission source
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    validated_by INT,
     validated_at TIMESTAMP NULL DEFAULT NULL, -- Allow NULL for validated_at
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id),
    FOREIGN KEY (validated_by) REFERENCES users(user_id),
    UNIQUE(user_id, latitude, longitude, submitted_at), -- Prevent duplicate submissions at same location/time
    UNIQUE(photo_path), -- Prevent photo reuse
    INDEX idx_status (status),
    INDEX idx_user_submitted (user_id, submitted_at)
);

-- Table for events
CREATE TABLE events (
    event_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    event_date DATE NOT NULL,
    location VARCHAR(255) NOT NULL,
    barangay_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id)
);

-- Table for event participants
CREATE TABLE event_participants (
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, user_id),
    FOREIGN KEY (event_id) REFERENCES events(event_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- Table for rewards
CREATE TABLE rewards (
    reward_id INT AUTO_INCREMENT PRIMARY KEY,
    reward_type ENUM('cash', 'voucher') NOT NULL,
    description VARCHAR(255) NOT NULL,
    eco_points_required INT NOT NULL,
    cash_value DECIMAL(10, 2),
    voucher_details VARCHAR(255)
);

-- Table for user rewards
CREATE TABLE user_rewards (
    user_reward_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    reward_id INT NOT NULL,
    claimed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (reward_id) REFERENCES rewards(reward_id)
);

-- Table for recent activities
CREATE TABLE activities (
    activity_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    description VARCHAR(255) NOT NULL,
    activity_type ENUM('submission', 'validation', 'event', 'reward') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- Table for rankings (barangay and national)
CREATE TABLE rankings (
    ranking_id INT AUTO_INCREMENT PRIMARY KEY,
    barangay_id INT NOT NULL,
    total_trees_planted INT DEFAULT 0,
    rank_position INT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id),
    UNIQUE(barangay_id)
);

-- Insert sample barangays
INSERT INTO barangays (name, city, province) VALUES
('Barangay 1', 'Quezon City', 'Metro Manila'),
('Barangay 2', 'Quezon City', 'Metro Manila'),
('Barangay 3', 'Davao City', 'Davao del Sur');

-- Insert sample rewards
INSERT INTO rewards (reward_type, description, eco_points_required, cash_value, voucher_details) VALUES
('cash', 'Cash Reward', 1000, 500.00, NULL),
('voucher', 'Grocery Voucher', 500, NULL, 'Valid at SM Supermarket');