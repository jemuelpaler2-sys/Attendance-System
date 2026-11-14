<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';

require_role('teacher');

$pdo = db();
$user = current_user();
$teacherId = (int) $user['id'];

$loads = attendance_class_subjects($pdo, $teacherId);
$classMap = [];
foreach ($loads as $load) {
    $classMap[(int) $load['class_id']] = [
        'id' => (int) $load['class_id'],
        'name' => $load['class_name'],
        'grade_level' => $load['grade_level'],
        'section' => $load['section'],
    ];
}

$classFilter = get_int('class');
$studentFilter = get_int('student');
$from = get_param('from', date('Y-m-d', strtotime('-29 days')));
$to = get_param('to', date('Y-m-d'));

if (!is_valid_date($from)) {
    $from = date('Y-m-d', strtotime('-29 days'));
}

if (!is_valid_date($to)) {
    $to = date('Y-m-d');
}

if ($from > $to) {
    [$from, $to] = [$to, $from];
}

if ($classFilter > 0 && !isset($classMap[$classFilter])) {
    $classFilter = 0;
}

$selectedStudent = null;
if ($studentFilter > 0) {
    $stmt = $pdo->prepare(
        "SELECT s.id, s.student_no, s.class_id, c.name AS class_name,
                u.full_name, u.avatar_color
         FROM students s
         JOIN users u ON u.id = s.user_id
         JOIN classes c ON c.id = s.class_id
         JOIN class_subjects cs ON cs.class_id = s.class_id AND cs.teacher_id = ?
         WHERE s.id = ? AND s.status = 'enrolled'
         LIMIT 1"
    );
    $stmt->execute([$teacherId, $studentFilter]);
    $selectedStudent = $stmt->fetch();

    if ($selectedStudent) {
        $classFilter = (int) $selectedStudent['class_id'];
    } else {
        $studentFilter = 0;
    }
}

$selectedClass = $classFilter > 0 ? ($classMap[$classFilter] ?? null) : null;
$scopeLabel = $selectedClass ? $selectedClass['name'] : 'All my classes';

/* Status totals within the teacher's scope */
$scopeWhere = 'cs.teacher_id = ? AND a.attendance_date BETWEEN ? AND ?';
$scopeParams = [$teacherId, $from, $to];

if ($classFilter > 0) {
    $scopeWhere .= ' AND cs.class_id = ?';
    $scopeParams[] = $classFilter;
}

if ($studentFilter > 0) {
    $scopeWhere .= ' AND a.student_id = ?';
    $scopeParams[] = $studentFilter;
}

$stmt = $pdo->prepare(
    "SELECT a.status, COUNT(*) AS total
     FROM attendance a
     JOIN class_subjects cs ON cs.id = a.class_subject_id
     WHERE {$scopeWhere}
     GROUP BY a.status"
);
$stmt->execute($scopeParams);

$counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
foreach ($stmt->fetchAll() as $row) {
    if (isset($counts[$row['status']])) {
        $counts[$row['status']] = (int) $row['total'];
    }
}

$totalRecords = array_sum($counts);
$overallRate = attendance_rate($counts['present'], $counts['late'], $counts['absent'], $counts['excused']);

/* Per-student summary across this teacher's subject loads */
$studentWhere = "s.status = 'enrolled'";
$studentParams = [$teacherId, $from, $to];

if ($classFilter > 0) {
    $studentWhere .= ' AND s.class_id = ?';
    $studentParams[] = $classFilter;
}

if ($studentFilter > 0) {
    $studentWhere .= ' AND s.id = ?';
    $studentParams[] = $studentFilter;
}

$stmt = $pdo->prepare(
    "SELECT s.id, s.student_no,
            u.full_name, u.avatar_color,
            c.name AS class_name,
            COUNT(a.id) AS total,
            COALESCE(SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END), 0) AS present,
            COALESCE(SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END), 0) AS late,
            COALESCE(SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END), 0) AS absent,
            COALESCE(SUM(CASE WHEN a.status = 'excused' THEN 1 ELSE 0 END), 0) AS excused
     FROM students s
     JOIN users u ON u.id = s.user_id
     JOIN classes c ON c.id = s.class_id
     JOIN class_subjects cs ON cs.class_id = s.class_id AND cs.teacher_id = ?
     LEFT JOIN attendance a
            ON a.student_id = s.id
           AND a.class_subject_id = cs.id
           AND a.attendance_date BETWEEN ? AND ?
     WHERE {$studentWhere}
     GROUP BY s.id, s.student_no, u.full_name, u.avatar_color, c.name
     ORDER BY u.full_name"
);
$stmt->execute($studentParams);
$studentRows = $stmt->fetchAll();

/* Per-subject breakdown for this teacher */
$subjectJoin = 'a.attendance_date BETWEEN ? AND ?';
$subjectParams = [$from, $to];

if ($studentFilter > 0) {
    $subjectJoin .= ' AND a.student_id = ?';
    $subjectParams[] = $studentFilter;
}

$subjectWhere = 'cs.teacher_id = ?';
$subjectParams[] = $teacherId;

