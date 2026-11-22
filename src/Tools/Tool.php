<?php

declare(strict_types=1);

namespace NoteAgent\Tools;

/**
 * agent 可以呼叫的工具。工具不認識 API：輸入陣列、回傳陣列，
 * 轉成 tool_use／tool_result 格式由 ToolRegistry（第 4 步）負責。
 */
interface Tool
{
    /**
     * 工具名稱。實作時用 class 常數 NAME 保存，讓其他地方不用建立物件就能引用
     * （interface 無法強制定義常數，所以用方法當作規格）。
     */
    public function name(): string;

    /**
     * 送給 API 的工具定義：name、description、inputSchema。
     *
     * @return array{name: string, description: string, inputSchema: array<string, mixed>}
     */
    public function definition(): array;

    /**
     * @param array<string, mixed> $input 模型傳來的參數（已 JSON 解碼）
     * @return array<string, mixed> 回傳給模型的資料，會被編碼成 JSON
     *
     * @throws ToolInputException 輸入不合法；訊息會回給模型
     */
    public function __invoke(array $input): array;
}
