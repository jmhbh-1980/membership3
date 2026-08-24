<?php

declare(strict_types=1);

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * Generates the signed "attestation de réponse négative" PDF for a minor:
 * the legal guardian certifies the QS-Sport health questionnaire was
 * completed with only negative answers. Embeds the drawn signature plus an
 * audit trail (timestamp, IP, guardian email).
 */
class AttestationPdfService
{
    public function __construct(private readonly string $uploadsDir)
    {
    }

    /**
     * @param string $storageDir subdirectory under uploads/ (e.g. 'applications/42' or 'renewals/2026/1405147')
     * @param string $signatureDataUrl "data:image/png;base64,..." from the signature canvas
     * @return string the stored PDF file name (inside uploads/{$storageDir}/)
     */
    public function generate(
        string $storageDir,
        array $minor,
        string $guardianFullname,
        string $guardianEmail,
        string $place,
        string $signatureDataUrl,
        string $ip,
    ): string {
        $signaturePng = $this->decodeSignature($signatureDataUrl);
        $signedAt = new \DateTimeImmutable();

        $html = $this->renderHtml($minor, $guardianFullname, $guardianEmail, $place, $signaturePng, $signedAt, $ip);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        $dir = $this->uploadsDir . '/' . $storageDir;
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException('Stockage indisponible.');
        }
        $storedName = 'attestation-p1-' . bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents($dir . '/' . $storedName, $dompdf->output());

        return $storedName;
    }

    private function decodeSignature(string $dataUrl): string
    {
        if (!str_starts_with($dataUrl, 'data:image/png;base64,')) {
            throw new RuntimeException('Signature manquante ou invalide.');
        }
        $binary = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);
        if ($binary === false || (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary) !== 'image/png') {
            throw new RuntimeException('Signature invalide.');
        }

        // Reject a blank canvas: require a meaningful number of drawn
        // (non-transparent) pixels in the decoded image.
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            throw new RuntimeException('Signature invalide.');
        }
        $drawn = 0;
        $width = imagesx($image);
        $height = imagesy($image);
        for ($y = 0; $y < $height; $y += 2) {
            for ($x = 0; $x < $width; $x += 2) {
                if ((imagecolorat($image, $x, $y) >> 24) < 120) {
                    $drawn++;
                }
            }
        }
        if ($drawn < 50) {
            throw new RuntimeException('Merci de signer dans le cadre prévu.');
        }
        return $binary;
    }

    private function renderHtml(
        array $minor,
        string $guardianFullname,
        string $guardianEmail,
        string $place,
        string $signaturePng,
        \DateTimeImmutable $signedAt,
        string $ip,
    ): string {
        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $minorName = $e(trim($minor['firstname'] . ' ' . $minor['lastname']));
        $birthdate = $e(date('d/m/Y', strtotime($minor['birthdate'])));
        $signatureSrc = 'data:image/png;base64,' . base64_encode($signaturePng);

        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><style>
    body { font-family: "DejaVu Sans", sans-serif; font-size: 11pt; color: #111; margin: 2.2cm; }
    h1 { font-size: 13pt; text-align: center; text-transform: uppercase; margin-bottom: 0.2cm; }
    h2 { font-size: 10pt; text-align: center; font-weight: normal; font-style: italic; margin-top: 0; }
    .box { border: 1px solid #333; padding: 0.6cm; margin-top: 0.8cm; }
    .sig { margin-top: 0.6cm; }
    .sig img { height: 3cm; }
    .audit { margin-top: 1.2cm; font-size: 8pt; color: #555; border-top: 1px solid #999; padding-top: 0.2cm; }
</style></head>
<body>
    <h1>Attestation de réponse négative<br>au questionnaire de santé du sportif mineur</h1>
    <h2>QS-SPORT — Annexe II-23, Art. A. 231-3 du code du sport<br>Bad &amp; Squash La Garenne-Colombes — saison sportive en cours</h2>

    <div class="box">
        <p>Je soussigné(e), <strong>{$e($guardianFullname)}</strong>, en ma qualité de représentant légal
        de <strong>{$minorName}</strong>, né(e) le <strong>{$birthdate}</strong>,</p>

        <p>atteste qu'il/elle a renseigné le questionnaire relatif à l'état de santé du sportif mineur
        et a répondu <strong>par la négative à l'ensemble des rubriques</strong>.</p>

        <p>Afin de respecter le secret médical, le questionnaire de santé renseigné n'est pas remis à
        l'association.</p>

        <p>Fait à {$e($place)}, le {$signedAt->format('d/m/Y')}</p>

        <div class="sig">
            <p>Signature du représentant légal :</p>
            <img src="{$signatureSrc}" alt="signature">
        </div>
    </div>

    <div class="audit">
        Document signé électroniquement via l'espace adhésion du club Bad &amp; Squash La Garenne-Colombes.<br>
        Signataire : {$e($guardianFullname)} &lt;{$e($guardianEmail)}&gt; —
        Horodatage : {$signedAt->format('d/m/Y H:i:s')} (Europe/Paris) — Adresse IP : {$e($ip)}
    </div>
</body>
</html>
HTML;
    }
}
