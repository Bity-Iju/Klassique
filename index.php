<?php
    // Temporarily enable detailed error reporting for debugging
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    // Start session if not already started
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }

    include './config/connection.php'; // Ensure this path is correct and the file establishes $con

    // Initialize messages at the very beginning
    $message = '';
    $signup_message = '';
    $show_signup_section = false; // Flag to determine which section to show initially

    // --- Login Logic ---
    if(isset($_POST['login'])) {
        $userName = $_POST['user_name'];
        $password = $_POST['password'];

        $encryptedPassword = md5($password); // Still using MD5 for compatibility

        // REMOVED 'profile_picture' from the SELECT statement
        $query = "SELECT `id`, `display_name`, `username`, `user_type` FROM `users` WHERE `username` = :userName AND `password` = :password";

        try {
            if (!isset($con) || !$con instanceof PDO) {
                throw new PDOException("Database connection is not established. Check 'config/connection.php'.");
            }

            $stmtLogin = $con->prepare($query);
            $stmtLogin->bindParam(':userName', $userName);
            $stmtLogin->bindParam(':password', $encryptedPassword);
            $stmtLogin->execute();

            $count = $stmtLogin->rowCount();
            if($count == 1) {
                $row = $stmtLogin->fetch(PDO::FETCH_ASSOC);

                $_SESSION['user_id'] = $row['id'];
                $_SESSION['display_name'] = $row['display_name'];
                $_SESSION['user_name'] = $row['username'];
                // REMOVED: $_SESSION['profile_picture'] = $row['profile_picture'];
                $_SESSION['user_type'] = $row['user_type'];

                header("location:dashboard.php");
                exit;

            } else {
                $message = 'Incorrect username or password.';
            }
        } catch(PDOException $ex) {
            error_log("Login error: " . $ex->getMessage());
            $message = 'An error occurred during login. Please try again later. Details: ' . htmlspecialchars($ex->getMessage());
        }
    }

    // --- Signup Logic ---
    if(isset($_POST['register'])) {
        $fullName = trim($_POST['full_name']);
        $username = trim($_POST['signup_username']);
        $email = trim($_POST['signup_email']);
        $password = $_POST['signup_password'];
        $confirmPassword = $_POST['signup_confirm_password'];
        $userType = $_POST['signup_user_type']; // Get the selected user type

        $show_signup_section = true; // Keep signup section active if registration attempt is made

        if (empty($fullName) || empty($username) || empty($email) || empty($password) || empty($confirmPassword) || empty($userType)) {
            $signup_message = 'All fields are required for registration.';
        } elseif ($password !== $confirmPassword) {
            $signup_message = 'Passwords do not match.';
        } elseif (strlen($password) < 6) {
            $signup_message = 'Password must be at least 6 characters long.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $signup_message = 'Invalid email format.';
        } else {
            // Check if username or email already exists
            $checkQuery = "SELECT COUNT(*) FROM `users` WHERE `username` = :username OR `email` = :email";
            try {
                $stmtCheck = $con->prepare($checkQuery);
                $stmtCheck->bindParam(':username', $username);
                $stmtCheck->bindParam(':email', $email);
                $stmtCheck->execute();
                $exists = $stmtCheck->fetchColumn();

                if ($exists > 0) {
                    $signup_message = 'Username or Email already registered. Please choose another or login.';
                } else {
                    // Proceed with registration
                    $encryptedPassword = md5($password);
                    // $userType is already collected from the form

                    $insertQuery = "INSERT INTO `users` (`username`, `password`, `user_type`, `email`, `display_name`) VALUES (:username, :password, :user_type, :email, :display_name)";

                    $stmtInsert = $con->prepare($insertQuery);
                    $stmtInsert->bindParam(':username', $username);
                    $stmtInsert->bindParam(':password', $encryptedPassword);
                    $stmtInsert->bindParam(':user_type', $userType); // Use the collected user type
                    $stmtInsert->bindParam(':email', $email);
                    $stmtInsert->bindParam(':display_name', $fullName);

                    if ($stmtInsert->execute()) {
                        $signup_message = 'Registration successful! You can now log in.';
                        $show_signup_section = false; // Switch back to login after successful registration
                        // Clear form fields after successful registration
                        $_POST = array(); // Clear all POST data to clear form fields
                    } else {
                        $signup_message = 'Failed to register user. Please try again.';
                    }
                }
            } catch (PDOException $ex) {
                error_log("Registration error: " . $ex->getMessage());
                $signup_message = 'An error occurred during registration. Details: ' . htmlspecialchars($ex->getMessage());
            }
        }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login / Sign Up - Klassique Diagnoses & Clinical Services</title>

    <link rel="icon" href="/dist/img/favicon.ico" type="image/x-icon">
    <link rel="shortcut icon" href="/dist/img/favicon.ico" type="image/x-icon">

    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">

    <style>
        :root {
            --primary-blue:rgb(2, 43, 2);
            --dark-blue:rgb(5, 148, 31);
            --light-blue: #e0f2ff;
            --text-dark: #343a40;
            --text-medium: #6c757d;
            --text-light: #f8f9fa;
            --border-light: #ced4da;
            --shadow-light: rgba(0, 0, 0, 0.08);
            --shadow-medium: rgba(0, 0, 0, 0.15);
            --success-green: #28a745;
            --danger-red: #dc3545;
        }

        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background: linear-gradient(135deg, #f0f2f5, #e0e5ec);
            overflow: auto; /* Allow scrolling if content is too large */
        }

        .login-wrapper {
            display: flex;
            width: 90%;
            max-width: 1800px;
            min-height: 700px;
            background-color: #fff;
            border-radius: 15px;
            box-shadow: 0 10px 30px var(--shadow-medium);
            overflow: hidden;
            margin: 20px 0; /* Add vertical margin to prevent content touching edges */
        }

        .landing-info {
            flex: 1.2;
            background: linear-gradient(to bottom right, var(--primary-blue), var(--dark-blue));
            color: var(--text-light);
            padding: 80px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start; /* Ensure items within landing-info are aligned to the start */
            text-align: left; /* Explicitly align text to the left */
            position: relative;
        }

        .landing-info::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('https://www.transparenttextures.com/patterns/cubes.png');
            opacity: 0.05;
            z-index: 0;
        }

        .landing-info > * { /* Target direct children to ensure they are above pseudo-element */
            position: relative;
            z-index: 2;
        }

        .landing-info h1 {
            font-size: 2.2em;
            margin-bottom: 5px;
            font-weight: 700;
            line-height: 1.2;
            color: #fff;
            text-align: left; /* Ensure title is left-aligned */
        }

        .landing-info h1 strong {
            font-size: 1.6em;
            display: block;
            margin-bottom: 2px;
            color: #fff;
        }

        .landing-info h3 {
            font-size: 1.5em;
            margin-bottom: 5px;
            font-weight: 600;
            color: #fff;
            text-align: left; /* Ensure main h3 is left-aligned */
        }

        .landing-info p {
            font-size: 1.1em;
            line-height: 1.2;
            margin-bottom: 5px;
            color: rgba(255, 255, 255, 0.9);
            word-wrap: break-word; /* Ensure long words break */
            display: flex; /* Make the parent <p> a flex container */
            flex-wrap: wrap; /* Allow items to wrap if screen is too narrow */
            gap: 15px; /* Adjust gap between the contact detail spans and pipes */
            justify-content: flex-start; /* Align items to the start */
            text-align: left; /* Ensure text within p is left-aligned */
        }
        .landing-info p span {
            display: inline-flex; /* Use inline-flex to keep them on one line but apply flex properties */
            align-items: center; /* Vertically align icon and text */
            gap: 5px; /* Space between icon and text */
            white-space: nowrap; /* Prevent wrapping for each contact detail */
        }

        .landing-info p span .fas {
            font-size: 1.1em; /* Adjust icon size if needed */
        }


        .landing-info hr {
            border: none;
            border-top: 2px solid rgba(255, 255, 255, 0.2);
            width: 80%;
            margin: 30px 0;
        }

        .landing-info .services-list {
            /* Ensure the container itself aligns its text to the left */
            text-align: left;
            width: 100%; /* Ensure it takes full width to align content within it */
        }

        .landing-info .services-list h3 {
            font-size: 1.5em;
            margin-top: 5px;
            margin-bottom: 5px;
            font-weight: 600;
            color: #fff;
            text-align: left; /* Ensure this header is also left-aligned */
        }

        .landing-info .services-list p {
            font-size: 1.1em;
            margin-bottom: 8px;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.95);
            display: flex; /* Make the paragraph a flex container */
            align-items: center; /* Vertically align items in the middle */
            gap: 10px; /* Add some space between the icon and the text */
            justify-content: flex-start; /* This is key for horizontal alignment of flex items */
            text-align: left; /* Redundant if parent is left, but good for explicit safety */
        }

        .landing-info .services-list p .fas {
            /* Optional: Adjust icon size or color if needed */
            font-size: 1.2em; /* Slightly larger icon */
            min-width: 20px; /* Ensure consistent spacing for icons if they vary in width */
            text-align: center; /* Center the icon within its allocated space */
        }

        .login-container {
            flex: 0.6;
            padding: 5px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background-color: #fff;
        }

        .login-box {
            width: 100%;
            max-width: 400px;
            text-align: center;
        }

        .login-logo {
            margin-bottom: 10px;
        }

        #system-logo {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border: 3px solid var(--primary-blue);
            border-radius: 50%;
            padding: 0;
            background-color: #fff;
            margin-bottom: 5px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .login-logo .text-center {
            font-size: 2.5em;
            font-weight: 500;
            color: var(--text-dark);
        }

        .card-body {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 5px 15px var(--shadow-light);
        }

        .login-box-msg {
            font-size: 1.1em;
            color: var(--text-medium);
            margin-bottom: 10px;
        }

        /* Input Group Styles (to match the image) */
        .input-group {
            margin-bottom: 20px;
            border: 1px solid var(--border-light); /* Main border for the group */
            border-radius: 8px; /* Apply border-radius to the whole group */
            overflow: hidden; /* Crucial for rounded corners to work with inner elements */
            display: flex; /* Ensure it's a flex container for its children */
            align-items: center; /* Vertically align items within the input group */
            background-color: #fcfcfc; /* Set background here for consistent look */
        }
        /* Style for the input field itself */
        .input-group .form-control {
            border: none; /* Remove individual border for input */
            padding: 12px 10px; /* Increased vertical padding for better height */
            font-size: 1em;
            height: auto;
            background-color: transparent; /* Make input background transparent so input-group background shows */
            color: var(--text-dark) !important;
            box-shadow: none !important;
            flex-grow: 1; /* Allow input to take up remaining space */
        }
        .input-group .form-control:focus {
            background-color: transparent !important; /* Keep transparent on focus */
            outline: none; /* Remove default outline */
            box-shadow: none; /* Ensure no focus box shadow */
        }
        .input-group .form-control::placeholder {
            color: var(--text-medium) !important;
            opacity: 0.7;
        }

        /* Styles for prepended icons (left side) */
        .input-group-prepend .input-group-text {
            background-color: transparent; /* No background */
            border: none; /* No border */
            padding: 0 10px 0 15px; /* Adjust padding: top, right, bottom, left */
            color: var(--primary-blue);
            height: 100%; /* Ensure it takes full height of the input-group */
            display: flex;
            align-items: center;
            justify-content: center;
        }
        /* Styles for appended icons (right side, like eye icon) */
        .input-group-append .input-group-text {
            background-color: transparent; /* No background */
            border: none; /* No border */
            padding: 0 15px 0 10px; /* Adjust padding: top, right, bottom, left */
            color: var(--primary-blue);
            height: 100%; /* Ensure it takes full height of the input-group */
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .input-group-text .fas { /* Common style for icons within both prepend/append */
            font-size: 1.1em; /* Adjust icon size */
        }

        #signup_section .input-group {
            margin-bottom: 10px;
        }

        .btn-primary {
            background: var(--primary-blue) !important;
            border: none !important;
            color: #fff !important;
            padding: 12px 25px;
            font-size: 1.1em;
            font-weight: 600;
            border-radius: 8px !important;
            transition: background-color 0.3s ease, transform 0.2s ease, box-shadow 0.2s ease;
            width: 100%;
            margin-top: 5px;
            box-shadow: 0 4px 10px rgba(0, 123, 255, 0.2);
        }
        .btn-primary:hover {
            background-color: var(--dark-blue) !important;
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(1, 72, 11, 0.3);
        }

        .text-danger {
            color: var(--danger-red) !important;
            font-weight: 500;
            font-size: 0.95em;
            margin-top: 15px;
        }
        .text-success {
            color: var(--success-green) !important;
            font-weight: 500;
            font-size: 0.95em;
            margin-top: 15px;
        }

        .toggle-link {
            display: block;
            margin-top: 25px;
            text-align: center;
            font-size: 0.95em;
            color: var(--primary-blue);
            cursor: pointer;
            text-decoration: none;
            transition: color 0.2s ease;
        }
        .toggle-link:hover {
            color: var(--dark-blue);
            text-decoration: underline;
        }

        .fas.fa-eye, .fas.fa-eye-slash {
            cursor: pointer;
        }

        /* --- Responsive Adjustments for Smaller Screens --- */

        /* Tablets and smaller Desktops (992px and below) */
        @media (max-width: 992px) {
            .login-wrapper {
                flex-direction: column; /* Stack panels vertically */
                width: 95%; /* Use more width on smaller screens */
                min-height: auto; /* Allow height to adjust to content */
                margin: 20px auto; /* Center with vertical margin */
                border-radius: 10px;
            }
            .landing-info {
                padding: 30px; /* Reduced padding */
                text-align: left; /* Ensure left alignment on tablets too */
                border-radius: 10px 10px 0 0; /* Rounded top corners */
                flex: none; /* Remove flex sizing to allow content to dictate height */
                min-height: 250px; /* Ensure a minimum height for the info panel */
                justify-content: flex-start; /* Align content to the top */
            }
            .landing-info h1 {
                font-size: 2em; /* Smaller font size */
                margin-bottom: 10px;
            }
            .landing-info h1 strong {
                font-size: 1em;
            }
            .landing-info h3 {
                font-size: 1.3em; /* Smaller font size */
                margin-bottom: 10px;
            }
            .landing-info p {
                font-size: 0.9em; /* Smaller font size */
                line-height: 1.4;
                margin-bottom: 10px;
            }
            .landing-info hr {
                width: 60%;
                margin: 20px auto;
            }
            /* .landing-info .services-list {
                   Add display: none; here if you want to hide services on tablets
            } */
            .login-container {
                padding: 30px;
                border-radius: 0 0 10px 10px; /* Rounded bottom corners */
                flex: none;
            }
            .login-box {
                max-width: 100%; /* Forms take full width */
            }
            .login-logo {
                margin-bottom: 30px;
            }
            #system-logo {
                width: 80px;
                height: 80px;
            }
            .login-logo .text-center {
                font-size: 2em;
            }
            .card-body {
                padding: 30px;
            }
            .login-box-msg {
                font-size: 1em;
                margin-bottom: 25px;
            }
            .input-group .form-control {
                padding: 12px 15px; /* Reduced padding */
                font-size: 0.95em;
            }
            .btn-primary {
                padding: 10px 20px; /* Reduced padding */
                font-size: 1em;
            }
            .toggle-link {
                margin-top: 20px;
            }
        }

        /* Mobile Phones (576px and below) */
        @media (max-width: 576px) {
            body {
                padding: 10px;
            }
            .login-wrapper {
                width: 100%; /* Full width on tiny screens */
                margin: 0; /* No margin */
                border-radius: 0; /* No border-radius for full screen */
                box-shadow: none; /* No shadow */
            }
            .landing-info {
                padding: 20px; /* Further reduced padding */
                border-radius: 0;
                min-height: 200px; /* Minimum height for mobile info panel */
                text-align: left; /* Ensure left alignment on mobiles too */
            }
            .landing-info h1 {
                font-size: 1.6em; /* Even smaller font size */
            }
            .landing-info h1 strong {
                font-size: 0.9em;
                margin-bottom: 0;
            }
            .landing-info h3 {
                font-size: 1.1em;
                margin-bottom: 8px;
            }
            .landing-info p {
                font-size: 0.8em; /* Smallest font size for paragraphs */
                line-height: 1.3;
                margin-bottom: 8px;
            }
            .landing-info hr {
                width: 80%;
                margin: 15px auto;
            }
            .login-container {
                padding: 20px;
                border-radius: 0;
            }
            .card-body {
                padding: 25px;
            }
            .login-logo {
                margin-bottom: 25px;
            }
            #system-logo {
                    width: 70px;
                    height: 70px;
            }
            .login-logo .text-center {
                font-size: 1.8em;
            }
            .login-box-msg {
                font-size: 0.9em;
                margin-bottom: 20px;
            }
            .input-group {
                margin-bottom: 15px;
            }
            .input-group .form-control {
                padding: 10px 12px;
                font-size: 0.9em;
            }
            .btn-primary {
                padding: 8px 15px;
                font-size: 0.95em;
            }
            .toggle-link {
                margin-top: 15px;
                font-size: 0.85em;
            }
            .text-danger, .text-success {
                font-size: 0.85em;
            }
        }
    </style>
