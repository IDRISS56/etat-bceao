<?php
// DIMF_2011.php - Informations annexes
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
            CREATE TABLE IF NOT EXISTS infos_annexes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exercice INT NOT NULL,
                code_indicateur VARCHAR(20) NOT NULL,
                valeur_montant DECIMAL(15,2) DEFAULT NULL,
                valeur_effectif INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_exercice_indicateur (exercice, code_indicateur)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        if ($_POST['action'] == 'save') {
            // Supprimer les anciennes données pour l'exercice
            $stmtDel = $pdo->prepare("DELETE FROM infos_annexes WHERE exercice = :exercice");
            $stmtDel->execute([':exercice' => $exercice]);
            
            // Insérer les nouvelles données
            $stmtIns = $pdo->prepare("
                INSERT INTO infos_annexes (exercice, code_indicateur, valeur_montant, valeur_effectif)
                VALUES (:exercice, :code_indicateur, :valeur_montant, :valeur_effectif)
            ");
            
            // Liste des indicateurs à sauvegarder
            $indicateurs = [
                'ZC01' => ['type' => 'montant', 'poste' => 'encours_engagements_ct'],
                'ZC02' => ['type' => 'montant', 'poste' => 'encours_engagements_mlt'],
                'ZC03' => ['type' => 'montant', 'poste' => 'montant_autres_activites'],
                'ZC04' => ['type' => 'effectif', 'poste' => 'nb_membres_total'],
                'ZC05' => ['type' => 'effectif', 'poste' => 'nb_groupements'],
                'ZC06' => ['type' => 'effectif', 'poste' => 'nb_membres_hommes'],
                'ZC07' => ['type' => 'effectif', 'poste' => 'nb_membres_femmes'],
                'ZC08' => ['type' => 'effectif', 'poste' => 'nb_groupements_beneficiaires'],
                'ZC09' => ['type' => 'effectif', 'poste' => 'nb_usagers_beneficiaires'],
                'ZC10' => ['type' => 'effectif', 'poste' => 'nb_societaires_beneficiaires'],
                'ZC11' => ['type' => 'effectif', 'poste' => 'population_cible'],
                'ZC12' => ['type' => 'montant', 'poste' => 'depots_plus_1_an_inst_fin'],
                'ZC13' => ['type' => 'montant', 'poste' => 'depots_terme_plus_1_an_membres'],
                'ZC14' => ['type' => 'montant', 'poste' => 'epargne_regime_special'],
                'ZC15' => ['type' => 'montant', 'poste' => 'autres_depots_plus_1_an_membres'],
                'ZC16' => ['type' => 'montant', 'poste' => 'recouvrements_prevus'],
                'ZC17' => ['type' => 'montant', 'poste' => 'recouvrements_attendus']
            ];
            
            foreach ($indicateurs as $code => $info) {
                if ($info['type'] == 'montant') {
                    $valeur_montant = (float)($_POST[$info['poste']] ?? 0);
                    $valeur_effectif = null;
                } else {
                    $valeur_montant = null;
                    $valeur_effectif = (int)($_POST[$info['poste']] ?? 0);
                }
                
                $stmtIns->execute([
                    ':exercice' => $exercice,
                    ':code_indicateur' => $code,
                    ':valeur_montant' => $valeur_montant,
                    ':valeur_effectif' => $valeur_effectif
                ]);
            }
            
            $message = "Informations annexes enregistrées avec succès !";
            $message_type = "success";
        }
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }
}

// Récupération des données existantes
$infos = [];

try {
    $stmt = $pdo->prepare("
        SELECT * FROM infos_annexes 
        WHERE exercice = :exercice
    ");
    $stmt->execute([':exercice' => $exercice]);
    $results = $stmt->fetchAll();
    
    foreach ($results as $row) {
        $infos[$row['code_indicateur']] = $row;
    }
} catch (PDOException $e) {
    $infos = [];
}

// Récupération des données calculées depuis la base
$donnees_calculees = [];

// Nombre total de membres/clients
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM clients WHERE statut = 'actif'");
    $stmt->execute();
    $result = $stmt->fetch();
    $donnees_calculees['nb_membres_total'] = $result['total'];
} catch (PDOException $e) {
    $donnees_calculees['nb_membres_total'] = 0;
}

// Nombre d'hommes et femmes
try {
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN genre = 'Masculin' THEN 1 ELSE 0 END) as hommes,
            SUM(CASE WHEN genre = 'Feminin' THEN 1 ELSE 0 END) as femmes
        FROM clients WHERE statut = 'actif'
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $donnees_calculees['nb_membres_hommes'] = $result['hommes'];
    $donnees_calculees['nb_membres_femmes'] = $result['femmes'];
} catch (PDOException $e) {
    $donnees_calculees['nb_membres_hommes'] = 0;
    $donnees_calculees['nb_membres_femmes'] = 0;
}

