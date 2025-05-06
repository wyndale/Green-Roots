<?php
require_once '../includes/config.php';

// Initialize messages
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $files = ['default_profile', 'icon', 'logo', 'default_voucher'];
        $uploaded_files = [];

        foreach ($files as $file_type) {
            if (!isset($_FILES[$file_type]) || $_FILES[$file_type]['error'] === UPLOAD_ERR_NO_FILE) {
                continue; // Skip if no file uploaded for this type
            }

            $file = $_FILES[$file_type];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = "Error uploading $file_type.";
                break;
            }

            // Validate file type (allow only images)
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/avif'];
            $file_info = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_buffer($file_info, file_get_contents($file['tmp_name']));
            finfo_close($file_info);

            if (!in_array($mime_type, $allowed_types)) {
                $error = "Invalid file type for $file_type. Only JPEG, PNG, GIF, ICO, and AVIF are allowed.";
                break;
            }

            // Read the file data
            $file_data = file_get_contents($file['tmp_name']);
            $uploaded_files[$file_type] = $file_data;
        }

        if (empty($uploaded_files) && empty($error)) {
            $error = 'No files were uploaded.';
        }

        // If no errors, proceed to store in database
        if (empty($error) && !empty($uploaded_files)) {
            foreach ($uploaded_files as $file_type => $file_data) {
                $asset_name = ucfirst(str_replace('_', ' ', $file_type));

                $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE asset_type = :type");
                $stmt->execute(['type' => $file_type]);
                $exists = $stmt->fetchColumn();

                if ($exists) {
                    $stmt = $pdo->prepare("UPDATE assets SET asset_data = :data, created_at = NOW() WHERE asset_type = :type");
                } else {
                    $stmt = $pdo->prepare("INSERT INTO assets (asset_name, asset_type, asset_data) VALUES (:name, :type, :data)");
                    $stmt->bindParam(':name', $asset_name);
                }

                $stmt->bindParam(':type', $file_type);
                $stmt->bindParam(':data', $file_data, PDO::PARAM_LOB);
                $stmt->execute();
            }

            $success = 'Uploaded image(s) successfully!';
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Assets - Green Roots</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }

        body {
            background: #E8F5E9;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .upload-container {
            background: #fff;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 500px;
            text-align: center;
        }

        .upload-container h2 {
            font-size: 28px;
            color: #4CAF50;
            margin-bottom: 20px;
        }

        .upload-container .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            display: none;
        }

        .upload-container .success {
            background: #d1fae5;
            color: #10b981;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            display: none;
        }

        .upload-container .error.show,
        .upload-container .success.show {
            display: block;
        }

        .upload-container form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .upload-container .form-group {
            text-align: left;
        }

        .upload-container label {
            display: block;
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
        }

        .upload-container input[type="file"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #e0e7ff;
            border-radius: 5px;
            font-size: 14px;
        }

        .upload-container input[type="submit"] {
            background: #4CAF50;
            color: #fff;
            padding: 12px;
            border: none;
            border-radius: 25px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s;
        }

        .upload-container input[type="submit"]:hover {
            background: #388E3C;
        }

        /* Mobile Responsive Design */
        @media (max-width: 768px) {
            .upload-container {
                padding: 20px;
                max-width: 90%;
            }

            .upload-container h2 {
                font-size: 24px;
            }

            .upload-container label {
                font-size: 12px;
            }

            .upload-container input[type="file"] {
                font-size: 12px;
            }

            .upload-container input[type="submit"] {
                font-size: 14px;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="upload-container">
        <h2>Upload Assets</h2>
        <div class="error <?php echo $error ? 'show' : ''; ?>">
            <?php echo htmlspecialchars($error); ?>
        </div>
        <div class="success <?php echo $success ? 'show' : ''; ?>">
            <?php echo htmlspecialchars($success); ?>
        </div>
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="form-group">
                <label for="default_profile">Default Profile Picture</label>
                <input type="file" id="default_profile" name="default_profile" accept=".jpg,.jpeg,.png,.gif,.ico,.avif">
            </div>
            <div class="form-group">
                <label for="icon">Icon</label>
                <input type="file" id="icon" name="icon" accept=".jpg,.jpeg,.png,.gif,.ico,.avif">
            </div>
            <div class="form-group">
                <label for="logo">Logo</label>
                <input type="file" id="logo" name="logo" accept=".jpg,.jpeg,.png,.gif,.ico,.avif">
            </div>
            <div class="form-group">
                <label for="default_voucher">Default Voucher Image</label>
                <input type="file" id="default_voucher" name="default_voucher" accept=".jpg,.jpeg,.png,.gif,.ico,.avif">
            </div>
            <input type="submit" value="Upload Images">
        </form>
    </div>

    <script>
        // Client-side validation to ensure at least one file is selected
        document.getElementById('uploadForm').addEventListener('submit', function(event) {
            const inputs = document.querySelectorAll('input[type="file"]');
            let hasFile = false;

            inputs.forEach(input => {
                if (input.files.length > 0) {
                    hasFile = true;
                }
            });

            if (!hasFile) {
                event.preventDefault();
                const errorDiv = document.querySelector('.error');
                errorDiv.textContent = 'Please select at least one file to upload.';
                errorDiv.classList.add('show');
            }
        });

        // Optional: Client-side file type validation
        const allowedExtensions = ['.jpg', '.jpeg', '.png', '.gif', '.ico', '.avif'];
        document.querySelectorAll('input[type="file"]').forEach(input => {
            input.addEventListener('change', function() {
                const errorDiv = document.querySelector('.error');
                errorDiv.classList.remove('show');

                if (this.files.length > 0) {
                    const fileName = this.files[0].name.toLowerCase();
                    const extension = fileName.substring(fileName.lastIndexOf('.'));
                    if (!allowedExtensions.includes(extension)) {
                        errorDiv.textContent = `Invalid file type for ${this.name}. Only JPEG, PNG, GIF, ICO, and AVIF are allowed.`;
                        errorDiv.classList.add('show');
                        this.value = ''; // Clear the input
                    }
                }
            });
        });
    </script>
</body>
</html>