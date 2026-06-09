<?php
// DIMF_2011_1.php - État des engagements par signature
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

// Traitement du formulaire
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        // Création de la table si elle n'existe pas
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS engagements_signature (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exercice INT NOT NULL,
                type_engagement VARCHAR(50) NOT NULL,
                montant DECIMAL(15,2) DEFAULT 0,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_exercice_type (exercice, type_engagement)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        if ($_POST['action'] == 'save') {
            // Supprimer les anciennes données pour l'exercice
            $stmtDel = $pdo->prepare("DELETE FROM engagements_signature WHERE exercice = :exercice");
            $stmtDel->execute([':exercice' => $exercice]);
            
            // Insérer les nouvelles données
            $stmtIns = $pdo->prepare("
                INSERT INTO engagements_signature (exercice, type_engagement, montant, description)
                VALUES (:exercice, :type_engagement, :montant, :description)
            ");
            
            $types = ['CT', 'MLT'];
            foreach ($types as $type) {
                $montant = (float)($_POST['montant_' . $type] ?? 0);
                $description = $_POST['description_' . $type] ?? '';
                
                $stmtIns->execute([
                    ':exercice' => $exercice,
                    ':type_engagement' => $type,
                    ':montant' => $montant,
                    ':description' => $description
                ]);
            }
            
            $message = "Engagements par signature enregistrés avec succès !";
            $message_type = "success";
        }
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }
}

// Récupération des données existantes
$engagements = [
    'CT' => ['montant' => 0, 'description' => ''],
    'MLT' => ['montant' => 0, 'description' => '']
];

