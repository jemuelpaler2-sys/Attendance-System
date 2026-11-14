<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

$pdo = db();

function next_student_no(PDO $pdo): string
{
    $prefix = 'S' . date('Y') . '-';
    $stmt = $pdo->prepare('SELECT student_no FROM students WHERE student_no LIKE ? ORDER BY student_no DESC LIMIT 1');
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last) {
        $next = ((int) substr((string) $last, strlen($prefix))) + 1;
    }
    return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

if (is_post()) {
    require_csrf('admin/students.php');
    $mode = post_param('mode');

    try {
        if ($mode === 'delete') {
            $id = post_int('id');
            $stmt = $pdo->prepare(
                "SELECT s.id, u.id AS user_id, u.full_name
                 FROM students s JOIN users u ON u.id = s.user_id
                 WHERE s.id = ? AND u.role = 'student' LIMIT 1"
            );
            $stmt->execute([$id]);
            $student = $stmt->fetch();

            if (!$student) {
                throw new InvalidArgumentException('Student not found.');
            }

            $delete = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $delete->execute([$student['user_id']]);

            flash($student['full_name'] . ' has been removed.', 'success');
            redirect('admin/students.php');
        }

        if (!in_array($mode, ['create', 'update'], true)) {
            throw new InvalidArgumentException('Unknown action.');
        }

        $id = post_int('id');
        $fullName = post_param('full_name');
        $email = strtolower(post_param('email'));
        $username = strtolower(post_param('username'));
        $password = (string) ($_POST['password'] ?? '');
        $studentNo = strtoupper(post_param('student_no'));
        $classId = post_int('class_id');
        $gender = post_param('gender');
        $dob = post_param('date_of_birth');
        $guardianName = post_param('guardian_name');
        $guardianPhone = post_param('guardian_phone');
        $address = post_param('address');
        $userStatus = post_param('user_status', 'active');
        $studentStatus = post_param('student_status', 'enrolled');

        $errors = [];

        if (mb_strlen($fullName) < 3) {
            $errors[] = 'Full name must be at least 3 characters.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please provide a valid email address.';
        }

        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
            $errors[] = 'Username must be 3–60 characters and may contain letters, numbers, dots, dashes or underscores.';
        }

        if (!in_array($gender, ['', 'male', 'female', 'other'], true)) {
            $errors[] = 'Invalid gender selection.';
        }

        if (!in_array($userStatus, ['active', 'inactive'], true)) {
            $errors[] = 'Invalid account status.';
        }

        if (!in_array($studentStatus, ['enrolled', 'inactive'], true)) {
            $errors[] = 'Invalid enrollment status.';
        }

        if ($dob !== '') {
            if (!is_valid_date($dob) || $dob > date('Y-m-d')) {
                $errors[] = 'Please provide a valid date of birth.';
            }
        }

        if ($mode === 'create' && strlen($password) < 8) {
            $errors[] = 'A password of at least 8 characters is required for a new student.';
        }

        if ($mode === 'update' && $password !== '' && strlen($password) < 8) {
            $errors[] = 'The new password must be at least 8 characters.';
        }

        if ($studentNo === '') {
            $studentNo = $mode === 'create' ? next_student_no($pdo) : '';
        }

        if ($studentNo !== '' && !preg_match('/^[A-Z0-9-]{3,30}$/', $studentNo)) {
            $errors[] = 'Student number may contain letters, numbers and dashes only.';
        }

        if ($classId > 0) {
            $check = $pdo->prepare('SELECT id FROM classes WHERE id = ? LIMIT 1');
            $check->execute([$classId]);
            if (!$check->fetch()) {
                $errors[] = 'The selected class does not exist.';
            }
        }

        $existing = null;
        if ($mode === 'update') {
            $stmt = $pdo->prepare(
                "SELECT s.id, s.user_id, s.student_no, u.id AS uid
                 FROM students s JOIN users u ON u.id = s.user_id
                 WHERE s.id = ? AND u.role = 'student' LIMIT 1"
            );
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            if (!$existing) {
                throw new InvalidArgumentException('Student not found.');
            }
            if ($studentNo === '') {
                $studentNo = $existing['student_no'];
            }
        }

        if (!$errors) {
            $check = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $check->execute([$email, $existing['user_id'] ?? 0]);
            if ($check->fetch()) {
                $errors[] = 'That email address is already registered.';
            }

            $check = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
            $check->execute([$username, $existing['user_id'] ?? 0]);
            if ($check->fetch()) {
                $errors[] = 'That username is already taken.';
            }

            $check = $pdo->prepare('SELECT id FROM students WHERE student_no = ? AND id <> ? LIMIT 1');
            $check->execute([$studentNo, $existing['id'] ?? 0]);
            if ($check->fetch()) {
                $errors[] = 'That student number is already in use.';
            }
        }

        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        $pdo->beginTransaction();

        if ($mode === 'create') {
            $colors = ['#1a73e8', '#188038', '#e37400', '#a142f4', '#d01884'];
            $stmt = $pdo->prepare(
                "INSERT INTO users (full_name, email, username, password_hash, role, phone, avatar_color, status)
                 VALUES (?, ?, ?, ?, 'student', ?, ?, ?)"
            );
            $stmt->execute([
                $fullName,
                $email,
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $guardianPhone !== '' ? $guardianPhone : null,
                $colors[array_rand($colors)],
                $userStatus,
            ]);
            $userId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO students (user_id, student_no, class_id, gender, date_of_birth, guardian_name, guardian_phone, address, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $studentNo,
                $classId > 0 ? $classId : null,
                $gender !== '' ? $gender : null,
                $dob !== '' ? $dob : null,
                $guardianName !== '' ? $guardianName : null,
                $guardianPhone !== '' ? $guardianPhone : null,
                $address !== '' ? $address : null,
                $studentStatus,
            ]);

            flash($fullName . ' has been enrolled successfully.', 'success');
        } else {
            $sql = 'UPDATE users SET full_name = ?, email = ?, username = ?, phone = ?, status = ?';
            $params = [$fullName, $email, $username, $guardianPhone !== '' ? $guardianPhone : null, $userStatus];
            if ($password !== '') {
                $sql .= ', password_hash = ?';
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id = ?';
            $params[] = $existing['user_id'];
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $stmt = $pdo->prepare(
                'UPDATE students
                 SET student_no = ?, class_id = ?, gender = ?, date_of_birth = ?, guardian_name = ?,
                     guardian_phone = ?, address = ?, status = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $studentNo,
                $classId > 0 ? $classId : null,
                $gender !== '' ? $gender : null,
                $dob !== '' ? $dob : null,
                $guardianName !== '' ? $guardianName : null,
                $guardianPhone !== '' ? $guardianPhone : null,
                $address !== '' ? $address : null,
                $studentStatus,
                $existing['id'],
            ]);

            flash($fullName . ' has been updated.', 'success');
        }

        $pdo->commit();
        redirect('admin/students.php');
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash($e->getMessage(), 'error');
        redirect('admin/students.php');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $e->getCode() === '23000'
            ? 'A record with the same email, username or student number already exists.'
            : 'Could not save the student. Please try again.';
        flash($message, 'error');
        redirect('admin/students.php');
    }
}

