# SmartRoll - Digital Student Attendance Management System

SmartRoll is a lightweight, role-based attendance management system for schools. It runs with free PHP and MySQL hosting, including the InfinityFree free plan, and uses only HTML5, CSS3, vanilla JavaScript, PHP 8, PDO, and MySQL/MariaDB.

No paid hosting, paid domain, paid SSL, paid API, Composer, Node.js, SSH, or root access is required.

## Features

### Administrator
- Dashboard with school-wide attendance statistics and recent activity.
- Manage students, teachers, classes, subjects, and class-subject assignments.
- Create teacher and student accounts with enrollment details.
- Mark or update attendance for any class and subject.
- Generate attendance reports by class, student, and date range.
- Print reports using the browser's print function or save them as PDF.
- Export filtered reports to CSV using PHP.
- Manage own profile and password.

### Teacher
- Dashboard limited to assigned classes and subjects.
- Mark daily attendance: present, late, absent, or excused.
- View students enrolled in assigned classes.
- View and filter attendance history and reports.
- Print reports or save them as PDF using the browser.
- Export class or student reports to CSV.
- Manage own profile and password.

### Student
- Dashboard with personal attendance summary and recent records.
- View personal attendance history with class, subject, and date filters.
- See attendance percentage calculations.
- Read-only access; students cannot mark attendance.
- Manage own profile and password.

### General
- Session-based authentication with role authorization.
- CSRF protection on all form submissions.
- Attendance saving uses a standard HTML form POST; no API or AJAX endpoint is required.
- PDO prepared statements for database access.
- Responsive Material-inspired interface with light and dark modes.
- Collapsible navigation, table search, snackbar notifications, and pagination.
- Attendance records track the teacher who marked them and the last update time.

## Requirements

- PHP 8.1 or newer. InfinityFree currently provides PHP 8.4; the application is tested with PHP 8.2 and is compatible with newer PHP 8.x releases.
- MySQL or MariaDB.
- Apache with PHP support.
- A modern browser with JavaScript enabled.

The application does not require Composer, Node.js, build tools, SSH, cron jobs, premium APIs, or a dedicated server.

## Local Development Installation

1. Place the project in the XAMPP web root:

   ```text
   C:\xampp\htdocs\attendance-system
   ```

2. Start **Apache** and **MySQL** from the XAMPP Control Panel.

3. Open `http://localhost/phpmyadmin` and create a database named `attendance_db` using `utf8mb4_unicode_ci`.

4. Select `attendance_db`, open **Import**, and import `database.sql`.

   Alternatively, after creating the database, run this from Command Prompt:

   ```bat
   C:\xampp\mysql\bin\mysql.exe -u root attendance_db < C:\xampp\htdocs\attendance-system\database.sql
   ```

   PowerShell users can run:

   ```powershell
   Get-Content -Raw C:\xampp\htdocs\attendance-system\database.sql | C:\xampp\mysql\bin\mysql.exe -u root attendance_db
   ```

5. Confirm the local database settings in `config/config.local.php`:

   ```php
   'db' => [
       'host' => '127.0.0.1',
       'port' => '3306',
       'name' => 'attendance_db',
       'user' => 'root',
       'pass' => '',
   ],
   ```

6. Open the application:

   ```text
   http://localhost/attendance-system/
   ```

## InfinityFree Deployment

The project is designed to run on the InfinityFree free hosting plan with a free subdomain and its free MySQL database.

1. Create a free InfinityFree account and a free subdomain.
2. In the InfinityFree control panel, create a free MySQL database.
3. Copy the **MySQL host**, **database name**, **username**, and **password** shown in the control panel.
4. Edit `config/config.online.php` and replace the placeholders with those values:

   ```php
   'db' => [
       'host' => 'sqlXXX.infinityfree.com',
       'port' => '3306',
       'name' => 'if0_XXXXXXXX_attendance_db',
       'user' => 'if0_XXXXXXXX',
       'pass' => 'your-database-password',
   ],
   ```

5. Upload all application files to the free account's `htdocs` directory using the InfinityFree File Manager or a free FTP client.
6. Open the InfinityFree phpMyAdmin, select the database you created, and import `database.sql`.
7. Open the application through `https://your-subdomain`. InfinityFree free subdomains include HTTPS by default; use the direct subdomain without `www`.

The online database host must be the exact MySQL hostname shown in the InfinityFree control panel. `localhost` does not work for the hosted database.

Do not run `CREATE DATABASE`, `DROP DATABASE`, or `USE` statements during import. `database.sql` is written for an already-selected database and only creates the application tables.

