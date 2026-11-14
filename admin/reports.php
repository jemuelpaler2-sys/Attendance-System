<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

$pdo = db();

$classes = $pdo->query('SELECT id, name, grade_level, section FROM classes ORDER BY grade_level, section')->fetchAll();

$classFilter = get_int('class');
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

$selectedClass = null;
if ($classFilter > 0) {
    $stmt = $pdo->prepare('SELECT id, name, grade_level, section, school_year FROM classes WHERE id = ? LIMIT 1');
    $stmt->execute([$classFilter]);
    $selectedClass = $stmt->fetch();
    if (!$selectedClass) {
        $classFilter = 0;
    }
}

/* Status totals for the selected scope */
$counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];

if ($classFilter > 0) {
    $stmt = $pdo->prepare(
        'SELECT a.status, COUNT(*) AS total
         FROM attendance a
         JOIN class_subjects cs ON cs.id = a.class_subject_id
         WHERE cs.class_id = ? AND a.attendance_date BETWEEN ? AND ?
         GROUP BY a.status'
    );
    $stmt->execute([$classFilter, $from, $to]);
} else {
    $stmt = $pdo->prepare(
        'SELECT status, COUNT(*) AS total FROM attendance WHERE attendance_date BETWEEN ? AND ? GROUP BY status'
    );
    $stmt->execute([$from, $to]);
}

foreach ($stmt->fetchAll() as $row) {
    if (isset($counts[$row['status']])) {
        $counts[$row['status']] = (int) $row['total'];
    }
}

$totalRecords = array_sum($counts);
$overallRate = attendance_rate($counts['present'], $counts['late'], $counts['absent'], $counts['excused']);

$studentRows = [];
$subjectRows = [];
$classRows = [];

if ($classFilter > 0) {
    $stmt = $pdo->prepare(
        "SELECT s.id, s.student_no, u.full_name, u.avatar_color,
                COALESCE(SUM(a.status = 'present'), 0) AS present,
                COALESCE(SUM(a.status = 'late'), 0) AS late,
                COALESCE(SUM(a.status = 'absent'), 0) AS absent,
                COALESCE(SUM(a.status = 'excused'), 0) AS excused,
                COUNT(a.student_id) AS total
         FROM students s
         JOIN users u ON u.id = s.user_id
         LEFT JOIN (
             SELECT att.student_id, att.status
             FROM attendance att
             JOIN class_subjects cs ON cs.id = att.class_subject_id
             WHERE cs.class_id = ? AND att.attendance_date BETWEEN ? AND ?
         ) a ON a.student_id = s.id
     WHERE s.class_id = ? AND s.status = 'enrolled'
     GROUP BY s.id, s.student_no, u.full_name, u.avatar_color
     ORDER BY u.full_name"
    );
    $stmt->execute([$classFilter, $from, $to, $classFilter]);
    $studentRows = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT sub.code, sub.name,
                COUNT(a.id) AS total,
                COALESCE(SUM(a.status = 'present'), 0) AS present,
                COALESCE(SUM(a.status = 'late'), 0) AS late,
                COALESCE(SUM(a.status = 'absent'), 0) AS absent,
                COALESCE(SUM(a.status = 'excused'), 0) AS excused
         FROM class_subjects cs
         JOIN subjects sub ON sub.id = cs.subject_id
         LEFT JOIN attendance a ON a.class_subject_id = cs.id AND a.attendance_date BETWEEN ? AND ?
     WHERE cs.class_id = ?
     GROUP BY cs.id, sub.code, sub.name
     ORDER BY sub.name"
    );
    $stmt->execute([$from, $to, $classFilter]);
    $subjectRows = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare(
        "SELECT c.id, c.name, c.grade_level, c.section,
                COUNT(a.id) AS total,
                COALESCE(SUM(a.status = 'present'), 0) AS present,
                COALESCE(SUM(a.status = 'late'), 0) AS late,
                COALESCE(SUM(a.status = 'absent'), 0) AS absent,
                COALESCE(SUM(a.status = 'excused'), 0) AS excused
         FROM classes c
         LEFT JOIN class_subjects cs ON cs.class_id = c.id
         LEFT JOIN attendance a ON a.class_subject_id = cs.id AND a.attendance_date BETWEEN ? AND ?
     GROUP BY c.id, c.name, c.grade_level, c.section
     ORDER BY c.grade_level, c.section"
    );
    $stmt->execute([$from, $to]);
    $classRows = $stmt->fetchAll();
}

