<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';

require_role('teacher');

$pdo = db();
$user = current_user();
$teacherId = (int) $user['id'];

$classes = attendance_class_subjects($pdo, $teacherId);
$classMap = [];
foreach ($classes as $load) {
    $classMap[(int) $load['class_id']] = [
        'id' => (int) $load['class_id'],
        'name' => $load['class_name'],
        'grade_level' => $load['grade_level'],
        'section' => $load['section'],
        'student_count' => (int) $load['student_count'],
    ];
}
$classes = array_values($classMap);

$q = trim(get_param('q'));
$classFilter = get_int('class');
if ($classFilter > 0 && !isset($classMap[$classFilter])) {
    $classFilter = 0;
}

$page = max(1, get_int('page', 1));
$perPage = 12;

$where = ["s.status = 'enrolled'"];
$whereParams = [];

if ($classFilter > 0) {
    $where[] = 's.class_id = ?';
    $whereParams[] = $classFilter;
}

if ($q !== '') {
    $where[] = '(u.full_name LIKE ? OR s.student_no LIKE ? OR COALESCE(s.guardian_name, \'\') LIKE ?)';
    $like = '%' . $q . '%';
    $whereParams[] = $like;
    $whereParams[] = $like;
    $whereParams[] = $like;
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT s.id)
     FROM students s
     JOIN users u ON u.id = s.user_id
     JOIN class_subjects cs ON cs.class_id = s.class_id
     WHERE cs.teacher_id = ? AND {$whereSql}"
);
$stmt->execute(array_merge([$teacherId], $whereParams));
$total = (int) $stmt->fetchColumn();
$pagination = pagination_data($total, $perPage, $page);

$stmt = $pdo->prepare(
    "SELECT s.id, s.student_no, s.guardian_name, s.guardian_phone,
            u.full_name, u.avatar_color, u.email,
            c.id AS class_id, c.name AS class_name, c.grade_level, c.section,
            COUNT(DISTINCT a.id) AS total_records,
            COALESCE(SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END), 0) AS attended,
            COALESCE(SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END), 0) AS absences,
            COALESCE(SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END), 0) AS lates
     FROM students s
     JOIN users u ON u.id = s.user_id
     JOIN classes c ON c.id = s.class_id
     JOIN class_subjects cs ON cs.class_id = s.class_id AND cs.teacher_id = ?
     LEFT JOIN attendance a
            ON a.student_id = s.id
           AND a.class_subject_id = cs.id
           AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     WHERE {$whereSql}
     GROUP BY s.id, s.student_no, s.guardian_name, s.guardian_phone,
              u.full_name, u.avatar_color, u.email,
              c.id, c.name, c.grade_level, c.section
     ORDER BY u.full_name
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}"
);
$stmt->execute(array_merge([$teacherId], $whereParams));
$students = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT s.id)
     FROM students s
     JOIN class_subjects cs ON cs.class_id = s.class_id
     WHERE cs.teacher_id = ? AND s.status = 'enrolled'"
);
$stmt->execute([$teacherId]);
$studentTotal = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END), 0) AS attended
     FROM attendance a
     JOIN class_subjects cs ON cs.id = a.class_subject_id
     WHERE cs.teacher_id = ? AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
);
$stmt->execute([$teacherId]);
$summary = $stmt->fetch();
$scopeTotal = (int) ($summary['total'] ?? 0);
$scopeAttended = (int) ($summary['attended'] ?? 0);
$scopeRate = $scopeTotal > 0 ? round(($scopeAttended / $scopeTotal) * 100, 1) : 0.0;

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM (
        SELECT s.id,
               COUNT(DISTINCT a.id) AS total,
               SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) AS attended
        FROM students s
        JOIN class_subjects cs ON cs.class_id = s.class_id
        JOIN attendance a ON a.student_id = s.id AND a.class_subject_id = cs.id
        WHERE cs.teacher_id = ? AND s.status = 'enrolled'
          AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY s.id
        HAVING COUNT(DISTINCT a.id) > 0
           AND (SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) / COUNT(DISTINCT a.id)) < 0.90
    ) AS at_risk"
);
$stmt->execute([$teacherId]);
$atRiskCount = (int) $stmt->fetchColumn();

$pageTitle = 'My Students';
$pageSubtitle = 'Students enrolled in the classes you teach.';
$activeNav = 'students';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div class="page-head-text">
        <h1>My Students</h1>
        <p>Students enrolled in the classes you teach, with attendance from the last 30 days.</p>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-outlined" href="<?= e(url('teacher/attendance.php')) ?>">
            <span class="material-icons-round">fact_check</span>
            Mark attendance
        </a>
        <a class="btn btn-filled" href="<?= e(url('teacher/reports.php')) ?>">
            <span class="material-icons-round">assessment</span>
            Open reports
        </a>
    </div>
</div>

