<?php
// IF12.php - Indicateurs financiers (Efficacité, Rentabilité, Gestion du bilan)
// Design DIMF_2000 identique à R01.php
// Structure des tableaux conforme au fichier IF12.xlsx (Colonnes: Acc, Source, Indicateur, Valeur)

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ------------------------- CONNEXION BDD -------------------------
require_once '../../databases/database.php'; 

// ------------------------- PARAMÈTRES (identique à R01) -------------------------
$exercice = isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y');
$type_periode = isset($_GET['type_periode']) ? $_GET['type_periode'] : 'annuel';
$mois = isset($_GET['mois']) ? (int)$_GET['mois'] : 12;
$trimestre = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 4;
$semestre = isset($_GET['semestre']) ? (int)$_GET['semestre'] : 2;

switch ($type_periode) {
    case 'mensuel': break;
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre': $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel': $mois = 12; break;
    default: $mois = 12;
}

$date_fin_periode = date('Y-m-t', strtotime("$exercice-" . str_pad($mois, 2, '0', STR_PAD_LEFT) . "-01"));
$date_debut_exercice = "$exercice-01-01";
$date_fin_exercice = "$exercice-12-31";

// Période précédente pour les moyennes
$exercice_prec = $exercice - 1;
$date_fin_prec = "$exercice_prec-12-31";

// ============================================================
// CALCUL DES INDICATEURS (LOGIQUE INCHANGÉE)
// ============================================================

// --- I-3 EFFICACITÉ / PRODUCTIVITÉ ---

// 1. Productivité des agents de crédits
$nb_emprunteurs_actifs = 0;
$nb_agents_credit = 0;
$productivite_agents = 0;

try {
    // Emprunteurs actifs
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT c.client_id) as nb FROM dossiers d INNER JOIN comptes cpt ON d.compte_id = cpt.compte_id INNER JOIN clients c ON cpt.client_id = c.client_id WHERE d.statut IN ('actif', 'approuve') AND d.date_octroi <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $nb_emprunteurs_actifs = (int)$stmt->fetch()['nb'];

    // Agents de crédit (Rôle Gestionnaire ou Caisse)
    $stmt = $pdo->prepare("SELECT COUNT(*) as nb FROM utilisateurs WHERE role IN ('Gestionnaire', 'Caisse') AND etat = 'actif'");
    $stmt->execute();
    $nb_agents_credit = (int)$stmt->fetch()['nb'];
    
    $productivite_agents = ($nb_agents_credit > 0) ? $nb_emprunteurs_actifs / $nb_agents_credit : 0;
} catch (PDOException $e) { }

// 2. Productivité du personnel
$nb_clients_actifs = 0;
$nb_employes = 0;
$productivite_personnel = 0;

try {
    // Clients actifs
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT client_id) as nb FROM clients WHERE statut = 'actif'");
    $stmt->execute();
    $nb_clients_actifs = (int)$stmt->fetch()['nb'];

    // Employés (Tout sauf Client)
    $stmt = $pdo->prepare("SELECT COUNT(*) as nb FROM utilisateurs WHERE role != 'Client' AND etat = 'actif'");
    $stmt->execute();
    $nb_employes = (int)$stmt->fetch()['nb'];

    $productivite_personnel = ($nb_employes > 0) ? $nb_clients_actifs / $nb_employes : 0;
} catch (PDOException $e) { }

// 3. Charges d'exploitation rapportées au portefeuille de crédit
$charges_exploitation = 0;
$portefeuille_n = 0;
$portefeuille_n1 = 0;
$portefeuille_moyen = 0;
$ratio_charges_portefeuille = 0;

try {
    // Charges exploitation (Classe 6)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '6' AND e.date_ecriture BETWEEN :debut AND :fin");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $charges_exploitation = (float)$stmt->fetch()['total'];

    // Portefeuille N
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as encours FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut IN ('actif', 'approuve') AND d.date_octroi <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $portefeuille_n = (float)$stmt->fetch()['encours'];

    // Portefeuille N-1
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as encours FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut IN ('actif', 'approuve') AND d.date_octroi <= :date_fin_prec");
    $stmt->execute([':date_fin_prec' => $date_fin_prec]);
    $portefeuille_n1 = (float)$stmt->fetch()['encours'];

    $portefeuille_moyen = ($portefeuille_n + $portefeuille_n1) / 2;
    $ratio_charges_portefeuille = ($portefeuille_moyen > 0) ? $charges_exploitation / $portefeuille_moyen : 0;
} catch (PDOException $e) { }

