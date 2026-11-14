<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

$pdo = db();

if (is_post()) {
    require_csrf('admin/classes.php');
    $mode = post_param('mode');

    try {
        if ($mode === 'delete') {
            $id = post_int('id');
            $stmt = $pdo->prepare('SELECT id, name FROM classes WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $class = $stmt->fetch();

            if (!$class) {
                throw new InvalidArgumentException('Class not found.');
            }

            $delete = $pdo->prepare('DELETE FROM classes WHERE id = ?');
            $delete->execute([$id]);

            flash($class['name'] . ' has been deleted.', 'success');
            redirect('admin/classes.php');
        }

        if (!in_array($mode, ['create', 'update'], true)) {
            throw new InvalidArgumentException('Unknown action.');
        }

        $id = post_int('id');
        $name = post_param('name');
        $gradeLevel = post_param('grade_level');
        $section = post_param('section');
        $schoolYear = post_param('school_year');
        $adviserId = post_int('adviser_id');
        $status = post_param('status', 'active');

        if ($name === '' && $gradeLevel !== '' && $section !== '') {
            $name = $gradeLevel . ' - ' . $section;
        }

        $errors = [];

        if (mb_strlen($name) < 2) {
            $errors[] = 'Class name is required.';
        }

        if ($gradeLevel === '') {
            $errors[] = 'Grade level is required.';
        }

        if ($section === '') {
            $errors[] = 'Section is required.';
        }

        if ($schoolYear === '') {
            $schoolYear = current_school_year();
        }

        if (!preg_match('/^\d{4}-\d{4}$/', $schoolYear)) {
            $errors[] = 'School year must use the format YYYY-YYYY.';
        }

        if (!in_array($status, ['active', 'archived'], true)) {
            $errors[] = 'Invalid class status.';
        }

        $existing = null;
        if ($mode === 'update') {
            $stmt = $pdo->prepare('SELECT id FROM classes WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            if (!$existing) {
                throw new InvalidArgumentException('Class not found.');
            }
        }

        if ($adviserId > 0) {
            $check = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher' AND status = 'active' LIMIT 1");
            $check->execute([$adviserId]);
            if (!$check->fetch()) {
                $errors[] = 'The selected adviser is not an active teacher.';
            }
        }

        if (!$errors) {
            $check = $pdo->prepare('SELECT id FROM classes WHERE name = ? AND school_year = ? AND id <> ? LIMIT 1');
            $check->execute([$name, $schoolYear, $existing['id'] ?? 0]);
            if ($check->fetch()) {
                $errors[] = 'A class with that name already exists for this school year.';
            }
        }

        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        if ($mode === 'create') {
            $stmt = $pdo->prepare(
                'INSERT INTO classes (name, grade_level, section, school_year, adviser_id, status)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $name,
                $gradeLevel,
                $section,
                $schoolYear,
                $adviserId > 0 ? $adviserId : null,
                $status,
            ]);

            flash($name . ' has been created.', 'success');
        } else {
            $stmt = $pdo->prepare(
                'UPDATE classes SET name = ?, grade_level = ?, section = ?, school_year = ?, adviser_id = ?, status = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $name,
                $gradeLevel,
                $section,
                $schoolYear,
                $adviserId > 0 ? $adviserId : null,
                $status,
                $existing['id'],
            ]);

            flash($name . ' has been updated.', 'success');
        }

        redirect('admin/classes.php');
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'error');
        redirect('admin/classes.php');
    } catch (PDOException $e) {
        flash($e->getCode() === '23000'
            ? 'That class conflicts with an existing record.'
            : 'Could not save the class. Please try again.', 'error');
        redirect('admin/classes.php');
    }
}

$teachers = $pdo->query(
    "SELECT id, full_name, avatar_color FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY full_name"
)->fetchAll();

$q = get_param('q');
$statusFilter = get_param('status');

$where = ['1 = 1'];
$params = [];

if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(c.name LIKE ? OR c.grade_level LIKE ? OR c.section LIKE ?)';
    array_push($params, $like, $like, $like);
}

