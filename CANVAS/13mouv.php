<?php
// 13-MouvementsActifs.php - Acquisitions et cessions d'actifs
// Suivi des immobilisations (acquisitions, cessions, amortissements)

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

// Récupérer l'exercice
$exercice = isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y');
$date_debut_exercice = $exercice . '-01-01';
$date_fin_exercice = $exercice . '-12-31';

// ============================================================
// RÉCUPÉRATION DES DONNÉES D'IMMOBILISATIONS
// ============================================================

// Catégories d'immobilisations
$categories = [
    'brevets_logiciels' => [
        'libelle' => 'Brevets, licences, logiciels et droits similaires',
        'comptes' => ['205', '206', '207'] // Comptes pour logiciels et brevets
    ],
    'recherche_developpement' => [
        'libelle' => 'Recherche et développement',
        'comptes' => ['203']
    ],
    'terrains' => [
        'libelle' => 'Terrains',
        'comptes' => ['211']
    ],
    'batiments' => [
        'libelle' => 'Bâtiments',
        'comptes' => ['212', '213']
    ],
    'installations_agencements' => [
        'libelle' => 'Installations et agencements',
        'comptes' => ['215', '2184']
    ],
    'mobilier_bureau' => [
        'libelle' => 'Mobilier de bureau',
        'comptes' => ['2181', '2183']
    ],
    'materiel_informatique' => [
        'libelle' => 'Matériel informatique',
        'comptes' => ['2182']
    ],
    'materiel_transport' => [
        'libelle' => 'Matériel de transport',
        'comptes' => ['2185']
    ],
    'autres_materiels' => [
        'libelle' => 'Autres matériels',
        'comptes' => ['2186', '2187', '2188']
    ]
];

// Récupération des données pour chaque catégorie
$data = [];

