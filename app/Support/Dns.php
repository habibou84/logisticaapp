<?php

namespace App\Support;

/**
 * Lecture des enregistrements DNS publics d'un nom (remplaçable dans les tests).
 */
class Dns
{
    /**
     * @return array{cname: list<string>, a: list<string>}
     */
    public function records(string $host): array
    {
        $records = @dns_get_record($host, DNS_CNAME | DNS_A) ?: [];

        return [
            'cname' => array_values(array_map(fn ($r) => strtolower(rtrim($r['target'], '.')), array_filter($records, fn ($r) => ($r['type'] ?? '') === 'CNAME'))),
            'a' => array_values(array_map(fn ($r) => $r['ip'], array_filter($records, fn ($r) => ($r['type'] ?? '') === 'A'))),
        ];
    }
}
