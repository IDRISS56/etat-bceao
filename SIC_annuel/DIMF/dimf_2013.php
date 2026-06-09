<?php
// DIMF_2013.php - Prêts aux dirigeants
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
// RÉCUPÉRATION DES PRÊTS AUX DIRIGEANTS
// ============================================================

// Liste des rôles considérés comme dirigeants
$roles_dirigeants = ['Superviseur', 'Administrateur', 'Responsable', 'Directeur'];

$prets_dirigeants = [];
$total_encours_dirigeants = 0;

try {
    // Récupération de tous les prêts accordés aux dirigeants
    $stmt = $pdo->prepare("
        SELECT 
            u.utilisateur_id,
            u.matricule,
            u.nom_prenom,
            u.role,
            u.telephone,
            u.email,
            d.dossier_id,
            d.date_octroi,
            d.montant as montant_initial,
            COALESCE(d.montant - COALESCE(e.rembourse, 0), d.montant) as encours_restant,
            d.duree,
            d.objet,
            d.statut as dossier_statut,
            (SELECT COUNT(*) FROM echeances WHERE dossier_id = d.dossier_id AND statut = 'attente' AND date_echeance < :date_fin) as nb_impayes
        FROM dossiers d
        INNER JOIN utilisateurs u ON d.utilisateur_id = u.utilisateur_id
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE u.role IN ('Superviseur', 'Administrateur', 'Responsable', 'Directeur')
          AND d.statut IN ('actif', 'approuve')
          AND d.date_octroi <= :date_fin
        ORDER BY encours_restant DESC
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $prets_dirigeants = $stmt->fetchAll();
    
    foreach ($prets_dirigeants as $pret) {
        $total_encours_dirigeants += $pret['encours_restant'];
    }
} catch (PDOException $e) {
    $prets_dirigeants = [];
    $total_encours_dirigeants = 0;
}

// Récupération des fonds propres pour calcul du ratio R03
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

// Récupération de tous les utilisateurs avec rôle dirigeant (même sans prêt)
$tous_dirigeants = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            utilisateur_id,
            matricule,
            nom_prenom,
            role,
            telephone,
            email,
            etat
        FROM utilisateurs
        WHERE role IN ('Superviseur', 'Administrateur', 'Responsable', 'Directeur')
          AND etat = 'actif'
        ORDER BY nom_prenom
    ");
    $stmt->execute();
    $tous_dirigeants = $stmt->fetchAll();
} catch (PDOException $e) {
    $tous_dirigeants = [];
}

// Calcul du ratio R03 (Prêts aux dirigeants / Fonds propres)
$ratio_r03 = ($fonds_propres > 0) ? ($total_encours_dirigeants / $fonds_propres) : 0;
$norme_r03 = 0.10; // 10%
$conformite_r03 = ($ratio_r03 <= $norme_r03) ? 'CONFORME' : 'NON CONFORME';

// Récupération des engagements par signature des dirigeants (garanties données)
$engagements_dirigeants = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(g.valeur_nette), 0) as total
        FROM garanties g
        INNER JOIN dossiers d ON g.credit_id = d.dossier_id
        INNER JOIN utilisateurs u ON d.utilisateur_id = u.utilisateur_id
        WHERE u.role IN ('Superviseur', 'Administrateur', 'Responsable', 'Directeur')
          AND g.statut = 'actif'
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $engagements_dirigeants = $result['total'];
} catch (PDOException $e) {
    $engagements_dirigeants = 0;
}

