CREATE TABLE z_bceao_credit_bail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exercice INT NOT NULL,
    code VARCHAR(10) NOT NULL,
    libelle VARCHAR(255) NOT NULL,
    duree INT DEFAULT NULL,
    montant_brut DECIMAL(15,2) DEFAULT 0,
    amortissements_provisions DECIMAL(15,2) DEFAULT 0,
    montant_net DECIMAL(15,2) DEFAULT 0,
    statut ENUM('actif', 'inactif') DEFAULT 'actif',
    UNIQUE KEY uk_exercice_code (exercice, code)
);

CREATE TABLE z_bceao_concessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exercice INT NOT NULL,
    code VARCHAR(20) NOT NULL,
    duree INT DEFAULT NULL,
    valeur_inventaire DECIMAL(15,2) DEFAULT 0,
    concessionnaire_nom VARCHAR(255) DEFAULT NULL,
    valeur_declaree_cahier DECIMAL(15,2) DEFAULT 0,
    statut ENUM('actif', 'inactif') DEFAULT 'actif',
    UNIQUE KEY uk_exercice_code (exercice, code)
);

CREATE TABLE z_bceao_reserve_propriete (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exercice INT NOT NULL,
    code VARCHAR(20) NOT NULL,
    libelle_bien VARCHAR(255) DEFAULT NULL,
    objet_clause VARCHAR(255) DEFAULT NULL,
    montant_brut DECIMAL(15,2) DEFAULT 0,
    date_inscription DATE DEFAULT NULL,
    duree_jouissance INT DEFAULT NULL,
    creancier_nom VARCHAR(255) DEFAULT NULL,
    statut ENUM('actif', 'inactif') DEFAULT 'actif',
    UNIQUE KEY uk_exercice_code (exercice, code)
);

CREATE TABLE z_bceao_personnel_exterieur (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exercice INT NOT NULL,
    categorie VARCHAR(10) NOT NULL,
    libelle VARCHAR(255) DEFAULT NULL,
    nationaux INT DEFAULT 0,
    autre_umoa INT DEFAULT 0,
    hors_umoa INT DEFAULT 0,
    secteur_primaire INT DEFAULT 0,
    secteur_secondaire INT DEFAULT 0,
    secteur_tertiaire INT DEFAULT 0,
    total_effectif INT DEFAULT 0,
    facturation DECIMAL(15,2) DEFAULT 0,
    statut ENUM('actif', 'inactif') DEFAULT 'actif',
    UNIQUE KEY uk_exercice_categorie (exercice, categorie)
);

CREATE TABLE z_bceao_infos_annexes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exercice INT NOT NULL,
    code_indicateur VARCHAR(10) NOT NULL,
    valeur_montant DECIMAL(15,2) DEFAULT NULL,
    valeur_effectif INT DEFAULT NULL,
    statut ENUM('actif', 'inactif') DEFAULT 'actif',
    UNIQUE KEY uk_exercice_code (exercice, code_indicateur)
);

CREATE TABLE z_bceao_engagements_signature (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exercice INT NOT NULL,
    code_indicateur VARCHAR(10) NOT NULL,
    libelle VARCHAR(255) DEFAULT NULL,
    montant DECIMAL(15,2) DEFAULT 0,
    statut ENUM('actif', 'inactif') DEFAULT 'actif',
    UNIQUE KEY uk_exercice_code (exercice, code_indicateur)
);

CREATE TABLE z_bceao_ressources_affectees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exercice INT NOT NULL,
    code VARCHAR(20) NOT NULL,
    libelle VARCHAR(255) DEFAULT NULL,
    court_terme DECIMAL(15,2) DEFAULT 0,
    moyen_terme DECIMAL(15,2) DEFAULT 0,
    long_terme DECIMAL(15,2) DEFAULT 0,
    total DECIMAL(15,2) DEFAULT 0,
    statut ENUM('actif', 'inactif') DEFAULT 'actif',
    UNIQUE KEY uk_exercice_code (exercice, code)
);

CREATE TABLE z_bceao_affectation_resultat (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exercice INT NOT NULL,
    code VARCHAR(20) NOT NULL,
    libelle VARCHAR(255) DEFAULT NULL,
    proposition DECIMAL(15,2) DEFAULT 0,
    repartition_effective DECIMAL(15,2) DEFAULT 0,
    statut ENUM('actif', 'inactif') DEFAULT 'actif',
    UNIQUE KEY uk_exercice_code (exercice, code)
);

CREATE TABLE z_bceao_reevaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exercice INT NOT NULL,
    code VARCHAR(20) NOT NULL,
    bien_libelle VARCHAR(255) DEFAULT NULL,
    date_reevaluation DATE DEFAULT NULL,
    nature_libre TINYINT(1) DEFAULT 0,
    nature_legale TINYINT(1) DEFAULT 0,
    methode_indiciaire TINYINT(1) DEFAULT 0,
    methode_couts_actuels TINYINT(1) DEFAULT 0,
    valeur_avant DECIMAL(15,2) DEFAULT 0,
    valeur_apres DECIMAL(15,2) DEFAULT 0,
    ecart_reevaluation DECIMAL(15,2) DEFAULT 0,
    statut ENUM('actif', 'inactif') DEFAULT 'actif',
    UNIQUE KEY uk_exercice_code (exercice, code)
);

CREATE TABLE z_bceao_annexes_rapport (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exercice INT NOT NULL,
    code_indicateur VARCHAR(10) NOT NULL,
    valeur DECIMAL(15,2) DEFAULT 0,
    statut ENUM('actif', 'inactif') DEFAULT 'actif',
    UNIQUE KEY uk_exercice_code (exercice, code_indicateur)
);

CREATE TABLE z_bceao_indicateurs_financiers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exercice INT NOT NULL,
    code_indicateur VARCHAR(20) NOT NULL,
    valeur_numerateur DECIMAL(15,2) DEFAULT 0,
    valeur_denominateur DECIMAL(15,2) DEFAULT 0,
    valeur_ratio DECIMAL(15,4) DEFAULT 0,
    statut ENUM('actif', 'inactif') DEFAULT 'actif',
    UNIQUE KEY uk_exercice_code (exercice, code_indicateur)
);