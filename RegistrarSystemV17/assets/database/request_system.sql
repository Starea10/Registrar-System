-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 30, 2026 at 02:08 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `request_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `archive_history`
--

CREATE TABLE `archive_history` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `claiming_date` date DEFAULT NULL,
  `requester_id` int(11) DEFAULT NULL,
  `source` enum('walkin','online') NOT NULL DEFAULT 'walkin',
  `status` enum('pending','processing','for_signature','for_release','released') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `student_number` varchar(50) DEFAULT NULL,
  `student_name` varchar(255) DEFAULT NULL,
  `is_archived` tinyint(1) DEFAULT 0,
  `released_at` datetime DEFAULT NULL,
  `document_path` varchar(255) DEFAULT NULL,
  `email_address` varchar(128) DEFAULT NULL,
  `student_id_image` varchar(255) DEFAULT NULL,
  `registration_form_image` varchar(255) DEFAULT NULL,
  `clearance_form_image` varchar(255) DEFAULT NULL,
  `proof_of_graduation_image` varchar(255) DEFAULT NULL,
  `id_picture_image` varchar(255) DEFAULT NULL,
  `honorable_dismissal_image` varchar(255) DEFAULT NULL,
  `government_id_image` varchar(255) DEFAULT NULL,
  `original_document_image` varchar(255) DEFAULT NULL,
  `photocopy_document_image` varchar(255) DEFAULT NULL,
  `photocopy_document_image_2` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_trail`
--

CREATE TABLE `audit_trail` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_trail`
--

INSERT INTO `audit_trail` (`id`, `user_id`, `action`, `details`, `created_at`) VALUES
(1, 2, 'approve_online_request', 'Approved online request #2 (Transcript of Record (TOR)) for Baguisi sd Jhonric (2022222222); created request #1', '2026-09-30 00:06:07'),
(2, 2, 'disapprove_online_request', 'Disapproved online request #1 (Transcript of Record (TOR)) for Baguisi dsds Jhonric (2022222222) -- reason: test', '2026-09-30 00:06:22'),
(3, 1, 'update_request', 'Updated request #1 status from \'pending\' to \'processing\'', '2026-09-30 00:06:27');

-- --------------------------------------------------------

--
-- Table structure for table `landing_images`
--

CREATE TABLE `landing_images` (
  `id` int(11) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `alt_text` varchar(255) NOT NULL DEFAULT 'Students',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `landing_images`
--

INSERT INTO `landing_images` (`id`, `image_path`, `display_order`, `alt_text`, `created_at`) VALUES
(15, 'assets/images/landing/064af04230b2b5e83582fc0948075b12.png', 9, 'Students', '2026-09-29 12:08:56'),
(16, 'assets/images/landing/0684eb73dbb37e5f99e7964e2f515a0f.png', 10, 'Students', '2026-09-29 13:38:11');

-- --------------------------------------------------------

--
-- Table structure for table `online_requests`
--

CREATE TABLE `online_requests` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `student_number` varchar(50) DEFAULT NULL,
  `student_name` varchar(255) DEFAULT NULL,
  `program` varchar(255) DEFAULT NULL,
  `document_type` varchar(100) DEFAULT NULL,
  `purpose` varchar(255) DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `email_address` varchar(128) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `student_id_image` varchar(255) DEFAULT NULL,
  `registration_form_image` varchar(255) DEFAULT NULL,
  `clearance_form_image` varchar(255) DEFAULT NULL,
  `proof_of_graduation_image` varchar(255) DEFAULT NULL,
  `id_picture_image` varchar(255) DEFAULT NULL,
  `honorable_dismissal_image` varchar(255) DEFAULT NULL,
  `government_id_image` varchar(255) DEFAULT NULL,
  `original_document_image` varchar(255) DEFAULT NULL,
  `photocopy_document_image` varchar(255) DEFAULT NULL,
  `photocopy_document_image_2` varchar(255) DEFAULT NULL,
  `tor_request_type` enum('first_time','previous') DEFAULT NULL,
  `tor_previous_confirmed` tinyint(1) DEFAULT 0,
  `status` enum('pending','approved','disapproved') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `disapproval_reason` text DEFAULT NULL,
  `approved_request_id` int(11) DEFAULT NULL,
  `tracking_code` varchar(24) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `online_requests`
--

INSERT INTO `online_requests` (`id`, `title`, `description`, `student_number`, `student_name`, `program`, `document_type`, `purpose`, `contact_number`, `email_address`, `notes`, `student_id_image`, `registration_form_image`, `clearance_form_image`, `proof_of_graduation_image`, `id_picture_image`, `honorable_dismissal_image`, `government_id_image`, `original_document_image`, `photocopy_document_image`, `photocopy_document_image_2`, `tor_request_type`, `tor_previous_confirmed`, `status`, `reviewed_by`, `reviewed_at`, `disapproval_reason`, `approved_request_id`, `tracking_code`, `created_at`, `updated_at`) VALUES
(1, 'Transcript of Record (TOR)', 'Student Number: 2022222222\nStudent Name: Baguisi dsds Jhonric\nProgram: BS in Fisheries and Aquatic Sciences\nContact Information: 09225558888\nEmail: jhonricbaguisi7@gmail.com\nPurpose: Employment\nRequested Document: Transcript of Record (TOR)', '2022222222', 'Baguisi dsds Jhonric', 'BS in Fisheries and Aquatic Sciences', 'TOR', 'Employment', '09225558888', 'jhonricbaguisi7@gmail.com', '', 'uploads/documents/c929450fc8f2a0941f4ff85e7374655e.jpg', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'previous', 1, 'disapproved', 2, '2026-09-30 08:06:22', 'test', NULL, 'CVSU-REGKpFcpbj8', '2026-09-29 23:25:56', '2026-09-30 00:06:22'),
(2, 'Transcript of Record (TOR)', 'Student Number: 2022222222\nStudent Name: Baguisi sd Jhonric\nProgram: Bachelor of Science in Information Technology\nContact Information: 09395259908\nEmail: jhonricbaguisi7@gmail.com\nPurpose: Employment\nRequested Document: Transcript of Record (TOR)', '2022222222', 'Baguisi sd Jhonric', 'Bachelor of Science in Information Technology', 'TOR', 'Employment', '09395259908', 'jhonricbaguisi7@gmail.com', '', 'uploads/documents/a869d19722967d9dfaf4a0d737128a8a.jpg', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'previous', 1, 'approved', 2, '2026-09-30 08:06:07', NULL, 1, 'CVSU-REGSXn59f2N', '2026-09-30 00:02:30', '2026-09-30 00:06:07');

-- --------------------------------------------------------

--
-- Table structure for table `programs`
--

CREATE TABLE `programs` (
  `program` varchar(16) NOT NULL,
  `program_name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `programs`