if ($classFilter > 0) {
    $subjectWhere .= ' AND cs.class_id = ?';
    $subjectParams[] = $classFilter;
}

$stmt = $pdo->prepare(
    "SELECT cs.id, sub.code, sub.name, c.name AS class_name,
            COUNT(a.id) AS total,
            COALESCE(SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END), 0) AS present,
            COALESCE(SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END), 0) AS late,
            COALESCE(SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END), 0) AS absent,
            COALESCE(SUM(CASE WHEN a.status = 'excused' THEN 1 ELSE 0 END), 0) AS excused
     FROM class_subjects cs
     JOIN subjects sub ON sub.id = cs.subject_id
     JOIN classes c ON c.id = cs.class_id
     LEFT JOIN attendance a ON a.class_subject_id = cs.id AND {$subjectJoin}
     WHERE {$subjectWhere}
     GROUP BY cs.id, sub.code, sub.name, c.name
     ORDER BY c.name, sub.name"
);
$stmt->execute($subjectParams);
$subjectRows = $stmt->fetchAll();

$filterParams = [
    'class' => $classFilter > 0 ? $classFilter : '',
    'student' => $studentFilter > 0 ? $studentFilter : '',
    'from' => $from,
    'to' => $to,
];

/* CSV export */
if (get_param('export') === 'csv') {
    $rows = [];
    foreach ($studentRows as $student) {
        $rate = attendance_rate((int) $student['present'], (int) $student['late'], (int) $student['absent'], (int) $student['excused']);
        $rows[] = [
            $student['student_no'],
            $student['full_name'],
            $student['class_name'],
            (int) $student['present'],
            (int) $student['late'],
            (int) $student['absent'],
            (int) $student['excused'],
            (int) $student['total'],
            number_format($rate, 1) . '%',
        ];
    }

    $label = $selectedStudent
        ? preg_replace('/[^a-z0-9]+/i', '-', $selectedStudent['full_name'])
        : ($selectedClass ? preg_replace('/[^a-z0-9]+/i', '-', $selectedClass['name']) : 'my-classes');

    send_csv(
        'teacher-attendance-' . $label . '-' . $from . '-to-' . $to . '.csv',
        ['Student No', 'Student', 'Class', 'Present', 'Late', 'Absent', 'Excused', 'Total', 'Rate'],
        $rows
    );
}

$pageTitle = 'Reports';
$pageSubtitle = 'Attendance insights for your classes.';
$activeNav = 'reports';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div class="page-head-text">
        <h1>Attendance Reports</h1>
        <p>Review attendance for <?= e($scopeLabel) ?><?= $selectedStudent ? ' · ' . e($selectedStudent['full_name']) : '' ?>.</p>
    </div>
    <div class="page-head-actions">
        <button class="btn btn-outlined" type="button" onclick="window.print()">
            <span class="material-icons-round">print</span>
            Print
        </button>
        <a class="btn btn-filled" href="<?= e(query_url('teacher/reports.php', array_merge($filterParams, ['export' => 'csv']))) ?>">
            <span class="material-icons-round">download</span>
            Export CSV
        </a>
    </div>
</div>

<?php if ($selectedStudent): ?>
    <div class="info-banner mb-24">
        <span class="avatar sm" style="background: <?= e(avatar_color($selectedStudent['avatar_color'])) ?>">
            <?= e(initials($selectedStudent['full_name'])) ?>
        </span>
        <div>
            <strong>Showing <?= e($selectedStudent['full_name']) ?></strong>
            <span><?= e($selectedStudent['student_no']) ?> · <?= e($selectedStudent['class_name']) ?></span>
        </div>
        <a class="btn btn-sm" href="<?= e(query_url('teacher/reports.php', ['from' => $from, 'to' => $to])) ?>">
            Clear student filter
        </a>
    </div>
<?php endif; ?>

