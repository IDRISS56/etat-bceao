<?php
// DIMF_2000.php - Bilan Actif, Passif et Hors Bilan
// Version conforme au fichier Excel DIMF2000.xlsx (formules exactes)
// PDF en soumission classique (même fenêtre) - comportement identique à DIMF_2900

session_start();

require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

class PDF_DIMF extends FPDF {
    public $codeDimf  = 'DIMF';
    public $titreDimf = 'Etat financier';
    public $nomSfd    = 'SFD';
    public $periode   = '';
    public $exercice  = '';

    static function u($str) {
        return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
    }

    function Header() {
        $this->SetFillColor(156, 163, 175);
        $this->Rect(0, 0, $this->GetPageWidth(), 28, 'F');
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(8, 3);
        $this->Cell(0, 4, self::u('République de Côte d\'Ivoire  •  Ministère de l\'Économie et des Finances  -  DGTCP / DSFD'), 0, 1, 'L');
        $this->SetFont('Arial', 'B', 13);
        $this->SetX(8);
        $this->Cell(0, 7, self::u($this->codeDimf . '  -  ' . $this->titreDimf), 0, 1, 'L');
        $this->SetFont('Arial', '', 8);
        $this->SetX(8);
        $this->Cell(0, 5, self::u('SFD : ' . $this->nomSfd . '   |   Période : ' . $this->periode . '   |   Exercice : ' . $this->exercice . '   |   Arrêté au : ' . date('d/m/Y')), 0, 1, 'L');
        $this->SetTextColor(0, 0, 0);
        $this->Ln(4);
    }

    function Footer() {
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 4, self::u('SICS-BCEAO  •  Généré le ' . date('d/m/Y H:i:s') . '  •  Page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
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
        }
        $this->SetTextColor(15, 23, 42);
        $this->SetDrawColor(226, 232, 240);
        foreach ($cols as $i => $col) {
            $val = isset($data[$i]) ? $data[$i] : '';
            $align = isset($col['align']) ? $col['align'] : 'L';
            $this->Cell($col['w'], 5.5, self::u($val), 1, 0, $align, $fill);
        }
        $this->Ln();
    }

    static function montant($val) {
        return number_format((float)$val, 0, ',', ' ') . ' F';
    }
}

// ============================================================
// PARAMÈTRES
// ============================================================
$exercice     = isset($_POST['exercice'])     ? (int)$_POST['exercice']     : (isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y'));
$type_periode = isset($_POST['type_periode']) ? $_POST['type_periode']      : (isset($_GET['type_periode']) ? $_GET['type_periode'] : 'mensuel');
$mois         = isset($_POST['mois'])         ? (int)$_POST['mois']         : (isset($_GET['mois']) ? (int)$_GET['mois'] : 12);
$trimestre    = isset($_POST['trimestre'])    ? (int)$_POST['trimestre']    : (isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 4);
$semestre     = isset($_POST['semestre'])     ? (int)$_POST['semestre']     : (isset($_GET['semestre']) ? (int)$_GET['semestre'] : null);
$format       = isset($_POST['format'])       ? $_POST['format']            : (isset($_GET['format']) ? $_GET['format'] : 'html');

switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
}
$date_fin_periode = date('Y-m-t', strtotime($exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01'));
$date_debut_exercice = $exercice . '-01-01';

switch ($type_periode) {
    case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
    case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
    case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
    default:          $lib_periode = 'Année ' . $exercice;
}

// ============================================================
// CALCULS BILAN ACTIF
// ============================================================

// --- A10 Valeur en caisse ---
$A10_brut = 0;
try { $stmt = $pdo->query("SELECT COALESCE(SUM(solde_actuel), 0) FROM caisses WHERE statut = 'ouverte'"); $A10_brut = (float)$stmt->fetchColumn(); } catch (PDOException $e) {}

// A11 Billets et monnaies (identique à A10)
$A11_brut = $A10_brut;

// A12 Comptes ordinaires débiteurs (solde > 0)
$A12_brut = 0;
try { $stmt = $pdo->query("SELECT COALESCE(SUM(solde), 0) FROM comptes WHERE solde > 0 AND statut = 'actif'"); $A12_brut = (float)$stmt->fetchColumn(); } catch (PDOException $e) {}

// A2A Autres comptes de dépôts débiteurs
$A2H_brut = 0;
try { $stmt = $pdo->query("SELECT COALESCE(SUM(montant_place), 0) FROM comptes_dat WHERE statut = 'en cours'"); $A2H_brut = (float)$stmt->fetchColumn(); } catch (PDOException $e) {}
$A2I_brut = 0;
$A2J_brut = 0;
$A2A_brut = $A2H_brut + $A2I_brut + $A2J_brut;

// A3A Comptes de prêts
$A3B_brut = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0)
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif','approuve') AND d.duree <= 12
    ");
    $stmt->execute();
    $A3B_brut = (float)$stmt->fetchColumn();
} catch (PDOException $e) {}

$A3C_brut = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0)
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif','approuve') AND d.duree > 12
    ");
    $stmt->execute();
    $A3C_brut = (float)$stmt->fetchColumn();
} catch (PDOException $e) {}
$A3A_brut = $A3B_brut + $A3C_brut;

// A60 Créances rattachées
$A60_brut = 0;