--

INSERT INTO `programs` (`program`, `program_name`) VALUES
('BEEd', 'Bachelor in Elementary Education'),
('BSBA', 'Bachelor of Science in Business Administration'),
('BSCS', 'Bachelor of Science in Computer Science'),
('BSEd', 'Bachelor in Secondary Education'),
('BSFAS', 'BS in Fisheries and Aquatic Sciences'),
('BSHM', 'Bachelor of Science in Hospitality Management'),
('BSIT', 'Bachelor of Science in Information Technology'),
('LSHS', 'Laboratory Science High School'),
('TCP', 'Teacher Certificate Program');

-- --------------------------------------------------------

--
-- Table structure for table `requests`
--

CREATE TABLE `requests` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `claiming_date` date DEFAULT NULL,
  `requester_id` int(11) DEFAULT NULL,
  `source` enum('walkin','online') NOT NULL DEFAULT 'walkin',
  `status` enum('pending','processing','for_signature','for_release','released') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `student_number` varchar(50) DEFAULT NULL,
  `student_name` varchar(255) DEFAULT NULL,
  `is_archived` tinyint(1) DEFAULT 0,
  `released_at` datetime DEFAULT NULL,
  `document_path` varchar(255) DEFAULT NULL,
  `email_address` varchar(128) DEFAULT NULL,
  `student_id_image` varchar(255) DEFAULT NULL,
  `registration_form_image` varchar(255) DEFAULT NULL,
  `clearance_form_image` varchar(255) DEFAULT NULL,
  `proof_of_graduation_image` varchar(255) DEFAULT NULL,
  `id_picture_image` varchar(255) DEFAULT NULL,
  `honorable_dismissal_image` varchar(255) DEFAULT NULL,
  `government_id_image` varchar(255) DEFAULT NULL,
  `original_document_image` varchar(255) DEFAULT NULL,
  `photocopy_document_image` varchar(255) DEFAULT NULL,
  `photocopy_document_image_2` varchar(255) DEFAULT NULL,
  `tracking_code` varchar(24) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `requests`