<div class="grid grid-4 mb-24">
    <div class="card stat-card tone-blue">
        <div class="stat-top">
            <span class="stat-icon"><span class="material-icons-round">groups</span></span>
        </div>
        <div>
            <div class="stat-value"><?= $studentTotal ?></div>
            <div class="stat-label">Students in my classes</div>
        </div>
    </div>

    <div class="card stat-card tone-purple">
        <div class="stat-top">
            <span class="stat-icon"><span class="material-icons-round">class</span></span>
        </div>
        <div>
            <div class="stat-value"><?= count($classMap) ?></div>
            <div class="stat-label">Classes taught</div>
        </div>
    </div>

    <div class="card stat-card tone-green">
        <div class="stat-top">
            <span class="stat-icon"><span class="material-icons-round">percent</span></span>
            <span class="stat-trend <?= $scopeRate >= 90 ? 'up' : ($scopeRate < 75 ? 'down' : '') ?>">
                <span class="material-icons-round"><?= $scopeRate >= 90 ? 'trending_up' : 'trending_flat' ?></span>
                last 30 days
            </span>
        </div>
        <div>
            <div class="stat-value"><?= number_format($scopeRate, 1) ?>%</div>
            <div class="stat-label">Attendance rate</div>
        </div>
    </div>

    <div class="card stat-card tone-red">
        <div class="stat-top">
            <span class="stat-icon"><span class="material-icons-round">health_and_safety</span></span>
        </div>
        <div>
            <div class="stat-value"><?= $atRiskCount ?></div>
            <div class="stat-label">Below 90% attendance</div>
        </div>
    </div>
</div>

<div class="table-card">
    <div class="table-toolbar">
        <form method="get" action="<?= e(url('teacher/students.php')) ?>" class="filter-bar" style="flex:1" data-auto-submit>
            <div class="search-box">
                <span class="material-icons-round">search</span>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, student no. or guardian" aria-label="Search students">
            </div>

            <div class="field is-static">
                <select name="class" aria-label="Filter by class">
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

            <button class="btn btn-tonal btn-sm" type="submit">
                <span class="material-icons-round">filter_list</span>
                Filter
            </button>

            <?php if ($q !== '' || $classFilter > 0): ?>
                <a class="btn btn-text btn-sm" href="<?= e(url('teacher/students.php')) ?>">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-scroll">
        <table class="data-table" style="min-width: 980px">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Class</th>
                    <th>Guardian</th>
                    <th>30-day attendance</th>
                    <th>Records</th>
                    <th>Absences</th>
                    <th style="text-align:right">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $student):
                    $records = (int) $student['total_records'];
                    $rate = $records > 0 ? round(((int) $student['attended'] / $records) * 100, 1) : 0.0;
                ?>
                    <tr>
                        <td>
                            <div class="cell-main">
                                <span class="avatar" style="background: <?= e(avatar_color($student['avatar_color'])) ?>">
                                    <?= e(initials($student['full_name'])) ?>
                                </span>
                                <div class="cell-meta">
                                    <strong><?= e($student['full_name']) ?></strong>
                                    <span><?= e($student['student_no']) ?> · <?= e(ucfirst($student['gender'] ?? 'student')) ?></span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="class-chip">
                                <span class="material-icons-round" style="font-size:14px">class</span>
                                <?= e($student['class_name']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="cell-meta">
                                <strong style="font-weight:500"><?= e($student['guardian_name'] ?: '—') ?></strong>
                                <span><?= e($student['guardian_phone'] ?: 'No contact number') ?></span>
                            </div>
                        </td>
                        <td>
                            <?php if ($records > 0): ?>
                                <div style="min-width:130px">
                                    <div class="row-between" style="margin-bottom:6px">
                                        <span class="chip <?= $rate >= 90 ? 'chip-present' : ($rate >= 75 ? 'chip-late' : 'chip-absent') ?>">
                                            <?= number_format($rate, 1) ?>%
                                        </span>
                                        <?php if ($rate < 90): ?>
                                            <span class="text-xs text-tertiary">needs attention</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="progress <?= rate_class($rate) ?>">
                                        <span style="width: <?= max(2, $rate) ?>%"></span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="chip chip-muted">No records</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $records ?></td>
                        <td>
                            <?php if ((int) $student['absences'] > 0): ?>
                                <span class="chip chip-absent"><?= (int) $student['absences'] ?></span>
                            <?php else: ?>
                                <span class="text-sm text-tertiary">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="table-actions">
                                <a class="btn btn-text btn-sm"
                                   href="<?= e(query_url('teacher/reports.php', ['class' => (int) $student['class_id'], 'student' => (int) $student['id']])) ?>">
                                    <span class="material-icons-round">insights</span>
                                    Report
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$students): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <span class="material-icons-round">group_off</span>
                                <strong>No students found</strong>
                                <p><?= ($q !== '' || $classFilter > 0) ? 'Try a different search term or class filter.' : 'Students will appear here once they are enrolled in your classes.' ?></p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="table-footer">
        <span class="text-sm">
            Showing <?= $total > 0 ? $pagination['offset'] + 1 : 0 ?>–<?= min($pagination['offset'] + $perPage, $total) ?> of <?= $total ?> students
        </span>
        <?= render_pagination($pagination, ['q' => $q, 'class' => $classFilter ?: ''], 'teacher/students.php') ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