### Environment Selection

SmartRoll chooses its database configuration automatically:

- `localhost`, `127.0.0.1`, private network addresses, `.local`, and `.test` hosts use `config/config.local.php`.
- A public subdomain such as `your-site.infinityfreeapp.com` uses `config/config.online.php`.
- If environment variables are available, set `SMARTROLL_ENV` to `local` or `online` to override detection.

Local mode enables debug messages. Online mode disables them. Keep `debug` set to `false` in `config/config.online.php`.

## Demo Accounts

| Role | Username | Password |
| --- | --- | --- |
| Administrator | `admin` | `admin123` |
| Teacher | `maria.santos` | `teacher123` |
| Student | `juan.dela` | `student123` |

Additional seeded teachers use `teacher123`; additional seeded students use `student123`. Change these credentials after installation.

## Attendance Rules

- Administrators can mark attendance for any class-subject assignment.
- Teachers can mark attendance only for their assigned classes and subjects.
- Students can view only their own attendance records.
- Statuses are `present`, `late`, `absent`, and `excused`.
- One record exists per student, class-subject, and date. Saving again updates the existing record.
- Future attendance dates are rejected.
- Attendance percentage is calculated from present and late records.

## Free Hosting Limits

The application is optimized for free shared hosting:

- All data is stored in the free MySQL database.
- Queries are compatible with MySQL 8 and MariaDB, including strict `ONLY_FULL_GROUP_BY` mode.
- The SQL import creates tables only and uses no database-level administrative privileges.
- Reports use browser printing and PHP-generated CSV files instead of paid PDF or reporting services.
- Attendance is saved through normal PHP form submissions, without a separate API service.
- Email notifications are optional and are not included, so no SMTP service is required.
- No background workers, queues, cron jobs, SSH access, or server-side packages are required.
- Google Fonts and Material Icons are loaded from free public CDNs. If those CDNs are unavailable, pages still work with fallback fonts and text labels.
- Queries and responses are kept small to respect free-plan database and bandwidth limits.

InfinityFree may impose free-plan limits such as database size, daily request limits, and temporary security checks. The application does not depend on SSH, cron jobs, background processes, or premium server features.

## Project Structure

```text
attendance-system/
├── admin/                  Administrator pages
├── assets/
│   ├── css/style.css       Material-inspired design system
│   └── js/                 Navigation, modals, snackbars, theme, attendance form behavior
├── config/
│   ├── config.php          Environment loader, app constants, session setup
│   ├── config.local.php    Local XAMPP database settings
│   ├── config.online.php   InfinityFree database settings
│   └── database.php        PDO singleton
├── includes/
│   ├── attendance_form.php Shared attendance marking UI
│   ├── attendance_helpers.php
│   ├── auth.php            Login and role guards
│   ├── footer.php
│   ├── functions.php       Helpers, CSRF, flash messages, formatting
│   ├── header.php
│   └── sidebar.php
├── student/                Student pages
├── teacher/                Teacher pages
├── database.sql            Portable schema and seed data
├── index.php               Entry point and role redirect
├── login.php
├── logout.php
└── profile.php
```

## Security Notes

- All POST requests require a valid CSRF token.
- Passwords are stored with PHP `password_hash()`.
- Database access uses prepared statements with emulated prepares disabled.
- Session cookies are HTTP-only and use `SameSite=Lax`.
- Role guards protect every admin, teacher, and student page.
- `.htaccess` rules block direct access to configuration files, SQL files, and Markdown files on Apache.
- `config.online.php` contains database credentials. Never expose or share it after entering real credentials.
- Change all demo passwords after installation.
- Keep `debug` disabled in online mode.

## Troubleshooting

**Database connection error**
- Confirm the MySQL service is running locally or that the InfinityFree database exists.
- Verify the database host, name, username, and password in the active configuration file.
- Confirm `database.sql` was imported into the selected database.

**Import fails with database permission errors**
- Select the existing database in phpMyAdmin before importing.
- Do not add `CREATE DATABASE`, `DROP DATABASE`, or `USE` statements.

**Page redirects to login unexpectedly**
- Confirm cookies are enabled and PHP sessions are writable.
- Use the same subdomain and protocol consistently.

**Reports do not print correctly**
- Use the Print button on the report page, then choose the browser's print or Save as PDF option.
- Enable background graphics in the browser print settings if colors are needed.

**InfinityFree shows a security check page**
- Complete the browser challenge, then reload the application.
