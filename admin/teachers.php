<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

$pdo = db();

if (is_post()) {
    require_csrf('admin/teachers.php');
    $mode = post_param('mode');

    try {
        if ($mode === 'delete') {
            $id = post_int('id');
            $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
            $stmt->execute([$id]);
            $teacher = $stmt->fetch();

            if (!$teacher) {
                throw new InvalidArgumentException('Teacher not found.');
            }

            $delete = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $delete->execute([$id]);

            flash($teacher['full_name'] . ' has been removed.', 'success');
            redirect('admin/teachers.php');
        }

        if (!in_array($mode, ['create', 'update'], true)) {
            throw new InvalidArgumentException('Unknown action.');
        }

        $id = post_int('id');
        $fullName = post_param('full_name');
        $email = strtolower(post_param('email'));
        $username = strtolower(post_param('username'));
        $phone = post_param('phone');
        $password = (string) ($_POST['password'] ?? '');
        $status = post_param('status', 'active');

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

        if (!in_array($status, ['active', 'inactive'], true)) {
            $errors[] = 'Invalid account status.';
        }

        if ($mode === 'create' && strlen($password) < 8) {
            $errors[] = 'A password of at least 8 characters is required for a new teacher.';
        }

        if ($mode === 'update' && $password !== '' && strlen($password) < 8) {
            $errors[] = 'The new password must be at least 8 characters.';
        }

        $existing = null;
        if ($mode === 'update') {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            if (!$existing) {
                throw new InvalidArgumentException('Teacher not found.');
            }
        }

        if (!$errors) {
            $check = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $check->execute([$email, $existing['id'] ?? 0]);
            if ($check->fetch()) {
                $errors[] = 'That email address is already registered.';
            }

            $check = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
            $check->execute([$username, $existing['id'] ?? 0]);
            if ($check->fetch()) {
                $errors[] = 'That username is already taken.';
            }
        }

        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        if ($mode === 'create') {
            $colors = ['#1a73e8', '#188038', '#e37400', '#a142f4', '#d01884'];
            $stmt = $pdo->prepare(
                "INSERT INTO users (full_name, email, username, password_hash, role, phone, avatar_color, status)
                 VALUES (?, ?, ?, ?, 'teacher', ?, ?, ?)"
            );
            $stmt->execute([
                $fullName,
                $email,
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $phone !== '' ? $phone : null,
                $colors[array_rand($colors)],
                $status,
            ]);

            flash($fullName . ' has been added to the faculty.', 'success');
        } else {
            $sql = 'UPDATE users SET full_name = ?, email = ?, username = ?, phone = ?, status = ?';
            $params = [$fullName, $email, $username, $phone !== '' ? $phone : null, $status];
            if ($password !== '') {
                $sql .= ', password_hash = ?';
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id = ?';
            $params[] = $existing['id'];
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            flash($fullName . ' has been updated.', 'success');
        }

        redirect('admin/teachers.php');
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'error');
        redirect('admin/teachers.php');
    } catch (PDOException $e) {
        $message = $e->getCode() === '23000'
            ? 'A record with the same email or username already exists.'
            : 'Could not save the teacher. Please try again.';
        flash($message, 'error');
        redirect('admin/teachers.php');
    }
}

$q = get_param('q');
$statusFilter = get_param('status');
$page = max(1, get_int('page', 1));
$perPage = 10;

$where = ["u.role = 'teacher'"];
$params = [];

if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.username LIKE ?)';
    array_push($params, $like, $like, $like);
}

if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $where[] = 'u.status = ?';
    $params[] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$pagination = pagination_data($total, $perPage, $page);

