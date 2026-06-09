<?php
// DIMF_2008.php - État des biens avec clause de réserve de propriété
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
            CREATE TABLE IF NOT EXISTS biens_reserve_propriete (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exercice INT NOT NULL,
                libelle VARCHAR(255) NOT NULL,
                objet_clause VARCHAR(100),
                montant_brut DECIMAL(15,2) DEFAULT 0,
                date_inscription DATE,
                duree_jouissance INT,
                creancier_nom VARCHAR(200),
                creancier_adresse TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        if ($_POST['action'] == 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO biens_reserve_propriete (
                    exercice, libelle, objet_clause, montant_brut, 
                    date_inscription, duree_jouissance, creancier_nom, creancier_adresse
                ) VALUES (
                    :exercice, :libelle, :objet_clause, :montant_brut, 
                    :date_inscription, :duree_jouissance, :creancier_nom, :creancier_adresse
                )
            ");
            $stmt->execute([
                ':exercice' => $exercice,
                ':libelle' => $_POST['libelle'] ?? '',
                ':objet_clause' => $_POST['objet_clause'] ?? '',
                ':montant_brut' => $_POST['montant_brut'] ?? 0,
                ':date_inscription' => $_POST['date_inscription'] ?? null,
                ':duree_jouissance' => $_POST['duree_jouissance'] ?? null,
                ':creancier_nom' => $_POST['creancier_nom'] ?? '',
                ':creancier_adresse' => $_POST['creancier_adresse'] ?? ''
            ]);
            $message = "Bien avec clause de réserve de propriété ajouté avec succès !";
            $message_type = "success";
        } elseif ($_POST['action'] == 'delete' && isset($_POST['id'])) {
            $stmt = $pdo->prepare("DELETE FROM biens_reserve_propriete WHERE id = :id AND exercice = :exercice");
            $stmt->execute([':id' => $_POST['id'], ':exercice' => $exercice]);
            $message = "Bien supprimé avec succès !";
            $message_type = "success";
        } elseif ($_POST['action'] == 'update' && isset($_POST['id'])) {
            $stmt = $pdo->prepare("
                UPDATE biens_reserve_propriete 
                SET libelle = :libelle, 
                    objet_clause = :objet_clause,
                    montant_brut = :montant_brut,
                    date_inscription = :date_inscription,
                    duree_jouissance = :duree_jouissance,
                    creancier_nom = :creancier_nom,
                    creancier_adresse = :creancier_adresse
                WHERE id = :id AND exercice = :exercice
            ");
            $stmt->execute([
                ':id' => $_POST['id'],
                ':exercice' => $exercice,
                ':libelle' => $_POST['libelle'] ?? '',
                ':objet_clause' => $_POST['objet_clause'] ?? '',
                ':montant_brut' => $_POST['montant_brut'] ?? 0,
                ':date_inscription' => $_POST['date_inscription'] ?? null,
                ':duree_jouissance' => $_POST['duree_jouissance'] ?? null,
                ':creancier_nom' => $_POST['creancier_nom'] ?? '',
                ':creancier_adresse' => $_POST['creancier_adresse'] ?? ''
            ]);
            $message = "Bien modifié avec succès !";
            $message_type = "success";
        }
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }
}

// Récupération des biens pour l'exercice
$biens_reserve = [];
$total_montant_brut = 0;

try {
    $stmt = $pdo->prepare("
        SELECT * FROM biens_reserve_propriete 
        WHERE exercice = :exercice 
        ORDER BY id
    ");
    $stmt->execute([':exercice' => $exercice]);
    $biens_reserve = $stmt->fetchAll();
    
    foreach ($biens_reserve as $bien) {
        $total_montant_brut += $bien['montant_brut'];
    }
} catch (PDOException $e) {
    $biens_reserve = [];
}

// Récupération d'un bien pour édition
$edit_bien = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM biens_reserve_propriete WHERE id = :id AND exercice = :exercice");
        $stmt->execute([':id' => $_GET['edit'], ':exercice' => $exercice]);
        $edit_bien = $stmt->fetch();
    } catch (PDOException $e) {
        $edit_bien = null;
    }
}