try {
    $stmt = $pdo->prepare("
        SELECT * FROM engagements_signature 
        WHERE exercice = :exercice
    ");
    $stmt->execute([':exercice' => $exercice]);
    $results = $stmt->fetchAll();
    
    foreach ($results as $row) {
        if ($row['type_engagement'] == 'CT') {
            $engagements['CT']['montant'] = $row['montant'];
            $engagements['CT']['description'] = $row['description'];
        } elseif ($row['type_engagement'] == 'MLT') {
            $engagements['MLT']['montant'] = $row['montant'];
            $engagements['MLT']['description'] = $row['description'];
        }
    }
} catch (PDOException $e) {
    // Table n'existe pas encore
}

// Récupération des engagements depuis les garanties (calcul automatique)
$engagements_calcules = [
    'CT' => 0,
    'MLT' => 0
];

try {
    // Engagements à court terme (garanties avec durée <= 12 mois)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(g.valeur_nette), 0) as total
        FROM garanties g
        INNER JOIN dossiers d ON g.credit_id = d.dossier_id
        WHERE g.statut = 'actif' AND d.duree <= 12
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $engagements_calcules['CT'] = $result['total'];
    
    // Engagements à moyen et long terme (garanties avec durée > 12 mois)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(g.valeur_nette), 0) as total
        FROM garanties g
        INNER JOIN dossiers d ON g.credit_id = d.dossier_id
        WHERE g.statut = 'actif' AND d.duree > 12
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $engagements_calcules['MLT'] = $result['total'];
} catch (PDOException $e) {
    $engagements_calcules = ['CT' => 0, 'MLT' => 0];
}

$total_engagements = $engagements_calcules['CT'] + $engagements_calcules['MLT'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2011_1 - État des engagements par signature</title>
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
            max-width: 1000px;
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
        
        .btn-success {
            background: #2e7d32;
            color: white;
        }
        
        .btn-success:hover {
            background: #1b5e20;
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
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin: 0 15px 15px 15px;
        }
        
        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #2e7d32;
        }
        
        .alert-error {
            background: #ffebee;
            color: #c62828;
            border-left: 4px solid #c62828;
        }
        
        .form-group {
            margin-bottom: 20px;
            padding: 0 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #555;
        }
        
        .form-group input, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-actions {
            padding: 20px;
            text-align: center;
            border-top: 1px solid #eee;
        }
        
        .calculated-value {
            background: #e8f5e9;
            font-weight: bold;
        }
        
        @media (max-width: 768px) {
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>DIMF_2011_1 - ÉTAT DES ENGAGEMENTS PAR SIGNATURE</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">SICS-BCEAO - Engagements hors bilan</div>
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
    
    <?php if($message): ?>
        <div class="alert alert-<?= $message_type ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    
    <div class="info-box">
        <strong>ⓘ Note :</strong> Les engagements par signature représentent les garanties données par l'institution (cautionnements, avals, garanties autonomes, etc.).
        Les montants sont calculés automatiquement à partir des garanties enregistrées dans la base.
    </div>
    
    <!-- Tableau récapitulatif -->
    <div class="section-card">
        <div class="section-title">📊 ENGAGEMENTS PAR SIGNATURE</div>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>CODE</th>
                        <th>LIBELLÉS</th>
                        <th class="text-right">Montant (FCFA)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>ZC18</td>
                        <td>Encours des engagements par signature donnés à court terme</td>
                        <td class="text-right <?= $engagements_calcules['CT'] > 0 ? 'calculated-value' : '' ?>">
                            <?= number_format($engagements_calcules['CT'], 0, ',', ' ') ?>
                        </td>
                    </tr>
                    <tr>
                        <td>ZC19</td>
                        <td>Encours des engagements par signature donnés à moyen et long termes</td>
                        <td class="text-right <?= $engagements_calcules['MLT'] > 0 ? 'calculated-value' : '' ?>">
                            <?= number_format($engagements_calcules['MLT'], 0, ',', ' ') ?>
                        </td>
                    </tr>
                    <tr class="total-row">
                        <td><strong>TOTAL</strong></td>
                        <td><strong>Engagements par signature</strong></td>
                        <td class="text-right"><strong><?= number_format($total_engagements, 0, ',', ' ') ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Formulaire de saisie manuelle -->
    <form method="post" action="">
        <input type="hidden" name="action" value="save">
        
        <div class="section-card">
            <div class="section-title">✏️ SAISIE MANUELLE DES ENGAGEMENTS</div>
            
            <div class="form-group">
                <label>ZC18 - Engagements par signature à court terme (≤ 12 mois) - Montant (FCFA)</label>
                <input type="number" name="montant_CT" step="1" 
                       value="<?= number_format($engagements['CT']['montant'], 0, '', '') ?>"
                       placeholder="Montant des engagements à court terme">
            </div>
            
            <div class="form-group">
                <label>Description des engagements à court terme</label>
                <textarea name="description_CT" placeholder="Nature des engagements (cautionnements, avals, garanties autonomes, etc.)"><?= htmlspecialchars($engagements['CT']['description']) ?></textarea>
            </div>
            
            <div class="form-group">
                <label>ZC19 - Engagements par signature à moyen et long termes (> 12 mois) - Montant (FCFA)</label>
                <input type="number" name="montant_MLT" step="1"
                       value="<?= number_format($engagements['MLT']['montant'], 0, '', '') ?>"
                       placeholder="Montant des engagements à moyen et long termes">
            </div>
            
            <div class="form-group">
                <label>Description des engagements à moyen et long termes</label>
                <textarea name="description_MLT" placeholder="Nature des engagements (cautionnements, avals, garanties autonomes, etc.)"><?= htmlspecialchars($engagements['MLT']['description']) ?></textarea>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-success">💾 Enregistrer les engagements</button>
            </div>
        </div>
    </form>
    
    <!-- Détail des garanties -->
    <div class="section-card">
        <div class="section-title">📋 DÉTAIL DES GARANTIES ACTIVES</div>
        <?php
        // Récupération du détail des garanties
        $details_garanties = [];
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    g.garantie_id,
                    g.libelle_garantie,
                    g.code_type_garantie,
                    g.valeur_nette,
                    g.date_evaluation,
                    g.date_expiration,
                    d.duree as credit_duree,
                    d.dossier_id
                FROM garanties g
                LEFT JOIN dossiers d ON g.credit_id = d.dossier_id
                WHERE g.statut = 'actif'
                ORDER BY g.valeur_nette DESC
                LIMIT 20
            ");
            $stmt->execute();
            $details_garanties = $stmt->fetchAll();
        } catch (PDOException $e) {
            $details_garanties = [];
        }
        ?>
        
        <?php if(empty($details_garanties)): ?>
            <div class="info-box">Aucune garantie active enregistrée.</div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>N° Garantie</th>
                            <th>Libellé</th>
                            <th>Type</th>
                            <th class="text-right">Valeur nette (FCFA)</th>
                            <th>Date évaluation</th>
                            <th>Expiration</th>
                            <th>Durée crédit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($details_garanties as $garantie): ?>
                        <tr>
                            <td><?= htmlspecialchars(substr($garantie['garantie_id'], 0, 8)) ?>...<\/td>
                            <td><?= htmlspecialchars($garantie['libelle_garantie']) ?><\/td>
                            <td>
                                <?php
                                $type_label = '';
                                switch($garantie['code_type_garantie']) {
                                    case '01': $type_label = 'Hypothèque'; break;
                                    case '02': $type_label = 'Nantissement'; break;
                                    case '03': $type_label = 'Caution'; break;
                                    case '04': $type_label = 'Gage'; break;
                                    default: $type_label = 'Autre';
                                }
                                echo $type_label;
                                ?>
                            </td>
                            <td class="text-right"><?= number_format($garantie['valeur_nette'], 0, ',', ' ') ?></td>
                            <td><?= date('d/m/Y', strtotime($garantie['date_evaluation'])) ?></td>
                            <td><?= $garantie['date_expiration'] ? date('d/m/Y', strtotime($garantie['date_expiration'])) : '-' ?></td>
                            <td><?= $garantie['credit_duree'] ? $garantie['credit_duree'] . ' mois' : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
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
        window.location.href = 'DIMF_2011_1.php?exercice=' + exercice + '&trimestre=' + trimestre + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>