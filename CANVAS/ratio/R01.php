<?php
// R01.php - Limitation des risques auxquels est exposée une institution
// Norme BCEAO: 0% à 200% (0 - 2)

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
// A - RISQUES PORTÉS PAR L'INSTITUTION (MONTANTS NETS DES PROVISIONS)
// ============================================================

// A12 - Comptes ordinaires débiteurs chez les institutions financières
$comptesOrdDebiteurs = 0;
try {
    $stmtA12 = $pdo->prepare("
        SELECT COALESCE(SUM(solde), 0) as total
        FROM comptes
        WHERE solde > 0 AND statut = 'actif'
    ");
    $stmtA12->execute();
    $resultA12 = $stmtA12->fetch();
    $comptesOrdDebiteurs = $resultA12['total'];
} catch (PDOException $e) {
    $comptesOrdDebiteurs = 0;
}

// A2A - Autres comptes de dépôts chez les institutions financières
$autresDepots = 0;
try {
    $stmtA2A = $pdo->prepare("
        SELECT COALESCE(SUM(solde), 0) as total
        FROM comptes
        WHERE solde > 0 AND statut = 'actif'
    ");
    $stmtA2A->execute();
    $resultA2A = $stmtA2A->fetch();
    $autresDepots = $resultA2A['total'];
} catch (PDOException $e) {
    $autresDepots = 0;
}

// A3A - Comptes de prêts
$comptesPrets = 0;
try {
    $stmtA3A = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve')
    ");
    $stmtA3A->execute();
    $resultA3A = $stmtA3A->fetch();
    $comptesPrets = $resultA3A['total'];
} catch (PDOException $e) {
    $comptesPrets = 0;
}

// A70 - Prêts en souffrance
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

// B2D - Crédits à court terme (durée ≤ 12 mois)
$creditsCourtTerme = 0;
try {
    $stmtB2D = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve')
          AND d.duree <= 12
    ");
    $stmtB2D->execute();
    $resultB2D = $stmtB2D->fetch();
    $creditsCourtTerme = $resultB2D['total'];
} catch (PDOException $e) {
    $creditsCourtTerme = 0;
}

// B2N - Comptes ordinaires débiteurs des membres
$comptesOrdMembres = $comptesOrdDebiteurs;

// B30 - Crédits à moyen terme (12 mois < durée ≤ 60 mois)
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

// C10 - Titres de placement
$titresPlacement = 0;
try {
    $stmtC10 = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '50%'
          AND e.date_ecriture <= :date_fin
    ");
    $stmtC10->execute([':date_fin' => $date_fin_periode]);
    $resultC10 = $stmtC10->fetch();
    $titresPlacement = $resultC10['total'];
} catch (PDOException $e) {
    $titresPlacement = 0;
}

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

// N1A - Engagements par signature donnés en faveur des institutions financières
$engagementsSignature = 0;
try {
    $stmtN1A = $pdo->prepare("
        SELECT COALESCE(SUM(g.valeur_nette), 0) as total
        FROM garanties g
        WHERE g.statut = 'actif'
    ");
    $stmtN1A->execute();
    $resultN1A = $stmtN1A->fetch();
    $engagementsSignature = $resultN1A['total'];
} catch (PDOException $e) {
    $engagementsSignature = 0;
}

// N1J - Engagements par signature donnés en faveur des membres
$engagementsMembres = $engagementsSignature;

// N3A - Engagements de garantie sur titre à livrer
$engagementsGarantie = 0;
try {
    $stmtN3A = $pdo->prepare("
        SELECT COALESCE(SUM(g.valeur_nette), 0) as total
        FROM garanties g
        WHERE g.code_type_garantie = '04' AND g.statut = 'actif'
    ");
    $stmtN3A->execute();
    $resultN3A = $stmtN3A->fetch();
    $engagementsGarantie = $resultN3A['total'];
} catch (PDOException $e) {
    $engagementsGarantie = 0;
}

// Q1A - Autres engagements donnés par signature
$autresEngagements = 0;

// Éléments à déduire
// F2C - Dépôts de Garantie sur les prêts aux institutions financières
$depotsGarantieInstFin = 0;
try {
    $stmtF2C = $pdo->prepare("
        SELECT COALESCE(SUM(solde), 0) as total
        FROM comptes
        WHERE solde < 0 AND statut = 'actif'
    ");
    $stmtF2C->execute();
    $resultF2C = $stmtF2C->fetch();
    $depotsGarantieInstFin = abs($resultF2C['total']);
} catch (PDOException $e) {
    $depotsGarantieInstFin = 0;
}

// G30 - Dépôts de Garantie sur les crédits aux membres/clients
$depotsGarantieMembres = $depotsGarantieInstFin;

// TOTAL A - Risques portés par l'institution (nets)
$totalA_brut = $comptesOrdDebiteurs + $autresDepots + $comptesPrets + $pretsSouffrance 
             + $creditsCourtTerme + $comptesOrdMembres + $creditsMoyenTerme + $creditsLongTerme
             + $creditsSouffrance + $titresPlacement + $titresParticipation + $titresInvestissement
             + $engagementsSignature + $engagementsMembres + $engagementsGarantie + $autresEngagements;

$totalA_deductions = $depotsGarantieInstFin + $depotsGarantieMembres;
$montantA = $totalA_brut - $totalA_deductions;

// ============================================================
// B - RESSOURCES
// ============================================================

// F1A - Comptes ordinaires créditeurs des institutions financières
$comptesCrediteurs = 0;
try {
    $stmtF1A = $pdo->prepare("
        SELECT COALESCE(SUM(ABS(solde)), 0) as total
        FROM comptes
        WHERE solde < 0 AND statut = 'actif'
    ");
    $stmtF1A->execute();
    $resultF1A = $stmtF1A->fetch();
    $comptesCrediteurs = $resultF1A['total'];
} catch (PDOException $e) {
    $comptesCrediteurs = 0;
}

// F2A - Autres comptes de dépôts créditeurs des institutions financières
$autresDepotsCrediteurs = $comptesCrediteurs;

// F3A - Comptes d'emprunts
$comptesEmprunts = 0;
try {
    $stmtF3A = $pdo->prepare("
        SELECT COALESCE(SUM(montant), 0) as total
        FROM capital
        WHERE statut = 'valide' AND mode_paiement = 'BANQUE'
    ");
    $stmtF3A->execute();
    $resultF3A = $stmtF3A->fetch();
    $comptesEmprunts = $resultF3A['total'];
} catch (PDOException $e) {
    $comptesEmprunts = 0;
}

// F50 - Autres sommes dues aux institutions financières
$autresSommesDues = 0;

// G2A - Comptes d'épargne à régime spécial
$epargneSpeciale = 0;
try {
    $stmtG2A = $pdo->prepare("
        SELECT COALESCE(SUM(c.solde), 0) as total
        FROM comptes c
        INNER JOIN produits p ON c.produit_id = p.produit_id
        INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id
        WHERE pf.categorie = 'Epargne' AND c.statut = 'actif' AND c.solde > 0
    ");
    $stmtG2A->execute();
    $resultG2A = $stmtG2A->fetch();
    $epargneSpeciale = $resultG2A['total'];
} catch (PDOException $e) {
    $epargneSpeciale = 0;
}

// G10 - Comptes ordinaires créditeurs des membres
$comptesCrediteursMembres = $comptesCrediteurs;

// G15 - Dépôts à terme reçus des membres
$depotsTerme = 0;
try {
    $stmtG15 = $pdo->prepare("
        SELECT COALESCE(SUM(capital_initial), 0) as total
        FROM comptes_dat
        WHERE statut = 'en cours'
    ");
    $stmtG15->execute();
    $resultG15 = $stmtG15->fetch();
    $depotsTerme = $resultG15['total'];
} catch (PDOException $e) {
    $depotsTerme = 0;
}

// G35 - Autres dépôts reçus des clients
$autresDepotsClients = 0;

// G60 - Emprunts reçus des clients
$empruntsClients = 0;

// G70 - Autres sommes dues aux membres
$autresSommesMembres = 0;

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

// TOTAL B
$montantB = $comptesCrediteurs + $autresDepotsCrediteurs + $comptesEmprunts + $autresSommesDues
          + $epargneSpeciale + $comptesCrediteursMembres + $depotsTerme + $autresDepotsClients
          + $empruntsClients + $autresSommesMembres + $fondsPropres;

// ============================================================
// CALCUL DU RATIO R01
// ============================================================

if ($montantB <= 0) {
    $montantB = 1;
    $ratioR01 = 0;
} else {
    $ratioR01 = $montantA / $montantB;
}

// Normes : 0% à 200%
$normeMin = 0;
$normeMax = 2;
$conformite = ($ratioR01 >= $normeMin && $ratioR01 <= $normeMax) ? 'CONFORME' : 'NON_CONFORME';
$pourcentageRisques = $ratioR01 * 100;

// Détail des postes pour affichage
$detailsRisques = [
    ['code' => 'A12', 'libelle' => 'Comptes ordinaires débiteurs chez les institutions financières', 'montant' => $comptesOrdDebiteurs],
    ['code' => 'A2A', 'libelle' => 'Autres comptes de dépôts chez les institutions financières', 'montant' => $autresDepots],
    ['code' => 'A3A', 'libelle' => 'Comptes de prêts', 'montant' => $comptesPrets],
    ['code' => 'A70', 'libelle' => 'Prêts en souffrance', 'montant' => $pretsSouffrance],
    ['code' => 'B2D', 'libelle' => 'Crédits à court terme', 'montant' => $creditsCourtTerme],
    ['code' => 'B2N', 'libelle' => 'Comptes ordinaires débiteurs des membres', 'montant' => $comptesOrdMembres],
    ['code' => 'B30', 'libelle' => 'Crédits à moyen terme', 'montant' => $creditsMoyenTerme],
    ['code' => 'B40', 'libelle' => 'Crédits à long terme', 'montant' => $creditsLongTerme],
    ['code' => 'B70', 'libelle' => 'Crédits en souffrance', 'montant' => $creditsSouffrance],
    ['code' => 'C10', 'libelle' => 'Titres de placement', 'montant' => $titresPlacement],
    ['code' => 'D1E', 'libelle' => 'Titres de participation', 'montant' => $titresParticipation],
    ['code' => 'D1L', 'libelle' => 'Titres d\'investissement', 'montant' => $titresInvestissement],
    ['code' => 'N1A', 'libelle' => 'Engagements par signature - institutions financières', 'montant' => $engagementsSignature],
    ['code' => 'N1J', 'libelle' => 'Engagements par signature - membres', 'montant' => $engagementsMembres],
    ['code' => 'N3A', 'libelle' => 'Engagements de garantie sur titre à livrer', 'montant' => $engagementsGarantie],
    ['code' => 'Q1A', 'libelle' => 'Autres engagements donnés par signature', 'montant' => $autresEngagements],
];

$detailsDeductions = [
    ['code' => 'F2C', 'libelle' => 'Dépôts de Garantie sur les prêts aux institutions financières', 'montant' => $depotsGarantieInstFin],
    ['code' => 'G30', 'libelle' => 'Dépôts de Garantie sur les crédits aux membres/clients', 'montant' => $depotsGarantieMembres],
];

$detailsRessources = [
    ['code' => 'F1A', 'libelle' => 'Comptes ordinaires créditeurs des institutions financières', 'montant' => $comptesCrediteurs],
    ['code' => 'F2A', 'libelle' => 'Autres comptes de dépôts créditeurs des institutions financières', 'montant' => $autresDepotsCrediteurs],
    ['code' => 'F3A', 'libelle' => 'Comptes d\'emprunts', 'montant' => $comptesEmprunts],
    ['code' => 'F50', 'libelle' => 'Autres sommes dues aux institutions financières', 'montant' => $autresSommesDues],
    ['code' => 'G2A', 'libelle' => 'Comptes d\'épargne à régime spécial', 'montant' => $epargneSpeciale],
    ['code' => 'G10', 'libelle' => 'Comptes ordinaires créditeurs des membres', 'montant' => $comptesCrediteursMembres],
    ['code' => 'G15', 'libelle' => 'Dépôts à terme reçus des membres', 'montant' => $depotsTerme],
    ['code' => 'G35', 'libelle' => 'Autres dépôts reçus des clients', 'montant' => $autresDepotsClients],
    ['code' => 'G60', 'libelle' => 'Emprunts reçus des clients', 'montant' => $empruntsClients],
    ['code' => 'G70', 'libelle' => 'Autres sommes dues aux membres', 'montant' => $autresSommesMembres],
    ['code' => 'L01', 'libelle' => 'Provisions, fonds propres et assimilés', 'montant' => $fondsPropres],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>R01 - Limitation des risques auxquels est exposée une institution</title>
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
        
        .progress-bar {
            background: #e0e0e0;
            border-radius: 10px;
            height: 20px;
            overflow: hidden;
            margin: 10px 0;
        }
        
        .progress-fill {
            background: linear-gradient(90deg, #2e7d32, #4caf50);
            height: 100%;
            border-radius: 10px;
            transition: width 0.5s ease;
            text-align: center;
            color: white;
            font-size: 0.7rem;
            line-height: 20px;
        }
        
        .progress-fill.non-conforme {
            background: linear-gradient(90deg, #c62828, #f44336);
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
        <h1>R01 - Limitation des risques auxquels est exposée une institution</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">Norme BCEAO : 0% à 200%</div>
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
        <div class="ratio-title">📊 Ratio R01 - Limitation des risques</div>
        <div class="ratio-value-container">
            <div class="ratio-value">
                <div class="value <?= ($ratioR01 >= $normeMin && $ratioR01 <= $normeMax) ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($pourcentageRisques, 2) ?>%
                </div>
                <div class="label">Risques / Ressources</div>
            </div>
            <div class="norme">
                <div class="title">Norme réglementaire</div>
                <div class="range">0% ≤ Ratio ≤ 200%</div>
                <div class="label">Conformité requise</div>
            </div>
            <div>
                <span class="status-badge <?= $conformite == 'CONFORME' ? 'status-conforme' : 'status-non-conforme' ?>">
                    <?= $conformite ?>
                </span>
            </div>
        </div>
        <div class="progress-bar" style="margin-top: 20px;">
            <div class="progress-fill <?= ($ratioR01 >= $normeMin && $ratioR01 <= $normeMax) ? '' : 'non-conforme' ?>" 
                 style="width: <?= min($pourcentageRisques, 100) ?>%;">
                <?= number_format($pourcentageRisques, 1) ?>%
            </div>
        </div>
    </div>
    
    <div class="two-columns">
        <div class="data-table">
            <h3>📈 A - RISQUES PORTÉS PAR L'INSTITUTION</h3>
            <table>
                <thead>
                    <tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th></tr>
                </thead>
                <tbody>
                    <?php foreach($detailsRisques as $item): ?>
                    <tr>
                        <td><?= $item['code'] ?></td>
                        <td><?= $item['libelle'] ?></td>
                        <td class="text-right"><?= number_format($item['montant'], 0, ',', ' ') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="background:#f0f7ff; font-weight:bold;">
                        <td colspan="2">TOTAL BRUT</td>
                        <td class="text-right"><?= number_format($totalA_brut, 0, ',', ' ') ?></td>
                    </tr>
                    <?php foreach($detailsDeductions as $item): ?>
                    <tr style="background:#ffebee;">
                        <td><?= $item['code'] ?></td>
                        <td>- <?= $item['libelle'] ?></td>
                        <td class="text-right">- <?= number_format($item['montant'], 0, ',', ' ') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="background:#e8f5e9; font-weight:bold;">
                        <td colspan="2">TOTAL RISQUES NETS (A)</td>
                        <td class="text-right"><?= number_format($montantA, 0, ',', ' ') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="data-table">
            <h3>💰 B - RESSOURCES</h3>
            <table>
                <thead>
                    <tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th></tr>
                </thead>
                <tbody>
                    <?php foreach($detailsRessources as $item): ?>
                    <tr>
                        <td><?= $item['code'] ?></td>
                        <td><?= $item['libelle'] ?></td>
                        <td class="text-right"><?= number_format($item['montant'], 0, ',', ' ') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="background:#e8f5e9; font-weight:bold;">
                        <td colspan="2">TOTAL RESSOURCES (B)</td>
                        <td class="text-right"><?= number_format($montantB, 0, ',', ' ') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="data-table">
        <h3>📊 Synthèse du calcul du ratio R01</h3>
        <table>
            <tbody>
                <tr>
                    <td style="width: 60%;"><strong>A - Risques portés par l'institution (nets)</strong></td>
                    <td class="text-right"><strong><?= number_format($montantA, 0, ',', ' ') ?> FCFA</strong></td>
                </tr>
                <tr>
                    <td><strong>B - Ressources</strong></td>
                    <td class="text-right"><strong><?= number_format($montantB, 0, ',', ' ') ?> FCFA</strong></td>
                </tr>
                <tr style="background:#f0f7ff;">
                    <td><strong>RATIO R01 = A / B</strong></td>
                    <td class="text-right"><strong><?= number_format($pourcentageRisques, 2) ?>%</strong></td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="data-table">
        <h3>📖 Interprétation du ratio R01</h3>
        <div style="padding: 15px; line-height: 1.6;">
            <p><strong>Ratio calculé :</strong> <?= number_format($pourcentageRisques, 2) ?>%</p>
            <p><strong>Formule :</strong> R01 = (Risques portés par l'institution) / (Ressources)</p>
            <p><strong>Norme BCEAO :</strong> Le ratio doit être compris entre <strong>0% et 200%</strong>.</p>
            <p><strong>Interprétation :</strong></p>
            <ul style="margin-left: 25px; margin-top: 10px;">
                <?php if($ratioR01 >= $normeMin && $ratioR01 <= $normeMax): ?>
                    <li style="color:#2e7d32;">✓ Le ratio est <strong>CONFORME</strong> à la réglementation BCEAO.</li>
                    <li>Les risques portés représentent <?= number_format($pourcentageRisques, 2) ?>% des ressources, soit dans la limite autorisée de 200%.</li>
                    <li>L'institution maîtrise bien son exposition globale.</li>
                <?php else: ?>
                    <li style="color:#c62828;">✗ Le ratio est <strong>NON CONFORME</strong> à la réglementation BCEAO.</li>
                    <li>Les risques portés dépassent les ressources de l'institution.</li>
                    <li>Il est recommandé de :</li>
                    <ul style="margin-left: 25px;">
                        <li>Réduire l'exposition aux risques (prêts, engagements)</li>
                        <li>Augmenter les ressources (fonds propres, dépôts)</li>
                        <li>Renforcer les provisions pour créances douteuses</li>
                    </ul>
                <?php endif; ?>
            </ul>
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
        window.location.href = 'R01.php?exercice=' + exercice + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>