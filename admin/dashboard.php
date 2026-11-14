<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

$pdo = db();

/* Core counters */
$counters = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM students WHERE status = 'enrolled') AS students,
        (SELECT COUNT(*) FROM users WHERE role = 'teacher' AND status = 'active') AS teachers,
        (SELECT COUNT(*) FROM classes WHERE status = 'active') AS classes,
        (SELECT COUNT(*) FROM class_subjects) AS assignments"
)->fetch();

/* Today's attendance breakdown */
$todayCounts = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
$stmt = $pdo->query("SELECT status, COUNT(*) AS total FROM attendance WHERE attendance_date = CURDATE() GROUP BY status");
foreach ($stmt->fetchAll() as $row) {
    if (isset($todayCounts[$row['status']])) {
        $todayCounts[$row['status']] = (int) $row['total'];
    }
}
$todayTotal = array_sum($todayCounts);
$todayRate = attendance_rate($todayCounts['present'], $todayCounts['late'], $todayCounts['absent'], $todayCounts['excused']);

/* Seven day trend */
$trend = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime('-' . $i . ' days'));
    $trend[$day] = [
        'label' => date('D', strtotime($day)),
        'rate'  => 0.0,
        'total' => 0,
    ];
}

$stmt = $pdo->prepare(
    "SELECT attendance_date,
            COUNT(*) AS total,
            SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) AS attended
     FROM attendance
     WHERE attendance_date BETWEEN ? AND ?
     GROUP BY attendance_date"
);
$stmt->execute([array_key_first($trend), array_key_last($trend)]);
foreach ($stmt->fetchAll() as $row) {
    $day = $row['attendance_date'];
    if (isset($trend[$day])) {
        $total = (int) $row['total'];
        $trend[$day]['total'] = $total;
        $trend[$day]['rate'] = $total > 0 ? round(((int) $row['attended'] / $total) * 100, 1) : 0.0;
    }
}

$weekTotal = array_sum(array_column($trend, 'total'));
$weekAttended = 0;
foreach ($trend as $day) {
    $weekAttended += (int) round(($day['rate'] / 100) * $day['total']);
}
$weekRate = $weekTotal > 0 ? round(($weekAttended / $weekTotal) * 100, 1) : 0.0;

/* Class attendance today */
$classRows = $pdo->query(
    "SELECT c.id, c.name, c.grade_level, c.section,
            COUNT(a.id) AS total,
            SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) AS attended
     FROM classes c
     LEFT JOIN class_subjects cs ON cs.class_id = c.id
     LEFT JOIN attendance a ON a.class_subject_id = cs.id AND a.attendance_date = CURDATE()
     WHERE c.status = 'active'
     GROUP BY c.id, c.name, c.grade_level, c.section
     ORDER BY c.grade_level, c.section"
)->fetchAll();

/* Students below 90% in last 30 days */
$atRisk = $pdo->query(
    "SELECT u.full_name, u.avatar_color, s.student_no, c.name AS class_name,
            COUNT(a.id) AS total,
            SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) AS attended
     FROM students s
     JOIN users u ON u.id = s.user_id
     LEFT JOIN classes c ON c.id = s.class_id
     JOIN attendance a ON a.student_id = s.id
     WHERE s.status = 'enrolled'
       AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY s.id, u.full_name, u.avatar_color, s.student_no, c.name
     HAVING COUNT(a.id) > 0
        AND (SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) / COUNT(a.id)) < 0.90
     ORDER BY (SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) / COUNT(a.id)) ASC
     LIMIT 5"
)->fetchAll();

/* Recent attendance activity */
$recent = $pdo->query(
    "SELECT a.status, a.attendance_date, a.updated_at,
            u.full_name, u.avatar_color, s.student_no,
            sub.name AS subject_name, c.name AS class_name
     FROM attendance a
     JOIN students s ON s.id = a.student_id
     JOIN users u ON u.id = s.user_id
     JOIN class_subjects cs ON cs.id = a.class_subject_id
     JOIN subjects sub ON sub.id = cs.subject_id
     JOIN classes c ON c.id = cs.class_id
     ORDER BY a.updated_at DESC, a.id DESC
     LIMIT 6"
)->fetchAll();