$sql = "SELECT u.*,
            (SELECT COUNT(*) FROM class_subjects cs WHERE cs.teacher_id = u.id) AS loads,
            (SELECT COUNT(DISTINCT cs.class_id) FROM class_subjects cs WHERE cs.teacher_id = u.id) AS class_count,
            (SELECT COUNT(DISTINCT s.id) FROM students s
                JOIN class_subjects cs ON cs.class_id = s.class_id
                WHERE cs.teacher_id = u.id AND s.status = 'enrolled') AS student_count,
            (SELECT COUNT(*) FROM classes c WHERE c.adviser_id = u.id AND c.status = 'active') AS advised
        FROM users u
        WHERE $whereSql
        ORDER BY u.full_name
        LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$teachers = $stmt->fetchAll();

$pageTitle = 'Teachers';
$pageSubtitle = 'Manage faculty accounts and subject loads.';
$activeNav = 'teachers';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div class="page-head-text">
        <h1>Teachers</h1>
        <p>Manage faculty accounts, contact details and teaching loads.</p>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-outlined" href="<?= e(url('admin/assignments.php')) ?>">
            <span class="material-icons-round">assignment_ind</span>
            Assignments
        </a>
        <button class="btn btn-filled hide-mobile" type="button" data-modal-open="teacherModal">
            <span class="material-icons-round">person_add</span>
            Add teacher
        </button>
    </div>
</div>

