<?php

declare(strict_types=1);

namespace PrimeBuilder\Jobs;

use Prime\Jobs\JobHandlerInterface;
use Prime\Mail\Mailer;
use Prime\Plugins\PluginContext;
use Throwable;

final class FormEmailJob implements JobHandlerInterface
{
    public function __construct(
        private readonly PluginContext $context,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        $to = trim((string) ($payload['to'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $template = (string) ($payload['template'] ?? 'prime-builder::mail/form-admin');
        $subject = (string) ($payload['subject'] ?? 'Prime Builder');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        try {
            $mailer = $this->context->app()->get(Mailer::class);
            if (method_exists($mailer, 'sendTemplate')) {
                $mailer->sendTemplate($template, $to, $subject, $data);
            }
        } catch (Throwable) {
            // Do not crash worker on mail failure
        }
    }
}
