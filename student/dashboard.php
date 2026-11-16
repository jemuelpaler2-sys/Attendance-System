<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_role('student');

$pdo = db();
$user = current_user();

$stmt = $pdo->prepare(
    "SELECT s.id, s.student_no, s.gender, s.guardian_name, s.guardian_phone,
            u.full_name, u.email, u.avatar_color,
            c.id AS class_id, c.name AS class_name, c.grade_level, c.section, c.school_year,
            adviser.full_name AS adviser_name
     FROM students s
     JOIN users u ON u.id = s.user_id
     LEFT JOIN classes c ON c.id = s.class_id
     LEFT JOIN users adviser ON adviser.id = c.adviser_id
     WHERE s.user_id = ? AND s.status = 'enrolled'
     LIMIT 1"
);
$stmt->execute([(int) $user['id']]);
$student = $stmt->fetch();

if (!$student) {
    flash('Your student profile could not be found. Please contact the school administrator.', 'error');
    redirect('profile.php');
}

$studentId = (int) $student['id'];

function student_status_counts(PDO $pdo, int $studentId, string $extraWhere = '', array $params = []): array
{
    $stmt = $pdo->prepare(
        "SELECT status, COUNT(*) AS total
         FROM attendance
         WHERE student_id = ? AND " . attendance_school_day_sql('attendance_date') . " {$extraWhere}
         GROUP BY status"
    );
    $stmt->execute(array_merge([$studentId], $params));

    $counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
    foreach ($stmt->fetchAll() as $row) {
        if (isset($counts[$row['status']])) {
            $counts[$row['status']] = (int) $row['total'];
        }
    }

    return $counts;
}

$counts = student_status_counts($pdo, $studentId);
$totalRecords = array_sum($counts);
$overallRate = attendance_rate($counts['present'], $counts['late'], $counts['absent'], $counts['excused']);

$monthCounts = student_status_counts(
    $pdo,
    $studentId,
    'AND attendance_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01") AND attendance_date <= LAST_DAY(CURDATE())'
);
$monthTotal = array_sum($monthCounts);
$monthRate = attendance_rate($monthCounts['present'], $monthCounts['late'], $monthCounts['absent'], $monthCounts['excused']);

/* Subject breakdown */
$stmt = $pdo->prepare(
    "SELECT sub.id, sub.code, sub.name,
            COUNT(a.id) AS total,
            COALESCE(SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END), 0) AS present,
            COALESCE(SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END), 0) AS late,
            COALESCE(SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END), 0) AS absent,
            COALESCE(SUM(CASE WHEN a.status = 'excused' THEN 1 ELSE 0 END), 0) AS excused
     FROM attendance a
     JOIN class_subjects cs ON cs.id = a.class_subject_id
     JOIN subjects sub ON sub.id = cs.subject_id
     WHERE a.student_id = ?
       AND " . attendance_school_day_sql('a.attendance_date') . "
     GROUP BY sub.id, sub.code, sub.name
     ORDER BY sub.name"
);
$stmt->execute([$studentId]);
$subjects = $stmt->fetchAll();

$focusSubjects = array_values(array_filter($subjects, static function (array $subject): bool {
    $total = (int) $subject['total'];
    $rate = attendance_rate((int) $subject['present'], (int) $subject['late'], (int) $subject['absent'], (int) $subject['excused']);
    return $total > 0 && $rate < 90;
}));

/* Last 7 days */
$trend = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime('-' . $i . ' days'));
    $trend[$day] = ['label' => date('D', strtotime($day)), 'rate' => 0.0, 'total' => 0, 'no_class' => is_weekend($day)];
}

$stmt = $pdo->prepare(
    "SELECT attendance_date, COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END), 0) AS attended
     FROM attendance
     WHERE student_id = ? AND attendance_date BETWEEN ? AND ?
       AND " . attendance_school_day_sql('attendance_date') . "
     GROUP BY attendance_date"
);
$stmt->execute([$studentId, array_key_first($trend), array_key_last($trend)]);
foreach ($stmt->fetchAll() as $row) {
    if (isset($trend[$row['attendance_date']])) {
        $total = (int) $row['total'];
        $trend[$row['attendance_date']]['total'] = $total;
        $trend[$row['attendance_date']]['rate'] = $total > 0 ? round(((int) $row['attended'] / $total) * 100, 1) : 0.0;
    }
}

