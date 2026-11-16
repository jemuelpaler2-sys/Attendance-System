<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';

require_role('teacher');

$pdo = db();
$user = current_user();
$teacherId = (int) $user['id'];
$isNoClassToday = is_weekend(date('Y-m-d'));

$assignments = attendance_class_subjects($pdo, $teacherId);

/* Today's marked/attended counts per assignment */
$todayByCs = [];
$stmt = $pdo->prepare(
    "SELECT cs.id,
            (SELECT COUNT(*) FROM attendance a
              WHERE a.class_subject_id = cs.id AND a.attendance_date = CURDATE()
                AND " . attendance_school_day_sql('a.attendance_date') . ") AS marked,
            (SELECT COUNT(*) FROM attendance a
              WHERE a.class_subject_id = cs.id AND a.attendance_date = CURDATE()
                AND a.status IN ('present','late')
                AND " . attendance_school_day_sql('a.attendance_date') . ") AS attended
     FROM class_subjects cs
     WHERE cs.teacher_id = ?"
);
$stmt->execute([$teacherId]);
foreach ($stmt->fetchAll() as $row) {
    $todayByCs[(int) $row['id']] = ['marked' => (int) $row['marked'], 'attended' => (int) $row['attended']];
}

$distinctClasses = count(array_unique(array_map(static fn ($a) => (int) $a['class_id'], $assignments)));
$loads = count($assignments);

$stmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT s.id)
     FROM students s
     JOIN class_subjects cs ON cs.class_id = s.class_id
     WHERE cs.teacher_id = ? AND s.status = 'enrolled'"
);
$stmt->execute([$teacherId]);
$studentTotal = (int) $stmt->fetchColumn();

/* Today's status breakdown for this teacher */
$todayCounts = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
$stmt = $pdo->prepare(
    'SELECT a.status, COUNT(*) AS total
     FROM attendance a
     JOIN class_subjects cs ON cs.id = a.class_subject_id
     WHERE cs.teacher_id = ? AND a.attendance_date = CURDATE()
       AND ' . attendance_school_day_sql('a.attendance_date') . '
     GROUP BY a.status'
);
$stmt->execute([$teacherId]);
foreach ($stmt->fetchAll() as $row) {
    if (isset($todayCounts[$row['status']])) {
        $todayCounts[$row['status']] = (int) $row['total'];
    }
}
$todayTotal = array_sum($todayCounts);
$todayRate = attendance_rate($todayCounts['present'], $todayCounts['late'], $todayCounts['absent'], $todayCounts['excused']);
$sessionsMarked = 0;
foreach ($todayByCs as $entry) {
    if ($entry['marked'] > 0) {
        $sessionsMarked++;
    }
}

/* 7-day trend for this teacher */
$trend = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime('-' . $i . ' days'));
    $trend[$day] = ['label' => date('D', strtotime($day)), 'rate' => 0.0, 'total' => 0, 'no_class' => is_weekend($day)];
}

$stmt = $pdo->prepare(
    "SELECT a.attendance_date, COUNT(*) AS total,
            SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) AS attended
     FROM attendance a
     JOIN class_subjects cs ON cs.id = a.class_subject_id
     WHERE cs.teacher_id = ? AND a.attendance_date BETWEEN ? AND ?
       AND " . attendance_school_day_sql('a.attendance_date') . "
     GROUP BY a.attendance_date"
);
$stmt->execute([$teacherId, array_key_first($trend), array_key_last($trend)]);
foreach ($stmt->fetchAll() as $row) {
    if (isset($trend[$row['attendance_date']])) {
        $total = (int) $row['total'];
        $trend[$row['attendance_date']]['total'] = $total;
        $trend[$row['attendance_date']]['rate'] = $total > 0 ? round(((int) $row['attended'] / $total) * 100, 1) : 0.0;
    }
}

$weekTotal = array_sum(array_column($trend, 'total'));
$weekAttended = 0;
foreach ($trend as $day) {
    $weekAttended += (int) round(($day['rate'] / 100) * $day['total']);
}
$weekRate = $weekTotal > 0 ? round(($weekAttended / $weekTotal) * 100, 1) : 0.0;