$classes = $pdo->query('SELECT id, name, grade_level, section FROM classes ORDER BY grade_level, section')->fetchAll();

$q = get_param('q');
$classFilter = get_int('class');
$page = max(1, get_int('page', 1));
$perPage = 10;

$where = ['1 = 1'];
$params = [];

if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR s.student_no LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}

if ($classFilter > 0) {
    $where[] = 's.class_id = ?';
    $params[] = $classFilter;
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM students s JOIN users u ON u.id = s.user_id WHERE $whereSql");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$pagination = pagination_data($total, $perPage, $page);

$sql = "SELECT s.*, u.full_name, u.email, u.username, u.phone, u.avatar_color,
               u.status AS user_status, u.last_login, c.name AS class_name
        FROM students s
        JOIN users u ON u.id = s.user_id
        LEFT JOIN classes c ON c.id = s.class_id
        WHERE $whereSql
        ORDER BY u.full_name
        LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

$unassigned = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE status = 'enrolled' AND class_id IS NULL")->fetchColumn();

$pageTitle = 'Students';
$pageSubtitle = 'Manage student accounts, classes and guardians.';
$activeNav = 'students';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div class="page-head-text">
        <h1>Students</h1>
        <p>Manage student accounts, class assignments and guardian details.</p>
    </div>
    <div class="page-head-actions">
        <?php if ($unassigned > 0): ?>
            <span class="chip chip-late">
                <span class="material-icons-round">warning</span>
                <?= $unassigned ?> unassigned
            </span>
        <?php endif; ?>
        <button class="btn btn-filled hide-mobile" type="button" data-modal-open="studentModal">
            <span class="material-icons-round">person_add</span>
            Add student
        </button>
    </div>
</div>

<div class="table-card">
    <div class="table-toolbar">
        <form method="get" action="<?= e(url('admin/students.php')) ?>" class="filter-bar" style="flex:1" data-auto-submit>
            <div class="search-box">
                <span class="material-icons-round">search</span>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, email or student no." aria-label="Search students">
            </div>

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

            <button class="btn btn-tonal btn-sm" type="submit">
                <span class="material-icons-round">filter_list</span>
                Filter
            </button>

            <?php if ($q !== '' || $classFilter > 0): ?>
                <a class="btn btn-text btn-sm" href="<?= e(url('admin/students.php')) ?>">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-scroll">
        <table class="data-table" style="min-width: 940px">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Contact</th>
                    <th>Class</th>
                    <th>Guardian</th>
                    <th>Status</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $student):
                    $record = [
                        '_title'         => 'Edit student',
                        '_subtitle'      => $student['full_name'] . ' · ' . $student['student_no'],
                        'mode'           => 'update',
                        'id'             => (int) $student['id'],
                        'full_name'      => $student['full_name'],
                        'email'          => $student['email'],
                        'username'       => $student['username'],
                        'student_no'     => $student['student_no'],
                        'class_id'       => $student['class_id'] !== null ? (int) $student['class_id'] : '',
                        'gender'         => $student['gender'] ?? '',
                        'date_of_birth'  => $student['date_of_birth'] ?? '',
                        'guardian_name'  => $student['guardian_name'] ?? '',
                        'guardian_phone' => $student['guardian_phone'] ?? '',
                        'address'        => $student['address'] ?? '',
                        'user_status'    => $student['user_status'],
                        'student_status' => $student['status'],
                    ];
                    $isActive = $student['user_status'] === 'active' && $student['status'] === 'enrolled';
                ?>
                    <tr>
                        <td>
                            <div class="cell-main">
                                <span class="avatar" style="background: <?= e(avatar_color($student['avatar_color'])) ?>">
                                    <?= e(initials($student['full_name'])) ?>
                                </span>
                                <div class="cell-meta">
                                    <strong><?= e($student['full_name']) ?></strong>
                                    <span><?= e($student['student_no']) ?> · @<?= e($student['username']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="cell-meta">
                                <strong style="font-weight:500"><?= e($student['email']) ?></strong>
                                <span><?= e($student['phone'] ?: 'No phone') ?></span>
                            </div>
                        </td>
                        <td>
                            <?php if ($student['class_name']): ?>
                                <span class="class-chip">
                                    <span class="material-icons-round" style="font-size:14px">class</span>
                                    <?= e($student['class_name']) ?>
                                </span>
                            <?php else: ?>
                                <span class="chip chip-late">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="cell-meta">
                                <strong style="font-weight:500"><?= e($student['guardian_name'] ?: '—') ?></strong>
                                <span><?= e($student['guardian_phone'] ?: '') ?></span>
                            </div>
                        </td>
                        <td>
                            <span class="chip <?= $isActive ? 'chip-present' : 'chip-muted' ?>">
                                <span class="material-icons-round"><?= $isActive ? 'check_circle' : 'pause_circle' ?></span>
                                <?= $isActive ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <div class="table-actions">
                                <button class="icon-btn" type="button" title="Edit student"
                                        data-modal-open="studentModal"
                                        data-record="<?= e(json_encode($record, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>">
                                    <span class="material-icons-round">edit</span>
                                </button>
                                <form method="post" class="inline-form"
                                      data-confirm="This permanently removes <?= e($student['full_name']) ?> and all related attendance records."
                                      data-confirm-title="Delete student?"
                                      data-confirm-text="Delete">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="mode" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $student['id'] ?>">
                                    <button class="icon-btn danger" type="submit" title="Delete student">
                                        <span class="material-icons-round">delete</span>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$students): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <span class="material-icons-round">group_off</span>
                                <strong>No students found</strong>
                                <p><?= ($q !== '' || $classFilter > 0) ? 'Try a different search term or class filter.' : 'Add your first student to get started.' ?></p>
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
        <?= render_pagination($pagination, ['q' => $q, 'class' => $classFilter ?: ''], 'admin/students.php') ?>
    </div>