foreach ($categories as $key => $category) {
    $data[$key] = [
        'libelle' => $category['libelle'],
        'montant_ouverture' => 0,
        'acquisitions' => 0,
        'cessions' => 0,
        'montant_cloture' => 0,
        'details' => []
    ];
    
    // Pour chaque compte de la catégorie
    foreach ($category['comptes'] as $compte) {
        // Solde à l'ouverture (immobilisations brutes)
        $stmtOuverture = $pdo->prepare("
            SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as solde
            FROM ecritures_comptables e
            WHERE e.compte_general LIKE :compte
              AND e.date_ecriture < :date_debut
        ");
        $stmtOuverture->execute([
            ':compte' => $compte . '%',
            ':date_debut' => $date_debut_exercice
        ]);
        $resultOuverture = $stmtOuverture->fetch();
        $data[$key]['montant_ouverture'] += $resultOuverture['solde'];
        
        // Acquisitions de l'année (mouvements débiteurs)
        $stmtAcquisitions = $pdo->prepare("
            SELECT COALESCE(SUM(e.montant_debit), 0) as total
            FROM ecritures_comptables e
            WHERE e.compte_general LIKE :compte
              AND e.date_ecriture BETWEEN :date_debut AND :date_fin
              AND e.montant_debit > 0
        ");
        $stmtAcquisitions->execute([
            ':compte' => $compte . '%',
            ':date_debut' => $date_debut_exercice,
            ':date_fin' => $date_fin_exercice
        ]);
        $resultAcquisitions = $stmtAcquisitions->fetch();
        $data[$key]['acquisitions'] += $resultAcquisitions['total'];
        
        // Cessions de l'année (mouvements créditeurs)
        $stmtCessions = $pdo->prepare("
            SELECT COALESCE(SUM(e.montant_credit), 0) as total
            FROM ecritures_comptables e
            WHERE e.compte_general LIKE :compte
              AND e.date_ecriture BETWEEN :date_debut AND :date_fin
              AND e.montant_credit > 0
        ");
        $stmtCessions->execute([
            ':compte' => $compte . '%',
            ':date_debut' => $date_debut_exercice,
            ':date_fin' => $date_fin_exercice
        ]);
        $resultCessions = $stmtCessions->fetch();
        $data[$key]['cessions'] += $resultCessions['total'];
    }
    
    // Calcul du montant à la clôture
    $data[$key]['montant_cloture'] = $data[$key]['montant_ouverture'] 
                                   + $data[$key]['acquisitions'] 
                                   - $data[$key]['cessions'];
}

// Totaux généraux
$total_ouverture = 0;
$total_acquisitions = 0;
$total_cessions = 0;
$total_cloture = 0;

foreach ($data as $key => $item) {
    $total_ouverture += $item['montant_ouverture'];
    $total_acquisitions += $item['acquisitions'];
    $total_cessions += $item['cessions'];
    $total_cloture += $item['montant_cloture'];
}

// Récupération des détails des acquisitions (pour affichage)
$detailsAcquisitions = [];
try {
    $stmtDetails = $pdo->prepare("
        SELECT 
            e.date_ecriture,
            e.numero_piece,
            e.libelle_ecriture,
            e.compte_general,
            pc.libelle_compte,
            e.montant_debit as montant,
            e.montant_credit
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE (e.compte_general LIKE '21%' OR e.compte_general LIKE '22%' OR e.compte_general LIKE '23%')
          AND e.date_ecriture BETWEEN :date_debut AND :date_fin
          AND e.montant_debit > 0
        ORDER BY e.date_ecriture DESC
        LIMIT 50
    ");
    $stmtDetails->execute([
        ':date_debut' => $date_debut_exercice,
        ':date_fin' => $date_fin_exercice
    ]);
    $detailsAcquisitions = $stmtDetails->fetchAll();
} catch (PDOException $e) {
    $detailsAcquisitions = [];
}

// Récupération des cessions
$detailsCessions = [];
try {
    $stmtCessionsDetails = $pdo->prepare("
        SELECT 
            e.date_ecriture,
            e.numero_piece,
            e.libelle_ecriture,
            e.compte_general,
            pc.libelle_compte,
            e.montant_credit as montant
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE (e.compte_general LIKE '21%' OR e.compte_general LIKE '22%' OR e.compte_general LIKE '23%')
          AND e.date_ecriture BETWEEN :date_debut AND :date_fin
          AND e.montant_credit > 0
        ORDER BY e.date_ecriture DESC
        LIMIT 50
    ");
    $stmtCessionsDetails->execute([
        ':date_debut' => $date_debut_exercice,
        ':date_fin' => $date_fin_exercice
    ]);
    $detailsCessions = $stmtCessionsDetails->fetchAll();
} catch (PDOException $e) {
    $detailsCessions = [];
}

// Récupération des amortissements
$total_amortissements_exercice = 0;
try {
    $stmtAmort = $pdo->prepare("
        SELECT COALESCE(SUM(a.dotation_mois), 0) as total_amort
        FROM amortissements a
        WHERE a.exercice = :exercice
    ");
    $stmtAmort->execute([':exercice' => $exercice]);
    $resultAmort = $stmtAmort->fetch();
    $total_amortissements_exercice = $resultAmort['total_amort'];
} catch (PDOException $e) {
    $total_amortissements_exercice = 0;
}

// Calcul de la valeur nette des immobilisations
$valeur_nette_totale = $total_cloture - $total_amortissements_exercice;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>13 - Acquisitions et cessions d'actifs</title>
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
            max-width: 1200px;
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
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .footer {
            text-align: center;
            padding: 20px;
            color: #777;
            font-size: 0.8rem;
        }
        
        .total-row {
            background: #e8f5e9;
            font-weight: bold;
        }
        
        .subtotal-row {
            background: #f0f7ff;
            font-weight: bold;
        }
        
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin: 15px;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        .acquisition {
            color: #2e7d32;
        }
        
        .cession {
            color: #c62828;
        }
        
        @media (max-width: 768px) {
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            
            table {
                font-size: 0.8rem;
            }
            
            th, td {
                padding: 8px 10px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>13 - Acquisitions et cessions d'actifs</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">Formation brute du capital fixe - Exercice <?= $exercice ?></div>
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
            <button class="btn btn-primary" onclick="appliquerFiltres()">Appliquer</button>
        </div>
        <div class="filter-group">
            <button class="btn" onclick="exporterPDF()" style="background:#f5f5f5;">📄 Exporter PDF</button>
        </div>
    </div>
    
    <!-- Tableau principal des immobilisations -->
    <div class="section-card">
        <div class="section-title">🏗️ FORMATION BRUTE DU CAPITAL FIXE (En FCFA)</div>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Catégorie d'immobilisation</th>
                        <th class="text-right">Montant à l'ouverture</th>
                        <th class="text-right">Acquisitions / Apports</th>
                        <th class="text-right">Cessions / Scissions</th>
                        <th class="text-right">Montant à la clôture</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $key => $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['libelle']) ?></td>
                        <td class="text-right"><?= number_format($item['montant_ouverture'], 0, ',', ' ') ?></td>
                        <td class="text-right acquisition"><?= number_format($item['acquisitions'], 0, ',', ' ') ?></td>
                        <td class="text-right cession"><?= number_format($item['cessions'], 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($item['montant_cloture'], 0, ',', ' ') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td><strong>TOTAL</strong></td>
                        <td class="text-right"><strong><?= number_format($total_ouverture, 0, ',', ' ') ?></strong></td>
                        <td class="text-right"><strong><?= number_format($total_acquisitions, 0, ',', ' ') ?></strong></td>
                        <td class="text-right"><strong><?= number_format($total_cessions, 0, ',', ' ') ?></strong></td>
                        <td class="text-right"><strong><?= number_format($total_cloture, 0, ',', ' ') ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Récapitulatif des amortissements -->
    <div class="section-card">
        <div class="section-title">📉 AMORTISSEMENTS DE L'EXERCICE</div>
        <div class="info-box">
            <table style="width: 100%;">
                <tr>
                    <td style="width: 50%;"><strong>Amortissements cumulés de l'exercice</strong></td>
                    <td class="text-right"><strong><?= number_format($total_amortissements_exercice, 0, ',', ' ') ?> FCFA</strong></td>
                </tr>
                <tr>
                    <td><strong>Valeur brute des immobilisations (clôture)</strong></td>
                    <td class="text-right"><?= number_format($total_cloture, 0, ',', ' ') ?> FCFA</td>
                </tr>
                <tr class="total-row">
                    <td><strong>Valeur nette des immobilisations (clôture)</strong></td>
                    <td class="text-right"><strong><?= number_format($valeur_nette_totale, 0, ',', ' ') ?> FCFA</strong></td>
                </tr>
            </table>
        </div>
    </div>
    
    <!-- Détail des acquisitions de l'exercice -->
    <div class="section-card">
        <div class="section-title">🟢 DÉTAIL DES ACQUISITIONS DE L'EXERCICE</div>
        <div style="overflow-x: auto;">
            <?php if(empty($detailsAcquisitions)): ?>
                <div class="info-box">Aucune acquisition enregistrée pour l'exercice <?= $exercice ?>.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>N° pièce</th>
                            <th>Libellé</th>
                            <th>Compte</th>
                            <th class="text-right">Montant (FCFA)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($detailsAcquisitions as $acq): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($acq['date_ecriture'])) ?></td>
                            <td><?= htmlspecialchars($acq['numero_piece'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($acq['libelle_ecriture']) ?></td>
                            <td><?= htmlspecialchars($acq['compte_general'] . ' - ' . substr($acq['libelle_compte'], 0, 30)) ?></td>
                            <td class="text-right acquisition"><?= number_format($acq['montant'], 0, ',', ' ') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Détail des cessions de l'exercice -->
    <div class="section-card">
        <div class="section-title">🔴 DÉTAIL DES CESSIONS DE L'EXERCICE</div>
        <div style="overflow-x: auto;">
            <?php if(empty($detailsCessions)): ?>
                <div class="info-box">Aucune cession enregistrée pour l'exercice <?= $exercice ?>.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>N° pièce</th>
                            <th>Libellé</th>
                            <th>Compte</th>
                            <th class="text-right">Montant (FCFA)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($detailsCessions as $ces): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($ces['date_ecriture'])) ?></td>
                            <td><?= htmlspecialchars($ces['numero_piece'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($ces['libelle_ecriture']) ?></td>
                            <td><?= htmlspecialchars($ces['compte_general'] . ' - ' . substr($ces['libelle_compte'], 0, 30)) ?></td>
                            <td class="text-right cession"><?= number_format($ces['montant'], 0, ',', ' ') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Graphique d'évolution (style simple) -->
    <div class="section-card">
        <div class="section-title">📈 ÉVOLUTION DES IMMOBILISATIONS</div>
        <div class="info-box">
            <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                <div style="flex: 1;">
                    <strong>Ouverture :</strong> <?= number_format($total_ouverture, 0, ',', ' ') ?> FCFA
                </div>
                <div style="flex: 1; color: #2e7d32;">
                    <strong>+ Acquisitions :</strong> <?= number_format($total_acquisitions, 0, ',', ' ') ?> FCFA
                </div>
                <div style="flex: 1; color: #c62828;">
                    <strong>- Cessions :</strong> <?= number_format($total_cessions, 0, ',', ' ') ?> FCFA
                </div>
                <div style="flex: 1;">
                    <strong>= Clôture :</strong> <?= number_format($total_cloture, 0, ',', ' ') ?> FCFA
                </div>
            </div>
            <div style="margin-top: 20px;">
                <div style="background: #e0e0e0; border-radius: 10px; height: 30px; overflow: hidden;">
                    <?php 
                    $max_value = max($total_ouverture, $total_cloture, 1);
                    $ouverture_width = ($total_ouverture / $max_value) * 100;
                    $cloture_width = ($total_cloture / $max_value) * 100;
                    ?>
                    <div style="float: left; width: <?= $ouverture_width ?>%; background: #1a3a5c; height: 100%; text-align: center; color: white; line-height: 30px; font-size: 0.7rem;">
                        Ouverture
                    </div>
                    <div style="float: left; width: <?= $cloture_width - $ouverture_width ?>%; background: #4caf50; height: 100%; text-align: center; color: white; line-height: 30px; font-size: 0.7rem;">
                        + Acquisitions
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="footer">
        Document généré le <?= date('d/m/Y à H:i:s') ?> - Données extraites de la base Mandigo<br>
        Exercice : <?= $exercice ?>
    </div>
</div>

<script>
    function appliquerFiltres() {
        let exercice = document.getElementById('exercice').value;
        window.location.href = '13-MouvementsActifs.php?exercice=' + exercice;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>