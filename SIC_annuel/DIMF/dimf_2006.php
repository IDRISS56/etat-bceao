<?php
// DIMF_2006.php - État des biens donnés en crédit-bail et opérations assimilées
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
// RÉCUPÉRATION DES DONNÉES DE CRÉDIT-BAIL
// ============================================================

// Structure des catégories de crédit-bail
$categories = [
    'ZA1' => ['code' => 'ZA1', 'libelle' => 'CRÉDIT-BAIL', 'is_parent' => true],
    'ZA2' => ['code' => 'ZA2', 'libelle' => 'Crédit-bail Mobilier', 'parent' => 'ZA1'],
    'ZA3' => ['code' => 'ZA3', 'libelle' => 'Crédit-bail Immobilier', 'parent' => 'ZA1'],
    'ZA4' => ['code' => 'ZA4', 'libelle' => 'Crédit-bail sur actifs incorporels', 'parent' => 'ZA1'],
    'ZA5' => ['code' => 'ZA5', 'libelle' => 'Location avec option d\'achat', 'parent' => 'ZA1'],
    'ZA6' => ['code' => 'ZA6', 'libelle' => 'LOCATION-VENTE', 'is_parent' => true],
    'ZA7' => ['code' => 'ZA7', 'libelle' => 'CRÉANCES EN SOUFFRANCE SUR OPÉRATIONS DE CRÉDIT-BAIL ET ASSIMILÉES', 'is_parent' => true]
];

// Initialisation des données
$data = [];
foreach ($categories as $key => $cat) {
    $data[$key] = [
        'code' => $cat['code'],
        'libelle' => $cat['libelle'],
        'duree' => '',
        'montant_brut' => 0,
        'amortissements' => 0,
        'montant_net' => 0,
        'is_parent' => isset($cat['is_parent']) ? $cat['is_parent'] : false,
        'parent' => isset($cat['parent']) ? $cat['parent'] : null
    ];
}

// Récupération des données depuis la table des immobilisations (si existante)
// Les crédit-bail sont généralement des immobilisations avec un type spécifique
try {
    // Vérifier si la table immobilisations a une colonne type_credit_bail
    $stmt = $pdo->query("SHOW COLUMNS FROM immobilisations LIKE 'type_credit_bail'");
    $has_column = $stmt->rowCount() > 0;
    
    if ($has_column) {
        // Crédit-bail mobilier
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(montant_achat), 0) as montant_brut,
                COALESCE(SUM(amortissement_total), 0) as amortissements
            FROM immobilisations
            WHERE type_credit_bail = 'MOBILIER' AND statut = 'actif' AND date_achat <= :date_fin
        ");
        $stmt->execute([':date_fin' => $date_fin_periode]);
        $result = $stmt->fetch();
        $data['ZA2']['montant_brut'] = $result['montant_brut'];
        $data['ZA2']['amortissements'] = $result['amortissements'];
        $data['ZA2']['montant_net'] = $result['montant_brut'] - $result['amortissements'];
        
        // Crédit-bail immobilier
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(montant_achat), 0) as montant_brut,
                COALESCE(SUM(amortissement_total), 0) as amortissements
            FROM immobilisations
            WHERE type_credit_bail = 'IMMOBILIER' AND statut = 'actif' AND date_achat <= :date_fin
        ");
        $stmt->execute([':date_fin' => $date_fin_periode]);
        $result = $stmt->fetch();
        $data['ZA3']['montant_brut'] = $result['montant_brut'];
        $data['ZA3']['amortissements'] = $result['amortissements'];
        $data['ZA3']['montant_net'] = $result['montant_brut'] - $result['amortissements'];
        
        // Location avec option d'achat
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(montant_achat), 0) as montant_brut,
                COALESCE(SUM(amortissement_total), 0) as amortissements
            FROM immobilisations
            WHERE type_credit_bail = 'LOA' AND statut = 'actif' AND date_achat <= :date_fin
        ");
        $stmt->execute([':date_fin' => $date_fin_periode]);
        $result = $stmt->fetch();
        $data['ZA5']['montant_brut'] = $result['montant_brut'];
        $data['ZA5']['amortissements'] = $result['amortissements'];
        $data['ZA5']['montant_net'] = $result['montant_brut'] - $result['amortissements'];
    }
} catch (PDOException $e) {
    // Table ou colonne n'existe pas
}

// Calcul des totaux par catégorie parente
$total_credit_bail = $data['ZA2']['montant_net'] + $data['ZA3']['montant_net'] + $data['ZA4']['montant_net'] + $data['ZA5']['montant_net'];
$data['ZA1']['montant_net'] = $total_credit_bail;
$data['ZA1']['montant_brut'] = $data['ZA2']['montant_brut'] + $data['ZA3']['montant_brut'] + $data['ZA4']['montant_brut'] + $data['ZA5']['montant_brut'];
$data['ZA1']['amortissements'] = $data['ZA2']['amortissements'] + $data['ZA3']['amortissements'] + $data['ZA4']['amortissements'] + $data['ZA5']['amortissements'];

$total_general = $total_credit_bail + $data['ZA6']['montant_net'] + $data['ZA7']['montant_net'];