$firstName = explode(' ', trim((string) current_user()['full_name']))[0];
$pageTitle = 'Dashboard';
$pageSubtitle = 'School-wide attendance overview.';
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
        <p>Here is what is happening across <?= e(SCHOOL_NAME) ?> today.</p>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-outlined" href="<?= e(url('admin/reports.php')) ?>">
            <span class="material-icons-round">assessment</span>
            Reports
        </a>
        <a class="btn btn-filled" href="<?= e(url('admin/attendance.php')) ?>">
            <span class="material-icons-round">fact_check</span>
            Mark attendance
        </a>
    </div>
</div>

<div class="grid grid-4 mb-24">
    <div class="card stat-card tone-blue">
        <div class="stat-top">
            <span class="stat-icon"><span class="material-icons-round">groups</span></span>
            <span class="stat-trend up"><span class="material-icons-round">verified</span>Enrolled</span>
        </div>
        <div>
            <div class="stat-value"><?= (int) $counters['students'] ?></div>
            <div class="stat-label">Total students</div>
        </div>
    </div>

    <div class="card stat-card tone-green">
        <div class="stat-top">
            <span class="stat-icon"><span class="material-icons-round">person</span></span>
            <span class="stat-trend up"><span class="material-icons-round">check_circle</span>Active</span>
        </div>
        <div>
            <div class="stat-value"><?= (int) $counters['teachers'] ?></div>
            <div class="stat-label">Faculty members</div>
        </div>
    </div>

    <div class="card stat-card tone-purple">
        <div class="stat-top">
            <span class="stat-icon"><span class="material-icons-round">class</span></span>
            <span class="stat-trend"><span class="material-icons-round">calendar_today</span><?= e(current_school_year()) ?></span>
        </div>
        <div>
            <div class="stat-value"><?= (int) $counters['classes'] ?></div>
            <div class="stat-label">Active classes</div>
        </div>
    </div>

    <div class="card stat-card tone-amber">
        <div class="stat-top">
            <span class="stat-icon"><span class="material-icons-round">fact_check</span></span>
            <span class="stat-trend <?= $todayRate >= 90 ? 'up' : ($todayRate < 75 ? 'down' : '') ?>">
                <span class="material-icons-round"><?= $todayRate >= 90 ? 'trending_up' : ($todayRate < 75 ? 'trending_down' : 'remove') ?></span>
                <?= $todayTotal ?> records
            </span>
        </div>
        <div>
            <div class="stat-value"><?= number_format($todayRate, 1) ?>%</div>
            <div class="stat-label">Attendance today</div>
        </div>
    </div>
</div>