// 4. Ratios des frais généraux rapportés au portefeuille de crédits
$frais_generaux = 0;
$ratio_frais_generaux = 0;

try {
    // Frais généraux (62, 63, 64, T50)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE (pc.numero_compte LIKE '62%' OR pc.numero_compte LIKE '63%' OR pc.numero_compte LIKE '64%' OR pc.numero_compte = 'T50') AND e.date_ecriture BETWEEN :debut AND :fin");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $frais_generaux = (float)$stmt->fetch()['total'];
    
    $ratio_frais_generaux = ($portefeuille_moyen > 0) ? $frais_generaux / $portefeuille_moyen : 0;
} catch (PDOException $e) { }

// 5. Ratio des charges de personnel
$charges_personnel = 0;
$ratio_charges_personnel = 0;

try {
    // Charges personnel (62)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '62%' AND e.date_ecriture BETWEEN :debut AND :fin");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $charges_personnel = (float)$stmt->fetch()['total'];

    $ratio_charges_personnel = ($portefeuille_moyen > 0) ? $charges_personnel / $portefeuille_moyen : 0;
} catch (PDOException $e) { }

// --- I-4 RENTABILITÉ ---

// 1. Rentabilité des fonds propres (ROE)
$resultat_net = 0;
$fonds_propres_n = 0;
$fonds_propres_n1 = 0;
$fonds_propres_moyens = 0;
$roe = 0;

try {
    // Résultat net (Produits - Charges)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN pc.classe_compte = '7' THEN e.montant_credit - e.montant_debit ELSE 0 END) - SUM(CASE WHEN pc.classe_compte = '6' THEN e.montant_debit - e.montant_credit ELSE 0 END), 0) as resultat FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte IN ('6', '7') AND e.date_ecriture BETWEEN :debut AND :fin");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $resultat_net = (float)$stmt->fetch()['resultat'];

    // Fonds Propres N (Classe 1)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as fp FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '1' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $fonds_propres_n = (float)$stmt->fetch()['fp'];

    // Fonds Propres N-1
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as fp FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '1' AND e.date_ecriture <= :date_fin_prec");
    $stmt->execute([':date_fin_prec' => $date_fin_prec]);
    $fonds_propres_n1 = (float)$stmt->fetch()['fp'];

    $fonds_propres_moyens = ($fonds_propres_n + $fonds_propres_n1) / 2;
    $roe = ($fonds_propres_moyens > 0) ? $resultat_net / $fonds_propres_moyens : 0;
} catch (PDOException $e) { }

// 2. Rendement sur actif (ROA)
$actif_total_n = 0;
$actif_total_n1 = 0;
$actif_total_moyen = 0;
$roa = 0;

try {
    // Actif Total N (Classe 2)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as actif FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '2' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $actif_total_n = (float)$stmt->fetch()['actif'];

    // Actif Total N-1
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as actif FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '2' AND e.date_ecriture <= :date_fin_prec");
    $stmt->execute([':date_fin_prec' => $date_fin_prec]);
    $actif_total_n1 = (float)$stmt->fetch()['actif'];

    $actif_total_moyen = ($actif_total_n + $actif_total_n1) / 2;
    $roa = ($actif_total_moyen > 0) ? $resultat_net / $actif_total_moyen : 0;
} catch (PDOException $e) { }

// 3. Autosuffisance opérationnelle
$produits_exploitation = 0;
$autosuffisance = 0;

try {
    // Produits exploitation (Classe 7)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '7' AND e.date_ecriture BETWEEN :debut AND :fin");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $produits_exploitation = (float)$stmt->fetch()['total'];

    $autosuffisance = ($charges_exploitation > 0) ? $produits_exploitation / $charges_exploitation : 0;
} catch (PDOException $e) { }

// 4. Marge bénéficiaire
$marge_beneficiaire = ($produits_exploitation > 0) ? $resultat_net / $produits_exploitation : 0;

// 5. Coefficient d'exploitation
$coefficient_exploitation = ($produits_exploitation > 0) ? $charges_exploitation / $produits_exploitation : 0;

// --- I-5 GESTION DU BILAN ---

// 1. Taux de rendement des actifs
$interets_commissions = 0;
$actifs_productifs = 0; // Simplifié ici par portefeuille moyen + titres
$taux_rendement_actifs = 0;

try {
    // Intérêts perçus (70%)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '70%' AND e.date_ecriture BETWEEN :debut AND :fin");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $interets_commissions = (float)$stmt->fetch()['total'];
    
    // Actifs productifs approximatifs (Portefeuille moyen)
    $actifs_productifs = $portefeuille_moyen; 
    $taux_rendement_actifs = ($actifs_productifs > 0) ? $interets_commissions / $actifs_productifs : 0;
} catch (PDOException $e) { }

