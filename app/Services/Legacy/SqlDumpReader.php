<?php

namespace App\Services\Legacy;

use Generator;
use RuntimeException;

/**
 * Lit les lignes d'un export SQL de MySQL/MariaDB (phpMyAdmin, mysqldump), sans
 * base MySQL : instructions « INSERT INTO `table` (`col`, …) VALUES (…), (…); ».
 * Gère les chaînes échappées (\' '' \n…), NULL, nombres et valeurs hexadécimales,
 * les fichiers compressés (.gz) et les INSERT sans liste de colonnes (ordre du CREATE TABLE).
 */
class SqlDumpReader
{
    /** @var array<string, list<string>> colonnes de chaque table, d'après CREATE TABLE */
    private array $columns = [];

    public function __construct(private readonly string $sql)
    {
        if (preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*?)\n\s*\)[^;]*;/is', $sql, $tables, PREG_SET_ORDER)) {
            foreach ($tables as [, $table, $body]) {
                preg_match_all('/^\s*`(\w+)`\s/m', $body, $cols);
                $this->columns[strtolower($table)] = $cols[1];
            }
        }
    }

    public static function fromFile(string $path): self
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Fichier introuvable ou illisible : {$path}");
        }

        $content = file_get_contents($path);
        if (str_starts_with($content, "\x1f\x8b")) {
            $content = gzdecode($content);
            if ($content === false) {
                throw new RuntimeException('Fichier .gz illisible.');
            }
        }

        return new self($content);
    }

    public function hasTable(string $table): bool
    {
        return isset($this->columns[strtolower($table)])
            || preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?'.preg_quote($table, '/').'`?[\s(]/i', $this->sql) === 1;
    }

    /**
     * Lignes d'une table : colonne => valeur (chaîne, ou null).
     *
     * @return Generator<int, array<string, string|null>>
     */
    public function rows(string $table): Generator
    {
        $pattern = '/INSERT\s+(?:IGNORE\s+)?INTO\s+`?'.preg_quote($table, '/').'`?\s*(?:\(([^)]*)\))?\s*VALUES\s*/i';
        $offset = 0;

        while (preg_match($pattern, $this->sql, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $columns = isset($match[1]) && $match[1][1] !== -1 && trim($match[1][0]) !== ''
                ? array_map(fn ($c) => trim($c, " `\t\r\n"), explode(',', $match[1][0]))
                : ($this->columns[strtolower($table)] ?? null);

            if ($columns === null) {
                throw new RuntimeException("Colonnes de la table « {$table} » inconnues : exportez aussi sa structure.");
            }

            $position = $match[0][1] + strlen($match[0][0]);

            foreach ($this->tuples($position) as $values) {
                if (count($values) !== count($columns)) {
                    throw new RuntimeException("Ligne de « {$table} » mal formée : ".count($values).' valeurs pour '.count($columns).' colonnes.');
                }

                yield array_combine($columns, $values);
            }

            $offset = $position;
        }
    }

    /**
     * Parcourt « (…), (…); » à partir de $position (avancée jusqu'après le « ; »).
     *
     * @return Generator<int, list<string|null>>
     */
    private function tuples(int &$position): Generator
    {
        $sql = $this->sql;
        $length = strlen($sql);

        while ($position < $length) {
            $this->skipSpaces($position);
            if (($sql[$position] ?? '') !== '(') {
                throw new RuntimeException('Export SQL illisible près de : '.substr($sql, $position, 40));
            }
            $position++;

            $values = [];
            while (true) {
                $this->skipSpaces($position);
                $values[] = ($sql[$position] ?? '') === "'" ? $this->string($position) : $this->token($position);
                $this->skipSpaces($position);

                $char = $sql[$position++] ?? '';
                if ($char === ')') {
                    break;
                }
                if ($char !== ',') {
                    throw new RuntimeException('Export SQL illisible près de : '.substr($sql, $position - 1, 40));
                }
            }

            yield $values;

            $this->skipSpaces($position);
            $char = $sql[$position++] ?? ';';
            if ($char === ';') {
                return;
            }
            if ($char !== ',') {
                throw new RuntimeException('Export SQL illisible près de : '.substr($sql, $position - 1, 40));
            }
        }
    }

    private function string(int &$position): string
    {
        $sql = $this->sql;
        $out = '';
        $position++; // guillemet ouvrant

        while (true) {
            $next = strcspn($sql, "\\'", $position);
            $out .= substr($sql, $position, $next);
            $position += $next;
            $char = $sql[$position] ?? null;

            if ($char === null) {
                throw new RuntimeException('Chaîne non terminée dans l\'export SQL.');
            }

            if ($char === '\\') {
                $escaped = $sql[$position + 1] ?? '';
                $out .= match ($escaped) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '0' => "\0",
                    'Z' => "\x1A",
                    'b' => "\x08",
                    default => $escaped,
                };
                $position += 2;

                continue;
            }

            // « '' » : guillemet doublé à l'intérieur de la chaîne
            if (($sql[$position + 1] ?? '') === "'") {
                $out .= "'";
                $position += 2;

                continue;
            }

            $position++;

            return $out;
        }
    }

    private function token(int &$position): ?string
    {
        $length = strcspn($this->sql, ',)', $position);
        $token = trim(substr($this->sql, $position, $length));
        $position += $length;

        if (strcasecmp($token, 'NULL') === 0) {
            return null;
        }

        if (preg_match('/^0x([0-9a-f]*)$/i', $token, $hex)) {
            return (string) hex2bin($hex[1]);
        }

        return $token;
    }

    private function skipSpaces(int &$position): void
    {
        $position += strspn($this->sql, " \t\r\n", $position);
    }
}
