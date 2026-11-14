<?php

declare(strict_types=1);

$role = user_role();
$sidebarUser = current_user();
$activeNav = $activeNav ?? '';

$navCounts = ['students' => null, 'teachers' => null, 'classes' => null, 'assignments' => null];

try {
    $pdo = db();
    if ($role === 'admin') {
        $row = $pdo->query(
            "SELECT
                (SELECT COUNT(*) FROM students WHERE status = 'enrolled') AS students,
                (SELECT COUNT(*) FROM users WHERE role = 'teacher' AND status = 'active') AS teachers,
                (SELECT COUNT(*) FROM classes WHERE status = 'active') AS classes,
                (SELECT COUNT(*) FROM class_subjects) AS assignments"
        )->fetch();
        $navCounts = array_merge($navCounts, array_map('intval', $row ?: []));
    } elseif ($role === 'teacher') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM class_subjects WHERE teacher_id = ?');
        $stmt->execute([$sidebarUser['id']]);
        $navCounts['assignments'] = (int) $stmt->fetchColumn();
    }
} catch (Throwable $e) {
    /* Navigation should still render even if a count query fails. */
}

function render_nav_item(string $key, string $label, string $icon, ?int $count = null, string $active = ''): void
{
    $isActive = $active === $key;
    ?>
    <a class="nav-link<?= $isActive ? ' active' : '' ?>" href="<?= e(url($key === 'dashboard' ? user_home() : user_role() . '/' . $key . '.php')) ?>">
        <span class="material-icons-round"><?= e($icon) ?></span>
        <span><?= e($label) ?></span>
        <?php if ($count !== null): ?>
            <span class="nav-count"><?= (int) $count ?></span>
        <?php endif; ?>
    </a>
    <?php
}
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-scroll">
        <?php if ($role === 'admin'): ?>
            <div class="nav-label">Overview</div>
            <?php render_nav_item('dashboard', 'Dashboard', 'dashboard', null, $activeNav); ?>

            <div class="nav-label">Management</div>
            <?php render_nav_item('students', 'Students', 'groups', $navCounts['students'], $activeNav); ?>
            <?php render_nav_item('teachers', 'Teachers', 'person', $navCounts['teachers'], $activeNav); ?>
            <?php render_nav_item('classes', 'Classes', 'class', $navCounts['classes'], $activeNav); ?>
            <?php render_nav_item('subjects', 'Subjects', 'menu_book', null, $activeNav); ?>
            <?php render_nav_item('assignments', 'Assignments', 'assignment_ind', $navCounts['assignments'], $activeNav); ?>

            <div class="nav-label">Attendance</div>
            <?php render_nav_item('attendance', 'Mark Attendance', 'fact_check', null, $activeNav); ?>
            <?php render_nav_item('reports', 'Reports', 'assessment', null, $activeNav); ?>

        <?php elseif ($role === 'teacher'): ?>
            <div class="nav-label">Overview</div>
            <?php render_nav_item('dashboard', 'Dashboard', 'dashboard', null, $activeNav); ?>

            <div class="nav-label">Teaching</div>
            <?php render_nav_item('attendance', 'Mark Attendance', 'fact_check', null, $activeNav); ?>
            <?php render_nav_item('students', 'My Students', 'groups', null, $activeNav); ?>
            <?php render_nav_item('reports', 'Reports', 'assessment', null, $activeNav); ?>

        <?php else: ?>
            <div class="nav-label">Overview</div>
            <?php render_nav_item('dashboard', 'Dashboard', 'dashboard', null, $activeNav); ?>

            <div class="nav-label">My records</div>
            <?php render_nav_item('attendance', 'My Attendance', 'fact_check', null, $activeNav); ?>
        <?php endif; ?>

        <div class="nav-label">Account</div>
        <a class="nav-link<?= $activeNav === 'profile' ? ' active' : '' ?>" href="<?= e(url('profile.php')) ?>">
            <span class="material-icons-round">manage_accounts</span>
            <span>My Profile</span>
        </a>
    </div>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <span class="avatar sm" style="background: <?= e(avatar_color($sidebarUser['avatar_color'] ?? null)) ?>">
                <?= e(initials($sidebarUser['full_name'] ?? 'User')) ?>
            </span>
            <div class="sidebar-user-meta">
                <strong><?= e($sidebarUser['full_name'] ?? 'User') ?></strong>
                <span><?= e(role_label($role)) ?> · <?= e(current_school_year()) ?></span>
            </div>
        </div>
    </div>
</aside>
