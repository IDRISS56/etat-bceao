<?php
// 15-CommAuxComptes.php - Suivi des commissariats aux comptes
// Pour les SFD soumis à l'obligation d'un commissariat aux comptes

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

// Récupérer l'exercice
$exercice = isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y');

// Traitement du formulaire
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Vérifier si la table des commissaires aux comptes existe, sinon la créer
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS commissaires_comptes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exercice INT NOT NULL,
                nom_cabinet VARCHAR(200) NOT NULL,
                date_nomination DATE DEFAULT NULL,
            UNIQUE KEY uk_exercice_nom (exercice, nom_cabinet)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS audits_externes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exercice INT NOT NULL,
                comptes_certifies ENUM('oui', 'non') DEFAULT 'non',
                avis ENUM('sans_reserve', 'avec_reserve', 'defavorable', 'impossible') DEFAULT NULL,
                date_audit DATE DEFAULT NULL,
            observations TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_exercice (exercice)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // Sauvegarder les commissaires aux comptes
        if (isset($_POST['commissaires']) && is_array($_POST['commissaires'])) {
            // Supprimer les anciennes entrées pour cet exercice
            $stmtDel = $pdo->prepare("DELETE FROM commissaires_comptes WHERE exercice = :exercice");
            $stmtDel->execute([':exercice' => $exercice]);
            
            // Insérer les nouveaux
            $stmtIns = $pdo->prepare("INSERT INTO commissaires_comptes (exercice, nom_cabinet, date_nomination) VALUES (:exercice, :nom, :date_nomination)");
            foreach ($_POST['commissaires'] as $commissaire) {
                if (!empty($commissaire['nom'])) {
                    $stmtIns->execute([
                        ':exercice' => $exercice,
                        ':nom' => $commissaire['nom'],
                        ':date_nomination' => !empty($commissaire['date_nomination']) ? $commissaire['date_nomination'] : null
                    ]);
                }
            }
        }
        
        // Sauvegarder les informations d'audit
        $stmtAudit = $pdo->prepare("
            INSERT INTO audits_externes (exercice, comptes_certifies, avis, date_audit, observations) 
            VALUES (:exercice, :certifies, :avis, :date_audit, :observations)
            ON DUPLICATE KEY UPDATE 
                comptes_certifies = VALUES(comptes_certifies),
                avis = VALUES(avis),
                date_audit = VALUES(date_audit),
                observations = VALUES(observations)
        ");
        
        $stmtAudit->execute([
            ':exercice' => $exercice,
            ':certifies' => $_POST['comptes_certifies'] ?? 'non',
            ':avis' => $_POST['avis'] ?? null,
            ':date_audit' => !empty($_POST['date_audit']) ? $_POST['date_audit'] : null,
            ':observations' => $_POST['observations'] ?? null
        ]);
        
        $message = "Données enregistrées avec succès !";
        $message_type = "success";
        
    } catch (PDOException $e) {
        $message = "Erreur lors de l'enregistrement : " . $e->getMessage();
        $message_type = "error";
    }
}

// Récupération des données existantes
$commissaires = [];
$audit = [
    'comptes_certifies' => 'non',
    'avis' => '',
    'date_audit' => '',
    'observations' => ''
];

try {
    // Vérifier si la table existe
    $tables = $pdo->query("SHOW TABLES LIKE 'commissaires_comptes'");
    if ($tables->rowCount() > 0) {
        $stmtCommissaires = $pdo->prepare("SELECT * FROM commissaires_comptes WHERE exercice = :exercice ORDER BY id");
        $stmtCommissaires->execute([':exercice' => $exercice]);
        $commissaires = $stmtCommissaires->fetchAll();
    }
    
    $tables2 = $pdo->query("SHOW TABLES LIKE 'audits_externes'");
    if ($tables2->rowCount() > 0) {
        $stmtAudit = $pdo->prepare("SELECT * FROM audits_externes WHERE exercice = :exercice");
        $stmtAudit->execute([':exercice' => $exercice]);
        $auditDb = $stmtAudit->fetch();
        if ($auditDb) {
            $audit = $auditDb;
        }
    }
} catch (PDOException $e) {
    // Les tables n'existent pas encore
}

