<?php

declare(strict_types=1);

namespace PrimeBuilder\Tests;

use PHPUnit\Framework\TestCase;
use PrimeBuilder\Forms\PrivateFiles;
use RuntimeException;

require_once dirname(__DIR__) . '/src/Forms/UploadLimits.php';
require_once dirname(__DIR__) . '/src/Forms/PrivateFiles.php';

final class PrivateFilesTest extends TestCase
{
    public function testPrivateFilesRejectTraversalAndSymlinksAndNeverFollowThem(): void
    {
        $root = sys_get_temp_dir() . '/pb-private-test-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $directory = $root . '/private';
        $outside = $root . '/outside.txt';
        file_put_contents($outside, 'outside');
        $store = new PrivateFiles($directory);
        try {
            $saved = $store->locked(static fn (): array => $store->write('private contents'));
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $saved['key']);
            self::assertSame(hash('sha256', 'private contents'), $saved['sha256']);
            self::assertSame('private contents', $store->read($saved['key']));
            self::assertNull($store->read('../outside.txt'));
            $path = $directory . '/' . $saved['key'];
            self::assertSame(0, fileperms($path) & 0077);
            unlink($path);
            symlink($outside, $path);
            self::assertNull($store->read($saved['key']));
            self::assertTrue($store->locked(static fn (): bool => $store->remove($saved['key'])));
            self::assertFileExists($outside);
            self::assertSame('outside', file_get_contents($outside));
            $store->clear();
            self::assertDirectoryDoesNotExist($directory);

            $link = $root . '/linked';
            symlink($root, $link);
            try {
                (new PrivateFiles($link))->locked(static fn (): bool => true);
                self::fail('A symlink directory must not be used for storage');
            } catch (RuntimeException) {
                self::assertTrue(is_link($link));
            }
            unlink($link);
        } finally {
            if (is_dir($directory)) {
                $store->clear();
            }
            unlink($outside);
            rmdir($root);
        }
    }
}
