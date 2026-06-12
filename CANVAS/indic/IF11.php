<?php
// IF11.php - Indicateurs financiers (Qualité du portefeuille et activités)
// Design DIMF_2000 identique à R01.php
// Structure des tableaux conforme au fichier IF11.xlsx (Colonnes: Code RCSFD, Source, Indicateur, Valeur)

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ------------------------- CONNEXION BDD -------------------------
$host = 'localhost';
$dbname = 'microfinances_dg';
$username = 'root';
$password = '';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

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

// ============================================================
// CALCUL DES INDICATEURS (LOGIQUE INCHANGÉE)
// ============================================================

// 1. Portefeuille total et encours par retard
$encours_total = 0;
$encours_30 = 0;
$encours_90 = 0;
$encours_180 = 0;
$nb_credits_30 = $nb_credits_90 = $nb_credits_180 = 0;

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
        $encours_total += $d['encours'];
        if ($d['dernier_impaye']) {
            $date_imp = new DateTime($d['dernier_impaye']);
            $jours = $date_ref->diff($date_imp)->days;
            if ($jours >= 30) { $encours_30 += $d['encours']; $nb_credits_30++; }
            if ($jours >= 90) { $encours_90 += $d['encours']; $nb_credits_90++; }
            if ($jours >= 180) { $encours_180 += $d['encours']; $nb_credits_180++; }
        }
    }
} catch (PDOException $e) { $encours_total = 0; }

$par30 = ($encours_total > 0) ? $encours_30 / $encours_total : 0;
$par90 = ($encours_total > 0) ? $encours_90 / $encours_total : 0;
$par180 = ($encours_total > 0) ? $encours_180 / $encours_total : 0;

// 2. Taux de provisions pour créances en souffrance
$provisions = 0;
$encours_souffrance = $encours_90; // PAR90 utilisé comme créances en souffrance
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant),0) as total FROM provisions WHERE statut='actif' AND date_provision <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $provisions = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $provisions = 0; }
$taux_provision = ($encours_souffrance > 0) ? $provisions / $encours_souffrance : 0;

