-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: May 04, 2026 at 06:36 AM
-- Server version: 11.8.6-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u248040635_ticketsystem`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(255) NOT NULL,
  `log_time` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `log_time`) VALUES
(1, 3, 'Reassigned ticket 001-00002 from Manager Head to Manager Rona Pascual', '2025-11-14 14:04:25'),
(2, 3, 'Reassigned ticket 001-00001 from Manager Head to Manager Rona Pascual', '2025-11-15 07:04:47'),
(3, 3, 'Reassigned ticket 001-00003 from Manager Head to Manager Rona Pascual', '2025-11-15 12:16:15'),
(4, 3, 'Reassigned ticket 001-00006 from Manager Head to Manager Rona Pascual', '2025-11-15 12:16:21'),
(5, 3, 'Reassigned ticket 001-00005 from Manager Head to Manager Rona Pascual', '2025-11-15 18:37:11'),
(6, 3, 'Reassigned ticket 001-00008 from Manager Head to Manager Rona Pascual', '2025-11-16 07:44:07'),
(7, 3, 'Reassigned ticket 001-00007 from Manager Head to Manager Rona Pascual', '2025-11-16 07:44:15'),
(8, 3, 'Reassigned ticket 001-00009 from Manager Head to Manager Rona Pascual', '2025-11-16 08:41:16'),
(9, 3, 'Reassigned ticket 001-00010 from Manager Head to Manager Rona Pascual', '2025-11-16 08:46:35'),
(10, 3, 'Reassigned ticket 001-00011 from Manager Head to Manager Rona Pascual', '2025-11-16 08:55:39'),
(11, 3, 'Reassigned ticket 001-00012 from Manager Head to Manager Rona Pascual', '2025-11-16 08:59:20'),
(12, 3, 'Reassigned ticket 001-00013 from Manager Head to Manager Rona Pascual', '2025-11-16 09:20:51'),
(13, 3, 'Reassigned ticket 001-00014 from Manager Head to Manager Rona Pascual', '2025-11-16 09:44:23'),
(14, 3, 'Reassigned ticket 001-00015 from Manager Head to Manager Rona Pascual', '2025-11-16 09:46:17'),
(15, 3, 'Reassigned ticket 001-00001 from Manager Head to Manager Rona Pascual', '2025-11-16 10:33:57'),
(16, 3, 'Reassigned ticket 001-00002 from Manager Head to Manager Rona Pascual', '2025-11-16 10:34:02'),
(17, 3, 'Reassigned ticket 001-00003 from Manager Head to Manager Rona Pascual', '2025-11-16 10:34:05'),
(18, 3, 'Reassigned ticket 001-00002 from Manager Head to Manager Rona Pascual', '2025-11-16 11:17:30'),
(19, 3, 'Reassigned ticket 001-00001 from Manager Head to Manager Rona Pascual', '2025-11-16 11:17:34'),
(22, 3, 'Reassigned ticket 001-00002 from Manager Head to Manager Rona Pascual', '2025-11-16 11:55:29'),
(23, 3, 'Reassigned ticket 001-00001 from Manager Head to Manager Rona Pascual', '2025-11-16 11:55:33'),
(29, 3, 'Reassigned ticket 001-00003 from Manager Head to Manager Rona Pascual', '2025-11-16 13:00:47'),
(30, 3, 'Reassigned ticket 001-00004 from Manager Head to Manager Rona Pascual', '2025-11-16 17:02:53'),
(31, 3, 'Reassigned ticket 001-00004 from Manager Head to Manager Rona Pascual', '2025-11-17 12:33:35'),
(32, 3, 'Reassigned ticket 001-00005 from Manager Head to Manager Rona Pascual', '2025-11-17 12:44:15'),
(34, 3, 'Reassigned ticket 001-00006 from Manager Head to Manager Rona Pascual', '2025-11-17 13:39:29'),
(35, 3, 'Reassigned ticket 001-00008 from Manager Head to Manager Rona Pascual', '2025-11-17 14:09:07'),
(36, 3, 'Reassigned ticket 001-00010 from Manager Head to Manager Rona Pascual', '2025-11-17 14:17:42'),
(37, 3, 'Reassigned ticket 001-00011 from Manager Head to Manager Rona Pascual', '2025-11-17 14:17:50'),
(38, 3, 'Reassigned ticket 001-00012 from Manager Head to Manager Rona Pascual', '2025-11-17 14:17:55'),
(39, 3, 'Reassigned ticket 001-00009 from Manager Head to Manager Rona Pascual', '2025-11-17 14:18:00'),
(40, 3, 'Reassigned ticket 001-00015 from Manager Head to Manager Rona Pascual', '2025-11-18 03:22:47'),
(41, 3, 'Reassigned ticket 001-00016 from Manager Head to Manager Rona Pascual', '2025-11-18 03:22:57'),
(42, 3, 'Reassigned ticket 001-00003 from Manager Head to Manager Rona Pascual', '2025-11-18 11:38:55'),
(43, 3, 'Reassigned ticket 001-00002 from Manager Head to Manager Rona Pascual', '2025-11-18 11:39:00'),
(44, 3, 'Reassigned ticket 001-00001 from Manager Head to Manager Rona Pascual', '2025-11-18 11:39:04'),
(45, 3, 'Reassigned ticket 001-00004 from Manager Head to Manager Rona Pascual', '2025-11-18 11:42:50'),
(46, 3, 'Reassigned ticket 001-00005 from Manager Head to Manager Rona Pascual', '2025-11-18 11:52:24'),
(47, 3, 'Reassigned ticket 001-00002 from Manager Head to Manager Rona Pascual', '2025-11-18 12:11:23'),
(48, 3, 'Reassigned ticket 001-00001 from Manager Head to Manager Rona Pascual', '2025-11-18 12:11:27'),
(51, 3, 'Reassigned ticket 001-00003 from Manager Head to Manager Rona Pascual', '2025-11-18 12:31:13'),
(52, 3, 'Reassigned ticket 001-00001 from Manager Head to Manager Rona Pascual', '2025-11-18 14:23:36'),
(53, 3, 'Reassigned ticket 001-00002 from Manager Head to Manager Rona Pascual', '2025-11-18 14:57:40'),
(54, 3, 'Reassigned ticket 001-00003 from Manager Head to Manager Rona Pascual', '2025-11-18 15:59:42'),
(55, 3, 'Reassigned ticket 001-00001 from Manager Head to Manager Rona Pascual', '2025-11-18 16:13:57'),
(56, 3, 'Reassigned ticket 001-00002 from Manager Head to Manager Rona Pascual', '2025-11-18 16:14:01'),
(57, 3, 'Reassigned ticket 001-00003 from Manager Head to Manager Rona Pascual', '2025-11-18 16:18:45'),
(58, 3, 'Reassigned ticket 001-00004 from Manager Head to Manager Rona Pascual', '2025-11-18 16:18:50'),
(59, 3, 'Reassigned ticket 001-00005 from Manager Head to Manager Rona Pascual', '2025-11-18 16:33:03'),
(60, 3, 'Reassigned ticket 001-00006 from Manager Head to Manager Rona Pascual', '2025-11-18 16:33:11'),
(61, 3, 'Reassigned ticket 001-00007 from Manager Head to Manager Rona Pascual', '2025-11-18 16:33:15'),
(62, 3, 'Reassigned ticket 001-00001 from Manager Head to Manager Rona Pascual', '2025-11-18 23:25:34'),
(63, 3, 'Reassigned ticket 001-00002 from Manager Head to Manager Rona Pascual', '2025-11-18 23:25:44'),
(64, 3, 'Reassigned ticket 001-00003 from Manager Head to Manager Rona Pascual', '2025-11-18 23:25:51'),
(67, 3, 'Reassigned ticket 001-00004 from Manager Head to Manager rhey santos', '2025-11-19 02:11:41'),
(68, 3, 'Reassigned ticket IT-2025-0002 from Manager Head to Manager Rona Shannie', '2025-12-16 12:20:46'),
(69, 3, 'Reassigned ticket IT-2025-0001 from Manager Head to Manager Rona Shannie', '2025-12-16 12:20:57'),
(70, 3, 'Reassigned ticket IT-2025-0004 from Manager Head to Manager Rona Shannie', '2025-12-17 09:55:55'),
(71, 3, 'Reassigned ticket IT-2025-0005 from Manager Head to Manager Rona Shannie', '2025-12-17 10:25:57'),
(72, 3, 'Reassigned ticket IT-2025-0006 from Manager Head to Manager Rona Shannie', '2025-12-17 10:34:39'),
(73, 3, 'Reassigned ticket IT-2025-0007 from Manager Head to Manager Rona Shannie', '2025-12-17 10:42:10'),
(74, 3, 'Reassigned ticket IT-2026-0001 from Manager Head to Manager Rona Shannie', '2026-01-13 14:05:57'),
(75, 3, 'Reassigned ticket IT-2026-0002 from Manager Head to Manager Rona Shannie', '2026-01-13 15:08:09'),
(76, 3, 'Reassigned ticket IT-2026-0001 from Manager Head to Manager Rona Shannie', '2026-01-13 16:19:12'),
(77, 3, 'Reassigned ticket IT-2026-0002 from Manager Head to Manager Rona Shannie', '2026-01-13 16:33:32'),
(78, 3, 'Reassigned ticket IT-2026-0003 from Manager Head to Manager Rona Shannie', '2026-01-13 17:45:43'),
(79, 3, 'Reassigned ticket IT-2026-0004 from Manager Head to Manager Rona Shannie', '2026-01-13 17:59:32'),
(80, 3, 'Reassigned ticket IT-2026-0001 from Manager Head to Manager Rona Shannie', '2026-01-14 05:43:18'),
(81, 3, 'Reassigned ticket IT-2026-0002 from Manager Head to Manager Rona Shannie', '2026-01-14 05:43:21'),
(82, 3, 'Reassigned ticket IT-2026-0003 from Manager Head to Manager Rona Shannie', '2026-01-14 05:43:26'),
(83, 3, 'Reassigned ticket IT-2026-0004 from Manager Head to Manager Rona Shannie', '2026-01-14 05:43:31'),
(87, 3, 'Reassigned ticket IT-2026-0001 from Manager Head to Manager Rona Shannie', '2026-01-14 07:05:19'),
(88, 3, 'Reassigned ticket IT-2026-0002 from Manager Head to Manager Rona Shannie', '2026-01-19 03:25:51'),
(89, 3, 'Reassigned ticket IT-2026-0003 from Manager Head to Manager Rona Shannie', '2026-01-19 03:25:55'),
(90, 3, 'Reassigned ticket IT-2026-0004 from Manager Head to Manager Rona Shannie', '2026-01-19 03:26:00'),
(91, 3, 'Reassigned ticket IT-2026-0006 from Manager Head to Manager Rona Shannie', '2026-01-19 05:57:16'),
(92, 3, 'Reassigned ticket IT-2026-0008 from Manager Head to Manager Rona Shannie', '2026-01-19 13:00:11'),
(93, 3, 'Reassigned ticket IT-2026-0009 from Manager Head to Manager Rona Shannie', '2026-01-19 13:02:09'),
(94, 3, 'Reassigned ticket IT-2026-0010 from Manager Head to Manager Rona Shannie', '2026-01-19 13:02:38'),
(95, 3, 'Reassigned ticket IT-2026-0014 from Manager Head to Manager Rona Shannie', '2026-01-19 14:22:02'),
(96, 3, 'Reassigned ticket IT-2026-0016 from Manager Head to Manager Rona Shannie', '2026-01-19 14:36:17'),
(97, 3, 'Reassigned ticket IT-2026-0017 from Manager Head to Manager Rona Shannie', '2026-01-19 14:48:10'),
(98, 3, 'Reassigned ticket IT-2026-0002 from Manager Head to Manager Rona Shannie', '2026-01-19 15:07:35'),
(99, 3, 'Reassigned ticket IT-2026-0003 from Manager Head to Manager Rona Shannie', '2026-01-19 15:12:27'),
(100, 3, 'Reassigned ticket IT-2026-0004 from Manager Head to Manager Rona Shannie', '2026-01-19 15:14:20'),
(101, 3, 'Reassigned ticket IT-2026-0002 from Manager Head to Manager Rona Shannie', '2026-01-19 15:18:27'),
(102, 3, 'Reassigned ticket IT-2026-0051 from Manager Head to Manager Rona Shannie', '2026-01-22 09:24:58'),
(103, 3, 'Reassigned ticket IT-2026-0003 from Manager Head to Manager Rona Shannie', '2026-01-29 01:10:43'),
(104, 3, 'Reassigned ticket IT-2026-0052 from Manager Head to Manager Rona Shannie', '2026-02-07 01:18:32'),
(105, 3, 'Reassigned ticket IT-2026-0004 from Manager Head to Manager Rona Shannie', '2026-02-08 12:07:53'),
(106, 25, 'Staff reopened ticket for reassessment.', '2026-02-10 22:43:28'),
(107, 3, 'Reassigned ticket IT-2026-0056 from Manager Head to Manager Rona Shannie', '2026-02-11 03:52:22'),
(108, 3, 'Reassigned ticket IT-2026-0007 from Manager Head to Manager Rona Shannie', '2026-02-12 02:31:28'),
(109, 3, 'Reassigned ticket IT-2026-0008 from Manager Head to Manager Rona Shannie', '2026-02-12 07:25:40'),
(110, 3, 'Reassigned ticket IT-2026-0009 from Manager Head to Manager Rona Shannie', '2026-02-12 08:29:00'),
(111, 3, 'Reassigned ticket IT-2026-0010 from Manager Head to Manager Rona Shannie', '2026-02-12 08:29:07'),
(112, 3, 'Reassigned ticket IT-2026-0011 from Manager Head to Manager Rona Shannie', '2026-02-12 08:29:23'),
(113, 3, 'Reassigned ticket IT-2026-0012 from Manager Head to Manager Rona Shannie', '2026-02-12 08:29:45');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL,
  `department_name` varchar(100) NOT NULL,
  `department_code` varchar(10) NOT NULL,
  `dept_code` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`department_id`, `department_name`, `department_code`, `dept_code`) VALUES
(1, 'IT', '001', 'IT'),
(2, 'HR', '002', 'HR'),
(52, 'Finance', '005', 'FIN'),
(53, 'Security', '006', 'SEC');

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_reset_tokens`
--

