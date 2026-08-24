<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * Writes a season pricing catalogue array out as a valid, readable
 * `pricing.<season>.php` file — the exact format PricingService already
 * reads via `require`. Writes go through a uniquely-named temp file in the
 * same directory, then an atomic rename() into place, so a reader never
 * sees a half-written file (the app's first runtime-overwrite write path —
 * every other write elsewhere is either a log append or a brand-new
 * uniquely-named file).
 */
final class PricingFileWriter
{
    public function write(string $path, array $catalogue): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Impossible de créer le dossier {$dir}.");
        }

        $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . $this->exportArray($catalogue, 0) . ";\n";

        $tmp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $php, LOCK_EX) === false) {
            throw new RuntimeException("Écriture impossible : {$tmp}");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Impossible de finaliser l'écriture de {$path}.");
        }
    }

    private function exportArray(array $arr, int $indent): string
    {
        if ($arr === []) {
            return '[]';
        }

        $pad = str_repeat('    ', $indent);
        $padIn = str_repeat('    ', $indent + 1);
        $isList = array_is_list($arr);
        $lines = [];

        foreach ($arr as $k => $v) {
            $keyPrefix = $isList ? '' : $this->exportScalar((string) $k) . ' => ';
            $lines[] = $padIn . $keyPrefix . (is_array($v) ? $this->exportArray($v, $indent + 1) : $this->exportScalar($v)) . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n" . $pad . ']';
    }

    private function exportScalar(mixed $v): string
    {
        // var_export() already renders floats exactly as the hand-written
        // files do (219.0 stays "219.0", not "219") — no special-casing needed.
        return is_bool($v) ? ($v ? 'true' : 'false') : var_export($v, true);
    }
}
