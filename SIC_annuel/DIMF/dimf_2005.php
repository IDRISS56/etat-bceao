<?php
// DIMF_2005.php - Tableau des emplois et ressources
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

// Période précédente
$exercice_prec = $exercice - 1;
$date_fin_prec = $exercice_prec . '-12-31';

// ============================================================
// EMPLOIS
// ============================================================

// I - OPERATIONS AVEC LES MEMBRES, BENEFICIAIRES OU CLIENTS
$B01_emplois_periode_prec = 0;
$B01_emplois_periode_cours = 0;

// B2D - Crédits à court terme (durée ≤ 12 mois)
$B2D_emplois = 0;
$B2D_emplois_prec = 0;

try {
    // Période en cours
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e 
        ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve') AND d.duree <= 12 AND d.date_octroi <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $B2D_emplois = $result['total'];
    
    // Période précédente
    $stmt->execute([':date_fin' => $date_fin_prec]);
    $result = $stmt->fetch();
    $B2D_emplois_prec = $result['total'];
} catch (PDOException $e) {
    $B2D_emplois = 0;
    $B2D_emplois_prec = 0;
}

// B30 - Crédits à moyen terme (13-60 mois)
$B30_emplois = 0;
$B30_emplois_prec = 0;

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e 
        ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve') AND d.duree BETWEEN 13 AND 60 AND d.date_octroi <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $B30_emplois = $result['total'];
    
    $stmt->execute([':date_fin' => $date_fin_prec]);
    $result = $stmt->fetch();
    $B30_emplois_prec = $result['total'];
} catch (PDOException $e) {
    $B30_emplois = 0;
    $B30_emplois_prec = 0;
}

// B40 - Crédits à long terme (>60 mois)
$B40_emplois = 0;
$B40_emplois_prec = 0;

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e 
        ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve') AND d.duree > 60 AND d.date_octroi <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $B40_emplois = $result['total'];
    
    $stmt->execute([':date_fin' => $date_fin_prec]);
    $result = $stmt->fetch();
    $B40_emplois_prec = $result['total'];
} catch (PDOException $e) {
    $B40_emplois = 0;
    $B40_emplois_prec = 0;
}

// B70 - Créances en souffrance
$B70_emplois = 0;
$B70_emplois_prec = 0;

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e 
        ON d.dossier_id = e.dossier_id
        WHERE d.statut = 'impaye' AND d.date_octroi <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $B70_emplois = $result['total'];
    
    $stmt->execute([':date_fin' => $date_fin_prec]);
    $result = $stmt->fetch();
    $B70_emplois_prec = $result['total'];
} catch (PDOException $e) {
    $B70_emplois = 0;
    $B70_emplois_prec = 0;
}

// TOTAL I - Emplois avec membres
$total_emplois_I_prec = $B2D_emplois_prec + $B30_emplois_prec + $B40_emplois_prec + $B70_emplois_prec;
$total_emplois_I_cours = $B2D_emplois + $B30_emplois + $B40_emplois + $B70_emplois;

// ============================================================
// RESSOURCES
// ============================================================

// III - DEPOTS ET EMPRUNTS
// G10 - Comptes ordinaires créditeurs
$G10_ressources = 0;
$G10_ressources_prec = 0;

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(ABS(solde)), 0) as total
        FROM comptes
        WHERE solde < 0 AND statut = 'actif' AND date_ouverture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $G10_ressources = $result['total'];
    
    $stmt->execute([':date_fin' => $date_fin_prec]);
    $result = $stmt->fetch();
    $G10_ressources_prec = $result['total'];
} catch (PDOException $e) {
    $G10_ressources = 0;
    $G10_ressources_prec = 0;
}

// G15 - Dépôts à terme reçus
$G15_ressources = 0;
$G15_ressources_prec = 0;

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(capital_initial), 0) as total
        FROM comptes_dat
        WHERE statut = 'en cours' AND date_ouverture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $G15_ressources = $result['total'];
    
    $stmt->execute([':date_fin' => $date_fin_prec]);
    $result = $stmt->fetch();
    $G15_ressources_prec = $result['total'];
} catch (PDOException $e) {
    $G15_ressources = 0;
    $G15_ressources_prec = 0;
}

// G2A - Comptes d'épargne à régime spécial
$G2A_ressources = 0;
$G2A_ressources_prec = 0;

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(c.solde), 0) as total
        FROM comptes c
        INNER JOIN produits p ON c.produit_id = p.produit_id
        INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id
        WHERE pf.categorie = 'Epargne' AND c.statut = 'actif' AND c.solde > 0 AND c.date_ouverture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $G2A_ressources = $result['total'];
    
    $stmt->execute([':date_fin' => $date_fin_prec]);
    $result = $stmt->fetch();
    $G2A_ressources_prec = $result['total'];
} catch (PDOException $e) {
    $G2A_ressources = 0;
    $G2A_ressources_prec = 0;
}

