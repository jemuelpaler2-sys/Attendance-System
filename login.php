<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

guest_only();

$pdo = db();
$error = '';
$loginValue = '';

if (is_post()) {
    require_csrf('login.php');

    $loginValue = post_param('login');
    $password = (string) ($_POST['password'] ?? '');

    if ($loginValue === '' || $password === '') {
        $error = 'Please enter your email or username and password.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1');
        $stmt->execute([$loginValue, $loginValue]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            usleep(250000);
            $error = 'Invalid email, username or password. Please try again.';
        } elseif ($user['status'] !== 'active') {
            $error = 'This account is inactive. Please contact the school administrator.';
        } else {
            session_regenerate_id(true);

            $_SESSION['user'] = [
                'id'           => (int) $user['id'],
                'full_name'    => $user['full_name'],
                'email'        => $user['email'],
                'username'     => $user['username'],
                'role'         => $user['role'],
                'phone'        => $user['phone'],
                'avatar_color' => $user['avatar_color'],
            ];

            $update = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
            $update->execute([$user['id']]);

            $firstName = explode(' ', trim($user['full_name']))[0];
            flash('Welcome back, ' . $firstName . '!', 'success');
            redirect(user_home($user['role']));
        }
    }
}

$flash = get_flash();

/*
|--------------------------------------------------------------------------
| About the Developer – editable portfolio content
|--------------------------------------------------------------------------
| Replace the placeholder text and set image paths / URLs when available.
| Image paths are relative to the project root, for example:
|   'assets/img/developer.jpg'
|   'assets/certificates/certificate-1.jpg'
| Leave optional values as empty strings to keep showing their placeholder.
*/
$developerProfile = [
    'photo'    => '', // [INSERT DEVELOPER PROFILE PICTURE HERE]
    'name'     => '[INSERT FULL NAME HERE]',
    'role'     => '[INSERT PROFESSIONAL TITLE OR ROLE HERE]',
    'bio'      => '[INSERT SHORT DEVELOPER BIOGRAPHY HERE]',
    'email'    => '', // [INSERT EMAIL ADDRESS HERE – OPTIONAL]
    'location' => '', // [INSERT LOCATION HERE – OPTIONAL]
    'facebook' => '', // [INSERT FACEBOOK PROFILE URL HERE – OPTIONAL]
    'github'   => '', // [INSERT GITHUB PROFILE URL HERE – OPTIONAL]
    'portfolio' => '', // [INSERT PORTFOLIO URL HERE – OPTIONAL]
    'certificates' => [
        [
            'image'       => '', // [INSERT CERTIFICATE IMAGE 1 HERE]
            'title'       => '[INSERT CERTIFICATE TITLE 1 HERE]',
            'description' => '[INSERT CERTIFICATE DESCRIPTION 1 HERE – OPTIONAL]',
        ],
        [
            'image'       => '', // [INSERT CERTIFICATE IMAGE 2 HERE]
            'title'       => '[INSERT CERTIFICATE TITLE 2 HERE]',
            'description' => '[INSERT CERTIFICATE DESCRIPTION 2 HERE – OPTIONAL]',
        ],
        [
            'image'       => '', // [INSERT CERTIFICATE IMAGE 3 HERE]
            'title'       => '[INSERT CERTIFICATE TITLE 3 HERE]',
            'description' => '[INSERT CERTIFICATE DESCRIPTION 3 HERE – OPTIONAL]',
        ],
    ],
];

