-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 13, 2026 at 01:32 AM
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
-- Database: `barbershop`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `fname` varchar(50) NOT NULL,
  `mname` varchar(50) DEFAULT NULL,
  `lname` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expiry` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `fname`, `mname`, `lname`, `email`, `password`, `reset_token`, `reset_expiry`, `created_at`) VALUES
(1, 'Owner', '', '', 'admin@fadestreet.com', '$2y$10$K8xTKEHvhIz5HTKU27AsDeqSJmIlDwy5omz0wLoTnrUmlmnXuJb8y', NULL, NULL, '2026-03-07 02:53:57');

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `id` int(11) NOT NULL,
  `barber_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `status` enum('pending approval','approved','rejected','canceled','completed','no-show') NOT NULL DEFAULT 'pending approval',
  `payment_ref` varchar(100) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `total_persons` int(11) NOT NULL DEFAULT 1,
  `customer_reschedule_count` int(11) NOT NULL DEFAULT 0,
  `leave_reschedule_credit` int(11) NOT NULL DEFAULT 0,
  `reschedule_reason` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`id`, `barber_id`, `service_id`, `customer_id`, `start_time`, `end_time`, `status`, `payment_ref`, `rejection_reason`, `total_persons`, `customer_reschedule_count`, `leave_reschedule_credit`, `reschedule_reason`) VALUES
