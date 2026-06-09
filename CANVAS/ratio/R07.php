<?php
// R07.php - Constitution de la réserve générale
// Norme BCEAO: ≥ 15% (doit être supérieur ou égal à 15% du résultat bénéficiaire)

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

// Récupérer l'année et le mois
$exercice = isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y');
$mois = isset($_GET['mois']) ? (int)$_GET['mois'] : date('m');
$periode = $exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT);
$date_fin_periode = $exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01';
$date_fin_periode = date('Y-m-t', strtotime($date_fin_periode));
$date_debut_exercice = $exercice . '-01-01';
$date_fin_exercice = $exercice . '-12-31';

// ============================================================
// A - BASE DE CALCUL (Résultat de l'exercice)
// ============================================================

// L80 - Résultat excédentaire de l'exercice (bénéfice)
$resultatExercice = 0;
$resultatDeficit = 0;

try {
    // Calcul du résultat à partir des comptes de produits et charges
    $stmtResultat = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN pc.classe_compte = '7' THEN e.montant_credit - e.montant_debit ELSE 0 END), 0) as total_produits,
            COALESCE(SUM(CASE WHEN pc.classe_compte = '6' THEN e.montant_debit - e.montant_credit ELSE 0 END), 0) as total_charges
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte IN ('6', '7')
          AND e.date_ecriture BETWEEN :date_debut AND :date_fin
    ");
    $stmtResultat->execute([
        ':date_debut' => $date_debut_exercice,
        ':date_fin' => $date_fin_exercice
    ]);
    $resultResultat = $stmtResultat->fetch();
    
    $resultatBrut = $resultResultat['total_produits'] - $resultResultat['total_charges'];
    
    if ($resultatBrut > 0) {
        $resultatExercice = $resultatBrut;
        $resultatDeficit = 0;
    } else {
        $resultatExercice = 0;
        $resultatDeficit = abs($resultatBrut);
    }
} catch (PDOException $e) {
    $resultatExercice = 0;
    $resultatDeficit = 0;
}

// L70 - Report à nouveau (positif ou négatif)
$reportNouveauPositif = 0;
$reportNouveauNegatif = 0;

try {
    $stmtReport = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde_report
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '11%'  -- Comptes de report à nouveau
          AND e.date_ecriture <= :date_fin
    ");
    $stmtReport->execute([':date_fin' => $date_fin_periode]);
    $resultReport = $stmtReport->fetch();
    
    $soldeReport = $resultReport['solde_report'];
    if ($soldeReport > 0) {
        $reportNouveauPositif = $soldeReport;
        $reportNouveauNegatif = 0;
    } else {
        $reportNouveauPositif = 0;
        $reportNouveauNegatif = abs($soldeReport);
    }
} catch (PDOException $e) {
    $reportNouveauPositif = 0;
    $reportNouveauNegatif = 0;
}

// Base = Résultat bénéficiaire (L80) - Report à nouveau déficitaire (L70 négatif)
$baseCalcul = $resultatExercice - $reportNouveauNegatif;
if ($baseCalcul < 0) {
    $baseCalcul = 0;
}

// Montant minimal de réserve à constituer (15% de la base)
$montantReserveMinimal = $baseCalcul * 0.15;

// ============================================================
// B - DOTATION ANNUELLE DE LA RÉSERVE GÉNÉRALE
// ============================================================

// Récupération de la dotation effectuée (mouvement créditeur du compte 106)
$dotationReserve = 0;
$detailsDotation = [];

try {
    $stmtDotation = $pdo->prepare("
        SELECT 
            e.date_ecriture,
            e.numero_piece,
            e.libelle_ecriture,
            e.montant_credit as montant
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '106%'  -- Compte de réserves (106)
          AND e.montant_credit > 0
          AND e.date_ecriture BETWEEN :date_debut AND :date_fin
        ORDER BY e.date_ecriture DESC
    ");
    $stmtDotation->execute([
        ':date_debut' => $date_debut_exercice,
        ':date_fin' => $date_fin_exercice
    ]);
    $detailsDotation = $stmtDotation->fetchAll();
    
    // Somme des dotations
    $stmtTotalDotation = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit), 0) as total_dotation
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '106%'
          AND e.montant_credit > 0
          AND e.date_ecriture BETWEEN :date_debut AND :date_fin
    ");
    $stmtTotalDotation->execute([
        ':date_debut' => $date_debut_exercice,
        ':date_fin' => $date_fin_exercice
    ]);
    $resultDotation = $stmtTotalDotation->fetch();
    $dotationReserve = $resultDotation['total_dotation'];
} catch (PDOException $e) {
    $dotationReserve = 0;
    $detailsDotation = [];
}

