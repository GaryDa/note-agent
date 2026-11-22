<?php

declare(strict_types=1);

namespace NoteAgent\Tools;

use NoteAgent\NoteSession;

final class ListNotes implements Tool
{
    public const string NAME = 'list_notes';

    public function __construct(private readonly NoteSession $session)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => '列出要分類的所有筆記，回傳每份筆記的檔名和字數，以及目前還沒分類的筆記。'
                . '開始分類前先呼叫一次；不確定檔名時也可以再呼叫。'
                . '這個工具不回傳內容，要看內容請用 read_note。',
            'inputSchema' => [
                'type' => 'object',
                // 用 [] 會被 json_encode 成陣列，API 要的是空物件 {}
                'properties' => new \stdClass(),
                'additionalProperties' => false,
            ],
        ];
    }

    public function __invoke(array $input): array
    {
        $notes = [];
        foreach ($this->session->files() as $file) {
            $content = @file_get_contents($this->session->notePath($file));
            $notes[] = ['file' => $file, 'chars' => $content === false ? null : mb_strlen($content)];
        }

        return [
            'count' => count($notes),
            'notes' => $notes,
            'unassigned' => $this->session->unassigned(),
        ];
    }
}
