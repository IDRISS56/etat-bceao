<?php
// DIMF_2000.php - Bilan Actif, Passif et Hors Bilan
// FPDF intégré directement (pas besoin de dimf_pdf_helper.php)

session_start();
// ============================================================
// CONFIGURATION BDD
// ============================================================
require_once '../../databases/database.php';

// ============================================================
// CLASSE FPDF INTÉGRÉE
// ============================================================
require_once '../../fpdf/fpdf.php';

class PDF_DIMF extends FPDF {
    public $codeDimf  = 'DIMF';
    public $titreDimf = 'Etat financier';
    public $nomSfd    = 'SFD';
    public $periode   = '';
    public $exercice  = '';

    // Convertit UTF-8 en Latin-1 pour FPDF
    static function u($str) {
        return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $str);
    }

    function Header() {
        // ── Gris léger pour l'en-tête DIMF_2000 ──
        $this->SetFillColor(156, 163, 175);
        $this->Rect(0, 0, $this->GetPageWidth(), 28, 'F');
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(8, 3);
        $this->Cell(0, 4, self::u('Republique de Cote d\'Ivoire  •  Ministere de l\'Economie et des Finances  -  DGTCP / DSFD'), 0, 1, 'L');
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(255, 255, 255);
        $this->SetX(8);
        $this->Cell(0, 7, self::u($this->codeDimf . '  -  ' . $this->titreDimf), 0, 1, 'L');
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(255, 255, 255);
        $this->SetX(8);
        $this->Cell(0, 5, self::u(
            'SFD : ' . $this->nomSfd .
            '   |   Periode : ' . $this->periode .
            '   |   Exercice : ' . $this->exercice .
            '   |   Arrete au : ' . date('d/m/Y')),
            0, 1, 'L');
        $this->SetTextColor(0, 0, 0);
        $this->Ln(4);
    }

    function Footer() {
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 4, self::u(
            'SICS-BCEAO  •  Genere le ' . date('d/m/Y a H:i:s') .
            '  •  Page ' . $this->PageNo() . '/{nb}'),
            0, 0, 'C');
    }

    function SectionTitle($label) {
        $this->SetFont('Arial', 'B', 9);
        // ── Noir pour les titres de sections bilan ──
        $this->SetFillColor(0, 0, 0);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 7, self::u('  ' . strtoupper($label)), 0, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(1);
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
        switch ($style) {
            case 'subtotal':
                $this->SetFillColor(248, 250, 252);
                $this->SetFont('Arial', 'B', 8);
                $fill = true; break;
            case 'total':
                $this->SetFillColor(240, 253, 244);
                $this->SetFont('Arial', 'B', 8.5);
                $fill = true; break;
            default:
                $this->SetFillColor(255, 255, 255);
                $this->SetFont('Arial', '', 7.5);
                $fill = false; break;
        }
        $this->SetTextColor(15, 23, 42);
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.1);
        foreach ($cols as $i => $col) {
            $val   = isset($data[$i]) ? $data[$i] : '';
            $align = isset($col['align']) ? $col['align'] : 'L';
            $this->Cell($col['w'], 5.5, self::u($val), 1, 0, $align, $fill);
        }
        $this->Ln();
    }

    static function montant($val) {
        return number_format((float)$val, 0, ',', ' ') . ' F';
    }
}


// ============================================================
// PARAMÈTRES
// ============================================================
$exercice     = isset($_GET['exercice'])     ? (int)$_GET['exercice']     : date('Y');
$type_periode = isset($_GET['type_periode']) ? $_GET['type_periode']      : 'mensuel';
$mois         = isset($_GET['mois'])         ? (int)$_GET['mois']         : 12;
$trimestre    = isset($_GET['trimestre'])    ? (int)$_GET['trimestre']    : 4;
$semestre     = isset($_GET['semestre'])     ? (int)$_GET['semestre']     : null;

switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
}

$date_fin_periode    = date('Y-m-t', strtotime($exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01'));
$date_debut_exercice = $exercice . '-01-01';

// ============================================================
// CALCULS BILAN ACTIF
// ============================================================
$A10_brut = 0;
try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde_actuel), 0) as total FROM caisses WHERE statut = 'ouverte'"); $stmt->execute(); $A10_brut = $stmt->fetch()['total']; } catch (PDOException $e) {}
$A11_brut = $A10_brut;

