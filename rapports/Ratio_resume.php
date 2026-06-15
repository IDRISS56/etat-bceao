<?php
// Ratio_resume.php - Synthèse des ratios prudentiels R01 à R10 (BCEAO)
// Version avec POST et Bootstrap 5 (design préservé)

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ------------------------- CONNEXION BDD -------------------------
require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

// ------------------------- PARAMÈTRES LUS EN POST AVEC DÉFAUTS -------------------------
$exercice     = isset($_POST['exercice'])     ? (int)$_POST['exercice']     : date('Y');
$type_periode = isset($_POST['type_periode']) ? $_POST['type_periode']      : 'annuel';
$mois         = isset($_POST['mois'])         ? (int)$_POST['mois']         : 12;
$trimestre    = isset($_POST['trimestre'])    ? (int)$_POST['trimestre']    : 4;
$semestre     = isset($_POST['semestre'])     ? (int)$_POST['semestre']     : 2;

switch ($type_periode) {
    case 'mensuel': break;
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre': $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel': $mois = 12; break;
    default: $mois = 12;
}

$date_fin_periode = date('Y-m-t', strtotime("$exercice-" . str_pad($mois, 2, '0', STR_PAD_LEFT) . "-01"));
$date_debut_exercice = "$exercice-01-01";

// ============================================================
// CALCUL DES RATIOS (LOGIQUE INCHANGÉE)
// ============================================================

// R01 - LIMITATION DES RISQUES
$risques_brut = 0; $risques_deductions = 0; $risques_net = 0;
$a12 = 0; $a3a = 0; $b30 = 0; $b40 = 0; $b70 = 0; $d1e = 0; $d1l = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde), 0) as total FROM comptes WHERE solde > 0 AND statut = 'actif'");
    $stmt->execute(); $a12 = (float)$stmt->fetch()['total']; $risques_brut += $a12;
} catch (PDOException $e) { $a12 = 0; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut IN ('actif', 'approuve')");
    $stmt->execute(); $a3a = (float)$stmt->fetch()['total']; $risques_brut += $a3a;
} catch (PDOException $e) { $a3a = 0; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut IN ('actif', 'approuve') AND d.duree BETWEEN 13 AND 60");
    $stmt->execute(); $b30 = (float)$stmt->fetch()['total']; $risques_brut += $b30;
} catch (PDOException $e) { $b30 = 0; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut IN ('actif', 'approuve') AND d.duree > 60");
    $stmt->execute(); $b40 = (float)$stmt->fetch()['total']; $risques_brut += $b40;
} catch (PDOException $e) { $b40 = 0; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut = 'impaye'");
    $stmt->execute(); $b70 = (float)$stmt->fetch()['total']; $risques_brut += $b70;
} catch (PDOException $e) { $b70 = 0; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '26%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $d1e = (float)$stmt->fetch()['total']; $risques_brut += $d1e;
} catch (PDOException $e) { $d1e = 0; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '27%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $d1l = (float)$stmt->fetch()['total']; $risques_brut += $d1l;
} catch (PDOException $e) { $d1l = 0; }

$depots_garantie = 0;
$risques_net = $risques_brut - $depots_garantie;

$ressources_total = 0; $f3a = 0; $g2a = 0; $g10 = 0; $g15 = 0; $l01 = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) as total FROM capital WHERE statut = 'valide' AND mode_paiement = 'BANQUE'");
    $stmt->execute(); $f3a = (float)$stmt->fetch()['total']; $ressources_total += $f3a;
} catch (PDOException $e) { $f3a = 0; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(c.solde), 0) as total FROM comptes c INNER JOIN produits p ON c.produit_id = p.produit_id INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id WHERE pf.categorie = 'Epargne' AND c.statut = 'actif' AND c.solde > 0");
    $stmt->execute(); $g2a = (float)$stmt->fetch()['total']; $ressources_total += $g2a;
} catch (PDOException $e) { $g2a = 0; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(ABS(solde)), 0) as total FROM comptes WHERE solde < 0 AND statut = 'actif'");
    $stmt->execute(); $g10 = (float)$stmt->fetch()['total']; $ressources_total += $g10;
} catch (PDOException $e) { $g10 = 0; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(capital_initial), 0) as total FROM comptes_dat WHERE statut = 'en cours'");
    $stmt->execute(); $g15 = (float)$stmt->fetch()['total']; $ressources_total += $g15;
} catch (PDOException $e) { $g15 = 0; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '1' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $l01 = (float)$stmt->fetch()['total']; $ressources_total += $l01;
} catch (PDOException $e) { $l01 = 0; }