// Si pas de données dans ecritures_comptables, on vérifie dans la table capital
if ($dotationReserve == 0) {
    try {
        $stmtCapital = $pdo->prepare("
            SELECT COALESCE(SUM(montant), 0) as total_reserve
            FROM capital
            WHERE statut = 'valide'
              AND libelle LIKE '%réserve%'
              AND YEAR(date_creation) = :exercice
        ");
        $stmtCapital->execute([':exercice' => $exercice]);
        $resultCapital = $stmtCapital->fetch();
        $dotationReserve = $resultCapital['total_reserve'];
    } catch (PDOException $e) {
        $dotationReserve = 0;
    }
}

// Solde actuel de la réserve générale (cumul)
$soldeReserveGeneral = 0;
try {
    $stmtSolde = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde_reserve
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '106%'
          AND e.date_ecriture <= :date_fin
    ");
    $stmtSolde->execute([':date_fin' => $date_fin_periode]);
    $resultSolde = $stmtSolde->fetch();
    $soldeReserveGeneral = $resultSolde['solde_reserve'];
} catch (PDOException $e) {
    $soldeReserveGeneral = 0;
}

// ============================================================
// CALCUL DU RATIO R07
// ============================================================

if ($baseCalcul <= 0) {
    $ratioR07 = 0;
    $statutBase = "ND"; // Non Déterminé (pas de bénéfice)
} else {
    $ratioR07 = $dotationReserve / $baseCalcul;
}

// Norme : ≥ 15%
$normeMin = 0.15;
$normeMax = null;

if ($baseCalcul <= 0) {
    $conformite = 'N/A - Pas de bénéfice';
} else {
    $conformite = ($ratioR07 >= $normeMin) ? 'CONFORME' : 'NON_CONFORME';
}

// Calcul du pourcentage atteint
$pourcentageAtteint = ($baseCalcul > 0) ? ($dotationReserve / $baseCalcul) * 100 : 0;

// Récupération du capital social pour information
$capitalSocial = 0;
try {
    $stmtCapital = $pdo->prepare("
        SELECT COALESCE(SUM(montant), 0) as capital
        FROM capital
        WHERE statut = 'valide'
          AND mode_paiement IN ('BANQUE', 'CASH')
    ");
    $stmtCapital->execute();
    $resultCapital = $stmtCapital->fetch();
    $capitalSocial = $resultCapital['capital'];
} catch (PDOException $e) {
    $capitalSocial = 0;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>R07 - Constitution de la réserve générale</title>
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
        
        .ratio-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .ratio-title {
            font-size: 1.1rem;
            color: #555;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .ratio-value-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .ratio-value {
            text-align: center;
        }
        
        .ratio-value .value {
            font-size: 3rem;
            font-weight: bold;
        }
        
        .ratio-value .label {
            color: #777;
            font-size: 0.85rem;
        }
        
        .conforme {
            color: #2e7d32;
        }
        
        .non-conforme {
            color: #c62828;
        }
        
        .norme {
            background: #f5f5f5;
            padding: 10px 20px;
            border-radius: 8px;
            text-align: center;
        }
        
        .norme .title {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .norme .range {
            font-size: 1.3rem;
            font-weight: bold;
            color: #1a3a5c;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.85rem;
        }
        
        .status-conforme {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .status-non-conforme {
            background: #ffebee;
            color: #c62828;
        }
        
        .status-na {
            background: #e3f2fd;
            color: #1565c0;
        }
        
        .data-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        
        .data-table h3 {
            padding: 15px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
            font-size: 1rem;
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
        
        .warning {
            background: #fff3e0;
            border-left: 4px solid #ff9800;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        
        .info {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        .progress-bar {
            background: #e0e0e0;
            border-radius: 10px;
            height: 20px;
            overflow: hidden;
            margin: 10px 0;
        }
        
        .progress-fill {
            background: linear-gradient(90deg, #2e7d32, #4caf50);
            height: 100%;
            border-radius: 10px;
            transition: width 0.5s ease;
            text-align: center;
            color: white;
            font-size: 0.7rem;
            line-height: 20px;
        }
        
        .progress-fill.non-conforme {
            background: linear-gradient(90deg, #c62828, #f44336);
        }
        
        @media (max-width: 768px) {
            .ratio-value-container {
                flex-direction: column;
                align-items: stretch;
            }
            
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
        <h1>R07 - Constitution de la réserve générale</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">Norme BCEAO : ≥ 15% du bénéfice</div>
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
            <label>Mois</label>
            <select name="mois" id="mois">
                <?php for($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m == $mois ? 'selected' : '' ?>>
                        <?= str_pad($m, 2, '0', STR_PAD_LEFT) ?> - 
                        <?= date('F', mktime(0,0,0,$m,1)) ?>
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
    
    <?php if($baseCalcul <= 0): ?>
    <div class="warning">
        ⚠️ <strong>Information :</strong> L'institution n'a pas réalisé de bénéfice pour l'exercice <?= $exercice ?> 
        (Résultat bénéficiaire: <?= number_format($resultatExercice, 0, ',', ' ') ?> FCFA, 
        Report à nouveau déficitaire: <?= number_format($reportNouveauNegatif, 0, ',', ' ') ?> FCFA).<br>
        La constitution de la réserve générale n'est pas exigée en l'absence de bénéfice distribuable.
    </div>
    <?php endif; ?>
    
    <div class="ratio-card">
        <div class="ratio-title">📊 Ratio R07 - Constitution de la réserve générale</div>
        <div class="ratio-value-container">
            <div class="ratio-value">
                <div class="value <?= ($baseCalcul > 0 && $ratioR07 >= $normeMin) ? 'conforme' : (($baseCalcul > 0) ? 'non-conforme' : '') ?>">
                    <?= number_format($pourcentageAtteint, 2) ?>%
                </div>
                <div class="label">Dotation / Bénéfice</div>
            </div>
            <div class="norme">
                <div class="title">Norme réglementaire</div>
                <div class="range">Dotation ≥ 15% du bénéfice</div>
                <div class="label">Conformité requise</div>
            </div>
            <div>
                <span class="status-badge <?= ($baseCalcul <= 0) ? 'status-na' : (($ratioR07 >= $normeMin) ? 'status-conforme' : 'status-non-conforme') ?>">
                    <?= ($baseCalcul <= 0) ? 'N/A - Pas de bénéfice' : $conformite ?>
                </span>
            </div>
        </div>
        <?php if($baseCalcul > 0): ?>
        <div class="progress-bar" style="margin-top: 20px;">
            <div class="progress-fill <?= ($ratioR07 >= $normeMin) ? '' : 'non-conforme' ?>" 
                 style="width: <?= min($pourcentageAtteint, 100) ?>%;">
                <?= number_format($pourcentageAtteint, 1) ?>%
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="data-table">
        <h3>📋 A - Base de calcul (Résultat de l'exercice)</h3>
        <table>
            <thead>
                <tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>L80<\/td>
                    <td>Résultat excédentaire de l'exercice (bénéfice) (A) </td>
                    <td class="text-right <?= $resultatExercice > 0 ? 'conforme' : '' ?>">
                        <?= number_format($resultatExercice, 0, ',', ' ') ?>
                    </td>
                </tr>
                <tr>
                    <td>L70</td>
                    <td>Report à nouveau déficitaire (B) </td>
                    <td class="text-right <?= $reportNouveauNegatif > 0 ? 'non-conforme' : '' ?>">
                        <?= number_format($reportNouveauNegatif, 0, ',', ' ') ?>
                    </td>
                </tr>
                <tr style="background:#f0f7ff; font-weight:bold;">
                    <td colspan="2">BASE = A - B</td>
                    <td class="text-right"><?= number_format($baseCalcul, 0, ',', ' ') ?></td>
                </tr>
            </tbody>
        </table>
        <div style="padding: 10px 15px; background: #f8f9fa; font-size: 0.8rem;">
            📌 <strong>Note :</strong> La base de calcul est le résultat bénéficiaire de l'exercice, diminué du report à nouveau déficitaire.
            <br>📌 <strong>Montant minimal à doter :</strong> <?= number_format($montantReserveMinimal, 0, ',', ' ') ?> FCFA (15% de la base)
        </div>
    </div>
    
    <div class="data-table">
        <h3>💰 B - Dotation annuelle de la réserve générale</h3>
        <table>
            <thead>
                <tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>Compte 106</td>
                    <td>Dotation annuelle de la réserve générale (C)</td>
                    <td class="text-right"><?= number_format($dotationReserve, 0, ',', ' ') ?></td>
                </tr>
                <tr style="background:#f0f7ff; font-weight:bold;">
                    <td colspan="2">TOTAL Dotation</td>
                    <td class="text-right"><?= number_format($dotationReserve, 0, ',', ' ') ?></td>
                </tr>
            </tbody>
        </table>
        <div style="padding: 10px 15px; background: #f8f9fa; font-size: 0.8rem;">
            📌 <strong>Solde actuel de la réserve générale :</strong> <?= number_format($soldeReserveGeneral, 0, ',', ' ') ?> FCFA
        </div>
    </div>
    
    <?php if(!empty($detailsDotation)): ?>
    <div class="data-table">
        <h3>📝 Détail des écritures de dotation à la réserve générale</h3>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>N° pièce</th>
                    <th>Libellé</th>
                    <th class="text-right">Montant (FCFA)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($detailsDotation as $dotation): ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($dotation['date_ecriture'])) ?></td>
                    <td><?= htmlspecialchars($dotation['numero_piece'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($dotation['libelle_ecriture']) ?></td>
                    <td class="text-right"><?= number_format($dotation['montant'], 0, ',', ' ') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <div class="data-table">
        <h3>📊 Synthèse du ratio R07</h3>
        <table>
            <tbody>
                <tr>
                    <td style="width: 60%;"><strong>Base de calcul (Bénéfice - Report déficitaire)</strong></td>
                    <td class="text-right"><strong><?= number_format($baseCalcul, 0, ',', ' ') ?> FCFA</strong></td>
                </tr>
                <tr>
                    <td><strong>Dotation à la réserve générale</strong></td>
                    <td class="text-right"><strong><?= number_format($dotationReserve, 0, ',', ' ') ?> FCFA</strong></td>
                </tr>
                <tr style="background:#f0f7ff;">
                    <td><strong>RATIO R07 = Dotation / Base</strong></td>
                    <td class="text-right"><strong><?= number_format($pourcentageAtteint, 2) ?>%</strong></td>
                </tr>
                <tr>
                    <td><strong>Objectif minimum (norme BCEAO)</strong></td>
                    <td class="text-right"><strong>15%</strong></td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="data-table">
        <h3>📖 Interprétation du ratio R07</h3>
        <div style="padding: 15px; line-height: 1.6;">
            <p><strong>Ratio calculé :</strong> <?= number_format($pourcentageAtteint, 2) ?>%</p>
            <p><strong>Formule :</strong> R07 = (Dotation à la réserve générale) / (Résultat bénéficiaire - Report à nouveau déficitaire)</p>
            <p><strong>Norme BCEAO :</strong> La dotation annuelle doit être <strong>au moins égale à 15%</strong> du bénéfice distribuable.</p>
            <p><strong>Interprétation :</strong></p>
            <ul style="margin-left: 25px; margin-top: 10px;">
                <?php if($baseCalcul <= 0): ?>
                    <li style="color:#1565c0;">ℹ️ Aucun bénéfice distribuable pour l'exercice <?= $exercice ?>.</li>
                    <li>La constitution de la réserve générale n'est pas exigée en l'absence de bénéfice.</li>
                    <li>L'institution doit s'efforcer de retrouver la rentabilité lors des prochains exercices.</li>
                <?php elseif($ratioR07 >= $normeMin): ?>
                    <li style="color:#2e7d32;">✓ Le ratio est <strong>CONFORME</strong> à la réglementation BCEAO.</li>
                    <li>La dotation annuelle représente <?= number_format($pourcentageAtteint, 2) ?>% du bénéfice, soit au moins 15% requis.</li>
                    <li>L'institution constitue correctement ses réserves.</li>
                <?php else: ?>
                    <li style="color:#c62828;">✗ Le ratio est <strong>NON CONFORME</strong> à la réglementation BCEAO.</li>
                    <li>La dotation annuelle représente seulement <?= number_format($pourcentageAtteint, 2) ?>% du bénéfice, alors que 15% sont requis.</li>
                    <li>L'institution doit augmenter sa dotation à la réserve générale d'au moins <?= number_format($montantReserveMinimal - $dotationReserve, 0, ',', ' ') ?> FCFA.</li>
                    <li>Il est recommandé de :</li>
                    <ul style="margin-left: 25px;">
                        <li>Augmenter la dotation à la réserve générale lors de l'affectation des résultats</li>
                        <li>Respecter le minimum réglementaire de 15%</li>
                    </ul>
                <?php endif; ?>
            </ul>
            <?php if($capitalSocial > 0): ?>
            <p style="margin-top: 15px; font-size: 0.9rem; color: #666;">
                <strong>Note :</strong> Capital social de l'institution : <?= number_format($capitalSocial, 0, ',', ' ') ?> FCFA
            </p>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="footer">
        Document généré le <?= date('d/m/Y à H:i:s') ?> - Données extraites de la base Mandigo<br>
        Période : <?= $periode ?> (exercice <?= $exercice ?>)
    </div>
</div>

<script>
    function appliquerFiltres() {
        let exercice = document.getElementById('exercice').value;
        let mois = document.getElementById('mois').value;
        window.location.href = 'R07.php?exercice=' + exercice + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>