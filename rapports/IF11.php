<?php
// IF11.php - Indicateurs financiers (Qualité du portefeuille et activités)
// Design DIMF_2000 avec Bootstrap 5
// Titres de sections raccourcis pour tenir dans le PDF

// Activer l'affichage des erreurs pour le débogage
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// ------------------------- VÉRIFICATION DES INCLUSIONS -------------------------
$dbFile = __DIR__ . '/../databases/database.php';
$fpdfFile = __DIR__ . '/../fpdf/fpdf.php';

if (!file_exists($dbFile)) {
    die('Fichier database.php introuvable : ' . $dbFile);
}
if (!file_exists($fpdfFile)) {
    die('Fichier fpdf.php introuvable : ' . $fpdfFile);
}

require_once($dbFile);
require_once($fpdfFile);

// ------------------------- DÉFINITION DE LA CLASSE PDF EN DEHORS DU CONDITIONNEL -------------------------
class PDF_DIMF_IF11 extends FPDF {
    public $codeDimf  = 'IF11';
    public $titreDimf = 'INDICATEURS FINANCIERS (PARTIE 1)';
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
        $this->SetFont('Arial', 'B', 7);
        $this->SetFillColor(248, 250, 252);
        $this->SetTextColor(30, 41, 59);
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.2);
        foreach ($cols as $col) {
            $align = isset($col['align']) ? $col['align'] : 'L';
            $this->Cell($col['w'], 5, self::u($col['label']), 1, 0, $align, true);
        }
        $this->Ln();
    }

    function TableRow($cols, $data, $style = '') {
        $fill = false;
        if ($style == 'subtotal') {
            $this->SetFillColor(248, 250, 252);
            $this->SetFont('Arial', 'B', 7);
            $fill = true;
        } elseif ($style == 'total') {
            $this->SetFillColor(240, 253, 244);
            $this->SetFont('Arial', 'B', 7);
            $fill = true;
        } else {
            $this->SetFillColor(255, 255, 255);
            $this->SetFont('Arial', '', 7);
            $fill = false;
        }
        $this->SetTextColor(15, 23, 42);
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.1);
        foreach ($cols as $i => $col) {
            $val = isset($data[$i]) ? $data[$i] : '';
            $align = isset($col['align']) ? $col['align'] : 'L';
            $this->Cell($col['w'], 5, self::u($val), 1, 0, $align, $fill);
        }
        $this->Ln();
    }
}

// ------------------------- PARAMÈTRES (POST) -------------------------
$exercice = isset($_POST['exercice']) ? (int)$_POST['exercice'] : (isset($_SESSION['if11_exercice']) ? $_SESSION['if11_exercice'] : date('Y'));
$type_periode = isset($_POST['type_periode']) ? $_POST['type_periode'] : (isset($_SESSION['if11_type_periode']) ? $_SESSION['if11_type_periode'] : 'annuel');
$mois = isset($_POST['mois']) ? (int)$_POST['mois'] : (isset($_SESSION['if11_mois']) ? $_SESSION['if11_mois'] : 12);
$trimestre = isset($_POST['trimestre']) ? (int)$_POST['trimestre'] : (isset($_SESSION['if11_trimestre']) ? $_SESSION['if11_trimestre'] : 4);
$semestre = isset($_POST['semestre']) ? (int)$_POST['semestre'] : (isset($_SESSION['if11_semestre']) ? $_SESSION['if11_semestre'] : 2);

$_SESSION['if11_exercice'] = $exercice;
$_SESSION['if11_type_periode'] = $type_periode;
$_SESSION['if11_mois'] = $mois;
$_SESSION['if11_trimestre'] = $trimestre;
$_SESSION['if11_semestre'] = $semestre;

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

// ============================================================
// CALCUL DES INDICATEURS (identique à la version précédente)
// ============================================================

// 1. Composantes du portefeuille (B2D, B2N, B30, B40, B70)
$encours_short_term = 0;      // B2D
$encours_current_account = 0; // B2N
$encours_medium_term = 0;     // B30
$encours_long_term = 0;       // B40
$encours_arrears = 0;         // B70

try {
    // B2D
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse,0)),0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif','approuve') AND d.duree <= 12
    ");
    $stmt->execute();
    $encours_short_term = (float)$stmt->fetch()['total'];

    // B2N
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde),0) as total FROM comptes WHERE solde > 0 AND statut='actif'");
    $stmt->execute();
    $encours_current_account = (float)$stmt->fetch()['total'];

    // B30
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse,0)),0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif','approuve') AND d.duree BETWEEN 13 AND 60
    ");
    $stmt->execute();
    $encours_medium_term = (float)$stmt->fetch()['total'];

    // B40
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse,0)),0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif','approuve') AND d.duree > 60
    ");
    $stmt->execute();
    $encours_long_term = (float)$stmt->fetch()['total'];

    // B70
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse,0)),0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut = 'impaye'
    ");
    $stmt->execute();
    $encours_arrears = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

$encours_total = $encours_short_term + $encours_current_account + $encours_medium_term + $encours_long_term + $encours_arrears;

// 2. Portefeuille à risque par retard (PAR 30, 90, 180)
$encours_30 = 0;
$encours_90 = 0;
$encours_180_6_12 = 0; // B72
$encours_180_12_24 = 0; // B73

