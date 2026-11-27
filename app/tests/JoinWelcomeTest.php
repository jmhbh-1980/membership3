<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\JoinWelcome;
use PHPUnit\Framework\TestCase;

/**
 * The welcome email a new member gets once their join is paid, and the last
 * step it shares with the payment confirmation page.
 */
final class JoinWelcomeTest extends TestCase
{
    public function testTheLastStepAsksToBookTheFirstWelcomeOverWhatsApp(): void
    {
        $html = (new JoinWelcome('+33756900235'))->lastStepHtml();

        self::assertSame(
            '<strong>Dernière étape :</strong> Merci de contacter le Club par WhatsApp via '
            . '<a href="https://wa.me/33756900235">https://wa.me/33756900235</a> (07&nbsp;56&nbsp;90&nbsp;02&nbsp;35)'
            . ' afin de convenir d\'un RDV de 1er accueil et de vérifier vos chaussures.'
            . ' Votre compte de réservation sera activé après ce RDV.',
            $html,
        );
        // A number typed the French way leads to the same chat, and reads the same.
        $local = new JoinWelcome('07 56 90 02 35');
        self::assertSame('https://wa.me/33756900235', $local->whatsappUrl());
        self::assertStringContainsString('(07&nbsp;56&nbsp;90&nbsp;02&nbsp;35)', $local->lastStepHtml());
        // A foreign number is shown as configured.
        self::assertStringContainsString('(+32 470 12 34 56)', (new JoinWelcome('+32 470 12 34 56'))->lastStepHtml());
    }

    public function testWithNoNumberConfiguredTheStepStillReadsWithoutALink(): void
    {
        $html = (new JoinWelcome(''))->lastStepHtml();

        self::assertStringNotContainsString('<a ', $html);
        self::assertStringContainsString('Merci de contacter le Club par WhatsApp afin de convenir', $html);
    }

    public function testTheEmailSaysTheInvoiceIsAttachedOnlyWhenItIs(): void
    {
        $welcome = new JoinWelcome('+33756900235');

        self::assertStringContainsString('Votre paiement de 324,17 € a bien été reçu', $welcome->emailHtml(324.17, null, true));
        self::assertStringContainsString('Vous trouverez votre facture en pièce jointe.', $welcome->emailHtml(324.17, null, true));
        self::assertStringNotContainsString('pièce jointe', $welcome->emailHtml(324.17, null, false));
    }

    public function testTicketCreditsAreMentionedForATicketJoinOnly(): void
    {
        $welcome = new JoinWelcome('+33756900235');

        self::assertStringContainsString('Vos 5 crédits de jeu sont déjà sur votre compte', $welcome->emailHtml(50.0, 5, true));
        self::assertStringNotContainsString('crédits de jeu', $welcome->emailHtml(324.17, null, true));
    }

    public function testTheLastStepClosesTheEmailInABox(): void
    {
        $welcome = new JoinWelcome('+33756900235');
        $html = $welcome->emailHtml(324.17, null, true);

        self::assertStringEndsWith('<td style="border:2px solid #0d6e4f;background-color:#e8f5ee;border-radius:8px;padding:14px 16px;font-size:15px;line-height:1.5;">'
            . $welcome->lastStepHtml() . '</td></tr></table>', $html);
        self::assertSame(1, substr_count($html, 'Dernière étape'));
    }
}