/* Students below 90% in this teacher's classes over 30 days */
$stmt = $pdo->prepare(
    "SELECT u.full_name, u.avatar_color, s.student_no, c.name AS class_name,
            COUNT(DISTINCT a.id) AS total,
            SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) AS attended
     FROM students s
     JOIN users u ON u.id = s.user_id
     JOIN classes c ON c.id = s.class_id
     JOIN class_subjects cs ON cs.class_id = s.class_id AND cs.teacher_id = ?
     JOIN attendance a ON a.student_id = s.id AND a.class_subject_id = cs.id
     WHERE s.status = 'enrolled'
       AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
       AND " . attendance_school_day_sql('a.attendance_date') . "
     GROUP BY s.id, u.full_name, u.avatar_color, s.student_no, c.name
     HAVING COUNT(DISTINCT a.id) > 0
        AND (SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) / COUNT(DISTINCT a.id)) < 0.90
     ORDER BY (SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) / COUNT(DISTINCT a.id)) ASC
     LIMIT 5"
);
$stmt->execute([$teacherId]);
$atRisk = $stmt->fetchAll();

/* Recent activity in this teacher's classes */
$stmt = $pdo->prepare(
    "SELECT a.status, a.attendance_date, a.updated_at,
            u.full_name, u.avatar_color, s.student_no,
            sub.name AS subject_name, c.name AS class_name
     FROM attendance a
     JOIN class_subjects cs ON cs.id = a.class_subject_id
     JOIN students s ON s.id = a.student_id
     JOIN users u ON u.id = s.user_id
     JOIN subjects sub ON sub.id = cs.subject_id
     JOIN classes c ON c.id = cs.class_id
     WHERE cs.teacher_id = ?
       AND " . attendance_school_day_sql('a.attendance_date') . "
     ORDER BY a.updated_at DESC, a.id DESC
     LIMIT 6"
);
$stmt->execute([$teacherId]);
$recent = $stmt->fetchAll();

$firstName = explode(' ', trim((string) $user['full_name']))[0];
$pageTitle = 'Dashboard';
$pageSubtitle = 'Your teaching and attendance snapshot.';
$activeNav = 'dashboard';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div class="page-head-text">
        <span class="greeting-eyebrow">
            <span class="material-icons-round" style="font-size:15px">auto_awesome</span>
            <?= e(format_date(date('Y-m-d'), 'l, F j, Y')) ?>
        </span>
        <h1><?= e(greeting()) ?>, <?= e($firstName) ?>.</h1>
        <p>Here is your teaching and attendance snapshot for today.</p>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-outlined" href="<?= e(url('teacher/reports.php')) ?>">
            <span class="material-icons-round">assessment</span>
            Reports
        </a>
        <a class="btn btn-filled" href="<?= e(url('teacher/attendance.php')) ?>">
            <span class="material-icons-round">fact_check</span>
            Mark attendance
        </a>
    </div>
</div>

<div class="grid grid-4 mb-24">
    <div class="card stat-card tone-blue">
        <div class="stat-top">
            <span class="stat-icon"><span class="material-icons-round">class</span></span>
            <span class="stat-trend up"><span class="material-icons-round">menu_book</span><?= $loads ?> loads</span>
        </div>
        <div>
            <div class="stat-value"><?= $distinctClasses ?></div>
            <div class="stat-label">Classes handled</div>
        </div>
    </div>

    <div class="card stat-card tone-purple">
        <div class="stat-top">
            <span class="stat-icon"><span class="material-icons-round">menu_book</span></span>
        </div>
        <div>
            <div class="stat-value"><?= $loads ?></div>
            <div class="stat-label">Subject loads</div>
        </div>
    </div>

    <div class="card stat-card tone-green">
        <div class="stat-top">
            <span class="stat-icon"><span class="material-icons-round">groups</span></span>
        </div>
        <div>
            <div class="stat-value"><?= $studentTotal ?></div>
            <div class="stat-label">Students handled</div>
        </div>
    </div>

    <div class="card stat-card tone-amber">
        <div class="stat-top">
            <span class="stat-icon"><span class="material-icons-round">fact_check</span></span>
            <span class="stat-trend <?= $todayRate >= 90 ? 'up' : ($todayRate < 75 ? 'down' : '') ?>">
                <span class="material-icons-round"><?= $todayRate >= 90 ? 'trending_up' : 'remove' ?></span>
                <?= $todayTotal ?> records
            </span>
        </div>
        <div>
            <div class="stat-value"><?= $isNoClassToday ? 'No Class' : number_format($todayRate, 1) . '%' ?></div>
            <div class="stat-label"><?= $isNoClassToday ? 'Weekend — no classes' : "Today's attendance" ?></div>
        </div>
    </div>
</div>