try {
    $stmt = $pdo->prepare("
    SELECT 
        d.dossier_id, 
        COALESCE(d.montant - SUM(CASE WHEN e.statut = 'payee' THEN e.montant ELSE 0 END), d.montant) as encours,
        MAX(CASE WHEN e.statut = 'attente' AND e.date_echeance < :date_fin THEN e.date_echeance ELSE NULL END) as dernier_impaye
    FROM dossiers d
    LEFT JOIN echeances e ON d.dossier_id = e.dossier_id
    WHERE d.statut IN ('actif', 'approuve', 'impaye') 
    AND d.date_octroi <= :date_fin
    GROUP BY d.dossier_id
    HAVING encours > 0
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $dossiers = $stmt->fetchAll();

    $date_ref = new DateTime($date_fin_periode);
    foreach ($dossiers as $d) {
        if ($d['dernier_impaye']) {
            $date_imp = new DateTime($d['dernier_impaye']);
            $jours = $date_ref->diff($date_imp)->days;
            if ($jours >= 30) { $encours_30 += $d['encours']; }
            if ($jours >= 90) { $encours_90 += $d['encours']; }
            if ($jours >= 180 && $jours <= 365) { $encours_180_6_12 += $d['encours']; }
            if ($jours > 365 && $jours <= 730) { $encours_180_12_24 += $d['encours']; }
        }
    }
} catch (PDOException $e) { }

$encours_180 = $encours_180_6_12 + $encours_180_12_24;
$par30 = ($encours_total > 0) ? $encours_30 / $encours_total : 0;
$par90 = ($encours_total > 0) ? $encours_90 / $encours_total : 0;
$par180 = ($encours_total > 0) ? $encours_180 / $encours_total : 0;

// 3. Provisions
$provisions = 0;
$encours_souffrance = $encours_90;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant),0) as total FROM provisions WHERE statut='actif' AND date_provision <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $provisions = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $provisions = 0; }
$taux_provision = ($encours_souffrance > 0) ? $provisions / $encours_souffrance : 0;

// 4. Taux de perte sur créances (T6K, T6L)
$pertes_couvertes = 0;
$pertes_non_couvertes = 0;
$pertes_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT pc.numero_compte, COALESCE(SUM(montant_debit),0) as total 
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '657%' AND e.date_ecriture BETWEEN :debut AND :fin
        GROUP BY pc.numero_compte
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $row) {
        if ($row['numero_compte'] == '6571') $pertes_couvertes = (float)$row['total'];
        elseif ($row['numero_compte'] == '6572') $pertes_non_couvertes = (float)$row['total'];
    }
    $pertes_total = $pertes_couvertes + $pertes_non_couvertes;
} catch (PDOException $e) { }
$taux_perte = ($encours_total > 0) ? $pertes_total / $encours_total : 0;

// 5. Activités : crédits décaissés
$total_decaisse = 0;
$nb_decaisse = 0;
try {
    $stmt = $pdo->prepare("
    SELECT COALESCE(SUM(montant),0) as total, COUNT(*) as nb 
    FROM dossiers 
    WHERE date_octroi BETWEEN :debut AND :fin AND statut IN ('actif','approuve')
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $row = $stmt->fetch();
    $total_decaisse = (float)$row['total'];
    $nb_decaisse = (int)$row['nb'];
} catch (PDOException $e) { }
$montant_moyen_credit = ($nb_decaisse > 0) ? $total_decaisse / $nb_decaisse : 0;

// 6. Épargne par composantes
$G10 = 0; $G15 = 0; $G2A = 0; $G30 = 0; $G35 = 0;
try {
    // G15 - Dépôts à terme
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(capital_initial),0) as total FROM comptes_dat WHERE statut='en cours'");
    $stmt->execute();
    $G15 = (float)$stmt->fetch()['total'];

    // G30 - Dépôts de garantie
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(c.solde),0) as total 
        FROM comptes c
        INNER JOIN produits p ON c.produit_id = p.produit_id
        WHERE p.libelle LIKE '%garantie%' AND c.statut='actif' AND c.solde>0
    ");
    $stmt->execute();
    $G30 = (float)$stmt->fetch()['total'];

    // G10 - Comptes ordinaires créditeurs (non épargne)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(solde),0) as total 
        FROM comptes 
        WHERE solde > 0 AND statut='actif' 
        AND compte_id NOT IN (SELECT compte_id FROM comptes c INNER JOIN produits p ON c.produit_id = p.produit_id INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id WHERE pf.categorie = 'Epargne')
    ");
    $stmt->execute();
    $G10 = (float)$stmt->fetch()['total'];

    // G2A - Épargne spéciale (par défaut 0)
    $G2A = 0;

    // G35 - Autres dépôts
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(c.solde),0) as total 
        FROM comptes c
        INNER JOIN produits p ON c.produit_id = p.produit_id
        INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id
        WHERE pf.categorie = 'Epargne' AND c.statut='actif' AND c.solde>0
    ");
    $stmt->execute();
    $total_epargne_brut = (float)$stmt->fetch()['total'];
    $G35 = $total_epargne_brut - $G15 - $G30;
    if ($G35 < 0) $G35 = 0;

} catch (PDOException $e) { }
$total_epargne = $G10 + $G15 + $G2A + $G30 + $G35;

