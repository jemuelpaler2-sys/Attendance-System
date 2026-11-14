<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

$pdo = db();

if (is_post()) {
    require_csrf('admin/assignments.php');
    $mode = post_param('mode');

    try {
        if ($mode === 'delete') {
            $id = post_int('id');
            $stmt = $pdo->prepare(
                'SELECT cs.id, c.name AS class_name, sub.name AS subject_name
                 FROM class_subjects cs
                 JOIN classes c ON c.id = cs.class_id
                 JOIN subjects sub ON sub.id = cs.subject_id
                 WHERE cs.id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $assignment = $stmt->fetch();

            if (!$assignment) {
                throw new InvalidArgumentException('Assignment not found.');
            }

            $stmt = $pdo->prepare('DELETE FROM class_subjects WHERE id = ?');
            $stmt->execute([$id]);

            flash($assignment['subject_name'] . ' removed from ' . $assignment['class_name'] . '.', 'success');
            redirect('admin/assignments.php');
        }

        if (!in_array($mode, ['create', 'update'], true)) {
            throw new InvalidArgumentException('Unknown action.');
        }

        $id = post_int('id');
        $classId = post_int('class_id');
        $subjectId = post_int('subject_id');
        $teacherId = post_int('teacher_id');

        $errors = [];

        if ($classId <= 0) {
            $errors[] = 'Please choose a class.';
        }

        if ($subjectId <= 0) {
            $errors[] = 'Please choose a subject.';
        }

        if ($teacherId <= 0) {
            $errors[] = 'Please choose a teacher.';
        }

        $existing = null;
        if ($mode === 'update') {
            $stmt = $pdo->prepare('SELECT id FROM class_subjects WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            if (!$existing) {
                throw new InvalidArgumentException('Assignment not found.');
            }
        }

        if ($classId > 0) {
            $check = $pdo->prepare('SELECT id FROM classes WHERE id = ? LIMIT 1');
            $check->execute([$classId]);
            if (!$check->fetch()) {
                $errors[] = 'The selected class does not exist.';
            }
        }

        if ($subjectId > 0) {
            $check = $pdo->prepare('SELECT id FROM subjects WHERE id = ? LIMIT 1');
            $check->execute([$subjectId]);
            if (!$check->fetch()) {
                $errors[] = 'The selected subject does not exist.';
            }
        }

        if ($teacherId > 0) {
            $check = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher' AND status = 'active' LIMIT 1");
            $check->execute([$teacherId]);
            if (!$check->fetch()) {
                $errors[] = 'The selected teacher is not active.';
            }
        }

        if (!$errors) {
            $check = $pdo->prepare('SELECT id FROM class_subjects WHERE class_id = ? AND subject_id = ? AND id <> ? LIMIT 1');
            $check->execute([$classId, $subjectId, $existing['id'] ?? 0]);
            if ($check->fetch()) {
                $errors[] = 'That subject is already assigned to this class.';
            }
        }

        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        if ($mode === 'create') {
            $stmt = $pdo->prepare('INSERT INTO class_subjects (class_id, subject_id, teacher_id) VALUES (?, ?, ?)');
            $stmt->execute([$classId, $subjectId, $teacherId]);
            flash('Subject assignment created.', 'success');
        } else {
            $stmt = $pdo->prepare('UPDATE class_subjects SET class_id = ?, subject_id = ?, teacher_id = ? WHERE id = ?');
            $stmt->execute([$classId, $subjectId, $teacherId, $existing['id']]);
            flash('Subject assignment updated.', 'success');
        }

        redirect('admin/assignments.php');
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'error');
        redirect('admin/assignments.php');
    } catch (PDOException $e) {
        flash($e->getCode() === '23000'
            ? 'That class and subject are already assigned together.'
            : 'Could not save the assignment. Please try again.', 'error');
        redirect('admin/assignments.php');
    }
}

$classes = $pdo->query('SELECT id, name, grade_level, section, status FROM classes ORDER BY grade_level, section')->fetchAll();
$subjects = $pdo->query('SELECT id, code, name FROM subjects ORDER BY code')->fetchAll();
$teachers = $pdo->query(
    "SELECT id, full_name, avatar_color FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY full_name"
)->fetchAll();

$classFilter = get_int('class');
$subjectFilter = get_int('subject');
$teacherFilter = get_int('teacher');

