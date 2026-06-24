<?php
// DIMF_2980.php - Compte de résultat consolidé
// Version conforme au fichier Excel DIMF_2980.xlsx
// Utilise les tables existantes, ne crée aucune nouvelle table

session_start();

require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

// ============================================================
// PARAMÈTRES (POST / GET)
// ============================================================
$exercice     = isset($_POST['exercice'])     ? (int)$_POST['exercice']     : (isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y'));
$type_periode = isset($_POST['type_periode']) ? $_POST['type_periode']      : (isset($_GET['type_periode']) ? $_GET['type_periode'] : 'mensuel');
$mois         = isset($_POST['mois'])         ? (int)$_POST['mois']         : (isset($_GET['mois']) ? (int)$_GET['mois'] : 12);
$trimestre    = isset($_POST['trimestre'])    ? (int)$_POST['trimestre']    : (isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 4);
$semestre     = isset($_POST['semestre'])     ? (int)$_POST['semestre']     : (isset($_GET['semestre']) ? (int)$_GET['semestre'] : null);
$format       = isset($_POST['format'])       ? $_POST['format']            : (isset($_GET['format']) ? $_GET['format'] : 'html');

switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
}
$date_fin_periode = date('Y-m-t', strtotime($exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01'));
$date_debut_exercice = $exercice . '-01-01';

$lib_periode = match($type_periode) {
    'mensuel'   => 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice,
    'trimestre' => $trimestre . 'e Trim. ' . $exercice,
    'semestre'  => $semestre . 'er Sem. ' . $exercice,
    default     => 'Année ' . $exercice,
};

// ============================================================
// FONCTIONS D'EXTRACTION DES MONTANTS PAR PLAGE DE COMPTES
// ============================================================

/**
 * Récupère le total des débits ou crédits pour une plage de comptes.
 * @param string $debut   Début de la plage de numéros de compte
 * @param string $fin     Fin de la plage (ou '%' pour LIKE)
 * @param string $sens    'DEBIT' ou 'CREDIT'
 * @param bool   $like    Si true, utilise LIKE $debut$ (fin ignoré)
 * @return float
 */
function getMontant($debut, $fin = null, $sens = 'DEBIT', $like = false) {
    global $pdo, $date_debut_exercice, $date_fin_periode;
    try {
        if ($like) {
            $sql = "
                SELECT COALESCE(SUM(e.montant_" . strtolower($sens) . "), 0) as total
                FROM ecritures_comptables e
                INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
                WHERE pc.numero_compte LIKE :debut
                  AND e.date_ecriture BETWEEN :debut_date AND :fin_date
                  AND e.statut = 'VALIDÉE'
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':debut' => $debut,
                ':debut_date' => $date_debut_exercice,
                ':fin_date' => $date_fin_periode
            ]);
        } else {
            $sql = "
                SELECT COALESCE(SUM(e.montant_" . strtolower($sens) . "), 0) as total
                FROM ecritures_comptables e
                INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
                WHERE pc.numero_compte BETWEEN :debut AND :fin
                  AND e.date_ecriture BETWEEN :debut_date AND :fin_date
                  AND e.statut = 'VALIDÉE'
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':debut' => $debut,
                ':fin' => $fin,
                ':debut_date' => $date_debut_exercice,
                ':fin_date' => $date_fin_periode
            ]);
        }
        return (float)$stmt->fetch()['total'];
    } catch (PDOException $e) {
        return 0;
    }
}

// ============================================================
// CALCUL DES POSTES DE CHARGES
// ============================================================

$charges = [];

// 600 - INTÉRÊTS ET CHARGES ASSIMILÉES (total)
$charges['600'] = getMontant('66%', null, 'DEBIT', true);

// 601 - Intérêts sur dettes envers institutions financières
// On suppose que ces comptes sont dans une sous-plage, par exemple 661
$charges['601'] = getMontant('661%', null, 'DEBIT', true);

// 602 - Intérêts sur dettes envers membres
$charges['602'] = getMontant('662%', null, 'DEBIT', true);

// 605 - Autres intérêts et charges assimilées
$charges['605'] = $charges['600'] - $charges['601'] - $charges['602'];

