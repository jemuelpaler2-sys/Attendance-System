<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

guest_only();

$pdo = db();
$error = '';
$loginValue = '';

if (is_post()) {
    require_csrf('login.php');

    $loginValue = post_param('login');
    $password = (string) ($_POST['password'] ?? '');

    if ($loginValue === '' || $password === '') {
        $error = 'Please enter your email or username and password.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1');
        $stmt->execute([$loginValue, $loginValue]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            usleep(250000);
            $error = 'Invalid email, username or password. Please try again.';
        } elseif ($user['status'] !== 'active') {
            $error = 'This account is inactive. Please contact the school administrator.';
        } else {
            session_regenerate_id(true);

            $_SESSION['user'] = [
                'id'           => (int) $user['id'],
                'full_name'    => $user['full_name'],
                'email'        => $user['email'],
                'username'     => $user['username'],
                'role'         => $user['role'],
                'phone'        => $user['phone'],
                'avatar_color' => $user['avatar_color'],
            ];

            $update = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
            $update->execute([$user['id']]);

            $firstName = explode(' ', trim($user['full_name']))[0];
            flash('Welcome back, ' . $firstName . '!', 'success');
            redirect(user_home($user['role']));
        }
    }
}

$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1a73e8">
    <title>Sign in · <?= e(APP_NAME) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
    <script>
        (function () {
            try {
                var saved = localStorage.getItem("smartroll-theme");
                if (saved === "dark") document.documentElement.setAttribute("data-theme", "dark");
            } catch (e) { }
        })();
    </script>
</head>
<body class="auth-body">
<div class="auth-layout">
    <section class="auth-hero">
        <div class="auth-brand">
            <span class="logo-mark"><span class="material-icons-round">how_to_reg</span></span>
            <div>
                <strong><?= e(APP_NAME) ?></strong>
                <span><?= e(SCHOOL_NAME) ?></span>
            </div>
        </div>

        <div class="auth-hero-body">
            <span class="auth-eyebrow">
                <span class="material-icons-round" style="font-size:15px">auto_awesome</span>
                Smart attendance, made simple
            </span>
            <h1>Every class.<br>Every student.<br><em>Accounted for.</em></h1>
            <p class="lead">
                Replace paper registers with a centralized attendance workspace built for
                administrators, teachers and students. Record, monitor and report in seconds.
            </p>

            <div class="auth-features">
                <div class="auth-feature">
                    <span class="material-icons-round">bolt</span>
                    One-tap attendance
                </div>
                <div class="auth-feature">
                    <span class="material-icons-round">insights</span>
                    Live analytics
                </div>
                <div class="auth-feature">
                    <span class="material-icons-round">devices</span>
                    Works on any device
                </div>
                <div class="auth-feature">
                    <span class="material-icons-round">lock</span>
                    Role-based access
                </div>
            </div>
        </div>

        <div class="auth-hero-footer">
            <span class="material-icons-round" style="font-size:16px">verified_user</span>
            School year <?= e(current_school_year()) ?> · Secure school portal
        </div>
    </section>

    <section class="auth-panel">
        <button class="icon-btn" type="button" data-theme-toggle aria-label="Toggle dark mode"
                style="position:absolute;top:22px;right:22px">
            <span class="material-icons-round">dark_mode</span>
        </button>

        <div class="auth-card">
            <div class="auth-card-head">
                <span class="logo-mark xl"><span class="material-icons-round">how_to_reg</span></span>
                <h2>Welcome back</h2>
                <p>Sign in to continue to your attendance workspace.</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>">
                    <span class="material-icons-round"><?= $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'warning' ? 'warning' : 'info') ?></span>
                    <span><?= e($flash['message']) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error">
                    <span class="material-icons-round">error</span>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('login.php')) ?>" class="auth-form" autocomplete="on">
                <?= csrf_field() ?>

                <div class="field">
                    <input type="text" id="login" name="login" placeholder=" "
                           value="<?= e($loginValue) ?>" required autofocus autocomplete="username">
                    <label for="login">Email or username</label>
                </div>

                <div class="field has-toggle">
                    <input type="password" id="password" name="password" placeholder=" "
                           required autocomplete="current-password">
                    <label for="password">Password</label>
                    <button class="password-toggle" type="button" data-password-toggle="password" aria-label="Show password">
                        <span class="material-icons-round">visibility_off</span>
                    </button>
                </div>

                <div class="auth-help">
                    <span>Forgot your password? Contact the registrar.</span>
                </div>

                <button class="btn btn-filled btn-lg btn-block" type="submit">
                    <span class="material-icons-round">login</span>
                    Sign in
                </button>
            </form>
        </div>

        <p class="auth-footer">
            &copy; <?= date('Y') ?> <?= e(SCHOOL_NAME) ?>. All rights reserved.
        </p>
    </section>
</div>

<div class="snackbar" id="snackbar" role="status" aria-live="polite">
    <span class="material-icons-round snackbar-icon">check_circle</span>
    <span class="snackbar-text"></span>
    <button type="button" data-snackbar-close>Dismiss</button>
</div>

<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
