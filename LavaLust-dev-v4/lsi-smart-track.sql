-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Nov 05, 2026 at 06:13 AM
-- Server version: 8.4.3
-- PHP Version: 8.3.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `lsi-smart-track`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `log_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `action` varchar(150) NOT NULL,
  `details` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`log_id`, `user_id`, `action`, `details`, `created_at`) VALUES
(1, 5, 'Created trainees', 'Record #1', '2026-10-27 06:20:29'),
(2, 5, 'Created programs', 'Record #1', '2026-10-27 06:21:24'),
(3, 5, 'Updated trainees', 'Record #1', '2026-10-27 06:21:37'),
(4, 5, 'Created classes', 'Record #1', '2026-10-27 06:22:37'),
(5, 5, 'Created enrollments', 'Record #1', '2026-10-27 06:23:50'),
(6, 5, 'Created schedules', 'Record #1', '2026-10-27 06:24:34'),
(7, 1, 'Updated profile', 'Name changed', '2026-10-27 12:08:32'),
(8, 1, 'Created programs', 'Record #2', '2026-10-27 12:10:36'),
(9, 1, 'Deleted schedules', 'Record #1 removed', '2026-10-27 12:11:54'),
(10, 1, 'Created schedules', 'Record #2', '2026-10-27 12:12:52'),
(11, 1, 'Created schedules', 'Record #3', '2026-10-27 12:22:16'),
(12, 1, 'Recorded attendance', 'Raver Alagao checked in.', '2026-10-27 14:00:45'),
(13, 1, 'Recorded attendance', 'Raver Alagao checked out.', '2026-10-27 14:01:07'),
(14, 1, 'Removed class enrollment from certificate eligibility', 'Eligibility #1: Enrollment removed. Attendance, documents, and assessment history were retained.', '2026-10-28 11:22:11'),
(15, 1, 'Activated certificate template', 'Template version 1 (generate)', '2026-10-28 12:26:53'),
(16, 1, 'Deleted schedules', 'Record #2 removed', '2026-10-28 13:37:31'),
(17, 1, 'Updated schedules', 'Record #3', '2026-10-28 13:38:07'),
(18, 1, 'Created enrollments', 'Record #2', '2026-10-28 13:39:03'),
(19, 1, 'Recorded attendance', 'Raver Alagao checked in.', '2026-10-28 13:58:39'),
(20, 1, 'Recorded attendance', 'Raver Alagao checked out.', '2026-10-28 13:59:05'),
(21, 1, 'Saved certificate template draft', 'Template version 2 (generate)', '2026-10-28 14:03:36'),
(22, 1, 'Removed class enrollment from certificate eligibility', 'Eligibility #2: Enrollment removed. Attendance, documents, and assessment history were retained.', '2026-10-28 15:08:06'),
(23, 1, 'Activated certificate template', 'Template version 3 (generate)', '2026-10-28 15:14:47'),
(24, 1, 'Created programs', 'Record #3', '2026-10-28 15:20:50'),
(25, 1, 'Created classes', 'Record #2', '2026-10-28 15:21:51'),
(26, 1, 'Created schedules', 'Record #4', '2026-10-28 15:22:35'),
(27, 1, 'Created enrollments', 'Record #3', '2026-10-28 15:22:44'),
(28, 1, 'Recorded attendance', 'Raver Alagao checked in.', '2026-10-28 15:23:49'),
(29, 1, 'Updated profile', 'Name changed', '2026-10-28 15:25:10'),
(30, 1, 'Recorded attendance', 'Raver Alagao checked out.', '2026-10-28 15:32:20'),
(31, 1, 'Recalculated eligibility', 'Trainee ID: 1, Class ID: 2', '2026-10-28 15:37:35'),
(32, 1, 'Uploaded document', 'Activity Web2 (Cloud)', '2026-10-28 15:40:05'),
(33, 5, 'Recorded training assessment', 'Activity WEB2 (PASS), record #1', '2026-10-28 15:42:33'),
(34, 5, 'Recalculated eligibility', 'Trainee ID: 1, Class ID: 2', '2026-10-28 15:43:00'),
(35, 5, 'Updated schedules', 'Record #4', '2026-10-28 16:02:26'),
(36, 5, 'Created schedules', 'Record #5', '2026-10-28 16:05:29'),
(37, 5, 'Recorded attendance', 'Raver Alagao checked in.', '2026-10-28 16:05:58'),
(38, 5, 'Recorded attendance', 'Raver Alagao checked out.', '2026-10-28 16:16:39'),
(39, 5, 'Activated certificate template', 'Template version 4 (generate)', '2026-10-28 16:17:55'),
(40, 1, 'Recalculated eligibility', 'Trainee ID: 1, Class ID: 2', '2026-10-28 16:19:16'),
(41, 1, 'Activated certificate template', 'Template version 5 (generate)', '2026-10-28 16:28:00'),
(42, 1, 'Activated certificate template', 'Template version 4', '2026-10-29 00:37:00'),
(43, 1, 'Deleted certificate template', 'Template version 1', '2026-10-29 00:50:49'),
(44, 1, 'Deleted certificate template', 'Template version 2', '2026-10-29 00:50:55'),
(45, 1, 'Deleted certificate template', 'Template version 3', '2026-10-29 00:51:01'),
(46, 1, 'Deleted certificate template', 'Template version 5', '2026-10-29 00:51:14'),
(47, 1, 'Updated certificate template', 'Template version 5 activated', '2026-10-29 00:52:47'),
(48, 1, 'Deleted certificate template', 'Template version 4', '2026-10-29 00:53:17'),
(49, 1, 'Recalculated eligibility', 'Trainee ID: 1, Class ID: 2', '2026-10-29 00:53:47'),
(50, 1, 'Uploaded document', 'AGResult (Cloud)', '2026-10-29 00:56:30'),
(51, 4, 'Uploaded document', 'AGResult (Cloud)', '2026-10-29 01:01:29'),
(52, 1, 'Deleted document', 'AGResult (Cloud)', '2026-10-29 01:05:07'),
(53, 1, 'Uploaded document', 'Resume (Cloud)', '2026-10-29 01:09:26'),
(54, 4, 'Uploaded document', 'RESUME (Cloud)', '2026-10-29 01:10:10'),
(55, 1, 'Removed class enrollment from certificate eligibility', 'Eligibility #3: Enrollment removed. Attendance, documents, and assessment history were retained.', '2026-10-29 01:10:41'),
(56, 1, 'Deleted document', 'AGResult (Cloud)', '2026-10-29 01:11:18'),
(57, 1, 'Deleted document', 'Resume (Cloud)', '2026-10-29 01:11:23'),
(58, 1, 'Deleted document', 'RESUME (Cloud)', '2026-10-29 01:11:29'),
(59, 1, 'Deleted document', 'Activity Web2 (Cloud)', '2026-10-29 01:11:34'),
(60, 1, 'Deleted classes', 'Record #2 removed', '2026-10-29 01:11:54'),
(61, 1, 'Updated classes', 'Record #1', '2026-10-29 01:13:11'),
(62, 1, 'Deleted schedules', 'Record #3 removed', '2026-10-29 01:13:20'),
(63, 1, 'Created schedules', 'Record #6', '2026-10-29 01:14:23'),
(64, 1, 'Created enrollments', 'Record #4', '2026-10-29 01:14:45'),
(65, 1, 'Created trainees', 'Record #2', '2026-10-29 01:18:49'),
(66, 1, 'Created enrollments', 'Record #5', '2026-10-29 01:19:21'),
(67, 1, 'Recorded attendance', 'Mark Harry checked in.', '2026-10-29 01:22:40'),
(68, 1, 'Recorded attendance', 'Raver Alagao checked in.', '2026-10-29 01:23:07'),
(69, 1, 'Uploaded document', 'Resume (Cloud)', '2026-10-29 01:24:21'),
(70, 1, 'Uploaded document', 'Resume (Cloud)', '2026-10-29 01:25:08'),
(71, 6, 'Uploaded document', 'Resume (Cloud)', '2026-10-29 01:26:30'),
(72, 4, 'Uploaded document', 'Resume (Cloud)', '2026-10-29 01:27:17'),
(73, 1, 'Recorded attendance', 'Raver Alagao checked out.', '2026-10-29 01:28:00'),
(74, 1, 'Recorded attendance', 'Mark Harry checked out.', '2026-10-29 01:28:24'),
(75, 1, 'Recalculated eligibility', 'Trainee ID: 1, Class ID: 1', '2026-10-29 01:28:42'),
(76, 1, 'Recalculated eligibility', 'Trainee ID: 2, Class ID: 1', '2026-10-29 01:28:46'),
(77, 1, 'Recorded training assessment', 'Resume (PASS), record #2', '2026-10-29 01:29:24'),
(78, 1, 'Recorded training assessment', 'Resume (PASS), record #3', '2026-10-29 01:29:42'),
(79, 1, 'Updated certificate template', 'Template version 6 activated', '2026-10-29 01:31:05'),
(80, 1, 'Deleted certificate template', 'Template version 5', '2026-10-29 01:31:12'),
(81, 1, 'Recalculated eligibility', 'Trainee ID: 1, Class ID: 1', '2026-10-29 01:44:21'),
(82, 1, 'Recalculated eligibility', 'Trainee ID: 2, Class ID: 1', '2026-10-29 01:44:28'),
(83, 1, 'Updated certificate template', 'Template version 7 activated', '2026-10-29 01:58:57'),
(84, 1, 'Recalculated eligibility', 'Trainee ID: 1, Class ID: 1', '2026-10-29 01:59:44'),
(85, 1, 'Deleted certificate template', 'Template version 6', '2026-10-29 02:00:10'),
(86, 1, 'Recalculated eligibility', 'Trainee ID: 1, Class ID: 1', '2026-10-29 02:01:02'),
(87, 1, 'Updated document status', 'Resume -> inactive', '2026-10-29 02:01:51'),
(88, 1, 'Updated document status', 'Resume -> inactive', '2026-10-29 02:01:55'),
(89, 1, 'Updated certificate template', 'Template version 8 activated', '2026-10-29 08:57:09'),
(90, 1, 'Deleted certificate template', 'Template version 7', '2026-10-29 08:57:17'),
(91, 1, 'Recalculated eligibility', 'Trainee ID: 1, Class ID: 1', '2026-10-29 08:57:47'),
(92, 5, 'Configured trainee fee', 'Registration (REGISTRATION), fee type #1', '2026-10-29 10:42:31'),
(93, 6, 'Submitted payment proof', '092877625, proof #1', '2026-10-29 11:49:57'),
(94, 1, 'Verified payment proof', 'Proof #1: Payment verified.', '2026-10-29 11:50:21'),
(95, 1, 'Configured trainee fee', 'Deposit (BOND), fee type #2', '2026-10-29 11:52:22'),
(96, 4, 'Submitted payment proof', '7645437877, proof #2', '2026-10-29 11:53:44'),
(97, 1, 'Verified payment proof', 'Proof #2: Payment verified.', '2026-10-29 11:54:20'),
(98, 1, 'Recalculated eligibility', 'Trainee ID: 1, Class ID: 1', '2026-10-29 11:55:50'),
(99, 1, 'Recalculated eligibility', 'Trainee ID: 2, Class ID: 1', '2026-10-29 11:55:55'),
(100, 1, 'Updated classes', 'Record #1', '2026-10-29 12:01:47'),
(101, 1, 'Created schedules', 'Record #7', '2026-10-29 12:03:03'),
(102, 1, 'Saved certificate signatory', 'MARK HARRY B. LUPIG (Director)', '2026-10-29 23:29:39'),
(103, 1, 'Saved certificate signatory', 'MARK HARRY B. LUPIG (Administrator)', '2026-10-29 23:31:12'),
(104, 1, 'Updated classes', 'Record #1', '2026-10-31 00:57:16'),
(105, 1, 'Updated schedules', 'Record #6', '2026-10-31 00:57:56'),
(106, 1, 'Uploaded document', 'Compilation (Cloud)', '2026-10-31 01:00:10'),
(107, 1, 'Uploaded document', 'Compilation (Cloud)', '2026-10-31 01:01:15'),
(108, 1, 'Recorded attendance', 'Mark Harry checked in.', '2026-10-31 01:02:21'),
(109, 1, 'Recorded attendance', 'Raver Alagao checked in.', '2026-10-31 01:02:53'),
(110, 1, 'Saved certificate signatory', 'MARK HARRY B. LUPIG (Administrator)', '2026-11-02 14:02:39'),
(111, 1, 'Updated classes', 'Record #1', '2026-11-02 14:21:39'),
(112, 1, 'Created schedules', 'Record #8', '2026-11-02 14:24:52'),
(113, 1, 'Recorded attendance', 'Mark Harry checked in.', '2026-11-02 14:25:09'),
(114, 1, 'Recorded attendance', 'Raver Alagao checked in.', '2026-11-02 14:25:37'),
(115, 1, 'Added certificate document requirement', 'Resume', '2026-11-02 14:26:17'),
(116, 6, 'Uploaded document', 'Resume (Cloud)', '2026-11-02 14:27:31'),
(117, 4, 'Uploaded document', 'Resume (Cloud)', '2026-11-02 14:28:23'),
(118, 1, 'Approved trainee document', 'Resume', '2026-11-02 14:29:13'),
(119, 1, 'Approved trainee document', 'Resume', '2026-11-02 14:29:16'),
(120, 1, 'Recorded training assessment', 'Resume requirement (PASS), record #4', '2026-11-02 14:33:30'),
(121, 1, 'Recorded training assessment', 'Resume requirement (PASS), record #5', '2026-11-02 14:34:13'),
(122, 1, 'Recalculated eligibility', 'Trainee ID: 1, Class ID: 1', '2026-11-02 14:39:30'),
(123, 1, 'Deleted document', 'Resume (Cloud)', '2026-11-02 14:43:10'),
(124, 1, 'Deleted document', 'Resume (Cloud)', '2026-11-02 14:43:15'),
(125, 1, 'Deleted document', 'Compilation (Cloud)', '2026-11-02 14:43:20'),
(126, 1, 'Deleted document', 'Compilation (Cloud)', '2026-11-02 14:43:27'),
(127, 3, 'Recorded attendance', 'Mark Harry checked out.', '2026-11-02 14:59:00'),
(128, 3, 'Recorded attendance', 'Raver Alagao checked out.', '2026-11-02 14:59:07'),
(129, 1, 'Created schedules', 'Record #9', '2026-11-03 03:33:26'),
(130, 1, 'Recorded attendance', 'Mark Harry checked in.', '2026-11-03 03:33:44'),
(131, 1, 'Recorded attendance', 'Raver Alagao checked in.', '2026-11-03 03:34:01'),
(132, 1, 'Recorded attendance', 'Mark Harry checked out.', '2026-11-03 03:52:04'),
(133, 1, 'Recorded attendance', 'Raver Alagao checked out.', '2026-11-03 03:52:40'),
(134, 1, 'Updated classes', 'Record #1', '2026-11-03 03:53:39'),
(135, 1, 'Uploaded document', 'SmartTrack (Cloud)', '2026-11-03 04:11:06'),
(136, 5, 'Created trainees', 'Record #3', '2026-11-03 04:15:57'),
(137, 5, 'Created programs', 'Record #4', '2026-11-03 04:21:32'),
(138, 5, 'Created trainees', 'Record #4', '2026-11-03 04:24:38'),
(139, 5, 'Created classes', 'Record #3', '2026-11-03 04:25:35'),
(140, 5, 'Created schedules', 'Record #10', '2026-11-03 04:26:08'),
(141, 3, 'Removed certificate document requirement', 'Requirement #1', '2026-11-03 04:30:06'),
(142, 3, 'Added certificate document requirement', 'Assignment', '2026-11-03 05:06:38'),
(143, 5, 'Created enrollments', 'Record #6', '2026-11-03 12:00:47'),
(144, 5, 'Created enrollments', 'Record #7', '2026-11-03 12:01:10'),
(145, 5, 'Configured trainee fee', 'Registration (REGISTRATION), fee type #3', '2026-11-03 12:02:03'),
(146, 5, 'Configured trainee fee', 'Deposit (BOND), fee type #4', '2026-11-03 12:02:36'),
(147, 1, 'Removed certificate document requirement', 'Requirement #2', '2026-11-03 12:23:11'),
(148, 8, 'Submitted payment proof', '09875535, proof #3', '2026-11-03 12:24:52'),
(149, 8, 'Submitted payment proof', '08767643, proof #4', '2026-11-03 12:25:50'),
(150, 5, 'Verified payment proof', 'Proof #3: Payment verified.', '2026-11-03 12:27:16'),
(151, 5, 'Verified payment proof', 'Proof #4: goods!', '2026-11-03 12:27:31'),
(152, 9, 'Submitted payment proof', '9866532, proof #5', '2026-11-03 12:29:26'),
(153, 9, 'Submitted payment proof', '875253123, proof #6', '2026-11-03 12:30:12'),
(154, 5, 'Verified payment proof', 'Proof #5: congrats!!!', '2026-11-03 12:31:16'),
(155, 5, 'Verified payment proof', 'Proof #6: congrats!!!', '2026-11-03 12:31:34'),
(156, 5, 'Deleted programs', 'Record #3 removed', '2026-11-03 12:32:40'),
(157, 5, 'Deleted programs', 'Record #2 removed', '2026-11-03 12:32:46'),
(158, 5, 'Updated schedules', 'Record #10', '2026-11-03 12:43:19'),
(159, 3, 'Recorded attendance', 'Jerome Sajul checked in.', '2026-11-03 12:45:01'),
(160, 3, 'Recorded attendance', 'Allesa Marie Magbanua checked in.', '2026-11-03 12:45:12'),
(161, 3, 'Added certificate document requirement', 'Assignment', '2026-11-03 12:46:32'),
(162, 8, 'Uploaded document', 'Assignment (Cloud)', '2026-11-03 12:47:59'),
(163, 9, 'Uploaded document', 'Assignment (Cloud)', '2026-11-03 12:50:01'),
(164, 5, 'Approved trainee document', 'Assignment', '2026-11-03 12:51:04'),
(165, 5, 'Approved trainee document', 'Assignment', '2026-11-03 12:51:07'),
(166, 5, 'Uploaded document', 'Anova (Cloud)', '2026-11-03 12:55:21'),
(167, 1, 'Recalculated eligibility', 'Trainee ID: 4, Class ID: 3', '2026-11-03 13:02:12'),
(168, 1, 'Recalculated eligibility', 'Trainee ID: 1, Class ID: 1', '2026-11-03 13:02:17'),
(169, 5, 'Deleted document', 'Anova (Cloud)', '2026-11-03 13:09:00'),
(170, 1, 'Uploaded document', 'AGResult (Cloud)', '2026-11-03 13:13:15'),
(171, 1, 'Created announcements', 'Record #1', '2026-11-03 14:03:22'),
(172, 1, 'Sent announcement notifications', 'Walang Pasok!!!', '2026-11-03 14:20:37'),
(173, 1, 'Sent announcement notifications', 'Walang Pasok!!!', '2026-11-03 14:37:05'),
(174, 1, 'Uploaded document', 'Activity 3 (Cloud)', '2026-11-04 00:54:37'),
(175, 1, 'Deleted document', 'Activity 3 (Cloud)', '2026-11-04 00:58:41'),
(176, 1, 'Sent announcement notifications', 'Walang Pasok!!!', '2026-11-04 03:18:25'),
(177, 1, 'Configured trainee fee', 'deposit (BOND), fee type #5', '2026-11-04 12:47:45'),
(178, 8, 'Submitted payment proof', '08927761, proof #7', '2026-11-04 12:49:06'),
(179, 8, 'Submitted payment proof', 'ST-9-27c0cff862f8058d, proof #8', '2026-11-04 15:17:29'),
(180, 5, 'Rejected payment proof', 'Proof #7: fake', '2026-11-04 15:18:53'),
(181, 5, 'Verified payment proof', 'Proof #8: good!!', '2026-11-04 15:19:16'),
(182, 9, 'Submitted payment proof', 'ST-10-8bfdab0de0e9e1df, proof #9', '2026-11-04 15:23:14'),
(183, 5, 'Verified payment proof', 'Proof #9: Payment verified.', '2026-11-04 15:23:46'),
(184, 1, 'Created programs', 'Record #5', '2026-11-04 23:24:05'),
(185, 1, 'Created classes', 'Record #4', '2026-11-04 23:24:56'),
(186, 1, 'Updated class', 'Class #4', '2026-11-04 23:58:15'),
(187, 1, 'Updated class', 'Class #4', '2026-11-04 23:58:30'),
(188, 1, 'Updated class', 'Class #4', '2026-11-04 23:58:40'),
(189, 1, 'Deleted class', 'Class #4', '2026-11-05 00:01:07'),
(190, 1, 'Deleted announcements', 'Record #1 removed', '2026-11-05 00:21:40'),
(191, 1, 'Deleted schedules', 'Record #10 removed', '2026-11-05 01:30:24'),
(192, 1, 'Deleted schedules', 'Record #6 removed', '2026-11-05 01:30:35'),
(193, 1, 'Deleted schedules', 'Record #7 removed', '2026-11-05 01:30:38'),
(194, 1, 'Deleted schedules', 'Record #8 removed', '2026-11-05 01:30:40'),
(195, 1, 'Deleted schedules', 'Record #9 removed', '2026-11-05 01:30:43'),
(196, 1, 'Updated programs', 'Record #1', '2026-11-05 01:32:32'),
(197, 1, 'Updated class', 'Class #1', '2026-11-05 01:33:25'),
(198, 1, 'Updated class', 'Class #3', '2026-11-05 01:33:54'),
(199, 1, 'Added certificate document requirement', 'Kahit Ano', '2026-11-05 01:35:05'),
(200, 1, 'Removed certificate document requirement', 'Requirement #3', '2026-11-05 01:35:12'),
(201, 5, 'Created schedules', 'Record #11', '2026-11-05 01:37:00'),
(202, 5, 'Created schedules', 'Record #12', '2026-11-05 01:38:08'),
(203, 3, 'Recorded attendance', 'Jerome Sajul checked in.', '2026-11-05 01:38:45'),
(204, 3, 'Recorded attendance', 'Allesa Marie Magbanua checked in.', '2026-11-05 01:38:51'),
(205, 8, 'Uploaded document', 'Kahit Ano (Cloud)', '2026-11-05 01:46:55'),
(206, 9, 'Uploaded document', 'Kahit Ano (Cloud)', '2026-11-05 01:50:07'),
(207, 3, 'Approved trainee document', 'Kahit Ano', '2026-11-05 01:57:43'),
(208, 3, 'Approved trainee document', 'Kahit Ano', '2026-11-05 01:57:46'),
(209, 3, 'Recorded training assessment', 'Quiz 1. (PASS), record #6', '2026-11-05 02:08:28'),
(210, 3, 'Recorded training assessment', 'Quiz 1. (PASS), record #7', '2026-11-05 02:09:03'),
(211, 3, 'Recorded attendance', 'Allesa Marie Magbanua checked out.', '2026-11-05 02:34:00'),
(212, 3, 'Recorded attendance', 'Jerome Sajul checked out.', '2026-11-05 02:34:05'),
(213, 1, 'Updated certificate template', 'Template version 9 activated', '2026-11-05 02:50:53'),
(214, 1, 'Activated certificate template', 'Template version 8', '2026-11-05 02:52:14'),
(215, 5, 'Deleted schedules', 'Record #12 removed', '2026-11-05 03:29:27'),
(216, 5, 'Created schedules', 'Record #13', '2026-11-05 03:31:35'),
(217, 1, 'Recalculated eligibility', 'Trainee ID: 2, Class ID: 1', '2026-11-05 04:09:32'),
(218, 1, 'Reviewed certificate eligibility', 'Eligibility #6 approved', '2026-11-05 04:22:07'),
(219, 1, 'Recalculated eligibility', 'Trainee ID: 2, Class ID: 1', '2026-11-05 04:22:20'),
(220, 1, 'Reviewed certificate eligibility', 'Eligibility #5 approved', '2026-11-05 04:22:28'),
(221, 1, 'Recalculated eligibility', 'Trainee ID: 2, Class ID: 1', '2026-11-05 04:22:39'),
(222, 1, 'Signed certificate', 'CERT-2026-3717847CB7FC906A', '2026-11-05 04:26:31'),
(223, 1, 'Signed certificate', 'CERT-2026-6DD878C616EDE7ED', '2026-11-05 04:31:02'),
(224, 1, 'Recalculated eligibility', 'Trainee ID: 1, Class ID: 1', '2026-11-05 04:33:58');

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `announcement_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `class_id` int UNSIGNED DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `content` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` varchar(20) NOT NULL DEFAULT 'published',
  `category` varchar(40) NOT NULL DEFAULT 'General',
  `attachment` varchar(255) DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `announcement_notifications`
--

CREATE TABLE `announcement_notifications` (
  `id` bigint UNSIGNED NOT NULL,
  `announcement_id` int UNSIGNED NOT NULL,
  `trainee_id` int UNSIGNED NOT NULL,
  `channel` enum('sms','email') NOT NULL,
  `recipient` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `provider_message_id` varchar(150) DEFAULT NULL,
  `error_message` text,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_records`
