<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_role('student');

$pdo = db();
$user = current_user();

$stmt = $pdo->prepare(
    "SELECT s.id, s.student_no, s.class_id, c.name AS class_name, c.grade_level, c.section
     FROM students s
     LEFT JOIN classes c ON c.id = s.class_id
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

$subjects = $pdo->prepare(
    "SELECT DISTINCT sub.id, sub.code, sub.name
     FROM attendance a
     JOIN class_subjects cs ON cs.id = a.class_subject_id
     JOIN subjects sub ON sub.id = cs.subject_id
     WHERE a.student_id = ?
     ORDER BY sub.name"
);
$subjects->execute([$studentId]);
$subjects = $subjects->fetchAll();

$from = get_param('from', date('Y-m-d', strtotime('-29 days')));
$to = get_param('to', date('Y-m-d'));
$subjectFilter = get_int('subject');
$statusFilter = get_param('status');
$page = max(1, get_int('page', 1));
$perPage = 15;

if (!is_valid_date($from)) {
    $from = date('Y-m-d', strtotime('-29 days'));
}

if (!is_valid_date($to)) {
    $to = date('Y-m-d');
}

if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$validStatuses = ['present', 'late', 'absent', 'excused'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

$subjectIds = array_map(static fn ($subject) => (int) $subject['id'], $subjects);
if ($subjectFilter > 0 && !in_array($subjectFilter, $subjectIds, true)) {
    $subjectFilter = 0;
}

$baseWhere = 'a.student_id = ? AND a.attendance_date BETWEEN ? AND ?';
$baseParams = [$studentId, $from, $to];

if ($subjectFilter > 0) {
    $baseWhere .= ' AND cs.subject_id = ?';
    $baseParams[] = $subjectFilter;
}

$statusWhere = $baseWhere;
$statusParams = $baseParams;
if ($statusFilter !== '') {
    $statusWhere .= ' AND a.status = ?';
    $statusParams[] = $statusFilter;
}

/* Summary ignores the status filter so totals stay stable. */
$stmt = $pdo->prepare(
    "SELECT a.status, COUNT(*) AS total
     FROM attendance a
     JOIN class_subjects cs ON cs.id = a.class_subject_id
     WHERE {$baseWhere}
     GROUP BY a.status"
);
$stmt->execute($baseParams);

$counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
foreach ($stmt->fetchAll() as $row) {
    if (isset($counts[$row['status']])) {
        $counts[$row['status']] = (int) $row['total'];
    }
}
$summaryTotal = array_sum($counts);
$summaryRate = attendance_rate($counts['present'], $counts['late'], $counts['absent'], $counts['excused']);

$stmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM attendance a
     JOIN class_subjects cs ON cs.id = a.class_subject_id
     WHERE {$statusWhere}"
);
$stmt->execute($statusParams);
$total = (int) $stmt->fetchColumn();
$pagination = pagination_data($total, $perPage, $page);

$stmt = $pdo->prepare(
    "SELECT a.id, a.status, a.attendance_date, a.remarks, a.updated_at,
            sub.code AS subject_code, sub.name AS subject_name,
            c.name AS class_name, t.full_name AS teacher_name
     FROM attendance a
     JOIN class_subjects cs ON cs.id = a.class_subject_id
     JOIN subjects sub ON sub.id = cs.subject_id
     JOIN classes c ON c.id = cs.class_id
     JOIN users t ON t.id = cs.teacher_id
     WHERE {$statusWhere}
     ORDER BY a.attendance_date DESC, a.id DESC
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}"
);
$stmt->execute($statusParams);
$records = $stmt->fetchAll();

$filterParams = [
    'from' => $from,
    'to' => $to,
    'subject' => $subjectFilter > 0 ? $subjectFilter : '',
    'status' => $statusFilter,
];

$pageTitle = 'My Attendance';
$pageSubtitle = 'Your complete attendance history.';
$activeNav = 'attendance';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div class="page-head-text">
        <h1>My Attendance</h1>
        <p>Review every attendance record across your classes and subjects.</p>
    </div>
    <div class="page-head-actions">
        <?php if ($student['class_name']): ?>
            <span class="chip chip-role">
                <span class="material-icons-round">class</span>
                <?= e($student['class_name']) ?>
            </span>
        <?php endif; ?>
        <span class="chip chip-muted">
            <span class="material-icons-round">badge</span>
            <?= e($student['student_no']) ?>
        </span>
        <button class="btn btn-outlined" type="button" onclick="window.print()">
            <span class="material-icons-round">print</span>
            Print
        </button>
        <a class="btn btn-filled" href="<?= e(url('student/dashboard.php')) ?>">
            <span class="material-icons-round">dashboard</span>
            Dashboard
        </a>
    </div>
