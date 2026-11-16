<?php

declare(strict_types=1);

/**
 * Reusable attendance marking UI.
 *
 * Expected variables:
 *  $attendanceClassSubjects  array
 *  $attendanceSelectedId     int
 *  $attendanceDate           string (Y-m-d)
 *  $attendanceRoster         array
 *  $attendanceCs             array|null
 *  $attendanceFilterPath     string (e.g. 'admin/attendance.php')
 *  $attendancePageTitle      string
 */

$attendanceSelectedId = (int) ($attendanceSelectedId ?? 0);
$attendanceDate = $attendanceDate ?? date('Y-m-d');
$attendanceRoster = $attendanceRoster ?? [];
$attendanceCs = $attendanceCs ?? null;
$attendanceFilterPath = $attendanceFilterPath ?? 'admin/attendance.php';
$attendanceIsNoClass = is_weekend($attendanceDate);
$counts = attendance_counts($attendanceRoster);
$today = date('Y-m-d');
?>

<div class="card mb-24" style="padding:18px 20px">
    <form method="get" action="<?= e(url($attendanceFilterPath)) ?>" class="attendance-toolbar" data-auto-submit>
        <div class="field is-static">
            <select name="class_subject" required>
                <option value="" disabled <?= $attendanceSelectedId === 0 ? 'selected' : '' ?>>Choose class and subject</option>
                <?php foreach ($attendanceClassSubjects as $cs): ?>
                    <option value="<?= (int) $cs['id'] ?>" <?= $attendanceSelectedId === (int) $cs['id'] ? 'selected' : '' ?>>
                        <?= e($cs['class_name']) ?> · <?= e($cs['subject_name']) ?> (<?= e($cs['teacher_name']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="select-arrow material-icons-round">expand_more</span>
            <label>Class &amp; subject</label>
        </div>

        <div class="field is-static">
            <input type="date" name="date" value="<?= e($attendanceDate) ?>" max="<?= e($today) ?>" required>
            <label>Attendance date</label>
        </div>

        <button class="btn btn-filled" type="submit">
            <span class="material-icons-round">fact_check</span>
            Load roster
        </button>
    </form>
</div>

<?php if (!$attendanceClassSubjects): ?>
    <div class="card">
        <div class="empty-state">
            <span class="material-icons-round">event_busy</span>
            <strong>No assignments available</strong>
            <p>There are no class and subject assignments for you yet. Ask an administrator to assign a subject load.</p>
        </div>
    </div>
<?php elseif ($attendanceSelectedId === 0 || !$attendanceCs): ?>
    <div class="card">
        <div class="empty-state">
            <span class="material-icons-round">play_lesson</span>
            <strong>Select a class to begin</strong>
            <p>Choose a class and subject above, then pick a date to load the student roster and mark attendance.</p>
        </div>
    </div>
<?php elseif ($attendanceIsNoClass): ?>
    <div class="card">
        <div class="empty-state">
            <span class="material-icons-round">event_busy</span>
            <strong>No Class</strong>
            <p><?= e(format_date($attendanceDate, 'l, F j, Y')) ?> falls on a weekend. Attendance is not recorded on Saturdays or Sundays.</p>
        </div>
    </div>
<?php else: ?>
    <div class="table-card">
        <div class="table-toolbar">
            <div class="table-toolbar-title">
                <span class="material-icons-round">groups</span>
                <div>
                    <div><?= e($attendanceCs['class_name']) ?> · <?= e($attendanceCs['subject_name']) ?></div>
                    <div class="text-sm text-tertiary" style="font-weight:400;margin-top:2px">
                        <?= e(format_date($attendanceDate, 'l, F j, Y')) ?> · <?= e($attendanceCs['teacher_name']) ?>
                    </div>
                </div>
                <span class="count"><?= count($attendanceRoster) ?> students</span>
            </div>
            <div class="row">
                <span class="chip <?= $attendanceDate === $today ? 'chip-present' : 'chip-muted' ?>">
                    <span class="material-icons-round"><?= $attendanceDate === $today ? 'today' : 'history' ?></span>
                    <?= $attendanceDate === $today ? 'Today' : 'Past date' ?>
                </span>
            </div>
        </div>

        <form id="attendanceForm"
              method="post"
              action="<?= e(url($attendanceFilterPath)) ?>"
              data-attendance-form
              data-attendance-date="<?= e($attendanceDate) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="save_attendance" value="1">
            <input type="hidden" name="class_subject_id" value="<?= (int) $attendanceSelectedId ?>">
            <input type="hidden" name="attendance_date" value="<?= e($attendanceDate) ?>">

            <div class="card-head" style="padding:18px 20px 0;margin-bottom:10px">
                <div class="attendance-summary" data-attendance-summary>
                    <span class="chip chip-present"><span class="material-icons-round">check_circle</span>Present <strong data-summary="present"><?= $counts['present'] ?></strong></span>
                    <span class="chip chip-late"><span class="material-icons-round">schedule</span>Late <strong data-summary="late"><?= $counts['late'] ?></strong></span>
                    <span class="chip chip-absent"><span class="material-icons-round">cancel</span>Absent <strong data-summary="absent"><?= $counts['absent'] ?></strong></span>
                    <span class="chip chip-excused"><span class="material-icons-round">info</span>Excused <strong data-summary="excused"><?= $counts['excused'] ?></strong></span>
                </div>
                <button class="btn btn-outlined btn-sm" type="button" data-mark-all-present>
                    <span class="material-icons-round">done_all</span>
                    Mark all present
                </button>
            </div>

            <div class="attendance-list" style="padding:0 20px">
                <?php if (!$attendanceRoster): ?>
                    <div class="empty-state">
                        <span class="material-icons-round">person_off</span>
                        <strong>No enrolled students</strong>
                        <p>This class does not have any enrolled students yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($attendanceRoster as $student):
                        $sid = (int) $student['id'];
                        $status = $student['status'];
                    ?>
                        <div class="attendance-row" data-student-id="<?= $sid ?>">
                            <div class="cell-main">
                                <span class="avatar sm" style="background: <?= e(avatar_color($student['avatar_color'])) ?>">
                                    <?= e(initials($student['full_name'])) ?>
                                </span>
                                <div class="cell-meta">
                                    <strong><?= e($student['full_name']) ?></strong>
                                    <span><?= e($student['student_no']) ?></span>
                                </div>
                            </div>

                            <div class="status-picker" role="radiogroup" aria-label="Attendance status for <?= e($student['full_name']) ?>">
                                <?php foreach (['present' => 'Present', 'late' => 'Late', 'absent' => 'Absent', 'excused' => 'Excused'] as $value => $label):
                                    $meta = status_meta($value);
                                ?>
                                    <input type="radio" id="status-<?= $sid ?>-<?= $value ?>" name="entries[<?= $sid ?>][status]"
                                           value="<?= $value ?>" <?= $status === $value ? 'checked' : '' ?>>
                                    <label for="status-<?= $sid ?>-<?= $value ?>">
                                        <span class="material-icons-round"><?= $meta['icon'] ?></span>
                                        <?= $label ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                            <div class="remark-cell">
                                <input class="remark-input" type="text" name="entries[<?= $sid ?>][remarks]"
                                       value="<?= e($student['remarks'] ?? '') ?>" maxlength="255"
                                       placeholder="Add remark (optional)"
                                       aria-label="Remarks for <?= e($student['full_name']) ?>">
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($attendanceRoster): ?>
                <div class="attendance-actions">
                    <div class="save-state" id="saveState">
                        <span class="material-icons-round">edit_note</span>
                        <span id="saveStateText">Click Save attendance to store changes</span>
                    </div>
                    <div class="row">
                        <button class="btn btn-text" type="button" onclick="window.location.reload()">
                            <span class="material-icons-round">refresh</span>
                            Reset
                        </button>
                        <button class="btn btn-filled" type="submit" id="saveAttendanceBtn">
                            <span class="material-icons-round">save</span>
                            Save attendance
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </form>
    </div>
<?php endif; ?>
