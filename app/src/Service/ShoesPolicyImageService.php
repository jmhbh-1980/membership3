<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SettingsRepository;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Admin-uploaded image (the club's "shoe rules" flyer) shown, with its own
 * required acceptance checkbox, on every paid flow. Unlike UploadService's
 * documents, this must be publicly viewable (including by an anonymous
 * prospect mid-wizard) — stored inside the webroot (settings['paths']
 * ['public_uploads'], under members/assets/) and served directly by the
 * webserver, no PHP route involved. Only the current filename is tracked,
 * in the generic settings table.
 */
final class ShoesPolicyImageService
{
    private const string SETTING_KEY = 'shoes_policy_image';
    private const int MAX_SIZE_BYTES = 8 * 1024 * 1024;
    private const array ALLOWED_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const string URL_PREFIX = '/assets/uploads/';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly string $publicUploadsDir,
    ) {
    }

    public function url(): ?string
    {
        $filename = $this->settings->get(self::SETTING_KEY);
        return $filename !== null && $filename !== '' ? self::URL_PREFIX . $filename : null;
    }

    /** Throws RuntimeException with a French, user-displayable message on validation failure. */
    public function save(UploadedFileInterface $file): void
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Le transfert du fichier a échoué, merci de réessayer.');
        }
        if ($file->getSize() === null || $file->getSize() > self::MAX_SIZE_BYTES) {
            throw new RuntimeException('Fichier trop volumineux (8 Mo maximum).');
        }

        $tmpPath = $file->getStream()->getMetadata('uri');
        $mime = is_string($tmpPath) ? (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath) : false;
        if ($mime === false || !isset(self::ALLOWED_MIMES[$mime])) {
            throw new RuntimeException('Format non accepté. Formats autorisés : ' . implode(', ', array_values(self::ALLOWED_MIMES)) . '.');
        }

        if (!is_dir($this->publicUploadsDir) && !mkdir($this->publicUploadsDir, 0775, true)) {
            throw new RuntimeException('Stockage indisponible, merci de réessayer.');
        }

        // Random name (cache-busting on replacement, not a security boundary —
        // this directory is public by design) rather than a fixed one.
        $newFilename = 'shoes-policy-' . bin2hex(random_bytes(8)) . '.' . self::ALLOWED_MIMES[$mime];
        $file->moveTo($this->publicUploadsDir . '/' . $newFilename);

        $previousFilename = $this->settings->get(self::SETTING_KEY);
        $this->settings->set(self::SETTING_KEY, $newFilename);
        if ($previousFilename !== null && $previousFilename !== '') {
            @unlink($this->publicUploadsDir . '/' . $previousFilename);
        }
    }

    public function delete(): void
    {
        $filename = $this->settings->get(self::SETTING_KEY);
        if ($filename === null || $filename === '') {
            return;
        }
        @unlink($this->publicUploadsDir . '/' . $filename);
        $this->settings->set(self::SETTING_KEY, '');
    }
}
