<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\WhatsApp;

/**
 * What a new member is told once their join is paid: the welcome email, and
 * the same last step on the payment confirmation page, worded in one place.
 *
 * A join leaves the member's Balle Jaune account a Visiteur until the club
 * has met them and checked their indoor shoes (RoleResolver, the shoes
 * board). Since November 2026 that first welcome is booked over WhatsApp
 * instead of happening on their first visit, on a number of its own — not the
 * club phone printed on invoices — hence settings' club.whatsapp.
 */
final class JoinWelcome
{
    public function __construct(private readonly string $whatsappNumber)
    {
    }

    /** Null when no number is configured: the last step then asks to contact the club without a link. */
    public function whatsappUrl(): ?string
    {
        return WhatsApp::link($this->whatsappNumber);
    }

    /**
     * The last step, as HTML for an email or a page — everything in it is
     * escaped here. The number follows the link in brackets, for whoever
     * can't open it (no WhatsApp on that computer, a mail client that
     * strips links) and would rather add it to their phone.
     */
    public function lastStepHtml(): string
    {
        $url = $this->whatsappUrl();
        $via = $url === null ? '' : ' via <a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($url, ENT_QUOTES) . '</a>'
            . ' (' . $this->displayNumber() . ')';
        return '<strong>Dernière étape :</strong> Merci de contacter le Club par WhatsApp' . $via
            . ' afin de convenir d\'un RDV de 1er accueil et de vérifier vos chaussures.'
            . ' Votre compte de réservation sera activé après ce RDV.';
    }

    /**
     * The number the way a French reader dials it, "07 56 90 02 35", with
     * non-breaking spaces so a line never ends in the middle of it. Any other
     * country's number is shown as configured.
     */
    private function displayNumber(): string
    {
        $digits = (string) preg_replace('/\D/', '', $this->whatsappNumber);
        if (strlen($digits) === 11 && str_starts_with($digits, '33')) {
            $digits = '0' . substr($digits, 2);
        }
        return strlen($digits) === 10 && $digits[0] === '0'
            ? implode('&nbsp;', str_split($digits, 2))
            : htmlspecialchars(trim($this->whatsappNumber), ENT_QUOTES);
    }

    /**
     * The welcome email's body. The last step sits in a box: a one-cell table
     * with inline styles, the one shape that keeps its border and background
     * in every mail client, Outlook included. The club green and its light
     * tint are the site's own (--accent, .alert-ok) — no stylesheet reaches
     * an email.
     *
     * @param ?int $tickets credits bought with a ticket join, null for a season membership
     */
    public function emailHtml(float $amount, ?int $tickets, bool $invoiceAttached): string
    {
        return '<p>Bonjour,</p>'
            . '<p>Votre paiement de ' . number_format($amount, 2, ',', ' ') . ' € a bien été reçu : bienvenue au club !</p>'
            . ($invoiceAttached ? '<p>Vous trouverez votre facture en pièce jointe.</p>' : '')
            . ($tickets !== null
                ? '<p>Vos ' . $tickets . ' crédits de jeu sont déjà sur votre compte, sans limite de durée. '
                    . 'Vous pourrez en racheter à tout moment depuis votre espace adhérent.</p>'
                : '')
            . '<p>Vos identifiants de réservation Balle Jaune vous parviennent par email séparé.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;margin:20px 0;">'
            . '<tr><td style="border:2px solid #0d6e4f;background-color:#e8f5ee;border-radius:8px;padding:14px 16px;font-size:15px;line-height:1.5;">'
            . $this->lastStepHtml()
            . '</td></tr></table>';
    }
}
