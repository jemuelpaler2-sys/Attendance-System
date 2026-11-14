<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

require_login();

$pdo = db();
$uid = (int) current_user()['id'];

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$uid]);
$user = $stmt->fetch();

if (!$user) {
    flash('Your account could not be found.', 'error');
    redirect('logout.php');
}

$profileErrors = [];
$passwordErrors = [];

if (is_post()) {
    require_csrf('profile.php');
    $action = post_param('action');

    if ($action === 'profile') {
        $fullName = post_param('full_name');
        $email = strtolower(post_param('email'));
        $phone = post_param('phone');

        if (mb_strlen($fullName) < 3) {
            $profileErrors[] = 'Please enter your full name (at least 3 characters).';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $profileErrors[] = 'Please enter a valid email address.';
        } else {
            $check = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $check->execute([$email, $uid]);
            if ($check->fetch()) {
                $profileErrors[] = 'That email address is already used by another account.';
            }
        }

        if (!$profileErrors) {
            $update = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?');
            $update->execute([$fullName, $email, $phone !== '' ? $phone : null, $uid]);
            refresh_session_user($pdo);
            flash('Your profile has been updated.', 'success');
            redirect('profile.php');
        }

        $user['full_name'] = $fullName;
        $user['email'] = $email;
        $user['phone'] = $phone;
    }

    if ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if ($current === '' || $new === '' || $confirm === '') {
            $passwordErrors[] = 'Please complete all password fields.';
        }

        if (!password_verify($current, $user['password_hash'])) {
            $passwordErrors[] = 'Your current password is incorrect.';
        }

        if (strlen($new) < 8) {
            $passwordErrors[] = 'The new password must be at least 8 characters long.';
        }

        if ($new !== $confirm) {
            $passwordErrors[] = 'The new password and confirmation do not match.';
        }

        if (!$passwordErrors) {
            $update = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $update->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            flash('Your password has been changed successfully.', 'success');
            redirect('profile.php');
        }
    }
}

$extra = [];

if ($user['role'] === 'student') {
    $stmt = $pdo->prepare(
        'SELECT s.student_no, s.gender, s.guardian_name, s.guardian_phone, s.status AS student_status,
                c.name AS class_name
         FROM students s
         LEFT JOIN classes c ON c.id = s.class_id
         WHERE s.user_id = ? LIMIT 1'
    );
    $stmt->execute([$uid]);
    $extra = $stmt->fetch() ?: [];
} elseif ($user['role'] === 'teacher') {
    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT cs.class_id) AS classes, COUNT(*) AS subjects
         FROM class_subjects cs WHERE cs.teacher_id = ?'
    );
    $stmt->execute([$uid]);
    $extra = $stmt->fetch() ?: [];

    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT s.id) FROM students s
         JOIN class_subjects cs ON cs.class_id = s.class_id
         WHERE cs.teacher_id = ? AND s.status = "enrolled"'
    );
    $stmt->execute([$uid]);
    $extra['students'] = (int) $stmt->fetchColumn();
}

$pageTitle = 'My Profile';
$pageSubtitle = 'Manage your personal information and account security.';
$activeNav = 'profile';

require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div class="page-head-text">
        <h1>My Profile</h1>
        <p>Manage your personal information and account security.</p>
    </div>
    <div class="page-head-actions">
        <span class="chip chip-role">
            <span class="material-icons-round">verified_user</span>
            <?= e(role_label($user['role'])) ?>
        </span>
    </div>
</div>