$ratio_r01 = ($ressources_total > 0) ? $risques_net / $ressources_total : 0;
$r01_conforme = ($ratio_r01 >= 0 && $ratio_r01 <= 2);

// R02 - COUVERTURE DES EMPLOIS MLT PAR RESSOURCES STABLES
$ressources_stables = $l01 + $f3a;
$emplois_mlt = $b30 + $b40;
$ratio_r02 = ($emplois_mlt > 0) ? $ressources_stables / $emplois_mlt : 0;
$r02_conforme = ($ratio_r02 >= 1);

// R03 - LIMITATION DES PRETS AUX DIRIGEANTS
$prets_dirigeants = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id INNER JOIN utilisateurs u ON d.utilisateur_id = u.utilisateur_id WHERE u.role IN ('Superviseur', 'Administrateur', 'Responsable', 'Directeur') AND d.statut IN ('actif', 'approuve')");
    $stmt->execute(); $prets_dirigeants = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $prets_dirigeants = 0; }

$immobilisations_incorp = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_achat - amortissement_total), 0) as total FROM immobilisations WHERE type_immobilisation = 'Immobilisations incorporelles' AND statut = 'actif'");
    $stmt->execute(); $immobilisations_incorp = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $immobilisations_incorp = 0; }

$fonds_propres_net = $l01 - $immobilisations_incorp;
$ratio_r03 = ($fonds_propres_net > 0) ? $prets_dirigeants / $fonds_propres_net : 0;
$r03_conforme = ($ratio_r03 <= 0.10);

// R04 - LIMITATION DES RISQUES SUR UNE SEULE SIGNATURE
$plus_gros_emprunteur = 0;
try {
    $stmt = $pdo->prepare("SELECT c.client_id, SUM(encours) as total_encours FROM (SELECT d.compte_id, COALESCE(d.montant - COALESCE(e.rembourse, 0), d.montant) as encours FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut IN ('actif', 'approuve')) as encours_par_dossier INNER JOIN comptes cpt ON encours_par_dossier.compte_id = cpt.compte_id INNER JOIN clients c ON cpt.client_id = c.client_id GROUP BY c.client_id ORDER BY total_encours DESC LIMIT 1");
    $stmt->execute(); $plus_gros_emprunteur = (float)$stmt->fetch()['total_encours'];
} catch (PDOException $e) { $plus_gros_emprunteur = 0; }

$ratio_r04 = ($fonds_propres_net > 0) ? $plus_gros_emprunteur / $fonds_propres_net : 0;
$r04_conforme = ($ratio_r04 <= 0.10);

// R05 - NORME DE LIQUIDITE
$disponibilites = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde_actuel), 0) as total FROM caisses WHERE statut = 'ouverte'");
    $stmt->execute(); $disponibilites = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $disponibilites = 0; }

$valeurs_realisables = $disponibilites + $a12 + $a3a;
$passif_exigible = $g10 + $g15;
$ratio_r05 = ($passif_exigible > 0) ? $valeurs_realisables / $passif_exigible : 0;
$r05_conforme = ($ratio_r05 >= 1);

// R06 - LIMITATION DES OPERATIONS HORS ACTIVITES PRINCIPALES
$autres_activites = 0;
$ratio_r06 = ($risques_net > 0) ? $autres_activites / $risques_net : 0;
$r06_conforme = ($ratio_r06 <= 0.05);

// R07 - CONSTITUTION DE LA RESERVE GENERALE
$resultat_exercice = 0; $dotation_reserve = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN pc.classe_compte = '7' THEN e.montant_credit - e.montant_debit ELSE 0 END), 0) as produits, COALESCE(SUM(CASE WHEN pc.classe_compte = '6' THEN e.montant_debit - e.montant_credit ELSE 0 END), 0) as charges FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte IN ('6', '7') AND e.date_ecriture BETWEEN :debut AND :fin");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $res = $stmt->fetch(); $resultat_exercice = $res['produits'] - $res['charges'];
} catch (PDOException $e) { $resultat_exercice = 0; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '106%' AND e.date_ecriture BETWEEN :debut AND :fin");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]); $dotation_reserve = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $dotation_reserve = 0; }

$ratio_r07 = ($resultat_exercice > 0) ? $dotation_reserve / $resultat_exercice : 0;
$r07_conforme = ($ratio_r07 >= 0.15);

// R08 - NORME DE CAPITALISATION
$total_actif = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_debit - montant_credit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '2' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $total_actif = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $total_actif = 0; }

