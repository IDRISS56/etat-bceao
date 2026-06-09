<?php
// DIMF_2007.php - État des biens détenus dans le cadre de la concession
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

// Traitement du formulaire d'ajout
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        // Création de la table si elle n'existe pas
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS biens_concession (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exercice INT NOT NULL,
                libelle VARCHAR(255) NOT NULL,
                valeur_inventaire DECIMAL(15,2) DEFAULT 0,
                concessionnaire_nom VARCHAR(200),
                valeur_declaree DECIMAL(15,2) DEFAULT 0,
                date_acquisition DATE,
                duree_concession INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        if ($_POST['action'] == 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO biens_concession (exercice, libelle, valeur_inventaire, concessionnaire_nom, valeur_declaree, date_acquisition, duree_concession)
                VALUES (:exercice, :libelle, :valeur_inventaire, :concessionnaire, :valeur_declaree, :date_acquisition, :duree)
            ");
            $stmt->execute([
                ':exercice' => $exercice,
                ':libelle' => $_POST['libelle'] ?? '',
                ':valeur_inventaire' => $_POST['valeur_inventaire'] ?? 0,
                ':concessionnaire' => $_POST['concessionnaire_nom'] ?? '',
                ':valeur_declaree' => $_POST['valeur_declaree'] ?? 0,
                ':date_acquisition' => $_POST['date_acquisition'] ?? null,
                ':duree' => $_POST['duree_concession'] ?? null
            ]);
            $message = "Bien en concession ajouté avec succès !";
            $message_type = "success";
        } elseif ($_POST['action'] == 'delete' && isset($_POST['id'])) {
            $stmt = $pdo->prepare("DELETE FROM biens_concession WHERE id = :id AND exercice = :exercice");
            $stmt->execute([':id' => $_POST['id'], ':exercice' => $exercice]);
            $message = "Bien en concession supprimé avec succès !";
            $message_type = "success";
        } elseif ($_POST['action'] == 'update' && isset($_POST['id'])) {
            $stmt = $pdo->prepare("
                UPDATE biens_concession 
                SET libelle = :libelle, 
                    valeur_inventaire = :valeur_inventaire, 
                    concessionnaire_nom = :concessionnaire, 
                    valeur_declaree = :valeur_declaree,
                    date_acquisition = :date_acquisition,
                    duree_concession = :duree
                WHERE id = :id AND exercice = :exercice
            ");
            $stmt->execute([
                ':id' => $_POST['id'],
                ':exercice' => $exercice,
                ':libelle' => $_POST['libelle'] ?? '',
                ':valeur_inventaire' => $_POST['valeur_inventaire'] ?? 0,
                ':concessionnaire' => $_POST['concessionnaire_nom'] ?? '',
                ':valeur_declaree' => $_POST['valeur_declaree'] ?? 0,
                ':date_acquisition' => $_POST['date_acquisition'] ?? null,
                ':duree' => $_POST['duree_concession'] ?? null
            ]);
            $message = "Bien en concession modifié avec succès !";
            $message_type = "success";
        }
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }
}

// Récupération des biens en concession pour l'exercice
$biens_concession = [];
$total_valeur_inventaire = 0;
$total_valeur_declaree = 0;

