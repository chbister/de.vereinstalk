#!/usr/bin/env php
<?php
/*
 * Backport des Upstream-Fixes für unaufgelöste `=>`-Referenzen im englischen
 * Katalog (flarum/framework Branch 2.x, noch NICHT in v2.0.0-rc.8 getaggt).
 *
 * Problem: Symfony lädt den `en`-Katalog als Fallback anderer Locales intern
 * voraus (roh, ohne Flarums `=>`-Auflösung). Flarums Parse-Gate
 * (`! isset($this->catalogues[$locale])`) hielt ihn danach fälschlich für
 * bereits geparst — das kompilierte `forum-en.js` enthielt dadurch rohe
 * `=> core.ref.*`-Keys. Deutsch/Französisch waren nie betroffen (Packs liefern
 * Literale ohne Referenzen).
 *
 * Fix: Parse-Status pro Katalog-OBJEKT (WeakMap) statt pro Locale-Namen
 * verfolgen — exakt der Upstream-Ansatz. Siehe:
 * https://github.com/flarum/framework/blob/2.x/framework/core/src/Locale/Translator.php
 *
 * ENTFERNEN, sobald flarum/core in einer Version mit dem Fix per Composer
 * kommt (dann schlägt dieses Skript bewusst fehl, falls die alte
 * Code-Stelle nicht mehr gefunden wird).
 */

$file = '/app/vendor/flarum/core/src/Locale/Translator.php';
$src = file_get_contents($file);

if (str_contains($src, 'parsedCatalogues')) {
    echo "Fix bereits vorhanden, nichts zu tun.\n";
    exit(0);
}

$oldProp = <<<'PHP'
    public const REFERENCE_REGEX = '/^=>\s*([a-z0-9_\-\.]+)$/i';
PHP;

$newProp = <<<'PHP'
    public const REFERENCE_REGEX = '/^=>\s*([a-z0-9_\-\.]+)$/i';

    /**
     * Catalogue objects whose `=> reference` values have already been resolved.
     *
     * Tracked per object rather than per locale: Symfony stores an original
     * catalogue per locale but attaches fresh copies as fallbacks of other
     * locales, so several objects can share one locale name.
     *
     * @var \WeakMap<MessageCatalogueInterface, bool>
     */
    private ?\WeakMap $parsedCatalogues = null;
PHP;

$oldMethod = <<<'PHP'
        $parse = ! isset($this->catalogues[$locale]);

        $catalogue = parent::getCatalogue($locale);

        if ($parse) {
            $this->parseCatalogue($catalogue);

            $fallbackCatalogue = $catalogue;
            while ($fallbackCatalogue = $fallbackCatalogue->getFallbackCatalogue()) {
                $this->parseCatalogue($fallbackCatalogue);
            }
        }

        return $catalogue;
PHP;

$newMethod = <<<'PHP'
        $catalogue = parent::getCatalogue($locale);

        $this->parsedCatalogues ??= new \WeakMap();

        for ($current = $catalogue; $current !== null; $current = $current->getFallbackCatalogue()) {
            if (! isset($this->parsedCatalogues[$current])) {
                $this->parseCatalogue($current);
                $this->parsedCatalogues[$current] = true;
            }
        }

        return $catalogue;
PHP;

foreach (['property anchor' => $oldProp, 'getCatalogue method' => $oldMethod] as $label => $needle) {
    if (! str_contains($src, $needle)) {
        fwrite(STDERR, "FEHLER: Erwartete Code-Stelle ($label) in Translator.php nicht gefunden. "
            . "Vermutlich enthält flarum/core den Upstream-Fix bereits oder hat die Datei umgebaut — "
            . "Patch-Skript prüfen/entfernen.\n");
        exit(1);
    }
}

$src = str_replace($oldProp, $newProp, $src);
$src = str_replace($oldMethod, $newMethod, $src);
file_put_contents($file, $src);
echo "Translator-Fix angewendet.\n";
