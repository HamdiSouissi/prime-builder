<?php

declare(strict_types=1);

namespace PrimeBuilder\Tests;

use PHPUnit\Framework\TestCase;
use PrimeBuilder\Forms\Fields;

require_once dirname(__DIR__) . '/src/Forms/Fields.php';

final class FormFieldsTest extends TestCase
{
    /** @return list<array{id: string, type: string, label: string, required: bool, step: int, options: list<string>, value: string}> */
    private function definition(): array
    {
        return [
            ['id' => 'f_name', 'type' => 'text', 'label' => ' Nom ', 'required' => true, 'step' => 1, 'options' => [], 'value' => ''],
            ['id' => 'f_email', 'type' => 'email', 'label' => 'E-mail', 'required' => true, 'step' => 1, 'options' => [], 'value' => ''],
            ['id' => 'f_phone', 'type' => 'phone', 'label' => 'Téléphone', 'required' => false, 'step' => 1, 'options' => [], 'value' => ''],
            ['id' => 'f_number', 'type' => 'number', 'label' => 'Nombre', 'required' => false, 'step' => 1, 'options' => [], 'value' => ''],
            ['id' => 'f_notes', 'type' => 'textarea', 'label' => 'Notes', 'required' => false, 'step' => 1, 'options' => [], 'value' => ''],
            ['id' => 'f_list', 'type' => 'select', 'label' => 'Liste', 'required' => true, 'step' => 2, 'options' => [' Alpha ', 'Bêta'], 'value' => ''],
            ['id' => 'f_check', 'type' => 'checkbox', 'label' => 'Cases', 'required' => true, 'step' => 2, 'options' => ['Un', 'Deux'], 'value' => ''],
            ['id' => 'f_radio', 'type' => 'radio', 'label' => 'Radio', 'required' => false, 'step' => 2, 'options' => ['Oui', 'Non'], 'value' => ''],
            ['id' => 'f_date', 'type' => 'date', 'label' => 'Date', 'required' => false, 'step' => 2, 'options' => [], 'value' => ''],
            ['id' => 'f_accept', 'type' => 'acceptance', 'label' => 'Consentement', 'required' => true, 'step' => 2, 'options' => [], 'value' => ''],
            ['id' => 'f_source', 'type' => 'hidden', 'label' => 'Source', 'required' => false, 'step' => 2, 'options' => [], 'value' => ' site '],
        ];
    }

    /** @return array<string, mixed> */
    private function values(): array
    {
        return [
            'f_name' => '  Zoé <admin>  ', 'f_email' => 'zoe@example.test', 'f_phone' => '+33 (0)1 23 45 67 89',
            'f_number' => '-123.4567', 'f_notes' => "Ligne 1\r\nLigne 2", 'f_list' => 'Alpha',
            'f_check' => ['Un', 'Deux'], 'f_radio' => 'Oui', 'f_date' => '2024-02-29',
            'f_accept' => true, 'f_source' => 'site',
        ];
    }

