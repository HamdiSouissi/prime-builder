<?php

declare(strict_types=1);

// Run only against the disposable, installed CMS. The children use independent
// DB connections and the real worker/handler; the transport is deliberately
// blocked to prove the ordering of concurrent deletion and delivery.
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Jobs\JobQueue;
use Prime\Mail\MailConfig;
use Prime\Mail\Mailer;
use Prime\Mail\MailerInterface;
use Prime\Mail\Message;
use Prime\View\View;
use PrimeBuilder\Forms\Notifications;
use PrimeBuilder\Forms\Submissions;

function marker(string $path): void
{
    if (file_put_contents($path, '1') === false) {
        throw new RuntimeException('Unable to write race marker.');
    }
}

function event(string $dir, string $name): void
{
    if (file_put_contents($dir . '/events', $name . "\n", FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Unable to record race event.');
    }
}

function waitMarker(string $path): void
{
    $until = microtime(true) + 20;
    while (!is_file($path)) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Timed out waiting for marker ' . basename($path));
        }
        usleep(10000);
        clearstatcache(true, $path);
    }
}

/** @return resource */
function startChild(string $mode, string $dir, int $submissionId, string $queue)
{
    $command = [PHP_BINARY];
    $ini = php_ini_loaded_file();
    if (is_string($ini)) {
        $command[] = '-c';
        $command[] = $ini;
    }
    array_push($command, __FILE__, $mode, $dir, (string) $submissionId, $queue);
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $dir . '/' . $mode . '.out', 'a'], 2 => ['file', $dir . '/' . $mode . '.err', 'a']], $pipes, getcwd() ?: null);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start race child.');
    }
    fclose($pipes[0]);
    return $process;
}

/** @param resource $process */
function finishChild($process, string $dir, string $mode): void
{
    $exit = proc_close($process);
    if ($exit !== 0) {
        $error = file_get_contents($dir . '/' . $mode . '.err');
        throw new RuntimeException($mode . ' exited ' . $exit . ': ' . (is_string($error) ? $error : ''));
    }
}

$mode = $argv[1] ?? 'main';
$app = require getcwd() . '/bootstrap/app.php';
$db = $app->get(Connection::class);
if ($mode !== 'main') {
    $dir = $argv[2] ?? '';
    $id = (int) ($argv[3] ?? 0);
    $queueName = $argv[4] ?? '';
    if ($id < 1 || !is_dir($dir) || preg_match('/^pb-race-[a-f0-9]{12}$/D', $queueName) !== 1) {
        throw new RuntimeException('Invalid race child arguments.');
    }
    if ($mode === 'worker') {
        $app->get(Hooks::class)->on('db.query', static function (string $sql) use ($dir): void {
            if (str_starts_with($sql, 'SELECT') && str_contains($sql, 'prime_builder_form_notifications')) {
                marker($dir . '/candidate-read');
            }
        });
        $transport = new class ($dir) implements MailerInterface {
            public function __construct(private readonly string $dir)
            {
            }
            public function send(Message $message): void
            {
                marker($this->dir . '/transport-entered');
                waitMarker($this->dir . '/release-worker');
                event($this->dir, 'delivered');
            }
        };
        $app->container()->instance(Mailer::class, new Mailer(new MailConfig(['from_name' => 'Race test']), $transport, view: $app->get(View::class)));
        try {
            $result = $app->get(JobQueue::class)->runNext('race-test', $queueName);
        } catch (Throwable $error) {
            $previous = $error->getPrevious();
            throw new RuntimeException('Worker failed: ' . $error->getMessage() . '; state=' . ($error instanceof \Prime\Database\Exception\QueryException ? $error->sqlState() : '') . '; previous=' . ($previous !== null ? $previous->getMessage() : ''), 0, $error);
        }
        if (($result['status'] ?? null) !== 'completed') {
            throw new RuntimeException('The notification worker did not complete: ' . json_encode($result));
        }
        marker($dir . '/worker-done');
        exit(0);
    }
    if ($mode === 'hold-delete') {
        $db->transaction(static function () use ($db, $dir, $id): void {
            $repository = new Submissions($db);
            $repository->lock($id);
            marker($dir . '/delete-started');
            waitMarker($dir . '/release-delete');
            if (!$repository->delete($id)) {
                throw new RuntimeException('Locked submission was not deleted.');
            }
        });
    } else {
        marker($dir . '/delete-started');
        $repository = new Submissions($db);
        if ($mode === 'delete' && !$repository->delete($id)) {
            throw new RuntimeException('Submission was not deleted.');
        }
        if ($mode === 'purge' && $repository->purge(time() + 8 * 86400, 7) < 1) {
            throw new RuntimeException('Submission was not purged.');
        }
        if ($mode === 'uninstall') {
            $repository->deleteAll();
        }
        if (!in_array($mode, ['delete', 'purge', 'uninstall'], true)) {
            throw new RuntimeException('Invalid race operation.');
        }
    }
    event($dir, 'deleted');
    marker($dir . '/delete-done');
    exit(0);
}

