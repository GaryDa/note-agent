# 工具設計

agent 只能透過這三個工具接觸筆記。工具定義（名稱、說明、input schema）會原樣送給模型，模型完全依靠這些文字決定什麼時候呼叫、怎麼填參數，所以說明要寫得像給新同事的 API 文件。

| 工具 | 作用 | 唯讀 |
|---|---|---|
| `list_notes` | 列出所有筆記的檔名和字數 | 是 |
| `read_note` | 讀一份筆記的內容 | 是 |
| `write_category` | 建立或覆寫一個分類，寫出分類檔 | 否 |

## 設計原則

1. **工具少而精，以任務為單位。** 不提供「讀任意檔案」「執行指令」這種萬用工具，只提供這個任務需要的三個動作。
2. **規則盡量由工具強制執行，不靠 prompt。** 完成條件 1（每份筆記只歸一類）、2（最多 6 類）、4（理由不可空白）、6（只能寫入 `out/`），都在 `write_category` 裡直接擋下，模型想違反也做不到。
3. **錯誤訊息要讓模型知道下一步怎麼做。** 每個錯誤都說明原因和修正方式，例如「找不到 X；請先呼叫 list_notes 確認檔名」。
4. **回傳精簡的 JSON。** 只回傳模型做決定需要的資訊，順便回報進度（還有哪些筆記沒分類），模型不用自己記。
5. **工具不認識 API。** 每個工具是普通的 PHP class，輸入陣列、回傳陣列；轉成 API 格式由 `ToolRegistry` 負責，方便單獨測試。

## 共用狀態

三個工具共用一個 `NoteSession` 物件，保存：
- 筆記資料夾路徑與檔名清單（啟動時掃描一次，之後不變）
- 目前的分類結果：`分類名稱 => [檔名 => 理由]`
- 輸出資料夾 `out/`

`write_category` 更新分類結果並寫檔；`list_notes` 和 `write_category` 的回傳都從這裡算出「已分類／未分類」。

---

## `list_notes`

**說明（給模型）：**

> 列出要分類的所有筆記，回傳每份筆記的檔名和字數，以及目前還沒分類的筆記。開始分類前先呼叫一次；不確定檔名時也可以再呼叫。這個工具不回傳內容，要看內容請用 read_note。

**input schema：** 沒有參數。

```php
[
    'name' => 'list_notes',
    'description' => '（上面的說明）',
    'inputSchema' => [
        'type' => 'object',
        'properties' => new \stdClass(),   // 空物件；用 [] 會被編碼成 JSON 陣列
        'additionalProperties' => false,
    ],
]
```

**回傳：**

```json
{
  "count": 8,
  "notes": [
    { "file": "agent-loop.txt", "chars": 46 },
    { "file": "週末採買.txt", "chars": 17 }
  ],
  "unassigned": ["agent-loop.txt", "週末採買.txt"]
}
```

**錯誤：** 沒有（筆記數量不在 2–50 份時，程式啟動就會結束，不會進到 agent）。

---

## `read_note`

**說明（給模型）：**

> 讀取一份筆記的完整內容。file 必須是 list_notes 回傳的檔名，只能是檔名，不能包含路徑。一次讀一份；要讀多份時，可以在同一回合同時呼叫多次。內容超過 8,000 字時只回傳前 8,000 字，truncated 會是 true。

**input schema：**

```php
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
```

**回傳：**

```json
{ "file": "agent-loop.txt", "content": "agent loop：呼叫模型……", "chars": 46, "truncated": false }
```

**錯誤：**

