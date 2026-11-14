<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

$pdo = db();

if (is_post()) {
    require_csrf('admin/subjects.php');
    $mode = post_param('mode');

    try {
        if ($mode === 'delete') {
            $id = post_int('id');
            $stmt = $pdo->prepare('SELECT id, name FROM subjects WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $subject = $stmt->fetch();

            if (!$subject) {
                throw new InvalidArgumentException('Subject not found.');
            }

            $stmt = $pdo->prepare('DELETE FROM subjects WHERE id = ?');
            $stmt->execute([$id]);

            flash($subject['name'] . ' has been deleted.', 'success');
            redirect('admin/subjects.php');
        }

        if (!in_array($mode, ['create', 'update'], true)) {
            throw new InvalidArgumentException('Unknown action.');
        }

        $id = post_int('id');
        $code = strtoupper(post_param('code'));
        $name = post_param('name');
        $description = post_param('description');

        $errors = [];

        if (!preg_match('/^[A-Z0-9-]{2,20}$/', $code)) {
            $errors[] = 'Subject code must be 2–20 characters (letters, numbers or dashes).';
        }

        if (mb_strlen($name) < 2) {
            $errors[] = 'Subject name is required.';
        }

        if (mb_strlen($description) > 255) {
            $errors[] = 'Description must be 255 characters or fewer.';
        }

        $existing = null;
        if ($mode === 'update') {
            $stmt = $pdo->prepare('SELECT id FROM subjects WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            if (!$existing) {
                throw new InvalidArgumentException('Subject not found.');
            }
        }

        if (!$errors) {
            $check = $pdo->prepare('SELECT id FROM subjects WHERE code = ? AND id <> ? LIMIT 1');
            $check->execute([$code, $existing['id'] ?? 0]);
            if ($check->fetch()) {
                $errors[] = 'That subject code is already in use.';
            }
        }

        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        if ($mode === 'create') {
            $stmt = $pdo->prepare('INSERT INTO subjects (code, name, description) VALUES (?, ?, ?)');
            $stmt->execute([$code, $name, $description !== '' ? $description : null]);
            flash($name . ' has been added.', 'success');
        } else {
            $stmt = $pdo->prepare('UPDATE subjects SET code = ?, name = ?, description = ? WHERE id = ?');
            $stmt->execute([$code, $name, $description !== '' ? $description : null, $existing['id']]);
            flash($name . ' has been updated.', 'success');
        }

        redirect('admin/subjects.php');
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'error');
        redirect('admin/subjects.php');
    } catch (PDOException $e) {
        flash($e->getCode() === '23000'
            ? 'That subject conflicts with an existing record.'
            : 'Could not save the subject. Please try again.', 'error');
        redirect('admin/subjects.php');
    }
}

$q = get_param('q');
$where = '1 = 1';
$params = [];

if ($q !== '') {
    $like = '%' . $q . '%';
    $where = '(s.code LIKE ? OR s.name LIKE ?)';
    array_push($params, $like, $like);
}

$stmt = $pdo->prepare(
    "SELECT s.*,
            (SELECT COUNT(*) FROM class_subjects cs WHERE cs.subject_id = s.id) AS loads
     FROM subjects s
     WHERE $where
     ORDER BY s.code"
);
$stmt->execute($params);
$subjects = $stmt->fetchAll();

$pageTitle = 'Subjects';
$pageSubtitle = 'Maintain the school subject catalog.';
$activeNav = 'subjects';

require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div class="page-head-text">
        <h1>Subjects</h1>
        <p>Maintain the subject catalog used in class and teacher assignments.</p>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-outlined" href="<?= e(url('admin/assignments.php')) ?>">
            <span class="material-icons-round">assignment_ind</span>
            Assignments
        </a>
        <button class="btn btn-filled hide-mobile" type="button" data-modal-open="subjectModal">
            <span class="material-icons-round">add</span>
            Add subject
        </button>
    </div>
</div>