// 607 - CHARGES SUR CRÉDIT-BAIL
$charges['607'] = getMontant('668%', null, 'DEBIT', true);

// 608 - COMMISSIONS (non disponibles -> 0)
$charges['608'] = 0;

// 609 - CHARGES SUR OPÉRATIONS FINANCIÈRES (total)
$charges['609'] = getMontant('669%', null, 'DEBIT', true);

// 610 - Charges sur titres de placement
$charges['610'] = getMontant('6691%', null, 'DEBIT', true);

// 611 - Charges sur opérations de change
$charges['611'] = getMontant('6692%', null, 'DEBIT', true);

// 612 - Charges sur opérations hors bilan
$charges['612'] = getMontant('6693%', null, 'DEBIT', true);

// 613 - Charges sur emprunts subordonnés
$charges['613'] = getMontant('6694%', null, 'DEBIT', true);

// 615 - CHARGES DIVERSES D'EXPLOITATION FINANCIÈRE
$charges['615'] = $charges['609'] - $charges['610'] - $charges['611'] - $charges['612'] - $charges['613'];

// 620 - ACHATS DE MARCHANDISES
$charges['620'] = getMontant('607%', null, 'DEBIT', true);

// 621 - STOCKS VENDUS
$charges['621'] = getMontant('6071%', null, 'DEBIT', true);

// 622 - VARIATIONS POSITIVES DE STOCKS DE MARCHANDISES
$charges['622'] = getMontant('6072%', null, 'CREDIT', true); // variation positive = crédit

// 630 - FRAIS GÉNÉRAUX D'EXPLOITATION (total)
$charges['630'] = getMontant('62%', null, 'DEBIT', true) + getMontant('63%', null, 'DEBIT', true) + getMontant('64%', null, 'DEBIT', true);

// 631 - Frais du personnel
$charges['631'] = getMontant('62%', null, 'DEBIT', true);

// 632 - Autres frais généraux
$charges['632'] = $charges['630'] - $charges['631'];

// 640 - DOTATION AUX AMORTISSEMENTS ET PROVISIONS SUR IMMOBILISATIONS
$charges['640'] = getMontant('681%', null, 'DEBIT', true);

// 645 - SOLDE EN PERTE DES CORRECTIONS DE VALEURS SUR CRÉANCES
$charges['645'] = getMontant('687%', null, 'DEBIT', true);

// 650 - EXCÉDENT DES DOTATIONS SUR LES REPRISES DU FONDS POUR RISQUES FINANCIERS GÉNÉRAUX
$charges['650'] = 0;

// 655 - CHARGES EXCEPTIONNELLES
$charges['655'] = getMontant('67%', null, 'DEBIT', true);

// 660 - PERTES SUR EXERCICES ANTÉRIEURS
$charges['660'] = getMontant('671%', null, 'DEBIT', true);

// 670 - IMPOT SUR LES EXCÉDENTS
$charges['670'] = getMontant('695%', null, 'DEBIT', true);

// 690 - TOTAL DES CHARGES
$charges['690'] = array_sum($charges);

// ============================================================
// CALCUL DES POSTES DE PRODUITS
// ============================================================

$produits = [];

// 700 - INTÉRÊTS ET PRODUITS ASSIMILÉS (total)
$produits['700'] = getMontant('76%', null, 'CREDIT', true);

// 701 - Intérêts sur créances envers institutions financières
$produits['701'] = getMontant('761%', null, 'CREDIT', true);

// 702 - Intérêts sur créances envers membres
$produits['702'] = getMontant('762%', null, 'CREDIT', true);

// 704 - Intérêts sur titres d'investissement
$produits['704'] = getMontant('763%', null, 'CREDIT', true);

// 705 - Autres intérêts et produits assimilés
$produits['705'] = $produits['700'] - $produits['701'] - $produits['702'] - $produits['704'];

// 707 - PRODUITS SUR CRÉDIT-BAIL
$produits['707'] = getMontant('768%', null, 'CREDIT', true);

// 708 - COMMISSIONS
$produits['708'] = getMontant('769%', null, 'CREDIT', true);

// 709 - PRODUITS SUR OPÉRATIONS FINANCIÈRES (total)
$produits['709'] = getMontant('77%', null, 'CREDIT', true);