INSERT INTO `password_reset_tokens` (`id`, `user_id`, `token`, `expires_at`) VALUES
(3, 2, '9c8b20ee5e6832fb5015aa053ab6b373', '2025-12-14 10:33:31'),
(17, 113, '9a04201a1cdcadd0ab948ae6ea71a8e3', '2026-02-11 05:25:23'),
(27, 144, '168d2e72dc45aa3c8036684b12d3a607bdeb30a5c8d1a999046000e5b5511d38', '2026-03-25 04:15:23'),
(28, 145, 'c0ada7be9818a295a4bd620b7d53684125f9855aa05a38b65d6c06a3db1973ee', '2026-03-25 04:15:34'),
(29, 146, '66acecafc1ffb639df6ad478feb8a49e26084e742271642e9ea5f869dde32d46', '2026-03-25 04:15:36'),
(30, 147, 'cdcc6ecc015efaa8b02834ddffeccda64d512d5209cebcc9b3f5e22eb9b14791', '2026-03-25 04:15:38'),
(31, 148, '6c0da0aa8a1d921fae7d5c89ee0956d7039f6b2a72691c2dd6b6981fc118ccb7', '2026-03-26 03:26:18'),
(32, 149, '74cfeeed3258010b2b52b3967966145258de05d01b22d01bd0f2ce8620bb1a99', '2026-03-26 03:30:36'),
(33, 150, 'c143a58f31cca6531716b54e18e0edd2864bc225e1be66e6618552aea872c478', '2026-04-20 04:58:04');