$ratio_r08 = ($total_actif > 0) ? $fonds_propres_net / $total_actif : 0;
$r08_conforme = ($ratio_r08 >= 0.15);

// R09 - LIMITATION DES PRISES DE PARTICIPATION
$ratio_r09 = ($fonds_propres_net > 0) ? $d1e / $fonds_propres_net : 0;
$r09_conforme = ($ratio_r09 <= 0.25);

// R10 - FINANCEMENT DES IMMOBILISATIONS ET PARTICIPATIONS
$immobilisations_totales = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_achat - amortissement_total), 0) as total FROM immobilisations WHERE statut = 'actif'");
    $stmt->execute(); $immobilisations_totales = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $immobilisations_totales = 0; }

$actifs_immobilises = $immobilisations_totales + $d1e;
$ratio_r10 = ($fonds_propres_net > 0) ? $actifs_immobilises / $fonds_propres_net : 0;
$r10_conforme = ($ratio_r10 <= 1);


// ------------------------- EXPORT PDF AVEC PDF_DIMF (via POST) -------------------------
if (isset($_POST['export']) && $_POST['export'] === 'pdf') {
    
    class PDF_DIMF extends FPDF {
        public $codeDimf  = 'RESUME';
        public $titreDimf = 'RESUME DES RATIOS PRUDENTIELS (R01-R10)';
        public $nomSfd    = 'SFD';
        public $periode   = '';
        public $exercice  = '';

        static function u($str) {
            return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $str);
        }

        function Header() {
            $this->SetFillColor(156, 163, 175);
            $this->Rect(0, 0, $this->GetPageWidth(), 28, 'F');
            $this->SetFont('Arial', '', 7);
            $this->SetTextColor(255, 255, 255);
            $this->SetXY(8, 3);
            $this->Cell(0, 4, self::u('République de Côte d\'Ivoire  •  Ministère de l\'Economie et des Finances  -  DGTCP / DSFD'), 0, 1, 'L');
            $this->SetFont('Arial', 'B', 13);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 7, self::u($this->codeDimf . '  -  ' . $this->titreDimf), 0, 1, 'L');
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 5, self::u(
                'SFD : ' . $this->nomSfd . 
                '   |   Période : ' . $this->periode . 
                '   |   Exercice : ' . $this->exercice . 
                '   |   Arrêté au : ' . date('d/m/Y', strtotime($GLOBALS['date_fin_periode']))
            ), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
            $this->Ln(4);
        }

        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, self::u(
                'SICS-BCEAO  •  Généré le ' . date('d/m/Y H:i:s') . 
                '  •  Page ' . $this->PageNo() . '/{nb}'),
            0, 0, 'C');
        }

        function TableHeader($cols) {
            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(248, 250, 252);
            $this->SetTextColor(30, 41, 59);
            $this->SetDrawColor(226, 232, 240);
            $this->SetLineWidth(0.2);
            foreach ($cols as $col) {
                $align = isset($col['align']) ? $col['align'] : 'L';
                $this->Cell($col['w'], 6, self::u($col['label']), 1, 0, $align, true);
            }
            $this->Ln();
        }

        function TableRow($cols, $data, $style = '') {
            $fill = false;
            if ($style == 'total') {
                $this->SetFillColor(240, 253, 244);
                $this->SetFont('Arial', 'B', 8.5);
                $fill = true;
            } else {
                $this->SetFillColor(255, 255, 255);
                $this->SetFont('Arial', '', 7.5);
                $fill = false;
            }
            $this->SetTextColor(15, 23, 42);
            $this->SetDrawColor(226, 232, 240);
            $this->SetLineWidth(0.1);
            foreach ($cols as $i => $col) {
                $val = isset($data[$i]) ? $data[$i] : '';
                $align = isset($col['align']) ? $col['align'] : 'L';
                $this->Cell($col['w'], 5.5, self::u($val), 1, 0, $align, $fill);
            }
            $this->Ln();
        }
    }

    $pdf = new PDF_DIMF();
    $pdf->AliasNbPages();
    $pdf->codeDimf = 'RESUME';
    $pdf->titreDimf = 'RESUME DES RATIOS PRUDENTIELS (R01-R10)';
    $pdf->nomSfd = 'SFD';
    $pdf->periode = ucfirst($type_periode);
    $pdf->exercice = $exercice;
    $pdf->AddPage();

    $cols = [
        ['w' => 20, 'label' => 'Code', 'align' => 'L'],
        ['w' => 70, 'label' => 'Libellé', 'align' => 'L'],
        ['w' => 30, 'label' => 'Valeur', 'align' => 'R'],
        ['w' => 25, 'label' => 'Min', 'align' => 'R'],
        ['w' => 25, 'label' => 'Max', 'align' => 'R'],
        ['w' => 30, 'label' => 'Conformité', 'align' => 'C']
    ];
    $pdf->TableHeader($cols);

    $ratios_data = [
        ['R01', 'Limitation des risques (Risques nets / Ressources)', number_format($ratio_r01 * 100, 2) . '%', '0%', '200%', $r01_conforme ? 'CONFORME' : 'NON CONFORME'],
        ['R02', 'Couverture des emplois MLT par ressources stables', number_format($ratio_r02, 2), '1.00', '-', $r02_conforme ? 'CONFORME' : 'NON CONFORME'],
        ['R03', 'Limitation des prêts aux dirigeants', number_format($ratio_r03 * 100, 2) . '%', '0%', '10%', $r03_conforme ? 'CONFORME' : 'NON CONFORME'],
        ['R04', 'Limitation des risques sur une seule signature', number_format($ratio_r04 * 100, 2) . '%', '0%', '10%', $r04_conforme ? 'CONFORME' : 'NON CONFORME'],
        ['R05', 'Norme de liquidité', number_format($ratio_r05, 2), '1.00', '-', $r05_conforme ? 'CONFORME' : 'NON CONFORME'],
        ['R06', 'Limitation des opérations hors activités principales', number_format($ratio_r06 * 100, 2) . '%', '0%', '5%', $r06_conforme ? 'CONFORME' : 'NON CONFORME'],
        ['R07', 'Constitution de la réserve générale', number_format($ratio_r07 * 100, 2) . '%', '15%', '-', $r07_conforme ? 'CONFORME' : 'NON CONFORME'],
        ['R08', 'Norme de capitalisation', number_format($ratio_r08 * 100, 2) . '%', '15%', '-', $r08_conforme ? 'CONFORME' : 'NON CONFORME'],
        ['R09', 'Limitation des prises de participation', number_format($ratio_r09 * 100, 2) . '%', '0%', '25%', $r09_conforme ? 'CONFORME' : 'NON CONFORME'],
        ['R10', 'Financement des immobilisations', number_format($ratio_r10 * 100, 2) . '%', '0%', '100%', $r10_conforme ? 'CONFORME' : 'NON CONFORME'],
    ];

    foreach ($ratios_data as $row) {
        $pdf->TableRow($cols, $row);
    }

    $pdf->Output('I', 'RESUME_RATIOS_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}

// ------------------------- EXPORT EXCEL (HTML .xls) VIA POST -------------------------
if (isset($_POST['export']) && $_POST['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="RESUME_RATIOS_' . $exercice . '_' . $type_periode . '.xls"');
    
    echo '<html><head><meta charset="UTF-8"><style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    h2 { color: #1a3a5c; }
    table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
    th, td { border: 1px solid #999; padding: 8px; }
    th { background: #f2f2f2; text-align: center; font-weight: bold; }
    .text-right { text-align: right; }
    .conforme { color: #2e7d32; font-weight: bold; }
    .non-conforme { color: #c62828; font-weight: bold; }
    </style></head><body>';
    
    echo '<h2>RESUME DES RATIOS PRUDENTIELS (R01 à R10)</h2>';
    echo '<p>Période : ' . $exercice . ' - ' . ucfirst($type_periode) . ' (arrêtée au ' . date('d/m/Y', strtotime($date_fin_periode)) . ')</p>';
    
    echo '<table>';
    echo '<tr><th>Code</th><th>Libellé</th><th class="text-right">Valeur calculée</th><th class="text-right">Norme min</th><th class="text-right">Norme max</th><th>Conformité</th></tr>';
    
    $ratios_data = [
        ['R01', 'Limitation des risques (Risques nets / Ressources)', number_format($ratio_r01 * 100, 2) . '%', '0%', '200%', $r01_conforme ? 'CONFORME' : 'NON CONFORME'],
        ['R02', 'Couverture des emplois MLT par ressources stables', number_format($ratio_r02, 2), '1.00', '-', $r02_conforme ? 'CONFORME' : 'NON CONFORME'],
        ['R03', 'Limitation des prêts aux dirigeants', number_format($ratio_r03 * 100, 2) . '%', '0%', '10%', $r03_conforme ? 'CONFORME' : 'NON CONFORME'],
        ['R04', 'Limitation des risques sur une seule signature', number_format($ratio_r04 * 100, 2) . '%', '0%', '10%', $r04_conforme ? 'CONFORME' : 'NON CONFORME'],
        ['R05', 'Norme de liquidité', number_format($ratio_r05, 2), '1.00', '-', $r05_conforme ? 'CONFORME' : 'NON CONFORME'],
        ['R06', 'Limitation des opérations hors activités principales', number_format($ratio_r06 * 100, 2) . '%', '0%', '5%', $r06_conforme ? 'CONFORME' : 'NON CONFORME'],
        ['R07', 'Constitution de la réserve générale', number_format($ratio_r07 * 100, 2) . '%', '15%', '-', $r07_conforme ? 'CONFORME' : 'NON CONFORME'],
        ['R08', 'Norme de capitalisation', number_format($ratio_r08 * 100, 2) . '%', '15%', '-', $r08_conforme ? 'CONFORME' : 'NON CONFORME'],
        ['R09', 'Limitation des prises de participation', number_format($ratio_r09 * 100, 2) . '%', '0%', '25%', $r09_conforme ? 'CONFORME' : 'NON CONFORME'],
        ['R10', 'Financement des immobilisations', number_format($ratio_r10 * 100, 2) . '%', '0%', '100%', $r10_conforme ? 'CONFORME' : 'NON CONFORME'],
    ];
    
    foreach ($ratios_data as $row) {
        $conf_class = ($row[5] == 'CONFORME') ? 'conforme' : 'non-conforme';
        echo "<tr>
            <td><strong>{$row[0]}</strong></td>
            <td>{$row[1]}</td>
            <td class='text-right'>{$row[2]}</td>
            <td class='text-right'>{$row[3]}</td>
            <td class='text-right'>{$row[4]}</td>
            <td class='{$conf_class}'>{$row[5]}</td>
        </tr>";
    }
    echo '<table>';
    
    echo '<h3>Détail des calculs de base</h3>';
    echo '<table>';
    echo '<tr><th>Indicateur</th><th class="text-right">Montant (FCFA)</th></tr>';
    echo '<tr><td>Fonds propres nets</td><td class="text-right">' . number_format($fonds_propres_net, 0, ',', ' ') . '</td></tr>';
    echo '<tr><td>Total actif</td><td class="text-right">' . number_format($total_actif, 0, ',', ' ') . '</td></tr>';
    echo '<tr><td>Risques nets</td><td class="text-right">' . number_format($risques_net, 0, ',', ' ') . '</td></tr>';
    echo '<tr><td>Ressources totales</td><td class="text-right">' . number_format($ressources_total, 0, ',', ' ') . '</td></tr>';
    echo '<tr><td>Prêts aux dirigeants</td><td class="text-right">' . number_format($prets_dirigeants, 0, ',', ' ') . '</td></tr>';
    echo '<tr><td>Immobilisations nettes</td><td class="text-right">' . number_format($immobilisations_totales, 0, ',', ' ') . '</td></tr>';
    echo '</table>';
    
    echo '</body></html>';
    exit;
}

// ------------------------- AFFICHAGE WEB (INTERFACE DIMF_2000 AVEC BOOTSTRAP 5, DESIGN CONSERVÉ) -------------------------
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>RESUME - Ratios prudentiels R01 à R10</title>
    <!-- Bootstrap 5 CSS (intégré sans modification du design) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Styles DIMF_2000 (inchangés) */
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',system-ui,sans-serif; background:#f1f5f9; padding:24px; }
        .dashboard { max-width:1400px; margin:0 auto; }
        .page-header { background:linear-gradient(135deg,#3b82f6,#60a5fa); border-radius:24px; padding:20px 28px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; }
        .header-left h1 { font-size:1.6rem; font-weight:600; color:white; display:flex; align-items:center; gap:10px; }
        .subtitle { font-size:0.8rem; color:#e0f2fe; }
        .badge { background:#2563eb; color:white; padding:4px 12px; border-radius:30px; display:inline-block; margin-top:8px; }
        .btn-group { display:flex; gap:12px; }
        .btn-excel, .btn-pdf { padding:8px 20px; border-radius:40px; font-weight:500; border:none; cursor:pointer; }
        .btn-excel { background:#10b981; color:white; }
        .btn-pdf { background:#ef4444; color:white; }
        .card { background:white; border-radius:20px; padding:20px 24px; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05); }
        .card-header { display:flex; align-items:center; gap:10px; border-bottom:1px solid #eef2f6; padding-bottom:12px; margin-bottom:16px; font-weight:600; color:#1e40af; }
        .filters-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:20px; }
        .filter-item { display:flex; flex-direction:column; gap:6px; }
        .filter-item label { font-size:0.7rem; font-weight:600; text-transform:uppercase; color:#4b5563; }
        .filter-item select, .filter-item input { padding:8px 14px; border:1px solid #d1d5db; border-radius:12px; }
        .btn-apply { background:#3b82f6; color:white; border:none; border-radius:40px; padding:8px 24px; cursor:pointer; }
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:12px 16px; text-align:left; border-bottom:1px solid #f1f5f9; }
        th { background:#f8fafc; font-weight:600; }
        .text-right { text-align:right; }
        .text-center { text-align:center; }
        .conforme { color: #2e7d32; font-weight: bold; }
        .non-conforme { color: #c62828; font-weight: bold; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px; border-radius:16px; display:flex; align-items:center; gap:14px; }
        .page-footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; }
        @media print { .btn-group, .filters-row, #filtersCard { display:none; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-chart-pie"></i> RESUME DES RATIOS PRUDENTIELS</h1>
            <div class="subtitle">République de Côte d'Ivoire / DGTCP / DSFD</div>
            <div class="badge">Synthèse R01 à R10 - Article 44</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="submitExport('excel')"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" onclick="submitExport('pdf')"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <!-- Formulaire de filtres en POST -->
    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres de période</div>
        <form method="post" id="filterForm">
            <div class="filters-row">
                <div class="filter-item">
                    <label>Année</label>
                    <select name="exercice" id="exerciceSelect">
                        <?php for($y=2020;$y<=date('Y')+1;$y++): ?>
                            <option value="<?=$y?>" <?=$y==$exercice?'selected':''?>><?=$y?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filter-item">
                    <label>Type de période</label>
                    <select name="type_periode" id="typePeriodeSelect">
                        <option value="mensuel" <?=$type_periode=='mensuel'?'selected':''?>>Mensuel</option>
                        <option value="trimestre" <?=$type_periode=='trimestre'?'selected':''?>>Trimestre</option>
                        <option value="semestre" <?=$type_periode=='semestre'?'selected':''?>>Semestre</option>
                        <option value="annuel" <?=$type_periode=='annuel'?'selected':''?>>Annuel</option>
                    </select>
                </div>
                <div class="filter-item" id="dynamicSelectContainer">
                    <!-- Contenu dynamique généré par JS, les noms des champs sont 'mois', 'trimestre' ou 'semestre' -->
                </div>
                <button type="submit" class="btn-apply">Appliquer</button>
            </div>
        </form>
    </div>

    <!-- Tableau des ratios -->
    <div class="card">
        <div class="card-header"><i class="fas fa-table"></i> Synthèse des Ratios Prudentiels</div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Libellé</th>
                        <th class="text-right">Valeur calculée</th>
                        <th class="text-right">Norme min</th>
                        <th class="text-right">Norme max</th>
                        <th class="text-center">Conformité</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>R01</strong></td>
                        <td>Limitation des risques (Risques nets / Ressources)</td>
                        <td class="text-right <?= $r01_conforme ? 'conforme' : 'non-conforme' ?>"><?= number_format($ratio_r01 * 100, 2) ?>%</td>
                        <td class="text-right">0%</td>
                        <td class="text-right">200%</td>
                        <td class="text-center <?= $r01_conforme ? 'conforme' : 'non-conforme' ?>"><?= $r01_conforme ? '✓ CONFORME' : '✗ NON CONFORME' ?></td>
                    </tr>
                    <tr>
                        <td><strong>R02</strong></td>
                        <td>Couverture des emplois MLT par ressources stables</td>
                        <td class="text-right <?= $r02_conforme ? 'conforme' : 'non-conforme' ?>"><?= number_format($ratio_r02, 2) ?></td>
                        <td class="text-right">1.00</td>
                        <td class="text-right">-</td>
                        <td class="text-center <?= $r02_conforme ? 'conforme' : 'non-conforme' ?>"><?= $r02_conforme ? '✓ CONFORME' : '✗ NON CONFORME' ?></td>
                    </tr>
                    <tr>
                        <td><strong>R03</strong></td>
                        <td>Limitation des prêts aux dirigeants</td>
                        <td class="text-right <?= $r03_conforme ? 'conforme' : 'non-conforme' ?>"><?= number_format($ratio_r03 * 100, 2) ?>%</td>
                        <td class="text-right">0%</td>
                        <td class="text-right">10%</td>
                        <td class="text-center <?= $r03_conforme ? 'conforme' : 'non-conforme' ?>"><?= $r03_conforme ? '✓ CONFORME' : '✗ NON CONFORME' ?></td>
                    </tr>
                    <tr>
                        <td><strong>R04</strong></td>
                        <td>Limitation des risques sur une seule signature</td>
                        <td class="text-right <?= $r04_conforme ? 'conforme' : 'non-conforme' ?>"><?= number_format($ratio_r04 * 100, 2) ?>%</td>
                        <td class="text-right">0%</td>
                        <td class="text-right">10%</td>
                        <td class="text-center <?= $r04_conforme ? 'conforme' : 'non-conforme' ?>"><?= $r04_conforme ? '✓ CONFORME' : '✗ NON CONFORME' ?></td>
                    </tr>
                    <tr>
                        <td><strong>R05</strong></td>
                        <td>Norme de liquidité</td>
                        <td class="text-right <?= $r05_conforme ? 'conforme' : 'non-conforme' ?>"><?= number_format($ratio_r05, 2) ?></td>
                        <td class="text-right">1.00</td>
                        <td class="text-right">-</td>
                        <td class="text-center <?= $r05_conforme ? 'conforme' : 'non-conforme' ?>"><?= $r05_conforme ? '✓ CONFORME' : '✗ NON CONFORME' ?></td>
                    </tr>
                    <tr>
                        <td><strong>R06</strong></td>
                        <td>Limitation des opérations hors activités principales</td>
                        <td class="text-right <?= $r06_conforme ? 'conforme' : 'non-conforme' ?>"><?= number_format($ratio_r06 * 100, 2) ?>%</td>
                        <td class="text-right">0%</td>
                        <td class="text-right">5%</td>
                        <td class="text-center <?= $r06_conforme ? 'conforme' : 'non-conforme' ?>"><?= $r06_conforme ? '✓ CONFORME' : '✗ NON CONFORME' ?></td>
                    </tr>
                    <tr>
                        <td><strong>R07</strong></td>
                        <td>Constitution de la réserve générale</td>
                        <td class="text-right <?= $r07_conforme ? 'conforme' : 'non-conforme' ?>"><?= number_format($ratio_r07 * 100, 2) ?>%</td>
                        <td class="text-right">15%</td>
                        <td class="text-right">-</td>
                        <td class="text-center <?= $r07_conforme ? 'conforme' : 'non-conforme' ?>"><?= $r07_conforme ? '✓ CONFORME' : '✗ NON CONFORME' ?></td>
                    </tr>
                    <tr>
                        <td><strong>R08</strong></td>
                        <td>Norme de capitalisation</td>
                        <td class="text-right <?= $r08_conforme ? 'conforme' : 'non-conforme' ?>"><?= number_format($ratio_r08 * 100, 2) ?>%</td>
                        <td class="text-right">15%</td>
                        <td class="text-right">-</td>
                        <td class="text-center <?= $r08_conforme ? 'conforme' : 'non-conforme' ?>"><?= $r08_conforme ? '✓ CONFORME' : '✗ NON CONFORME' ?></td>
                    </tr>
                    <tr>
                        <td><strong>R09</strong></td>
                        <td>Limitation des prises de participation</td>
                        <td class="text-right <?= $r09_conforme ? 'conforme' : 'non-conforme' ?>"><?= number_format($ratio_r09 * 100, 2) ?>%</td>
                        <td class="text-right">0%</td>
                        <td class="text-right">25%</td>
                        <td class="text-center <?= $r09_conforme ? 'conforme' : 'non-conforme' ?>"><?= $r09_conforme ? '✓ CONFORME' : '✗ NON CONFORME' ?></td>
                    </tr>
                    <tr>
                        <td><strong>R10</strong></td>
                        <td>Financement des immobilisations</td>
                        <td class="text-right <?= $r10_conforme ? 'conforme' : 'non-conforme' ?>"><?= number_format($ratio_r10 * 100, 2) ?>%</td>
                        <td class="text-right">0%</td>
                        <td class="text-right">100%</td>
                        <td class="text-center <?= $r10_conforme ? 'conforme' : 'non-conforme' ?>"><?= $r10_conforme ? '✓ CONFORME' : '✗ NON CONFORME' ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Détail des calculs -->
    <div class="card">
        <div class="card-header"><i class="fas fa-calculator"></i> Détail des calculs de base</div>
        <div class="info-box">
            <i class="fas fa-info-circle" style="font-size: 1.5rem; color: #3b82f6;"></i>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; width: 100%;">
                <div><strong>Fonds propres nets :</strong> <?= number_format($fonds_propres_net, 0, ',', ' ') ?> FCFA</div>
                <div><strong>Total actif :</strong> <?= number_format($total_actif, 0, ',', ' ') ?> FCFA</div>
                <div><strong>Risques nets :</strong> <?= number_format($risques_net, 0, ',', ' ') ?> FCFA</div>
                <div><strong>Ressources totales :</strong> <?= number_format($ressources_total, 0, ',', ' ') ?> FCFA</div>
                <div><strong>Prêts aux dirigeants :</strong> <?= number_format($prets_dirigeants, 0, ',', ' ') ?> FCFA</div>
                <div><strong>Immobilisations nettes :</strong> <?= number_format($immobilisations_totales, 0, ',', ' ') ?> FCFA</div>
            </div>
        </div>
    </div>

    <div class="page-footer">
        <i class="fas fa-calendar-alt"></i> Généré le <?=date('d/m/Y à H:i:s')?> – Période <?=$exercice?> (<?=ucfirst($type_periode)?>) arrêtée au <?=date('d/m/Y',strtotime($date_fin_periode))?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Remplissage dynamique du select (mois, trimestre, semestre) avec conservation des valeurs POST
    function updateDynamicSelect() {
        const type = document.getElementById('typePeriodeSelect').value;
        const container = document.getElementById('dynamicSelectContainer');
        const currentMois = <?=$mois?>;
        const currentTrimestre = <?=$trimestre?>;
        const currentSemestre = <?=json_encode($semestre)?>;
        let html = '';
        if (type === 'mensuel') {
            html = '<label>Mois</label><select name="mois" id="moisSelect" class="form-select">';
            for (let m = 1; m <= 12; m++) {
                let selected = (m === currentMois) ? 'selected' : '';
                html += `<option value="${m}" ${selected}>${String(m).padStart(2,'0')} - ${new Date(2000,m-1,1).toLocaleString('fr',{month:'long'})}</option>`;
            }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select name="trimestre" id="trimestreSelect" class="form-select">';
            for (let t = 1; t <= 4; t++) {
                let selected = (t === currentTrimestre) ? 'selected' : '';
                html += `<option value="${t}" ${selected}>${t}${t===1?'er':'ème'} Trimestre</option>`;
            }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select name="semestre" id="semestreSelect" class="form-select">';
            for (let s = 1; s <= 2; s++) {
                let selected = (s === currentSemestre) ? 'selected' : '';
                html += `<option value="${s}" ${selected}>${s}${s===1?'er':'e'} semestre</option>`;
            }
            html += '</select>';
        } else {
            html = '<label>Période</label><input type="text" class="form-control" disabled value="Année complète">';
        }
        container.innerHTML = html;
    }

    // Soumission des exports en POST (réutilisation des valeurs du formulaire principal)
    function submitExport(type) {
        const form = document.getElementById('filterForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'export';
        input.value = type;
        form.appendChild(input);
        form.submit();
        form.removeChild(input);
    }

    // Écouteur pour changement de type période
    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
    });
</script>
</body>
</html>