// 710 - Produits sur titres de placement
$produits['710'] = getMontant('771%', null, 'CREDIT', true);

// 711 - Dividendes et produits assimilés
$produits['711'] = getMontant('772%', null, 'CREDIT', true);

// 712 - Produits sur opérations de change
$produits['712'] = getMontant('773%', null, 'CREDIT', true);

// 713 - Produits sur opérations hors bilan
$produits['713'] = getMontant('774%', null, 'CREDIT', true);

// 714 - Produits sur prêts et titres subordonnés
$produits['714'] = getMontant('775%', null, 'CREDIT', true);

// 715 - PRODUITS DIVERS D'EXPLOITATION FINANCIÈRE
$produits['715'] = $produits['709'] - $produits['710'] - $produits['711'] - $produits['712'] - $produits['713'] - $produits['714'];

// 720 - MARGES COMMERCIALES
$produits['720'] = getMontant('706%', null, 'CREDIT', true) - getMontant('607%', null, 'DEBIT', true);

// 721 - VENTES DE MARCHANDISES
$produits['721'] = getMontant('7061%', null, 'CREDIT', true);

// 722 - VARIATIONS NÉGATIVES DE STOCKS DE MARCHANDISES
$produits['722'] = getMontant('6072%', null, 'DEBIT', true); // variation négative = débit

// 730 - PRODUITS GÉNÉRAUX D'EXPLOITATION
$produits['730'] = getMontant('78%', null, 'CREDIT', true);

// 740 - REPRISES D'AMORTISSEMENTS ET DE PROVISIONS SUR IMMOBILISATIONS
$produits['740'] = getMontant('781%', null, 'CREDIT', true);

// 745 - SOLDE EN BÉNÉFICE DES CORRECTIONS DE VALEURS SUR CRÉANCES
$produits['745'] = getMontant('787%', null, 'CREDIT', true);

// 750 - EXCÉDENT DES REPRISES SUR LES DOTATIONS DU FONDS POUR RISQUES FINANCIERS GÉNÉRAUX
$produits['750'] = 0;

// 755 - PRODUITS EXCEPTIONNELS
$produits['755'] = getMontant('79%', null, 'CREDIT', true);

// 760 - PROFITS SUR EXERCICES ANTÉRIEURS
$produits['760'] = getMontant('791%', null, 'CREDIT', true);

// 765 - QUOTE-PART DANS LE RÉSULTAT D'ENTREPRISES MISES EN ÉQUIVALENCE
$produits['765'] = 0;

// 780 - RÉSULTAT DE L'EXERCICE (+/-)
$produits['780'] = $charges['690'] - $produits['700']; // calculé comme total produits - total charges
// 781 - Part du groupe
$produits['781'] = 0;
// 782 - Part des intérêts minoritaires
$produits['782'] = 0;

// 790 - TOTAL DES PRODUITS (somme de tous les produits sauf 780-782 car ce sont des sous-postes du résultat)
$produits['790'] = array_sum($produits) - $produits['780'] - $produits['781'] - $produits['782'];

// ============================================================
// STRUCTURE DÉTAILLÉE POUR L'AFFICHAGE (conforme au fichier Excel)
// ============================================================

// Définition de l'ordre des postes de charges (incluant les codes)
$ordre_charges = [
    '600', '601', '602', '605',
    '607', '608',
    '609', '610', '611', '612', '613',
    '615',
    '620', '621', '622',
    '630', '631', '632',
    '640',
    '645',
    '650',
    '655',
    '660',
    '670',
    '690'
];