<div class="grid grid-2-1 mb-24">
    <div class="card">
        <div class="card-head">
            <div>
                <h2>Attendance trend</h2>
                <p>Daily attendance rate over the last 7 days</p>
            </div>
            <span class="chip <?= $weekRate >= 90 ? 'chip-present' : ($weekRate >= 75 ? 'chip-late' : 'chip-absent') ?>">
                <span class="material-icons-round">insights</span>
                <?= number_format($weekRate, 1) ?>% avg
            </span>
        </div>

        <div class="chart-bars" role="img" aria-label="Attendance rate for the last seven days">
            <?php foreach ($trend as $day): ?>
                <?php $height = $day['total'] > 0 ? max(3, min(100, $day['rate'])) : 2; ?>
                <div class="bar-col" title="<?= e($day['label']) ?>: <?= number_format($day['rate'], 1) ?>% (<?= (int) $day['total'] ?> records)">
                    <span class="bar-value"><?= $day['total'] > 0 ? number_format($day['rate'], 0) . '%' : '—' ?></span>
                    <div class="bar-track">
                        <div class="bar-fill<?= $day['total'] > 0 ? '' : ' muted' ?>" style="--h: <?= $height ?>%"></div>
                    </div>
                    <span class="bar-label"><?= e($day['label']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row-between mt-24" style="padding-top:16px;border-top:1px solid var(--outline)">
            <div class="row text-sm text-muted">
                <span class="material-icons-round" style="font-size:18px">fact_check</span>
                <?= $todayTotal ?> attendance records today
            </div>
            <div class="row text-sm text-muted">
                <span class="material-icons-round" style="font-size:18px">assignment</span>
                <?= (int) $counters['assignments'] ?> subject loads
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h2>Today's breakdown</h2>
                <p>All subject sessions</p>
            </div>
        </div>

        <div class="chart-flex">
            <?php
            $donutColor = $todayRate >= 90 ? 'var(--success)' : ($todayRate >= 75 ? 'var(--warning-solid)' : 'var(--danger)');
            ?>
            <div class="donut-wrap">
                <div class="donut" style="--p: <?= $todayRate ?>; --donut-color: <?= $donutColor ?>">
                    <div class="donut-center">
                        <strong><?= number_format($todayRate, 1) ?>%</strong>
                        <span>Present</span>
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
            <span class="material-icons-round"><?= $todayRate >= 90 ? 'verified' : 'notification_important' ?></span>
            <div>
                <strong><?= $todayRate >= 90 ? 'Attendance is on track' : 'Attendance needs attention' ?></strong>
                <span><?= $todayRate >= 90 ? 'The school is meeting its daily attendance target.' : 'Consider following up with the classes below.' ?></span>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-2-1 mb-24">
    <div class="card">
        <div class="card-head">
            <div>
                <h2>Class attendance today</h2>
                <p>Present and late students across each class</p>
            </div>
            <a class="btn btn-text btn-sm" href="<?= e(url('admin/classes.php')) ?>">Manage classes</a>
        </div>

        <div class="subject-bars">
            <?php foreach ($classRows as $class):
                $total = (int) $class['total'];
                $attended = (int) $class['attended'];
                $rate = $total > 0 ? round(($attended / $total) * 100, 1) : 0.0;
            ?>
                <div>
                    <div class="subject-bar-head">
                        <span><?= e($class['name']) ?></span>
                        <strong><?= $total > 0 ? number_format($rate, 1) . '%' : 'No records' ?></strong>
                    </div>
                    <div class="progress <?= $total > 0 ? rate_class($rate) : '' ?>">
                        <span style="width: <?= $total > 0 ? max(2, $rate) : 0 ?>%"></span>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (!$classRows): ?>
                <div class="empty-state">
                    <span class="material-icons-round">class</span>
                    <strong>No classes yet</strong>
                    <p>Create a class to start tracking attendance.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

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
                        <span><?= e($student['class_name'] ?: $student['student_no']) ?></span>
                    </div>
                    <span class="chip <?= $rate >= 75 ? 'chip-late' : 'chip-absent' ?>"><?= number_format($rate, 1) ?>%</span>
                </div>
            <?php endforeach; ?>

            <?php if (!$atRisk): ?>
                <div class="empty-state">
                    <span class="material-icons-round">emoji_events</span>
                    <strong>Everyone is on track</strong>
                    <p>No student is below the 90% attendance threshold.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="table-card">
    <div class="table-toolbar">
        <div class="table-toolbar-title">
            <span class="material-icons-round">history</span>
            Recent attendance activity
        </div>
        <a class="btn btn-text btn-sm" href="<?= e(url('admin/reports.php')) ?>">
            View reports
            <span class="material-icons-round">arrow_forward</span>
        </a>
    </div>

    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Class</th>
                    <th>Subject</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td>
                            <div class="cell-main">
                                <span class="avatar sm" style="background: <?= e(avatar_color($row['avatar_color'])) ?>">
                                    <?= e(initials($row['full_name'])) ?>
                                </span>
                                <div class="cell-meta">
                                    <strong><?= e($row['full_name']) ?></strong>
                                    <span><?= e($row['student_no']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td><?= e($row['class_name']) ?></td>
                        <td><?= e($row['subject_name']) ?></td>
                        <td><?= e(format_date($row['attendance_date'])) ?></td>
                        <td><?= status_chip($row['status']) ?></td>
                        <td class="text-sm text-tertiary"><?= e(time_ago($row['updated_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$recent): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state" style="padding:32px 20px">
                                <span class="material-icons-round">history</span>
                                <strong>No activity yet</strong>
                                <p>Attendance records will appear here once teachers start marking.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