</head>
<body class="hold-transition login-page">
<div class="login-wrapper">

    <div class="landing-info">
        <h1><strong>Klassique Diagnoses & Clinical Services</strong></h1>
        <hr>
        <h3>Caring for a BETTER Life?</h3>
        <p>Visit us @ No.23, Klassique Diagnostic house, Wuro Bulude B, Adjacent <strong>Federal Medical Center (FMC)</strong> Mubi, Adamawa State</p>
        <p>
            <span><i class="fas fa-envelope"></i> careklas7@gmail.com</span> |
            <span><i class="fas fa-phone"></i> +234 (0) 814 856 4676</span> |
            <span><i class="fas fa-user-md"></i> +234 (0) 902 115 6143</span>
        </p>
        <hr>
        <div class="services-list">
            <h3>Our Services</h3>
            <p><i class="fas fa-microscope"></i> Ultrasound</p>
            <p><i class="fas fa-flask"></i> Laboratory Services</p>
            <p><i class="fas fa-user-friends"></i> Out-Patient Services</p>
            <p><i class="fas fa-notes-medical"></i> General Medical Checkup</p>
        </div>
    </div>
    <div class="login-container">
        <div class="login-box">
            <div class="login-logo">
                <img src="dist/img/logo.png" class="img-thumbnail" id="system-logo" alt="KDCS Logo">
                <div class="text-center h2 mb-0">KDCS</div>
            </div>
            <div class="card-body">

                <div id="login_section" style="<?php echo $show_signup_section ? 'display: none;' : 'display: block;'; ?>">
                    <p class="login-box-msg">Please enter your login credentials</p>
                    <form method="post">
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <div class="input-group-text">
                                    <span class="fas fa-user"></span>
                                </div>
                            </div>
                            <input type="text" class="form-control autofocus"
                            placeholder="Username" id="user_name" name="user_name" required>
                        </div>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <div class="input-group-text">
                                    <span class="fas fa-lock"></span>
                                </div>
                            </div>
                            <input type="password" class="form-control"
                            placeholder="Password" id="password" name="password" required>
                            <div class="input-group-append">
                                <div class="input-group-text">
                                    <span class="fas fa-eye" id="toggleLoginPassword" style="cursor: pointer;"></span>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12">
                                <button name="login" type="submit" class="btn btn-primary">Sign In</button>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12">
                                <p class="mt-3 mb-0 <?php echo ($message != '') ? 'text-danger' : ''; ?>">
                                    <?php if($message != '') { echo $message; } ?>
                                </p>
                            </div>
                        </div>
                    </form>
                    <a href="javascript:void(0);" class="toggle-link" onclick="showSection('signup');">Don't have an account? Sign Up Here</a>
                </div>

                <div id="signup_section" style="<?php echo $show_signup_section ? 'display: block;' : 'display: none;'; ?>">
                    <p class="login-box-msg">Register a new account</p>
                    <form method="post">
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <div class="input-group-text">
                                    <span class="fas fa-user-circle"></span>
                                </div>
                            </div>
                            <input type="text" class="form-control"
                            placeholder="Full Name" name="full_name" value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>" required>
                        </div>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <div class="input-group-text">
                                    <span class="fas fa-user"></span>
                                </div>
                            </div>
                            <input type="text" class="form-control"
                            placeholder="Username" name="signup_username" value="<?php echo isset($_POST['signup_username']) ? htmlspecialchars($_POST['signup_username']) : ''; ?>" required>
                        </div>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <div class="input-group-text">
                                    <span class="fas fa-envelope"></span>
                                </div>
                            </div>
                            <input type="email" class="form-control"
                            placeholder="Email" name="signup_email" value="<?php echo isset($_POST['signup_email']) ? htmlspecialchars($_POST['signup_email']) : ''; ?>" required>
                        </div>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <div class="input-group-text">
                                    <span class="fas fa-lock"></span>
                                </div>
                            </div>
                            <input type="password" class="form-control"
                            placeholder="Password" id="signup_password" name="signup_password" required>
                            <div class="input-group-append">
                                <div class="input-group-text">
                                    <span class="fas fa-eye" id="toggleSignupPassword" style="cursor: pointer;"></span>
                                </div>
                            </div>
                        </div>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <div class="input-group-text">
                                    <span class="fas fa-lock"></span>
                                </div>
                            </div>
                            <input type="password" class="form-control"
                            placeholder="Confirm Password" id="signup_confirm_password" name="signup_confirm_password" required>
                            <div class="input-group-append">
                                <div class="input-group-text">
                                    <span class="fas fa-eye" id="toggleConfirmPassword" style="cursor: pointer;"></span>
                                </div>
                            </div>
                        </div>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <div class="input-group-text">
                                    <span class="fas fa-user-tag"></span> </div>
                            </div>
                            <select class="form-control" name="signup_user_type" required>
                                <option value="">Select Role</option>
                                <option value="Admin" <?php echo (isset($_POST['signup_user_type']) && $_POST['signup_user_type'] == 'Admin') ? 'selected' : ''; ?>>Admin</option>
                                <option value="Secretary" <?php echo (isset($_POST['signup_user_type']) && $_POST['signup_user_type'] == 'Secretary') ? 'selected' : ''; ?>>Secretary</option>
                                <option value="Scanning Room" <?php echo (isset($_POST['signup_user_type']) && $_POST['signup_user_type'] == 'Scanning Room') ? 'selected' : ''; ?>>Scanning Room</option>
                                <option value="Lab Technician" <?php echo (isset($_POST['signup_user_type']) && $_POST['signup_user_type'] == 'Lab Technician') ? 'selected' : ''; ?>>Lab Technician</option>
                                <option value="Doctor" <?php echo (isset($_POST['signup_user_type']) && $_POST['signup_user_type'] == 'Doctor') ? 'selected' : ''; ?>>Doctor</option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-12">
                                <button name="register" type="submit" class="btn btn-primary">Register</button>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12">
                                <p class="mt-3 mb-0 <?php echo (strpos($signup_message, 'successful') !== false) ? 'text-success' : 'text-danger'; ?>">
                                    <?php if($signup_message != '') { echo $signup_message; } ?>
                                </p>
                            </div>
                        </div>
                    </form>
                    <a href="javascript:void(0);" class="toggle-link" onclick="showSection('login');">Already have an account? Login Here</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function showSection(section) {
        const loginSection = document.getElementById('login_section');
        const signupSection = document.getElementById('signup_section');

        if (section === 'login') {
            loginSection.style.display = 'block';
            signupSection.style.display = 'none';
        } else if (section === 'signup') {
            loginSection.style.display = 'none';
            signupSection.style.display = 'block';
        }
    }

    // Toggle password visibility for Login Form
    const toggleLoginPassword = document.querySelector('#toggleLoginPassword');
    const loginPassword = document.querySelector('#password');

    if (toggleLoginPassword && loginPassword) {
        toggleLoginPassword.addEventListener('click', function () {
            const type = loginPassword.getAttribute('type') === 'password' ? 'text' : 'password';
            loginPassword.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
    }

    // Toggle password visibility for Signup Form (Password field)
    const toggleSignupPassword = document.querySelector('#toggleSignupPassword');
    const signupPassword = document.querySelector('#signup_password');

    if (toggleSignupPassword && signupPassword) {
        toggleSignupPassword.addEventListener('click', function () {
            const type = signupPassword.getAttribute('type') === 'password' ? 'text' : 'password';
            signupPassword.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
    }

    // Toggle password visibility for Signup Form (Confirm Password field)
    const toggleConfirmPassword = document.querySelector('#toggleConfirmPassword');
    const signupConfirmPassword = document.querySelector('#signup_confirm_password');

    if (toggleConfirmPassword && signupConfirmPassword) {
        toggleConfirmPassword.addEventListener('click', function () {
            const type = signupConfirmPassword.getAttribute('type') === 'password' ? 'text' : 'password';
            signupConfirmPassword.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
    }
</script>
</body>
</html>