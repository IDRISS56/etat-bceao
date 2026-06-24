<?php
// DIMF_2016.php - État d'affectation du résultat
// Utilise la table z_bceao_affectation_resultat (existe déjà)

session_start();

// ============================================================
// CONFIGURATION BDD
// ============================================================
require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';


// ============================================================
// PARAMÈTRES (POST > GET)
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
$lib_periode = match($type_periode) {
    'mensuel'   => 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice,
    'trimestre' => $trimestre . 'e Trim. ' . $exercice,
    'semestre'  => $semestre . 'er Sem. ' . $exercice,
    default     => 'Année ' . $exercice,
};

// ============================================================
// TRAITEMENT DU FORMULAIRE (SAUVEGARDE EN POST)
// ============================================================
$message = '';
$message_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'save') {
    try {
        // Supprimer les anciennes données pour l'exercice
        $stmtDel = $pdo->prepare("DELETE FROM z_bceao_affectation_resultat WHERE exercice = :exercice");
        $stmtDel->execute([':exercice' => $exercice]);

        $stmtIns = $pdo->prepare("
            INSERT INTO z_bceao_affectation_resultat 
            (exercice, code, libelle, proposition, repartition_effective, statut)
            VALUES (:exercice, :code, :libelle, :proposition, :repartition_effective, 'actif')
        ");

        // Définition des lignes avec leurs codes et libellés
        $lignes = [
            ['code' => 'DIMF_2016_1_1', 'libelle' => 'DÉTERMINATION DU RÉSULTAT À AFFECTER', 'type' => 'titre'],
            ['code' => 'L80',           'libelle' => 'Résultat de l\'exercice (+/-)', 'type' => 'montant'],
            ['code' => 'L70',           'libelle' => 'Report à nouveau (+/-)', 'type' => 'montant'],
            ['code' => '770',           'libelle' => 'RÉSULTAT À AFFECTER', 'type' => 'resultat'],
            ['code' => 'DIMF_2016_1_5', 'libelle' => 'AFFECTATION DU RÉSULTAT BÉNÉFICIAIRE', 'type' => 'titre'],
            ['code' => '772',           'libelle' => 'Réserve générale', 'type' => 'montant'],
            ['code' => '773',           'libelle' => 'Réserve facultatives', 'type' => 'montant'],
            ['code' => '774',           'libelle' => 'Autres réserves', 'type' => 'montant'],
            ['code' => '776',           'libelle' => 'Report à nouveau bénéficiaire', 'type' => 'montant'],
            ['code' => '777',           'libelle' => 'Autres affectations', 'type' => 'montant'],
            ['code' => 'DIMF_2016_1_11','libelle' => 'AFFECTATION DU RÉSULTAT DÉFICITAIRE', 'type' => 'titre'],
            ['code' => '776b',          'libelle' => '*Report à nouveau déficitaire', 'type' => 'montant'],
            ['code' => '778',           'libelle' => '*Prélèvements sur les réserves', 'type' => 'montant'],
            ['code' => '779',           'libelle' => 'Autres', 'type' => 'montant'],
        ];

        // Récupération des montants postés
        foreach ($lignes as $l) {
            $code = $l['code'];
            $proposition = (float)($_POST['proposition_' . $code] ?? 0);
            $repartition = (float)($_POST['repartition_' . $code] ?? 0);
            // Si la ligne est un titre, on met 0
            if ($l['type'] == 'titre' || $l['type'] == 'resultat') {
                // On peut stocker 0 pour les titres
                $proposition = 0;
                $repartition = 0;
            }
            // Exception : 770 est le résultat à affecter, calculé automatiquement, on le stocke aussi
            if ($code == '770') {
                $proposition = (float)($_POST['resultat_a_affecter'] ?? 0);
                $repartition = $proposition; // par défaut même valeur
            }
            $stmtIns->execute([
                ':exercice' => $exercice,
                ':code' => $code,
                ':libelle' => $l['libelle'],
                ':proposition' => $proposition,
                ':repartition_effective' => $repartition
            ]);
        }

        $message = "Affectation du résultat enregistrée avec succès !";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }
    $url = "DIMF_2016.php?exercice=$exercice&type_periode=$type_periode" .
           ($type_periode=='mensuel' ? "&mois=$mois" : ($type_periode=='trimestre' ? "&trimestre=$trimestre" : ($type_periode=='semestre' ? "&semestre=$semestre" : ""))) .
           "&msg=" . urlencode($message) . "&msg_type=$message_type";
    header("Location: $url");
    exit;
}
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $message_type = $_GET['msg_type'] ?? 'success';
}

