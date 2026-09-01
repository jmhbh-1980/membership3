<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SettingsRepository;
use League\CommonMark\CommonMarkConverter;

/**
 * Admin-editable "règlement intérieur" (club bylaws), stored as Markdown in
 * the generic settings table and rendered to HTML for the acceptance
 * checkbox shown on every paid flow (join/renewal/lessons add-on/tickets).
 */
final class ReglementInterieurService
{
    private const string SETTING_KEY = 'reglement_interieur';

    private readonly CommonMarkConverter $converter;

    public function __construct(private readonly SettingsRepository $settings)
    {
        // Raw HTML in the admin's Markdown is escaped, not rendered — an admin
        // account compromise shouldn't become stored XSS reaching every member.
        $this->converter = new CommonMarkConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    public function markdown(): string
    {
        return $this->settings->get(self::SETTING_KEY) ?? '';
    }

    public function save(string $markdown): void
    {
        $this->settings->set(self::SETTING_KEY, $markdown);
    }

    public function html(): string
    {
        $markdown = $this->markdown();
        return $markdown === '' ? '' : (string) $this->converter->convert($markdown);
    }
}
