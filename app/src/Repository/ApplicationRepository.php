<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Db;
use PDO;

/**
 * Persistence for prospect applications, their people (1-2), documents and
 * minor-health attestations. Status machine:
 * draft → submitted → validated → awaiting_payment → paid → fulfilled | rejected.
 */
class ApplicationRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function create(string $email, int $seasonStartYear, bool $summerPack = false): array
    {
        $token = bin2hex(random_bytes(32));
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO applications (token, status, email, season_start_year, summer_pack, created_at, updated_at)
             VALUES (?, "draft", ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([$token, $email, $seasonStartYear, (int) $summerPack]);

        return $this->findByToken($token);
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM applications WHERE token = ?');
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM applications WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function update(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $sets = implode(', ', array_map(fn (string $k) => "`$k` = ?", array_keys($fields)));
        $stmt = $this->db->pdo()->prepare("UPDATE applications SET {$sets}, updated_at = NOW() WHERE id = ?");
        $stmt->execute([...array_values($fields), $id]);
    }

    public function setStatus(int $id, string $status, array $extra = []): void
    {
        $this->update($id, ['status' => $status] + $extra);
    }

    /** Deletes the application; application_people/documents/attestations cascade (ON DELETE CASCADE). */
    public function delete(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM applications WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** Insert or replace the person at a given position (1 or 2). */
    public function savePerson(int $applicationId, int $position, array $person): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('DELETE FROM application_people WHERE application_id = ? AND position = ?');
        $stmt->execute([$applicationId, $position]);

        $stmt = $pdo->prepare(
            'INSERT INTO application_people
                (application_id, position, firstname, lastname, sex, birthdate, email, phone,
                 address, postalcode, city, is_minor, guardian_fullname, guardian_email, guardian_phone,
                 competitor, licence_removed, licence_removal_reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $applicationId,
            $position,
            $person['firstname'],
            $person['lastname'],
            $person['sex'] ?? '',
            $person['birthdate'],
            $person['email'] ?? '',
            $person['phone'] ?? '',
            $person['address'] ?? '',
            $person['postalcode'] ?? '',
            $person['city'] ?? '',
            (int) ($person['is_minor'] ?? 0),
            $person['guardian_fullname'] ?? '',
            $person['guardian_email'] ?? '',
            $person['guardian_phone'] ?? '',
            (int) ($person['competitor'] ?? 0),
            (int) ($person['licence_removed'] ?? 0),
            $person['licence_removal_reason'] ?? '',
        ]);
    }

    public function setPersonBjUserId(int $applicationId, int $position, int $bjUserId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE application_people SET bj_user_id = ? WHERE application_id = ? AND position = ?'
        );
        $stmt->execute([$bjUserId, $applicationId, $position]);
    }

    /** Updates one person's licence-removal choice without touching the rest of their record. */
    public function updatePersonLicence(int $applicationId, int $position, bool $removed, string $reason): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE application_people SET licence_removed = ?, licence_removal_reason = ? WHERE application_id = ? AND position = ?'
        );
        $stmt->execute([(int) $removed, mb_substr($reason, 0, 500), $applicationId, $position]);
    }

    public function removePartner(int $applicationId): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM application_people WHERE application_id = ? AND position = 2');
        $stmt->execute([$applicationId]);
    }

    /** @return array<int, array> people keyed by position */
    public function people(int $applicationId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM application_people WHERE application_id = ? ORDER BY position');
        $stmt->execute([$applicationId]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['position']] = $row;
        }
        return $result;
    }

    public function addDocument(int $applicationId, int $position, string $kind, string $originalName, string $storedName, string $mime, int $size): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'DELETE FROM documents WHERE application_id = ? AND person_position = ? AND kind = ?'
        );
        $stmt->execute([$applicationId, $position, $kind]);

        $stmt = $pdo->prepare(
            'INSERT INTO documents (application_id, person_position, kind, original_name, stored_name, mime, size, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$applicationId, $position, $kind, $originalName, $storedName, $mime, $size]);
    }

    /** @return array<string, array> documents indexed by "{position}:{kind}" */
    public function documents(int $applicationId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM documents WHERE application_id = ?');
        $stmt->execute([$applicationId]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['person_position'] . ':' . $row['kind']] = $row;
        }
        return $result;
    }

    public function saveAttestation(int $applicationId, int $position, array $fields): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('DELETE FROM attestations WHERE application_id = ? AND person_position = ?');
        $stmt->execute([$applicationId, $position]);

        $stmt = $pdo->prepare(
            'INSERT INTO attestations
                (application_id, person_position, outcome, guardian_fullname, signature_ip, signed_at, pdf_stored_name, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $applicationId,
            $position,
            $fields['outcome'],
            $fields['guardian_fullname'] ?? '',
            $fields['signature_ip'] ?? '',
            $fields['signed_at'] ?? null,
            $fields['pdf_stored_name'] ?? '',
        ]);
    }

    /** @return array<int, array> attestations keyed by person position */
    public function attestations(int $applicationId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM attestations WHERE application_id = ?');
        $stmt->execute([$applicationId]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['person_position']] = $row;
        }
        return $result;
    }

    /** @return array[] applications in a given status, oldest first */
    public function byStatus(string $status): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM applications WHERE status = ? ORDER BY submitted_at, created_at');
        $stmt->execute([$status]);
        return $stmt->fetchAll();
    }

    /** @return array[] applications still in draft (abandoned signups), most recent activity first */
    public function abandonedDrafts(): array
    {
        $stmt = $this->db->pdo()->query('SELECT * FROM applications WHERE status = "draft" ORDER BY updated_at DESC');
        return $stmt->fetchAll();
    }
}