// ============================================================
// RÉCUPÉRATION DES DONNÉES COMPTABLES
// ============================================================
$resultat_exercice = 0;
try {
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN pc.classe_compte = '7' THEN e.montant_credit - e.montant_debit ELSE 0 END), 0) as produits,
            COALESCE(SUM(CASE WHEN pc.classe_compte = '6' THEN e.montant_debit - e.montant_credit ELSE 0 END), 0) as charges
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte IN ('6', '7')
          AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $r = $stmt->fetch();
    $resultat_exercice = (float)$r['produits'] - (float)$r['charges'];
} catch (PDOException $e) { $resultat_exercice = 0; }

$report_anterieur = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '11%'
          AND e.date_ecriture < :debut
    ");
    $stmt->execute([':debut' => $date_debut_exercice]);
    $report_anterieur = (float)$stmt->fetch()['solde'];
} catch (PDOException $e) { $report_anterieur = 0; }

$resultat_a_affecter = $resultat_exercice + $report_anterieur;

// Récupération des données existantes pour l'affichage
$affectations_data = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM z_bceao_affectation_resultat WHERE exercice = :exercice AND statut = 'actif'");
    $stmt->execute([':exercice' => $exercice]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $row) {
        $affectations_data[$row['code']] = [
            'proposition' => (float)$row['proposition'],
            'repartition_effective' => (float)$row['repartition_effective']
        ];
    }
} catch (PDOException $e) { $affectations_data = []; }

// Valeurs par défaut pour le formulaire
$default_values = [
    'resultat' => $resultat_exercice,
    'report_anterieur' => $report_anterieur,
    'reserve_generale' => $affectations_data['772']['proposition'] ?? 0,
    'reserve_facultative' => $affectations_data['773']['proposition'] ?? 0,
    'autres_reserves' => $affectations_data['774']['proposition'] ?? 0,
    'report_nouveau' => $affectations_data['776']['proposition'] ?? 0,
    'autres_affectations' => $affectations_data['777']['proposition'] ?? 0,
    'report_deficitaire' => $affectations_data['776b']['proposition'] ?? 0,
    'prelevement_reserves' => $affectations_data['778']['proposition'] ?? 0,
    'autres' => $affectations_data['779']['proposition'] ?? 0
];

// Calcul des totaux
$total_proposition = 0;
foreach (['reserve_generale','reserve_facultative','autres_reserves','report_nouveau','autres_affectations','report_deficitaire','prelevement_reserves','autres'] as $key) {
    $total_proposition += $default_values[$key];
}
$total_repartition_effective = $total_proposition; // par défaut, on met la même chose

// Vérification équilibre (pour l'affichage de l'état)
$equilibre_ok = (abs($resultat_a_affecter - $total_proposition) < 1);

// Réserve générale minimale
$min_reserve_requis = ($resultat_a_affecter > 0) ? $resultat_a_affecter * 0.15 : 0;

function format_montant($val) {
    return number_format((float)$val, 0, ',', ' ') . ' F';
}

