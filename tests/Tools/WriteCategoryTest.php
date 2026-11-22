<?php

declare(strict_types=1);

namespace NoteAgent\Tests\Tools;

use NoteAgent\NoteSession;
use NoteAgent\Tests\TempNotesTestCase;
use NoteAgent\Tools\ToolInputException;
use NoteAgent\Tools\WriteCategory;
use PHPUnit\Framework\Attributes\DataProvider;

final class WriteCategoryTest extends TempNotesTestCase
{
    private NoteSession $session;
    private WriteCategory $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = $this->session();
        $this->tool = new WriteCategory($this->session);
    }

    public function testWritesCategoryFileAndReportsProgress(): void
    {
        $result = ($this->tool)([
            'name' => 'PHP 與 agent',
            'notes' => [
                ['file' => 'a.txt', 'reason' => '講 PHP 設定'],
                ['file' => 'b.txt', 'reason' => '講 agent loop'],
            ],
        ]);

        self::assertSame(
            ['written' => 'out/PHP 與 agent.md', 'category_count' => 1, 'assigned' => 2, 'unassigned' => ['週末.txt']],
            $result,
        );
        self::assertSame(
            "# PHP 與 agent\n\n- a.txt：講 PHP 設定\n- b.txt：講 agent loop\n",
            file_get_contents($this->outDir . '/PHP 與 agent.md'),
        );
    }

    public function testUnassignedIsEmptyWhenEverythingIsClassified(): void
    {
        ($this->tool)(['name' => '技術', 'notes' => [['file' => 'a.txt', 'reason' => 'r'], ['file' => 'b.txt', 'reason' => 'r']]]);
        $result = ($this->tool)(['name' => '生活', 'notes' => [['file' => '週末.txt', 'reason' => 'r']]]);

        self::assertSame([], $result['unassigned']);
        self::assertSame(2, $result['category_count']);
    }

    public function testReasonIsCollapsedToOneLine(): void
    {
        ($this->tool)(['name' => '技術', 'notes' => [['file' => 'a.txt', 'reason' => "  第一行\n第二行\t結尾  "]]]);

        self::assertSame("# 技術\n\n- a.txt：第一行 第二行 結尾\n", file_get_contents($this->outDir . '/技術.md'));
    }

    public function testOverwritingSameNameReleasesNotesNotListedAgain(): void
    {
        ($this->tool)(['name' => '技術', 'notes' => [['file' => 'a.txt', 'reason' => 'r'], ['file' => 'b.txt', 'reason' => 'r']]]);

        $result = ($this->tool)(['name' => '技術', 'notes' => [['file' => 'a.txt', 'reason' => '只留 a']]]);

        self::assertSame(['b.txt', '週末.txt'], $result['unassigned']);
        self::assertSame(1, $result['category_count']);
        self::assertSame("# 技術\n\n- a.txt：只留 a\n", file_get_contents($this->outDir . '/技術.md'));
    }

    public function testNoteAlreadyInAnotherCategoryIsRejectedAndNothingChanges(): void
    {
        ($this->tool)(['name' => '技術', 'notes' => [['file' => 'a.txt', 'reason' => 'r']]]);

        try {
            ($this->tool)(['name' => '其他', 'notes' => [['file' => 'b.txt', 'reason' => 'r'], ['file' => 'a.txt', 'reason' => 'r']]]);
            self::fail('應該要丟出 ToolInputException');
        } catch (ToolInputException $e) {
            self::assertStringContainsString('a.txt 已經在分類「技術」裡', $e->getMessage());
            self::assertStringContainsString('同名覆寫', $e->getMessage());
        }

        // 整次呼叫都不生效：b.txt 沒有被歸類，也沒有寫出檔案
        self::assertSame(['技術'], array_keys($this->session->categories()));
        self::assertNull($this->session->categoryOf('b.txt'));
        self::assertFileDoesNotExist($this->outDir . '/其他.md');
    }

    public function testCannotCreateMoreThanSixCategories(): void
    {
        $this->writeNotes(array_combine(
            array_map(fn (int $i) => "n{$i}.txt", range(1, 7)),
            array_fill(0, 7, 'x'),
        ));
        $session = new NoteSession($this->notesDir, $this->outDir);
        $tool = new WriteCategory($session);
        for ($i = 1; $i <= WriteCategory::MAX_CATEGORIES; $i++) {
            $tool(['name' => "分類{$i}", 'notes' => [['file' => "n{$i}.txt", 'reason' => 'r']]]);
        }

        try {
            $tool(['name' => '分類7', 'notes' => [['file' => 'n7.txt', 'reason' => 'r']]]);
            self::fail('應該要丟出 ToolInputException');
        } catch (ToolInputException $e) {
            self::assertStringContainsString('已經有 6 個分類', $e->getMessage());
        }

        // 覆寫既有分類不受上限影響
        $result = $tool(['name' => '分類1', 'notes' => [['file' => 'n1.txt', 'reason' => 'r'], ['file' => 'n7.txt', 'reason' => 'r']]]);
        self::assertSame(6, $result['category_count']);
    }

    public function testNameIsSanitizedIntoFileName(): void
    {
        $result = ($this->tool)(['name' => ' ..PHP/環境: 設定? ', 'notes' => [['file' => 'a.txt', 'reason' => 'r']]]);

        self::assertSame('out/PHP環境 設定.md', $result['written']);
        self::assertFileExists($this->outDir . '/PHP環境 設定.md');
        // 檔案內的標題保留原本的名稱
        self::assertStringStartsWith("# ..PHP/環境: 設定?\n", (string) file_get_contents($this->outDir . '/PHP環境 設定.md'));
    }

    public function testNamesProducingSameFileNameCollide(): void
    {
        ($this->tool)(['name' => 'a/b', 'notes' => [['file' => 'a.txt', 'reason' => 'r']]]);

        $this->expectException(ToolInputException::class);
        $this->expectExceptionMessage('會產生同一個檔名');

        ($this->tool)(['name' => 'ab', 'notes' => [['file' => 'b.txt', 'reason' => 'r']]]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badNames(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => ['   '];
        yield 'only forbidden characters' => ['/?*'];
        yield 'only dots' => ['...'];
        yield 'too long' => [str_repeat('字', WriteCategory::MAX_NAME_CHARS + 1)];
        yield 'not a string' => [42];
    }

    #[DataProvider('badNames')]
    public function testRejectsInvalidNames(mixed $name): void
    {
        $this->expectException(ToolInputException::class);
        $this->expectExceptionMessage('分類名稱要 1 到 40 字');

        ($this->tool)(['name' => $name, 'notes' => [['file' => 'a.txt', 'reason' => 'r']]]);
    }

    public function testNameOfExactlyFortyCharactersIsAccepted(): void
    {
        $name = str_repeat('字', WriteCategory::MAX_NAME_CHARS);

        $result = ($this->tool)(['name' => $name, 'notes' => [['file' => 'a.txt', 'reason' => 'r']]]);

        self::assertSame(1, $result['category_count']);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function badNotes(): iterable
    {
        yield 'missing' => [null, '至少有 1 份筆記'];
        yield 'empty list' => [[], '至少有 1 份筆記'];
        yield 'object instead of list' => [['file' => 'a.txt', 'reason' => 'r'], '至少有 1 份筆記'];
        yield 'item without reason' => [[['file' => 'a.txt']], '字串 file 和 reason'];
        yield 'unknown file' => [[['file' => 'nope.txt', 'reason' => 'r']], '請呼叫 list_notes'];
        yield 'duplicate in one call' => [[['file' => 'a.txt', 'reason' => 'r'], ['file' => 'a.txt', 'reason' => 'r']], '出現了兩次'];
        yield 'blank reason' => [[['file' => 'a.txt', 'reason' => " \n "]], '理由是空的'];
    }

    #[DataProvider('badNotes')]
    public function testRejectsInvalidNotes(mixed $notes, string $message): void
    {
        $this->expectException(ToolInputException::class);
        $this->expectExceptionMessage($message);

        ($this->tool)(['name' => '技術', 'notes' => $notes]);
    }

    public function testFailedCallLeavesNoFileBehind(): void
    {
        try {
            ($this->tool)(['name' => '技術', 'notes' => [['file' => 'a.txt', 'reason' => '']]]);
        } catch (ToolInputException) {
        }

        self::assertSame([], $this->session->categories());
        self::assertFileDoesNotExist($this->outDir . '/技術.md');
    }
}
