<?php

declare(strict_types=1);

namespace NoteAgent\Tools;

/**
 * 工具收到不合法的輸入。訊息會原樣回給模型（tool_result 的錯誤內容），
 * 所以要寫成模型看得懂、知道下一步怎麼修正的句子。
 */
final class ToolInputException extends \RuntimeException
{
}