if (in_array($statusFilter, ['active', 'archived'], true)) {
    $where[] = 'c.status = ?';
    $params[] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT c.*, t.full_name AS adviser_name, t.avatar_color AS adviser_color,
            (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND s.status = 'enrolled') AS students,
            (SELECT COUNT(*) FROM class_subjects cs WHERE cs.class_id = c.id) AS loads
     FROM classes c
     LEFT JOIN users t ON t.id = c.adviser_id
     WHERE $whereSql
     ORDER BY c.grade_level, c.section"
);
$stmt->execute($params);
$classes = $stmt->fetchAll();

$pageTitle = 'Classes';
$pageSubtitle = 'Organize sections, advisers and class rosters.';
$activeNav = 'classes';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div class="page-head-text">
        <h1>Classes</h1>
        <p>Organize sections, assign advisers and keep school-year records tidy.</p>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-outlined" href="<?= e(url('admin/subjects.php')) ?>">
            <span class="material-icons-round">menu_book</span>
            Subjects
        </a>
        <button class="btn btn-filled hide-mobile" type="button" data-modal-open="classModal">
            <span class="material-icons-round">add</span>
            Add class
        </button>
    </div>
</div>

<div class="table-card">
    <div class="table-toolbar">
        <form method="get" action="<?= e(url('admin/classes.php')) ?>" class="filter-bar" style="flex:1" data-auto-submit>
            <div class="search-box">
                <span class="material-icons-round">search</span>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search class, grade or section" aria-label="Search classes">
            </div>

            <div class="field is-static">
                <select name="status" aria-label="Filter by status">
                    <option value="">All statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>Archived</option>
                </select>
                <span class="select-arrow material-icons-round">expand_more</span>
                <label>Status</label>
            </div>

            <button class="btn btn-tonal btn-sm" type="submit">
                <span class="material-icons-round">filter_list</span>
                Filter
            </button>

            <?php if ($q !== '' || $statusFilter !== ''): ?>
                <a class="btn btn-text btn-sm" href="<?= e(url('admin/classes.php')) ?>">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-scroll">
        <table class="data-table" style="min-width: 920px">
            <thead>
                <tr>
                    <th>Class</th>
                    <th>Adviser</th>
                    <th>Students</th>
                    <th>Subject loads</th>
                    <th>School year</th>
                    <th>Status</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($classes as $class):
                    $record = [
                        '_title'      => 'Edit class',
                        '_subtitle'   => $class['name'] . ' · ' . $class['school_year'],
                        'mode'        => 'update',
                        'id'          => (int) $class['id'],
                        'name'        => $class['name'],
                        'grade_level' => $class['grade_level'],
                        'section'     => $class['section'],
                        'school_year' => $class['school_year'],
                        'adviser_id'  => $class['adviser_id'] !== null ? (int) $class['adviser_id'] : '',
                        'status'      => $class['status'],
                    ];
                ?>
                    <tr>
                        <td>
                            <div class="cell-main">
                                <span class="avatar" style="background: var(--primary-container);color:var(--on-primary-container)">
                                    <span class="material-icons-round" style="font-size:20px">class</span>
                                </span>
                                <div class="cell-meta">
                                    <strong><?= e($class['name']) ?></strong>
                                    <span><?= e($class['grade_level']) ?> · <?= e($class['section']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if ($class['adviser_name']): ?>
                                <div class="cell-main">
                                    <span class="avatar sm" style="background: <?= e(avatar_color($class['adviser_color'])) ?>">
                                        <?= e(initials($class['adviser_name'])) ?>
                                    </span>
                                    <div class="cell-meta">
                                        <strong><?= e($class['adviser_name']) ?></strong>
                                        <span>Class adviser</span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="text-sm text-tertiary">No adviser</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?= e(query_url('admin/students.php', ['class' => (int) $class['id']])) ?>" class="class-chip">
                                <span class="material-icons-round" style="font-size:14px">groups</span>
                                <?= (int) $class['students'] ?>
                            </a>
                        </td>
                        <td>
                            <a href="<?= e(query_url('admin/assignments.php', ['class' => (int) $class['id']])) ?>" class="badge-soft">
                                <span class="material-icons-round" style="font-size:14px">assignment</span>
                                <?= (int) $class['loads'] ?>
                            </a>
                        </td>
                        <td><?= e($class['school_year']) ?></td>
                        <td>
                            <span class="chip <?= $class['status'] === 'active' ? 'chip-present' : 'chip-muted' ?>">
                                <span class="material-icons-round"><?= $class['status'] === 'active' ? 'check_circle' : 'archive' ?></span>
                                <?= e(ucfirst($class['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <div class="table-actions">
                                <button class="icon-btn" type="button" title="Edit class"
                                        data-modal-open="classModal"
                                        data-record="<?= e(json_encode($record, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>">
                                    <span class="material-icons-round">edit</span>
                                </button>
                                <form method="post" class="inline-form"
                                      data-confirm="Deleting <?= e($class['name']) ?> also removes its subject assignments and attendance history. Students become unassigned."
                                      data-confirm-title="Delete class?"
                                      data-confirm-text="Delete">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="mode" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $class['id'] ?>">
                                    <button class="icon-btn danger" type="submit" title="Delete class">
                                        <span class="material-icons-round">delete</span>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$classes): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <span class="material-icons-round">class</span>
                                <strong>No classes found</strong>
                                <p><?= ($q !== '' || $statusFilter !== '') ? 'Try a different search or filter.' : 'Create your first class to begin organizing students.' ?></p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="table-footer">
        <span class="text-sm"><?= count($classes) ?> class<?= count($classes) === 1 ? '' : 'es' ?> listed</span>
    </div>
</div>

<button class="fab only-mobile" type="button" data-modal-open="classModal" aria-label="Add class">
    <span class="material-icons-round">add</span>
    Add class
</button>

<div class="modal-scrim" id="classModal" aria-hidden="true"
     data-title-add="Add class" data-subtitle-add="Create a section and assign an adviser.">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="classModalTitle">
        <div class="modal-head">
            <div>
                <h2 id="classModalTitle" data-modal-title>Add class</h2>
                <p data-modal-subtitle>Create a section and assign an adviser.</p>
            </div>
            <button class="modal-close" type="button" data-modal-close aria-label="Close">
                <span class="material-icons-round">close</span>
            </button>
        </div>

        <form method="post" action="<?= e(url('admin/classes.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="create" data-modal-mode>
            <input type="hidden" name="id" value="" data-modal-id>

            <div class="modal-body">
                <div class="form-grid">
                    <div class="field span-2">
                        <input type="text" id="class_name" name="name" placeholder=" ">
                        <label for="class_name">Class name</label>
                        <p class="field-hint">Leave blank to build the name from grade level and section.</p>
                    </div>

                    <div class="field">
                        <input type="text" id="grade_level" name="grade_level" placeholder=" " required>
                        <label for="grade_level">Grade level</label>
                    </div>

                    <div class="field">
                        <input type="text" id="section" name="section" placeholder=" " required>
                        <label for="section">Section</label>
                    </div>

                    <div class="field">
                        <input type="text" id="school_year" name="school_year" placeholder=" " required
                               value="<?= e(current_school_year()) ?>" pattern="\d{4}-\d{4}" title="Format: YYYY-YYYY">
                        <label for="school_year">School year</label>
                    </div>

                    <div class="field">
                        <select name="adviser_id" id="adviser_id">
                            <option value="">No adviser</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= (int) $teacher['id'] ?>"><?= e($teacher['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="select-arrow material-icons-round">expand_more</span>
                        <label for="adviser_id">Class adviser</label>
                    </div>

                    <div class="field span-2">
                        <select name="status" id="class_status">
                            <option value="active" selected>Active</option>
                            <option value="archived">Archived</option>
                        </select>
                        <span class="select-arrow material-icons-round">expand_more</span>
                        <label for="class_status">Status</label>
                    </div>
                </div>
            </div>

            <div class="modal-actions">
                <button class="btn btn-text" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-filled" type="submit">
                    <span class="material-icons-round">save</span>
                    Save class
                </button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