$developerLinks = [
    [
        'icon'        => 'mail',
        'label'       => 'Email',
        'href'        => $developerProfile['email'] !== '' ? 'mailto:' . $developerProfile['email'] : '',
        'placeholder' => '[INSERT EMAIL HERE]',
        'external'    => false,
    ],
    [
        'icon'        => 'public',
        'label'       => 'Facebook',
        'href'        => $developerProfile['facebook'],
        'placeholder' => '[INSERT FACEBOOK PROFILE URL HERE – OPTIONAL]',
        'external'    => true,
    ],
    [
        'icon'        => 'code',
        'label'       => 'GitHub',
        'href'        => $developerProfile['github'],
        'placeholder' => '[INSERT GITHUB PROFILE URL HERE – OPTIONAL]',
        'external'    => true,
    ],
    [
        'icon'        => 'language',
        'label'       => 'Portfolio',
        'href'        => $developerProfile['portfolio'],
        'placeholder' => '[INSERT PORTFOLIO URL HERE – OPTIONAL]',
        'external'    => true,
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1a73e8">
    <title>Sign in · <?= e(APP_NAME) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
    <script>
        (function () {
            try {
                var saved = localStorage.getItem("smartroll-theme");
                if (saved === "dark") document.documentElement.setAttribute("data-theme", "dark");
            } catch (e) { }
        })();
    </script>
</head>
<body class="auth-body">
<div class="auth-layout">
    <section class="auth-hero">
        <div class="auth-brand">
            <span class="logo-mark"><span class="material-icons-round">how_to_reg</span></span>
            <div>
                <strong><?= e(APP_NAME) ?></strong>
                <span><?= e(SCHOOL_NAME) ?></span>
            </div>
        </div>

        <div class="auth-hero-body">
            <span class="auth-eyebrow">
                <span class="material-icons-round" style="font-size:15px">auto_awesome</span>
                Smart attendance, made simple
            </span>
            <h1>Every class.<br>Every student.<br><em>Accounted for.</em></h1>
            <p class="lead">
                Replace paper registers with a centralized attendance workspace built for
                administrators, teachers and students. Record, monitor and report in seconds.
            </p>

            <div class="auth-features">
                <div class="auth-feature">
                    <span class="material-icons-round">bolt</span>
                    One-tap attendance
                </div>
                <div class="auth-feature">
                    <span class="material-icons-round">insights</span>
                    Live analytics
                </div>
                <div class="auth-feature">
                    <span class="material-icons-round">devices</span>
                    Works on any device
                </div>
                <div class="auth-feature">
                    <span class="material-icons-round">lock</span>
                    Role-based access
                </div>
            </div>
        </div>

        <div class="auth-hero-footer">
            <span class="material-icons-round" style="font-size:16px">verified_user</span>
            School year <?= e(current_school_year()) ?> · Secure school portal
        </div>
    </section>

    <section class="auth-panel">
        <button class="icon-btn" type="button" data-theme-toggle aria-label="Toggle dark mode"
                style="position:absolute;top:22px;right:22px">
            <span class="material-icons-round">dark_mode</span>
        </button>

        <div class="auth-card">
            <div class="auth-card-head">
                <span class="logo-mark xl"><span class="material-icons-round">how_to_reg</span></span>
                <h2>Welcome back</h2>
                <p>Sign in to continue to your attendance workspace.</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>">
                    <span class="material-icons-round"><?= $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'warning' ? 'warning' : 'info') ?></span>
                    <span><?= e($flash['message']) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error">
                    <span class="material-icons-round">error</span>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('login.php')) ?>" class="auth-form" autocomplete="on">
                <?= csrf_field() ?>

                <div class="field">
                    <input type="text" id="login" name="login" placeholder=" "
                           value="<?= e($loginValue) ?>" required autofocus autocomplete="username">
                    <label for="login">Email or username</label>
                </div>

                <div class="field has-toggle">
                    <input type="password" id="password" name="password" placeholder=" "
                           required autocomplete="current-password">
                    <label for="password">Password</label>
                    <button class="password-toggle" type="button" data-password-toggle="password" aria-label="Show password">
                        <span class="material-icons-round">visibility_off</span>
                    </button>
                </div>

                <div class="auth-help">
                    <span>Forgot your password? Contact the registrar.</span>
                </div>

                <button class="btn btn-filled btn-lg btn-block" type="submit">
                    <span class="material-icons-round">login</span>
                    Sign in
                </button>
            </form>
        </div>

        <p class="auth-footer">
            &copy; <?= date('Y') ?> <?= e(SCHOOL_NAME) ?>. All rights reserved.
            <span class="auth-footer-sep">&middot;</span>
            <button class="auth-footer-link" type="button" data-modal-open="aboutDeveloperModal">
                <span class="material-icons-round">person</span>
                About the Developer
            </button>
        </p>
    </section>
</div>