<div class="card mb-24">
    <form method="get" action="<?= e(url('teacher/reports.php')) ?>" class="filter-bar">
        <?php if ($studentFilter > 0): ?>
            <input type="hidden" name="student" value="<?= $studentFilter ?>">
        <?php endif; ?>

        <div class="field is-static">
            <select name="class" aria-label="Class">
                <option value="">All my classes</option>
                <?php foreach ($classMap as $class): ?>
                    <option value="<?= (int) $class['id'] ?>" <?= $classFilter === (int) $class['id'] ? 'selected' : '' ?>>
                        <?= e($class['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="select-arrow material-icons-round">expand_more</span>
            <label>Class</label>
        </div>

        <div class="field is-static">
            <input type="date" name="from" value="<?= e($from) ?>" max="<?= e(date('Y-m-d')) ?>" aria-label="From date">
            <label>From</label>
        </div>

        <div class="field is-static">
            <input type="date" name="to" value="<?= e($to) ?>" max="<?= e(date('Y-m-d')) ?>" aria-label="To date">
            <label>To</label>
        </div>

        <button class="btn btn-filled btn-sm" type="submit">
            <span class="material-icons-round">search</span>
            Generate
        </button>

        <a class="btn btn-text btn-sm" href="<?= e(url('teacher/reports.php')) ?>">Reset</a>

        <span class="spacer"></span>
        <span class="badge-soft">
            <span class="material-icons-round" style="font-size:15px">date_range</span>
            <?= e(date_range_label($from, $to)) ?>
        </span>
    </form>
</div>

<div class="grid grid-4 mb-24">
    <div class="card stat-card tone-blue">
        <div class="stat-top">
            <span class="stat-icon"><span class="material-icons-round">percent</span></span>
            <span class="stat-trend <?= $overallRate >= 90 ? 'up' : ($overallRate < 75 ? 'down' : '') ?>">
                <span class="material-icons-round">trending_<?= $overallRate >= 75 ? 'up' : 'down' ?></span>
                <?= $totalRecords ?> records
            </span>
        </div>
        <div>
            <div class="stat-value"><?= number_format($overallRate, 1) ?>%</div>
            <div class="stat-label">Attendance rate</div>
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

<div class="grid grid-2-1">
    <div class="table-card">
        <div class="table-toolbar">
            <div class="table-toolbar-title">
                <span class="material-icons-round">groups</span>
                Student summary
                <span class="count"><?= count($studentRows) ?> students</span>
            </div>
            <span class="chip chip-role"><?= e($scopeLabel) ?></span>
        </div>

        <div class="table-scroll">
            <table class="data-table" style="min-width: 820px">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Class</th>
                        <th>Present</th>
                        <th>Late</th>
                        <th>Absent</th>
                        <th>Excused</th>
                        <th>Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($studentRows as $student):
                        $rate = attendance_rate((int) $student['present'], (int) $student['late'], (int) $student['absent'], (int) $student['excused']);
                        $records = (int) $student['total'];
                    ?>
                        <tr>
                            <td>
                                <div class="cell-main">
                                    <span class="avatar sm" style="background: <?= e(avatar_color($student['avatar_color'])) ?>">
                                        <?= e(initials($student['full_name'])) ?>
                                    </span>
                                    <div class="cell-meta">
                                        <strong><?= e($student['full_name']) ?></strong>
                                        <span><?= e($student['student_no']) ?></span>
                                    </div>
                                </div>
                            </td>
                            <td><?= e($student['class_name']) ?></td>
                            <td><?= (int) $student['present'] ?></td>
                            <td><?= (int) $student['late'] ?></td>
                            <td><?= (int) $student['absent'] ?></td>
                            <td><?= (int) $student['excused'] ?></td>
                            <td>
                                <?php if ($records > 0): ?>
                                    <span class="chip <?= $rate >= 90 ? 'chip-present' : ($rate >= 75 ? 'chip-late' : 'chip-absent') ?>">
                                        <?= number_format($rate, 1) ?>%
                                    </span>
                                <?php else: ?>
                                    <span class="chip chip-muted">No records</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$studentRows): ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <span class="material-icons-round">group_off</span>
                                    <strong>No students found</strong>
                                    <p>There are no enrolled students in this scope.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h2>By subject</h2>
                <p>Attendance rate across your loads</p>
            </div>
            <span class="material-icons-round">menu_book</span>
        </div>

        <div class="subject-bars">
            <?php foreach ($subjectRows as $subject):
                $rate = attendance_rate((int) $subject['present'], (int) $subject['late'], (int) $subject['absent'], (int) $subject['excused']);
                $records = (int) $subject['total'];
            ?>
                <div>
                    <div class="subject-bar-head">
                        <span>
                            <?= e($subject['name']) ?>
                            <span class="text-xs text-tertiary" style="display:block;font-weight:400"><?= e($subject['class_name']) ?></span>
                        </span>
                        <strong><?= $records > 0 ? number_format($rate, 1) . '%' : '—' ?></strong>
                    </div>
                    <div class="progress <?= $records > 0 ? rate_class($rate) : '' ?>">
                        <span style="width: <?= $records > 0 ? max(2, $rate) : 0 ?>%"></span>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (!$subjectRows): ?>
                <div class="empty-state">
                    <span class="material-icons-round">assignment</span>
                    <strong>No subject loads</strong>
                    <p>Ask an administrator to assign you to a class and subject.</p>
                </div>
            <?php endif; ?>
        </div>

        <hr class="divider">

        <div class="legend">
            <div class="legend-item"><span class="badge-dot present"></span><span class="label">Present</span><strong><?= $counts['present'] ?></strong></div>
            <div class="legend-item"><span class="badge-dot late"></span><span class="label">Late</span><strong><?= $counts['late'] ?></strong></div>
            <div class="legend-item"><span class="badge-dot absent"></span><span class="label">Absent</span><strong><?= $counts['absent'] ?></strong></div>
            <div class="legend-item"><span class="badge-dot excused"></span><span class="label">Excused</span><strong><?= $counts['excused'] ?></strong></div>
        </div>

        <div class="info-banner mt-24">
            <span class="material-icons-round">insights</span>
            <div>
                <strong><?= number_format($overallRate, 1) ?>% overall attendance</strong>
                <span><?= $totalRecords ?> records from <?= e(date_range_label($from, $to)) ?>.</span>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