// 2. Ratio de liquidité de l'actif
$disponibilites = 0;
$ratio_liquidite = 0;

try {
    // Disponibilités (Caisses + Banques)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde_actuel), 0) as total FROM caisses WHERE statut = 'ouverte'");
    $stmt->execute();
    $disponibilites = (float)$stmt->fetch()['total'];
    
    // Ajout comptes bancaires si présents dans une table comptes spécifiques, sinon on garde caisses
    // Pour simplifier selon le code original :
    $ratio_liquidite = ($actif_total_moyen > 0) ? $disponibilites / $actif_total_moyen : 0;
} catch (PDOException $e) { }

// 3. Ratio de capitalisation
$ratio_capitalisation = ($actif_total_moyen > 0) ? $fonds_propres_moyens / $actif_total_moyen : 0;


// ============================================================
// PRÉPARATION DES TABLEAUX EXCEL (Structure IF12.xlsx)
// ============================================================

$tableau_efficacite_excel = [
    // Productivité des agents de crédits
    ['code' => 'Y04501', 'source' => 'Instruction N°18', 'indicateur' => 'Nombre d\'emprunteurs actifs (A)', 'valeur' => number_format($nb_emprunteurs_actifs, 0, ',', ' ')],
    ['code' => 'Z67', 'source' => '', 'indicateur' => 'Nombre d\'agents de crédits (B)', 'valeur' => number_format($nb_agents_credit, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme > 130)', 'valeur' => number_format($productivite_agents, 0, ',', ' ')],

    // Productivité du personnel
    ['code' => 'Y01101', 'source' => 'Instruction N°18', 'indicateur' => 'Nombre de clients actifs (A)', 'valeur' => number_format($nb_clients_actifs, 0, ',', ' ')],
    ['code' => 'Y01205', 'source' => 'Instruction N°18', 'indicateur' => 'Nombre d\'employés (B)', 'valeur' => number_format($nb_employes, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme > 115)', 'valeur' => number_format($productivite_personnel, 0, ',', ' ')],

    // Charges d'exploitation rapportées au portefeuille de crédit
    ['code' => 'R08 à T6B', 'source' => 'Charges', 'indicateur' => 'Montant des charges d\'exploitation de la période (A)', 'valeur' => number_format($charges_exploitation, 0, ',', ' ')],
    ['code' => '[Portefeuille N+ N-1]/2', 'source' => 'Actif brut', 'indicateur' => 'Montant brut moyen du portfeuille de crédit de la période (B)', 'valeur' => number_format($portefeuille_moyen, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme <= 35%)', 'valeur' => number_format($ratio_charges_portefeuille * 100, 2) . '%'],

    // Ratios des frais généraux rapportés au portefeuille de crédits
    ['code' => 'S02 à T50', 'source' => 'Charges', 'indicateur' => 'Montant des frais généraux de la période (A)', 'valeur' => number_format($frais_generaux, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Montant brut moyen du portfeuille de crédit de la période (B)', 'valeur' => number_format($portefeuille_moyen, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme <= 20%)', 'valeur' => number_format($ratio_frais_generaux * 100, 2) . '%'],

    // Ratio des charges de personnel
    ['code' => 'S02', 'source' => 'Charges', 'indicateur' => 'Montant des charges de personnel de la période (A)', 'valeur' => number_format($charges_personnel, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Montant brut moyen du portfeuille de crédit de la période (B)', 'valeur' => number_format($portefeuille_moyen, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme <= 10%)', 'valeur' => number_format($ratio_charges_personnel * 100, 2) . '%'],
];

$tableau_rentabilite_excel = [
    // Rentabilité des fonds propres
    ['code' => 'V08 à X6B - W53 - [R08 à T6B]', 'source' => 'Produits / Charges', 'indicateur' => 'Résultat d\'exploitation hors subvention (A)', 'valeur' => number_format($resultat_net, 0, ',', ' ')],
    ['code' => '(FP N+ FP N-1)/2', 'source' => 'Passif', 'indicateur' => 'Montant moyen des fonds propres pour la période (B)', 'valeur' => number_format($fonds_propres_moyens, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme > 15%)', 'valeur' => number_format($roe * 100, 2) . '%'],

    // Rendement sur actif
    ['code' => '', 'source' => '', 'indicateur' => 'Résultat d\'exploitation hors subvention (A)', 'valeur' => number_format($resultat_net, 0, ',', ' ')],
    ['code' => '(Actif N+ Actif N-1)/2', 'source' => 'Actif net', 'indicateur' => 'Montant moyen de l\'actif pour la période (B)', 'valeur' => number_format($actif_total_moyen, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme > 3%)', 'valeur' => number_format($roa * 100, 2) . '%'],

    // Autosuffisance opérationnelle
    ['code' => 'V08 à X6B - W53', 'source' => 'Produits', 'indicateur' => 'Montant total des produits d\'exploitation (A)', 'valeur' => number_format($produits_exploitation, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Montant total des charges d\'exploitation (B)', 'valeur' => number_format($charges_exploitation, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme > 130%)', 'valeur' => number_format($autosuffisance, 2)],

    // Marge bénéficiaire
    ['code' => '', 'source' => '', 'indicateur' => 'Résultat d\'exploitation (A)', 'valeur' => number_format($resultat_net, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Montant total des produits d\'exploitation (B)', 'valeur' => number_format($produits_exploitation, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme > 20%)', 'valeur' => number_format($marge_beneficiaire * 100, 2) . '%'],

    // Coefficient d'exploitation
    ['code' => 'S02 à T50', 'source' => 'Charges', 'indicateur' => 'Frais généraux (A)', 'valeur' => number_format($frais_generaux, 0, ',', ' ')],
    ['code' => '[V08 à V7A] - [R08 à R7A]', 'source' => 'Produits / Charges', 'indicateur' => 'Produits financiers nets (B)', 'valeur' => number_format($produits_exploitation - $charges_exploitation, 0, ',', ' ')], // Approximation
    ['code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme <= 60%)', 'valeur' => number_format($coefficient_exploitation * 100, 2) . '%'],
];

$tableau_gestion_excel = [
    // Taux de rendement des actifs
    ['code' => 'V08 à V7A', 'source' => 'Produits', 'indicateur' => 'Montant des intérêts et des commissions perçus au cours de la période (A)', 'valeur' => number_format($interets_commissions, 0, ',', ' ')],
    ['code' => 'Actifs Productifs', 'source' => 'Actif brut', 'indicateur' => 'Montant des actifs productifs de la période (B)', 'valeur' => number_format($actifs_productifs, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme > 15%)', 'valeur' => number_format($taux_rendement_actifs * 100, 2) . '%'],

    // Ratio de liquidité de l'actif
    ['code' => 'A10+A12+C10...', 'source' => 'Actif net', 'indicateur' => 'Disponibilités et instruments facilement négociables (A)', 'valeur' => number_format($disponibilites, 0, ',', ' ')],
    ['code' => 'E90', 'source' => 'Actif net', 'indicateur' => 'Actif total de la période (B)', 'valeur' => number_format($actif_total_moyen, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme > 5%)', 'valeur' => number_format($ratio_liquidite * 100, 2) . '%'],

    // Ratio de capitalisation
    ['code' => 'L01', 'source' => 'Passif', 'indicateur' => 'Montant total des fonds propres de la période (A)', 'valeur' => number_format($fonds_propres_moyens, 0, ',', ' ')],
    ['code' => 'E90', 'source' => 'Actif net', 'indicateur' => 'Montant total de l\'actif de la période (B)', 'valeur' => number_format($actif_total_moyen, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme > 15%)', 'valeur' => number_format($ratio_capitalisation * 100, 2) . '%'],
];


// ------------------------- EXPORT PDF AVEC PDF_DIMF (Style R01) -------------------------
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require_once('../../fpdf/fpdf.php'); // Ajustez le chemin si nécessaire
    
    class PDF_DIMF extends FPDF {
        public $codeDimf  = 'IF12';
        public $titreDimf = 'INDICATEURS FINANCIERS (PARTIE 2)';
        public $nomSfd    = 'SFD';
        public $periode   = '';
        public $exercice  = '';

        static function u($str) {
            return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $str);
        }

        function Header() {
            $this->SetFillColor(156, 163, 175);
            $this->Rect(0, 0, $this->GetPageWidth(), 28, 'F');
            
            $this->SetFont('Arial', '', 7);
            $this->SetTextColor(255, 255, 255);
            $this->SetXY(8, 3);
            $this->Cell(0, 4, self::u('République de Côte d\'Ivoire  •  Ministère de l\'Economie et des Finances  -  DGTCP / DSFD'), 0, 1, 'L');
            
            $this->SetFont('Arial', 'B', 13);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 7, self::u($this->codeDimf . '  -  ' . $this->titreDimf), 0, 1, 'L');
            
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 5, self::u(
                'SFD : ' . $this->nomSfd . 
                '   |   Période : ' . $this->periode . 
                '   |   Exercice : ' . $this->exercice . 
                '   |   Arrêté au : ' . date('d/m/Y', strtotime($GLOBALS['date_fin_periode']))
            ), 0, 1, 'L');
            
            $this->SetTextColor(0, 0, 0);
            $this->Ln(4);
        }

        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, self::u(
                'SICS-BCEAO  •  Généré le ' . date('d/m/Y H:i:s') . 
                '  •  Page ' . $this->PageNo() . '/{nb}'),
            0, 0, 'C');
        }

        function SectionTitle($label) {
            $this->SetFont('Arial', 'B', 9);
            $this->SetFillColor(0, 0, 0);
            $this->SetTextColor(255, 255, 255);
            $this->Cell(0, 7, self::u('  ' . strtoupper($label)), 0, 1, 'L', true);
            $this->SetTextColor(0, 0, 0);
            $this->Ln(1);
        }

        function TableHeader($cols) {
            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(248, 250, 252);
            $this->SetTextColor(30, 41, 59);
            $this->SetDrawColor(226, 232, 240);
            $this->SetLineWidth(0.2);
            foreach ($cols as $col) {
                $align = isset($col['align']) ? $col['align'] : 'L';
                $this->Cell($col['w'], 6, self::u($col['label']), 1, 0, $align, true);
            }
            $this->Ln();
        }

        function TableRow($cols, $data, $style = '') {
            $fill = false;
            if ($style == 'subtotal') {
                $this->SetFillColor(248, 250, 252);
                $this->SetFont('Arial', 'B', 8);
                $fill = true;
            } elseif ($style == 'total') {
                $this->SetFillColor(240, 253, 244);
                $this->SetFont('Arial', 'B', 8.5);
                $fill = true;
            } else {
                $this->SetFillColor(255, 255, 255);
                $this->SetFont('Arial', '', 7.5);
                $fill = false;
            }
            
            $this->SetTextColor(15, 23, 42);
            $this->SetDrawColor(226, 232, 240);
            $this->SetLineWidth(0.1);
            
            foreach ($cols as $i => $col) {
                $val = isset($data[$i]) ? $data[$i] : '';
                $align = isset($col['align']) ? $col['align'] : 'L';
                $this->Cell($col['w'], 5.5, self::u($val), 1, 0, $align, $fill);
            }
            $this->Ln();
        }
    }

    $pdf = new PDF_DIMF();
    $pdf->AliasNbPages();
    $pdf->codeDimf = 'IF12';
    $pdf->titreDimf = 'INDICATEURS FINANCIERS (PARTIE 2)';
    $pdf->nomSfd = 'SFD';
    $pdf->periode = ucfirst($type_periode);
    $pdf->exercice = $exercice;
    $pdf->AddPage();

    // Tableau I-3 (efficacité) - 4 Colonnes
    $pdf->SectionTitle("I-3 - INDICATEURS D'EFFICACITE/PRODUCTIVITE");
    $cols = [
        ['w' => 30, 'label' => 'Acc', 'align' => 'L'],
        ['w' => 40, 'label' => 'Source', 'align' => 'L'],
        ['w' => 90, 'label' => 'Indicateur', 'align' => 'L'],
        ['w' => 30, 'label' => 'Valeur', 'align' => 'R']
    ];
    $pdf->TableHeader($cols);
    foreach ($tableau_efficacite_excel as $row) {
        $pdf->TableRow($cols, [$row['code'], $row['source'], $row['indicateur'], $row['valeur']]);
    }

    // Tableau I-4 (rentabilité) - 4 Colonnes
    $pdf->Ln(5);
    $pdf->SectionTitle("I-4 - INDICATEURS DE RENTABILITE");
    $cols2 = [
        ['w' => 30, 'label' => 'Acc', 'align' => 'L'],
        ['w' => 40, 'label' => 'Source', 'align' => 'L'],
        ['w' => 90, 'label' => 'Indicateur', 'align' => 'L'],
        ['w' => 30, 'label' => 'Valeur', 'align' => 'R']
    ];
    $pdf->TableHeader($cols2);
    foreach ($tableau_rentabilite_excel as $row) {
        $pdf->TableRow($cols2, [$row['code'], $row['source'], $row['indicateur'], $row['valeur']]);
    }

    // Tableau I-5 (gestion bilan) - 4 Colonnes
    $pdf->Ln(5);
    $pdf->SectionTitle("I-5 - INDICATEURS DE GESTION DU BILAN");
    $cols3 = [
        ['w' => 30, 'label' => 'Acc', 'align' => 'L'],
        ['w' => 40, 'label' => 'Source', 'align' => 'L'],
        ['w' => 90, 'label' => 'Indicateur', 'align' => 'L'],
        ['w' => 30, 'label' => 'Valeur', 'align' => 'R']
    ];
    $pdf->TableHeader($cols3);
    foreach ($tableau_gestion_excel as $row) {
        $pdf->TableRow($cols3, [$row['code'], $row['source'], $row['indicateur'], $row['valeur']]);
    }

    $pdf->Output('I', 'IF12_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}

// ------------------------- EXPORT EXCEL (HTML .xls) -------------------------
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="IF12_' . $exercice . '_' . $type_periode . '.xls"');
    
    echo '<html><head><meta charset="UTF-8"><style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    h2 { color: #1a3a5c; }
    table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
    th, td { border: 1px solid #999; padding: 8px; }
    th { background: #f2f2f2; text-align: center; font-weight: bold; }
    .text-right { text-align: right; }
    </style></head><body>';
    
    echo '<h2>IF 12 - INDICATEURS FINANCIERS (Efficacité, Rentabilité, Gestion)</h2>';
    echo '<p>Période : ' . $exercice . ' - ' . ucfirst($type_periode) . ' (arrêtée au ' . date('d/m/Y', strtotime($date_fin_periode)) . ')</p>';
    
    echo '<h3>I-3 - Indicateurs d\'efficacité/productivité</h3>';
    echo '<table>';
    echo '<tr><th style="width:10%">Acc</th><th style="width:15%">Source</th><th>Indicateur</th><th style="width:15%" class="text-right">Valeur</th></tr>';
    foreach ($tableau_efficacite_excel as $q) {
        echo "<tr>
            <td>{$q['code']}</td>
            <td>{$q['source']}</td>
            <td>{$q['indicateur']}</td>
            <td class='text-right'>{$q['valeur']}</td>
        </tr>";
    }
    echo '</table>';

    echo '<h3>I-4 - Indicateurs de rentabilité</h3>';
    echo '<table>';
    echo '<tr><th style="width:10%">Acc</th><th style="width:15%">Source</th><th>Indicateur</th><th style="width:15%" class="text-right">Valeur</th></tr>';
    foreach ($tableau_rentabilite_excel as $a) {
        echo "<tr>
            <td>{$a['code']}</td>
            <td>{$a['source']}</td>
            <td>{$a['indicateur']}</td>
            <td class='text-right'>{$a['valeur']}</td>
        </tr>";
    }
    echo '</table>';

    echo '<h3>I-5 - Indicateurs de gestion du bilan</h3>';
    echo '<table>';
    echo '<tr><th style="width:10%">Acc</th><th style="width:15%">Source</th><th>Indicateur</th><th style="width:15%" class="text-right">Valeur</th></tr>';
    foreach ($tableau_gestion_excel as $b) {
        echo "<tr>
            <td>{$b['code']}</td>
            <td>{$b['source']}</td>
            <td>{$b['indicateur']}</td>
            <td class='text-right'>{$b['valeur']}</td>
        </tr>";
    }
    echo '</table>';
    
    echo '</body></html>';
    exit;
}

// ------------------------- AFFICHAGE WEB (INTERFACE DIMF_2000 - Style R01) -------------------------
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>IF 12 - Indicateurs financiers (DSFD)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Styles DIMF_2000 (exactement comme R01) */
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',system-ui,sans-serif; background:#f1f5f9; padding:24px; }
        .dashboard { max-width:1400px; margin:0 auto; }
        .page-header { background:linear-gradient(135deg,#3b82f6,#60a5fa); border-radius:24px; padding:20px 28px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; }
        .header-left h1 { font-size:1.6rem; font-weight:600; color:white; display:flex; align-items:center; gap:10px; }
        .subtitle { font-size:0.8rem; color:#e0f2fe; }
        .badge { background:#2563eb; color:white; padding:4px 12px; border-radius:30px; display:inline-block; margin-top:8px; }
        .btn-group { display:flex; gap:12px; }
        .btn-excel, .btn-pdf { padding:8px 20px; border-radius:40px; font-weight:500; border:none; cursor:pointer; }
        .btn-excel { background:#10b981; color:white; }
        .btn-pdf { background:#ef4444; color:white; }
        .card { background:white; border-radius:20px; padding:20px 24px; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05); }
        .card-header { display:flex; align-items:center; gap:10px; border-bottom:1px solid #eef2f6; padding-bottom:12px; margin-bottom:16px; font-weight:600; color:#1e40af; }
        .filters-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:20px; }
        .filter-item { display:flex; flex-direction:column; gap:6px; }
        .filter-item label { font-size:0.7rem; font-weight:600; text-transform:uppercase; color:#4b5563; }
        .filter-item select, .filter-item input { padding:8px 14px; border:1px solid #d1d5db; border-radius:12px; }
        .btn-apply { background:#3b82f6; color:white; border:none; border-radius:40px; padding:8px 24px; cursor:pointer; }
        .ratio-card { background:linear-gradient(145deg,#f8fafc,#fff); border-radius:20px; padding:24px; margin-bottom:24px; border:1px solid #e2e8f0; }
        .ratio-value { font-size:3rem; font-weight:800; }
        .ratio-value.conforme { color:#10b981; }
        .ratio-value.non-conforme { color:#ef4444; }
        .norme-box { background:#f1f5f9; border-radius:16px; padding:12px 20px; text-align:center; }
        .progress-bar { background:#e2e8f0; border-radius:50px; height:24px; overflow:hidden; margin-top:20px; }
        .progress-fill { background:linear-gradient(90deg,#3b82f6,#60a5fa); height:100%; border-radius:50px; text-align:center; color:white; font-size:0.75rem; line-height:24px; }
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:12px 16px; text-align:left; border-bottom:1px solid #f1f5f9; }
        th { background:#f8fafc; font-weight:600; }
        .text-right { text-align:right; }
        .total-row { background:#f0fdf4; font-weight:700; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px; border-radius:16px; display:flex; align-items:center; gap:14px; }
        .two-columns { display:flex; gap:24px; flex-wrap:wrap; }
        .two-columns .card { flex:1; min-width:320px; }
        .page-footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; }
        @media print { .btn-group, .filters-row, #filtersCard { display:none; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-chart-line"></i> IF 12 - INDICATEURS FINANCIERS</h1>
            <div class="subtitle">République de Côte d'Ivoire / DGTCP / DSFD</div>
            <div class="badge">Partie 2 : Efficacité, Rentabilité et Gestion du bilan</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="location.href='?<?=http_build_query(array_merge($_GET,['export'=>'excel']))?>'"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" onclick="location.href='?<?=http_build_query(array_merge($_GET,['export'=>'pdf']))?>'"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <!-- Filtres période (identique à R01) -->
    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres de période</div>
        <div class="filters-row">
            <div class="filter-item"><label>Année</label><select id="exerciceSelect"><?php for($y=2020;$y<=date('Y')+1;$y++): ?><option value="<?=$y?>" <?=$y==$exercice?'selected':''?>><?=$y?></option><?php endfor; ?></select></div>
            <div class="filter-item"><label>Type de période</label><select id="typePeriodeSelect"><option value="mensuel" <?=$type_periode=='mensuel'?'selected':''?>>Mensuel</option><option value="trimestre" <?=$type_periode=='trimestre'?'selected':''?>>Trimestre</option><option value="semestre" <?=$type_periode=='semestre'?'selected':''?>>Semestre</option><option value="annuel" <?=$type_periode=='annuel'?'selected':''?>>Annuel</option></select></div>
            <div class="filter-item" id="dynamicSelectContainer">
                <?php if($type_periode=='mensuel'): ?>
                    <label>Mois</label><select id="moisSelect"><?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m==$mois?'selected':''?>><?=str_pad($m,2,'0',STR_PAD_LEFT)?> - <?=date('F',mktime(0,0,0,$m,1))?></option><?php endfor; ?></select>
                <?php elseif($type_periode=='trimestre'): ?>
                    <label>Trimestre</label><select id="trimestreSelect"><?php for($t=1;$t<=4;$t++): ?><option value="<?=$t?>" <?=$t==$trimestre?'selected':''?>><?=$t?><?=$t==1?'er':'ème'?> Trimestre</option><?php endfor; ?></select>
                <?php elseif($type_periode=='semestre'): ?>
                    <label>Semestre</label><select id="semestreSelect"><?php for($s=1;$s<=2;$s++): ?><option value="<?=$s?>" <?=$s==$semestre?'selected':''?>><?=$s?><?=$s==1?'er':'e'?> semestre</option><?php endfor; ?></select>
                <?php else: ?>
                    <label>Période</label><input type="text" disabled value="Année complète">
                <?php endif; ?>
            </div>
            <button class="btn-apply" onclick="appliquerFiltres()">Appliquer</button>
        </div>
    </div>

    <!-- I-3 - Efficacité/Productivité (Tableau Excel Style) -->
    <div class="card">
        <div class="card-header"><i class="fas fa-bolt"></i> I-3 – Indicateurs d'efficacité/productivité</div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width:10%">Acc</th>
                        <th style="width:15%">Source</th>
                        <th>Indicateur</th>
                        <th class="text-right" style="width:15%">Valeur</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($tableau_efficacite_excel as $q): ?>
                    <tr>
                        <td><?= $q['code'] ?></td>
                        <td><?= $q['source'] ?></td>
                        <td><?= $q['indicateur'] ?></td>
                        <td class="text-right"><?= $q['valeur'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- I-4 - Rentabilité (Tableau Excel Style) -->
    <div class="card">
        <div class="card-header"><i class="fas fa-coins"></i> I-4 – Indicateurs de rentabilité</div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width:10%">Acc</th>
                        <th style="width:15%">Source</th>
                        <th>Indicateur</th>
                        <th class="text-right" style="width:15%">Valeur</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($tableau_rentabilite_excel as $a): ?>
                    <tr>
                        <td><?= $a['code'] ?></td>
                        <td><?= $a['source'] ?></td>
                        <td><?= $a['indicateur'] ?></td>
                        <td class="text-right"><?= $a['valeur'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- I-5 - Gestion du bilan (Tableau Excel Style) -->
    <div class="card">
        <div class="card-header"><i class="fas fa-balance-scale"></i> I-5 – Indicateurs de gestion du bilan</div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width:10%">Acc</th>
                        <th style="width:15%">Source</th>
                        <th>Indicateur</th>
                        <th class="text-right" style="width:15%">Valeur</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($tableau_gestion_excel as $b): ?>
                    <tr>
                        <td><?= $b['code'] ?></td>
                        <td><?= $b['source'] ?></td>
                        <td><?= $b['indicateur'] ?></td>
                        <td class="text-right"><?= $b['valeur'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="page-footer"><i class="fas fa-calendar-alt"></i> Généré le <?=date('d/m/Y à H:i:s')?> – Période <?=$exercice?> (<?=ucfirst($type_periode)?>) arrêtée au <?=date('d/m/Y',strtotime($date_fin_periode))?></div>
</div>

<script>
function updateDynamicSelect() {
    const type = document.getElementById('typePeriodeSelect').value;
    const container = document.getElementById('dynamicSelectContainer');
    const currentMois = <?=$mois?>;
    const currentTrimestre = <?=$trimestre?>;
    const currentSemestre = <?=json_encode($semestre)?>;
    let html = '';
    if (type === 'mensuel') {
        html = '<label>Mois</label><select id="moisSelect">';
        for (let m = 1; m <= 12; m++) { html += `<option value="${m}" ${m===currentMois?'selected':''}>${String(m).padStart(2,'0')} - ${new Date(2000,m-1,1).toLocaleString('fr',{month:'long'})}</option>`; }
        html += '</select>';
    } else if (type === 'trimestre') {
        html = '<label>Trimestre</label><select id="trimestreSelect">';
        for (let t = 1; t <= 4; t++) { html += `<option value="${t}" ${t===currentTrimestre?'selected':''}>${t}${t===1?'er':'ème'} Trimestre</option>`; }
        html += '</select>';
    } else if (type === 'semestre') {
        html = '<label>Semestre</label><select id="semestreSelect">';
        for (let s = 1; s <= 2; s++) { html += `<option value="${s}" ${s===currentSemestre?'selected':''}>${s}${s===1?'er':'e'} semestre</option>`; }
        html += '</select>';
    } else {
        html = '<label>Période</label><input type="text" disabled value="Année complète">';
    }
    container.innerHTML = html;
}

function appliquerFiltres() {
    let url = 'IF12.php?exercice=' + document.getElementById('exerciceSelect').value + '&type_periode=' + document.getElementById('typePeriodeSelect').value;
    let type = document.getElementById('typePeriodeSelect').value;
    if (type === 'mensuel') url += '&mois=' + document.getElementById('moisSelect').value;
    else if (type === 'trimestre') url += '&trimestre=' + document.getElementById('trimestreSelect').value;
    else if (type === 'semestre') url += '&semestre=' + document.getElementById('semestreSelect').value;
    window.location.href = url;
}

document.addEventListener('DOMContentLoaded', function() {
    updateDynamicSelect();
    document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
});
</script>
</body>
</html>