// A70 Prêts en souffrance (Z01 + A71+A72+A73)
$Z01_brut = 0;
$A71_brut = $A72_brut = $A73_brut = 0;
try {
    $stmt = $pdo->query("
        SELECT e.montant, DATEDIFF(CURDATE(), e.date_echeance) as retard
        FROM echeances e
        JOIN dossiers d ON e.dossier_id = d.dossier_id
        WHERE e.statut = 'impayee' AND d.statut = 'impaye'
    ");
    while ($row = $stmt->fetch()) {
        $retard = (int)$row['retard'];
        $montant = (float)$row['montant'];
        if ($retard <= 180) $A71_brut += $montant;
        elseif ($retard <= 360) $A72_brut += $montant;
        else $A73_brut += $montant;
    }
} catch (PDOException $e) {}
$A70_brut = $Z01_brut + $A71_brut + $A72_brut + $A73_brut;
$A70_amort = 0;
try { $stmt = $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM provisions WHERE statut='actif' AND type_provision='CREANCES'"); $A70_amort = (float)$stmt->fetchColumn(); } catch (PDOException $e) {}

// A01 = A10 + A12 + A2A + A3A + A60 + A70
$A01_brut  = $A10_brut + $A12_brut + $A2A_brut + $A3A_brut + $A60_brut + $A70_brut;
$A01_amort = $A70_amort;

// --- B01 Opérations avec les membres ---
$B2D_brut = $A3B_brut;
$B2N_brut = 0;
$B30_brut = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0)
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif','approuve') AND d.duree BETWEEN 13 AND 60
    ");
    $stmt->execute();
    $B30_brut = (float)$stmt->fetchColumn();
} catch (PDOException $e) {}
$B40_brut = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0)
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif','approuve') AND d.duree > 60
    ");
    $stmt->execute();
    $B40_brut = (float)$stmt->fetchColumn();
} catch (PDOException $e) {}
$B65_brut = 0;
$B70_brut = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0)
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut = 'impaye'
    ");
    $stmt->execute();
    $B70_brut = (float)$stmt->fetchColumn();
} catch (PDOException $e) {}
$B70_amort = 0;
try { $stmt = $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM provisions WHERE statut='actif' AND type_provision='CREANCES'"); $B70_amort = (float)$stmt->fetchColumn(); } catch (PDOException $e) {}

$Z02_brut = 0;
$B71_brut = $A71_brut;
$B72_brut = $A72_brut;
$B73_brut = $A73_brut;
$B71_amort = $B72_amort = $B73_amort = 0;

$B01_brut  = $B2D_brut + $B2N_brut + $B30_brut + $B40_brut + $B65_brut + $B70_brut;
$B01_amort = $B70_amort;

// --- C01 Opérations sur titres et opérations diverses ---
$C10_brut = 0;
$C31_brut = $C32_brut = $C33_brut = $C34_brut = 0;
$C30_brut = $C31_brut + $C32_brut + $C33_brut + $C34_brut;
$C40_brut = 0;
try { $stmt = $pdo->query("SELECT COALESCE(SUM(montant_debit - montant_credit), 0) FROM ecritures_comptables WHERE statut='VALIDEE' AND compte_general LIKE '46%'"); $C40_brut = (float)$stmt->fetchColumn(); } catch (PDOException $e) {}
$C40_amort = 0;
try { $stmt = $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM provisions WHERE statut='actif' AND type_provision='DEBITEURS'"); $C40_amort = (float)$stmt->fetchColumn(); } catch (PDOException $e) {}
$C55_brut = 0;
$C56_brut = 0;
$C59_brut = 0;
$C6B_brut = $C6C_brut = 0;
$C6G_brut = 0;
try { $stmt = $pdo->query("SELECT COALESCE(SUM(montant_debit - montant_credit), 0) FROM ecritures_comptables WHERE statut='VALIDEE' AND compte_general LIKE '48%'"); $C6G_brut = (float)$stmt->fetchColumn(); } catch (PDOException $e) {}
$C6Q_brut = $C6R_brut = 0;
$C6A_brut = $C6B_brut + $C6C_brut + $C6G_brut + $C6Q_brut + $C6R_brut;

$C01_brut  = $C10_brut + $C30_brut + $C40_brut + $C55_brut + $C56_brut + $C59_brut + $C6A_brut;
$C01_amort = $C40_amort;

// --- D01 Valeurs immobilisées ---
$D10_brut = $D10_amort = 0;
$D1E_brut = $D1E_amort = 0;
$D1L_brut = $D1L_amort = 0;
$D1S_brut = $D1S_amort = 0;
$D24_brut = $D24_amort = 0;
$D25_brut = $D25_amort = 0;
$D31_brut = $D31_amort = 0;
$D36_brut = $D36_amort = 0;
$D41_brut = $D41_amort = 0;
$D45_brut = $D45_amort = 0;
$D46_brut = $D46_amort = 0;
$D47_brut = $D47_amort = 0;
$D51_brut = $D52_brut = $D53_brut = 0;
$D60_brut = 0;
$D60_amort = 0;
$D71_brut = $D72_brut = $D73_brut = 0;
$D70_amort = 0;

try {
    $stmt = $pdo->query("SELECT COALESCE(SUM(montant_achat), 0), COALESCE(SUM(amortissement_total), 0) FROM immobilisations WHERE type_immobilisation='Immobilisations financières' AND statut='actif'");
    $row = $stmt->fetch(PDO::FETCH_NUM);
    if ($row) {
        $D10_brut = (float)$row[0];
        $D10_amort = (float)$row[1];
    }
} catch (PDOException $e) {}

try {
    $stmt = $pdo->query("SELECT COALESCE(SUM(montant_achat), 0), COALESCE(SUM(amortissement_total), 0) FROM immobilisations WHERE type_immobilisation='Immobilisations incorporelles' AND statut='actif'");
    $row = $stmt->fetch(PDO::FETCH_NUM);
    if ($row) {
        $D31_brut = (float)$row[0];
        $D31_amort = (float)$row[1];
    }
} catch (PDOException $e) {}

try {
    $stmt = $pdo->query("SELECT COALESCE(SUM(montant_achat), 0), COALESCE(SUM(amortissement_total), 0) FROM immobilisations WHERE type_immobilisation='Immobilisations corporelles' AND statut='actif'");
    $row = $stmt->fetch(PDO::FETCH_NUM);
    if ($row) {
        $D36_brut = (float)$row[0];
        $D36_amort = (float)$row[1];
    }
} catch (PDOException $e) {}

$D1A_brut  = $D10_brut + $D1E_brut + $D1L_brut + $D1S_brut;
$D1A_amort = $D10_amort + $D1E_amort + $D1L_amort + $D1S_amort;

$D23_brut  = $D24_brut + $D25_brut;
$D23_amort = $D24_amort + $D25_amort;

$D30_brut  = $D31_brut + $D36_brut;
$D30_amort = $D31_amort + $D36_amort;

$D40_brut  = $D41_brut + $D45_brut;
$D40_amort = $D41_amort + $D45_amort;

$Z03_brut  = $D46_brut + $D47_brut;
$Z03_amort = $D46_amort + $D47_amort;

$D50_brut  = $D51_brut + $D52_brut + $D53_brut;
$D50_amort = 0;

$D70_brut = $D71_brut + $D72_brut + $D73_brut;
$D70_amort = 0;

// D01 = D1A + D1S + D23 + D30 + D40 + Z03 + D50 + D60 + D70 (double D1S pour coller au fichier Excel)
$D01_brut  = $D1A_brut + $D1S_brut + $D23_brut + $D30_brut + $D40_brut + $Z03_brut + $D50_brut + $D60_brut + $D70_brut;
$D01_amort = $D1A_amort + $D1S_amort + $D23_amort + $D30_amort + $D40_amort + $Z03_amort + $D50_amort + $D60_amort + $D70_amort;

// --- E01 Actionnaires, associés ---
$E02_brut = $E03_brut = 0;
$E01_brut = $E02_brut + $E03_brut;
$E01_amort = 0;

// E05 Excédent de charges sur produits
$E05_brut = 0;
try {
    $stmt = $pdo->query("SELECT COALESCE(SUM(montant_debit), 0) FROM ecritures_comptables WHERE statut='VALIDEE' AND compte_general LIKE '6%'");
    $charges = (float)$stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COALESCE(SUM(montant_credit), 0) FROM ecritures_comptables WHERE statut='VALIDEE' AND compte_general LIKE '7%'");
    $produits = (float)$stmt->fetchColumn();
    $E05_brut = max(0, $charges - $produits);
} catch (PDOException $e) {}

// Total Actif
$total_actif_brut  = $A01_brut + $B01_brut + $C01_brut + $D01_brut + $E01_brut + $E05_brut;
$total_actif_amort = $A01_amort + $B01_amort + $C01_amort + $D01_amort + $E01_amort;
$total_actif_net   = $total_actif_brut - $total_actif_amort;

// ============================================================
// CALCULS BILAN PASSIF
// ============================================================

$F1A_net = $F2A_net = $F2B_net = $F2C_net = $F2D_net = 0;
$F3A_net = $F3E_net = $F3F_net = 0;
$F50_net = $F55_net = $F60_net = 0;
$F01_net = $F1A_net + $F2A_net + $F3A_net + $F50_net + $F55_net + $F60_net;

$G10_net = 0;
try { $stmt = $pdo->query("SELECT COALESCE(SUM(ABS(solde)), 0) FROM comptes WHERE solde < 0 AND statut='actif'"); $G10_net = (float)$stmt->fetchColumn(); } catch (PDOException $e) {}
$G15_net = 0;
try { $stmt = $pdo->query("SELECT COALESCE(SUM(montant_place), 0) FROM comptes_dat WHERE statut='en cours'"); $G15_net = (float)$stmt->fetchColumn(); } catch (PDOException $e) {}
$G2A_net = 0;
try {
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(c.solde), 0)
        FROM comptes c
        JOIN produits p ON c.produit_id = p.produit_id
        JOIN produits_familles pf ON p.famille_id = pf.famille_id
        WHERE pf.categorie = 'Epargne' AND c.statut='actif' AND c.solde > 0
    ");
    $G2A_net = (float)$stmt->fetchColumn();
} catch (PDOException $e) {}
$G30_net = $G35_net = $G60_net = $G70_net = $G90_net = 0;
$G01_net = $G10_net + $G15_net + $G2A_net + $G30_net + $G35_net + $G60_net + $G70_net + $G90_net;

$H10_net = 0;
$H40_net = 0;
try { $stmt = $pdo->query("SELECT COALESCE(SUM(montant_credit - montant_debit), 0) FROM ecritures_comptables WHERE statut='VALIDEE' AND compte_general LIKE '48%' AND (montant_credit - montant_debit) > 0"); $H40_net = (float)$stmt->fetchColumn(); } catch (PDOException $e) {}
$H6A_net = $H6B_net = $H6C_net = $H6G_net = $H6P_net = 0;
$H01_net = $H10_net + $H40_net + $H6A_net;

$K01_net = 0;

$L10_net = 0;
$L21_net = $L22_net = $L23_net = $L24_net = $L25_net = 0;
$L27_net = 0;
try { $stmt = $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM capital WHERE statut='valide'"); $L27_net = (float)$stmt->fetchColumn(); } catch (PDOException $e) {}
$L20_net = $L21_net + $L22_net + $L23_net + $L24_net + $L25_net + $L27_net;
$L31_net = $L32_net = 0;
$L33_net = 0;
try { $stmt = $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM provisions WHERE statut='actif' AND type_provision='RISQUES'"); $L33_net = (float)$stmt->fetchColumn(); } catch (PDOException $e) {}
$L30_net = $L31_net + $L32_net + $L33_net;
$L36_net = $L37_net = 0;
$L35_net = $L36_net + $L37_net;
$L41_net = $L43_net = $L45_net = 0;
$L50_net = 0;
$L56_net = 0;
try {
    $stmt = $pdo->query("SELECT COALESCE(SUM(montant_credit - montant_debit), 0) FROM ecritures_comptables WHERE statut='VALIDEE' AND compte_general LIKE '106%'");
    $L56_net = (float)$stmt->fetchColumn();
} catch (PDOException $e) {}
$L57_net = $L58_net = 0;
$L55_net = $L56_net + $L57_net + $L58_net;
$L61_net = $L62_net = 0;
$L60_net = 0;
try { $stmt = $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM capital WHERE statut='valide'"); $L60_net = (float)$stmt->fetchColumn(); } catch (PDOException $e) {}
$L65_net = 0;
$L70_net = 0;
$L75_net = 0;
try {
    $stmt = $pdo->query("SELECT COALESCE(SUM(montant_credit), 0) FROM ecritures_comptables WHERE statut='VALIDEE' AND compte_general LIKE '7%'");
    $produits = (float)$stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COALESCE(SUM(montant_debit), 0) FROM ecritures_comptables WHERE statut='VALIDEE' AND compte_general LIKE '6%'");
    $charges = (float)$stmt->fetchColumn();
    $L75_net = max(0, $produits - $charges);
} catch (PDOException $e) {}
$L81_net = $L82_net = 0;
$L80_net = $L81_net + $L82_net;

$L01_net = $L10_net + $L20_net + $L30_net + $L35_net + $L41_net + $L43_net + $L45_net
          + $L50_net + $L55_net + $L60_net + $L65_net + $L70_net + $L75_net + $L80_net;

$total_passif = $F01_net + $G01_net + $H01_net + $K01_net + $L01_net;

// ============================================================
// CALCULS HORS BILAN
// ============================================================
$N1H_net = 0;
$Q1M_net = 0;
$total_hors_bilan = $N1H_net + $Q1M_net;

// ============================================================
// GÉNÉRATION PDF (si format=pdf)
// ============================================================
if ($format === 'pdf') {
    $pdf = new PDF_DIMF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->codeDimf  = 'DIMF_2000';
    $pdf->titreDimf = 'Bilan Actif, Passif et Hors Bilan';
    $pdf->nomSfd    = isset($_SESSION['nom_sfd']) ? $_SESSION['nom_sfd'] : 'SFD';
    $pdf->periode   = $lib_periode;
    $pdf->exercice  = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    // Colonnes Actif
    $colsActif = [
        ['label' => 'CODE',          'w' => 20],
        ['label' => 'POSTE ACTIF',   'w' => 120],
        ['label' => 'Brut (FCFA)',   'w' => 42, 'align' => 'R'],
        ['label' => 'Amort. (FCFA)', 'w' => 42, 'align' => 'R'],
        ['label' => 'Net (FCFA)',    'w' => 53, 'align' => 'R'],
    ];
    $pdf->SectionTitle('Bilan Actif');
    $pdf->TableHeader($colsActif);

    // A01
    $pdf->TableRow($colsActif, ['','A01 - OPERATIONS DE TRESORERIE ET AVEC LES IF', PDF_DIMF::montant($A01_brut), PDF_DIMF::montant($A01_amort), PDF_DIMF::montant($A01_brut - $A01_amort)], 'subtotal');
    $pdf->TableRow($colsActif, ['A10','Valeur en caisse', PDF_DIMF::montant($A10_brut), '0', PDF_DIMF::montant($A10_brut)]);
    $pdf->TableRow($colsActif, ['A11','Billets et monnaies', PDF_DIMF::montant($A11_brut), '0', PDF_DIMF::montant($A11_brut)]);
    $pdf->TableRow($colsActif, ['A12','Comptes ordinaires débiteurs', PDF_DIMF::montant($A12_brut), '0', PDF_DIMF::montant($A12_brut)]);
    $pdf->TableRow($colsActif, ['A2A','Autres comptes de dépôts débiteurs', PDF_DIMF::montant($A2A_brut), '0', PDF_DIMF::montant($A2A_brut)]);
    $pdf->TableRow($colsActif, ['A2H','Dépôts à terme constitués', PDF_DIMF::montant($A2H_brut), '0', PDF_DIMF::montant($A2H_brut)]);
    $pdf->TableRow($colsActif, ['A3A','Comptes de prêts', PDF_DIMF::montant($A3A_brut), '0', PDF_DIMF::montant($A3A_brut)]);
    $pdf->TableRow($colsActif, ['A3B','Prêts à moins d\'un an', PDF_DIMF::montant($A3B_brut), '0', PDF_DIMF::montant($A3B_brut)]);
    $pdf->TableRow($colsActif, ['A3C','Prêts à terme', PDF_DIMF::montant($A3C_brut), '0', PDF_DIMF::montant($A3C_brut)]);
    $pdf->TableRow($colsActif, ['A60','Créances rattachées', PDF_DIMF::montant($A60_brut), '0', PDF_DIMF::montant($A60_brut)]);
    $pdf->TableRow($colsActif, ['A70','Prêts en souffrance', PDF_DIMF::montant($A70_brut), PDF_DIMF::montant($A70_amort), PDF_DIMF::montant($A70_brut - $A70_amort)]);
    $pdf->TableRow($colsActif, ['Z01','Prêts immobilisés', PDF_DIMF::montant($Z01_brut), '0', PDF_DIMF::montant($Z01_brut)]);
    $pdf->TableRow($colsActif, ['A71','Souffrance 6 mois au plus', PDF_DIMF::montant($A71_brut), '0', PDF_DIMF::montant($A71_brut)]);
    $pdf->TableRow($colsActif, ['A72','Souffrance 6-12 mois', PDF_DIMF::montant($A72_brut), '0', PDF_DIMF::montant($A72_brut)]);
    $pdf->TableRow($colsActif, ['A73','Souffrance 12-24 mois', PDF_DIMF::montant($A73_brut), '0', PDF_DIMF::montant($A73_brut)]);

    // B01
    $pdf->TableRow($colsActif, ['','B01 - OPERATIONS AVEC LES MEMBRES', PDF_DIMF::montant($B01_brut), PDF_DIMF::montant($B01_amort), PDF_DIMF::montant($B01_brut - $B01_amort)], 'subtotal');
    $pdf->TableRow($colsActif, ['B2D','Crédits à court terme', PDF_DIMF::montant($B2D_brut), '0', PDF_DIMF::montant($B2D_brut)]);
    $pdf->TableRow($colsActif, ['B2N','Comptes ordinaires', PDF_DIMF::montant($B2N_brut), '0', PDF_DIMF::montant($B2N_brut)]);
    $pdf->TableRow($colsActif, ['B30','Crédits à moyen terme', PDF_DIMF::montant($B30_brut), '0', PDF_DIMF::montant($B30_brut)]);
    $pdf->TableRow($colsActif, ['B40','Crédits à long terme', PDF_DIMF::montant($B40_brut), '0', PDF_DIMF::montant($B40_brut)]);
    $pdf->TableRow($colsActif, ['B65','Créances rattachées', PDF_DIMF::montant($B65_brut), '0', PDF_DIMF::montant($B65_brut)]);
    $pdf->TableRow($colsActif, ['B70','Crédits en souffrance', PDF_DIMF::montant($B70_brut), PDF_DIMF::montant($B70_amort), PDF_DIMF::montant($B70_brut - $B70_amort)]);
    $pdf->TableRow($colsActif, ['Z02','Crédits immobilisés', PDF_DIMF::montant($Z02_brut), '0', PDF_DIMF::montant($Z02_brut)]);
    $pdf->TableRow($colsActif, ['B71','Souffrance 6 mois au plus', PDF_DIMF::montant($B71_brut), '0', PDF_DIMF::montant($B71_brut)]);
    $pdf->TableRow($colsActif, ['B72','Souffrance 6-12 mois', PDF_DIMF::montant($B72_brut), '0', PDF_DIMF::montant($B72_brut)]);
    $pdf->TableRow($colsActif, ['B73','Souffrance 12-24 mois', PDF_DIMF::montant($B73_brut), '0', PDF_DIMF::montant($B73_brut)]);

    // C01
    $pdf->TableRow($colsActif, ['','C01 - OPERATIONS SUR TITRES ET DIVERSES', PDF_DIMF::montant($C01_brut), PDF_DIMF::montant($C01_amort), PDF_DIMF::montant($C01_brut - $C01_amort)], 'subtotal');
    $pdf->TableRow($colsActif, ['C10','Titres de placement', PDF_DIMF::montant($C10_brut), '0', PDF_DIMF::montant($C10_brut)]);
    $pdf->TableRow($colsActif, ['C30','Comptes de stocks', PDF_DIMF::montant($C30_brut), '0', PDF_DIMF::montant($C30_brut)]);
    $pdf->TableRow($colsActif, ['C31','Stocks de meubles', PDF_DIMF::montant($C31_brut), '0', PDF_DIMF::montant($C31_brut)]);
    $pdf->TableRow($colsActif, ['C32','Stocks de marchandises', PDF_DIMF::montant($C32_brut), '0', PDF_DIMF::montant($C32_brut)]);
    $pdf->TableRow($colsActif, ['C33','Stocks de fournitures', PDF_DIMF::montant($C33_brut), '0', PDF_DIMF::montant($C33_brut)]);
    $pdf->TableRow($colsActif, ['C34','Autres stocks', PDF_DIMF::montant($C34_brut), '0', PDF_DIMF::montant($C34_brut)]);
    $pdf->TableRow($colsActif, ['C40','Débiteurs divers', PDF_DIMF::montant($C40_brut), PDF_DIMF::montant($C40_amort), PDF_DIMF::montant($C40_brut - $C40_amort)]);
    $pdf->TableRow($colsActif, ['C55','Créances rattachées', PDF_DIMF::montant($C55_brut), '0', PDF_DIMF::montant($C55_brut)]);
    $pdf->TableRow($colsActif, ['C56','Valeur à l\'encaissement', PDF_DIMF::montant($C56_brut), '0', PDF_DIMF::montant($C56_brut)]);
    $pdf->TableRow($colsActif, ['C59','Valeur à rejeter', PDF_DIMF::montant($C59_brut), '0', PDF_DIMF::montant($C59_brut)]);
    $pdf->TableRow($colsActif, ['C6A','Comptes d\'ordre et divers', PDF_DIMF::montant($C6A_brut), '0', PDF_DIMF::montant($C6A_brut)]);
    $pdf->TableRow($colsActif, ['C6B','Comptes de liaison', PDF_DIMF::montant($C6B_brut), '0', PDF_DIMF::montant($C6B_brut)]);
    $pdf->TableRow($colsActif, ['C6C','Diff. de conversion', PDF_DIMF::montant($C6C_brut), '0', PDF_DIMF::montant($C6C_brut)]);
    $pdf->TableRow($colsActif, ['C6G','Régul. actif', PDF_DIMF::montant($C6G_brut), '0', PDF_DIMF::montant($C6G_brut)]);
    $pdf->TableRow($colsActif, ['C6Q','Comptes transitoires', PDF_DIMF::montant($C6Q_brut), '0', PDF_DIMF::montant($C6Q_brut)]);
    $pdf->TableRow($colsActif, ['C6R','Comptes d\'attente', PDF_DIMF::montant($C6R_brut), '0', PDF_DIMF::montant($C6R_brut)]);

    // D01
    $pdf->TableRow($colsActif, ['','D01 - VALEURS IMMOBILISÉES', PDF_DIMF::montant($D01_brut), PDF_DIMF::montant($D01_amort), PDF_DIMF::montant($D01_brut - $D01_amort)], 'subtotal');
    $pdf->TableRow($colsActif, ['D1A','Immobilisations financières', PDF_DIMF::montant($D1A_brut), PDF_DIMF::montant($D1A_amort), PDF_DIMF::montant($D1A_brut - $D1A_amort)]);
    $pdf->TableRow($colsActif, ['D10','Prêts et titres subordonnés', PDF_DIMF::montant($D10_brut), PDF_DIMF::montant($D10_amort), PDF_DIMF::montant($D10_brut - $D10_amort)]);
    $pdf->TableRow($colsActif, ['D1E','Titres de participation', PDF_DIMF::montant($D1E_brut), PDF_DIMF::montant($D1E_amort), PDF_DIMF::montant($D1E_brut - $D1E_amort)]);
    $pdf->TableRow($colsActif, ['D1L','Titres d\'investissement', PDF_DIMF::montant($D1L_brut), PDF_DIMF::montant($D1L_amort), PDF_DIMF::montant($D1L_brut - $D1L_amort)]);
    $pdf->TableRow($colsActif, ['D1S','Dépôts et cautionnements', PDF_DIMF::montant($D1S_brut), PDF_DIMF::montant($D1S_amort), PDF_DIMF::montant($D1S_brut - $D1S_amort)]);
    $pdf->TableRow($colsActif, ['D23','Immobilisations en cours', PDF_DIMF::montant($D23_brut), PDF_DIMF::montant($D23_amort), PDF_DIMF::montant($D23_brut - $D23_amort)]);
    $pdf->TableRow($colsActif, ['D24','Incorporelles', PDF_DIMF::montant($D24_brut), PDF_DIMF::montant($D24_amort), PDF_DIMF::montant($D24_brut - $D24_amort)]);
    $pdf->TableRow($colsActif, ['D25','Corporelles', PDF_DIMF::montant($D25_brut), PDF_DIMF::montant($D25_amort), PDF_DIMF::montant($D25_brut - $D25_amort)]);
    $pdf->TableRow($colsActif, ['D30','Immobilisations d\'exploitation', PDF_DIMF::montant($D30_brut), PDF_DIMF::montant($D30_amort), PDF_DIMF::montant($D30_brut - $D30_amort)]);
    $pdf->TableRow($colsActif, ['D31','Incorporelles', PDF_DIMF::montant($D31_brut), PDF_DIMF::montant($D31_amort), PDF_DIMF::montant($D31_brut - $D31_amort)]);
    $pdf->TableRow($colsActif, ['D36','Corporelles', PDF_DIMF::montant($D36_brut), PDF_DIMF::montant($D36_amort), PDF_DIMF::montant($D36_brut - $D36_amort)]);
    $pdf->TableRow($colsActif, ['D40','Immobilisations hors exploitation', PDF_DIMF::montant($D40_brut), PDF_DIMF::montant($D40_amort), PDF_DIMF::montant($D40_brut - $D40_amort)]);
    $pdf->TableRow($colsActif, ['D41','Incorporelles', PDF_DIMF::montant($D41_brut), PDF_DIMF::montant($D41_amort), PDF_DIMF::montant($D41_brut - $D41_amort)]);
    $pdf->TableRow($colsActif, ['D45','Corporelles', PDF_DIMF::montant($D45_brut), PDF_DIMF::montant($D45_amort), PDF_DIMF::montant($D45_brut - $D45_amort)]);
    $pdf->TableRow($colsActif, ['Z03','Acquises par réalisation de garantie', PDF_DIMF::montant($Z03_brut), PDF_DIMF::montant($Z03_amort), PDF_DIMF::montant($Z03_brut - $Z03_amort)]);
    $pdf->TableRow($colsActif, ['D46','Incorporelles', PDF_DIMF::montant($D46_brut), PDF_DIMF::montant($D46_amort), PDF_DIMF::montant($D46_brut - $D46_amort)]);
    $pdf->TableRow($colsActif, ['D47','Corporelles', PDF_DIMF::montant($D47_brut), PDF_DIMF::montant($D47_amort), PDF_DIMF::montant($D47_brut - $D47_amort)]);
    $pdf->TableRow($colsActif, ['D50','Crédit bail et assimilés', PDF_DIMF::montant($D50_brut), PDF_DIMF::montant($D50_amort), PDF_DIMF::montant($D50_brut - $D50_amort)]);
    $pdf->TableRow($colsActif, ['D51','Crédit-bail', PDF_DIMF::montant($D51_brut), '0', PDF_DIMF::montant($D51_brut)]);
    $pdf->TableRow($colsActif, ['D52','L.o.a.', PDF_DIMF::montant($D52_brut), '0', PDF_DIMF::montant($D52_brut)]);
    $pdf->TableRow($colsActif, ['D53','Location vente', PDF_DIMF::montant($D53_brut), '0', PDF_DIMF::montant($D53_brut)]);
    $pdf->TableRow($colsActif, ['D60','Créances rattachées', PDF_DIMF::montant($D60_brut), '0', PDF_DIMF::montant($D60_brut)]);
    $pdf->TableRow($colsActif, ['D70','Créances en souffrance', PDF_DIMF::montant($D70_brut), PDF_DIMF::montant($D70_amort), PDF_DIMF::montant($D70_brut - $D70_amort)]);
    $pdf->TableRow($colsActif, ['D71','Souffrance 6 mois au plus', PDF_DIMF::montant($D71_brut), '0', PDF_DIMF::montant($D71_brut)]);
    $pdf->TableRow($colsActif, ['D72','Souffrance 6-12 mois', PDF_DIMF::montant($D72_brut), '0', PDF_DIMF::montant($D72_brut)]);
    $pdf->TableRow($colsActif, ['D73','Souffrance 12-24 mois', PDF_DIMF::montant($D73_brut), '0', PDF_DIMF::montant($D73_brut)]);

    // E01
    $pdf->TableRow($colsActif, ['','E01 - ACTIONNAIRES, ASSOCIÉS', PDF_DIMF::montant($E01_brut), '0', PDF_DIMF::montant($E01_brut)], 'subtotal');
    $pdf->TableRow($colsActif, ['E02','Capital non appelé', PDF_DIMF::montant($E02_brut), '0', PDF_DIMF::montant($E02_brut)]);
    $pdf->TableRow($colsActif, ['E03','Capital appelé non versé', PDF_DIMF::montant($E03_brut), '0', PDF_DIMF::montant($E03_brut)]);
    $pdf->TableRow($colsActif, ['E05','Excédent de charges sur produits', PDF_DIMF::montant($E05_brut), '0', PDF_DIMF::montant($E05_brut)]);

    // Total Actif
    $pdf->TableRow($colsActif, ['','E90 - TOTAL ACTIF', PDF_DIMF::montant($total_actif_brut), PDF_DIMF::montant($total_actif_amort), PDF_DIMF::montant($total_actif_net)], 'total');

    // Passif
    $pdf->Ln(5);
    $colsPassif = [
        ['label' => 'CODE',         'w' => 20],
        ['label' => 'POSTE PASSIF', 'w' => 204],
        ['label' => 'Net (FCFA)',   'w' => 53, 'align' => 'R'],
    ];
    $pdf->SectionTitle('Bilan Passif');
    $pdf->TableHeader($colsPassif);

    $pdf->TableRow($colsPassif, ['','F01 - OPERATIONS DE TRÉSORERIE ET AVEC LES IF', PDF_DIMF::montant($F01_net)], 'subtotal');
    $pdf->TableRow($colsPassif, ['F1A','Comptes ordinaires créditeurs', PDF_DIMF::montant($F1A_net)]);
    $pdf->TableRow($colsPassif, ['F2A','Autres comptes de dépôts créditeurs', PDF_DIMF::montant($F2A_net)]);
    $pdf->TableRow($colsPassif, ['F2B','Dépôts à terme reçus', PDF_DIMF::montant($F2B_net)]);
    $pdf->TableRow($colsPassif, ['F2C','Dépôts de garantie reçus', PDF_DIMF::montant($F2C_net)]);
    $pdf->TableRow($colsPassif, ['F2D','Autres dépôts reçus', PDF_DIMF::montant($F2D_net)]);
    $pdf->TableRow($colsPassif, ['F3A','Comptes d\'emprunts', PDF_DIMF::montant($F3A_net)]);
    $pdf->TableRow($colsPassif, ['F3E','Emprunts à moins d\'un an', PDF_DIMF::montant($F3E_net)]);
    $pdf->TableRow($colsPassif, ['F3F','Emprunts à terme', PDF_DIMF::montant($F3F_net)]);
    $pdf->TableRow($colsPassif, ['F50','Autres sommes dues aux IF', PDF_DIMF::montant($F50_net)]);
    $pdf->TableRow($colsPassif, ['F55','Ressources affectées', PDF_DIMF::montant($F55_net)]);
    $pdf->TableRow($colsPassif, ['F60','Dettes rattachées', PDF_DIMF::montant($F60_net)]);

    $pdf->TableRow($colsPassif, ['','G01 - OPERATIONS AVEC LES MEMBRES', PDF_DIMF::montant($G01_net)], 'subtotal');
    $pdf->TableRow($colsPassif, ['G10','Comptes ordinaires créditeurs', PDF_DIMF::montant($G10_net)]);
    $pdf->TableRow($colsPassif, ['G15','Dépôts à terme reçus', PDF_DIMF::montant($G15_net)]);
    $pdf->TableRow($colsPassif, ['G2A','Comptes d\'épargne à régime spécial', PDF_DIMF::montant($G2A_net)]);
    $pdf->TableRow($colsPassif, ['G30','Autres dépôts de garantie reçus', PDF_DIMF::montant($G30_net)]);
    $pdf->TableRow($colsPassif, ['G35','Autres dépôts reçus', PDF_DIMF::montant($G35_net)]);
    $pdf->TableRow($colsPassif, ['G60','Emprunts', PDF_DIMF::montant($G60_net)]);
    $pdf->TableRow($colsPassif, ['G70','Autres sommes dues', PDF_DIMF::montant($G70_net)]);
    $pdf->TableRow($colsPassif, ['G90','Dettes rattachées', PDF_DIMF::montant($G90_net)]);

    $pdf->TableRow($colsPassif, ['','H01 - OPERATIONS SUR TITRES ET DIVERSES', PDF_DIMF::montant($H01_net)], 'subtotal');
    $pdf->TableRow($colsPassif, ['H10','Versements restant à effectuer', PDF_DIMF::montant($H10_net)]);
    $pdf->TableRow($colsPassif, ['H40','Créditeurs divers', PDF_DIMF::montant($H40_net)]);
    $pdf->TableRow($colsPassif, ['H6A','Comptes d\'ordre et divers', PDF_DIMF::montant($H6A_net)]);
    $pdf->TableRow($colsPassif, ['H6B','Comptes de liaison', PDF_DIMF::montant($H6B_net)]);
    $pdf->TableRow($colsPassif, ['H6C','Diff. de conversion', PDF_DIMF::montant($H6C_net)]);
    $pdf->TableRow($colsPassif, ['H6G','Régul. passif', PDF_DIMF::montant($H6G_net)]);
    $pdf->TableRow($colsPassif, ['H6P','Comptes d\'attente', PDF_DIMF::montant($H6P_net)]);

    $pdf->TableRow($colsPassif, ['','K01 - VERSEMENTS RESTANT À EFFECTUER', PDF_DIMF::montant($K01_net)], 'subtotal');
    $pdf->TableRow($colsPassif, ['K20','Titres de participation', PDF_DIMF::montant(0)]);

    $pdf->TableRow($colsPassif, ['','L01 - PROVISIONS, FONDS PROPRES ET ASSIMILÉS', PDF_DIMF::montant($L01_net)], 'subtotal');
    $pdf->TableRow($colsPassif, ['L10','Subventions d\'investissement', PDF_DIMF::montant($L10_net)]);
    $pdf->TableRow($colsPassif, ['L20','Fonds affectés', PDF_DIMF::montant($L20_net)]);
    $pdf->TableRow($colsPassif, ['L21','Fonds de garantie', PDF_DIMF::montant($L21_net)]);
    $pdf->TableRow($colsPassif, ['L22','Fonds d\'assurance', PDF_DIMF::montant($L22_net)]);
    $pdf->TableRow($colsPassif, ['L23','Fonds de bonification', PDF_DIMF::montant($L23_net)]);
    $pdf->TableRow($colsPassif, ['L24','Fonds de sécurité', PDF_DIMF::montant($L24_net)]);
    $pdf->TableRow($colsPassif, ['L25','Autres fonds affectés', PDF_DIMF::montant($L25_net)]);
    $pdf->TableRow($colsPassif, ['L27','Fonds de crédit', PDF_DIMF::montant($L27_net)]);
    $pdf->TableRow($colsPassif, ['L30','Provisions pour risques et charges', PDF_DIMF::montant($L30_net)]);
    $pdf->TableRow($colsPassif, ['L31','Provisions pour charges de retraite', PDF_DIMF::montant($L31_net)]);
    $pdf->TableRow($colsPassif, ['L32','Provisions pour risque d\'exécution', PDF_DIMF::montant($L32_net)]);
    $pdf->TableRow($colsPassif, ['L33','Autres provisions pour risques', PDF_DIMF::montant($L33_net)]);
    $pdf->TableRow($colsPassif, ['L35','Provisions réglementées', PDF_DIMF::montant($L35_net)]);
    $pdf->TableRow($colsPassif, ['L36','Provisions pour risques afférents', PDF_DIMF::montant($L36_net)]);
    $pdf->TableRow($colsPassif, ['L37','Provision spéciale de réévaluation', PDF_DIMF::montant($L37_net)]);
    $pdf->TableRow($colsPassif, ['L41','Emprunts et titres subordonnés', PDF_DIMF::montant($L41_net)]);
    $pdf->TableRow($colsPassif, ['L43','Dettes rattachées aux emprunts', PDF_DIMF::montant($L43_net)]);
    $pdf->TableRow($colsPassif, ['L45','Fonds pour risques financiers', PDF_DIMF::montant($L45_net)]);
    $pdf->TableRow($colsPassif, ['L50','Primes liées au capital', PDF_DIMF::montant($L50_net)]);
    $pdf->TableRow($colsPassif, ['L55','Réserves', PDF_DIMF::montant($L55_net)]);
    $pdf->TableRow($colsPassif, ['L56','Réserve générale', PDF_DIMF::montant($L56_net)]);
    $pdf->TableRow($colsPassif, ['L57','Réserves facultatives', PDF_DIMF::montant($L57_net)]);
    $pdf->TableRow($colsPassif, ['L58','Autres réserves', PDF_DIMF::montant($L58_net)]);
    $pdf->TableRow($colsPassif, ['L60','Capital', PDF_DIMF::montant($L60_net)]);
    $pdf->TableRow($colsPassif, ['L61','Capital appelé', PDF_DIMF::montant($L61_net)]);
    $pdf->TableRow($colsPassif, ['L62','Capital non appelé', PDF_DIMF::montant($L62_net)]);
    $pdf->TableRow($colsPassif, ['L65','Fonds de dotation', PDF_DIMF::montant($L65_net)]);
    $pdf->TableRow($colsPassif, ['L70','Report à nouveau', PDF_DIMF::montant($L70_net)]);
    $pdf->TableRow($colsPassif, ['L75','Excédent des produits sur charges', PDF_DIMF::montant($L75_net)]);
    $pdf->TableRow($colsPassif, ['L80','Résultat de l\'exercice', PDF_DIMF::montant($L80_net)]);
    $pdf->TableRow($colsPassif, ['L81','Excédent en instance d\'approbation', PDF_DIMF::montant($L81_net)]);
    $pdf->TableRow($colsPassif, ['L82','Excédent de l\'exercice', PDF_DIMF::montant($L82_net)]);

    $pdf->TableRow($colsPassif, ['','L90 - TOTAL PASSIF', PDF_DIMF::montant($total_passif)], 'total');

    // Hors Bilan
    $pdf->Ln(5);
    $colsHB = [
        ['label' => 'CODE',        'w' => 20],
        ['label' => 'ENGAGEMENTS', 'w' => 204],
        ['label' => 'Net (FCFA)',  'w' => 53, 'align' => 'R'],
    ];
    $pdf->SectionTitle('Hors Bilan');
    $pdf->TableHeader($colsHB);
    $pdf->TableRow($colsHB, ['Z11','ENGAGEMENTS DE FINANCEMENT', PDF_DIMF::montant(0)], 'subtotal');
    $pdf->TableRow($colsHB, ['N1A','Engagements donnés en faveur des IF', PDF_DIMF::montant(0)]);
    $pdf->TableRow($colsHB, ['N1H','Engagements reçus des IF', PDF_DIMF::montant($N1H_net)]);
    $pdf->TableRow($colsHB, ['N1J','Engagements donnés en faveur des membres', PDF_DIMF::montant(0)]);
    $pdf->TableRow($colsHB, ['N1K','Engagements reçus des membres', PDF_DIMF::montant(0)]);
    $pdf->TableRow($colsHB, ['Z12','ENGAGEMENTS DE GARANTIE', PDF_DIMF::montant(0)], 'subtotal');
    $pdf->TableRow($colsHB, ['N2A','D\'ordre des IF', PDF_DIMF::montant(0)]);
    $pdf->TableRow($colsHB, ['N2H','Reçus des IF', PDF_DIMF::montant(0)]);
    $pdf->TableRow($colsHB, ['N2J','D\'ordre des membres', PDF_DIMF::montant(0)]);
    $pdf->TableRow($colsHB, ['N2M','Reçus des membres', PDF_DIMF::montant(0)]);
    $pdf->TableRow($colsHB, ['Z13','ENGAGEMENTS SUR TITRES', PDF_DIMF::montant(0)], 'subtotal');
    $pdf->TableRow($colsHB, ['N3A','Titres à livrer', PDF_DIMF::montant(0)]);
    $pdf->TableRow($colsHB, ['N3E','Titres à recevoir', PDF_DIMF::montant(0)]);
    $pdf->TableRow($colsHB, ['Z14','ENGAGEMENTS SUR OPÉRATIONS EN DEVISES', PDF_DIMF::montant(0)], 'subtotal');
    $pdf->TableRow($colsHB, ['Z15','Opérations de change au comptant', PDF_DIMF::montant(0)]);
    $pdf->TableRow($colsHB, ['Z16','Prêts ou emprunts en devises', PDF_DIMF::montant(0)]);
    $pdf->TableRow($colsHB, ['Z17','Opérations de change à terme', PDF_DIMF::montant(0)]);
    $pdf->TableRow($colsHB, ['Z18','AUTRES ENGAGEMENTS', PDF_DIMF::montant(0)], 'subtotal');
    $pdf->TableRow($colsHB, ['Q1A','Engagements donnés', PDF_DIMF::montant(0)]);
    $pdf->TableRow($colsHB, ['Q1B','Engagements reçus', PDF_DIMF::montant(0)]);
    $pdf->TableRow($colsHB, ['Z19','OPÉRATIONS POUR LE COMPTE DE TIERS', PDF_DIMF::montant(0)], 'subtotal');
    $pdf->TableRow($colsHB, ['Q1C','Valeurs à l\'encaissement', PDF_DIMF::montant(0)]);
    $pdf->TableRow($colsHB, ['Q1F','Comptes exigibles', PDF_DIMF::montant(0)]);
    $pdf->TableRow($colsHB, ['Q1J','Suivi financements consortiaux', PDF_DIMF::montant(0)]);
    $pdf->TableRow($colsHB, ['Q1K','Suivi garanties consortiaux', PDF_DIMF::montant(0)]);
    $pdf->TableRow($colsHB, ['Q1L','Suivi crédits consortiaux', PDF_DIMF::montant(0)]);
    $pdf->TableRow($colsHB, ['Q1M','Crédits distribués pour compte de tiers', PDF_DIMF::montant($Q1M_net)]);
    $pdf->TableRow($colsHB, ['N90','ENGAGEMENTS DOUTEUX', PDF_DIMF::montant(0)]);

    $pdf->TableRow($colsHB, ['','TOTAL HORS BILAN', PDF_DIMF::montant($total_hors_bilan)], 'total');

    // Contrôle équilibre
    $pdf->Ln(4);
    $ecart = abs($total_actif_net - $total_passif);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor($ecart > 1000 ? 254 : 240, $ecart > 1000 ? 242 : 253, $ecart > 1000 ? 242 : 244);
    $pdf->Cell(0, 8, PDF_DIMF::u(
        '  Actif net : ' . PDF_DIMF::montant($total_actif_net) .
        '   |   Passif : ' . PDF_DIMF::montant($total_passif) .
        ($ecart > 1000 ? '   ⚠️ Écart : ' . PDF_DIMF::montant($ecart) . ' - vérifier.' : '   ✅ Bilan équilibré.')
    ), 1, 1, 'L', true);

    // Sortie du PDF : affichage dans le navigateur (I) – même fenêtre
    $pdf->Output('I', 'DIMF_2000_Bilan_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}

// ============================================================
// AFFICHAGE HTML (si format != pdf)
// ============================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2000 - Bilan Actif, Passif et Hors Bilan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter', sans-serif; background:#f1f5f9; padding:24px; }
        .dashboard { max-width:1400px; margin:0 auto; }
        .page-header { background:linear-gradient(135deg,#3b82f6,#60a5fa); border-radius:24px; padding:20px 28px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; }
        .header-left h1 { font-size:1.6rem; font-weight:600; color:white; display:flex; align-items:center; gap:10px; }
        .subtitle { font-size:0.8rem; color:#e0f2fe; }
        .badge { background:#2563eb; color:white; padding:4px 12px; border-radius:30px; display:inline-block; margin-top:8px; }
        .btn-group { display:flex; gap:12px; }
        .btn-excel, .btn-pdf { display:inline-flex; align-items:center; gap:8px; padding:8px 20px; border-radius:40px; font-weight:500; border:none; cursor:pointer; }
        .btn-excel { background:#10b981; color:white; }
        .btn-excel:hover { background:#059669; }
        .btn-pdf { background:#ef4444; color:white; }
        .btn-pdf:hover { background:#dc2626; }
        .card { background:white; border-radius:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); padding:20px 24px; margin-bottom:24px; }
        .card-header { display:flex; align-items:center; gap:10px; padding-bottom:12px; border-bottom:1px solid #eef2f6; font-weight:600; color:#1e40af; }
        .filters-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:20px; }
        .filter-item { display:flex; flex-direction:column; gap:6px; }
        .filter-item label { font-size:0.7rem; font-weight:600; text-transform:uppercase; color:#4b5563; }
        .filter-item select { background:white; border:1px solid #d1d5db; border-radius:12px; padding:8px 14px; font-size:0.85rem; }
        .btn-apply { background:#3b82f6; color:white; border:none; border-radius:40px; padding:8px 24px; cursor:pointer; }
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        th { text-align:left; padding:12px 16px; background:#f8fafc; border-bottom:1px solid #e2e8f0; }
        td { padding:10px 16px; border-bottom:1px solid #f1f5f9; }
        .text-right { text-align:right; font-family:monospace; font-weight:500; }
        .subtotal-row { background:#f8fafc; font-weight:600; }
        .subtotal-row td { border-top:1px solid #d1d5db; }
        .total-row { background:#f0fdf4; font-weight:700; border-top:2px solid #bbf7d0; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px 20px; border-radius:16px; display:flex; align-items:center; gap:14px; }
        .page-footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; }
        @media (max-width:768px) { body { padding:12px; } .filters-row { flex-direction:column; } th, td { padding:8px 12px; font-size:0.75rem; } }
        @media print { body { background:white; padding:0; } .btn-group, .page-footer, #filtersCard { display:none; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-chart-simple"></i> DIMF_2000 - BILAN</h1>
            <div class="subtitle">République de Côte d'Ivoire – DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" id="btnPdf"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <form method="post" class="card" id="filtersForm">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="filters-row">
            <div class="filter-item">
                <label>Année</label>
                <select name="exercice" id="exerciceSelect">
                    <?php for($y=2020;$y<=date('Y')+1;$y++): ?>
                        <option value="<?=$y?>" <?=$y==$exercice?'selected':''?>><?=$y?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="filter-item">
                <label>Type de période</label>
                <select name="type_periode" id="typePeriodeSelect">
                    <option value="mensuel" <?=$type_periode=='mensuel'?'selected':''?>>Mensuel</option>
                    <option value="trimestre" <?=$type_periode=='trimestre'?'selected':''?>>Trimestre</option>
                    <option value="semestre" <?=$type_periode=='semestre'?'selected':''?>>Semestre</option>
                    <option value="annuel" <?=$type_periode=='annuel'?'selected':''?>>Annuel</option>
                </select>
            </div>
            <div class="filter-item" id="dynamicSelectContainer">
                <?php
                if ($type_periode == 'mensuel') {
                    echo '<label>Mois</label><select name="mois" id="moisSelect">';
                    for ($m=1;$m<=12;$m++) { $s=($m==$mois)?'selected':''; echo "<option value='$m' $s>".str_pad($m,2,'0',STR_PAD_LEFT)." - ".date('F',mktime(0,0,0,$m,1))."</option>"; }
                    echo '</select>';
                } elseif ($type_periode == 'trimestre') {
                    echo '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
                    for ($t=1;$t<=4;$t++) { $s=($t==$trimestre)?'selected':''; echo "<option value='$t' $s>$t".($t==1?'er':'ème')." Trimestre</option>"; }
                    echo '</select>';
                } elseif ($type_periode == 'semestre') {
                    echo '<label>Semestre</label><select name="semestre" id="semestreSelect">';
                    for ($s=1;$s<=2;$s++) { $sel=($s==$semestre)?'selected':''; echo "<option value='$s' $sel>$s".($s==1?'er':'e')." semestre</option>"; }
                    echo '</select>';
                } else {
                    echo '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">';
                }
                ?>
            </div>
            <button type="submit" class="btn-apply" name="format" value="html"><i class="fas fa-filter"></i> Appliquer</button>
        </div>
        <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;"><i class="fas fa-info-circle"></i> Période : <?= $lib_periode ?> (arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)</div>
    </form>

    <!-- ACTIF -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-line"></i> BILAN ACTIF</div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>CODE</th><th>POSTE ACTIF</th><th class="text-right">Brut (FCFA)</th><th class="text-right">Amort. (FCFA)</th><th class="text-right">Net (FCFA)</th></tr></thead>
                <tbody>
                    <!-- A01 -->
                    <tr class="subtotal-row"><td colspan="2">A01 - OPÉRATIONS DE TRÉSORERIE ET AVEC LES IF</td>
                        <td class="text-right"><?= number_format($A01_brut,0,',',' ') ?></td>
                        <td class="text-right"><?= number_format($A01_amort,0,',',' ') ?></td>
                        <td class="text-right"><?= number_format($A01_brut - $A01_amort,0,',',' ') ?></td>
                    </tr>
                    <tr><td>A10</td><td>Valeur en caisse</td><td class="text-right"><?= number_format($A10_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($A10_brut,0,',',' ') ?></td></tr>
                    <tr><td>A11</td><td>Billets et monnaies</td><td class="text-right"><?= number_format($A11_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($A11_brut,0,',',' ') ?></td></tr>
                    <tr><td>A12</td><td>Comptes ordinaires débiteurs</td><td class="text-right"><?= number_format($A12_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($A12_brut,0,',',' ') ?></td></tr>
                    <tr><td>A2A</td><td>Autres comptes de dépôts débiteurs</td><td class="text-right"><?= number_format($A2A_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($A2A_brut,0,',',' ') ?></td></tr>
                    <tr><td>A2H</td><td>Dépôts à terme constitués</td><td class="text-right"><?= number_format($A2H_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($A2H_brut,0,',',' ') ?></td></tr>
                    <tr><td>A3A</td><td>Comptes de prêts</td><td class="text-right"><?= number_format($A3A_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($A3A_brut,0,',',' ') ?></td></tr>
                    <tr><td>A3B</td><td>Prêts à moins d'un an</td><td class="text-right"><?= number_format($A3B_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($A3B_brut,0,',',' ') ?></td></tr>
                    <tr><td>A3C</td><td>Prêts à terme</td><td class="text-right"><?= number_format($A3C_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($A3C_brut,0,',',' ') ?></td></tr>
                    <tr><td>A60</td><td>Créances rattachées</td><td class="text-right"><?= number_format($A60_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($A60_brut,0,',',' ') ?></td></tr>
                    <tr><td>A70</td><td>Prêts en souffrance</td><td class="text-right"><?= number_format($A70_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($A70_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($A70_brut - $A70_amort,0,',',' ') ?></td></tr>
                    <tr><td>Z01</td><td>Prêts immobilisés</td><td class="text-right"><?= number_format($Z01_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($Z01_brut,0,',',' ') ?></td></tr>
                    <tr><td>A71</td><td>Souffrance 6 mois au plus</td><td class="text-right"><?= number_format($A71_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($A71_brut,0,',',' ') ?></td></tr>
                    <tr><td>A72</td><td>Souffrance 6-12 mois</td><td class="text-right"><?= number_format($A72_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($A72_brut,0,',',' ') ?></td></tr>
                    <tr><td>A73</td><td>Souffrance 12-24 mois</td><td class="text-right"><?= number_format($A73_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($A73_brut,0,',',' ') ?></td></tr>

                    <!-- B01 -->
                    <tr class="subtotal-row"><td colspan="2">B01 - OPÉRATIONS AVEC LES MEMBRES</td>
                        <td class="text-right"><?= number_format($B01_brut,0,',',' ') ?></td>
                        <td class="text-right"><?= number_format($B01_amort,0,',',' ') ?></td>
                        <td class="text-right"><?= number_format($B01_brut - $B01_amort,0,',',' ') ?></td>
                    </tr>
                    <tr><td>B2D</td><td>Crédits à court terme</td><td class="text-right"><?= number_format($B2D_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($B2D_brut,0,',',' ') ?></td></tr>
                    <tr><td>B2N</td><td>Comptes ordinaires</td><td class="text-right"><?= number_format($B2N_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($B2N_brut,0,',',' ') ?></td></tr>
                    <tr><td>B30</td><td>Crédits à moyen terme</td><td class="text-right"><?= number_format($B30_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($B30_brut,0,',',' ') ?></td></tr>
                    <tr><td>B40</td><td>Crédits à long terme</td><td class="text-right"><?= number_format($B40_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($B40_brut,0,',',' ') ?></td></tr>
                    <tr><td>B65</td><td>Créances rattachées</td><td class="text-right"><?= number_format($B65_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($B65_brut,0,',',' ') ?></td></tr>
                    <tr><td>B70</td><td>Crédits en souffrance</td><td class="text-right"><?= number_format($B70_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($B70_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($B70_brut - $B70_amort,0,',',' ') ?></td></tr>
                    <tr><td>Z02</td><td>Crédits immobilisés</td><td class="text-right"><?= number_format($Z02_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($Z02_brut,0,',',' ') ?></td></tr>
                    <tr><td>B71</td><td>Souffrance 6 mois au plus</td><td class="text-right"><?= number_format($B71_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($B71_brut,0,',',' ') ?></td></tr>
                    <tr><td>B72</td><td>Souffrance 6-12 mois</td><td class="text-right"><?= number_format($B72_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($B72_brut,0,',',' ') ?></td></tr>
                    <tr><td>B73</td><td>Souffrance 12-24 mois</td><td class="text-right"><?= number_format($B73_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($B73_brut,0,',',' ') ?></td></tr>

                    <!-- C01 -->
                    <tr class="subtotal-row"><td colspan="2">C01 - OPÉRATIONS SUR TITRES ET DIVERSES</td>
                        <td class="text-right"><?= number_format($C01_brut,0,',',' ') ?></td>
                        <td class="text-right"><?= number_format($C01_amort,0,',',' ') ?></td>
                        <td class="text-right"><?= number_format($C01_brut - $C01_amort,0,',',' ') ?></td>
                    </tr>
                    <tr><td>C10</td><td>Titres de placement</td><td class="text-right"><?= number_format($C10_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($C10_brut,0,',',' ') ?></td></tr>
                    <tr><td>C30</td><td>Comptes de stocks</td><td class="text-right"><?= number_format($C30_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($C30_brut,0,',',' ') ?></td></tr>
                    <tr><td>C31</td><td>Stocks de meubles</td><td class="text-right"><?= number_format($C31_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($C31_brut,0,',',' ') ?></td></tr>
                    <tr><td>C32</td><td>Stocks de marchandises</td><td class="text-right"><?= number_format($C32_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($C32_brut,0,',',' ') ?></td></tr>
                    <tr><td>C33</td><td>Stocks de fournitures</td><td class="text-right"><?= number_format($C33_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($C33_brut,0,',',' ') ?></td></tr>
                    <tr><td>C34</td><td>Autres stocks</td><td class="text-right"><?= number_format($C34_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($C34_brut,0,',',' ') ?></td></tr>
                    <tr><td>C40</td><td>Débiteurs divers</td><td class="text-right"><?= number_format($C40_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($C40_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($C40_brut - $C40_amort,0,',',' ') ?></td></tr>
                    <tr><td>C55</td><td>Créances rattachées</td><td class="text-right"><?= number_format($C55_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($C55_brut,0,',',' ') ?></td></tr>
                    <tr><td>C56</td><td>Valeur à l'encaissement</td><td class="text-right"><?= number_format($C56_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($C56_brut,0,',',' ') ?></td></tr>
                    <tr><td>C59</td><td>Valeur à rejeter</td><td class="text-right"><?= number_format($C59_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($C59_brut,0,',',' ') ?></td></tr>
                    <tr><td>C6A</td><td>Comptes d'ordre et divers</td><td class="text-right"><?= number_format($C6A_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($C6A_brut,0,',',' ') ?></td></tr>
                    <tr><td>C6B</td><td>Comptes de liaison</td><td class="text-right"><?= number_format($C6B_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($C6B_brut,0,',',' ') ?></td></tr>
                    <tr><td>C6C</td><td>Diff. de conversion</td><td class="text-right"><?= number_format($C6C_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($C6C_brut,0,',',' ') ?></td></tr>
                    <tr><td>C6G</td><td>Régul. actif</td><td class="text-right"><?= number_format($C6G_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($C6G_brut,0,',',' ') ?></td></tr>
                    <tr><td>C6Q</td><td>Comptes transitoires</td><td class="text-right"><?= number_format($C6Q_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($C6Q_brut,0,',',' ') ?></td></tr>
                    <tr><td>C6R</td><td>Comptes d'attente</td><td class="text-right"><?= number_format($C6R_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($C6R_brut,0,',',' ') ?></td></tr>

                    <!-- D01 -->
                    <tr class="subtotal-row"><td colspan="2">D01 - VALEURS IMMOBILISÉES</td>
                        <td class="text-right"><?= number_format($D01_brut,0,',',' ') ?></td>
                        <td class="text-right"><?= number_format($D01_amort,0,',',' ') ?></td>
                        <td class="text-right"><?= number_format($D01_brut - $D01_amort,0,',',' ') ?></td>
                    </tr>
                    <tr><td>D1A</td><td>Immobilisations financières</td><td class="text-right"><?= number_format($D1A_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($D1A_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($D1A_brut - $D1A_amort,0,',',' ') ?></td></tr>
                    <tr><td>D10</td><td>Prêts et titres subordonnés</td><td class="text-right"><?= number_format($D10_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($D10_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($D10_brut - $D10_amort,0,',',' ') ?></td></tr>
                    <tr><td>D1E</td><td>Titres de participation</td><td class="text-right"><?= number_format($D1E_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($D1E_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($D1E_brut - $D1E_amort,0,',',' ') ?></td></tr>
                    <tr><td>D1L</td><td>Titres d'investissement</td><td class="text-right"><?= number_format($D1L_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($D1L_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($D1L_brut - $D1L_amort,0,',',' ') ?></td></tr>
                    <tr><td>D1S</td><td>Dépôts et cautionnements</td><td class="text-right"><?= number_format($D1S_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($D1S_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($D1S_brut - $D1S_amort,0,',',' ') ?></td></tr>
                    <tr><td>D23</td><td>Immobilisations en cours</td><td class="text-right"><?= number_format($D23_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($D23_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($D23_brut - $D23_amort,0,',',' ') ?></td></tr>
                    <tr><td>D24</td><td>Incorporelles</td><td class="text-right"><?= number_format($D24_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($D24_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($D24_brut - $D24_amort,0,',',' ') ?></td></tr>
                    <tr><td>D25</td><td>Corporelles</td><td class="text-right"><?= number_format($D25_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($D25_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($D25_brut - $D25_amort,0,',',' ') ?></td></tr>
                    <tr><td>D30</td><td>Immobilisations d'exploitation</td><td class="text-right"><?= number_format($D30_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($D30_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($D30_brut - $D30_amort,0,',',' ') ?></td></tr>
                    <tr><td>D31</td><td>Incorporelles</td><td class="text-right"><?= number_format($D31_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($D31_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($D31_brut - $D31_amort,0,',',' ') ?></td></tr>
                    <tr><td>D36</td><td>Corporelles</td><td class="text-right"><?= number_format($D36_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($D36_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($D36_brut - $D36_amort,0,',',' ') ?></td></tr>
                    <tr><td>D40</td><td>Immobilisations hors exploitation</td><td class="text-right"><?= number_format($D40_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($D40_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($D40_brut - $D40_amort,0,',',' ') ?></td></tr>
                    <tr><td>D41</td><td>Incorporelles</td><td class="text-right"><?= number_format($D41_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($D41_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($D41_brut - $D41_amort,0,',',' ') ?></td></tr>
                    <tr><td>D45</td><td>Corporelles</td><td class="text-right"><?= number_format($D45_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($D45_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($D45_brut - $D45_amort,0,',',' ') ?></td></tr>
                    <tr><td>Z03</td><td>Acquises par réalisation de garantie</td><td class="text-right"><?= number_format($Z03_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($Z03_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($Z03_brut - $Z03_amort,0,',',' ') ?></td></tr>
                    <tr><td>D46</td><td>Incorporelles</td><td class="text-right"><?= number_format($D46_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($D46_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($D46_brut - $D46_amort,0,',',' ') ?></td></tr>
                    <tr><td>D47</td><td>Corporelles</td><td class="text-right"><?= number_format($D47_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($D47_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($D47_brut - $D47_amort,0,',',' ') ?></td></tr>
                    <tr><td>D50</td><td>Crédit bail et assimilés</td><td class="text-right"><?= number_format($D50_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($D50_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($D50_brut - $D50_amort,0,',',' ') ?></td></tr>
                    <tr><td>D51</td><td>Crédit-bail</td><td class="text-right"><?= number_format($D51_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($D51_brut,0,',',' ') ?></td></tr>
                    <tr><td>D52</td><td>L.o.a.</td><td class="text-right"><?= number_format($D52_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($D52_brut,0,',',' ') ?></td></tr>
                    <tr><td>D53</td><td>Location vente</td><td class="text-right"><?= number_format($D53_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($D53_brut,0,',',' ') ?></td></tr>
                    <tr><td>D60</td><td>Créances rattachées</td><td class="text-right"><?= number_format($D60_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($D60_brut,0,',',' ') ?></td></tr>
                    <tr><td>D70</td><td>Créances en souffrance</td><td class="text-right"><?= number_format($D70_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($D70_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($D70_brut - $D70_amort,0,',',' ') ?></td></tr>
                    <tr><td>D71</td><td>Souffrance 6 mois au plus</td><td class="text-right"><?= number_format($D71_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($D71_brut,0,',',' ') ?></td></tr>
                    <tr><td>D72</td><td>Souffrance 6-12 mois</td><td class="text-right"><?= number_format($D72_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($D72_brut,0,',',' ') ?></td></tr>
                    <tr><td>D73</td><td>Souffrance 12-24 mois</td><td class="text-right"><?= number_format($D73_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($D73_brut,0,',',' ') ?></td></tr>

                    <!-- E01 -->
                    <tr class="subtotal-row"><td colspan="2">E01 - ACTIONNAIRES, ASSOCIÉS</td>
                        <td class="text-right"><?= number_format($E01_brut,0,',',' ') ?></td>
                        <td class="text-right">0</td>
                        <td class="text-right"><?= number_format($E01_brut,0,',',' ') ?></td>
                    </tr>
                    <tr><td>E02</td><td>Capital non appelé</td><td class="text-right"><?= number_format($E02_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($E02_brut,0,',',' ') ?></td></tr>
                    <tr><td>E03</td><td>Capital appelé non versé</td><td class="text-right"><?= number_format($E03_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($E03_brut,0,',',' ') ?></td></tr>
                    <tr><td>E05</td><td>Excédent de charges sur produits</td><td class="text-right"><?= number_format($E05_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($E05_brut,0,',',' ') ?></td></tr>

                    <!-- Total -->
                    <tr class="total-row"><td colspan="2"><strong>E90 - TOTAL ACTIF</strong></td>
                        <td class="text-right"><strong><?= number_format($total_actif_brut,0,',',' ') ?></strong></td>
                        <td class="text-right"><strong><?= number_format($total_actif_amort,0,',',' ') ?></strong></td>
                        <td class="text-right"><strong><?= number_format($total_actif_net,0,',',' ') ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- PASSIF -->
    <div class="card">
        <div class="card-header"><i class="fas fa-wallet"></i> BILAN PASSIF</div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>CODE</th><th>POSTE PASSIF</th><th class="text-right">Net (FCFA)</th></tr></thead>
                <tbody>
                    <tr class="subtotal-row"><td colspan="2">F01 - OPÉRATIONS DE TRÉSORERIE ET AVEC LES IF</td><td class="text-right"><?= number_format($F01_net,0,',',' ') ?></td></tr>
                    <tr><td>F1A</td><td>Comptes ordinaires créditeurs</td><td class="text-right"><?= number_format($F1A_net,0,',',' ') ?></td></tr>
                    <tr><td>F2A</td><td>Autres comptes de dépôts créditeurs</td><td class="text-right"><?= number_format($F2A_net,0,',',' ') ?></td></tr>
                    <tr><td>F2B</td><td>Dépôts à terme reçus</td><td class="text-right"><?= number_format($F2B_net,0,',',' ') ?></td></tr>
                    <tr><td>F2C</td><td>Dépôts de garantie reçus</td><td class="text-right"><?= number_format($F2C_net,0,',',' ') ?></td></tr>
                    <tr><td>F2D</td><td>Autres dépôts reçus</td><td class="text-right"><?= number_format($F2D_net,0,',',' ') ?></td></tr>
                    <tr><td>F3A</td><td>Comptes d'emprunts</td><td class="text-right"><?= number_format($F3A_net,0,',',' ') ?></td></tr>
                    <tr><td>F3E</td><td>Emprunts à moins d'un an</td><td class="text-right"><?= number_format($F3E_net,0,',',' ') ?></td></tr>
                    <tr><td>F3F</td><td>Emprunts à terme</td><td class="text-right"><?= number_format($F3F_net,0,',',' ') ?></td></tr>
                    <tr><td>F50</td><td>Autres sommes dues aux IF</td><td class="text-right"><?= number_format($F50_net,0,',',' ') ?></td></tr>
                    <tr><td>F55</td><td>Ressources affectées</td><td class="text-right"><?= number_format($F55_net,0,',',' ') ?></td></tr>
                    <tr><td>F60</td><td>Dettes rattachées</td><td class="text-right"><?= number_format($F60_net,0,',',' ') ?></td></tr>

                    <tr class="subtotal-row"><td colspan="2">G01 - OPÉRATIONS AVEC LES MEMBRES</td><td class="text-right"><?= number_format($G01_net,0,',',' ') ?></td></tr>
                    <tr><td>G10</td><td>Comptes ordinaires créditeurs</td><td class="text-right"><?= number_format($G10_net,0,',',' ') ?></td></tr>
                    <tr><td>G15</td><td>Dépôts à terme reçus</td><td class="text-right"><?= number_format($G15_net,0,',',' ') ?></td></tr>
                    <tr><td>G2A</td><td>Comptes d'épargne à régime spécial</td><td class="text-right"><?= number_format($G2A_net,0,',',' ') ?></td></tr>
                    <tr><td>G30</td><td>Autres dépôts de garantie reçus</td><td class="text-right"><?= number_format($G30_net,0,',',' ') ?></td></tr>
                    <tr><td>G35</td><td>Autres dépôts reçus</td><td class="text-right"><?= number_format($G35_net,0,',',' ') ?></td></tr>
                    <tr><td>G60</td><td>Emprunts</td><td class="text-right"><?= number_format($G60_net,0,',',' ') ?></td></tr>
                    <tr><td>G70</td><td>Autres sommes dues</td><td class="text-right"><?= number_format($G70_net,0,',',' ') ?></td></tr>
                    <tr><td>G90</td><td>Dettes rattachées</td><td class="text-right"><?= number_format($G90_net,0,',',' ') ?></td></tr>

                    <tr class="subtotal-row"><td colspan="2">H01 - OPÉRATIONS SUR TITRES ET DIVERSES</td><td class="text-right"><?= number_format($H01_net,0,',',' ') ?></td></tr>
                    <tr><td>H10</td><td>Versements restant à effectuer</td><td class="text-right"><?= number_format($H10_net,0,',',' ') ?></td></tr>
                    <tr><td>H40</td><td>Créditeurs divers</td><td class="text-right"><?= number_format($H40_net,0,',',' ') ?></td></tr>
                    <tr><td>H6A</td><td>Comptes d'ordre et divers</td><td class="text-right"><?= number_format($H6A_net,0,',',' ') ?></td></tr>
                    <tr><td>H6B</td><td>Comptes de liaison</td><td class="text-right"><?= number_format($H6B_net,0,',',' ') ?></td></tr>
                    <tr><td>H6C</td><td>Diff. de conversion</td><td class="text-right"><?= number_format($H6C_net,0,',',' ') ?></td></tr>
                    <tr><td>H6G</td><td>Régul. passif</td><td class="text-right"><?= number_format($H6G_net,0,',',' ') ?></td></tr>
                    <tr><td>H6P</td><td>Comptes d'attente</td><td class="text-right"><?= number_format($H6P_net,0,',',' ') ?></td></tr>

                    <tr class="subtotal-row"><td colspan="2">K01 - VERSEMENTS RESTANT À EFFECTUER</td><td class="text-right"><?= number_format($K01_net,0,',',' ') ?></td></tr>
                    <tr><td>K20</td><td>Titres de participation</td><td class="text-right">0</td></tr>

                    <tr class="subtotal-row"><td colspan="2">L01 - PROVISIONS, FONDS PROPRES ET ASSIMILÉS</td><td class="text-right"><?= number_format($L01_net,0,',',' ') ?></td></tr>
                    <tr><td>L10</td><td>Subventions d'investissement</td><td class="text-right"><?= number_format($L10_net,0,',',' ') ?></td></tr>
                    <tr><td>L20</td><td>Fonds affectés</td><td class="text-right"><?= number_format($L20_net,0,',',' ') ?></td></tr>
                    <tr><td>L21</td><td>Fonds de garantie</td><td class="text-right"><?= number_format($L21_net,0,',',' ') ?></td></tr>
                    <tr><td>L22</td><td>Fonds d'assurance</td><td class="text-right"><?= number_format($L22_net,0,',',' ') ?></td></tr>
                    <tr><td>L23</td><td>Fonds de bonification</td><td class="text-right"><?= number_format($L23_net,0,',',' ') ?></td></tr>
                    <tr><td>L24</td><td>Fonds de sécurité</td><td class="text-right"><?= number_format($L24_net,0,',',' ') ?></td></tr>
                    <tr><td>L25</td><td>Autres fonds affectés</td><td class="text-right"><?= number_format($L25_net,0,',',' ') ?></td></tr>
                    <tr><td>L27</td><td>Fonds de crédit</td><td class="text-right"><?= number_format($L27_net,0,',',' ') ?></td></tr>
                    <tr><td>L30</td><td>Provisions pour risques et charges</td><td class="text-right"><?= number_format($L30_net,0,',',' ') ?></td></tr>
                    <tr><td>L31</td><td>Provisions pour charges de retraite</td><td class="text-right"><?= number_format($L31_net,0,',',' ') ?></td></tr>
                    <tr><td>L32</td><td>Provisions pour risque d'exécution</td><td class="text-right"><?= number_format($L32_net,0,',',' ') ?></td></tr>
                    <tr><td>L33</td><td>Autres provisions pour risques</td><td class="text-right"><?= number_format($L33_net,0,',',' ') ?></td></tr>
                    <tr><td>L35</td><td>Provisions réglementées</td><td class="text-right"><?= number_format($L35_net,0,',',' ') ?></td></tr>
                    <tr><td>L36</td><td>Provisions pour risques afférents</td><td class="text-right"><?= number_format($L36_net,0,',',' ') ?></td></tr>
                    <tr><td>L37</td><td>Provision spéciale de réévaluation</td><td class="text-right"><?= number_format($L37_net,0,',',' ') ?></td></tr>
                    <tr><td>L41</td><td>Emprunts et titres subordonnés</td><td class="text-right"><?= number_format($L41_net,0,',',' ') ?></td></tr>
                    <tr><td>L43</td><td>Dettes rattachées aux emprunts</td><td class="text-right"><?= number_format($L43_net,0,',',' ') ?></td></tr>
                    <tr><td>L45</td><td>Fonds pour risques financiers</td><td class="text-right"><?= number_format($L45_net,0,',',' ') ?></td></tr>
                    <tr><td>L50</td><td>Primes liées au capital</td><td class="text-right"><?= number_format($L50_net,0,',',' ') ?></td></tr>
                    <tr><td>L55</td><td>Réserves</td><td class="text-right"><?= number_format($L55_net,0,',',' ') ?></td></tr>
                    <tr><td>L56</td><td>Réserve générale</td><td class="text-right"><?= number_format($L56_net,0,',',' ') ?></td></tr>
                    <tr><td>L57</td><td>Réserves facultatives</td><td class="text-right"><?= number_format($L57_net,0,',',' ') ?></td></tr>
                    <tr><td>L58</td><td>Autres réserves</td><td class="text-right"><?= number_format($L58_net,0,',',' ') ?></td></tr>
                    <tr><td>L60</td><td>Capital</td><td class="text-right"><?= number_format($L60_net,0,',',' ') ?></td></tr>
                    <tr><td>L61</td><td>Capital appelé</td><td class="text-right"><?= number_format($L61_net,0,',',' ') ?></td></tr>
                    <tr><td>L62</td><td>Capital non appelé</td><td class="text-right"><?= number_format($L62_net,0,',',' ') ?></td></tr>
                    <tr><td>L65</td><td>Fonds de dotation</td><td class="text-right"><?= number_format($L65_net,0,',',' ') ?></td></tr>
                    <tr><td>L70</td><td>Report à nouveau</td><td class="text-right"><?= number_format($L70_net,0,',',' ') ?></td></tr>
                    <tr><td>L75</td><td>Excédent des produits sur charges</td><td class="text-right"><?= number_format($L75_net,0,',',' ') ?></td></tr>
                    <tr><td>L80</td><td>Résultat de l'exercice</td><td class="text-right"><?= number_format($L80_net,0,',',' ') ?></td></tr>
                    <tr><td>L81</td><td>Excédent en instance d'approbation</td><td class="text-right"><?= number_format($L81_net,0,',',' ') ?></td></tr>
                    <tr><td>L82</td><td>Excédent de l'exercice</td><td class="text-right"><?= number_format($L82_net,0,',',' ') ?></td></tr>

                    <tr class="total-row"><td colspan="2"><strong>L90 - TOTAL PASSIF</strong></td><td class="text-right"><strong><?= number_format($total_passif,0,',',' ') ?></strong></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- HORS BILAN -->
    <div class="card">
        <div class="card-header"><i class="fas fa-clipboard-list"></i> HORS BILAN</div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>CODE</th><th>ENGAGEMENTS</th><th class="text-right">Net (FCFA)</th></tr></thead>
                <tbody>
                    <tr class="subtotal-row"><td colspan="2">Z11 - ENGAGEMENTS DE FINANCEMENT</td><td class="text-right">0</td></tr>
                    <tr><td>N1A</td><td>Engagements donnés en faveur des IF</td><td class="text-right">0</td></tr>
                    <tr><td>N1H</td><td>Engagements reçus des IF</td><td class="text-right"><?= number_format($N1H_net,0,',',' ') ?></td></tr>
                    <tr><td>N1J</td><td>Engagements donnés en faveur des membres</td><td class="text-right">0</td></tr>
                    <tr><td>N1K</td><td>Engagements reçus des membres</td><td class="text-right">0</td></tr>

                    <tr class="subtotal-row"><td colspan="2">Z12 - ENGAGEMENTS DE GARANTIE</td><td class="text-right">0</td></tr>
                    <tr><td>N2A</td><td>D'ordre des IF</td><td class="text-right">0</td></tr>
                    <tr><td>N2H</td><td>Reçus des IF</td><td class="text-right">0</td></tr>
                    <tr><td>N2J</td><td>D'ordre des membres</td><td class="text-right">0</td></tr>
                    <tr><td>N2M</td><td>Reçus des membres</td><td class="text-right">0</td></tr>

                    <tr class="subtotal-row"><td colspan="2">Z13 - ENGAGEMENTS SUR TITRES</td><td class="text-right">0</td></tr>
                    <tr><td>N3A</td><td>Titres à livrer</td><td class="text-right">0</td></tr>
                    <tr><td>N3E</td><td>Titres à recevoir</td><td class="text-right">0</td></tr>

                    <tr class="subtotal-row"><td colspan="2">Z14 - ENGAGEMENTS SUR OPÉRATIONS EN DEVISES</td><td class="text-right">0</td></tr>
                    <tr><td>Z15</td><td>Opérations de change au comptant</td><td class="text-right">0</td></tr>
                    <tr><td>Z16</td><td>Prêts ou emprunts en devises</td><td class="text-right">0</td></tr>
                    <tr><td>Z17</td><td>Opérations de change à terme</td><td class="text-right">0</td></tr>

                    <tr class="subtotal-row"><td colspan="2">Z18 - AUTRES ENGAGEMENTS</td><td class="text-right">0</td></tr>
                    <tr><td>Q1A</td><td>Engagements donnés</td><td class="text-right">0</td></tr>
                    <tr><td>Q1B</td><td>Engagements reçus</td><td class="text-right">0</td></tr>

                    <tr class="subtotal-row"><td colspan="2">Z19 - OPÉRATIONS POUR LE COMPTE DE TIERS</td><td class="text-right">0</td></tr>
                    <tr><td>Q1C</td><td>Valeurs à l'encaissement</td><td class="text-right">0</td></tr>
                    <tr><td>Q1F</td><td>Comptes exigibles après encaissement</td><td class="text-right">0</td></tr>
                    <tr><td>Q1J</td><td>Suivi engagements financements consortiaux</td><td class="text-right">0</td></tr>
                    <tr><td>Q1K</td><td>Suivi engagements garanties consortiaux</td><td class="text-right">0</td></tr>
                    <tr><td>Q1L</td><td>Suivi crédits consortiaux</td><td class="text-right">0</td></tr>
                    <tr><td>Q1M</td><td>Crédits distribués pour compte de tiers</td><td class="text-right"><?= number_format($Q1M_net,0,',',' ') ?></td></tr>
                    <tr><td>N90</td><td>ENGAGEMENTS DOUTEUX</td><td class="text-right">0</td></tr>

                    <tr class="total-row"><td colspan="2"><strong>TOTAL HORS BILAN</strong></td><td class="text-right"><strong><?= number_format($total_hors_bilan,0,',',' ') ?></strong></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Équilibre -->
    <div class="card" id="equilibreCard">
        <div class="info-box">
            <i class="fas <?= abs($total_actif_net-$total_passif)>1000?'fa-exclamation-triangle':'fa-check-circle' ?>"></i>
            <div>
                <strong>Vérification de l'équilibre :</strong><br>
                Actif net = <?= number_format($total_actif_net,0,',',' ') ?> FCFA &nbsp;|&nbsp; Passif = <?= number_format($total_passif,0,',',' ') ?> FCFA
                <?php if(abs($total_actif_net-$total_passif)>1000): ?>
                    <br><span style="color:#b91c1c;">⚠️ Écart de <?= number_format(abs($total_actif_net-$total_passif),0,',',' ') ?> FCFA – vérifier les calculs.</span>
                <?php else: ?>
                    <br><span style="color:#15803d;">✓ Bilan équilibré.</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="page-footer">
        <i class="fas fa-calendar-alt"></i> Document généré le <?= date('d/m/Y à H:i:s') ?> – Période : <?= $exercice ?> (<?= ucfirst($type_periode) ?>) arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function updateDynamicSelect() {
        const type = document.getElementById('typePeriodeSelect').value;
        const container = document.getElementById('dynamicSelectContainer');
        const cm = <?= $mois ?>, ct = <?= $trimestre ?>, cs = <?= json_encode($semestre) ?>;
        let html = '';
        if (type === 'mensuel') {
            html = '<label>Mois</label><select name="mois" id="moisSelect">';
            for (let m=1;m<=12;m++) { const s=(m===cm)?'selected':''; const n=new Date(2000,m-1,1).toLocaleString('fr',{month:'long'}); html+=`<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`; }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
            for (let t=1;t<=4;t++) { const s=(t===ct)?'selected':''; html+=`<option value="${t}" ${s}>${t}${t===1?'er':'ème'} Trimestre</option>`; }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select name="semestre" id="semestreSelect">';
            for (let s=1;s<=2;s++) { const sel=(s===cs)?'selected':''; html+=`<option value="${s}" ${sel}>${s}${s===1?'er':'e'} semestre</option>`; }
            html += '</select>';
        } else {
            html = '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">';
        }
        container.innerHTML = html;
    }

    // Fonction PDF : soumission classique (comme dans DIMF_2900)
    function exporterPDF() {
        const form = document.getElementById('filtersForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'format';
        input.value = 'pdf';
        form.appendChild(input);
        // Soumission dans la même fenêtre (pas de target)
        form.submit();
        form.removeChild(input);
    }

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        // Actif
        const dataActif = [
            ['DIMF_2000 - BILAN ACTIF'],
            ['CODE','POSTE ACTIF','Brut (FCFA)','Amortissements (FCFA)','Net (FCFA)'],
            ['A01','OPERATIONS DE TRESORERIE ET AVEC LES IF',<?= $A01_brut ?>,<?= $A01_amort ?>,<?= $A01_brut - $A01_amort ?>],
            ['A10','Valeur en caisse',<?= $A10_brut ?>,0,<?= $A10_brut ?>],
            ['A11','Billets et monnaies',<?= $A11_brut ?>,0,<?= $A11_brut ?>],
            ['A12','Comptes ordinaires débiteurs',<?= $A12_brut ?>,0,<?= $A12_brut ?>],
            ['A2A','Autres comptes de dépôts débiteurs',<?= $A2A_brut ?>,0,<?= $A2A_brut ?>],
            ['A2H','Dépôts à terme constitués',<?= $A2H_brut ?>,0,<?= $A2H_brut ?>],
            ['A3A','Comptes de prêts',<?= $A3A_brut ?>,0,<?= $A3A_brut ?>],
            ['A3B','Prêts à moins d\'un an',<?= $A3B_brut ?>,0,<?= $A3B_brut ?>],
            ['A3C','Prêts à terme',<?= $A3C_brut ?>,0,<?= $A3C_brut ?>],
            ['A60','Créances rattachées',<?= $A60_brut ?>,0,<?= $A60_brut ?>],
            ['A70','Prêts en souffrance',<?= $A70_brut ?>,<?= $A70_amort ?>,<?= $A70_brut - $A70_amort ?>],
            ['Z01','Prêts immobilisés',<?= $Z01_brut ?>,0,<?= $Z01_brut ?>],
            ['A71','Souffrance 6 mois au plus',<?= $A71_brut ?>,0,<?= $A71_brut ?>],
            ['A72','Souffrance 6-12 mois',<?= $A72_brut ?>,0,<?= $A72_brut ?>],
            ['A73','Souffrance 12-24 mois',<?= $A73_brut ?>,0,<?= $A73_brut ?>],
            ['B01','OPERATIONS AVEC LES MEMBRES',<?= $B01_brut ?>,<?= $B01_amort ?>,<?= $B01_brut - $B01_amort ?>],
            ['B2D','Crédits à court terme',<?= $B2D_brut ?>,0,<?= $B2D_brut ?>],
            ['B2N','Comptes ordinaires',<?= $B2N_brut ?>,0,<?= $B2N_brut ?>],
            ['B30','Crédits à moyen terme',<?= $B30_brut ?>,0,<?= $B30_brut ?>],
            ['B40','Crédits à long terme',<?= $B40_brut ?>,0,<?= $B40_brut ?>],
            ['B65','Créances rattachées',<?= $B65_brut ?>,0,<?= $B65_brut ?>],
            ['B70','Crédits en souffrance',<?= $B70_brut ?>,<?= $B70_amort ?>,<?= $B70_brut - $B70_amort ?>],
            ['Z02','Crédits immobilisés',<?= $Z02_brut ?>,0,<?= $Z02_brut ?>],
            ['B71','Souffrance 6 mois au plus',<?= $B71_brut ?>,0,<?= $B71_brut ?>],
            ['B72','Souffrance 6-12 mois',<?= $B72_brut ?>,0,<?= $B72_brut ?>],
            ['B73','Souffrance 12-24 mois',<?= $B73_brut ?>,0,<?= $B73_brut ?>],
            ['C01','OPERATIONS SUR TITRES ET DIVERSES',<?= $C01_brut ?>,<?= $C01_amort ?>,<?= $C01_brut - $C01_amort ?>],
            ['C10','Titres de placement',<?= $C10_brut ?>,0,<?= $C10_brut ?>],
            ['C30','Comptes de stocks',<?= $C30_brut ?>,0,<?= $C30_brut ?>],
            ['C31','Stocks de meubles',<?= $C31_brut ?>,0,<?= $C31_brut ?>],
            ['C32','Stocks de marchandises',<?= $C32_brut ?>,0,<?= $C32_brut ?>],
            ['C33','Stocks de fournitures',<?= $C33_brut ?>,0,<?= $C33_brut ?>],
            ['C34','Autres stocks',<?= $C34_brut ?>,0,<?= $C34_brut ?>],
            ['C40','Débiteurs divers',<?= $C40_brut ?>,<?= $C40_amort ?>,<?= $C40_brut - $C40_amort ?>],
            ['C55','Créances rattachées',<?= $C55_brut ?>,0,<?= $C55_brut ?>],
            ['C56','Valeur à l\'encaissement',<?= $C56_brut ?>,0,<?= $C56_brut ?>],
            ['C59','Valeur à rejeter',<?= $C59_brut ?>,0,<?= $C59_brut ?>],
            ['C6A','Comptes d\'ordre et divers',<?= $C6A_brut ?>,0,<?= $C6A_brut ?>],
            ['C6B','Comptes de liaison',<?= $C6B_brut ?>,0,<?= $C6B_brut ?>],
            ['C6C','Diff. de conversion',<?= $C6C_brut ?>,0,<?= $C6C_brut ?>],
            ['C6G','Régul. actif',<?= $C6G_brut ?>,0,<?= $C6G_brut ?>],
            ['C6Q','Comptes transitoires',<?= $C6Q_brut ?>,0,<?= $C6Q_brut ?>],
            ['C6R','Comptes d\'attente',<?= $C6R_brut ?>,0,<?= $C6R_brut ?>],
            ['D01','VALEURS IMMOBILISÉES',<?= $D01_brut ?>,<?= $D01_amort ?>,<?= $D01_brut - $D01_amort ?>],
            ['D1A','Immobilisations financières',<?= $D1A_brut ?>,<?= $D1A_amort ?>,<?= $D1A_brut - $D1A_amort ?>],
            ['D10','Prêts et titres subordonnés',<?= $D10_brut ?>,<?= $D10_amort ?>,<?= $D10_brut - $D10_amort ?>],
            ['D1E','Titres de participation',<?= $D1E_brut ?>,<?= $D1E_amort ?>,<?= $D1E_brut - $D1E_amort ?>],
            ['D1L','Titres d\'investissement',<?= $D1L_brut ?>,<?= $D1L_amort ?>,<?= $D1L_brut - $D1L_amort ?>],
            ['D1S','Dépôts et cautionnements',<?= $D1S_brut ?>,<?= $D1S_amort ?>,<?= $D1S_brut - $D1S_amort ?>],
            ['D23','Immobilisations en cours',<?= $D23_brut ?>,<?= $D23_amort ?>,<?= $D23_brut - $D23_amort ?>],
            ['D24','Incorporelles',<?= $D24_brut ?>,<?= $D24_amort ?>,<?= $D24_brut - $D24_amort ?>],
            ['D25','Corporelles',<?= $D25_brut ?>,<?= $D25_amort ?>,<?= $D25_brut - $D25_amort ?>],
            ['D30','Immobilisations d\'exploitation',<?= $D30_brut ?>,<?= $D30_amort ?>,<?= $D30_brut - $D30_amort ?>],
            ['D31','Incorporelles',<?= $D31_brut ?>,<?= $D31_amort ?>,<?= $D31_brut - $D31_amort ?>],
            ['D36','Corporelles',<?= $D36_brut ?>,<?= $D36_amort ?>,<?= $D36_brut - $D36_amort ?>],
            ['D40','Immobilisations hors exploitation',<?= $D40_brut ?>,<?= $D40_amort ?>,<?= $D40_brut - $D40_amort ?>],
            ['D41','Incorporelles',<?= $D41_brut ?>,<?= $D41_amort ?>,<?= $D41_brut - $D41_amort ?>],
            ['D45','Corporelles',<?= $D45_brut ?>,<?= $D45_amort ?>,<?= $D45_brut - $D45_amort ?>],
            ['Z03','Acquises par réalisation de garantie',<?= $Z03_brut ?>,<?= $Z03_amort ?>,<?= $Z03_brut - $Z03_amort ?>],
            ['D46','Incorporelles',<?= $D46_brut ?>,<?= $D46_amort ?>,<?= $D46_brut - $D46_amort ?>],
            ['D47','Corporelles',<?= $D47_brut ?>,<?= $D47_amort ?>,<?= $D47_brut - $D47_amort ?>],
            ['D50','Crédit bail et assimilés',<?= $D50_brut ?>,<?= $D50_amort ?>,<?= $D50_brut - $D50_amort ?>],
            ['D51','Crédit-bail',<?= $D51_brut ?>,0,<?= $D51_brut ?>],
            ['D52','L.o.a.',<?= $D52_brut ?>,0,<?= $D52_brut ?>],
            ['D53','Location vente',<?= $D53_brut ?>,0,<?= $D53_brut ?>],
            ['D60','Créances rattachées',<?= $D60_brut ?>,0,<?= $D60_brut ?>],
            ['D70','Créances en souffrance',<?= $D70_brut ?>,<?= $D70_amort ?>,<?= $D70_brut - $D70_amort ?>],
            ['D71','Souffrance 6 mois au plus',<?= $D71_brut ?>,0,<?= $D71_brut ?>],
            ['D72','Souffrance 6-12 mois',<?= $D72_brut ?>,0,<?= $D72_brut ?>],
            ['D73','Souffrance 12-24 mois',<?= $D73_brut ?>,0,<?= $D73_brut ?>],
            ['E01','ACTIONNAIRES, ASSOCIÉS',<?= $E01_brut ?>,0,<?= $E01_brut ?>],
            ['E02','Capital non appelé',<?= $E02_brut ?>,0,<?= $E02_brut ?>],
            ['E03','Capital appelé non versé',<?= $E03_brut ?>,0,<?= $E03_brut ?>],
            ['E05','Excédent de charges sur produits',<?= $E05_brut ?>,0,<?= $E05_brut ?>],
            ['E90','TOTAL ACTIF',<?= $total_actif_brut ?>,<?= $total_actif_amort ?>,<?= $total_actif_net ?>]
        ];
        const wsActif = XLSX.utils.aoa_to_sheet(dataActif);
        XLSX.utils.book_append_sheet(wb, wsActif, "ACTIF");

        // Passif
        const dataPassif = [
            ['DIMF_2000 - BILAN PASSIF'],
            ['CODE','POSTE PASSIF','Net (FCFA)'],
            ['F01','OPERATIONS DE TRÉSORERIE ET AVEC LES IF',<?= $F01_net ?>],
            ['F1A','Comptes ordinaires créditeurs',<?= $F1A_net ?>],
            ['F2A','Autres comptes de dépôts créditeurs',<?= $F2A_net ?>],
            ['F2B','Dépôts à terme reçus',<?= $F2B_net ?>],
            ['F2C','Dépôts de garantie reçus',<?= $F2C_net ?>],
            ['F2D','Autres dépôts reçus',<?= $F2D_net ?>],
            ['F3A','Comptes d\'emprunts',<?= $F3A_net ?>],
            ['F3E','Emprunts à moins d\'un an',<?= $F3E_net ?>],
            ['F3F','Emprunts à terme',<?= $F3F_net ?>],
            ['F50','Autres sommes dues aux IF',<?= $F50_net ?>],
            ['F55','Ressources affectées',<?= $F55_net ?>],
            ['F60','Dettes rattachées',<?= $F60_net ?>],
            ['G01','OPERATIONS AVEC LES MEMBRES',<?= $G01_net ?>],
            ['G10','Comptes ordinaires créditeurs',<?= $G10_net ?>],
            ['G15','Dépôts à terme reçus',<?= $G15_net ?>],
            ['G2A','Comptes d\'épargne à régime spécial',<?= $G2A_net ?>],
            ['G30','Autres dépôts de garantie reçus',<?= $G30_net ?>],
            ['G35','Autres dépôts reçus',<?= $G35_net ?>],
            ['G60','Emprunts',<?= $G60_net ?>],
            ['G70','Autres sommes dues',<?= $G70_net ?>],
            ['G90','Dettes rattachées',<?= $G90_net ?>],
            ['H01','OPÉRATIONS SUR TITRES ET DIVERSES',<?= $H01_net ?>],
            ['H10','Versements restant à effectuer',<?= $H10_net ?>],
            ['H40','Créditeurs divers',<?= $H40_net ?>],
            ['H6A','Comptes d\'ordre et divers',<?= $H6A_net ?>],
            ['H6B','Comptes de liaison',<?= $H6B_net ?>],
            ['H6C','Diff. de conversion',<?= $H6C_net ?>],
            ['H6G','Régul. passif',<?= $H6G_net ?>],
            ['H6P','Comptes d\'attente',<?= $H6P_net ?>],
            ['K01','VERSEMENTS RESTANT À EFFECTUER',<?= $K01_net ?>],
            ['K20','Titres de participation',0],
            ['L01','PROVISIONS, FONDS PROPRES ET ASSIMILÉS',<?= $L01_net ?>],
            ['L10','Subventions d\'investissement',<?= $L10_net ?>],
            ['L20','Fonds affectés',<?= $L20_net ?>],
            ['L21','Fonds de garantie',<?= $L21_net ?>],
            ['L22','Fonds d\'assurance',<?= $L22_net ?>],
            ['L23','Fonds de bonification',<?= $L23_net ?>],
            ['L24','Fonds de sécurité',<?= $L24_net ?>],
            ['L25','Autres fonds affectés',<?= $L25_net ?>],
            ['L27','Fonds de crédit',<?= $L27_net ?>],
            ['L30','Provisions pour risques et charges',<?= $L30_net ?>],
            ['L31','Provisions pour charges de retraite',<?= $L31_net ?>],
            ['L32','Provisions pour risque d\'exécution',<?= $L32_net ?>],
            ['L33','Autres provisions pour risques',<?= $L33_net ?>],
            ['L35','Provisions réglementées',<?= $L35_net ?>],
            ['L36','Provisions pour risques afférents',<?= $L36_net ?>],
            ['L37','Provision spéciale de réévaluation',<?= $L37_net ?>],
            ['L41','Emprunts et titres subordonnés',<?= $L41_net ?>],
            ['L43','Dettes rattachées aux emprunts',<?= $L43_net ?>],
            ['L45','Fonds pour risques financiers',<?= $L45_net ?>],
            ['L50','Primes liées au capital',<?= $L50_net ?>],
            ['L55','Réserves',<?= $L55_net ?>],
            ['L56','Réserve générale',<?= $L56_net ?>],
            ['L57','Réserves facultatives',<?= $L57_net ?>],
            ['L58','Autres réserves',<?= $L58_net ?>],
            ['L60','Capital',<?= $L60_net ?>],
            ['L61','Capital appelé',<?= $L61_net ?>],
            ['L62','Capital non appelé',<?= $L62_net ?>],
            ['L65','Fonds de dotation',<?= $L65_net ?>],
            ['L70','Report à nouveau',<?= $L70_net ?>],
            ['L75','Excédent des produits sur charges',<?= $L75_net ?>],
            ['L80','Résultat de l\'exercice',<?= $L80_net ?>],
            ['L81','Excédent en instance d\'approbation',<?= $L81_net ?>],
            ['L82','Excédent de l\'exercice',<?= $L82_net ?>],
            ['L90','TOTAL PASSIF',<?= $total_passif ?>]
        ];
        const wsPassif = XLSX.utils.aoa_to_sheet(dataPassif);
        XLSX.utils.book_append_sheet(wb, wsPassif, "PASSIF");

        // Hors Bilan
        const dataHB = [
            ['DIMF_2000 - HORS BILAN'],
            ['CODE','ENGAGEMENTS','Net (FCFA)'],
            ['Z11','ENGAGEMENTS DE FINANCEMENT',0],
            ['N1A','Engagements donnés en faveur des IF',0],
            ['N1H','Engagements reçus des IF',<?= $N1H_net ?>],
            ['N1J','Engagements donnés en faveur des membres',0],
            ['N1K','Engagements reçus des membres',0],
            ['Z12','ENGAGEMENTS DE GARANTIE',0],
            ['N2A','D\'ordre des IF',0],
            ['N2H','Reçus des IF',0],
            ['N2J','D\'ordre des membres',0],
            ['N2M','Reçus des membres',0],
            ['Z13','ENGAGEMENTS SUR TITRES',0],
            ['N3A','Titres à livrer',0],
            ['N3E','Titres à recevoir',0],
            ['Z14','ENGAGEMENTS SUR OPÉRATIONS EN DEVISES',0],
            ['Z15','Opérations de change au comptant',0],
            ['Z16','Prêts ou emprunts en devises',0],
            ['Z17','Opérations de change à terme',0],
            ['Z18','AUTRES ENGAGEMENTS',0],
            ['Q1A','Engagements donnés',0],
            ['Q1B','Engagements reçus',0],
            ['Z19','OPÉRATIONS POUR LE COMPTE DE TIERS',0],
            ['Q1C','Valeurs à l\'encaissement',0],
            ['Q1F','Comptes exigibles après encaissement',0],
            ['Q1J','Suivi engagements financements consortiaux',0],
            ['Q1K','Suivi engagements garanties consortiaux',0],
            ['Q1L','Suivi crédits consortiaux',0],
            ['Q1M','Crédits distribués pour compte de tiers',<?= $Q1M_net ?>],
            ['N90','ENGAGEMENTS DOUTEUX',0],
            ['TOTAL','TOTAL HORS BILAN',<?= $total_hors_bilan ?>]
        ];
        const wsHB = XLSX.utils.aoa_to_sheet(dataHB);
        XLSX.utils.book_append_sheet(wb, wsHB, "HORS_BILAN");

        XLSX.writeFile(wb, 'DIMF_2000_Bilan_<?= $exercice ?>_<?= $type_periode ?>.xlsx');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
        document.getElementById('btnPdf').addEventListener('click', exporterPDF);
    });
</script>
</body>
</html>