    public function testDefinitionIsBoundedCanonicalAndRequiresContiguousVisibleSteps(): void
    {
        $json = (string) json_encode($this->definition(), JSON_UNESCAPED_UNICODE);
        $canonical = Fields::canonical($json);
        self::assertNotNull($canonical);
        $parsed = Fields::parse($canonical);
        self::assertNotNull($parsed);
        self::assertSame('Nom', $parsed[0]['label']);
        self::assertSame(['Alpha', 'Bêta'], $parsed[5]['options']);
        self::assertSame('site', $parsed[10]['value']);
        self::assertSame($canonical, Fields::canonical($canonical));
        foreach (['', '{}', '[]', '[', str_repeat(' ', Fields::MAX_BYTES + 1), "[\xff]"] as $bad) {
            self::assertNull(Fields::parse($bad));
        }
        $cases = [];
        $bad = $this->definition();
        $bad[1]['id'] = 'f_name';
        $cases[] = $bad;
        $bad = $this->definition();
        $bad[0]['id'] = 'website';
        $cases[] = $bad;
        $bad = $this->definition();
        $bad[0]['id'] = 'f_bad-name';
        $cases[] = $bad;
        $bad = $this->definition();
        $bad[0]['type'] = 'unsupported';
        $cases[] = $bad;
        $bad = $this->definition();
        $bad[0]['label'] = "A\nB";
        $cases[] = $bad;
        $bad = $this->definition();
        $bad[0]['label'] = str_repeat('é', 101);
        $cases[] = $bad;
        $bad = $this->definition();
        $bad[0]['required'] = 1;
        $cases[] = $bad;
        $bad = $this->definition();
        $bad[0]['step'] = 0;
        $cases[] = $bad;
        $bad = $this->definition();
        $bad[0]['step'] = 6;
        $cases[] = $bad;
        $bad = $this->definition();
        $bad[0]['extra'] = true;
        $cases[] = $bad;
        $bad = $this->definition();
        unset($bad[0]['value']);
        $cases[] = $bad;
        $bad = $this->definition();
        $bad[5]['options'] = [];
        $cases[] = $bad;
        $bad = $this->definition();
        $bad[5]['options'] = ['Alpha', ' Alpha '];
        $cases[] = $bad;
        $bad = $this->definition();
        $bad[5]['options'] = [str_repeat('x', 101)];
        $cases[] = $bad;
        $bad = $this->definition();
        $bad[0]['options'] = ['Unexpected'];
        $cases[] = $bad;
        $bad = $this->definition();
        $bad[0]['value'] = 'unexpected';
        $cases[] = $bad;
        $bad = $this->definition();
        $bad[10]['required'] = true;
        $cases[] = $bad;
        $bad = $this->definition();
        foreach ($bad as &$row) {
            if ($row['step'] === 2) {
                $row['step'] = 3;
            }
        } unset($row);
        $cases[] = $bad;
        $bad = [$this->definition()[10]];
        $bad[0]['step'] = 1;
        $cases[] = $bad;
        $bad = [];
        for ($i = 0; $i < 25; $i++) {
            $row = $this->definition()[0];
            $row['id'] = 'f_' . $i;
            $bad[] = $row;
        } $cases[] = $bad;
        foreach ($cases as $index => $case) {
            self::assertNull(Fields::parse((string) json_encode($case, JSON_UNESCAPED_UNICODE)), 'invalid definition ' . $index);
        }
    }