-- --------------------------------------------------------

--
-- Table structure for table `tickets`
--

CREATE TABLE `tickets` (
  `ticket_id` int(11) NOT NULL,
  `control_number` varchar(100) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `assigned_staff_id` int(11) DEFAULT NULL,
  `department_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `issue` text DEFAULT NULL,
  `description` text NOT NULL,
  `priority` enum('low','medium','high') DEFAULT 'low',
  `sla_deadline` datetime DEFAULT NULL,
  `status` enum('pending','cancelled','in_progress','resolved','closed','declined','reopened') DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp(),
  `accepted_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `assigned_at` datetime DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `time_spent` int(11) GENERATED ALWAYS AS (timestampdiff(MINUTE,`created_at`,`ended_at`)) STORED,
  `original_assigned_staff_id` int(11) DEFAULT NULL,
  `support_type` varchar(100) NOT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `queue_number` int(11) DEFAULT NULL,
  `decline_reason` text DEFAULT NULL,
  `accept_attempts` int(11) DEFAULT 0,
  `escalated` tinyint(4) DEFAULT 0,
  `escalated_at` datetime DEFAULT NULL,
  `escalate_reason` text DEFAULT NULL,
  `withdrawn_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tickets`
--

INSERT INTO `tickets` (`ticket_id`, `control_number`, `user_id`, `manager_id`, `assigned_staff_id`, `department_id`, `title`, `issue`, `description`, `priority`, `sla_deadline`, `status`, `created_at`, `accepted_at`, `ended_at`, `updated_at`, `assigned_at`, `resolved_at`, `closed_at`, `original_assigned_staff_id`, `support_type`, `attachment`, `queue_number`, `decline_reason`, `accept_attempts`, `escalated`, `escalated_at`, `escalate_reason`, `withdrawn_by`) VALUES
(308, 'IT-2026-0012', 25, 3, 143, 1, 'Battery not charging', 'Laptop battery not charging when plugged in', '', 'low', NULL, 'pending', '2026-03-25 15:29:26', '2026-04-21 11:30:37', NULL, '2026-04-21 11:37:02', NULL, NULL, NULL, NULL, 'Hardware', NULL, NULL, 'not my forte', 3, 1, '2026-04-21 11:37:02', 'change charging pin', NULL),
(309, 'IT-2026-0013', 25, 26, 143, 1, 'Fan noise problem', 'Loud noise coming from cooling fan', '', 'low', NULL, 'pending', '2026-03-25 15:29:26', '2026-04-21 12:36:49', NULL, '2026-04-21 13:56:40', NULL, NULL, NULL, NULL, 'Hardware', NULL, NULL, 'not fixed', 1, 0, NULL, NULL, NULL),
(310, 'IT-2026-0014', 25, 26, NULL, 1, 'Broken screen', 'Laptop screen has visible cracks', '', 'low', NULL, 'pending', '2026-03-25 15:29:26', NULL, NULL, '2026-03-25 15:29:26', NULL, NULL, NULL, NULL, 'Hardware', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL),
(311, 'IT-2026-0015', 25, 26, NULL, 1, 'Power supply issue', 'PC shuts down unexpectedly', '', 'low', NULL, 'pending', '2026-03-25 15:29:26', NULL, NULL, '2026-03-25 15:29:26', NULL, NULL, NULL, NULL, 'Hardware', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL),
(312, 'IT-2026-0016', 25, 26, NULL, 1, 'Headset not detected', 'Computer cannot detect headset device', '', 'low', NULL, 'pending', '2026-03-25 15:29:26', NULL, NULL, '2026-03-25 15:29:26', NULL, NULL, NULL, NULL, 'Hardware', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL),
(313, 'IT-2026-0017', 25, 26, NULL, 1, 'Docking station problem', 'Laptop not connecting to docking station', '', 'low', NULL, 'pending', '2026-03-25 15:29:26', NULL, NULL, '2026-03-25 15:29:26', NULL, NULL, NULL, NULL, 'Hardware', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL),
(314, 'IT-2026-0018', 25, 26, NULL, 1, 'Projector not working', 'Projector not displaying output', '', 'low', NULL, 'pending', '2026-03-25 15:29:26', NULL, NULL, '2026-03-25 15:29:26', NULL, NULL, NULL, NULL, 'Hardware', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL),
(315, 'IT-2026-0019', 25, 26, NULL, 1, 'Webcam failure', 'Webcam not functioning during meetings', '', 'low', NULL, 'pending', '2026-03-25 15:29:26', NULL, NULL, '2026-03-25 15:29:26', NULL, NULL, NULL, NULL, 'Hardware', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL),
(316, 'IT-2026-0020', 25, 26, NULL, 1, 'Speaker issue', 'No sound coming from speakers', '', 'low', NULL, 'pending', '2026-03-25 15:29:26', NULL, NULL, '2026-03-25 15:29:26', NULL, NULL, NULL, NULL, 'Hardware', NULL, NULL, NULL, 0, 0, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `ticket_attachments`
--

CREATE TABLE `ticket_attachments` (
  `attachment_id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_feedback`
--

CREATE TABLE `ticket_feedback` (
  `feedback_id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `rated_staff_id` int(11) DEFAULT NULL,
  `satisfied` tinyint(1) NOT NULL,
  `rating` tinyint(1) DEFAULT NULL,
  `rating_1` tinyint(1) DEFAULT NULL,
  `rating_2` tinyint(1) DEFAULT NULL,
  `rating_3` tinyint(1) DEFAULT NULL,
  `rating_4` tinyint(1) DEFAULT NULL,
  `rating_5` tinyint(1) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `reason_title` varchar(255) DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_notes`
--

CREATE TABLE `ticket_notes` (
  `note_id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `note` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_withdrawals`
--

CREATE TABLE `ticket_withdrawals` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ticket_withdrawals`
--

INSERT INTO `ticket_withdrawals` (`id`, `ticket_id`, `user_id`, `created_at`) VALUES
(1, 301, 26, '2026-04-20 02:49:30'),
(2, 301, 26, '2026-04-20 02:57:57'),
(3, 304, 26, '2026-04-20 03:43:57'),
(4, 298, 150, '2026-04-20 04:41:23'),
(5, 300, 150, '2026-04-20 04:45:37'),
(6, 307, 150, '2026-04-20 04:46:21'),
(7, 298, 26, '2026-04-20 04:46:45'),
(8, 308, 26, '2026-04-21 03:19:28'),
(9, 308, 150, '2026-04-21 03:20:49'),
(10, 309, 143, '2026-04-21 05:56:40');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `position` varchar(100) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `role` enum('admin','manager_head','support_staff','staff') NOT NULL,
  `status` enum('pending','active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `employee_id`, `name`, `position`, `department_id`, `email`, `password`, `profile_picture`, `role`, `status`) VALUES
(2, 'EMP2024-001', 'John Cedric', 'IT Admin', 1, 'johncedric@gmail.com', '$2y$10$WK8rDuFGwPIxupaMQqFrGebI5Zq6duOsythJtjlkwgJW.yFZNBhBK', NULL, 'admin', 'active'),
(3, 'EMP2023-002', 'Jared Paguio', 'Manager', 1, 'jared@gmail.com', '$2y$10$rgA07KNGUXTJqzkLVt9THeE5YTi2o/fXdMEymNqs0.XKIob8luWYS', '1768374440_3.jpg', 'manager_head', 'active'),
(25, 'EMP2023-003', 'Maria Tabuyan', 'HR Staff', 2, 'maria@gmail.com', '$2y$10$R0fwf0N078c8qq3FwBQBJekjuZKTRs4Gi.OfXEcffkStvl0lwJ4ge', '1765964772_25.jpg', 'staff', 'active'),
(26, 'EMP2023-004', 'Rona Shannie', 'IT Support', 1, 'rona@gmail.com', '$2y$10$prA3SPLkuwoWFpANJ/3Bouo1E/b4EfWPwJTGsbKEDAqN.PdECw42C', '1770763690_26.png', 'support_staff', 'active'),
(113, 'EMP-2026-00002', 'Rona Pascual', 'HR Staff', 2, 'ronashanniepascual@gmail.com', '$2y$10$P9CNYV9b4vf3ks4CkAU.Le2O8ZviTEM4Hj5UK01NWDA7MSL737tAC', '1770427529_113.jpg', 'staff', 'active'),
(131, 'EMP-2026-00004', 'kyung soo', 'staff', 1, 'rona23pascual@gmail.com', '$2y$10$kglrq/8Pna9WY7E..Fuid.UG8Q5CYt0dnW7tOe5hWjoFccwjdX2KO', NULL, 'staff', 'active'),
(142, 'EMP-2026-00005', 'Ced Geron', 'IT Staff', 1, 'johncedric.geron@gmail.com', '$2y$10$jfH2xiHVi5qieLEEZNLgtuU3PMU1L7TnX2943s4V0W.Gy8sliY.p2', NULL, 'staff', 'active'),
(143, 'EMP-2026-00006', 'Amanda Cruz', 'IT Support', 1, 'geronjohncedric66@gmail.com', '$2y$10$.vur8u7cc4xn2ZzyFDdSQeUGBekOzJl0XBlmE6YOAuYk5vSoZ8m82', NULL, 'support_staff', 'active'),
(144, 'EMP-2026-00007', 'Juan Dela Cruz', 'HR Manager', 2, 'juan@gmail.com', '$2y$10$rBO32twxnVssL1xqcIBmb.qPJSZQ9iq2tfUc60y6znCEOW5cn/sM2', NULL, 'manager_head', 'active'),
(145, 'EMP-2026-00008', 'Ella Cruz', 'Finance Manager', 52, 'ella@gmail.com', '$2y$10$9Xbtbmsq8NYEkRU1prfMTOSEBVF2miDxGGdhhP.qnCUfvqp8hOpnO', NULL, 'manager_head', 'active'),
(146, 'EMP-2026-00009', 'Stella Starling', 'HR Suppport', 2, 'stella@gmail.com', '$2y$10$cQ3n.OgVRMeVFC6Qm7kPMOSXSOQkv/aAjzItKE0h/rUirrpTMVFsa', NULL, 'support_staff', 'active'),
(147, 'EMP-2026-00010', 'Jack Thompson', 'Finance Support', 52, 'jack@gmail.com', '$2y$10$ShL1M9Hsbwy.x1GwRSzM3utURTBsd4umh/vbVvTTt2zJk6xhTSedC', NULL, 'support_staff', 'active'),
(148, 'EMP-2026-00011', 'Mateo Cruz', 'IT Staff', 1, 'leowon088@gmail.com', '$2y$10$NwtFbZY5eNSQefirSkpFF.6gP7PxN.CW7kW0U8K//6RhKTMBLduZi', NULL, 'staff', 'active'),
(149, 'EMP-2026-00012', 'Marie Pascual', 'HR Staff', 2, 'mariepascual59@gmail.com', '$2y$10$K3eMBk.whIAUlJEo/HX6OO2AQrekFOBeVkvW3GeSQqkHr2AQIeodq', NULL, 'staff', 'active'),
(150, 'EMP-2026-00013', 'AJ Alzette Alba', 'IT Support', 1, 'alshiiiiit@gmail.com', '$2y$10$3qlqU5.3COWphEFKnMq0VuOHxXM4WG7qPPN/0O12qnCLrZ33sakgK', NULL, 'support_staff', 'active');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`),
  ADD UNIQUE KEY `department_code` (`department_code`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`ticket_id`),
  ADD UNIQUE KEY `control_number` (`control_number`),
  ADD KEY `fk_ticket_user` (`user_id`),
  ADD KEY `fk_ticket_department` (`department_id`),
  ADD KEY `fk_ticket_assigned_staff` (`assigned_staff_id`);

--
-- Indexes for table `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  ADD PRIMARY KEY (`attachment_id`),
  ADD KEY `ticket_id` (`ticket_id`);

--
-- Indexes for table `ticket_feedback`
--
ALTER TABLE `ticket_feedback`
  ADD PRIMARY KEY (`feedback_id`),
  ADD UNIQUE KEY `ticket_id` (`ticket_id`),
  ADD KEY `fk_feedback_rated_staff` (`rated_staff_id`);

--
-- Indexes for table `ticket_notes`
--
ALTER TABLE `ticket_notes`
  ADD PRIMARY KEY (`note_id`),
  ADD KEY `ticket_id` (`ticket_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `ticket_withdrawals`
--
ALTER TABLE `ticket_withdrawals`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `employee_id` (`employee_id`),
  ADD KEY `fk_users_department` (`department_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=114;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `tickets`
--
ALTER TABLE `tickets`
  MODIFY `ticket_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=317;

--
-- AUTO_INCREMENT for table `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  MODIFY `attachment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ticket_feedback`
--
ALTER TABLE `ticket_feedback`
  MODIFY `feedback_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=56;

--
-- AUTO_INCREMENT for table `ticket_notes`
--
ALTER TABLE `ticket_notes`
  MODIFY `note_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ticket_withdrawals`
--
ALTER TABLE `ticket_withdrawals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=151;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD CONSTRAINT `password_reset_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `tickets`
--
ALTER TABLE `tickets`
  ADD CONSTRAINT `fk_ticket_assigned_staff` FOREIGN KEY (`assigned_staff_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ticket_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ticket_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  ADD CONSTRAINT `ticket_attachments_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE;

--
-- Constraints for table `ticket_feedback`
--
ALTER TABLE `ticket_feedback`
  ADD CONSTRAINT `fk_feedback_rated_staff` FOREIGN KEY (`rated_staff_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_feedback_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE;

--
-- Constraints for table `ticket_notes`
--
ALTER TABLE `ticket_notes`
  ADD CONSTRAINT `ticket_notes_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_notes_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