// Objets de clause possibles
$objets_clause = [
    'ACHAT' => 'Achat',
    'CONSTRUCTION' => 'Construction',
    'EQUIPEMENT' => 'Équipement',
    'AUTRE' => 'Autre'
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2008 - État des biens avec clause de réserve de propriété</title>
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
        
        .form-group input, .form-group select, .form-group textarea {
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
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
        <h1>DIMF_2008 - ÉTAT DES BIENS AVEC CLAUSE DE RÉSERVE DE PROPRIÉTÉ</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">SICS-BCEAO - Biens frappés de réserve de propriété</div>
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
        <strong>ⓘ Note :</strong> Cet état présente les biens inscrits à l'actif frappés de la clause de réserve de propriété.
        La clause de réserve de propriété permet au vendeur de conserver la propriété du bien jusqu'au paiement intégral du prix.
    </div>
    
    <!-- Formulaire d'ajout / modification -->
    <div class="section-card">
        <div class="section-title">
            <?= $edit_bien ? '✏️ MODIFIER UN BIEN' : '➕ AJOUTER UN BIEN AVEC CLAUSE DE RÉSERVE DE PROPRIÉTÉ' ?>
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
                               placeholder="Ex: Véhicule, Machine, Équipement, etc.">
                    </div>
                    <div class="form-group">
                        <label>Objet de la clause</label>
                        <select name="objet_clause">
                            <option value="">-- Sélectionner --</option>
                            <?php foreach($objets_clause as $key => $value): ?>
                                <option value="<?= $key ?>" <?= ($edit_bien && $edit_bien['objet_clause'] == $key) ? 'selected' : '' ?>>
                                    <?= $value ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Montant brut (FCFA)</label>
                        <input type="number" name="montant_brut" step="1" 
                               value="<?= $edit_bien ? number_format($edit_bien['montant_brut'], 0, '', '') : '0' ?>">
                    </div>
                    <div class="form-group">
                        <label>Date d'inscription</label>
                        <input type="date" name="date_inscription" 
                               value="<?= $edit_bien && $edit_bien['date_inscription'] ? date('Y-m-d', strtotime($edit_bien['date_inscription'])) : '' ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Durée de jouissance (mois)</label>
                        <input type="number" name="duree_jouissance" 
                               value="<?= $edit_bien ? $edit_bien['duree_jouissance'] : '' ?>"
                               placeholder="Durée en mois">
                    </div>
                    <div class="form-group">
                        <label>Créancier (Nom / Raison sociale)</label>
                        <input type="text" name="creancier_nom" 
                               value="<?= $edit_bien ? htmlspecialchars($edit_bien['creancier_nom']) : '' ?>"
                               placeholder="Nom du créancier bénéficiaire">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Adresse du créancier</label>
                    <textarea name="creancier_adresse" placeholder="Adresse complète du créancier"><?= $edit_bien ? htmlspecialchars($edit_bien['creancier_adresse']) : '' ?></textarea>
                </div>
                
                <div style="margin-top: 20px; text-align: right;">
                    <?php if($edit_bien): ?>
                        <a href="DIMF_2008.php?exercice=<?= $exercice ?>&trimestre=<?= $trimestre ?>&mois=<?= $mois ?>" class="btn btn-warning">Annuler</a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-success">
                        <?= $edit_bien ? 'Mettre à jour' : 'Ajouter le bien' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Liste des biens -->
    <div class="section-card">
        <div class="section-title">📋 LISTE DES BIENS AVEC CLAUSE DE RÉSERVE DE PROPRIÉTÉ</div>
        <div style="overflow-x: auto;">
            <?php if(empty($biens_reserve)): ?>
                <div class="info-box">Aucun bien avec clause de réserve de propriété enregistré pour l'exercice <?= $exercice ?>.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Libellé du bien</th>
                            <th>Objet clause</th>
                            <th class="text-right">Montant brut (FCFA)</th>
                            <th>Date inscription</th>
                            <th>Durée jouissance</th>
                            <th>Créancier</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($biens_reserve as $bien): ?>
                        <tr>
                            <td><?= htmlspecialchars($bien['libelle']) ?></td>
                            <td><?= htmlspecialchars($objets_clause[$bien['objet_clause']] ?? $bien['objet_clause'] ?? '-') ?></td>
                            <td class="text-right"><?= number_format($bien['montant_brut'], 0, ',', ' ') ?></td>
                            <td><?= $bien['date_inscription'] ? date('d/m/Y', strtotime($bien['date_inscription'])) : '-' ?></td>
                            <td class="text-center"><?= $bien['duree_jouissance'] ? $bien['duree_jouissance'] . ' mois' : '-' ?></td>
                            <td><?= htmlspecialchars($bien['creancier_nom'] ?: '-') ?></td>
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
                            <td colspan="2"><strong>TOTAL</strong></td>
                            <td class="text-right"><strong><?= number_format($total_montant_brut, 0, ',', ' ') ?></strong></td>
                            <td colspan="4"></td>
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
            <strong>Nombre de biens :</strong> <?= count($biens_reserve) ?><br>
            <strong>Montant brut total :</strong> <?= number_format($total_montant_brut, 0, ',', ' ') ?> FCFA
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
        window.location.href = 'DIMF_2008.php?exercice=' + exercice + '&trimestre=' + trimestre + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>