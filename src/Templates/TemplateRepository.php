<?php

declare(strict_types=1);

namespace PrimeBuilder\Templates;

use Prime\Database\Connection;

final class TemplateRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(?string $type = null): array
    {
        $query = $this->db->table('prime_builder_templates')->orderBy('priority', 'ASC')->orderBy('id', 'DESC');
        if ($type !== null && $type !== '') {
            $query = $query->where('type', '=', $type);
        }

        return $query->get();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->table('prime_builder_templates')->where('id', '=', $id)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) $this->db->table('prime_builder_templates')->insertGetId([
            'title' => (string) ($data['title'] ?? 'Sans titre'),
            'type' => (string) ($data['type'] ?? 'section'),
            'status' => (string) ($data['status'] ?? 'published'),
            'document_json' => (string) ($data['document_json'] ?? '{"version":1,"elements":[]}'),
            'conditions_json' => isset($data['conditions_json']) ? (string) $data['conditions_json'] : null,
            'triggers_json' => isset($data['triggers_json']) ? (string) $data['triggers_json'] : null,
            'priority' => (int) ($data['priority'] ?? 10),
            'author_id' => isset($data['author_id']) ? (int) $data['author_id'] : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $payload = ['updated_at' => date('Y-m-d H:i:s')];
        foreach (['title', 'type', 'status', 'document_json', 'conditions_json', 'triggers_json', 'priority'] as $col) {
            if (array_key_exists($col, $data)) {
                $payload[$col] = $data[$col];
            }
        }
        $this->db->table('prime_builder_templates')->where('id', '=', $id)->update($payload);
    }

    public function delete(int $id): void
    {
        $this->db->table('prime_builder_templates')->where('id', '=', $id)->delete();
    }
}