<div class="table-card">
    <div class="table-toolbar">
        <form method="get" action="<?= e(url('admin/teachers.php')) ?>" class="filter-bar" style="flex:1" data-auto-submit>
            <div class="search-box">
                <span class="material-icons-round">search</span>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, email or username" aria-label="Search teachers">
            </div>

            <div class="field is-static">
                <select name="status" aria-label="Filter by status">
                    <option value="">All statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
                <span class="select-arrow material-icons-round">expand_more</span>
                <label>Status</label>
            </div>

            <button class="btn btn-tonal btn-sm" type="submit">
                <span class="material-icons-round">filter_list</span>
                Filter
            </button>

            <?php if ($q !== '' || $statusFilter !== ''): ?>
                <a class="btn btn-text btn-sm" href="<?= e(url('admin/teachers.php')) ?>">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-scroll">
        <table class="data-table" style="min-width: 880px">
            <thead>
                <tr>
                    <th>Teacher</th>
                    <th>Contact</th>
                    <th>Teaching load</th>
                    <th>Students</th>
                    <th>Status</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($teachers as $teacher):
                    $record = [
                        '_title'    => 'Edit teacher',
                        '_subtitle' => $teacher['full_name'] . ' · @' . $teacher['username'],
                        'mode'      => 'update',
                        'id'        => (int) $teacher['id'],
                        'full_name' => $teacher['full_name'],
                        'email'     => $teacher['email'],
                        'username'  => $teacher['username'],
                        'phone'     => $teacher['phone'] ?? '',
                        'status'    => $teacher['status'],
                    ];
                ?>
                    <tr>
                        <td>
                            <div class="cell-main">
                                <span class="avatar" style="background: <?= e(avatar_color($teacher['avatar_color'])) ?>">
                                    <?= e(initials($teacher['full_name'])) ?>
                                </span>
                                <div class="cell-meta">
                                    <strong><?= e($teacher['full_name']) ?></strong>
                                    <span>@<?= e($teacher['username']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="cell-meta">
                                <strong style="font-weight:500"><?= e($teacher['email']) ?></strong>
                                <span><?= e($teacher['phone'] ?: 'No phone') ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="row" style="gap:6px;flex-wrap:wrap">
                                <span class="class-chip"><?= (int) $teacher['loads'] ?> subjects</span>
                                <span class="badge-soft"><?= (int) $teacher['class_count'] ?> classes</span>
                                <?php if ((int) $teacher['advised'] > 0): ?>
                                    <span class="badge-soft"><span class="material-icons-round" style="font-size:14px">star</span>Adviser</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?= (int) $teacher['student_count'] ?></td>
                        <td>
                            <span class="chip <?= $teacher['status'] === 'active' ? 'chip-present' : 'chip-muted' ?>">
                                <span class="material-icons-round"><?= $teacher['status'] === 'active' ? 'check_circle' : 'pause_circle' ?></span>
                                <?= e(ucfirst($teacher['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <div class="table-actions">
                                <a class="icon-btn" href="<?= e(query_url('admin/assignments.php', ['teacher' => (int) $teacher['id']])) ?>" title="View assignments">
                                    <span class="material-icons-round">assignment_ind</span>
                                </a>
                                <button class="icon-btn" type="button" title="Edit teacher"
                                        data-modal-open="teacherModal"
                                        data-record="<?= e(json_encode($record, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>">
                                    <span class="material-icons-round">edit</span>
                                </button>
                                <form method="post" class="inline-form"
                                      data-confirm="This removes <?= e($teacher['full_name']) ?> and their subject assignments. Classes they advise will be left without an adviser."
                                      data-confirm-title="Delete teacher?"
                                      data-confirm-text="Delete">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="mode" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $teacher['id'] ?>">
                                    <button class="icon-btn danger" type="submit" title="Delete teacher">
                                        <span class="material-icons-round">delete</span>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$teachers): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <span class="material-icons-round">person_off</span>
                                <strong>No teachers found</strong>
                                <p><?= ($q !== '' || $statusFilter !== '') ? 'Try a different search or filter.' : 'Add your first teacher to start assigning subjects.' ?></p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="table-footer">
        <span class="text-sm">
            Showing <?= $total > 0 ? $pagination['offset'] + 1 : 0 ?>–<?= min($pagination['offset'] + $perPage, $total) ?> of <?= $total ?> teachers
        </span>
        <?= render_pagination($pagination, ['q' => $q, 'status' => $statusFilter], 'admin/teachers.php') ?>
    </div>
</div>

<button class="fab only-mobile" type="button" data-modal-open="teacherModal" aria-label="Add teacher">
    <span class="material-icons-round">person_add</span>
    Add teacher
</button>

<div class="modal-scrim" id="teacherModal" aria-hidden="true"
     data-title-add="Add teacher" data-subtitle-add="Create a faculty account for a teacher.">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="teacherModalTitle">
        <div class="modal-head">
            <div>
                <h2 id="teacherModalTitle" data-modal-title>Add teacher</h2>
                <p data-modal-subtitle>Create a faculty account for a teacher.</p>
            </div>
            <button class="modal-close" type="button" data-modal-close aria-label="Close">
                <span class="material-icons-round">close</span>
            </button>
        </div>

        <form method="post" action="<?= e(url('admin/teachers.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="create" data-modal-mode>
            <input type="hidden" name="id" value="" data-modal-id>

            <div class="modal-body">
                <div class="form-grid">
                    <div class="field span-2">
                        <input type="text" id="teacher_full_name" name="full_name" placeholder=" " required minlength="3">
                        <label for="teacher_full_name">Full name</label>
                    </div>

                    <div class="field">
                        <input type="email" id="teacher_email" name="email" placeholder=" " required>
                        <label for="teacher_email">Email address</label>
                    </div>

                    <div class="field">
                        <input type="text" id="teacher_username" name="username" placeholder=" " required
                               pattern="[A-Za-z0-9._-]{3,60}" title="Letters, numbers, dots, dashes and underscores">
                        <label for="teacher_username">Username</label>
                    </div>

                    <div class="field">
                        <input type="password" id="teacher_password" name="password" placeholder=" "
                               autocomplete="new-password" data-optional-password>
                        <label for="teacher_password">Password</label>
                        <p class="field-hint">Required for new accounts. Leave blank to keep the current password.</p>
                    </div>

                    <div class="field">
                        <input type="text" id="teacher_phone" name="phone" placeholder=" ">
                        <label for="teacher_phone">Phone number</label>
                    </div>

                    <div class="field span-2">
                        <select name="status" id="teacher_status">
                            <option value="active" selected>Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <span class="select-arrow material-icons-round">expand_more</span>
                        <label for="teacher_status">Account status</label>
                    </div>
                </div>
            </div>

            <div class="modal-actions">
                <button class="btn btn-text" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-filled" type="submit">
                    <span class="material-icons-round">save</span>
                    Save teacher
                </button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
