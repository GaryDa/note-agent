<?php

declare(strict_types=1);

namespace NoteAgent\Tools;

use NoteAgent\NoteSession;

final class ReadNote implements Tool
{
    public const int MAX_CHARS = 8000;

    public function __construct(private readonly NoteSession $session)
    {
    }

    public function name(): string
    {
        return 'read_note';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => '讀取一份筆記的完整內容。file 必須是 list_notes 回傳的檔名，只能是檔名，不能包含路徑。'
                . '一次讀一份；要讀多份時，可以在同一回合同時呼叫多次。'
                . '內容超過 ' . number_format(self::MAX_CHARS) . ' 字時只回傳前 ' . number_format(self::MAX_CHARS)
                . ' 字，truncated 會是 true。',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'file' => [
                        'type' => 'string',
                        'description' => '筆記檔名，例如 agent-loop.txt；必須是 list_notes 回傳的檔名',
                    ],
                ],
                'required' => ['file'],
                'additionalProperties' => false,
            ],
        ];
    }

    public function __invoke(array $input): array
    {
        $file = $input['file'] ?? null;
        if (!is_string($file) || $file === '') {
            throw new ToolInputException('缺少 file 參數；請傳入 list_notes 回傳的檔名');
        }
        if (str_contains($file, '/') || str_contains($file, '\\') || str_contains($file, '..')) {
            throw new ToolInputException("file 只能是檔名，不能包含路徑：{$file}");
        }
        if (!$this->session->hasFile($file)) {
            throw new ToolInputException("找不到筆記 {$file}；請呼叫 list_notes 確認正確的檔名");
        }

        $content = @file_get_contents($this->session->notePath($file));
        if ($content === false) {
            throw new ToolInputException("無法讀取 {$file}，請略過這份筆記並在最後說明");
        }

        $chars = mb_strlen($content);
        return [
            'file' => $file,
            'content' => mb_substr($content, 0, self::MAX_CHARS),
            'chars' => $chars,
            'truncated' => $chars > self::MAX_CHARS,
        ];
    }
}