// ============================================================
// CLASSE PDF
// ============================================================
if ($format === 'pdf') {
    class PDF_DIMF extends FPDF {
        public $codeDimf = 'DIMF_2016';
        public $titreDimf = "Etat d'affectation du résultat";
        public $nomSfd = 'SFD';
        public $periode = '';
        public $exercice = '';
        static function u($str) {
            return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
        }
        function Header() {
            $this->SetFillColor(156, 163, 175);
            $this->Rect(0, 0, $this->GetPageWidth(), 28, 'F');
            $this->SetFont('Arial', '', 7);
            $this->SetTextColor(255, 255, 255);
            $this->SetXY(8, 3);
            $this->Cell(0, 4, self::u('République de Côte d\'Ivoire  •  Ministère de l\'Economie et des Finances  -  DGTCP / DSFD'), 0, 1, 'L');
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
        function TableHeader() {
            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(248, 250, 252);
            $this->SetTextColor(30, 41, 59);
            $this->SetDrawColor(226, 232, 240);
            $this->Cell(30, 6, self::u('CODE'), 1, 0, 'L', true);
            $this->Cell(70, 6, self::u('LIBELLÉS'), 1, 0, 'L', true);
            $this->Cell(45, 6, self::u('Proposition de répartition'), 1, 0, 'R', true);
            $this->Cell(45, 6, self::u('Répartition effective'), 1, 1, 'R', true);
        }
        function TableRow($code, $libelle, $proposition, $repartition, $style = '') {
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
            $this->Cell(30, 6, self::u($code), 1, 0, 'L', $fill);
            $this->Cell(70, 6, self::u($libelle), 1, 0, 'L', $fill);
            $this->Cell(45, 6, self::u($proposition), 1, 0, 'R', $fill);
            $this->Cell(45, 6, self::u($repartition), 1, 1, 'R', $fill);
        }
        static function montant($val) {
            return number_format((float)$val, 0, ',', ' ') . ' F';
        }
    }

    if (ob_get_length()) ob_end_clean();

    $pdf = new PDF_DIMF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->nomSfd = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode = $lib_periode;
    $pdf->exercice = $exercice;
    $pdf->SetMargins(15, 35, 15);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, PDF_DIMF::u('ÉTATS D\'AFFECTATION DU RÉSULTAT'), 0, 1, 'C');
    $pdf->Ln(2);

    $pdf->TableHeader();

    // Lignes (codes et libellés fixes)
    $lignes_pdf = [
        ['code' => 'DIMF_2016_1_1', 'libelle' => 'DÉTERMINATION DU RÉSULTAT À AFFECTER', 'type' => 'subtotal'],
        ['code' => 'L80', 'libelle' => 'Résultat de l\'exercice (+/-)', 'val' => $resultat_exercice],
        ['code' => 'L70', 'libelle' => 'Report à nouveau (+/-)', 'val' => $report_anterieur],
        ['code' => '770', 'libelle' => 'RÉSULTAT À AFFECTER', 'val' => $resultat_a_affecter, 'type' => 'total'],
        ['code' => 'DIMF_2016_1_5', 'libelle' => 'AFFECTATION DU RÉSULTAT BÉNÉFICIAIRE', 'type' => 'subtotal'],
        ['code' => '772', 'libelle' => 'Réserve générale', 'val' => $default_values['reserve_generale']],
        ['code' => '773', 'libelle' => 'Réserve facultatives', 'val' => $default_values['reserve_facultative']],
        ['code' => '774', 'libelle' => 'Autres réserves', 'val' => $default_values['autres_reserves']],
        ['code' => '776', 'libelle' => 'Report à nouveau bénéficiaire', 'val' => $default_values['report_nouveau']],
        ['code' => '777', 'libelle' => 'Autres affectations', 'val' => $default_values['autres_affectations']],
        ['code' => 'DIMF_2016_1_11', 'libelle' => 'AFFECTATION DU RÉSULTAT DÉFICITAIRE', 'type' => 'subtotal'],
        ['code' => '776b', 'libelle' => '*Report à nouveau déficitaire', 'val' => $default_values['report_deficitaire']],
        ['code' => '778', 'libelle' => '*Prélèvements sur les réserves', 'val' => $default_values['prelevement_reserves']],
        ['code' => '779', 'libelle' => 'Autres', 'val' => $default_values['autres']],
    ];

    foreach ($lignes_pdf as $l) {
        $style = '';
        if (isset($l['type']) && $l['type'] == 'subtotal') $style = 'subtotal';
        if (isset($l['type']) && $l['type'] == 'total') $style = 'total';
        $proposition = isset($l['val']) ? PDF_DIMF::montant($l['val']) : '-';
        $repartition = isset($l['val']) ? PDF_DIMF::montant($l['val']) : '-'; // par défaut, on met pareil
        // Pour les titres, on met des tirets
        if (isset($l['type']) && ($l['type'] == 'subtotal' || $l['type'] == 'total')) {
            $proposition = '';
            $repartition = '';
        }
        $pdf->TableRow($l['code'], $l['libelle'], $proposition, $repartition, $style);
    }

    $pdf->Output('I', 'DIMF_2016_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}

// ============================================================
// EXPORT EXCEL
// ============================================================
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="DIMF_2016_' . $exercice . '.xls"');
    echo '<html><head><meta charset="UTF-8"><style> body { font-family: Arial; } td { text-align: right; } .text-left { text-align: left; } </style></head><body>';
    echo '<h2>DIMF_2016 - État d\'affectation du résultat</h2>';
    echo '<p>Période : ' . htmlspecialchars($lib_periode) . '</p>';
    echo '<table border="1"><thead>';
    echo '<tr><th>CODE</th><th>LIBELLÉS</th><th>Proposition de répartition</th><th>Répartition effective</th></tr>';
    echo '</thead><tbody>';
    // Mêmes lignes que pour le PDF
    $lignes_excel = [
        ['code' => 'DIMF_2016_1_1', 'libelle' => 'DÉTERMINATION DU RÉSULTAT À AFFECTER', 'val' => null, 'type' => 'titre'],
        ['code' => 'L80', 'libelle' => 'Résultat de l\'exercice (+/-)', 'val' => $resultat_exercice],
        ['code' => 'L70', 'libelle' => 'Report à nouveau (+/-)', 'val' => $report_anterieur],
        ['code' => '770', 'libelle' => 'RÉSULTAT À AFFECTER', 'val' => $resultat_a_affecter, 'type' => 'total'],
        ['code' => 'DIMF_2016_1_5', 'libelle' => 'AFFECTATION DU RÉSULTAT BÉNÉFICIAIRE', 'val' => null, 'type' => 'titre'],
        ['code' => '772', 'libelle' => 'Réserve générale', 'val' => $default_values['reserve_generale']],
        ['code' => '773', 'libelle' => 'Réserve facultatives', 'val' => $default_values['reserve_facultative']],
        ['code' => '774', 'libelle' => 'Autres réserves', 'val' => $default_values['autres_reserves']],
        ['code' => '776', 'libelle' => 'Report à nouveau bénéficiaire', 'val' => $default_values['report_nouveau']],
        ['code' => '777', 'libelle' => 'Autres affectations', 'val' => $default_values['autres_affectations']],
        ['code' => 'DIMF_2016_1_11', 'libelle' => 'AFFECTATION DU RÉSULTAT DÉFICITAIRE', 'val' => null, 'type' => 'titre'],
        ['code' => '776b', 'libelle' => '*Report à nouveau déficitaire', 'val' => $default_values['report_deficitaire']],
        ['code' => '778', 'libelle' => '*Prélèvements sur les réserves', 'val' => $default_values['prelevement_reserves']],
        ['code' => '779', 'libelle' => 'Autres', 'val' => $default_values['autres']],
    ];
    foreach ($lignes_excel as $l) {
        $style = (isset($l['type']) && $l['type'] == 'total') ? 'background:#e8f5e9;' : '';
        if (isset($l['type']) && ($l['type'] == 'titre' || $l['type'] == 'total')) {
            $prop = '';
            $repart = '';
        } else {
            $prop = number_format($l['val'],0,',',' ');
            $repart = $prop;
        }
        echo '<tr style="' . $style . '"><td>' . $l['code'] . '</td><td class="text-left">' . htmlspecialchars($l['libelle']) . '</td>';
        echo '<td>' . $prop . '</td><td>' . $repart . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '</body></html>';
    exit;
}

// ============================================================
// AFFICHAGE HTML
// ============================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2016 - Affectation du résultat</title>
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
        .btn-excel, .btn-pdf { display:inline-flex; align-items:center; gap:8px; padding:8px 20px; border-radius:40px; font-weight:500; border:none; cursor:pointer; text-decoration:none; }
        .btn-excel { background:#10b981; color:white; }
        .btn-excel:hover { background:#059669; }
        .btn-pdf { background:#ef4444; color:white; }
        .btn-pdf:hover { background:#dc2626; }
        .btn-save { background:#3b82f6; color:white; border:none; border-radius:40px; padding:8px 24px; font-weight:500; cursor:pointer; }
        .btn-save:hover { background:#2563eb; }
        .card { background:white; border-radius:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:24px; overflow:hidden; }
        .card-header { display:flex; align-items:center; gap:10px; padding:16px 24px; background:#f8fafc; border-bottom:1px solid #eef2f6; font-weight:600; color:#1e40af; }
        .card-body { padding:20px 24px; }
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
        .total-row { background:#f0fdf4; font-weight:700; }
        .form-group { margin-bottom:15px; }
        .form-group label { display:block; font-weight:600; margin-bottom:5px; color:#555; font-size:0.8rem; }
        .form-group input { width:100%; padding:10px 12px; border:1px solid #ddd; border-radius:8px; font-size:0.9rem; text-align:right; font-family:monospace; }
        .form-group input:focus { outline:none; border-color:#3b82f6; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px; border-radius:16px; display:flex; align-items:center; gap:14px; margin-bottom:20px; }
        .alert { padding:14px 20px; border-radius:16px; margin-bottom:20px; display:flex; align-items:center; gap:12px; }
        .alert-success { background:#ecfdf5; color:#065f46; border-left:4px solid #10b981; }
        .alert-error { background:#fef2f2; color:#991b1b; border-left:4px solid #ef4444; }
        .calculated-value { background:#e8f5e9; font-weight:bold; }
        .footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; padding:16px; }
        @media (max-width:768px) { body { padding:12px; } .filters-row { flex-direction:column; } .btn-group { flex-wrap:wrap; } }
        @media print { .btn-group, .footer, .filters-row, .btn-save, #filtersCard, .alert { display:none !important; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-chart-pie"></i> DIMF_2016 - AFFECTATION DU RÉSULTAT</h1>
            <div class="subtitle">République de Côte d'Ivoire / DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO • Affectation des résultats</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" id="btnPdf"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <form method="post" class="card" id="filtersForm">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="card-body">
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
                        for ($m=1;$m<=12;$m++) echo '<option value="'.$m.'" '.($m==$mois?'selected':'').'>'.str_pad($m,2,'0',STR_PAD_LEFT).' - '.date('F',mktime(0,0,0,$m,1)).'</option>';
                        echo '</select>';
                    } elseif ($type_periode == 'trimestre') {
                        echo '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
                        for ($t=1;$t<=4;$t++) echo '<option value="'.$t.'" '.($t==$trimestre?'selected':'').'>'.$t.($t==1?'er':'ème').' Trimestre</option>';
                        echo '</select>';
                    } elseif ($type_periode == 'semestre') {
                        echo '<label>Semestre</label><select name="semestre" id="semestreSelect">';
                        for ($s=1;$s<=2;$s++) echo '<option value="'.$s.'" '.($s==$semestre?'selected':'').'>'.$s.($s==1?'er':'e').' semestre</option>';
                        echo '</select>';
                    } else {
                        echo '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">';
                    }
                    ?>
                </div>
                <div class="filter-item">
                    <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Appliquer</button>
                </div>
            </div>
            <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;">
                <i class="fas fa-info-circle"></i> Période : <?= $lib_periode ?> (arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
            </div>
        </div>
    </form>

    <?php if($message): ?>
        <div class="alert alert-<?= $message_type ?>">
            <i class="fas fa-<?= $message_type=='success'?'check-circle':'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Note :</strong> Conformément à la réglementation, les SFD doivent affecter au minimum 15% du bénéfice à la réserve générale.
                </div>
            </div>
        </div>
    </div>

    <form method="post" action="">
        <input type="hidden" name="action" value="save">

        <div class="card">
            <div class="card-header"><i class="fas fa-chart-line"></i> ÉTAT D'AFFECTATION DU RÉSULTAT</div>
            <div class="card-body">
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr><th>CODE</th><th>LIBELLÉS</th><th class="text-right">Proposition de répartition</th><th class="text-right">Répartition effective</th></tr>
                        </thead>
                        <tbody>
                            <!-- Ligne DIMF_2016_1_1 (titre) -->
                            <tr class="subtotal-row">
                                <td>DIMF_2016_1_1</td>
                                <td><strong>DÉTERMINATION DU RÉSULTAT À AFFECTER</strong></td>
                                <td class="text-right">-</td>
                                <td class="text-right">-</td>
                            </tr>
                            <!-- L80 : résultat -->
                            <tr>
                                <td>L80</td>
                                <td>Résultat de l'exercice (+/-)</td>
                                <td class="text-right"><input type="number" name="proposition_L80" step="1" class="form-control form-control-sm text-right" value="<?= number_format($resultat_exercice,0,'','') ?>" readonly style="background:#f3f4f6;"></td>
                                <td class="text-right"><input type="number" name="repartition_L80" step="1" class="form-control form-control-sm text-right" value="<?= number_format($resultat_exercice,0,'','') ?>" readonly style="background:#f3f4f6;"></td>
                            </tr>
                            <!-- L70 : report antérieur -->
                            <tr>
                                <td>L70</td>
                                <td>Report à nouveau (+/-)</td>
                                <td class="text-right"><input type="number" name="proposition_L70" step="1" class="form-control form-control-sm text-right" value="<?= number_format($report_anterieur,0,'','') ?>" readonly style="background:#f3f4f6;"></td>
                                <td class="text-right"><input type="number" name="repartition_L70" step="1" class="form-control form-control-sm text-right" value="<?= number_format($report_anterieur,0,'','') ?>" readonly style="background:#f3f4f6;"></td>
                            </tr>
                            <!-- 770 : résultat à affecter -->
                            <tr class="total-row">
                                <td>770</td>
                                <td><strong>RÉSULTAT À AFFECTER</strong></td>
                                <td class="text-right"><input type="text" id="resultat_a_affecter_display" readonly style="background:#f0fdf4; font-weight:bold; border:none; text-align:right; width:100%;" value="<?= number_format($resultat_a_affecter,0,',',' ') ?>"></td>
                                <td class="text-right"><input type="text" id="resultat_a_affecter_repartition" readonly style="background:#f0fdf4; font-weight:bold; border:none; text-align:right; width:100%;" value="<?= number_format($resultat_a_affecter,0,',',' ') ?>"></td>
                                <input type="hidden" name="resultat_a_affecter" value="<?= $resultat_a_affecter ?>">
                            </tr>
                            <!-- DIMF_2016_1_5 -->
                            <tr class="subtotal-row">
                                <td>DIMF_2016_1_5</td>
                                <td><strong>AFFECTATION DU RÉSULTAT BÉNÉFICIAIRE</strong></td>
                                <td class="text-right">-</td>
                                <td class="text-right">-</td>
                            </tr>
                            <!-- 772 -->
                            <tr>
                                <td>772</td>
                                <td>Réserve générale</td>
                                <td class="text-right"><input type="number" name="proposition_772" step="1" class="form-control form-control-sm text-right" id="reserve_generale" value="<?= number_format($default_values['reserve_generale'],0,'','') ?>"></td>
                                <td class="text-right"><input type="number" name="repartition_772" step="1" class="form-control form-control-sm text-right" id="reserve_generale_rep" value="<?= number_format($default_values['reserve_generale'],0,'','') ?>"></td>
                            </tr>
                            <!-- 773 -->
                            <tr>
                                <td>773</td>
                                <td>Réserve facultatives</td>
                                <td class="text-right"><input type="number" name="proposition_773" step="1" class="form-control form-control-sm text-right" id="reserve_facultative" value="<?= number_format($default_values['reserve_facultative'],0,'','') ?>"></td>
                                <td class="text-right"><input type="number" name="repartition_773" step="1" class="form-control form-control-sm text-right" id="reserve_facultative_rep" value="<?= number_format($default_values['reserve_facultative'],0,'','') ?>"></td>
                            </tr>
                            <!-- 774 -->
                            <tr>
                                <td>774</td>
                                <td>Autres réserves</td>
                                <td class="text-right"><input type="number" name="proposition_774" step="1" class="form-control form-control-sm text-right" id="autres_reserves" value="<?= number_format($default_values['autres_reserves'],0,'','') ?>"></td>
                                <td class="text-right"><input type="number" name="repartition_774" step="1" class="form-control form-control-sm text-right" id="autres_reserves_rep" value="<?= number_format($default_values['autres_reserves'],0,'','') ?>"></td>
                            </tr>
                            <!-- 776 (bénéficiaire) -->
                            <tr>
                                <td>776</td>
                                <td>Report à nouveau bénéficiaire</td>
                                <td class="text-right"><input type="number" name="proposition_776" step="1" class="form-control form-control-sm text-right" id="report_nouveau" value="<?= number_format($default_values['report_nouveau'],0,'','') ?>"></td>
                                <td class="text-right"><input type="number" name="repartition_776" step="1" class="form-control form-control-sm text-right" id="report_nouveau_rep" value="<?= number_format($default_values['report_nouveau'],0,'','') ?>"></td>
                            </tr>
                            <!-- 777 -->
                            <tr>
                                <td>777</td>
                                <td>Autres affectations</td>
                                <td class="text-right"><input type="number" name="proposition_777" step="1" class="form-control form-control-sm text-right" id="autres_affectations" value="<?= number_format($default_values['autres_affectations'],0,'','') ?>"></td>
                                <td class="text-right"><input type="number" name="repartition_777" step="1" class="form-control form-control-sm text-right" id="autres_affectations_rep" value="<?= number_format($default_values['autres_affectations'],0,'','') ?>"></td>
                            </tr>
                            <!-- DIMF_2016_1_11 -->
                            <tr class="subtotal-row">
                                <td>DIMF_2016_1_11</td>
                                <td><strong>AFFECTATION DU RÉSULTAT DÉFICITAIRE</strong></td>
                                <td class="text-right">-</td>
                                <td class="text-right">-</td>
                            </tr>
                            <!-- 776b (déficitaire) -->
                            <tr>
                                <td>776b</td>
                                <td>*Report à nouveau déficitaire</td>
                                <td class="text-right"><input type="number" name="proposition_776b" step="1" class="form-control form-control-sm text-right" id="report_deficitaire" value="<?= number_format($default_values['report_deficitaire'],0,'','') ?>"></td>
                                <td class="text-right"><input type="number" name="repartition_776b" step="1" class="form-control form-control-sm text-right" id="report_deficitaire_rep" value="<?= number_format($default_values['report_deficitaire'],0,'','') ?>"></td>
                            </tr>
                            <!-- 778 -->
                            <tr>
                                <td>778</td>
                                <td>*Prélèvements sur les réserves</td>
                                <td class="text-right"><input type="number" name="proposition_778" step="1" class="form-control form-control-sm text-right" id="prelevement_reserves" value="<?= number_format($default_values['prelevement_reserves'],0,'','') ?>"></td>
                                <td class="text-right"><input type="number" name="repartition_778" step="1" class="form-control form-control-sm text-right" id="prelevement_reserves_rep" value="<?= number_format($default_values['prelevement_reserves'],0,'','') ?>"></td>
                            </tr>
                            <!-- 779 -->
                            <tr>
                                <td>779</td>
                                <td>Autres</td>
                                <td class="text-right"><input type="number" name="proposition_779" step="1" class="form-control form-control-sm text-right" id="autres" value="<?= number_format($default_values['autres'],0,'','') ?>"></td>
                                <td class="text-right"><input type="number" name="repartition_779" step="1" class="form-control form-control-sm text-right" id="autres_rep" value="<?= number_format($default_values['autres'],0,'','') ?>"></td>
                            </tr>
                            <!-- Ligne TOTAL (affichage) -->
                            <tr class="total-row">
                                <td></td>
                                <td><strong>TOTAL AFFECTATIONS</strong></td>
                                <td class="text-right" id="total_proposition_display"><?= number_format($total_proposition,0,',',' ') ?></td>
                                <td class="text-right" id="total_repartition_display"><?= number_format($total_repartition_effective,0,',',' ') ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top:20px; text-align:center;">
                    <button type="submit" class="btn-save"><i class="fas fa-save"></i> Enregistrer l'affectation</button>
                </div>
            </div>
        </div>
    </form>

    <div class="footer">
        <i class="fas fa-calendar-alt"></i> Document généré le <?= date('d/m/Y à H:i:s') ?> – Données issues de la table <code>z_bceao_affectation_resultat</code>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function updateDynamicSelect() {
        const type = document.getElementById('typePeriodeSelect').value;
        const container = document.getElementById('dynamicSelectContainer');
        const currentMois = <?= $mois ?>;
        const currentTrimestre = <?= $trimestre ?>;
        const currentSemestre = <?= json_encode($semestre) ?>;
        let html = '';
        if (type === 'mensuel') {
            html = '<label>Mois</label><select name="mois" id="moisSelect">';
            for (let m=1;m<=12;m++) { const s=(m===currentMois)?'selected':''; const n=new Date(2000,m-1,1).toLocaleString('fr',{month:'long'}); html+=`<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`; }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
            for (let t=1;t<=4;t++) { const s=(t===currentTrimestre)?'selected':''; html+=`<option value="${t}" ${s}>${t}${t===1?'er':'ème'} Trimestre</option>`; }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select name="semestre" id="semestreSelect">';
            for (let s=1;s<=2;s++) { const sel=(s===currentSemestre)?'selected':''; html+=`<option value="${s}" ${sel}>${s}${s===1?'er':'e'} semestre</option>`; }
            html += '</select>';
        } else {
            html = '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">';
        }
        container.innerHTML = html;
    }

    function exporterPDF() {
        const form = document.getElementById('filtersForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'format';
        input.value = 'pdf';
        form.appendChild(input);
        form.target = '_blank';
        form.submit();
        form.target = '';
        form.removeChild(input);
    }

    function exporterExcel() {
        const form = document.getElementById('filtersForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'format';
        input.value = 'excel';
        form.appendChild(input);
        form.submit();
        form.removeChild(input);
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
        document.getElementById('btnPdf').addEventListener('click', exporterPDF);
    });
</script>
</body>
</html>