--

INSERT INTO `requests` (`id`, `title`, `description`, `claiming_date`, `requester_id`, `source`, `status`, `created_at`, `updated_at`, `student_number`, `student_name`, `is_archived`, `released_at`, `document_path`, `email_address`, `student_id_image`, `registration_form_image`, `clearance_form_image`, `proof_of_graduation_image`, `id_picture_image`, `honorable_dismissal_image`, `government_id_image`, `original_document_image`, `photocopy_document_image`, `photocopy_document_image_2`, `tracking_code`) VALUES
(1, 'Transcript of Record (TOR)', 'Student Number: 2022222222\nStudent Name: Baguisi sd Jhonric\nProgram: Bachelor of Science in Information Technology\nContact Information: 09395259908\nEmail: jhonricbaguisi7@gmail.com\nPurpose: Employment\nRequested Document: Transcript of Record (TOR)', '2026-10-01', 2, 'online', 'processing', '2026-09-30 00:06:07', '2026-09-30 00:06:07', '2022222222', 'Baguisi sd Jhonric', 0, NULL, NULL, 'jhonricbaguisi7@gmail.com', 'uploads/documents/a869d19722967d9dfaf4a0d737128a8a.jpg', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'CVSU-REGSXn59f2N');

-- --------------------------------------------------------

--
-- Table structure for table `staffs`
--

CREATE TABLE `staffs` (
  `id` int(11) NOT NULL,
  `staff_name` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `is_deleted` tinyint(4) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `staffs`
--

INSERT INTO `staffs` (`id`, `staff_name`, `is_deleted`) VALUES
(1, 'MJ', 0),
(2, 'Zyn', 0),
(3, 'Pearl', 0);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `role` enum('admin','staff','viewer') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`, `role`, `created_at`) VALUES
(1, 'admin', '$2y$10$4SpieI15lueWGW9nuQ6UXuHMu8T1W6GMdVnavT.ouRk3qtLxIwZVu', 'registrar@cvsu-naic.edu.ph', 'admin', '2025-08-01 02:14:13');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `archive_history`
--
ALTER TABLE `archive_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `requester_id` (`requester_id`);

--
-- Indexes for table `audit_trail`
--
ALTER TABLE `audit_trail`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `landing_images`
--
ALTER TABLE `landing_images`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `online_requests`
--
ALTER TABLE `online_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tracking_code` (`tracking_code`),
  ADD KEY `reviewed_by` (`reviewed_by`),
  ADD KEY `approved_request_id` (`approved_request_id`),
  ADD KEY `status` (`status`),
  ADD KEY `status_created_at` (`status`,`created_at`);

--
-- Indexes for table `programs`
--
ALTER TABLE `programs`
  ADD PRIMARY KEY (`program`);

--
-- Indexes for table `requests`
--
ALTER TABLE `requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `requester_id` (`requester_id`),
  ADD KEY `archive_status_created_at` (`is_archived`,`status`,`created_at`),
  ADD KEY `status_released_at` (`status`,`released_at`),
  ADD KEY `source_status_created_at` (`source`,`status`,`created_at`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `updated_at` (`updated_at`),
  ADD KEY `tracking_code` (`tracking_code`);

--
-- Indexes for table `staffs`
--
ALTER TABLE `staffs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `archive_history`
--
ALTER TABLE `archive_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_trail`
--
ALTER TABLE `audit_trail`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `landing_images`
--
ALTER TABLE `landing_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `online_requests`
--
ALTER TABLE `online_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `requests`
--
ALTER TABLE `requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `staffs`
--
ALTER TABLE `staffs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_trail`
--
ALTER TABLE `audit_trail`
  ADD CONSTRAINT `audit_trail_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `staffs` (`id`);

--
-- Constraints for table `online_requests`
--
ALTER TABLE `online_requests`
  ADD CONSTRAINT `online_requests_approved_request_fk2` FOREIGN KEY (`approved_request_id`) REFERENCES `requests` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `online_requests_reviewed_by_fk` FOREIGN KEY (`reviewed_by`) REFERENCES `staffs` (`id`);

--
-- Constraints for table `requests`
--
ALTER TABLE `requests`
  ADD CONSTRAINT `requests_ibfk_1` FOREIGN KEY (`requester_id`) REFERENCES `staffs` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
