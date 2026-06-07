-- MilkRoute Database Schema v1.0
-- Import this file via phpMyAdmin or MySQL CLI

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+05:30";

-- --------------------------------------------------------
-- Table: admins
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL UNIQUE,
  `password` varchar(255) NOT NULL,
  `role` enum('superadmin','admin') DEFAULT 'admin',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `admins` (`name`, `email`, `password`, `role`) VALUES
('Super Admin', 'admin@milkroute.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'superadmin');
-- Default password: password

-- --------------------------------------------------------
-- Table: customers
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL UNIQUE,
  `mobile` varchar(15) NOT NULL,
  `password` varchar(255) NOT NULL,
  `wing` varchar(50) NOT NULL,
  `flat_number` varchar(20) NOT NULL,
  `delivery_pattern` enum('daily','alternate') DEFAULT 'daily',
  `alternate_start` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `email_verified` tinyint(1) DEFAULT 0,
  `email_verify_token` varchar(100) DEFAULT NULL,
  `email_verify_expires` datetime DEFAULT NULL,
  `reset_token` varchar(100) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table: milk_companies
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `milk_companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `tagline` varchar(200) DEFAULT NULL,
  `logo_color` varchar(10) DEFAULT '#22c55e',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `milk_companies` (`name`, `tagline`, `logo_color`) VALUES
('Amul', 'Goodness of pure milk', '#0066b2'),
('Chitale', 'Fresh & healthy dairy', '#e31837'),
('Gokul', 'Pure by tradition', '#f97316'),
('Govardhan', 'Simply authentic', '#facc15');

-- --------------------------------------------------------
-- Table: milk_products
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `milk_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `unit` enum('litre','piece') DEFAULT 'litre',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`company_id`) REFERENCES `milk_companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `milk_products` (`company_id`, `name`, `description`) VALUES
(1, 'Amul Taaza', 'Standardised toned milk'),
(1, 'Amul Gold', 'Full cream milk'),
(1, 'Amul Cow Milk', 'Pure cow milk'),
(1, 'Amul Slim & Trim', 'Double toned milk'),
(2, 'Chitale Buffalo', 'Rich buffalo milk'),
(2, 'Chitale Cow Milk', 'Fresh cow milk');

-- --------------------------------------------------------
-- Table: milk_prices (price history per product)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `milk_prices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `price_per_litre` decimal(8,2) NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'admin id',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`product_id`) REFERENCES `milk_products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `milk_prices` (`product_id`, `price_per_litre`, `effective_from`) VALUES
(1, 31.00, '2026-01-01'),
(2, 67.00, '2026-01-01'),
(3, 60.00, '2026-01-01'),
(4, 65.00, '2026-01-01'),
(5, 74.00, '2026-01-01'),
(6, 64.00, '2026-01-01');

-- --------------------------------------------------------
-- Table: subscriptions (customer's default milk preferences)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `default_qty` decimal(5,1) NOT NULL DEFAULT 1.0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_customer_product` (`customer_id`, `product_id`),
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `milk_products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table: daily_deliveries
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `daily_deliveries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `delivery_date` date NOT NULL,
  `qty_ordered` decimal(5,1) NOT NULL DEFAULT 0.0,
  `qty_delivered` decimal(5,1) DEFAULT NULL,
  `status` enum('pending','delivered','not_delivered','skipped') DEFAULT 'pending',
  `skip_reason` varchar(100) DEFAULT NULL COMMENT 'customer_request, vacation, alternate_day',
  `price_at_delivery` decimal(8,2) DEFAULT NULL,
  `amount` decimal(10,2) GENERATED ALWAYS AS (qty_delivered * price_at_delivery) STORED,
  `delivery_time` time DEFAULT NULL,
  `marked_by` enum('system','admin','customer') DEFAULT 'system',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_delivery` (`customer_id`, `product_id`, `delivery_date`),
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `milk_products`(`id`) ON DELETE CASCADE,
  INDEX `idx_delivery_date` (`delivery_date`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table: skip_requests (customer skip management)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `skip_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `skip_date_start` date NOT NULL,
  `skip_date_end` date NOT NULL,
  `reason` varchar(255) DEFAULT 'customer_request',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  INDEX `idx_skip_dates` (`skip_date_start`, `skip_date_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table: payments
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `month` tinyint(2) NOT NULL COMMENT '1-12',
  `year` smallint(4) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `balance` decimal(10,2) DEFAULT 0.00,
  `payment_method` enum('cash','upi','bank_transfer','other') DEFAULT 'upi',
  `payment_date` date DEFAULT NULL,
  `status` enum('unpaid','partial','paid') DEFAULT 'unpaid',
  `transaction_ref` varchar(100) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL COMMENT 'admin id',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_customer_month` (`customer_id`, `month`, `year`),
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  INDEX `idx_status` (`status`),
  INDEX `idx_month_year` (`month`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table: notifications
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) DEFAULT NULL COMMENT 'NULL = all customers',
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `type` enum('delivery','payment','price_change','general') DEFAULT 'general',
  `is_read` tinyint(1) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL COMMENT 'admin id',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  INDEX `idx_customer_read` (`customer_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
