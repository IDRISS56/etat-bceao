<?php
// R04.php - Limitation des risques pris sur une seule signature
// Norme BCEAO: 0% à 10% (0 - 0.1)

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
// A - PRÊTS ET ENGAGEMENTS AU PLUS GROS EMPRUNTEUR (Z54)
// ============================================================

// Structure de la base :
// dossiers -> compte_id -> comptes -> client_id -> clients

$queryA = "
    SELECT 
        c.client_id,
        c.nom,
        c.prenom,
        c.matricule,
        c.categorie,
        SUM(encours_restant) as encours_total
    FROM (
        SELECT 
            d.compte_id,
            COALESCE(d.montant - COALESCE(e.montant_paye, 0), d.montant) as encours_restant
        FROM dossiers d
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as montant_paye
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve')
          AND d.date_octroi <= :date_fin
    ) AS encours_par_compte
    INNER JOIN comptes cpt ON encours_par_compte.compte_id = cpt.compte_id
    INNER JOIN clients c ON cpt.client_id = c.client_id
    GROUP BY c.client_id, c.nom, c.prenom, c.matricule, c.categorie
    ORDER BY encours_total DESC
    LIMIT 1
";

$stmtA = $pdo->prepare($queryA);
$stmtA->execute([':date_fin' => $date_fin_periode]);
$plusGrosEmprunteur = $stmtA->fetch();

if ($plusGrosEmprunteur && $plusGrosEmprunteur['encours_total'] > 0) {
    $montantA = $plusGrosEmprunteur['encours_total'];
    $clientPlusGros = $plusGrosEmprunteur['client_id'];
    $clientInfos = $plusGrosEmprunteur;
} else {
    $montantA = 0;
    $clientPlusGros = null;
    $clientInfos = null;
}

