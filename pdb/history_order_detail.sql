-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- 主机： 192.168.58.128:3308:3308
-- 生成日期： 2026-08-14 13:27:32
-- 服务器版本： 5.5.47
-- PHP 版本： 8.2.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 数据库： `coolroid`
--

-- --------------------------------------------------------

--
-- 表的结构 `history_order_detail`
--

CREATE TABLE `history_order_detail` (
  `order_detail_id` int(11) NOT NULL,
  `order_head_id` int(11) DEFAULT NULL,
  `check_id` int(11) DEFAULT '1',
  `menu_item_id` int(11) DEFAULT '0',
  `menu_item_name` varchar(60) DEFAULT '',
  `product_price` double(15,2) DEFAULT '0.00',
  `is_discount` bit(1) DEFAULT b'0',
  `original_price` double(15,2) DEFAULT NULL,
  `discount_id` int(11) DEFAULT '0',
  `actual_price` double(15,2) DEFAULT '0.00',
  `is_return_item` bit(1) DEFAULT b'0',
  `order_employee_id` int(11) DEFAULT '0',
  `order_employee_name` varchar(30) DEFAULT '',
  `pos_device_id` int(11) DEFAULT '0',
  `pos_name` varchar(30) DEFAULT '',
  `order_time` datetime DEFAULT NULL,
  `return_time` datetime DEFAULT NULL,
  `return_reason` varchar(200) DEFAULT '',
  `unit` varchar(30) DEFAULT '',
  `is_send` bit(1) DEFAULT b'0',
  `condiment_belong_item` int(11) DEFAULT '0',
  `quantity` float DEFAULT '0',
  `eat_type` int(11) DEFAULT '1',
  `auth_id` int(11) DEFAULT NULL,
  `auth_name` varchar(40) DEFAULT '',
  `weight_entry_required` bit(1) DEFAULT NULL,
  `description` varchar(250) DEFAULT NULL,
  `n_service_type` int(11) DEFAULT NULL,
  `not_print` int(11) DEFAULT NULL,
  `seat_num` int(11) DEFAULT NULL,
  `discount_price` double(15,2) DEFAULT NULL,
  `sales_amount` double(15,2) DEFAULT NULL,
  `is_make` int(11) DEFAULT NULL,
  `print_class` int(11) DEFAULT NULL,
  `rush` int(11) DEFAULT '0',
  `ready_qty` double(15,2) DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `the_cook_id` int(11) DEFAULT '0',
  `description2` varchar(200) DEFAULT NULL,
  `is_hitted` bit(1) DEFAULT b'0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 转存表中的数据 `history_order_detail`
--

INSERT INTO `history_order_detail` (`order_detail_id`, `order_head_id`, `check_id`, `menu_item_id`, `menu_item_name`, `product_price`, `is_discount`, `original_price`, `discount_id`, `actual_price`, `is_return_item`, `order_employee_id`, `order_employee_name`, `pos_device_id`, `pos_name`, `order_time`, `return_time`, `return_reason`, `unit`, `is_send`, `condiment_belong_item`, `quantity`, `eat_type`, `auth_id`, `auth_name`, `weight_entry_required`, `description`, `n_service_type`, `not_print`, `seat_num`, `discount_price`, `sales_amount`, `is_make`, `print_class`, `rush`, `ready_qty`, `end_time`, `the_cook_id`, `description2`, `is_hitted`) VALUES
(1, 9932, 1, 110, '110-Nigiri de salmón flameado', 0.00, b'0', NULL, 9, 0.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-22 19:16:30', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(2, 9932, 1, 95, '95-Gunkan de queso crema', 0.00, b'0', NULL, 9, 0.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-22 19:16:30', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(3, 9932, 1, 165, '165-Rollo especial', 0.00, b'0', NULL, 9, 0.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-22 19:16:30', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(4, 9932, 1, 175, '175-Rollo especial ebi (Noche)', 0.00, b'0', NULL, 9, 0.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-22 19:16:30', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(5, 9932, 1, 224, '224-Futo frito', 0.00, b'0', NULL, 9, 0.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-22 19:16:30', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(6, 9932, 1, 63, '63-Yakisoba', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-22 19:16:30', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(7, 9932, 1, 4, '4-Ensalada Wakame', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-22 19:16:30', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(8, 9932, 1, 202, '202-Niku gyoza', 0.00, b'0', NULL, 9, 0.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-22 19:16:30', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(9, 9932, 1, 46, '46-Ebi tempura', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-22 19:16:30', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(10, 9932, 1, 431, 'Agua', 0.00, b'0', 2.80, 0, 0.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-22 19:16:30', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 0, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(11, 9932, 1, -3, '**999 Enviado 19:16**', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-22 19:16:30', NULL, NULL, NULL, NULL, 0, 0, 0, 0, '', b'0', NULL, 0, 1, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(12, 9932, 1, 111, '111-Nigiri de salmón', 0.00, b'0', NULL, 9, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-22 19:18:01', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(13, 9932, 1, 150, '150-Salmon plus', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-22 19:18:01', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(14, 9932, 1, 178, '178-Rollo especial de mango', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-22 19:18:01', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(15, 9932, 1, 92, '92-Gunkan de atún', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-22 19:18:01', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(16, 9932, 1, 45, '45-Tempura moriawase', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-22 19:18:01', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(17, 9932, 1, 204, '204-Ebi gyoza (especial)', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-22 19:18:01', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(18, 9932, 1, 62, '62-Espagueti de soja', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-22 19:18:01', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(19, 9932, 1, 214, '214-Sartén de gambas y verduras mixtas', 0.00, b'0', NULL, 9, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-22 19:18:01', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(20, 9932, 1, 225, '225-Futo ebiten', 0.00, b'0', NULL, 9, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-22 19:18:01', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(21, 9932, 1, 431, 'Agua', 0.00, b'0', 2.80, 0, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-22 19:18:01', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 0, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(22, 9932, 1, -3, '**999 Enviado 19:18**', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-22 19:18:01', NULL, NULL, NULL, NULL, 0, 0, 0, 0, '', b'0', NULL, 0, 1, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(23, 9932, 1, -4, 'EFECTIVO', 0.00, b'0', NULL, 1, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-22 19:30:18', NULL, NULL, NULL, NULL, 0, 0, 0, 0, '', b'0', NULL, 0, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(49, 9934, 1, 1, '1-Edamame', 0.00, b'0', NULL, 0, 0.00, b'1', 555, '员工', 1, 'PDA', '2024-01-23 22:09:23', '2024-01-23 22:18:27', 'Doblado', NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(50, 9934, 1, 240, '240-Poke Som', 0.00, b'0', NULL, 0, 0.00, b'1', 555, '员工', 1, 'PDA', '2024-01-23 22:09:23', '2024-01-23 22:18:27', 'Doblado', NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(51, 9934, 1, 90, '90-Gunkan de salmón y calabacín', 0.00, b'0', NULL, 9, 0.00, b'1', 555, '员工', 1, 'PDA', '2024-01-23 22:09:23', '2024-01-23 22:18:27', 'Doblado', NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(52, 9934, 1, 192, '192-Sushi medio', 0.00, b'0', NULL, 9, 0.00, b'1', 555, '员工', 1, 'PDA', '2024-01-23 22:09:23', '2024-01-23 22:18:27', 'Doblado', NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(53, 9934, 1, 224, '224-Futo frito', 0.00, b'0', NULL, 9, 0.00, b'1', 555, '员工', 1, 'PDA', '2024-01-23 22:09:23', '2024-01-23 22:18:27', 'Doblado', NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(54, 9934, 1, 149, '149-Crispy chicken roll', 0.00, b'0', NULL, 0, 0.00, b'1', 555, '员工', 1, 'PDA', '2024-01-23 22:09:23', '2024-01-23 22:18:27', 'Doblado', NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(55, 9934, 1, 178, '178-Rollo especial de mango', 0.00, b'0', NULL, 0, 0.00, b'1', 555, '员工', 1, 'PDA', '2024-01-23 22:09:23', '2024-01-23 22:18:27', 'Doblado', NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(56, 9934, 1, 41, '41-Pinzas de cangrejo frito', 0.00, b'0', NULL, 0, 0.00, b'1', 555, '员工', 1, 'PDA', '2024-01-23 22:09:23', '2024-01-23 22:18:27', 'Doblado', NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(57, 9934, 1, 140, '140-Tataki de ternera', 0.00, b'0', NULL, 9, 0.00, b'1', 555, '员工', 1, 'PDA', '2024-01-23 22:09:23', '2024-01-23 22:18:27', 'Doblado', NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(58, 9934, 1, 402, 'Valdubon - Verdejo (Rueda)', 13.95, b'0', NULL, 0, 13.95, b'1', 555, '员工', 1, 'PDA', '2024-01-23 22:09:23', '2024-01-23 22:18:27', 'Doblado', NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(59, 9934, 1, -3, '**555 下单 22:09**', 0.00, NULL, NULL, NULL, 0.00, b'0', 555, '员工', 1, 'PDA', NULL, NULL, NULL, NULL, NULL, NULL, 1, 0, NULL, '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(60, 9934, 1, 431, 'Agua', 2.80, b'0', NULL, 0, 2.80, b'1', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-23 22:11:43', '2024-01-23 22:18:27', 'Doblado', NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 0, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(61, 9934, 1, 2390, 'MENÚ INFINITY NOCHE-FESTIVO-FIN DE SEMANA-ADULTOS', 23.90, b'0', NULL, 9, 23.90, b'1', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-23 22:11:43', '2024-01-23 22:18:27', 'Doblado', NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 0, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(62, 9934, 1, -3, '**999 Enviado 22:11**', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-23 22:11:43', NULL, NULL, NULL, NULL, 0, 0, 0, 0, '', b'0', NULL, 0, 1, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(63, 9934, 1, 403, 'Apertas - Godello (Monterrei)', 15.95, b'0', NULL, 0, 15.95, b'1', 555, '员工', 1, 'PDA', '2024-01-23 22:15:45', '2024-01-23 22:18:27', 'Doblado', NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', '1 Copa  ', 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(64, 9934, 1, -3, '**555 下单 22:15**', 0.00, NULL, NULL, NULL, 0.00, b'0', 555, '员工', 1, 'PDA', NULL, NULL, NULL, NULL, NULL, NULL, 1, 0, NULL, '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(65, 9934, 1, 403, 'Apertas - Godello (Monterrei)', 15.95, b'0', NULL, 0, 15.95, b'1', 555, '员工', 1, 'PDA', '2024-01-23 22:16:22', '2024-01-23 22:18:27', 'Doblado', NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', '2 Copas  ', 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(66, 9934, 1, -3, '**555 下单 22:16**', 0.00, NULL, NULL, NULL, 0.00, b'0', 555, '员工', 1, 'PDA', NULL, NULL, NULL, NULL, NULL, NULL, 1, 0, NULL, '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(24, 9933, 1, 221, '221-Hoso sake', 0.00, b'0', NULL, 9, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:03:16', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(25, 9933, 1, 222, '222-Hoso aguacate', 0.00, b'0', NULL, 9, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:03:16', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(26, 9933, 1, 223, '223-Hoso fruta', 0.00, b'0', NULL, 9, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:03:16', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(27, 9933, 1, 226, '226-Hoso tekka', 0.00, b'0', NULL, 9, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:03:16', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(28, 9933, 1, 149, '149-Crispy chicken roll', 0.00, b'0', NULL, 0, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:03:16', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(29, 9933, 1, 156, '156-Crispy aguacate roll negro ', 0.00, b'0', NULL, 9, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:03:16', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(30, 9933, 1, 157, '157-Rollo crujiente', 0.00, b'0', NULL, 9, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:03:16', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(31, 9933, 1, -3, '**555 下单 22:03**', 0.00, NULL, NULL, NULL, 0.00, b'0', 555, '员工', 1, 'PDA', NULL, NULL, NULL, NULL, NULL, NULL, 1, 0, NULL, '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(32, 9933, 1, 205, '205-Gambas con sal y pimienta', 0.00, b'0', NULL, 9, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:06:25', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(33, 9933, 1, 208, '208-Ebi picante', 0.00, b'0', NULL, 9, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:06:25', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(34, 9933, 1, 209, '209-Gambas con setas', 0.00, b'0', NULL, 9, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:06:25', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(35, 9933, 1, 61, '61-Fideos de arroz ', 0.00, b'0', NULL, 0, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:06:25', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(36, 9933, 1, 64, '64-Yakiudon', 0.00, b'0', NULL, 0, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:06:25', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(37, 9933, 1, 80, '80-Yakimeshi venus', 0.00, b'0', NULL, 9, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:06:25', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(38, 9933, 1, 81, '81-Yakimeshi', 0.00, b'0', NULL, 9, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:06:25', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(39, 9933, 1, 235, '235-Yaki bao', 0.00, b'0', NULL, 0, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:06:25', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(40, 9933, 1, 200, '200-Pan al vapor', 0.00, b'0', NULL, 9, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:06:25', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(41, 9933, 1, 203, '203-Pan frito', 0.00, b'0', NULL, 0, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:06:25', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(42, 9933, 1, 201, '201-Shumai gyoza', 0.00, b'0', NULL, 9, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:06:25', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(43, 9933, 1, 195, '195-Miso', 0.00, b'0', NULL, 9, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:06:25', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(44, 9933, 1, 140, '140-Tataki de ternera', 0.00, b'0', NULL, 9, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:06:25', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(45, 9933, 1, 143, '143-Sake teppanyaki', 0.00, b'0', NULL, 9, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:06:25', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(46, 9933, 1, 47, '47-Yasai tempura', 0.00, b'0', NULL, 0, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:06:25', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(47, 9933, 1, 152, '152-Rollo de rúcula', 0.00, b'0', NULL, 0, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-23 22:06:25', NULL, NULL, NULL, b'1', 0, 1, 0, 0, '', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(48, 9933, 1, -3, '**555 下单 22:06**', 0.00, NULL, NULL, NULL, 0.00, b'0', 555, '员工', 1, 'PDA', NULL, NULL, NULL, NULL, NULL, NULL, 1, 0, NULL, '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(67, 9935, 1, 1, '1-Edamame', 0.00, b'1', NULL, 0, 0.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-25 12:31:04', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(68, 9935, 1, 2, '2-Takoyaki', 0.00, b'1', NULL, 0, 0.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-25 12:31:04', NULL, NULL, NULL, b'1', 0, 2, 0, 999, 'Jefe', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(69, 9935, 1, -3, '**999 Enviado 12:31**', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-25 12:31:04', NULL, NULL, NULL, NULL, 0, 0, 0, 0, '', b'0', NULL, 0, 1, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(75, 9935, 1, 431, 'Agua', 2.80, b'1', NULL, 0, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-25 12:44:45', NULL, NULL, NULL, b'1', 0, 3, 0, 999, 'Jefe', b'0', NULL, 0, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(76, 9935, 1, -3, '**999 Enviado 12:44**', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-25 12:44:45', NULL, NULL, NULL, NULL, 0, 0, 0, 0, '', b'0', NULL, 0, 1, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(77, 9937, 1, 6052, 'COMBO S', 15.00, b'0', NULL, 0, 15.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-25 12:56:33', NULL, NULL, NULL, b'1', 0, 1, 3, 0, '', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(78, 9937, 1, 111, '111-Nigiri de salmón (2uds)', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-25 12:56:33', NULL, NULL, NULL, b'1', -77, 1, 3, 0, '', b'0', NULL, 0, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(79, 9937, 1, 115, '115-Nigiri de atún (2uds)', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-25 12:56:33', NULL, NULL, NULL, b'1', -77, 1, 3, 0, '', b'0', NULL, 0, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(80, 9937, 1, 162, '162-Rollo tigre (4uds)', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-25 12:56:33', NULL, NULL, NULL, b'1', -77, 2, 3, 0, '', b'0', NULL, 0, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(81, 9937, 1, 163, '163-Rollo ángel (4uds)', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-25 12:56:33', NULL, NULL, NULL, b'1', -77, 2, 3, 0, '', b'0', NULL, 0, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(82, 9937, 1, -4, 'EFECTIVO', 0.00, b'0', NULL, 1, 15.00, b'0', 999, 'Jefe', 4, 'SDPOS-1', '2024-01-25 12:57:11', NULL, NULL, NULL, NULL, 0, 0, 0, 0, '', b'0', NULL, 0, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(83, 9938, 1, 431, 'Agua', 2.80, b'1', NULL, 0, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-25 13:01:54', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 0, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(84, 9938, 1, 90, '90-Gunkan de salmón y calabacín', 0.00, b'1', NULL, 9, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-25 13:01:54', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(85, 9938, 1, 110, '110-Nigiri de salmón flameado', 0.00, b'1', NULL, 9, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-25 13:01:54', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(86, 9938, 1, 165, '165-Rollo especial', 0.00, b'1', NULL, 9, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-25 13:01:54', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(87, 9938, 1, 178, '178-Rollo especial de mango', 0.00, b'1', NULL, 0, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-25 13:01:54', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(88, 9938, 1, 224, '224-Futo frito', 0.00, b'1', NULL, 9, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-25 13:01:54', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(89, 9938, 1, 2, '2-Takoyaki', 0.00, b'1', NULL, 0, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-25 13:01:54', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(90, 9938, 1, 63, '63-Yakisoba', 0.00, b'1', NULL, 0, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-25 13:01:54', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(91, 9938, 1, 202, '202-Niku gyoza', 0.00, b'1', NULL, 9, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-25 13:01:54', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(92, 9938, 1, -3, '**999 Enviado 13:01**', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-25 13:01:54', NULL, NULL, NULL, NULL, 0, 0, 0, 0, '', b'0', NULL, 0, 1, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(93, 9938, 1, 209, '209-Gambas con setas', 0.00, b'1', NULL, 9, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-25 13:03:50', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(94, 9938, 1, -3, '**999 Enviado 13:03**', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-25 13:03:50', NULL, NULL, NULL, NULL, 0, 0, 0, 0, '', b'0', NULL, 0, 1, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(95, 9938, 1, 225, '225-Futo ebiten', 0.00, b'1', NULL, 9, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-25 13:04:00', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(96, 9938, 1, -3, '**999 Enviado 13:04**', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-25 13:04:00', NULL, NULL, NULL, NULL, 0, 0, 0, 0, '', b'0', NULL, 0, 1, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(97, 9938, 1, 201, '201-Shumai gyoza', 0.00, b'1', NULL, 9, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-25 13:05:11', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, 0, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(98, 9938, 1, -3, '**999 Enviado 13:05**', 0.00, b'0', NULL, 0, 0.00, b'0', 999, 'Jefe', 8, 'SDPOS2', '2024-01-25 13:05:11', NULL, NULL, NULL, NULL, 0, 0, 0, 0, '', b'0', NULL, 0, 1, 0, 0.00, 0.00, NULL, 0, 0, 0.00, NULL, 0, NULL, b'0'),
(99, 9939, 1, 402, 'Valdubon - Verdejo (Rueda)', 13.95, b'1', NULL, 0, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-25 13:06:04', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', '2 Copas  ', 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(100, 9939, 1, 1, '1-Edamame', 0.00, b'1', NULL, 0, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-25 13:06:04', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(101, 9939, 1, 2, '2-Takoyaki', 0.00, b'1', NULL, 0, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-25 13:06:04', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(102, 9939, 1, 240, '240-Poke Som', 0.00, b'1', NULL, 0, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-25 13:06:04', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(103, 9939, 1, 90, '90-Gunkan de salmón y calabacín', 0.00, b'1', NULL, 9, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-25 13:06:04', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(104, 9939, 1, 32, '32-Rollo de esparrágos de atún (especial)', 1.00, b'1', NULL, 0, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-25 13:06:04', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0'),
(105, 9939, 1, 230, '230-Onigiri sake', 0.00, b'1', NULL, 9, 0.00, b'0', 555, '员工', 1, 'PDA', '2024-01-25 13:06:04', NULL, NULL, NULL, b'1', 0, 1, 0, 999, 'Jefe', b'0', NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, b'0');

--
-- 触发器 `history_order_detail`
--
DELIMITER $$
CREATE TRIGGER `trigger_history_order_detail_update` AFTER UPDATE ON `history_order_detail` FOR EACH ROW BEGIN 
	update history_order_head set status=1 where order_head_id=NEW.order_head_id;
END
$$
DELIMITER ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