(2, 1, 4, 5, '2025-09-24 10:00:00', '2025-09-24 11:30:00', 'approved', NULL, NULL, 1, 0, 0, NULL),
(3, 3, 5, 4, '2025-09-24 15:00:00', '2025-09-24 17:30:00', 'approved', NULL, NULL, 1, 0, 0, NULL),
(4, 3, 5, 4, '2025-09-24 12:30:00', '2025-09-24 15:00:00', 'approved', NULL, NULL, 1, 0, 0, NULL),
(5, 1, 4, 4, '2025-09-25 10:00:00', '2025-09-25 11:30:00', 'approved', NULL, NULL, 1, 0, 0, NULL),
(6, 1, 4, 1, '2025-09-29 12:00:00', '2025-09-29 14:00:00', 'approved', NULL, NULL, 1, 0, 0, NULL),
(7, 1, 4, 1, '2025-09-29 12:00:00', '2025-09-29 14:00:00', 'rejected', NULL, NULL, 1, 0, 0, NULL),
(18, 1, 4, 4, '2025-10-03 10:00:00', '2025-10-03 11:00:00', 'canceled', NULL, NULL, 1, 0, 0, NULL),
(20, 2, 6, 4, '2025-09-20 09:00:00', '2025-09-20 10:00:00', 'completed', NULL, NULL, 1, 0, 0, NULL),
(21, 1, 4, 4, '2025-10-01 14:00:00', '2025-10-01 15:00:00', 'canceled', NULL, NULL, 1, 0, 0, NULL),
(23, 1, 6, 4, '2025-10-03 10:00:00', '2025-10-03 11:00:00', 'canceled', NULL, NULL, 1, 0, 0, NULL),
(25, 2, 9, 4, '2025-09-20 09:00:00', '2025-09-20 10:00:00', 'completed', NULL, NULL, 1, 0, 0, NULL),
(27, 1, 4, 4, '2025-10-01 14:00:00', '2025-10-01 14:30:00', 'completed', NULL, NULL, 1, 0, 0, NULL),
(28, 2, 7, 4, '2025-10-02 16:00:00', '2025-10-02 17:00:00', 'completed', NULL, NULL, 1, 0, 0, NULL),
(29, 1, 4, 4, '2025-10-04 14:00:00', '2025-10-04 14:30:00', 'completed', NULL, NULL, 1, 0, 0, NULL),
(30, 2, 7, 4, '2025-10-15 11:00:00', '2025-10-15 12:00:00', 'completed', NULL, NULL, 1, 0, 0, NULL),
(32, 2, 7, 4, '2025-10-11 10:00:00', '2025-10-11 11:00:00', 'canceled', NULL, NULL, 1, 0, 0, NULL),
(33, 4, 10, 4, '2025-10-09 10:00:00', '2025-10-09 13:00:00', 'approved', NULL, NULL, 1, 0, 0, NULL),
(34, 2, 4, 6, '2025-10-15 10:00:00', '2025-10-15 11:00:00', 'completed', NULL, NULL, 1, 0, 0, NULL),
(35, 1, 4, 4, '2025-10-21 10:00:00', '2025-10-21 11:30:00', 'approved', NULL, NULL, 1, 0, 0, NULL),
(36, 2, 4, 4, '2025-10-31 12:00:00', '2025-10-31 13:00:00', 'approved', NULL, NULL, 1, 0, 0, NULL),
(37, 1, 4, 4, '2025-10-24 10:00:00', '2025-10-24 12:00:00', 'approved', NULL, NULL, 1, 0, 0, NULL),
(38, 1, 5, 4, '2025-09-29 10:00:00', '2025-09-29 12:00:00', 'rejected', NULL, 'short 50 pesos', 1, 0, 0, NULL),
(39, 1, 5, 4, '2025-10-31 20:30:00', '2025-10-31 22:00:00', 'approved', NULL, NULL, 1, 0, 0, NULL),
(40, 6, 9, 6, '2025-12-06 12:00:00', '2025-12-06 13:00:00', 'canceled', NULL, NULL, 1, 0, 0, NULL),
(41, 2, 4, 4, '2026-03-07 17:30:00', '2026-03-07 18:00:00', 'rejected', NULL, NULL, 1, 0, 0, NULL),
(42, 2, 5, 4, '2026-03-14 10:00:00', '2026-03-14 11:30:00', 'approved', NULL, NULL, 1, 0, 0, NULL),
(43, 2, 4, 4, '2026-03-14 12:00:00', '2026-03-14 13:00:00', 'rejected', NULL, 'jasfqajklwdk', 1, 0, 0, NULL),
(44, 6, 4, 4, '2026-03-11 10:00:00', '2026-03-11 11:00:00', 'approved', NULL, NULL, 1, 0, 0, NULL),
(45, 5, 4, 4, '2026-03-12 17:30:00', '2026-03-12 20:00:00', 'completed', NULL, NULL, 1, 0, 0, NULL),
(46, 1, 4, 4, '2026-03-11 11:00:00', '2026-03-11 12:00:00', 'approved', NULL, NULL, 1, 0, 0, NULL),
(47, 1, 4, 6, '2026-03-13 10:00:00', '2026-03-13 11:00:00', 'completed', NULL, NULL, 1, 0, 0, NULL),
(48, 3, 4, 6, '2026-03-13 10:00:00', '2026-03-13 11:00:00', 'completed', NULL, NULL, 1, 0, 0, NULL),
(49, 2, 8, 6, '2026-03-13 10:00:00', '2026-03-13 12:30:00', 'no-show', NULL, NULL, 1, 0, 0, NULL),
(50, 2, 4, 6, '2026-03-14 19:30:00', '2026-03-14 20:00:00', '', NULL, NULL, 1, 0, 0, NULL),
(51, 1, 4, 6, '2026-03-14 19:30:00', '2026-03-14 20:00:00', 'no-show', NULL, NULL, 1, 0, 0, NULL),
(52, 4, 4, 6, '2026-03-15 10:00:00', '2026-03-15 11:00:00', 'no-show', NULL, NULL, 1, 0, 0, NULL),
(53, 1, 4, 14, '2026-03-17 11:00:00', '2026-03-17 12:00:00', 'completed', NULL, NULL, 1, 0, 0, NULL),
(54, 6, 4, 14, '2026-03-18 11:30:00', '2026-03-18 13:00:00', 'canceled', NULL, NULL, 1, 0, 0, NULL),
(55, 6, 4, 14, '2026-03-18 17:30:00', '2026-03-18 20:00:00', 'canceled', NULL, NULL, 1, 0, 0, NULL),
(56, 4, 4, 14, '2026-03-18 13:00:00', '2026-03-18 14:00:00', 'completed', NULL, NULL, 1, 0, 0, NULL),
(57, 2, 4, 6, '2026-03-21 12:00:00', '2026-03-21 13:00:00', 'completed', NULL, NULL, 1, 0, 0, NULL),
(58, 6, 4, 6, '2026-03-21 13:00:00', '2026-03-21 14:00:00', 'completed', NULL, NULL, 1, 0, 0, NULL),
(59, 5, 8, 6, '2026-03-21 15:00:00', '2026-03-21 17:30:00', 'approved', NULL, NULL, 1, 0, 0, NULL),
(60, 1, 4, 14, '2026-03-23 14:00:00', '2026-03-23 15:00:00', 'completed', NULL, NULL, 1, 0, 0, NULL),
(61, 2, 4, 6, '2026-03-24 21:00:00', '2026-03-24 21:30:00', 'approved', NULL, NULL, 1, 0, 0, NULL),
(63, 2, 10, 6, '2026-03-25 19:00:00', '2026-03-25 20:30:00', 'rejected', NULL, 'short 1 pesos', 1, 0, 0, NULL),
(67, 6, 4, 6, '2026-03-25 14:30:00', '2026-03-25 15:30:00', 'approved', NULL, NULL, 1, 0, 0, NULL),
(68, 6, 4, 6, '2026-03-26 10:00:00', '2026-03-26 10:30:00', 'canceled', NULL, NULL, 1, 0, 0, NULL),
(72, 3, 4, 6, '2026-03-28 10:00:00', '2026-03-28 11:00:00', 'canceled', NULL, NULL, 1, 0, 0, NULL),
(73, 2, 5, 6, '2026-03-28 10:00:00', '2026-03-28 11:30:00', 'completed', NULL, NULL, 1, 0, 0, NULL),
(75, 1, 10, 6, '2026-03-28 12:00:00', '2026-03-28 13:00:00', 'completed', NULL, NULL, 1, 0, 0, NULL),
(76, 1, 4, 6, '2026-03-29 10:00:00', '2026-03-29 10:30:00', 'completed', NULL, NULL, 1, 0, 0, NULL),
(77, 1, 4, 6, '2026-04-06 10:00:00', '2026-04-06 11:00:00', 'completed', NULL, NULL, 1, 0, 1, NULL),
(78, 6, 4, 6, '2026-04-06 12:30:00', '2026-04-06 13:00:00', 'canceled', NULL, NULL, 2, 1, 0, 'customer_request'),
(79, 2, 4, 14, '2026-04-09 12:30:00', '2026-04-09 13:00:00', 'completed', NULL, NULL, 2, 0, 0, NULL),
(80, 2, 4, 6, '2026-04-09 13:00:00', '2026-04-09 14:00:00', 'canceled', NULL, NULL, 3, 0, 0, NULL),
(81, 5, 4, 6, '2026-04-09 16:30:00', '2026-04-09 17:30:00', 'canceled', NULL, NULL, 2, 0, 0, NULL),
(82, 6, 4, 6, '2026-04-09 17:00:00', '2026-04-09 18:30:00', 'canceled', NULL, NULL, 2, 0, 1, NULL),
(83, 2, 4, 6, '2026-04-09 17:30:00', '2026-04-09 19:30:00', 'completed', NULL, NULL, 2, 0, 0, NULL),
(84, 2, 4, 14, '2026-04-10 10:00:00', '2026-04-10 11:00:00', 'completed', NULL, NULL, 2, 0, 0, NULL),
(85, 2, 4, 14, '2026-04-10 10:00:00', '2026-04-10 10:30:00', 'rejected', NULL, 'short dp', 2, 0, 0, NULL),
(86, 2, 10, 14, '2026-04-10 10:00:00', '2026-04-10 12:00:00', 'completed', NULL, NULL, 2, 0, 0, NULL),
(87, 3, 10, 14, '2026-04-10 10:00:00', '2026-04-10 13:00:00', 'completed', NULL, NULL, 3, 0, 0, NULL),
(88, 2, 4, 14, '2026-04-10 11:30:00', '2026-04-10 12:30:00', 'completed', NULL, NULL, 2, 0, 0, NULL),
(89, 6, 6, 14, '2026-04-28 10:00:00', '2026-04-28 11:00:00', 'completed', NULL, NULL, 2, 0, 0, NULL),
(90, 2, 4, 14, '2026-04-28 11:00:00', '2026-04-28 11:30:00', 'completed', NULL, NULL, 1, 0, 0, NULL),
(91, 6, 4, 17, '2026-05-04 15:00:00', '2026-05-04 16:30:00', 'approved', NULL, NULL, 1, 0, 0, NULL),
(92, 6, 4, 6, '2026-05-13 11:30:00', '2026-05-13 12:00:00', 'canceled', NULL, NULL, 2, 0, 0, NULL),
(93, 2, 4, 24, '2026-05-16 11:30:00', '2026-05-16 13:30:00', 'approved', NULL, NULL, 2, 0, 1, NULL),
(94, 6, 4, 20, '2026-07-09 10:00:00', '2026-07-09 13:30:00', 'approved', NULL, NULL, 2, 0, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `appointment_companions`
--

CREATE TABLE `appointment_companions` (
  `id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `person_no` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `service_id` int(11) DEFAULT NULL,
  `barber_id` int(11) DEFAULT NULL,
  `selected_services` text DEFAULT NULL,
  `total_price` decimal(10,2) DEFAULT 0.00,
  `total_duration` int(11) DEFAULT 0,
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `customer_reschedule_count` int(11) NOT NULL DEFAULT 0,
  `leave_reschedule_credit` int(11) NOT NULL DEFAULT 0,
  `reschedule_reason` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointment_companions`
--

INSERT INTO `appointment_companions` (`id`, `appointment_id`, `person_no`, `full_name`, `service_id`, `barber_id`, `selected_services`, `total_price`, `total_duration`, `start_time`, `end_time`, `created_at`, `customer_reschedule_count`, `leave_reschedule_credit`, `reschedule_reason`) VALUES
(4, 67, 2, 'Jec Castillo', 4, NULL, '[9]', 248.00, 60, NULL, NULL, '2026-03-25 04:05:12', 0, 0, NULL),
(5, 68, 1, 'Jec Castillo', 4, NULL, '[]', 199.00, 30, NULL, NULL, '2026-03-25 04:07:41', 0, 0, NULL),
(9, 72, 1, 'Jec Castillo', 4, NULL, '[9]', 248.00, 60, NULL, NULL, '2026-03-27 00:55:58', 0, 0, NULL),
(10, 73, 2, 'Jec Castillo', 5, NULL, '[6]', 298.00, 90, NULL, NULL, '2026-03-27 00:55:58', 0, 0, NULL),
(12, 75, 1, 'Jec Castillo', 10, 1, '[]', 499.00, 60, '2026-03-28 12:00:00', '2026-03-28 13:00:00', '2026-03-28 02:25:59', 0, 0, NULL),
(13, 76, 1, 'Jec Castillo', 4, 1, '[]', 199.00, 30, '2026-03-29 10:00:00', '2026-03-29 10:30:00', '2026-03-28 02:41:10', 0, 0, NULL),
(14, 77, 1, 'Jec Castillo', 4, 1, '[9]', 248.00, 60, '2026-04-06 10:00:00', '2026-04-06 11:00:00', '2026-04-05 09:20:45', 0, 0, NULL),
(15, 78, 1, 'Jec Castillo', 4, 1, '[]', 199.00, 30, '2026-04-09 13:30:00', '2026-04-09 14:00:00', '2026-04-06 04:26:42', 0, 0, NULL),
(16, 78, 2, 'Jec Castillo', 4, 2, '[]', 199.00, 30, '2026-04-06 12:30:00', '2026-04-06 13:00:00', '2026-04-06 04:26:42', 0, 0, NULL),
(17, 79, 1, 'Kiff Bautista', 4, 2, '[]', 199.00, 30, '2026-04-09 12:30:00', '2026-04-09 13:00:00', '2026-04-09 04:01:33', 0, 0, NULL),
(18, 79, 2, 'Kiff Bautista', 4, 3, '[]', 199.00, 30, '2026-04-09 12:30:00', '2026-04-09 13:00:00', '2026-04-09 04:01:33', 0, 0, NULL),
(19, 80, 1, 'Jec Castillo', 4, 2, '[]', 199.00, 30, '2026-04-09 13:00:00', '2026-04-09 13:30:00', '2026-04-09 04:47:06', 0, 0, NULL),
(20, 80, 2, 'Jec Castillo', 4, 6, '[]', 199.00, 30, '2026-04-09 13:00:00', '2026-04-09 13:30:00', '2026-04-09 04:47:06', 0, 0, NULL),
(21, 80, 3, 'Jec Castillo', 4, 6, '[]', 199.00, 30, '2026-04-09 13:30:00', '2026-04-09 14:00:00', '2026-04-09 04:47:06', 0, 0, NULL),
(22, 81, 1, 'Jec Castillo', 4, 2, '[9]', 248.00, 60, '2026-04-09 16:30:00', '2026-04-09 17:30:00', '2026-04-09 05:44:35', 1, 0, 'customer_request'),
(23, 81, 2, 'Jec Castillo', 4, 1, '[]', 199.00, 30, '2026-04-09 16:30:00', '2026-04-09 17:00:00', '2026-04-09 05:44:35', 1, 0, 'customer_request'),
(24, 82, 1, 'Jec Castillo', 4, 6, '[]', 199.00, 30, '2026-04-09 17:00:00', '2026-04-09 17:30:00', '2026-04-09 06:05:22', 0, 1, NULL),
(25, 82, 2, 'Jec Castillo', 4, 6, '[7]', 698.00, 90, '2026-04-11 10:00:00', '2026-04-11 11:30:00', '2026-04-09 06:05:22', 1, 0, 'barber_leave'),
(26, 83, 1, 'Jec Castillo', 4, 2, '[6,9]', 297.00, 90, '2026-04-09 18:30:00', '2026-04-09 19:30:00', '2026-04-09 09:05:22', 0, 0, NULL),
(27, 83, 2, 'Jec Castillo', 4, 5, '[9]', 248.00, 60, '2026-04-09 17:30:00', '2026-04-09 18:30:00', '2026-04-09 09:05:22', 0, 0, NULL),
(28, 84, 1, 'Kiff Bautista', 4, 2, '[9]', 248.00, 60, '2026-04-10 10:00:00', '2026-04-10 10:30:00', '2026-04-09 23:40:54', 0, 0, NULL),
(29, 84, 2, 'Kiff Bautista', 4, 3, '[9]', 248.00, 60, '2026-04-10 10:00:00', '2026-04-10 11:00:00', '2026-04-09 23:40:54', 0, 0, NULL),
(30, 85, 1, 'Kiff Bautista', 4, 2, '[]', 199.00, 30, '2026-04-10 10:00:00', '2026-04-10 10:30:00', '2026-04-10 00:15:44', 0, 0, NULL),
(31, 85, 2, 'Kiff Bautista', 4, 3, '[]', 199.00, 30, '2026-04-10 10:00:00', '2026-04-10 10:30:00', '2026-04-10 00:15:44', 0, 0, NULL),
(32, 86, 1, 'Kiff Bautista', 10, 2, '[6]', 548.00, 90, '2026-04-10 10:30:00', '2026-04-10 12:00:00', '2026-04-10 00:26:20', 0, 0, NULL),
(33, 86, 2, 'Kiff Bautista', 4, 3, '[6]', 248.00, 60, '2026-04-10 10:00:00', '2026-04-10 11:00:00', '2026-04-10 00:26:20', 0, 0, NULL),
(34, 87, 1, 'Kiff Bautista', 10, 3, '[6]', 548.00, 90, '2026-04-10 10:00:00', '2026-04-10 11:30:00', '2026-04-10 01:25:47', 0, 0, NULL),
(35, 87, 2, 'Kiff Bautista', 4, 3, '[]', 199.00, 30, '2026-04-10 11:30:00', '2026-04-10 12:00:00', '2026-04-10 01:25:47', 0, 0, NULL),
(36, 87, 3, 'Kiff Bautista', 8, 2, '[7]', 998.00, 180, '2026-04-10 10:00:00', '2026-04-10 13:00:00', '2026-04-10 01:25:47', 0, 0, NULL),
(37, 88, 1, 'Kiff Bautista', 4, 2, '[9,10]', 747.00, 120, '2026-04-10 11:30:00', '2026-04-10 12:30:00', '2026-04-10 03:20:46', 0, 0, NULL),
(38, 88, 2, 'Kiff Bautista', 4, 5, '[9]', 248.00, 60, '2026-04-10 11:30:00', '2026-04-10 12:00:00', '2026-04-10 03:20:46', 0, 0, NULL),
(39, 89, 1, 'Kiff Bautista', 6, 6, '[]', 49.00, 30, '2026-04-28 10:00:00', '2026-04-28 10:30:00', '2026-04-27 08:13:33', 0, 0, NULL),
(40, 89, 2, 'Kiff Bautista', 6, 2, '[9]', 98.00, 60, '2026-04-28 10:00:00', '2026-04-28 11:00:00', '2026-04-27 08:13:33', 0, 0, NULL),
(41, 90, 1, 'Kiff Bautista', 4, 2, '[]', 199.00, 30, '2026-04-28 11:00:00', '2026-04-28 11:30:00', '2026-04-27 08:24:50', 0, 0, NULL),
(42, 91, 1, 'Jericho Castillo', 4, 6, '[7]', 698.00, 90, '2026-05-04 15:00:00', '2026-05-04 16:30:00', '2026-05-04 06:38:22', 0, 0, NULL),
(43, 92, 1, 'Jec Castillo', 4, 6, '[]', 199.00, 30, '2026-05-13 11:30:00', '2026-05-13 12:00:00', '2026-05-12 05:45:03', 0, 0, NULL),
(44, 92, 2, 'Jec Castillo', 4, 2, '[]', 199.00, 30, '2026-05-13 11:30:00', '2026-05-13 12:00:00', '2026-05-12 05:45:03', 0, 0, NULL),
(45, 93, 1, 'Gio Asi', 4, 2, '[7]', 698.00, 90, '2026-05-16 11:30:00', '2026-05-16 13:00:00', '2026-05-16 03:17:19', 0, 1, NULL),
(46, 93, 2, 'Gio Asi', 4, 2, '[]', 199.00, 30, '2026-05-16 13:00:00', '2026-05-16 13:30:00', '2026-05-16 03:17:19', 0, 1, NULL),
(47, 94, 1, 'Jer Castillo', 4, 6, '[9,7]', 747.00, 120, '2026-07-09 10:00:00', '2026-07-09 12:00:00', '2026-07-09 01:52:27', 0, 0, NULL),
(48, 94, 2, 'Jer Castillo', 5, 6, '[6]', 298.00, 90, '2026-07-09 12:00:00', '2026-07-09 13:30:00', '2026-07-09 01:52:27', 0, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `appointment_services`
--

CREATE TABLE `appointment_services` (
  `id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `downpayment` decimal(10,2) NOT NULL DEFAULT 0.00,
  `duration_minutes` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointment_services`
--

INSERT INTO `appointment_services` (`id`, `appointment_id`, `service_id`, `price`, `downpayment`, `duration_minutes`) VALUES
(15, 21, 4, 199.00, 50.00, 30),
(17, 23, 6, 49.00, 20.00, 30),
(19, 25, 9, 49.00, 20.00, 30),
(22, 29, 4, 199.00, 29.85, 30),
(23, 30, 7, 499.00, 74.85, 60),
(27, 32, 7, 499.00, 74.85, 60),
(28, 33, 6, 49.00, 7.35, 30),
(29, 33, 7, 499.00, 74.85, 60),
(30, 33, 9, 49.00, 7.35, 30),
(31, 33, 10, 499.00, 74.85, 60),
(32, 34, 4, 199.00, 29.85, 30),
(33, 34, 6, 49.00, 7.35, 30),
(34, 35, 4, 199.00, 29.85, 30),
(35, 35, 7, 499.00, 74.85, 60),
(36, 36, 4, 199.00, 29.85, 30),
(37, 36, 6, 49.00, 7.35, 30),
(38, 37, 4, 199.00, 29.85, 30),
(39, 37, 6, 49.00, 7.35, 30),
(40, 37, 7, 499.00, 74.85, 60),
(41, 39, 5, 249.00, 37.35, 60),
(42, 39, 6, 49.00, 7.35, 30),
(43, 40, 9, 49.00, 7.35, 30),
(44, 41, 4, 199.00, 29.85, 30),
(45, 42, 5, 249.00, 37.35, 60),
(46, 42, 6, 49.00, 7.35, 30),
(47, 43, 4, 199.00, 29.85, 30),
(48, 43, 6, 49.00, 7.35, 30),
(49, 44, 4, 199.00, 29.85, 30),
(50, 44, 6, 49.00, 7.35, 30),
(51, 45, 4, 199.00, 29.85, 30),
(52, 45, 6, 49.00, 7.35, 30),
(53, 45, 7, 499.00, 74.85, 60),
(54, 45, 9, 49.00, 7.35, 30),
(55, 46, 4, 199.00, 29.85, 30),
(56, 46, 6, 49.00, 7.35, 30),
(57, 47, 4, 199.00, 29.85, 30),
(58, 47, 9, 49.00, 7.35, 30),
(59, 48, 4, 199.00, 29.85, 30),
(60, 48, 6, 49.00, 7.35, 30),
(61, 49, 6, 49.00, 7.35, 30),
(62, 49, 8, 499.00, 74.85, 120),
(63, 50, 4, 199.00, 29.85, 30),
(64, 51, 4, 199.00, 29.85, 30),
(65, 52, 4, 199.00, 29.85, 30),
(66, 52, 6, 49.00, 7.35, 30),
(67, 53, 4, 199.00, 29.85, 30),
(68, 53, 6, 49.00, 7.35, 30),
(69, 54, 4, 199.00, 29.85, 30),
(70, 54, 7, 499.00, 74.85, 60),
(71, 55, 4, 199.00, 29.85, 30),
(72, 55, 6, 49.00, 7.35, 30),
(73, 55, 9, 49.00, 7.35, 30),
(74, 55, 7, 499.00, 74.85, 60),
(75, 56, 4, 199.00, 29.85, 30),
(76, 56, 6, 49.00, 7.35, 30),
(77, 57, 4, 199.00, 29.85, 30),
(78, 57, 6, 49.00, 7.35, 30),
(79, 57, 9, 49.00, 0.00, 30),
(80, 58, 4, 199.00, 29.85, 30),
(81, 58, 6, 49.00, 7.35, 30),
(82, 59, 8, 499.00, 74.85, 120),
(83, 59, 9, 49.00, 7.35, 30),
(84, 60, 4, 199.00, 29.85, 30),
(85, 60, 9, 49.00, 7.35, 30),
(86, 61, 4, 199.00, 29.85, 30),
(89, 63, 10, 499.00, 74.85, 60),
(90, 63, 6, 49.00, 7.35, 30),
(96, 67, 4, 199.00, 29.85, 30),
(97, 67, 9, 49.00, 7.35, 30),
(98, 68, 4, 199.00, 29.85, 30),
(104, 72, 4, 199.00, 29.85, 30),
(105, 72, 9, 49.00, 7.35, 30),
(106, 73, 5, 249.00, 37.35, 60),
(107, 73, 6, 49.00, 7.35, 30),
(110, 75, 10, 499.00, 74.85, 60),
(111, 76, 4, 199.00, 29.85, 30),
(112, 76, 5, 249.00, 0.00, 60),
(113, 75, 9, 49.00, 0.00, 30),
(114, 77, 4, 199.00, 29.85, 30),
(115, 77, 9, 49.00, 7.35, 30),
(116, 78, 4, 199.00, 29.85, 30),
(117, 79, 4, 199.00, 29.85, 30),
(118, 80, 4, 199.00, 29.85, 30),
(119, 81, 4, 199.00, 29.85, 30),
(120, 81, 9, 49.00, 7.35, 30),
(121, 82, 4, 199.00, 29.85, 30),
(122, 82, 7, 499.00, 74.85, 60),
(124, 83, 4, 199.00, 29.85, 30),
(125, 83, 6, 49.00, 7.35, 30),
(126, 83, 9, 49.00, 7.35, 30),
(127, 83, 9, 49.00, 0.00, 30),
(128, 84, 4, 199.00, 29.85, 30),
(129, 84, 9, 49.00, 7.35, 30),
(130, 84, 9, 49.00, 0.00, 30),
(131, 85, 4, 199.00, 29.85, 30),
(132, 86, 10, 499.00, 74.85, 60),
(133, 86, 6, 49.00, 7.35, 30),
(134, 86, 4, 199.00, 29.85, 30),
(135, 87, 10, 499.00, 74.85, 60),
(136, 87, 6, 49.00, 7.35, 30),
(137, 87, 4, 199.00, 29.85, 30),
(138, 87, 8, 499.00, 74.85, 120),
(139, 87, 7, 499.00, 74.85, 60),
(140, 88, 4, 199.00, 29.85, 30),
(141, 88, 9, 49.00, 7.35, 30),
(142, 88, 10, 499.00, 0.00, 60),
(143, 88, 9, 49.00, 0.00, 30),
(144, 89, 6, 49.00, 7.35, 30),
(145, 89, 9, 49.00, 7.35, 30),
(146, 90, 4, 199.00, 29.85, 30),
(147, 91, 4, 199.00, 29.85, 30),
(148, 91, 7, 499.00, 74.85, 60),
(149, 92, 4, 199.00, 29.85, 30),
(150, 93, 4, 199.00, 29.85, 30),
(151, 93, 7, 499.00, 74.85, 60),
(152, 94, 4, 199.00, 29.85, 30),
(153, 94, 9, 49.00, 7.35, 30),
(154, 94, 7, 499.00, 74.85, 60),
(155, 94, 5, 249.00, 37.35, 60),
(156, 94, 6, 49.00, 7.35, 30);

-- --------------------------------------------------------

--
-- Table structure for table `barbers`
--

CREATE TABLE `barbers` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expiry` datetime DEFAULT NULL,
  `image_path` varchar(255) NOT NULL,
  `title` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `barbers`
--

INSERT INTO `barbers` (`id`, `name`, `email`, `password`, `status`, `reset_token`, `reset_expiry`, `image_path`, `title`) VALUES
(1, 'Maxell', 'maxell@fadestreet.com', '$2y$10$K8xTKEHvhIz5HTKU27AsDeqSJmIlDwy5omz0wLoTnrUmlmnXuJb8y', 'active', NULL, NULL, 'maxell.png', 'Senior Barber'),
(2, 'Jonathan', 'jonathan@fadestreet.com', '$2y$10$vEnmvTWDRnNuuvVUTVewnOV5rLbrHuaQlUQzGfmqtNCTNBP7u.N7q', 'active', NULL, NULL, 'jonathan.png', 'Barber'),
(3, 'Joshua', 'joshua@fadestreet.com', '$2y$10$vEnmvTWDRnNuuvVUTVewnOV5rLbrHuaQlUQzGfmqtNCTNBP7u.N7q', 'active', NULL, NULL, 'joshua.png', 'Barber'),
(4, 'Paul', 'paul@fadestreet.com', '$2y$10$vEnmvTWDRnNuuvVUTVewnOV5rLbrHuaQlUQzGfmqtNCTNBP7u.N7q', 'active', NULL, NULL, 'barber_1777255693_9277.png', 'Barber'),
(5, 'Kyle', 'kyle@fadestreet.com', '$2y$10$vEnmvTWDRnNuuvVUTVewnOV5rLbrHuaQlUQzGfmqtNCTNBP7u.N7q', 'active', NULL, NULL, 'kyle.png', 'Barber'),
(6, 'Carl', 'carl@fadestreet.com', '$2y$10$vEnmvTWDRnNuuvVUTVewnOV5rLbrHuaQlUQzGfmqtNCTNBP7u.N7q', 'active', NULL, NULL, 'carl.png', 'Barber'),
(9, 'Jericho', 'itsmejericho0@gmail.com', '$2y$10$DwPGJZNcBdm4j7AluIMgOOrD1xSRxFkMx8k5uCHWn0GwJK8AOJYnS', 'active', '601193e138ca23ddb0ce5eca552f50f729bc1dfab74f84218850be21dc982ac6', '2026-04-06 09:40:47', 'barber_1778895840_3650.png', 'Barber');

-- --------------------------------------------------------

--
-- Table structure for table `barber_leaves`
--

CREATE TABLE `barber_leaves` (
  `id` int(11) NOT NULL,
  `barber_id` int(11) NOT NULL,
  `leave_date` date NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `barber_leaves`
--

INSERT INTO `barber_leaves` (`id`, `barber_id`, `leave_date`, `reason`, `status`, `rejection_reason`, `reviewed_at`, `reviewed_by`, `created_at`) VALUES
(1, 1, '2026-03-28', 'Vacation', 'approved', NULL, NULL, NULL, '2026-03-28 02:28:29'),
(2, 1, '2026-03-29', 'Vacation', 'approved', NULL, NULL, NULL, '2026-03-28 02:28:29'),
(3, 1, '2026-03-30', 'Vacation', 'rejected', NULL, NULL, NULL, '2026-03-28 02:28:29'),
(4, 1, '2026-03-31', 'check up', 'rejected', 'no', NULL, NULL, '2026-03-29 03:17:28'),
(5, 9, '2026-03-31', 'vacation', 'approved', NULL, NULL, NULL, '2026-03-30 00:58:13'),
(6, 9, '2026-03-30', 'vacation', 'approved', NULL, NULL, NULL, '2026-03-30 01:04:14'),
(7, 9, '2026-04-01', 'vacation', 'rejected', 'no', NULL, NULL, '2026-03-30 01:05:36'),
(8, 9, '2026-04-02', 'vacation', 'approved', NULL, NULL, NULL, '2026-03-30 01:05:36'),
(9, 9, '2026-04-01', 'vacation', 'approved', NULL, NULL, NULL, '2026-03-30 01:05:58'),
(10, 1, '2026-04-06', 'hospital', 'approved', NULL, NULL, NULL, '2026-04-05 09:21:43'),
(11, 1, '2026-04-09', 'sick', 'approved', NULL, NULL, NULL, '2026-04-09 05:46:36'),
(12, 6, '2026-04-10', 'sick', 'approved', NULL, NULL, NULL, '2026-04-09 06:07:08'),
(13, 6, '2026-04-09', 'VACATION', 'approved', NULL, NULL, NULL, '2026-04-09 06:28:47'),
(14, 5, '2026-05-12', 'Sick', 'approved', NULL, NULL, NULL, '2026-05-12 06:54:50'),
(15, 6, '2026-05-12', 'Vacation', 'pending', NULL, NULL, NULL, '2026-05-12 06:56:53'),
(16, 5, '2026-05-16', 'vacation', 'approved', NULL, NULL, NULL, '2026-05-16 03:20:48'),
(17, 2, '2026-05-16', 'sick', 'approved', NULL, NULL, NULL, '2026-05-16 03:25:49');

-- --------------------------------------------------------

--
-- Table structure for table `barber_ratings`
--

CREATE TABLE `barber_ratings` (
  `id` int(11) NOT NULL,
  `barber_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `person_no` int(11) NOT NULL DEFAULT 0,
  `rating` int(11) NOT NULL CHECK (`rating` between 1 and 5),
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `barber_ratings`
--

INSERT INTO `barber_ratings` (`id`, `barber_id`, `customer_id`, `appointment_id`, `person_no`, `rating`, `comment`, `created_at`) VALUES
(2, 2, 4, 25, 0, 5, 'Very Good!', '2025-10-20 09:52:41'),
(3, 2, 4, 30, 0, 5, 'ayos naman', '2025-10-21 06:04:20'),
(4, 1, 4, 29, 0, 5, '', '2025-10-23 05:25:15'),
(5, 1, 6, 47, 0, 4, 'ok din', '2026-03-12 23:43:32'),
(6, 2, 6, 57, 0, 5, 'good barber', '2026-03-24 05:22:27'),
(7, 1, 6, 76, 0, 5, '', '2026-04-06 04:34:21'),
(8, 2, 14, 79, 0, 5, 'Good barber', '2026-04-09 08:01:51'),
(9, 3, 14, 79, 2, 5, 'skilled!', '2026-04-09 08:18:01'),
(10, 2, 14, 79, 1, 5, 'Nice!', '2026-04-09 08:18:14'),
(11, 1, 14, 60, 0, 5, 'Good Barber!', '2026-04-09 08:18:46'),
(12, 2, 6, 83, 1, 5, '', '2026-04-27 08:33:53'),
(13, 5, 6, 83, 2, 5, '', '2026-04-27 08:33:58');

-- --------------------------------------------------------

--
-- Table structure for table `barber_schedules`
--

CREATE TABLE `barber_schedules` (
  `id` int(11) NOT NULL,
  `barber_id` int(11) NOT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `barber_schedules`
--

INSERT INTO `barber_schedules` (`id`, `barber_id`, `day_of_week`, `start_time`, `end_time`) VALUES
(1, 1, 'Monday', '10:00:00', '22:00:00'),
(2, 2, 'Monday', '10:00:00', '22:00:00'),
(3, 3, 'Monday', '10:00:00', '22:00:00'),
(4, 4, 'Monday', '10:00:00', '22:00:00'),
(5, 5, 'Monday', '10:00:00', '22:00:00'),
(7, 1, 'Tuesday', '10:00:00', '22:00:00'),
(8, 2, 'Tuesday', '10:00:00', '22:00:00'),
(9, 3, 'Tuesday', '10:00:00', '22:00:00'),
(10, 4, 'Tuesday', '10:00:00', '22:00:00'),
(11, 5, 'Tuesday', '10:00:00', '22:00:00'),
(12, 6, 'Tuesday', '10:00:00', '22:00:00'),
(13, 1, 'Wednesday', '10:00:00', '22:00:00'),
(14, 2, 'Wednesday', '10:00:00', '22:00:00'),
(15, 3, 'Wednesday', '10:00:00', '22:00:00'),
(16, 4, 'Wednesday', '10:00:00', '22:00:00'),
(17, 5, 'Wednesday', '10:00:00', '22:00:00'),
(18, 6, 'Wednesday', '10:00:00', '22:00:00'),
(19, 1, 'Thursday', '10:00:00', '22:00:00'),
(20, 2, 'Thursday', '10:00:00', '22:00:00'),
(21, 3, 'Thursday', '10:00:00', '22:00:00'),
(22, 4, 'Thursday', '10:00:00', '22:00:00'),
(23, 5, 'Thursday', '10:00:00', '22:00:00'),
(24, 6, 'Thursday', '10:00:00', '22:00:00'),
(25, 1, 'Friday', '10:00:00', '22:00:00'),
(26, 2, 'Friday', '10:00:00', '22:00:00'),
(27, 3, 'Friday', '10:00:00', '22:00:00'),
(28, 4, 'Friday', '10:00:00', '22:00:00'),
(29, 5, 'Friday', '10:00:00', '22:00:00'),
(30, 6, 'Friday', '10:00:00', '22:00:00'),
(31, 1, 'Saturday', '10:00:00', '22:00:00'),
(32, 2, 'Saturday', '10:00:00', '22:00:00'),
(33, 3, 'Saturday', '10:00:00', '22:00:00'),
(34, 4, 'Saturday', '10:00:00', '22:00:00'),
(35, 5, 'Saturday', '10:00:00', '22:00:00'),
(36, 6, 'Saturday', '10:00:00', '22:00:00'),
(37, 1, 'Sunday', '10:00:00', '22:00:00'),
(38, 2, 'Sunday', '10:00:00', '22:00:00'),
(39, 3, 'Sunday', '10:00:00', '22:00:00'),
(40, 4, 'Sunday', '10:00:00', '22:00:00'),
(41, 5, 'Sunday', '10:00:00', '22:00:00'),
(42, 6, 'Sunday', '10:00:00', '22:00:00'),
(43, 6, 'Monday', '10:00:00', '22:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `hairstyles`
--

CREATE TABLE `hairstyles` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `width_scale` decimal(5,2) NOT NULL DEFAULT 2.50,
  `height_scale` decimal(5,2) NOT NULL DEFAULT 1.00,
  `offset_x` int(11) NOT NULL DEFAULT 0,
  `offset_y` int(11) NOT NULL DEFAULT -60,
  `anchor_y` decimal(5,2) NOT NULL DEFAULT 0.24,
  `rotation_offset` decimal(5,2) NOT NULL DEFAULT 0.00,
  `flip_x` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `hairstyles`
--

INSERT INTO `hairstyles` (`id`, `name`, `image_path`, `width_scale`, `height_scale`, `offset_x`, `offset_y`, `anchor_y`, `rotation_offset`, `flip_x`, `is_active`, `created_at`) VALUES
(4, 'Modern Haircut', 'uploads/hairstyles/hair_69d075fd15dc92.80107088.png', 5.80, 1.80, -10, -80, 0.24, 0.00, 0, 1, '2026-04-04 02:22:53'),
(8, 'Sleek Back Fade', 'uploads/hairstyles/hair_69d0782022da66.12322942.png', 6.20, 2.20, 0, -100, 0.24, 0.00, 0, 1, '2026-04-04 02:32:00'),
(10, 'Caesar Cut with Textured Fringe', 'uploads/hairstyles/hair_69d081d896e844.57909018.png', 6.20, 2.00, 0, -120, 0.24, 0.00, 0, 1, '2026-04-04 03:13:28'),
(11, 'Textured Crop', 'uploads/hairstyles/hair_69d12c7d8b1d93.71648708.png', 6.00, 2.00, 0, -120, 0.24, 0.00, 0, 1, '2026-04-04 15:21:33'),
(13, 'Pompadour', 'uploads/hairstyles/hair_69d4579d5aac89.18786772.png', 7.70, 1.60, 0, -100, 0.24, 0.00, 0, 1, '2026-04-07 01:02:21'),
(18, 'Textured Fade', 'uploads/hairstyles/hair_69fe9092b3b288.22487255.png', 4.52, 1.55, 0, -97, 0.24, 0.00, 0, 1, '2026-05-09 01:40:34'),
(19, 'Mohawk', 'uploads/hairstyles/hair_69fe91dcb601a7.97886787.png', 5.18, 1.65, 0, -142, 0.24, 0.00, 0, 1, '2026-05-09 01:46:04');

-- --------------------------------------------------------

--
-- Table structure for table `hairstyle_face_shapes`
--

CREATE TABLE `hairstyle_face_shapes` (
  `id` int(11) NOT NULL,
  `hairstyle_id` int(11) NOT NULL,
  `face_shape` enum('oval','round','square','heart','oblong') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `hairstyle_face_shapes`
--

INSERT INTO `hairstyle_face_shapes` (`id`, `hairstyle_id`, `face_shape`, `created_at`) VALUES
(5, 4, 'oval', '2026-04-07 00:14:33'),
(6, 4, 'round', '2026-04-07 00:14:33'),
(7, 4, 'square', '2026-04-07 00:14:33'),
(11, 11, 'oval', '2026-04-07 00:52:45'),
(12, 11, 'round', '2026-04-07 00:52:45'),
(15, 10, 'oval', '2026-04-07 00:54:01'),
(16, 10, 'square', '2026-04-07 00:54:01'),
(17, 10, 'heart', '2026-04-07 00:54:01'),
(18, 8, 'oval', '2026-04-07 00:54:15'),
(19, 8, 'round', '2026-04-07 00:54:15'),
(20, 8, 'heart', '2026-04-07 00:54:15'),
(33, 13, 'round', '2026-04-07 01:04:31'),
(34, 13, 'heart', '2026-04-07 01:04:31'),
(35, 13, 'oblong', '2026-04-07 01:04:31'),
(68, 18, 'oval', '2026-05-09 01:42:33'),
(69, 18, 'round', '2026-05-09 01:42:33'),
(70, 18, 'square', '2026-05-09 01:42:33'),
(71, 18, 'heart', '2026-05-09 01:42:33'),
(96, 19, 'oval', '2026-05-09 01:48:59'),
(97, 19, 'round', '2026-05-09 01:48:59'),
(98, 19, 'square', '2026-05-09 01:48:59');

-- --------------------------------------------------------

--
-- Table structure for table `optional_services`
--

CREATE TABLE `optional_services` (
  `id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `optional_services`
--

INSERT INTO `optional_services` (`id`, `service_id`) VALUES
(1, 6),
(2, 7),
(3, 9);

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `downpayment_amount` decimal(10,2) NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `balance_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `method` enum('Bank Transfer') DEFAULT 'Bank Transfer',
  `reference_number` varchar(100) NOT NULL,
  `proof_image` varchar(255) DEFAULT NULL,
  `status` enum('pending','verified','rejected') DEFAULT 'pending',
  `is_repayment` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `appointment_id`, `total_amount`, `downpayment_amount`, `amount_paid`, `balance_amount`, `method`, `reference_number`, `proof_image`, `status`, `is_repayment`, `created_at`) VALUES
(1, 3, 797.00, 119.55, 0.00, 0.00, 'Bank Transfer', 'qweqewr', NULL, 'pending', 0, '2025-09-23 15:11:37'),
(2, 4, 797.00, 119.55, 0.00, 0.00, 'Bank Transfer', 'qweqw', NULL, 'pending', 0, '2025-09-23 15:41:45'),
(3, 5, 698.00, 104.70, 0.00, 0.00, 'Bank Transfer', '1237343291', NULL, 'pending', 0, '2025-09-25 07:38:14'),
(11, 32, 499.00, 74.85, 0.00, 0.00, 'Bank Transfer', 'asdkasnda', NULL, 'pending', 0, '2025-10-06 08:37:10'),
(12, 33, 1096.00, 164.40, 164.40, 931.60, 'Bank Transfer', '342342', NULL, 'verified', 0, '2025-10-06 08:45:10'),
(13, 34, 248.00, 37.20, 0.00, 0.00, 'Bank Transfer', 'qweqweq', NULL, 'pending', 0, '2025-10-14 12:17:47'),
(14, 35, 698.00, 104.70, 0.00, 0.00, 'Bank Transfer', '1237343291', NULL, 'pending', 0, '2025-10-21 06:03:58'),
(15, 36, 248.00, 37.20, 0.00, 0.00, 'Bank Transfer', '3453535353433', NULL, 'pending', 0, '2025-10-23 02:49:29'),
(16, 37, 747.00, 112.05, 112.05, 634.95, 'Bank Transfer', '56564646587', NULL, 'verified', 0, '2025-10-23 05:23:40'),
(17, 39, 298.00, 44.70, 0.00, 0.00, 'Bank Transfer', '324532453', NULL, 'pending', 0, '2025-10-29 03:38:40'),
(18, 39, 298.00, 44.70, 0.00, 0.00, 'Bank Transfer', '123ewrw1231', NULL, 'verified', 1, '2025-10-29 03:41:10'),
(19, 40, 98.00, 14.70, 98.00, 0.00, 'Bank Transfer', '23424242', NULL, 'verified', 0, '2025-12-04 04:03:05'),
(20, 41, 199.00, 29.85, 0.00, 0.00, 'Bank Transfer', '1237343291', NULL, 'pending', 0, '2026-03-07 12:34:46'),
(21, 39, 298.00, 44.70, 44.70, 253.30, 'Bank Transfer', '234245252342', 'repay_39_1772964824.png', 'verified', 1, '2026-03-08 10:13:44'),
(22, 42, 298.00, 44.70, 0.00, 0.00, 'Bank Transfer', '234234252', NULL, 'rejected', 0, '2026-03-09 04:26:38'),
(23, 42, 298.00, 44.70, 200.00, 98.00, 'Bank Transfer', '25425252', 'repay_42_1773030539.png', 'verified', 1, '2026-03-09 04:28:59'),
(24, 43, 248.00, 37.20, 0.00, 0.00, 'Bank Transfer', '34563432rtfw2', 'payment_1773031170_4.png', 'rejected', 0, '2026-03-09 04:39:30'),
(25, 44, 248.00, 37.20, 0.00, 0.00, 'Bank Transfer', '89', 'payment_1773192861_4.png', '', 0, '2026-03-11 01:34:21'),
(26, 45, 796.00, 119.40, 0.00, 0.00, 'Bank Transfer', '123', 'payment_1773194493_4.png', '', 0, '2026-03-11 02:01:33'),
(27, 46, 248.00, 37.20, 100.00, 148.00, 'Bank Transfer', '1237343291', 'payment_1773194793_4.png', 'verified', 0, '2026-03-11 02:06:33'),
(28, 47, 248.00, 37.20, 0.00, 0.00, 'Bank Transfer', 'y45y5yerye', 'payment_1773358971_6.jpg', '', 0, '2026-03-12 23:42:51'),
(29, 48, 248.00, 37.20, 100.00, 148.00, 'Bank Transfer', 'o[7ot7t7uk', 'payment_1773359044_6.jpg', 'verified', 0, '2026-03-12 23:44:04'),
(30, 49, 548.00, 82.20, 43.00, 505.00, 'Bank Transfer', 'qe212314', 'payment_1773360793_6.jpg', 'verified', 0, '2026-03-13 00:13:13'),
(31, 50, 199.00, 29.85, 0.00, 0.00, 'Bank Transfer', '323234234232', 'payment_1773486823_6.jpg', '', 0, '2026-03-14 11:13:43'),
(32, 51, 199.00, 29.85, 0.00, 0.00, 'Bank Transfer', '23423424', 'payment_1773487328_6.jpg', '', 0, '2026-03-14 11:22:08'),
(33, 52, 248.00, 37.20, 0.00, 0.00, 'Bank Transfer', '121', 'payment_1773534523_6.jpg', 'rejected', 0, '2026-03-15 00:28:43'),
(34, 52, 248.00, 37.20, 200.00, 48.00, 'Bank Transfer', '123', 'repay_52_1773534583.jpg', 'verified', 1, '2026-03-15 00:29:43'),
(35, 53, 248.00, 37.20, 0.00, 0.00, 'Bank Transfer', '42342323432', 'payment_1773714217_14.png', 'rejected', 0, '2026-03-17 02:23:37'),
(36, 53, 248.00, 37.20, 30.00, 218.00, 'Bank Transfer', 'werwerw2141', 'repay_53_1773714523.png', 'verified', 1, '2026-03-17 02:28:43'),
(37, 54, 698.00, 104.70, 300.00, 398.00, 'Bank Transfer', 'b', 'payment_1773794619_14.png', 'verified', 0, '2026-03-18 00:43:39'),
(38, 55, 796.00, 119.40, 119.40, 676.60, 'Bank Transfer', '123454', 'payment_1773806163_14.png', 'verified', 0, '2026-03-18 03:56:03'),
(39, 56, 248.00, 37.20, 37.20, 210.80, 'Bank Transfer', '12345', 'payment_1773806443_14.png', 'verified', 0, '2026-03-18 04:00:43'),
(40, 57, 248.00, 37.20, 37.20, 210.80, 'Bank Transfer', '121221121', 'payment_1774065371_6.png', 'verified', 0, '2026-03-21 03:56:11'),
(41, 57, 297.00, 37.20, 37.20, 259.80, 'Bank Transfer', 'INSHOP-57-1774066624', NULL, 'verified', 1, '2026-03-21 04:17:04'),
(42, 58, 248.00, 37.20, 37.20, 210.80, 'Bank Transfer', '2313121', 'payment_1774068197_6.jpg', 'verified', 0, '2026-03-21 04:43:17'),
(43, 59, 548.00, 82.20, 82.20, 465.80, 'Bank Transfer', '321132132', 'payment_1774069873_6.jpg', 'verified', 0, '2026-03-21 05:11:14'),
(44, 60, 248.00, 37.20, 37.20, 210.80, '', '123456', 'payment_1774244706_14.jpg', 'verified', 0, '2026-03-23 05:45:06'),
(45, 61, 199.00, 29.85, 29.85, 169.15, '', '1232456254', 'payment_1774324187_6.jpg', 'verified', 0, '2026-03-24 03:49:47'),
(47, 63, 548.00, 82.20, 0.00, 0.00, '', '1231313', 'payment_1774402559_6.jpg', 'rejected', 0, '2026-03-25 01:35:59'),
(51, 67, 248.00, 37.20, 37.20, 210.80, '', '1231211', 'payment_1774411512_6.jpg', 'verified', 0, '2026-03-25 04:05:12'),
(52, 68, 199.00, 29.85, 29.85, 169.15, '', '1231231', 'payment_1774411661_6.jpg', 'verified', 0, '2026-03-25 04:07:41'),
(56, 72, 248.00, 37.20, 37.20, 210.80, '', '453453453534', 'payment_1774572958_6.jpg', 'verified', 0, '2026-03-27 00:55:58'),
(57, 73, 298.00, 44.70, 44.70, 253.30, '', '453453453534', 'payment_1774572958_6.jpg', 'verified', 0, '2026-03-27 00:55:58'),
(59, 75, 499.00, 74.85, 74.85, 424.15, '', '12345', 'payment_1774664759_6.jpg', 'verified', 0, '2026-03-28 02:25:59'),
(60, 76, 199.00, 29.85, 29.85, 169.15, '', '23423424', 'payment_1774665670_6.jpg', 'verified', 0, '2026-03-28 02:41:10'),
(61, 76, 448.00, 29.85, 29.85, 418.15, '', 'INSHOP-76-1774925120', NULL, 'verified', 1, '2026-03-31 02:45:20'),
(62, 75, 548.00, 74.85, 74.85, 473.15, '', 'INSHOP-75-1774925171', NULL, 'verified', 1, '2026-03-31 02:46:11'),
(63, 77, 248.00, 37.20, 37.20, 210.80, '', '12345', 'payment_1775380845_6.png', 'verified', 0, '2026-04-05 09:20:45'),
(64, 78, 398.00, 59.70, 100.00, 298.00, '', '3424235252', 'payment_1775449602_6.png', 'verified', 0, '2026-04-06 04:26:42'),
(65, 79, 398.00, 59.70, 59.70, 338.30, '', '023482342', 'payment_1775707293_14.png', 'verified', 0, '2026-04-09 04:01:33'),
(66, 80, 597.00, 89.55, 89.55, 507.45, '', '23423424', 'payment_1775710026_6.png', 'verified', 0, '2026-04-09 04:47:06'),
(67, 81, 447.00, 67.05, 67.05, 379.95, '', '234242342', 'payment_1775713475_6.png', 'verified', 0, '2026-04-09 05:44:35'),
(68, 82, 897.00, 134.55, 134.55, 762.45, '', '2342342432', 'payment_1775714722_6.png', 'verified', 0, '2026-04-09 06:05:22'),
(69, 77, 248.00, 37.20, 248.00, 0.00, '', 'INSHOP-77-1775724791', NULL, 'verified', 1, '2026-04-09 08:53:11'),
(70, 83, 496.00, 74.40, 74.40, 421.60, '', '324234232', 'payment_1775725522_6.png', 'verified', 0, '2026-04-09 09:05:22'),
(71, 83, 545.00, 74.40, 545.00, 0.00, '', 'COMPLETE-83-1775725648', NULL, 'verified', 1, '2026-04-09 09:07:28'),
(72, 84, 447.00, 67.05, 70.00, 377.00, '', '5423243266', 'payment_1775778054_14.png', 'verified', 0, '2026-04-09 23:40:54'),
(73, 84, 496.00, 67.05, 496.00, 0.00, '', 'COMPLETE-84-1775778270', NULL, 'verified', 1, '2026-04-09 23:44:30'),
(74, 85, 398.00, 59.70, 59.70, 338.30, '', '245434464', 'payment_1775780144_14.png', 'rejected', 0, '2026-04-10 00:15:44'),
(75, 86, 796.00, 119.40, 119.40, 676.60, '', '1234e4', 'payment_1775780780_14.png', 'verified', 0, '2026-04-10 00:26:20'),
(76, 86, 796.00, 119.40, 796.00, 0.00, '', 'COMPLETE-86-1775782205', NULL, 'verified', 1, '2026-04-10 00:50:05'),
(77, 73, 298.00, 44.70, 298.00, 0.00, '', 'COMPLETE-73-1775782248', NULL, 'verified', 1, '2026-04-10 00:50:48'),
(78, 87, 1745.00, 261.75, 261.75, 1483.25, '', '12345', 'payment_1775784347_14.png', 'verified', 0, '2026-04-10 01:25:47'),
(79, 87, 1745.00, 261.75, 1745.00, 0.00, '', 'COMPLETE-87-1775785498', NULL, 'verified', 1, '2026-04-10 01:44:58'),
(80, 88, 447.00, 67.05, 67.05, 379.95, '', '32423423', 'payment_1775791246_14.png', 'verified', 0, '2026-04-10 03:20:46'),
(81, 88, 995.00, 67.05, 995.00, 0.00, '', 'COMPLETE-88-1775791435', NULL, 'verified', 1, '2026-04-10 03:23:55'),
(82, 89, 147.00, 22.05, 22.05, 124.95, '', '342342423', 'payment_1777277613_14.png', 'verified', 0, '2026-04-27 08:13:33'),
(83, 90, 199.00, 29.85, 29.85, 169.15, '', '3534534', 'payment_1777278290_14.png', 'verified', 0, '2026-04-27 08:24:50'),
(84, 90, 199.00, 29.85, 199.00, 0.00, '', 'COMPLETE-90-1777278326', NULL, 'verified', 1, '2026-04-27 08:25:26'),
(85, 89, 147.00, 22.05, 147.00, 0.00, '', 'COMPLETE-89-1777278337', NULL, 'verified', 1, '2026-04-27 08:25:37'),
(86, 91, 698.00, 104.70, 300.00, 398.00, '', '7565464', 'payment_1777876702_17.webp', 'verified', 0, '2026-05-04 06:38:22'),
(87, 92, 398.00, 59.70, 59.70, 338.30, '', '4242342342', 'payment_1778564703_6.jpg', 'verified', 0, '2026-05-12 05:45:03'),
(88, 93, 897.00, 134.55, 140.00, 757.00, '', '1234345678', 'payment_1778901439_24.jpg', 'verified', 0, '2026-05-16 03:17:19'),
(89, 94, 1045.00, 156.75, 160.00, 885.00, '', '2342542542', 'payment_1783561947_20.jpg', 'verified', 0, '2026-07-09 01:52:27');

-- --------------------------------------------------------

--
-- Table structure for table `payment_settings`
--

CREATE TABLE `payment_settings` (
  `id` int(11) NOT NULL,
  `gcash_number` varchar(20) NOT NULL,
  `gcash_qr` varchar(255) NOT NULL,
  `gcash_name` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_settings`
--

INSERT INTO `payment_settings` (`id`, `gcash_number`, `gcash_qr`, `gcash_name`) VALUES
(1, '0912******6', 'gcash_qr_1775439319_2009.png', 'Jer**** C.');

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rating` int(11) NOT NULL CHECK (`rating` between 1 and 5),
  `comment` text NOT NULL,
  `owner_reply` text DEFAULT NULL,
  `owner_reply_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `photo` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reviews`
--

INSERT INTO `reviews` (`id`, `user_id`, `rating`, `comment`, `owner_reply`, `owner_reply_at`, `created_at`, `photo`) VALUES
(1, 1, 5, 'Amazing experience!', NULL, NULL, '2025-07-14 11:13:45', NULL),
(2, 2, 4, 'Good service.', NULL, NULL, '2025-07-14 11:13:45', NULL),
(3, 3, 5, 'Loved the haircut!', NULL, NULL, '2025-07-14 11:13:45', NULL),
(4, 4, 5, 'pogi ko ', NULL, NULL, '2025-07-20 16:21:14', NULL),
(5, 4, 3, 'omsiman', NULL, NULL, '2025-07-29 19:00:55', NULL),
(7, 4, 1, 'baduy', NULL, NULL, '2025-09-25 15:42:13', NULL),
(8, 4, 5, 'good!', NULL, NULL, '2025-09-30 09:58:30', NULL),
(9, 4, 5, 'anags', NULL, NULL, '2025-09-30 14:44:48', NULL),
(10, 4, 5, 'Pogi', NULL, NULL, '2025-10-05 21:43:48', NULL),
(11, 4, 5, 'asda', NULL, NULL, '2025-10-05 21:50:04', NULL),
(12, 4, 5, 'qweq', NULL, NULL, '2025-10-05 21:50:14', NULL),
(13, 4, 5, 'pogi', NULL, NULL, '2025-10-05 21:51:40', 'uploads/reviews/review_68e277ece44725.08562124.webp'),
(14, 4, 5, 'Good', NULL, NULL, '2025-10-05 22:37:35', 'uploads/reviews/review_68e282af10eca6.04043532.jpg'),
(15, 4, 5, 'hehe goods', 'Thankyou!', '2026-03-27 10:53:31', '2025-10-21 14:09:07', 'uploads/reviews/review_68f7238398d733.23069586.png'),
(16, 6, 5, 'Good experience', NULL, NULL, '2026-04-06 12:19:59', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `duration_minutes` int(11) NOT NULL DEFAULT 30
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `name`, `description`, `price`, `duration_minutes`) VALUES
(4, 'BASIC HAIRCUT', 'A grooming service that includes a customized haircut, a deep-cleansing shampoo, and professional hairstyling to give you a clean, fresh, and stylish finish.', 199.00, 30),
(5, 'HAIRCUT PACKAGE', 'Includes a personalized haircut, hot towel treatment, shampoo, and expert hairstyling for a clean, refined look.', 249.00, 60),
(6, 'BEARD TRIMMING / SHAVING', 'Precise beard shaping or a clean shave tailored to your style and comfort.', 49.00, 30),
(7, 'HAIR COLOR', 'Professional hair coloring services to refresh your look or try something new, using high-quality, lasting color.', 499.00, 60),
(8, 'PERM / TRADITIONAL PERM', 'Add long-lasting waves or curls with our classic perm service, designed to give your hair volume, texture, and style that lasts.', 499.00, 120),
(9, 'HAIR ART', 'Creative and detailed designs shaved or styled into the hair, perfect for making a bold, personal statement.', 49.00, 30),
(10, 'HAIR BLEACHING', 'Lighten your hair to the perfect shade with professional bleaching for a bold new look or a fresh base for vibrant colors.', 499.00, 60);

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `shop_name` varchar(150) NOT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `banner_image` varchar(255) DEFAULT NULL,
  `booking_policy` text DEFAULT NULL,
  `about_us` text DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `facebook` varchar(150) DEFAULT NULL,
  `est_year` varchar(10) DEFAULT NULL,
  `opening_hours` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `smtp_email` varchar(150) DEFAULT NULL,
  `smtp_password` varchar(255) DEFAULT NULL,
  `smtp_from_name` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `shop_name`, `logo`, `banner_image`, `booking_policy`, `about_us`, `address`, `phone`, `email`, `facebook`, `est_year`, `opening_hours`, `created_at`, `smtp_email`, `smtp_password`, `smtp_from_name`) VALUES
(1, 'Fade Street Hair Studio', 'logo.png', 'banner_1777254727_6792.jpg', 'We require a 15% down payment of your total bill upon booking. A no-show or arrival more than 15 minutes late will result in a voided booking with no refund.', 'By combining comfort, style, and perfection, Fade Street Hair Studio reinvents contemporary grooming. Enter a thoughtfully created area intended for rest, rejuvenation, and superior service. Every element is designed with intention, from precise cuts to careful attention to detail. Beyond just grooming, we provide a customised experience based on reliability, excellence, and trust.', '996 Mabini St. Burgos, Rodriguez Rizal', '09123456789', 'fadestreet@gmail.com', 'Fade Street Hair Studio', '2023', '10:00 AM - 10:00 PM', '2026-03-07 11:25:21', 'jericho1castillo@gmail.com', 'vwem fdgf llky mfix', 'Fade Street Hair Studio');

-- --------------------------------------------------------

--
-- Table structure for table `staff`
--

CREATE TABLE `staff` (
  `id` int(11) NOT NULL,
  `fname` varchar(50) NOT NULL,
  `mname` varchar(50) DEFAULT NULL,
  `lname` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expiry` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `staff`
--

INSERT INTO `staff` (`id`, `fname`, `mname`, `lname`, `email`, `password`, `status`, `reset_token`, `reset_expiry`, `created_at`) VALUES
(1, 'John', '', 'Dela Cruz', 'john@fadestreet.com', '$2y$10$K8xTKEHvhIz5HTKU27AsDeqSJmIlDwy5omz0wLoTnrUmlmnXuJb8y', 'active', NULL, NULL, '2026-03-07 02:54:09'),
(2, 'Mark', '', 'Santos', 'mark@fadestreet.com', '$2y$10$K8xTKEHvhIz5HTKU27AsDeqSJmIlDwy5omz0wLoTnrUmlmnXuJb8y', 'active', NULL, NULL, '2026-03-07 02:54:09');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','staff','barber') NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expiry` datetime DEFAULT NULL,
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verification_token` varchar(255) DEFAULT NULL,
  `verification_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `first_name`, `middle_name`, `last_name`, `phone`, `name`, `email`, `password`, `role`, `status`, `created_at`, `reset_token`, `reset_expiry`, `email_verified`, `verification_token`, `verification_expires`) VALUES
(1, 'John', 'O.', 'De Jesus', '', 'John D.', 'john@example.com', 'test123', 'staff', 'active', '2025-07-14 11:13:38', NULL, NULL, 1, NULL, NULL),
(2, 'Jane', 'P.', 'Salam', '', 'Jane S.', 'jane@example.com', 'test123', 'staff', 'active', '2025-07-14 11:13:38', NULL, NULL, 1, NULL, NULL),
(3, 'Mike', 'E.', 'Reyes', '', 'Mike R.', 'mike@example.com', 'test123', 'staff', 'active', '2025-07-14 11:13:38', NULL, NULL, 1, NULL, NULL),
(4, 'Jericho', 'Ocampo', 'Castillo', '09561347142', 'Jericho Antonio Ocampo Castillo', 'jericho_1213@yahoo.com', '$2y$10$NJcnCF3lsaaMfkZrvFnOJee0tEr03mZbeusFIL5oIo.PdA/4HfX8G', 'staff', 'active', '2025-07-20 16:20:31', NULL, NULL, 1, NULL, NULL),
(5, 'test', 'R', 'Testing', '09137385743', 'test R Testing', 'test45@yahoo.com', '$2y$10$9uQrDzCyAT1aEG2MIccTPOudjR9TSHNvKJxj1Fj3i4gm4esOBDrP.', 'staff', 'active', '2025-07-20 16:28:01', NULL, NULL, 1, NULL, NULL),
(6, 'Jec', 'Ocampo', 'Castillo', '09561437142', 'Jec Ocampo Castillo', 'itsmejericho0@gmail.com', '$2y$10$XgycFa8V2bbqqgWSXCCeHuYU4a5fZ/ZywJlM/ZVrdLBMXiGpoC47y', 'staff', 'active', '2025-09-29 10:07:09', 'e5772b414e710b01c65752ff7fea90be', '2026-04-27 08:50:15', 1, NULL, NULL),
(7, '1231', '231', 'castillo', '09561347142', '1231 231 castillo', 'jericho_121@yahoo.com', '$2y$10$QDnp8TpIUkBUMCipRcl2I.vjUPrBOFpvGimBxv9QaQn0CNmrkYU0i', 'staff', 'active', '2025-10-23 10:31:58', NULL, NULL, 1, NULL, NULL),
(8, 'Admin', NULL, 'User', '09123456789', 'Admin User', 'admin@barbershop.com', 'admin123', 'admin', 'active', '2025-10-29 12:16:17', NULL, NULL, 1, NULL, NULL),
(11, 'piolo', '', 'pascual', '09234521534', 'piolo  pascual', 'piolopascual@gmail.com', '$2y$10$dNszM2tDH4kpHEEMewjh0O/MUDLEAbbXx34KBi.nXZFedGqFFfSyy', 'admin', 'active', '2025-11-29 18:28:29', NULL, NULL, 1, NULL, NULL),
(12, 'joyjoy', 'kat', 'lawna', '09561347143', 'joyjoy kat lawna', 'lawan.christine@gmail.com', '$2y$10$RLu0dN..JIbsEJlo/dSLAes2b15DT34u1ciy1SaqtKO8Hsaze5mym', 'admin', 'active', '2026-01-29 11:14:00', 'bde9db59aaa24b8abca4f3a394f88e0e', '2026-01-29 04:14:13', 1, NULL, NULL),
(13, 'J', 'O', 'Cas', '09561347142', 'J O Cas', 'jericho1castillo@gmail.com', '$2y$10$00J4rU1mVCnQZ3Jqg39AE..ASaMFQXvSixV6pNNKQKb3wrVe8y0uu', 'admin', 'active', '2026-03-12 13:38:06', '795a44fdad9d108451891911ea0bca62', '2026-05-12 06:16:21', 1, NULL, NULL),
(14, 'Kiff', 'Lagutan', 'Bautista', '09494334497', 'Kiff Lagutan Bautista', 'bautistakiff@gmail.com', '$2y$10$KTTe3D4zlwFlWJ4Wbw9bPexm53VBOeBH99uMMo2VwCf9/fVM9om.e', 'admin', 'active', '2026-03-17 10:23:17', 'b27b1cf8c16b1bfeeb72874dd172b6aa', '2026-03-17 03:47:04', 1, NULL, NULL),
(15, 'jeo', 'ocampo', 'castillo', '09693210033', 'jeo ocampo castillo', 'castillojeoantonio@gmail.com', '$2y$10$Op/c4RJgUy/wxbCJ4N.wfe4smziG6RkaHriNv5bQuDYLnJ2g.T7Su', 'admin', 'active', '2026-03-20 08:31:52', NULL, NULL, 1, NULL, NULL),
(16, 'Antonio', 'Revillame', 'Santos', '09123465321', 'Antonio Revillame Santos', 'antonio@fade.com', '$2y$10$z5dSvkJNo2mkDOkxcr9we.V4TuOLip3SqvSU2GfgCu8j2kWSgM6Lm', 'admin', 'active', '2026-03-21 13:08:32', NULL, NULL, 1, NULL, NULL),
(17, 'Jericho', '', 'Castillo', '09124567891', 'Jericho  Castillo', 'jerichocastillo@gmail.com', '$2y$10$7OP3ccixuRz3aQFnGlwDIeH596Ee9e1BfxxTUPP9klednhhIutTru', 'admin', 'active', '2026-05-04 13:51:01', NULL, NULL, 1, NULL, NULL),
(20, 'Jer', '', 'Castillo', '09561347142', 'Jer  Castillo', 'castillojo@students.nu-fairview.edu.ph', '$2y$10$vQVz9vSQ1bNqmR0OQ.TFmerZt1Lf3Pn/KnNIgn4F7vZMH3Y06tRoy', 'admin', 'active', '2026-05-07 12:59:39', NULL, NULL, 1, NULL, NULL),
(21, 'Toni', '', 'Maranga', '09661808652', 'Toni  Maranga', 'mtonichie190@gmail.com', '$2y$10$RoGtqkf2HJiDcp5xEpwb.OyvhyhGTpXW9fzdVZwnSsBjCkX5qeGF.', 'admin', 'active', '2026-05-14 11:27:01', NULL, NULL, 0, '21d4425a37c478c2b1426bacc27c15a5c5b1d0c53c8f6c5f0ec574326cdf65a8', '2026-05-15 11:27:01'),
(24, 'Gio', '', 'Asi', '09999385385', 'Gio  Asi', 'gioasi14@gmail.com', '$2y$10$QJ2PKS8v9MMh.JEm8GdyGuWt/7glIFnU2h2hiiJHBSWk36oRjSbgG', 'admin', 'active', '2026-05-16 11:12:33', NULL, NULL, 1, NULL, NULL),
(25, 'Asdad', 'Asda', 'Asdasda', '09123741824', 'Asdad Asda Asdasda', 'castillojeoantonio1@gmail.com', '$2y$10$TM09ZVXmIleBKgEr8bau6uYZZv.XGOWpnuMZlffa6NvvmzChlytJS', 'admin', 'active', '2026-09-25 12:23:40', NULL, NULL, 0, 'baf7b8ac4e3dbaa5502e280bf40943338a8df87b126538b447972ff8e6670c37', '2026-09-26 12:23:40');

-- --------------------------------------------------------

--
-- Table structure for table `walkins`
--

CREATE TABLE `walkins` (
  `id` int(11) NOT NULL,
  `customer_name` varchar(100) NOT NULL,
  `service_name` varchar(100) NOT NULL,
  `barber_id` int(11) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('waiting','ongoing','completed','cancelled') DEFAULT 'waiting',
  `created_at` datetime DEFAULT current_timestamp(),
  `started_at` datetime DEFAULT NULL,
  `estimated_end_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `walkins`
--

INSERT INTO `walkins` (`id`, `customer_name`, `service_name`, `barber_id`, `total_amount`, `status`, `created_at`, `started_at`, `estimated_end_at`, `completed_at`) VALUES
(1, 'jericho', 'BASIC HAIRCUT', NULL, 199.00, 'cancelled', '2026-03-09 13:23:12', NULL, NULL, NULL),
(2, 'kiff', 'BEARD TRIMMING / SHAVING', 6, 49.00, 'completed', '2026-03-09 13:24:42', NULL, NULL, '2026-03-09 13:25:06'),
(3, 'asi', 'BASIC HAIRCUT', 4, 199.00, 'completed', '2026-03-09 13:25:52', NULL, NULL, '2026-03-09 13:26:05'),
(5, 'jericho', 'HAIR BLEACHING', 6, 548.00, 'completed', '2026-03-11 09:21:03', '2026-03-11 02:32:55', '2026-03-11 04:02:55', '2026-03-11 09:42:11'),
(6, 'jericho', 'BASIC HAIRCUT', 1, 248.00, 'completed', '2026-03-11 09:42:01', '2026-03-11 03:03:14', '2026-03-11 04:03:14', '2026-03-11 10:04:41'),
(8, 'jericho', 'BASIC HAIRCUT', 1, 199.00, 'completed', '2026-03-11 10:06:48', '2026-03-11 03:06:53', '2026-03-11 03:36:53', '2026-03-11 10:14:11'),
(11, 'jericho', 'BASIC HAIRCUT', 2, 199.00, 'completed', '2026-03-11 10:34:22', '2026-03-11 10:35:05', '2026-03-11 11:05:05', '2026-03-11 10:36:24'),
(14, 'jericho', 'BASIC HAIRCUT', NULL, 248.00, 'cancelled', '2026-03-13 08:17:24', NULL, NULL, NULL),
(15, 'jericho', 'BASIC HAIRCUT', 1, 199.00, 'completed', '2026-03-14 19:10:02', '2026-03-14 19:10:59', '2026-03-14 19:40:59', '2026-03-14 19:12:42'),
(21, 'jericho', 'BASIC HAIRCUT', 6, 248.00, 'completed', '2026-03-23 13:57:07', '2026-03-23 13:57:11', '2026-03-23 14:57:11', '2026-03-23 13:57:12'),
(22, 'Daniel', 'BASIC HAIRCUT', 4, 297.00, 'completed', '2026-03-31 10:55:42', '2026-03-31 10:55:55', '2026-03-31 11:55:55', '2026-03-31 10:56:07'),
(23, 'Peter', 'HAIR BLEACHING', 6, 597.00, 'completed', '2026-03-31 10:56:27', '2026-03-31 11:17:02', '2026-03-31 12:47:02', '2026-03-31 11:33:51'),
(24, 'jericho', 'BASIC HAIRCUT', 3, 698.00, 'ongoing', '2026-03-31 11:34:03', '2026-03-31 11:46:06', '2026-03-31 13:16:06', NULL),
(25, 'Kiff', 'BASIC HAIRCUT', 2, 248.00, 'completed', '2026-04-06 10:16:50', '2026-04-06 10:17:10', '2026-04-06 11:17:10', '2026-04-06 10:17:20'),
(26, 'gio', 'HAIR BLEACHING', 6, 747.00, 'completed', '2026-04-06 10:17:06', '2026-04-06 10:17:29', '2026-04-06 11:47:29', '2026-04-06 10:17:53'),
(27, 'Joseph', 'BASIC HAIRCUT', NULL, 248.00, 'cancelled', '2026-04-06 10:17:41', NULL, NULL, NULL),
(28, 'Kevs', 'BASIC HAIRCUT', 2, 248.00, 'completed', '2026-04-06 12:38:58', '2026-04-06 12:39:20', '2026-04-06 13:09:20', '2026-04-06 12:39:33'),
(29, 'jericho', 'BASIC HAIRCUT', 2, 199.00, 'completed', '2026-04-09 21:02:06', '2026-04-09 21:02:12', '2026-04-09 21:32:12', '2026-04-09 21:02:19'),
(30, 'jericho', 'BASIC HAIRCUT', NULL, 199.00, 'cancelled', '2026-04-10 07:39:02', NULL, NULL, NULL),
(31, 'Gio', 'BASIC HAIRCUT', NULL, 248.00, 'cancelled', '2026-04-10 08:47:50', NULL, NULL, NULL),
(32, 'Kip Lowjen', 'BASIC HAIRCUT', NULL, 796.00, 'cancelled', '2026-04-10 08:48:56', NULL, NULL, NULL),
(33, 'jericho', 'BASIC HAIRCUT', 3, 199.00, 'completed', '2026-04-10 11:24:57', '2026-04-10 11:25:19', '2026-04-10 11:55:19', '2026-04-10 11:25:26'),
(34, 'kff', 'BASIC HAIRCUT', 3, 248.00, 'completed', '2026-05-04 14:43:36', '2026-05-04 14:45:06', '2026-05-04 15:45:06', '2026-05-04 14:45:41'),
(35, 'Jericho', 'BASIC HAIRCUT', 5, 248.00, 'completed', '2026-05-07 14:29:29', '2026-05-07 14:29:36', '2026-05-07 15:29:36', '2026-05-07 14:29:38'),
(36, 'jericho', 'HAIR BLEACHING', 6, 548.00, 'completed', '2026-05-08 13:27:58', '2026-05-08 13:28:08', '2026-05-08 14:58:08', '2026-05-08 13:28:14'),
(37, 'Jayson', 'BASIC HAIRCUT', NULL, 248.00, 'waiting', '2026-05-12 14:11:12', NULL, NULL, NULL),
(38, 'Jayson', 'BASIC HAIRCUT', NULL, 248.00, 'waiting', '2026-05-12 14:11:12', NULL, NULL, NULL),
(39, 'jericho', 'BASIC HAIRCUT', NULL, 199.00, 'waiting', '2026-05-12 14:11:27', NULL, NULL, NULL),
(40, 'Joseph', 'BASIC HAIRCUT', NULL, 248.00, 'cancelled', '2026-05-16 09:29:11', NULL, NULL, NULL),
(41, 'jericho', 'BASIC HAIRCUT', NULL, 248.00, 'cancelled', '2026-07-09 09:58:01', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `walkin_services`
--

CREATE TABLE `walkin_services` (
  `id` int(11) NOT NULL,
  `walkin_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `duration_minutes` int(11) NOT NULL,
  `is_addon` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `walkin_services`
--

INSERT INTO `walkin_services` (`id`, `walkin_id`, `service_id`, `price`, `duration_minutes`, `is_addon`, `created_at`) VALUES
(5, 5, 10, 499.00, 60, 0, '2026-03-11 09:21:03'),
(6, 5, 6, 49.00, 30, 1, '2026-03-11 09:21:03'),
(7, 6, 4, 199.00, 30, 0, '2026-03-11 09:42:01'),
(8, 6, 6, 49.00, 30, 1, '2026-03-11 09:42:01'),
(10, 8, 4, 199.00, 30, 0, '2026-03-11 10:06:48'),
(13, 11, 4, 199.00, 30, 0, '2026-03-11 10:34:22'),
(16, 14, 4, 199.00, 30, 0, '2026-03-13 08:17:24'),
(17, 14, 6, 49.00, 30, 1, '2026-03-13 08:17:24'),
(18, 15, 4, 199.00, 30, 0, '2026-03-14 19:10:02'),
(26, 21, 4, 199.00, 30, 0, '2026-03-23 13:57:07'),
(27, 21, 6, 49.00, 30, 1, '2026-03-23 13:57:07'),
(28, 22, 4, 199.00, 30, 0, '2026-03-31 10:55:42'),
(29, 22, 9, 49.00, 30, 1, '2026-03-31 10:55:42'),
(30, 22, 6, 49.00, 30, 1, '2026-03-31 10:56:07'),
(31, 23, 10, 499.00, 60, 0, '2026-03-31 10:56:27'),
(32, 23, 6, 49.00, 30, 1, '2026-03-31 10:56:27'),
(33, 23, 9, 49.00, 30, 1, '2026-03-31 11:33:51'),
(34, 24, 4, 199.00, 30, 0, '2026-03-31 11:34:03'),
(35, 24, 7, 499.00, 60, 1, '2026-03-31 11:34:03'),
(36, 25, 4, 199.00, 30, 0, '2026-04-06 10:16:50'),
(37, 25, 6, 49.00, 30, 1, '2026-04-06 10:16:50'),
(38, 26, 10, 499.00, 60, 0, '2026-04-06 10:17:06'),
(39, 26, 6, 49.00, 30, 1, '2026-04-06 10:17:06'),
(40, 27, 4, 199.00, 30, 0, '2026-04-06 10:17:41'),
(41, 27, 9, 49.00, 30, 1, '2026-04-06 10:17:41'),
(42, 26, 4, 199.00, 30, 1, '2026-04-06 10:17:53'),
(43, 28, 4, 199.00, 30, 0, '2026-04-06 12:38:58'),
(44, 28, 9, 49.00, 30, 1, '2026-04-06 12:39:33'),
(45, 29, 4, 199.00, 30, 0, '2026-04-09 21:02:06'),
(46, 30, 4, 199.00, 30, 0, '2026-04-10 07:39:02'),
(47, 31, 4, 199.00, 30, 0, '2026-04-10 08:47:50'),
(48, 31, 6, 49.00, 30, 1, '2026-04-10 08:47:50'),
(49, 32, 4, 199.00, 30, 0, '2026-04-10 08:48:56'),
(50, 32, 6, 49.00, 30, 1, '2026-04-10 08:48:56'),
(51, 32, 7, 499.00, 60, 1, '2026-04-10 08:48:56'),
(52, 32, 9, 49.00, 30, 1, '2026-04-10 08:48:56'),
(53, 33, 4, 199.00, 30, 0, '2026-04-10 11:24:57'),
(54, 34, 4, 199.00, 30, 0, '2026-05-04 14:43:36'),
(55, 34, 9, 49.00, 30, 1, '2026-05-04 14:43:36'),
(56, 35, 4, 199.00, 30, 0, '2026-05-07 14:29:29'),
(57, 35, 6, 49.00, 30, 1, '2026-05-07 14:29:29'),
(58, 36, 10, 499.00, 60, 0, '2026-05-08 13:27:58'),
(59, 36, 6, 49.00, 30, 1, '2026-05-08 13:27:58'),
(60, 37, 4, 199.00, 30, 0, '2026-05-12 14:11:12'),
(61, 37, 9, 49.00, 30, 1, '2026-05-12 14:11:12'),
(62, 38, 4, 199.00, 30, 0, '2026-05-12 14:11:12'),
(63, 38, 9, 49.00, 30, 1, '2026-05-12 14:11:12'),
(64, 39, 4, 199.00, 30, 0, '2026-05-12 14:11:27'),
(65, 40, 4, 199.00, 30, 0, '2026-05-16 09:29:11'),
(66, 40, 9, 49.00, 30, 1, '2026-05-16 09:29:11'),
(67, 41, 4, 199.00, 30, 0, '2026-07-09 09:58:01'),
(68, 41, 9, 49.00, 30, 1, '2026-07-09 09:58:01');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `barber_id` (`barber_id`),
  ADD KEY `service_id` (`service_id`),
  ADD KEY `fk_appointments_customer` (`customer_id`);

--
-- Indexes for table `appointment_companions`
--
ALTER TABLE `appointment_companions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_appointment_companions_appointment` (`appointment_id`);

--
-- Indexes for table `appointment_services`
--
ALTER TABLE `appointment_services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `appointment_id` (`appointment_id`),
  ADD KEY `service_id` (`service_id`);

--
-- Indexes for table `barbers`
--
ALTER TABLE `barbers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `barber_leaves`
--
ALTER TABLE `barber_leaves`
  ADD PRIMARY KEY (`id`),
  ADD KEY `barber_id` (`barber_id`);

--
-- Indexes for table `barber_ratings`
--
ALTER TABLE `barber_ratings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `barber_id` (`barber_id`),
  ADD KEY `appointment_id` (`appointment_id`);

--
-- Indexes for table `barber_schedules`
--
ALTER TABLE `barber_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `barber_id` (`barber_id`);

--
-- Indexes for table `hairstyles`
--
ALTER TABLE `hairstyles`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `hairstyle_face_shapes`
--
ALTER TABLE `hairstyle_face_shapes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_hairstyle_face_shape` (`hairstyle_id`,`face_shape`);

--
-- Indexes for table `optional_services`
--
ALTER TABLE `optional_services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `service_id` (`service_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `appointment_id` (`appointment_id`);

--
-- Indexes for table `payment_settings`
--
ALTER TABLE `payment_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_reviews_user` (`user_id`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `staff`
--
ALTER TABLE `staff`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `walkins`
--
ALTER TABLE `walkins`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `walkin_services`
--
ALTER TABLE `walkin_services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `walkin_id` (`walkin_id`),
  ADD KEY `service_id` (`service_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=95;

--
-- AUTO_INCREMENT for table `appointment_companions`
--
ALTER TABLE `appointment_companions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `appointment_services`
--
ALTER TABLE `appointment_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=157;

--
-- AUTO_INCREMENT for table `barbers`
--
ALTER TABLE `barbers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `barber_leaves`
--
ALTER TABLE `barber_leaves`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `barber_ratings`
--
ALTER TABLE `barber_ratings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `barber_schedules`
--
ALTER TABLE `barber_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `hairstyles`
--
ALTER TABLE `hairstyles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `hairstyle_face_shapes`
--
ALTER TABLE `hairstyle_face_shapes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=99;

--
-- AUTO_INCREMENT for table `optional_services`
--
ALTER TABLE `optional_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=90;

--
-- AUTO_INCREMENT for table `payment_settings`
--
ALTER TABLE `payment_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `staff`
--
ALTER TABLE `staff`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `walkins`
--
ALTER TABLE `walkins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `walkin_services`
--
ALTER TABLE `walkin_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=69;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`barber_id`) REFERENCES `barbers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_appointments_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `appointment_companions`
--
ALTER TABLE `appointment_companions`
  ADD CONSTRAINT `fk_appointment_companions_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `appointment_services`
--
ALTER TABLE `appointment_services`
  ADD CONSTRAINT `appointment_services_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `appointment_services_ibfk_2` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `barber_leaves`
--
ALTER TABLE `barber_leaves`
  ADD CONSTRAINT `fk_barber_leaves_barber` FOREIGN KEY (`barber_id`) REFERENCES `barbers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `barber_ratings`
--
ALTER TABLE `barber_ratings`
  ADD CONSTRAINT `barber_ratings_ibfk_1` FOREIGN KEY (`barber_id`) REFERENCES `barbers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `barber_ratings_ibfk_2` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `barber_schedules`
--
ALTER TABLE `barber_schedules`
  ADD CONSTRAINT `barber_schedules_ibfk_1` FOREIGN KEY (`barber_id`) REFERENCES `barbers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `hairstyle_face_shapes`
--
ALTER TABLE `hairstyle_face_shapes`
  ADD CONSTRAINT `fk_hfs_hairstyle` FOREIGN KEY (`hairstyle_id`) REFERENCES `hairstyles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `optional_services`
--
ALTER TABLE `optional_services`
  ADD CONSTRAINT `optional_services_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`);

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_settings`
--
ALTER TABLE `payment_settings`
  ADD CONSTRAINT `fk_payment_settings_user` FOREIGN KEY (`id`) REFERENCES `users` (`id`);

--
-- Constraints for table `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `fk_reviews_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `walkin_services`
--
ALTER TABLE `walkin_services`
  ADD CONSTRAINT `walkin_services_ibfk_1` FOREIGN KEY (`walkin_id`) REFERENCES `walkins` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `walkin_services_ibfk_2` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
