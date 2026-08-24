<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ApplicationRepository;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Validates and stores prospect document uploads under
 * uploads/applications/{applicationId}/ — always outside the webroot.
 * MIME type is determined server-side (finfo), never trusted from the client.
 */
class UploadService
{
    private const int MAX_SIZE_BYTES = 8 * 1024 * 1024;

    private const array ALLOWED_MIMES = [
        'photo'               => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
        'justificatif'        => ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'],
        'medical_certificate' => ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'],
    ];

    public function __construct(
        private readonly string $uploadsDir,
        private readonly ApplicationRepository $applications,
    ) {
    }

    /**
     * Stores one uploaded document; replaces any previous document of the
     * same kind for the same person. Throws RuntimeException with a
     * French, user-displayable message on validation failure.
     */
    public function store(UploadedFileInterface $file, int $applicationId, int $position, string $kind): void
    {
        if (!isset(self::ALLOWED_MIMES[$kind])) {
            throw new RuntimeException('Type de document inconnu.');
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Le transfert du fichier a échoué, merci de réessayer.');
        }
        if ($file->getSize() === null || $file->getSize() > self::MAX_SIZE_BYTES) {
            throw new RuntimeException('Fichier trop volumineux (8 Mo maximum).');
        }

        $tmpPath = $file->getStream()->getMetadata('uri');
        $mime = is_string($tmpPath) ? (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath) : false;
        if ($mime === false || !isset(self::ALLOWED_MIMES[$kind][$mime])) {
            throw new RuntimeException('Format non accepté. Formats autorisés : ' . implode(', ', array_values(self::ALLOWED_MIMES[$kind])) . '.');
        }

        $dir = $this->dirFor($applicationId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException('Stockage indisponible, merci de réessayer.');
        }

        $storedName = $kind . '-p' . $position . '-' . bin2hex(random_bytes(8)) . '.' . self::ALLOWED_MIMES[$kind][$mime];
        $file->moveTo($dir . '/' . $storedName);

        $this->applications->addDocument(
            $applicationId,
            $position,
            $kind,
            $file->getClientFilename() ?? '',
            $storedName,
            $mime,
            (int) $file->getSize(),
        );
    }

    public function dirFor(int $applicationId): string
    {
        return $this->uploadsDir . '/applications/' . $applicationId;
    }

    /** Removes an application's upload directory (photos, justificatifs, certificates, signed attestation PDFs). */
    public function deleteAll(int $applicationId): void
    {
        $dir = $this->dirFor($applicationId);
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                unlink($dir . '/' . $file);
            }
        }
        rmdir($dir);
    }

    /**
     * Stores a renewal's annual medical certificate upload. Unlike store(),
     * there's no application_id to persist against — the caller saves the
     * returned metadata itself (into renewal_attestations).
     *
     * @return array{originalName:string,storedName:string,mime:string,size:int}
     */
    public function storeRenewalCertificate(UploadedFileInterface $file, int $seasonStartYear, int $bjUserId): array
    {
        $kind = 'medical_certificate';
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Le transfert du fichier a échoué, merci de réessayer.');
        }
        if ($file->getSize() === null || $file->getSize() > self::MAX_SIZE_BYTES) {
            throw new RuntimeException('Fichier trop volumineux (8 Mo maximum).');
        }

        $tmpPath = $file->getStream()->getMetadata('uri');
        $mime = is_string($tmpPath) ? (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath) : false;
        if ($mime === false || !isset(self::ALLOWED_MIMES[$kind][$mime])) {
            throw new RuntimeException('Format non accepté. Formats autorisés : ' . implode(', ', array_values(self::ALLOWED_MIMES[$kind])) . '.');
        }

        $dir = $this->uploadsDir . '/renewals/' . $seasonStartYear . '/' . $bjUserId;
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException('Stockage indisponible, merci de réessayer.');
        }

        $storedName = $kind . '-' . bin2hex(random_bytes(8)) . '.' . self::ALLOWED_MIMES[$kind][$mime];
        $file->moveTo($dir . '/' . $storedName);

        return [
            'originalName' => $file->getClientFilename() ?? '',
            'storedName'   => $storedName,
            'mime'         => $mime,
            'size'         => (int) $file->getSize(),
        ];
    }
}
