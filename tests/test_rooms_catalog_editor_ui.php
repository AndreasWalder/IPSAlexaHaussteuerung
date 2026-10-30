<?php
declare(strict_types=1);

class IPSModule
{
    private array $attributes = [];
    public array $updatedFields = [];
    public function ReadPropertyInteger(string $name): int { return 0; }
    public function ReadAttributeString(string $name): string { return $this->attributes[$name] ?? ''; }
    public function WriteAttributeString(string $name, string $value): void { $this->attributes[$name] = $value; }
    public function UpdateFormField(string $name, string $field, mixed $value): void
    {
        $this->updatedFields[$name . '.' . $field] = $value;
    }
    public function ReloadForm(): void { throw new RuntimeException('ReloadForm would collapse the editor'); }
}
function IPS_ScriptExists(int $id): bool { return false; }
function IPS_VariableExists(int $id): bool { return $id === 91001 || $id === 91002; }
require dirname(__DIR__) . '/RoomsCatalogConfigurator/module.php';
function assertEditor(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
function namesInDialog(array $form): array
{
    $result = [];
    $visit = function (array $items) use (&$visit, &$result): void {
        foreach ($items as $item) {
            if (isset($item['name'])) $result[] = $item['name'];
            if (isset($item['items']) && is_array($item['items'])) $visit($item['items']);
        }
    };
    $visit($form);
    return $result;
}
$instance = new RoomsCatalogConfigurator();
$row = [
    'selected' => false, 'roomKey' => 'testraum', 'roomLabel' => 'Testraum',
    'floor' => 'EG', 'domain' => 'licht', 'group' => 'switches',
    'key' => 'decke', 'title' => 'Testlampe',
    'state' => 91001, 'toggle' => 91002, 'kiEnabled' => true, 'kiReadOnly' => true
];
$instance->WriteAttributeString('RuntimeEntries', json_encode([$row], JSON_THROW_ON_ERROR));
$instance->WriteAttributeString('FilterAI', 'all');
$form = json_decode($instance->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
$panel = null;
foreach ($form['elements'] as $element) {
    if (($element['type'] ?? '') === 'ExpansionPanel' && str_contains($element['caption'] ?? '', 'Geraete bearbeiten')) {
        $panel = $element; break;
    }
}
assertEditor(is_array($panel) && ($panel['expanded'] ?? false) === true, 'Editor must start expanded');
$list = null;
foreach ($panel['items'] as $item) {
    if (($item['name'] ?? '') === 'Entries') { $list = $item; break; }
}
assertEditor(is_array($list), 'Main list missing');
assertEditor(str_contains($list['form'], '$Entries["roomKey"]'), 'Selected row key missing in dynamic form');
assertEditor(!str_contains($list['form'], '$id, $Entries)'), 'Whole list incorrectly passed to dynamic form');
assertEditor(str_contains($list['onAdd'], 'ARRAY_FILTER_USE_KEY'), 'onAdd must save selected row only');

$edit = json_decode($instance->GetEntryEditForm('testraum', 'licht', 'switches', 'decke'), true, 512, JSON_THROW_ON_ERROR);
$editNames = namesInDialog($edit);
assertEditor(in_array('kiEnabled', $editNames, true) && in_array('toggle', $editNames, true), 'Existing row edit form incomplete');
$add = json_decode($instance->GetEntryEditForm(), true, 512, JSON_THROW_ON_ERROR);
$addNames = namesInDialog($add);
assertEditor(in_array('roomKey', $addNames, true) && in_array('state', $addNames, true)
    && in_array('toggle', $addNames, true), 'New device form missing fields');

$instance->SetRoomFilter('testraum');
assertEditor(isset($instance->updatedFields['Entries.values']), 'Filtering should update existing list');
assertEditor(isset($instance->updatedFields['EntriesEdit.values']), 'Diff must update in-place');
assertEditor(isset($instance->updatedFields['CatalogSummary.caption']), 'Summary must update in-place');
echo "PASS RoomsCatalog editor: static expansion, add/edit forms, non-collapsing filter updates\n";
