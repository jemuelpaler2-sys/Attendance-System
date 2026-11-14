<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';

require_role('teacher');

$pdo = db();
$user = current_user();
$teacherId = (int) $user['id'];

/* Save attendance with a standard form submission. */
if (is_post() && post_param('save_attendance') === '1') {
    require_csrf('teacher/attendance.php');

    $csId = post_int('class_subject_id');
    $postDate = post_param('attendance_date');
    $entries = $_POST['entries'] ?? [];

    $cs = attendance_access($pdo, $csId, 'teacher', $teacherId);

    if (!$cs || !is_valid_date($postDate) || !is_array($entries)) {
        flash('Could not save attendance. Please check the class and date.', 'error');
        redirect('teacher/attendance.php');
    }

    try {
        $saved = save_attendance($pdo, $csId, $postDate, $entries, $teacherId);
        flash('Attendance saved for ' . $saved . ' student' . ($saved === 1 ? '' : 's') . '.', 'success');
    } catch (Throwable $e) {
        flash('Could not save attendance. Please try again.', 'error');
    }

    redirect('teacher/attendance.php?' . http_build_query(['class_subject' => $csId, 'date' => $postDate]));
}

$classSubjects = attendance_class_subjects($pdo, $teacherId);
$selectedId = get_int('class_subject');
$attendanceDate = get_param('date', date('Y-m-d'));

if (!is_valid_date($attendanceDate) || $attendanceDate > date('Y-m-d')) {
    $attendanceDate = date('Y-m-d');
}

$attendanceCs = null;
$roster = [];

if ($selectedId > 0) {
    $attendanceCs = attendance_access($pdo, $selectedId, 'teacher', $teacherId);
    if (!$attendanceCs) {
        flash('That class assignment could not be found.', 'error');
        $selectedId = 0;
    } else {
        $roster = attendance_roster($pdo, $selectedId, $attendanceDate);
    }
}

$attendanceClassSubjects = $classSubjects;
$attendanceSelectedId = $selectedId;
$attendanceRoster = $roster;
$attendanceFilterPath = 'teacher/attendance.php';
$attendancePageTitle = 'Mark Attendance';

$pageTitle = 'Mark Attendance';
$pageSubtitle = 'Record attendance for your assigned classes.';
$activeNav = 'attendance';

$extraScripts = '<script src="' . e(asset('js/attendance.js')) . '"></script>';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div class="page-head-text">
        <h1>Mark Attendance</h1>
        <p>Select one of your subject loads and a date, then review the roster.</p>
    </div>
    <div class="page-head-actions">
        <span class="chip chip-role">
            <span class="material-icons-round">badge</span>
            <?= count($classSubjects) ?> subject load<?= count($classSubjects) === 1 ? '' : 's' ?>
        </span>
        <a class="btn btn-outlined" href="<?= e(url('teacher/reports.php')) ?>">
            <span class="material-icons-round">assessment</span>
            Reports
        </a>
    </div>
</div>

<?php if ($classSubjects): ?>
    <div class="info-banner mb-24">
        <span class="material-icons-round">bolt</span>
        <div>
            <strong>Simple attendance entry</strong>
            <span>Choose the class, load the roster, adjust exceptions, then click Save attendance.</span>
        </div>
        <?php if (!$attendanceCs): ?>
            <a class="btn btn-sm" href="<?= e(query_url('teacher/attendance.php', ['class_subject' => (int) $classSubjects[0]['id'], 'date' => $attendanceDate])) ?>">
                Start first class
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/attendance_form.php'; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
