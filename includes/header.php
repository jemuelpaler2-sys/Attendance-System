<?php

declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Dashboard';
$pageSubtitle = $pageSubtitle ?? '';
$activeNav = $activeNav ?? '';
$headerUser = current_user();
$role = user_role();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= e(APP_NAME) ?> — Digital Student Attendance Management System for <?= e(SCHOOL_NAME) ?>.">
    <meta name="theme-color" content="#1a73e8">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>

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
<body>
<div class="app-shell">
    <header class="app-bar">
        <div class="app-bar-left">
            <button class="icon-btn" id="drawerToggle" type="button" aria-label="Toggle navigation" title="Toggle navigation">
                <span class="material-icons-round">menu</span>
            </button>
            <a class="brand" href="<?= e(url(user_home())) ?>">
                <span class="logo-mark"><span class="material-icons-round">how_to_reg</span></span>
                <span class="brand-text">
                    <strong><?= e(APP_NAME) ?></strong>
                    <small><?= e(SCHOOL_NAME) ?></small>
                </span>
            </a>
            <div class="app-bar-divider"></div>
            <div class="app-context">
                <strong><?= e($pageTitle) ?></strong>
                <span><?= e(role_label($role)) ?> workspace</span>
            </div>
        </div>

        <div class="app-bar-actions">
            <button class="icon-btn" type="button" data-theme-toggle aria-label="Toggle dark mode" title="Dark mode">
                <span class="material-icons-round">dark_mode</span>
            </button>

            <div class="dropdown">
                <button class="profile-trigger" type="button" data-menu-toggle aria-haspopup="true" aria-expanded="false">
                    <span class="avatar" style="background: <?= e(avatar_color($headerUser['avatar_color'] ?? null)) ?>">
                        <?= e(initials($headerUser['full_name'] ?? 'User')) ?>
                    </span>
                    <span class="profile-meta">
                        <strong><?= e($headerUser['full_name'] ?? 'User') ?></strong>
                        <small><?= e(role_label($role)) ?></small>
                    </span>
                    <span class="material-icons-round">expand_more</span>
                </button>

                <div class="menu" role="menu">
                    <div class="menu-head">
                        <span class="avatar" style="background: <?= e(avatar_color($headerUser['avatar_color'] ?? null)) ?>">
                            <?= e(initials($headerUser['full_name'] ?? 'User')) ?>
                        </span>
                        <div class="menu-head-meta">
                            <strong><?= e($headerUser['full_name'] ?? 'User') ?></strong>
                            <span><?= e($headerUser['email'] ?? '') ?></span>
                        </div>
                    </div>
                    <div class="menu-divider"></div>
                    <a class="menu-item" href="<?= e(url('profile.php')) ?>" role="menuitem">
                        <span class="material-icons-round">manage_accounts</span>My profile
                    </a>
                    <a class="menu-item" href="<?= e(url(user_home())) ?>" role="menuitem">
                        <span class="material-icons-round">dashboard</span>Dashboard
                    </a>
                    <div class="menu-divider"></div>
                    <form method="post" action="<?= e(url('logout.php')) ?>" class="menu-form">
                        <?= csrf_field() ?>
                        <button type="submit" class="menu-item danger" role="menuitem">
                            <span class="material-icons-round">logout</span>Sign out
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="drawer-scrim" id="drawerScrim"></div>

    <main class="main">
        <div class="main-inner page-enter">