$A12_brut = 0;
try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde), 0) as total FROM comptes WHERE solde > 0 AND statut = 'actif'"); $stmt->execute(); $A12_brut = $stmt->fetch()['total']; } catch (PDOException $e) {}

$A2A_brut = 0;
try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde), 0) as total FROM comptes WHERE solde > 0 AND statut = 'actif' AND type_compte_id = 'DEPOT'"); $stmt->execute(); $A2A_brut = $stmt->fetch()['total']; } catch (PDOException $e) {}

$A2H_brut = 0;
try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(capital_initial), 0) as total FROM comptes_dat WHERE statut = 'en cours'"); $stmt->execute(); $A2H_brut = $stmt->fetch()['total']; } catch (PDOException $e) {}

$A3A_brut = 0;
try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut IN ('actif', 'approuve')"); $stmt->execute(); $A3A_brut = $stmt->fetch()['total']; } catch (PDOException $e) {}

$A3B_brut = 0;
try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut IN ('actif', 'approuve') AND d.duree <= 12"); $stmt->execute(); $A3B_brut = $stmt->fetch()['total']; } catch (PDOException $e) {}

$A70_brut = 0;
try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut = 'impaye'"); $stmt->execute(); $A70_brut = $stmt->fetch()['total']; } catch (PDOException $e) {}

$B2D_brut = $A3B_brut;

$B30_brut = 0;
try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut IN ('actif', 'approuve') AND d.duree BETWEEN 13 AND 60"); $stmt->execute(); $B30_brut = $stmt->fetch()['total']; } catch (PDOException $e) {}

$B40_brut = 0;
try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut IN ('actif', 'approuve') AND d.duree > 60"); $stmt->execute(); $B40_brut = $stmt->fetch()['total']; } catch (PDOException $e) {}

$B70_brut = $A70_brut; $B70_amort = 0;
try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(p.montant), 0) as total FROM provisions p WHERE p.statut = 'actif' AND p.type_provision = 'CREANCES'"); $stmt->execute(); $B70_amort = $stmt->fetch()['total']; } catch (PDOException $e) {}

$D01_brut = 0; $D01_amort = 0;
try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_achat), 0) as brut, COALESCE(SUM(amortissement_total), 0) as amort FROM immobilisations WHERE statut = 'actif' AND date_achat <= :date_fin"); $stmt->execute([':date_fin' => $date_fin_periode]); $r = $stmt->fetch(); $D01_brut = $r['brut']; $D01_amort = $r['amort']; } catch (PDOException $e) {}
$D01_net = $D01_brut - $D01_amort;

$total_actif_brut  = $D01_brut;
$total_actif_amort = $D01_amort;
$total_actif_net   = $total_actif_brut - $total_actif_amort;

// ============================================================
// CALCULS BILAN PASSIF
// ============================================================
$F01_net = 0; $F3A_net = 0;
try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) as total FROM capital WHERE statut = 'valide' AND mode_paiement = 'BANQUE'"); $stmt->execute(); $F3A_net = $stmt->fetch()['total']; } catch (PDOException $e) {}

$G01_net = 0; $G10_net = 0;
try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(ABS(solde)), 0) as total FROM comptes WHERE solde < 0 AND statut = 'actif'"); $stmt->execute(); $G10_net = $stmt->fetch()['total']; } catch (PDOException $e) {}
$G15_net = $A2H_brut;

$G2A_net = 0;
try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(c.solde), 0) as total FROM comptes c INNER JOIN produits p ON c.produit_id = p.produit_id INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id WHERE pf.categorie = 'Epargne' AND c.statut = 'actif' AND c.solde > 0"); $stmt->execute(); $G2A_net = $stmt->fetch()['total']; } catch (PDOException $e) {}

$H01_net = 0; $L01_net = 0;
try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '1' AND e.date_ecriture <= :date_fin"); $stmt->execute([':date_fin' => $date_fin_periode]); $L01_net = $stmt->fetch()['total']; } catch (PDOException $e) {}

$total_passif = $F01_net + $G01_net + $H01_net + $L01_net;

// ============================================================
// HORS BILAN
// ============================================================
$N1H_net = 0; $Q1M_net = 0;
$total_hors_bilan = 0;

// ============================================================
// GÉNÉRATION PDF (si format=pdf)
// ============================================================
$format = isset($_GET['format']) ? $_GET['format'] : 'html';

