<?php
// DIMF_2012.php - Top 10 des débiteurs
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
// RÉCUPÉRATION DU TOP 10 DES DÉBITEURS
// ============================================================

// Structure de la base: dossiers -> comptes -> clients
$top_debiteurs = [];
$total_encours = 0;

try {
    $stmt = $pdo->prepare("
        SELECT 
            c.client_id,
            c.matricule,
            CONCAT(COALESCE(c.nom, ''), ' ', COALESCE(c.prenom, '')) as nom_complet,
            c.categorie,
            c.secteur_id,
            SUM(encours_restant) as encours_total,
            AVG(d.duree) as duree_moyenne,
            COUNT(d.dossier_id) as nb_credits,
            MAX(CASE WHEN e.date_dernier_impaye IS NOT NULL THEN 1 ELSE 0 END) as has_impaye
        FROM (
            SELECT 
                d.compte_id,
                d.dossier_id,
                d.duree,
                COALESCE(d.montant - COALESCE(e.rembourse, 0), d.montant) as encours_restant
            FROM dossiers d
            LEFT JOIN (
                SELECT dossier_id, SUM(montant) as rembourse
                FROM echeances
                WHERE statut = 'payee'
                GROUP BY dossier_id
            ) e ON d.dossier_id = e.dossier_id
            WHERE d.statut IN ('actif', 'approuve')
              AND d.date_octroi <= :date_fin
        ) AS encours_par_dossier
        INNER JOIN comptes cpt ON encours_par_dossier.compte_id = cpt.compte_id
        INNER JOIN clients c ON cpt.client_id = c.client_id
        LEFT JOIN (
            SELECT d.client_id, MAX(e.date_echeance) as date_dernier_impaye
            FROM dossiers d
            INNER JOIN comptes cpt ON d.compte_id = cpt.compte_id
            INNER JOIN echeances e ON d.dossier_id = e.dossier_id
            WHERE e.statut = 'attente' AND e.date_echeance < :date_fin
            GROUP BY d.client_id
        ) e ON c.client_id = e.client_id
        GROUP BY c.client_id, c.matricule, c.nom, c.prenom, c.categorie, c.secteur_id
        HAVING encours_total > 0
        ORDER BY encours_total DESC
        LIMIT 10
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $top_debiteurs = $stmt->fetchAll();
    
    // Calcul du total des encours
    foreach ($top_debiteurs as $debiteur) {
        $total_encours += $debiteur['encours_total'];
    }
} catch (PDOException $e) {
    $top_debiteurs = [];
    $total_encours = 0;
}

// Récupération des fonds propres pour calcul du ratio de concentration
$fonds_propres = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '1'
          AND e.date_ecriture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $fonds_propres = $result['total'];
} catch (PDOException $e) {
    $fonds_propres = 0;
}

// Calcul du ratio de concentration pour le top 1
$ratio_concentration = ($fonds_propres > 0 && !empty($top_debiteurs)) ? ($top_debiteurs[0]['encours_total'] / $fonds_propres) * 100 : 0;