$entryId = (int) (getenv('E2E_ENTRY_ID') ?: 0);
if ($entryId < 1) {
    throw new RuntimeException('E2E_ENTRY_ID must name a disposable CMS entry.');
}
foreach (['delete', 'purge', 'uninstall', 'hold-delete'] as $operation) {
    $dir = sys_get_temp_dir() . '/pb-notification-race-' . bin2hex(random_bytes(8));
    if (!mkdir($dir, 0700)) {
        throw new RuntimeException('Unable to create race directory.');
    }
    $queueName = 'pb-race-' . bin2hex(random_bytes(6));
    $active = [];
    $submissionId = 0;
    try {
        $submissionId = $db->table(Submissions::TABLE)->insertGetId([
            'entry_id' => $entryId, 'node_id' => 'race', 'sender_name' => 'Race', 'sender_email' => 'race@example.test',
            'message' => 'Fixture', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $notificationId = $db->table(Submissions::NOTIFICATION_TABLE)->insertGetId([
            'submission_id' => $submissionId, 'kind' => 'receipt', 'recipient' => 'race@example.test', 'job_id' => null,
        ]);
        $jobId = $app->get(JobQueue::class)->dispatch(Notifications::JOB_NAME, ['notification_id' => $notificationId], $queueName);
        $db->table(Submissions::NOTIFICATION_TABLE)->where('id', '=', $notificationId)->update(['job_id' => $jobId]);

        if ($operation === 'hold-delete') {
            $active['hold-delete'] = startChild('hold-delete', $dir, $submissionId, $queueName);
            waitMarker($dir . '/delete-started');
            $active['worker'] = startChild('worker', $dir, $submissionId, $queueName);
            waitMarker($dir . '/candidate-read');
            // The worker has read the notification but must wait on the row lock.
            usleep(350000);
            clearstatcache();
            if (is_file($dir . '/transport-entered') || is_file($dir . '/worker-done')) {
                throw new RuntimeException('Worker ran while deletion held the submission lock.');
            }
            marker($dir . '/release-delete');
        } else {
            $active['worker'] = startChild('worker', $dir, $submissionId, $queueName);
            waitMarker($dir . '/transport-entered');
            $active[$operation] = startChild($operation, $dir, $submissionId, $queueName);
            waitMarker($dir . '/delete-started');
            usleep(350000);
            clearstatcache();
            if (is_file($dir . '/delete-done')) {
                throw new RuntimeException($operation . ' completed before in-flight delivery.');
            }
            marker($dir . '/release-worker');
        }
        waitMarker($dir . '/delete-done');
        waitMarker($dir . '/worker-done');
        foreach ($active as $childMode => $process) {
            unset($active[$childMode]);
            finishChild($process, $dir, $childMode);
        }
        $events = file($dir . '/events', FILE_IGNORE_NEW_LINES);
        $expected = $operation === 'hold-delete' ? ['deleted'] : ['delivered', 'deleted'];
        if ($events !== $expected || $db->table(Submissions::TABLE)->find($submissionId) !== null
            || $db->table(Submissions::NOTIFICATION_TABLE)->find($notificationId) !== null || $db->table('jobs')->find($jobId) !== null) {
            throw new RuntimeException('Unexpected race result for ' . $operation . ': ' . json_encode($events));
        }
    } catch (Throwable $error) {
        $details = [];
        foreach (glob($dir . '/*.{out,err}', GLOB_BRACE) ?: [] as $path) {
            $details[] = basename($path) . ': ' . (string) file_get_contents($path);
        }
        throw new RuntimeException($operation . ': ' . $error->getMessage() . ' ' . implode(' ', $details), 0, $error);
    } finally {
        foreach ($active as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        if ($submissionId > 0) {
            (new Submissions($db))->delete($submissionId);
        }
        // A failed worker may have committed delivery before queue completion,
        // leaving an orphan job. Only the uniquely named test queue is touched.
        $db->table('jobs')->where('queue', '=', $queueName)->where('handler', '=', Notifications::JOB_NAME)->delete();
        foreach (glob($dir . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($dir);
    }
}
echo "Notification races: delivery serialized with deletion, purge and uninstall cleanup; reserved job cancelled.\n";