/* Recent attendance */
$stmt = $pdo->prepare(
    "SELECT a.status, a.attendance_date, a.remarks,
            sub.name AS subject_name, sub.code AS subject_code,
            c.name AS class_name, t.full_name AS teacher_name
     FROM attendance a
     JOIN class_subjects cs ON cs.id = a.class_subject_id
     JOIN subjects sub ON sub.id = cs.subject_id
     JOIN classes c ON c.id = cs.class_id
     JOIN users t ON t.id = cs.teacher_id
     WHERE a.student_id = ?
       AND " . attendance_school_day_sql('a.attendance_date') . "
     ORDER BY a.attendance_date DESC, a.id DESC
     LIMIT 8"
);
$stmt->execute([$studentId]);
$recent = $stmt->fetchAll();

$firstName = explode(' ', trim((string) $student['full_name']))[0];
$pageTitle = 'My Dashboard';
$pageSubtitle = 'Your attendance at a glance.';
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
        <p>Here is your attendance summary for <?= e($student['school_year'] ?: current_school_year()) ?>.</p>
    </div>
    <div class="page-head-actions">
        <?php if ($student['class_name']): ?>
            <span class="chip chip-role">
                <span class="material-icons-round">class</span>
                <?= e($student['class_name']) ?>
            </span>
        <?php endif; ?>
        <a class="btn btn-filled" href="<?= e(url('student/attendance.php')) ?>">
            <span class="material-icons-round">history</span>
            My attendance
        </a>
    </div>
</div>

<?php if ($overallRate < 90 && $totalRecords > 0): ?>
    <div class="info-banner mb-24">
        <span class="material-icons-round">notification_important</span>
        <div>
            <strong>Keep an eye on your attendance</strong>
            <span>Your overall rate is <?= number_format($overallRate, 1) ?>%. Aim for at least 90% to stay on track.</span>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-4 mb-24">
    <div class="card stat-card tone-blue">
        <div class="stat-top">
            <span class="stat-icon"><span class="material-icons-round">percent</span></span>
            <span class="stat-trend <?= $overallRate >= 90 ? 'up' : ($overallRate < 75 ? 'down' : '') ?>">
                <span class="material-icons-round"><?= $overallRate >= 90 ? 'trending_up' : 'trending_flat' ?></span>
                <?= $totalRecords ?> records
            </span>
        </div>
        <div>
            <div class="stat-value"><?= number_format($overallRate, 1) ?>%</div>
            <div class="stat-label">Overall attendance</div>
        </div>
    </div>

    <div class="card stat-card tone-green">
        <div class="stat-top">
            <span class="stat-icon"><span class="material-icons-round">check_circle</span></span>
        </div>
        <div>
            <div class="stat-value"><?= $counts['present'] ?></div>
            <div class="stat-label">Present records</div>
        </div>
    </div>

    <div class="card stat-card tone-amber">
        <div class="stat-top">
            <span class="stat-icon"><span class="material-icons-round">schedule</span></span>
        </div>
        <div>
            <div class="stat-value"><?= $counts['late'] ?></div>
            <div class="stat-label">Late records</div>
        </div>
    </div>

    <div class="card stat-card tone-red">
        <div class="stat-top">
            <span class="stat-icon"><span class="material-icons-round">cancel</span></span>
        </div>
        <div>
            <div class="stat-value"><?= $counts['absent'] ?></div>
            <div class="stat-label">Absent records</div>
        </div>
    </div>
</div>

