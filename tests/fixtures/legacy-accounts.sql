-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : localhost
-- Généré le : mar. 13 oct. 2026 à 17:13
-- Version du serveur : 10.11.19-MariaDB-deb12
-- Version de PHP : 8.2.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `bolds940982_22e8hqb`
--

-- --------------------------------------------------------

--
-- Structure de la table `clients`
--

CREATE TABLE `clients` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `nom` varchar(191) NOT NULL,
  `phone` varchar(191) NOT NULL,
  `city` varchar(191) DEFAULT NULL,
  `adresse` varchar(191) NOT NULL,
  `goods_type` varchar(191) DEFAULT NULL,
  `wme` varchar(20) DEFAULT NULL,
  `user_id` int(20) DEFAULT NULL,
  `latitude` varchar(300) DEFAULT NULL,
  `longitude` varchar(300) DEFAULT NULL,
  `photo` varchar(191) DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `is_manager` int(11) DEFAULT NULL,
  `is_certifier` varchar(5) DEFAULT NULL,
  `is_collecter` varchar(5) DEFAULT NULL,
  `client_type` varchar(200) DEFAULT NULL,
  `cc_id` int(11) DEFAULT NULL,
  `last_sms` datetime DEFAULT NULL,
  `last_wa` datetime DEFAULT NULL,
  `shop_subs` date DEFAULT NULL,
  `wa_end` datetime DEFAULT NULL,
  `instance_id` varchar(50) DEFAULT NULL,
  `wa_token` varchar(500) DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `livreurs`
--

CREATE TABLE `livreurs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `nom` varchar(191) DEFAULT NULL,
  `phone` varchar(191) DEFAULT NULL,
  `adresse` varchar(191) DEFAULT NULL,
  `city` varchar(191) DEFAULT NULL,
  `pieces` varchar(191) DEFAULT NULL,
  `moto_id` int(11) DEFAULT NULL,
  `map_link` varchar(1000) DEFAULT NULL,
  `wme` varchar(100) DEFAULT NULL,
  `working_day` varchar(20) DEFAULT NULL,
  `card_number` int(11) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'inactive',
  `user_id` int(11) DEFAULT NULL,
  `longitude` varchar(250) DEFAULT NULL,
  `latitude` varchar(250) DEFAULT NULL,
  `domlong` varchar(1000) DEFAULT NULL,
  `domlat` varchar(1000) DEFAULT NULL,
  `geotime` datetime DEFAULT NULL,
  `photo` varchar(191) DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `is_manager` int(11) DEFAULT NULL,
  `certified_at` datetime DEFAULT NULL,
  `piece_link` varchar(191) DEFAULT NULL,
  `jibiat_reliability` int(1) DEFAULT NULL,
  `average` varchar(100) DEFAULT NULL,
  `experience` int(11) DEFAULT NULL,
  `disponible` tinyint(1) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- Structure de la table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `email` varchar(191) NOT NULL,
  `phone` varchar(191) NOT NULL,
  `usertype` varchar(191) DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(191) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `livreur_id` int(20) DEFAULT NULL,
  `client_id` int(20) DEFAULT NULL,
  `approved` varchar(3) DEFAULT NULL,
  `category` int(2) DEFAULT NULL,
  `manager_id` int(20) DEFAULT NULL,
  `certified_at` timestamp NULL DEFAULT NULL,
  `user_group` varchar(500) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `livreurs`
--
ALTER TABLE `livreurs`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `clients`
--
ALTER TABLE `clients`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `livreurs`
--
ALTER TABLE `livreurs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT pour la table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

INSERT INTO `users` (`id`, `name`, `email`, `phone`, `usertype`, `email_verified_at`, `password`, `remember_token`, `created_at`, `updated_at`, `livreur_id`, `client_id`, `approved`, `category`, `manager_id`, `certified_at`, `user_group`) VALUES
(1, 'Admin Agence', 'admin@agence.ci', '0700000099', 'admin', NULL, '$2y$10$l4EdonX1RMloN0UFYN1.Z.TGDzAlof1zNELfbYOhY6FO67hOU6tTu', NULL, '2022-01-01 10:00:00', NULL, NULL, NULL, 'yes', NULL, NULL, NULL, NULL),
(10, 'Mariam Koné', 'MARIAM@boutique.ci', '07 11 22 33 44', 'client', NULL, '$2y$10$l4EdonX1RMloN0UFYN1.Z.TGDzAlof1zNELfbYOhY6FO67hOU6tTu', NULL, '2023-03-05 09:00:00', NULL, NULL, 5, 'yes', NULL, NULL, NULL, NULL),
(11, 'Jean d\'Arc', 'pas-un-email', '0511223344', 'client', NULL, 'md5:abcdef', NULL, NULL, NULL, NULL, 6, NULL, NULL, NULL, NULL, NULL),
(20, 'Adamo', 'adamo@livreur.ci', '0153141666', 'livreur', NULL, '$2a$10$zONflzkJqIclnO6VyJmNIuXWG37VS8vUr8WMqG49o1h1eeZ8KhpFy', NULL, '2025-04-26 17:15:46', NULL, 20, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `clients` (`id`, `created_at`, `updated_at`, `nom`, `phone`, `city`, `adresse`, `goods_type`, `wme`, `user_id`, `latitude`, `longitude`, `photo`, `manager_id`, `is_manager`, `is_certifier`, `is_collecter`, `client_type`, `cc_id`, `last_sms`, `last_wa`, `shop_subs`, `wa_end`, `instance_id`, `wa_token`, `url`) VALUES
(5, '2023-03-05 09:00:00', '2024-01-10 12:00:00', 'Boutique Chic', '0711223344', 'cocody', 'Riviera 3, près de la pharmacie', 'Vêtements', NULL, 10, '5.3601', '-3.9712', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(6, NULL, NULL, 'L''Atelier\nde Jean', '0511223344', 'Bingerville', 'Route de Bingerville', NULL, NULL, 11, '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `clients` VALUES
(7, NULL, NULL, 'Sans téléphone', '12', 'Plateau', 'Rue du commerce', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(8, NULL, NULL, 'Boutique sans compte', '0102030405', 'Yopougon', 'Siporex', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `livreurs` (`id`, `created_at`, `updated_at`, `nom`, `phone`, `adresse`, `city`, `pieces`, `moto_id`, `map_link`, `wme`, `working_day`, `card_number`, `status`, `user_id`, `longitude`, `latitude`, `domlong`, `domlat`, `geotime`, `photo`, `manager_id`, `owner_id`, `is_manager`, `certified_at`, `piece_link`, `jibiat_reliability`, `average`, `experience`, `disponible`) VALUES
(11, '2023-06-20 04:31:47', '2023-06-20 04:32:00', 'Livreur', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'inactive', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL),
(20, '2025-04-26 17:15:46', '2025-08-22 08:17:50', 'Adamo', '0153141666', 'abatta', 'Cocody', NULL, NULL, NULL, NULL, NULL, NULL, 'active', 20, '-4.02', '5.36', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1),
(29, '2025-06-12 07:31:50', '2025-06-12 07:31:50', 'Aya Moaye', '0202020202', '0202020202', 'Marcory', NULL, NULL, NULL, NULL, NULL, NULL, 'inactive', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