$filterParams = [
    'class' => $classFilter > 0 ? $classFilter : '',
    'from'  => $from,
    'to'    => $to,
];

/* CSV export */
if (get_param('export') === 'csv') {
    if ($classFilter > 0 && $selectedClass) {
        $rows = [];
        foreach ($studentRows as $student) {
            $rate = attendance_rate((int) $student['present'], (int) $student['late'], (int) $student['absent'], (int) $student['excused']);
            $rows[] = [
                $student['student_no'],
                $student['full_name'],
                $selectedClass['name'],
                (int) $student['present'],
                (int) $student['late'],
                (int) $student['absent'],
                (int) $student['excused'],
                (int) $student['total'],
                number_format($rate, 1) . '%',
            ];
        }
        send_csv(
            'attendance-' . preg_replace('/[^a-z0-9]+/i', '-', $selectedClass['name']) . '-' . $from . '-to-' . $to . '.csv',
            ['Student No', 'Student', 'Class', 'Present', 'Late', 'Absent', 'Excused', 'Total', 'Rate'],
            $rows
        );
    }

    $rows = [];
    foreach ($classRows as $class) {
        $rate = attendance_rate((int) $class['present'], (int) $class['late'], (int) $class['absent'], (int) $class['excused']);
        $rows[] = [
            $class['name'],
            (int) $class['present'],
            (int) $class['late'],
            (int) $class['absent'],
            (int) $class['excused'],
            (int) $class['total'],
            number_format($rate, 1) . '%',
        ];
    }
    send_csv(
        'attendance-school-wide-' . $from . '-to-' . $to . '.csv',
        ['Class', 'Present', 'Late', 'Absent', 'Excused', 'Total', 'Rate'],
        $rows
    );
}

$pageTitle = 'Reports';
$pageSubtitle = 'Analyze attendance across classes and students.';
$activeNav = 'reports';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div class="page-head-text">
        <h1>Attendance Reports</h1>
        <p>Review attendance for any date range<?= $selectedClass ? ' in ' . e($selectedClass['name']) : ' across the whole school' ?>.</p>
    </div>
    <div class="page-head-actions">
        <button class="btn btn-outlined" type="button" onclick="window.print()">
            <span class="material-icons-round">print</span>
            Print
        </button>
        <a class="btn btn-filled" href="<?= e(query_url('admin/reports.php', array_merge($filterParams, ['export' => 'csv']))) ?>">
            <span class="material-icons-round">download</span>
            Export CSV
        </a>
    </div>
</div>

<div class="card mb-24">
    <form method="get" action="<?= e(url('admin/reports.php')) ?>" class="filter-bar">
        <div class="field is-static">
            <select name="class" aria-label="Class">
                <option value="">School-wide</option>
                <?php foreach ($classes as $class): ?>
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

        <a class="btn btn-text btn-sm" href="<?= e(url('admin/reports.php')) ?>">Reset</a>

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

<?php if ($classFilter > 0 && $selectedClass): ?>
    <div class="grid grid-2-1">
        <div class="table-card">
            <div class="table-toolbar">
                <div class="table-toolbar-title">
                    <span class="material-icons-round">groups</span>
                    Student summary
                    <span class="count"><?= count($studentRows) ?> students</span>
                </div>
                <span class="chip chip-role"><?= e($selectedClass['name']) ?></span>
            </div>

            <div class="table-scroll">
                <table class="data-table" style="min-width: 760px">
                    <thead>
                        <tr>
                            <th>Student</th>
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
                            $total = (int) $student['total'];
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
                                <td><?= (int) $student['present'] ?></td>
                                <td><?= (int) $student['late'] ?></td>
                                <td><?= (int) $student['absent'] ?></td>
                                <td><?= (int) $student['excused'] ?></td>
                                <td>
                                    <?php if ($total > 0): ?>
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
                                <td colspan="6">
                                    <div class="empty-state">
                                        <span class="material-icons-round">group_off</span>
                                        <strong>No students in this class</strong>
                                        <p>Enroll students to see their attendance summaries here.</p>
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
                    <p>Attendance rate per subject</p>
                </div>
                <span class="material-icons-round">menu_book</span>
            </div>

            <div class="subject-bars">
                <?php foreach ($subjectRows as $subject):
                    $rate = attendance_rate((int) $subject['present'], (int) $subject['late'], (int) $subject['absent'], (int) $subject['excused']);
                    $total = (int) $subject['total'];
                ?>
                    <div>
                        <div class="subject-bar-head">
                            <span><?= e($subject['name']) ?></span>
                            <strong><?= $total > 0 ? number_format($rate, 1) . '%' : '—' ?></strong>
                        </div>
                        <div class="progress <?= $total > 0 ? rate_class($rate) : '' ?>">
                            <span style="width: <?= $total > 0 ? max(2, $rate) : 0 ?>%"></span>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (!$subjectRows): ?>
                    <div class="empty-state">
                        <span class="material-icons-round">assignment</span>
                        <strong>No subjects assigned</strong>
                        <p>Assign subjects to this class to see a per-subject breakdown.</p>
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
        </div>
    </div>