$nb_epargnants = 0;
try {
    $stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT c.client_id) as nb 
    FROM comptes c
    INNER JOIN produits p ON c.produit_id = p.produit_id
    INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id
    WHERE pf.categorie = 'Epargne' AND c.statut='actif' AND c.solde>0
    ");
    $stmt->execute();
    $nb_epargnants = (int)$stmt->fetch()['nb'];
} catch (PDOException $e) { }
$montant_moyen_epargne = ($nb_epargnants > 0) ? $total_epargne / $nb_epargnants : 0;

// 7. Encours moyen des crédits par emprunteur
$nb_emprunteurs = 0;
try {
    $stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT c.client_id) as nb 
    FROM dossiers d
    INNER JOIN comptes cpt ON d.compte_id = cpt.compte_id
    INNER JOIN clients c ON cpt.client_id = c.client_id
    WHERE d.statut IN ('actif','approuve') AND d.date_octroi <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $nb_emprunteurs = (int)$stmt->fetch()['nb'];
} catch (PDOException $e) { }
$encours_moyen_emprunteur = ($nb_emprunteurs > 0) ? $encours_total / $nb_emprunteurs : 0;

// ============================================================
// PRÉPARATION DES TABLEAUX AVEC COLONNE "SECTION"
// ============================================================

// Titres de sections raccourcis pour tenir dans le PDF
$sectionTitles = [
    'PAR 30 jours',
    'PAR 90 jours',
    'PAR 180 jours',
    'Taux provisions',
    'Taux perte',
    'Montant moyen crédits',
    'Montant moyen épargne',
    'Encours moyen par emprunteur'
];

// Tableau I-1 : Qualité du portefeuille
$tableau_qualite_excel = [];

// Section 1 : PAR 30
$tableau_qualite_excel[] = ['section' => $sectionTitles[0], 'code' => 'Z60', 'source' => 'DIMF_2000_ACTIF_DEV', 'indicateur' => 'Encours des prêts comportant au moins une échéance impayée de 30 jours (A)', 'valeur' => number_format($encours_30, 0, ',', ' '), 'note' => ''];
$tableau_qualite_excel[] = ['section' => '', 'code' => 'B2D', 'source' => 'Actif brut', 'indicateur' => 'Crédits à court terme', 'valeur' => number_format($encours_short_term, 0, ',', ' '), 'note' => ''];
$tableau_qualite_excel[] = ['section' => '', 'code' => 'B2N', 'source' => 'Actif brut', 'indicateur' => 'Comptes ordinaires', 'valeur' => number_format($encours_current_account, 0, ',', ' '), 'note' => ''];
$tableau_qualite_excel[] = ['section' => '', 'code' => 'B30', 'source' => 'Actif brut', 'indicateur' => 'Crédits à moyen terme', 'valeur' => number_format($encours_medium_term, 0, ',', ' '), 'note' => ''];
$tableau_qualite_excel[] = ['section' => '', 'code' => 'B40', 'source' => 'Actif brut', 'indicateur' => 'Crédits à long terme', 'valeur' => number_format($encours_long_term, 0, ',', ' '), 'note' => ''];
$tableau_qualite_excel[] = ['section' => '', 'code' => 'B70', 'source' => 'Actif brut', 'indicateur' => 'Crédits en souffrance', 'valeur' => number_format($encours_arrears, 0, ',', ' '), 'note' => ''];
$tableau_qualite_excel[] = ['section' => '', 'code' => '', 'source' => 'Actif brut', 'indicateur' => 'Montant brut du portefeuille de prêts (B)', 'valeur' => number_format($encours_total, 0, ',', ' '), 'note' => ''];
$tableau_qualite_excel[] = ['section' => '', 'code' => '', 'source' => 'DIMF_2000_ACTIF_DEV', 'indicateur' => 'Ratio A/B', 'valeur' => number_format($par30 * 100, 2) . '%', 'note' => ''];

// Section 2 : PAR 90
$tableau_qualite_excel[] = ['section' => $sectionTitles[1], 'code' => 'B70', 'source' => 'Actif brut', 'indicateur' => 'Encours des prêts comportant au moins une échéance impayée de 90 jours (A)', 'valeur' => number_format($encours_90, 0, ',', ' '), 'note' => ''];
$tableau_qualite_excel[] = ['section' => '', 'code' => '', 'source' => 'DIMF_2000_ACTIF_DEV', 'indicateur' => 'Montant brut du portfeuille de prêts (B)', 'valeur' => number_format($encours_total, 0, ',', ' '), 'note' => ''];
$tableau_qualite_excel[] = ['section' => '', 'code' => '', 'source' => 'DIMF_2000_ACTIF_DEV', 'indicateur' => 'Ratio A/B (Norme <= 3%)', 'valeur' => number_format($par90 * 100, 2) . '%', 'note' => ''];

