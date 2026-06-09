<?php
// DIMF_2009.php - Détail du compte 6221 (Personnel extérieur à l'institution)
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
$date_debut_exercice = $exercice . '-01-01';

// Traitement du formulaire d'ajout
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        // Création de la table si elle n'existe pas
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS personnel_exterieur (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exercice INT NOT NULL,
                categorie VARCHAR(100) NOT NULL,
                nationaux INT DEFAULT 0,
                autre_umoa INT DEFAULT 0,
                hors_umoa INT DEFAULT 0,
                secteur_primaire INT DEFAULT 0,
                secteur_secondaire INT DEFAULT 0,
                secteur_tertiaire INT DEFAULT 0,
                total_effectif INT DEFAULT 0,
                facturation DECIMAL(15,2) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_exercice_categorie (exercice, categorie)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        if ($_POST['action'] == 'save') {
            // Supprimer les anciennes données pour l'exercice
            $stmt = $pdo->prepare("DELETE FROM personnel_exterieur WHERE exercice = :exercice");
            $stmt->execute([':exercice' => $exercice]);
            
            // Insérer les nouvelles données
            $stmt = $pdo->prepare("
                INSERT INTO personnel_exterieur (
                    exercice, categorie, nationaux, autre_umoa, hors_umoa,
                    secteur_primaire, secteur_secondaire, secteur_tertiaire,
                    total_effectif, facturation
                ) VALUES (
                    :exercice, :categorie, :nationaux, :autre_umoa, :hors_umoa,
                    :secteur_primaire, :secteur_secondaire, :secteur_tertiaire,
                    :total_effectif, :facturation
                )
            ");
            
            $categories = [
                'ZB1' => ['libelle' => 'Cadres Supérieurs'],
                'ZB2' => ['libelle' => 'Techniciens Supérieurs et cadres moyens'],
                'ZB3' => ['libelle' => 'Techniciens Agents de Maîtrise et ouvriers qualifiés'],
                'ZB4' => ['libelle' => 'Employés, manœuvres, ouvriers et apprentis']
            ];
            
            foreach ($categories as $code => $cat) {
                $nationaux = (int)($_POST[$code . '_nationaux'] ?? 0);
                $autre_umoa = (int)($_POST[$code . '_autre_umoa'] ?? 0);
                $hors_umoa = (int)($_POST[$code . '_hors_umoa'] ?? 0);
                $secteur_primaire = (int)($_POST[$code . '_secteur_primaire'] ?? 0);
                $secteur_secondaire = (int)($_POST[$code . '_secteur_secondaire'] ?? 0);
                $secteur_tertiaire = (int)($_POST[$code . '_secteur_tertiaire'] ?? 0);
                $total_effectif = $nationaux + $autre_umoa + $hors_umoa;
                $facturation = (float)($_POST[$code . '_facturation'] ?? 0);
                
                $stmt->execute([
                    ':exercice' => $exercice,
                    ':categorie' => $code,
                    ':nationaux' => $nationaux,
                    ':autre_umoa' => $autre_umoa,
                    ':hors_umoa' => $hors_umoa,
                    ':secteur_primaire' => $secteur_primaire,
                    ':secteur_secondaire' => $secteur_secondaire,
                    ':secteur_tertiaire' => $secteur_tertiaire,
                    ':total_effectif' => $total_effectif,
                    ':facturation' => $facturation
                ]);
            }
            
            $message = "Données enregistrées avec succès !";
            $message_type = "success";
        }
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }
}

// Récupération des données existantes
$personnel_data = [];
$categories = [
    'ZB1' => 'Cadres Supérieurs',
    'ZB2' => 'Techniciens Supérieurs et cadres moyens',
    'ZB3' => 'Techniciens Agents de Maîtrise et ouvriers qualifiés',
    'ZB4' => 'Employés, manœuvres, ouvriers et apprentis'
];