// Encours des dépôts à terme
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(capital_initial), 0) as total FROM comptes_dat WHERE statut = 'en cours'");
    $stmt->execute();
    $result = $stmt->fetch();
    $donnees_calculees['depots_terme_plus_1_an_membres'] = $result['total'];
} catch (PDOException $e) {
    $donnees_calculees['depots_terme_plus_1_an_membres'] = 0;
}

// Épargne
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(c.solde), 0) as total
        FROM comptes c
        INNER JOIN produits p ON c.produit_id = p.produit_id
        INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id
        WHERE pf.categorie = 'Epargne' AND c.statut = 'actif' AND c.solde > 0
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $donnees_calculees['epargne_regime_special'] = $result['total'];
} catch (PDOException $e) {
    $donnees_calculees['epargne_regime_special'] = 0;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2011 - Informations annexes</title>
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
        
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
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
            
            .form-row {
                grid-template-columns: 1fr;
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
        <h1>DIMF_2011 - INFORMATIONS ANNEXES</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">SICS-BCEAO - Informations complémentaires</div>
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
        <strong>ⓘ Note :</strong> Ce tableau regroupe les informations annexes non disponibles dans les états financiers de base.
        Les champs marqués d'un ✓ sont automatiquement calculés à partir de la base de données.
    </div>
    
    <form method="post" action="">
        <input type="hidden" name="action" value="save">
        
        <div class="section-card">
            <div class="section-title">📊 INFORMATIONS GÉNÉRALES</div>
            <div class="form-row" style="padding: 20px;">
                <div class="form-group">
                    <label>ZC01 - Encours des engagements par signature à court terme (FCFA)</label>
                    <input type="number" name="encours_engagements_ct" step="1" 
                           value="<?= number_format($infos['ZC01']['valeur_montant'] ?? 0, 0, '', '') ?>">
                </div>
                <div class="form-group">
                    <label>ZC02 - Encours des engagements par signature à moyen et long termes (FCFA)</label>
                    <input type="number" name="encours_engagements_mlt" step="1"
                           value="<?= number_format($infos['ZC02']['valeur_montant'] ?? 0, 0, '', '') ?>">
                </div>
                <div class="form-group">
                    <label>ZC03 - Montant consacré aux opérations autres qu'épargne et crédit (FCFA)</label>
                    <input type="number" name="montant_autres_activites" step="1"
                           value="<?= number_format($infos['ZC03']['valeur_montant'] ?? 0, 0, '', '') ?>">
                </div>
            </div>
        </div>
        
        <div class="section-card">
            <div class="section-title">👥 EFFECTIFS DES MEMBRES ET GROUPEMENTS</div>
            <div class="form-row" style="padding: 20px;">
                <div class="form-group">
                    <label>ZC04 - Nombre total de membres, bénéficiaires ou clients</label>
                    <input type="number" name="nb_membres_total" 
                           value="<?= $infos['ZC04']['valeur_effectif'] ?? $donnees_calculees['nb_membres_total'] ?>"
                           class="<?= isset($donnees_calculees['nb_membres_total']) ? 'calculated-value' : '' ?>">
                    <?php if(isset($donnees_calculees['nb_membres_total'])): ?>
                        <small style="color:#2e7d32;">✓ Calculé automatiquement : <?= number_format($donnees_calculees['nb_membres_total']) ?></small>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>ZC05 - Nombre total de groupements</label>
                    <input type="number" name="nb_groupements"
                           value="<?= $infos['ZC05']['valeur_effectif'] ?? 0 ?>">
                </div>
                <div class="form-group">
                    <label>ZC06 - Nombre total de membres de sexe masculin</label>
                    <input type="number" name="nb_membres_hommes"
                           value="<?= $infos['ZC06']['valeur_effectif'] ?? $donnees_calculees['nb_membres_hommes'] ?>"
                           class="<?= isset($donnees_calculees['nb_membres_hommes']) ? 'calculated-value' : '' ?>">
                    <?php if(isset($donnees_calculees['nb_membres_hommes'])): ?>
                        <small style="color:#2e7d32;">✓ Calculé automatiquement : <?= number_format($donnees_calculees['nb_membres_hommes']) ?></small>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>ZC07 - Nombre total de membres de sexe féminin</label>
                    <input type="number" name="nb_membres_femmes"
                           value="<?= $infos['ZC07']['valeur_effectif'] ?? $donnees_calculees['nb_membres_femmes'] ?>"
                           class="<?= isset($donnees_calculees['nb_membres_femmes']) ? 'calculated-value' : '' ?>">
                    <?php if(isset($donnees_calculees['nb_membres_femmes'])): ?>
                        <small style="color:#2e7d32;">✓ Calculé automatiquement : <?= number_format($donnees_calculees['nb_membres_femmes']) ?></small>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>ZC08 - Nombre total de groupements bénéficiaires</label>
                    <input type="number" name="nb_groupements_beneficiaires"
                           value="<?= $infos['ZC08']['valeur_effectif'] ?? 0 ?>">
                </div>
                <div class="form-group">
                    <label>ZC09 - Nombre total d'usagers bénéficiaires</label>
                    <input type="number" name="nb_usagers_beneficiaires"
                           value="<?= $infos['ZC09']['valeur_effectif'] ?? 0 ?>">
                </div>
                <div class="form-group">
                    <label>ZC10 - Nombre total de sociétaires bénéficiaires</label>
                    <input type="number" name="nb_societaires_beneficiaires"
                           value="<?= $infos['ZC10']['valeur_effectif'] ?? 0 ?>">
                </div>
                <div class="form-group">
                    <label>ZC11 - Population cible de la caisse (estimation)</label>
                    <input type="number" name="population_cible"
                           value="<?= $infos['ZC11']['valeur_effectif'] ?? 0 ?>">
                </div>
            </div>
        </div>
        
        <div class="section-card">
            <div class="section-title">💰 DÉPÔTS ET ÉPARGNE</div>
            <div class="form-row" style="padding: 20px;">
                <div class="form-group">
                    <label>ZC12 - Dépôts à plus d'un an auprès des institutions financières (FCFA)</label>
                    <input type="number" name="depots_plus_1_an_inst_fin" step="1"
                           value="<?= number_format($infos['ZC12']['valeur_montant'] ?? 0, 0, '', '') ?>">
                </div>
                <div class="form-group">
                    <label>ZC13 - Dépôts à terme à plus d'un an des membres (FCFA)</label>
                    <input type="number" name="depots_terme_plus_1_an_membres" step="1"
                           value="<?= number_format($infos['ZC13']['valeur_montant'] ?? $donnees_calculees['depots_terme_plus_1_an_membres'], 0, '', '') ?>"
                           class="<?= isset($donnees_calculees['depots_terme_plus_1_an_membres']) ? 'calculated-value' : '' ?>">
                    <?php if(isset($donnees_calculees['depots_terme_plus_1_an_membres'])): ?>
                        <small style="color:#2e7d32;">✓ Calculé automatiquement : <?= number_format($donnees_calculees['depots_terme_plus_1_an_membres'], 0, ',', ' ') ?> FCFA</small>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>ZC14 - Comptes d'épargne à régime spécial (FCFA)</label>
                    <input type="number" name="epargne_regime_special" step="1"
                           value="<?= number_format($infos['ZC14']['valeur_montant'] ?? $donnees_calculees['epargne_regime_special'], 0, '', '') ?>"
                           class="<?= isset($donnees_calculees['epargne_regime_special']) ? 'calculated-value' : '' ?>">
                    <?php if(isset($donnees_calculees['epargne_regime_special'])): ?>
                        <small style="color:#2e7d32;">✓ Calculé automatiquement : <?= number_format($donnees_calculees['epargne_regime_special'], 0, ',', ' ') ?> FCFA</small>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>ZC15 - Autres dépôts à plus d'un an des membres (FCFA)</label>
                    <input type="number" name="autres_depots_plus_1_an_membres" step="1"
                           value="<?= number_format($infos['ZC15']['valeur_montant'] ?? 0, 0, '', '') ?>">
                </div>
            </div>
        </div>
        
        <div class="section-card">
            <div class="section-title">📈 RECOUVREMENTS</div>
            <div class="form-row" style="padding: 20px;">
                <div class="form-group">
                    <label>ZC16 - Recouvrements sur prêts intervenus au cours de l'exercice (FCFA)</label>
                    <input type="number" name="recouvrements_prevus" step="1"
                           value="<?= number_format($infos['ZC16']['valeur_montant'] ?? 0, 0, '', '') ?>">
                </div>
                <div class="form-group">
                    <label>ZC17 - Recouvrements sur prêts attendus au cours de l'exercice (FCFA)</label>
                    <input type="number" name="recouvrements_attendus" step="1"
                           value="<?= number_format($infos['ZC17']['valeur_montant'] ?? 0, 0, '', '') ?>">
                </div>
            </div>
            <div class="form-actions" style="padding: 20px; text-align: center; border-top: 1px solid #eee;">
                <button type="submit" class="btn btn-success">💾 Enregistrer les informations</button>
            </div>
        </div>
    </form>
    
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
        window.location.href = 'DIMF_2011.php?exercice=' + exercice + '&trimestre=' + trimestre + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>