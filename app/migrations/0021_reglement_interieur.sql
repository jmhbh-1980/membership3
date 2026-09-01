-- Seeds the "règlement intérieur" (club bylaws) shown, with a required
-- acceptance checkbox, on every paid flow (join/renewal/lessons add-on/
-- tickets). Stored in the existing generic settings table, editable
-- afterwards in Markdown at /admin/reglages/reglement-interieur. INSERT
-- IGNORE: a one-time seed, never overwrites an admin edit on re-run.
INSERT IGNORE INTO settings (name, value, updated_at) VALUES (
    'reglement_interieur',
    '## Chaussures

1. Utiliser des chaussures de squash (ou hand, bad, volley)
2. Semelles non marquantes
3. Semelles propres
4. Chaussures utilisées exclusivement sur le court (interdiction de venir déjà chaussé pour jouer)

Tout manquement à ces règles entraînera un avertissement et, le cas échéant, une exclusion.',
    NOW()
);
