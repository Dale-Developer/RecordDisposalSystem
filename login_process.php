<?php
session_start();
require_once 'db_connect.php'; // This creates $pdo, not $conn
require_once 'log_activity.php';




if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $errors = [];

    if (empty($email) || empty($password)) {
        $errors[] = "Email and password are required.";
    }

    if (empty($errors)) {
        try {
            // Prepare statement to get user data using PDO
            $stmt = $pdo->prepare("SELECT user_id, first_name, last_name, password_hash, role_id, office_id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            
            if ($stmt->rowCount() === 1) {
                $user = $stmt->fetch();
                
                // Verify password
                if (password_verify($password, $user['password_hash'])) {
                    // Login successful - set session variables
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name'] = $user['last_name'];
                    $_SESSION['email'] = $email;
                    $_SESSION['role_id'] = $user['role_id'];
                    $_SESSION['office_id'] = $user['office_id'];
                    $_SESSION['logged_in'] = true;

                    
                    // Redirect to dashboard
                    header("Location: ../Record_Disposal_System/static/dashboard.php");
                    exit();
                } else {
                    $errors[] = "Invalid email or password.";
                }
            } else {
                $errors[] = "Invalid email or password.";
            }
            
        } catch (Exception $e) {
            $errors[] = "Login error: " . $e->getMessage();
        }
    }
    
    $_SESSION['error'] = implode("<br>", $errors);
    header("Location: ../Record_Disposal_System/static/index.php");
    exit();
} else {
    $_SESSION['error'] = "Invalid request method.";
    header("Location: ../Record_Disposal_System/static/index.php");
    exit();
}
?>