    public function testStructuredSnapshotKeepsDuplicateLabelsArraysAndLiteralFormulaLikeValues(): void
    {
        $definitions = [
            ['id' => 'f_choices', 'type' => 'checkbox', 'label' => 'Même libellé', 'required' => false, 'step' => 1, 'options' => ['A, B', '+SUM(1,1)'], 'value' => ''],
            ['id' => 'f_text', 'type' => 'text', 'label' => 'Même libellé', 'required' => false, 'step' => 1, 'options' => [], 'value' => ''],
        ];
        $fields = Fields::parse((string) json_encode($definitions));
        self::assertNotNull($fields);
        $result = Fields::submitted($fields, ['f_choices' => ['A, B', '+SUM(1,1)'], 'f_text' => '=1+1']);
        self::assertNotNull($result);
        $snapshot = json_decode($result['fields_json'], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame([
            ['id' => 'f_choices', 'type' => 'checkbox', 'label' => 'Même libellé', 'value' => ['A, B', '+SUM(1,1)']],
            ['id' => 'f_text', 'type' => 'text', 'label' => 'Même libellé', 'value' => '=1+1'],
        ], $snapshot['fields']);
    }

    public function testSubmissionChecksEveryPublishedTypeAndRejectsUnexpectedData(): void
    {
        $fields = Fields::parse((string) json_encode($this->definition(), JSON_UNESCAPED_UNICODE));
        self::assertNotNull($fields);
        $values = $this->values();
        $result = Fields::submitted($fields, $values);
        self::assertNotNull($result);
        self::assertSame('Zoé <admin>', $result['name']);
        self::assertSame('zoe@example.test', $result['email']);
        self::assertStringContainsString("Notes: Ligne 1\nLigne 2", $result['message']);
        self::assertStringContainsString('Cases: Un, Deux', $result['message']);
        self::assertStringContainsString('Source: site', $result['message']);
        $snapshot = json_decode($result['fields_json'], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(1, $snapshot['version']);
        $byId = array_column($snapshot['fields'], null, 'id');
        self::assertSame('Zoé <admin>', $byId['f_name']['value']);
        self::assertSame('text', $byId['f_name']['type']);
        self::assertSame("Ligne 1\nLigne 2", $byId['f_notes']['value']);
        self::assertSame(['Un', 'Deux'], $byId['f_check']['value']);
        self::assertTrue($byId['f_accept']['value']);
        self::assertSame('-123.4567', $byId['f_number']['value'], 'Numbers retain the exact validated decimal string');
        $cases = [];
        $bad = $values;
        unset($bad['f_name']);
        $cases[] = $bad;
        $bad = $values;
        $bad['unknown'] = 'x';
        $cases[] = $bad;
        $bad = $values;
        $bad['f_name'] = ['x'];
        $cases[] = $bad;
        $bad = $values;
        $bad['f_name'] = '';
        $cases[] = $bad;
        $bad = $values;
        $bad['f_name'] = "a\nb";
        $cases[] = $bad;
        $bad = $values;
        $bad['f_email'] = "a@example.test\r\nBcc:other@example.test";
        $cases[] = $bad;
        $bad = $values;
        $bad['f_email'] = 'not-an-email';
        $cases[] = $bad;
        $bad = $values;
        $bad['f_phone'] = '123<script>';
        $cases[] = $bad;
        $bad = $values;
        $bad['f_number'] = '1e9';
        $cases[] = $bad;
        $bad = $values;
        $bad['f_number'] = '1.12345';
        $cases[] = $bad;
        $bad = $values;
        $bad['f_notes'] = str_repeat('x', 2001);
        $cases[] = $bad;
        $bad = $values;
        $bad['f_notes'] = "bad\0text";
        $cases[] = $bad;
        $bad = $values;
        $bad['f_list'] = 'Forged';
        $cases[] = $bad;
        $bad = $values;
        $bad['f_list'] = '';
        $cases[] = $bad;
        $bad = $values;
        $bad['f_check'] = [];
        $cases[] = $bad;
        $bad = $values;
        $bad['f_check'] = ['Un', 'Un'];
        $cases[] = $bad;
        $bad = $values;
        $bad['f_check'] = ['Un', 'Forged'];
        $cases[] = $bad;
        $bad = $values;
        $bad['f_check'] = 'Un';
        $cases[] = $bad;
        $bad = $values;
        $bad['f_radio'] = 'Forged';
        $cases[] = $bad;
        $bad = $values;
        $bad['f_date'] = '2023-02-29';
        $cases[] = $bad;
        $bad = $values;
        $bad['f_date'] = '2024-2-29';
        $cases[] = $bad;
        $bad = $values;
        $bad['f_accept'] = false;
        $cases[] = $bad;
        $bad = $values;
        $bad['f_accept'] = 'true';
        $cases[] = $bad;
        $bad = $values;
        $bad['f_source'] = 'forged';
        $cases[] = $bad;
        foreach ($cases as $index => $case) {
            self::assertNull(Fields::submitted($fields, $case), 'invalid submission ' . $index);
        }
        $optional = $values;
        foreach (['f_phone', 'f_number', 'f_notes', 'f_radio', 'f_date'] as $key) {
            $optional[$key] = '';
        }
        self::assertNotNull(Fields::submitted($fields, $optional));
        $optional['f_name'] = str_repeat('a', 161);
        self::assertNull(Fields::submitted($fields, $optional));
    }
}
