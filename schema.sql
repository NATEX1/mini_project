-- Hospital Patient Registration and Queue Management System
-- Database Schema for phpMyAdmin / MySQL / MariaDB
-- Character set: utf8mb4

CREATE DATABASE IF NOT EXISTS `hospital_queue_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `hospital_queue_db`;

-- --------------------------------------------------------
-- Table structure for `patients`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `queues`;
DROP TABLE IF EXISTS `visits`;
DROP TABLE IF EXISTS `staff`;
DROP TABLE IF EXISTS `departments`;
DROP TABLE IF EXISTS `patients`;

CREATE TABLE `patients` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `hn` VARCHAR(20) NOT NULL UNIQUE COMMENT 'เลขประจำตัวผู้ป่วย (HN)',
  `national_id` VARCHAR(13) NOT NULL UNIQUE COMMENT 'เลขบัตรประชาชน',
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `gender` VARCHAR(10) NULL COMMENT 'ชาย, หญิง, อื่นๆ',
  `date_of_birth` DATE NULL,
  `phone_number` VARCHAR(20) NULL,
  `address` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `departments`
-- --------------------------------------------------------
CREATE TABLE `departments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `department_code` VARCHAR(20) NOT NULL UNIQUE COMMENT 'รหัสแผนก เช่น MED, SUR, PED',
  `department_name` VARCHAR(150) NOT NULL COMMENT 'ชื่อแผนก/คลินิก',
  `description` TEXT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `staff`
-- --------------------------------------------------------
CREATE TABLE `staff` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `role` VARCHAR(50) NOT NULL DEFAULT 'เจ้าหน้าที่ลงทะเบียน' COMMENT 'เจ้าหน้าที่ลงทะเบียน, ผู้ดูแลระบบ',
  `phone_number` VARCHAR(20) NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `visits`
-- --------------------------------------------------------
CREATE TABLE `visits` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `patient_id` INT NOT NULL,
  `department_id` INT NOT NULL,
  `staff_id` INT NULL COMMENT 'เจ้าหน้าที่ผู้ทำรายการลงทะเบียน',
  `visit_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
  `status` VARCHAR(30) DEFAULT 'registered' COMMENT 'registered, completed, cancelled',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_visits_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_visits_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_visits_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `queues`
-- --------------------------------------------------------
CREATE TABLE `queues` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `visit_id` INT NOT NULL UNIQUE,
  `department_id` INT NOT NULL,
  `queue_number` VARCHAR(20) NOT NULL COMMENT 'หมายเลขคิว เช่น A001',
  `queue_sequence` INT NOT NULL COMMENT 'ลำดับคิวตามการลงทะเบียนประจำวัน',
  `status` VARCHAR(30) NOT NULL DEFAULT 'waiting' COMMENT 'waiting, called, serving, completed, cancelled',
  `called_at` TIMESTAMP NULL DEFAULT NULL,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_queues_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_queues_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Seed Initial Sample Data
-- --------------------------------------------------------

-- 1. Departments
INSERT INTO `departments` (`id`, `department_code`, `department_name`, `description`, `is_active`) VALUES
(1, 'MED', 'แผนกอายุรกรรม (Internal Medicine)', 'ตรวจรักษาโรคทั่วไป ความดัน เบาหวาน โรคระบบทางเดินหายใจ', 1),
(2, 'PED', 'แผนกกุมารเวชกรรม (Pediatrics)', 'ตรวจรักษาโรคเด็กและวัคซีนสำหรับเด็ก', 1),
(3, 'SUR', 'แผนกศัลยกรรม (Surgery)', 'ตรวจรักษาแผล ผ่าตัด ศัลยกรรมทั่วไป', 1),
(4, 'ORTHO', 'แผนกกระดูกและข้อ (Orthopedics)', 'ตรวจรักษากระดูก กล้ามเนื้อ และข้อ', 1),
(5, 'DEN', 'แผนกทันตกรรม (Dental Clinic)', 'ขูดหินปูน อุดฟัน ถอนฟัน และทันตกรรมเฉพาะทาง', 1),
(6, 'ER', 'แผนกอุบัติเหตุและฉุกเฉิน (Emergency)', 'ดูแลผู้ป่วยกรณีฉุกเฉิน 24 ชม.', 1);

-- 2. Staff
-- Default Passwords:
-- admin: admin123  (hash: password_hash('admin123', PASSWORD_DEFAULT))
-- staff1: staff123 (hash: password_hash('staff123', PASSWORD_DEFAULT))
INSERT INTO `staff` (`id`, `username`, `password_hash`, `first_name`, `last_name`, `role`, `phone_number`, `is_active`) VALUES
(1, 'admin', '$2y$10$KXqKMLHIrCHbei1lypdzoeYP4XOuVVDPZP9dhN1ftrMf.lIcazE7W', 'สมชาย', 'ใจดี (ผู้ดูแลระบบ)', 'ผู้ดูแลระบบ', '0812345678', 1),
(2, 'staff1', '$2y$10$P.LdQRGnms72yrfOx2K7bOup1E.i73xfV3wxOFXrNW6WB9e1o3UHO', 'วิภาดา', 'บริการดี', 'เจ้าหน้าที่ลงทะเบียน', '0898765432', 1);

-- 3. Patients
INSERT INTO `patients` (`id`, `hn`, `national_id`, `first_name`, `last_name`, `gender`, `date_of_birth`, `phone_number`, `address`) VALUES
(1, 'HN-260001', '1100200300401', 'กิตติศักดิ์', 'เจริญสุข', 'ชาย', '1992-05-15', '0811112222', '123/45 ถนนสุขุมวิท แขวงคลองเตย กรุงเทพมหานคร'),
(2, 'HN-260002', '1100200300402', 'อารียา', 'สุวรรณรัตน์', 'หญิง', '1998-08-21', '0822223333', '88/9 หมู่ 2 ตำบลบางกรวย อำเภอบางกรวย จ.นนทบุรี'),
(3, 'HN-260003', '1100200300403', 'ณัฐวุฒิ', 'ทองคำ', 'ชาย', '1985-12-03', '0833334444', '50/12 ซอยพหลโยธิน 8 แขวงสามเสนใน เขตพญาไท กรุงเทพฯ'),
(4, 'HN-260004', '1100200300404', 'พรพิมล', 'พิทักษ์ธรรม', 'หญิง', '2018-04-10', '0844445555', '9/99 ถนนแจ้งวัฒนะ ปากเกร็ด นนทบุรี');

-- 4. Visits & Queues (Today's sample queues)
INSERT INTO `visits` (`id`, `patient_id`, `department_id`, `staff_id`, `visit_date`, `status`) VALUES
(1, 1, 1, 2, CURRENT_TIMESTAMP, 'registered'),
(2, 2, 5, 2, CURRENT_TIMESTAMP, 'registered'),
(3, 3, 1, 2, CURRENT_TIMESTAMP, 'registered'),
(4, 4, 2, 2, CURRENT_TIMESTAMP, 'registered');

INSERT INTO `queues` (`id`, `visit_id`, `department_id`, `queue_number`, `queue_sequence`, `status`, `called_at`, `completed_at`) VALUES
(1, 1, 1, 'MED-001', 1, 'serving', CURRENT_TIMESTAMP, NULL),
(2, 2, 5, 'DEN-001', 1, 'waiting', NULL, NULL),
(3, 3, 1, 'MED-002', 2, 'waiting', NULL, NULL),
(4, 4, 2, 'PED-001', 1, 'called', CURRENT_TIMESTAMP, NULL);
