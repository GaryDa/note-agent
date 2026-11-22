<?php

declare(strict_types=1);

namespace NoteAgent;

/**
 * 三個工具共用的狀態：筆記清單（啟動時掃描一次）與目前的分類結果。
 */
final class NoteSession
{
    public const int MIN_NOTES = 2;
    public const int MAX_NOTES = 50;

    /** @var list<string> 筆記檔名，依檔名排序 */
    private readonly array $files;

    /** @var array<string, array<string, string>> 分類名稱 => [檔名 => 理由] */
    private array $categories = [];

    /** @var array<string, string> 分類名稱 => 輸出檔名 */
    private array $categoryFiles = [];

    /**
     * @throws InvalidInputException 資料夾不存在，或筆記數量不在範圍內
     */
    public function __construct(
        public readonly string $notesDir,
        public readonly string $outDir,
    ) {
        if (!is_dir($notesDir)) {
            throw new InvalidInputException("筆記資料夾不存在：{$notesDir}");
        }

        $files = [];
        foreach (new \DirectoryIterator($notesDir) as $entry) {
            if ($entry->isFile() && strtolower($entry->getExtension()) === 'txt') {
                $files[] = $entry->getFilename();
            }
        }
        sort($files, SORT_STRING);

        $count = count($files);
        if ($count < self::MIN_NOTES || $count > self::MAX_NOTES) {
            throw new InvalidInputException(sprintf(
                '筆記數量要介於 %d 和 %d 份之間，%s 有 %d 份',
                self::MIN_NOTES,
                self::MAX_NOTES,
                $notesDir,
                $count,
            ));
        }
        $this->files = $files;
    }

    /** @return list<string> */
    public function files(): array
    {
        return $this->files;
    }

    public function hasFile(string $file): bool
    {
        return in_array($file, $this->files, true);
    }

    public function notePath(string $file): string
    {
        return $this->notesDir . DIRECTORY_SEPARATOR . $file;
    }

    /** @return array<string, array<string, string>> */
    public function categories(): array
    {
        return $this->categories;
    }

    /** @return array<string, string> 分類名稱 => 輸出檔名 */
    public function categoryFiles(): array
    {
        return $this->categoryFiles;
    }

    public function categoryOf(string $file): ?string
    {
        foreach ($this->categories as $name => $notes) {
            if (array_key_exists($file, $notes)) {
                return $name;
            }
        }
        return null;
    }

    public function assignedCount(): int
    {
        return array_sum(array_map(count(...), $this->categories));
    }

    /** @return list<string> 還沒分類的筆記，依檔名排序 */
    public function unassigned(): array
    {
        return array_values(array_filter($this->files, fn (string $f) => $this->categoryOf($f) === null));
    }

    /**
     * 建立或整個取代一個分類。驗證由 WriteCategory 負責，這裡只更新狀態。
     *
     * @param array<string, string> $notes 檔名 => 理由
     */
    public function putCategory(string $name, string $fileName, array $notes): void
    {
        $this->categories[$name] = $notes;
        $this->categoryFiles[$name] = $fileName;
    }

    /**
     * 每次執行前清空輸出資料夾：只刪第一層的 .md，不碰其他檔案。
     */
    public function clearOutput(): void
    {
        if (!is_dir($this->outDir) && !mkdir($this->outDir, 0o755, true) && !is_dir($this->outDir)) {
            throw new \RuntimeException("無法建立輸出資料夾：{$this->outDir}");
        }
        foreach (glob($this->outDir . DIRECTORY_SEPARATOR . '*.md') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