<div class="grid grid-2-1 mb-24">
    <div class="card">
        <div class="card-head">
            <div>
                <h2>Your attendance trend</h2>
                <p>Daily attendance rate across your classes</p>
            </div>
            <span class="chip <?= $weekRate >= 90 ? 'chip-present' : ($weekRate >= 75 ? 'chip-late' : 'chip-absent') ?>">
                <span class="material-icons-round">insights</span>
                <?= number_format($weekRate, 1) ?>% avg
            </span>
        </div>

        <div class="chart-bars" role="img" aria-label="Your attendance rate for the last seven days">
            <?php foreach ($trend as $day):
                $dayNoClass = !empty($day['no_class']);
                $height = $day['total'] > 0 ? max(3, min(100, $day['rate'])) : 2;
                $title = $dayNoClass ? $day['label'] . ': No Class' : $day['label'] . ': ' . number_format($day['rate'], 1) . '%';
            ?>
                <div class="bar-col" title="<?= e($title) ?>">
                    <span class="bar-value"<?= $dayNoClass ? ' style="font-size:10px;white-space:nowrap"' : '' ?>><?= $dayNoClass ? 'No Class' : ($day['total'] > 0 ? number_format($day['rate'], 0) . '%' : '—') ?></span>
                    <div class="bar-track">
                        <div class="bar-fill<?= $day['total'] > 0 ? '' : ' muted' ?>" style="--h: <?= $height ?>%"></div>
                    </div>
                    <span class="bar-label"><?= e($day['label']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row-between mt-24" style="padding-top:16px;border-top:1px solid var(--outline)">
            <span class="text-sm text-muted"><?= $isNoClassToday ? 'No Class today' : $sessionsMarked . ' of ' . $loads . ' sessions marked today' ?></span>
            <a class="btn btn-text btn-sm" href="<?= e(url('teacher/attendance.php')) ?>">Open attendance</a>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h2>Today's breakdown</h2>
                <p><?= $isNoClassToday ? 'Weekend — no classes' : 'Across your subject loads' ?></p>
            </div>
        </div>

        <div class="chart-flex">
            <?php
            $donutColor = $todayRate >= 90 ? 'var(--success)' : ($todayRate >= 75 ? 'var(--warning-solid)' : 'var(--danger)');
            ?>
            <div class="donut-wrap">
                <div class="donut" style="--p: <?= $todayRate ?>; --donut-color: <?= $donutColor ?>">
                    <div class="donut-center">
                        <strong><?= $isNoClassToday ? 'No Class' : number_format($todayRate, 1) . '%' ?></strong>
                        <span><?= $isNoClassToday ? 'Weekend' : 'Present' ?></span>
                    </div>
                </div>
            </div>
            <div class="legend">
                <div class="legend-item"><span class="badge-dot present"></span><span class="label">Present</span><strong><?= $todayCounts['present'] ?></strong></div>
                <div class="legend-item"><span class="badge-dot late"></span><span class="label">Late</span><strong><?= $todayCounts['late'] ?></strong></div>
                <div class="legend-item"><span class="badge-dot absent"></span><span class="label">Absent</span><strong><?= $todayCounts['absent'] ?></strong></div>
                <div class="legend-item"><span class="badge-dot excused"></span><span class="label">Excused</span><strong><?= $todayCounts['excused'] ?></strong></div>
            </div>
        </div>

        <div class="info-banner mt-24">
            <span class="material-icons-round"><?= $isNoClassToday ? 'event_busy' : ($sessionsMarked >= $loads && $loads > 0 ? 'verified' : 'pending_actions') ?></span>
            <div>
                <strong><?= $isNoClassToday ? 'No Class today' : ($sessionsMarked >= $loads && $loads > 0 ? 'All sessions marked' : 'Sessions still pending') ?></strong>
                <span><?= $isNoClassToday ? 'Saturdays and Sundays are not school days. Attendance resumes on the next school day.' : ($sessionsMarked >= $loads && $loads > 0 ? 'Great job keeping attendance up to date.' : 'Complete today’s attendance for your remaining classes.') ?></span>
            </div>
        </div>
    </div>
</div>

<div class="table-card mb-24">
    <div class="table-toolbar">
        <div class="table-toolbar-title">
            <span class="material-icons-round">class</span>
            My classes
            <span class="count"><?= count($assignments) ?> loads</span>
        </div>
        <a class="btn btn-text btn-sm" href="<?= e(url('teacher/students.php')) ?>">
            View all students
            <span class="material-icons-round">arrow_forward</span>
        </a>
    </div>

    <div class="table-scroll">
        <table class="data-table" style="min-width: 820px">
            <thead>
                <tr>
                    <th>Class</th>
                    <th>Subject</th>
                    <th>Students</th>
                    <th>Today's status</th>
                    <th>Today's rate</th>
                    <th style="text-align:right">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assignments as $assignment):
                    $info = $todayByCs[(int) $assignment['id']] ?? ['marked' => 0, 'attended' => 0];
                    $rate = $info['marked'] > 0 ? round(($info['attended'] / $info['marked']) * 100, 1) : 0.0;
                ?>
                    <tr>
                        <td>
                            <div class="cell-meta">
                                <strong><?= e($assignment['class_name']) ?></strong>
                                <span><?= e($assignment['grade_level'] . ' · ' . $assignment['section']) ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="row" style="gap:8px">
                                <span class="chip chip-role" style="font-family:monospace"><?= e($assignment['subject_code']) ?></span>
                                <?= e($assignment['subject_name']) ?>
                            </div>
                        </td>
                        <td><?= (int) $assignment['student_count'] ?></td>
                        <td>
                            <?php if ($isNoClassToday): ?>
                                <span class="chip chip-muted">
                                    <span class="material-icons-round">event_busy</span>
                                    No Class
                                </span>
                            <?php elseif ($info['marked'] > 0): ?>
                                <span class="chip chip-present">
                                    <span class="material-icons-round">check_circle</span>
                                    Marked
                                </span>
                            <?php else: ?>
                                <span class="chip chip-late">
                                    <span class="material-icons-round">pending</span>
                                    Not marked
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isNoClassToday): ?>
                                <span class="chip chip-muted">No Class</span>
                            <?php elseif ($info['marked'] > 0): ?>
                                <span class="chip <?= $rate >= 90 ? 'chip-present' : ($rate >= 75 ? 'chip-late' : 'chip-absent') ?>">
                                    <?= number_format($rate, 1) ?>%
                                </span>
                            <?php else: ?>
                                <span class="text-sm text-tertiary">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="table-actions">
                                <a class="btn btn-tonal btn-sm"
                                   href="<?= e(query_url('teacher/attendance.php', ['class_subject' => (int) $assignment['id'], 'date' => date('Y-m-d')])) ?>">
                                    <span class="material-icons-round">fact_check</span>
                                    Mark
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$assignments): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <span class="material-icons-round">assignment_ind</span>
                                <strong>No subject loads yet</strong>
                                <p>Ask an administrator to assign you to a class and subject.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head">
            <div>
                <h2>Needs attention</h2>
                <p>Below 90% in the last 30 days</p>
            </div>
            <span class="material-icons-round">health_and_safety</span>
        </div>

        <div class="list-card">
            <?php foreach ($atRisk as $student):
                $rate = ((int) $student['total']) > 0 ? round(((int) $student['attended'] / (int) $student['total']) * 100, 1) : 0.0;
            ?>
                <div class="list-row">
                    <span class="avatar sm" style="background: <?= e(avatar_color($student['avatar_color'])) ?>">
                        <?= e(initials($student['full_name'])) ?>
                    </span>
                    <div class="cell-meta">
                        <strong><?= e($student['full_name']) ?></strong>
                        <span><?= e($student['class_name']) ?> · <?= e($student['student_no']) ?></span>
                    </div>
                    <span class="chip <?= $rate >= 75 ? 'chip-late' : 'chip-absent' ?>"><?= number_format($rate, 1) ?>%</span>
                </div>
            <?php endforeach; ?>

            <?php if (!$atRisk): ?>
                <div class="empty-state">
                    <span class="material-icons-round">emoji_events</span>
                    <strong>Everyone is on track</strong>
                    <p>No student in your classes is below 90% attendance.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h2>Recent activity</h2>
                <p>Latest attendance updates in your classes</p>
            </div>
            <span class="material-icons-round">history</span>
        </div>

        <div class="activity-list">
            <?php
            $toneMap = ['present' => 'tone-green', 'late' => 'tone-amber', 'absent' => 'tone-red', 'excused' => 'tone-blue'];
            $iconMap = ['present' => 'check_circle', 'late' => 'schedule', 'absent' => 'cancel', 'excused' => 'info'];
            ?>
            <?php foreach ($recent as $row): ?>
                <div class="activity-item">
                    <span class="activity-icon <?= $toneMap[$row['status']] ?? '' ?>">
                        <span class="material-icons-round"><?= $iconMap[$row['status']] ?? 'history' ?></span>
                    </span>
                    <div class="activity-body">
                        <strong><?= e($row['full_name']) ?> · <?= e(status_meta($row['status'])['label']) ?></strong>
                        <span><?= e($row['class_name']) ?> · <?= e($row['subject_name']) ?> · <?= e(format_date($row['attendance_date'])) ?></span>
                    </div>
                    <span class="activity-time"><?= e(time_ago($row['updated_at'])) ?></span>
                </div>
            <?php endforeach; ?>

            <?php if (!$recent): ?>
                <div class="empty-state">
                    <span class="material-icons-round">history</span>
                    <strong>No activity yet</strong>
                    <p>Attendance you save will appear here.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
