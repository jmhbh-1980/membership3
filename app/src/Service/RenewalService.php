<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Db;
use DateTimeImmutable;

/**
 * Renewal domain logic: which season a member renews for, and which
 * subscription they currently hold. BJ's own subscription_id is authoritative
 * for subscription_type: every catalogue tier (heures-pleines, heures-creuses,
 * midi, jeune) maps to its own distinct BJ subscription name, so it resolves
 * unambiguously via resolveSubscriptionFromBjName() — either an exact match
 * on the simplified catalogue, or a substring guess for members still on a
 * pre-migration legacy name. member_formulas is only consulted when BJ's name
 * resolves to nothing (never transacted through the app), which keeps a
 * manual BJ correction picked up immediately rather than shadowed by a stale
 * cache. competitor (and lessons) are the one exception in the other
 * direction: BJ has no structured field for either — only a free-text note —
 * so member_formulas stays the sole source for those, and can't itself go
 * stale relative to BJ since there's nothing in BJ to compare against. Couple
 * status and partner linkage are handled the same live-first way: BJ's
 * custom2/custom3 fields are authoritative (see resolveCoupleStatus()), with
 * member_formulas and the BJ-name guess only as a fallback until BJ has been
 * written.
 *
 * Season *coverage* (renewalTarget()/subscriptionCovers()) is a separate
 * concern from formula *detail* above, and is BJ-`subscription_date_end`-only
 * — Balle Jaune is the club's source of truth for membership state, so
 * nothing local can ever override it back to "not yet renewed". A stray or
 * stale member_formulas row (a mistake, a support fix, a manual BJ edit) must
 * never be able to make the app claim a season is covered that BJ doesn't
 * actually show as covered.
 */
class RenewalService
{
    public function __construct(
        private readonly Db $db,
        private readonly PricingService $pricing,
    ) {
    }

    /**
     * Decides which season (if any) a member can renew into right now, and
     * whether the late-settlement flat fee applies.
     *
     * - subscription lapsed (or never set) → renew into the current season
     *   (always possible, it's the season actively running), prorated
     *   against today like a mid-season join (see PricingService::quote()'s
     *   joinDate). On or after 1 July the prorated amount would cover only
     *   the season's last month or two, so a flat late-settlement fee (the
     *   "Pack été" forfeit) applies instead — independent of whether next
     *   season's price list is published yet, that's a separate concern
     *   (below).
     * - still covered today, next season published and not yet reached →
     *   the normal forward renewal, now gated by the price list actually
     *   existing rather than the calendar month.
     * - still covered today, next season not published yet → nothing to
     *   do (state 'not_yet_open' — distinct from 'done' so the member can
     *   be told why, rather than left to assume they're set for good).
     *   `season` is the *current* season here, so the caller can name the
     *   unpublished one via `season->next()`.
     * - covered all the way into next season → nothing to do ('done');
     *   `season` is the *next* season, the furthest one the member is
     *   actually covered through.
     *
     * Never offers two seasons at once, with one carve-out: a member behind
     * on the current season, in the late-settlement window, gets a real
     * choice between the flat late fee and jumping straight to next season
     * instead — but only once next season is actually published (surfaced as
     * `choice_available`; the caller decides what to do with it). Behind on
     * both otherwise simply gets the current one, never a choice.
     *
     * Pure BJ-date math — no DB read, so nothing local can ever make this
     * report a season as covered that BJ's own subscription_date_end doesn't
     * actually reach (see the class docblock).
     *
     * @return array{state:'open'|'done'|'not_yet_open', season:Season, late_settlement:bool, next_published:bool, choice_available:bool}
     */
    public function renewalTarget(DateTimeImmutable $today, string $subscriptionDateEnd): array
    {
        $current = Season::fromDate($today);
        $next = $current->next();
        $nextPublished = $this->pricing->hasCatalogue($next);

        if (!$this->subscriptionCovers($subscriptionDateEnd, $today)) {
            $lateSettlement = (int) $today->format('n') >= 7;
            return [
                'state' => 'open', 'season' => $current, 'late_settlement' => $lateSettlement,
                'next_published' => $nextPublished, 'choice_available' => $lateSettlement && $nextPublished,
            ];
        }

        if ($nextPublished && !$this->subscriptionCovers($subscriptionDateEnd, $next->end())) {
            return ['state' => 'open', 'season' => $next, 'late_settlement' => false, 'next_published' => $nextPublished, 'choice_available' => false];
        }

        if (!$nextPublished) {
            return ['state' => 'not_yet_open', 'season' => $current, 'late_settlement' => false, 'next_published' => $nextPublished, 'choice_available' => false];
        }

        return ['state' => 'done', 'season' => $next, 'late_settlement' => false, 'next_published' => $nextPublished, 'choice_available' => false];
    }

