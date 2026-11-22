<?php

declare(strict_types=1);

namespace NoteAgent\Tests\Tools;

use NoteAgent\Tests\TempNotesTestCase;
use NoteAgent\Tools\ListNotes;
use NoteAgent\Tools\ReadNote;
use NoteAgent\Tools\Tool;
use NoteAgent\Tools\WriteCategory;

final class ToolNamesTest extends TempNotesTestCase
{
    public function testNameAndDefinitionUseTheNameConstant(): void
    {
        $session = $this->session();
        $tools = [
            ListNotes::NAME => new ListNotes($session),
            ReadNote::NAME => new ReadNote($session),
            WriteCategory::NAME => new WriteCategory($session),
        ];

        foreach ($tools as $name => $tool) {
            self::assertInstanceOf(Tool::class, $tool);
            self::assertSame($name, $tool->name());
            self::assertSame($name, $tool->definition()['name']);
        }
    }
}