// Section 3 : PAR 180 (uniquement B72 et B73)
$tableau_qualite_excel[] = ['section' => $sectionTitles[2], 'code' => 'B72', 'source' => 'Actif brut', 'indicateur' => 'Crédits en souffrance de plus de 6 mois à 12 mois au plus', 'valeur' => number_format($encours_180_6_12, 0, ',', ' '), 'note' => ''];
$tableau_qualite_excel[] = ['section' => '', 'code' => 'B73', 'source' => 'Actif brut', 'indicateur' => 'Crédits en souffrance de plus de 12 mois à 24 mois au plus', 'valeur' => number_format($encours_180_12_24, 0, ',', ' '), 'note' => ''];
$tableau_qualite_excel[] = ['section' => '', 'code' => '', 'source' => 'Actif brut', 'indicateur' => 'Montant brut du portfeuille de prêts (B)', 'valeur' => number_format($encours_total, 0, ',', ' '), 'note' => ''];
$tableau_qualite_excel[] = ['section' => '', 'code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme <= 2%)', 'valeur' => number_format($par180 * 100, 2) . '%', 'note' => ''];

// Section 4 : Taux de provisions
$tableau_qualite_excel[] = ['section' => $sectionTitles[3], 'code' => 'B70', 'source' => 'Amortissement', 'indicateur' => 'Montant brut des provisions constituées (A)', 'valeur' => number_format($provisions, 0, ',', ' '), 'note' => ''];
$tableau_qualite_excel[] = ['section' => '', 'code' => 'B70', 'source' => 'Actif brut', 'indicateur' => 'Montant brut des créances en souffrance (B)', 'valeur' => number_format($encours_90, 0, ',', ' '), 'note' => ''];
$tableau_qualite_excel[] = ['section' => '', 'code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme >= 40%)', 'valeur' => number_format($taux_provision * 100, 2) . '%', 'note' => ''];

// Section 5 : Taux de perte (uniquement T6K et T6L)
$tableau_qualite_excel[] = ['section' => $sectionTitles[4], 'code' => 'T6K', 'source' => 'Charges', 'indicateur' => 'Pertes sur créances irrécouvrables couvertes par des provisions', 'valeur' => number_format($pertes_couvertes, 0, ',', ' '), 'note' => ''];
$tableau_qualite_excel[] = ['section' => '', 'code' => 'T6L', 'source' => 'Charges', 'indicateur' => 'Pertes sur créances irrécouvrables non couvertes par des provisions', 'valeur' => number_format($pertes_non_couvertes, 0, ',', ' '), 'note' => ''];
$tableau_qualite_excel[] = ['section' => '', 'code' => '', 'source' => '', 'indicateur' => 'Montant brut du portfeuille de crédit de la période (B)', 'valeur' => number_format($encours_total, 0, ',', ' '), 'note' => ''];
$tableau_qualite_excel[] = ['section' => '', 'code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme <= 2%)', 'valeur' => number_format($taux_perte * 100, 2) . '%', 'note' => ''];

// Tableau I-2 : Indicateurs d'activités
$tableau_activites_excel = [];

// Section 6 : Montant moyen crédits décaissés
$tableau_activites_excel[] = ['section' => $sectionTitles[5], 'code' => 'Y04101', 'source' => 'Instruction N°18', 'indicateur' => 'Montant total des crédits décaissés au cours de la période (A)', 'valeur' => number_format($total_decaisse, 0, ',', ' '), 'note' => ''];
$tableau_activites_excel[] = ['section' => '', 'code' => 'Y04201', 'source' => 'Instruction N°18', 'indicateur' => 'Nombre total des crédits décaissés au cours de la période (B)', 'valeur' => number_format($nb_decaisse, 0, ',', ' '), 'note' => ''];
$tableau_activites_excel[] = ['section' => '', 'code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme: Tendence haussière)', 'valeur' => number_format($montant_moyen_credit, 0, ',', ' '), 'note' => ''];

// Section 7 : Montant moyen épargne (uniquement les 5 composantes)
$tableau_activites_excel[] = ['section' => $sectionTitles[6], 'code' => 'G10', 'source' => 'Passif', 'indicateur' => 'Comptes ordinaires créditeurs', 'valeur' => number_format($G10, 0, ',', ' '), 'note' => ''];
$tableau_activites_excel[] = ['section' => '', 'code' => 'G15', 'source' => 'Passif', 'indicateur' => 'Dépôts à terme reçus', 'valeur' => number_format($G15, 0, ',', ' '), 'note' => ''];
$tableau_activites_excel[] = ['section' => '', 'code' => 'G2A', 'source' => 'Passif', 'indicateur' => 'Comptes d\'épargne à régime spécial', 'valeur' => number_format($G2A, 0, ',', ' '), 'note' => ''];
$tableau_activites_excel[] = ['section' => '', 'code' => 'G30', 'source' => 'Passif', 'indicateur' => 'Autres dépôts de garantie reçus', 'valeur' => number_format($G30, 0, ',', ' '), 'note' => ''];
$tableau_activites_excel[] = ['section' => '', 'code' => 'G35', 'source' => 'Passif', 'indicateur' => 'Autres dépôts reçus', 'valeur' => number_format($G35, 0, ',', ' '), 'note' => ''];
$tableau_activites_excel[] = ['section' => '', 'code' => 'Y03301', 'source' => 'Instruction N°18', 'indicateur' => 'Nombre d\'épargnant à la fin de la période  (B)', 'valeur' => number_format($nb_epargnants, 0, ',', ' '), 'note' => 'Nombre de personnes disposant d\'un ou de plusieurs dépôts auprès de l\'institution, y compris l\'épargne obligatoire (un individu ne peut être compté plus d\'une fois)'];
$tableau_activites_excel[] = ['section' => '', 'code' => '', 'source' => 'ANNEXES_AU_RAPPORT_ANNUEL', 'indicateur' => 'Ratio A/B (Norme: Tendence haussière)', 'valeur' => number_format($montant_moyen_epargne, 0, ',', ' '), 'note' => ''];