// Récupération des détails des prêts par débiteur
$details_par_debiteur = [];
foreach ($top_debiteurs as $index => $debiteur) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                d.dossier_id,
                d.date_octroi,
                d.montant as montant_initial,
                COALESCE(d.montant - COALESCE(e.rembourse, 0), d.montant) as encours_restant,
                d.duree,
                d.objet,
                (SELECT COUNT(*) FROM echeances WHERE dossier_id = d.dossier_id AND statut = 'attente' AND date_echeance < :date_fin) as nb_impayes
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
            ORDER BY encours_restant DESC
        ");
        $stmt->execute([
            ':client_id' => $debiteur['client_id'],
            ':date_fin' => $date_fin_periode
        ]);
        $details_par_debiteur[$debiteur['client_id']] = $stmt->fetchAll();
    } catch (PDOException $e) {
        $details_par_debiteur[$debiteur['client_id']] = [];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2012 - Top 10 des débiteurs</title>
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
        
        .total-row {
            background: #e8f5e9;
            font-weight: bold;
        }
        
        .warning-row {
            background: #fff3e0;
        }
        
        .danger-row {
            background: #ffebee;
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
        
        .badge-risk {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: bold;
        }
        
        .risk-high {
            background: #ffebee;
            color: #c62828;
        }
        
        .risk-medium {
            background: #fff3e0;
            color: #ef6c00;
        }
        
        .risk-low {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .sub-table {
            margin: 10px 0 10px 30px;
            width: calc(100% - 30px);
            font-size: 0.8rem;
        }
        
        .sub-table th, .sub-table td {
            padding: 6px 10px;
        }
        
        .expand-btn {
            cursor: pointer;
            background: none;
            border: none;
            font-size: 1.2rem;
            font-weight: bold;
            color: #1a3a5c;
        }
        
        .hidden-row {
            display: none;
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
                padding: 6px 8px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>DIMF_2012 - TOP 10 DES DÉBITEURS</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">SICS-BCEAO - Grands risques</div>
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
        <strong>ⓘ Note :</strong> Ce tableau présente les 10 débiteurs les plus importants de l'institution.
        La norme BCEAO limite l'exposition sur une seule signature à 10% des fonds propres.
    </div>
    
    <?php if(empty($top_debiteurs)): ?>
        <div class="section-card">
            <div class="info-box">Aucun débiteur actif pour la période sélectionnée.</div>
        </div>
    <?php else: ?>
        
        <!-- Fonds propres et ratio de concentration -->
        <div class="section-card">
            <div class="section-title">📊 INDICATEURS DE CONCENTRATION</div>
            <div class="info-box">
                <strong>Fonds propres :</strong> <?= number_format($fonds_propres, 0, ',', ' ') ?> FCFA<br>
                <strong>Plus gros emprunteur :</strong> <?= number_format($top_debiteurs[0]['encours_total'], 0, ',', ' ') ?> FCFA
                (<?= number_format($ratio_concentration, 2) ?>% des fonds propres)<br>
                <strong>Norme BCEAO :</strong> ≤ 10% des fonds propres
                <?php if($ratio_concentration > 10): ?>
                    <span style="color: #c62828;">⚠️ Non conforme !</span>
                <?php else: ?>
                    <span style="color: #2e7d32;">✓ Conforme</span>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Tableau Top 10 -->
        <div class="section-card">
            <div class="section-title">🏆 TOP 10 DES DÉBITEURS</div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Rang</th>
                            <th>N° Compte/Matricule</th>
                            <th>Noms et prénoms</th>
                            <th>Catégorie</th>
                            <th>Secteur</th>
                            <th class="text-right">Durée initiale (mois)</th>
                            <th class="text-right">Durée restante (mois)</th>
                            <th class="text-right">Montant net (FCFA)</th>
                            <th>% Fonds propres</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($top_debiteurs as $index => $debiteur): 
                            $pourcentage_fp = ($fonds_propres > 0) ? ($debiteur['encours_total'] / $fonds_propres) * 100 : 0;
                            $row_class = '';
                            if ($pourcentage_fp > 10) {
                                $row_class = 'danger-row';
                            } elseif ($debiteur['has_impaye']) {
                                $row_class = 'warning-row';
                            }
                        ?>
                            <tr class="<?= $row_class ?>" id="row-<?= $index ?>">
                                <td class="text-center"><strong><?= $index + 1 ?></strong></td>
                                <td><?= htmlspecialchars($debiteur['matricule']) ?></td>
                                <td><?= htmlspecialchars($debiteur['nom_complet'] ?: 'N/A') ?></td>
                                <td><?= htmlspecialchars($debiteur['categorie'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($debiteur['secteur_id'] ?: '-') ?></td>
                                <td class="text-right"><?= round($debiteur['duree_moyenne']) ?></td>
                                <td class="text-right">-<\/td>
                                <td class="text-right"><?= number_format($debiteur['encours_total'], 0, ',', ' ') ?></td>
                                <td class="text-right <?= $pourcentage_fp > 10 ? 'danger-row' : '' ?>">
                                    <?= number_format($pourcentage_fp, 2) ?>%
                                </td>
                                <td class="text-center">
                                    <button class="expand-btn" onclick="toggleDetails(<?= $index ?>)">▼</button>
                                </td>
                            </tr>
                            <tr id="details-<?= $index ?>" class="hidden-row">
                                <td colspan="10">
                                    <div style="padding: 10px; background: #f8f9fa;">
                                        <strong>Détail des prêts :</strong>
                                        <table class="sub-table">
                                            <thead>
                                                <tr>
                                                    <th>N° Dossier</th>
                                                    <th>Date octroi</th>
                                                    <th class="text-right">Montant initial</th>
                                                    <th class="text-right">Encours restant</th>
                                                    <th>Durée</th>
                                                    <th>Objet</th>
                                                    <th>Impayés</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $details = $details_par_debiteur[$debiteur['client_id']] ?? [];
                                                if(empty($details)):
                                                ?>
                                                    <tr><td colspan="7" class="text-center">Aucun prêt actif</td></tr>
                                                <?php else: ?>
                                                    <?php foreach($details as $pret): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($pret['dossier_id']) ?></td>
                                                            <td><?= date('d/m/Y', strtotime($pret['date_octroi'])) ?></td>
                                                            <td class="text-right"><?= number_format($pret['montant_initial'], 0, ',', ' ') ?></td>
                                                            <td class="text-right"><?= number_format($pret['encours_restant'], 0, ',', ' ') ?></td>
                                                            <td class="text-center"><?= $pret['duree'] ?> mois</td>
                                                            <td><?= htmlspecialchars($pret['objet'] ?: '-') ?></td>
                                                            <td class="text-center"><?= $pret['nb_impayes'] ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="7"><strong>TOTAL 10 PREMIERS DÉBITEURS</strong></td>
                            <td class="text-right"><strong><?= number_format($total_encours, 0, ',', ' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format(($total_encours / max($fonds_propres, 1)) * 100, 2) ?>%</strong></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Synthèse des risques -->
        <div class="section-card">
            <div class="section-title">📈 SYNTHÈSE DES RISQUES DE CONCENTRATION</div>
            <div class="info-box">
                <?php
                $nb_superieur_10 = 0;
                $nb_superieur_25 = 0;
                foreach ($top_debiteurs as $debiteur) {
                    $pourcentage = ($fonds_propres > 0) ? ($debiteur['encours_total'] / $fonds_propres) * 100 : 0;
                    if ($pourcentage > 10) $nb_superieur_10++;
                    if ($pourcentage > 25) $nb_superieur_25++;
                }
                ?>
                <strong>Nombre de débiteurs > 10% des fonds propres :</strong> <?= $nb_superieur_10 ?><br>
                <strong>Nombre de débiteurs > 25% des fonds propres :</strong> <?= $nb_superieur_25 ?><br>
                <strong>Encours total des 10 premiers débiteurs :</strong> <?= number_format($total_encours, 0, ',', ' ') ?> FCFA<br>
                <strong>Part dans le portefeuille total :</strong> 
                <?php
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
                    $result = $stmt->fetch();
                    $portefeuille_total = $result['total'];
                } catch (PDOException $e) {
                    $portefeuille_total = 0;
                }
                $part_top10 = ($portefeuille_total > 0) ? ($total_encours / $portefeuille_total) * 100 : 0;
                echo number_format($part_top10, 2) . '%';
                ?>
            </div>
        </div>
        
    <?php endif; ?>
    
    <div class="footer">
        Document généré le <?= date('d/m/Y à H:i:s') ?> - Données extraites de la base Mandigo<br>
        Période : <?= $exercice ?> - <?= $trimestre ?>ème trimestre (arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
    </div>
</div>

<script>
    function toggleDetails(index) {
        const detailsRow = document.getElementById('details-' + index);
        if (detailsRow.classList.contains('hidden-row')) {
            detailsRow.classList.remove('hidden-row');
        } else {
            detailsRow.classList.add('hidden-row');
        }
    }
    
    function appliquerFiltres() {
        let exercice = document.getElementById('exercice').value;
        let trimestre = document.getElementById('trimestre').value;
        let mois = document.getElementById('mois').value;
        window.location.href = 'DIMF_2012.php?exercice=' + exercice + '&trimestre=' + trimestre + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>