// Libellés des postes de charges
$libelles_charges = [
    '600' => 'INTÉRÊTS ET CHARGES ASSIMILÉES',
    '601' => 'Intérêts et charges assimilées sur dettes à l\'égard des institutions financières',
    '602' => 'Intérêts et charges assimilées sur dettes à l\'égard des membres, bénéficiaires ou clients',
    '605' => 'Autres intérêts et charges assimilées',
    '607' => 'CHARGES SUR CRÉDIT-BAIL ET OPÉRATIONS ASSIMILÉES',
    '608' => 'COMMISSIONS',
    '609' => 'CHARGES SUR OPÉRATIONS FINANCIÈRES',
    '610' => 'Charges sur titres de placement',
    '611' => 'Charges sur opérations de change',
    '612' => 'Charges sur opérations hors bilan',
    '613' => 'Charges sur emprunts et titres émis subordonnés',
    '615' => 'CHARGES DIVERSES D\'EXPLOITATION FINANCIÈRE',
    '620' => 'ACHATS DE MARCHANDISES',
    '621' => 'STOCKS VENDUS',
    '622' => 'VARIATIONS POSITIVES DE STOCKS DE MARCHANDISES',
    '630' => 'FRAIS GÉNÉRAUX D\'EXPLOITATION',
    '631' => 'Frais du personnel',
    '632' => 'Autres frais généraux',
    '640' => 'DOTATION AUX AMORTISSEMENTS ET AUX PROVISIONS SUR IMMOBILISATIONS',
    '645' => 'SOLDE EN PERTE DES CORRECTIONS DE VALEURS SUR CRÉANCES ET DU HORS BILAN',
    '650' => 'EXCÉDENT DES DOTATIONS SUR LES REPRISES DU FONDS POUR RISQUES FINANCIERS GÉNÉRAUX',
    '655' => 'CHARGES EXCEPTIONNELLES',
    '660' => 'PERTES SUR EXERCICES ANTÉRIEURS',
    '670' => 'IMPOT SUR LES EXCÉDENTS',
    '690' => 'TOTAL DES CHARGES'
];

// Définition de l'ordre des postes de produits
$ordre_produits = [
    '700', '701', '702', '704', '705',
    '707', '708',
    '709', '710', '711', '712', '713', '714',
    '715',
    '720', '721', '722',
    '730',
    '740',
    '745',
    '750',
    '755',
    '760',
    '765',
    '780', '781', '782',
    '790'
];

$libelles_produits = [
    '700' => 'INTÉRÊTS ET PRODUITS ASSIMILÉS',
    '701' => 'Intérêts et produits assimilés sur créances à l\'égard des institutions financières',
    '702' => 'Intérêts et produits assimilés sur créances à l\'égard des membres, bénéficiaires ou clients',
    '704' => 'Intérêts et produits assimilés sur titres d\'investissement',
    '705' => 'Autres intérêts et produits assimilés',
    '707' => 'PRODUITS SUR CRÉDIT-BAIL ET OPÉRATIONS ASSIMILÉES',
    '708' => 'COMMISSIONS',
    '709' => 'PRODUITS SUR OPÉRATIONS FINANCIÈRES',
    '710' => 'Produits sur titres de placement',
    '711' => 'Dividendes et produits assimilés',
    '712' => 'Produits sur opérations de change',
    '713' => 'Produits sur opérations hors bilan',
    '714' => 'Produits sur prêts et titres subordonnés',
    '715' => 'PRODUITS DIVERS D\'EXPLOITATION FINANCIÈRE',
    '720' => 'MARGES COMMERCIALES',
    '721' => 'VENTES DE MARCHANDISES',
    '722' => 'VARIATIONS NÉGATIVES DE STOCKS DE MARCHANDISES',
    '730' => 'PRODUITS GÉNÉRAUX D\'EXPLOITATION',
    '740' => 'REPRISES D\'AMORTISSEMENTS ET DE PROVISIONS SUR IMMOBILISATIONS',
    '745' => 'SOLDE EN BÉNÉFICE DES CORRECTIONS DE VALEURS SUR CRÉANCES ET DU HORS BILAN',
    '750' => 'EXCÉDENT DES REPRISES SUR LES DOTATIONS DU FONDS POUR RISQUES FINANCIERS GÉNÉRAUX',
    '755' => 'PRODUITS EXCEPTIONNELS',
    '760' => 'PROFITS SUR EXERCICES ANTÉRIEURS',
    '765' => 'QUOTE-PART DANS LE RÉSULTAT D\'ENTREPRISES MISES EN ÉQUIVALENCE',
    '780' => 'RÉSULTAT DE L\'EXERCICE (+/-)',
    '781' => 'Part du groupe',
    '782' => 'Part des intérêts minoritaires',
    '790' => 'TOTAL DES PRODUITS'
];

