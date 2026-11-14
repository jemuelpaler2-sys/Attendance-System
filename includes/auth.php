<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/database.php';

function require_login(): void
{
    if (!is_logged_in()) {
        flash('Please sign in to continue.', 'warning');
        redirect('login.php');
    }
}

function require_role(string ...$roles): void
{
    require_login();
    if (!in_array(user_role(), $roles, true)) {
        flash('You do not have permission to access that page.', 'error');
        redirect(user_home());
    }
}

function guest_only(): void
{
    if (is_logged_in()) {
        redirect(user_home());
    }
}

/**
 * Refresh cached session details after a profile update.
 */
function refresh_session_user(PDO $pdo): void
{
    if (!is_logged_in()) {
        return;
    }
    $stmt = $pdo->prepare('SELECT id, full_name, email, username, role, phone, avatar_color FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([current_user()['id']]);
    $user = $stmt->fetch();
    if ($user) {
        $_SESSION['user'] = $user;
    }
}
