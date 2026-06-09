<?php
// IDENTIFIANT.php - Informations générales du SFD
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

// ============================================================
// RÉCUPÉRATION DES INFORMATIONS DU SFD
// ============================================================

// 1. Informations depuis la table societes
$nom_sfd = '';
$sigle_sfd = '';
$telephone_sfd = '';
$email_sfd = '';
$adresse_sfd = '';
$ville_sfd = '';
$pays_sfd = 'Côte d\'Ivoire';

try {
    $stmtSociete = $pdo->prepare("
        SELECT * FROM societes WHERE etat_societe = 'Actif' LIMIT 1
    ");
    $stmtSociete->execute();
    $societe = $stmtSociete->fetch();
    if ($societe) {
        $nom_sfd = $societe['nom_societe'] ?? '';
        $sigle_sfd = $societe['sigle_societe'] ?? '';
        $telephone_sfd = $societe['telephone_societe'] ?? '';
        $email_sfd = $societe['email_societe'] ?? '';
        $adresse_sfd = $societe['adresse_societe'] ?? '';
        $ville_sfd = $societe['ville_societe'] ?? '';
        $pays_sfd = $societe['pays_societe'] ?? 'Côte d\'Ivoire';
    }
} catch (PDOException $e) {
    // Table peut ne pas exister
}

// 2. Numéro d'agrément (depuis table agences ou paramètres)
$numero_agrement = '';
try {
    $stmtAgrement = $pdo->prepare("
        SELECT code_agence_bceao as agrement 
        FROM agences 
        WHERE statut = 'active' 
        AND code_agence_bceao IS NOT NULL 
        LIMIT 1
    ");
    $stmtAgrement->execute();
    $agrement = $stmtAgrement->fetch();
    if ($agrement) {
        $numero_agrement = $agrement['agrement'];
    }
} catch (PDOException $e) {
    $numero_agrement = '';
}

// 3. Date de renseignement (date du jour)
$date_renseignement = date('d/m/Y');

// 4. Version (dernière version disponible)
$version = '1';

// 5. Forme (Spécificité) du SFD
$forme_sfd = '';
try {
    $stmtForme = $pdo->prepare("
        SELECT DISTINCT c.categorie as forme
        FROM clients c
        WHERE c.categorie IS NOT NULL
        LIMIT 1
    ");
    $stmtForme->execute();
    $forme = $stmtForme->fetch();
    if ($forme) {
        $forme_sfd = $forme['forme'];
    }
} catch (PDOException $e) {
    $forme_sfd = '';
}

// 6. Récupération des données de la déclaration existante (pour l'exercice)
$declaration = [
    'nom_sfd' => $nom_sfd,
    'numero_agrement' => $numero_agrement,
    'annee' => $exercice,
    'trimestre' => $trimestre,
    'mois' => $mois,
    'version' => $version,
    'forme' => $forme_sfd,
    'date_renseignement' => $date_renseignement
];

// Sauvegarde des modifications (si formulaire soumis)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $declaration['nom_sfd'] = $_POST['nom_sfd'] ?? $nom_sfd;
    $declaration['numero_agrement'] = $_POST['numero_agrement'] ?? $numero_agrement;
    $declaration['annee'] = (int)$_POST['annee'] ?? $exercice;
    $declaration['trimestre'] = (int)$_POST['trimestre'] ?? $trimestre;
    $declaration['mois'] = (int)$_POST['mois'] ?? $mois;
    $declaration['version'] = $_POST['version'] ?? $version;
    $declaration['forme'] = $_POST['forme'] ?? $forme_sfd;
    $declaration['date_renseignement'] = $_POST['date_renseignement'] ?? $date_renseignement;
    
    // Mise à jour des paramètres
    $exercice = $declaration['annee'];
    $trimestre = $declaration['trimestre'];
    $mois = $declaration['mois'];
    
    // Sauvegarde dans une table dédiée (si elle existe)
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS declaration_sfd (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exercice INT NOT NULL,
                trimestre INT NOT NULL,
                mois INT NOT NULL,
                nom_sfd VARCHAR(200),
                numero_agrement VARCHAR(50),
                version VARCHAR(10),
                forme VARCHAR(100),
                date_renseignement DATE,
                date_mise_a_jour TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_exercice_trimestre (exercice, trimestre)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        $stmtSave = $pdo->prepare("
            INSERT INTO declaration_sfd (exercice, trimestre, mois, nom_sfd, numero_agrement, version, forme, date_renseignement)
            VALUES (:exercice, :trimestre, :mois, :nom_sfd, :numero_agrement, :version, :forme, :date_renseignement)
            ON DUPLICATE KEY UPDATE
                mois = VALUES(mois),
                nom_sfd = VALUES(nom_sfd),
                numero_agrement = VALUES(numero_agrement),
                version = VALUES(version),
                forme = VALUES(forme),
                date_renseignement = VALUES(date_renseignement)
        ");
        
        $stmtSave->execute([
            ':exercice' => $exercice,
            ':trimestre' => $trimestre,
            ':mois' => $mois,
            ':nom_sfd' => $declaration['nom_sfd'],
            ':numero_agrement' => $declaration['numero_agrement'],
            ':version' => $declaration['version'],
            ':forme' => $declaration['forme'],
            ':date_renseignement' => date('Y-m-d', strtotime($declaration['date_renseignement']))
        ]);
        
        $message = "Informations enregistrées avec succès !";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Erreur lors de l'enregistrement : " . $e->getMessage();
        $message_type = "error";
    }
}

// Récupération des déclarations précédentes
$historique = [];
try {
    $stmtHisto = $pdo->prepare("
        SELECT * FROM declaration_sfd 
        ORDER BY exercice DESC, trimestre DESC 
        LIMIT 10
    ");
    $stmtHisto->execute();
    $historique = $stmtHisto->fetchAll();
} catch (PDOException $e) {
    $historique = [];
}

// Calcul de l'ID du SFD (pour affichage)
$sfd_id = md5($nom_sfd . $numero_agrement);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IDENTIFIANT - Informations générales du SFD</title>
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
            max-width: 900px;
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
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #1a3a5c;
            box-shadow: 0 0 0 2px rgba(26,58,92,0.1);
        }
        
        .form-row {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .form-row .form-group {
            flex: 1;
            min-width: 150px;
        }
        
        .form-actions {
            padding: 20px;
            border-top: 1px solid #eee;
            text-align: center;
        }
        
        .btn {
            padding: 10px 25px;
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
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin: 0 20px 20px 20px;
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
        
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin: 0 20px 20px 20px;
            border-radius: 8px;
            font-size: 0.9rem;
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
        
        .footer {
            text-align: center;
            padding: 20px;
            color: #777;
            font-size: 0.8rem;
        }
        
        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            padding: 15px 20px;
            background: #f8f9fa;
            border-radius: 8px;
            margin: 0 20px 20px 20px;
            font-size: 0.8rem;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 4px;
        }
        
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>IDENTIFIANT - Informations générales du SFD</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">SICS-BCEAO - Déclaration annuelle</div>
    </div>
    
    <?php if(isset($message)): ?>
        <div class="alert alert-<?= $message_type ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    
    <div class="legend">
        <div class="legend-item">
            <div class="legend-color" style="background: #1a3a5c;"></div>
            <span>Information d'ordre général</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: #2e7d32;"></div>
            <span>Donnée à récupérer directement dans les états financiers</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: #ff9800;"></div>
            <span>Donnée prenant en compte la valeur résiduelle</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: #e0e0e0;"></div>
            <span>Cellule contenant une valeur ou formule à ne pas modifier</span>
        </div>
    </div>
    
    <form method="post" action="">
        <div class="section-card">
            <div class="section-title">🏢 IDENTIFICATION DU SFD</div>
            
            <div class="info-box">
                <strong>ⓘ À propos de ce formulaire :</strong> Ce canevas permet de collecter les données non disponibles dans le canevas électronique SICS-BCEAO 29, à savoir les ratios prudentiels, indicateurs de performance, mouvements d'actifs, statistiques des points de services et conclusions du commissariat aux comptes.
            </div>
            
            <div class="form-group">
                <label>Date de renseignement</label>
                <input type="date" name="date_renseignement" 
                       value="<?= date('Y-m-d', strtotime($declaration['date_renseignement'])) ?>">
            </div>
            
            <div class="form-group">
                <label>Identifiant du SFD</label>
                <input type="text" value="<?= htmlspecialchars($sfd_id) ?>" readonly disabled style="background:#f5f5f5;">
            </div>
            
            <div class="form-group">
                <label>Nom du SFD</label>
                <input type="text" name="nom_sfd" value="<?= htmlspecialchars($declaration['nom_sfd']) ?>" 
                       placeholder="Nom complet du Système Financier Décentralisé">
            </div>
            
            <div class="form-group">
                <label>Numéro d'agrément</label>
                <input type="text" name="numero_agrement" value="<?= htmlspecialchars($declaration['numero_agrement']) ?>" 
                       placeholder="Ex: A11/10-24">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Année d'exercice</label>
                    <select name="annee">
                        <?php for($y = 2020; $y <= date('Y')+1; $y++): ?>
                            <option value="<?= $y ?>" <?= $y == $declaration['annee'] ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Trimestre</label>
                    <select name="trimestre">
                        <option value="1" <?= $declaration['trimestre'] == 1 ? 'selected' : '' ?>>1er Trimestre</option>
                        <option value="2" <?= $declaration['trimestre'] == 2 ? 'selected' : '' ?>>2ème Trimestre</option>
                        <option value="3" <?= $declaration['trimestre'] == 3 ? 'selected' : '' ?>>3ème Trimestre</option>
                        <option value="4" <?= $declaration['trimestre'] == 4 ? 'selected' : '' ?>>4ème Trimestre</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Mois</label>
                    <select name="mois">
                        <?php for($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m == $declaration['mois'] ? 'selected' : '' ?>>
                                <?= str_pad($m, 2, '0', STR_PAD_LEFT) ?> - 
                                <?= date('F', mktime(0,0,0,$m,1)) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Version</label>
                    <input type="text" name="version" value="<?= htmlspecialchars($declaration['version']) ?>" 
                           style="background:#f5f5f5;" readonly>
                </div>
                <div class="form-group">
                    <label>Forme (Spécificité)</label>
                    <select name="forme">
                        <option value="Mutualiste (Faîtière)" <?= $declaration['forme'] == 'Mutualiste (Faîtière)' ? 'selected' : '' ?>>Mutualiste (Faîtière)</option>
                        <option value="Mutualiste (caisse affiliée)" <?= $declaration['forme'] == 'Mutualiste (caisse affiliée)' ? 'selected' : '' ?>>Mutualiste (caisse affiliée)</option>
                        <option value="Mutualiste (Caisse unitaire)" <?= $declaration['forme'] == 'Mutualiste (Caisse unitaire)' ? 'selected' : '' ?>>Mutualiste (Caisse unitaire)</option>
                        <option value="SA (Société Anonyme)" <?= $declaration['forme'] == 'SA (Société Anonyme)' ? 'selected' : '' ?>>SA (Société Anonyme)</option>
                        <option value="SARL" <?= $declaration['forme'] == 'SARL' ? 'selected' : '' ?>>SARL</option>
                        <option value="Association" <?= $declaration['forme'] == 'Association' ? 'selected' : '' ?>>Association</option>
                        <option value="Coopérative" <?= $declaration['forme'] == 'Coopérative' ? 'selected' : '' ?>>Coopérative</option>
                        <option value="Autre" <?= $declaration['forme'] == 'Autre' ? 'selected' : '' ?>>Autre</option>
                    </select>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-success">💾 Enregistrer les informations</button>
            </div>
        </div>
    </form>
    
    <!-- Objectifs du canevas -->
    <div class="section-card">
        <div class="section-title">🎯 OBJECTIFS DU CANEVAS</div>
        <div class="info-box" style="margin: 20px;">
            <strong>Collecter les données non disponibles dans le canevas électronique SICS-BCEAO 29 :</strong>
            <ul style="margin-left: 20px; margin-top: 10px;">
                <li>✓ Données sur les ratios prudentiels</li>
                <li>✓ Données sur les indicateurs de performance</li>
                <li>✓ Données sur les mouvements d'actifs</li>
                <li>✓ Statistiques des points de services</li>
                <li>✓ Conclusions du commissariat aux comptes</li>
            </ul>
        </div>
    </div>
    
    <!-- Historique des déclarations -->
    <?php if(!empty($historique)): ?>
    <div class="section-card">
        <div class="section-title">📜 HISTORIQUE DES DÉCLARATIONS</div>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Exercice</th>
                        <th>Trimestre</th>
                        <th>Nom du SFD</th>
                        <th>N° agrément</th>
                        <th>Date mise à jour</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($historique as $h): ?>
                    <tr>
                        <td><?= $h['exercice'] ?></td>
                        <td><?= $h['trimestre'] ?>ème trimestre</td>
                        <td><?= htmlspecialchars($h['nom_sfd'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($h['numero_agrement'] ?? '-') ?></td>
                        <td><?= date('d/m/Y H:i', strtotime($h['date_mise_a_jour'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Destinataires -->
    <div class="section-card">
        <div class="section-title">📧 DESTINATAIRES</div>
        <div class="info-box" style="margin: 20px;">
            <strong>SFD en activité en Côte d'Ivoire</strong><br>
            Direction des Systèmes Financiers Décentralisés (DSFD)<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)
        </div>
    </div>
    
    <div class="footer">
        Document généré le <?= date('d/m/Y à H:i:s') ?> - Données extraites de la base Mandigo<br>
        Canevas annuel de données complémentaires - Code: DRS X1 - Version 1<br>
        Mis à jour: 2023-05-31
    </div>
</div>
</body>
</html>