// Détail des prêts du plus gros emprunteur
$detailsGrosEmprunteur = [];
if ($clientPlusGros) {
    $stmtDetails = $pdo->prepare("
        SELECT 
            d.dossier_id,
            d.date_octroi,
            d.montant as montant_initial,
            COALESCE(d.montant - COALESCE(e.montant_paye, 0), d.montant) as encours_restant,
            d.objet,
            cpt.numero_compte
        FROM dossiers d
        INNER JOIN comptes cpt ON d.compte_id = cpt.compte_id
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as montant_paye
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE cpt.client_id = :client_id
          AND d.statut IN ('actif', 'approuve')
        ORDER BY encours_restant DESC
    ");
    $stmtDetails->execute([':client_id' => $clientPlusGros]);
    $detailsGrosEmprunteur = $stmtDetails->fetchAll();
}

// ============================================================
// B - FONDS PROPRES
// ============================================================

$queryB = "
    SELECT 
        COALESCE(SUM(montant_credit - montant_debit), 0) as total_fonds_propres
    FROM ecritures_comptables e
    INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
    WHERE pc.classe_compte = '1'
      AND e.date_ecriture <= :date_fin
";

$stmtB = $pdo->prepare($queryB);
$stmtB->execute([':date_fin' => $date_fin_periode]);
$resultB = $stmtB->fetch();
$montantB = $resultB ? $resultB['total_fonds_propres'] : 0;

// Fallback sur table capital
if ($montantB == 0) {
    $queryB_capital = "
        SELECT COALESCE(SUM(montant), 0) as total_capital
        FROM capital
        WHERE statut = 'valide'
          AND date_creation <= :date_fin
    ";
    $stmtB_capital = $pdo->prepare($queryB_capital);
    $stmtB_capital->execute([':date_fin' => $date_fin_periode]);
    $resultB_capital = $stmtB_capital->fetch();
    $montantB = $resultB_capital ? $resultB_capital['total_capital'] : 0;
}

// Fallback sur table agences (si besoin)
if ($montantB == 0) {
    // Valeur par défaut pour éviter division par zéro
    $montantB = 1;
}

// Éviter division par zéro
if ($montantB <= 0) {
    $montantB = 1;
    $ratioR04 = 0;
} else {
    $ratioR04 = $montantA / $montantB;
}

// Normes
$normeMin = 0;
$normeMax = 0.1;
$conformite = ($ratioR04 >= $normeMin && $ratioR04 <= $normeMax) ? 'CONFORME' : 'NON_CONFORME';

// Top 10 des plus gros emprunteurs pour analyse
$topEmprunteurs = [];
try {
    $stmtTop = $pdo->prepare("
        SELECT 
            c.client_id,
            CONCAT(COALESCE(c.prenom, ''), ' ', COALESCE(c.nom, '')) as nom_complet,
            c.matricule,
            c.categorie,
            SUM(encours_restant) as encours_total
        FROM (
            SELECT 
                d.compte_id,
                COALESCE(d.montant - COALESCE(e.montant_paye, 0), d.montant) as encours_restant
            FROM dossiers d
            LEFT JOIN (
                SELECT dossier_id, SUM(montant) as montant_paye
                FROM echeances
                WHERE statut = 'payee'
                GROUP BY dossier_id
            ) e ON d.dossier_id = e.dossier_id
            WHERE d.statut IN ('actif', 'approuve')
              AND d.date_octroi <= :date_fin
        ) AS encours_par_compte
        INNER JOIN comptes cpt ON encours_par_compte.compte_id = cpt.compte_id
        INNER JOIN clients c ON cpt.client_id = c.client_id
        GROUP BY c.client_id, c.nom, c.prenom, c.matricule, c.categorie
        HAVING encours_total > 0
        ORDER BY encours_total DESC
        LIMIT 10
    ");
    $stmtTop->execute([':date_fin' => $date_fin_periode]);
    $topEmprunteurs = $stmtTop->fetchAll();
} catch (PDOException $e) {
    $topEmprunteurs = [];
}

// Récupération du total de l'actif
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

// Calculer le pourcentage par rapport aux fonds propres pour chaque top emprunteur
foreach ($topEmprunteurs as &$emprunteur) {
    $emprunteur['pourcentage_fp'] = ($montantB > 0) ? ($emprunteur['encours_total'] / $montantB) * 100 : 0;
    $emprunteur['conforme'] = ($emprunteur['pourcentage_fp'] <= 10) ? 'oui' : 'non';
}

// Récupérer le nombre total d'emprunteurs actifs
$nbEmprunteurs = 0;
try {
    $stmtNb = $pdo->prepare("
        SELECT COUNT(DISTINCT c.client_id) as total
        FROM dossiers d
        INNER JOIN comptes cpt ON d.compte_id = cpt.compte_id
        INNER JOIN clients c ON cpt.client_id = c.client_id
        WHERE d.statut IN ('actif', 'approuve')
          AND d.date_octroi <= :date_fin
    ");
    $stmtNb->execute([':date_fin' => $date_fin_periode]);
    $resultNb = $stmtNb->fetch();
    $nbEmprunteurs = $resultNb ? $resultNb['total'] : 0;
} catch (PDOException $e) {
    $nbEmprunteurs = 0;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>R04 - Limitation des risques pris sur une seule signature</title>
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
        
        .client-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin: 15px;
        }
        
        .client-card h4 {
            color: #1a3a5c;
            margin-bottom: 10px;
        }
        
        .badge-conforme {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: bold;
        }
        
        .badge-non-conforme {
            background: #ffebee;
            color: #c62828;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: bold;
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
        <h1>R04 - Limitation des risques pris sur une seule signature</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">Norme BCEAO : 0% à 10%</div>
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
    
    <?php if($montantA == 0): ?>
    <div class="warning">
        ⚠️ <strong>Information :</strong> Aucun prêt en cours trouvé pour la période sélectionnée.
    </div>
    <?php endif; ?>
    
    <div class="ratio-card">
        <div class="ratio-title">📊 Ratio R04 - Risque sur une seule signature</div>
        <div class="ratio-value-container">
            <div class="ratio-value">
                <div class="value <?= $ratioR04 <= $normeMax ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($ratioR04 * 100, 2) ?>%
                </div>
                <div class="label">Valeur du ratio</div>
            </div>
            <div class="norme">
                <div class="title">Norme réglementaire</div>
                <div class="range">0% ≤ Ratio ≤ 10%</div>
                <div class="label">Conformité requise</div>
            </div>
            <div>
                <span class="status-badge <?= $conformite == 'CONFORME' ? 'status-conforme' : 'status-non-conforme' ?>">
                    <?= $conformite ?>
                </span>
            </div>
        </div>
    </div>
    
    <div class="data-table">
        <h3>📋 Calcul du ratio R04</h3>
        <table>
            <thead>
                <tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th></tr>
            </thead>
            <tbody>
                <tr style="background:#f0f7ff;">
                    <td colspan="2"><strong>A - PRÊTS ET ENGAGEMENTS AU PLUS GROS EMPRUNTEUR (Z54)</strong></td>
                    <td class="text-right"><strong><?= number_format($montantA, 0, ',', ' ') ?></strong></td>
                </tr>
                <tr style="background:#f0f7ff;">
                    <td colspan="2"><strong>B - FONDS PROPRES (L01)</strong></td>
                    <td class="text-right"><strong><?= number_format($montantB, 0, ',', ' ') ?></strong></td>
                </tr>
                <tr style="background:#fff3e0;">
                    <td colspan="2"><strong>RATIO R04 = A / B</strong></td>
                    <td class="text-right"><strong><?= number_format($ratioR04 * 100, 2) ?>%</strong></td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <!-- Détail du plus gros emprunteur -->
    <?php if ($clientPlusGros && $clientInfos && $montantA > 0): ?>
    <div class="data-table">
        <h3>🏆 Plus gros emprunteur</h3>
        <div class="client-card">
            <h4><?= htmlspecialchars(($clientInfos['prenom'] ? $clientInfos['prenom'] . ' ' : '') . ($clientInfos['nom'] ?: 'N/A')) ?></h4>
            <div style="display: flex; flex-wrap: wrap; gap: 20px; margin-top: 10px;">
                <div><strong>Matricule :</strong> <?= htmlspecialchars($clientInfos['matricule'] ?? 'N/A') ?></div>
                <div><strong>Catégorie :</strong> <?= htmlspecialchars($clientInfos['categorie'] ?? 'N/A') ?></div>
                <div><strong>Encours total :</strong> <?= number_format($montantA, 0, ',', ' ') ?> FCFA</div>
                <div><strong>% des fonds propres :</strong> 
                    <span class="<?= ($montantA / $montantB * 100) <= 10 ? 'badge-conforme' : 'badge-non-conforme' ?>">
                        <?= number_format(($montantA / $montantB * 100), 2) ?>%
                    </span>
                </div>
            </div>
        </div>
        
        <?php if(!empty($detailsGrosEmprunteur)): ?>
        <h3 style="padding: 15px 20px; background: #f8f9fa; border-top: 1px solid #e0e0e0; margin-top: 0;">Détail des prêts</h3>
        <table>
            <thead>
                <tr><th>N° dossier</th><th>N° compte</th><th>Date d'octroi</th><th class="text-right">Montant initial</th><th class="text-right">Encours restant</th><th>Objet</th></tr>
            </thead>
            <tbody>
                <?php foreach($detailsGrosEmprunteur as $pret): ?>
                <tr>
                    <td><?= htmlspecialchars($pret['dossier_id']) ?></td>
                    <td><?= htmlspecialchars($pret['numero_compte'] ?? 'N/A') ?></td>
                    <td><?= date('d/m/Y', strtotime($pret['date_octroi'])) ?></td>
                    <td class="text-right"><?= number_format($pret['montant_initial'], 0, ',', ' ') ?></td>
                    <td class="text-right"><?= number_format($pret['encours_restant'], 0, ',', ' ') ?></td>
                    <td><?= htmlspecialchars($pret['objet'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Top 10 des emprunteurs -->
    <?php if(!empty($topEmprunteurs)): ?>
    <div class="data-table">
        <h3>📊 Top 10 des emprunteurs par encours</h3>
        <table>
            <thead>
                <tr>
                    <th>Rang</th>
                    <th>Client</th>
                    <th>Matricule</th>
                    <th>Catégorie</th>
                    <th class="text-right">Encours total (FCFA)</th>
                    <th class="text-right">% Fonds propres</th>
                    <th>Conformité</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($topEmprunteurs as $index => $emprunteur): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><?= htmlspecialchars($emprunteur['nom_complet'] ?: 'N/A') ?></td>
                    <td><?= htmlspecialchars($emprunteur['matricule']) ?></td>
                    <td><?= htmlspecialchars($emprunteur['categorie']) ?></td>
                    <td class="text-right"><?= number_format($emprunteur['encours_total'], 0, ',', ' ') ?></td>
                    <td class="text-right <?= $emprunteur['pourcentage_fp'] > 10 ? 'non-conforme' : '' ?>">
                        <?= number_format($emprunteur['pourcentage_fp'], 2) ?>%
                    </td>
                    <td class="text-center">
                        <?php if($emprunteur['pourcentage_fp'] <= 10): ?>
                            <span class="badge-conforme">✓ Conforme</span>
                        <?php else: ?>
                            <span class="badge-non-conforme">✗ Non conforme</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="padding: 10px 15px; background: #f8f9fa; font-size: 0.8rem; color: #666;">
            <strong>Note :</strong> La norme BCEAO exige qu'aucun emprunteur ne dépasse 10% des fonds propres.<br>
            <strong>Nombre total d'emprunteurs actifs :</strong> <?= $nbEmprunteurs ?>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="data-table">
        <h3>📖 Interprétation du ratio R04</h3>
        <div style="padding: 15px; line-height: 1.6;">
            <p><strong>Ratio calculé :</strong> <?= number_format($ratioR04 * 100, 2) ?>%</p>
            <p><strong>Formule :</strong> R04 = (Encours du plus gros emprunteur) / (Fonds propres)</p>
            <p><strong>Norme BCEAO :</strong> Le ratio doit être compris entre <strong>0% et 10%</strong>.</p>
            <p><strong>Interprétation :</strong></p>
            <ul style="margin-left: 25px; margin-top: 10px;">
                <?php if($ratioR04 <= $normeMax): ?>
                    <li style="color:#2e7d32;">✓ Le ratio est <strong>CONFORME</strong> à la réglementation BCEAO.</li>
                    <li>Le plus gros emprunteur représente <?= number_format($ratioR04 * 100, 2) ?>% des fonds propres, soit dans la limite autorisée de 10%.</li>
                    <li>Le risque de concentration est bien maîtrisé.</li>
                <?php else: ?>
                    <li style="color:#c62828;">✗ Le ratio est <strong>NON CONFORME</strong> à la réglementation BCEAO.</li>
                    <li>Le plus gros emprunteur dépasse la limite de 10% des fonds propres.</li>
                    <li>L'institution présente un risque de concentration excessif.</li>
                    <li>Il est recommandé de :</li>
                    <ul style="margin-left: 25px;">
                        <li>Réduire l'exposition sur cet emprunteur</li>
                        <li>Augmenter les fonds propres</li>
                        <li>Chercher à diversifier le portefeuille</li>
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
        Période : <?= $periode ?> (arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
    </div>
</div>

<script>
    function appliquerFiltres() {
        let exercice = document.getElementById('exercice').value;
        let mois = document.getElementById('mois').value;
        window.location.href = 'R04.php?exercice=' + exercice + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>