$where = ['1 = 1'];
$params = [];

if ($classFilter > 0) {
    $where[] = 'cs.class_id = ?';
    $params[] = $classFilter;
}

if ($subjectFilter > 0) {
    $where[] = 'cs.subject_id = ?';
    $params[] = $subjectFilter;
}

if ($teacherFilter > 0) {
    $where[] = 'cs.teacher_id = ?';
    $params[] = $teacherFilter;
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT cs.id, cs.class_id, cs.subject_id, cs.teacher_id,
            c.name AS class_name, c.grade_level, c.section, c.status AS class_status,
            sub.code AS subject_code, sub.name AS subject_name,
            t.full_name AS teacher_name, t.avatar_color,
            (SELECT COUNT(*) FROM students s
              WHERE s.class_id = cs.class_id AND s.status = 'enrolled') AS student_count
     FROM class_subjects cs
     JOIN classes c ON c.id = cs.class_id
     JOIN subjects sub ON sub.id = cs.subject_id
     JOIN users t ON t.id = cs.teacher_id
     WHERE $whereSql
     ORDER BY c.grade_level, c.section, sub.name"
);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

$activeClasses = array_values(array_filter($classes, static fn ($c) => $c['status'] === 'active'));

$pageTitle = 'Assignments';
$pageSubtitle = 'Connect classes, subjects and teachers.';
$activeNav = 'assignments';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div class="page-head-text">
        <h1>Subject Assignments</h1>
        <p>Connect a class and subject to the teacher responsible for it.</p>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-outlined" href="<?= e(url('admin/classes.php')) ?>">
            <span class="material-icons-round">class</span>
            Classes
        </a>
        <button class="btn btn-filled hide-mobile" type="button" data-modal-open="assignmentModal">
            <span class="material-icons-round">add</span>
            New assignment
        </button>
    </div>
</div>

