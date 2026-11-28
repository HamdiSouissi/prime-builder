<?php

declare(strict_types=1);

namespace PrimeBuilder\Tests;

use PHPUnit\Framework\TestCase;
use PrimeBuilder\Forms\Fields;
use PrimeBuilder\Forms\FileInput;
use PrimeBuilder\Forms\UploadLimits;
use ZipArchive;

require_once dirname(__DIR__) . '/src/Forms/FileInput.php';
require_once dirname(__DIR__) . '/src/Forms/UploadLimits.php';
require_once dirname(__DIR__) . '/src/Forms/Fields.php';

final class FormFileTest extends TestCase
{
    public function testFilesAreBoundedAndDetectedFromTheirContents(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO9WI3sAAAAASUVORK5CYII=', true);
        foreach ([
            ['rapport.txt', "Bonjour, résultat.\n", 'text/plain'],
            ['aperçu.PnG', $png, 'image/png'],
            ['image.jpeg', "\xff\xd8\xff\xe0\x00\x10JFIF\x00" . str_repeat("\0", 100), 'image/jpeg'],
            ['document.pdf', "%PDF-1.4\nSample\n%%EOF\n", 'application/pdf'],
        ] as [$name, $bytes, $mime]) {
            $result = FileInput::validate(['name' => $name, 'content' => base64_encode($bytes)]);
            self::assertNotNull($result, $name);
            self::assertSame($mime, $result['mime']);
            self::assertSame(strlen($bytes), $result['bytes']);
            self::assertSame($bytes, base64_decode($result['content_base64'], true));
        }
        $good = ['name' => 'document.txt', 'content' => base64_encode('A plain text document.')];
        $bad = [
            null, '', [], $good + ['type' => 'text/plain'],
            ['name' => '../document.txt', 'content' => $good['content']],
            ['name' => 'dir\\document.txt', 'content' => $good['content']],
            ['name' => "document.txt\r\nX", 'content' => $good['content']],
            ['name' => 'executable.php', 'content' => $good['content']],
            ['name' => 'document.pdf', 'content' => $good['content']],
            ['name' => 'document.txt', 'content' => base64_encode('<html><script>evil()</script></html>')],
            ['name' => 'document.txt', 'content' => base64_encode("text\0more")],
            ['name' => 'document.txt', 'content' => ''],
            ['name' => 'document.txt', 'content' => $good['content'] . '!'],
            ['name' => 'document.txt', 'content' => base64_encode(str_repeat('a', FileInput::MAX_BYTES + 1))],
            ['name' => str_repeat('a', 157) . '.txt', 'content' => $good['content']],
        ];
        foreach ($bad as $index => $file) {
            self::assertNull(FileInput::validate($file), 'invalid attachment ' . $index);
        }
        self::assertNotNull(FileInput::validate(['name' => 'large.txt', 'content' => base64_encode(str_repeat('a', FileInput::MAX_BYTES))]));
    }

    public function testDocxMustBeABoundedNonMacroOoxmlPackage(): void
    {
        self::assertTrue(class_exists(ZipArchive::class));
        $package = static function (array $extra = [], string $type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'): string {
            $path = tempnam(sys_get_temp_dir(), 'pb-docx-');
            self::assertIsString($path);
            $zip = new ZipArchive();
            self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
            $zip->addFromString('[Content_Types].xml', '<Types><Override ContentType="' . $type . '"/></Types>');
            $zip->addFromString('word/document.xml', '<w:document xmlns:w="urn:test"/>');
            foreach ($extra as $name => $contents) {
                $zip->addFromString($name, $contents);
            }
            $zip->close();
            $bytes = file_get_contents($path);
            unlink($path);
            self::assertIsString($bytes);
            return $bytes;
        };
        $valid = $package();
        $checked = FileInput::validate(['name' => 'document.DOCX', 'content' => base64_encode($valid)]);
        self::assertNotNull($checked);
        self::assertSame(FileInput::DOCX_MIME, $checked['mime']);
        self::assertSame($valid, base64_decode($checked['content_base64'], true));
        foreach ([
            $package(['../escape' => 'bad']),
            $package(['word/vbaProject.bin' => 'bad']),
            $package([], 'application/vnd.ms-word.document.macroEnabled.main+xml'),
            $package(['word/bomb.xml' => str_repeat('x', 33 * 1024 * 1024)]),
            'not a zip archive',
        ] as $bad) {
            self::assertNull(FileInput::validate(['name' => 'document.docx', 'content' => base64_encode($bad)]));
        }
        $fakeUpload = ['name' => 'document.docx', 'tmp_name' => __FILE__, 'size' => 1, 'error' => UPLOAD_ERR_OK];
        self::assertNull(FileInput::uploaded($fakeUpload, 1024), 'Only actual PHP upload temp files are accepted');
        $fakeUpload['error'] = UPLOAD_ERR_INI_SIZE;
        self::assertNull(FileInput::uploaded($fakeUpload, 1024));
    }

