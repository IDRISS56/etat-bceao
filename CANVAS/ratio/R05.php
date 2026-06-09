<?php
// R05.php - Norme de liquidité
// Norme BCEAO: ≥ 1 (doit être supérieur ou égal à 1)

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

// ============================================================
// A - VALEURS RÉALISABLES ET DISPONIBLES (actifs à court terme < 3 mois)
// ============================================================

// Calcul des disponibilités (caisse + banque)
$disponibilites = 0;
try {
    // Récupération du solde des caisses
    $stmtCaisse = $pdo->prepare("
        SELECT COALESCE(SUM(solde_actuel), 0) as total_caisse
        FROM caisses
        WHERE statut = 'ouverte'
    ");
    $stmtCaisse->execute();
    $resultCaisse = $stmtCaisse->fetch();
    $disponibilites += $resultCaisse['total_caisse'];
} catch (PDOException $e) {
    $disponibilites = 0;
}

// Comptes ordinaires débiteurs (A12) - encours des comptes débiteurs
$comptesDebiteurs = 0;
try {
    $stmtComptes = $pdo->prepare("
        SELECT COALESCE(SUM(solde), 0) as total_debiteurs
        FROM comptes
        WHERE statut = 'actif' AND solde > 0
    ");
    $stmtComptes->execute();
    $resultComptes = $stmtComptes->fetch();
    $comptesDebiteurs = $resultComptes['total_debiteurs'];
} catch (PDOException $e) {
    $comptesDebiteurs = 0;
}

// Crédits à court terme (B2D) - échéances < 3 mois
$creditsCourtTerme = 0;
try {
    $stmtCredits = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant), 0) as total_credits_ct
        FROM echeances e
        INNER JOIN dossiers d ON e.dossier_id = d.dossier_id
        WHERE e.statut = 'attente'
          AND e.date_echeance <= DATE_ADD(:date_fin, INTERVAL 3 MONTH)
          AND d.statut IN ('actif', 'approuve')
    ");
    $stmtCredits->execute([':date_fin' => $date_fin_periode]);
    $resultCredits = $stmtCredits->fetch();
    $creditsCourtTerme = $resultCredits['total_credits_ct'];
} catch (PDOException $e) {
    $creditsCourtTerme = 0;
}

// Titres de placement (C10)
$titresPlacement = 0;
// À adapter selon votre structure