| 情況 | 回給模型的訊息 |
|---|---|
| 檔名含 `/`、`\`、`..` | `file 只能是檔名，不能包含路徑：{file}` |
| 檔名不在清單裡 | `找不到筆記 {file}；請呼叫 list_notes 確認正確的檔名` |
| 讀檔失敗（權限、被刪除） | `無法讀取 {file}，請略過這份筆記並在最後說明` |

**為什麼一次只讀一份：** 也可以設計成一次讀多份（參數是陣列），回合數會更少。這裡刻意一次一份，讓模型在同一回合**平行呼叫**多次 `read_note`，第 4 步寫 loop 時才會練到「多個 `tool_result` 放在同一則訊息」。

---

## `write_category`

**說明（給模型）：**

> 建立一個分類並寫出分類檔；如果同名分類已經存在，就整個覆寫（原本在這個分類、這次沒列出的筆記會變回未分類）。每份筆記只能屬於一個分類，最多 6 個分類。每份筆記都要附一句理由，說明為什麼歸到這一類。回傳寫出的檔案，以及目前還沒分類的筆記；unassigned 是空的就代表全部分類完成。

**input schema：**

```php
'inputSchema' => [
    'type' => 'object',
    'properties' => [
        'name' => [
            'type' => 'string',
            'description' => '分類名稱，1 到 40 字，例如「PHP 環境設定」；會直接當成檔名，不能包含 / \ : * ? " < > |',
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
```

**回傳：**

```json
{
  "written": "out/PHP 環境設定.md",
  "category_count": 2,
  "assigned": 5,
  "unassigned": ["週末採買.txt", "今晚待辦.txt", "hook與權限.txt"]
}
```

**錯誤**（任何一項失敗就整個不寫，分類結果維持原狀）：

| 情況 | 回給模型的訊息 |
|---|---|
| 名稱是空的、超過 40 字，或去掉禁用字元後是空的 | `分類名稱要 1 到 40 字，且不能只有特殊字元` |
| 去掉禁用字元後跟另一個分類撞名 | `分類名稱「{name}」跟已存在的「{other}」會產生同一個檔名，請換一個名稱` |
| 已經有 6 個分類，又要新增第 7 個 | `已經有 6 個分類（{列出名稱}），不能再新增；請把筆記歸到現有分類，或用同名覆寫來合併` |
| 檔名不在清單裡 | `找不到筆記 {file}；請呼叫 list_notes 確認正確的檔名` |
| 同一次呼叫裡重複列了同一份筆記 | `{file} 在這次的清單裡出現了兩次` |
| 筆記已經屬於另一個分類 | `{file} 已經在分類「{other}」裡；一份筆記只能屬於一個分類。要搬移的話，先用同名覆寫把它從「{other}」移除` |
| 理由是空白 | `{file} 的理由是空的，請寫一句說明` |

**檔名規則**（照 README）：去掉 `/ \ : * ? " < > |` 和開頭的 `.`，前後空白去掉，最長 40 字，加上 `.md`。寫入前確認最終路徑在 `out/` 底下。

**為什麼「已經在別的分類」要擋下，而不是自動搬過去：** 自動搬移的話，模型可能不小心把別的分類裡的筆記拉走，自己卻不知道。擋下並說明怎麼搬，讓每次變動都是模型明確決定的。

---

## 沒有做的工具，以及為什麼

| 想過的工具 | 為什麼不做 |
|---|---|
| `finish`（宣告完成） | 模型回 `end_turn` 就代表結束；是否真的完成由程式事後檢查（完成條件），不讓模型自己宣告 |
| `delete_category` | 用同名覆寫就能調整內容；真的要刪分類時，把筆記全部搬走後，空分類由程式在結束時檢查出來 |
| `list_notes` 附內容預覽 | 筆記都很短，附上預覽的話模型不用讀檔就能分類，練不到工具呼叫；正式產品可以考慮加 |
| `read_notes`（一次讀多份） | 見 `read_note` 的說明，刻意讓模型平行呼叫 |

## 第 3 步要實作的 class

```
src/NoteSession.php          共用狀態
src/Tools/ListNotes.php      __invoke(array $input): array
src/Tools/ReadNote.php
src/Tools/WriteCategory.php
src/Tools/ToolInputException.php   錯誤訊息直接回給模型的 exception
```
