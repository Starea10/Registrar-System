-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Jul 30, 2026 at 01:45 AM
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
  `status` enum('pending','processing','for_signature','for_release','released') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `student_number` varchar(50) DEFAULT NULL,
  `student_name` varchar(255) DEFAULT NULL,
  `is_archived` tinyint(1) DEFAULT 0,
  `released_at` datetime DEFAULT NULL,
  `document_path` varchar(255) DEFAULT NULL,
  `email_address` varchar(128) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `archive_history`
--

INSERT INTO `archive_history` (`id`, `title`, `description`, `claiming_date`, `requester_id`, `status`, `created_at`, `updated_at`, `student_number`, `student_name`, `is_archived`, `released_at`, `document_path`, `email_address`) VALUES
(6, 'Request for : 1x Transcript of Record (TOR)', 'Student Number: 29987654\nStudent Name: Testing\nProgram: BSCS\nYear of Graduation: \nContact Information: gtabang310@gmail.com\nPurpose: Employment\nRequested Documents: 1x Transcript of Record (TOR)\nScheduled Claiming Date: 2026-08-01', '2026-08-01', 1, 'released', '2026-07-29 14:39:30', '2026-07-29 16:32:51', '29987654', 'Testing', 1, '2026-07-24 23:21:00', NULL, 'gtabang310@gmail.com'),
(8, 'Request for : 1x Transcript of Record (TOR)', 'Student Number: 29987654\nStudent Name: test\nProgram: BSCS\nYear of Graduation: \nContact Information: 123456789\nPurpose: Employment\nRequested Documents: 1x Transcript of Record (TOR)\nScheduled Claiming Date: 2026-07-31', '2026-07-31', 1, 'released', '2026-07-29 16:38:01', '2026-07-29 17:18:28', '29987654', 'test', 1, '2026-07-30 01:18:28', NULL, NULL);

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
(1, 1, 'create_request', 'Created new request: Request for : 1x Transcript of Record (TOR)', '2026-07-29 14:12:35'),
(2, 1, 'delete_request', 'Permanently deleted request #1', '2026-07-29 14:12:49'),
(3, 1, 'create_request', 'Created new request: Request for : 1x Transcript of Record (TOR)', '2026-07-29 14:13:08'),
(4, 1, 'create_request', 'Created new request: Request for : 1x Diploma', '2026-07-29 14:14:08'),
(5, 1, 'create_request', 'Created new request: Request for : 1x Transcript of Record (TOR)', '2026-07-29 14:17:33'),
(6, 1, 'create_request', 'Created new request: Request for : 1x Transcript of Record (TOR)', '2026-07-29 14:17:57'),
(7, 1, 'delete_request', 'Permanently deleted request #5', '2026-07-29 14:18:42'),
(8, 1, 'delete_request', 'Permanently deleted request #2', '2026-07-29 14:18:45'),
(9, 1, 'delete_request', 'Permanently deleted request #3', '2026-07-29 14:18:51'),
(10, 1, 'delete_request', 'Permanently deleted request #4', '2026-07-29 14:18:54'),
(11, 1, 'create_request', 'Created new request: Request for : 1x Transcript of Record (TOR)', '2026-07-29 14:39:30'),
(12, 2, 'create_request', 'Created new request: Request for : 1x Diploma', '2026-07-29 14:45:17'),
(13, 2, 'delete_request', 'Permanently deleted request #7', '2026-07-29 15:01:21'),
(14, 1, 'update_request', 'Updated request #6 status from \'pending\' to \'released\' and automatically archived', '2026-07-29 15:21:33'),
(15, 1, 'update_released_date', 'Updated released date for request #6 from \'2026-07-29\' to \'2026-07-31T23:21\'', '2026-07-29 16:26:13'),
(16, 1, 'update_released_date', 'Updated released date for request #6 from \'2026-07-31\' to \'2026-07-23T23:21\'', '2026-07-29 16:28:38'),
(17, 1, 'update_released_date', 'Updated released date for request #6 from \'2026-07-23\' to \'2026-07-24T23:21\'', '2026-07-29 16:32:51'),
(18, 1, 'create_request', 'Created new request: Request for : 1x Transcript of Record (TOR)', '2026-07-29 16:38:01'),
(19, 1, 'update_request', 'Updated request #8 status from \'pending\' to \'processing\'', '2026-07-29 16:38:05'),
(20, 1, 'update_request', 'Updated request #8 status from \'processing\' to \'released\' and automatically archived', '2026-07-29 17:18:28');

-- --------------------------------------------------------

--
-- Table structure for table `pending_requests`
--

CREATE TABLE `pending_requests` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `claiming_date` date DEFAULT NULL,
  `requester_id` int(11) DEFAULT NULL,
  `status` enum('submitted') DEFAULT 'submitted',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `student_number` varchar(50) DEFAULT NULL,
  `student_name` varchar(255) DEFAULT NULL,
  `is_archived` tinyint(1) DEFAULT 0,
  `released_at` datetime DEFAULT NULL,
  `document_path` varchar(255) DEFAULT NULL,
  `email_address` varchar(128) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
('BSCS', 'Bachelor of Science in Computer Science'),
('BSIT', 'Bachelor of Science in Information Technology'),
('BSFAS', 'BS in Fisheries and Aquatic Sciences'),
('BSEd', 'Bachelor in Secondary Education'),
('BEEd', 'Bachelor in Elementary Education'),
('BSBA', 'Bachelor of Science in Business Administration'),
('BSHM', 'Bachelor of Science in Hospitality Management'),
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
  `status` enum('pending','processing','for_signature','for_release','released') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `student_number` varchar(50) DEFAULT NULL,
  `student_name` varchar(255) DEFAULT NULL,
  `is_archived` tinyint(1) DEFAULT 0,
  `released_at` datetime DEFAULT NULL,
  `document_path` varchar(255) DEFAULT NULL,
  `email_address` varchar(128) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(1, 'admin', '$2y$10$M9QSuWv3XDk8I1H2rqF0DOiVbtn3upp9OCnMSeAF9xETDPzZfT9bi', 'registrar@cvsu-naic.edu.ph', 'admin', '2025-08-01 02:14:13');

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
-- Indexes for table `pending_requests`
--
ALTER TABLE `pending_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `requester_id` (`requester_id`);

--
-- Indexes for table `requests`
--
ALTER TABLE `requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `requester_id` (`requester_id`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `audit_trail`
--
ALTER TABLE `audit_trail`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `pending_requests`
--
ALTER TABLE `pending_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `requests`
--
ALTER TABLE `requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `staffs`
--
ALTER TABLE `staffs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_trail`
--
ALTER TABLE `audit_trail`
  ADD CONSTRAINT `audit_trail_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `staffs` (`id`);

--
-- Constraints for table `requests`
--
ALTER TABLE `requests`
  ADD CONSTRAINT `requests_ibfk_1` FOREIGN KEY (`requester_id`) REFERENCES `staffs` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