try {
    $stmt = $pdo->prepare("
        SELECT * FROM personnel_exterieur 
        WHERE exercice = :exercice
    ");
    $stmt->execute([':exercice' => $exercice]);
    $results = $stmt->fetchAll();
    
    foreach ($results as $row) {
        $personnel_data[$row['categorie']] = $row;
    }
} catch (PDOException $e) {
    $personnel_data = [];
}

// Calcul des totaux
$totaux = [
    'nationaux' => 0,
    'autre_umoa' => 0,
    'hors_umoa' => 0,
    'secteur_primaire' => 0,
    'secteur_secondaire' => 0,
    'secteur_tertiaire' => 0,
    'total_effectif' => 0,
    'facturation' => 0
];

foreach ($categories as $code => $libelle) {
    $data = $personnel_data[$code] ?? null;
    if ($data) {
        $totaux['nationaux'] += $data['nationaux'];
        $totaux['autre_umoa'] += $data['autre_umoa'];
        $totaux['hors_umoa'] += $data['hors_umoa'];
        $totaux['secteur_primaire'] += $data['secteur_primaire'];
        $totaux['secteur_secondaire'] += $data['secteur_secondaire'];
        $totaux['secteur_tertiaire'] += $data['secteur_tertiaire'];
        $totaux['total_effectif'] += $data['total_effectif'];
        $totaux['facturation'] += $data['facturation'];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2009 - Détail du compte 6221 (Personnel extérieur)</title>
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
            text-align: center;
        }
        
        td {
            text-align: center;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-left {
            text-align: left;
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
        
        input[type="number"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 6px;
            text-align: right;
        }
        
        .form-actions {
            padding: 20px;
            text-align: center;
            border-top: 1px solid #eee;
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
                padding: 6px 4px;
            }
            
            input[type="number"] {
                min-width: 60px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>DIMF_2009 - DÉTAIL DU COMPTE 6221</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">SICS-BCEAO - Personnel extérieur à l'institution</div>
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
        <strong>ⓘ Note :</strong> Ce tableau détaille le personnel extérieur à l'institution (prestataires, consultants, intérimaires, etc.)
        inscrit au compte 6221. Sont concernés les effectifs et les montants facturés à l'institution.
    </div>
    
    <form method="post" action="">
        <input type="hidden" name="action" value="save">
        
        <div class="section-card">
            <div class="section-title">📊 PERSONNEL EXTÉRIEUR - EFFECTIFS ET FACTURATION</div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th rowspan="2">Libellés</th>
                            <th colspan="3">EFFECTIF (en unités)</th>
                            <th colspan="3">Par secteur d'activité</th>
                            <th rowspan="2" class="text-right">TOTAL</th>
                            <th rowspan="2" class="text-right">FACTURATION À L'INSTITUTION (FCFA)</th>
                         </tr>
                        <tr>
                            <th>NATIONAUX</th>
                            <th>Autres États UMOA</th>
                            <th>Hors UMOA</th>
                            <th>Primaire</th>
                            <th>Secondaire</th>
                            <th>Tertiaire</th>
                         </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $code => $libelle): ?>
                            <?php 
                            $data = $personnel_data[$code] ?? [
                                'nationaux' => 0, 'autre_umoa' => 0, 'hors_umoa' => 0,
                                'secteur_primaire' => 0, 'secteur_secondaire' => 0, 'secteur_tertiaire' => 0,
                                'total_effectif' => 0, 'facturation' => 0
                            ];
                            ?>
                            <tr>
                                <td class="text-left"><?= htmlspecialchars($libelle) ?></td>
                                <td><input type="number" name="<?= $code ?>_nationaux" value="<?= $data['nationaux'] ?>"></td>
                                <td><input type="number" name="<?= $code ?>_autre_umoa" value="<?= $data['autre_umoa'] ?>"></td>
                                <td><input type="number" name="<?= $code ?>_hors_umoa" value="<?= $data['hors_umoa'] ?>"></td>
                                <td><input type="number" name="<?= $code ?>_secteur_primaire" value="<?= $data['secteur_primaire'] ?>"></td>
                                <td><input type="number" name="<?= $code ?>_secteur_secondaire" value="<?= $data['secteur_secondaire'] ?>"></td>
                                <td><input type="number" name="<?= $code ?>_secteur_tertiaire" value="<?= $data['secteur_tertiaire'] ?>"></td>
                                <td class="text-right"><strong><?= $data['total_effectif'] ?></strong></td>
                                <td><input type="number" name="<?= $code ?>_facturation" value="<?= number_format($data['facturation'], 0, '', '') ?>" step="1"></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td class="text-left"><strong>TOTAL</strong></td>
                            <td><strong><?= $totaux['nationaux'] ?></strong></td>
                            <td><strong><?= $totaux['autre_umoa'] ?></strong></td>
                            <td><strong><?= $totaux['hors_umoa'] ?></strong></td>
                            <td><strong><?= $totaux['secteur_primaire'] ?></strong></td>
                            <td><strong><?= $totaux['secteur_secondaire'] ?></strong></td>
                            <td><strong><?= $totaux['secteur_tertiaire'] ?></strong></td>
                            <td class="text-right"><strong><?= $totaux['total_effectif'] ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux['facturation'], 0, ',', ' ') ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-success">💾 Enregistrer les données</button>
            </div>
        </div>
    </form>
    
    <!-- Synthèse des effectifs par origine -->
    <div class="section-card">
        <div class="section-title">📈 SYNTHÈSE DES EFFECTIFS PAR ORIGINE</div>
        <div class="info-box">
            <strong>Effectifs nationaux :</strong> <?= $totaux['nationaux'] ?> personnes<br>
            <strong>Effectifs autres pays UMOA :</strong> <?= $totaux['autre_umoa'] ?> personnes<br>
            <strong>Effectifs hors UMOA :</strong> <?= $totaux['hors_umoa'] ?> personnes<br>
            <strong>Effectif total :</strong> <?= $totaux['total_effectif'] ?> personnes<br>
            <strong>Facturation totale :</strong> <?= number_format($totaux['facturation'], 0, ',', ' ') ?> FCFA
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
        window.location.href = 'DIMF_2009.php?exercice=' + exercice + '&trimestre=' + trimestre + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>