// Nombre de commissaires à afficher (minimum 5)
$nb_commissaires = max(5, count($commissaires));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>15 - Suivi des commissariats aux comptes</title>
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
        
        .warning-badge {
            display: inline-block;
            background: #ff9800;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
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
        
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .commissaire-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            padding: 0 20px;
            align-items: center;
        }
        
        .commissaire-row .nom-input {
            flex: 3;
        }
        
        .commissaire-row .date-input {
            flex: 2;
        }
        
        .commissaire-row .btn-remove {
            background: #c62828;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 15px;
            cursor: pointer;
            font-size: 0.8rem;
        }
        
        .commissaire-row .btn-remove:hover {
            background: #b71c1c;
        }
        
        .btn-add {
            background: #1a3a5c;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            cursor: pointer;
            font-size: 0.9rem;
            margin: 0 20px 20px 20px;
            display: inline-block;
        }
        
        .btn-add:hover {
            background: #0d2137;
        }
        
        .form-actions {
            padding: 20px;
            border-top: 1px solid #eee;
            text-align: center;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
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
        
        .alert-info {
            background: #e3f2fd;
            color: #1565c0;
            border-left: 4px solid #1565c0;
        }
        
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin: 15px;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: bold;
        }
        
        .status-ok {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .status-warning {
            background: #fff3e0;
            color: #ef6c00;
        }
        
        .text-center {
            text-align: center;
        }
        
        .footer {
            text-align: center;
            padding: 20px;
            color: #777;
            font-size: 0.8rem;
        }
        
        @media (max-width: 768px) {
            .commissaire-row {
                flex-direction: column;
            }
            
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
        <h1>15 - Suivi des commissariats aux comptes</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">Pour les SFD soumis à l'obligation d'un commissariat aux comptes</div>
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
            <button class="btn btn-primary" onclick="changerExercice()">Changer d'exercice</button>
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
        ⓘ <strong>Information :</strong> Ce formulaire concerne uniquement les SFD soumis à l'obligation légale de désigner un commissaire aux comptes. 
        Les données saisies sont conservées d'un exercice à l'autre.
    </div>
    
    <form method="post" action="">
        <div class="section-card">
            <div class="section-title">📋 IDENTIFICATION DES COMMISSAIRES AUX COMPTES</div>
            <div id="commissaires-container">
                <?php for($i = 0; $i < $nb_commissaires; $i++): ?>
                    <div class="commissaire-row" data-index="<?= $i ?>">
                        <div class="nom-input">
                            <input type="text" 
                                   name="commissaires[<?= $i ?>][nom]" 
                                   placeholder="Nom du cabinet ou du commissaire" 
                                   value="<?= isset($commissaires[$i]) ? htmlspecialchars($commissaires[$i]['nom_cabinet']) : '' ?>">
                        </div>
                        <div class="date-input">
                            <input type="date" 
                                   name="commissaires[<?= $i ?>][date_nomination]" 
                                   value="<?= isset($commissaires[$i]) && $commissaires[$i]['date_nomination'] ? date('Y-m-d', strtotime($commissaires[$i]['date_nomination'])) : '' ?>">
                        </div>
                        <?php if($i >= 5): ?>
                            <button type="button" class="btn-remove" onclick="supprimerCommissaire(this)">🗑 Supprimer</button>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
            <button type="button" class="btn-add" onclick="ajouterCommissaire()">+ Ajouter un commissaire</button>
        </div>
        
        <div class="section-card">
            <div class="section-title">📊 CERTIFICATION DES COMPTES</div>
            
            <div class="form-group">
                <label>Les comptes ont-ils été certifiés ?</label>
                <select name="comptes_certifies" id="comptes_certifies">
                    <option value="non" <?= $audit['comptes_certifies'] == 'non' ? 'selected' : '' ?>>Non</option>
                    <option value="oui" <?= $audit['comptes_certifies'] == 'oui' ? 'selected' : '' ?>>Oui</option>
                </select>
            </div>
            
            <div class="form-group" id="avis-group" style="display: <?= $audit['comptes_certifies'] == 'oui' ? 'block' : 'none' ?>;">
                <label>Avec ou sans réserves :</label>
                <select name="avis">
                    <option value="" <?= empty($audit['avis']) ? 'selected' : '' ?>>-- Sélectionnez --</option>
                    <option value="sans_reserve" <?= $audit['avis'] == 'sans_reserve' ? 'selected' : '' ?>>Sans réserve</option>
                    <option value="avec_reserve" <?= $audit['avis'] == 'avec_reserve' ? 'selected' : '' ?>>Avec réserves</option>
                    <option value="defavorable" <?= $audit['avis'] == 'defavorable' ? 'selected' : '' ?>>Avis défavorable</option>
                    <option value="impossible" <?= $audit['avis'] == 'impossible' ? 'selected' : '' ?>>Impossibilité de certifier</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Date de l'audit / de la certification :</label>
                <input type="date" name="date_audit" value="<?= $audit['date_audit'] ? date('Y-m-d', strtotime($audit['date_audit'])) : '' ?>">
            </div>
            
            <div class="form-group" id="reserves-group" style="display: <?= $audit['avis'] == 'avec_reserve' ? 'block' : 'none' ?>;">
                <label>Liste des principales réserves :</label>
                <textarea name="observations" placeholder="Décrivez les principales réserves émises par le commissaire aux comptes..."><?= htmlspecialchars($audit['observations'] ?? '') ?></textarea>
            </div>
        </div>
        
        <div class="section-card">
            <div class="form-actions">
                <button type="submit" class="btn btn-success">💾 Enregistrer les informations</button>
            </div>
        </div>
    </form>
    
    <!-- Récapitulatif des audits précédents -->
    <div class="section-card">
        <div class="section-title">📜 HISTORIQUE DES AUDITS</div>
        <div style="padding: 20px;">
            <?php
            try {
                $stmtHisto = $pdo->prepare("
                    SELECT * FROM audits_externes 
                    ORDER BY exercice DESC 
                    LIMIT 10
                ");
                $stmtHisto->execute();
                $historique = $stmtHisto->fetchAll();
                
                if (empty($historique)):
            ?>
                <div class="info-box">Aucun historique d'audit disponible.</div>
            <?php else: ?>
                </table>
                    <thead>
                        <tr>
                            <th>Exercice</th>
                            <th>Comptes certifiés</th>
                            <th>Avis</th>
                            <th>Date audit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($historique as $h): ?>
                        <tr>
                            <td><?= $h['exercice'] ?></td>
                            <td>
                                <span class="status-badge <?= $h['comptes_certifies'] == 'oui' ? 'status-ok' : 'status-warning' ?>">
                                    <?= $h['comptes_certifies'] == 'oui' ? 'Oui' : 'Non' ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $avis_text = '';
                                switch($h['avis']) {
                                    case 'sans_reserve': $avis_text = 'Sans réserve'; break;
                                    case 'avec_reserve': $avis_text = 'Avec réserves'; break;
                                    case 'defavorable': $avis_text = 'Avis défavorable'; break;
                                    case 'impossible': $avis_text = 'Impossibilité de certifier'; break;
                                    default: $avis_text = '-';
                                }
                                echo $avis_text;
                                ?>
                            </td>
                            <td><?= $h['date_audit'] ? date('d/m/Y', strtotime($h['date_audit'])) : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php 
                endif;
            } catch (PDOException $e) {
                echo '<div class="info-box">Aucun historique disponible.</div>';
            }
            ?>
        </div>
    </div>
    
    <!-- Obligations légales -->
    <div class="section-card">
        <div class="section-title">⚖️ RAPPEL DES OBLIGATIONS LÉGALES</div>
        <div style="padding: 20px; line-height: 1.6;">
            <ul style="margin-left: 20px;">
                <li>Les SFD sont soumis à l'obligation de désigner un commissaire aux comptes conformément à la réglementation en vigueur.</li>
                <li>Le rapport du commissaire aux comptes doit être transmis à la DSFD dans les 6 mois suivant la clôture de l'exercice.</li>
                <li>En cas de réserves, un plan d'actions correctives doit être soumis à la DSFD.</li>
                <li>Les SFD qui ne seraient pas soumis à cette obligation peuvent laisser ce formulaire vide.</li>
            </ul>
        </div>
    </div>
    
    <div class="footer">
        Document généré le <?= date('d/m/Y à H:i:s') ?> - Données extraites de la base Mandigo<br>
        Exercice : <?= $exercice ?>
    </div>
</div>

<script>
    let commissaireIndex = <?= $nb_commissaires ?>;
    
    function ajouterCommissaire() {
        const container = document.getElementById('commissaires-container');
        const newRow = document.createElement('div');
        newRow.className = 'commissaire-row';
        newRow.setAttribute('data-index', commissaireIndex);
        newRow.innerHTML = `
            <div class="nom-input">
                <input type="text" name="commissaires[${commissaireIndex}][nom]" placeholder="Nom du cabinet ou du commissaire">
            </div>
            <div class="date-input">
                <input type="date" name="commissaires[${commissaireIndex}][date_nomination]">
            </div>
            <button type="button" class="btn-remove" onclick="supprimerCommissaire(this)">🗑 Supprimer</button>
        `;
        container.appendChild(newRow);
        commissaireIndex++;
    }
    
    function supprimerCommissaire(button) {
        const row = button.closest('.commissaire-row');
        row.remove();
    }
    
    function changerExercice() {
        let exercice = document.getElementById('exercice').value;
        window.location.href = '15-CommAuxComptes.php?exercice=' + exercice;
    }
    
    function exporterPDF() {
        window.print();
    }
    
    // Afficher/masquer le champ des réserves selon la sélection
    document.getElementById('comptes_certifies').addEventListener('change', function() {
        const avisGroup = document.getElementById('avis-group');
        if (this.value === 'oui') {
            avisGroup.style.display = 'block';
        } else {
            avisGroup.style.display = 'none';
            document.getElementById('reserves-group').style.display = 'none';
        }
    });
    
    // Afficher/masquer le champ des réserves selon l'avis
    const avisSelect = document.querySelector('select[name="avis"]');
    if (avisSelect) {
        avisSelect.addEventListener('change', function() {
            const reservesGroup = document.getElementById('reserves-group');
            if (this.value === 'avec_reserve') {
                reservesGroup.style.display = 'block';
            } else {
                reservesGroup.style.display = 'none';
            }
        });
    }
</script>
</body>
</html>