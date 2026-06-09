<?php
// R02.php - Couverture des emplois à moyen et long terme par des ressources stables
// Norme BCEAO: ≥ 1 (doit être supérieur ou égal à 1)

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

// Récupérer l'année et le mois
$exercice = isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y');
$mois = isset($_GET['mois']) ? (int)$_GET['mois'] : date('m');
$periode = $exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT);
$date_fin_periode = $exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01';
$date_fin_periode = date('Y-m-t', strtotime($date_fin_periode));

// ============================================================
// A - RESSOURCES STABLES (échéance > 12 mois)
// ============================================================

// L01 - Provisions, fonds propres et assimilés
$fondsPropres = 0;
try {
    $stmtL01 = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '1'
          AND e.date_ecriture <= :date_fin
    ");
    $stmtL01->execute([':date_fin' => $date_fin_periode]);
    $resultL01 = $stmtL01->fetch();
    $fondsPropres = $resultL01['total'];
} catch (PDOException $e) {
    $fondsPropres = 0;
}

// F2A - Autres comptes de dépôts créditeurs (part > 12 mois)
$autresDepotsCrediteurs = 0;

// F3F - Comptes d'emprunts à terme auprès des institutions financières (> 12 mois)
$empruntsTerme = 0;
try {
    $stmtF3F = $pdo->prepare("
        SELECT COALESCE(SUM(montant), 0) as total
        FROM capital
        WHERE statut = 'valide' 
          AND mode_paiement = 'BANQUE'
          AND date_creation <= :date_fin
          AND date_creation >= DATE_SUB(:date_fin, INTERVAL 1 YEAR)
    ");
    $stmtF3F->execute([':date_fin' => $date_fin_periode]);
    $resultF3F = $stmtF3F->fetch();
    $empruntsTerme = $resultF3F['total'];
} catch (PDOException $e) {
    $empruntsTerme = 0;
}

// F50 - Autres sommes dues aux institutions financières (> 12 mois)
$autresSommesDues = 0;

// G15 - Dépôts à terme reçus (> 12 mois)
$depotsTerme = 0;
try {
    $stmtG15 = $pdo->prepare("
        SELECT COALESCE(SUM(capital_initial), 0) as total
        FROM comptes_dat
        WHERE statut = 'en cours'
          AND date_echeance > DATE_ADD(:date_fin, INTERVAL 12 MONTH)
    ");
    $stmtG15->execute([':date_fin' => $date_fin_periode]);
    $resultG15 = $stmtG15->fetch();
    $depotsTerme = $resultG15['total'];
} catch (PDOException $e) {
    $depotsTerme = 0;
}

// G2A - Comptes d'épargne à régime spécial (part stable)
$epargneSpeciale = 0;
try {
    $stmtG2A = $pdo->prepare("
        SELECT COALESCE(SUM(c.solde), 0) as total
        FROM comptes c
        INNER JOIN produits p ON c.produit_id = p.produit_id
        INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id
        WHERE pf.categorie = 'Epargne' 
          AND c.statut = 'actif' 
          AND c.solde > 0
    ");
    $stmtG2A->execute();
    $resultG2A = $stmtG2A->fetch();
    $epargneSpeciale = $resultG2A['total'];
} catch (PDOException $e) {
    $epargneSpeciale = 0;
}

// G30 - Autres dépôts de garantie reçus (> 12 mois)
$depotsGarantie = 0;

// G35 - Autres dépôts reçus (> 12 mois)
$autresDepotsRecus = 0;

// G60 - Emprunts (> 12 mois)
$emprunts = $empruntsTerme;

// G70 - Autres sommes (> 12 mois)
$autresSommes = 0;

// TOTAL A - Ressources stables
$montantA = $fondsPropres + $autresDepotsCrediteurs + $empruntsTerme + $autresSommesDues
          + $depotsTerme + $epargneSpeciale + $depotsGarantie + $autresDepotsRecus
          + $emprunts + $autresSommes;

// ============================================================
// B - EMPLOIS À MOYEN ET LONG TERME (échéance > 12 mois, montants nets des provisions)
// ============================================================

// A2H - Dépôts à terme constitués (> 12 mois)
$depotsTermeConstitués = 0;

// A2I - Dépôts de garantie constitués (> 12 mois)
$depotsGarantieConstitués = 0;

// A2J - Autres dépôts constitués (> 12 mois)
$autresDepotsConstitués = 0;

// A3C - Comptes de prêts à terme (> 12 mois)
$pretsTerme = 0;
try {
    $stmtA3C = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve')
          AND d.duree > 12
    ");
    $stmtA3C->execute();
    $resultA3C = $stmtA3C->fetch();
    $pretsTerme = $resultA3C['total'];
} catch (PDOException $e) {
    $pretsTerme = 0;
}

// A70 - Prêts en souffrance (> 12 mois)
$pretsSouffrance = 0;
try {
    $stmtA70 = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE d.statut = 'impaye'
    ");
    $stmtA70->execute();
    $resultA70 = $stmtA70->fetch();
    $pretsSouffrance = $resultA70['total'];
} catch (PDOException $e) {
    $pretsSouffrance = 0;
}

// B30 - Crédits à moyen terme (12 < durée ≤ 60 mois)
$creditsMoyenTerme = 0;
try {
    $stmtB30 = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve')
          AND d.duree > 12 AND d.duree <= 60
    ");
    $stmtB30->execute();
    $resultB30 = $stmtB30->fetch();
    $creditsMoyenTerme = $resultB30['total'];
} catch (PDOException $e) {
    $creditsMoyenTerme = 0;
}

// B40 - Crédits à long terme (durée > 60 mois)
$creditsLongTerme = 0;
try {
    $stmtB40 = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve')
          AND d.duree > 60
    ");
    $stmtB40->execute();
    $resultB40 = $stmtB40->fetch();
    $creditsLongTerme = $resultB40['total'];
} catch (PDOException $e) {
    $creditsLongTerme = 0;
}

// B70 - Crédits en souffrance
$creditsSouffrance = $pretsSouffrance;

// D1E - Titres de participation
$titresParticipation = 0;
try {
    $stmtD1E = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '26%'
          AND e.date_ecriture <= :date_fin
    ");
    $stmtD1E->execute([':date_fin' => $date_fin_periode]);
    $resultD1E = $stmtD1E->fetch();
    $titresParticipation = $resultD1E['total'];
} catch (PDOException $e) {
    $titresParticipation = 0;
}

// D1L - Titres d'investissement
$titresInvestissement = 0;
try {
    $stmtD1L = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '27%'
          AND e.date_ecriture <= :date_fin
    ");
    $stmtD1L->execute([':date_fin' => $date_fin_periode]);
    $resultD1L = $stmtD1L->fetch();
    $titresInvestissement = $resultD1L['total'];
} catch (PDOException $e) {
    $titresInvestissement = 0;
}

// D10 - Prêts et titres subordonnés
$pretsSubordonnes = 0;

// D1S - Dépôts et cautionnements
$depotsCautionnements = 0;

// D23 - Immobilisations en cours
$immobilisationsEnCours = 0;
try {
    $stmtD23 = $pdo->prepare("
        SELECT COALESCE(SUM(i.montant_achat - i.amortissement_total), 0) as valeur_nette
        FROM immobilisations i
        WHERE i.statut = 'actif'
          AND (i.libelle LIKE '%en cours%' OR i.libelle LIKE '%projet%')
          AND i.date_achat <= :date_fin
    ");
    $stmtD23->execute([':date_fin' => $date_fin_periode]);
    $resultD23 = $stmtD23->fetch();
    $immobilisationsEnCours = $resultD23['valeur_nette'];
} catch (PDOException $e) {
    $immobilisationsEnCours = 0;
}

// D30 - Immobilisations d'exploitation
$immobilisationsExploit = 0;
try {
    $stmtD30 = $pdo->prepare("
        SELECT COALESCE(SUM(i.montant_achat - i.amortissement_total), 0) as valeur_nette
        FROM immobilisations i
        WHERE i.type_immobilisation IN ('Immobilisations corporelles', 'Immobilisations incorporelles')
          AND i.statut = 'actif'
          AND i.date_achat <= :date_fin
          AND i.libelle NOT LIKE '%hors exploitation%'
    ");
    $stmtD30->execute([':date_fin' => $date_fin_periode]);
    $resultD30 = $stmtD30->fetch();
    $immobilisationsExploit = $resultD30['valeur_nette'];
} catch (PDOException $e) {
    $immobilisationsExploit = 0;
}

// D40 - Immobilisations hors exploitation
$immobilisationsHorsExploit = 0;
try {
    $stmtD40 = $pdo->prepare("
        SELECT COALESCE(SUM(i.montant_achat - i.amortissement_total), 0) as valeur_nette
        FROM immobilisations i
        WHERE i.type_immobilisation IN ('Immobilisations corporelles', 'Immobilisations incorporelles')
          AND i.statut = 'actif'
          AND i.date_achat <= :date_fin
          AND (i.libelle LIKE '%hors exploitation%' OR i.libelle LIKE '%terrain%' OR i.libelle LIKE '%immobilier%')
    ");
    $stmtD40->execute([':date_fin' => $date_fin_periode]);
    $resultD40 = $stmtD40->fetch();
    $immobilisationsHorsExploit = $resultD40['valeur_nette'];
} catch (PDOException $e) {
    $immobilisationsHorsExploit = 0;
}

// TOTAL B - Emplois à moyen et long terme
$montantB = $depotsTermeConstitués + $depotsGarantieConstitués + $autresDepotsConstitués
          + $pretsTerme + $pretsSouffrance + $creditsMoyenTerme + $creditsLongTerme
          + $creditsSouffrance + $titresParticipation + $titresInvestissement
          + $pretsSubordonnes + $depotsCautionnements + $immobilisationsEnCours
          + $immobilisationsExploit + $immobilisationsHorsExploit;

// ============================================================
// CALCUL DU RATIO R02
// ============================================================

if ($montantB <= 0) {
    $montantB = 1;
    $ratioR02 = 0;
} else {
    $ratioR02 = $montantA / $montantB;
}

// Norme : ≥ 1
$normeMin = 1;
$normeMax = null;
$conformite = ($ratioR02 >= $normeMin) ? 'CONFORME' : 'NON_CONFORME';

// Détail des postes pour affichage
$detailsRessourcesStables = [
    ['code' => 'L01', 'libelle' => 'Provisions, fonds propres et assimilés', 'montant' => $fondsPropres],
    ['code' => 'F2A', 'libelle' => 'Autres comptes de dépôts créditeurs (>12 mois)', 'montant' => $autresDepotsCrediteurs],
    ['code' => 'F3F', 'libelle' => 'Comptes d\'emprunts à terme (>12 mois)', 'montant' => $empruntsTerme],
    ['code' => 'F50', 'libelle' => 'Autres sommes dues aux institutions financières (>12 mois)', 'montant' => $autresSommesDues],
    ['code' => 'G15', 'libelle' => 'Dépôts à terme reçus (>12 mois)', 'montant' => $depotsTerme],
    ['code' => 'G2A', 'libelle' => 'Comptes d\'épargne à régime spécial', 'montant' => $epargneSpeciale],
    ['code' => 'G30', 'libelle' => 'Autres dépôts de garantie reçus (>12 mois)', 'montant' => $depotsGarantie],
    ['code' => 'G35', 'libelle' => 'Autres dépôts reçus (>12 mois)', 'montant' => $autresDepotsRecus],
    ['code' => 'G60', 'libelle' => 'Emprunts (>12 mois)', 'montant' => $emprunts],
    ['code' => 'G70', 'libelle' => 'Autres sommes (>12 mois)', 'montant' => $autresSommes],
];

$detailsEmploisMLT = [
    ['code' => 'A2H', 'libelle' => 'Dépôts à terme constitués (>12 mois)', 'montant' => $depotsTermeConstitués],
    ['code' => 'A2I', 'libelle' => 'Dépôts de garantie constitués (>12 mois)', 'montant' => $depotsGarantieConstitués],
    ['code' => 'A2J', 'libelle' => 'Autres dépôts constitués (>12 mois)', 'montant' => $autresDepotsConstitués],
    ['code' => 'A3C', 'libelle' => 'Comptes de prêts à terme (>12 mois)', 'montant' => $pretsTerme],
    ['code' => 'A70', 'libelle' => 'Prêts en souffrance (>12 mois)', 'montant' => $pretsSouffrance],
    ['code' => 'B30', 'libelle' => 'Crédits à moyen terme', 'montant' => $creditsMoyenTerme],
    ['code' => 'B40', 'libelle' => 'Crédits à long terme', 'montant' => $creditsLongTerme],
    ['code' => 'B70', 'libelle' => 'Crédits en souffrance', 'montant' => $creditsSouffrance],
    ['code' => 'D1E', 'libelle' => 'Titres de participation', 'montant' => $titresParticipation],
    ['code' => 'D1L', 'libelle' => 'Titres d\'investissement', 'montant' => $titresInvestissement],
    ['code' => 'D10', 'libelle' => 'Prêts et titres subordonnés', 'montant' => $pretsSubordonnes],
    ['code' => 'D1S', 'libelle' => 'Dépôts et cautionnements', 'montant' => $depotsCautionnements],
    ['code' => 'D23', 'libelle' => 'Immobilisations en cours', 'montant' => $immobilisationsEnCours],
    ['code' => 'D30', 'libelle' => 'Immobilisations d\'exploitation', 'montant' => $immobilisationsExploit],
    ['code' => 'D40', 'libelle' => 'Immobilisations hors exploitation', 'montant' => $immobilisationsHorsExploit],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>R02 - Couverture des emplois à moyen et long terme par des ressources stables</title>
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
        
        .ratio-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .ratio-title {
            font-size: 1.1rem;
            color: #555;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .ratio-value-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .ratio-value {
            text-align: center;
        }
        
        .ratio-value .value {
            font-size: 3rem;
            font-weight: bold;
        }
        
        .ratio-value .label {
            color: #777;
            font-size: 0.85rem;
        }
        
        .conforme {
            color: #2e7d32;
        }
        
        .non-conforme {
            color: #c62828;
        }
        
        .norme {
            background: #f5f5f5;
            padding: 10px 20px;
            border-radius: 8px;
            text-align: center;
        }
        
        .norme .title {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .norme .range {
            font-size: 1.3rem;
            font-weight: bold;
            color: #1a3a5c;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.85rem;
        }
        
        .status-conforme {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .status-non-conforme {
            background: #ffebee;
            color: #c62828;
        }
        
        .data-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        
        .data-table h3 {
            padding: 15px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
            font-size: 1rem;
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
        
        .footer {
            text-align: center;
            padding: 20px;
            color: #777;
            font-size: 0.8rem;
        }
        
        .two-columns {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .two-columns > div {
            flex: 1;
            min-width: 300px;
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
            .ratio-value-container {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            
            .two-columns {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>R02 - Couverture des emplois à moyen et long terme par des ressources stables</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">Norme BCEAO : ≥ 1</div>
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
    
    <div class="ratio-card">
        <div class="ratio-title">📊 Ratio R02 - Couverture des emplois MLT par des ressources stables</div>
        <div class="ratio-value-container">
            <div class="ratio-value">
                <div class="value <?= $ratioR02 >= $normeMin ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($ratioR02, 2) ?>
                </div>
                <div class="label">Ressources stables / Emplois MLT</div>
            </div>
            <div class="norme">
                <div class="title">Norme réglementaire</div>
                <div class="range">Ratio ≥ 1</div>
                <div class="label">Conformité requise</div>
            </div>
            <div>
                <span class="status-badge <?= $conformite == 'CONFORME' ? 'status-conforme' : 'status-non-conforme' ?>">
                    <?= $conformite ?>
                </span>
            </div>
        </div>
    </div>
    
    <div class="two-columns">
        <div class="data-table">
            <h3>🏦 A - RESSOURCES STABLES</h3>
            <div class="info-box">
                ⓘ Ne concerne que les parties des comptes ayant une durée résiduelle <strong>supérieure à 12 mois</strong>.
            </div>
            <table>
                <thead>
                    <tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th></tr>
                </thead>
                <tbody>
                    <?php foreach($detailsRessourcesStables as $item): ?>
                    <tr>
                        <td><?= $item['code'] ?></td>
                        <td><?= $item['libelle'] ?></td>
                        <td class="text-right"><?= number_format($item['montant'], 0, ',', ' ') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="background:#e8f5e9; font-weight:bold;">
                        <td colspan="2">TOTAL RESSOURCES STABLES (A)</td>
                        <td class="text-right"><?= number_format($montantA, 0, ',', ' ') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="data-table">
            <h3>📈 B - EMPLOIS À MOYEN ET LONG TERME</h3>
            <div class="info-box">
                ⓘ Ne concerne que les parties des comptes ayant une durée résiduelle <strong>supérieure à 12 mois</strong>.<br>
                ⓘ Montants nets des provisions.
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Libellé</th>
                        <th class="text-right">Montant (FCFA)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($detailsEmploisMLT as $item): ?>
                    <tr>
                        <td><?= $item['code'] ?></td>
                        <td><?= $item['libelle'] ?></td>
                        <td class="text-right"><?= number_format($item['montant'], 0, ',', ' ') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="background:#e8f5e9; font-weight:bold;">
                        <td colspan="2">TOTAL EMPLOIS MLT (B)</td>
                        <td class="text-right"><?= number_format($montantB, 0, ',', ' ') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="data-table">
        <h3>📊 Synthèse du calcul du ratio R02</h3>
        <table>
            <tbody>
                <tr>
                    <td style="width: 60%;"><strong>A - Ressources stables (>12 mois)</strong></td>
                    <td class="text-right"><strong><?= number_format($montantA, 0, ',', ' ') ?> FCFA</strong></td>
                </tr>
                <tr>
                    <td><strong>B - Emplois à moyen et long terme (>12 mois)</strong></td>
                    <td class="text-right"><strong><?= number_format($montantB, 0, ',', ' ') ?> FCFA</strong></td>
                </tr>
                <tr style="background:#f0f7ff;">
                    <td><strong>RATIO R02 = A / B</strong></td>
                    <td class="text-right"><strong><?= number_format($ratioR02, 2) ?></strong></td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="data-table">
        <h3>📖 Interprétation du ratio R02</h3>
        <div style="padding: 15px; line-height: 1.6;">
            <p><strong>Ratio calculé :</strong> <?= number_format($ratioR02, 2) ?></p>
            <p><strong>Formule :</strong> R02 = (Ressources stables) / (Emplois à moyen et long terme)</p>
            <p><strong>Norme BCEAO :</strong> Le ratio doit être <strong>supérieur ou égal à 1</strong>.</p>
            <p><strong>Interprétation :</strong></p>
            <ul style="margin-left: 25px; margin-top: 10px;">
                <?php if($ratioR02 >= $normeMin): ?>
                    <li style="color:#2e7d32;">✓ Le ratio est <strong>CONFORME</strong> à la réglementation BCEAO.</li>
                    <li>Les ressources stables couvrent intégralement les emplois à moyen et long terme.</li>
                    <li>La structure financière de l'institution est équilibrée.</li>
                <?php else: ?>
                    <li style="color:#c62828;">✗ Le ratio est <strong>NON CONFORME</strong> à la réglementation BCEAO.</li>
                    <li>Les ressources stables ne couvrent pas entièrement les emplois à moyen et long terme.</li>
                    <li>L'institution finance des actifs longs par des ressources courtes, ce qui crée un risque de liquidité.</li>
                    <li>Il est recommandé de :</li>
                    <ul style="margin-left: 25px;">
                        <li>Augmenter les ressources stables (fonds propres, dépôts à long terme)</li>
                        <li>Réduire les emplois à long terme (crédits, immobilisations)</li>
                        <li>Refinancer les crédits à long terme par des emprunts adaptés</li>
                    </ul>
                <?php endif; ?>
            </ul>
            <div class="info-box" style="margin-top: 15px;">
                <strong>Note :</strong> Le calcul de ce ratio ne concerne que les parties des comptes ayant une durée résiduelle &gt; 12 mois.
                Pour les postes entièrement à plus de 12 mois, la totalité est reportée.
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
        window.location.href = 'R02.php?exercice=' + exercice + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>