// ============================================================
// EXPORT PDF (FPDF)
// ============================================================
if ($format === 'pdf') {
    class PDF_DIMF extends FPDF {
        public $codeDimf = 'DIMF_2980';
        public $titreDimf = "Compte de résultat consolidé";
        public $nomSfd = 'SFD';
        public $periode = '';
        public $exercice = '';

        static function u($str) {
            return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
        }

        function Header() {
            $this->SetFillColor(156, 163, 175);
            $this->Rect(0, 0, $this->GetPageWidth(), 28, 'F');
            $this->SetFont('Arial', '', 7);
            $this->SetTextColor(255, 255, 255);
            $this->SetXY(8, 3);
            $this->Cell(0, 4, self::u('République de Côte d\'Ivoire  •  Ministère de l\'Économie et des Finances  -  DGTCP / DSFD'), 0, 1, 'L');
            $this->SetFont('Arial', 'B', 13);
            $this->SetX(8);
            $this->Cell(0, 7, self::u($this->codeDimf . '  -  ' . $this->titreDimf), 0, 1, 'L');
            $this->SetFont('Arial', '', 8);
            $this->SetX(8);
            $this->Cell(0, 5, self::u('SFD : ' . $this->nomSfd . '   |   Période : ' . $this->periode . '   |   Exercice : ' . $this->exercice . '   |   Arrêté au : ' . date('d/m/Y')), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
            $this->Ln(4);
        }

        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, self::u('SICS-BCEAO  •  Généré le ' . date('d/m/Y H:i:s') . '  •  Page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
        }

        function SectionTitle($label) {
            $this->SetFont('Arial', 'B', 9);
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
            foreach ($cols as $col) {
                $align = isset($col['align']) ? $col['align'] : 'L';
                $this->Cell($col['w'], 6, self::u($col['label']), 1, 0, $align, true);
            }
            $this->Ln();
        }

        function TableRow($cols, $data, $style = '') {
            $fill = false;
            if ($style == 'subtotal') {
                $this->SetFillColor(248, 250, 252);
                $this->SetFont('Arial', 'B', 8);
                $fill = true;
            } elseif ($style == 'total') {
                $this->SetFillColor(240, 253, 244);
                $this->SetFont('Arial', 'B', 8.5);
                $fill = true;
            } else {
                $this->SetFont('Arial', '', 7.5);
                $fill = false;
            }
            $this->SetTextColor(15, 23, 42);
            $this->SetDrawColor(226, 232, 240);
            foreach ($cols as $i => $col) {
                $val = isset($data[$i]) ? $data[$i] : '';
                $align = isset($col['align']) ? $col['align'] : 'L';
                $this->Cell($col['w'], 5.5, self::u($val), 1, 0, $align, $fill);
            }
            $this->Ln();
        }

        static function montant($val) {
            return number_format((float)$val, 0, ',', ' ') . ' F';
        }
    }

    if (ob_get_length()) ob_end_clean();

    $pdf = new PDF_DIMF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->nomSfd = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode = $lib_periode;
    $pdf->exercice = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $cols = [
        ['label' => 'CODE', 'w' => 25, 'align' => 'L'],
        ['label' => 'POSTE', 'w' => 130, 'align' => 'L'],
        ['label' => 'Montant (FCFA)', 'w' => 60, 'align' => 'R'],
    ];

    // CHARGES
    $pdf->SectionTitle('COMPTE DE RÉSULTAT CONSOLIDÉ - CHARGES');
    $pdf->TableHeader($cols);
    foreach ($ordre_charges as $code) {
        $style = ($code == '690') ? 'total' : '';
        $pdf->TableRow($cols, [
            $code,
            self::u($libelles_charges[$code]),
            PDF_DIMF::montant($charges[$code])
        ], $style);
    }

    $pdf->Ln(8);

    // PRODUITS
    $pdf->SectionTitle('COMPTE DE RÉSULTAT CONSOLIDÉ - PRODUITS');
    $pdf->TableHeader($cols);
    foreach ($ordre_produits as $code) {
        $style = ($code == '790') ? 'total' : '';
        if (in_array($code, ['780', '781', '782'])) {
            $style = 'subtotal';
        }
        $pdf->TableRow($cols, [
            $code,
            self::u($libelles_produits[$code]),
            PDF_DIMF::montant($produits[$code])
        ], $style);
    }

    // Résultat (affiché séparément)
    $pdf->Ln(8);
    $pdf->SectionTitle('RÉSULTAT DE L\'EXERCICE');
    $pdf->SetFont('Arial', '', 9);
    $resultat = $produits['780'];
    if ($resultat >= 0) {
        $pdf->SetTextColor(22, 163, 74);
        $pdf->Cell(0, 6, self::u('EXCÉDENT : ' . PDF_DIMF::montant($resultat)), 0, 1);
    } else {
        $pdf->SetTextColor(220, 38, 38);
        $pdf->Cell(0, 6, self::u('DÉFICIT : ' . PDF_DIMF::montant(abs($resultat))), 0, 1);
    }

    $pdf->Output('I', 'DIMF_2980_' . $exercice . '.pdf');
    exit;
}

// ============================================================
// EXPORT EXCEL
// ============================================================
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="DIMF_2980_' . $exercice . '.xls"');
    echo '<html><head><meta charset="UTF-8"><style>
        body { font-family: Arial; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #999; padding: 6px; }
        th { background: #f2f2f2; }
        .text-right { text-align: right; }
        .total { background: #e8f5e9; font-weight: bold; }
    </style></head><body>';
    echo '<h2>DIMF_2980 - Compte de résultat consolidé</h2>';
    echo '<p>Période : ' . htmlspecialchars($lib_periode) . '</p>';

    // CHARGES
    echo '<h3>CHARGES</h3>';
    echo '<table><thead><tr><th>CODE</th><th>POSTE</th><th class="text-right">Montant (FCFA)</th></tr></thead><tbody>';
    foreach ($ordre_charges as $code) {
        $class = ($code == '690') ? 'total' : '';
        echo '<tr class="' . $class . '"><td>' . $code . '</td><td>' . htmlspecialchars($libelles_charges[$code]) . '</td><td class="text-right">' . number_format($charges[$code],0,',',' ') . '</td></tr>';
    }
    echo '</tbody></table><br/>';

    // PRODUITS
    echo '<h3>PRODUITS</h3>';
    echo '<table><thead><tr><th>CODE</th><th>POSTE</th><th class="text-right">Montant (FCFA)</th></tr></thead><tbody>';
    foreach ($ordre_produits as $code) {
        $class = ($code == '790') ? 'total' : '';
        echo '<tr class="' . $class . '"><td>' . $code . '</td><td>' . htmlspecialchars($libelles_produits[$code]) . '</td><td class="text-right">' . number_format($produits[$code],0,',',' ') . '</td></tr>';
    }
    echo '</tbody></table><br/>';

    // RÉSULTAT
    echo '<h3>RÉSULTAT DE L\'EXERCICE</h3>';
    $resultat = $produits['780'];
    echo '<p><strong>' . ($resultat >= 0 ? 'EXCÉDENT' : 'DÉFICIT') . ' :</strong> ' . number_format(abs($resultat),0,',',' ') . ' FCFA</p>';

    echo '</body></html>';
    exit;
}

// ============================================================
// AFFICHAGE WEB (HTML)
// ============================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2980 - Compte de résultat consolidé</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter', system-ui, sans-serif; background:#f1f5f9; padding:24px; }
        .dashboard { max-width:1400px; margin:0 auto; }
        .page-header { background:linear-gradient(135deg,#3b82f6,#60a5fa); border-radius:24px; padding:20px 28px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; }
        .header-left h1 { font-size:1.6rem; font-weight:600; color:white; display:flex; align-items:center; gap:10px; }
        .subtitle { font-size:0.8rem; color:#e0f2fe; }
        .badge { background:#2563eb; color:white; padding:4px 12px; border-radius:30px; display:inline-block; margin-top:8px; }
        .btn-group { display:flex; gap:12px; }
        .btn-excel, .btn-pdf { display:inline-flex; align-items:center; gap:8px; padding:8px 20px; border-radius:40px; font-weight:500; border:none; cursor:pointer; }
        .btn-excel { background:#10b981; color:white; }
        .btn-excel:hover { background:#059669; }
        .btn-pdf { background:#ef4444; color:white; }
        .btn-pdf:hover { background:#dc2626; }
        .card { background:white; border-radius:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:24px; overflow:hidden; }
        .card-header { display:flex; align-items:center; gap:10px; padding:16px 24px; background:#f8fafc; border-bottom:1px solid #eef2f6; font-weight:600; color:#1e40af; }
        .card-body { padding:20px 24px; }
        .filters-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:20px; }
        .filter-item { display:flex; flex-direction:column; gap:6px; }
        .filter-item label { font-size:0.7rem; font-weight:600; text-transform:uppercase; color:#4b5563; }
        .filter-item select { background:white; border:1px solid #d1d5db; border-radius:12px; padding:8px 14px; font-size:0.85rem; }
        .btn-apply { background:#3b82f6; color:white; border:none; border-radius:40px; padding:8px 24px; cursor:pointer; }
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        th { padding:12px 16px; background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align:left; font-weight:600; }
        td { padding:10px 16px; border-bottom:1px solid #f1f5f9; }
        .text-right { text-align:right; font-family:'Courier New',monospace; font-weight:500; }
        .total-row { background:#f0fdf4; font-weight:700; border-top:2px solid #bbf7d0; }
        .subtotal-row { background:#f8fafc; font-weight:600; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px 20px; border-radius:16px; display:flex; align-items:center; gap:14px; margin-bottom:20px; }
        .result-box { background:#f0fdf4; border-left:4px solid #22c55e; padding:20px 24px; border-radius:16px; text-align:center; }
        .excedent { color:#16a34a; font-size:1.8rem; font-weight:bold; }
        .deficit { color:#dc2626; font-size:1.8rem; font-weight:bold; }
        .two-cols { display:grid; grid-template-columns:repeat(auto-fit,minmax(500px,1fr)); gap:20px; }
        .footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; padding:16px; }
        @media (max-width:768px) { body { padding:12px; } .filters-row { flex-direction:column; } .btn-group { flex-wrap:wrap; } .two-cols { grid-template-columns:1fr; } }
        @media print { .btn-group, .footer, .filters-row, #filtersCard { display:none; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-chart-line"></i> DIMF_2980 - COMPTE DE RÉSULTAT CONSOLIDÉ</h1>
            <div class="subtitle">République de Côte d'Ivoire / Ministère de l'Économie et des Finances – DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO • Résultats consolidés</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" id="btnPdf"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <!-- Filtres -->
    <form method="post" class="card" id="filtersForm">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="card-body">
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
                    <?php
                    if ($type_periode == 'mensuel') {
                        echo '<label>Mois</label><select name="mois" id="moisSelect">';
                        for ($m=1;$m<=12;$m++) echo '<option value="'.$m.'" '.($m==$mois?'selected':'').'>'.str_pad($m,2,'0',STR_PAD_LEFT).' - '.date('F',mktime(0,0,0,$m,1)).'</option>';
                        echo '</select>';
                    } elseif ($type_periode == 'trimestre') {
                        echo '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
                        for ($t=1;$t<=4;$t++) echo '<option value="'.$t.'" '.($t==$trimestre?'selected':'').'>'.$t.($t==1?'er':'ème').' Trimestre</option>';
                        echo '</select>';
                    } elseif ($type_periode == 'semestre') {
                        echo '<label>Semestre</label><select name="semestre" id="semestreSelect">';
                        for ($s=1;$s<=2;$s++) echo '<option value="'.$s.'" '.($s==$semestre?'selected':'').'>'.$s.($s==1?'er':'e').' semestre</option>';
                        echo '</select>';
                    } else {
                        echo '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">';
                    }
                    ?>
                </div>
                <div class="filter-item">
                    <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Appliquer</button>
                </div>
            </div>
            <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;">
                <i class="fas fa-info-circle"></i> Période : <?= $lib_periode ?> (arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
            </div>
        </div>
    </form>

    <!-- Note -->
    <div class="card">
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <div><strong>Note :</strong> Le compte de résultat consolidé présente la performance financière du groupe (institution + ses filiales) sur la période.</div>
            </div>
        </div>
    </div>

    <!-- Deux colonnes -->
    <div class="two-cols">
        <!-- CHARGES -->
        <div class="card">
            <div class="card-header"><i class="fas fa-arrow-down"></i> CHARGES CONSOLIDÉES</div>
            <div class="card-body">
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>CODE</th><th>POSTE</th><th class="text-right">Montant (FCFA)</th></tr></thead>
                        <tbody>
                            <?php foreach ($ordre_charges as $code): 
                                $class = ($code == '690') ? 'total-row' : '';
                            ?>
                            <tr class="<?= $class ?>">
                                <td><?= $code ?></td>
                                <td><?= htmlspecialchars($libelles_charges[$code]) ?></td>
                                <td class="text-right"><?= number_format($charges[$code],0,',',' ') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- PRODUITS -->
        <div class="card">
            <div class="card-header"><i class="fas fa-arrow-up"></i> PRODUITS CONSOLIDÉS</div>
            <div class="card-body">
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>CODE</th><th>POSTE</th><th class="text-right">Montant (FCFA)</th></tr></thead>
                        <tbody>
                            <?php foreach ($ordre_produits as $code): 
                                $class = '';
                                if ($code == '790') $class = 'total-row';
                                if (in_array($code, ['780','781','782'])) $class = 'subtotal-row';
                            ?>
                            <tr class="<?= $class ?>">
                                <td><?= $code ?></td>
                                <td><?= htmlspecialchars($libelles_produits[$code]) ?></td>
                                <td class="text-right"><?= number_format($produits[$code],0,',',' ') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Résultat -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-simple"></i> RÉSULTAT DE L'EXERCICE</div>
        <div class="card-body">
            <div class="result-box">
                <strong>Résultat = Total Produits – Total Charges</strong><br><br>
                <span class="<?= ($produits['780'] >= 0) ? 'excedent' : 'deficit' ?>">
                    <?= number_format(abs($produits['780']),0,',',' ') ?> FCFA
                </span><br>
                <span style="font-size:0.9rem;">
                    L'exercice <?= $exercice ?> se solde par un <strong><?= ($produits['780'] >= 0) ? 'EXCÉDENT' : 'DÉFICIT' ?></strong> de
                    <?= number_format(abs($produits['780']),0,',',' ') ?> FCFA
                </span>
            </div>
        </div>
    </div>

    <div class="footer">
        <i class="fas fa-calendar-alt"></i> Document généré le <?= date('d/m/Y à H:i:s') ?> - Données extraites de la base
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function updateDynamicSelect() {
        const type = document.getElementById('typePeriodeSelect').value;
        const container = document.getElementById('dynamicSelectContainer');
        const currentMois = <?= $mois ?>;
        const currentTrimestre = <?= $trimestre ?>;
        const currentSemestre = <?= json_encode($semestre) ?>;
        let html = '';
        if (type === 'mensuel') {
            html = '<label>Mois</label><select name="mois" id="moisSelect">';
            for (let m=1;m<=12;m++) {
                const s = (m===currentMois)?'selected':'';
                const n = new Date(2000,m-1,1).toLocaleString('fr',{month:'long'});
                html += `<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`;
            }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
            for (let t=1;t<=4;t++) {
                const s = (t===currentTrimestre)?'selected':'';
                html += `<option value="${t}" ${s}>${t}${t===1?'er':'ème'} Trimestre</option>`;
            }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select name="semestre" id="semestreSelect">';
            for (let s=1;s<=2;s++) {
                const sel = (s===currentSemestre)?'selected':'';
                html += `<option value="${s}" ${sel}>${s}${s===1?'er':'e'} semestre</option>`;
            }
            html += '</select>';
        } else {
            html = '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">';
        }
        container.innerHTML = html;
    }

    function exporterPDF() {
        const form = document.getElementById('filtersForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'format';
        input.value = 'pdf';
        form.appendChild(input);
        // PDF dans la même fenêtre
        form.submit();
        form.removeChild(input);
    }

    function exporterExcel() {
        const form = document.getElementById('filtersForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'format';
        input.value = 'excel';
        form.appendChild(input);
        form.submit();
        form.removeChild(input);
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
        document.getElementById('btnPdf').addEventListener('click', exporterPDF);
    });
</script>
</body>
</html>