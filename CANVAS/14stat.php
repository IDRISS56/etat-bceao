<?php
// 14-StatPointsServices.php - Statistiques des points de services
// Suivi des agences, points de service et indicateurs associés

session_start();

// Configuration BDD
require_once('../databases/database.php');

// ============================================================
// PARAMÈTRES
// ============================================================
$exercice     = isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y');
$type_periode = isset($_GET['type_periode']) ? $_GET['type_periode'] : 'annuel';
$mois         = isset($_GET['mois']) ? (int)$_GET['mois'] : 12;
$trimestre    = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 4;
$semestre     = isset($_GET['semestre']) ? (int)$_GET['semestre'] : 2;
$format       = isset($_GET['format']) ? $_GET['format'] : 'html';

// Calcul de la période
switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
    default:          $mois = isset($_GET['mois']) ? (int)$_GET['mois'] : 12;
}

$date_fin_periode = date('Y-m-t', strtotime($exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01'));
$date_debut_exercice = $exercice . '-01-01';

// Libellé période
switch ($type_periode) {
    case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
    case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
    case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
    default:          $lib_periode = 'Annee ' . $exercice;
}

// ============================================================
// RÉCUPÉRATION DES DONNÉES
// ============================================================

// 1. Points de services (Agences)
$pointsServices = [];
try {
    $stmtPS = $pdo->prepare("
        SELECT a.agence_id, a.code_agence, a.nom_agence, a.adresse, a.telephone, a.directeur, a.date_creation, a.statut, a.coordonnes_gps
        FROM agences a WHERE a.statut = 'active' ORDER BY a.date_creation ASC
    ");
    $stmtPS->execute();
    $pointsServices = $stmtPS->fetchAll();
} catch (PDOException $e) { $pointsServices = []; }

// 2. Effectif du personnel par agence
$personnelParAgence = [];
try {
    $stmtPersonnel = $pdo->prepare("SELECT agence_id, COUNT(*) as nb_personnel FROM utilisateurs WHERE role != 'Client' AND etat = 'actif' GROUP BY agence_id");
    $stmtPersonnel->execute();
    $personnelParAgence = $stmtPersonnel->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) { $personnelParAgence = []; }

// 3. Nombre de clients par agence
$clientsParAgence = [];
try {
    $stmtClients = $pdo->prepare("SELECT agence_id, COUNT(*) as nb_clients FROM clients WHERE statut = 'actif' GROUP BY agence_id");
    $stmtClients->execute();
    $clientsParAgence = $stmtClients->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) { $clientsParAgence = []; }

// 4. Répartition des clients par genre
$clientsParGenre = [];
try {
    $stmtGenre = $pdo->prepare("SELECT genre, COUNT(*) as nb FROM clients WHERE statut = 'actif' GROUP BY genre");
    $stmtGenre->execute();
    $clientsParGenre = $stmtGenre->fetchAll();
} catch (PDOException $e) { $clientsParGenre = []; }

// 5. Répartition des clients par milieu
$clientsParMilieu = [];
try {
    $stmtMilieu = $pdo->prepare("SELECT milieu, COUNT(*) as nb FROM clients WHERE statut = 'actif' GROUP BY milieu");
    $stmtMilieu->execute();
    $clientsParMilieu = $stmtMilieu->fetchAll();
} catch (PDOException $e) { $clientsParMilieu = []; }

// 6. Encours des dépôts par agence
$depotsParAgence = [];
try {
    $stmtDepots = $pdo->prepare("SELECT c.agence_id, COALESCE(SUM(c.solde), 0) as total_depots FROM comptes c WHERE c.solde > 0 AND c.statut = 'actif' GROUP BY c.agence_id");
    $stmtDepots->execute();
    $depotsParAgence = $stmtDepots->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) { $depotsParAgence = []; }

// 7. Crédits décaissés par agence
$creditsDecaissesParAgence = [];
$total_decaissements = 0;
$total_nb_decaissements = 0;
try {
    $stmtCredits = $pdo->prepare("
        SELECT d.agence_id, COUNT(*) as nb_credits, COALESCE(SUM(d.montant), 0) as montant_total
        FROM dossiers d
        WHERE d.date_octroi BETWEEN :date_debut AND :date_fin AND d.statut IN ('actif', 'approuve')
        GROUP BY d.agence_id
    ");
    $stmtCredits->execute([':date_debut' => $date_debut_exercice, ':date_fin' => $date_fin_periode]);
    $creditsDecaissesParAgence = $stmtCredits->fetchAll(PDO::FETCH_ASSOC);
    $total_decaissements = array_sum(array_column($creditsDecaissesParAgence, 'montant_total'));
    $total_nb_decaissements = array_sum(array_column($creditsDecaissesParAgence, 'nb_credits'));
} catch (PDOException $e) { $creditsDecaissesParAgence = []; }

// 8. Encours des crédits par agence
$encoursCreditsParAgence = [];
$total_encours = 0;
$total_nb_encours = 0;
try {
    $stmtEncours = $pdo->prepare("
        SELECT d.agence_id, COUNT(*) as nb_credits, COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as encours_total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve') AND d.date_octroi <= :date_fin
        GROUP BY d.agence_id
    ");
    $stmtEncours->execute([':date_fin' => $date_fin_periode]);
    $encoursCreditsParAgence = $stmtEncours->fetchAll(PDO::FETCH_ASSOC);
    $total_encours = array_sum(array_column($encoursCreditsParAgence, 'encours_total'));
    $total_nb_encours = array_sum(array_column($encoursCreditsParAgence, 'nb_credits'));
} catch (PDOException $e) { $encoursCreditsParAgence = []; }

// 9. Crédits par secteur d'activité
$creditsParSecteur = [];
try {
    $stmtSecteur = $pdo->prepare("
        SELECT c.secteur_id, s.nom as secteur_nom, COUNT(*) as nb_credits, COALESCE(SUM(d.montant), 0) as montant_total
        FROM dossiers d
        INNER JOIN clients c ON d.client_id = c.client_id
        LEFT JOIN secteurs s ON c.secteur_id = s.secteur_id
        WHERE d.date_octroi BETWEEN :date_debut AND :date_fin AND d.statut IN ('actif', 'approuve')
        GROUP BY c.secteur_id ORDER BY montant_total DESC
    ");
    $stmtSecteur->execute([':date_debut' => $date_debut_exercice, ':date_fin' => $date_fin_periode]);
    $creditsParSecteur = $stmtSecteur->fetchAll();
} catch (PDOException $e) { $creditsParSecteur = []; }

// Totaux généraux
$total_personnel = array_sum($personnelParAgence);
$total_clients = array_sum($clientsParAgence);
$total_depots = array_sum($depotsParAgence);
$total_credits_decaisses = $total_decaissements;
$total_nb_credits_decaisses = $total_nb_decaissements;
$total_encours_credits = $total_encours;

// Performance par agence
$performanceParAgence = [];
foreach ($pointsServices as $ps) {
    $agence_id = $ps['agence_id'];
    $performanceParAgence[$agence_id] = [
        'nom' => $ps['nom_agence'],
        'code' => $ps['code_agence'],
        'personnel' => $personnelParAgence[$agence_id] ?? 0,
        'clients' => $clientsParAgence[$agence_id] ?? 0,
        'depots' => $depotsParAgence[$agence_id] ?? 0,
        'credits_decaisses' => 0,
        'encours_credits' => 0
    ];
    
    foreach ($creditsDecaissesParAgence as $cd) {
        if ($cd['agence_id'] == $agence_id) {
            $performanceParAgence[$agence_id]['credits_decaisses'] = $cd['montant_total'];
            break;
        }
    }
    foreach ($encoursCreditsParAgence as $ec) {
        if ($ec['agence_id'] == $agence_id) {
            $performanceParAgence[$agence_id]['encours_credits'] = $ec['encours_total'];
            break;
        }
    }
}

// Indicateurs clés
$ratio_clients_par_employe = ($total_personnel > 0) ? $total_clients / $total_personnel : 0;
$montant_moyen_credit = ($total_nb_credits_decaisses > 0) ? $total_credits_decaisses / $total_nb_credits_decaisses : 0;

// ============================================================
// FONCTION FORMATAGE
// ============================================================
function format_montant($val) {
    return number_format((float)$val, 0, ',', ' ') . ' F';
}

// ============================================================
// EXPORT PDF
// ============================================================
if ($format === 'pdf') {
    if (ob_get_length()) ob_end_clean();
    
    $fpdf_path = __DIR__ . '/fpdf/fpdf.php';
    $alt_fpdf_path = dirname(__DIR__) . '/fpdf/fpdf.php';
    
    if (file_exists($fpdf_path)) {
        require_once($fpdf_path);
    } elseif (file_exists($alt_fpdf_path)) {
        require_once($alt_fpdf_path);
    } else {
        die("Erreur: La bibliotheque FPDF n'est pas trouvee.");
    }
    
    class PDF_DIMF extends FPDF {
        function convert($str) {
            $str = str_replace(array('é','è','ê','ë','à','â','ä','î','ï','ô','ö','ù','û','ü','ç','É','È','Ê','Ë','À','Â','Ä','Î','Ï','Ô','Ö','Ù','Û','Ü','Ç'), 
                              array('e','e','e','e','a','a','a','i','i','o','o','u','u','u','c','E','E','E','E','A','A','A','I','I','O','O','U','U','U','C'), $str);
            return $str;
        }
        
        function Header() {
            $this->SetFillColor(156, 163, 175);
            $this->Rect(0, 0, $this->GetPageWidth(), 28, 'F');
            $this->SetFont('Arial', '', 7);
            $this->SetTextColor(255, 255, 255);
            $this->SetXY(8, 3);
            $this->Cell(0, 4, $this->convert('Republique de Cote d\'Ivoire  •  Ministere de l\'Economie et des Finances  -  DGTCP / DSFD'), 0, 1, 'L');
            $this->SetFont('Arial', 'B', 13);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 7, $this->convert('14 - STATISTIQUES DES POINTS DE SERVICES'), 0, 1, 'L');
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 5, $this->convert('Donnees statistiques des agences et points de service'), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
            $this->Ln(4);
        }
        
        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, $this->convert('Page ' . $this->PageNo() . '/{nb} - Genere le ' . date('d/m/Y H:i:s')), 0, 0, 'C');
        }
        
        function SectionTitle($label) {
            $this->SetFont('Arial', 'B', 10);
            $this->SetFillColor(0, 0, 0);
            $this->SetTextColor(255, 255, 255);
            $this->Cell(0, 8, $this->convert($label), 0, 1, 'L', true);
            $this->SetTextColor(0, 0, 0);         
            $this->Ln(2);
        }
        
        function TableHeader($cols) {
            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(240, 240, 240);
            $this->SetTextColor(0, 0, 0);
            foreach ($cols as $col) {
                $this->Cell($col['w'], 7, $this->convert($col['label']), 1, 0, $col['align'] ?? 'L', true);
            }
            $this->Ln();
        }
        
        function TableRow($cols, $data, $style = '') {
            if ($style == 'total') {
                $this->SetFont('Arial', 'B', 8);
                $this->SetFillColor(240, 253, 244);
            $this->SetTextColor(0, 0, 0);
                $fill = true;
            } else {
                $this->SetFont('Arial', '', 8);
                $fill = false;
            }
            foreach ($cols as $i => $col) {
                $val = $data[$i] ?? '';
                $this->Cell($col['w'], 6, $this->convert($val), 1, 0, $col['align'] ?? 'L', $fill);
            }
            $this->Ln();
        }
        
        function montant($val) {
            return number_format((float)$val, 0, ',', ' ') . ' F';
        }
    }
    
    $pdf = new PDF_DIMF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->SetMargins(10, 35, 10);
    $pdf->AddPage();
    
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 6, $pdf->convert('Periode : ' . $lib_periode), 0, 1, 'C');
    $pdf->Ln(5);
    
    // Vue d'ensemble
    $pdf->SectionTitle('VUE D\'ENSEMBLE');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(70, 7, 'Points de services :', 0, 0);
    $pdf->Cell(0, 7, count($pointsServices), 0, 1);
    $pdf->Cell(70, 7, 'Effectif du personnel :', 0, 0);
    $pdf->Cell(0, 7, number_format($total_personnel), 0, 1);
    $pdf->Cell(70, 7, 'Nombre de membres/clients :', 0, 0);
    $pdf->Cell(0, 7, number_format($total_clients), 0, 1);
    $pdf->Cell(70, 7, 'Credits decaisses (annee) :', 0, 0);
    $pdf->Cell(0, 7, number_format($total_nb_credits_decaisses), 0, 1);
    $pdf->Ln(5);
    
    // Performance par agence
    $pdf->SectionTitle('PERFORMANCE PAR AGENCE');
    $cols = [
        ['label' => 'AGENCE', 'w' => 50, 'align' => 'L'],
        ['label' => 'Personnel', 'w' => 25, 'align' => 'R'],
        ['label' => 'Clients', 'w' => 25, 'align' => 'R'],
        ['label' => 'Depots (FCFA)', 'w' => 45, 'align' => 'R'],
        ['label' => 'Credits decaisses (FCFA)', 'w' => 50, 'align' => 'R'],
        ['label' => 'Encours credits (FCFA)', 'w' => 45, 'align' => 'R']
    ];
    $pdf->TableHeader($cols);
    foreach ($performanceParAgence as $pa) {
        $pdf->TableRow($cols, [
            $pa['nom'],
            number_format($pa['personnel']),
            number_format($pa['clients']),
            $pdf->montant($pa['depots']),
            $pdf->montant($pa['credits_decaisses']),
            $pdf->montant($pa['encours_credits'])
        ]);
    }
    $pdf->TableRow($cols, [
        'TOTAL',
        number_format($total_personnel),
        number_format($total_clients),
        $pdf->montant($total_depots),
        $pdf->montant($total_credits_decaisses),
        $pdf->montant($total_encours_credits)
    ], 'total');
    
    $pdf->Output('I', '14_STAT_POINTS_SERVICES_' . $exercice . '.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>14 - Statistiques des points de services</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; padding: 24px; }
        .dashboard { max-width: 1400px; margin: 0 auto; }
        
        .page-header { background: linear-gradient(135deg, #3b82f6, #60a5fa); border-radius: 24px; padding: 20px 28px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; box-shadow: 0 4px 6px -2px rgba(0,0,0,0.05); }
        .header-left h1 { font-size: 1.6rem; font-weight: 600; color: white; margin-bottom: 6px; display: flex; align-items: center; gap: 10px; }
        .subtitle { font-size: 0.8rem; color: #e0f2fe; line-height: 1.4; }
        .badge { display: inline-block; background: #2563eb; color: white; padding: 4px 12px; border-radius: 30px; font-size: 0.7rem; font-weight: 500; margin-top: 8px; }
        
        .btn-group { display: flex; gap: 12px; }
        .btn-excel, .btn-pdf { display: inline-flex; align-items: center; gap: 8px; padding: 8px 20px; border-radius: 40px; font-weight: 500; font-size: 0.85rem; border: none; cursor: pointer; transition: 0.2s; text-decoration: none; }
        .btn-excel { background: #10b981; color: white; }
        .btn-excel:hover { background: #059669; transform: translateY(-1px); }
        .btn-pdf { background: #ef4444; color: white; }
        .btn-pdf:hover { background: #dc2626; transform: translateY(-1px); }
        
        .card { background: white; border-radius: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05), 0 8px 16px -4px rgba(0,0,0,0.05); margin-bottom: 24px; overflow: hidden; }
        .card-header { display: flex; align-items: center; gap: 10px; padding: 16px 24px; background: #f8fafc; border-bottom: 1px solid #eef2f6; font-weight: 600; font-size: 1rem; color: #1e40af; }
        .card-header i { color: #3b82f6; }
        .card-body { padding: 20px 24px; }
        
        .filters-row { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 20px; }
        .filter-item { display: flex; flex-direction: column; gap: 6px; }
        .filter-item label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #4b5563; }
        .filter-item select { background: white; border: 1px solid #d1d5db; border-radius: 12px; padding: 8px 14px; font-size: 0.85rem; color: #111827; cursor: pointer; }
        .filter-item select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.2); }
        .btn-apply { background: #3b82f6; color: white; border: none; border-radius: 40px; padding: 8px 24px; font-weight: 500; font-size: 0.85rem; cursor: pointer; transition: 0.2s; }
        .btn-apply:hover { background: #2563eb; transform: translateY(-1px); }
        
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 20px; }
        .stat-card { background: #f8fafc; border-radius: 16px; padding: 20px; text-align: center; border-top: 3px solid #3b82f6; transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-card .value { font-size: 1.8rem; font-weight: 700; color: #1e293b; }
        .stat-card .label { font-size: 0.8rem; color: #64748b; margin-top: 5px; }
        
        .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
        
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th { text-align: left; padding: 12px 16px; background: #f8fafc; font-weight: 600; color: #1e293b; border-bottom: 1px solid #e2e8f0; }
        td { padding: 10px 16px; border-bottom: 1px solid #f1f5f9; color: #0f172a; }
        .text-right { text-align: right; font-family: 'Courier New', monospace; font-weight: 500; }
        .total-row { background: #f0fdf4; font-weight: 700; border-top: 2px solid #bbf7d0; }
        
        .info-box { background: #eef2ff; border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 16px; display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
        .progress-bar { background: #e2e8f0; border-radius: 10px; height: 20px; overflow: hidden; margin-top: 8px; }
        .progress-fill { background: #3b82f6; height: 100%; display: flex; align-items: center; justify-content: flex-end; padding-right: 5px; color: white; font-size: 0.7rem; }
        
        .status-active { background: #e8f5e9; color: #2e7d32; padding: 4px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; display: inline-block; }
        
        .footer { text-align: center; font-size: 0.75rem; color: #6b7280; margin-top: 16px; padding: 16px; }
        
        @media (max-width: 768px) {
            body { padding: 12px; }
            .filters-row { flex-direction: column; align-items: stretch; }
            .btn-group { flex-wrap: wrap; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .grid-2 { grid-template-columns: 1fr; }
            th, td { padding: 8px 12px; font-size: 0.75rem; }
        }
        
        @media print {
            body { background: white; padding: 0; }
            .btn-group, .footer, .filters-row, #filtersCard { display: none !important; }
        }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-map-marker-alt"></i> 14 - Statistiques des points de services</h1>
            <div class="subtitle">Republique de Cote d'Ivoire / Ministere de l'Economie et des Finances – DGTCP / DSFD</div>
            <div class="badge">Donnees statistiques des agences et points de service</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <?php
            $params = [
                'exercice' => $exercice,
                'type_periode' => $type_periode,
                'mois' => $mois,
                'trimestre' => $trimestre,
                'semestre' => $semestre,
                'format' => 'pdf'
            ];
            ?>
            <a class="btn-pdf" href="?<?= http_build_query($params) ?>" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
        </div>
    </div>

    <!-- Filtres -->
    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="card-body">
            <div class="filters-row">
                <div class="filter-item">
                    <label>Annee</label>
                    <select id="exerciceSelect">
                        <?php for ($y = 2020; $y <= date('Y')+1; $y++): ?>
                            <option value="<?= $y ?>" <?= $y==$exercice?'selected':'' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filter-item">
                    <label>Type de periode</label>
                    <select id="typePeriodeSelect">
                        <option value="mensuel"   <?= $type_periode=='mensuel'  ?'selected':'' ?>>Mensuel</option>
                        <option value="trimestre" <?= $type_periode=='trimestre'?'selected':'' ?>>Trimestre</option>
                        <option value="semestre"  <?= $type_periode=='semestre' ?'selected':'' ?>>Semestre</option>
                        <option value="annuel"    <?= $type_periode=='annuel'   ?'selected':'' ?>>Annuel</option>
                    </select>
                </div>
                <div class="filter-item" id="dynamicSelectContainer">
                    <?php
                    if ($type_periode == 'mensuel') {
                        echo '<label>Mois</label><select id="moisSelect">';
                        for ($m=1;$m<=12;$m++) { $s=($m==$mois)?'selected':''; echo "<option value='$m' $s>".str_pad($m,2,'0',STR_PAD_LEFT)." - ".date('F',mktime(0,0,0,$m,1))."</option>"; }
                        echo '</select>';
                    } elseif ($type_periode == 'trimestre') {
                        echo '<label>Trimestre</label><select id="trimestreSelect">';
                        for ($t=1;$t<=4;$t++) { $s=($t==$trimestre)?'selected':''; echo "<option value='$t' $s>$t".($t==1?'er':'eme')." Trimestre</option>"; }
                        echo '</select>';
                    } elseif ($type_periode == 'semestre') {
                        echo '<label>Semestre</label><select id="semestreSelect">';
                        for ($s=1;$s<=2;$s++) { $sel=($s==$semestre)?'selected':''; echo "<option value='$s' $sel>$s".($s==1?'er':'e')." semestre</option>"; }
                        echo '</select>';
                    } else {
                        echo '<label>Periode</label><input type="text" disabled value="Annee complete" style="background:#f3f4f6;cursor:default;">';
                    }
                    ?>
                </div>
                <button class="btn-apply" onclick="appliquerFiltres()"><i class="fas fa-filter"></i> Appliquer</button>
            </div>
            <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;">
                <i class="fas fa-info-circle"></i> Periode : <?= $lib_periode ?> (arrete au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
            </div>
        </div>
    </div>

    <!-- Vue d'ensemble -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-simple"></i> VUE D'ENSEMBLE</div>
        <div class="card-body">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="value"><?= count($pointsServices) ?></div>
                    <div class="label">Points de services</div>
                </div>
                <div class="stat-card">
                    <div class="value"><?= number_format($total_personnel) ?></div>
                    <div class="label">Effectif du personnel</div>
                </div>
                <div class="stat-card">
                    <div class="value"><?= number_format($total_clients) ?></div>
                    <div class="label">Nombre de membres/clients</div>
                </div>
                <div class="stat-card">
                    <div class="value"><?= number_format($total_nb_credits_decaisses) ?></div>
                    <div class="label">Credits decaisses (annee)</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Liste des points de services -->
    <div class="card">
        <div class="card-header"><i class="fas fa-list"></i> LISTE DES POINTS DE SERVICES</div>
        <div class="card-body">
            <div class="table-wrapper">
                <?php if(empty($pointsServices)): ?>
                    <div class="info-box">Aucun point de service enregistre.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr><th>Code</th><th>Nom de l'agence</th><th>Adresse</th><th>Telephone</th><th>Directeur</th><th>Date creation</th><th>Statut</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($pointsServices as $ps): ?>
                            <tr>
                                <td><?= htmlspecialchars($ps['code_agence']) ?></td>
                                <td><?= htmlspecialchars($ps['nom_agence']) ?></td>
                                <td><?= htmlspecialchars($ps['adresse'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($ps['telephone'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($ps['directeur'] ?? '-') ?></td>
                                <td><?= date('d/m/Y', strtotime($ps['date_creation'])) ?></td>
                                <td><span class="status-active">Actif</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Performance par agence -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-line"></i> PERFORMANCE PAR AGENCE</div>
        <div class="card-body">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr><th>Agence</th><th class="text-right">Personnel</th><th class="text-right">Clients</th><th class="text-right">Depots (FCFA)</th><th class="text-right">Credits decaisses (FCFA)</th><th class="text-right">Encours credits (FCFA)</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($performanceParAgence as $pa): ?>
                        <tr>
                            <td><?= htmlspecialchars($pa['nom']) ?> (<?= htmlspecialchars($pa['code']) ?>)</td>
                            <td class="text-right"><?= number_format($pa['personnel']) ?></td>
                            <td class="text-right"><?= number_format($pa['clients']) ?></td>
                            <td class="text-right"><?= number_format($pa['depots'], 0, ',', ' ') ?></td>
                            <td class="text-right"><?= number_format($pa['credits_decaisses'], 0, ',', ' ') ?></td>
                            <td class="text-right"><?= number_format($pa['encours_credits'], 0, ',', ' ') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td><strong>TOTAL</strong></td>
                            <td class="text-right"><strong><?= number_format($total_personnel) ?></strong></td>
                            <td class="text-right"><strong><?= number_format($total_clients) ?></strong></td>
                            <td class="text-right"><strong><?= number_format($total_depots, 0, ',', ' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($total_credits_decaisses, 0, ',', ' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($total_encours_credits, 0, ',', ' ') ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Repartition des clients -->
    <div class="grid-2">
        <div class="card">
            <div class="card-header"><i class="fas fa-venus-mars"></i> REPARTITION DES CLIENTS PAR GENRE</div>
            <div class="card-body">
                <?php if(empty($clientsParGenre)): ?>
                    <div class="info-box">Aucune donnee disponible.</div>
                <?php else: ?>
                    <table style="width: 100%;">
                        <?php foreach($clientsParGenre as $cg): ?>
                        <tr>
                            <td><?= htmlspecialchars($cg['genre'] ?? 'Non precise') ?></td>
                            <td class="text-right"><?= number_format($cg['nb']) ?> (<?= number_format(($total_clients > 0) ? ($cg['nb'] / $total_clients) * 100 : 0, 1) ?>%)</td>
                            <td style="width: 50%;">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?= ($total_clients > 0) ? ($cg['nb'] / $total_clients) * 100 : 0 ?>%;"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-globe"></i> REPARTITION DES CLIENTS PAR MILIEU</div>
            <div class="card-body">
                <?php if(empty($clientsParMilieu)): ?>
                    <div class="info-box">Aucune donnee disponible.</div>
                <?php else: ?>
                    <table style="width: 100%;">
                        <?php foreach($clientsParMilieu as $cm): ?>
                        <tr>
                            <td><?= ucfirst(htmlspecialchars($cm['milieu'])) ?></td>
                            <td class="text-right"><?= number_format($cm['nb']) ?> (<?= number_format(($total_clients > 0) ? ($cm['nb'] / $total_clients) * 100 : 0, 1) ?>%)</td>
                            <td style="width: 50%;">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?= ($total_clients > 0) ? ($cm['nb'] / $total_clients) * 100 : 0 ?>%;"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Credits par secteur -->
    <div class="card">
        <div class="card-header"><i class="fas fa-industry"></i> CREDITS PAR SECTEUR D'ACTIVITE</div>
        <div class="card-body">
            <div class="table-wrapper">
                <?php if(empty($creditsParSecteur)): ?>
                    <div class="info-box">Aucune donnee disponible.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr><th>Secteur d'activite</th><th class="text-right">Nombre de credits</th><th class="text-right">Montant total (FCFA)</th><th class="text-right">Part (%)</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($creditsParSecteur as $cs): ?>
                            <tr>
                                <td><?= htmlspecialchars($cs['secteur_nom'] ?? $cs['secteur_id'] ?? 'Non specifie') ?></td>
                                <td class="text-right"><?= number_format($cs['nb_credits']) ?></td>
                                <td class="text-right"><?= number_format($cs['montant_total'], 0, ',', ' ') ?></td>
                                <td class="text-right"><?= number_format(($total_credits_decaisses > 0) ? ($cs['montant_total'] / $total_credits_decaisses) * 100 : 0, 1) ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td><strong>TOTAL</strong></td>
                                <td class="text-right"><strong><?= number_format($total_nb_credits_decaisses) ?></strong></td>
                                <td class="text-right"><strong><?= number_format($total_credits_decaisses, 0, ',', ' ') ?></strong></td>
                                <td class="text-right"><strong>100%</strong></td>
                            </tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Indicateurs clés -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-pie"></i> INDICATEURS CLES</div>
        <div class="card-body">
            <div class="grid-2">
                <div class="info-box">
                    <i class="fas fa-users"></i>
                    <div>
                        <strong>Productivite du personnel :</strong><br>
                        Clients par employe : <?= number_format($ratio_clients_par_employe, 1) ?><br>
                        Encours credits par employe : <?= ($total_personnel > 0) ? number_format($total_encours_credits / $total_personnel, 0, ',', ' ') : '0' ?> FCFA
                    </div>
                </div>
                <div class="info-box">
                    <i class="fas fa-hand-holding-usd"></i>
                    <div>
                        <strong>Performance commerciale :</strong><br>
                        Montant moyen des credits : <?= number_format($montant_moyen_credit, 0, ',', ' ') ?> FCFA<br>
                        Taux d'utilisation des depots : N/A
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="footer">
        <i class="fas fa-calendar-alt"></i> Document genere le <?= date('d/m/Y a H:i:s') ?> - Donnees extraites de la base Mandigo<br>
        Periode : <?= $lib_periode ?> (arrete au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
    </div>
</div>

<script>
    function updateDynamicSelect() {
        const type = document.getElementById('typePeriodeSelect').value;
        const container = document.getElementById('dynamicSelectContainer');
        const currentMois = <?= $mois ?>;
        const currentTrimestre = <?= $trimestre ?>;
        const currentSemestre = <?= json_encode($semestre) ?>;
        let html = '';
        
        if (type === 'mensuel') {
            html = '<label>Mois</label><select id="moisSelect">';
            for (let m = 1; m <= 12; m++) {
                const s = (m === currentMois) ? 'selected' : '';
                const n = new Date(2000, m-1, 1).toLocaleString('fr', {month:'long'});
                html += `<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`;
            }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select id="trimestreSelect">';
            for (let t = 1; t <= 4; t++) {
                const s = (t === currentTrimestre) ? 'selected' : '';
                html += `<option value="${t}" ${s}>${t}${t === 1 ? 'er' : 'eme'} Trimestre</option>`;
            }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select id="semestreSelect">';
            for (let s = 1; s <= 2; s++) {
                const sel = (s === currentSemestre) ? 'selected' : '';
                html += `<option value="${s}" ${sel}>${s}${s === 1 ? 'er' : 'e'} semestre</option>`;
            }
            html += '</select>';
        } else {
            html = '<label>Periode</label><input type="text" disabled value="Annee complete" style="background:#f3f4f6;cursor:default;">';
        }
        container.innerHTML = html;
    }

    function appliquerFiltres() {
        const exercice = document.getElementById('exerciceSelect').value;
        const type = document.getElementById('typePeriodeSelect').value;
        let url = '14stat.php?exercice=' + exercice + '&type_periode=' + type;
        
        if (type === 'mensuel') url += '&mois=' + document.getElementById('moisSelect').value;
        if (type === 'trimestre') url += '&trimestre=' + document.getElementById('trimestreSelect').value;
        if (type === 'semestre') url += '&semestre=' + document.getElementById('semestreSelect').value;
        
        window.location.href = url;
    }

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        
        let data = [
            ['14 - STATISTIQUES DES POINTS DE SERVICES'],
            ['Periode : <?= addslashes($lib_periode) ?>'],
            [],
            ['VUE D\'ENSEMBLE', ''],
            ['Points de services', <?= count($pointsServices) ?>],
            ['Effectif du personnel', <?= $total_personnel ?>],
            ['Nombre de membres/clients', <?= $total_clients ?>],
            ['Credits decaisses (annee)', <?= $total_nb_credits_decaisses ?>],
            [],
            ['PERFORMANCE PAR AGENCE', '', '', '', '', ''],
            ['Agence', 'Personnel', 'Clients', 'Depots (FCFA)', 'Credits decaisses (FCFA)', 'Encours credits (FCFA)']
        ];
        
        <?php foreach($performanceParAgence as $pa): ?>
        data.push([
            '<?= addslashes($pa['nom']) ?> (<?= addslashes($pa['code']) ?>)',
            <?= $pa['personnel'] ?>,
            <?= $pa['clients'] ?>,
            <?= $pa['depots'] ?>,
            <?= $pa['credits_decaisses'] ?>,
            <?= $pa['encours_credits'] ?>
        ]);
        <?php endforeach; ?>
        
        data.push(['TOTAL', <?= $total_personnel ?>, <?= $total_clients ?>, <?= $total_depots ?>, <?= $total_credits_decaisses ?>, <?= $total_encours_credits ?>]);
        
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(data), "STAT_POINTS_SERVICES");
        XLSX.writeFile(wb, '14_STAT_POINTS_SERVICES_<?= $exercice ?>.xlsx');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
    });
</script>
</body>
</html>