// Exposition totale dirigeants (prêts + engagements)
$exposition_totale_dirigeants = $total_encours_dirigeants + $engagements_dirigeants;
$ratio_exposition = ($fonds_propres > 0) ? ($exposition_totale_dirigeants / $fonds_propres) : 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2013 - Prêts aux dirigeants</title>
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
        
        .ratio-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .ratio-value {
            font-size: 2rem;
            font-weight: bold;
        }
        
        .conforme {
            color: #2e7d32;
        }
        
        .non-conforme {
            color: #c62828;
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
        }
        
        .progress-fill.non-conforme {
            background: linear-gradient(90deg, #c62828, #f44336);
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
        <h1>DIMF_2013 - PRÊTS AUX DIRIGEANTS</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">SICS-BCEAO - Conformité R03</div>
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
    
    <!-- Ratio R03 - Limitation des prêts aux dirigeants -->
    <div class="ratio-card">
        <div class="section-title">📊 R03 - LIMITATION DES PRÊTS AUX DIRIGEANTS</div>
        <div class="info-box">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                <div>
                    <strong>Ratio calculé :</strong>
                    <span class="ratio-value <?= $ratio_r03 <= $norme_r03 ? 'conforme' : 'non-conforme' ?>">
                        <?= number_format($ratio_r03 * 100, 2) ?>%
                    </span>
                </div>
                <div>
                    <strong>Norme BCEAO :</strong> ≤ 10%
                </div>
                <div>
                    <span class="status-badge <?= $conformite_r03 == 'CONFORME' ? 'status-conforme' : 'status-non-conforme' ?>">
                        <?= $conformite_r03 ?>
                    </span>
                </div>
            </div>
            <div class="progress-bar" style="margin-top: 15px;">
                <div class="progress-fill <?= $ratio_r03 <= $norme_r03 ? '' : 'non-conforme' ?>" 
                     style="width: <?= min($ratio_r03 * 100, 100) ?>%;">
                </div>
            </div>
            <div style="margin-top: 15px; font-size: 0.85rem;">
                <strong>Prêts aux dirigeants :</strong> <?= number_format($total_encours_dirigeants, 0, ',', ' ') ?> FCFA<br>
                <strong>Fonds propres :</strong> <?= number_format($fonds_propres, 0, ',', ' ') ?> FCFA<br>
                <strong>Engagements par signature des dirigeants :</strong> <?= number_format($engagements_dirigeants, 0, ',', ' ') ?> FCFA<br>
                <strong>Exposition totale des dirigeants :</strong> <?= number_format($exposition_totale_dirigeants, 0, ',', ' ') ?> FCFA (<?= number_format($ratio_exposition * 100, 2) ?>% des fonds propres)
            </div>
        </div>
    </div>
    
    <!-- Liste des dirigeants -->
    <div class="section-card">
        <div class="section-title">👥 LISTE DES DIRIGEANTS</div>
        <div style="overflow-x: auto;">
            <?php if(empty($tous_dirigeants)): ?>
                <div class="info-box">Aucun dirigeant enregistré dans la base.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Matricule</th>
                            <th>Nom et prénom</th>
                            <th>Fonction</th>
                            <th>Téléphone</th>
                            <th>Email</th>
                            <th class="text-right">Encours prêts (FCFA)</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $dirigeants_avec_prets = [];
                        foreach ($prets_dirigeants as $pret) {
                            $dirigeants_avec_prets[$pret['utilisateur_id']] = true;
                        }
                        
                        foreach ($tous_dirigeants as $dirigeant):
                            $encours_dirigeant = 0;
                            foreach ($prets_dirigeants as $pret) {
                                if ($pret['utilisateur_id'] == $dirigeant['utilisateur_id']) {
                                    $encours_dirigeant += $pret['encours_restant'];
                                }
                            }
                            $has_prets = isset($dirigeants_avec_prets[$dirigeant['utilisateur_id']]);
                        ?>
                            <tr class="<?= $has_prets ? '' : '' ?>">
                                <td><?= htmlspecialchars($dirigeant['matricule'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($dirigeant['nom_prenom'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($dirigeant['role'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($dirigeant['telephone'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($dirigeant['email'] ?? '-') ?></td>
                                <td class="text-right <?= $encours_dirigeant > 0 ? 'conforme' : '' ?>">
                                    <?= $encours_dirigeant > 0 ? number_format($encours_dirigeant, 0, ',', ' ') : '-' ?>
                                </td>
                                <td>
                                    <?php if($has_prets): ?>
                                        <span class="status-badge status-conforme" style="background:#e8f5e9; padding:2px 8px;">A un prêt</span>
                                    <?php else: ?>
                                        <span style="color:#777;">Aucun prêt</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Détail des prêts aux dirigeants -->
    <div class="section-card">
        <div class="section-title">💰 DÉTAIL DES PRÊTS AUX DIRIGEANTS</div>
        <div style="overflow-x: auto;">
            <?php if(empty($prets_dirigeants)): ?>
                <div class="info-box">Aucun prêt en cours accordé aux dirigeants.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Dirigeant</th>
                            <th>Fonction</th>
                            <th>N° Dossier</th>
                            <th>Date octroi</th>
                            <th class="text-right">Montant initial</th>
                            <th class="text-right">Encours restant</th>
                            <th>Durée</th>
                            <th>Objet</th>
                            <th>Impayés</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($prets_dirigeants as $pret): ?>
                        <tr>
                            <td><?= htmlspecialchars($pret['nom_prenom']) ?></td>
                            <td><?= htmlspecialchars($pret['role']) ?></td>
                            <td><?= htmlspecialchars($pret['dossier_id']) ?></td>
                            <td><?= date('d/m/Y', strtotime($pret['date_octroi'])) ?></td>
                            <td class="text-right"><?= number_format($pret['montant_initial'], 0, ',', ' ') ?></td>
                            <td class="text-right"><?= number_format($pret['encours_restant'], 0, ',', ' ') ?></td>
                            <td class="text-center"><?= $pret['duree'] ?> mois</td>
                            <td><?= htmlspecialchars($pret['objet'] ?: '-') ?></td>
                            <td class="text-center <?= $pret['nb_impayes'] > 0 ? 'non-conforme' : '' ?>">
                                <?= $pret['nb_impayes'] ?>
                            </td>
                            <td>
                                <span class="status-badge <?= $pret['dossier_statut'] == 'actif' ? 'status-conforme' : 'status-non-conforme' ?>" style="font-size:0.7rem;">
                                    <?= $pret['dossier_statut'] == 'actif' ? 'Actif' : ucfirst($pret['dossier_statut']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="5"><strong>TOTAL</strong></td>
                            <td class="text-right"><strong><?= number_format($total_encours_dirigeants, 0, ',', ' ') ?></strong></td>
                            <td colspan="4"></td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Interprétation -->
    <div class="section-card">
        <div class="section-title">📖 INTERPRÉTATION</div>
        <div class="info-box">
            <p><strong>Ratio R03 :</strong> <?= number_format($ratio_r03 * 100, 2) ?>%</p>
            <p><strong>Norme :</strong> ≤ 10% des fonds propres</p>
            <?php if($ratio_r03 <= $norme_r03): ?>
                <p style="color: #2e7d32;">✓ Le ratio est <strong>CONFORME</strong> à la réglementation BCEAO.</p>
                <p>Les prêts aux dirigeants représentent <?= number_format($ratio_r03 * 100, 2) ?>% des fonds propres, soit dans la limite autorisée de 10%.</p>
            <?php else: ?>
                <p style="color: #c62828;">✗ Le ratio est <strong>NON CONFORME</strong> à la réglementation BCEAO.</p>
                <p>Les prêts aux dirigeants dépassent la limite de 10% des fonds propres.</p>
                <p>L'institution doit prendre des mesures correctives pour réduire cette exposition.</p>
            <?php endif; ?>
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
        window.location.href = 'DIMF_2013.php?exercice=' + exercice + '&trimestre=' + trimestre + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>