<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (!is_logged_in()) {
    redirect('login.php');
}

if (!is_post() || !verify_csrf()) {
    redirect(user_home());
}

unset($_SESSION['user']);
session_regenerate_id(true);
flash('You have been signed out securely.', 'info');
redirect('login.php');