// 3. Taux de perte sur créances
$pertes = 0;
try {
    $stmt = $pdo->prepare("
    SELECT COALESCE(SUM(montant_debit),0) as total 
    FROM ecritures_comptables e
    INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
    WHERE pc.numero_compte LIKE '657%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $pertes = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $pertes = 0; }
$taux_perte = ($encours_total > 0) ? $pertes / $encours_total : 0;

// 4. Montant moyen des crédits décaissés
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

// 5. Montant moyen de l'épargne par épargnant
$total_epargne = 0;
$nb_epargnants = 0;
try {
    $stmt = $pdo->prepare("
    SELECT COALESCE(SUM(c.solde),0) as total 
    FROM comptes c
    INNER JOIN produits p ON c.produit_id = p.produit_id
    INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id
    WHERE pf.categorie = 'Epargne' AND c.statut='actif' AND c.solde>0
    ");
    $stmt->execute();
    $total_epargne = (float)$stmt->fetch()['total'];

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

// 6. Encours moyen des crédits par emprunteur
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

// Normes et conformités (pour info interne, non affichées dans les nouveaux tableaux)
$norme_par30 = 0.05; $norme_par90 = 0.03; $norme_par180 = 0.02;
$norme_provision = 0.40; $norme_perte = 0.02;
$conformite_par30 = ($par30 <= $norme_par30) ? 'CONFORME' : 'NON CONFORME';
$conformite_par90 = ($par90 <= $norme_par90) ? 'CONFORME' : 'NON CONFORME';
$conformite_par180 = ($par180 <= $norme_par180) ? 'CONFORME' : 'NON CONFORME';
$conformite_provision = ($taux_provision >= $norme_provision) ? 'CONFORME' : 'NON CONFORME';
$conformite_perte = ($taux_perte <= $norme_perte) ? 'CONFORME' : 'NON CONFORME';

// ============================================================
// PRÉPARATION DES TABLEAUX EXCEL (Structure IF11.xlsx)
// ============================================================

$tableau_qualite_excel = [
    // PAR30
    ['code' => 'Z60', 'source' => 'DIMF_2000_ACTIF_DEV', 'indicateur' => 'Encours des prêts comportant au moins une échéance impayée de 30 jours (A)', 'valeur' => number_format($encours_30, 0, ',', ' ')],
    ['code' => 'B2D + B2N + B30 + B40 + B70', 'source' => 'Actif brut', 'indicateur' => 'Montant brut du portefeuille de prêts (B)', 'valeur' => number_format($encours_total, 0, ',', ' ')],
    ['code' => '', 'source' => 'DIMF_2000_ACTIF_DEV', 'indicateur' => 'Ratio A/B', 'valeur' => number_format($par30 * 100, 2) . '%'],

    // PAR90
    ['code' => 'B70', 'source' => 'Actif brut', 'indicateur' => 'Encours des prêts comportant au moins une échéance impayée de 90 jours (A)', 'valeur' => number_format($encours_90, 0, ',', ' ')],
    ['code' => '', 'source' => 'DIMF_2000_ACTIF_DEV', 'indicateur' => 'Montant brut du portfeuille de prêts (B)', 'valeur' => number_format($encours_total, 0, ',', ' ')],
    ['code' => '', 'source' => 'DIMF_2000_ACTIF_DEV', 'indicateur' => 'Ratio A/B (Norme <= 3%)', 'valeur' => number_format($par90 * 100, 2) . '%'],

    // PAR180
    ['code' => 'B72 + B73', 'source' => 'Actif brut', 'indicateur' => 'Encours des prêts comportant au moins une échéance impayée de 180 jours (A)', 'valeur' => number_format($encours_180, 0, ',', ' ')],
    ['code' => '', 'source' => 'Actif brut', 'indicateur' => 'Montant brut du portfeuille de prêts (B)', 'valeur' => number_format($encours_total, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme <= 2%)', 'valeur' => number_format($par180 * 100, 2) . '%'],

    // Taux de provisions
    ['code' => 'B70', 'source' => 'Amortissement', 'indicateur' => 'Montant brut des provisions constituées (A)', 'valeur' => number_format($provisions, 0, ',', ' ')],
    ['code' => 'B70', 'source' => 'Actif brut', 'indicateur' => 'Montant brut des créances en souffrance (B)', 'valeur' => number_format($encours_90, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme >= 40%)', 'valeur' => number_format($taux_provision * 100, 2) . '%'],

    // Taux de perte
    ['code' => 'T6K + T6L', 'source' => 'Charges', 'indicateur' => 'Montant des crédits passés en perte durant la période (A)', 'valeur' => number_format($pertes, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Montant brut du portfeuille de crédit de la période (B)', 'valeur' => number_format($encours_total, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme <= 2%)', 'valeur' => number_format($taux_perte * 100, 2) . '%'],
];

$tableau_activites_excel = [
    // Montant moyen des crédits décaissés
    ['code' => 'Y04101', 'source' => 'Instruction N°18', 'indicateur' => 'Montant total des crédits décaissés au cours de la période (A)', 'valeur' => number_format($total_decaisse, 0, ',', ' ')],
    ['code' => 'Y04201', 'source' => 'Instruction N°18', 'indicateur' => 'Nombre total des crédits décaissés au cours de la période (B)', 'valeur' => number_format($nb_decaisse, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme: Tendence haussière)', 'valeur' => number_format($montant_moyen_credit, 0, ',', ' ')],

    // Montant moyen de l'épargne par épargnant
    ['code' => 'G10 + G15 + G2A + G30 + G35', 'source' => 'Passif', 'indicateur' => 'Montant total des dépôts à la fin de la période  (A)', 'valeur' => number_format($total_epargne, 0, ',', ' ')],
    ['code' => 'Y03301', 'source' => 'Instruction N°18', 'indicateur' => 'Nombre d\'épargnant à la fin de la période  (B)', 'valeur' => number_format($nb_epargnants, 0, ',', ' ')],
    ['code' => '', 'source' => 'ANNEXES_AU_RAPPORT_ANNUEL', 'indicateur' => 'Ratio A/B (Norme: Tendence haussière)', 'valeur' => number_format($montant_moyen_epargne, 0, ',', ' ')],

    // Encours moyen des crédits par emprunteur
    ['code' => '', 'source' => '', 'indicateur' => 'Total des encours des crédits à la fin de la période  (A)', 'valeur' => number_format($encours_total, 0, ',', ' ')],
    ['code' => 'Y04501', 'source' => 'Instruction N°18', 'indicateur' => 'Nombre d\'emprunteurs actifs (A)', 'valeur' => number_format($nb_emprunteurs, 0, ',', ' ')],
    ['code' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme: Tendence haussière)', 'valeur' => number_format($encours_moyen_emprunteur, 0, ',', ' ')],
];

// ------------------------- EXPORT PDF AVEC PDF_DIMF (Style R01) -------------------------
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require_once('../../fpdf/fpdf.php'); // Ajustez le chemin si nécessaire
    
    class PDF_DIMF extends FPDF {
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
    $pdf->codeDimf = 'IF11';
    $pdf->titreDimf = 'INDICATEURS FINANCIERS (PARTIE 1)';
    $pdf->nomSfd = 'SFD';
    $pdf->periode = ucfirst($type_periode);
    $pdf->exercice = $exercice;
    $pdf->AddPage();

    // Tableau I-1 (qualité) - 4 Colonnes
    $pdf->SectionTitle("I-1 - INDICATEUR DE QUALITE DU PORTEFEUILLE");
    $cols = [
        ['w' => 30, 'label' => 'Acc', 'align' => 'L'],
        ['w' => 40, 'label' => 'Source', 'align' => 'L'],
        ['w' => 90, 'label' => 'Indicateur', 'align' => 'L'],
        ['w' => 30, 'label' => 'Valeur', 'align' => 'R']
    ];
    $pdf->TableHeader($cols);
    foreach ($tableau_qualite_excel as $row) {
        $pdf->TableRow($cols, [$row['code'], $row['source'], $row['indicateur'], $row['valeur']]);
    }

    // Tableau I-2 (activités) - 4 Colonnes
    $pdf->Ln(5);
    $pdf->SectionTitle("I-2 - INDICATEURS D'ACTIVITES");
    $cols2 = [
        ['w' => 30, 'label' => 'Acc', 'align' => 'L'],
        ['w' => 40, 'label' => 'Source', 'align' => 'L'],
        ['w' => 90, 'label' => 'Indicateur', 'align' => 'L'],
        ['w' => 30, 'label' => 'Valeur', 'align' => 'R']
    ];
    $pdf->TableHeader($cols2);
    foreach ($tableau_activites_excel as $row) {
        $pdf->TableRow($cols2, [$row['code'], $row['source'], $row['indicateur'], $row['valeur']]);
    }

    $pdf->Output('I', 'IF11_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}

// ------------------------- EXPORT EXCEL (HTML .xls) -------------------------
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="IF11_' . $exercice . '_' . $type_periode . '.xls"');
    
    echo '<html><head><meta charset="UTF-8"><style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    h2 { color: #1a3a5c; }
    table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
    th, td { border: 1px solid #999; padding: 8px; }
    th { background: #f2f2f2; text-align: center; font-weight: bold; }
    .text-right { text-align: right; }
    </style></head><body>';
    
    echo '<h2>IF 11 - INDICATEURS FINANCIERS (Qualité du portefeuille et activités)</h2>';
    echo '<p>Période : ' . $exercice . ' - ' . ucfirst($type_periode) . ' (arrêtée au ' . date('d/m/Y', strtotime($date_fin_periode)) . ')</p>';
    
    echo '<h3>I-1 - Indicateur de qualité du portefeuille</h3>';
    echo '<table>';
    echo '<tr><th style="width:10%">Acc</th><th style="width:15%">Source</th><th>Indicateur</th><th style="width:15%" class="text-right">Valeur</th></tr>';
    foreach ($tableau_qualite_excel as $q) {
        echo "<tr>
            <td>{$q['code']}</td>
            <td>{$q['source']}</td>
            <td>{$q['indicateur']}</td>
            <td class='text-right'>{$q['valeur']}</td>
        </tr>";
    }
    echo '</table>';

    echo '<h3>I-2 - Indicateurs d\'activités</h3>';
    echo '<table>';
    echo '<tr><th style="width:10%">Acc</th><th style="width:15%">Source</th><th>Indicateur</th><th style="width:15%" class="text-right">Valeur</th></tr>';
    foreach ($tableau_activites_excel as $a) {
        echo "<tr>
            <td>{$a['code']}</td>
            <td>{$a['source']}</td>
            <td>{$a['indicateur']}</td>
            <td class='text-right'>{$a['valeur']}</td>
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
    <title>IF 11 - Indicateurs financiers (DSFD)</title>
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
            <h1><i class="fas fa-chart-line"></i> IF 11 - INDICATEURS FINANCIERS</h1>
            <div class="subtitle">République de Côte d'Ivoire / DGTCP / DSFD</div>
            <div class="badge">Partie 1 : Qualité du portefeuille et indicateurs d'activités</div>
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

    <!-- I-1 - Qualité du portefeuille (Tableau Excel Style) -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-simple"></i> I-1 – Indicateur de qualité du portefeuille</div>
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
                    <?php foreach($tableau_qualite_excel as $q): ?>
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

    <!-- I-2 - Indicateurs d'activités (Tableau Excel Style) -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-simple"></i> I-2 – Indicateurs d'activités</div>
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
                    <?php foreach($tableau_activites_excel as $a): ?>
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
    let url = 'IF11.php?exercice=' + document.getElementById('exerciceSelect').value + '&type_periode=' + document.getElementById('typePeriodeSelect').value;
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