// G30 - Autres dépôts de garantie reçus
$G30_ressources = 0;
$G30_ressources_prec = 0;

// TOTAL III - Dépôts et emprunts
$total_ressources_III_prec = $G10_ressources_prec + $G15_ressources_prec + $G2A_ressources_prec + $G30_ressources_prec;
$total_ressources_III_cours = $G10_ressources + $G15_ressources + $G2A_ressources + $G30_ressources;

// V - FONDS PROPRES NETS
$fonds_propres_cours = 0;
$fonds_propres_prec = 0;

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '1' AND e.date_ecriture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $fonds_propres_cours = $result['total'];
    
    $stmt->execute([':date_fin' => $date_fin_prec]);
    $result = $stmt->fetch();
    $fonds_propres_prec = $result['total'];
} catch (PDOException $e) {
    $fonds_propres_cours = 0;
    $fonds_propres_prec = 0;
}

// TOTAL RESSOURCES
$total_ressources_prec = $total_ressources_III_prec + $fonds_propres_prec;
$total_ressources_cours = $total_ressources_III_cours + $fonds_propres_cours;

// Excédent/Déficit
$excedent_prec = $total_ressources_prec - $total_emplois_I_prec;
$excedent_cours = $total_ressources_cours - $total_emplois_I_cours;

// Calcul des variations
$variation_absolue = $total_emplois_I_cours - $total_emplois_I_prec;
$variation_pct = ($total_emplois_I_prec != 0) ? ($variation_absolue / $total_emplois_I_prec) * 100 : 0;

