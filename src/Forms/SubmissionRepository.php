<?php

declare(strict_types=1);

namespace PrimeBuilder\Forms;

use Prime\Database\Connection;

final class SubmissionRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(?string $formId = null, int $limit = 100): array
    {
        $query = $this->db->table('prime_builder_submissions')->orderBy('id', 'DESC')->limit($limit);
        if ($formId !== null && $formId !== '') {
            $query = $query->where('form_id', '=', $formId);
        }

        return $query->get();
    }

    public function countRecentByIp(string $ipHash, int $seconds = 60): int
    {
        $since = date('Y-m-d H:i:s', time() - $seconds);

        return (int) $this->db->table('prime_builder_submissions')
            ->where('ip_hash', '=', $ipHash)
            ->where('created_at', '>=', $since)
            ->count();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    public function create(string $formId, array $data, array $meta, string $ipHash, ?int $entryId = null, string $formName = ''): int
    {
        return (int) $this->db->table('prime_builder_submissions')->insertGetId([
            'form_id' => $formId,
            'entry_id' => $entryId,
            'form_name' => $formName,
            'data_json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_hash' => $ipHash,
            'status' => 'unread',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function delete(int $id): void
    {
        $this->db->table('prime_builder_submissions')->where('id', '=', $id)->delete();
    }

    public function purgeOlderThanDays(int $days): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));

        return (int) $this->db->table('prime_builder_submissions')
            ->where('created_at', '<', $cutoff)
            ->delete();
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function toCsv(array $rows): string
    {
        $out = fopen('php://temp', 'r+b');
        if ($out === false) {
            return '';
        }
        fputcsv($out, ['ID', 'Formulaire', 'Statut', 'Données', 'Date']);
        foreach ($rows as $row) {
            fputcsv($out, [
                (string) ($row['id'] ?? ''),
                (string) ($row['form_id'] ?? ''),
                (string) ($row['status'] ?? ''),
                (string) ($row['data_json'] ?? '{}'),
                (string) ($row['created_at'] ?? ''),
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return is_string($csv) ? $csv : '';
    }
}