// Créances rattachées (A60, B65, C55)
$creancesRattachees = 0;
try {
    $stmtCreances = $pdo->prepare("
        SELECT COALESCE(SUM(montant_debit - montant_credit), 0) as total_creances
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '46%'  -- Comptes de créances rattachées
          AND e.date_ecriture <= :date_fin
    ");
    $stmtCreances->execute([':date_fin' => $date_fin_periode]);
    $resultCreances = $stmtCreances->fetch();
    $creancesRattachees = $resultCreances['total_creances'];
} catch (PDOException $e) {
    $creancesRattachees = 0;
}

// TOTAL A - Valeurs réalisables et disponibles
$montantA = $disponibilites + $comptesDebiteurs + $creditsCourtTerme + $titresPlacement + $creancesRattachees;

// ============================================================
// B - PASSIF EXIGIBLE (dettes à court terme < 3 mois)
// ============================================================

// Comptes ordinaires créditeurs (G10) - dépôts clients
$depotsClients = 0;
try {
    $stmtDepots = $pdo->prepare("
        SELECT COALESCE(SUM(solde), 0) as total_depots
        FROM comptes
        WHERE statut = 'actif' AND solde < 0
    ");
    $stmtDepots->execute();
    $resultDepots = $stmtDepots->fetch();
    $depotsClients = abs($resultDepots['total_depots']);
} catch (PDOException $e) {
    $depotsClients = 0;
}

// Dépôts à terme reçus (G15)
$depotsTerme = 0;
try {
    $stmtDepotsTerme = $pdo->prepare("
        SELECT COALESCE(SUM(capital_initial), 0) as total_depots_terme
        FROM comptes_dat
        WHERE statut = 'en cours'
          AND date_echeance <= DATE_ADD(:date_fin, INTERVAL 3 MONTH)
    ");
    $stmtDepotsTerme->execute([':date_fin' => $date_fin_periode]);
    $resultDepotsTerme = $stmtDepotsTerme->fetch();
    $depotsTerme = $resultDepotsTerme['total_depots_terme'];
} catch (PDOException $e) {
    $depotsTerme = 0;
}

// Comptes d'épargne à régime spécial (G2A)
$epargneSpeciale = 0;
try {
    $stmtEpargne = $pdo->prepare("
        SELECT COALESCE(SUM(solde), 0) as total_epargne
        FROM comptes c
        INNER JOIN produits p ON c.produit_id = p.produit_id
        INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id
        WHERE pf.categorie = 'Epargne'
          AND c.statut = 'actif'
          AND c.solde > 0
    ");
    $stmtEpargne->execute();
    $resultEpargne = $stmtEpargne->fetch();
    $epargneSpeciale = $resultEpargne['total_epargne'];
} catch (PDOException $e) {
    $epargneSpeciale = 0;
}

// Emprunts à moins d'un an (F3E)
$empruntsCT = 0;
try {
    $stmtEmprunts = $pdo->prepare("
        SELECT COALESCE(SUM(montant), 0) as total_emprunts
        FROM capital
        WHERE statut = 'valide'
          AND mode_paiement = 'BANQUE'
          AND date_creation <= :date_fin
          AND date_creation >= DATE_SUB(:date_fin, INTERVAL 1 YEAR)
    ");
    $stmtEmprunts->execute([':date_fin' => $date_fin_periode]);
    $resultEmprunts = $stmtEmprunts->fetch();
    $empruntsCT = $resultEmprunts['total_emprunts'];
} catch (PDOException $e) {
    $empruntsCT = 0;
}

// Autres sommes dues (G70, F50, etc.)
$autresDettes = 0;
try {
    $stmtDettes = $pdo->prepare("
        SELECT COALESCE(SUM(montant_credit - montant_debit), 0) as total_dettes
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '4'  -- Comptes de dettes
          AND e.date_ecriture <= :date_fin
    ");
    $stmtDettes->execute([':date_fin' => $date_fin_periode]);
    $resultDettes = $stmtDettes->fetch();
    $autresDettes = abs($resultDettes['total_dettes']);
} catch (PDOException $e) {
    $autresDettes = 0;
}

// Engagements de financement donnés (N1A, N1J)
$engagementsDonnes = 0;
try {
    $stmtEngagements = $pdo->prepare("
        SELECT COALESCE(SUM(g.valeur_nette), 0) as total_engagements
        FROM garanties g
        WHERE g.statut = 'actif'
          AND g.date_creation <= :date_fin
    ");
    $stmtEngagements->execute([':date_fin' => $date_fin_periode]);
    $resultEngagements = $stmtEngagements->fetch();
    $engagementsDonnes = $resultEngagements['total_engagements'];
} catch (PDOException $e) {
    $engagementsDonnes = 0;
}

// TOTAL B - Passif exigible
$montantB = $depotsClients + $depotsTerme + $epargneSpeciale + $empruntsCT + $autresDettes + $engagementsDonnes;

// ============================================================
// CALCUL DU RATIO R05
// ============================================================

if ($montantB <= 0) {
    $montantB = 1;
    $ratioR05 = 0;
} else {
    $ratioR05 = $montantA / $montantB;
}

// Normes : le ratio doit être ≥ 1
$normeMin = 1;
$normeMax = null;
$conformite = ($ratioR05 >= $normeMin) ? 'CONFORME' : 'NON_CONFORME';

// ============================================================
// DÉTAILS POUR L'AFFICHAGE
// ============================================================

// Détail des disponibilités par caisse
$detailsCaisses = [];
try {
    $stmtDetailsCaisses = $pdo->prepare("
        SELECT code_caisse, nom_caisse, solde_actuel, statut
        FROM caisses
        WHERE statut = 'ouverte'
        ORDER BY solde_actuel DESC
    ");
    $stmtDetailsCaisses->execute();
    $detailsCaisses = $stmtDetailsCaisses->fetchAll();
} catch (PDOException $e) {
    $detailsCaisses = [];
}

// Top 5 des dépôts clients
$topDepots = [];
try {
    $stmtTopDepots = $pdo->prepare("
        SELECT 
            c.numero_compte,
            CONCAT(COALESCE(cl.nom, ''), ' ', COALESCE(cl.prenom, '')) as client_nom,
            c.solde,
            c.statut
        FROM comptes c
        INNER JOIN clients cl ON c.client_id = cl.client_id
        WHERE c.solde < 0 AND c.statut = 'actif'
        ORDER BY c.solde ASC
        LIMIT 5
    ");
    $stmtTopDepots->execute();
    $topDepots = $stmtTopDepots->fetchAll();
    foreach ($topDepots as &$depot) {
        $depot['solde'] = abs($depot['solde']);
    }
} catch (PDOException $e) {
    $topDepots = [];
}

// Échéances à venir (prochains mois)
$echeancesProches = [];
try {
    $stmtEcheances = $pdo->prepare("
        SELECT 
            DATE_FORMAT(e.date_echeance, '%Y-%m') as mois,
            COUNT(*) as nombre,
            SUM(e.montant) as total
        FROM echeances e
        WHERE e.statut = 'attente'
          AND e.date_echeance BETWEEN :date_fin AND DATE_ADD(:date_fin, INTERVAL 3 MONTH)
        GROUP BY DATE_FORMAT(e.date_echeance, '%Y-%m')
        ORDER BY mois
    ");
    $stmtEcheances->execute([':date_fin' => $date_fin_periode]);
    $echeancesProches = $stmtEcheances->fetchAll();
} catch (PDOException $e) {
    $echeancesProches = [];
}

// Récupération du total actif pour information
$totalActif = 0;
try {
    $stmtActif = $pdo->prepare("
        SELECT COALESCE(SUM(montant_debit - montant_credit), 0) as total_actif
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '2'
          AND e.date_ecriture <= :date_fin
    ");
    $stmtActif->execute([':date_fin' => $date_fin_periode]);
    $resultActif = $stmtActif->fetch();
    $totalActif = $resultActif ? $resultActif['total_actif'] : 0;
} catch (PDOException $e) {
    $totalActif = 0;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>R05 - Norme de liquidité</title>
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
        
        .two-columns {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        
        .two-columns > div {
            flex: 1;
            min-width: 250px;
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
            
            .two-columns {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>R05 - Norme de liquidité</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">Norme BCEAO : ≥ 1</div>
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
    
    <div class="ratio-card">
        <div class="ratio-title">📊 Ratio R05 - Norme de liquidité</div>
        <div class="ratio-value-container">
            <div class="ratio-value">
                <div class="value <?= $ratioR05 >= $normeMin ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($ratioR05, 2) ?>
                </div>
                <div class="label">Valeur du ratio</div>
            </div>
            <div class="norme">
                <div class="title">Norme réglementaire</div>
                <div class="range">Ratio ≥ 1</div>
                <div class="label">Conformité requise</div>
            </div>
            <div>
                <span class="status-badge <?= $conformite == 'CONFORME' ? 'status-conforme' : 'status-non-conforme' ?>">
                    <?= $conformite ?>
                </span>
            </div>
        </div>
    </div>
    
    <div class="two-columns">
        <div class="data-table">
            <h3>📈 A - VALEURS RÉALISABLES ET DISPONIBLES</h3>
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Libellé</th>
                        <th class="text-right">Montant (FCFA)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>A10</td>
                        <td>Valeurs en caisse</td>
                        <td class="text-right"><?= number_format($disponibilites, 0, ',', ' ') ?></td>
                    </tr>
                    <tr>
                        <td>A12</td>
                        <td>Comptes ordinaires débiteurs</td>
                        <td class="text-right"><?= number_format($comptesDebiteurs, 0, ',', ' ') ?></td>
                    </tr>
                    <tr>
                        <td>B2D</td>
                        <td>Crédits à court terme</td>
                        <td class="text-right"><?= number_format($creditsCourtTerme, 0, ',', ' ') ?></td>
                    </tr>
                    <tr>
                        <td>C10</td>
                        <td>Titres de placement</td>
                        <td class="text-right"><?= number_format($titresPlacement, 0, ',', ' ') ?></td>
                    </tr>
                    <tr>
                        <td>A60/B65/C55</td>
                        <td>Créances rattachées</td>
                        <td class="text-right"><?= number_format($creancesRattachees, 0, ',', ' ') ?></td>
                    </tr>
                    <tr style="background:#f0f7ff; font-weight:bold;">
                        <td colspan="2">TOTAL A</td>
                        <td class="text-right"><?= number_format($montantA, 0, ',', ' ') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="data-table">
            <h3>📉 B - PASSIF EXIGIBLE</h3>
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Libellé</th>
                        <th class="text-right">Montant (FCFA)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>G10</td>
                        <td>Comptes ordinaires créditeurs (dépôts clients)</td>
                        <td class="text-right"><?= number_format($depotsClients, 0, ',', ' ') ?></td>
                    </tr>
                    <tr>
                        <td>G15</td>
                        <td>Dépôts à terme reçus</td>
                        <td class="text-right"><?= number_format($depotsTerme, 0, ',', ' ') ?></td>
                    </tr>
                    <tr>
                        <td>G2A</td>
                        <td>Comptes d'épargne à régime spécial</td>
                        <td class="text-right"><?= number_format($epargneSpeciale, 0, ',', ' ') ?></td>
                    </tr>
                    <tr>
                        <td>F3E</td>
                        <td>Emprunts à moins d'un an</td>
                        <td class="text-right"><?= number_format($empruntsCT, 0, ',', ' ') ?></td>
                    </tr>
                    <tr>
                        <td>G70/F50</td>
                        <td>Autres sommes dues</td>
                        <td class="text-right"><?= number_format($autresDettes, 0, ',', ' ') ?></td>
                    </tr>
                    <tr>
                        <td>N1A/N1J</td>
                        <td>Engagements de financement donnés</td>
                        <td class="text-right"><?= number_format($engagementsDonnes, 0, ',', ' ') ?></td>
                    </tr>
                    <tr style="background:#f0f7ff; font-weight:bold;">
                        <td colspan="2">TOTAL B</td>
                        <td class="text-right"><?= number_format($montantB, 0, ',', ' ') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="data-table">
        <h3>📊 Synthèse du calcul</h3>
        <table>
            <tbody>
                <tr>
                    <td style="width: 60%;"><strong>A - Valeurs réalisables et disponibles</strong></td>
                    <td class="text-right"><strong><?= number_format($montantA, 0, ',', ' ') ?> FCFA</strong></td>
                </tr>
                <tr>
                    <td><strong>B - Passif exigible</strong></td>
                    <td class="text-right"><strong><?= number_format($montantB, 0, ',', ' ') ?> FCFA</strong></td>
                </tr>
                <tr style="background:#f0f7ff;">
                    <td><strong>RATIO R05 = A / B</strong></td>
                    <td class="text-right"><strong><?= number_format($ratioR05, 2) ?></strong></td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <?php if(!empty($detailsCaisses)): ?>
    <div class="data-table">
        <h3>💰 Détail des disponibilités par caisse</h3>
        <table>
            <thead>
                <tr>
                    <th>Code caisse</th>
                    <th>Nom caisse</th>
                    <th class="text-right">Solde actuel (FCFA)</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($detailsCaisses as $caisse): ?>
                <tr>
                    <td><?= htmlspecialchars($caisse['code_caisse']) ?></td>
                    <td><?= htmlspecialchars($caisse['nom_caisse']) ?></td>
                    <td class="text-right"><?= number_format($caisse['solde_actuel'], 0, ',', ' ') ?></td>
                    <td><?= htmlspecialchars($caisse['statut']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <?php if(!empty($topDepots)): ?>
    <div class="data-table">
        <h3>🏦 Top 5 des dépôts clients</h3>
        <table>
            <thead>
                <tr>
                    <th>N° compte</th>
                    <th>Client</th>
                    <th class="text-right">Solde (FCFA)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($topDepots as $depot): ?>
                <tr>
                    <td><?= htmlspecialchars($depot['numero_compte']) ?></td>
                    <td><?= htmlspecialchars($depot['client_nom'] ?: 'N/A') ?></td>
                    <td class="text-right"><?= number_format($depot['solde'], 0, ',', ' ') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <?php if(!empty($echeancesProches)): ?>
    <div class="data-table">
        <h3>📅 Échéances à venir (prochains mois)</h3>
        <table>
            <thead>
                <tr>
                    <th>Mois</th>
                    <th class="text-right">Nombre d'échéances</th>
                    <th class="text-right">Montant total (FCFA)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($echeancesProches as $echeance): ?>
                <tr>
                    <td><?= date('F Y', strtotime($echeance['mois'] . '-01')) ?></td>
                    <td class="text-right"><?= number_format($echeance['nombre']) ?></td>
                    <td class="text-right"><?= number_format($echeance['total'], 0, ',', ' ') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <div class="data-table">
        <h3>📖 Interprétation du ratio R05 - Norme de liquidité</h3>
        <div style="padding: 15px; line-height: 1.6;">
            <p><strong>Ratio calculé :</strong> <?= number_format($ratioR05, 2) ?></p>
            <p><strong>Formule :</strong> R05 = (Valeurs réalisables et disponibles) / (Passif exigible)</p>
            <p><strong>Norme BCEAO :</strong> Le ratio doit être <strong>supérieur ou égal à 1</strong>.</p>
            <p><strong>Interprétation :</strong></p>
            <ul style="margin-left: 25px; margin-top: 10px;">
                <?php if($ratioR05 >= $normeMin): ?>
                    <li style="color:#2e7d32;">✓ Le ratio est <strong>CONFORME</strong> à la réglementation BCEAO.</li>
                    <li>L'institution dispose de suffisamment d'actifs liquides pour faire face à ses engagements à court terme.</li>
                    <li>La trésorerie couvre <?= number_format($ratioR05, 2) ?> fois le passif exigible.</li>
                <?php else: ?>
                    <li style="color:#c62828;">✗ Le ratio est <strong>NON CONFORME</strong> à la réglementation BCEAO.</li>
                    <li>L'institution ne dispose pas d'assez d'actifs liquides pour couvrir ses engagements à court terme.</li>
                    <li>Risque de liquidité élevé.</li>
                    <li>Il est recommandé de :</li>
                    <ul style="margin-left: 25px;">
                        <li>Augmenter les disponibilités en caisse et en banque</li>
                        <li>Réduire les dettes à court terme</li>
                        <li>Améliorer le recouvrement des créances</li>
                    </ul>
                <?php endif; ?>
            </ul>
            <?php if($totalActif > 0): ?>
            <p style="margin-top: 15px; font-size: 0.9rem; color: #666;">
                <strong>Note :</strong> Total de l'actif au <?= date('d/m/Y', strtotime($date_fin_periode)) ?> : <?= number_format($totalActif, 0, ',', ' ') ?> FCFA
            </p>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="footer">
        Document généré le <?= date('d/m/Y à H:i:s') ?> - Données extraites de la base Mandigo<br>
        Période : <?= $periode ?> (arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)<br>
        <strong>Note :</strong> Le calcul ne concerne que les parties des comptes ayant une durée résiduelle inférieure à 3 mois.
    </div>
</div>

<script>
    function appliquerFiltres() {
        let exercice = document.getElementById('exercice').value;
        let mois = document.getElementById('mois').value;
        window.location.href = 'R05.php?exercice=' + exercice + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>