// ============================================================
// DONNÉES POUR LE BILAN PÉRIODE PRÉCÉDENTE (pour affichage)
// ============================================================
$actif_brut_prec = 0;
$actif_net_prec = 0;
$passif_net_prec = 0;

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_debit - montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '2' AND e.date_ecriture <= :date_fin_prec
    ");
    $stmt->execute([':date_fin_prec' => $date_fin_prec]);
    $result = $stmt->fetch();
    $actif_net_prec = abs($result['total']);
} catch (PDOException $e) {
    $actif_net_prec = 0;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2005 - Tableau des emplois et ressources</title>
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
            max-width: 1300px;
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
        
        .text-bold {
            font-weight: bold;
        }
        
        .total-row {
            background: #e8f5e9;
            font-weight: bold;
        }
        
        .subtotal-row {
            background: #f0f7ff;
            font-weight: bold;
        }
        
        .variation-positive {
            color: #2e7d32;
        }
        
        .variation-negative {
            color: #c62828;
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
        
        @media (max-width: 768px) {
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            
            table {
                font-size: 0.75rem;
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
        <h1>DIMF_2005 - TABLEAU DES EMPLOIS ET RESSOURCES</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">SICS-BCEAO - Analyse des variations</div>
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
    
    <!-- Tableau principal des emplois et ressources -->
    <div class="section-card">
        <div class="section-title">📊 EMPLOIS ET RESSOURCES</div>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th colspan="2">LIBELLÉS</th>
                        <th class="text-right">Période précédente (N-1)</th>
                        <th class="text-right">Période en cours (N)</th>
                        <th class="text-right">Variation absolue</th>
                        <th class="text-right">Variation %</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- I - Opérations avec les membres -->
                    <tr class="subtotal-row">
                        <td colspan="2">I - OPERATIONS AVEC LES MEMBRES, BENEFICIAIRES OU CLIENTS</td>
                        <td class="text-right"><?= number_format($total_emplois_I_prec, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($total_emplois_I_cours, 0, ',', ' ') ?></td>
                        <td class="text-right <?= ($total_emplois_I_cours - $total_emplois_I_prec) >= 0 ? 'variation-positive' : 'variation-negative' ?>">
                            <?= number_format($total_emplois_I_cours - $total_emplois_I_prec, 0, ',', ' ') ?>
                        </td>
                        <td class="text-right">
                            <?php if($total_emplois_I_prec != 0): ?>
                                <span class="<?= (($total_emplois_I_cours - $total_emplois_I_prec) / $total_emplois_I_prec) * 100 >= 0 ? 'variation-positive' : 'variation-negative' ?>">
                                    <?= number_format((($total_emplois_I_cours - $total_emplois_I_prec) / $total_emplois_I_prec) * 100, 2) ?>%
                                </span>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                    </tr>
                    <tr><td></td><td>1) Crédits à court terme</td>
                        <td class="text-right"><?= number_format($B2D_emplois_prec, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($B2D_emplois, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($B2D_emplois - $B2D_emplois_prec, 0, ',', ' ') ?></td>
                        <td class="text-right">-</td>
                    </tr>
                    <tr><td></td><td>2) Crédits à moyen terme</td>
                        <td class="text-right"><?= number_format($B30_emplois_prec, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($B30_emplois, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($B30_emplois - $B30_emplois_prec, 0, ',', ' ') ?></td>
                        <td class="text-right">-</td>
                    </tr>
                    <tr><td></td><td>3) Crédits à long terme</td>
                        <td class="text-right"><?= number_format($B40_emplois_prec, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($B40_emplois, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($B40_emplois - $B40_emplois_prec, 0, ',', ' ') ?></td>
                        <td class="text-right">-</td>
                    </tr>
                    <tr><td></td><td>5) Créances en souffrance</td>
                        <td class="text-right"><?= number_format($B70_emplois_prec, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($B70_emplois, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($B70_emplois - $B70_emplois_prec, 0, ',', ' ') ?></td>
                        <td class="text-right">-</td>
                    </tr>
                    
                    <!-- III - Dépôts et emprunts -->
                    <tr class="subtotal-row">
                        <td colspan="2">III - DÉPÔTS ET EMPRUNTS</td>
                        <td class="text-right"><?= number_format($total_ressources_III_prec, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($total_ressources_III_cours, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($total_ressources_III_cours - $total_ressources_III_prec, 0, ',', ' ') ?></td>
                        <td class="text-right">-</td>
                    </tr>
                    <tr><td></td><td>29) Comptes ordinaires créditeurs</td>
                        <td class="text-right"><?= number_format($G10_ressources_prec, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($G10_ressources, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($G10_ressources - $G10_ressources_prec, 0, ',', ' ') ?></td>
                        <td class="text-right">-</td>
                    </tr>
                    <tr><td></td><td>30) Dépôts à terme reçus</td>
                        <td class="text-right"><?= number_format($G15_ressources_prec, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($G15_ressources, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($G15_ressources - $G15_ressources_prec, 0, ',', ' ') ?></td>
                        <td class="text-right">-</td>
                    </tr>
                    <tr><td></td><td>31) Comptes d'épargne à régime spécial</td>
                        <td class="text-right"><?= number_format($G2A_ressources_prec, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($G2A_ressources, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($G2A_ressources - $G2A_ressources_prec, 0, ',', ' ') ?></td>
                        <td class="text-right">-</td>
                    </tr>
                    
                    <!-- V - Fonds propres nets -->
                    <tr class="subtotal-row">
                        <td colspan="2">V - FONDS PROPRES NETS</td>
                        <td class="text-right"><?= number_format($fonds_propres_prec, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($fonds_propres_cours, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($fonds_propres_cours - $fonds_propres_prec, 0, ',', ' ') ?></td>
                        <td class="text-right">-</td>
                    </tr>
                    
                    <!-- TOTAL RESSOURCES -->
                    <tr class="total-row">
                        <td colspan="2">B - TOTAL RESSOURCES (III + V)</td>
                        <td class="text-right"><?= number_format($total_ressources_prec, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($total_ressources_cours, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($total_ressources_cours - $total_ressources_prec, 0, ',', ' ') ?></td>
                        <td class="text-right">-</td>
                    </tr>
                    
                    <!-- Excédent / Déficit -->
                    <tr class="subtotal-row">
                        <td colspan="2">C - EXCÉDENT (+) OU DÉFICIT (-) (B - A)</td>
                        <td class="text-right"><?= number_format($excedent_prec, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($excedent_cours, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($excedent_cours - $excedent_prec, 0, ',', ' ') ?></td>
                        <td class="text-right">-</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Synthèse -->
    <div class="section-card">
        <div class="section-title">📈 SYNTHÈSE DES VARIATIONS</div>
        <div class="info-box">
            <strong>Évolution du portefeuille de crédits :</strong><br>
            <span class="<?= ($total_emplois_I_cours - $total_emplois_I_prec) >= 0 ? 'variation-positive' : 'variation-negative' ?>">
                <?= number_format(abs($total_emplois_I_cours - $total_emplois_I_prec), 0, ',', ' ') ?> FCFA
                (<?= number_format($variation_pct, 2) ?>%)
            </span><br><br>
            <strong>Évolution des fonds propres :</strong><br>
            <span class="<?= ($fonds_propres_cours - $fonds_propres_prec) >= 0 ? 'variation-positive' : 'variation-negative' ?>">
                <?= number_format(abs($fonds_propres_cours - $fonds_propres_prec), 0, ',', ' ') ?> FCFA
            </span>
        </div>
    </div>
    
    <!-- Bilan période précédente -->
    <div class="section-card">
        <div class="section-title">📋 BILAN PÉRIODE PRÉCÉDENTE (N-1)</div>
        <div class="info-box">
            <strong>Total actif net (N-1) :</strong> <?= number_format($actif_net_prec, 0, ',', ' ') ?> FCFA<br>
            <strong>Total passif (N-1) :</strong> <?= number_format($total_ressources_prec, 0, ',', ' ') ?> FCFA
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
        window.location.href = 'DIMF_2005.php?exercice=' + exercice + '&trimestre=' + trimestre + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>