    /**
     * True when BJ's raw subscription_date_end is still in the future
     * relative to $asOf — no derived "grace period" marker is compared
     * against here, only the real BJ date, exactly as club staff would read
     * it. Used two ways: is the member covered *right now* ($asOf = today),
     * and separately, has their coverage already reached all the way
     * through *next* season ($asOf = next season's actual 31 August end) —
     * both are plain date comparisons against BJ's own value, never a
     * computed end-of-season-plus-grace date.
     */
    public function subscriptionCovers(string $subscriptionDateEnd, DateTimeImmutable $asOf): bool
    {
        return $subscriptionDateEnd !== ''
            && $subscriptionDateEnd !== '0000-00-00'
            && $subscriptionDateEnd >= $asOf->format('Y-m-d');
    }

    /**
     * Seasons this member is covered for, admin-facing: the union of the
     * app's own renewal history (member_formulas) and whatever the raw BJ
     * subscription_date_end reaches on its own. The two normally agree
     * (fulfillJoin()/fulfillRenewal() write both from the same season), but
     * BJ can be edited by hand (support, testing) without touching
     * member_formulas — `mismatch` flags exactly that: the app thinks the
     * member is covered through a season BJ's own date doesn't reach.
     *
     * @return array{seasons: string[], mismatch: bool}
     */
    public function validSeasonLabels(int $bjUserId, string $subscriptionDateEnd): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT season_start_year FROM member_formulas WHERE bj_user_id = ? ORDER BY season_start_year'
        );
        $stmt->execute([$bjUserId]);
        $localYears = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $bjYear = null;
        if ($subscriptionDateEnd !== '' && $subscriptionDateEnd !== '0000-00-00') {
            [$y, $md] = [(int) substr($subscriptionDateEnd, 0, 4), substr($subscriptionDateEnd, 5, 5)];
            // graceEnd(startYear) = "(startYear+1)-09-15" — the largest startYear whose
            // grace end the date reaches, without walking Season instances one by one.
            $bjYear = ($md < '09-15' ? $y - 1 : $y) - 1;
        }

        $years = $bjYear !== null && !in_array($bjYear, $localYears, true)
            ? [...$localYears, $bjYear]
            : $localYears;
        sort($years);

        return [
            'seasons'  => array_map(static fn (int $y) => (new Season($y))->label(), $years),
            'mismatch' => $localYears !== [] && ($bjYear === null || $bjYear < max($localYears)),
        ];
    }

    /** @return ?array{subscription_type: string, is_couple: int, competitor: int, lessons: int, partner_bj_user_id: int} */
    public function knownFormula(int $bjUserId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT subscription_type, is_couple, competitor, lessons, partner_bj_user_id FROM member_formulas
             WHERE bj_user_id = ? ORDER BY season_start_year DESC LIMIT 1'
        );
        $stmt->execute([$bjUserId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * The member's current formula, BJ-first: $fromBj (BJ's live subscription_id,
     * resolved via resolveSubscriptionFromBjName()) wins whenever it resolves to
     * something — a manual BJ correction is picked up on the member's very next
     * page load, with no local cache to go stale. $known (member_formulas) is
     * only consulted when BJ's name doesn't resolve to anything (never
     * transacted through the app). competitor status is the one exception in
     * the other direction: BJ has no structured field for it (only a free-text
     * note), so $known stays authoritative for it regardless of which side
     * supplied subscriptionType.
     *
     * @return ?array{subscriptionType: string, isCompetitor: bool}
     */
    public function resolveCurrentFormula(?array $known, ?array $fromBj): ?array
    {
        if ($fromBj !== null) {
            return ['subscriptionType' => $fromBj['subscriptionType'], 'isCompetitor' => $known !== null ? (bool) $known['competitor'] : $fromBj['isCompetitor']];
        }
        if ($known !== null) {
            return ['subscriptionType' => $known['subscription_type'], 'isCompetitor' => (bool) $known['competitor']];
        }
        return null;
    }

    /**
     * BJ's custom2 ("1"/"") + custom3 (partner bj_user_id) are the couple-status
     * source of truth. Falls back to the local member_formulas row, then the
     * legacy-name guess, only when BJ hasn't been written yet (custom2 blank) —
     * so a member who transacted before this migration, or whose only signal
     * is their legacy "Couple" subscription name, isn't silently dropped from
     * couple status until their next renewal re-asserts it in BJ.
     *
     * @return array{isCouple: bool, partnerBjUserId: int}
     */
    public function resolveCoupleStatus(array $bjUser, ?array $known, ?array $legacyGuess): array
    {
        if (($bjUser['custom2'] ?? '') === '1') {
            return ['isCouple' => true, 'partnerBjUserId' => (int) ($bjUser['custom3'] ?? 0)];
        }
        if ($known !== null) {
            return ['isCouple' => (bool) $known['is_couple'], 'partnerBjUserId' => (int) $known['partner_bj_user_id']];
        }
        return ['isCouple' => (bool) ($legacyGuess['isCouple'] ?? false), 'partnerBjUserId' => 0];
    }

    public function recordFormula(
        int $seasonStartYear,
        int $bjUserId,
        string $subscriptionType,
        bool $isCouple,
        bool $competitor,
        int $lessons,
        int $partnerBjUserId,
        ?int $orderId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'REPLACE INTO member_formulas (season_start_year, bj_user_id, subscription_type, is_couple, competitor, lessons, partner_bj_user_id, order_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$seasonStartYear, $bjUserId, $subscriptionType, (int) $isCouple, (int) $competitor, $lessons, $partnerBjUserId, $orderId]);
    }

    /**
     * Whether another order already recorded a formula for this member+season —
     * used by FulfillmentService to stop a second order from double-fulfilling
     * the same renewal (e.g. a delayed SumUp webhook settling an abandoned
     * duplicate order after a fresh retry already went through). Deliberately
     * local-only: BJ's own date is never consulted here, unlike
     * subscriptionCovers() above — a manual BJ correction must never be able
     * to make this wrongly skip a genuine renewal, only the local order ledger
     * decides "already fulfilled" in this direction.
     */
    public function hasFulfilledFormula(int $seasonStartYear, int $bjUserId, int $excludeOrderId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM member_formulas WHERE season_start_year = ? AND bj_user_id = ? AND order_id IS NOT NULL AND order_id != ? LIMIT 1'
        );
        $stmt->execute([$seasonStartYear, $bjUserId, $excludeOrderId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Bumps this season's recorded lesson count to at least 1 after a
     * standalone lessons add-on purchase (LessonSignupController), so the
     * member's next renewal still prefills the lessons checkbox. No-op if no
     * row exists yet — shouldn't happen, since the add-on is only offered to
     * members who already renewed (and so already have a row here).
     */
    public function markLessonsTaken(int $seasonStartYear, int $bjUserId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE member_formulas SET lessons = GREATEST(lessons, 1) WHERE season_start_year = ? AND bj_user_id = ?'
        );
        $stmt->execute([$seasonStartYear, $bjUserId]);
    }

    // ── Annual renewal health questionnaire (minors) ─────────────────────

    public function saveAttestation(int $seasonStartYear, int $bjUserId, array $fields): void
    {
        $stmt = $this->db->pdo()->prepare(
            'REPLACE INTO renewal_attestations
                (season_start_year, bj_user_id, outcome, guardian_fullname, signature_ip, signed_at, document_stored_name, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $seasonStartYear,
            $bjUserId,
            $fields['outcome'],
            $fields['guardian_fullname'] ?? '',
            $fields['signature_ip'] ?? '',
            $fields['signed_at'] ?? null,
            $fields['document_stored_name'] ?? '',
        ]);
    }

    public function attestationFor(int $seasonStartYear, int $bjUserId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM renewal_attestations WHERE season_start_year = ? AND bj_user_id = ?'
        );
        $stmt->execute([$seasonStartYear, $bjUserId]);
        return $stmt->fetch() ?: null;
    }

    // ── Annual student-discount certificate ──────────────────────────────

    /** Resets to 'pending' on a fresh upload (REPLACE INTO) — a re-upload after a refusal means a fresh review. */
    public function saveStudentCertificateRequest(int $seasonStartYear, int $bjUserId, array $fields): void
    {
        $stmt = $this->db->pdo()->prepare(
            'REPLACE INTO renewal_student_certificates
                (season_start_year, bj_user_id, status, original_name, stored_name, mime, size, requested_at)
             VALUES (?, ?, "pending", ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $seasonStartYear,
            $bjUserId,
            $fields['originalName'],
            $fields['storedName'],
            $fields['mime'],
            $fields['size'],
        ]);
    }

    public function studentCertificateFor(int $seasonStartYear, int $bjUserId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM renewal_student_certificates WHERE season_start_year = ? AND bj_user_id = ?'
        );
        $stmt->execute([$seasonStartYear, $bjUserId]);
        return $stmt->fetch() ?: null;
    }

    public function decideStudentCertificate(int $seasonStartYear, int $bjUserId, string $status, string $decidedBy, string $refusalReason = ''): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE renewal_student_certificates SET status = ?, decided_at = NOW(), decided_by = ?, refusal_reason = ?
             WHERE season_start_year = ? AND bj_user_id = ?'
        );
        $stmt->execute([$status, $decidedBy, $refusalReason, $seasonStartYear, $bjUserId]);
    }

    // ── Change requests ──────────────────────────────────────────────────

    public function createChangeRequest(
        int $bjUserId,
        string $email,
        string $memberName,
        string $currentLabel,
        string $subscriptionType,
        bool $isCouple,
        bool $competitor,
        int $lessons,
        string $partnerEmail,
        int $seasonStartYear,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO change_requests (bj_user_id, email, member_name, current_label, subscription_type, is_couple, competitor, lessons, partner_email, season_start_year, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$bjUserId, $email, $memberName, $currentLabel, $subscriptionType, (int) $isCouple, (int) $competitor, $lessons, $partnerEmail, $seasonStartYear]);
    }

    /**
     * A member requesting to waive their mandatory licence — same admin-approval
     * queue as createChangeRequest(), distinguished by kind='licence'.
     * subscription_type/is_couple/competitor/lessons carry the member's current,
     * unchanged formula (not a real change) so the generic approved-request
     * seeding in RenewalController::context() works the same for both kinds.
     */
    public function createLicenceWaiverRequest(
        int $bjUserId,
        string $email,
        string $memberName,
        string $currentLabel,
        string $subscriptionType,
        bool $isCouple,
        bool $competitor,
        int $lessons,
        string $partnerEmail,
        int $seasonStartYear,
        bool $licenceRemoved,
        string $licenceRemovalReason,
        bool $partnerLicenceRemoved,
        string $partnerLicenceRemovalReason,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO change_requests (
                kind, bj_user_id, email, member_name, current_label, subscription_type, is_couple, competitor, lessons,
                partner_email, season_start_year, licence_removed, licence_removal_reason,
                partner_licence_removed, partner_licence_removal_reason, created_at
             ) VALUES ("licence", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $bjUserId, $email, $memberName, $currentLabel, $subscriptionType, (int) $isCouple, (int) $competitor, $lessons,
            $partnerEmail, $seasonStartYear, (int) $licenceRemoved, mb_substr($licenceRemovalReason, 0, 500),
            (int) $partnerLicenceRemoved, mb_substr($partnerLicenceRemovalReason, 0, 500),
        ]);
    }

    public function pendingChangeRequest(int $bjUserId, int $seasonStartYear): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM change_requests WHERE bj_user_id = ? AND season_start_year = ? AND status IN ("pending","approved")
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$bjUserId, $seasonStartYear]);
        return $stmt->fetch() ?: null;
    }

    /** @return array[] */
    public function changeRequestsByStatus(string $status): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM change_requests WHERE status = ? ORDER BY created_at');
        $stmt->execute([$status]);
        return $stmt->fetchAll();
    }

    public function findChangeRequest(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM change_requests WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function decideChangeRequest(int $id, bool $approved, string $note): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE change_requests SET status = ?, admin_note = ?, decided_at = NOW() WHERE id = ? AND status IN ("pending","approved")'
        );
        $stmt->execute([$approved ? 'approved' : 'refused', mb_substr($note, 0, 500), $id]);
    }

    public function completeChangeRequest(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE change_requests SET status = "completed" WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Resolves a member's current formula from their raw BJ subscription
     * name — used when member_formulas has no row for them yet (never
     * transacted through the app, or a _-named subscription set some other
     * way, e.g. directly in BJ). Two tiers:
     * - an exact catalogue match for the app's own simplified "_"-prefixed
     *   names (e.g. "_Abonnement Individuel Jeune") — not a guess, since
     *   these names map 1:1 to a subscription key via bj_subscription;
     * - otherwise a best-effort substring guess of a legacy (pre-migration)
     *   verbose name (e.g. "Abonnement Garennois - Individuel Compétiteur
     *   Midi (réinscription)"). Returns null when neither applies (ticket
     *   formulas, staff types, unrecognized names). Residence is never
     *   derived here — it comes separately from postal code. Couple/
     *   competitor status can't be read off a "_"-name at all (BJ's
     *   custom2/custom3 are authoritative for couple — see
     *   resolveCoupleStatus() — and competitor status doesn't gate renewal
     *   approval), so an exact match defaults both to false.
     *
     * @return ?array{subscriptionType: string, isCouple: bool, isCompetitor: bool}
     */
    public function resolveSubscriptionFromBjName(string $subscriptionName, Season $season): ?array
    {
        $exactKey = $this->pricing->subscriptionKeyForBjName($subscriptionName, $season);
        if ($exactKey !== null) {
            return ['subscriptionType' => $exactKey, 'isCouple' => false, 'isCompetitor' => false];
        }

        $name = mb_strtolower($subscriptionName);
        if ($name === '' || str_contains($name, 'ticket') || str_starts_with($name, '_')) {
            return null;
        }
        $couple = str_contains($name, 'couple');
        $competitor = str_contains($name, 'comp'); // compétiteur, with BJ typos
        $midi = str_contains($name, 'midi');
        $creuses = str_contains($name, 'creuses');
        $jeune = str_contains($name, 'jeune') && !str_contains($name, 'école');

        return match (true) {
            $jeune                             => ['subscriptionType' => 'jeune', 'isCouple' => false, 'isCompetitor' => false],
            $couple && $creuses                => ['subscriptionType' => 'heures-creuses', 'isCouple' => true, 'isCompetitor' => $competitor],
            $couple                            => ['subscriptionType' => 'heures-pleines', 'isCouple' => true, 'isCompetitor' => $competitor],
            $midi                              => ['subscriptionType' => 'midi', 'isCouple' => false, 'isCompetitor' => $competitor],
            $creuses                           => ['subscriptionType' => 'heures-creuses', 'isCouple' => false, 'isCompetitor' => $competitor],
            $competitor                        => ['subscriptionType' => 'heures-pleines', 'isCouple' => false, 'isCompetitor' => true],
            str_contains($name, 'abonnement')  => ['subscriptionType' => 'heures-pleines', 'isCouple' => false, 'isCompetitor' => false],
            default                            => null,
        };
    }
}