<!-- About the Developer modal -->
<div class="modal-scrim" id="aboutDeveloperModal" aria-hidden="true">
    <div class="modal about-modal" role="dialog" aria-modal="true" aria-labelledby="aboutDeveloperTitle">
        <div class="modal-head">
            <div>
                <h2 id="aboutDeveloperTitle">About the Developer</h2>
                <p>The person behind SmartRoll.</p>
            </div>
            <button class="modal-close" type="button" data-modal-close aria-label="Close">
                <span class="material-icons-round">close</span>
            </button>
        </div>

        <div class="modal-body about-body">
            <!-- Profile -->
            <section class="about-profile" aria-label="Developer profile">
                <div class="about-avatar">
                    <?php if ($developerProfile['photo'] !== ''): ?>
                        <img src="<?= e(url($developerProfile['photo'])) ?>" alt="<?= e($developerProfile['name']) ?>">
                    <?php else: ?>
                        <span class="material-icons-round">add_a_photo</span>
                        <span class="about-placeholder">[INSERT DEVELOPER PROFILE PICTURE HERE]</span>
                    <?php endif; ?>
                </div>

                <div class="about-profile-info">
                    <h3><?= e($developerProfile['name']) ?></h3>
                    <span class="about-role"><?= e($developerProfile['role']) ?></span>
                    <p class="about-bio"><?= e($developerProfile['bio']) ?></p>

                    <div class="about-meta">
                        <?php if ($developerProfile['email'] !== ''): ?>
                            <a class="about-meta-item" href="mailto:<?= e($developerProfile['email']) ?>">
                                <span class="material-icons-round">mail</span>
                                <?= e($developerProfile['email']) ?>
                            </a>
                        <?php else: ?>
                            <span class="about-meta-item about-placeholder">
                                <span class="material-icons-round">mail</span>
                                [INSERT EMAIL ADDRESS HERE – OPTIONAL]
                            </span>
                        <?php endif; ?>

                        <?php if ($developerProfile['location'] !== ''): ?>
                            <span class="about-meta-item">
                                <span class="material-icons-round">place</span>
                                <?= e($developerProfile['location']) ?>
                            </span>
                        <?php else: ?>
                            <span class="about-meta-item about-placeholder">
                                <span class="material-icons-round">place</span>
                                [INSERT LOCATION HERE – OPTIONAL]
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- Introduction -->
            <section class="about-section" aria-labelledby="aboutIntroTitle">
                <div class="about-section-head">
                    <span class="material-icons-round">person</span>
                    <div>
                        <h3 id="aboutIntroTitle">About the Developer</h3>
                        <p>Background and purpose behind the project.</p>
                    </div>
                </div>
                <div class="about-copy">
                    <p>Hello! I am [DEVELOPER NAME], the developer behind SmartRoll, a Student Attendance Management System developed for Nonok Norte National High School.</p>
                    <p>I am passionate about technology, software development, and creating digital solutions that help improve everyday processes.</p>
                    <p>Through this project, I aim to demonstrate my knowledge and skills in system development, user interface design, database management, and problem-solving.</p>
                </div>
            </section>

            <!-- Technical skills -->
            <section class="about-section" aria-labelledby="aboutSkillsTitle">
                <div class="about-section-head">
                    <span class="material-icons-round">build</span>
                    <div>
                        <h3 id="aboutSkillsTitle">Technical Skills</h3>
                        <p>Replace each label with the technologies you actually used.</p>
                    </div>
                </div>
                <div class="about-skills">
                    <div class="skill-card">
                        <span class="skill-icon material-icons-round">code</span>
                        <span class="about-placeholder">[INSERT PROGRAMMING LANGUAGE]</span>
                    </div>
                    <div class="skill-card">
                        <span class="skill-icon material-icons-round">devices</span>
                        <span class="about-placeholder">[INSERT FRONTEND TECHNOLOGIES]</span>
                    </div>
                    <div class="skill-card">
                        <span class="skill-icon material-icons-round">dns</span>
                        <span class="about-placeholder">[INSERT BACKEND TECHNOLOGIES]</span>
                    </div>
                    <div class="skill-card">
                        <span class="skill-icon material-icons-round">storage</span>
                        <span class="about-placeholder">[INSERT DATABASE TECHNOLOGY]</span>
                    </div>
                    <div class="skill-card">
                        <span class="skill-icon material-icons-round">construction</span>
                        <span class="about-placeholder">[INSERT DEVELOPMENT TOOLS]</span>
                    </div>
                    <div class="skill-card">
                        <span class="skill-icon material-icons-round">extension</span>
                        <span class="about-placeholder">[INSERT OTHER TECHNICAL SKILLS]</span>
                    </div>
                </div>
            </section>

            <!-- Certificates -->
            <section class="about-section" aria-labelledby="aboutCertTitle">
                <div class="about-section-head">
                    <span class="material-icons-round">workspace_premium</span>
                    <div>
                        <h3 id="aboutCertTitle">Certificates &amp; Achievements</h3>
                        <p>Click a certificate card to view it larger.</p>
                    </div>
                </div>

                <div class="about-cert-grid">
                    <?php foreach ($developerProfile['certificates'] as $certificateIndex => $certificate): ?>
                        <figure class="cert-card"
                                data-cert-title="<?= e($certificate['title']) ?>"
                                data-cert-description="<?= e($certificate['description']) ?>">
                            <button class="cert-media" type="button" data-certificate-open
                                    aria-label="View <?= e($certificate['title']) ?>">
                                <span class="cert-media-inner" data-cert-media>
                                    <?php if ($certificate['image'] !== ''): ?>
                                        <img src="<?= e(url($certificate['image'])) ?>"
                                             alt="<?= e($certificate['title']) ?>" loading="lazy">
                                    <?php else: ?>
                                        <span class="about-placeholder">
                                            [INSERT CERTIFICATE IMAGE <?= $certificateIndex + 1 ?> HERE]
                                        </span>
                                    <?php endif; ?>
                                </span>
                                <span class="cert-media-overlay" aria-hidden="true">
                                    <span class="material-icons-round">zoom_in</span>
                                </span>
                            </button>
                            <figcaption class="cert-body">
                                <strong><?= e($certificate['title']) ?></strong>
                                <?php if ($certificate['description'] !== ''): ?>
                                    <p><?= e($certificate['description']) ?></p>
                                <?php endif; ?>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>

                    <div class="cert-card cert-card-add">
                        <span class="material-icons-round">add_photo_alternate</span>
                        <strong>[ADD MORE CERTIFICATES HERE]</strong>
                        <span>Add another entry to the certificates list in login.php.</span>
                    </div>
                </div>
            </section>

            <!-- Project information -->
            <section class="about-section" aria-labelledby="aboutProjectTitle">
                <div class="about-section-head">
                    <span class="material-icons-round">workspaces</span>
                    <div>
                        <h3 id="aboutProjectTitle">Project Information</h3>
                        <p>Details about SmartRoll.</p>
                    </div>
                </div>
                <div class="about-project-grid">
                    <div class="about-project-item">
                        <span>Project name</span>
                        <strong>SmartRoll</strong>
                    </div>
                    <div class="about-project-item">
                        <span>Project type</span>
                        <strong>Student Attendance Management System</strong>
                    </div>
                    <div class="about-project-item">
                        <span>Institution</span>
                        <strong>Nonok Norte National High School</strong>
                    </div>
                    <div class="about-project-item">
                        <span>School year</span>
                        <strong>2026–2027</strong>
                    </div>
                    <div class="about-project-item about-project-desc">
                        <span>Project description</span>
                        <strong>A digital attendance management platform designed to simplify student attendance recording, student information management, attendance monitoring, and report generation.</strong>
                    </div>
                </div>
            </section>

            <!-- Contact -->
            <section class="about-section" aria-labelledby="aboutContactTitle">
                <div class="about-section-head">
                    <span class="material-icons-round">alternate_email</span>
                    <div>
                        <h3 id="aboutContactTitle">Contact &amp; Social Links</h3>
                        <p>Only links with real URLs become clickable.</p>
                    </div>
                </div>
                <div class="about-contact">
                    <?php foreach ($developerLinks as $link): ?>
                        <?php if ($link['href'] !== ''): ?>
                            <a class="about-contact-link"
                               href="<?= e($link['href']) ?>"
                               <?= $link['external'] ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                                <span class="material-icons-round"><?= e($link['icon']) ?></span>
                                <span><?= e($link['label']) ?></span>
                            </a>
                        <?php else: ?>
                            <span class="about-contact-link is-placeholder">
                                <span class="material-icons-round"><?= e($link['icon']) ?></span>
                                <span><?= e($link['placeholder']) ?></span>
                            </span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </div>
</div>

<!-- Certificate lightbox -->
<div class="certificate-lightbox" id="certificateLightbox" aria-hidden="true">
    <div class="cert-lightbox-card" role="dialog" aria-modal="true" aria-labelledby="certLightboxTitle">
        <button class="modal-close cert-lightbox-close" type="button" data-cert-lightbox-close aria-label="Close certificate preview">
            <span class="material-icons-round">close</span>
        </button>
        <div class="cert-lightbox-media" data-cert-lightbox-media></div>
        <div class="cert-lightbox-caption">
            <h3 id="certLightboxTitle" data-cert-lightbox-title></h3>
            <p data-cert-lightbox-desc></p>
        </div>
    </div>
</div>

<div class="snackbar" id="snackbar" role="status" aria-live="polite">
    <span class="material-icons-round snackbar-icon">check_circle</span>
    <span class="snackbar-text"></span>
    <button type="button" data-snackbar-close>Dismiss</button>
</div>

<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/about-developer.js')) ?>"></script>
</body>
</html>
