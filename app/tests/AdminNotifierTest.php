<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\AdminNotifier;
use App\Service\Mailer;
use App\Support\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The "[Action requise]" emails the club gets when something waits on an
 * admin: one address, a filterable subject, an absolute link to the page that
 * owns the decision, and never a crash in the member's flow.
 */
final class AdminNotifierTest extends TestCase
{
    private const string TO = 'squash+members@bad-squash.org';
    private const string URL = 'https://members.bad-squash.org';

    /** @return array{0: AdminNotifier, 1: \ArrayObject<int, array>} the notifier, and every email it sent */
    private function notifier(string $to = self::TO, ?\Throwable $failure = null): array
    {
        $sent = new \ArrayObject();
        $mailer = $this->createMock(Mailer::class);
        $mailer->method('send')->willReturnCallback(function (string $to, string $subject, string $body, string $template) use ($sent, $failure): bool {
            if ($failure !== null) {
                throw $failure;
            }
            $sent[] = compact('to', 'subject', 'body', 'template');
            return true;
        });
        return [new AdminNotifier($mailer, new Logger(sys_get_temp_dir() . '/admin_notifier_test.log'), $to, self::URL), $sent];
    }

    public function testAnApplicationLinksStraightToItsPage(): void
    {
        [$notifier, $sent] = $this->notifier();

        $notifier->applicationSubmitted(42, 'DUPONT Jean', 'Heures Pleines, en couple');

        self::assertCount(1, $sent);
        self::assertSame(self::TO, $sent[0]['to']);
        self::assertSame('[Action requise] Demande d\'adhésion à valider — DUPONT Jean', $sent[0]['subject']);
        self::assertSame('admin_application_submitted', $sent[0]['template']);
        self::assertStringContainsString('DUPONT Jean vient d&#039;envoyer sa demande d&#039;adhésion (Heures Pleines, en couple).', $sent[0]['body']);
        self::assertStringContainsString('<a href="https://members.bad-squash.org/admin/demandes/42">Examiner la demande</a>', $sent[0]['body']);
        self::assertStringContainsString('href="https://members.bad-squash.org/admin/decisions"', $sent[0]['body']);
    }

    public function testEveryCaseSaysWhatWaitsAndWhere(): void
    {
        [$notifier, $sent] = $this->notifier();
        $order = ['id' => 7, 'kind' => 'renewal', 'amount' => 324.17, 'checkout_reference' => 'ref-7', 'bj_user_id' => 5];

        $notifier->changeRequested('DUPONT Jean', false, 'Midi → Heures Pleines');
        $notifier->changeRequested('DUPONT Jean', true);
        $notifier->approvalRequested('promo', $order, 'DUPONT Jean');
        $notifier->approvalRequested('student', $order, 'DUPONT Jean');
        $notifier->bankTransferClaimed($order);
        $notifier->fulfillmentFailed($order, 'BJ injoignable.');
        $notifier->duplicatePayment($order);
        $notifier->installmentFailed(5, 'DUPONT Jean', 108.06);

        $links = array_map(static fn (array $m): string => (preg_match('~<strong><a href="([^"]+)"~', $m['body'], $l) ? $l[1] : ''), (array) $sent);
        self::assertSame([
            self::URL . '/admin/changements',
            self::URL . '/admin/changements',
            self::URL . '/admin/codes-promo/approbations',
            self::URL . '/admin/reduction-etudiant',
            self::URL . '/admin/virements',
            self::URL . '/admin/commandes/7',
            self::URL . '/admin/commandes/7',
            self::URL . '/admin/membres/5',
        ], $links);
        foreach ($sent as $mail) {
            self::assertStringStartsWith('[Action requise] ', $mail['subject']);
        }
        self::assertStringContainsString('Midi → Heures Pleines', $sent[0]['body']);
        self::assertStringContainsString('324,17 €', $sent[2]['body']);
        self::assertStringContainsString('BJ injoignable.', $sent[5]['body']);
        self::assertStringContainsString('108,06 €', $sent[7]['body']);
    }

    public function testWhatAMemberTypedIsEscaped(): void
    {
        [$notifier, $sent] = $this->notifier();

        $notifier->applicationSubmitted(1, '<script>x</script>', 'Midi');

        self::assertStringNotContainsString('<script>', $sent[0]['body']);
        self::assertStringContainsString('&lt;script&gt;', $sent[0]['body']);
    }

    public function testNothingIsSentWithoutAnAddress(): void
    {
        [$notifier, $sent] = $this->notifier('');

        $notifier->applicationSubmitted(1, 'DUPONT Jean', 'Midi');

        self::assertCount(0, $sent);
    }

    public function testAFailedEmailNeverBreaksTheMembersFlow(): void
    {
        [$notifier] = $this->notifier(self::TO, new RuntimeException('SMTP indisponible'));

        $notifier->applicationSubmitted(1, 'DUPONT Jean', 'Midi');

        $this->addToAssertionCount(1); // reaching here is the point: the exception was swallowed (and logged)
    }
}
