<?php

declare(strict_types=1);

namespace PrimeBuilder\Tests;

use PHPUnit\Framework\TestCase;
use PrimeBuilder\Forms\Fields;
use PrimeBuilder\Forms\FileInput;

require_once dirname(__DIR__) . '/src/Forms/FileInput.php';
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
        self::assertNull(Fields::submitted($fields, ['f_one' => null, 'f_two' => null]));
        self::assertNull(Fields::submitted($fields, ['f_one' => $file]));
        self::assertNull(Fields::submitted($fields, ['f_one' => $file, 'f_two' => 'notes.txt']));
        $large = ['name' => 'large.txt', 'content' => base64_encode(str_repeat('a', 300000))];
        self::assertNull(Fields::submitted($fields, ['f_one' => $large, 'f_two' => $large]));
        self::assertNull(Fields::parse($json([$field('f_1'), $field('f_2'), $field('f_3'), $field('f_4'), $field('f_5')])));
    }
}
