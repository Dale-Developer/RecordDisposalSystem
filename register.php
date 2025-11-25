<?php
session_start();
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signUp'])) {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role_id = $_POST['role_id'];
    
    // Set office_id - only for custodians, otherwise NULL
    $office_id = ($role_id == 4 && !empty($_POST['office_id'])) ? $_POST['office_id'] : NULL;

    $errors = [];

    // Validation
    if (empty($first_name) || empty($last_name)) {
        $errors[] = "First name and last name are required.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }

    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long.";
    }

    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }

    // Check if email already exists
    $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    $check_email->bind_param("s", $email);
    $check_email->execute();
    $check_email->store_result();

    if ($check_email->num_rows > 0) {
        $errors[] = "Email already exists.";
    }
    $check_email->close();

    if (empty($errors)) {
        // Hash password
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            // Handle NULL office_id properly
            if ($office_id === NULL) {
                $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password_hash, role_id, office_id) VALUES (?, ?, ?, ?, ?, NULL)");
                $stmt->bind_param("ssssi", $first_name, $last_name, $email, $password_hash, $role_id);
            } else {
                $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password_hash, role_id, office_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssii", $first_name, $last_name, $email, $password_hash, $role_id, $office_id);
            }
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Account created successfully! Please login.";
                header("Location: ../Record_Disposal_System/static/index.php");
                exit();
            } else {
                throw new Exception("Error creating account.");
            }
        } catch (Exception $e) {
            $errors[] = "Error creating account: " . $e->getMessage();
            $_SESSION['error'] = implode("<br>", $errors);
            header("Location: ../Record_Disposal_System/static/index.php");
            exit();
        }
    } else {
        $_SESSION['error'] = implode("<br>", $errors);
        header("Location: ../Record_Disposal_System/static/index.php");
        exit();
    }
} else {
    $_SESSION['error'] = "Invalid request.";
    header("Location: ../Record_Disposal_System/static/index.php");
    exit();
}
?>