<div class="grid grid-1-2">
    <div class="stack">
        <div class="card">
            <div class="profile-hero">
                <span class="avatar xl" style="background: <?= e(avatar_color($user['avatar_color'])) ?>">
                    <?= e(initials($user['full_name'])) ?>
                </span>
                <div class="profile-hero-meta">
                    <h2><?= e($user['full_name']) ?></h2>
                    <p>@<?= e($user['username']) ?> · <?= e(role_label($user['role'])) ?></p>
                    <div class="row mt-8">
                        <span class="chip <?= $user['status'] === 'active' ? 'chip-present' : 'chip-absent' ?>">
                            <span class="material-icons-round"><?= $user['status'] === 'active' ? 'check_circle' : 'block' ?></span>
                            <?= e(ucfirst($user['status'])) ?>
                        </span>
                    </div>
                </div>
            </div>

            <hr class="divider">

            <div class="profile-detail-list">
                <div class="profile-detail">
                    <span class="material-icons-round">mail</span>
                    <div><small>Email</small><strong><?= e($user['email']) ?></strong></div>
                </div>
                <div class="profile-detail">
                    <span class="material-icons-round">call</span>
                    <div><small>Phone</small><strong><?= e($user['phone'] ?: 'Not provided') ?></strong></div>
                </div>
                <div class="profile-detail">
                    <span class="material-icons-round">alternate_email</span>
                    <div><small>Username</small><strong><?= e($user['username']) ?></strong></div>
                </div>
                <div class="profile-detail">
                    <span class="material-icons-round">login</span>
                    <div><small>Last sign in</small><strong><?= e($user['last_login'] ? format_date($user['last_login'], 'M j, Y g:i A') : 'First session') ?></strong></div>
                </div>
                <div class="profile-detail">
                    <span class="material-icons-round">calendar_today</span>
                    <div><small>Member since</small><strong><?= e(format_date($user['created_at'])) ?></strong></div>
                </div>

                <?php if ($user['role'] === 'student' && $extra): ?>
                    <div class="profile-detail">
                        <span class="material-icons-round">badge</span>
                        <div><small>Student number</small><strong><?= e($extra['student_no']) ?></strong></div>
                    </div>
                    <div class="profile-detail">
                        <span class="material-icons-round">class</span>
                        <div><small>Class</small><strong><?= e($extra['class_name'] ?: 'Unassigned') ?></strong></div>
                    </div>
                    <div class="profile-detail">
                        <span class="material-icons-round">family_restroom</span>
                        <div><small>Guardian</small><strong><?= e($extra['guardian_name'] ?: 'Not provided') ?></strong></div>
                    </div>
                <?php elseif ($user['role'] === 'teacher'): ?>
                    <div class="profile-detail">
                        <span class="material-icons-round">class</span>
                        <div><small>Assigned classes</small><strong><?= (int) ($extra['classes'] ?? 0) ?></strong></div>
                    </div>
                    <div class="profile-detail">
                        <span class="material-icons-round">menu_book</span>
                        <div><small>Subject loads</small><strong><?= (int) ($extra['subjects'] ?? 0) ?></strong></div>
                    </div>
                    <div class="profile-detail">
                        <span class="material-icons-round">groups</span>
                        <div><small>Students handled</small><strong><?= (int) ($extra['students'] ?? 0) ?></strong></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card-head">
                <div>
                    <h2>Personal information</h2>
                    <p>Keep your contact details up to date.</p>
                </div>
                <span class="material-icons-round">person</span>
            </div>

            <?php if ($profileErrors): ?>
                <div class="alert alert-error">
                    <span class="material-icons-round">error</span>
                    <span><?= e(implode(' ', $profileErrors)) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('profile.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="profile">

                <div class="form-grid">
                    <div class="field span-2">
                        <input type="text" id="full_name" name="full_name" placeholder=" " required
                               value="<?= e($user['full_name']) ?>">
                        <label for="full_name">Full name</label>
                    </div>
                    <div class="field">
                        <input type="email" id="email" name="email" placeholder=" " required
                               value="<?= e($user['email']) ?>">
                        <label for="email">Email address</label>
                    </div>
                    <div class="field">
                        <input type="text" id="phone" name="phone" placeholder=" "
                               value="<?= e($user['phone'] ?? '') ?>">
                        <label for="phone">Phone number</label>
                    </div>
                </div>

                <div class="form-actions mt-24">
                    <button class="btn btn-filled" type="submit">
                        <span class="material-icons-round">save</span>
                        Save changes
                    </button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-head">
                <div>
                    <h2>Change password</h2>
                    <p>Use at least 8 characters for a stronger password.</p>
                </div>
                <span class="material-icons-round">lock</span>
            </div>

            <?php if ($passwordErrors): ?>
                <div class="alert alert-error">
                    <span class="material-icons-round">error</span>
                    <span><?= e(implode(' ', $passwordErrors)) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('profile.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">

                <div class="form-grid">
                    <div class="field span-2">
                        <input type="password" id="current_password" name="current_password" placeholder=" " required
                               autocomplete="current-password">
                        <label for="current_password">Current password</label>
                    </div>
                    <div class="field">
                        <input type="password" id="new_password" name="new_password" placeholder=" " required
                               minlength="8" autocomplete="new-password">
                        <label for="new_password">New password</label>
                    </div>
                    <div class="field">
                        <input type="password" id="confirm_password" name="confirm_password" placeholder=" " required
                               minlength="8" autocomplete="new-password">
                        <label for="confirm_password">Confirm new password</label>
                    </div>
                </div>

                <div class="form-actions mt-24">
                    <button class="btn btn-tonal" type="submit">
                        <span class="material-icons-round">password</span>
                        Update password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
