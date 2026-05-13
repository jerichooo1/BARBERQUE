<?php
session_start();
require 'modal_helper.php';

/* Clear only admin-side session values */
unset($_SESSION['admin_id']);
unset($_SESSION['staff_id']);
unset($_SESSION['barber_id']);
unset($_SESSION['role']);
unset($_SESSION['admin_name']);
unset($_SESSION['admin_email']);

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

/* Admin redirect */
showModalMessage(
    'Logged Out',
    'You have been logged out successfully.',
    'Continue',
    'admin_login.php'
);
?>