</div>

<button class="fab only-mobile" type="button" data-modal-open="studentModal" aria-label="Add student">
    <span class="material-icons-round">person_add</span>
    Add student
</button>

<!-- Add / edit student modal -->
<div class="modal-scrim" id="studentModal" aria-hidden="true"
     data-title-add="Add student" data-subtitle-add="Create a student account and enrollment record.">
    <div class="modal modal-lg" role="dialog" aria-modal="true" aria-labelledby="studentModalTitle">
        <div class="modal-head">
            <div>
                <h2 id="studentModalTitle" data-modal-title>Add student</h2>
                <p data-modal-subtitle>Create a student account and enrollment record.</p>
            </div>
            <button class="modal-close" type="button" data-modal-close aria-label="Close">
                <span class="material-icons-round">close</span>
            </button>
        </div>

        <form method="post" action="<?= e(url('admin/students.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="create" data-modal-mode>
            <input type="hidden" name="id" value="" data-modal-id>

            <div class="modal-body">
                <div class="form-grid">
                    <div class="field">
                        <input type="text" id="full_name" name="full_name" placeholder=" " required minlength="3">
                        <label for="full_name">Full name</label>
                    </div>

                    <div class="field">
                        <input type="email" id="email" name="email" placeholder=" " required>
                        <label for="email">Email address</label>
                    </div>

                    <div class="field">
                        <input type="text" id="username" name="username" placeholder=" " required
                               pattern="[A-Za-z0-9._-]{3,60}" title="Letters, numbers, dots, dashes and underscores">
                        <label for="username">Username</label>
                    </div>

                    <div class="field">
                        <input type="password" id="student_password" name="password" placeholder=" "
                               autocomplete="new-password" data-optional-password>
                        <label for="student_password">Password</label>
                        <p class="field-hint">Required for new accounts. Leave blank to keep the current password.</p>
                    </div>

                    <div class="field">
                        <input type="text" id="student_no" name="student_no" placeholder=" ">
                        <label for="student_no">Student number</label>
                        <p class="field-hint">Leave blank to auto-generate.</p>
                    </div>

                    <div class="field">
                        <select name="class_id" id="class_id">
                            <option value="">Unassigned</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= (int) $class['id'] ?>"><?= e($class['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="class_id">Class</label>
                        <span class="select-arrow material-icons-round">expand_more</span>
                    </div>

                    <div class="field">
                        <select name="gender" id="gender">
                            <option value="">Not specified</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                        <label for="gender">Gender</label>
                        <span class="select-arrow material-icons-round">expand_more</span>
                    </div>

                    <div class="field">
                        <input type="date" id="date_of_birth" name="date_of_birth" placeholder=" " max="<?= e(date('Y-m-d')) ?>">
                        <label for="date_of_birth">Date of birth</label>
                    </div>

                    <div class="field">
                        <input type="text" id="guardian_name" name="guardian_name" placeholder=" ">
                        <label for="guardian_name">Guardian name</label>
                    </div>

                    <div class="field">
                        <input type="text" id="guardian_phone" name="guardian_phone" placeholder=" ">
                        <label for="guardian_phone">Guardian phone</label>
                    </div>

                    <div class="field span-2">
                        <textarea id="address" name="address" placeholder=" " rows="2"></textarea>
                        <label for="address">Home address</label>
                    </div>

                    <div class="field">
                        <select name="user_status" id="user_status">
                            <option value="active" selected>Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <label for="user_status">Account status</label>
                        <span class="select-arrow material-icons-round">expand_more</span>
                    </div>

                    <div class="field">
                        <select name="student_status" id="student_status">
                            <option value="enrolled" selected>Enrolled</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <label for="student_status">Enrollment status</label>
                        <span class="select-arrow material-icons-round">expand_more</span>
                    </div>
                </div>
            </div>

            <div class="modal-actions">
                <button class="btn btn-text" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-filled" type="submit">
                    <span class="material-icons-round">save</span>
                    Save student
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        /* Mount the modal at the document level so its fixed overlay is not
           constrained by the transformed .page-enter container. */
        var modal = document.getElementById('studentModal');
        if (modal && modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }
    })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
