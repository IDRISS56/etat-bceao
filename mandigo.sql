-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1:3306
-- Généré le : mar. 09 juin 2026 à 18:19
-- Version du serveur : 9.1.0
-- Version de PHP : 8.4.0

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `mandigo`
--

-- --------------------------------------------------------

--
-- Structure de la table `agences`
--

DROP TABLE IF EXISTS `agences`;
CREATE TABLE IF NOT EXISTS `agences` (
  `agence_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `code_agence` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nom_agence` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `adresse` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `telephone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `directeur` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `coordonnes_gps` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `code_agence_bceao` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Code officiel attribué par la BCEAO',
  `region_bceao` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `statut` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`agence_id`),
  UNIQUE KEY `code_agence` (`code_agence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `agences`
--

INSERT INTO `agences` (`agence_id`, `code_agence`, `nom_agence`, `adresse`, `telephone`, `directeur`, `coordonnes_gps`, `code_agence_bceao`, `region_bceao`, `date_creation`, `statut`) VALUES
('AGC001', 'AG001', 'Agence Centrale', 'Abidjan Plateau, Rue des Banques', '+225 27 20 21 11 11', 'Kouadio Jean', '5.3364° N, -4.0267° E', 'CI001A', 'ABIDJAN', '2023-01-01 00:00:00', 'active'),
('AGC002', 'AG002', 'Agence Yopougon', 'Abidjan Yopougon, Rue Principale', '+225 27 22 23 45 67', 'Konan Marie', '5.3250° N, -4.0580° E', 'CI002B', 'ABIDJAN', '2023-02-15 00:00:00', 'active'),
('AGC003', 'AG003', 'Agence Bouaké', 'Bouaké, Avenue Houphouet', '+225 31 63 12 34 56', 'Traoré Ahmed', '7.6900° N, -5.0300° W', 'CI003C', 'CENTRE', '2023-03-10 00:00:00', 'active');

-- --------------------------------------------------------

--
-- Structure de la table `amortissements`
--

DROP TABLE IF EXISTS `amortissements`;
CREATE TABLE IF NOT EXISTS `amortissements` (
  `amortissement_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `immobilisation_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `exercice` year NOT NULL,
  `mois` tinyint NOT NULL COMMENT '1 à 12',
  `dotation_mois` decimal(15,6) NOT NULL COMMENT 'Charge du mois',
  `cumule_amortissement` decimal(15,6) NOT NULL,
  `valeur_nette` decimal(15,6) NOT NULL COMMENT 'Valeur brute - amort_cumule_apres',
  `date_calcul` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `statut` enum('calculé','validé','extourné') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'calculé',
  PRIMARY KEY (`amortissement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Historique mensuel des dotations aux amortissements';

--
-- Déchargement des données de la table `amortissements`
--

INSERT INTO `amortissements` (`amortissement_id`, `immobilisation_id`, `exercice`, `mois`, `dotation_mois`, `cumule_amortissement`, `valeur_nette`, `date_calcul`, `statut`) VALUES
('AMT001', 'IMM001', '2024', 1, 41666.667000, 666666.670000, 833333.330000, '2024-01-31 23:59:59', 'validé'),
('AMT002', 'IMM001', '2024', 2, 41666.667000, 708333.340000, 791666.660000, '2024-02-29 23:59:59', 'validé'),
('AMT003', 'IMM001', '2024', 3, 41666.667000, 750000.010000, 749999.990000, '2024-03-31 23:59:59', 'calculé'),
('AMT004', 'IMM002', '2024', 1, 13888.889000, 208333.330000, 291666.670000, '2024-01-31 23:59:59', 'validé'),
('AMT005', 'IMM002', '2024', 2, 13888.889000, 222222.220000, 277777.780000, '2024-02-29 23:59:59', 'validé'),
('AMT006', 'IMM002', '2024', 3, 13888.889000, 236111.110000, 263888.890000, '2024-03-31 23:59:59', 'calculé');

-- --------------------------------------------------------

--
-- Structure de la table `audits`
--

DROP TABLE IF EXISTS `audits`;
CREATE TABLE IF NOT EXISTS `audits` (
  `id_audit` int NOT NULL AUTO_INCREMENT,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `action` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `module` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `date_action` datetime NOT NULL,
  `etat_audit` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id_audit`),
  KEY `id_utilisateur` (`utilisateur_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `audits`
--

INSERT INTO `audits` (`id_audit`, `utilisateur_id`, `action`, `module`, `details`, `date_action`, `etat_audit`) VALUES
(1, 'USR001', 'CONNEXION', 'Authentification', 'Connexion utilisateur', '2026-06-09 15:39:02', 'SUCCÈS'),
(2, 'USR001', 'CREATION', 'Client', 'Création client CLT001 - Diallo Amadou', '2026-06-09 15:39:02', 'SUCCÈS'),
(3, 'USR001', 'CREATION', 'Compte', 'Création compte CPT001 pour client CLT001', '2026-06-09 15:39:02', 'SUCCÈS'),
(4, 'USR001', 'APPROBATION', 'Crédit', 'Approbation dossier crédit DOS001', '2026-06-09 15:39:02', 'SUCCÈS'),
(5, 'USR004', 'VALIDATION', 'Transaction', 'Validation transaction TRX001', '2026-06-09 15:39:02', 'SUCCÈS');

-- --------------------------------------------------------

--
-- Structure de la table `caisses`
--

DROP TABLE IF EXISTS `caisses`;
CREATE TABLE IF NOT EXISTS `caisses` (
  `caisse_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `code_caisse` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nom_caisse` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `caisse_type_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `solde_actuel` decimal(15,2) DEFAULT '0.00',
  `plafond_maximum` decimal(15,2) NOT NULL,
  `agence_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('ouverte','fermee') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'ouverte',
  PRIMARY KEY (`caisse_id`),
  UNIQUE KEY `code_caisse` (`code_caisse`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `caisses`
--

INSERT INTO `caisses` (`caisse_id`, `code_caisse`, `nom_caisse`, `caisse_type_id`, `solde_actuel`, `plafond_maximum`, `agence_id`, `statut`) VALUES
('CAI001', 'CAI001', 'Caisse Principale Plateau', 'CT001', 15000000.00, 50000000.00, 'AGC001', 'ouverte'),
('CAI002', 'CAI002', 'Caisse Guichet Yopougon', 'CT002', 5000000.00, 25000000.00, 'AGC002', 'ouverte'),
('CAI003', 'CAI003', 'Caisse Bouaké', 'CT002', 3000000.00, 20000000.00, 'AGC003', 'ouverte');

-- --------------------------------------------------------

--
-- Structure de la table `caisses_types`
--

DROP TABLE IF EXISTS `caisses_types`;
CREATE TABLE IF NOT EXISTS `caisses_types` (
  `caisse_type_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `numero_compte` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'actif',
  PRIMARY KEY (`caisse_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Types de caisses physiques ou logiques';

--
-- Déchargement des données de la table `caisses_types`
--

INSERT INTO `caisses_types` (`caisse_type_id`, `nom`, `numero_compte`, `statut`) VALUES
('CT001', 'Caisse Principale', '571000', 'actif'),
('CT002', 'Caisse Guichet', '571100', 'actif'),
('CT003', 'Caisse Secours', '571200', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `capital`
--

DROP TABLE IF EXISTS `capital`;
CREATE TABLE IF NOT EXISTS `capital` (
  `capital_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `libelle` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `actionnaire` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `montant` decimal(15,2) NOT NULL,
  `operation_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `mode_paiement` enum('CASH','BANQUE','COMPENSATION','NATURE') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'BANQUE',
  `agence_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `statut` enum('valide','en_attente','annule','partiel') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'valide',
  PRIMARY KEY (`capital_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `capital`
--

INSERT INTO `capital` (`capital_id`, `libelle`, `actionnaire`, `montant`, `operation_id`, `mode_paiement`, `agence_id`, `utilisateur_id`, `date_creation`, `statut`) VALUES
('CAP001', 'Apport initial', 'KOUADIO Jean', 50000000.00, NULL, 'BANQUE', 'AGC001', 'USR001', '2026-06-09 15:39:02', 'valide'),
('CAP002', 'Apport secondaire', 'KONAN Marie', 25000000.00, NULL, 'BANQUE', 'AGC001', 'USR002', '2026-06-09 15:39:02', 'valide'),
('CAP003', 'Apport augmentation', 'TRAORE Ahmed', 15000000.00, NULL, 'BANQUE', 'AGC003', 'USR003', '2026-06-09 15:39:02', 'valide');

-- --------------------------------------------------------

--
-- Structure de la table `clients`
--

DROP TABLE IF EXISTS `clients`;
CREATE TABLE IF NOT EXISTS `clients` (
  `client_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `matricule` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `prenom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_naissance` date DEFAULT NULL,
  `lieu_naissance` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `genre` enum('Masculin','Feminin','Morale') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `profession` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `telephone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `adresse` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `categorie` enum('Personne physique','Entreprise','Association','Autres') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `situation_matrimoniale` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nationalite` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nom_conjoint` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `telephone_conjoint` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nombre_enfants` int DEFAULT '0',
  `nom_contact_urgence` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `telephone_contact_urgence` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `revenus_mensuels` decimal(15,2) DEFAULT '0.00',
  `source_revenus` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `milieu` enum('Rural','Semi-rural','Urbain') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `piece_identite` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `numero_piece` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `secteur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `agence_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nui_bceao` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Numéro Unique Identification BCEAO',
  `code_forme_juridique` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `statut` enum('actif','suspendu','ferme') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'actif',
  PRIMARY KEY (`client_id`),
  UNIQUE KEY `matricule` (`matricule`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `clients`
--

INSERT INTO `clients` (`client_id`, `matricule`, `nom`, `prenom`, `date_naissance`, `lieu_naissance`, `genre`, `profession`, `telephone`, `adresse`, `email`, `categorie`, `situation_matrimoniale`, `nationalite`, `nom_conjoint`, `telephone_conjoint`, `nombre_enfants`, `nom_contact_urgence`, `telephone_contact_urgence`, `revenus_mensuels`, `source_revenus`, `milieu`, `piece_identite`, `numero_piece`, `secteur_id`, `agence_id`, `nui_bceao`, `code_forme_juridique`, `utilisateur_id`, `date_creation`, `statut`) VALUES
('CLT001', 'M001', 'DIALLO', 'Amadou', '1985-06-15', 'Korhogo', 'Masculin', 'Commerçant', '0701010101', 'Abidjan Marcory, Rue 12', 'amadou.diallo@email.com', 'Personne physique', 'Marié', 'Ivoirienne', 'Diallo Aminata', '0701010102', 3, 'Diallo Mamadou', '0701010103', 500000.00, 'Commerce', 'Urbain', 'CNI', 'CNI0012345', 'SEC001', 'AGC001', NULL, NULL, 'USR001', '2024-01-10 00:00:00', 'actif'),
('CLT002', 'M002', 'KONE', 'Moussa', '1990-03-20', 'Bouaké', 'Masculin', 'Agriculteur', '0702020201', 'Bouaké, Quartier commercial', 'moussa.kone@email.com', 'Personne physique', 'Célibataire', 'Ivoirienne', NULL, NULL, 0, 'Kone Ibrahim', '0702020202', 300000.00, 'Agriculture', 'Rural', 'CNI', 'CNI0067890', 'SEC002', 'AGC003', NULL, NULL, 'USR003', '2024-01-15 00:00:00', 'actif'),
('CLT003', 'M003', 'FOFANA', 'Fatoumata', '1988-11-10', 'Séguela', 'Feminin', 'Tailleuse', '0703030301', 'Yopougon, Quartier Millionnaire', 'fatou.fofana@email.com', 'Personne physique', 'Veuf(ve)', 'Ivoirienne', NULL, NULL, 2, 'Fofana Sékou', '0703030302', 400000.00, 'Artisanat', 'Urbain', 'CNI', 'CNI0034567', 'SEC004', 'AGC002', NULL, NULL, 'USR002', '2024-02-01 00:00:00', 'actif'),
('CLT004', 'M004', 'KOUADIO', 'Jean-Claude', '1975-12-05', 'Abidjan', 'Masculin', 'Transporteur', '0704040401', 'Abidjan Treichville', 'jc.kouadio@email.com', 'Personne physique', 'Marié', 'Ivoirienne', 'Kouadio Solange', '0704040402', 4, 'Kouadio Paul', '0704040403', 600000.00, 'Transport', 'Urbain', 'Permis', 'P001234', 'SEC005', 'AGC001', NULL, NULL, 'USR001', '2024-02-15 00:00:00', 'actif'),
('CLT005', 'M005', 'BAMBA', 'Souleymane', '1995-07-25', 'Man', 'Masculin', 'Étudiant', '0705050501', 'Abidjan Cocody', 'souleymane.bamba@email.com', 'Personne physique', 'Célibataire', 'Ivoirienne', NULL, NULL, 0, 'Bamba Karim', '0705050502', 150000.00, 'Bourses', 'Urbain', 'CNI', 'CNI0098765', 'SEC006', 'AGC001', NULL, NULL, 'USR002', '2024-03-01 00:00:00', 'actif'),
('CLT006', 'M006', 'Coulibaly entreprise', NULL, NULL, NULL, 'Morale', 'Commerce', '0706060601', 'Abidjan Zone 4', 'contact@coulibaly.com', 'Entreprise', NULL, 'Ivoirienne', NULL, NULL, 0, 'Coulibaly Ibrahim', '0706060602', 2000000.00, 'Commerce', 'Urbain', 'Registre', 'RC00123456', 'SEC001', 'AGC001', NULL, NULL, 'USR001', '2024-03-15 00:00:00', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `commissions`
--

DROP TABLE IF EXISTS `commissions`;
CREATE TABLE IF NOT EXISTS `commissions` (
  `commission_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `agent_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `type_commission` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `montant_base` decimal(15,2) NOT NULL,
  `taux_commission` decimal(8,2) NOT NULL,
  `montant_commission` decimal(15,2) NOT NULL,
  `transaction_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_commission` date NOT NULL,
  `date_paiement` datetime DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `statut` enum('en_attente','payee','annulee') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'en_attente',
  PRIMARY KEY (`commission_id`),
  KEY `agent_id` (`agent_id`),
  KEY `transaction_id` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `commissions`
--

INSERT INTO `commissions` (`commission_id`, `agent_id`, `type_commission`, `montant_base`, `taux_commission`, `montant_commission`, `transaction_id`, `date_commission`, `date_paiement`, `description`, `date_creation`, `statut`) VALUES
('COM001', 'USR001', 'Crédit octroyé', 1000000.00, 1.00, 10000.00, 'TRX001', '2024-03-29', NULL, 'Commission octroi crédit DOS001', '2026-06-09 15:39:02', 'en_attente'),
('COM002', 'USR004', 'Transaction caisse', 95000.00, 0.50, 475.00, 'TRX001', '2024-03-29', NULL, 'Commission encaissement', '2026-06-09 15:39:02', 'en_attente');

-- --------------------------------------------------------

--
-- Structure de la table `comptes`
--

DROP TABLE IF EXISTS `comptes`;
CREATE TABLE IF NOT EXISTS `comptes` (
  `compte_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `numero_compte` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `client_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nom_compte` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `produit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `agence_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `solde` decimal(15,2) DEFAULT '0.00',
  `solde_bloque` decimal(15,2) DEFAULT '0.00',
  `devise` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'F.CFA',
  `date_ouverture` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_fermeture` datetime DEFAULT NULL,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Gestionnaire du compte',
  `statut` enum('actif','bloque','ferme','attente') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'actif',
  PRIMARY KEY (`compte_id`),
  UNIQUE KEY `numero_compte` (`numero_compte`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `comptes`
--

INSERT INTO `comptes` (`compte_id`, `numero_compte`, `client_id`, `nom_compte`, `produit_id`, `agence_id`, `solde`, `solde_bloque`, `devise`, `date_ouverture`, `date_fermeture`, `utilisateur_id`, `statut`) VALUES
('CPT001', '1234567890', 'CLT001', 'Diallo Amadou CC', 'PROD002', 'AGC001', 250000.00, 0.00, 'F.CFA', '2024-01-10 00:00:00', NULL, 'USR001', 'actif'),
('CPT002', '1234567891', 'CLT001', 'Diallo Amadou EP', 'PROD001', 'AGC001', 500000.00, 0.00, 'F.CFA', '2024-01-10 00:00:00', NULL, 'USR001', 'actif'),
('CPT003', '2234567890', 'CLT002', 'Kone Moussa CC', 'PROD002', 'AGC003', 150000.00, 0.00, 'F.CFA', '2024-01-15 00:00:00', NULL, 'USR003', 'actif'),
('CPT004', '3234567890', 'CLT003', 'Fofana Fatoumata CC', 'PROD002', 'AGC002', 300000.00, 0.00, 'F.CFA', '2024-02-01 00:00:00', NULL, 'USR002', 'actif'),
('CPT005', '4234567890', 'CLT004', 'Kouadio Jean-Claude CC', 'PROD002', 'AGC001', 750000.00, 100000.00, 'F.CFA', '2024-02-15 00:00:00', NULL, 'USR001', 'actif'),
('CPT006', '5234567890', 'CLT005', 'Bamba Souleymane EP', 'PROD001', 'AGC001', 50000.00, 0.00, 'F.CFA', '2024-03-01 00:00:00', NULL, 'USR002', 'actif'),
('CPT007', '6234567890', 'CLT006', 'Coulibaly entreprise CC', 'PROD002', 'AGC001', 1500000.00, 250000.00, 'F.CFA', '2024-03-15 00:00:00', NULL, 'USR001', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `comptes_dat`
--

DROP TABLE IF EXISTS `comptes_dat`;
CREATE TABLE IF NOT EXISTS `comptes_dat` (
  `id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `compte_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `capital_initial` decimal(18,4) NOT NULL,
  `montant_place` decimal(18,4) NOT NULL,
  `taux_applique` decimal(8,4) NOT NULL,
  `devise` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'XOF',
  `date_ouverture` date NOT NULL,
  `date_echeance` date NOT NULL,
  `duree_jours` int NOT NULL,
  `interets_acquis` decimal(18,4) DEFAULT '0.0000',
  `interets_paye` decimal(18,4) DEFAULT '0.0000',
  `date_dernier_calcul` date DEFAULT NULL,
  `renouvellement_auto` enum('oui','non') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nombre_renouvellements` int DEFAULT '0',
  `date_rupture` date DEFAULT NULL,
  `penalite_rupture` decimal(18,4) DEFAULT '0.0000',
  `motif_rupture` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `statut` enum('en cours','echeance','renouvelle','rupture','cloture') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'en cours',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `comptes_dat`
--

INSERT INTO `comptes_dat` (`id`, `compte_id`, `capital_initial`, `montant_place`, `taux_applique`, `devise`, `date_ouverture`, `date_echeance`, `duree_jours`, `interets_acquis`, `interets_paye`, `date_dernier_calcul`, `renouvellement_auto`, `nombre_renouvellements`, `date_rupture`, `penalite_rupture`, `motif_rupture`, `date_creation`, `statut`) VALUES
('DAT001', 'CPT002', 500000.0000, 500000.0000, 5.5000, 'XOF', '2024-01-10', '2024-07-10', 180, 13750.0000, 0.0000, '2024-03-31', 'non', 0, NULL, 0.0000, NULL, '2026-06-09 15:39:02', 'en cours');

-- --------------------------------------------------------

--
-- Structure de la table `comptes_types`
--

DROP TABLE IF EXISTS `comptes_types`;
CREATE TABLE IF NOT EXISTS `comptes_types` (
  `type_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nom_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`type_id`),
  UNIQUE KEY `nom_type` (`nom_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `comptes_types`
--

INSERT INTO `comptes_types` (`type_id`, `nom_type`, `description`, `statut`) VALUES
('TYPC001', 'Compte courant', 'Compte de dépôt et retrait classique', 'actif'),
('TYPC002', 'Compte épargne', 'Compte rémunéré avec conditions', 'actif'),
('TYPC003', 'Compte blocage', 'Compte avec fonds bloqués', 'actif'),
('TYPC004', 'Compte garantie', 'Compte pour garantie crédit', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `documents`
--

DROP TABLE IF EXISTS `documents`;
CREATE TABLE IF NOT EXISTS `documents` (
  `contrat_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `titre` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `modele` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `contenu` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`contrat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `documents`
--

INSERT INTO `documents` (`contrat_id`, `titre`, `modele`, `contenu`, `statut`) VALUES
('DOC001', 'Contrat crédit microfinance', 'credit_v1', 'MODELE: Contrat de crédit n°{{numero}}', 'actif'),
('DOC002', 'Contrat DAT', 'dat_v1', 'MODELE: Dépôt à terme n°{{numero}}', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `dossiers`
--

DROP TABLE IF EXISTS `dossiers`;
CREATE TABLE IF NOT EXISTS `dossiers` (
  `dossier_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `compte_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `produit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `montant` decimal(15,6) NOT NULL,
  `frequence` enum('unique','jour','semaine','mois','annee') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'mois',
  `duree` int NOT NULL,
  `periode_exclus` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Jours exclus (ex: sam,dim)',
  `date_octroi` date NOT NULL,
  `date_debut_remboursement` date DEFAULT NULL,
  `date_premiere_echeance` date NOT NULL,
  `date_derniere_echeance` date NOT NULL,
  `objet` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nombre_echeance` int DEFAULT '0',
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `agence_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('demande','approuve','actif','rembourse','impaye') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'demande',
  PRIMARY KEY (`dossier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `dossiers`
--

INSERT INTO `dossiers` (`dossier_id`, `compte_id`, `produit_id`, `montant`, `frequence`, `duree`, `periode_exclus`, `date_octroi`, `date_debut_remboursement`, `date_premiere_echeance`, `date_derniere_echeance`, `objet`, `nombre_echeance`, `date_creation`, `agence_id`, `utilisateur_id`, `statut`) VALUES
('DOS001', 'CPT001', 'PROD003', 1000000.000000, 'mois', 12, NULL, '2024-02-01', '2024-03-01', '2024-03-01', '2025-02-01', 'Achat marchandises', 12, '2024-01-25 00:00:00', 'AGC001', 'USR001', 'actif'),
('DOS002', 'CPT003', 'PROD004', 500000.000000, 'mois', 6, NULL, '2024-02-15', '2024-03-15', '2024-03-15', '2024-08-15', 'Achat intrants agricoles', 6, '2024-02-10 00:00:00', 'AGC003', 'USR003', 'actif'),
('DOS003', 'CPT004', 'PROD004', 750000.000000, 'mois', 8, NULL, '2024-03-01', '2024-04-01', '2024-04-01', '2024-11-01', 'Achat machine à coudre', 8, '2024-02-20 00:00:00', 'AGC002', 'USR002', 'actif'),
('DOS004', 'CPT005', 'PROD003', 2000000.000000, 'mois', 18, NULL, '2024-03-15', '2024-04-15', '2024-04-15', '2025-09-15', 'Achat véhicule', 18, '2024-03-05 00:00:00', 'AGC001', 'USR001', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `echeances`
--

DROP TABLE IF EXISTS `echeances`;
CREATE TABLE IF NOT EXISTS `echeances` (
  `echeance_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `dossier_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `numero_echeance` int NOT NULL,
  `type_echeance_id` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_echeance` date NOT NULL,
  `montant` decimal(15,6) NOT NULL,
  `statut` enum('attente','payee','impayee') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'attente',
  PRIMARY KEY (`echeance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `echeances`
--

INSERT INTO `echeances` (`echeance_id`, `dossier_id`, `numero_echeance`, `type_echeance_id`, `date_echeance`, `montant`, `statut`) VALUES
('ECH001', 'DOS001', 1, 'TYPECH003', '2024-03-01', 95000.000000, 'payee'),
('ECH002', 'DOS001', 2, 'TYPECH003', '2024-04-01', 95000.000000, 'payee'),
('ECH003', 'DOS001', 3, 'TYPECH003', '2024-05-01', 95000.000000, 'attente'),
('ECH004', 'DOS002', 1, 'TYPECH003', '2024-03-15', 88000.000000, 'payee'),
('ECH005', 'DOS002', 2, 'TYPECH003', '2024-04-15', 88000.000000, 'attente'),
('ECH006', 'DOS003', 1, 'TYPECH003', '2024-04-01', 105000.000000, 'attente');

-- --------------------------------------------------------

--
-- Structure de la table `echeances_types`
--

DROP TABLE IF EXISTS `echeances_types`;
CREATE TABLE IF NOT EXISTS `echeances_types` (
  `type_echeance_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nom` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`type_echeance_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `echeances_types`
--

INSERT INTO `echeances_types` (`type_echeance_id`, `nom`, `statut`) VALUES
('TYPECH001', 'Capital', 'actif'),
('TYPECH002', 'Intérêt', 'actif'),
('TYPECH003', 'Capital + Intérêt', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `ecritures_comptables`
--

DROP TABLE IF EXISTS `ecritures_comptables`;
CREATE TABLE IF NOT EXISTS `ecritures_comptables` (
  `ecriture_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `numero_ecriture` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `numero_piece` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_ecriture` date NOT NULL,
  `libelle_ecriture` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `montant_debit` decimal(15,2) DEFAULT '0.00',
  `montant_credit` decimal(15,2) DEFAULT '0.00',
  `compte_general` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `compte_tiers` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `transaction_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `code_journal` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `exercice` char(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci GENERATED ALWAYS AS (year(`date_ecriture`)) STORED,
  `periode` char(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci GENERATED ALWAYS AS (concat(year(`date_ecriture`),lpad(month(`date_ecriture`),2,_utf8mb4'0'))) STORED,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `statut` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`ecriture_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `ecritures_comptables`
--

INSERT INTO `ecritures_comptables` (`ecriture_id`, `numero_ecriture`, `numero_piece`, `date_ecriture`, `libelle_ecriture`, `montant_debit`, `montant_credit`, `compte_general`, `compte_tiers`, `transaction_id`, `code_journal`, `utilisateur_id`, `statut`) VALUES
('ECR001', 'ECR202400001', 'TRX002', '2024-03-29', 'Dépôt espèces Diallo Amadou', 0.00, 100000.00, '571', 'CPT001', 'TRX002', 'CAISSE', 'USR004', 'VALIDÉE'),
('ECR002', 'ECR202400001', 'TRX002', '2024-03-29', 'Dépôt espèces Diallo Amadou (contrepartie)', 100000.00, 0.00, '521', 'CPT001', 'TRX002', 'CAISSE', 'USR004', 'VALIDÉE'),
('ECR003', 'ECR202400002', 'TRX001', '2024-03-29', 'Remboursement crédit DOS001', 0.00, 95000.00, '571', 'CPT001', 'TRX001', 'CAISSE', 'USR004', 'VALIDÉE'),
('ECR004', 'ECR202400002', 'TRX001', '2024-03-29', 'Remboursement crédit DOS001 (contrepartie)', 95000.00, 0.00, '521', 'CPT001', 'TRX001', 'CAISSE', 'USR004', 'VALIDÉE'),
('ECR005', 'ECR202400003', 'TRX003', '2024-03-29', 'Retrait espèces Kouadio Jean-Claude', 75000.00, 0.00, '571', 'CPT005', 'TRX003', 'CAISSE', 'USR004', 'VALIDÉE'),
('ECR006', 'ECR202400003', 'TRX003', '2024-03-29', 'Retrait espèces Kouadio Jean-Claude (contrepartie)', 0.00, 75000.00, '521', 'CPT005', 'TRX003', 'CAISSE', 'USR004', 'VALIDÉE');

-- --------------------------------------------------------

--
-- Structure de la table `frais_comptes`
--

DROP TABLE IF EXISTS `frais_comptes`;
CREATE TABLE IF NOT EXISTS `frais_comptes` (
  `id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nom` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `type_compte_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `type` enum('fixe','pourcentage') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `valeur` decimal(10,2) DEFAULT NULL,
  `famille_operation_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `operation_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `priorite` int DEFAULT NULL,
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nom` (`nom`,`type_compte_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `frais_comptes`
--

INSERT INTO `frais_comptes` (`id`, `nom`, `type_compte_id`, `type`, `valeur`, `famille_operation_id`, `operation_id`, `priorite`, `statut`) VALUES
('FRC001', 'Frais tenue CC', 'TYPC001', 'fixe', 1000.00, 'FOP005', 'OP007', 1, 'actif'),
('FRC002', 'Frais tenue EP', 'TYPC002', 'fixe', 500.00, 'FOP005', 'OP007', 1, 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `frais_produits`
--

DROP TABLE IF EXISTS `frais_produits`;
CREATE TABLE IF NOT EXISTS `frais_produits` (
  `id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nom` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `produit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `type` enum('fixe','pourcentage') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `valeur` decimal(10,2) DEFAULT NULL,
  `famille_operation_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `operation_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `priorite` int DEFAULT NULL,
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nom` (`nom`,`produit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `frais_produits`
--

INSERT INTO `frais_produits` (`id`, `nom`, `produit_id`, `type`, `valeur`, `famille_operation_id`, `operation_id`, `priorite`, `statut`) VALUES
('FRAIS001', 'Frais dossier crédit', 'PROD003', 'pourcentage', 2.00, 'FOP004', 'OP005', 1, 'actif'),
('FRAIS002', 'Frais de tenue compte', 'PROD002', 'fixe', 1000.00, 'FOP005', 'OP007', 1, 'actif'),
('FRAIS003', 'Frais d\'ouverture', 'PROD002', 'fixe', 5000.00, 'FOP005', 'OP008', 1, 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `garanties`
--

DROP TABLE IF EXISTS `garanties`;
CREATE TABLE IF NOT EXISTS `garanties` (
  `garantie_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `credit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `code_type_garantie` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '01=Hypoth,02=Nantis,03=Caution,04=Gage,05=Autre',
  `libelle_garantie` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `valeur_brute` decimal(15,2) NOT NULL DEFAULT '0.00',
  `valeur_nette` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'Valeur réalisable nette',
  `date_evaluation` date NOT NULL,
  `date_expiration` date DEFAULT NULL,
  `notaire` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `numero_acte` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `valeur_nette_realisable` decimal(15,2) GENERATED ALWAYS AS ((`valeur_nette` * 0.7)) STORED,
  `code_garantie_bceao` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `statut` enum('actif','expiré','libéré','réalisé') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'actif',
  PRIMARY KEY (`garantie_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Garanties reçues sur crédits — État BCEAO 6030';

--
-- Déchargement des données de la table `garanties`
--

INSERT INTO `garanties` (`garantie_id`, `credit_id`, `code_type_garantie`, `libelle_garantie`, `valeur_brute`, `valeur_nette`, `date_evaluation`, `date_expiration`, `notaire`, `numero_acte`, `code_garantie_bceao`, `date_creation`, `statut`) VALUES
('GAR001', 'DOS001', '02', 'Nantissement véhicule', 1500000.00, 1200000.00, '2024-01-20', '2025-02-01', 'Maître KONE', 'ACTE001234', NULL, '2026-06-09 15:39:02', 'actif'),
('GAR002', 'DOS002', '03', 'Caution solidaire - Kone Ibrahim', 500000.00, 500000.00, '2024-02-10', '2024-08-15', NULL, NULL, NULL, '2026-06-09 15:39:02', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `immobilisations`
--

DROP TABLE IF EXISTS `immobilisations`;
CREATE TABLE IF NOT EXISTS `immobilisations` (
  `immobilisation_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type_immobilisation` enum('Immobilisations corporelles','Immobilisations incorporelles','Immobilisations financières') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_achat` date NOT NULL,
  `libelle` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `montant_achat` decimal(15,6) NOT NULL,
  `duree_mois_vie` int NOT NULL,
  `amortissement_mensuel` decimal(15,6) NOT NULL,
  `nombre_mois_vie` int NOT NULL,
  `amortissement_total` decimal(15,6) NOT NULL,
  `valeur_nette` decimal(15,6) NOT NULL,
  `agence_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_creation` date NOT NULL,
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`immobilisation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `immobilisations`
--

INSERT INTO `immobilisations` (`immobilisation_id`, `type_immobilisation`, `date_achat`, `libelle`, `montant_achat`, `duree_mois_vie`, `amortissement_mensuel`, `nombre_mois_vie`, `amortissement_total`, `valeur_nette`, `agence_id`, `date_creation`, `statut`) VALUES
('IMM001', 'Immobilisations corporelles', '2023-01-15', 'Ordinateur Dell XPS', 1500000.000000, 36, 41666.667000, 15, 625000.000000, 875000.000000, 'AGC001', '2023-01-15', 'actif'),
('IMM002', 'Immobilisations corporelles', '2023-02-10', 'Imprimante HP LaserJet', 500000.000000, 36, 13888.889000, 14, 194444.440000, 305555.560000, 'AGC001', '2023-02-10', 'actif'),
('IMM003', 'Immobilisations corporelles', '2023-03-05', 'Mobilier de bureau', 2000000.000000, 60, 33333.333000, 13, 433333.330000, 1566666.670000, 'AGC001', '2023-03-05', 'actif'),
('IMM004', 'Immobilisations incorporelles', '2023-04-20', 'Logiciel de gestion', 3000000.000000, 24, 125000.000000, 12, 1500000.000000, 1500000.000000, 'AGC001', '2023-04-20', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `journal_comptable`
--

DROP TABLE IF EXISTS `journal_comptable`;
CREATE TABLE IF NOT EXISTS `journal_comptable` (
  `code_journal` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `libelle_journal` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type_journal` enum('CAISSE','BANQUE','ACHATS','VENTES','OD','PAIE','IMMOBILISATION','AUTRE') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `statut` enum('ACTIF','INACTIF') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'ACTIF',
  PRIMARY KEY (`code_journal`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `journal_comptable`
--

INSERT INTO `journal_comptable` (`code_journal`, `libelle_journal`, `type_journal`, `statut`) VALUES
('CAISSE', 'Journal de Caisse', 'CAISSE', 'ACTIF'),
('BANQUE', 'Journal de Banque', 'BANQUE', 'ACTIF'),
('OD', 'Journal des Opérations Diverses', 'OD', 'ACTIF'),
('CREDIT', 'Journal des Crédits', 'AUTRE', 'ACTIF');

-- --------------------------------------------------------

--
-- Structure de la table `journees_caisse`
--

DROP TABLE IF EXISTS `journees_caisse`;
CREATE TABLE IF NOT EXISTS `journees_caisse` (
  `id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `caisse_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `journee_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_journee` date NOT NULL,
  `date_ouverture` datetime NOT NULL,
  `date_fermeture` datetime DEFAULT NULL,
  `solde_ouverture` decimal(15,2) NOT NULL,
  `solde_theorique` decimal(15,2) DEFAULT NULL COMMENT 'Calculé : ouverture + entrées - sorties',
  `solde_physique` decimal(15,2) DEFAULT NULL COMMENT 'Compté physiquement par le guichetier',
  `total_entrees` decimal(15,2) DEFAULT '0.00',
  `total_sorties` decimal(15,2) DEFAULT '0.00',
  `ecart_solde` decimal(15,2) DEFAULT '0.00',
  `nombre_transactions` int NOT NULL DEFAULT '0',
  `agence_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `id_utilisateur_ouverture` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `id_utilisateur_fermeture` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `observations` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `statut` enum('ouverte','fermee') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `journees_caisse`
--

INSERT INTO `journees_caisse` (`id`, `caisse_id`, `journee_id`, `date_journee`, `date_ouverture`, `date_fermeture`, `solde_ouverture`, `solde_theorique`, `solde_physique`, `total_entrees`, `total_sorties`, `ecart_solde`, `nombre_transactions`, `agence_id`, `id_utilisateur_ouverture`, `id_utilisateur_fermeture`, `observations`, `statut`) VALUES
('JCA001', 'CAI001', 'JRN001', '2024-03-29', '2024-03-29 08:00:00', '2024-03-29 17:30:00', 15000000.00, 15500000.00, 15500000.00, 1250000.00, 750000.00, 0.00, 25, 'AGC001', 'USR001', 'USR004', NULL, 'fermee');

-- --------------------------------------------------------

--
-- Structure de la table `journees_travail`
--

DROP TABLE IF EXISTS `journees_travail`;
CREATE TABLE IF NOT EXISTS `journees_travail` (
  `journee_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_journee` date NOT NULL,
  `date_ouverture` datetime NOT NULL,
  `date_fermeture` datetime DEFAULT NULL,
  `solde_initial` decimal(15,2) NOT NULL,
  `solde_final` decimal(15,2) DEFAULT '0.00',
  `total_entrees` decimal(15,2) DEFAULT '0.00',
  `total_sorties` decimal(15,2) DEFAULT '0.00',
  `ecart_solde` decimal(15,2) DEFAULT '0.00',
  `agence_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `id_utilisateur_ouverture` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `id_utilisateur_fermeture` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `observations` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `statut` enum('ouverte','fermee') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`journee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `journees_travail`
--

INSERT INTO `journees_travail` (`journee_id`, `date_journee`, `date_ouverture`, `date_fermeture`, `solde_initial`, `solde_final`, `total_entrees`, `total_sorties`, `ecart_solde`, `agence_id`, `id_utilisateur_ouverture`, `id_utilisateur_fermeture`, `observations`, `statut`) VALUES
('JRN001', '2024-03-29', '2024-03-29 08:00:00', '2024-03-29 17:30:00', 15000000.00, 15500000.00, 1250000.00, 750000.00, 0.00, 'AGC001', 'USR001', 'USR004', NULL, 'fermee'),
('JRN002', '2024-03-30', '2024-03-30 08:00:00', NULL, 15500000.00, NULL, 0.00, 0.00, 0.00, 'AGC001', 'USR004', NULL, NULL, 'ouverte');

-- --------------------------------------------------------

--
-- Structure de la table `mouvements_caisse`
--

DROP TABLE IF EXISTS `mouvements_caisse`;
CREATE TABLE IF NOT EXISTS `mouvements_caisse` (
  `mouvement_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `caisse_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `journee_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `agence_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_mouvement` date NOT NULL,
  `heure_mouvement` time NOT NULL,
  `type_mouvement` enum('Ouverture caisse','Fermeture caisse','Alimentation caisse','Retrait caisse','Transfert entre caisses','Depot client','Retrait client','Paiement fournisseur','Frais bancaires','Ajustement','Décaissement crédit','Remboursement crédit') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `montant` decimal(15,2) NOT NULL,
  `sens_mouvement` enum('ENTREE','SORTIE') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `solde_avant` decimal(15,2) NOT NULL,
  `solde_apres` decimal(15,2) NOT NULL,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `transaction_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `statut` enum('Validé','Rejeté','En attente') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`mouvement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `mouvements_caisse`
--

INSERT INTO `mouvements_caisse` (`mouvement_id`, `caisse_id`, `journee_id`, `agence_id`, `date_mouvement`, `heure_mouvement`, `type_mouvement`, `montant`, `sens_mouvement`, `solde_avant`, `solde_apres`, `utilisateur_id`, `transaction_id`, `description`, `date_creation`, `statut`) VALUES
('MC001', 'CAI001', 'JRN001', 'AGC001', '2024-03-29', '09:15:00', 'Depot client', 100000.00, 'ENTREE', 15000000.00, 15100000.00, 'USR004', 'TRX002', 'Dépôt Diallo Amadou', '2026-06-09 15:39:02', 'Validé'),
('MC002', 'CAI001', 'JRN001', 'AGC001', '2024-03-29', '10:30:00', 'Remboursement crédit', 95000.00, 'ENTREE', 15100000.00, 15195000.00, 'USR004', 'TRX001', 'Remboursement crédit DOS001', '2026-06-09 15:39:02', 'Validé'),
('MC003', 'CAI001', 'JRN001', 'AGC001', '2024-03-29', '14:20:00', 'Retrait client', 75000.00, 'SORTIE', 15195000.00, 15120000.00, 'USR004', 'TRX003', 'Retrait Kouadio Jean-Claude', '2026-06-09 15:39:02', 'Validé');

-- --------------------------------------------------------

--
-- Structure de la table `operations`
--

DROP TABLE IF EXISTS `operations`;
CREATE TABLE IF NOT EXISTS `operations` (
  `operation_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `famille_operation_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `libelle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `sens` enum('DEBIT','CREDIT','MIXTE') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'MIXTE',
  `impact_solde` enum('AUGMENTE','DIMINUE','AUCUN') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'AUCUN',
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'actif',
  PRIMARY KEY (`operation_id`),
  KEY `fk_operation_famille` (`famille_operation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `operations`
--

INSERT INTO `operations` (`operation_id`, `famille_operation_id`, `libelle`, `sens`, `impact_solde`, `statut`) VALUES
('OP001', 'FOP001', 'Dépôt espèces', 'CREDIT', 'AUGMENTE', 'actif'),
('OP002', 'FOP002', 'Retrait espèces', 'DEBIT', 'DIMINUE', 'actif'),
('OP003', 'FOP003', 'Virement reçu', 'CREDIT', 'AUGMENTE', 'actif'),
('OP004', 'FOP003', 'Virement émis', 'DEBIT', 'DIMINUE', 'actif'),
('OP005', 'FOP004', 'Décaissement crédit', 'CREDIT', 'AUGMENTE', 'actif'),
('OP006', 'FOP004', 'Remboursement crédit', 'DEBIT', 'DIMINUE', 'actif'),
('OP007', 'FOP005', 'Frais de tenue', 'DEBIT', 'DIMINUE', 'actif'),
('OP008', 'FOP005', 'Frais d\'ouverture', 'DEBIT', 'DIMINUE', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `operations_familles`
--

DROP TABLE IF EXISTS `operations_familles`;
CREATE TABLE IF NOT EXISTS `operations_familles` (
  `famille_operation_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `libelle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'actif',
  PRIMARY KEY (`famille_operation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Déchargement des données de la table `operations_familles`
--

INSERT INTO `operations_familles` (`famille_operation_id`, `libelle`, `statut`) VALUES
('FOP001', 'Dépôts', 'actif'),
('FOP002', 'Retraits', 'actif'),
('FOP003', 'Virements', 'actif'),
('FOP004', 'Crédits', 'actif'),
('FOP005', 'Frais et commissions', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE IF NOT EXISTS `permissions` (
  `permission_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `module` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `page` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `description` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`permission_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `permissions`
--

INSERT INTO `permissions` (`permission_id`, `module`, `page`, `description`) VALUES
('PERM001', 'CLIENT', 'client_liste', 'Voir la liste des clients'),
('PERM002', 'CLIENT', 'client_creer', 'Créer un client'),
('PERM003', 'COMPTE', 'compte_creer', 'Créer un compte'),
('PERM004', 'CREDIT', 'credit_creer', 'Créer un crédit'),
('PERM005', 'TRANSACTION', 'transaction_valider', 'Valider une transaction'),
('PERM006', 'CAISSE', 'caisse_ouvrir', 'Ouvrir une caisse');

-- --------------------------------------------------------

--
-- Structure de la table `plan_comptables`
--

DROP TABLE IF EXISTS `plan_comptables`;
CREATE TABLE IF NOT EXISTS `plan_comptables` (
  `numero_compte` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `libelle_compte` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type_compte` enum('ACTIF','PASSIF','CHARGE','PRODUIT') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `classe_compte` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nature_compte` enum('DETAIL','TOTAL','TITRE') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'DETAIL',
  `compte_collectif` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sens_normal` enum('DEBIT','CREDIT') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `statut_compte` enum('ACTIF','INACTIF') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'ACTIF',
  PRIMARY KEY (`numero_compte`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `plan_comptables`
--

INSERT INTO `plan_comptables` (`numero_compte`, `libelle_compte`, `type_compte`, `classe_compte`, `nature_compte`, `compte_collectif`, `sens_normal`, `statut_compte`) VALUES
('10', 'Capital', 'PASSIF', '1', 'TOTAL', NULL, 'CREDIT', 'ACTIF'),
('101', 'Capital social', 'PASSIF', '1', 'TOTAL', '10', 'CREDIT', 'ACTIF'),
('1011', 'Capital souscrit appelé', 'PASSIF', '1', 'DETAIL', '101', 'CREDIT', 'ACTIF'),
('20', 'Immobilisations incorporelles', 'ACTIF', '2', 'TOTAL', NULL, 'DEBIT', 'ACTIF'),
('21', 'Immobilisations corporelles', 'ACTIF', '2', 'TOTAL', NULL, 'DEBIT', 'ACTIF'),
('31', 'Marchandises', 'ACTIF', '3', 'TOTAL', NULL, 'DEBIT', 'ACTIF'),
('411', 'Clients', 'ACTIF', '4', 'DETAIL', '41', 'DEBIT', 'ACTIF'),
('521', 'Banques', 'ACTIF', '5', 'DETAIL', '52', 'DEBIT', 'ACTIF'),
('571', 'Caisse', 'ACTIF', '5', 'DETAIL', '57', 'DEBIT', 'ACTIF'),
('631', 'Intérêts bancaires', 'CHARGE', '6', 'DETAIL', '63', 'DEBIT', 'ACTIF'),
('701', 'Ventes de marchandises', 'PRODUIT', '7', 'DETAIL', '70', 'CREDIT', 'ACTIF');

-- --------------------------------------------------------

--
-- Structure de la table `produits`
--

DROP TABLE IF EXISTS `produits`;
CREATE TABLE IF NOT EXISTS `produits` (
  `produit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nom_produit` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type_compte_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `famille_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'actif',
  PRIMARY KEY (`produit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `produits`
--

INSERT INTO `produits` (`produit_id`, `nom_produit`, `type_compte_id`, `famille_id`, `statut`) VALUES
('PROD001', 'Épargne POP', 'TYPC002', 'FAM001', 'actif'),
('PROD002', 'Compte courant SIMPLE', 'TYPC001', 'FAM001', 'actif'),
('PROD003', 'Crédit PME', NULL, 'FAM002', 'actif'),
('PROD004', 'Crédit Micro entreprise', NULL, 'FAM002', 'actif'),
('PROD005', 'DAT 6 mois', 'TYPC003', 'FAM003', 'actif'),
('PROD006', 'DAT 12 mois', 'TYPC003', 'FAM003', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `produits_familles`
--

DROP TABLE IF EXISTS `produits_familles`;
CREATE TABLE IF NOT EXISTS `produits_familles` (
  `famille_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `categorie` enum('Epargne','Credit','Tontine','Microassurance','DAT') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`famille_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `produits_familles`
--

INSERT INTO `produits_familles` (`famille_id`, `nom`, `categorie`, `description`, `statut`) VALUES
('FAM001', 'Épargne classique', 'Epargne', 'Produit d\'épargne standard', 'actif'),
('FAM002', 'Crédit classique', 'Credit', 'Crédit standard', 'actif'),
('FAM003', 'DAT', 'DAT', 'Dépôt à terme', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `provisions`
--

DROP TABLE IF EXISTS `provisions`;
CREATE TABLE IF NOT EXISTS `provisions` (
  `provision_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `credit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `type_provision` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `montant` decimal(15,2) NOT NULL,
  `taux_provision` decimal(8,2) NOT NULL,
  `date_provision` date NOT NULL,
  `date_echeance` date DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `statut` enum('actif','inactif','utilise') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'actif',
  PRIMARY KEY (`provision_id`),
  KEY `credit_id` (`credit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `provisions`
--

INSERT INTO `provisions` (`provision_id`, `credit_id`, `type_provision`, `montant`, `taux_provision`, `date_provision`, `date_echeance`, `description`, `date_creation`, `statut`) VALUES
('PROV001', 'DOS001', 'Provision réglementée', 50000.00, 5.00, '2024-03-31', '2024-04-30', 'Provision crédit DOS001', '2026-06-09 15:39:02', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `regles_produits`
--

DROP TABLE IF EXISTS `regles_produits`;
CREATE TABLE IF NOT EXISTS `regles_produits` (
  `regle_produit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nom` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `produit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `famille_operation_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `operation_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `montant_minimum` decimal(15,2) DEFAULT NULL,
  `montant_maximum` decimal(15,2) DEFAULT NULL,
  `duree_minimum` int DEFAULT NULL,
  `duree_maximum` int DEFAULT NULL,
  `frequence` enum('unique','jour','semaine','mois','annee') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`regle_produit_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `regles_produits`
--

INSERT INTO `regles_produits` (`regle_produit_id`, `nom`, `produit_id`, `famille_operation_id`, `operation_id`, `montant_minimum`, `montant_maximum`, `duree_minimum`, `duree_maximum`, `frequence`, `statut`) VALUES
('REG001', 'Règle crédit PME', 'PROD003', 'FOP004', 'OP005', 500000.00, 5000000.00, 3, 24, 'mois', 'actif'),
('REG002', 'Règle crédit Micro', 'PROD004', 'FOP004', 'OP005', 100000.00, 1000000.00, 1, 12, 'mois', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `regles_produits_taux`
--

DROP TABLE IF EXISTS `regles_produits_taux`;
CREATE TABLE IF NOT EXISTS `regles_produits_taux` (
  `regle_produit_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `regle_taux_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`regle_produit_id`,`regle_taux_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `regles_produits_taux`
--

INSERT INTO `regles_produits_taux` (`regle_produit_id`, `regle_taux_id`) VALUES
('REG001', 'RT001'),
('REG001', 'RT003'),
('REG002', 'RT001'),
('REG002', 'RT003');

-- --------------------------------------------------------

--
-- Structure de la table `regles_taux`
--

DROP TABLE IF EXISTS `regles_taux`;
CREATE TABLE IF NOT EXISTS `regles_taux` (
  `regle_taux_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `type` enum('fixe','pourcentage') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `valeur` decimal(10,4) DEFAULT NULL,
  `frequence` enum('unique','jour','semaine','mois','annee') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `type_echeance_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`regle_taux_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `regles_taux`
--

INSERT INTO `regles_taux` (`regle_taux_id`, `nom`, `type`, `valeur`, `frequence`, `type_echeance_id`, `statut`) VALUES
('RT001', 'Taux crédit standard', 'pourcentage', 1.5000, 'mois', 'TYPECH002', 'actif'),
('RT002', 'Taux épargne', 'pourcentage', 0.5000, 'annee', NULL, 'actif'),
('RT003', 'Pénalité retard', 'pourcentage', 5.0000, 'mois', NULL, 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `roles`
--

DROP TABLE IF EXISTS `roles`;
CREATE TABLE IF NOT EXISTS `roles` (
  `role_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nom_role` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`role_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `roles`
--

INSERT INTO `roles` (`role_id`, `nom_role`, `description`, `statut`) VALUES
('ROL001', 'SUPERVISEUR', 'Superviseur général - tous droits', 'actif'),
('ROL002', 'ADMINISTRATEUR', 'Administrateur système', 'actif'),
('ROL003', 'COMPTABLE', 'Gestion comptable', 'actif'),
('ROL004', 'GESTIONNAIRE', 'Gestion des opérations courantes', 'actif'),
('ROL005', 'CAISSE', 'Opérations de caisse uniquement', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `roles_permissions`
--

DROP TABLE IF EXISTS `roles_permissions`;
CREATE TABLE IF NOT EXISTS `roles_permissions` (
  `role_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `permission_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `cree_le` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`role_id`,`permission_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `roles_permissions`
--

INSERT INTO `roles_permissions` (`role_id`, `permission_id`, `cree_le`, `statut`) VALUES
('ROL001', 'PERM001', '2026-06-09 15:39:02', 'actif'),
('ROL001', 'PERM002', '2026-06-09 15:39:02', 'actif'),
('ROL001', 'PERM003', '2026-06-09 15:39:02', 'actif'),
('ROL001', 'PERM004', '2026-06-09 15:39:02', 'actif'),
('ROL001', 'PERM005', '2026-06-09 15:39:02', 'actif'),
('ROL001', 'PERM006', '2026-06-09 15:39:02', 'actif'),
('ROL002', 'PERM001', '2026-06-09 15:39:02', 'actif'),
('ROL002', 'PERM002', '2026-06-09 15:39:02', 'actif'),
('ROL002', 'PERM003', '2026-06-09 15:39:02', 'actif'),
('ROL004', 'PERM001', '2026-06-09 15:39:02', 'actif'),
('ROL004', 'PERM004', '2026-06-09 15:39:02', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `schemas_comptables`
--

DROP TABLE IF EXISTS `schemas_comptables`;
CREATE TABLE IF NOT EXISTS `schemas_comptables` (
  `schema_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `operation_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `code_journal` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ordre` int NOT NULL DEFAULT '1',
  `sens` enum('DEBIT','CREDIT') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `numero_compte` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `libelle_ecriture` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `obligatoire` enum('oui','non') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'oui',
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'actif',
  PRIMARY KEY (`schema_id`),
  KEY `idx_operation` (`operation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Déchargement des données de la table `schemas_comptables`
--

INSERT INTO `schemas_comptables` (`schema_id`, `operation_id`, `code_journal`, `ordre`, `sens`, `numero_compte`, `libelle_ecriture`, `obligatoire`, `statut`) VALUES
('SCH001', 'OP001', 'CAISSE', 1, 'DEBIT', '571', 'Dépôt espèces client', 'oui', 'actif'),
('SCH002', 'OP001', 'CAISSE', 2, 'CREDIT', '521', 'Contrepartie bancaire', 'oui', 'actif'),
('SCH003', 'OP002', 'CAISSE', 1, 'CREDIT', '571', 'Retrait espèces', 'oui', 'actif'),
('SCH004', 'OP002', 'CAISSE', 2, 'DEBIT', '521', 'Contrepartie bancaire', 'oui', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `secteurs`
--

DROP TABLE IF EXISTS `secteurs`;
CREATE TABLE IF NOT EXISTS `secteurs` (
  `secteur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nom` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`secteur_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `secteurs`
--

INSERT INTO `secteurs` (`secteur_id`, `nom`, `statut`) VALUES
('SEC001', 'Commerce général', 'actif'),
('SEC002', 'Agriculture', 'actif'),
('SEC003', 'Élevage', 'actif'),
('SEC004', 'Artisanat', 'actif'),
('SEC005', 'Transport', 'actif'),
('SEC006', 'Services', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `societes`
--

DROP TABLE IF EXISTS `societes`;
CREATE TABLE IF NOT EXISTS `societes` (
  `code_societe` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nom_societe` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sigle_societe` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `telephone_societe` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email_societe` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pays_societe` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ville_societe` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `adresse_societe` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `coordonnee_gps` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `logo_societe` longblob,
  `type_logo` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_societe` enum('Actif','Inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`code_societe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `societes`
--

INSERT INTO `societes` (`code_societe`, `nom_societe`, `sigle_societe`, `telephone_societe`, `email_societe`, `pays_societe`, `ville_societe`, `adresse_societe`, `coordonnee_gps`, `logo_societe`, `type_logo`, `etat_societe`) VALUES
('SOC001', 'MICROFINANCE SA', 'MFS', '+225 27 20 22 33 44', 'contact@microfinance.ci', 'Côte d\'Ivoire', 'Abidjan', '01 BP 1234 Abidjan 01', NULL, NULL, NULL, 'Actif');

-- --------------------------------------------------------

--
-- Structure de la table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
CREATE TABLE IF NOT EXISTS `transactions` (
  `transaction_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `journee_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `compte_id` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `echeance_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `libelle` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type` enum('Ouverture','Dépôt','Retrait','Virement recu','Virement emis','Prélèvement','Frais','Pénalité','Déblocage crédit','Remboursement crédit','Intérêt crédit','Intérêt débit','Commission','Clôture','Paiement pénalité','Épargne obligatoire') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `montant` decimal(15,2) NOT NULL,
  `date` date NOT NULL,
  `heure` time NOT NULL,
  `sens` enum('Débit','Crédit') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `solde_avant` decimal(15,2) NOT NULL,
  `solde_apres` decimal(15,2) NOT NULL,
  `canal` enum('Guichet','Mobile','Web','ATM','Batch') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `agence_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `mode_reglement` enum('Espèce','Carte bancaire','Virement bancaire','Orange money','MTN money','Moov money','Wave','Autres') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `numero_mode_reglement` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `reference_mode_reglement` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `devise` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nature_deposant` enum('Titulaire','Non titulaire') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nom_prenom_deposant` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nature_piece_justificative` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `numero_piece_justificative` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_delivrance_piece` date DEFAULT NULL,
  `date_expiration_piece` date DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `statut` enum('Validé','Rejeté','Échoué','Annulé','En attente') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'En attente',
  PRIMARY KEY (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `transactions`
--

INSERT INTO `transactions` (`transaction_id`, `journee_id`, `compte_id`, `echeance_id`, `libelle`, `type`, `montant`, `date`, `heure`, `sens`, `solde_avant`, `solde_apres`, `canal`, `agence_id`, `utilisateur_id`, `mode_reglement`, `numero_mode_reglement`, `reference_mode_reglement`, `devise`, `nature_deposant`, `nom_prenom_deposant`, `nature_piece_justificative`, `numero_piece_justificative`, `date_delivrance_piece`, `date_expiration_piece`, `description`, `statut`) VALUES
('TRX001', 'JRN001', 'CPT001', 'ECH001', 'Remboursement crédit DOS001', 'Remboursement crédit', 95000.00, '2024-03-29', '10:30:00', 'Débit', 250000.00, 155000.00, 'Guichet', 'AGC001', 'USR004', 'Espèce', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Remboursement échéance mars', 'Validé'),
('TRX002', 'JRN001', 'CPT001', '', 'Dépôt espèces Diallo', 'Dépôt', 100000.00, '2024-03-29', '09:15:00', 'Crédit', 150000.00, 250000.00, 'Guichet', 'AGC001', 'USR004', 'Espèce', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Dépôt espèces', 'Validé'),
('TRX003', 'JRN001', 'CPT005', '', 'Retrait Kouadio', 'Retrait', 75000.00, '2024-03-29', '14:20:00', 'Débit', 750000.00, 675000.00, 'Guichet', 'AGC001', 'USR004', 'Espèce', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Retrait espèces', 'Validé'),
('TRX004', 'JRN001', 'CPT007', '', 'Virement émis Coulibaly', 'Virement emis', 250000.00, '2024-03-29', '11:00:00', 'Débit', 1500000.00, 1250000.00, 'Guichet', 'AGC001', 'USR001', 'Virement bancaire', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Virement fournisseur', 'Validé'),
('TRX005', 'JRN001', 'CPT002', '', 'Dépôt épargne Diallo', 'Dépôt', 50000.00, '2024-03-29', '13:45:00', 'Crédit', 500000.00, 550000.00, 'Guichet', 'AGC001', 'USR004', 'Espèce', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Dépôt épargne', 'Validé');

-- --------------------------------------------------------

--
-- Structure de la table `utilisateurs`
--

DROP TABLE IF EXISTS `utilisateurs`;
CREATE TABLE IF NOT EXISTS `utilisateurs` (
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `matricule` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nom_prenom` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `login` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `mdp` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `telephone` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `role` enum('Superviseur','Administrateur','Caisse','Client','Comptable','Gestionnaire','Responsable') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_saisie` date DEFAULT NULL,
  `photo` longblob,
  `type` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `agence_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat` enum('actif','inactif','en cours') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`utilisateur_id`),
  UNIQUE KEY `matricule` (`matricule`),
  UNIQUE KEY `login` (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `utilisateurs`
--

INSERT INTO `utilisateurs` (`utilisateur_id`, `matricule`, `nom_prenom`, `login`, `mdp`, `telephone`, `email`, `role`, `date_saisie`, `photo`, `type`, `agence_id`, `etat`) VALUES
('USR001', 'EMP001', 'KOUADIO Jean', 'jean.kouadio', '8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918', '0101010101', 'jean.kouadio@microfinance.ci', 'Superviseur', '2023-01-01', NULL, NULL, 'AGC001', 'actif'),
('USR002', 'EMP002', 'KONAN Marie', 'marie.konan', '8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918', '0102020202', 'marie.konan@microfinance.ci', 'Administrateur', '2023-01-15', NULL, NULL, 'AGC001', 'actif'),
('USR003', 'EMP003', 'TRAORE Ahmed', 'ahmed.traore', '8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918', '0103030303', 'ahmed.traore@microfinance.ci', 'Responsable', '2023-02-01', NULL, NULL, 'AGC003', 'actif'),
('USR004', 'EMP004', 'DIARRA Fatoumata', 'fatou.diarra', '8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918', '0104040404', 'fatou.diarra@microfinance.ci', 'Caisse', '2023-02-10', NULL, NULL, 'AGC001', 'actif'),
('USR005', 'EMP005', 'BAMBA Ousmane', 'ousmane.bamba', '8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918', '0105050505', 'ousmane.bamba@microfinance.ci', 'Comptable', '2023-03-01', NULL, NULL, 'AGC001', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `utilisateurs_caisses`
--

DROP TABLE IF EXISTS `utilisateurs_caisses`;
CREATE TABLE IF NOT EXISTS `utilisateurs_caisses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `caisse_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `utilisateur_id` (`utilisateur_id`),
  KEY `caisse_id` (`caisse_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `utilisateurs_caisses`
--

INSERT INTO `utilisateurs_caisses` (`id`, `utilisateur_id`, `caisse_id`, `statut`) VALUES
(1, 'USR001', 'CAI001', 'actif'),
(2, 'USR004', 'CAI001', 'actif'),
(3, 'USR002', 'CAI002', 'actif'),
(4, 'USR003', 'CAI003', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `utilisateurs_comptes`
--

DROP TABLE IF EXISTS `utilisateurs_comptes`;
CREATE TABLE IF NOT EXISTS `utilisateurs_comptes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `compte_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `utilisateur_id` (`utilisateur_id`),
  KEY `compte_id` (`compte_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `utilisateurs_comptes`
--

INSERT INTO `utilisateurs_comptes` (`id`, `utilisateur_id`, `compte_id`, `statut`) VALUES
(1, 'USR001', 'CPT001', 'actif'),
(2, 'USR001', 'CPT005', 'actif'),
(3, 'USR001', 'CPT007', 'actif'),
(4, 'USR002', 'CPT004', 'actif'),
(5, 'USR003', 'CPT003', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `visiteurs`
--

DROP TABLE IF EXISTS `visiteurs`;
CREATE TABLE IF NOT EXISTS `visiteurs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `date_connexion` date DEFAULT NULL,
  `heure_connexion` time DEFAULT NULL,
  `date_deconnexion` date DEFAULT NULL,
  `heure_deconnexion` time DEFAULT NULL,
  `duree_date` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `duree_heure` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `reference` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `etat_connexion` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `visiteurs`
--

INSERT INTO `visiteurs` (`id`, `date_connexion`, `heure_connexion`, `date_deconnexion`, `heure_deconnexion`, `duree_date`, `duree_heure`, `reference`, `etat_connexion`) VALUES
(1, '2024-03-29', '08:00:00', '2024-03-29', '17:30:00', '0', '09:30:00', '192.168.1.1', 'Déconnecté'),
(2, '2024-03-30', '08:15:00', NULL, NULL, NULL, NULL, '192.168.1.1', 'Connecté'),
(3, '2024-03-30', '09:00:00', '2024-03-30', '12:00:00', '0', '03:00:00', '192.168.1.2', 'Déconnecté');

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_contreparties_liees`
--

DROP TABLE IF EXISTS `z_bceao_contreparties_liees`;
CREATE TABLE IF NOT EXISTS `z_bceao_contreparties_liees` (
  `id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `client_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nui_bceao` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nom_contrepartie` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `lien` enum('ACTIONNAIRE_SIGNIFICATIF','DIRIGEANT','GROUPE','FAMILLE','AUTRE') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `pourcentage_capital` decimal(5,2) DEFAULT NULL,
  `montant_exposition_totale` decimal(20,2) NOT NULL DEFAULT '0.00',
  `date_debut_lien` date NOT NULL,
  `date_fin_lien` date DEFAULT NULL,
  `agence_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'actif',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_client_lien` (`client_id`,`lien`,`nom_contrepartie`),
  KEY `idx_client` (`client_id`),
  KEY `idx_nui` (`nui_bceao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Contreparties liées - Exigences grands risques et déduction fonds propres BCEAO';

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_etat_6000_bilan`
--

DROP TABLE IF EXISTS `z_bceao_etat_6000_bilan`;
CREATE TABLE IF NOT EXISTS `z_bceao_etat_6000_bilan` (
  `ligne_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT (uuid()),
  `periode` char(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'AAAAMM',
  `date_arrete` date NOT NULL,
  `code_poste` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `intitule_poste` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `sens_poste` enum('ACTIF','PASSIF','HORS_BILAN','RESULTAT') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `niveau` tinyint NOT NULL DEFAULT '2',
  `montant_n` decimal(20,2) NOT NULL DEFAULT '0.00' COMMENT 'Période courante',
  `montant_n1` decimal(20,2) NOT NULL DEFAULT '0.00' COMMENT 'Même période N-1',
  `variation_pct` decimal(8,2) GENERATED ALWAYS AS ((case when (`montant_n1` = 0) then NULL else round((((`montant_n` - `montant_n1`) / abs(`montant_n1`)) * 100),2) end)) STORED,
  `ordre_affichage` int DEFAULT '0',
  `controle_equilibre` decimal(20,2) DEFAULT NULL COMMENT 'Contrôle Actif = Passif à la ligne Total',
  `date_generation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('brouillon','validé','transmis') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'brouillon',
  PRIMARY KEY (`ligne_id`),
  UNIQUE KEY `uk_periode_poste` (`periode`,`code_poste`),
  KEY `idx_periode` (`periode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='État 6000 : Bilan consolidé mensuel BCEAO';

--
-- Déchargement des données de la table `z_bceao_etat_6000_bilan`
--

INSERT INTO `z_bceao_etat_6000_bilan` (`ligne_id`, `periode`, `date_arrete`, `code_poste`, `intitule_poste`, `sens_poste`, `niveau`, `montant_n`, `montant_n1`, `ordre_affichage`, `controle_equilibre`, `date_generation`, `utilisateur_id`, `statut`) VALUES
('575f6a38-6419-11f1-bb63-54ee75ed345c', '202403', '2024-03-31', 'A', 'ACTIF TOTAL', 'ACTIF', 1, 15625000.00, 12000000.00, 0, NULL, '2026-06-09 15:39:02', 'USR001', 'brouillon'),
('575f6c66-6419-11f1-bb63-54ee75ed345c', '202403', '2024-03-31', 'A1', 'Caisse', 'ACTIF', 2, 15500000.00, 11900000.00, 0, NULL, '2026-06-09 15:39:02', 'USR001', 'brouillon'),
('575f6d56-6419-11f1-bb63-54ee75ed345c', '202403', '2024-03-31', 'A2', 'Banques', 'ACTIF', 2, 125000.00, 100000.00, 0, NULL, '2026-06-09 15:39:02', 'USR001', 'brouillon'),
('575f6dfd-6419-11f1-bb63-54ee75ed345c', '202403', '2024-03-31', 'P', 'PASSIF TOTAL', 'PASSIF', 1, 15625000.00, 12000000.00, 0, NULL, '2026-06-09 15:39:02', 'USR001', 'brouillon'),
('575f6ea4-6419-11f1-bb63-54ee75ed345c', '202403', '2024-03-31', 'P1', 'Dépôts clientèle', 'PASSIF', 2, 3750000.00, 3000000.00, 0, NULL, '2026-06-09 15:39:02', 'USR001', 'brouillon'),
('575f6f48-6419-11f1-bb63-54ee75ed345c', '202403', '2024-03-31', 'P2', 'Capital', 'PASSIF', 2, 90000000.00, 90000000.00, 0, NULL, '2026-06-09 15:39:02', 'USR001', 'brouillon');

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_etat_6010_encours_credits`
--

DROP TABLE IF EXISTS `z_bceao_etat_6010_encours_credits`;
CREATE TABLE IF NOT EXISTS `z_bceao_etat_6010_encours_credits` (
  `ligne_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT (uuid()),
  `periode` char(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_arrete` date NOT NULL,
  `code_secteur_bceao` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'K05',
  `libelle_secteur` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `code_objet_bceao` char(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `libelle_objet` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `code_type_credit` char(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'CT/MT/LT',
  `code_classification` enum('S','CAS','CC','CL','P') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'S',
  `nombre_credits` int NOT NULL DEFAULT '0',
  `nombre_clients` int NOT NULL DEFAULT '0',
  `encours_brut` decimal(20,2) NOT NULL DEFAULT '0.00',
  `encours_interet_couru` decimal(20,2) NOT NULL DEFAULT '0.00',
  `montant_en_souffrance` decimal(20,2) NOT NULL DEFAULT '0.00',
  `montant_provision_req` decimal(20,2) NOT NULL DEFAULT '0.00',
  `montant_provision_const` decimal(20,2) NOT NULL DEFAULT '0.00',
  `teg_moyen` decimal(8,4) DEFAULT NULL,
  `date_generation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('brouillon','validé','transmis') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'brouillon',
  PRIMARY KEY (`ligne_id`),
  UNIQUE KEY `uk_ligne_6010` (`periode`,`code_secteur_bceao`,`code_objet_bceao`,`code_type_credit`,`code_classification`),
  KEY `idx_periode` (`periode`),
  KEY `idx_secteur` (`code_secteur_bceao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='État 6010 : Encours crédits par secteur d''activité BCEAO';

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_etat_6011_creances_souffrance`
--

DROP TABLE IF EXISTS `z_bceao_etat_6011_creances_souffrance`;
CREATE TABLE IF NOT EXISTS `z_bceao_etat_6011_creances_souffrance` (
  `ligne_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT (uuid()),
  `periode` char(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_arrete` date NOT NULL,
  `code_classification` enum('CAS','CC','CL','P') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `libelle_classification` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `code_secteur_bceao` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `tranche_retard` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Ex: 31-90j, 91-180j, 181-360j, >360j',
  `nombre_credits` int NOT NULL DEFAULT '0',
  `nombre_clients` int NOT NULL DEFAULT '0',
  `encours_brut_capital` decimal(20,2) NOT NULL DEFAULT '0.00',
  `encours_interet_impaye` decimal(20,2) NOT NULL DEFAULT '0.00',
  `encours_total_souffrance` decimal(20,2) NOT NULL DEFAULT '0.00',
  `valeur_garanties_elig` decimal(20,2) NOT NULL DEFAULT '0.00' COMMENT 'Garanties éligibles déductibles',
  `base_provision` decimal(20,2) NOT NULL DEFAULT '0.00',
  `taux_provision_bceao` decimal(5,2) NOT NULL DEFAULT '0.00',
  `provision_requise` decimal(20,2) NOT NULL DEFAULT '0.00',
  `provision_constituee` decimal(20,2) NOT NULL DEFAULT '0.00',
  `par_numerateur` decimal(20,2) DEFAULT NULL COMMENT 'Encours crédits > 1 jour de retard',
  `par_denominateur` decimal(20,2) DEFAULT NULL COMMENT 'Total portefeuille brut',
  `par_ratio` decimal(8,4) GENERATED ALWAYS AS ((case when ((`par_denominateur` is null) or (`par_denominateur` = 0)) then NULL else round(((`par_numerateur` / `par_denominateur`) * 100),4) end)) STORED COMMENT 'PAR30 ou PAR90 selon configuration',
  `date_generation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('brouillon','validé','transmis') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'brouillon',
  PRIMARY KEY (`ligne_id`),
  UNIQUE KEY `uk_ligne_6011` (`periode`,`code_classification`,`code_secteur_bceao`),
  KEY `idx_periode` (`periode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='État 6011 : Créances en souffrance et PAR BCEAO';

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_etat_6020_depots`
--

DROP TABLE IF EXISTS `z_bceao_etat_6020_depots`;
CREATE TABLE IF NOT EXISTS `z_bceao_etat_6020_depots` (
  `ligne_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT (uuid()),
  `periode` char(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_arrete` date NOT NULL,
  `code_type_depot` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'VUE/EPARG/TERM/DAT selon nomenclature BCEAO',
  `libelle_type_depot` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `code_secteur_bceao` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `tranche_duree` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Pour dépôts à terme : <3M, 3-12M, >12M',
  `nombre_comptes` int NOT NULL DEFAULT '0',
  `nombre_clients` int NOT NULL DEFAULT '0',
  `solde_total` decimal(20,2) NOT NULL DEFAULT '0.00',
  `montant_bloque` decimal(20,2) NOT NULL DEFAULT '0.00',
  `solde_net` decimal(20,2) GENERATED ALWAYS AS ((`solde_total` - `montant_bloque`)) STORED,
  `interets_payes` decimal(20,2) NOT NULL DEFAULT '0.00',
  `taux_moyen` decimal(8,4) DEFAULT NULL,
  `date_generation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('brouillon','validé','transmis') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'brouillon',
  PRIMARY KEY (`ligne_id`),
  UNIQUE KEY `uk_ligne_6020` (`periode`,`code_type_depot`,`code_secteur_bceao`),
  KEY `idx_periode` (`periode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='État 6020 : Dépôts collectés auprès de la clientèle BCEAO';

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_etat_6030_garanties`
--

DROP TABLE IF EXISTS `z_bceao_etat_6030_garanties`;
CREATE TABLE IF NOT EXISTS `z_bceao_etat_6030_garanties` (
  `ligne_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT (uuid()),
  `periode` char(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_arrete` date NOT NULL,
  `code_garantie` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `libelle_garantie` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `categorie_garantie` enum('REELLE','PERSONNELLE','FINANCIERE','AUTRE') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `code_classification` enum('S','CAS','CC','CL','P') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Classification du crédit couvert',
  `nombre_garanties` int NOT NULL DEFAULT '0',
  `nombre_credits_couverts` int NOT NULL DEFAULT '0',
  `valeur_brute_totale` decimal(20,2) NOT NULL DEFAULT '0.00',
  `valeur_nette_totale` decimal(20,2) NOT NULL DEFAULT '0.00',
  `encours_credits_couverts` decimal(20,2) NOT NULL DEFAULT '0.00',
  `taux_couverture` decimal(8,4) GENERATED ALWAYS AS ((case when (`encours_credits_couverts` = 0) then NULL else round(((`valeur_nette_totale` / `encours_credits_couverts`) * 100),4) end)) STORED,
  `date_generation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('brouillon','validé','transmis') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'brouillon',
  PRIMARY KEY (`ligne_id`),
  UNIQUE KEY `uk_ligne_6030` (`periode`,`code_garantie`,`code_classification`),
  KEY `idx_periode` (`periode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='État 6030 : Garanties reçues sur crédits BCEAO';

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_etat_6040_exploitation`
--

DROP TABLE IF EXISTS `z_bceao_etat_6040_exploitation`;
CREATE TABLE IF NOT EXISTS `z_bceao_etat_6040_exploitation` (
  `ligne_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT (uuid()),
  `periode` char(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_arrete` date NOT NULL,
  `code_rubrique` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Ex: PR1=Produits intérêts, CH1=Charges intérêts...',
  `intitule_rubrique` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type_rubrique` enum('PRODUIT','CHARGE','SOLDE') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nature_rubrique` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Ex: Intérêts/Commissions/Provisions/Personnel...',
  `montant_periode` decimal(20,2) NOT NULL DEFAULT '0.00' COMMENT 'Mois ou trimestre',
  `montant_ytd` decimal(20,2) NOT NULL DEFAULT '0.00' COMMENT 'Cumulé depuis début exercice',
  `montant_n1_ytd` decimal(20,2) NOT NULL DEFAULT '0.00' COMMENT 'Même cumulé N-1',
  `comptes_sources` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ordre_affichage` int DEFAULT '0',
  `date_generation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('brouillon','validé','transmis') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'brouillon',
  PRIMARY KEY (`ligne_id`),
  UNIQUE KEY `uk_ligne_6040` (`periode`,`code_rubrique`),
  KEY `idx_periode` (`periode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='État 6040 : Compte de résultat / Résultats d''exploitation BCEAO';

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_etat_6050_indicateurs`
--

DROP TABLE IF EXISTS `z_bceao_etat_6050_indicateurs`;
CREATE TABLE IF NOT EXISTS `z_bceao_etat_6050_indicateurs` (
  `ligne_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT (uuid()),
  `periode` char(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_arrete` date NOT NULL,
  `code_indicateur` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Ex: LCR, NSFR, PAR30, CAR, ROA, ROE...',
  `libelle_indicateur` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `categorie` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'LIQUIDITE/SOLVABILITE/QUALITE_PORTEFEUILLE/RENTABILITE',
  `numerateur` decimal(20,2) DEFAULT NULL,
  `denominateur` decimal(20,2) DEFAULT NULL,
  `valeur_calculee` decimal(12,4) DEFAULT NULL COMMENT 'Ratio ou montant selon indicateur',
  `unite` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT '%',
  `seuil_min_bceao` decimal(12,4) DEFAULT NULL,
  `seuil_max_bceao` decimal(12,4) DEFAULT NULL,
  `conformite` enum('CONFORME','NON_CONFORME','ATTENTION','N/A') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci GENERATED ALWAYS AS ((case when (`valeur_calculee` is null) then _utf8mb4'N/A' when ((`seuil_min_bceao` is not null) and (`valeur_calculee` < `seuil_min_bceao`)) then _utf8mb4'NON_CONFORME' when ((`seuil_max_bceao` is not null) and (`valeur_calculee` > `seuil_max_bceao`)) then _utf8mb4'NON_CONFORME' when ((`seuil_min_bceao` is not null) and (`valeur_calculee` < (`seuil_min_bceao` * 1.1))) then _utf8mb4'ATTENTION' else _utf8mb4'CONFORME' end)) STORED,
  `formule_bceao` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci COMMENT 'Formule réglementaire',
  `date_generation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('brouillon','validé','transmis') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'brouillon',
  PRIMARY KEY (`ligne_id`),
  UNIQUE KEY `uk_ligne_6050` (`periode`,`code_indicateur`),
  KEY `idx_periode` (`periode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='État 6050 : Indicateurs prudentiels BCEAO';

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_etat_6100_liquidite`
--

DROP TABLE IF EXISTS `z_bceao_etat_6100_liquidite`;
CREATE TABLE IF NOT EXISTS `z_bceao_etat_6100_liquidite` (
  `ligne_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT (uuid()),
  `periode` char(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_arrete` date NOT NULL,
  `code_ligne` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `intitule_ligne` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type_flux` enum('ENTREE','SORTIE','STOCK','RATIO') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `echeance_bucket` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Ex: 0-7j, 8-30j, 31-90j, >90j',
  `montant` decimal(20,2) NOT NULL DEFAULT '0.00',
  `facteur_bceao` decimal(5,2) DEFAULT '100.00' COMMENT 'Facteur de pondération BCEAO (ex: 5% sorties dépôts stables)',
  `montant_pondere` decimal(20,2) GENERATED ALWAYS AS (round(((`montant` * `facteur_bceao`) / 100),2)) STORED,
  `comptes_sources` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ordre_affichage` int DEFAULT '0',
  `date_generation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('brouillon','validé','transmis') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'brouillon',
  PRIMARY KEY (`ligne_id`),
  UNIQUE KEY `uk_ligne_6100` (`periode`,`code_ligne`,`echeance_bucket`),
  KEY `idx_periode` (`periode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='État 6100 : Situation de liquidité / LCR BCEAO';

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_etat_6200_fonds_propres`
--

DROP TABLE IF EXISTS `z_bceao_etat_6200_fonds_propres`;
CREATE TABLE IF NOT EXISTS `z_bceao_etat_6200_fonds_propres` (
  `ligne_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT (uuid()),
  `periode` char(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_arrete` date NOT NULL,
  `code_composante` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `intitule_composante` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `tier` enum('TIER1','TIER2','DEDUCTION','TOTAL') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Tier 1 = fonds propres de base, Tier 2 = complémentaires',
  `montant` decimal(20,2) NOT NULL DEFAULT '0.00',
  `comptes_sources` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `actifs_pond_risque` decimal(20,2) DEFAULT NULL,
  `car_ratio` decimal(8,4) GENERATED ALWAYS AS ((case when ((`actifs_pond_risque` is null) or (`actifs_pond_risque` = 0)) then NULL else round(((`montant` / `actifs_pond_risque`) * 100),4) end)) STORED COMMENT 'CAR calculé sur cette ligne',
  `ordre_affichage` int DEFAULT '0',
  `date_generation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('brouillon','validé','transmis') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'brouillon',
  PRIMARY KEY (`ligne_id`),
  UNIQUE KEY `uk_ligne_6200` (`periode`,`code_composante`),
  KEY `idx_periode` (`periode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='État 6200 : Fonds propres et adéquation du capital BCEAO';

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_etat_6300_risques`
--

DROP TABLE IF EXISTS `z_bceao_etat_6300_risques`;
CREATE TABLE IF NOT EXISTS `z_bceao_etat_6300_risques` (
  `ligne_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT (uuid()),
  `periode` char(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_arrete` date NOT NULL,
  `nui_bceao` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Numéro Unique Identification BCEAO',
  `client_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `credit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nom_prenom_raison_soc` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `code_forme_juridique` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `code_secteur_bceao` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `code_pays` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'CIV',
  `code_type_credit` char(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `code_objet_bceao` char(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_octroi` date NOT NULL,
  `date_echeance` date NOT NULL,
  `montant_initial` decimal(20,2) NOT NULL DEFAULT '0.00',
  `encours_capital` decimal(20,2) NOT NULL DEFAULT '0.00',
  `montant_impaye` decimal(20,2) NOT NULL DEFAULT '0.00',
  `nombre_jours_retard` int NOT NULL DEFAULT '0',
  `code_classification` enum('S','CAS','CC','CL','P') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'S',
  `code_garantie_principale` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `valeur_garantie_princ` decimal(20,2) DEFAULT '0.00',
  `teg` decimal(8,4) DEFAULT NULL,
  `date_generation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('brouillon','validé','transmis') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'brouillon',
  PRIMARY KEY (`ligne_id`),
  UNIQUE KEY `uk_ligne_6300` (`periode`,`credit_id`),
  KEY `idx_periode` (`periode`),
  KEY `idx_nui` (`nui_bceao`),
  KEY `idx_classification` (`periode`,`code_classification`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='État 6300 : Déclaration Centrale des Risques BCEAO';

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_flux_liquidite`
--

DROP TABLE IF EXISTS `z_bceao_flux_liquidite`;
CREATE TABLE IF NOT EXISTS `z_bceao_flux_liquidite` (
  `flux_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT (uuid()),
  `periode` char(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'AAAAMM',
  `date_arrete` date NOT NULL,
  `bucket_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type_flux` enum('ENTREE','SORTIE') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `categorie_flux` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Ex: Depots stables, Depots a vue, Credits CT, Titres LCR, etc.',
  `montant` decimal(20,2) NOT NULL DEFAULT '0.00',
  `montant_pondere` decimal(20,2) GENERATED ALWAYS AS (round((`montant` * `facteur_ponderation`),2)) STORED,
  `facteur_ponderation` decimal(5,2) NOT NULL DEFAULT '100.00',
  `source` enum('CONTRACTUEL','COMPORTEMENTAL','HYPOTHESE') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `compte_source` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `credit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `compte_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `date_generation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('brouillon','valide','transmis') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'brouillon',
  PRIMARY KEY (`flux_id`),
  UNIQUE KEY `uk_periode_bucket_flux` (`periode`,`bucket_id`,`type_flux`,`categorie_flux`),
  KEY `idx_periode` (`periode`),
  KEY `idx_bucket` (`bucket_id`),
  KEY `idx_credit` (`credit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Flux de liquidité prévisionnels par bucket - Base de l''état 6100 LCR/NSFR';

--
-- Déchargement des données de la table `z_bceao_flux_liquidite`
--

INSERT INTO `z_bceao_flux_liquidite` (`flux_id`, `periode`, `date_arrete`, `bucket_id`, `type_flux`, `categorie_flux`, `montant`, `facteur_ponderation`, `source`, `compte_source`, `credit_id`, `compte_id`, `description`, `date_generation`, `utilisateur_id`, `statut`) VALUES
('575e9c5f-6419-11f1-bb63-54ee75ed345c', '202403', '2024-03-31', '8-30J', 'ENTREE', 'Remboursements crédits', 183000.00, 100.00, 'CONTRACTUEL', NULL, 'DOS001', 'CPT001', 'Échéances avril 2024', '2026-06-09 15:39:02', 'USR001', 'valide'),
('575e9ec1-6419-11f1-bb63-54ee75ed345c', '202403', '2024-03-31', '31-90J', 'ENTREE', 'Remboursements crédits', 88000.00, 100.00, 'CONTRACTUEL', NULL, 'DOS002', 'CPT003', 'Échéances mai 2024', '2026-06-09 15:39:02', 'USR003', 'valide'),
('575e9fbc-6419-11f1-bb63-54ee75ed345c', '202403', '2024-03-31', '0-7J', 'SORTIE', 'Dépôts clientèle', 825000.00, 10.00, 'COMPORTEMENTAL', NULL, NULL, 'CPT007', 'Dépôts stables prévisibles', '2026-06-09 15:39:02', 'USR001', 'valide');

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_hist_encours_credits`
--

DROP TABLE IF EXISTS `z_bceao_hist_encours_credits`;
CREATE TABLE IF NOT EXISTS `z_bceao_hist_encours_credits` (
  `snap_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT (uuid()),
  `periode` char(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'AAAAMM ex: 202501',
  `date_arrete` date NOT NULL,
  `credit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `compte_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `client_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `agence_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `code_secteur_bceao` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `code_objet_bceao` char(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `code_type_credit` char(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'CT/MT/LT',
  `code_pays_client` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'CIV',
  `montant_capital_initial` decimal(15,2) NOT NULL DEFAULT '0.00',
  `encours_capital_brut` decimal(15,2) NOT NULL DEFAULT '0.00',
  `encours_interet_couru` decimal(15,2) NOT NULL DEFAULT '0.00',
  `montant_en_souffrance` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'Capital échu non remboursé',
  `montant_interet_impaye` decimal(15,2) NOT NULL DEFAULT '0.00',
  `nombre_jours_retard` int NOT NULL DEFAULT '0',
  `code_classification` enum('S','CAS','CC','CL','P') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'S',
  `taux_provision_bceao` decimal(5,2) NOT NULL DEFAULT '0.00',
  `montant_provision_req` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'Provision requise selon taux BCEAO',
  `montant_provision_const` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'Provision effectivement constituée',
  `teg` decimal(8,4) DEFAULT NULL,
  `code_restructuration` tinyint(1) NOT NULL DEFAULT '0',
  `date_generation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`snap_id`),
  UNIQUE KEY `uk_periode_credit` (`periode`,`credit_id`),
  KEY `idx_periode` (`periode`),
  KEY `idx_classification` (`periode`,`code_classification`),
  KEY `idx_secteur` (`code_secteur_bceao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Snapshot mensuel encours crédits — base états 6010/6011/6050';

--
-- Déchargement des données de la table `z_bceao_hist_encours_credits`
--

INSERT INTO `z_bceao_hist_encours_credits` (`snap_id`, `periode`, `date_arrete`, `credit_id`, `compte_id`, `client_id`, `agence_id`, `code_secteur_bceao`, `code_objet_bceao`, `code_type_credit`, `code_pays_client`, `montant_capital_initial`, `encours_capital_brut`, `encours_interet_couru`, `montant_en_souffrance`, `montant_interet_impaye`, `nombre_jours_retard`, `code_classification`, `taux_provision_bceao`, `montant_provision_req`, `montant_provision_const`, `teg`, `code_restructuration`, `date_generation`, `utilisateur_id`) VALUES
('575dd0b9-6419-11f1-bb63-54ee75ed345c', '202403', '2024-03-31', 'DOS001', 'CPT001', 'CLT001', 'AGC001', 'G47', '01', 'MT', 'CIV', 1000000.00, 810000.00, 12500.00, 0.00, 0.00, 0, 'S', 0.00, 0.00, 0.00, NULL, 0, '2026-06-09 15:39:02', 'USR001'),
('575dd423-6419-11f1-bb63-54ee75ed345c', '202403', '2024-03-31', 'DOS002', 'CPT003', 'CLT002', 'AGC003', 'A01A', '01', 'CT', 'CIV', 500000.00, 412000.00, 6200.00, 0.00, 0.00, 0, 'S', 0.00, 0.00, 0.00, NULL, 0, '2026-06-09 15:39:02', 'USR003'),
('575dd561-6419-11f1-bb63-54ee75ed345c', '202403', '2024-03-31', 'DOS003', 'CPT004', 'CLT003', 'AGC002', 'A01A', '02', 'CT', 'CIV', 750000.00, 750000.00, 2500.00, 0.00, 0.00, 0, 'S', 0.00, 0.00, 0.00, NULL, 0, '2026-06-09 15:39:02', 'USR002'),
('575dd68c-6419-11f1-bb63-54ee75ed345c', '202403', '2024-03-31', 'DOS004', 'CPT005', 'CLT004', 'AGC001', 'H49', '02', 'MT', 'CIV', 2000000.00, 2000000.00, 8750.00, 0.00, 0.00, 0, 'S', 0.00, 0.00, 0.00, NULL, 0, '2026-06-09 15:39:02', 'USR001');

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_hist_provisions`
--

DROP TABLE IF EXISTS `z_bceao_hist_provisions`;
CREATE TABLE IF NOT EXISTS `z_bceao_hist_provisions` (
  `prov_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT (uuid()),
  `periode` char(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_arrete` date NOT NULL,
  `credit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `code_classification` enum('S','CAS','CC','CL','P') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `encours_brut` decimal(15,2) NOT NULL DEFAULT '0.00',
  `valeur_garanties` decimal(15,2) NOT NULL DEFAULT '0.00',
  `base_provisionnement` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'Encours brut - valeur garanties éligibles',
  `taux_provision` decimal(5,2) NOT NULL,
  `provision_requise` decimal(15,2) NOT NULL DEFAULT '0.00',
  `provision_constituee` decimal(15,2) NOT NULL DEFAULT '0.00',
  `ecart_provision` decimal(15,2) GENERATED ALWAYS AS ((`provision_requise` - `provision_constituee`)) STORED COMMENT 'Positif = sous-provisionnement',
  `date_generation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`prov_id`),
  UNIQUE KEY `uk_periode_credit` (`periode`,`credit_id`),
  KEY `idx_periode` (`periode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Historique mensuel provisions prudentielles BCEAO';

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_log_generation`
--

DROP TABLE IF EXISTS `z_bceao_log_generation`;
CREATE TABLE IF NOT EXISTS `z_bceao_log_generation` (
  `log_id` int NOT NULL AUTO_INCREMENT,
  `code_etat` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Ex: 6000, 6010, 6300',
  `libelle_etat` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `periode` char(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'AAAAMM',
  `date_arrete` date NOT NULL,
  `date_generation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `utilisateur_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `nb_lignes_generees` int NOT NULL DEFAULT '0',
  `montant_controle` decimal(20,2) DEFAULT NULL COMMENT 'Total actif pour 6000, total encours pour 6010...',
  `checksum_md5` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'MD5 des données générées pour intégrité',
  `duree_ms` int DEFAULT NULL COMMENT 'Durée génération en ms',
  `nom_fichier` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `chemin_fichier` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `controle_6000_6010` decimal(20,2) DEFAULT NULL COMMENT 'Écart encours 6010 vs poste A3 du bilan 6000',
  `controle_equilibre` decimal(20,2) DEFAULT NULL COMMENT 'Actif - Passif du bilan (doit être 0)',
  `erreurs` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `observations` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `statut` enum('OK','ERREUR','PARTIEL','EN_COURS') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'EN_COURS',
  PRIMARY KEY (`log_id`),
  KEY `idx_etat_periode` (`code_etat`,`periode`),
  KEY `idx_date` (`date_generation`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Journal immuable des générations états BCEAO — piste d audit';

--
-- Déchargement des données de la table `z_bceao_log_generation`
--

INSERT INTO `z_bceao_log_generation` (`log_id`, `code_etat`, `libelle_etat`, `periode`, `date_arrete`, `date_generation`, `utilisateur_id`, `nb_lignes_generees`, `montant_controle`, `checksum_md5`, `duree_ms`, `nom_fichier`, `chemin_fichier`, `controle_6000_6010`, `controle_equilibre`, `erreurs`, `observations`, `statut`) VALUES
(1, '6000', 'Bilan BCEAO', '202403', '2024-03-31', '2026-06-09 15:39:02', 'USR001', 6, 15625000.00, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'OK'),
(2, '6010', 'Encours crédits', '202403', '2024-03-31', '2026-06-09 15:39:02', 'USR001', 4, 4062000.00, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'OK');

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_param_classification`
--

DROP TABLE IF EXISTS `z_bceao_param_classification`;
CREATE TABLE IF NOT EXISTS `z_bceao_param_classification` (
  `code_class` enum('S','CAS','CC','CL','P') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `libelle` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `jours_retard_min` int NOT NULL DEFAULT '0',
  `jours_retard_max` int DEFAULT NULL COMMENT 'NULL = illimité',
  `taux_provision` decimal(5,2) NOT NULL COMMENT 'Taux BCEAO obligatoire',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `date_application` date NOT NULL DEFAULT '2011-01-01',
  PRIMARY KEY (`code_class`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Paramètres classification prudentielle BCEAO Instruction 004-2011';

--
-- Déchargement des données de la table `z_bceao_param_classification`
--

INSERT INTO `z_bceao_param_classification` (`code_class`, `libelle`, `jours_retard_min`, `jours_retard_max`, `taux_provision`, `description`, `date_application`) VALUES
('S', 'Sain', 0, 30, 0.00, 'Crédits sains sans retard significatif', '2011-01-01'),
('CAS', 'À surveiller', 31, 90, 20.00, 'Crédits présentant des signes de fragilité', '2011-01-01'),
('CC', 'Mauvais', 91, 180, 50.00, 'Crédits litigieux', '2011-01-01'),
('CL', 'Douteux', 181, 360, 75.00, 'Crédits compromis', '2011-01-01'),
('P', 'Perte', 361, NULL, 100.00, 'Crédits irrécouvrables', '2011-01-01');

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_param_grands_risques`
--

DROP TABLE IF EXISTS `z_bceao_param_grands_risques`;
CREATE TABLE IF NOT EXISTS `z_bceao_param_grands_risques` (
  `id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `seuil_bceao` decimal(15,2) NOT NULL DEFAULT '100000000.00',
  `date_application` date NOT NULL,
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'actif',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `z_bceao_param_grands_risques`
--

INSERT INTO `z_bceao_param_grands_risques` (`id`, `seuil_bceao`, `date_application`, `statut`) VALUES
('SEUIL001', 100000000.00, '2024-01-01', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_param_maturite_buckets`
--

DROP TABLE IF EXISTS `z_bceao_param_maturite_buckets`;
CREATE TABLE IF NOT EXISTS `z_bceao_param_maturite_buckets` (
  `bucket_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Ex: 0-7J, 8-30J, 31-90J, 91-180J, 181-365J, 1-5A, >5A',
  `libelle_bucket` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `duree_min_jours` int NOT NULL,
  `duree_max_jours` int DEFAULT NULL COMMENT 'NULL = illimité',
  `type_flux` enum('ENTREE','SORTIE','STOCK') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `facteur_ponderation_bceao` decimal(5,2) NOT NULL DEFAULT '100.00' COMMENT 'Haircut ou facteur réglementaire BCEAO',
  `ordre_affichage` int NOT NULL DEFAULT '0',
  `categorie_lcr` enum('LIQUIDITE_NIV1','LIQUIDITE_NIV2A','LIQUIDITE_NIV2B','FLUX_SORTIE_STABLES','FLUX_SORTIE_MOINS_STABLES','AUTRE') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'actif',
  `date_application` date NOT NULL DEFAULT '2021-01-01',
  PRIMARY KEY (`bucket_id`),
  UNIQUE KEY `uk_duree` (`duree_min_jours`,`duree_max_jours`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Paramètres des buckets de maturité pour LCR / État 6100 BCEAO';

--
-- Déchargement des données de la table `z_bceao_param_maturite_buckets`
--

INSERT INTO `z_bceao_param_maturite_buckets` (`bucket_id`, `libelle_bucket`, `duree_min_jours`, `duree_max_jours`, `type_flux`, `facteur_ponderation_bceao`, `ordre_affichage`, `categorie_lcr`, `statut`, `date_application`) VALUES
('0-7J', '0 à 7 jours', 0, 7, 'STOCK', 100.00, 1, 'LIQUIDITE_NIV1', 'actif', '2021-01-01'),
('1-5A', '1 à 5 ans', 366, 1825, 'STOCK', 10.00, 6, 'AUTRE', 'actif', '2021-01-01'),
('181-365J', '181 à 365 jours', 181, 365, 'STOCK', 25.00, 5, 'AUTRE', 'actif', '2021-01-01'),
('31-90J', '31 à 90 jours', 31, 90, 'STOCK', 70.00, 3, 'LIQUIDITE_NIV2B', 'actif', '2021-01-01'),
('8-30J', '8 à 30 jours', 8, 30, 'STOCK', 85.00, 2, 'LIQUIDITE_NIV2A', 'actif', '2021-01-01'),
('91-180J', '91 à 180 jours', 91, 180, 'STOCK', 50.00, 4, 'AUTRE', 'actif', '2021-01-01'),
('>5A', 'Plus de 5 ans', 1826, NULL, 'STOCK', 5.00, 7, 'AUTRE', 'actif', '2021-01-01');

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_param_postes_bilan`
--

DROP TABLE IF EXISTS `z_bceao_param_postes_bilan`;
CREATE TABLE IF NOT EXISTS `z_bceao_param_postes_bilan` (
  `poste_id` int NOT NULL AUTO_INCREMENT,
  `code_poste` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Ex: A1, A2, A3, P1...',
  `intitule_poste` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `sens_poste` enum('ACTIF','PASSIF','HORS_BILAN','RESULTAT') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `code_poste_parent` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `niveau` tinyint NOT NULL DEFAULT '2' COMMENT '1=Agrégat 2=Ligne',
  `ordre_affichage` int DEFAULT '0',
  `comptes_debut` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Virgule-séparé : comptes de début de plage ex: 20,21',
  `comptes_fin` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Virgule-séparé : comptes de fin de plage ex: 29,29',
  `signe_inversion` tinyint(1) DEFAULT '0' COMMENT '1=inverser le signe pour présentation',
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'actif',
  PRIMARY KEY (`poste_id`),
  UNIQUE KEY `uk_code_poste` (`code_poste`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Mapping comptes SYSCOHADA vers postes bilan BCEAO — état 6000';

--
-- Déchargement des données de la table `z_bceao_param_postes_bilan`
--

INSERT INTO `z_bceao_param_postes_bilan` (`poste_id`, `code_poste`, `intitule_poste`, `sens_poste`, `code_poste_parent`, `niveau`, `ordre_affichage`, `comptes_debut`, `comptes_fin`, `signe_inversion`, `statut`) VALUES
(1, 'A1', 'Caisse et Banques Centrales', 'ACTIF', NULL, 1, 10, '571,521', '571,521', 0, 'actif'),
(2, 'A2', 'Créances sur les clients', 'ACTIF', NULL, 1, 20, '411', '411', 0, 'actif'),
(3, 'A3', 'Crédits à la clientèle', 'ACTIF', NULL, 1, 30, '801', '899', 0, 'actif'),
(4, 'P1', 'Dépôts de la clientèle', 'PASSIF', NULL, 1, 10, '401', '499', 0, 'actif'),
(5, 'P2', 'Capital social', 'PASSIF', NULL, 1, 20, '101', '101', 1, 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_ref_garanties`
--

DROP TABLE IF EXISTS `z_bceao_ref_garanties`;
CREATE TABLE IF NOT EXISTS `z_bceao_ref_garanties` (
  `code_garantie` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `libelle` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `categorie` enum('REELLE','PERSONNELLE','FINANCIERE','AUTRE') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `taux_couverture_std` decimal(5,2) DEFAULT '100.00' COMMENT 'Taux de couverture prudentielle standard BCEAO',
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'actif',
  PRIMARY KEY (`code_garantie`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Types de garanties acceptées — état 6030';

--
-- Déchargement des données de la table `z_bceao_ref_garanties`
--

INSERT INTO `z_bceao_ref_garanties` (`code_garantie`, `libelle`, `categorie`, `taux_couverture_std`, `statut`) VALUES
('01', 'Hypothèque', 'REELLE', 100.00, 'actif'),
('02', 'Nantissement', 'REELLE', 80.00, 'actif'),
('03', 'Caution solidaire', 'PERSONNELLE', 100.00, 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_ref_objets_credit`
--

DROP TABLE IF EXISTS `z_bceao_ref_objets_credit`;
CREATE TABLE IF NOT EXISTS `z_bceao_ref_objets_credit` (
  `code_objet` char(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `libelle` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'actif',
  PRIMARY KEY (`code_objet`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Nomenclature objet/finalité des crédits BCEAO — état 6010';

--
-- Déchargement des données de la table `z_bceao_ref_objets_credit`
--

INSERT INTO `z_bceao_ref_objets_credit` (`code_objet`, `libelle`, `statut`) VALUES
('01', 'Fonds de roulement', 'actif'),
('02', 'Investissement', 'actif'),
('03', 'Consommation', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_ref_pays`
--

DROP TABLE IF EXISTS `z_bceao_ref_pays`;
CREATE TABLE IF NOT EXISTS `z_bceao_ref_pays` (
  `code_pays` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'ISO 3166-1 alpha-3',
  `libelle_pays` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `zone_uemoa` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=pays UEMOA',
  `code_bceao` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Code interne BCEAO si différent',
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'actif',
  PRIMARY KEY (`code_pays`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Référentiel pays BCEAO — utilisé dans 6010, 6300';

--
-- Déchargement des données de la table `z_bceao_ref_pays`
--

INSERT INTO `z_bceao_ref_pays` (`code_pays`, `libelle_pays`, `zone_uemoa`, `code_bceao`, `statut`) VALUES
('BEN', 'Bénin', 1, 'BJ', 'actif'),
('CIV', 'Côte d\'Ivoire', 1, 'CI', 'actif'),
('FRA', 'France', 0, 'FR', 'actif'),
('SEN', 'Sénégal', 1, 'SN', 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_ref_secteurs`
--

DROP TABLE IF EXISTS `z_bceao_ref_secteurs`;
CREATE TABLE IF NOT EXISTS `z_bceao_ref_secteurs` (
  `code_secteur` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Code BCEAO ex: A01, G47',
  `libelle_secteur` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `code_parent` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Secteur agrégé parent',
  `niveau` tinyint NOT NULL DEFAULT '2' COMMENT '1=Agrégat 2=Détail',
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'actif',
  PRIMARY KEY (`code_secteur`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Nomenclature sectorielle BCEAO — état 6010';

--
-- Déchargement des données de la table `z_bceao_ref_secteurs`
--

INSERT INTO `z_bceao_ref_secteurs` (`code_secteur`, `libelle_secteur`, `code_parent`, `niveau`, `statut`) VALUES
('A01', 'Agriculture', NULL, 1, 'actif'),
('A01A', 'Agriculture vivrière', 'A01', 2, 'actif'),
('G46', 'Commerce de gros', NULL, 1, 'actif'),
('G47', 'Commerce de détail', NULL, 1, 'actif'),
('H49', 'Transport terrestre', NULL, 1, 'actif');

-- --------------------------------------------------------

--
-- Structure de la table `z_bceao_ref_types_credit`
--

DROP TABLE IF EXISTS `z_bceao_ref_types_credit`;
CREATE TABLE IF NOT EXISTS `z_bceao_ref_types_credit` (
  `code_type` char(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'CT/MT/LT',
  `libelle` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `duree_min_mois` int NOT NULL,
  `duree_max_mois` int DEFAULT NULL COMMENT 'NULL = pas de limite',
  `statut` enum('actif','inactif') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'actif',
  PRIMARY KEY (`code_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Types de crédit par durée — BCEAO';

--
-- Déchargement des données de la table `z_bceao_ref_types_credit`
--

INSERT INTO `z_bceao_ref_types_credit` (`code_type`, `libelle`, `duree_min_mois`, `duree_max_mois`, `statut`) VALUES
('CT', 'Court terme (≤12 mois)', 0, 12, 'actif'),
('LT', 'Long terme (>60 mois)', 61, NULL, 'actif'),
('MT', 'Moyen terme (13-60 mois)', 13, 60, 'actif');

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `operations`
--
ALTER TABLE `operations`
  ADD CONSTRAINT `fk_operation_famille` FOREIGN KEY (`famille_operation_id`) REFERENCES `operations_familles` (`famille_operation_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
