<?php
session_start();
require 'modal_helper.php';

/* Clear only customer-side session values */
unset($_SESSION['user_id']);
unset($_SESSION['first_name']);
unset($_SESSION['last_name']);
unset($_SESSION['email']);

/* If no other session data remains, destroy session cookie too */
if (empty($_SESSION)) {
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
}

/* Customer redirect */
showModalMessage(
    'Logged Out',
    'You have been logged out successfully.',
    'Continue',
    'index.php'
);
?>