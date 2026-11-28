<?php

declare(strict_types=1);

namespace PrimeBuilder\Forms;

use Prime\Jobs\Job;
use Prime\Jobs\JobQueue;
use Prime\Jobs\UnrecoverableJobException;
use Prime\Mail\Mailer;
use Prime\Plugins\PluginContext;
use Prime\Site\UrlGenerator;
use PrimeBuilder\Configuration;
use RuntimeException;
use Throwable;

/** Private, separately retryable admin and receipt messages backed by the CMS queue. */
final class Notifications
{
    public const JOB_NAME = 'prime-builder.form-notification';
    private const ADMIN_TEMPLATE = 'prime-builder::mail/admin';
    private const RECEIPT_TEMPLATE = 'prime-builder::mail/receipt';

    public function __construct(private readonly PluginContext $plugin)
    {
    }

    /**
     * Called from Submissions::create() within its transaction. The queue uses
     * the CMS's same Connection; no recipient or message goes into its payload.
     *
     * @param array{admin: bool, receipt: bool} $settings
     */
    public function enqueue(int $submissionId, array $settings, string $admin, string $email): void
    {
        $recipients = [];
        if ($settings['admin']) {
            $recipients['admin'] = $admin;
        }
        if ($settings['receipt'] && $email !== '') {
            $recipients['receipt'] = $email;
        }
        if ($recipients === []) {
            return;
        }
        $queue = $this->plugin->app()->get(JobQueue::class);
        foreach ($recipients as $kind => $recipient) {
            $id = $this->plugin->db()->table(Submissions::NOTIFICATION_TABLE)->insertGetId([
                'submission_id' => $submissionId, 'kind' => $kind, 'recipient' => $recipient, 'job_id' => null,
            ]);
            $jobId = $queue->dispatch(self::JOB_NAME, ['notification_id' => $id]);
            $this->plugin->db()->table(Submissions::NOTIFICATION_TABLE)->where('id', '=', $id)->update(['job_id' => $jobId]);
        }
    }

    /** @param array<string, mixed> $payload */
    public function handle(array $payload, Job $job): void
    {
        $id = $payload['notification_id'] ?? null;
        if (!is_int($id) || $id < 1 || count($payload) !== 1 || $job->handler !== self::JOB_NAME) {
            throw new UnrecoverableJobException('Invalid form notification.');
        }
        $db = $this->plugin->db();
        try {
            $candidate = $db->table(Submissions::NOTIFICATION_TABLE)->find($id);
            if ($candidate === null) {
                return; // Deleted, expired, or already delivered after a worker crash.
            }
            $submissionId = (int) ($candidate['submission_id'] ?? 0);
            $db->transaction(function () use ($db, $id, $job, $submissionId): void {
                // Deletion and retention take the same lock in the same order.
                // Holding it through transport means completed deletion cannot race
                // a previously reserved job, at the cost of a long-running lock.
                (new Submissions($db))->lock($submissionId);
                $submission = $db->table(Submissions::TABLE)->find($submissionId);
                $notification = $db->table(Submissions::NOTIFICATION_TABLE)->find($id);
                if ($submission === null || $notification === null || (int) ($notification['submission_id'] ?? 0) !== $submissionId
                    || (int) ($notification['job_id'] ?? 0) !== $job->id) {
                    return;
                }
                $days = Submissions::retentionDays(Configuration::get($this->plugin->settings(), 'submission_retention_days', Submissions::RETENTION_DAYS));
                $created = $submission['created_at'] ?? null;
                if (!is_string($created) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $created) !== 1
                    || $created < gmdate('Y-m-d H:i:s', time() - $days * 86400)) {
                    $db->table(Submissions::NOTIFICATION_TABLE)->where('id', '=', $id)->delete();
                    return;
                }
                $kind = $notification['kind'] ?? null;
                $recipient = NotificationSettings::address($notification['recipient'] ?? null);
                if (!in_array($kind, ['admin', 'receipt'], true) || $recipient === null || $recipient === '') {
                    throw new RuntimeException('Invalid notification record.');
                }
                $template = $kind === 'admin' ? self::ADMIN_TEMPLATE : self::RECEIPT_TEMPLATE;
                // Mailer::render silently falls back to a subject-only message if
                // a view is missing, and hasTemplate prepends the core namespace.
                if (!$this->plugin->views()->exists($template)) {
                    throw new RuntimeException('Notification template unavailable.');
                }
                $subject = $kind === 'admin' ? t('plugin::prime-builder.mail.admin_subject') : t('plugin::prime-builder.mail.receipt_subject');
                // Do not duplicate the submission or attachments in mail bodies:
                // the inbox remains the access-controlled source of the details.
                $data = $kind === 'admin' ? [
                    'inbox_url' => $this->plugin->app()->get(UrlGenerator::class)->absolute('/admin/prime-builder/submissions'),
                ] : [];
                $mailer = $this->plugin->app()->get(Mailer::class);
                $message = $mailer->render($template, $recipient, $subject, $data);
                // This job is already queued; sendNow prevents another queue or
                // synchronous fallback on the public request. Omitting the template
                // in the CMS mail log prevents storing render data or offering resend.
                $mailer->sendNow($message);
                $db->table(Submissions::NOTIFICATION_TABLE)->where('id', '=', $id)->delete();
            });
        } catch (Throwable) {
            // Queue errors can persist indefinitely; do not copy transport or
            // database exception text (which could contain a recipient or body).
            throw new RuntimeException('Form notification delivery failed.');
        }
    }
}
