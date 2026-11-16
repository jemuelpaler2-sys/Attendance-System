<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

/* ---------------------------------------------------------
   URLs & escaping
--------------------------------------------------------- */

function url(string $path = ''): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return BASE_URL . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/')) . '?v=' . APP_VERSION;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . (preg_match('#^https?://#i', $path) ? $path : url($path)));
    exit;
}

function query_url(string $path, array $params = []): string
{
    $params = array_filter($params, static fn ($v) => $v !== '' && $v !== null);
    return url($path) . ($params ? '?' . http_build_query($params) : '');
}

/* ---------------------------------------------------------
   Request helpers
--------------------------------------------------------- */

function get_param(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function post_param(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function get_int(string $key, int $default = 0): int
{
    return isset($_GET[$key]) ? (int) $_GET[$key] : $default;
}

function post_int(string $key, int $default = 0): int
{
    return isset($_POST[$key]) ? (int) $_POST[$key] : $default;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_valid_date(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

function is_weekend(?string $date): bool
{
    if (!$date || !is_valid_date($date)) {
        return false;
    }

    return (int) (new DateTimeImmutable($date))->format('N') >= 6;
}

function is_school_day(?string $date): bool
{
    return is_valid_date((string) $date) && !is_weekend($date);
}

function attendance_school_day_sql(string $column = 'attendance_date'): string
{
    if (!preg_match('/^[A-Za-z0-9_.]+$/', $column)) {
        throw new InvalidArgumentException('Invalid attendance date column.');
    }

    return 'DAYOFWEEK(' . $column . ') BETWEEN 2 AND 6';
}

/* ---------------------------------------------------------
   Session, auth & flash messages
--------------------------------------------------------- */

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']['id']);
}

function user_role(): string
{
    return current_user()['role'] ?? '';
}

function user_home(?string $role = null): string
{
    $role = $role ?? user_role();
    return $role !== '' ? $role . '/dashboard.php' : 'login.php';
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token)
        && $token !== ''
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf(string $fallback = 'index.php'): void
{
    if (!verify_csrf()) {
        flash('Your session expired. Please try again.', 'error');
        redirect($fallback);
    }
}

/* ---------------------------------------------------------
   Formatting & presentation
--------------------------------------------------------- */

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $parts = array_values(array_filter($parts));
    if (!$parts) {
        return '?';
    }
    $first = mb_substr($parts[0], 0, 1);
    $last  = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return mb_strtoupper($first . $last);
}

function avatar_color(?string $color): string
{
    return preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $color) ? $color : '#1a73e8';
}

function format_date(?string $date, string $format = 'M j, Y'): string
{
    if (!$date) {
        return '—';
    }
    try {
        return (new DateTime($date))->format($format);
    } catch (Exception $e) {
        return '—';
    }
}

function format_day(?string $date): string
{
    return format_date($date, 'l');
}

function greeting(): string
{
    $hour = (int) date('G');
    if ($hour < 12) {
        return 'Good morning';
    }
    if ($hour < 18) {
        return 'Good afternoon';
    }
    return 'Good evening';
}

function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    try {
        $then = new DateTime($datetime);
        $now  = new DateTime();
        $diff = $now->getTimestamp() - $then->getTimestamp();
    } catch (Exception $e) {
        return '—';
    }

    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . 'h ago';
    }
    if ($diff < 604800) {
        return floor($diff / 86400) . 'd ago';
    }
    return $then->format('M j, Y');
}

function status_meta(string $status): array
{
    return match ($status) {
        'present' => ['label' => 'Present', 'class' => 'present', 'icon' => 'check_circle'],
        'late'    => ['label' => 'Late',    'class' => 'late',    'icon' => 'schedule'],
        'absent'  => ['label' => 'Absent',  'class' => 'absent',  'icon' => 'cancel'],
        'excused' => ['label' => 'Excused', 'class' => 'excused', 'icon' => 'info'],
        default   => ['label' => ucfirst($status), 'class' => 'muted', 'icon' => 'help'],
    };
}

function status_chip(string $status): string
{
    $meta = status_meta($status);
    return '<span class="chip chip-' . $meta['class'] . '">'
        . '<span class="material-icons-round">' . $meta['icon'] . '</span>'
        . e($meta['label']) . '</span>';
}

function role_label(string $role): string
{
    return match ($role) {
        'admin'   => 'Administrator',
        'teacher' => 'Teacher',
        'student' => 'Student',
        default   => ucfirst($role),
    };
}

function attendance_rate(int $present, int $late, int $absent, int $excused): float
{
    $total = $present + $late + $absent + $excused;
    if ($total <= 0) {
        return 0.0;
    }
    return round((($present + $late) / $total) * 100, 1);
}

function rate_class(float $rate): string
{
    if ($rate >= 90) {
        return 'good';
    }
    if ($rate >= 75) {
        return 'warn';
    }
    return 'bad';
}

function current_school_year(): string
{
    $year = (int) date('Y');
    $month = (int) date('n');
    $start = $month >= 6 ? $year : $year - 1;
    return $start . '-' . ($start + 1);
}

function day_short(string $date): string
{
    return format_date($date, 'D');
}

function date_range_label(string $from, string $to): string
{
    return format_date($from) . ' – ' . format_date($to);
}

/* ---------------------------------------------------------
   Pagination
--------------------------------------------------------- */

function pagination_data(int $total, int $perPage, int $page): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = max(1, min($page, $pages));
    return [
        'total'    => $total,
        'per_page' => $perPage,
        'page'     => $page,
        'pages'    => $pages,
        'offset'   => ($page - 1) * $perPage,
    ];
}

function render_pagination(array $p, array $params, string $path): string
{
    if (($p['pages'] ?? 1) <= 1) {
        return '';
    }

    $page = (int) $p['page'];
    $pages = (int) $p['pages'];
    $html = '<nav class="pagination" aria-label="Pagination">';

    $prevParams = array_merge($params, ['page' => max(1, $page - 1)]);
    $html .= $page <= 1
        ? '<span class="page-btn disabled"><span class="material-icons-round">chevron_left</span></span>'
        : '<a class="page-btn" href="' . e(query_url($path, $prevParams)) . '" aria-label="Previous page">'
            . '<span class="material-icons-round">chevron_left</span></a>';

    $start = max(1, $page - 2);
    $end   = min($pages, $page + 2);

    if ($start > 1) {
        $html .= '<a class="page-btn" href="' . e(query_url($path, array_merge($params, ['page' => 1]))) . '">1</a>';
        if ($start > 2) {
            $html .= '<span class="page-ellipsis">…</span>';
        }
    }

    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $page ? ' active' : '';
        $html .= '<a class="page-btn' . $active . '" href="'
            . e(query_url($path, array_merge($params, ['page' => $i]))) . '">' . $i . '</a>';
    }

    if ($end < $pages) {
        if ($end < $pages - 1) {
            $html .= '<span class="page-ellipsis">…</span>';
        }
        $html .= '<a class="page-btn" href="' . e(query_url($path, array_merge($params, ['page' => $pages]))) . '">' . $pages . '</a>';
    }

    $nextParams = array_merge($params, ['page' => min($pages, $page + 1)]);
    $html .= $page >= $pages
        ? '<span class="page-btn disabled"><span class="material-icons-round">chevron_right</span></span>'
        : '<a class="page-btn" href="' . e(query_url($path, $nextParams)) . '" aria-label="Next page">'
            . '<span class="material-icons-round">chevron_right</span></a>';

    $html .= '</nav>';
    return $html;
}

/* ---------------------------------------------------------
   CSV export
--------------------------------------------------------- */

function send_csv(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}
