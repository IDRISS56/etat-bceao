<?php
// DIMF_2010.php - État des crédits en souffrance
// Déclaration SICS-BCEAO

session_start();

// Configuration BDD
$host = 'localhost';
$dbname = 'mandigo';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// Récupération des paramètres
$exercice = isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y');
$trimestre = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 4;
$mois = isset($_GET['mois']) ? (int)$_GET['mois'] : 12;
$date_fin_periode = $exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01';
$date_fin_periode = date('Y-m-t', strtotime($date_fin_periode));

// ============================================================
// CALCUL DES CRÉDITS EN SOUFFRANCE
// ============================================================

// Récupérer tous les dossiers avec leurs échéances impayées
$credits_souffrance = [];

try {
    // Récupération des crédits en souffrance avec détails
    $stmt = $pdo->prepare("
        SELECT 
            d.dossier_id,
            d.montant as montant_initial,
            d.date_octroi,
            d.statut as dossier_statut,
            COALESCE(d.montant - SUM(CASE WHEN e.statut = 'payee' THEN e.montant ELSE 0 END), d.montant) as encours_actuel,
            SUM(CASE WHEN e.statut = 'attente' AND e.date_echeance < :date_fin THEN e.montant ELSE 0 END) as montant_impaye,
            MAX(CASE WHEN e.statut = 'attente' AND e.date_echeance < :date_fin THEN e.date_echeance ELSE NULL END) as date_dernier_impaye,
            COUNT(CASE WHEN e.statut = 'attente' AND e.date_echeance < :date_fin THEN 1 ELSE NULL END) as nb_echeances_impayees,
            SUM(CASE WHEN e.statut = 'payee' THEN e.montant ELSE 0 END) as total_rembourse
        FROM dossiers d
        LEFT JOIN echeances e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve', 'impaye')
          AND d.date_octroi <= :date_fin
        GROUP BY d.dossier_id
        HAVING montant_impaye > 0
        ORDER BY date_dernier_impaye DESC
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $credits_souffrance = $stmt->fetchAll();
} catch (PDOException $e) {
    $credits_souffrance = [];
}

// Initialisation des totaux par tranche
$tranches = [
    'B71' => ['libelle' => 'Crédits comportant au moins une échéance impayée ≤ 6 mois', 'min_jours' => 1, 'max_jours' => 180, 'montant_brut' => 0, 'depots_garantie' => 0, 'solde_restant' => 0, 'provisions' => 0, 'montant_net' => 0],
    'B72' => ['libelle' => 'Crédits comportant au moins une échéance impayée > 6 à ≤ 12 mois', 'min_jours' => 181, 'max_jours' => 365, 'montant_brut' => 0, 'depots_garantie' => 0, 'solde_restant' => 0, 'provisions' => 0, 'montant_net' => 0],
    'B73' => ['libelle' => 'Crédits comportant au moins une échéance impayée > 12 à ≤ 24 mois', 'min_jours' => 366, 'max_jours' => 730, 'montant_brut' => 0, 'depots_garantie' => 0, 'solde_restant' => 0, 'provisions' => 0, 'montant_net' => 0]
];

$date_reference = new DateTime($date_fin_periode);

foreach ($credits_souffrance as $credit) {
    if ($credit['date_dernier_impaye']) {
        $date_impaye = new DateTime($credit['date_dernier_impaye']);
        $interval = $date_reference->diff($date_impaye);
        $jours_retard = $interval->days;
        
        // Déterminer la tranche
        if ($jours_retard >= 1 && $jours_retard <= 180) {
            $tranche = 'B71';
        } elseif ($jours_retard >= 181 && $jours_retard <= 365) {
            $tranche = 'B72';
        } elseif ($jours_retard >= 366 && $jours_retard <= 730) {
            $tranche = 'B73';
        } else {
            continue; // Ignorer les crédits hors tranches
        }
        
        // Récupération des provisions pour ce crédit
        $provisions_credit = 0;
        try {
            $stmtProv = $pdo->prepare("
                SELECT COALESCE(SUM(montant), 0) as total_prov
                FROM provisions
                WHERE credit_id = :credit_id AND statut = 'actif'
            ");
            $stmtProv->execute([':credit_id' => $credit['dossier_id']]);
            $provResult = $stmtProv->fetch();
            $provisions_credit = $provResult['total_prov'];
        } catch (PDOException $e) {
            $provisions_credit = 0;
        }
        
        $tranches[$tranche]['montant_brut'] += $credit['montant_initial'];
        $tranches[$tranche]['solde_restant'] += $credit['encours_actuel'];
        $tranches[$tranche]['provisions'] += $provisions_credit;
        $tranches[$tranche]['montant_net'] += $credit['encours_actuel'] - $provisions_credit;
    }
}

// Calcul des totaux
$total_brut = $tranches['B71']['montant_brut'] + $tranches['B72']['montant_brut'] + $tranches['B73']['montant_brut'];
$total_solde_restant = $tranches['B71']['solde_restant'] + $tranches['B72']['solde_restant'] + $tranches['B73']['solde_restant'];
$total_provisions = $tranches['B71']['provisions'] + $tranches['B72']['provisions'] + $tranches['B73']['provisions'];
$total_net = $tranches['B71']['montant_net'] + $tranches['B72']['montant_net'] + $tranches['B73']['montant_net'];

// Détail des crédits en souffrance pour affichage
$details_credits = [];
foreach ($credits_souffrance as $credit) {
    if ($credit['date_dernier_impaye']) {
        $date_impaye = new DateTime($credit['date_dernier_impaye']);
        $interval = $date_reference->diff($date_impaye);
        $jours_retard = $interval->days;
        
        if ($jours_retard >= 1 && $jours_retard <= 730) {
            $details_credits[] = $credit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2010 - État des crédits en souffrance</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, #1a3a5c, #0d2137);
            color: white;
            padding: 25px 30px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 1.8rem;
            margin-bottom: 8px;
        }
        
        .header .subtitle {
            opacity: 0.9;
            font-size: 0.95rem;
        }
        
        .badge {
            display: inline-block;
            background: #ffc107;
            color: #1a3a5c;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
            margin-top: 10px;
        }
        
        .filters {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-group label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #555;
        }
        
        .filter-group select, .filter-group input {
            padding: 8px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        .btn {
            padding: 8px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #1a3a5c;
            color: white;
        }
        
        .btn-primary:hover {
            background: #0d2137;
        }
        
        .section-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .section-title {
            padding: 15px 20px;
            background: #f8f9fa;
            border-bottom: 2px solid #1a3a5c;
            font-size: 1.1rem;
            font-weight: bold;
            color: #1a3a5c;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #555;
            font-size: 0.85rem;
            text-align: center;
        }
        
        td {
            text-align: center;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-left {
            text-align: left;
        }
        
        .total-row {
            background: #e8f5e9;
            font-weight: bold;
        }
        
        .warning-row {
            background: #fff3e0;
        }
        
        .footer {
            text-align: center;
            padding: 20px;
            color: #777;
            font-size: 0.8rem;
        }
        
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin: 15px;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        .badge-retard {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: bold;
        }
        
        .retard-30 {
            background: #fff3e0;
            color: #ef6c00;
        }
        
        .retard-90 {
            background: #ffebee;
            color: #c62828;
        }
        
        .retard-180 {
            background: #b71c1c;
            color: white;
        }
        
        @media (max-width: 768px) {
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            
            table {
                font-size: 0.7rem;
            }
            
            th, td {
                padding: 6px 4px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>DIMF_2010 - ÉTAT DES CRÉDITS EN SOUFFRANCE</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">SICS-BCEAO - Créances en souffrance</div>
    </div>
    
    <div class="filters">
        <div class="filter-group">
            <label>Exercice</label>
            <select name="exercice" id="exercice">
                <?php for($y = 2020; $y <= date('Y')+1; $y++): ?>
                    <option value="<?= $y ?>" <?= $y == $exercice ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Trimestre</label>
            <select name="trimestre" id="trimestre">
                <option value="1" <?= $trimestre == 1 ? 'selected' : '' ?>>1er Trimestre</option>
                <option value="2" <?= $trimestre == 2 ? 'selected' : '' ?>>2ème Trimestre</option>
                <option value="3" <?= $trimestre == 3 ? 'selected' : '' ?>>3ème Trimestre</option>
                <option value="4" <?= $trimestre == 4 ? 'selected' : '' ?>>4ème Trimestre</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Mois</label>
            <select name="mois" id="mois">
                <?php for($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m == $mois ? 'selected' : '' ?>>
                        <?= str_pad($m, 2, '0', STR_PAD_LEFT) ?> - <?= date('F', mktime(0,0,0,$m,1)) ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="filter-group">
            <button class="btn btn-primary" onclick="appliquerFiltres()">Appliquer</button>
        </div>
        <div class="filter-group">
            <button class="btn" onclick="exporterPDF()" style="background:#f5f5f5;">📄 Exporter PDF</button>
        </div>
    </div>
    
    <div class="info-box">
        <strong>ⓘ Note :</strong> Cet état présente les crédits en souffrance classés par tranche de retard.
        Conformément à la réglementation BCEAO, les provisions doivent être constituées selon le taux applicable à chaque tranche.
    </div>
    
    <!-- Tableau principal -->
    <div class="section-card">
        <div class="section-title">📊 CRÉDITS EN SOUFFRANCE PAR TRANCHE</div>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>CODE</th>
                        <th>CRÉDITS EN SOUFFRANCE</th>
                        <th class="text-right">Crédits et Prêts en souffrance (Brut)</th>
                        <th class="text-right">Dépôts de garantie</th>
                        <th class="text-right">Soldes restants dus</th>
                        <th class="text-right">Provisions</th>
                        <th class="text-right">Crédits et Prêts en souffrance nets</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>B71</td>
                        <td class="text-left"><?= htmlspecialchars($tranches['B71']['libelle']) ?></td>
                        <td class="text-right"><?= number_format($tranches['B71']['montant_brut'], 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($tranches['B71']['depots_garantie'], 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($tranches['B71']['solde_restant'], 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($tranches['B71']['provisions'], 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($tranches['B71']['montant_net'], 0, ',', ' ') ?></td>
                    </tr>
                    <tr>
                        <td>B72</td>
                        <td class="text-left"><?= htmlspecialchars($tranches['B72']['libelle']) ?></td>
                        <td class="text-right"><?= number_format($tranches['B72']['montant_brut'], 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($tranches['B72']['depots_garantie'], 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($tranches['B72']['solde_restant'], 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($tranches['B72']['provisions'], 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($tranches['B72']['montant_net'], 0, ',', ' ') ?></td>
                    </tr>
                    <tr>
                        <td>B73</td>
                        <td class="text-left"><?= htmlspecialchars($tranches['B73']['libelle']) ?></td>
                        <td class="text-right"><?= number_format($tranches['B73']['montant_brut'], 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($tranches['B73']['depots_garantie'], 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($tranches['B73']['solde_restant'], 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($tranches['B73']['provisions'], 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($tranches['B73']['montant_net'], 0, ',', ' ') ?></td>
                    </tr>
                    <tr class="total-row">
                        <td><strong>TOTAL</strong></td>
                        <td class="text-left"><strong>Ensemble des créances en souffrance</strong></td>
                        <td class="text-right"><strong><?= number_format($total_brut, 0, ',', ' ') ?></strong></td>
                        <td class="text-right"><strong><?= number_format(0, 0, ',', ' ') ?></strong></td>
                        <td class="text-right"><strong><?= number_format($total_solde_restant, 0, ',', ' ') ?></strong></td>
                        <td class="text-right"><strong><?= number_format($total_provisions, 0, ',', ' ') ?></strong></td>
                        <td class="text-right"><strong><?= number_format($total_net, 0, ',', ' ') ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Détail des crédits en souffrance -->
    <div class="section-card">
        <div class="section-title">📋 DÉTAIL DES CRÉDITS EN SOUFFRANCE</div>
        <div style="overflow-x: auto;">
            <?php if(empty($details_credits)): ?>
                <div class="info-box">Aucun crédit en souffrance pour la période sélectionnée.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>N° Dossier</th>
                            <th>Date octroi</th>
                            <th class="text-right">Montant initial</th>
                            <th class="text-right">Encours actuel</th>
                            <th class="text-right">Montant impayé</th>
                            <th>Dernier impayé</th>
                            <th>Jours retard</th>
                            <th class="text-right">Provisions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($details_credits as $credit): 
                            $date_impaye = new DateTime($credit['date_dernier_impaye']);
                            $interval = $date_reference->diff($date_impaye);
                            $jours_retard = $interval->days;
                            
                            $retard_class = 'retard-30';
                            if ($jours_retard >= 90) $retard_class = 'retard-90';
                            if ($jours_retard >= 180) $retard_class = 'retard-180';
                            
                            // Récupération provisions
                            $prov_credit = 0;
                            try {
                                $stmtProv = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) as total FROM provisions WHERE credit_id = :credit_id AND statut = 'actif'");
                                $stmtProv->execute([':credit_id' => $credit['dossier_id']]);
                                $provResult = $stmtProv->fetch();
                                $prov_credit = $provResult['total'];
                            } catch (PDOException $e) {
                                $prov_credit = 0;
                            }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($credit['dossier_id']) ?></td>
                            <td><?= date('d/m/Y', strtotime($credit['date_octroi'])) ?></td>
                            <td class="text-right"><?= number_format($credit['montant_initial'], 0, ',', ' ') ?></td>
                            <td class="text-right"><?= number_format($credit['encours_actuel'], 0, ',', ' ') ?></td>
                            <td class="text-right"><?= number_format($credit['montant_impaye'], 0, ',', ' ') ?></td>
                            <td><?= date('d/m/Y', strtotime($credit['date_dernier_impaye'])) ?></td>
                            <td><span class="badge-retard <?= $retard_class ?>"><?= $jours_retard ?> jours</span></td>
                            <td class="text-right"><?= number_format($prov_credit, 0, ',', ' ') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Indicateurs de qualité -->
    <div class="section-card">
        <div class="section-title">📈 INDICATEURS DE QUALITÉ DU PORTEFEUILLE</div>
        <div class="info-box">
            <?php
            // Calcul du portefeuille total
            $portefeuille_total = 0;
            try {
                $stmtPort = $pdo->prepare("
                    SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
                    FROM dossiers d
                    LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e 
                    ON d.dossier_id = e.dossier_id
                    WHERE d.statut IN ('actif', 'approuve') AND d.date_octroi <= :date_fin
                ");
                $stmtPort->execute([':date_fin' => $date_fin_periode]);
                $resultPort = $stmtPort->fetch();
                $portefeuille_total = $resultPort['total'];
            } catch (PDOException $e) {
                $portefeuille_total = 0;
            }
            
            $par30 = ($portefeuille_total > 0) ? ($tranches['B71']['solde_restant'] + $tranches['B72']['solde_restant'] + $tranches['B73']['solde_restant']) / $portefeuille_total * 100 : 0;
            $par90 = ($portefeuille_total > 0) ? ($tranches['B72']['solde_restant'] + $tranches['B73']['solde_restant']) / $portefeuille_total * 100 : 0;
            $par180 = ($portefeuille_total > 0) ? $tranches['B73']['solde_restant'] / $portefeuille_total * 100 : 0;
            $taux_couverture = ($tranches['B71']['solde_restant'] + $tranches['B72']['solde_restant'] + $tranches['B73']['solde_restant'] > 0) ? 
                               $total_provisions / ($tranches['B71']['solde_restant'] + $tranches['B72']['solde_restant'] + $tranches['B73']['solde_restant']) * 100 : 0;
            ?>
            <strong>PAR 30 :</strong> <?= number_format($par30, 2) ?>% (Norme ≤ 5%)<br>
            <strong>PAR 90 :</strong> <?= number_format($par90, 2) ?>% (Norme ≤ 3%)<br>
            <strong>PAR 180 :</strong> <?= number_format($par180, 2) ?>% (Norme ≤ 2%)<br>
            <strong>Taux de couverture des créances en souffrance :</strong> <?= number_format($taux_couverture, 2) ?>% (Norme ≥ 40%)<br>
            <strong>Portefeuille total :</strong> <?= number_format($portefeuille_total, 0, ',', ' ') ?> FCFA
        </div>
    </div>
    
    <div class="footer">
        Document généré le <?= date('d/m/Y à H:i:s') ?> - Données extraites de la base Mandigo<br>
        Période : <?= $exercice ?> - <?= $trimestre ?>ème trimestre (arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
    </div>
</div>

<script>
    function appliquerFiltres() {
        let exercice = document.getElementById('exercice').value;
        let trimestre = document.getElementById('trimestre').value;
        let mois = document.getElementById('mois').value;
        window.location.href = 'DIMF_2010.php?exercice=' + exercice + '&trimestre=' + trimestre + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>