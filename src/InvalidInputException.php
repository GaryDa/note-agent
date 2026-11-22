<?php

declare(strict_types=1);

namespace NoteAgent;

/**
 * 程式啟動時的輸入錯誤（資料夾不存在、筆記數量不在範圍內）。
 * 發生在呼叫模型之前，對應結束碼 2。
 */
final class InvalidInputException extends \RuntimeException
{
}
