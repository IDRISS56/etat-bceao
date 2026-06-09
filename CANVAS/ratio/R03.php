<?php
// R03.php - Limitation des prêts aux dirigeants et au personnel
// Norme BCEAO: 0% à 10% (0 - 0.1)

session_start();

// Configuration BDD
$host = 'localhost';
$dbname = 'mandigo';
$username = 'root';  // À modifier selon votre configuration
$password = '';      // À modifier selon votre configuration

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// Récupérer l'année et la période depuis les paramètres GET ou utiliser l'année courante
$exercice = isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y');
$mois = isset($_GET['mois']) ? (int)$_GET['mois'] : date('m');
$periode = $exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT);
$date_fin_periode = $exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01';
$date_fin_periode = date('Y-m-t', strtotime($date_fin_periode)); // Dernier jour du mois

// ============================================================
// CALCUL DES DONNÉES POUR LE RATIO R03
// ============================================================

// A - PRÊTS ET ENGAGEMENTS PAR SIGNATURE AUX DIRIGEANTS/EMPLOYÉS
// Version corrigée - chaque SELECT du UNION doit avoir le même nombre de colonnes

$queryA = "
    SELECT COALESCE(SUM(montant_restant), 0) as montant
    FROM (
        -- Prêts aux dirigeants (utilisateurs avec rôles de direction)
        SELECT 
            d.dossier_id,
            COALESCE(d.montant - COALESCE(e.montant_paye, 0), d.montant) as montant_restant,
            1 as type
        FROM dossiers d
        INNER JOIN utilisateurs u ON d.utilisateur_id = u.utilisateur_id
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as montant_paye
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE u.role IN ('Superviseur', 'Administrateur', 'Responsable')
          AND d.statut IN ('actif', 'approuve')
          AND d.date_octroi <= :date_fin
          
        UNION ALL
        
        -- Prêts aux employés (autres rôles)
        SELECT 
            d.dossier_id,
            COALESCE(d.montant - COALESCE(e.montant_paye, 0), d.montant) as montant_restant,
            2 as type
        FROM dossiers d
        INNER JOIN utilisateurs u ON d.utilisateur_id = u.utilisateur_id
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as montant_paye
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE u.role IN ('Caisse', 'Comptable', 'Gestionnaire')
          AND d.statut IN ('actif', 'approuve')
          AND d.date_octroi <= :date_fin
    ) AS prets_employes
";

$stmtA = $pdo->prepare($queryA);
$stmtA->execute([':date_fin' => $date_fin_periode]);
$resultA = $stmtA->fetch();
$montantA = $resultA ? $resultA['montant'] : 0;

// Si pas de données, on essaie une requête alternative plus simple
if ($montantA == 0) {
    $queryA_simple = "
        SELECT COALESCE(SUM(d.montant), 0) as montant
        FROM dossiers d
        INNER JOIN utilisateurs u ON d.utilisateur_id = u.utilisateur_id
        WHERE u.role IN ('Superviseur', 'Administrateur', 'Responsable', 'Caisse', 'Comptable', 'Gestionnaire')
          AND d.statut IN ('actif', 'approuve')
          AND d.date_octroi <= :date_fin
    ";
    $stmtA_simple = $pdo->prepare($queryA_simple);
    $stmtA_simple->execute([':date_fin' => $date_fin_periode]);
    $resultA_simple = $stmtA_simple->fetch();
    $montantA = $resultA_simple ? $resultA_simple['montant'] : 0;
}

// B - FONDS PROPRES
// Version simplifiée et corrigée

// Récupération du total du passif (fonds propres) depuis la table des écriputes
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

// Si pas de données dans ecritures_comptables, on utilise les données de la table capital
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

// Éviter la division par zéro
if ($montantB <= 0) {
    $montantB = 1;
    $ratioR03 = 0;
} else {
    $ratioR03 = $montantA / $montantB;
}

// Normes
$normeMin = 0;
$normeMax = 0.1;
$conformite = ($ratioR03 >= $normeMin && $ratioR03 <= $normeMax) ? 'CONFORME' : 'NON_CONFORME';

