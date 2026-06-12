<?php
// 14-StatPointsServices.php - Statistiques des points de services
// Suivi des agences, points de service et indicateurs associés

session_start();

// Configuration BDD
$host = 'localhost';
$dbname = 'microfinances_dg';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// Récupérer l'exercice et le trimestre/mois
$exercice = isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y');
$mois = isset($_GET['mois']) ? (int)$_GET['mois'] : date('m');
$periode = $exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT);
$date_fin_periode = $exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01';
$date_fin_periode = date('Y-m-t', strtotime($date_fin_periode));

// ============================================================
// RÉCUPÉRATION DES DONNÉES
// ============================================================

// 1. Points de services (Agences)
$pointsServices = [];
try {
    $stmtPS = $pdo->prepare("
        SELECT 
            a.agence_id,
            a.code_agence,
            a.nom_agence,
            a.adresse,
            a.telephone,
            a.directeur,
            a.date_creation,
            a.statut,
            a.coordonnes_gps
        FROM agences a
        WHERE a.statut = 'active'
        ORDER BY a.date_creation ASC
    ");
    $stmtPS->execute();
    $pointsServices = $stmtPS->fetchAll();
} catch (PDOException $e) {
    $pointsServices = [];
}

// 2. Effectif du personnel par agence
$personnelParAgence = [];
try {
    $stmtPersonnel = $pdo->prepare("
        SELECT 
            agence_id,
            COUNT(*) as nb_personnel
        FROM utilisateurs
        WHERE role != 'Client'
          AND etat = 'actif'
        GROUP BY agence_id
    ");
    $stmtPersonnel->execute();
    $personnelParAgence = $stmtPersonnel->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $personnelParAgence = [];
}

// 3. Nombre de clients par agence
$clientsParAgence = [];
try {
    $stmtClients = $pdo->prepare("
        SELECT 
            agence_id,
            COUNT(*) as nb_clients
        FROM clients
        WHERE statut = 'actif'
        GROUP BY agence_id
    ");
    $stmtClients->execute();
    $clientsParAgence = $stmtClients->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $clientsParAgence = [];
}

// 4. Répartition des clients par genre
$clientsParGenre = [];
try {
    $stmtGenre = $pdo->prepare("
        SELECT 
            genre,
            COUNT(*) as nb
        FROM clients
        WHERE statut = 'actif'
        GROUP BY genre
    ");
    $stmtGenre->execute();
    $clientsParGenre = $stmtGenre->fetchAll();
} catch (PDOException $e) {
    $clientsParGenre = [];
}

// 5. Répartition des clients par milieu
$clientsParMilieu = [];
try {
    $stmtMilieu = $pdo->prepare("
        SELECT 
            milieu,
            COUNT(*) as nb
        FROM clients
        WHERE statut = 'actif'
        GROUP BY milieu
    ");
    $stmtMilieu->execute();
    $clientsParMilieu = $stmtMilieu->fetchAll();
} catch (PDOException $e) {
    $clientsParMilieu = [];
}

// 6. Encours des dépôts par agence
$depotsParAgence = [];
try {
    $stmtDepots = $pdo->prepare("
        SELECT 
            c.agence_id,
            COALESCE(SUM(c.solde), 0) as total_depots
        FROM comptes c
        WHERE c.solde > 0
          AND c.statut = 'actif'
        GROUP BY c.agence_id
    ");
    $stmtDepots->execute();
    $depotsParAgence = $stmtDepots->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $depotsParAgence = [];
}

// 7. Nombre de crédits décaissés dans l'année par agence
$creditsDecaissesParAgence = [];
try {
    $stmtCredits = $pdo->prepare("
        SELECT 
            d.agence_id,
            COUNT(*) as nb_credits,
            COALESCE(SUM(d.montant), 0) as montant_total
        FROM dossiers d
        WHERE d.date_octroi BETWEEN :date_debut AND :date_fin
          AND d.statut IN ('actif', 'approuve')
        GROUP BY d.agence_id
    ");
    $stmtCredits->execute([
        ':date_debut' => $exercice . '-01-01',
        ':date_fin' => $date_fin_periode
    ]);
    $creditsDecaissesParAgence = $stmtCredits->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $creditsDecaissesParAgence = [];
}

// 8. Encours des crédits par agence
$encoursCreditsParAgence = [];
try {
    $stmtEncours = $pdo->prepare("
        SELECT 
            d.agence_id,
            COUNT(*) as nb_credits,
            COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as encours_total
        FROM dossiers d
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve')
          AND d.date_octroi <= :date_fin
        GROUP BY d.agence_id
    ");
    $stmtEncours->execute([':date_fin' => $date_fin_periode]);
    $encoursCreditsParAgence = $stmtEncours->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $encoursCreditsParAgence = [];
}

// 9. Répartition des crédits par secteur d'activité
$creditsParSecteur = [];
try {
    $stmtSecteur = $pdo->prepare("
        SELECT 
            c.secteur_id,
            s.nom as secteur_nom,
            COUNT(*) as nb_credits,
            COALESCE(SUM(d.montant), 0) as montant_total
        FROM dossiers d
        INNER JOIN clients c ON d.client_id = c.client_id
        LEFT JOIN secteurs s ON c.secteur_id = s.secteur_id
        WHERE d.date_octroi BETWEEN :date_debut AND :date_fin
          AND d.statut IN ('actif', 'approuve')
        GROUP BY c.secteur_id
        ORDER BY montant_total DESC
    ");
    $stmtSecteur->execute([
        ':date_debut' => $exercice . '-01-01',
        ':date_fin' => $date_fin_periode
    ]);
    $creditsParSecteur = $stmtSecteur->fetchAll();
} catch (PDOException $e) {
    $creditsParSecteur = [];
}

// 10. Totaux généraux
$total_personnel = array_sum($personnelParAgence);
$total_clients = array_sum($clientsParAgence);
$total_depots = array_sum($depotsParAgence);
$total_credits_decaisses = array_sum(array_column($creditsDecaissesParAgence, 'montant_total'));
$total_nb_credits_decaisses = array_sum(array_column($creditsDecaissesParAgence, 'nb_credits'));
$total_encours_credits = array_sum(array_column($encoursCreditsParAgence, 'encours_total'));
$total_nb_encours_credits = array_sum(array_column($encoursCreditsParAgence, 'nb_credits'));

// 11. Calcul des indicateurs de performance par agence
$performanceParAgence = [];
foreach ($pointsServices as $ps) {
    $agence_id = $ps['agence_id'];
    $performanceParAgence[$agence_id] = [
        'nom' => $ps['nom_agence'],
        'personnel' => $personnelParAgence[$agence_id] ?? 0,
        'clients' => $clientsParAgence[$agence_id] ?? 0,
        'depots' => $depotsParAgence[$agence_id] ?? 0,
        'credits_decaisses' => 0,
        'encours_credits' => 0
    ];
    
    foreach ($creditsDecaissesParAgence as $cd) {
        if ($cd['agence_id'] == $agence_id) {
            $performanceParAgence[$agence_id]['credits_decaisses'] = $cd['montant_total'];
            break;
        }
    }
    
    foreach ($encoursCreditsParAgence as $ec) {
        if ($ec['agence_id'] == $agence_id) {
            $performanceParAgence[$agence_id]['encours_credits'] = $ec['encours_total'];
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>14 - Statistiques des points de services</title>
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            padding: 20px;
        }
        
        .stat-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            border-left: 4px solid #1a3a5c;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #1a3a5c;
        }
        
        .stat-label {
            font-size: 0.85rem;
            color: #666;
            margin-top: 5px;
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
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .text-right {
            text-align: right;
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
        
        .total-row {
            background: #e8f5e9;
            font-weight: bold;
        }
        
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin: 15px;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            padding: 20px;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .grid-2 {
                grid-template-columns: 1fr;
            }
            
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            
            table {
                font-size: 0.8rem;
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
        <h1>14 - Statistiques des points de services</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">Données statistiques des agences et points de service</div>
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
            <label>Mois</label>
            <select name="mois" id="mois">
                <?php for($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m == $mois ? 'selected' : '' ?>>
                        <?= str_pad($m, 2, '0', STR_PAD_LEFT) ?> - 
                        <?= date('F', mktime(0,0,0,$m,1)) ?>
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
    
    <!-- Vue d'ensemble -->
    <div class="section-card">
        <div class="section-title">📊 VUE D'ENSEMBLE</div>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= count($pointsServices) ?></div>
                <div class="stat-label">Points de services</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= number_format($total_personnel) ?></div>
                <div class="stat-label">Effectif du personnel</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= number_format($total_clients) ?></div>
                <div class="stat-label">Nombre de membres/clients</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= number_format($total_nb_credits_decaisses) ?></div>
                <div class="stat-label">Crédits décaissés (année)</div>
            </div>
        </div>
    </div>
    
    <!-- Liste des points de services -->
    <div class="section-card">
        <div class="section-title">📍 LISTE DES POINTS DE SERVICES</div>
        <div style="overflow-x: auto;">
            <?php if(empty($pointsServices)): ?>
                <div class="info-box">Aucun point de service enregistré.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Nom de l'agence</th>
                            <th>Adresse</th>
                            <th>Téléphone</th>
                            <th>Directeur</th>
                            <th>Date création</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($pointsServices as $ps): ?>
                        <tr>
                            <td><?= htmlspecialchars($ps['code_agence']) ?></td>
                            <td><?= htmlspecialchars($ps['nom_agence']) ?></td>
                            <td><?= htmlspecialchars($ps['adresse'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($ps['telephone'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($ps['directeur'] ?? '-') ?></td>
                            <td><?= date('d/m/Y', strtotime($ps['date_creation'])) ?></td>
                            <td>
                                <span class="status-badge" style="background: <?= $ps['statut'] == 'active' ? '#e8f5e9' : '#ffebee' ?>; color: <?= $ps['statut'] == 'active' ? '#2e7d32' : '#c62828' ?>">
                                    <?= $ps['statut'] == 'active' ? 'Actif' : 'Inactif' ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Performance par agence -->
    <div class="section-card">
        <div class="section-title">📈 PERFORMANCE PAR AGENCE</div>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Agence</th>
                        <th class="text-right">Personnel</th>
                        <th class="text-right">Clients</th>
                        <th class="text-right">Dépôts (FCFA)</th>
                        <th class="text-right">Crédits décaissés (FCFA)</th>
                        <th class="text-right">Encours crédits (FCFA)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($performanceParAgence as $pa): ?>
                    <tr>
                        <td><?= htmlspecialchars($pa['nom']) ?></td>
                        <td class="text-right"><?= number_format($pa['personnel']) ?></td>
                        <td class="text-right"><?= number_format($pa['clients']) ?></td>
                        <td class="text-right"><?= number_format($pa['depots'], 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($pa['credits_decaisses'], 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($pa['encours_credits'], 0, ',', ' ') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td><strong>TOTAL</strong></td>
                        <td class="text-right"><strong><?= number_format($total_personnel) ?></strong></td>
                        <td class="text-right"><strong><?= number_format($total_clients) ?></strong></td>
                        <td class="text-right"><strong><?= number_format($total_depots, 0, ',', ' ') ?></strong></td>
                        <td class="text-right"><strong><?= number_format($total_credits_decaisses, 0, ',', ' ') ?></strong></td>
                        <td class="text-right"><strong><?= number_format($total_encours_credits, 0, ',', ' ') ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Répartition des clients -->
    <div class="grid-2">
        <div class="section-card">
            <div class="section-title">👥 RÉPARTITION DES CLIENTS PAR GENRE</div>
            <div style="padding: 20px;">
                <?php if(empty($clientsParGenre)): ?>
                    <div class="info-box">Aucune donnée disponible.</div>
                <?php else: ?>
                    <table style="width: 100%;">
                        <?php foreach($clientsParGenre as $cg): ?>
                        <tr>
                            <td><?= htmlspecialchars($cg['genre'] ?? 'Non précisé') ?></td>
                            <td class="text-right"><?= number_format($cg['nb']) ?></td>
                            <td style="width: 50%;">
                                <div style="background: #e0e0e0; border-radius: 10px; height: 20px; overflow: hidden;">
                                    <div style="width: <?= ($total_clients > 0) ? ($cg['nb'] / $total_clients) * 100 : 0 ?>%; background: #1a3a5c; height: 100%;"></div>
                                </div>
                            </td>
                            <td class="text-right"><?= number_format(($total_clients > 0) ? ($cg['nb'] / $total_clients) * 100 : 0, 1) ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="section-card">
            <div class="section-title">🌍 RÉPARTITION DES CLIENTS PAR MILIEU</div>
            <div style="padding: 20px;">
                <?php if(empty($clientsParMilieu)): ?>
                    <div class="info-box">Aucune donnée disponible.</div>
                <?php else: ?>
                    <table style="width: 100%;">
                        <?php foreach($clientsParMilieu as $cm): ?>
                        <tr>
                            <td><?= ucfirst(htmlspecialchars($cm['milieu'])) ?></td>
                            <td class="text-right"><?= number_format($cm['nb']) ?></td>
                            <td style="width: 50%;">
                                <div style="background: #e0e0e0; border-radius: 10px; height: 20px; overflow: hidden;">
                                    <div style="width: <?= ($total_clients > 0) ? ($cm['nb'] / $total_clients) * 100 : 0 ?>%; background: #1a3a5c; height: 100%;"></div>
                                </div>
                            </td>
                            <td class="text-right"><?= number_format(($total_clients > 0) ? ($cm['nb'] / $total_clients) * 100 : 0, 1) ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Crédits par secteur d'activité -->
    <div class="section-card">
        <div class="section-title">🏭 CRÉDITS PAR SECTEUR D'ACTIVITÉ</div>
        <div style="overflow-x: auto;">
            <?php if(empty($creditsParSecteur)): ?>
                <div class="info-box">Aucune donnée disponible.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Secteur d'activité</th>
                            <th class="text-right">Nombre de crédits</th>
                            <th class="text-right">Montant total (FCFA)</th>
                            <th class="text-right">Part (%)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($creditsParSecteur as $cs): ?>
                        <tr>
                            <td><?= htmlspecialchars($cs['secteur_nom'] ?? $cs['secteur_id'] ?? 'Non spécifié') ?></td>
                            <td class="text-right"><?= number_format($cs['nb_credits']) ?></td>
                            <td class="text-right"><?= number_format($cs['montant_total'], 0, ',', ' ') ?></td>
                            <td class="text-right"><?= number_format(($total_credits_decaisses > 0) ? ($cs['montant_total'] / $total_credits_decaisses) * 100 : 0, 1) ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td><strong>TOTAL</strong></td>
                            <td class="text-right"><strong><?= number_format($total_nb_credits_decaisses) ?></strong></td>
                            <td class="text-right"><strong><?= number_format($total_credits_decaisses, 0, ',', ' ') ?></strong></td>
                            <td class="text-right"><strong>100%</strong></td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Indicateurs clés -->
    <div class="section-card">
        <div class="section-title">📌 INDICATEURS CLÉS</div>
        <div class="grid-2">
            <div class="info-box">
                <strong>📊 Productivité du personnel :</strong><br>
                <?php if($total_personnel > 0): ?>
                    Clients par employé : <?= number_format($total_clients / $total_personnel, 1) ?><br>
                    Encours crédits par employé : <?= number_format($total_encours_credits / $total_personnel, 0, ',', ' ') ?> FCFA
                <?php else: ?>
                    Données insuffisantes
                <?php endif; ?>
            </div>
            <div class="info-box">
                <strong>💰 Performance commerciale :</strong><br>
                Taux de pénétration (clients/population cible) : N/A<br>
                Montant moyen des crédits : <?= ($total_nb_credits_decaisses > 0) ? number_format($total_credits_decaisses / $total_nb_credits_decaisses, 0, ',', ' ') : '0' ?> FCFA
            </div>
        </div>
    </div>
    
    <div class="footer">
        Document généré le <?= date('d/m/Y à H:i:s') ?> - Données extraites de la base Mandigo<br>
        Période : <?= $periode ?> (arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
    </div>
</div>

<script>
    function appliquerFiltres() {
        let exercice = document.getElementById('exercice').value;
        let mois = document.getElementById('mois').value;
        window.location.href = '14-StatPointsServices.php?exercice=' + exercice + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>