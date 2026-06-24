<?php
// DIMF_2012.php - État de l'encours des 10 débiteurs les plus importants
// Version conforme au fichier Excel DIMF_2012.xlsx
// Utilise les tables existantes, ne crée aucune nouvelle table

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
// PARAMÈTRES (POST / GET)
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

switch ($type_periode) {
    case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
    case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
    case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
    default:          $lib_periode = 'Année ' . $exercice;
}

// ============================================================
// RÉCUPÉRATION DU TOP 10 DES DÉBITEURS (conforme au fichier Excel)
// ============================================================

$top_debiteurs = [];
$total_encours = 0;
$total_duree_initial = 0;
$total_duree_restante = 0;

try {
    // 1. Récupérer les 10 plus gros débiteurs avec leur encours total, durée initiale totale et durée restante moyenne
    $stmt = $pdo->prepare("
        SELECT 
            c.client_id,
            c.matricule,
            c.numero_piece,
            CONCAT(COALESCE(c.nom, ''), ' ', COALESCE(c.prenom, '')) as nom_complet,
            c.categorie,
            c.secteur_id,
            SUM(encours_dossier.encours_restant) as encours_total,
            SUM(encours_dossier.duree) as duree_initiale_totale,
            AVG(encours_dossier.duree_restante) as duree_restante_moyenne,
            COUNT(encours_dossier.dossier_id) as nb_credits
        FROM (
            SELECT 
                d.compte_id,
                d.dossier_id,
                d.duree,
                COALESCE(d.montant - COALESCE(e2.rembourse, 0), d.montant) as encours_restant,
                (SELECT COALESCE(MAX(date_echeance), DATE_ADD(CURDATE(), INTERVAL d.duree MONTH))
                 FROM echeances 
                 WHERE dossier_id = d.dossier_id AND statut = 'attente') as date_derniere_echeance
            FROM dossiers d
            LEFT JOIN (
                SELECT dossier_id, SUM(montant) as rembourse
                FROM echeances
                WHERE statut = 'payee'
                GROUP BY dossier_id
            ) e2 ON d.dossier_id = e2.dossier_id
            WHERE d.statut IN ('actif', 'approuve')
              AND d.date_octroi <= :date_fin
        ) AS encours_dossier
        INNER JOIN comptes cpt ON encours_dossier.compte_id = cpt.compte_id
        INNER JOIN clients c ON cpt.client_id = c.client_id
        GROUP BY c.client_id, c.matricule, c.numero_piece, c.nom, c.prenom, c.categorie, c.secteur_id
        HAVING encours_total > 0
        ORDER BY encours_total DESC
        LIMIT 10
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $top_debiteurs = $stmt->fetchAll();

    // 2. Calcul des durées restantes pour chaque débiteur (en mois)
    foreach ($top_debiteurs as &$d) {
        // Pour chaque débiteur, on récupère la durée restante de chaque prêt
        try {
            $stmt2 = $pdo->prepare("
                SELECT 
                    d.dossier_id,
                    d.duree,
                    COALESCE(d.montant - COALESCE(e.rembourse, 0), d.montant) as encours_restant,
                    COALESCE(
                        (SELECT MAX(date_echeance) 
                         FROM echeances 
                         WHERE dossier_id = d.dossier_id AND statut = 'attente'),
                        DATE_ADD(CURDATE(), INTERVAL d.duree MONTH)
                    ) as date_derniere_echeance
                FROM dossiers d
                LEFT JOIN (
                    SELECT dossier_id, SUM(montant) as rembourse
                    FROM echeances
                    WHERE statut = 'payee'
                    GROUP BY dossier_id
                ) e ON d.dossier_id = e.dossier_id
                INNER JOIN comptes cpt ON d.compte_id = cpt.compte_id
                WHERE cpt.client_id = :client_id
                  AND d.statut IN ('actif', 'approuve')
            ");
            $stmt2->execute([':client_id' => $d['client_id']]);
            $prets = $stmt2->fetchAll();

            $duree_restante_ponderee = 0;
            $encours_total = $d['encours_total'];
            foreach ($prets as $pret) {
                // Calcul de la durée restante : date_derniere_echeance - aujourd'hui (en mois)
                $date_echeance = new DateTime($pret['date_derniere_echeance']);
                $aujourdhui = new DateTime($date_fin_periode);
                $diff_mois = $aujourdhui->diff($date_echeance)->days / 30; // approximation
                $duree_restante = max(0, round($diff_mois));
                // Pondération par l'encours du prêt
                $poids = ($encours_total > 0) ? ($pret['encours_restant'] / $encours_total) : 0;
                $duree_restante_ponderee += $duree_restante * $poids;
            }
            $d['duree_restante_moyenne'] = round($duree_restante_ponderee);
        } catch (PDOException $e) {
            $d['duree_restante_moyenne'] = 0;
        }

        $total_encours += $d['encours_total'];
        $total_duree_initial += $d['duree_initiale_totale'];
        $total_duree_restante += $d['duree_restante_moyenne'] * $d['nb_credits'];
    }
    unset($d);

} catch (PDOException $e) {
    $top_debiteurs = [];
}

// Si moins de 10 débiteurs, on complète avec des lignes vides
while (count($top_debiteurs) < 10) {
    $top_debiteurs[] = [
        'client_id' => null,
        'matricule' => '',
        'nom_complet' => '',
        'encours_total' => 0,
        'duree_initiale_totale' => 0,
        'duree_restante_moyenne' => 0,
        'nb_credits' => 0
    ];
}

// ============================================================
// FONDS PROPRES (pour les indicateurs de concentration)
// ============================================================
$fonds_propres = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '1' AND e.date_ecriture <= :date_fin AND e.statut = 'VALIDÉE'
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $fonds_propres = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $fonds_propres = 0; }

// Portefeuille total
$portefeuille_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e 
        ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve') AND d.date_octroi <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $portefeuille_total = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $portefeuille_total = 0; }

$part_top10 = ($portefeuille_total > 0) ? ($total_encours / $portefeuille_total) * 100 : 0;
$ratio_concentration = ($fonds_propres > 0 && !empty($top_debiteurs) && isset($top_debiteurs[0]['encours_total'])) 
    ? ($top_debiteurs[0]['encours_total'] / $fonds_propres) * 100 
    : 0;

// ============================================================
// GÉNÉRATION PDF (format=pdf)
// ============================================================
if ($format === 'pdf') {
    $pdf = new PDF_DIMF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->codeDimf = 'DIMF_2012';
    $pdf->titreDimf = 'État de l\'encours des 10 débiteurs les plus importants';
    $pdf->nomSfd = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode = $lib_periode;
    $pdf->exercice = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $cols = [
        ['label' => 'CODE', 'w' => 22],
        ['label' => 'No DE COMPTE', 'w' => 30],
        ['label' => 'NOMS ET PRÉNOMS', 'w' => 60],
        ['label' => 'DURÉE INITIALE (mois)', 'w' => 32, 'align' => 'R'],
        ['label' => 'DURÉE RESTANTE (mois)', 'w' => 32, 'align' => 'R'],
        ['label' => 'MONTANTS NETS (FCFA)', 'w' => 45, 'align' => 'R']
    ];
    $pdf->SectionTitle('Top 10 des débiteurs');
    $pdf->TableHeader($cols);

    $i = 1;
    foreach ($top_debiteurs as $d) {
        $code = 'DIMF_2012_' . $i;
        $montant = isset($d['encours_total']) ? $d['encours_total'] : 0;
        $duree_init = isset($d['duree_initiale_totale']) ? $d['duree_initiale_totale'] : 0;
        $duree_rest = isset($d['duree_restante_moyenne']) ? $d['duree_restante_moyenne'] : 0;
        $nom = isset($d['nom_complet']) ? $d['nom_complet'] : '';
        $matricule = isset($d['matricule']) ? $d['matricule'] : '';
        // Si pas de matricule, on utilise le numéro de pièce
        $num_compte = $matricule ?: (isset($d['numero_piece']) ? $d['numero_piece'] : '');
        $pdf->TableRow($cols, [
            $code,
            $num_compte,
            PDF_DIMF::u($nom ?: '-'),
            $duree_init > 0 ? $duree_init : '-',
            $duree_rest > 0 ? $duree_rest : '-',
            $montant > 0 ? PDF_DIMF::montant($montant) : '-'
        ]);
        $i++;
    }

    // Ligne TOTAL
    $pdf->TableRow($cols, [
        'DIMF_2012_11',
        'TOTAL',
        '',
        $total_duree_initial > 0 ? $total_duree_initial : '-',
        $total_duree_restante > 0 ? round($total_duree_restante) : '-',
        PDF_DIMF::montant($total_encours)
    ], 'total');

    // Synthèse des risques
    $pdf->Ln(6);
    $pdf->SectionTitle('INDICATEURS DE CONCENTRATION');
    $pdf->SetFont('Arial', '', 8);
    $nb_superieur_10 = 0;
    $nb_superieur_25 = 0;
    foreach ($top_debiteurs as $d) {
        if (isset($d['encours_total']) && $fonds_propres > 0) {
            $p = ($d['encours_total'] / $fonds_propres) * 100;
            if ($p > 10) $nb_superieur_10++;
            if ($p > 25) $nb_superieur_25++;
        }
    }
    $pdf->MultiCell(0, 5, PDF_DIMF::u(
        "Fonds propres : " . PDF_DIMF::montant($fonds_propres) . "\n" .
        "Part des 10 premiers dans le portefeuille total : " . number_format($part_top10, 2) . "%\n" .
        "Débiteurs > 10% des fonds propres : " . $nb_superieur_10 . "\n" .
        "Débiteurs > 25% des fonds propres : " . $nb_superieur_25 . "\n" .
        "Plus gros emprunteur : " . PDF_DIMF::montant($top_debiteurs[0]['encours_total'] ?? 0) .
        " (" . number_format($ratio_concentration, 2) . "% des FP)"
    ));

    $pdf->Output('I', 'DIMF_2012_Top10_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}

// ============================================================
// EXPORT EXCEL (format=excel)
// ============================================================
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="DIMF_2012_' . $exercice . '_' . $type_periode . '.xls"');
    echo '<html><head><meta charset="UTF-8"><style>
        body { font-family: Arial; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #999; padding: 6px; }
        th { background: #f2f2f2; }
        .text-right { text-align: right; }
        .total { background: #e8f5e9; font-weight: bold; }
    </style></head><body>';
    echo '<h2>DIMF_2012 - État de l\'encours des 10 débiteurs les plus importants</h2>';
    echo '<p>Période : ' . htmlspecialchars($lib_periode) . '</p>';
    echo '<table><thead><tr><th>CODE</th><th>No DE COMPTE</th><th>NOMS ET PRÉNOMS</th><th class="text-right">DURÉE INITIALE (mois)</th><th class="text-right">DURÉE RESTANTE (mois)</th><th class="text-right">MONTANTS NETS (FCFA)</th></tr></thead><tbody>';
    $i = 1;
    foreach ($top_debiteurs as $d) {
        $code = 'DIMF_2012_' . $i;
        $montant = isset($d['encours_total']) ? $d['encours_total'] : 0;
        $duree_init = isset($d['duree_initiale_totale']) ? $d['duree_initiale_totale'] : 0;
        $duree_rest = isset($d['duree_restante_moyenne']) ? $d['duree_restante_moyenne'] : 0;
        $nom = isset($d['nom_complet']) ? $d['nom_complet'] : '';
        $matricule = isset($d['matricule']) ? $d['matricule'] : '';
        $num_compte = $matricule ?: (isset($d['numero_piece']) ? $d['numero_piece'] : '');
        echo '<tr><td>' . $code . '</td><td>' . htmlspecialchars($num_compte) . '</td><td>' . htmlspecialchars($nom ?: '-') . '</td>';
        echo '<td class="text-right">' . ($duree_init > 0 ? $duree_init : '-') . '</td>';
        echo '<td class="text-right">' . ($duree_rest > 0 ? $duree_rest : '-') . '</td>';
        echo '<td class="text-right">' . ($montant > 0 ? number_format($montant,0,',',' ') : '-') . '</td></tr>';
        $i++;
    }
    echo '<tr class="total"><td>DIMF_2012_11</td><td>TOTAL</td><td></td>';
    echo '<td class="text-right">' . ($total_duree_initial > 0 ? $total_duree_initial : '-') . '</td>';
    echo '<td class="text-right">' . ($total_duree_restante > 0 ? round($total_duree_restante) : '-') . '</td>';
    echo '<td class="text-right">' . number_format($total_encours,0,',',' ') . '</td></tr>';
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
    <title>DIMF_2012 - Top 10 des débiteurs</title>
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
        .text-right { text-align:right; font-family:'Courier New',monospace; font-weight:500; }
        .total-row { background:#f0fdf4; font-weight:700; border-top:2px solid #bbf7d0; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px 20px; border-radius:16px; display:flex; align-items:center; gap:14px; margin-bottom:20px; }
        .indicators-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; }
        .risk-high { color:#dc2626; font-weight:700; }
        .risk-low { color:#16a34a; font-weight:700; }
        .badge-risk { display:inline-block; padding:4px 10px; border-radius:20px; font-size:0.7rem; font-weight:600; }
        .badge-risk.high { background:#fee2e2; color:#dc2626; }
        .badge-risk.low { background:#d1fae5; color:#059669; }
        .page-footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; padding:16px; }
        @media (max-width:768px) { body { padding:12px; } .filters-row { flex-direction:column; } .btn-group { flex-wrap:wrap; } }
        @media print { .btn-group, .page-footer, .filters-row, #filtersCard { display:none; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-users"></i> DIMF_2012 - TOP 10 DES DÉBITEURS</h1>
            <div class="subtitle">République de Côte d'Ivoire – DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO • Grands risques</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" id="btnPdf"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <!-- Filtres -->
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
                <div class="filter-item">
                    <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Appliquer</button>
                </div>
            </div>
            <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;">
                <i class="fas fa-info-circle"></i> Période : <?= $lib_periode ?> (arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
            </div>
        </div>
    </form>

    <!-- Indicateurs de concentration -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-simple"></i> INDICATEURS DE CONCENTRATION</div>
        <div class="card-body">
            <div class="info-box">
                <div class="indicators-grid">
                    <div><strong>Fonds propres :</strong><br><?= number_format($fonds_propres,0,',',' ') ?> FCFA</div>
                    <div><strong>Plus gros emprunteur :</strong><br><?= number_format($top_debiteurs[0]['encours_total'] ?? 0,0,',',' ') ?> FCFA<br>
                        <span class="badge-risk <?= $ratio_concentration>10?'high':'low' ?>"><?= number_format($ratio_concentration,2) ?>% des FP</span>
                    </div>
                    <div><strong>Norme BCEAO :</strong> ≤10%<br>
                        <?= $ratio_concentration>10?'<span class="badge-risk high">❌ Non conforme</span>':'<span class="badge-risk low">✅ Conforme</span>' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tableau principal -->
    <div class="card">
        <div class="card-header"><i class="fas fa-table"></i> ÉTAT DE L'ENCOURS DES 10 DÉBITEURS LES PLUS IMPORTANTS</div>
        <div class="card-body">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>CODE</th>
                            <th>No DE COMPTE</th>
                            <th>NOMS ET PRÉNOMS</th>
                            <th class="text-right">DURÉE INITIALE (mois)</th>
                            <th class="text-right">DURÉE RESTANTE (mois)</th>
                            <th class="text-right">MONTANTS NETS (FCFA)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($top_debiteurs as $d): 
                            $montant = isset($d['encours_total']) ? $d['encours_total'] : 0;
                            $duree_init = isset($d['duree_initiale_totale']) ? $d['duree_initiale_totale'] : 0;
                            $duree_rest = isset($d['duree_restante_moyenne']) ? $d['duree_restante_moyenne'] : 0;
                            $nom = isset($d['nom_complet']) ? $d['nom_complet'] : '';
                            $matricule = isset($d['matricule']) ? $d['matricule'] : '';
                            $num_compte = $matricule ?: (isset($d['numero_piece']) ? $d['numero_piece'] : '');
                            $code = 'DIMF_2012_' . $i;
                        ?>
                        <tr>
                            <td><?= $code ?></td>
                            <td><?= htmlspecialchars($num_compte ?: '-') ?></td>
                            <td><?= htmlspecialchars($nom ?: '-') ?></td>
                            <td class="text-right"><?= $duree_init > 0 ? $duree_init : '-' ?></td>
                            <td class="text-right"><?= $duree_rest > 0 ? $duree_rest : '-' ?></td>
                            <td class="text-right"><?= $montant > 0 ? number_format($montant,0,',',' ') : '-' ?></td>
                        </tr>
                        <?php $i++; endforeach; ?>
                        <tr class="total-row">
                            <td>DIMF_2012_11</td>
                            <td><strong>TOTAL</strong></td>
                            <td></td>
                            <td class="text-right"><strong><?= $total_duree_initial > 0 ? $total_duree_initial : '-' ?></strong></td>
                            <td class="text-right"><strong><?= $total_duree_restante > 0 ? round($total_duree_restante) : '-' ?></strong></td>
                            <td class="text-right"><strong><?= number_format($total_encours,0,',',' ') ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Synthèse des risques -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-pie"></i> SYNTHÈSE DES RISQUES</div>
        <div class="card-body">
            <div class="info-box">
                <div class="indicators-grid">
                    <?php
                    $nb_superieur_10 = 0;
                    $nb_superieur_25 = 0;
                    foreach ($top_debiteurs as $d) {
                        if (isset($d['encours_total']) && $fonds_propres > 0) {
                            $p = ($d['encours_total'] / $fonds_propres) * 100;
                            if ($p > 10) $nb_superieur_10++;
                            if ($p > 25) $nb_superieur_25++;
                        }
                    }
                    ?>
                    <div><strong>Débiteurs >10% FP :</strong> <?= $nb_superieur_10 ?></div>
                    <div><strong>Débiteurs >25% FP :</strong> <?= $nb_superieur_25 ?></div>
                    <div><strong>Encours Top 10 :</strong> <?= number_format($total_encours,0,',',' ') ?> FCFA</div>
                    <div><strong>Part dans portefeuille :</strong> <?= number_format($part_top10,2) ?>%</div>
                </div>
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
        const currentMois = <?= $mois ?>;
        const currentTrimestre = <?= $trimestre ?>;
        const currentSemestre = <?= json_encode($semestre) ?>;
        let html = '';
        if (type === 'mensuel') {
            html = '<label>Mois</label><select name="mois" id="moisSelect">';
            for (let m=1;m<=12;m++) {
                const s = (m===currentMois)?'selected':'';
                const n = new Date(2000,m-1,1).toLocaleString('fr',{month:'long'});
                html += `<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`;
            }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
            for (let t=1;t<=4;t++) {
                const s = (t===currentTrimestre)?'selected':'';
                html += `<option value="${t}" ${s}>${t}${t===1?'er':'ème'} Trimestre</option>`;
            }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select name="semestre" id="semestreSelect">';
            for (let s=1;s<=2;s++) {
                const sel = (s===currentSemestre)?'selected':'';
                html += `<option value="${s}" ${sel}>${s}${s===1?'er':'e'} semestre</option>`;
            }
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
        // Soumission dans la même fenêtre (pas de target)
        form.submit();
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