// Section 8 : Encours moyen par emprunteur
$tableau_activites_excel[] = ['section' => $sectionTitles[7], 'code' => '', 'source' => '', 'indicateur' => 'Total des encours des crédits à la fin de la période  (A)', 'valeur' => number_format($encours_total, 0, ',', ' '), 'note' => ''];
$tableau_activites_excel[] = ['section' => '', 'code' => 'Y04501', 'source' => 'Instruction N°18', 'indicateur' => 'Nombre d\'emprunteurs actifs (A)', 'valeur' => number_format($nb_emprunteurs, 0, ',', ' '), 'note' => 'Nombre de personnes ayant un encours vis-à-vis de l\'institution (un individu ne peut être compté plus d\'une fois)'];
$tableau_activites_excel[] = ['section' => '', 'code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme: Tendence haussière)', 'valeur' => number_format($encours_moyen_emprunteur, 0, ',', ' '), 'note' => ''];

// ------------------------- EXPORT PDF AVEC GESTION D'ERREUR -------------------------
if (isset($_POST['export']) && $_POST['export'] === 'pdf') {
    try {
        if (ob_get_length()) ob_clean();

        if (!class_exists('PDF_DIMF_IF11')) {
            throw new Exception('PDF_DIMF_IF11 class not found.');
        }

        $pdf = new PDF_DIMF_IF11();
        $pdf->AliasNbPages();
        $pdf->codeDimf = 'IF11';
        $pdf->titreDimf = 'INDICATEURS FINANCIERS (PARTIE 1)';
        $pdf->nomSfd = 'SFD';
        $pdf->periode = ucfirst($type_periode);
        $pdf->exercice = $exercice;
        $pdf->AddPage();

        // Largeurs ajustées pour une page A4 (largeur utile ~190 mm)
        $cols = [
            ['w' => 32, 'label' => 'Section', 'align' => 'L'],
            ['w' => 18, 'label' => 'Code', 'align' => 'L'],
            ['w' => 25, 'label' => 'Source', 'align' => 'L'],
            ['w' => 68, 'label' => 'Indicateur', 'align' => 'L'],
            ['w' => 22, 'label' => 'Valeur', 'align' => 'R'],
            ['w' => 25, 'label' => 'Note', 'align' => 'L']
        ];

        $pdf->SectionTitle("I-1 - INDICATEUR DE QUALITE DU PORTEFEUILLE");
        $pdf->TableHeader($cols);
        foreach ($tableau_qualite_excel as $row) {
            $pdf->TableRow($cols, [$row['section'], $row['code'], $row['source'], $row['indicateur'], $row['valeur'], $row['note']]);
        }

        $pdf->Ln(5);
        $pdf->SectionTitle("I-2 - INDICATEURS D'ACTIVITES");
        $pdf->TableHeader($cols);
        foreach ($tableau_activites_excel as $row) {
            $pdf->TableRow($cols, [$row['section'], $row['code'], $row['source'], $row['indicateur'], $row['valeur'], $row['note']]);
        }

        $pdf->Output('I', 'IF11_' . $exercice . '_' . $type_periode . '.pdf');
        exit;

    } catch (Exception $e) {
        die('Erreur lors de la génération du PDF : ' . $e->getMessage() . ' dans ' . $e->getFile() . ' à la ligne ' . $e->getLine());
    }
}