if ($format === 'pdf') {
    switch ($type_periode) {
        case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
        case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
        case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
        default:          $lib_periode = 'Annee ' . $exercice;
    }

    $pdf = new PDF_DIMF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->codeDimf  = 'DIMF_2000';
    $pdf->titreDimf = 'Bilan Actif, Passif et Hors Bilan';
    $pdf->nomSfd    = isset($_SESSION['nom_sfd']) ? $_SESSION['nom_sfd'] : 'SFD';
    $pdf->periode   = $lib_periode;
    $pdf->exercice  = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    // Colonnes Actif : CODE(20) | POSTE(120) | Brut(42) | Amort(42) | Net(53) = 277
    $colsActif = [
        ['label' => 'CODE',          'w' => 20],
        ['label' => 'POSTE ACTIF',   'w' => 120],
        ['label' => 'Brut (FCFA)',   'w' => 42, 'align' => 'R'],
        ['label' => 'Amort. (FCFA)', 'w' => 42, 'align' => 'R'],
        ['label' => 'Net (FCFA)',    'w' => 53, 'align' => 'R'],
    ];
    $pdf->SectionTitle('Bilan Actif');
    $pdf->TableHeader($colsActif);
    $pdf->TableRow($colsActif, ['','A01 - OPERATIONS DE TRESORERIE','-','-','-'], 'subtotal');
    $pdf->TableRow($colsActif, ['A10','Valeur en caisse',                   PDF_DIMF::montant($A10_brut),'0',PDF_DIMF::montant($A10_brut)]);
    $pdf->TableRow($colsActif, ['A11','Billets et monnaies',                PDF_DIMF::montant($A11_brut),'0',PDF_DIMF::montant($A11_brut)]);
    $pdf->TableRow($colsActif, ['A12','Comptes ordinaires debiteurs',       PDF_DIMF::montant($A12_brut),'0',PDF_DIMF::montant($A12_brut)]);
    $pdf->TableRow($colsActif, ['A2A','Autres comptes de depots debiteurs', PDF_DIMF::montant($A2A_brut),'0',PDF_DIMF::montant($A2A_brut)]);
    $pdf->TableRow($colsActif, ['A2H','Depots a terme constitues',          PDF_DIMF::montant($A2H_brut),'0',PDF_DIMF::montant($A2H_brut)]);
    $pdf->TableRow($colsActif, ['A3A','Comptes de prets',                   PDF_DIMF::montant($A3A_brut),'0',PDF_DIMF::montant($A3A_brut)]);
    $pdf->TableRow($colsActif, ['A70','Prets en souffrance',                PDF_DIMF::montant($A70_brut),'0',PDF_DIMF::montant($A70_brut)]);
    $pdf->TableRow($colsActif, ['','B01 - OPERATIONS AVEC LES MEMBRES','-','-','-'], 'subtotal');
    $pdf->TableRow($colsActif, ['B2D','Credits a court terme',  PDF_DIMF::montant($B2D_brut),'0',PDF_DIMF::montant($B2D_brut)]);
    $pdf->TableRow($colsActif, ['B30','Credits a moyen terme',  PDF_DIMF::montant($B30_brut),'0',PDF_DIMF::montant($B30_brut)]);
    $pdf->TableRow($colsActif, ['B40','Credits a long terme',   PDF_DIMF::montant($B40_brut),'0',PDF_DIMF::montant($B40_brut)]);
    $pdf->TableRow($colsActif, ['B70','Credits en souffrance',  PDF_DIMF::montant($B70_brut),PDF_DIMF::montant($B70_amort),PDF_DIMF::montant($B70_brut-$B70_amort)]);
    $pdf->TableRow($colsActif, ['','D01 - VALEURS IMMOBILISEES','-','-','-'], 'subtotal');
    $pdf->TableRow($colsActif, ['D30','Immobilisations d\'exploitation', PDF_DIMF::montant($D01_brut),PDF_DIMF::montant($D01_amort),PDF_DIMF::montant($D01_net)]);
    $pdf->TableRow($colsActif, ['','E90 - TOTAL ACTIF', PDF_DIMF::montant($total_actif_brut),PDF_DIMF::montant($total_actif_amort),PDF_DIMF::montant($total_actif_net)], 'total');

    $pdf->Ln(5);
    $colsPassif = [
        ['label' => 'CODE',         'w' => 20],
        ['label' => 'POSTE PASSIF', 'w' => 204],
        ['label' => 'Net (FCFA)',   'w' => 53, 'align' => 'R'],
    ];
    $pdf->SectionTitle('Bilan Passif');
    $pdf->TableHeader($colsPassif);
    $pdf->TableRow($colsPassif, ['','F01 - OPERATIONS DE TRESORERIE', PDF_DIMF::montant($F01_net)], 'subtotal');
    $pdf->TableRow($colsPassif, ['F3A','Comptes d\'emprunts',           PDF_DIMF::montant($F3A_net)]);
    $pdf->TableRow($colsPassif, ['','G01 - OPERATIONS AVEC LES MEMBRES', PDF_DIMF::montant($G01_net)], 'subtotal');
    $pdf->TableRow($colsPassif, ['G10','Comptes ordinaires crediteurs', PDF_DIMF::montant($G10_net)]);
    $pdf->TableRow($colsPassif, ['G15','Depots a terme recus',          PDF_DIMF::montant($G15_net)]);
    $pdf->TableRow($colsPassif, ['G2A','Comptes d\'epargne regime special', PDF_DIMF::montant($G2A_net)]);
    $pdf->TableRow($colsPassif, ['','L01 - PROVISIONS ET FONDS PROPRES', PDF_DIMF::montant($L01_net)], 'subtotal');
    $pdf->TableRow($colsPassif, ['L01','Fonds propres',                 PDF_DIMF::montant($L01_net)]);
    $pdf->TableRow($colsPassif, ['','L90 - TOTAL PASSIF',               PDF_DIMF::montant($total_passif)], 'total');

    $pdf->Ln(5);
    $colsHB = [
        ['label' => 'CODE',        'w' => 20],
        ['label' => 'ENGAGEMENTS', 'w' => 204],
        ['label' => 'Net (FCFA)',  'w' => 53, 'align' => 'R'],
    ];
    $pdf->SectionTitle('Hors Bilan');
    $pdf->TableHeader($colsHB);
    $pdf->TableRow($colsHB, ['N1H','Engagements recus des institutions financieres', PDF_DIMF::montant($N1H_net)]);
    $pdf->TableRow($colsHB, ['Q1M','Credits distribues pour compte de tiers',       PDF_DIMF::montant($Q1M_net)]);
    $pdf->TableRow($colsHB, ['','TOTAL HORS BILAN',                                  PDF_DIMF::montant($total_hors_bilan)], 'total');

    $pdf->Ln(4);
    $ecart = abs($total_actif_net - $total_passif);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor($ecart > 1000 ? 254 : 240, $ecart > 1000 ? 242 : 253, $ecart > 1000 ? 242 : 244);
    $pdf->Cell(0, 8, PDF_DIMF::u(
        '  Actif net : ' . PDF_DIMF::montant($total_actif_net) .
        '   |   Passif : ' . PDF_DIMF::montant($total_passif) .
        ($ecart > 1000
            ? '   Ecart : ' . PDF_DIMF::montant($ecart) . ' - verifier.'
            : '   Bilan equilibre.')),
        1, 1, 'L', true);

    $pdf->Output('I', 'DIMF_2000_Bilan_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}
// FIN BLOC PDF
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2000 - Bilan Actif, Passif et Hors Bilan</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; padding: 24px; }
        .dashboard { max-width: 1400px; margin: 0 auto; }
        /* ── En-tête page : bleu d'origine ── */
        .page-header { background: linear-gradient(135deg, #3b82f6, #60a5fa); border-radius: 24px; padding: 20px 28px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; box-shadow: 0 4px 6px -2px rgba(0,0,0,0.05); }
        .header-left h1 { font-size: 1.6rem; font-weight: 600; color: white; margin-bottom: 6px; display: flex; align-items: center; gap: 10px; }
        .subtitle { font-size: 0.8rem; color: #e0f2fe; line-height: 1.4; }
        .badge { display: inline-block; background: #2563eb; color: white; padding: 4px 12px; border-radius: 30px; font-size: 0.7rem; font-weight: 500; margin-top: 8px; }
        .btn-group { display: flex; gap: 12px; }
        .btn-excel, .btn-pdf { display: inline-flex; align-items: center; gap: 8px; padding: 8px 20px; border-radius: 40px; font-weight: 500; font-size: 0.85rem; border: none; cursor: pointer; transition: 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.05); text-decoration: none; }
        .btn-excel { background: #10b981; color: white; }
        .btn-excel:hover { background: #059669; transform: translateY(-1px); }
        .btn-pdf { background: #ef4444; color: white; }
        .btn-pdf:hover { background: #dc2626; transform: translateY(-1px); }
        .card { background: white; border-radius: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05), 0 8px 16px -4px rgba(0,0,0,0.05); padding: 20px 24px; margin-bottom: 24px; }
        /* ── Card header : gris foncé ── */
        .card-header { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #eef2f6; font-weight: 600; font-size: 1rem; color: #1e40af; }
        .card-header i { color: #3b82f6; }
        .filters-row { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 20px; }
        .filter-item { display: flex; flex-direction: column; gap: 6px; }
        .filter-item label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #4b5563; }
        .filter-item select, .filter-item input { background: white; border: 1px solid #d1d5db; border-radius: 12px; padding: 8px 14px; font-size: 0.85rem; color: #111827; cursor: pointer; }
        .filter-item select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.2); }
        .btn-apply { background: #3b82f6; color: white; border: none; border-radius: 40px; padding: 8px 24px; font-weight: 500; font-size: 0.85rem; cursor: pointer; transition: 0.2s; }
        .btn-apply:hover { background: #2563eb; transform: translateY(-1px); }
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th { text-align: left; padding: 12px 16px; background: #f8fafc; font-weight: 600; color: #1e293b; border-bottom: 1px solid #e2e8f0; }
        td { padding: 10px 16px; border-bottom: 1px solid #f1f5f9; color: #0f172a; }
        .text-right { text-align: right; font-family: 'Courier New', monospace; font-weight: 500; }
        .subtotal-row { background: #f8fafc; font-weight: 600; }
        .total-row { background: #f0fdf4; font-weight: 700; border-top: 2px solid #bbf7d0; }
        .info-box { background: #eef2ff; border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 16px; display: flex; align-items: center; gap: 14px; }
        .pagination { display: flex; justify-content: flex-end; align-items: center; gap: 12px; margin-top: 8px; padding-top: 8px; border-top: 1px solid #eef2f6; }
        .page-footer { text-align: center; font-size: 0.75rem; color: #6b7280; margin-top: 16px; }
        @media print { body { background: white; padding: 0; } .btn-group, .page-footer, .pagination, #filtersCard, #equilibreCard { display: none !important; } .card { box-shadow: none; } }
        @media (max-width: 780px) { body { padding: 12px; } .filters-row { flex-direction: column; } th, td { padding: 8px 12px; font-size: 0.75rem; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-chart-simple"></i> DIMF_2000 - BILAN</h1>
            <div class="subtitle">République de Côte d'Ivoire / Ministère de l'Economie et des Finances – DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO • État financier annuel</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <?php
            $pdf_params = http_build_query(['exercice'=>$exercice,'type_periode'=>$type_periode,'mois'=>$mois,'trimestre'=>$trimestre,'semestre'=>$semestre,'format'=>'pdf']);
            ?>
            <a class="btn-pdf" href="?<?= $pdf_params ?>" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
        </div>
    </div>

    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="filters-row">
            <div class="filter-item">
                <label>Année</label>
                <select id="exerciceSelect">
                    <?php for ($y = 2020; $y <= date('Y')+1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y==$exercice?'selected':'' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="filter-item">
                <label>Type de période</label>
                <select id="typePeriodeSelect">
                    <option value="mensuel"   <?= $type_periode=='mensuel'  ?'selected':'' ?>>Mensuel</option>
                    <option value="trimestre" <?= $type_periode=='trimestre'?'selected':'' ?>>Trimestre</option>
                    <option value="semestre"  <?= $type_periode=='semestre' ?'selected':'' ?>>Semestre</option>
                    <option value="annuel"    <?= $type_periode=='annuel'   ?'selected':'' ?>>Annuel</option>
                </select>
            </div>
            <div class="filter-item" id="dynamicSelectContainer">
                <?php
                if ($type_periode == 'mensuel') {
                    echo '<label>Mois</label><select id="moisSelect">';
                    for ($m=1;$m<=12;$m++) { $s=($m==$mois)?'selected':''; echo "<option value='$m' $s>".str_pad($m,2,'0',STR_PAD_LEFT)." - ".date('F',mktime(0,0,0,$m,1))."</option>"; }
                    echo '</select>';
                } elseif ($type_periode == 'trimestre') {
                    echo '<label>Trimestre</label><select id="trimestreSelect">';
                    for ($t=1;$t<=4;$t++) { $s=($t==$trimestre)?'selected':''; echo "<option value='$t' $s>$t".($t==1?'er':'ème')." Trimestre</option>"; }
                    echo '</select>';
                } elseif ($type_periode == 'semestre') {
                    echo '<label>Semestre</label><select id="semestreSelect">';
                    for ($s=1;$s<=2;$s++) { $sel=($s==$semestre)?'selected':''; echo "<option value='$s' $sel>$s".($s==1?'er':'e')." semestre</option>"; }
                    echo '</select>';
                } else {
                    echo '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;cursor:default;">';
                }
                ?>
            </div>
            <button class="btn-apply" onclick="appliquerFiltres()"><i class="fas fa-filter"></i> Appliquer</button>
        </div>
        <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;"><i class="fas fa-info-circle"></i> Choisissez le type de période pour affiner la date d'arrêté.</div>
    </div>

    <!-- BILAN ACTIF -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-line"></i> BILAN ACTIF</div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>CODE</th><th>POSTE ACTIF</th><th class="text-right">Brut (FCFA)</th><th class="text-right">Amort. (FCFA)</th><th class="text-right">Net (FCFA)</th></tr></thead>
                <tbody>
                    <tr class="subtotal-row"><td colspan="2">A01 - OPÉRATIONS DE TRÉSORERIE</td><td class="text-right">-</td><td class="text-right">-</td><td class="text-right">-</td></tr>
                    <tr><td>A10</td><td>Valeur en caisse</td><td class="text-right"><?= number_format($A10_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($A10_brut,0,',',' ') ?></td></tr>
                    <tr><td>A11</td><td>Billets et monnaies</td><td class="text-right"><?= number_format($A11_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($A11_brut,0,',',' ') ?></td></tr>
                    <tr><td>A12</td><td>Comptes ordinaires débiteurs</td><td class="text-right"><?= number_format($A12_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($A12_brut,0,',',' ') ?></td></tr>
                    <tr><td>A2A</td><td>Autres comptes de dépôts débiteurs</td><td class="text-right"><?= number_format($A2A_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($A2A_brut,0,',',' ') ?></td></tr>
                    <tr><td>A2H</td><td>Dépôts à terme constitués</td><td class="text-right"><?= number_format($A2H_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($A2H_brut,0,',',' ') ?></td></tr>
                    <tr><td>A3A</td><td>Comptes de prêts</td><td class="text-right"><?= number_format($A3A_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($A3A_brut,0,',',' ') ?></td></tr>
                    <tr><td>A70</td><td>Prêts en souffrance</td><td class="text-right"><?= number_format($A70_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($A70_brut,0,',',' ') ?></td></tr>
                    <tr class="subtotal-row"><td colspan="2">B01 - OPÉRATIONS AVEC LES MEMBRES</td><td class="text-right">-</td><td class="text-right">-</td><td class="text-right">-</td></tr>
                    <tr><td>B2D</td><td>Crédits à court terme</td><td class="text-right"><?= number_format($B2D_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($B2D_brut,0,',',' ') ?></td></tr>
                    <tr><td>B30</td><td>Crédits à moyen terme</td><td class="text-right"><?= number_format($B30_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($B30_brut,0,',',' ') ?></td></tr>
                    <tr><td>B40</td><td>Crédits à long terme</td><td class="text-right"><?= number_format($B40_brut,0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($B40_brut,0,',',' ') ?></td></tr>
                    <tr><td>B70</td><td>Crédits en souffrance</td><td class="text-right"><?= number_format($B70_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($B70_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($B70_brut-$B70_amort,0,',',' ') ?></td></tr>
                    <tr class="subtotal-row"><td colspan="2">D01 - VALEURS IMMOBILISÉES</td><td class="text-right">-</td><td class="text-right">-</td><td class="text-right">-</td></tr>
                    <tr><td>D30</td><td>Immobilisations d'exploitation</td><td class="text-right"><?= number_format($D01_brut,0,',',' ') ?></td><td class="text-right"><?= number_format($D01_amort,0,',',' ') ?></td><td class="text-right"><?= number_format($D01_net,0,',',' ') ?></td></tr>
                    <tr class="total-row"><td colspan="2"><strong>E90 - TOTAL ACTIF</strong></td><td class="text-right"><strong><?= number_format($total_actif_brut,0,',',' ') ?></strong></td><td class="text-right"><strong><?= number_format($total_actif_amort,0,',',' ') ?></strong></td><td class="text-right"><strong><?= number_format($total_actif_net,0,',',' ') ?></strong></td></tr>
                </tbody>
            </table>
        </div>
        <div class="pagination"><span>Lignes par page :</span><select><option>10</option><option>25</option><option>50</option></select></div>
    </div>

    <!-- BILAN PASSIF -->
    <div class="card">
        <div class="card-header"><i class="fas fa-wallet"></i> BILAN PASSIF</div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>CODE</th><th>POSTE PASSIF</th><th class="text-right">Net (FCFA)</th></tr></thead>
                <tbody>
                    <tr class="subtotal-row"><td colspan="2">F01 - OPÉRATIONS DE TRÉSORERIE</td><td class="text-right"><?= number_format($F01_net,0,',',' ') ?></td></tr>
                    <tr><td>F3A</td><td>Comptes d'emprunts</td><td class="text-right"><?= number_format($F3A_net,0,',',' ') ?></td></tr>
                    <tr class="subtotal-row"><td colspan="2">G01 - OPÉRATIONS AVEC LES MEMBRES</td><td class="text-right"><?= number_format($G01_net,0,',',' ') ?></td></tr>
                    <tr><td>G10</td><td>Comptes ordinaires créditeurs</td><td class="text-right"><?= number_format($G10_net,0,',',' ') ?></td></tr>
                    <tr><td>G15</td><td>Dépôts à terme reçus</td><td class="text-right"><?= number_format($G15_net,0,',',' ') ?></td></tr>
                    <tr><td>G2A</td><td>Comptes d'épargne régime spécial</td><td class="text-right"><?= number_format($G2A_net,0,',',' ') ?></td></tr>
                    <tr class="subtotal-row"><td colspan="2">L01 - PROVISIONS ET FONDS PROPRES</td><td class="text-right"><?= number_format($L01_net,0,',',' ') ?></td></tr>
                    <tr><td>L01</td><td>Fonds propres</td><td class="text-right"><?= number_format($L01_net,0,',',' ') ?></td></tr>
                    <tr class="total-row"><td colspan="2"><strong>L90 - TOTAL PASSIF</strong></td><td class="text-right"><strong><?= number_format($total_passif,0,',',' ') ?></strong></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- HORS BILAN -->
    <div class="card">
        <div class="card-header"><i class="fas fa-clipboard-list"></i> HORS BILAN</div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>CODE</th><th>ENGAGEMENTS</th><th class="text-right">Net (FCFA)</th></tr></thead>
                <tbody>
                    <tr><td>N1H</td><td>Engagements reçus des institutions financières</td><td class="text-right"><?= number_format($N1H_net,0,',',' ') ?></td></tr>
                    <tr><td>Q1M</td><td>Crédits distribués pour compte de tiers</td><td class="text-right"><?= number_format($Q1M_net,0,',',' ') ?></td></tr>
                    <tr class="total-row"><td colspan="2"><strong>TOTAL HORS BILAN</strong></td><td class="text-right"><strong><?= number_format($total_hors_bilan,0,',',' ') ?></strong></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" id="equilibreCard">
        <div class="info-box">
            <i class="fas <?= abs($total_actif_net-$total_passif)>1000?'fa-exclamation-triangle':'fa-check-circle' ?>" style="font-size:1.4rem;"></i>
            <div>
                <strong>Note d'équilibre :</strong><br>
                Total Actif net = <?= number_format($total_actif_net,0,',',' ') ?> FCFA &nbsp;|&nbsp; Total Passif = <?= number_format($total_passif,0,',',' ') ?> FCFA
                <?php if(abs($total_actif_net-$total_passif)>1000): ?>
                    <br><span style="color:#b91c1c;">⚠️ Écart de <?= number_format(abs($total_actif_net-$total_passif),0,',',' ') ?> FCFA – vérifier les calculs.</span>
                <?php else: ?>
                    <br><span style="color:#15803d;">✓ Équilibre du bilan respecté.</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="page-footer">
        <i class="fas fa-calendar-alt"></i> Document généré le <?= date('d/m/Y à H:i:s') ?> – Période : <?= $exercice ?> (<?= ucfirst($type_periode) ?>) arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>
    </div>
</div>

<script>
    function updateDynamicSelect() {
        const type = document.getElementById('typePeriodeSelect').value;
        const container = document.getElementById('dynamicSelectContainer');
        const currentMois = <?= $mois ?>;
        const currentTrimestre = <?= $trimestre ?>;
        const currentSemestre = <?= json_encode($semestre) ?>;
        let html = '';
        if (type === 'mensuel') {
            html = '<label>Mois</label><select id="moisSelect">';
            for (let m=1;m<=12;m++) { const s=(m===currentMois)?'selected':''; const n=new Date(2000,m-1,1).toLocaleString('fr',{month:'long'}); html+=`<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`; }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select id="trimestreSelect">';
            for (let t=1;t<=4;t++) { const s=(t===currentTrimestre)?'selected':''; html+=`<option value="${t}" ${s}>${t}${t===1?'er':'ème'} Trimestre</option>`; }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select id="semestreSelect">';
            for (let s=1;s<=2;s++) { const sel=(s===currentSemestre)?'selected':''; html+=`<option value="${s}" ${sel}>${s}${s===1?'er':'e'} semestre</option>`; }
            html += '</select>';
        } else {
            html = '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;cursor:default;">';
        }
        container.innerHTML = html;
    }
    function appliquerFiltres() {
        const exercice = document.getElementById('exerciceSelect').value;
        const type = document.getElementById('typePeriodeSelect').value;
        let url = 'DIMF_2000.php?exercice='+exercice+'&type_periode='+type;
        if (type==='mensuel')   url+='&mois='+document.getElementById('moisSelect').value;
        if (type==='trimestre') url+='&trimestre='+document.getElementById('trimestreSelect').value;
        if (type==='semestre')  url+='&semestre='+document.getElementById('semestreSelect').value;
        window.location.href = url;
    }
    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
    });
    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        const dataActif = [
            ['DIMF_2000 - BILAN ACTIF'],
            ['CODE','POSTE ACTIF','Brut (FCFA)','Amortissements (FCFA)','Net (FCFA)'],
            ['A10','Valeur en caisse',<?= $A10_brut ?>,0,<?= $A10_brut ?>],
            ['A11','Billets et monnaies',<?= $A11_brut ?>,0,<?= $A11_brut ?>],
            ['A12','Comptes ordinaires debiteurs',<?= $A12_brut ?>,0,<?= $A12_brut ?>],
            ['A2A','Autres comptes de depots debiteurs',<?= $A2A_brut ?>,0,<?= $A2A_brut ?>],
            ['A2H','Depots a terme constitues',<?= $A2H_brut ?>,0,<?= $A2H_brut ?>],
            ['A3A','Comptes de prets',<?= $A3A_brut ?>,0,<?= $A3A_brut ?>],
            ['A70','Prets en souffrance',<?= $A70_brut ?>,0,<?= $A70_brut ?>],
            ['B2D','Credits a court terme',<?= $B2D_brut ?>,0,<?= $B2D_brut ?>],
            ['B30','Credits a moyen terme',<?= $B30_brut ?>,0,<?= $B30_brut ?>],
            ['B40','Credits a long terme',<?= $B40_brut ?>,0,<?= $B40_brut ?>],
            ['B70','Credits en souffrance',<?= $B70_brut ?>,<?= $B70_amort ?>,<?= $B70_brut-$B70_amort ?>],
            ['D30','Immobilisations',<?= $D01_brut ?>,<?= $D01_amort ?>,<?= $D01_net ?>],
            ['TOTAL','E90 TOTAL ACTIF',<?= $total_actif_brut ?>,<?= $total_actif_amort ?>,<?= $total_actif_net ?>]
        ];
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(dataActif), "ACTIF");
        const dataPassif = [
            ['DIMF_2000 - BILAN PASSIF'],
            ['CODE','POSTE PASSIF','Net (FCFA)'],
            ['F3A','Comptes d\'emprunts',<?= $F3A_net ?>],
            ['G10','Comptes ordinaires crediteurs',<?= $G10_net ?>],
            ['G15','Depots a terme recus',<?= $G15_net ?>],
            ['G2A','Comptes d\'epargne regime special',<?= $G2A_net ?>],
            ['L01','Fonds propres',<?= $L01_net ?>],
            ['TOTAL','L90 TOTAL PASSIF',<?= $total_passif ?>]
        ];
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(dataPassif), "PASSIF");
        XLSX.writeFile(wb, 'DIMF_2000_Bilan_<?= $exercice ?>_<?= $type_periode ?>.xlsx');
    }
</script>
</body>
</html>