// Récupération des détails des contrats de crédit-bail
$details_contrats = [];
try {
    // Vérifier si une table des contrats de crédit-bail existe
    $stmt = $pdo->query("SHOW TABLES LIKE 'credit_bail_contrats'");
    if ($stmt->rowCount() > 0) {
        $stmtDetails = $pdo->prepare("
            SELECT * FROM credit_bail_contrats 
            WHERE exercice = :exercice 
            ORDER BY date_debut DESC
        ");
        $stmtDetails->execute([':exercice' => $exercice]);
        $details_contrats = $stmtDetails->fetchAll();
    }
} catch (PDOException $e) {
    $details_contrats = [];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2006 - État des biens donnés en crédit-bail</title>
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
            font-size: 0.85rem;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .text-right {
            text-align: right;
        }
        
        .total-row {
            background: #e8f5e9;
            font-weight: bold;
        }
        
        .parent-row {
            background: #f0f7ff;
            font-weight: bold;
        }
        
        .child-row {
            padding-left: 30px;
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
        
        .child-indent {
            padding-left: 30px;
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
        <h1>DIMF_2006 - ÉTAT DES BIENS DONNÉS EN CRÉDIT-BAIL</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">SICS-BCEAO - Crédit-bail et opérations assimilées</div>
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
        <strong>ⓘ Note :</strong> Cet état présente les biens donnés en crédit-bail et opérations assimilées (location avec option d'achat, location-vente).
        Les montants sont présentés en valeur brute, amortissements/provisions et valeur nette.
    </div>
    
    <!-- Tableau principal -->
    <div class="section-card">
        <div class="section-title">🏗️ CRÉDIT-BAIL ET OPÉRATIONS ASSIMILÉES</div>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>CODE</th>
                        <th>LIBELLÉS</th>
                        <th class="text-right">Durée (mois)</th>
                        <th class="text-right">Montants Bruts (FCFA)</th>
                        <th class="text-right">Amortissements/Provisions (FCFA)</th>
                        <th class="text-right">Montants nets (FCFA)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $key => $item): ?>
                        <?php if ($item['is_parent']): ?>
                            <tr class="parent-row">
                                <td><strong><?= $item['code'] ?></strong></td>
                                <td colspan="5"><strong><?= htmlspecialchars($item['libelle']) ?></strong></td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td><?= $item['code'] ?></td>
                                <td class="child-indent"><?= htmlspecialchars($item['libelle']) ?></td>
                                <td class="text-right"><?= htmlspecialchars($item['duree']) ?></td>
                                <td class="text-right"><?= number_format($item['montant_brut'], 0, ',', ' ') ?></td>
                                <td class="text-right"><?= number_format($item['amortissements'], 0, ',', ' ') ?></td>
                                <td class="text-right"><?= number_format($item['montant_net'], 0, ',', ' ') ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="5"><strong>TOTAL</strong></td>
                        <td class="text-right"><strong><?= number_format($total_general, 0, ',', ' ') ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Détail des contrats -->
    <?php if(!empty($details_contrats)): ?>
    <div class="section-card">
        <div class="section-title">📋 DÉTAIL DES CONTRATS DE CRÉDIT-BAIL</div>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>N° Contrat</th>
                        <th>Type</th>
                        <th>Durée</th>
                        <th>Date début</th>
                        <th>Date fin</th>
                        <th class="text-right">Montant brut</th>
                        <th class="text-right">Valeur nette</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($details_contrats as $contrat): ?>
                    <tr>
                        <td><?= htmlspecialchars($contrat['numero_contrat'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($contrat['type'] ?? '-') ?></td>
                        <td class="text-right"><?= htmlspecialchars($contrat['duree'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($contrat['date_debut'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($contrat['date_fin'] ?? '-') ?></td>
                        <td class="text-right"><?= number_format($contrat['montant_brut'] ?? 0, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($contrat['valeur_nette'] ?? 0, 0, ',', ' ') ?></td>
                        <td><?= htmlspecialchars($contrat['statut'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php else: ?>
    <div class="section-card">
        <div class="section-title">📋 DÉTAIL DES CONTRATS DE CRÉDIT-BAIL</div>
        <div class="info-box">
            Aucun contrat de crédit-bail enregistré pour l'exercice <?= $exercice ?>.
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Formulaire d'ajout rapide (optionnel) -->
    <div class="section-card">
        <div class="section-title">➕ AJOUTER UN CONTRAT DE CRÉDIT-BAIL</div>
        <div class="info-box">
            <form method="post" action="">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <div>
                        <label>Type de contrat</label>
                        <select name="type_contrat" style="width:100%; padding:8px;">
                            <option value="">-- Sélectionner --</option>
                            <option value="MOBILIER">Crédit-bail Mobilier</option>
                            <option value="IMMOBILIER">Crédit-bail Immobilier</option>
                            <option value="INCORPOREL">Crédit-bail sur actifs incorporels</option>
                            <option value="LOA">Location avec option d'achat</option>
                            <option value="VENTE">Location-vente</option>
                        </select>
                    </div>
                    <div>
                        <label>Durée (mois)</label>
                        <input type="number" name="duree" placeholder="Durée en mois">
                    </div>
                    <div>
                        <label>Montant brut (FCFA)</label>
                        <input type="number" name="montant_brut" placeholder="Montant brut">
                    </div>
                    <div>
                        <label>Date début</label>
                        <input type="date" name="date_debut">
                    </div>
                    <div>
                        <label>Date fin</label>
                        <input type="date" name="date_fin">
                    </div>
                </div>
                <div style="margin-top: 15px;">
                    <button type="submit" class="btn btn-primary">Enregistrer le contrat</button>
                </div>
            </form>
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
        window.location.href = 'DIMF_2006.php?exercice=' + exercice + '&trimestre=' + trimestre + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>