    public function testConfigurableLimitsAndServerWarnings(): void
    {
        self::assertSame(2097152, UploadLimits::iniBytes('2M'));
        self::assertSame(1073741824, UploadLimits::iniBytes('1G'));
        self::assertNull(UploadLimits::iniBytes('0'));
        self::assertNull(UploadLimits::iniBytes('garbage'));
        $limits = ['file_kb' => 1024, 'total_kb' => 2048, 'count' => 2];
        $warnings = UploadLimits::warnings($limits, 1024, '512K', '2M', '1', 'Off');
        self::assertContains('disabled', $warnings);
        self::assertContains('upload_max', $warnings);
        self::assertContains('post_max', $warnings);
        self::assertContains('api_max', $warnings);
        self::assertContains('file_count', $warnings);
        $legacyOnly = UploadLimits::warnings($limits, 2200, '2M', '4M', '4', 'On');
        self::assertNotContains('api_max', $legacyOnly);
        self::assertContains('api_json_max', $legacyOnly);
        $ok = UploadLimits::warnings($limits, 4096, '2M', '4M', '4', 'On');
        foreach (['disabled', 'upload_max', 'post_max', 'api_max', 'api_json_max', 'file_count'] as $warning) {
            self::assertNotContains($warning, $ok);
        }
    }

    public function testRequiredOptionalAndCombinedAttachmentLimitsAreValidated(): void
    {
        $field = static fn (string $id, bool $required = false): array => ['id' => $id, 'type' => 'file', 'label' => 'Pièce ' . $id, 'required' => $required, 'step' => 1, 'options' => [], 'value' => ''];
        $json = static fn (array $rows): string => (string) json_encode($rows);
        $fields = Fields::parse($json([$field('f_one', true), $field('f_two')]));
        self::assertNotNull($fields);
        $file = ['name' => 'notes.txt', 'content' => base64_encode('A private message.')];
        $values = Fields::submitted($fields, ['f_one' => $file, 'f_two' => null]);
        self::assertNotNull($values);
        self::assertSame('Pièce f_one: notes.txt', explode("\n", $values['message'])[0]);
        self::assertCount(1, $values['files']);
        self::assertSame('f_one', $values['files'][0]['field_id']);
        $snapshot = json_decode($values['fields_json'], true, 32, JSON_THROW_ON_ERROR);
        $byId = array_column($snapshot['fields'], null, 'id');
        self::assertSame(['filename' => 'notes.txt', 'mime' => 'text/plain', 'bytes' => strlen('A private message.')], $byId['f_one']['value']);
        self::assertNull($byId['f_two']['value']);
        self::assertStringNotContainsString(base64_encode('A private message.'), $values['fields_json']);
        self::assertNull(Fields::submitted($fields, ['f_one' => null, 'f_two' => null]));
        self::assertNull(Fields::submitted($fields, ['f_one' => $file]));
        self::assertNull(Fields::submitted($fields, ['f_one' => $file, 'f_two' => 'notes.txt']));
        $large = ['name' => 'large.txt', 'content' => base64_encode(str_repeat('a', 300000))];
        self::assertNull(Fields::submitted($fields, ['f_one' => $large, 'f_two' => $large]));
        $twoKiB = ['name' => 'two.txt', 'content' => base64_encode(str_repeat('a', 2048))];
        $oneKiB = ['name' => 'one.txt', 'content' => base64_encode(str_repeat('b', 1024))];
        $limits = ['file_kb' => 2, 'total_kb' => 3, 'count' => 2];
        self::assertNotNull(Fields::submitted($fields, ['f_one' => $twoKiB, 'f_two' => $oneKiB], $limits));
        self::assertNull(Fields::submitted($fields, ['f_one' => $twoKiB, 'f_two' => $twoKiB], $limits));
        $limits['count'] = 1;
        self::assertNull(Fields::submitted($fields, ['f_one' => $oneKiB, 'f_two' => $oneKiB], $limits));
        $limits['file_kb'] = 1;
        self::assertNull(Fields::submitted($fields, ['f_one' => $twoKiB, 'f_two' => null], $limits));
        self::assertNull(Fields::parse($json([$field('f_1'), $field('f_2'), $field('f_3'), $field('f_4'), $field('f_5')])));
    }
}
