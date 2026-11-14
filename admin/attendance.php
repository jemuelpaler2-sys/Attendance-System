<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance_helpers.php';

require_role('admin');

$pdo = db();
$user = current_user();

/* Save attendance with a standard form submission. */
if (is_post() && post_param('save_attendance') === '1') {
    require_csrf('admin/attendance.php');

    $csId = post_int('class_subject_id');
    $postDate = post_param('attendance_date');
    $entries = $_POST['entries'] ?? [];

    $cs = attendance_access($pdo, $csId, 'admin', (int) $user['id']);

    if (!$cs || !is_valid_date($postDate) || !is_array($entries)) {
        flash('Could not save attendance. Please check the class and date.', 'error');
        redirect('admin/attendance.php');
    }

    try {
        $saved = save_attendance($pdo, $csId, $postDate, $entries, (int) $user['id']);
        flash('Attendance saved for ' . $saved . ' student' . ($saved === 1 ? '' : 's') . '.', 'success');
    } catch (Throwable $e) {
        flash('Could not save attendance. Please try again.', 'error');
    }

    redirect('admin/attendance.php?' . http_build_query(['class_subject' => $csId, 'date' => $postDate]));
}

$classSubjects = attendance_class_subjects($pdo);
$selectedId = get_int('class_subject');
$attendanceDate = get_param('date', date('Y-m-d'));

if (!is_valid_date($attendanceDate) || $attendanceDate > date('Y-m-d')) {
    $attendanceDate = date('Y-m-d');
}

$attendanceCs = null;
$roster = [];

if ($selectedId > 0) {
    $attendanceCs = attendance_access($pdo, $selectedId, 'admin', (int) $user['id']);
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
$attendanceFilterPath = 'admin/attendance.php';
$attendancePageTitle = 'Mark Attendance';

$pageTitle = 'Mark Attendance';
$pageSubtitle = 'Record or update attendance for any class.';
$activeNav = 'attendance';

$extraScripts = '<script src="' . e(asset('js/attendance.js')) . '"></script>';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div class="page-head-text">
        <h1>Mark Attendance</h1>
        <p>Select a class and date to review the roster, then save attendance updates.</p>
    </div>
    <div class="page-head-actions">
        <span class="chip chip-role">
            <span class="material-icons-round">admin_panel_settings</span>
            Administrator override
        </span>
        <a class="btn btn-outlined" href="<?= e(url('admin/reports.php')) ?>">
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
            <span>Use “Mark all present”, adjust exceptions, then click Save attendance.</span>
        </div>
        <?php if (!$attendanceCs): ?>
            <a class="btn btn-sm" href="<?= e(query_url('admin/attendance.php', ['class_subject' => (int) $classSubjects[0]['id'], 'date' => $attendanceDate])) ?>">
                Start first class
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/attendance_form.php'; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
