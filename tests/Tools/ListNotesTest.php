<?php

declare(strict_types=1);

namespace NoteAgent\Tests\Tools;

use NoteAgent\Tests\TempNotesTestCase;
use NoteAgent\Tools\ListNotes;

final class ListNotesTest extends TempNotesTestCase
{
    public function testListsFilesWithCharacterCountsAndUnassigned(): void
    {
        $session = $this->session();

        $result = (new ListNotes($session))([]);

        self::assertSame(3, $result['count']);
        self::assertSame(
            [
                ['file' => 'a.txt', 'chars' => 6],      // 「PHP 設定」是 6 個字，不是位元組數
                ['file' => 'b.txt', 'chars' => 10],
                ['file' => '週末.txt', 'chars' => 2],
            ],
            $result['notes'],
        );
        self::assertSame(['a.txt', 'b.txt', '週末.txt'], $result['unassigned']);
    }

    public function testUnassignedReflectsCurrentCategories(): void
    {
        $session = $this->session();
        $session->putCategory('技術', '技術.md', ['a.txt' => 'r']);

        $result = (new ListNotes($session))([]);

        self::assertSame(['b.txt', '週末.txt'], $result['unassigned']);
    }

    public function testEmptySchemaPropertiesEncodeAsJsonObject(): void
    {
        $definition = (new ListNotes($this->session()))->definition();

        $json = json_encode($definition['inputSchema'], JSON_THROW_ON_ERROR);

        // API 要求 properties 是物件；用 [] 的話會變成 "properties":[]
        self::assertStringContainsString('"properties":{}', $json);
    }
}