<div class="table-card">
    <div class="table-toolbar">
        <form method="get" action="<?= e(url('admin/subjects.php')) ?>" class="filter-bar" style="flex:1">
            <div class="search-box">
                <span class="material-icons-round">search</span>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by code or name" aria-label="Search subjects">
            </div>
            <button class="btn btn-tonal btn-sm" type="submit">
                <span class="material-icons-round">search</span>
                Search
            </button>
            <?php if ($q !== ''): ?>
                <a class="btn btn-text btn-sm" href="<?= e(url('admin/subjects.php')) ?>">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-scroll">
        <table class="data-table" style="min-width: 760px">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Subject</th>
                    <th>Description</th>
                    <th>Class loads</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subjects as $subject):
                    $record = [
                        '_title'      => 'Edit subject',
                        '_subtitle'   => $subject['code'] . ' · ' . $subject['name'],
                        'mode'        => 'update',
                        'id'          => (int) $subject['id'],
                        'code'        => $subject['code'],
                        'name'        => $subject['name'],
                        'description' => $subject['description'] ?? '',
                    ];
                ?>
                    <tr>
                        <td>
                            <span class="chip chip-role" style="font-family:monospace;letter-spacing:0.5px"><?= e($subject['code']) ?></span>
                        </td>
                        <td>
                            <div class="cell-meta">
                                <strong><?= e($subject['name']) ?></strong>
                                <span><?= (int) $subject['loads'] ?> class load<?= (int) $subject['loads'] === 1 ? '' : 's' ?></span>
                            </div>
                        </td>
                        <td class="text-sm text-muted" style="max-width:380px"><?= e($subject['description'] ?: '—') ?></td>
                        <td>
                            <a class="badge-soft" href="<?= e(query_url('admin/assignments.php', ['subject' => (int) $subject['id']])) ?>">
                                <span class="material-icons-round" style="font-size:14px">assignment</span>
                                <?= (int) $subject['loads'] ?>
                            </a>
                        </td>
                        <td>
                            <div class="table-actions">
                                <button class="icon-btn" type="button" title="Edit subject"
                                        data-modal-open="subjectModal"
                                        data-record="<?= e(json_encode($record, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>">
                                    <span class="material-icons-round">edit</span>
                                </button>
                                <form method="post" class="inline-form"
                                      data-confirm="Deleting <?= e($subject['name']) ?> also removes its class assignments and related attendance records."
                                      data-confirm-title="Delete subject?"
                                      data-confirm-text="Delete">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="mode" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $subject['id'] ?>">
                                    <button class="icon-btn danger" type="submit" title="Delete subject">
                                        <span class="material-icons-round">delete</span>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$subjects): ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">
                                <span class="material-icons-round">menu_book</span>
                                <strong>No subjects found</strong>
                                <p><?= $q !== '' ? 'Try a different search term.' : 'Add your first subject to start assigning classes.' ?></p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="table-footer">
        <span class="text-sm"><?= count($subjects) ?> subject<?= count($subjects) === 1 ? '' : 's' ?> listed</span>
    </div>
</div>

<button class="fab only-mobile" type="button" data-modal-open="subjectModal" aria-label="Add subject">
    <span class="material-icons-round">add</span>
    Add subject
</button>

<div class="modal-scrim" id="subjectModal" aria-hidden="true"
     data-title-add="Add subject" data-subtitle-add="Add a subject to the school catalog.">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="subjectModalTitle">
        <div class="modal-head">
            <div>
                <h2 id="subjectModalTitle" data-modal-title>Add subject</h2>
                <p data-modal-subtitle>Add a subject to the school catalog.</p>
            </div>
            <button class="modal-close" type="button" data-modal-close aria-label="Close">
                <span class="material-icons-round">close</span>
            </button>
        </div>

        <form method="post" action="<?= e(url('admin/subjects.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="create" data-modal-mode>
            <input type="hidden" name="id" value="" data-modal-id>

            <div class="modal-body">
                <div class="form-grid">
                    <div class="field">
                        <input type="text" id="subject_code" name="code" placeholder=" " required
                               pattern="[A-Za-z0-9-]{2,20}" title="2-20 letters, numbers or dashes" style="text-transform:uppercase">
                        <label for="subject_code">Subject code</label>
                    </div>

                    <div class="field">
                        <input type="text" id="subject_name" name="name" placeholder=" " required minlength="2">
                        <label for="subject_name">Subject name</label>
                    </div>

                    <div class="field span-2">
                        <textarea id="subject_description" name="description" placeholder=" " maxlength="255" rows="3"></textarea>
                        <label for="subject_description">Description</label>
                    </div>
                </div>
            </div>

            <div class="modal-actions">
                <button class="btn btn-text" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-filled" type="submit">
                    <span class="material-icons-round">save</span>
                    Save subject
                </button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