--

CREATE TABLE `attendance_records` (
  `attendance_id` int UNSIGNED NOT NULL,
  `trainee_id` int UNSIGNED NOT NULL,
  `class_id` int UNSIGNED DEFAULT NULL,
  `schedule_id` int UNSIGNED DEFAULT NULL,
  `attendance_date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `status` varchar(30) NOT NULL,
  `remarks` varchar(200) NOT NULL DEFAULT '',
  `recorded_by` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `smarttrack_attendance_scan_limits`
--

CREATE TABLE `smarttrack_attendance_scan_limits` (
  `rate_key` char(64) NOT NULL,
  `window_started_at` datetime NOT NULL,
  `request_count` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`rate_key`),
  KEY `idx_smarttrack_attendance_scan_window` (`window_started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `attendance_records`
--

INSERT INTO `attendance_records` (`attendance_id`, `trainee_id`, `class_id`, `schedule_id`, `attendance_date`, `time_in`, `time_out`, `status`, `remarks`, `recorded_by`) VALUES
(1, 1, 1, NULL, '2026-10-27', '22:00:45', '22:01:07', 'Late', 'QR/manual scan', 1),
(2, 1, 1, NULL, '2026-10-28', '21:58:39', '21:59:05', 'Late', 'QR/manual scan', 1),
(3, 1, NULL, NULL, '2026-10-28', '23:23:49', '23:32:20', 'Present', 'QR/manual scan', 1),
(4, 1, NULL, NULL, '2026-10-29', '00:05:58', '00:16:39', 'Present', 'QR/manual scan', 5),
(5, 2, 1, NULL, '2026-10-29', '09:22:40', '09:28:24', 'Late', 'QR/manual scan', 1),
(6, 1, 1, NULL, '2026-10-29', '09:23:07', '09:28:00', 'Late', 'QR/manual scan', 1),
(7, 2, 1, NULL, '2026-10-31', '09:02:21', NULL, 'Present', 'QR/manual scan', 1),
(8, 1, 1, NULL, '2026-10-31', '09:02:53', NULL, 'Present', 'QR/manual scan', 1),
(9, 2, 1, NULL, '2026-11-02', '22:25:09', '22:59:00', 'Present', 'QR/manual scan', 3),
(10, 1, 1, NULL, '2026-11-02', '22:25:37', '22:59:07', 'Present', 'QR/manual scan', 3),
(11, 2, 1, NULL, '2026-11-03', '11:33:44', '11:52:04', 'Present', 'QR/manual scan', 1),
(12, 1, 1, NULL, '2026-11-03', '11:34:01', '11:52:40', 'Present', 'QR/manual scan', 1),
(13, 5, 3, NULL, '2026-11-03', '20:45:01', NULL, 'Present', 'QR/manual scan', 3),
(14, 4, 3, NULL, '2026-11-03', '20:45:12', NULL, 'Present', 'QR/manual scan', 3),
(15, 5, 3, NULL, '2026-11-05', '09:38:45', '10:34:05', 'Present', 'QR/manual scan', 3),
(16, 4, 3, NULL, '2026-11-05', '09:38:51', '10:34:00', 'Present', 'QR/manual scan', 3);

-- --------------------------------------------------------

--
-- Table structure for table `bond_transactions`
--

CREATE TABLE `bond_transactions` (
  `bond_transaction_id` int UNSIGNED NOT NULL,
  `trainee_fee_id` int UNSIGNED NOT NULL,
  `transaction_type` varchar(20) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_id` int UNSIGNED DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `method` varchar(20) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `remarks` text,
  `performed_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `bond_transactions`
--

INSERT INTO `bond_transactions` (`bond_transaction_id`, `trainee_fee_id`, `transaction_type`, `amount`, `payment_id`, `transaction_date`, `method`, `reference_number`, `remarks`, `performed_by`, `created_at`) VALUES
(1, 7, 'deposit', 500.00, 4, '2026-11-03', 'gcash', '08767643', 'Bond deposit received.', 5, '2026-11-03 12:27:31'),
(2, 8, 'deposit', 500.00, 6, '2026-11-03', 'gcash', '875253123', 'Bond deposit received.', 5, '2026-11-03 12:31:34'),
(3, 9, 'deposit', 12.00, 7, '2026-11-04', 'gcash', 'ST-9-27c0cff862f8058d', 'Bond deposit received.', 5, '2026-11-04 15:19:16'),
(4, 10, 'deposit', 12.00, 8, '2026-11-04', 'gcash', 'ST-10-8bfdab0de0e9e1df', 'Bond deposit received.', 5, '2026-11-04 15:23:46');

-- --------------------------------------------------------

--
-- Table structure for table `certificates`
--

CREATE TABLE `certificates` (
  `certificate_id` int UNSIGNED NOT NULL,
  `trainee_id` int UNSIGNED NOT NULL,
  `class_id` int UNSIGNED NOT NULL,
  `program_id` int UNSIGNED NOT NULL,
  `certificate_number` varchar(50) NOT NULL,
  `completion_date` date NOT NULL,
  `issue_date` date NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'issued',
  `file_path` varchar(255) DEFAULT NULL,
  `qr_code` varchar(100) NOT NULL,
  `template_id` int UNSIGNED DEFAULT NULL,
  `revoked_at` date DEFAULT NULL,
  `revoked_by` int UNSIGNED DEFAULT NULL,
  `revocation_reason` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `template_snapshot` json DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `certificates`
--

INSERT INTO `certificates` (`certificate_id`, `trainee_id`, `class_id`, `program_id`, `certificate_number`, `completion_date`, `issue_date`, `status`, `file_path`, `qr_code`, `template_id`, `revoked_at`, `revoked_by`, `revocation_reason`, `created_at`, `template_snapshot`) VALUES
(2, 2, 1, 1, 'CERT-2026-3717847CB7FC906A', '2026-11-03', '2026-11-05', 'issued', NULL, 'ff22cec833133ebbeeb763c31949dfb5815c7c9ea6b5814ec9a71ab37afb0bb4', 9, NULL, NULL, NULL, '2026-11-05 04:22:07', '{\"mode\": \"upload\", \"version\": 8, \"settings\": {\"font\": \"times\", \"body_text\": \"This is to certify that Raver Mychael has successfully completed CSS Python on October 28, 2026.\", \"logo_path\": \"runtime/uploads/certificates/templates/7fbe7ddc1890b15d4295a73645fecc7bb62e637c.jpg\", \"title_text\": \"Certificate of Completion\", \"accent_color\": \"#2738b9\", \"border_style\": \"double\", \"signatory_name\": \"Marky Harry Lupig\", \"signatory_title\": \"Director\", \"institution_name\": \"LSI Training Center\"}, \"file_mime\": null, \"file_path\": \"runtime/uploads/certificates/templates/0b7ebf267275539cbad194a12efbd4e692f0a27f.jpg\", \"is_active\": 1, \"created_at\": \"2026-10-29 16:57:09\", \"created_by\": 1, \"template_id\": 9, \"field_layout\": {\"program_name\": {\"x\": 50, \"y\": 65, \"align\": \"left\", \"font_size\": 20}, \"trainee_name\": {\"x\": 50, \"y\": 52, \"align\": \"left\", \"font_size\": 28}, \"date_completed\": {\"x\": 50, \"y\": 70, \"align\": \"center\", \"font_size\": 14}, \"signatory_name\": {\"x\": 24, \"y\": 86, \"align\": \"left\", \"font_size\": 12}, \"verification_code\": {\"x\": 84, \"y\": 72, \"align\": \"center\", \"font_size\": 12}, \"certificate_number\": {\"x\": 50, \"y\": 91, \"align\": \"left\", \"font_size\": 6}}}'),
(3, 1, 1, 1, 'CERT-2026-6DD878C616EDE7ED', '2026-11-03', '2026-11-05', 'issued', 'runtime/uploads/certificates/certificate_CERT-2026-6DD878C616EDE7ED.pdf', 'cf6f47b6a0414f5c42a6435b5bb422fa590dcaf8da0b9fdf2b0fb547a3bb5f71', 9, NULL, NULL, NULL, '2026-11-05 04:22:28', '{\"mode\": \"upload\", \"version\": 8, \"settings\": {\"font\": \"times\", \"body_text\": \"This is to certify that Raver Mychael has successfully completed CSS Python on October 28, 2026.\", \"logo_path\": \"runtime/uploads/certificates/templates/7fbe7ddc1890b15d4295a73645fecc7bb62e637c.jpg\", \"title_text\": \"Certificate of Completion\", \"accent_color\": \"#2738b9\", \"border_style\": \"double\", \"signatory_name\": \"Marky Harry Lupig\", \"signatory_title\": \"Director\", \"institution_name\": \"LSI Training Center\"}, \"file_mime\": null, \"file_path\": \"runtime/uploads/certificates/templates/0b7ebf267275539cbad194a12efbd4e692f0a27f.jpg\", \"is_active\": 1, \"created_at\": \"2026-10-29 16:57:09\", \"created_by\": 1, \"template_id\": 9, \"field_layout\": {\"program_name\": {\"x\": 50, \"y\": 65, \"align\": \"left\", \"font_size\": 20}, \"trainee_name\": {\"x\": 50, \"y\": 52, \"align\": \"left\", \"font_size\": 28}, \"date_completed\": {\"x\": 50, \"y\": 70, \"align\": \"center\", \"font_size\": 14}, \"signatory_name\": {\"x\": 24, \"y\": 86, \"align\": \"left\", \"font_size\": 12}, \"verification_code\": {\"x\": 84, \"y\": 72, \"align\": \"center\", \"font_size\": 12}, \"certificate_number\": {\"x\": 50, \"y\": 91, \"align\": \"left\", \"font_size\": 6}}}');

-- --------------------------------------------------------

--
-- Table structure for table `certificate_audit_logs`
--

CREATE TABLE `certificate_audit_logs` (
  `audit_id` int UNSIGNED NOT NULL,
  `actor_id` int UNSIGNED DEFAULT NULL,
  `certificate_id` int UNSIGNED DEFAULT NULL,
  `eligibility_id` int UNSIGNED DEFAULT NULL,
  `action` varchar(30) NOT NULL,
  `details` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `certificate_audit_logs`
--

INSERT INTO `certificate_audit_logs` (`audit_id`, `actor_id`, `certificate_id`, `eligibility_id`, `action`, `details`, `ip_address`, `created_at`) VALUES
(1, 1, NULL, NULL, 'delete_enrollment', 'Enrollment removed. Attendance, documents, and assessment history were retained.', '::1', '2026-10-28 11:22:11'),
(2, 1, NULL, NULL, 'delete_enrollment', 'Enrollment removed. Attendance, documents, and assessment history were retained.', '::1', '2026-10-28 15:08:06'),
(3, 1, NULL, NULL, 'delete_enrollment', 'Enrollment removed. Attendance, documents, and assessment history were retained.', '127.0.0.1', '2026-10-29 01:10:41'),
(4, 1, NULL, 6, 'approved', '', '127.0.0.1', '2026-11-05 04:22:07'),
(5, 1, NULL, 5, 'approved', '', '127.0.0.1', '2026-11-05 04:22:28'),
(6, 1, 2, NULL, 'sign', 'Certificate signature recorded; SHA-256 c4c24040f8175e52a5a84dbccd5faf988454ce815523566a67d2b36d6c38c80d', '127.0.0.1', '2026-11-05 04:26:31'),
(7, 1, 3, NULL, 'sign', 'Certificate signature recorded; SHA-256 827fd854664c61fae38bdc224243282e00c20854a19c46c234cb3cbdbf86f83b', '127.0.0.1', '2026-11-05 04:31:02'),
(8, 4, 3, NULL, 'download', 'Certificate downloaded by trainee', '::1', '2026-11-05 05:48:15');

-- --------------------------------------------------------

--
-- Table structure for table `certificate_config`
--

CREATE TABLE `certificate_config` (
  `config_id` int UNSIGNED NOT NULL,
  `config_key` varchar(100) NOT NULL,
  `config_value` text NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `certificate_config`
--

INSERT INTO `certificate_config` (`config_id`, `config_key`, `config_value`, `description`, `updated_at`) VALUES
(1, 'institution_name', 'LSI Training Center', NULL, '2026-10-28 16:28:00'),
(2, 'logo_path', 'runtime/uploads/private/certificate_templates/4a2e3ac74494015b94f942e3c1cfe180.jpg', NULL, '2026-10-28 16:28:00'),
(3, 'signatory_name', 'Marky Harry Lupig', NULL, '2026-10-28 16:28:00'),
(4, 'signatory_designation', 'Director', NULL, '2026-10-28 16:28:00'),
(5, 'min_attendance_percentage', '75', NULL, '2026-11-05 02:50:53'),
(6, 'require_documents', '1', NULL, '2026-11-05 02:50:53'),
(7, 'require_assessments', '1', NULL, '2026-11-05 02:50:53'),
(8, 'required_assessment_count', '2', NULL, '2026-11-05 02:50:53'),
(9, 'certificate_title', 'Certificate of Completion', NULL, '2026-10-28 16:28:00'),
(10, 'certificate_subtitle', 'OF COMPLETION', NULL, '2026-10-28 16:28:00'),
(11, 'template_color', '#03117c', NULL, '2026-10-28 16:28:00');

-- --------------------------------------------------------

--
-- Table structure for table `certificate_document_requirements`
--

CREATE TABLE `certificate_document_requirements` (
  `requirement_id` int UNSIGNED NOT NULL,
  `class_id` int UNSIGNED NOT NULL,
  `required_document_name` varchar(150) NOT NULL,
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `certificate_document_requirements`
--

INSERT INTO `certificate_document_requirements` (`requirement_id`, `class_id`, `required_document_name`, `created_by`, `created_at`) VALUES
(4, 3, 'Kahit Ano', 1, '2026-11-05 01:35:05'),
(5, 1, 'Resume', NULL, '2026-11-05 04:15:04');

-- --------------------------------------------------------

--
-- Table structure for table `certificate_eligibility`
--

CREATE TABLE `certificate_eligibility` (
  `eligibility_id` int UNSIGNED NOT NULL,
  `trainee_id` int UNSIGNED NOT NULL,
  `class_id` int UNSIGNED NOT NULL,
  `program_id` int UNSIGNED NOT NULL,
  `attendance_percentage` decimal(5,2) NOT NULL,
  `documents_submitted` int NOT NULL DEFAULT '0',
  `documents_required` int NOT NULL DEFAULT '0',
  `documents_approved` int NOT NULL DEFAULT '0',
  `assessments_completed` int NOT NULL DEFAULT '0',
  `assessments_required` int NOT NULL DEFAULT '0',
  `is_eligible` tinyint(1) NOT NULL DEFAULT '0',
  `ineligibility_reasons` text,
  `approval_status` varchar(20) NOT NULL DEFAULT 'pending',
  `approval_reason` text,
  `approved_by` int UNSIGNED DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int UNSIGNED DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `certificate_eligibility`
--

INSERT INTO `certificate_eligibility` (`eligibility_id`, `trainee_id`, `class_id`, `program_id`, `attendance_percentage`, `documents_submitted`, `documents_required`, `documents_approved`, `assessments_completed`, `assessments_required`, `is_eligible`, `ineligibility_reasons`, `approval_status`, `approval_reason`, `approved_by`, `approved_at`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`) VALUES
(5, 1, 1, 1, 100.00, 1, 1, 1, 2, 2, 0, 'A certificate record already exists for this class', 'approved', NULL, 1, '2026-11-05 04:22:28', 1, '2026-11-05 04:22:28', '2026-10-29 01:14:45', '2026-11-05 05:03:16'),
(6, 2, 1, 1, 100.00, 1, 1, 1, 2, 2, 0, 'A certificate record already exists for this class', 'approved', NULL, 1, '2026-11-05 04:22:07', 1, '2026-11-05 04:22:07', '2026-10-29 01:19:21', '2026-11-05 05:03:16'),
(7, 4, 3, 4, 100.00, 2, 1, 1, 1, 2, 0, 'The class has not reached its completion date; Passed assessments (1/2) do not meet the requirement', 'pending', NULL, NULL, NULL, NULL, NULL, '2026-11-03 12:00:47', '2026-11-05 05:03:16'),
(8, 5, 3, 4, 100.00, 2, 1, 1, 1, 2, 0, 'The class has not reached its completion date; Passed assessments (1/2) do not meet the requirement', 'pending', NULL, NULL, NULL, NULL, NULL, '2026-11-03 12:01:10', '2026-11-05 05:03:16');

-- --------------------------------------------------------

--
-- Table structure for table `certificate_notifications`
--

CREATE TABLE `certificate_notifications` (
  `notification_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `certificate_id` int UNSIGNED NOT NULL,
  `message` varchar(255) NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `certificate_signatories`
--

CREATE TABLE `certificate_signatories` (
  `signatory_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `position` varchar(150) NOT NULL,
  `signature_path` varchar(255) NOT NULL,
  `field_x` decimal(5,2) NOT NULL DEFAULT '24.00',
  `field_y` decimal(5,2) NOT NULL DEFAULT '86.00',
  `font_size` smallint UNSIGNED NOT NULL DEFAULT '12',
  `alignment` char(1) NOT NULL DEFAULT 'C',
  `is_required` tinyint(1) NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `x_position` decimal(5,2) NOT NULL DEFAULT '50.00',
  `y_position` decimal(5,2) NOT NULL DEFAULT '80.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `certificate_signatories`
--

INSERT INTO `certificate_signatories` (`signatory_id`, `user_id`, `name`, `position`, `signature_path`, `field_x`, `field_y`, `font_size`, `alignment`, `is_required`, `is_active`, `created_by`, `created_at`, `updated_at`, `x_position`, `y_position`) VALUES
(1, 1, 'MARK HARRY B. LUPIG', 'Administrator', 'runtime/uploads/certificates/signatures/f13b64042312dd651905fb2d2ca7276af087d74d.png', 24.00, 86.00, 12, 'C', 1, 1, 1, '2026-10-29 23:29:39', '2026-11-02 14:02:39', 50.00, 85.00);

-- --------------------------------------------------------

--
-- Table structure for table `certificate_signatures`
--

CREATE TABLE `certificate_signatures` (
  `signature_id` int UNSIGNED NOT NULL,
  `certificate_id` int UNSIGNED NOT NULL,
  `signatory_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `status` enum('pending','signed','rejected') NOT NULL DEFAULT 'pending',
  `signature_path` varchar(255) DEFAULT NULL,
  `signatory_name` varchar(150) NOT NULL,
  `position` varchar(150) DEFAULT NULL,
  `field_x` decimal(5,2) DEFAULT NULL,
  `field_y` decimal(5,2) DEFAULT NULL,
  `font_size` smallint UNSIGNED DEFAULT NULL,
  `alignment` char(1) DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT '1',
  `signed_at` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `certificate_hash` char(64) DEFAULT NULL,
  `remarks` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `signatory_user_id` int UNSIGNED DEFAULT NULL,
  `decision` varchar(20) NOT NULL DEFAULT 'pending',
  `signatory_position` varchar(150) DEFAULT NULL,
  `x_position` decimal(5,2) DEFAULT NULL,
  `y_position` decimal(5,2) DEFAULT NULL,
  `certificate_data_hash` char(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `certificate_signatures`
--

INSERT INTO `certificate_signatures` (`signature_id`, `certificate_id`, `signatory_id`, `user_id`, `status`, `signature_path`, `signatory_name`, `position`, `field_x`, `field_y`, `font_size`, `alignment`, `is_required`, `signed_at`, `ip_address`, `certificate_hash`, `remarks`, `created_at`, `signatory_user_id`, `decision`, `signatory_position`, `x_position`, `y_position`, `certificate_data_hash`) VALUES
(1, 2, 1, NULL, 'pending', 'runtime/uploads/certificates/signatures/f13b64042312dd651905fb2d2ca7276af087d74d.png', 'MARK HARRY B. LUPIG', NULL, NULL, NULL, NULL, NULL, 1, '2026-11-05 12:26:31', '127.0.0.1', NULL, NULL, '2026-11-05 04:22:07', 1, 'signed', 'Administrator', 50.00, 85.00, 'c4c24040f8175e52a5a84dbccd5faf988454ce815523566a67d2b36d6c38c80d'),
(2, 3, 1, NULL, 'pending', 'runtime/uploads/certificates/signatures/f13b64042312dd651905fb2d2ca7276af087d74d.png', 'MARK HARRY B. LUPIG', NULL, NULL, NULL, NULL, NULL, 1, '2026-11-05 12:31:02', '127.0.0.1', NULL, NULL, '2026-11-05 04:22:28', 1, 'signed', 'Administrator', 50.00, 85.00, '827fd854664c61fae38bdc224243282e00c20854a19c46c234cb3cbdbf86f83b');

-- --------------------------------------------------------

--
-- Table structure for table `certificate_templates`
--

CREATE TABLE `certificate_templates` (
  `template_id` int UNSIGNED NOT NULL,
  `version` int UNSIGNED NOT NULL,
  `mode` enum('upload','generate') NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_mime` varchar(100) DEFAULT NULL,
  `field_layout` json NOT NULL,
  `settings` json NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `certificate_templates`
--

INSERT INTO `certificate_templates` (`template_id`, `version`, `mode`, `file_path`, `file_mime`, `field_layout`, `settings`, `is_active`, `created_by`, `created_at`) VALUES
(9, 8, 'upload', 'runtime/uploads/certificates/templates/0b7ebf267275539cbad194a12efbd4e692f0a27f.jpg', NULL, '{\"program_name\": {\"x\": 50, \"y\": 65, \"align\": \"left\", \"font_size\": 20}, \"trainee_name\": {\"x\": 50, \"y\": 52, \"align\": \"left\", \"font_size\": 28}, \"date_completed\": {\"x\": 50, \"y\": 70, \"align\": \"center\", \"font_size\": 14}, \"signatory_name\": {\"x\": 24, \"y\": 86, \"align\": \"left\", \"font_size\": 12}, \"verification_code\": {\"x\": 84, \"y\": 72, \"align\": \"center\", \"font_size\": 12}, \"certificate_number\": {\"x\": 50, \"y\": 91, \"align\": \"left\", \"font_size\": 6}}', '{\"font\": \"times\", \"body_text\": \"This is to certify that Raver Mychael has successfully completed CSS Python on October 28, 2026.\", \"logo_path\": \"runtime/uploads/certificates/templates/7fbe7ddc1890b15d4295a73645fecc7bb62e637c.jpg\", \"title_text\": \"Certificate of Completion\", \"accent_color\": \"#2738b9\", \"border_style\": \"double\", \"signatory_name\": \"Marky Harry Lupig\", \"signatory_title\": \"Director\", \"institution_name\": \"LSI Training Center\"}', 1, 1, '2026-10-29 08:57:09'),
(10, 9, 'upload', 'runtime/uploads/certificates/templates/0b7ebf267275539cbad194a12efbd4e692f0a27f.jpg', NULL, '{\"program_name\": {\"x\": 50, \"y\": 65, \"align\": \"left\", \"font_size\": 20}, \"trainee_name\": {\"x\": 50, \"y\": 52, \"align\": \"left\", \"font_size\": 28}, \"date_completed\": {\"x\": 50, \"y\": 70, \"align\": \"center\", \"font_size\": 14}, \"signatory_name\": {\"x\": 24, \"y\": 86, \"align\": \"left\", \"font_size\": 12}, \"verification_code\": {\"x\": 84, \"y\": 72, \"align\": \"center\", \"font_size\": 12}, \"certificate_number\": {\"x\": 50, \"y\": 91, \"align\": \"left\", \"font_size\": 6}}', '{\"font\": \"times\", \"body_text\": \"This is to certify that Raver Mychael has successfully completed CSS Python on October 28, 2026.\", \"logo_path\": \"runtime/uploads/certificates/templates/7fbe7ddc1890b15d4295a73645fecc7bb62e637c.jpg\", \"title_text\": \"Certificate of Completion\", \"accent_color\": \"#2738b9\", \"border_style\": \"double\", \"signatory_name\": \"Marky Harry Lupig\", \"signatory_title\": \"Director\", \"institution_name\": \"LSI Training Center\"}', 0, 1, '2026-11-05 02:50:53');

-- --------------------------------------------------------

--
-- Table structure for table `class_enrollments`
--

CREATE TABLE `class_enrollments` (
  `enrollment_id` int UNSIGNED NOT NULL,
  `class_id` int UNSIGNED NOT NULL,
  `trainee_id` int UNSIGNED NOT NULL,
  `enrolled_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `class_enrollments`
--

INSERT INTO `class_enrollments` (`enrollment_id`, `class_id`, `trainee_id`, `enrolled_at`) VALUES
(4, 1, 1, '2026-10-29 01:14:45'),
(5, 1, 2, '2026-10-29 01:19:21'),
(6, 3, 4, '2026-11-03 12:00:47'),
(7, 3, 5, '2026-11-03 12:01:10');

-- --------------------------------------------------------

--
-- Table structure for table `fee_types`
--

CREATE TABLE `fee_types` (
  `fee_type_id` int UNSIGNED NOT NULL,
  `program_id` int UNSIGNED DEFAULT NULL,
  `class_id` int UNSIGNED DEFAULT NULL,
  `fee_kind` varchar(20) NOT NULL,
  `fee_name` varchar(150) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `due_date` date DEFAULT NULL,
  `required_for_certificate` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `fee_types`
--

INSERT INTO `fee_types` (`fee_type_id`, `program_id`, `class_id`, `fee_kind`, `fee_name`, `amount`, `due_date`, `required_for_certificate`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'registration', 'Registration', 500.00, '2026-10-31', 1, 1, 5, '2026-10-29 10:42:31', '2026-10-29 10:42:31'),
(2, 1, 1, 'bond', 'Deposit', 500.00, '2026-10-31', 0, 1, 1, '2026-10-29 11:52:22', '2026-10-29 11:52:22'),
(3, 4, 3, 'registration', 'Registration', 500.00, '2026-11-07', 1, 1, 5, '2026-11-03 12:02:03', '2026-11-03 12:02:03'),
(4, 4, NULL, 'bond', 'Deposit', 500.00, '2026-11-07', 0, 1, 5, '2026-11-03 12:02:36', '2026-11-03 12:02:36'),
(5, 4, 3, 'bond', 'deposit', 12.00, '2026-11-07', 1, 1, 1, '2026-11-04 12:47:45', '2026-11-04 12:47:45');

-- --------------------------------------------------------

--
-- Table structure for table `inquiries`
--

CREATE TABLE `inquiries` (
  `inquiry_id` int UNSIGNED NOT NULL,
  `trainee_id` int UNSIGNED NOT NULL,
  `subject` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `reply` text,
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `message` text NOT NULL,
  `notification_type` varchar(50) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `notification_type`, `is_read`, `created_at`) VALUES
(1, 1, 'Certificate CERT-2026-3717847CB7FC906A is ready for your signature.', 'certificate_signature', 0, '2026-11-05 04:22:07'),
(2, 1, 'Certificate CERT-2026-6DD878C616EDE7ED is ready for your signature.', 'certificate_signature', 0, '2026-11-05 04:22:28'),
(3, 6, 'Your certificate CERT-2026-3717847CB7FC906A has been signed and issued.', 'certificate_issued', 0, '2026-11-05 04:26:31'),
(4, 4, 'Your certificate CERT-2026-6DD878C616EDE7ED has been signed and issued.', 'certificate_issued', 0, '2026-11-05 04:31:02');

-- --------------------------------------------------------

--
-- Table structure for table `notification_settings`
--

CREATE TABLE `notification_settings` (
  `id` tinyint UNSIGNED NOT NULL,
  `sms_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `email_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `notification_settings`
--

INSERT INTO `notification_settings` (`id`, `sms_enabled`, `email_enabled`, `updated_at`) VALUES
(1, 1, 1, '2026-11-03 14:20:15');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int UNSIGNED NOT NULL,
  `trainee_fee_id` int UNSIGNED NOT NULL,
  `entry_type` varchar(20) NOT NULL DEFAULT 'payment',
  `amount` decimal(12,2) NOT NULL,
  `method` varchar(20) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `payment_date` date NOT NULL,
  `receipt_number` varchar(50) DEFAULT NULL,
  `reversal_of_payment_id` int UNSIGNED DEFAULT NULL,
  `remarks` text,
  `recorded_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `trainee_fee_id`, `entry_type`, `amount`, `method`, `reference_number`, `payment_date`, `receipt_number`, `reversal_of_payment_id`, `remarks`, `recorded_by`, `created_at`) VALUES
(1, 2, 'payment', 500.00, 'other', '092877625', '2026-10-29', 'LSI-20261029-3E49C3CF', NULL, 'Verified from uploaded proof #1', 1, '2026-10-29 11:50:21'),
(2, 1, 'payment', 500.00, 'other', '7645437877', '2026-10-30', 'LSI-20261029-8CB4F4EF', NULL, 'Verified from uploaded proof #2', 1, '2026-10-29 11:54:20'),
(3, 5, 'payment', 500.00, 'gcash', '09875535', '2026-11-03', 'LSI-20261103-6B478AF0', NULL, 'Verified from uploaded proof #3', 5, '2026-11-03 12:27:16'),
(4, 7, 'payment', 500.00, 'gcash', '08767643', '2026-11-03', 'LSI-20261103-0423119E', NULL, 'Verified from uploaded proof #4', 5, '2026-11-03 12:27:31'),
(5, 6, 'payment', 500.00, 'gcash', '9866532', '2026-11-03', 'LSI-20261103-4135B796', NULL, 'Verified from uploaded proof #5', 5, '2026-11-03 12:31:16'),
(6, 8, 'payment', 500.00, 'gcash', '875253123', '2026-11-03', 'LSI-20261103-F624472E', NULL, 'Verified from uploaded proof #6', 5, '2026-11-03 12:31:34'),
(7, 9, 'payment', 12.00, 'gcash', 'ST-9-27c0cff862f8058d', '2026-11-04', 'LSI-20261104-F6765380', NULL, 'Verified from uploaded proof #8', 5, '2026-11-04 15:19:16'),
(8, 10, 'payment', 12.00, 'gcash', 'ST-10-8bfdab0de0e9e1df', '2026-11-04', 'LSI-20261104-A84FBE44', NULL, 'Verified from uploaded proof #9', 5, '2026-11-04 15:23:46');

-- --------------------------------------------------------

--
-- Table structure for table `payment_proofs`
--

CREATE TABLE `payment_proofs` (
  `proof_id` int UNSIGNED NOT NULL,
  `trainee_fee_id` int UNSIGNED NOT NULL,
  `uploaded_by` int UNSIGNED NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `method` varchar(20) NOT NULL,
  `reference_number` varchar(100) NOT NULL,
  `payment_date` date NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending_verification',
  `remarks` text,
  `payment_id` int UNSIGNED DEFAULT NULL,
  `verified_by` int UNSIGNED DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `payment_proofs`
--

INSERT INTO `payment_proofs` (`proof_id`, `trainee_fee_id`, `uploaded_by`, `amount`, `method`, `reference_number`, `payment_date`, `file_path`, `status`, `remarks`, `payment_id`, `verified_by`, `verified_at`, `created_at`) VALUES
(1, 2, 6, 500.00, 'other', '092877625', '2026-10-29', 'runtime/uploads/payment-proofs/208c8b630773803aa27890ec9399ceaec283c630.jpg', 'verified', NULL, 1, 1, '2026-10-29 11:50:21', '2026-10-29 11:49:57'),
(2, 1, 4, 500.00, 'other', '7645437877', '2026-10-30', 'runtime/uploads/payment-proofs/cd70260482905c0ab19d82f561b10cb4bec78787.jpg', 'verified', NULL, 2, 1, '2026-10-29 11:54:20', '2026-10-29 11:53:44'),
(3, 5, 8, 500.00, 'gcash', '09875535', '2026-11-03', 'runtime/uploads/payment-proofs/1a9793843bb8fd9bedb0f593dd2770e18a8be043.png', 'verified', NULL, 3, 5, '2026-11-03 12:27:16', '2026-11-03 12:24:52'),
(4, 7, 8, 500.00, 'gcash', '08767643', '2026-11-03', 'runtime/uploads/payment-proofs/c5a30dc4c9aeab78321c26a3cf747c326cab5ae7.png', 'verified', 'goods!', 4, 5, '2026-11-03 12:27:31', '2026-11-03 12:25:50'),
(5, 6, 9, 500.00, 'gcash', '9866532', '2026-11-03', 'runtime/uploads/payment-proofs/4004fba0f5166b5eb3460dcc4a5de3875103d142.png', 'verified', 'congrats!!!', 5, 5, '2026-11-03 12:31:16', '2026-11-03 12:29:26'),
(6, 8, 9, 500.00, 'gcash', '875253123', '2026-11-03', 'runtime/uploads/payment-proofs/b83da0be9b5205ff3550bc80510520abd67ef5df.png', 'verified', 'congrats!!!', 6, 5, '2026-11-03 12:31:34', '2026-11-03 12:30:12'),
(7, 9, 8, 12.00, 'gcash', '08927761', '2026-11-04', 'runtime/uploads/payment-proofs/39ba782766c59357d3e53e7080761dd4e2656708.png', 'rejected', 'fake', NULL, 5, '2026-11-04 15:18:52', '2026-11-04 12:49:06'),
(8, 9, 8, 12.00, 'gcash', 'ST-9-27c0cff862f8058d', '2026-11-04', 'runtime/uploads/payment-proofs/9c9bacf48e62dc6dbcb0d17e1986377324e7d582.png', 'verified', 'good!!', 7, 5, '2026-11-04 15:19:16', '2026-11-04 15:17:29'),
(9, 10, 9, 12.00, 'gcash', 'ST-10-8bfdab0de0e9e1df', '2026-11-04', 'runtime/uploads/payment-proofs/28ca084fd969831f48fadad199dcff9feb203e20.png', 'verified', NULL, 8, 5, '2026-11-04 15:23:46', '2026-11-04 15:23:14');

-- --------------------------------------------------------

--
-- Table structure for table `refresh_tokens`
--

CREATE TABLE `refresh_tokens` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `jti` varchar(64) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `refresh_tokens`
--

INSERT INTO `refresh_tokens` (`id`, `user_id`, `token_hash`, `jti`, `expires_at`, `created_at`) VALUES
(42, 3, 'e3665f31fa283ea59f44f686543f208cd412c9a0987a4b743f043075c2c68932', '870d2db943f427865087758b6b2b9601', '2026-11-04 03:11:26', '2026-10-28 03:11:26');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `role_id` tinyint UNSIGNED NOT NULL,
  `role_name` varchar(30) NOT NULL,
  `description` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trainees`
--

CREATE TABLE `trainees` (
  `trainee_id` int UNSIGNED NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `sex` varchar(20) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `contact_number` varchar(20) NOT NULL,
  `course` varchar(100) NOT NULL,
  `program_id` int UNSIGNED DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `user_id` int UNSIGNED DEFAULT NULL,
  `qr_code` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `trainees`
--

INSERT INTO `trainees` (`trainee_id`, `first_name`, `last_name`, `sex`, `date_of_birth`, `address`, `email`, `contact_number`, `course`, `program_id`, `status`, `user_id`, `qr_code`) VALUES
(1, 'Raver', 'Alagao', 'Male', '2006-07-09', 'Masipit, Calapan City, Oriental Mindoro', 'raver@gmail.com', '09776192256', 'Programming', 1, 'active', 4, 'LSI-5945DE2C6FBB'),
(2, 'Mark', 'Harry', 'Male', '2008-12-12', 'Batuhan, Pola', 'lupigmarkharry539@gmail.com', '09939448360', 'Programming', 1, 'active', 6, 'LSI-83FF2A0C56F6'),
(3, 'Mark Harry', 'Lupig', 'Male', '2000-12-03', 'Batuhan, Pola', 'lupigmarkharry539@gmail.com', '09776192256', 'Programming', 1, 'active', 7, 'LSI-F59C9C1F369F'),
(4, 'Allesa Marie', 'Magbanua', 'Female', '2000-11-24', 'Masipit, Calapan City, Oriental Mindoro\nBatuhan, Pola, Oriental Mindoro', 'allesa@gmail.com', '09778767676', 'programming', 4, 'active', 8, 'LSI-FF09A5F3E364'),
(5, 'Jerome', 'Sajul', 'Male', '2005-10-13', 'Maluanluan, Pola, Oriental Mindoro', 'jerome@gmail.com', '09872656352', 'Programming', 4, 'active', 9, 'LSI-047E71036B17'),
(6, 'Xhantel', 'Ramos', 'Female', '2005-12-25', 'Zone 2, Pola, Oriental Mindoro', 'xhantel@gmail.com', '0975533676', 'Programming', NULL, 'active', 10, 'LSI-AAC7BBBF6707');

-- --------------------------------------------------------

--
-- Table structure for table `trainee_documents`
--

CREATE TABLE `trainee_documents` (
  `document_id` int UNSIGNED NOT NULL,
  `trainee_id` int UNSIGNED DEFAULT NULL,
  `class_id` int UNSIGNED DEFAULT NULL,
  `uploaded_by` int UNSIGNED DEFAULT NULL,
  `document_name` varchar(150) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `upload_date` date NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'active',
  `access_level` varchar(20) NOT NULL DEFAULT 'private',
  `review_status` varchar(20) NOT NULL DEFAULT 'approved',
  `reviewed_by` int UNSIGNED DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `trainee_documents`
--

INSERT INTO `trainee_documents` (`document_id`, `trainee_id`, `class_id`, `uploaded_by`, `document_name`, `file_path`, `upload_date`, `status`, `access_level`, `review_status`, `reviewed_by`, `reviewed_at`) VALUES
(6, 1, 1, 1, 'Resume', 'cloud://documents/2026/10/ee2a75ab5c877511949422a5590e22f87d8cf17d.docx', '2026-10-29', 'active', 'class', 'approved', NULL, NULL),
(7, 2, 1, 1, 'Resume', 'cloud://documents/2026/10/54b590f7ebdf7276b9d3eff1d17505bd8514d08f.docx', '2026-10-29', 'active', 'class', 'approved', NULL, NULL),
(8, 2, NULL, 6, 'Resume', 'cloud://documents/2026/10/d7de8b00c083c90f649b16cc6c0e952be9dd879b.docx', '2026-10-29', 'inactive', 'private', 'approved', NULL, NULL),
(9, 1, NULL, 4, 'Resume', 'cloud://documents/2026/10/f4c32574d8e3ebbdace462b14afd2852eac7f642.docx', '2026-10-29', 'inactive', 'private', 'approved', NULL, NULL),
(14, NULL, NULL, 1, 'SmartTrack', 'cloud://documents/2026/11/8068f4486472f0d6382ee9b5acc3c887ef44554e.docx', '2026-11-03', 'active', 'private', 'approved', NULL, NULL),
(15, 4, 3, 8, 'Assignment', 'cloud://documents/2026/11/c70d585c6d1ef0dc28a37d9518bca023a82a19c0.pdf', '2026-11-03', 'active', 'private', 'approved', 5, '2026-11-03 12:51:04'),
(16, 5, 3, 9, 'Assignment', 'cloud://documents/2026/11/346ae0ac16899057add0fc794ede90f5d0f08e55.docx', '2026-11-03', 'active', 'private', 'approved', 5, '2026-11-03 12:51:07'),
(18, NULL, NULL, 1, 'AGResult', 'cloud://documents/2026/11/006f23584edbf6b1c3a8352dfe101790503ad09e.docx', '2026-11-03', 'active', 'staff', 'approved', NULL, NULL),
(20, 4, 3, 8, 'Kahit Ano', 'cloud://documents/2026/11/195a16c3bb8f99d6f025e6a8599417442baa50ca.docx', '2026-11-05', 'active', 'private', 'approved', 3, '2026-11-05 01:57:43'),
(21, 5, 3, 9, 'Kahit Ano', 'cloud://documents/2026/11/bc0921d4893f4ec91b3107e17d5a27577ff6a6b3.pdf', '2026-11-05', 'active', 'private', 'approved', 3, '2026-11-05 01:57:46');

-- --------------------------------------------------------

--
-- Table structure for table `trainee_fees`
--

CREATE TABLE `trainee_fees` (
  `trainee_fee_id` int UNSIGNED NOT NULL,
  `fee_type_id` int UNSIGNED DEFAULT NULL,
  `trainee_id` int UNSIGNED NOT NULL,
  `class_id` int UNSIGNED NOT NULL,
  `fee_kind` varchar(20) NOT NULL,
  `fee_name` varchar(150) NOT NULL,
  `amount_due` decimal(12,2) NOT NULL,
  `due_date` date DEFAULT NULL,
  `bond_status` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `trainee_fees`
--

INSERT INTO `trainee_fees` (`trainee_fee_id`, `fee_type_id`, `trainee_id`, `class_id`, `fee_kind`, `fee_name`, `amount_due`, `due_date`, `bond_status`, `created_at`) VALUES
(1, 1, 1, 1, 'registration', 'Registration', 500.00, '2026-10-31', NULL, '2026-10-29 10:42:31'),
(2, 1, 2, 1, 'registration', 'Registration', 500.00, '2026-10-31', NULL, '2026-10-29 10:42:31'),
(3, 2, 1, 1, 'bond', 'Deposit', 500.00, '2026-10-31', 'unpaid', '2026-10-29 11:52:22'),
(4, 2, 2, 1, 'bond', 'Deposit', 500.00, '2026-10-31', 'unpaid', '2026-10-29 11:52:22'),
(5, 3, 4, 3, 'registration', 'Registration', 500.00, '2026-11-07', NULL, '2026-11-03 12:02:03'),
(6, 3, 5, 3, 'registration', 'Registration', 500.00, '2026-11-07', NULL, '2026-11-03 12:02:03'),
(7, 4, 4, 3, 'bond', 'Deposit', 500.00, '2026-11-07', 'held', '2026-11-03 12:02:36'),
(8, 4, 5, 3, 'bond', 'Deposit', 500.00, '2026-11-07', 'held', '2026-11-03 12:02:36'),
(9, 5, 4, 3, 'bond', 'deposit', 12.00, '2026-11-07', 'held', '2026-11-04 12:47:45'),
(10, 5, 5, 3, 'bond', 'deposit', 12.00, '2026-11-07', 'held', '2026-11-04 12:47:45');

-- --------------------------------------------------------

--
-- Table structure for table `training_assessments`
--

CREATE TABLE `training_assessments` (
  `assessment_id` int UNSIGNED NOT NULL,
  `trainee_id` int UNSIGNED NOT NULL,
  `class_id` int UNSIGNED NOT NULL,
  `assessment_name` varchar(150) NOT NULL,
  `outcome` varchar(10) NOT NULL,
  `assessed_by` int UNSIGNED NOT NULL,
  `assessment_date` date NOT NULL,
  `notes` text,
  `time_limit_minutes` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `training_assessments`
--

INSERT INTO `training_assessments` (`assessment_id`, `trainee_id`, `class_id`, `assessment_name`, `outcome`, `assessed_by`, `assessment_date`, `notes`, `time_limit_minutes`, `created_at`, `updated_at`) VALUES
(2, 1, 1, 'Resume', 'pass', 1, '2026-10-29', 'WELL DONE!!!', NULL, '2026-10-29 01:29:24', '2026-10-29 01:29:24'),
(3, 2, 1, 'Resume', 'pass', 1, '2026-10-29', 'WELL DONE!!!', NULL, '2026-10-29 01:29:42', '2026-10-29 01:29:42'),
(4, 1, 1, 'Resume requirement', 'pass', 1, '2026-11-02', 'Well Done!!!', NULL, '2026-11-02 14:33:30', '2026-11-02 14:33:30'),
(5, 2, 1, 'Resume requirement', 'pass', 1, '2026-11-02', 'Well Done!!!', NULL, '2026-11-02 14:34:13', '2026-11-02 14:34:13'),
(6, 4, 3, 'Quiz 1.', 'pass', 3, '2026-11-05', '', 20, '2026-11-05 02:08:28', '2026-11-05 02:08:28'),
(7, 5, 3, 'Quiz 1.', 'pass', 3, '2026-11-05', '', 20, '2026-11-05 02:09:03', '2026-11-05 02:09:03');

-- --------------------------------------------------------

--
-- Table structure for table `training_classes`
--

CREATE TABLE `training_classes` (
  `class_id` int UNSIGNED NOT NULL,
  `program_id` int UNSIGNED NOT NULL,
  `instructor_id` int UNSIGNED DEFAULT NULL,
  `class_name` varchar(150) NOT NULL,
  `room` varchar(150) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `training_classes`
--

INSERT INTO `training_classes` (`class_id`, `program_id`, `instructor_id`, `class_name`, `room`, `start_date`, `end_date`, `status`, `created_at`) VALUES
(1, 1, 3, 'Css class 1', NULL, '2026-10-29', '2026-11-03', 'active', '2026-10-27 06:22:37'),
(3, 4, 3, 'Accento class1', NULL, '2026-11-05', '2026-11-06', 'active', '2026-11-03 04:25:35');

-- --------------------------------------------------------

--
-- Table structure for table `training_programs`
--

CREATE TABLE `training_programs` (
  `program_id` int UNSIGNED NOT NULL,
  `program_name` varchar(150) NOT NULL,
  `program_code` varchar(50) DEFAULT NULL,
  `description` text,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `training_programs`
--

INSERT INTO `training_programs` (`program_id`, `program_name`, `program_code`, `description`, `status`, `created_at`) VALUES
(1, 'Java CSS', 'java09', 'jsbhfiu au', 'active', '2026-10-27 06:21:24'),
(4, 'Accento Advance', 'accento01', 'bvscuywscdhbk', 'active', '2026-11-03 04:21:32'),
(5, 'Python', 'python1', 'nm chuvasdfafyh', 'active', '2026-11-04 23:24:05');

-- --------------------------------------------------------

--
-- Table structure for table `training_schedules`
--

CREATE TABLE `training_schedules` (
  `schedule_id` int UNSIGNED NOT NULL,
  `program_id` int UNSIGNED DEFAULT NULL,
  `class_id` int UNSIGNED DEFAULT NULL,
  `schedule_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `training_schedules`
--

INSERT INTO `training_schedules` (`schedule_id`, `program_id`, `class_id`, `schedule_date`, `start_time`, `end_time`) VALUES
(11, 4, 3, '2026-11-05', '09:45:00', '10:37:00'),
(13, 4, 3, '2026-11-06', '09:30:00', '11:31:00');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int UNSIGNED NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(30) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `username`, `email`, `password`, `role`, `status`, `created_at`) VALUES
(1, 'Mark Harry Lupig', 'Harry', NULL, '$2y$10$.c8vnnGk7SlD2zAQFfnf0ublf1/8alX4wLf/RDCM1ODEs.gRw9Hgi', 'administrator', 'active', '2026-10-26 11:48:30'),
(3, 'Jasmine Mae Gagote', 'Jasmine', NULL, '$2y$10$6G7BRLRbViySLzhwRNJybu2/.C4FLEz40bLpEd8qZ71jBA5Ehrg/u', 'instructor', 'active', '2026-10-27 06:16:10'),
(4, 'Raver Mychael Alagao', 'Raver', NULL, '$2y$10$ZlRNUnUlq5DOlTND2RvRyuzIJWEtnBs7lNg/4C3KbUm/ohtwuIyfu', 'trainee', 'active', '2026-10-27 06:17:52'),
(5, 'Nenita Magboo', 'Nenita', NULL, '$2y$10$erwu6psYIh.Uc5olTX53OejX0TPYPhpl68Z9ApqNKmoK5cOvqekPe', 'staff', 'active', '2026-10-27 06:19:02'),
(6, 'Mark Harry', 'Marky', NULL, '$2y$10$tfR6nnf2KA10MbIQ5aSl.ul/MTTJ63gmWynX4T6t5lK4K/HkiDp.K', 'trainee', 'active', '2026-10-29 01:17:00'),
(7, 'Marky', 'Markharyy', 'lupigmarkharry539@gmail.com', '$2y$10$/9U/ubsBhAl14/pG8dzms.TZ67Na7qXEU/H.IfZO0Vp1OWuptTmhC', 'trainee', 'active', '2026-11-03 04:14:20'),
(8, 'Allesa Marie', 'Allesa', 'alle@gmail.com', '$2y$10$URm2UeI1V55txs.pxdussO7QHeq8SRa9fljmfBRGZa1mel6wgkhMq', 'trainee', 'active', '2026-11-03 04:22:54'),
(9, 'Jerome Sajul', 'Jerome', 'jerome@gmail.com', '$2y$10$RioaYROQQicx4ry4ZR8.1u/0pw.zzH.Q5EaoilvPWVvHyNcZbne8S', 'trainee', 'active', '2026-11-03 11:48:37'),
(10, 'Xhantel Ramos', 'Xhantel', 'xhantel@gmail.com', '$2y$10$QIiuemaVTg2d1IbfEQ2zruFU1xbFm02EpMVCaMlfjwxk4gM4HYHTS', 'trainee', 'active', '2026-11-05 00:20:29');

-- --------------------------------------------------------

--
-- Table structure for table `xendit_payments`
--

CREATE TABLE `xendit_payments` (
  `id` bigint UNSIGNED NOT NULL,
  `trainee_fee_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `flow` enum('invoice','payment-link') NOT NULL,
  `reference_id` varchar(100) NOT NULL,
  `provider_id` varchar(150) DEFAULT NULL,
  `provider_transaction_id` varchar(150) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `status` enum('pending','paid','failed') NOT NULL DEFAULT 'pending',
  `checkout_url` varchar(1000) NOT NULL,
  `raw_response` json DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `xendit_payments`
--

INSERT INTO `xendit_payments` (`id`, `trainee_fee_id`, `user_id`, `flow`, `reference_id`, `provider_id`, `amount`, `status`, `checkout_url`, `raw_response`, `paid_at`, `created_at`, `updated_at`) VALUES
(1, 9, 8, 'invoice', 'ST-9-9c0961b993355505', 'ps-6aeb4a948d06bcd762c09d07', 12.00, 'pending', 'https://dev.xen.to/4YV01EzD', '{\"mode\": \"PAYMENT_LINK\", \"items\": [{\"name\": \"deposit\", \"type\": \"DIGITAL_PRODUCT\", \"category\": \"EDUCATION\", \"quantity\": 1, \"description\": \"SmartTrack training fee\", \"reference_id\": \"fee-9\", \"net_unit_amount\": 12}], \"amount\": 12, \"locale\": \"en\", \"status\": \"ACTIVE\", \"country\": \"PH\", \"created\": \"2026-11-04T15:09:08.103Z\", \"updated\": \"2026-11-04T15:09:08.103Z\", \"currency\": \"PHP\", \"expires_at\": \"2026-11-04T15:39:07.905Z\", \"business_id\": \"6aeb24d4cfe90b9c667c6778\", \"expires_ttl\": 1799, \"reference_id\": \"ST-9-9c0961b993355505\", \"session_type\": \"PAY\", \"capture_method\": \"AUTOMATIC\", \"payment_link_url\": \"https://dev.xen.to/4YV01EzD\", \"payment_session_id\": \"ps-6aeb4a948d06bcd762c09d07\", \"success_return_url\": \"https://lsi-vue-frontend.onrender.com/payments?payment=success\"}', NULL, '2026-11-04 15:09:08', '2026-11-04 15:09:08'),
(2, 9, 8, 'payment-link', 'ST-9-27c0cff862f8058d', 'ps-6aeb4bad9104fa5c9e8342d4', 12.00, 'pending', 'https://dev.xen.to/t-uo86WR', '{\"mode\": \"PAYMENT_LINK\", \"items\": [{\"name\": \"deposit\", \"type\": \"DIGITAL_PRODUCT\", \"category\": \"EDUCATION\", \"quantity\": 1, \"description\": \"SmartTrack training fee\", \"reference_id\": \"fee-9\", \"net_unit_amount\": 12}], \"amount\": 12, \"locale\": \"en\", \"status\": \"ACTIVE\", \"country\": \"PH\", \"created\": \"2026-11-04T15:13:50.047Z\", \"updated\": \"2026-11-04T15:13:50.047Z\", \"currency\": \"PHP\", \"expires_at\": \"2026-11-04T15:43:49.948Z\", \"business_id\": \"6aeb24d4cfe90b9c667c6778\", \"expires_ttl\": 1799, \"reference_id\": \"ST-9-27c0cff862f8058d\", \"session_type\": \"PAY\", \"capture_method\": \"AUTOMATIC\", \"payment_link_url\": \"https://dev.xen.to/t-uo86WR\", \"payment_session_id\": \"ps-6aeb4bad9104fa5c9e8342d4\", \"success_return_url\": \"https://lsi-vue-frontend.onrender.com/payments?payment=success\"}', NULL, '2026-11-04 15:13:50', '2026-11-04 15:13:50'),
(3, 10, 9, 'invoice', 'ST-10-8bfdab0de0e9e1df', 'ps-6aeb4d5311e31c808784e2e4', 12.00, 'pending', 'https://dev.xen.to/mMbOrZkG', '{\"mode\": \"PAYMENT_LINK\", \"items\": [{\"name\": \"deposit\", \"type\": \"DIGITAL_PRODUCT\", \"category\": \"EDUCATION\", \"quantity\": 1, \"description\": \"SmartTrack training fee\", \"reference_id\": \"fee-10\", \"net_unit_amount\": 12}], \"amount\": 12, \"locale\": \"en\", \"status\": \"ACTIVE\", \"country\": \"PH\", \"created\": \"2026-11-04T15:20:51.207Z\", \"updated\": \"2026-11-04T15:20:51.207Z\", \"currency\": \"PHP\", \"expires_at\": \"2026-11-04T15:50:50.937Z\", \"business_id\": \"6aeb24d4cfe90b9c667c6778\", \"expires_ttl\": 1799, \"reference_id\": \"ST-10-8bfdab0de0e9e1df\", \"session_type\": \"PAY\", \"capture_method\": \"AUTOMATIC\", \"payment_link_url\": \"https://dev.xen.to/mMbOrZkG\", \"payment_session_id\": \"ps-6aeb4d5311e31c808784e2e4\", \"success_return_url\": \"https://lsi-vue-frontend.onrender.com/payments?payment=success\"}', NULL, '2026-11-04 15:20:51', '2026-11-04 15:20:51');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_smarttrack_activity_user` (`user_id`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`announcement_id`),
  ADD KEY `idx_smarttrack_announcement_user` (`user_id`),
  ADD KEY `idx_smarttrack_announcement_class` (`class_id`);

--
-- Indexes for table `announcement_notifications`
--
ALTER TABLE `announcement_notifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_notification_delivery` (`announcement_id`,`trainee_id`,`channel`),
  ADD KEY `idx_notification_status` (`status`,`channel`),
  ADD KEY `idx_notification_announcement` (`announcement_id`),
  ADD KEY `fk_announcement_notification_trainee` (`trainee_id`);

--
-- Indexes for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD PRIMARY KEY (`attendance_id`),
  ADD UNIQUE KEY `uq_smarttrack_attendance_class_day` (`trainee_id`,`class_id`,`attendance_date`),
  ADD KEY `idx_smarttrack_attendance_class` (`class_id`),
  ADD KEY `idx_smarttrack_attendance_schedule` (`schedule_id`),
  ADD KEY `smarttrack_attendance_recorder_fk` (`recorded_by`);

--
-- Indexes for table `bond_transactions`
--
ALTER TABLE `bond_transactions`
  ADD PRIMARY KEY (`bond_transaction_id`),
  ADD UNIQUE KEY `uq_smarttrack_bond_reference` (`reference_number`),
  ADD KEY `idx_smarttrack_bond_fee_date` (`trainee_fee_id`,`transaction_date`),
  ADD KEY `smarttrack_bond_payment_fk` (`payment_id`),
  ADD KEY `smarttrack_bond_performer_fk` (`performed_by`);

--
-- Indexes for table `certificates`
--
ALTER TABLE `certificates`
  ADD PRIMARY KEY (`certificate_id`),
  ADD UNIQUE KEY `uq_smarttrack_certificate_number` (`certificate_number`),
  ADD UNIQUE KEY `uq_smarttrack_certificate_trainee_class` (`trainee_id`,`class_id`),
  ADD KEY `idx_smarttrack_certificate_trainee` (`trainee_id`),
  ADD KEY `idx_smarttrack_certificate_class` (`class_id`),
  ADD KEY `idx_smarttrack_certificate_program` (`program_id`),
  ADD KEY `smarttrack_certificates_revoker_fk` (`revoked_by`);

--
-- Indexes for table `certificate_audit_logs`
--
ALTER TABLE `certificate_audit_logs`
  ADD PRIMARY KEY (`audit_id`),
  ADD KEY `idx_smarttrack_certificate_audit_certificate` (`certificate_id`),
  ADD KEY `idx_smarttrack_certificate_audit_eligibility` (`eligibility_id`),
  ADD KEY `smarttrack_certificate_audit_actor_fk` (`actor_id`);

--
-- Indexes for table `certificate_config`
--
ALTER TABLE `certificate_config`
  ADD PRIMARY KEY (`config_id`),
  ADD UNIQUE KEY `uq_smarttrack_config_key` (`config_key`);

--
-- Indexes for table `certificate_document_requirements`
--
ALTER TABLE `certificate_document_requirements`
  ADD PRIMARY KEY (`requirement_id`),
  ADD UNIQUE KEY `uq_smarttrack_required_document_class_name` (`class_id`,`required_document_name`),
  ADD KEY `smarttrack_document_requirement_creator_fk` (`created_by`);

--
-- Indexes for table `certificate_eligibility`
--
ALTER TABLE `certificate_eligibility`
  ADD PRIMARY KEY (`eligibility_id`),
  ADD UNIQUE KEY `uq_smarttrack_eligibility_trainee_class` (`trainee_id`,`class_id`),
  ADD KEY `idx_smarttrack_eligibility_trainee` (`trainee_id`),
  ADD KEY `idx_smarttrack_eligibility_class` (`class_id`),
  ADD KEY `smarttrack_eligibility_reviewer_fk` (`reviewed_by`),
  ADD KEY `smarttrack_eligibility_approver_fk` (`approved_by`);

--
-- Indexes for table `certificate_notifications`
--
ALTER TABLE `certificate_notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `idx_certificate_notification_user` (`user_id`,`read_at`),
  ADD KEY `smarttrack_certificate_notification_certificate_fk` (`certificate_id`);

--
-- Indexes for table `certificate_signatories`
--
ALTER TABLE `certificate_signatories`
  ADD PRIMARY KEY (`signatory_id`),
  ADD UNIQUE KEY `uq_certificate_signatory_user` (`user_id`),
  ADD KEY `idx_certificate_signatory_active` (`is_active`),
  ADD KEY `smarttrack_certificate_signatory_creator_fk` (`created_by`);

--
-- Indexes for table `certificate_signatures`
--
ALTER TABLE `certificate_signatures`
  ADD PRIMARY KEY (`signature_id`),
  ADD UNIQUE KEY `uq_certificate_signature_slot` (`certificate_id`,`signatory_id`),
  ADD KEY `idx_certificate_signature_queue` (`user_id`,`status`),
  ADD KEY `smarttrack_certificate_signature_signatory_fk` (`signatory_id`);

--
-- Indexes for table `certificate_templates`
--
ALTER TABLE `certificate_templates`
  ADD PRIMARY KEY (`template_id`),
  ADD UNIQUE KEY `uq_certificate_template_version` (`version`),
  ADD KEY `idx_certificate_template_active` (`is_active`),
  ADD KEY `smarttrack_certificate_template_creator_fk` (`created_by`);

--
-- Indexes for table `class_enrollments`
--
ALTER TABLE `class_enrollments`
  ADD PRIMARY KEY (`enrollment_id`),
  ADD UNIQUE KEY `uq_smarttrack_class_trainee` (`class_id`,`trainee_id`),
  ADD KEY `idx_smarttrack_enrollment_trainee` (`trainee_id`);

--
-- Indexes for table `fee_types`
--
ALTER TABLE `fee_types`
  ADD PRIMARY KEY (`fee_type_id`),
  ADD KEY `idx_smarttrack_fee_program` (`program_id`),
  ADD KEY `idx_smarttrack_fee_class` (`class_id`),
  ADD KEY `idx_smarttrack_fee_scope` (`fee_kind`,`is_active`),
  ADD KEY `smarttrack_fee_creator_fk` (`created_by`);

--
-- Indexes for table `inquiries`
--
ALTER TABLE `inquiries`
  ADD PRIMARY KEY (`inquiry_id`),
  ADD KEY `idx_smarttrack_inquiry_trainee` (`trainee_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `idx_smarttrack_notification_user` (`user_id`);

--
-- Indexes for table `notification_settings`
--
ALTER TABLE `notification_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD UNIQUE KEY `uq_smarttrack_payment_reference` (`reference_number`),
  ADD UNIQUE KEY `uq_smarttrack_payment_receipt` (`receipt_number`),
  ADD KEY `idx_smarttrack_payment_fee_date` (`trainee_fee_id`,`payment_date`),
  ADD KEY `idx_smarttrack_payment_reversal` (`reversal_of_payment_id`),
  ADD KEY `smarttrack_payment_recorder_fk` (`recorded_by`);

--
-- Indexes for table `payment_proofs`
--
ALTER TABLE `payment_proofs`
  ADD PRIMARY KEY (`proof_id`),
  ADD UNIQUE KEY `uq_smarttrack_proof_reference` (`reference_number`),
  ADD KEY `idx_smarttrack_proof_fee_status` (`trainee_fee_id`,`status`),
  ADD KEY `smarttrack_proof_uploader_fk` (`uploaded_by`),
  ADD KEY `smarttrack_proof_payment_fk` (`payment_id`),
  ADD KEY `smarttrack_proof_verifier_fk` (`verified_by`);

--
-- Indexes for table `refresh_tokens`
--
ALTER TABLE `refresh_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_token_hash` (`token_hash`),
  ADD KEY `idx_expires_at` (`expires_at`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`role_id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `trainees`
--
ALTER TABLE `trainees`
  ADD PRIMARY KEY (`trainee_id`),
  ADD UNIQUE KEY `uq_smarttrack_trainee_qr` (`qr_code`),
  ADD UNIQUE KEY `uq_smarttrack_trainee_user` (`user_id`),
  ADD KEY `idx_smarttrack_trainee_program` (`program_id`);

--
-- Indexes for table `trainee_documents`
--
ALTER TABLE `trainee_documents`
  ADD PRIMARY KEY (`document_id`),
  ADD KEY `idx_smarttrack_document_trainee` (`trainee_id`),
  ADD KEY `idx_smarttrack_document_class` (`class_id`),
  ADD KEY `idx_smarttrack_document_uploader` (`uploaded_by`);

--
-- Indexes for table `trainee_fees`
--
ALTER TABLE `trainee_fees`
  ADD PRIMARY KEY (`trainee_fee_id`),
  ADD UNIQUE KEY `uq_smarttrack_trainee_fee_instance` (`trainee_id`,`class_id`,`fee_type_id`),
  ADD KEY `idx_smarttrack_trainee_fee_trainee` (`trainee_id`),
  ADD KEY `idx_smarttrack_trainee_fee_class` (`class_id`),
  ADD KEY `idx_smarttrack_trainee_fee_kind_status` (`fee_kind`,`bond_status`),
  ADD KEY `smarttrack_trainee_fee_type_fk` (`fee_type_id`);

--
-- Indexes for table `training_assessments`
--
ALTER TABLE `training_assessments`
  ADD PRIMARY KEY (`assessment_id`),
  ADD UNIQUE KEY `uq_smarttrack_assessment_trainee_class_name` (`trainee_id`,`class_id`,`assessment_name`),
  ADD KEY `idx_smarttrack_assessment_class_outcome` (`class_id`,`outcome`),
  ADD KEY `smarttrack_assessments_assessor_fk` (`assessed_by`);

--
-- Indexes for table `training_classes`
--
ALTER TABLE `training_classes`
  ADD PRIMARY KEY (`class_id`),
  ADD KEY `idx_smarttrack_class_program` (`program_id`),
  ADD KEY `idx_smarttrack_class_instructor` (`instructor_id`);

--
-- Indexes for table `training_programs`
--
ALTER TABLE `training_programs`
  ADD PRIMARY KEY (`program_id`),
  ADD UNIQUE KEY `uq_training_programs_program_code` (`program_code`);

--
-- Indexes for table `training_schedules`
--
ALTER TABLE `training_schedules`
  ADD PRIMARY KEY (`schedule_id`),
  ADD KEY `idx_smarttrack_schedule_program` (`program_id`),
  ADD KEY `idx_smarttrack_schedule_class` (`class_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uq_smarttrack_username` (`username`);

--
-- Indexes for table `xendit_payments`
--
ALTER TABLE `xendit_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_xendit_reference` (`reference_id`),
  ADD UNIQUE KEY `uq_xendit_provider_transaction` (`provider_transaction_id`),
  ADD KEY `idx_xendit_fee_status` (`trainee_fee_id`,`status`),
  ADD KEY `fk_xendit_payment_user` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `log_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=225;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `announcement_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `announcement_notifications`
--
ALTER TABLE `announcement_notifications`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `attendance_records`
--
ALTER TABLE `attendance_records`
  MODIFY `attendance_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `bond_transactions`
--
ALTER TABLE `bond_transactions`
  MODIFY `bond_transaction_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `certificates`
--
ALTER TABLE `certificates`
  MODIFY `certificate_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `certificate_audit_logs`
--
ALTER TABLE `certificate_audit_logs`
  MODIFY `audit_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `certificate_config`
--
ALTER TABLE `certificate_config`
  MODIFY `config_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `certificate_document_requirements`
--
ALTER TABLE `certificate_document_requirements`
  MODIFY `requirement_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `certificate_eligibility`
--
ALTER TABLE `certificate_eligibility`
  MODIFY `eligibility_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `certificate_notifications`
--
ALTER TABLE `certificate_notifications`
  MODIFY `notification_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `certificate_signatories`
--
ALTER TABLE `certificate_signatories`
  MODIFY `signatory_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `certificate_signatures`
--
ALTER TABLE `certificate_signatures`
  MODIFY `signature_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `certificate_templates`
--
ALTER TABLE `certificate_templates`
  MODIFY `template_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `class_enrollments`
--
ALTER TABLE `class_enrollments`
  MODIFY `enrollment_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `fee_types`
--
ALTER TABLE `fee_types`
  MODIFY `fee_type_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `inquiries`
--
ALTER TABLE `inquiries`
  MODIFY `inquiry_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `payment_proofs`
--
ALTER TABLE `payment_proofs`
  MODIFY `proof_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `refresh_tokens`
--
ALTER TABLE `refresh_tokens`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `role_id` tinyint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trainees`
--
ALTER TABLE `trainees`
  MODIFY `trainee_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `trainee_documents`
--
ALTER TABLE `trainee_documents`
  MODIFY `document_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `trainee_fees`
--
ALTER TABLE `trainee_fees`
  MODIFY `trainee_fee_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `training_assessments`
--
ALTER TABLE `training_assessments`
  MODIFY `assessment_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `training_classes`
--
ALTER TABLE `training_classes`
  MODIFY `class_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `training_programs`
--
ALTER TABLE `training_programs`
  MODIFY `program_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `training_schedules`
--
ALTER TABLE `training_schedules`
  MODIFY `schedule_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `xendit_payments`
--
ALTER TABLE `xendit_payments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `smarttrack_activity_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `announcements`
--
ALTER TABLE `announcements`
  ADD CONSTRAINT `smarttrack_announcements_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `announcement_notifications`
--
ALTER TABLE `announcement_notifications`
  ADD CONSTRAINT `fk_announcement_notification_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`announcement_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_announcement_notification_trainee` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`trainee_id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD CONSTRAINT `smarttrack_attendance_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_attendance_recorder_fk` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_attendance_schedule_fk` FOREIGN KEY (`schedule_id`) REFERENCES `training_schedules` (`schedule_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_attendance_trainee_fk` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`trainee_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `bond_transactions`
--
ALTER TABLE `bond_transactions`
  ADD CONSTRAINT `smarttrack_bond_fee_fk` FOREIGN KEY (`trainee_fee_id`) REFERENCES `trainee_fees` (`trainee_fee_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_bond_payment_fk` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_bond_performer_fk` FOREIGN KEY (`performed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `certificates`
--
ALTER TABLE `certificates`
  ADD CONSTRAINT `smarttrack_certificates_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_certificates_program_fk` FOREIGN KEY (`program_id`) REFERENCES `training_programs` (`program_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_certificates_revoker_fk` FOREIGN KEY (`revoked_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_certificates_trainee_fk` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`trainee_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `certificate_audit_logs`
--
ALTER TABLE `certificate_audit_logs`
  ADD CONSTRAINT `smarttrack_certificate_audit_actor_fk` FOREIGN KEY (`actor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_certificate_audit_certificate_fk` FOREIGN KEY (`certificate_id`) REFERENCES `certificates` (`certificate_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_certificate_audit_eligibility_fk` FOREIGN KEY (`eligibility_id`) REFERENCES `certificate_eligibility` (`eligibility_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `certificate_document_requirements`
--
ALTER TABLE `certificate_document_requirements`
  ADD CONSTRAINT `smarttrack_document_requirement_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_document_requirement_creator_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `certificate_eligibility`
--
ALTER TABLE `certificate_eligibility`
  ADD CONSTRAINT `smarttrack_eligibility_approver_fk` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_eligibility_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_eligibility_reviewer_fk` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_eligibility_trainee_fk` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`trainee_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `certificate_notifications`
--
ALTER TABLE `certificate_notifications`
  ADD CONSTRAINT `smarttrack_certificate_notification_certificate_fk` FOREIGN KEY (`certificate_id`) REFERENCES `certificates` (`certificate_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_certificate_notification_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `certificate_signatories`
--
ALTER TABLE `certificate_signatories`
  ADD CONSTRAINT `smarttrack_certificate_signatory_creator_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_certificate_signatory_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `certificate_signatures`
--
ALTER TABLE `certificate_signatures`
  ADD CONSTRAINT `smarttrack_certificate_signature_certificate_fk` FOREIGN KEY (`certificate_id`) REFERENCES `certificates` (`certificate_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_certificate_signature_signatory_fk` FOREIGN KEY (`signatory_id`) REFERENCES `certificate_signatories` (`signatory_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_certificate_signature_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `certificate_templates`
--
ALTER TABLE `certificate_templates`
  ADD CONSTRAINT `smarttrack_certificate_template_creator_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `class_enrollments`
--
ALTER TABLE `class_enrollments`
  ADD CONSTRAINT `smarttrack_enrollments_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_enrollments_trainee_fk` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`trainee_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `fee_types`
--
ALTER TABLE `fee_types`
  ADD CONSTRAINT `smarttrack_fee_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_fee_creator_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_fee_program_fk` FOREIGN KEY (`program_id`) REFERENCES `training_programs` (`program_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `inquiries`
--
ALTER TABLE `inquiries`
  ADD CONSTRAINT `smarttrack_inquiries_trainee_fk` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`trainee_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `smarttrack_notifications_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `smarttrack_payment_fee_fk` FOREIGN KEY (`trainee_fee_id`) REFERENCES `trainee_fees` (`trainee_fee_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_payment_recorder_fk` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_payment_reversal_fk` FOREIGN KEY (`reversal_of_payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `payment_proofs`
--
ALTER TABLE `payment_proofs`
  ADD CONSTRAINT `smarttrack_proof_fee_fk` FOREIGN KEY (`trainee_fee_id`) REFERENCES `trainee_fees` (`trainee_fee_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_proof_payment_fk` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_proof_uploader_fk` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_proof_verifier_fk` FOREIGN KEY (`verified_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `refresh_tokens`
--
ALTER TABLE `refresh_tokens`
  ADD CONSTRAINT `refresh_tokens_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `trainees`
--
ALTER TABLE `trainees`
  ADD CONSTRAINT `smarttrack_trainees_program_fk` FOREIGN KEY (`program_id`) REFERENCES `training_programs` (`program_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_trainees_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `trainee_documents`
--
ALTER TABLE `trainee_documents`
  ADD CONSTRAINT `smarttrack_documents_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_documents_trainee_fk` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`trainee_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_documents_uploader_fk` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `trainee_fees`
--
ALTER TABLE `trainee_fees`
  ADD CONSTRAINT `smarttrack_trainee_fee_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_trainee_fee_trainee_fk` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`trainee_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_trainee_fee_type_fk` FOREIGN KEY (`fee_type_id`) REFERENCES `fee_types` (`fee_type_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `training_assessments`
--
ALTER TABLE `training_assessments`
  ADD CONSTRAINT `smarttrack_assessments_assessor_fk` FOREIGN KEY (`assessed_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_assessments_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_assessments_trainee_fk` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`trainee_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `training_classes`
--
ALTER TABLE `training_classes`
  ADD CONSTRAINT `smarttrack_classes_instructor_fk` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_classes_program_fk` FOREIGN KEY (`program_id`) REFERENCES `training_programs` (`program_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `training_schedules`
--
ALTER TABLE `training_schedules`
  ADD CONSTRAINT `smarttrack_schedules_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `smarttrack_schedules_program_fk` FOREIGN KEY (`program_id`) REFERENCES `training_programs` (`program_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `xendit_payments`
--
ALTER TABLE `xendit_payments`
  ADD CONSTRAINT `fk_xendit_payment_fee` FOREIGN KEY (`trainee_fee_id`) REFERENCES `trainee_fees` (`trainee_fee_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_xendit_payment_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Shared atomic rate-limit state for Gemini insight generation
--
CREATE TABLE `smarttrack_gemini_generation_limits` (
  `user_id` int unsigned NOT NULL,
  `window_started_at` datetime NOT NULL,
  `request_count` tinyint unsigned NOT NULL DEFAULT 0,
  `last_granted` tinyint unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`user_id`),
  KEY `idx_smarttrack_gemini_limits_window` (`window_started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
