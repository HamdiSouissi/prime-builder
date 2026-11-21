<?php

declare(strict_types=1);

namespace PrimeBuilder\Dynamic;

final class DisplayConditions
{
    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $context
     */
    public static function shouldRender(array $settings, array $context): bool
    {
        $authRule = (string) ($settings['condition_auth'] ?? 'any');
        $isLoggedIn = (bool) ($context['is_logged_in'] ?? false);

        if ($authRule === 'logged_in' && !$isLoggedIn) {
            return false;
        }
        if ($authRule === 'logged_out' && $isLoggedIn) {
            return false;
        }

        $roleRule = trim((string) ($settings['condition_role'] ?? ''));
        if ($roleRule !== '') {
            $userRoles = is_array($context['user_roles'] ?? null) ? $context['user_roles'] : [];
            if (!in_array($roleRule, $userRoles, true)) {
                return false;
            }
        }

        $now = (string) ($context['now'] ?? date('Y-m-d H:i:s'));
        $start = trim((string) ($settings['condition_date_start'] ?? ''));
        if ($start !== '' && $now < $start) {
            return false;
        }
        $end = trim((string) ($settings['condition_date_end'] ?? ''));
        if ($end !== '' && $now > $end) {
            return false;
        }

        return true;
    }
}