<div class="table-card">
    <div class="table-toolbar">
        <form method="get" action="<?= e(url('admin/assignments.php')) ?>" class="filter-bar" style="flex:1" data-auto-submit>
            <div class="field is-static">
                <select name="class" aria-label="Filter by class">
                    <option value="">All classes</option>
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
                <select name="subject" aria-label="Filter by subject">
                    <option value="">All subjects</option>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?= (int) $subject['id'] ?>" <?= $subjectFilter === (int) $subject['id'] ? 'selected' : '' ?>>
                            <?= e($subject['code'] . ' · ' . $subject['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="select-arrow material-icons-round">expand_more</span>
                <label>Subject</label>
            </div>

            <div class="field is-static">
                <select name="teacher" aria-label="Filter by teacher">
                    <option value="">All teachers</option>
                    <?php foreach ($teachers as $teacher): ?>
                        <option value="<?= (int) $teacher['id'] ?>" <?= $teacherFilter === (int) $teacher['id'] ? 'selected' : '' ?>>
                            <?= e($teacher['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="select-arrow material-icons-round">expand_more</span>
                <label>Teacher</label>
            </div>

            <button class="btn btn-tonal btn-sm" type="submit">
                <span class="material-icons-round">filter_list</span>
                Filter
            </button>

            <?php if ($classFilter > 0 || $subjectFilter > 0 || $teacherFilter > 0): ?>
                <a class="btn btn-text btn-sm" href="<?= e(url('admin/assignments.php')) ?>">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-scroll">
        <table class="data-table" style="min-width: 860px">
            <thead>
                <tr>
                    <th>Class</th>
                    <th>Subject</th>
                    <th>Teacher</th>
                    <th>Students</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assignments as $assignment):
                    $record = [
                        '_title'     => 'Edit assignment',
                        '_subtitle'  => $assignment['class_name'] . ' · ' . $assignment['subject_name'],
                        'mode'       => 'update',
                        'id'         => (int) $assignment['id'],
                        'class_id'   => (int) $assignment['class_id'],
                        'subject_id' => (int) $assignment['subject_id'],
                        'teacher_id' => (int) $assignment['teacher_id'],
                    ];
                ?>
                    <tr>
                        <td>
                            <div class="cell-meta">
                                <strong><?= e($assignment['class_name']) ?></strong>
                                <span><?= e($assignment['grade_level'] . ' · ' . $assignment['section']) ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="row" style="gap:9px">
                                <span class="chip chip-role" style="font-family:monospace"><?= e($assignment['subject_code']) ?></span>
                                <span><?= e($assignment['subject_name']) ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="cell-main">
                                <span class="avatar sm" style="background: <?= e(avatar_color($assignment['avatar_color'])) ?>">
                                    <?= e(initials($assignment['teacher_name'])) ?>
                                </span>
                                <div class="cell-meta">
                                    <strong><?= e($assignment['teacher_name']) ?></strong>
                                    <span>Subject teacher</span>
                                </div>
                            </div>
                        </td>
                        <td><?= (int) $assignment['student_count'] ?></td>
                        <td>
                            <div class="table-actions">
                                <a class="icon-btn" title="Mark attendance"
                                   href="<?= e(query_url('admin/attendance.php', ['class_subject' => (int) $assignment['id'], 'date' => date('Y-m-d')])) ?>">
                                    <span class="material-icons-round">fact_check</span>
                                </a>
                                <button class="icon-btn" type="button" title="Edit assignment"
                                        data-modal-open="assignmentModal"
                                        data-record="<?= e(json_encode($record, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>">
                                    <span class="material-icons-round">edit</span>
                                </button>
                                <form method="post" class="inline-form"
                                      data-confirm="Removing this assignment also deletes its attendance records."
                                      data-confirm-title="Remove assignment?"
                                      data-confirm-text="Remove">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="mode" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $assignment['id'] ?>">
                                    <button class="icon-btn danger" type="submit" title="Remove assignment">
                                        <span class="material-icons-round">delete</span>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$assignments): ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">
                                <span class="material-icons-round">assignment_ind</span>
                                <strong>No assignments found</strong>
                                <p><?= ($classFilter > 0 || $subjectFilter > 0 || $teacherFilter > 0) ? 'Try clearing the filters.' : 'Assign a subject to a class to begin marking attendance.' ?></p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="table-footer">
        <span class="text-sm"><?= count($assignments) ?> assignment<?= count($assignments) === 1 ? '' : 's' ?> listed</span>
    </div>
</div>

<button class="fab only-mobile" type="button" data-modal-open="assignmentModal" aria-label="New assignment">
    <span class="material-icons-round">add</span>
    New assignment
</button>

<div class="modal-scrim" id="assignmentModal" aria-hidden="true"
     data-title-add="New assignment" data-subtitle-add="Assign a subject and teacher to a class.">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="assignmentModalTitle">
        <div class="modal-head">
            <div>
                <h2 id="assignmentModalTitle" data-modal-title>New assignment</h2>
                <p data-modal-subtitle>Assign a subject and teacher to a class.</p>
            </div>
            <button class="modal-close" type="button" data-modal-close aria-label="Close">
                <span class="material-icons-round">close</span>
            </button>
        </div>

        <form method="post" action="<?= e(url('admin/assignments.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="create" data-modal-mode>
            <input type="hidden" name="id" value="" data-modal-id>

            <div class="modal-body">
                <div class="form-grid">
                    <div class="field span-2">
                        <select name="class_id" id="assignment_class" required>
                            <option value="" disabled selected>Choose a class</option>
                            <?php foreach ($activeClasses as $class): ?>
                                <option value="<?= (int) $class['id'] ?>"><?= e($class['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="select-arrow material-icons-round">expand_more</span>
                        <label for="assignment_class">Class</label>
                    </div>

                    <div class="field span-2">
                        <select name="subject_id" id="assignment_subject" required>
                            <option value="" disabled selected>Choose a subject</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= (int) $subject['id'] ?>"><?= e($subject['code'] . ' · ' . $subject['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="select-arrow material-icons-round">expand_more</span>
                        <label for="assignment_subject">Subject</label>
                    </div>

                    <div class="field span-2">
                        <select name="teacher_id" id="assignment_teacher" required>
                            <option value="" disabled selected>Choose a teacher</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= (int) $teacher['id'] ?>"><?= e($teacher['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="select-arrow material-icons-round">expand_more</span>
                        <label for="assignment_teacher">Teacher</label>
                    </div>
                </div>

                <div class="form-note mt-16">
                    <span class="material-icons-round">info</span>
                    <span>Each subject can only be assigned once per class. Attendance is recorded against this assignment.</span>
                </div>
            </div>

            <div class="modal-actions">
                <button class="btn btn-text" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-filled" type="submit">
                    <span class="material-icons-round">save</span>
                    Save assignment
                </button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