<div class="grid grid-2-1 mb-24">
    <div class="card">
        <div class="card-head">
            <div>
                <h2>Your last 7 days</h2>
                <p>Daily attendance across your subjects</p>
            </div>
            <span class="chip <?= $monthRate >= 90 ? 'chip-present' : ($monthRate >= 75 ? 'chip-late' : 'chip-absent') ?>">
                <span class="material-icons-round">calendar_month</span>
                <?= $monthTotal > 0 ? 'This month ' . number_format($monthRate, 1) . '%' : 'No records this month' ?>
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
            <span class="text-sm text-muted"><?= $monthTotal ?> records this month</span>
            <a class="btn btn-text btn-sm" href="<?= e(url('student/attendance.php')) ?>">View full history</a>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h2>Overall breakdown</h2>
                <p>All your attendance records</p>
            </div>
        </div>

        <div class="chart-flex">
            <?php $donutColor = $overallRate >= 90 ? 'var(--success)' : ($overallRate >= 75 ? 'var(--warning-solid)' : 'var(--danger)'); ?>
            <div class="donut-wrap">
                <div class="donut" style="--p: <?= $overallRate ?>; --donut-color: <?= $donutColor ?>">
                    <div class="donut-center">
                        <strong><?= number_format($overallRate, 1) ?>%</strong>
                        <span>Attended</span>
                    </div>
                </div>
            </div>
            <div class="legend">
                <div class="legend-item"><span class="badge-dot present"></span><span class="label">Present</span><strong><?= $counts['present'] ?></strong></div>
                <div class="legend-item"><span class="badge-dot late"></span><span class="label">Late</span><strong><?= $counts['late'] ?></strong></div>
                <div class="legend-item"><span class="badge-dot absent"></span><span class="label">Absent</span><strong><?= $counts['absent'] ?></strong></div>
                <div class="legend-item"><span class="badge-dot excused"></span><span class="label">Excused</span><strong><?= $counts['excused'] ?></strong></div>
            </div>
        </div>

        <div class="info-banner mt-24">
            <span class="material-icons-round"><?= $counts['absent'] > 0 ? 'event_note' : 'verified' ?></span>
            <div>
                <strong><?= $counts['absent'] > 0 ? $counts['absent'] . ' absent record' . ($counts['absent'] === 1 ? '' : 's') : 'No absences recorded' ?></strong>
                <span><?= $student['adviser_name'] ? 'Class adviser: ' . e($student['adviser_name']) : 'Ask your adviser if you notice a missing record.' ?></span>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head">
            <div>
                <h2>By subject</h2>
                <p>Your attendance rate per subject</p>
            </div>
            <span class="material-icons-round">menu_book</span>
        </div>

        <div class="subject-bars">
            <?php foreach ($subjects as $subject):
                $rate = attendance_rate((int) $subject['present'], (int) $subject['late'], (int) $subject['absent'], (int) $subject['excused']);
                $records = (int) $subject['total'];
            ?>
                <div>
                    <div class="subject-bar-head">
                        <span>
                            <?= e($subject['name']) ?>
                            <span class="text-xs text-tertiary" style="display:block;font-weight:400"><?= e($subject['code']) ?> · <?= $records ?> records</span>
                        </span>
                        <strong><?= $records > 0 ? number_format($rate, 1) . '%' : '—' ?></strong>
                    </div>
                    <div class="progress <?= $records > 0 ? rate_class($rate) : '' ?>">
                        <span style="width: <?= $records > 0 ? max(2, $rate) : 0 ?>%"></span>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (!$subjects): ?>
                <div class="empty-state">
                    <span class="material-icons-round">menu_book</span>
                    <strong>No subject records yet</strong>
                    <p>Attendance will appear here once your teachers begin marking classes.</p>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($focusSubjects): ?>
            <hr class="divider">
            <div class="text-sm text-muted mb-12">Subjects below 90%</div>
            <div class="row row-wrap">
                <?php foreach ($focusSubjects as $subject):
                    $rate = attendance_rate((int) $subject['present'], (int) $subject['late'], (int) $subject['absent'], (int) $subject['excused']);
                ?>
                    <span class="chip <?= $rate >= 75 ? 'chip-late' : 'chip-absent' ?>">
                        <span class="material-icons-round">priority_high</span>
                        <?= e($subject['name']) ?> · <?= number_format($rate, 1) ?>%
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h2>Recent activity</h2>
                <p>Your latest attendance records</p>
            </div>
            <a class="btn btn-text btn-sm" href="<?= e(url('student/attendance.php')) ?>">
                View all
                <span class="material-icons-round">arrow_forward</span>
            </a>
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
                        <strong><?= e($row['subject_name']) ?> · <?= e(status_meta($row['status'])['label']) ?></strong>
                        <span>
                            <?= e(format_date($row['attendance_date'], 'l, M j')) ?> ·
                            <?= e($row['class_name']) ?> ·
                            <?= e($row['teacher_name']) ?>
                            <?php if ($row['remarks']): ?>
                                · “<?= e($row['remarks']) ?>”
                            <?php endif; ?>
                        </span>
                    </div>
                    <span class="activity-time"><?= e($row['subject_code']) ?></span>
                </div>
            <?php endforeach; ?>

            <?php if (!$recent): ?>
                <div class="empty-state">
                    <span class="material-icons-round">history</span>
                    <strong>No attendance records yet</strong>
                    <p>Records will appear here after your first class is marked.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