</div>

<div class="card mb-24">
    <form method="get" action="<?= e(url('student/attendance.php')) ?>" class="filter-bar">
        <div class="field is-static">
            <input type="date" name="from" value="<?= e($from) ?>" max="<?= e(date('Y-m-d')) ?>" aria-label="From date">
            <label>From</label>
        </div>

        <div class="field is-static">
            <input type="date" name="to" value="<?= e($to) ?>" max="<?= e(date('Y-m-d')) ?>" aria-label="To date">
            <label>To</label>
        </div>

        <div class="field is-static">
            <select name="subject" aria-label="Subject">
                <option value="">All subjects</option>
                <?php foreach ($subjects as $subject): ?>
                    <option value="<?= (int) $subject['id'] ?>" <?= $subjectFilter === (int) $subject['id'] ? 'selected' : '' ?>>
                        <?= e($subject['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="select-arrow material-icons-round">expand_more</span>
            <label>Subject</label>
        </div>

        <div class="field is-static">
            <select name="status" aria-label="Attendance status">
                <option value="">All statuses</option>
                <?php foreach ($validStatuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                        <?= e(status_meta($status)['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="select-arrow material-icons-round">expand_more</span>
            <label>Status</label>
        </div>

        <button class="btn btn-filled btn-sm" type="submit">
            <span class="material-icons-round">filter_list</span>
            Apply
        </button>

        <a class="btn btn-text btn-sm" href="<?= e(url('student/attendance.php')) ?>">Reset</a>

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
            <span class="stat-trend <?= $summaryRate >= 90 ? 'up' : ($summaryRate < 75 ? 'down' : '') ?>">
                <span class="material-icons-round"><?= $summaryRate >= 90 ? 'trending_up' : 'trending_flat' ?></span>
                <?= $summaryTotal ?> records
            </span>
        </div>
        <div>
            <div class="stat-value"><?= number_format($summaryRate, 1) ?>%</div>
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

<div class="table-card">
    <div class="table-toolbar">
        <div class="table-toolbar-title">
            <span class="material-icons-round">fact_check</span>
            Attendance history
            <span class="count"><?= $total ?> records</span>
        </div>
        <div class="row">
            <span class="chip chip-excused">
                <span class="material-icons-round">info</span>
                Excused <?= $counts['excused'] ?>
            </span>
            <span class="chip <?= $summaryRate >= 90 ? 'chip-present' : ($summaryRate >= 75 ? 'chip-late' : 'chip-absent') ?>">
                <?= number_format($summaryRate, 1) ?>%
            </span>
        </div>
    </div>

    <div class="table-scroll">
        <table class="data-table" style="min-width: 920px">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Subject</th>
                    <th>Class</th>
                    <th>Teacher</th>
                    <th>Status</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td>
                            <div class="cell-meta">
                                <strong><?= e(format_date($record['attendance_date'], 'M j, Y')) ?></strong>
                                <span><?= e(format_date($record['attendance_date'], 'l')) ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="row" style="gap:8px">
                                <span class="chip chip-role" style="font-family:monospace"><?= e($record['subject_code']) ?></span>
                                <?= e($record['subject_name']) ?>
                            </div>
                        </td>
                        <td><?= e($record['class_name']) ?></td>
                        <td><?= e($record['teacher_name']) ?></td>
                        <td><?= status_chip($record['status']) ?></td>
                        <td>
                            <?php if ($record['remarks']): ?>
                                <span class="text-sm"><?= e($record['remarks']) ?></span>
                            <?php else: ?>
                                <span class="text-sm text-tertiary">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$records): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <span class="material-icons-round">event_busy</span>
                                <strong>No attendance records found</strong>
                                <p>Try a different date range, subject or status filter.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="table-footer">
        <span class="text-sm">
            Showing <?= $total > 0 ? $pagination['offset'] + 1 : 0 ?>–<?= min($pagination['offset'] + $perPage, $total) ?> of <?= $total ?> records
        </span>
        <?= render_pagination($pagination, $filterParams, 'student/attendance.php') ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
