<?php
session_start();

// Define base path for consistent redirects
$baseDir = dirname(__DIR__); // Gets the parent directory of the current file's directory

// Redirect to login if not authenticated
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    $loginUrl = $baseDir . '/static/index.php';
    header("Location: " . $loginUrl);
    exit();
}

// Get user information from session
$first_name = $_SESSION['first_name'] ?? 'User';
$last_name = $_SESSION['last_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$user_id = $_SESSION['user_id'] ?? null;

// Log session info for debugging (remove in production)
error_log("Session - User ID: " . $user_id . ", Name: " . $first_name . " " . $last_name);
?>