<?php

declare(strict_types=1);

namespace NoteAgent\Tests;

use NoteAgent\NoteSession;
use PHPUnit\Framework\TestCase;

/**
 * 每個測試建立自己的暫存資料夾（notes/ 與 out/），結束後刪掉。
 */
abstract class TempNotesTestCase extends TestCase
{
    protected string $root;
    protected string $notesDir;
    protected string $outDir;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/note-agent-test-' . bin2hex(random_bytes(6));
        $this->notesDir = $this->root . '/notes';
        $this->outDir = $this->root . '/out';
        mkdir($this->notesDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        self::removeDir($this->root);
    }

    /**
     * @param array<string, string> $notes 檔名 => 內容
     */
    protected function writeNotes(array $notes): void
    {
        foreach ($notes as $file => $content) {
            file_put_contents($this->notesDir . '/' . $file, $content);
        }
    }

    /**
     * 建立預設的三份筆記並回傳 session。
     */
    protected function session(): NoteSession
    {
        $this->writeNotes([
            'a.txt' => 'PHP 設定',
            'b.txt' => 'agent loop',
            '週末.txt' => '買菜',
        ]);
        return new NoteSession($this->notesDir, $this->outDir);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