// Récupération des détails des prêts
$detailsPrets = [];
try {
    $stmtDetails = $pdo->prepare("
        SELECT 
            CONCAT(u.nom_prenom, ' (', u.role, ')') as emprunteur,
            d.date_octroi,
            d.montant as montant_initial,
            COALESCE(d.montant - COALESCE(e.montant_paye, 0), d.montant) as montant_restant,
            u.role
        FROM dossiers d
        INNER JOIN utilisateurs u ON d.utilisateur_id = u.utilisateur_id
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as montant_paye
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE u.role IN ('Superviseur', 'Administrateur', 'Responsable', 'Caisse', 'Comptable', 'Gestionnaire')
          AND d.statut IN ('actif', 'approuve')
          AND d.date_octroi <= :date_fin
        GROUP BY d.dossier_id
        ORDER BY montant_restant DESC
    ");
    $stmtDetails->execute([':date_fin' => $date_fin_periode]);
    $detailsPrets = $stmtDetails->fetchAll();
} catch (PDOException $e) {
    $detailsPrets = [];
}

// Récupération du détail des fonds propres par type (pour l'affichage)
$detailsFondsProp = [];
try {
    $stmtFP = $pdo->prepare("
        SELECT 
            pc.numero_compte,
            pc.libelle_compte,
            COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM plan_comptables pc
        LEFT JOIN ecritures_comptables e ON pc.numero_compte = e.compte_general AND e.date_ecriture <= :date_fin
        WHERE pc.classe_compte = '1'
        GROUP BY pc.numero_compte
        HAVING solde != 0
        ORDER BY pc.numero_compte
    ");
    $stmtFP->execute([':date_fin' => $date_fin_periode]);
    $detailsFondsProp = $stmtFP->fetchAll();
} catch (PDOException $e) {
    $detailsFondsProp = [];
}

// Récupération du total de l'actif pour information
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
    <title>R03 - Limitation des prêts aux dirigeants et au personnel</title>
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
        <h1>R03 - Limitation des prêts aux dirigeants et au personnel</h1>
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
    
    <?php if($montantA == 0 && $montantB == 1): ?>
    <div class="warning">
        ⚠️ <strong>Information :</strong> Aucune donnée trouvée pour la période sélectionnée. Les valeurs affichées sont par défaut.
    </div>
    <?php endif; ?>
    
    <div class="ratio-card">
        <div class="ratio-title">📊 Ratio R03 - Prêts aux dirigeants et personnel / Fonds propres</div>
        <div class="ratio-value-container">
            <div class="ratio-value">
                <div class="value <?= $ratioR03 <= $normeMax ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($ratioR03 * 100, 2) ?>%
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
        <h3>📋 Calcul du ratio R03</h3>
        <table>
            <thead>
                <tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th></tr>
            </thead>
            <tbody>
                <tr style="background:#f0f7ff;">
                    <td colspan="2"><strong>A - PRÊTS ET ENGAGEMENTS AUX DIRIGEANTS/EMPLOYÉS (Z51)</strong></td>
                    <td class="text-right"><strong><?= number_format($montantA, 0, ',', ' ') ?></strong></td>
                </tr>
                <tr style="background:#f0f7ff;">
                    <td colspan="2"><strong>B - FONDS PROPRES (L01)</strong></td>
                    <td class="text-right"><strong><?= number_format($montantB, 0, ',', ' ') ?></strong></td>
                </tr>
                <tr style="background:#fff3e0;">
                    <td colspan="2"><strong>RATIO R03 = A / B</strong></td>
                    <td class="text-right"><strong><?= number_format($ratioR03 * 100, 2) ?>%</strong></td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <?php if(!empty($detailsFondsProp)): ?>
    <div class="data-table">
        <h3>📋 Détail des fonds propres (comptes de classe 1)</h3>
        <table>
            <thead>
                <tr><th>Numéro compte</th><th>Libellé</th><th class="text-right">Solde (FCFA)</th></tr>
            </thead>
            <tbody>
                <?php foreach($detailsFondsProp as $fp): ?>
                <tr>
                    <td><?= htmlspecialchars($fp['numero_compte']) ?></td>
                    <td><?= htmlspecialchars($fp['libelle_compte']) ?></td>
                    <td class="text-right"><?= number_format($fp['solde'], 0, ',', ' ') ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:#e8f5e9; font-weight:bold;">
                    <td colspan="2">TOTAL FONDS PROPRES</td>
                    <td class="text-right"><?= number_format($montantB, 0, ',', ' ') ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <?php if(!empty($detailsPrets)): ?>
    <div class="data-table">
        <h3>📋 Détail des prêts aux dirigeants et employés</h3>
        <table>
            <thead>
                <tr><th>Emprunteur</th><th>Rôle</th><th>Date d'octroi</th><th class="text-right">Montant initial</th><th class="text-right">Encours restant</th></tr>
            </thead>
            <tbody>
                <?php foreach($detailsPrets as $pret): ?>
                <tr>
                    <td><?= htmlspecialchars($pret['emprunteur']) ?></td>
                    <td><?= htmlspecialchars($pret['role']) ?></td>
                    <td><?= date('d/m/Y', strtotime($pret['date_octroi'])) ?></td>
                    <td class="text-right"><?= number_format($pret['montant_initial'], 0, ',', ' ') ?></td>
                    <td class="text-right"><?= number_format($pret['montant_restant'], 0, ',', ' ') ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:#f0f7ff; font-weight:bold;">
                    <td colspan="4">TOTAL</td>
                    <td class="text-right"><?= number_format($montantA, 0, ',', ' ') ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="data-table">
        <h3>📋 Détail des prêts aux dirigeants et employés</h3>
        <div style="padding: 20px; text-align: center; color: #777;">
            Aucun prêt en cours pour les dirigeants ou employés sur la période sélectionnée.
        </div>
    </div>
    <?php endif; ?>
    
    <div class="data-table">
        <h3>📖 Interprétation du ratio R03</h3>
        <div style="padding: 15px; line-height: 1.6;">
            <p><strong>Ratio calculé :</strong> <?= number_format($ratioR03 * 100, 2) ?>%</p>
            <p><strong>Formule :</strong> R03 = (Encours des prêts aux dirigeants et employés) / (Fonds propres)</p>
            <p><strong>Norme BCEAO :</strong> Le ratio doit être compris entre <strong>0% et 10%</strong>.</p>
            <p><strong>Interprétation :</strong></p>
            <ul style="margin-left: 25px; margin-top: 10px;">
                <?php if($ratioR03 <= $normeMax): ?>
                    <li style="color:#2e7d32;">✓ Le ratio est <strong>CONFORME</strong> à la réglementation BCEAO.</li>
                    <li>Les prêts accordés aux dirigeants et au personnel représentent <?= number_format($ratioR03 * 100, 2) ?>% des fonds propres, soit dans la limite autorisée de 10%.</li>
                <?php else: ?>
                    <li style="color:#c62828;">✗ Le ratio est <strong>NON CONFORME</strong> à la réglementation BCEAO.</li>
                    <li>Les prêts accordés aux dirigeants et au personnel dépassent la limite de 10% des fonds propres.</li>
                    <li>L'institution doit prendre des mesures correctives pour réduire cette exposition.</li>
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
        window.location.href = 'R03.php?exercice=' + exercice + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>