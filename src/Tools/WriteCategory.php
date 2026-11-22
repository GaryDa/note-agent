<?php

declare(strict_types=1);

namespace NoteAgent\Tools;

use NoteAgent\NoteSession;

final class WriteCategory implements Tool
{
    public const int MAX_CATEGORIES = 6;
    public const int MAX_NAME_CHARS = 40;

    /** 不能出現在檔名裡的字元 */
    private const array FORBIDDEN = ['/', '\\', ':', '*', '?', '"', '<', '>', '|'];

    public function __construct(private readonly NoteSession $session)
    {
    }

    public function name(): string
    {
        return 'write_category';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => '建立一個分類並寫出分類檔；如果同名分類已經存在，就整個覆寫'
                . '（原本在這個分類、這次沒列出的筆記會變回未分類）。'
                . '每份筆記只能屬於一個分類，最多 ' . self::MAX_CATEGORIES . ' 個分類。'
                . '每份筆記都要附一句理由，說明為什麼歸到這一類。'
                . '回傳寫出的檔案，以及目前還沒分類的筆記；unassigned 是空的就代表全部分類完成。',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => '分類名稱，1 到 ' . self::MAX_NAME_CHARS . ' 字，例如「PHP 環境設定」；'
                            . '會直接當成檔名，不能包含 / \\ : * ? " < > |',
                    ],
                    'notes' => [
                        'type' => 'array',
                        'description' => '歸到這個分類的筆記，至少 1 份',
                        'minItems' => 1,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'file' => ['type' => 'string', 'description' => '筆記檔名，必須是 list_notes 回傳的檔名'],
                                'reason' => ['type' => 'string', 'description' => '一句話說明為什麼歸到這一類'],
                            ],
                            'required' => ['file', 'reason'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['name', 'notes'],
                'additionalProperties' => false,
            ],
        ];
    }

    public function __invoke(array $input): array
    {
        // 1. 先全部驗證，任何一項失敗就丟例外，分類結果維持原狀
        $name = $this->validateName($input['name'] ?? null);
        $fileName = self::toFileName($name);
        $this->assertNoCollision($name, $fileName);
        $this->assertCategoryLimit($name);
        $notes = $this->validateNotes($name, $input['notes'] ?? null);

        // 2. 驗證都通過才寫檔、更新狀態
        $this->writeFile($fileName, $name, $notes);
        $this->session->putCategory($name, $fileName, $notes);

        return [
            'written' => basename($this->session->outDir) . '/' . $fileName,
            'category_count' => count($this->session->categories()),
            'assigned' => $this->session->assignedCount(),
            'unassigned' => $this->session->unassigned(),
        ];
    }

    /**
     * 分類名稱轉成檔名：去掉禁用字元和開頭的 .，前後去空白，加上 .md。
     * 長度已在 validateName 檢查過。
     */
    public static function toFileName(string $name): string
    {
        $clean = str_replace(self::FORBIDDEN, '', $name);
        $clean = ltrim(trim($clean), '.');
        return trim($clean) . '.md';
    }

    private function validateName(mixed $raw): string
    {
        $name = is_string($raw) ? trim($raw) : '';
        $length = mb_strlen($name);
        if ($length === 0 || $length > self::MAX_NAME_CHARS || self::toFileName($name) === '.md') {
            throw new ToolInputException('分類名稱要 1 到 ' . self::MAX_NAME_CHARS . ' 字，且不能只有特殊字元');
        }
        return $name;
    }

    private function assertNoCollision(string $name, string $fileName): void
    {
        foreach ($this->session->categoryFiles() as $other => $otherFile) {
            if ($other !== $name && $otherFile === $fileName) {
                throw new ToolInputException("分類名稱「{$name}」跟已存在的「{$other}」會產生同一個檔名，請換一個名稱");
            }
        }
    }

    private function assertCategoryLimit(string $name): void
    {
        $categories = $this->session->categories();
        if (!array_key_exists($name, $categories) && count($categories) >= self::MAX_CATEGORIES) {
            throw new ToolInputException(sprintf(
                '已經有 %d 個分類（%s），不能再新增；請把筆記歸到現有分類，或用同名覆寫來合併',
                self::MAX_CATEGORIES,
                implode('、', array_keys($categories)),
            ));
        }
    }

    /**
     * @return array<string, string> 檔名 => 理由
     */
    private function validateNotes(string $name, mixed $raw): array
    {
        if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
            throw new ToolInputException('notes 要是至少有 1 份筆記的陣列，每項包含 file 和 reason');
        }

        $notes = [];
        foreach ($raw as $item) {
            $file = is_array($item) ? ($item['file'] ?? null) : null;
            $reason = is_array($item) ? ($item['reason'] ?? null) : null;
            if (!is_string($file) || !is_string($reason)) {
                throw new ToolInputException('notes 的每一項都要包含字串 file 和 reason');
            }
            if (!$this->session->hasFile($file)) {
                throw new ToolInputException("找不到筆記 {$file}；請呼叫 list_notes 確認正確的檔名");
            }
            if (array_key_exists($file, $notes)) {
                throw new ToolInputException("{$file} 在這次的清單裡出現了兩次");
            }
            $current = $this->session->categoryOf($file);
            if ($current !== null && $current !== $name) {
                throw new ToolInputException(
                    "{$file} 已經在分類「{$current}」裡；一份筆記只能屬於一個分類。"
                    . "要搬移的話，先用同名覆寫把它從「{$current}」移除",
                );
            }
            // 理由要放在同一行（輸出格式是一行一份筆記），換行改成空白
            $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
            if ($reason === '') {
                throw new ToolInputException("{$file} 的理由是空的，請寫一句說明");
            }
            $notes[$file] = $reason;
        }
        return $notes;
    }

    /**
     * @param array<string, string> $notes
     */
    private function writeFile(string $fileName, string $name, array $notes): void
    {
        $lines = ["# {$name}", ''];
        foreach ($notes as $file => $reason) {
            $lines[] = "- {$file}：{$reason}";
        }

        $outDir = $this->session->outDir;
        if (!is_dir($outDir) && !mkdir($outDir, 0o755, true) && !is_dir($outDir)) {
            throw new \RuntimeException("無法建立輸出資料夾：{$outDir}");
        }
        // $fileName 已去掉 / 和 \，只會落在 outDir 第一層
        $path = $outDir . DIRECTORY_SEPARATOR . $fileName;
        if (file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX) === false) {
            throw new \RuntimeException("無法寫入分類檔：{$path}");
        }
    }
}