<?php else: ?>
    <div class="grid grid-2-1">
        <div class="table-card">
            <div class="table-toolbar">
                <div class="table-toolbar-title">
                    <span class="material-icons-round">school</span>
                    Class summary
                    <span class="count"><?= count($classRows) ?> classes</span>
                </div>
                <span class="badge-soft">
                    <span class="material-icons-round" style="font-size:15px">date_range</span>
                    <?= e(date_range_label($from, $to)) ?>
                </span>
            </div>

            <div class="table-scroll">
                <table class="data-table" style="min-width: 820px">
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Present</th>
                            <th>Late</th>
                            <th>Absent</th>
                            <th>Excused</th>
                            <th>Total</th>
                            <th>Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classRows as $class):
                            $rate = attendance_rate((int) $class['present'], (int) $class['late'], (int) $class['absent'], (int) $class['excused']);
                            $total = (int) $class['total'];
                        ?>
                            <tr>
                                <td>
                                    <div class="cell-meta">
                                        <strong><?= e($class['name']) ?></strong>
                                        <span><?= e($class['grade_level'] . ' · ' . $class['section']) ?></span>
                                    </div>
                                </td>
                                <td><?= (int) $class['present'] ?></td>
                                <td><?= (int) $class['late'] ?></td>
                                <td><?= (int) $class['absent'] ?></td>
                                <td><?= (int) $class['excused'] ?></td>
                                <td><?= $total ?></td>
                                <td>
                                    <?php if ($total > 0): ?>
                                        <span class="chip <?= $rate >= 90 ? 'chip-present' : ($rate >= 75 ? 'chip-late' : 'chip-absent') ?>">
                                            <?= number_format($rate, 1) ?>%
                                        </span>
                                    <?php else: ?>
                                        <span class="chip chip-muted">No records</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$classRows): ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <span class="material-icons-round">class</span>
                                        <strong>No classes found</strong>
                                        <p>Create classes and mark attendance to generate reports.</p>
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
                    <h2>Attendance by class</h2>
                    <p>Ranked by attendance rate</p>
                </div>
                <span class="material-icons-round">leaderboard</span>
            </div>

            <?php
            $ranked = $classRows;
            usort($ranked, static function ($a, $b) {
                $ra = ((int) $a['total']) > 0 ? (int) $a['present'] + (int) $a['late'] : 0;
                $rb = ((int) $b['total']) > 0 ? (int) $b['present'] + (int) $b['late'] : 0;
                $ta = (int) $a['total'];
                $tb = (int) $b['total'];
                $pa = $ta > 0 ? $ra / $ta : 0;
                $pb = $tb > 0 ? $rb / $tb : 0;
                return $pb <=> $pa;
            });
            ?>
            <div class="subject-bars">
                <?php foreach ($ranked as $class):
                    $rate = attendance_rate((int) $class['present'], (int) $class['late'], (int) $class['absent'], (int) $class['excused']);
                    $total = (int) $class['total'];
                ?>
                    <div>
                        <div class="subject-bar-head">
                            <span><?= e($class['name']) ?></span>
                            <strong><?= $total > 0 ? number_format($rate, 1) . '%' : '—' ?></strong>
                        </div>
                        <div class="progress <?= $total > 0 ? rate_class($rate) : '' ?>">
                            <span style="width: <?= $total > 0 ? max(2, $rate) : 0 ?>%"></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <hr class="divider">

            <div class="info-banner">
                <span class="material-icons-round">insights</span>
                <div>
                    <strong><?= number_format($overallRate, 1) ?>% overall attendance</strong>
                    <span><?= $totalRecords ?> records from <?= e(date_range_label($from, $to)) ?>.</span>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
