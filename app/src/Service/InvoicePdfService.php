<?php

declare(strict_types=1);

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * Generates the "facture" PDF for an already-fulfilled (paid) order. No
 * payment link/QR code — by construction this is only ever produced after
 * payment. Modeled on AttestationPdfService's dompdf usage. Layout mirrors
 * the club's earlier SumUp-generated invoices (logo + club identity top
 * left, billing name/address top right, a boxed "Facture" number/date/
 * status block, the line-item table, and the season date range under the
 * total).
 */
final class InvoicePdfService
{
    private ?string $logoDataUri = null;

    /** @param array $club settings.php's 'club' array (name/address/postal_city/siret/email/phone/website/bank) */
    public function __construct(
        private readonly string $uploadsDir,
        private readonly array $club,
        private readonly string $logoPath,
    ) {
    }

    /**
     * @param array{number:string, seasonLabel:string, sequence:int} $allocation
     * @param array $order the orders row (for amount/kind)
     * @param array{address:string, postalcode:string, city:string} $billingAddress
     * @param list<array{description:string, blurb:string, quantity:int, unitPrice:float, reduc:string, amount:float}> $lines
     * @return string stored path, relative to uploads/, e.g. invoices/2026-2027/facture-SQ-2026-2027-001-<rand>.pdf
     */
    public function generate(
        array $allocation,
        \DateTimeImmutable $issuedAt,
        array $order,
        string $billingName,
        array $billingAddress,
        array $lines,
        Season $season,
    ): string {
        $html = $this->renderHtml($allocation, $issuedAt, $order, $billingName, $billingAddress, $lines, $season);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        $dir = $this->uploadsDir . '/invoices/' . $allocation['seasonLabel'];
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException('Stockage indisponible.');
        }
        $storedName = 'facture-' . $allocation['number'] . '-' . bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents($dir . '/' . $storedName, $dompdf->output());

        return 'invoices/' . $allocation['seasonLabel'] . '/' . $storedName;
    }

    private function logoDataUri(): string
    {
        if ($this->logoDataUri === null) {
            $this->logoDataUri = is_file($this->logoPath)
                ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($this->logoPath))
                : '';
        }
        return $this->logoDataUri;
    }

    private function renderHtml(
        array $allocation,
        \DateTimeImmutable $issuedAt,
        array $order,
        string $billingName,
        array $billingAddress,
        array $lines,
        Season $season,
    ): string {
        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $money = fn (float $v) => number_format($v, 2, ',', ' ') . ' €';

        $rowsHtml = '';
        foreach ($lines as $line) {
            $blurbHtml = $line['blurb'] !== '' ? '<div class="blurb">' . $e($line['blurb']) . '</div>' : '';
            $rowsHtml .= '<tr>'
                . '<td>' . $e($line['description']) . $blurbHtml . '</td>'
                . '<td class="num">' . (int) $line['quantity'] . '</td>'
                . '<td class="num">pièce</td>'
                . '<td class="num">' . $money((float) $line['unitPrice']) . '</td>'
                . '<td class="num">' . $e((string) $line['reduc']) . '</td>'
                . '<td class="num">' . $money((float) $line['amount']) . '</td>'
                . '</tr>';
        }

        $address = trim((string) $billingAddress['address']);
        $postalCity = trim((string) ($billingAddress['postalcode'] ?? '') . ' ' . (string) ($billingAddress['city'] ?? ''));

        $club = $this->club;
        $bank = $club['bank'] ?? [];
        $logo = $this->logoDataUri();
        $logoImg = $logo !== '' ? '<img class="logo" src="' . $logo . '" alt="">' : '';
        $seasonText = 'Du 1er septembre ' . $season->startYear . ' au 31 août ' . ($season->startYear + 1);

        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><style>
    body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; color: #111; margin: 1.8cm; }
    .header { width: 100%; margin-bottom: 0.8cm; }
    .col-left { float: left; width: 48%; }
    .col-right { float: right; width: 48%; }
    .logo { width: 2.6cm; height: auto; margin-bottom: 0.3cm; }
    .facture-heading { font-size: 13pt; font-weight: bold; margin: 0.9cm 0 0.15cm; }
    table.facture-meta { width: 100%; border-collapse: collapse; margin-bottom: 0.9cm; }
    table.facture-meta th, table.facture-meta td { text-align: left; font-weight: normal; padding: 0.12cm 0; border-bottom: 1px solid #ddd; }
    table.facture-meta th { color: #333; }
    table.facture-meta td { text-align: right; }
    .club-info { font-size: 9pt; color: #333; margin-bottom: 0.5cm; }
    .billing { font-weight: bold; }
    table.lines { width: 100%; border-collapse: collapse; margin-bottom: 0.4cm; clear: both; }
    table.lines th { background: #eee; text-align: left; padding: 0.2cm 0.3cm; font-size: 8.5pt; }
    table.lines td { padding: 0.25cm 0.3cm; border-bottom: 1px solid #ddd; vertical-align: top; }
    table.lines td.num { text-align: right; white-space: nowrap; }
    table.lines th.num { text-align: right; }
    .blurb { font-size: 8pt; color: #666; font-style: italic; margin-top: 0.1cm; }
    table.total { width: 100%; }
    table.total td { text-align: right; padding: 0.15cm 0.3cm; }
    table.total .label { font-weight: bold; }
    .season { margin-top: 0.4cm; }
    .footer { margin-top: 1.5cm; padding-top: 0.3cm; border-top: 1px solid #999; font-size: 8pt; color: #555; }
</style></head>
<body>
    <div class="header">
        <div class="col-left">
            {$logoImg}
            <div class="facture-heading">Facture</div>
            <table class="facture-meta">
                <tr><th>Numéro de facture</th><td>{$e($allocation['number'])}</td></tr>
                <tr><th>Date de facture</th><td>{$issuedAt->format('d/m/Y')}</td></tr>
                <tr><th>Date d'échéance</th><td><strong>Acquittée</strong></td></tr>
            </table>
        </div>
        <div class="col-right">
            <div class="club-info">
                <strong>{$e((string) $club['name'])}</strong><br>
                {$e((string) $club['address'])}<br>
                {$e((string) $club['postal_city'])}
            </div>
            <div class="billing">
                {$e($billingName)}<br>
                {$e($address)}<br>
                {$e($postalCity)}
            </div>
        </div>
    </div>

    <table class="lines">
        <thead><tr>
            <th>Description</th><th class="num">Quantité</th><th class="num">Unité</th>
            <th class="num">Prix</th><th class="num">Réduc.</th><th class="num">Montant</th>
        </tr></thead>
        <tbody>{$rowsHtml}</tbody>
    </table>

    <table class="total">
        <tr><td class="label">Montant Total EUR</td><td>{$money((float) $order['amount'])}</td></tr>
    </table>

    <div class="season">{$e($seasonText)}</div>

    <div class="footer">
        TVA non applicable, art. 293 B du CGI.<br>
        {$e((string) $club['name'])} — {$e((string) $club['address'])}, {$e((string) $club['postal_city'])}<br>
        SIRET : {$e((string) $club['siret'])} —
        Email : {$e((string) $club['email'])} —
        Site : {$e((string) $club['website'])}<br>
        Banque : {$e((string) ($bank['name'] ?? ''))} — Code banque : {$e((string) ($bank['code_banque'] ?? ''))} —
        N° de compte : {$e((string) ($bank['account_number'] ?? ''))}<br>
        BIC : {$e((string) ($bank['bic'] ?? ''))} — IBAN : {$e((string) ($bank['iban'] ?? ''))}
    </div>
</body>
</html>
HTML;
    }
}