// ------------------------- EXPORT EXCEL -------------------------
if (isset($_POST['export']) && $_POST['export'] === 'excel') {
    if (ob_get_length()) ob_clean();

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="IF11_' . $exercice . '_' . $type_periode . '.xls"');
    header('Cache-Control: max-age=0');

    echo '<html><head><meta charset="UTF-8"><style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    h2 { color: #1a3a5c; }
    table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
    th, td { border: 1px solid #999; padding: 8px; }
    th { background: #f2f2f2; text-align: center; font-weight: bold; }
    .text-right { text-align: right; }
    .text-left { text-align: left; }
    </style></head><body>';
    
    echo '<h2>IF 11 - INDICATEURS FINANCIERS (Qualité du portefeuille et activités)</h2>';
    echo '<p>Période : ' . $exercice . ' - ' . ucfirst($type_periode) . ' (arrêtée au ' . date('d/m/Y', strtotime($date_fin_periode)) . ')</p>';
    
    echo '<h3>I-1 - Indicateur de qualité du portefeuille</h3>';
    echo '<table>';
    echo '<tr><th>Section</th><th>Code</th><th>Source</th><th>Indicateur</th><th class="text-right">Valeur</th><th>Note</th></tr>';
    foreach ($tableau_qualite_excel as $q) {
        echo "<tr>
            <td>{$q['section']}</td>
            <td>{$q['code']}</td>
            <td>{$q['source']}</td>
            <td>{$q['indicateur']}</td>
            <td class='text-right'>{$q['valeur']}</td>
            <td>{$q['note']}</td>
        </tr>";
    }
    echo '</table>';

    echo '<h3>I-2 - Indicateurs d\'activités</h3>';
    echo '<table>';
    echo '<tr><th>Section</th><th>Code</th><th>Source</th><th>Indicateur</th><th class="text-right">Valeur</th><th>Note</th></tr>';
    foreach ($tableau_activites_excel as $a) {
        echo "<tr>
            <td>{$a['section']}</td>
            <td>{$a['code']}</td>
            <td>{$a['source']}</td>
            <td>{$a['indicateur']}</td>
            <td class='text-right'>{$a['valeur']}</td>
            <td>{$a['note']}</td>
        </tr>";
    }
    echo '</table>';
    
    echo '</body></html>';
    exit;
}

// ------------------------- AFFICHAGE WEB (Bootstrap 5 + Design DIMF_2000) -------------------------
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>IF 11 - Indicateurs financiers (DSFD)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',system-ui,sans-serif; background:#f1f5f9; padding:24px; }
        .dashboard { max-width:1400px; margin:0 auto; }
        .page-header { background:linear-gradient(135deg,#3b82f6,#60a5fa); border-radius:24px; padding:20px 28px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; }
        .header-left h1 { font-size:1.6rem; font-weight:600; color:white; display:flex; align-items:center; gap:10px; }
        .subtitle { font-size:0.8rem; color:#e0f2fe; }
        .badge-custom { background:#2563eb; color:white; padding:4px 12px; border-radius:30px; display:inline-block; margin-top:8px; font-size:0.7rem; }
        .btn-group-custom { display:flex; gap:12px; }
        .btn-excel, .btn-pdf { padding:8px 20px; border-radius:40px; font-weight:500; border:none; cursor:pointer; transition:0.2s; }
        .btn-excel { background:#10b981; color:white; }
        .btn-excel:hover { background:#059669; transform:translateY(-1px); }
        .btn-pdf { background:#ef4444; color:white; }
        .btn-pdf:hover { background:#dc2626; transform:translateY(-1px); }
        .card-custom { background:white; border-radius:20px; padding:20px 24px; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:none; }
        .card-header-custom { display:flex; align-items:center; gap:10px; border-bottom:1px solid #eef2f6; padding-bottom:12px; margin-bottom:16px; font-weight:600; color:#1e40af; }
        .card-header-custom i { color:#3b82f6; }
        .filter-item-custom label { font-size:0.7rem; font-weight:600; text-transform:uppercase; color:#4b5563; margin-bottom:4px; }
        .table-wrapper { overflow-x:auto; }
        .table-dIMF th { background:#f8fafc; font-weight:600; color:#1e293b; border-bottom:1px solid #e2e8f0; }
        .table-dIMF td { border-bottom:1px solid #f1f5f9; color:#0f172a; }
        .table-dIMF .text-right { text-align:right; }
        .page-footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; }
        .ratio-value { font-size:1.5rem; font-weight:700; }
        .ratio-value.conforme { color:#10b981; }
        .ratio-value.non-conforme { color:#ef4444; }
        .progress-bar-custom { background:#e2e8f0; border-radius:50px; height:24px; overflow:hidden; margin-top:20px; }
        .progress-fill-custom { background:linear-gradient(90deg,#3b82f6,#60a5fa); height:100%; border-radius:50px; text-align:center; color:white; font-size:0.75rem; line-height:24px; }
        @media print { .btn-group-custom, .filters-card, .btn-apply { display:none; } }
        .form-select, .form-control { border-radius:12px; padding:8px 14px; }
        .btn-primary-custom { background:#3b82f6; border:none; border-radius:40px; padding:8px 24px; transition:0.2s; }
        .btn-primary-custom:hover { background:#2563eb; transform:translateY(-1px); }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-chart-line"></i> IF 11 - INDICATEURS FINANCIERS</h1>
            <div class="subtitle">République de Côte d'Ivoire / DGTCP / DSFD</div>
            <div class="badge-custom">Partie 1 : Qualité du portefeuille et indicateurs d'activités</div>
        </div>
        <div class="btn-group-custom">
            <form method="POST" action="" style="display:inline-block;" id="excelForm">
                <input type="hidden" name="exercice" value="<?= $exercice ?>">
                <input type="hidden" name="type_periode" value="<?= $type_periode ?>">
                <input type="hidden" name="mois" value="<?= $mois ?>">
                <input type="hidden" name="trimestre" value="<?= $trimestre ?>">
                <input type="hidden" name="semestre" value="<?= $semestre ?>">
                <input type="hidden" name="export" value="excel">
                <button type="submit" class="btn-excel"><i class="fas fa-file-excel"></i> Excel</button>
            </form>
            <form method="POST" action="" style="display:inline-block;" id="pdfForm">
                <input type="hidden" name="exercice" value="<?= $exercice ?>">
                <input type="hidden" name="type_periode" value="<?= $type_periode ?>">
                <input type="hidden" name="mois" value="<?= $mois ?>">
                <input type="hidden" name="trimestre" value="<?= $trimestre ?>">
                <input type="hidden" name="semestre" value="<?= $semestre ?>">
                <input type="hidden" name="export" value="pdf">
                <button type="submit" class="btn-pdf"><i class="fas fa-file-pdf"></i> PDF</button>
            </form>
        </div>
    </div>

    <!-- Filtres période -->
    <div class="card-custom filters-card">
        <div class="card-header-custom"><i class="fas fa-sliders-h"></i> Filtres de période</div>
        <form method="POST" action="" id="filtersForm">
            <div class="row g-3 align-items-end">
                <div class="col-auto">
                    <div class="filter-item-custom">
                        <label>Année</label>
                        <select name="exercice" class="form-select">
                            <?php for($y=2020; $y<=date('Y')+1; $y++): ?>
                                <option value="<?=$y?>" <?=$y==$exercice?'selected':''?>><?=$y?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div class="col-auto">
                    <div class="filter-item-custom">
                        <label>Type de période</label>
                        <select name="type_periode" id="typePeriodeSelect" class="form-select">
                            <option value="mensuel" <?=$type_periode=='mensuel'?'selected':''?>>Mensuel</option>
                            <option value="trimestre" <?=$type_periode=='trimestre'?'selected':''?>>Trimestre</option>
                            <option value="semestre" <?=$type_periode=='semestre'?'selected':''?>>Semestre</option>
                            <option value="annuel" <?=$type_periode=='annuel'?'selected':''?>>Annuel</option>
                        </select>
                    </div>
                </div>
                <div class="col-auto" id="dynamicSelectContainer">
                    <?php if($type_periode=='mensuel'): ?>
                        <div class="filter-item-custom">
                            <label>Mois</label>
                            <select name="mois" class="form-select">
                                <?php for($m=1;$m<=12;$m++): ?>
                                    <option value="<?=$m?>" <?=$m==$mois?'selected':''?>><?=str_pad($m,2,'0',STR_PAD_LEFT)?> - <?=date('F',mktime(0,0,0,$m,1))?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    <?php elseif($type_periode=='trimestre'): ?>
                        <div class="filter-item-custom">
                            <label>Trimestre</label>
                            <select name="trimestre" class="form-select">
                                <?php for($t=1;$t<=4;$t++): ?>
                                    <option value="<?=$t?>" <?=$t==$trimestre?'selected':''?>><?=$t?><?=$t==1?'er':'ème'?> Trimestre</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    <?php elseif($type_periode=='semestre'): ?>
                        <div class="filter-item-custom">
                            <label>Semestre</label>
                            <select name="semestre" class="form-select">
                                <?php for($s=1;$s<=2;$s++): ?>
                                    <option value="<?=$s?>" <?=$s==$semestre?'selected':''?>><?=$s?><?=$s==1?'er':'e'?> semestre</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <div class="filter-item-custom">
                            <label>Période</label>
                            <input type="text" class="form-control" disabled value="Année complète" style="background:#f3f4f6;">
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary-custom"><i class="fas fa-filter"></i> Appliquer</button>
                </div>
            </div>
        </form>
        <div class="mt-2">
            <small class="text-muted"><i class="fas fa-info-circle"></i> Période : <?= ucfirst($type_periode) ?> <?= $exercice ?> (arrêtée au <?= date('d/m/Y',strtotime($date_fin_periode)) ?>)</small>
        </div>
    </div>

    <!-- I-1 - Qualité du portefeuille -->
    <div class="card-custom">
        <div class="card-header-custom"><i class="fas fa-chart-simple"></i> I-1 – Indicateur de qualité du portefeuille</div>
        <div class="table-wrapper">
            <table class="table table-dIMF">
                <thead>
                    <tr>
                        <th style="width:12%">Section</th>
                        <th style="width:8%">Code</th>
                        <th style="width:12%">Source</th>
                        <th>Indicateur</th>
                        <th class="text-right" style="width:10%">Valeur</th>
                        <th style="width:18%">Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($tableau_qualite_excel as $q): ?>
                    <tr>
                        <td><?= $q['section'] ?></td>
                        <td><?= $q['code'] ?></td>
                        <td><?= $q['source'] ?></td>
                        <td><?= $q['indicateur'] ?></td>
                        <td class="text-right"><?= $q['valeur'] ?></td>
                        <td><?= $q['note'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- I-2 - Indicateurs d'activités -->
    <div class="card-custom">
        <div class="card-header-custom"><i class="fas fa-chart-simple"></i> I-2 – Indicateurs d'activités</div>
        <div class="table-wrapper">
            <table class="table table-dIMF">
                <thead>
                    <tr>
                        <th style="width:12%">Section</th>
                        <th style="width:8%">Code</th>
                        <th style="width:12%">Source</th>
                        <th>Indicateur</th>
                        <th class="text-right" style="width:10%">Valeur</th>
                        <th style="width:18%">Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($tableau_activites_excel as $a): ?>
                    <tr>
                        <td><?= $a['section'] ?></td>
                        <td><?= $a['code'] ?></td>
                        <td><?= $a['source'] ?></td>
                        <td><?= $a['indicateur'] ?></td>
                        <td class="text-right"><?= $a['valeur'] ?></td>
                        <td><?= $a['note'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Résumé des indicateurs clés (inchangé) -->
    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card-custom h-100">
                <div class="card-header-custom"><i class="fas fa-chart-pie"></i> Résumé Qualité du Portefeuille</div>
                <div class="mt-3">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span>PAR 30</span>
                            <span class="fw-bold <?= $par30 <= 0.05 ? 'text-success' : 'text-danger' ?>"><?= number_format($par30 * 100, 2) ?>%</span>
                        </div>
                        <div class="progress-bar-custom">
                            <div class="progress-fill-custom" style="width: <?= min($par30 * 100, 100) ?>%;"><?= number_format($par30 * 100, 2) ?>%</div>
                        </div>
                        <small class="text-muted">Norme ≤ 5%</small>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span>PAR 90</span>
                            <span class="fw-bold <?= $par90 <= 0.03 ? 'text-success' : 'text-danger' ?>"><?= number_format($par90 * 100, 2) ?>%</span>
                        </div>
                        <div class="progress-bar-custom">
                            <div class="progress-fill-custom" style="width: <?= min($par90 * 100, 100) ?>%;"><?= number_format($par90 * 100, 2) ?>%</div>
                        </div>
                        <small class="text-muted">Norme ≤ 3%</small>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span>Taux de provision</span>
                            <span class="fw-bold <?= $taux_provision >= 0.40 ? 'text-success' : 'text-danger' ?>"><?= number_format($taux_provision * 100, 2) ?>%</span>
                        </div>
                        <div class="progress-bar-custom">
                            <div class="progress-fill-custom" style="width: <?= min($taux_provision * 100, 100) ?>%;"><?= number_format($taux_provision * 100, 2) ?>%</div>
                        </div>
                        <small class="text-muted">Norme ≥ 40%</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 mb-4">
            <div class="card-custom h-100">
                <div class="card-header-custom"><i class="fas fa-chart-line"></i> Résumé Activités</div>
                <div class="mt-3">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span>Montant moyen crédit</span>
                            <span class="fw-bold"><?= number_format($montant_moyen_credit, 0, ',', ' ') ?> FCFA</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span>Montant moyen épargne</span>
                            <span class="fw-bold"><?= number_format($montant_moyen_epargne, 0, ',', ' ') ?> FCFA</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span>Encours moyen par emprunteur</span>
                            <span class="fw-bold"><?= number_format($encours_moyen_emprunteur, 0, ',', ' ') ?> FCFA</span>
                        </div>
                    </div>
                    <div class="alert alert-info mt-3 mb-0">
                        <i class="fas fa-info-circle"></i> Total portefeuille : <?= number_format($encours_total, 0, ',', ' ') ?> FCFA
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="page-footer">
        <i class="fas fa-calendar-alt"></i> Généré le <?=date('d/m/Y à H:i:s')?> – Période <?=$exercice?> (<?=ucfirst($type_periode)?>) arrêtée au <?=date('d/m/Y',strtotime($date_fin_periode))?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateDynamicSelect() {
    const type = document.getElementById('typePeriodeSelect').value;
    const container = document.getElementById('dynamicSelectContainer');
    const currentMois = <?=$mois?>;
    const currentTrimestre = <?=$trimestre?>;
    const currentSemestre = <?=json_encode($semestre)?>;
    let html = '';
    
    if (type === 'mensuel') {
        html = '<div class="filter-item-custom"><label>Mois</label><select name="mois" class="form-select">';
        for (let m = 1; m <= 12; m++) {
            const selected = (m === currentMois) ? 'selected' : '';
            const monthName = new Date(2000, m-1, 1).toLocaleString('fr', {month:'long'});
            html += `<option value="${m}" ${selected}>${String(m).padStart(2,'0')} - ${monthName}</option>`;
        }
        html += '</select></div>';
    } else if (type === 'trimestre') {
        html = '<div class="filter-item-custom"><label>Trimestre</label><select name="trimestre" class="form-select">';
        for (let t = 1; t <= 4; t++) {
            const selected = (t === currentTrimestre) ? 'selected' : '';
            html += `<option value="${t}" ${selected}>${t}${t === 1 ? 'er' : 'ème'} Trimestre</option>`;
        }
        html += '</select></div>';
    } else if (type === 'semestre') {
        html = '<div class="filter-item-custom"><label>Semestre</label><select name="semestre" class="form-select">';
        for (let s = 1; s <= 2; s++) {
            const selected = (s === currentSemestre) ? 'selected' : '';
            html += `<option value="${s}" ${selected}>${s}${s === 1 ? 'er' : 'e'} semestre</option>`;
        }
        html += '</select></div>';
    } else {
        html = '<div class="filter-item-custom"><label>Période</label><input type="text" class="form-control" disabled value="Année complète" style="background:#f3f4f6;"></div>';
    }
    container.innerHTML = html;
}

document.addEventListener('DOMContentLoaded', function() {
    const typeSelect = document.getElementById('typePeriodeSelect');
    if (typeSelect) {
        typeSelect.addEventListener('change', updateDynamicSelect);
    }
});
</script>
</body>
</html>