<?php

declare(strict_types=1);

namespace NoteAgent\Tests;

use NoteAgent\InvalidInputException;
use NoteAgent\NoteSession;

final class NoteSessionTest extends TempNotesTestCase
{
    public function testScansOnlyTopLevelTxtFilesSortedByName(): void
    {
        $this->writeNotes(['b.txt' => 'b', 'a.txt' => 'a', 'readme.md' => 'x', 'C.TXT' => 'c']);
        mkdir($this->notesDir . '/sub');
        file_put_contents($this->notesDir . '/sub/nested.txt', 'nested');

        $session = new NoteSession($this->notesDir, $this->outDir);

        self::assertSame(['C.TXT', 'a.txt', 'b.txt'], $session->files());
    }

    public function testMissingDirectoryIsInputError(): void
    {
        $this->expectException(InvalidInputException::class);
        new NoteSession($this->root . '/nope', $this->outDir);
    }

    public function testTooFewNotesIsInputError(): void
    {
        $this->writeNotes(['only.txt' => 'one']);

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('有 1 份');
        new NoteSession($this->notesDir, $this->outDir);
    }

    public function testTooManyNotesIsInputError(): void
    {
        for ($i = 1; $i <= NoteSession::MAX_NOTES + 1; $i++) {
            $this->writeNotes(["n{$i}.txt" => 'x']);
        }

        $this->expectException(InvalidInputException::class);
        new NoteSession($this->notesDir, $this->outDir);
    }

    public function testUnassignedTracksCategories(): void
    {
        $session = $this->session();
        self::assertSame(['a.txt', 'b.txt', '週末.txt'], $session->unassigned());

        $session->putCategory('技術', '技術.md', ['a.txt' => 'r', 'b.txt' => 'r']);

        self::assertSame(['週末.txt'], $session->unassigned());
        self::assertSame(2, $session->assignedCount());
        self::assertSame('技術', $session->categoryOf('b.txt'));
        self::assertNull($session->categoryOf('週末.txt'));
    }

    public function testClearOutputRemovesOnlyMarkdownFiles(): void
    {
        $session = $this->session();
        mkdir($this->outDir);
        file_put_contents($this->outDir . '/old.md', 'old');
        file_put_contents($this->outDir . '/keep.txt', 'keep');

        $session->clearOutput();

        self::assertFileDoesNotExist($this->outDir . '/old.md');
        self::assertFileExists($this->outDir . '/keep.txt');
    }

    public function testClearOutputCreatesMissingDirectory(): void
    {
        $session = $this->session();

        $session->clearOutput();

        self::assertDirectoryExists($this->outDir);
    }
}
