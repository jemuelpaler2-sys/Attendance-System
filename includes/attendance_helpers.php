<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/**
 * List class + subject assignments. When $teacherId is provided only that
 * teacher's loads are returned.
 */
function attendance_class_subjects(PDO $pdo, ?int $teacherId = null): array
{
    $sql = "SELECT cs.id, cs.class_id, cs.subject_id, cs.teacher_id,
                   c.name AS class_name, c.grade_level, c.section,
                   sub.code AS subject_code, sub.name AS subject_name,
                   t.full_name AS teacher_name, t.avatar_color AS teacher_color,
                   (SELECT COUNT(*) FROM students s
                     WHERE s.class_id = cs.class_id AND s.status = 'enrolled') AS student_count
            FROM class_subjects cs
            JOIN classes c ON c.id = cs.class_id
            JOIN subjects sub ON sub.id = cs.subject_id
            JOIN users t ON t.id = cs.teacher_id
            WHERE c.status = 'active'";

    $params = [];
    if ($teacherId !== null) {
        $sql .= ' AND cs.teacher_id = ?';
        $params[] = $teacherId;
    }

    $sql .= ' ORDER BY c.grade_level, c.section, sub.name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Fetch one assignment and optionally enforce teacher ownership.
 */
function attendance_access(PDO $pdo, int $classSubjectId, string $role, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT cs.id, cs.class_id, cs.subject_id, cs.teacher_id,
                c.name AS class_name, c.grade_level, c.section,
                sub.code AS subject_code, sub.name AS subject_name,
                t.full_name AS teacher_name
         FROM class_subjects cs
         JOIN classes c ON c.id = cs.class_id
         JOIN subjects sub ON sub.id = cs.subject_id
         JOIN users t ON t.id = cs.teacher_id
         WHERE cs.id = ? LIMIT 1'
    );
    $stmt->execute([$classSubjectId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    if ($role === 'teacher' && (int) $row['teacher_id'] !== $userId) {
        return null;
    }

    if (!in_array($role, ['admin', 'teacher'], true)) {
        return null;
    }

    return $row;
}

/**
 * Students enrolled in the assignment's class, with any existing attendance
 * record for the given date. Missing records default to "present".
 */
function attendance_roster(PDO $pdo, int $classSubjectId, string $date): array
{
    $stmt = $pdo->prepare(
        "SELECT s.id, s.student_no, s.gender,
                u.full_name, u.avatar_color,
                a.id AS attendance_id, a.status, a.remarks
         FROM class_subjects cs
         JOIN students s ON s.class_id = cs.class_id AND s.status = 'enrolled'
         JOIN users u ON u.id = s.user_id
         LEFT JOIN attendance a
                ON a.student_id = s.id
               AND a.class_subject_id = cs.id
               AND a.attendance_date = ?
         WHERE cs.id = ?
         ORDER BY u.full_name"
    );
    $stmt->execute([$date, $classSubjectId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        if (empty($row['status'])) {
            $row['status'] = 'present';
        }
    }
    unset($row);

    return $rows;
}

/**
 * Save a set of attendance entries. Entries are keyed by student id.
 * Each entry: ['status' => string, 'remarks' => string].
 */
function save_attendance(PDO $pdo, int $classSubjectId, string $date, array $entries, int $markedBy): int
{
    if (!is_valid_date($date) || $date > date('Y-m-d')) {
        throw new InvalidArgumentException('Attendance date must be today or earlier.');
    }

    $allowed = ['present', 'late', 'absent', 'excused'];

    $stmt = $pdo->prepare(
        "SELECT s.id
         FROM class_subjects cs
         JOIN students s ON s.class_id = cs.class_id AND s.status = 'enrolled'
         WHERE cs.id = ?"
    );
    $stmt->execute([$classSubjectId]);
    $validStudents = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if (!$validStudents) {
        throw new InvalidArgumentException('This class has no enrolled students.');
    }

    $upsert = $pdo->prepare(
        'INSERT INTO attendance (class_subject_id, student_id, attendance_date, status, remarks, marked_by)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            remarks = VALUES(remarks),
            marked_by = VALUES(marked_by),
            updated_at = CURRENT_TIMESTAMP'
    );

    $saved = 0;
    $pdo->beginTransaction();

    try {
        foreach ($entries as $studentId => $entry) {
            $studentId = (int) $studentId;
            if (!in_array($studentId, $validStudents, true)) {
                continue;
            }

            $status = is_array($entry) ? ($entry['status'] ?? 'present') : (string) $entry;
            if (!in_array($status, $allowed, true)) {
                $status = 'present';
            }

            $remarks = is_array($entry) ? trim((string) ($entry['remarks'] ?? '')) : '';
            if (mb_strlen($remarks) > 255) {
                $remarks = mb_substr($remarks, 0, 255);
            }

            $upsert->execute([
                $classSubjectId,
                $studentId,
                $date,
                $status,
                $remarks !== '' ? $remarks : null,
                $markedBy,
            ]);
            $saved++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $saved;
}

function attendance_counts(array $roster): array
{
    $counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
    foreach ($roster as $student) {
        $status = $student['status'] ?? 'present';
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
    }
    return $counts;
}