try {
    $stmt = $pdo->prepare("
        SELECT * FROM biens_concession 
        WHERE exercice = :exercice 
        ORDER BY id
    ");
    $stmt->execute([':exercice' => $exercice]);
    $biens_concession = $stmt->fetchAll();
    
    foreach ($biens_concession as $bien) {
        $total_valeur_inventaire += $bien['valeur_inventaire'];
        $total_valeur_declaree += $bien['valeur_declaree'];
    }
} catch (PDOException $e) {
    $biens_concession = [];
}

// Récupération d'un bien pour édition
$edit_bien = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM biens_concession WHERE id = :id AND exercice = :exercice");
        $stmt->execute([':id' => $_GET['edit'], ':exercice' => $exercice]);
        $edit_bien = $stmt->fetch();
    } catch (PDOException $e) {
        $edit_bien = null;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2007 - État des biens détenus dans le cadre de la concession</title>
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
        
        .btn-success {
            background: #2e7d32;
            color: white;
        }
        
        .btn-success:hover {
            background: #1b5e20;
        }
        
        .btn-danger {
            background: #c62828;
            color: white;
        }
        
        .btn-danger:hover {
            background: #b71c1c;
        }
        
        .btn-warning {
            background: #ff9800;
            color: white;
        }
        
        .btn-warning:hover {
            background: #f57c00;
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
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            color: #555;
            font-size: 0.85rem;
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
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
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>DIMF_2007 - ÉTAT DES BIENS DÉTENUS DANS LE CADRE DE LA CONCESSION</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">SICS-BCEAO - Biens en concession</div>
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
        <strong>ⓘ Note :</strong> Cet état présente les biens détenus dans le cadre de la concession. 
        Sont concernés les biens acquis ou construits sur le domaine public ou privé de l'État ou des collectivités locales.
    </div>
    
    <!-- Formulaire d'ajout / modification -->
    <div class="section-card">
        <div class="section-title">
            <?= $edit_bien ? '✏️ MODIFIER UN BIEN EN CONCESSION' : '➕ AJOUTER UN BIEN EN CONCESSION' ?>
        </div>
        <div style="padding: 20px;">
            <form method="post" action="">
                <input type="hidden" name="action" value="<?= $edit_bien ? 'update' : 'add' ?>">
                <?php if($edit_bien): ?>
                    <input type="hidden" name="id" value="<?= $edit_bien['id'] ?>">
                <?php endif; ?>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Libellé du bien *</label>
                        <input type="text" name="libelle" required 
                               value="<?= $edit_bien ? htmlspecialchars($edit_bien['libelle']) : '' ?>"
                               placeholder="Ex: Immeuble commercial, Terrain, etc.">
                    </div>
                    <div class="form-group">
                        <label>Valeur d'inventaire / Valeur de marché (FCFA)</label>
                        <input type="number" name="valeur_inventaire" step="1" 
                               value="<?= $edit_bien ? number_format($edit_bien['valeur_inventaire'], 0, '', '') : '0' ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Concessionnaire (Nom)</label>
                        <input type="text" name="concessionnaire_nom" 
                               value="<?= $edit_bien ? htmlspecialchars($edit_bien['concessionnaire_nom']) : '' ?>"
                               placeholder="Nom du concessionnaire">
                    </div>
                    <div class="form-group">
                        <label>Valeur déclarée dans le cahier de charges (FCFA)</label>
                        <input type="number" name="valeur_declaree" step="1" 
                               value="<?= $edit_bien ? number_format($edit_bien['valeur_declaree'], 0, '', '') : '0' ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Date d'acquisition</label>
                        <input type="date" name="date_acquisition" 
                               value="<?= $edit_bien && $edit_bien['date_acquisition'] ? date('Y-m-d', strtotime($edit_bien['date_acquisition'])) : '' ?>">
                    </div>
                    <div class="form-group">
                        <label>Durée de la concession (années)</label>
                        <input type="number" name="duree_concession" 
                               value="<?= $edit_bien ? $edit_bien['duree_concession'] : '' ?>"
                               placeholder="Durée en années">
                    </div>
                </div>
                
                <div style="margin-top: 20px; text-align: right;">
                    <?php if($edit_bien): ?>
                        <a href="DIMF_2007.php?exercice=<?= $exercice ?>&trimestre=<?= $trimestre ?>&mois=<?= $mois ?>" class="btn btn-warning">Annuler</a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-success">
                        <?= $edit_bien ? 'Mettre à jour' : 'Ajouter le bien' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Liste des biens en concession -->
    <div class="section-card">
        <div class="section-title">📋 LISTE DES BIENS EN CONCESSION</div>
        <div style="overflow-x: auto;">
            <?php if(empty($biens_concession)): ?>
                <div class="info-box">Aucun bien en concession enregistré pour l'exercice <?= $exercice ?>.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Libellé</th>
                            <th class="text-right">Valeur d'inventaire (FCFA)</th>
                            <th>Concessionnaire</th>
                            <th class="text-right">Valeur déclarée (FCFA)</th>
                            <th>Date acquisition</th>
                            <th>Durée</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($biens_concession as $bien): ?>
                        <tr>
                            <td><?= htmlspecialchars($bien['libelle']) ?></td>
                            <td class="text-right"><?= number_format($bien['valeur_inventaire'], 0, ',', ' ') ?></td>
                            <td><?= htmlspecialchars($bien['concessionnaire_nom'] ?: '-') ?></td>
                            <td class="text-right"><?= number_format($bien['valeur_declaree'], 0, ',', ' ') ?></td>
                            <td><?= $bien['date_acquisition'] ? date('d/m/Y', strtotime($bien['date_acquisition'])) : '-' ?></td>
                            <td class="text-center"><?= $bien['duree_concession'] ? $bien['duree_concession'] . ' ans' : '-' ?></td>
                            <td class="action-buttons">
                                <a href="?exercice=<?= $exercice ?>&trimestre=<?= $trimestre ?>&mois=<?= $mois ?>&edit=<?= $bien['id'] ?>" 
                                   class="btn btn-warning" style="padding: 4px 10px; font-size: 0.75rem;">✏️</a>
                                <form method="post" style="display: inline-block;" onsubmit="return confirm('Supprimer ce bien ?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $bien['id'] ?>">
                                    <button type="submit" class="btn btn-danger" style="padding: 4px 10px; font-size: 0.75rem;">🗑️</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td><strong>TOTAL</strong></td>
                            <td class="text-right"><strong><?= number_format($total_valeur_inventaire, 0, ',', ' ') ?></strong></td>
                            <td></td>
                            <td class="text-right"><strong><?= number_format($total_valeur_declaree, 0, ',', ' ') ?></strong></td>
                            <td colspan="3"></td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Récapitulatif -->
    <div class="section-card">
        <div class="section-title">📊 RÉCAPITULATIF</div>
        <div class="info-box">
            <strong>Nombre de biens en concession :</strong> <?= count($biens_concession) ?><br>
            <strong>Valeur totale d'inventaire :</strong> <?= number_format($total_valeur_inventaire, 0, ',', ' ') ?> FCFA<br>
            <strong>Valeur totale déclarée :</strong> <?= number_format($total_valeur_declaree, 0, ',', ' ') ?> FCFA
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
        window.location.href = 'DIMF_2007.php?exercice=' + exercice + '&trimestre=' + trimestre + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>