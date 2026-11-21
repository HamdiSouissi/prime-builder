<?php

declare(strict_types=1);

namespace PrimeBuilder\ThemeBuilder;

final class TemplateResolver
{
    /**
     * @param list<array<string, mixed>> $templates
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public function resolve(string $type, array $templates, array $context): ?array
    {
        $candidates = [];
        foreach ($templates as $tpl) {
            if (($tpl['type'] ?? '') !== $type || ($tpl['status'] ?? '') !== 'published') {
                continue;
            }
            $conditions = json_decode((string) ($tpl['conditions_json'] ?? '[]'), true);
            if (!is_array($conditions)) {
                $conditions = [];
            }
            if ($this->matches($conditions, $context)) {
                $candidates[] = $tpl;
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => ((int) ($a['priority'] ?? 10)) <=> ((int) ($b['priority'] ?? 10)));

        return $candidates[0];
    }

    /**
     * @param array<string, mixed> $conditions
     * @param array<string, mixed> $context
     */
    public function matches(array $conditions, array $context): bool
    {
        $exclude = is_array($conditions['exclude'] ?? null) ? $conditions['exclude'] : [];
        foreach ($exclude as $rule) {
            if (is_array($rule) && $this->matchRule($rule, $context)) {
                return false;
            }
        }

        $include = is_array($conditions['include'] ?? null) ? $conditions['include'] : [];
        if ($include === []) {
            return true;
        }

        foreach ($include as $rule) {
            if (is_array($rule) && $this->matchRule($rule, $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $context
     */
    private function matchRule(array $rule, array $context): bool
    {
        $kind = (string) ($rule['type'] ?? 'all');

        return match ($kind) {
            'all' => true,
            'home' => (bool) ($context['is_home'] ?? false),
            'content_type' => ($context['content_type'] ?? '') === ($rule['value'] ?? ''),
            'entry_id' => (int) ($context['entry_id'] ?? 0) === (int) ($rule['value'] ?? 0),
            default => false,
        };
    }
}
