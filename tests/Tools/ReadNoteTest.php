<?php

declare(strict_types=1);

namespace NoteAgent\Tests\Tools;

use NoteAgent\NoteSession;
use NoteAgent\Tests\TempNotesTestCase;
use NoteAgent\Tools\ReadNote;
use NoteAgent\Tools\ToolInputException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ReadNoteTest extends TempNotesTestCase
{
    public function testReadsNoteContent(): void
    {
        $result = (new ReadNote($this->session()))(['file' => '週末.txt']);

        self::assertSame(
            ['file' => '週末.txt', 'content' => '買菜', 'chars' => 2, 'truncated' => false],
            $result,
        );
    }

    public function testTruncatesLongNotesByCharacters(): void
    {
        $this->writeNotes([
            'long.txt' => str_repeat('字', ReadNote::MAX_CHARS + 5),
            'short.txt' => 'x',
        ]);
        $session = new NoteSession($this->notesDir, $this->outDir);

        $result = (new ReadNote($session))(['file' => 'long.txt']);

        self::assertSame(ReadNote::MAX_CHARS, mb_strlen($result['content']));
        self::assertSame(ReadNote::MAX_CHARS + 5, $result['chars']);
        self::assertTrue($result['truncated']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathLikeNames(): iterable
    {
        yield 'parent dir' => ['../secret.txt'];
        yield 'subdir' => ['sub/a.txt'];
        yield 'backslash' => ['sub\\a.txt'];
        yield 'dot dot only' => ['..'];
        yield 'absolute' => ['/etc/passwd'];
    }

    #[DataProvider('pathLikeNames')]
    public function testRejectsPaths(string $file): void
    {
        $this->expectException(ToolInputException::class);
        $this->expectExceptionMessage('不能包含路徑');

        (new ReadNote($this->session()))(['file' => $file]);
    }

    public function testUnknownFileTellsModelToListNotes(): void
    {
        $this->expectException(ToolInputException::class);
        $this->expectExceptionMessage('請呼叫 list_notes');

        (new ReadNote($this->session()))(['file' => 'nope.txt']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badInputs(): iterable
    {
        yield 'missing' => [[]];
        yield 'empty' => [['file' => '']];
        yield 'not a string' => [['file' => 123]];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('badInputs')]
    public function testRejectsMissingOrInvalidFileParameter(array $input): void
    {
        $this->expectException(ToolInputException::class);
        $this->expectExceptionMessage('缺少 file 參數');

        (new ReadNote($this->session()))($input);
    }
}
