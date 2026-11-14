-- ============================================================
--  Digital Student Attendance Management System
--  Database schema + seed data
--  MySQL / MariaDB (XAMPP and InfinityFree)
--
--  Import this file into the database selected in phpMyAdmin.
--  It drops and recreates the SmartRoll tables only, so no
--  CREATE DATABASE or DROP DATABASE privilege is required.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS attendance;
DROP TABLE IF EXISTS class_subjects;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS subjects;
DROP TABLE IF EXISTS classes;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- Users (administrators, teachers, students)
-- ------------------------------------------------------------
CREATE TABLE users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name     VARCHAR(120) NOT NULL,
    email         VARCHAR(150) NOT NULL,
    username      VARCHAR(60)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','teacher','student') NOT NULL DEFAULT 'student',
    phone         VARCHAR(30)  DEFAULT NULL,
    avatar_color  VARCHAR(20)  DEFAULT '#1a73e8',
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    last_login    DATETIME     DEFAULT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Classes
-- ------------------------------------------------------------
CREATE TABLE classes (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(80) NOT NULL,
    grade_level VARCHAR(30) NOT NULL,
    section     VARCHAR(50) NOT NULL,
    school_year VARCHAR(20) NOT NULL DEFAULT '2026-2027',
    adviser_id  INT UNSIGNED DEFAULT NULL,
    status      ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_classes_adviser (adviser_id),
    CONSTRAINT fk_classes_adviser FOREIGN KEY (adviser_id)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Subjects
-- ------------------------------------------------------------
CREATE TABLE subjects (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(20)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subjects_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Student profiles
-- ------------------------------------------------------------
CREATE TABLE students (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    student_no     VARCHAR(30)  NOT NULL,
    class_id       INT UNSIGNED DEFAULT NULL,
    gender         ENUM('male','female','other') DEFAULT NULL,
    date_of_birth  DATE DEFAULT NULL,
    guardian_name  VARCHAR(120) DEFAULT NULL,
    guardian_phone VARCHAR(30)  DEFAULT NULL,
    address        VARCHAR(255) DEFAULT NULL,
    status         ENUM('enrolled','inactive') NOT NULL DEFAULT 'enrolled',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_students_user (user_id),
    UNIQUE KEY uq_students_no (student_no),
    KEY idx_students_class (class_id),
    CONSTRAINT fk_students_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_students_class FOREIGN KEY (class_id)
        REFERENCES classes (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Class + subject + teacher assignments
-- ------------------------------------------------------------
CREATE TABLE class_subjects (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_id   INT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_class_subject (class_id, subject_id),
    KEY idx_cs_teacher (teacher_id),
    CONSTRAINT fk_cs_class FOREIGN KEY (class_id)
        REFERENCES classes (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cs_subject FOREIGN KEY (subject_id)
        REFERENCES subjects (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cs_teacher FOREIGN KEY (teacher_id)
        REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Attendance records
-- ------------------------------------------------------------
CREATE TABLE attendance (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_subject_id  INT UNSIGNED NOT NULL,
    student_id        INT UNSIGNED NOT NULL,
    attendance_date   DATE NOT NULL,
    status            ENUM('present','late','absent','excused') NOT NULL DEFAULT 'present',
    remarks           VARCHAR(255) DEFAULT NULL,
    marked_by         INT UNSIGNED DEFAULT NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance (class_subject_id, student_id, attendance_date),
    KEY idx_attendance_student_date (student_id, attendance_date),
    KEY idx_attendance_date (attendance_date),
    CONSTRAINT fk_att_cs FOREIGN KEY (class_subject_id)
        REFERENCES class_subjects (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_att_student FOREIGN KEY (student_id)
        REFERENCES students (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_att_marked_by FOREIGN KEY (marked_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED DATA
-- Only the default administrator is created. All other records
-- (teachers, students, classes, subjects, attendance) start empty.
-- Default password:
--   admin : admin123
-- ============================================================

INSERT INTO users (full_name, email, username, password_hash, role, phone, avatar_color, status) VALUES
('School Administrator', 'admin@school.edu', 'admin', '$2y$10$sGDlcrzfPblOQ3.57QAFle8WB5ppWnRUm.CK1lzagDP/VDO/WNUra', 'admin', '0917 000 0001', '#1a73e8', 'active');
