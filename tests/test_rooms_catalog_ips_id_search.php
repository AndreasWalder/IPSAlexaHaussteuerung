<?php
declare(strict_types=1);

/**
 * Live table values use real IP-Symcon object and parent-instance IDs.
 * These are display-only lookup columns; they must never be written
 * back to RoomsCatalog EDIT or influence multi-selection/diff detection.
 */
class IPSModule
{
    private array $attributes = [];
    public array $formUpdates = [];
    public function ReadPropertyInteger(string $name): int { return 0; }
    public function ReadAttributeString(string $name): string { return $this->attributes[$name] ?? ''; }
    public function WriteAttributeString(string $name, string $value): void { $this->attributes[$name] = $value; }
    public function UpdateFormField(string $name, string $field, mixed $value): void
    {
        $this->formUpdates[$name . '.' . $field] = $value;
    }
    public function ReloadForm(): void { throw new RuntimeException('Must not collapse editor'); }
}
function IPS_ScriptExists(int $id): bool { return false; }
function IPS_VariableExists(int $id): bool { return $id >= 90000; }
$GLOBALS['ipsTestObjects'] = [
    // state / toggle belong to the same instance
    91001 => ['ObjectType'=>2,'ObjectParentID'=>51001],
    91002 => ['ObjectType'=>2,'ParentID'=>51001],
    51001 => ['ObjectType'=>1,'ParentID'=>0],
    // A variable can be linked to an instance through a category
    91003 => ['ObjectType'=>2,'ObjectParentID'=>62001],
    62001 => ['ObjectType'=>0,'ObjectParentID'=>51002],
    51002 => ['ObjectType'=>1,'ObjectParentID'=>0],
    // A valid, bare instance may be configured as ID itself
    51003 => ['ObjectType'=>1,'ObjectParentID'=>0],
    // A variable in a category without any instance: blank owner
    91004 => ['ObjectType'=>2,'ObjectParentID'=>62002],
    62002 => ['ObjectType'=>0,'ObjectParentID'=>0]
];
$GLOBALS['ipsTestLookups'] = 0;
function IPS_ObjectExists(int $id): bool {
    return isset($GLOBALS['ipsTestObjects'][$id]);
}
function IPS_GetObject(int $id): array {
    ++$GLOBALS['ipsTestLookups'];
    return $GLOBALS['ipsTestObjects'][$id];
}

require dirname(__DIR__) . '/RoomsCatalogConfigurator/module.php';
function idAssert(bool $valid, string $message): void {
    if (!$valid) throw new RuntimeException($message);
}
function listFromForm(array $form, string $name): array
{
    foreach ($form['elements'] as $panel) {
        foreach ($panel['items'] ?? [] as $item) {
            if (($item['name'] ?? '') === $name) return $item;
        }
    }
    throw new RuntimeException('List ' . $name . ' missing');
}
function rowByKey(array $values, string $key): array
{
    foreach ($values as $row) if (($row['key'] ?? '') === $key) return $row;
    throw new RuntimeException('Row ' . $key . ' missing');
}

$entries = [
    ['selected'=>false, 'roomKey'=>'wohnzimmer', 'roomLabel'=>'Wohnzimmer',
     'domain'=>'licht', 'group'=>'switches', 'key'=>'decke', 'title'=>'Deckenlicht',
     'state'=>91001, 'toggle'=>91002, 'kiEnabled'=>true, 'kiReadOnly'=>true],
    ['selected'=>false, 'roomKey'=>'wohnzimmer', 'roomLabel'=>'Wohnzimmer',
     'domain'=>'jalousie', 'group'=>'jalousie', 'key'=>'fenster', 'title'=>'Jalousie',
     'statusId'=>'91003', 'controlId'=>51003, 'value'=>91004,
     'entityId'=>'blind.wohnzimmer', 'kiEnabled'=>false, 'kiReadOnly'=>true],
    ['selected'=>false, 'roomKey'=>'kueche', 'roomLabel'=>'Küche',
     'domain'=>'licht', 'group'=>'switches', 'key'=>'arbeitsplatte',
     'title'=>'Arbeitslicht', 'state'=>91099, 'toggle'=>0, 'order'=>51001,
     'kiEnabled'=>false, 'kiReadOnly'=>true],
];
$instance = new RoomsCatalogConfigurator();
$instance->WriteAttributeString('RuntimeEntries', json_encode($entries, JSON_THROW_ON_ERROR));
$instance->WriteAttributeString('FilterAI','all');
$form = json_decode($instance->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);

$overview=listFromForm($form,'Entries');
$diff=listFromForm($form,'EntriesEdit');
foreach ([$overview,$diff] as $table) {
    foreach (['ipsObjectIds','ipsInstanceIds'] as $field) {
        $columns=array_values(array_filter(
            $table['columns'], static fn(array $col): bool=>($col['name']??'')===$field
        ));
        idAssert(count($columns)===1,'Missing ID column: '.$field);
        idAssert(($columns[0]['quickFilter']??false)===true,
            $field.' must be searchable via IP-Symcon list quick filter');
        idAssert(($columns[0]['save']??true)===false,
            $field.' must never be editable/stored');
    }
    $light=rowByKey($table['values'],'decke');
    idAssert($light['ipsObjectIds']==='91001, 91002','Object IDs deduplicated and available for search');
    idAssert($light['ipsInstanceIds']==='51001','Same owner instance shown once');
    $blind=rowByKey($table['values'],'fenster');
    idAssert($blind['ipsObjectIds']==='91003, 51003, 91004',
        'String object IDs and instance object IDs shown, in field order');
    idAssert($blind['ipsInstanceIds']==='51002, 51003',
        'Parent categories resolve to the actual instance, not a fabricated variable ID');
    $other=rowByKey($table['values'],'arbeitsplatte');
    idAssert($other['ipsObjectIds']==='91099','Unknown configured IPS object ID can still be searched');
    idAssert($other['ipsInstanceIds']==='','Nonexistent object must not claim an instance');
}

idAssert(!str_contains($overview['onEdit'], "'ipsObjectIds' =>")
    && !str_contains($overview['onEdit'], "'ipsInstanceIds' =>"),
    'IPSList onEdit callback must not persist computed search fields');
idAssert($entries===json_decode($instance->ReadAttributeString('RuntimeEntries'),true),
    'Displaying both ID columns must never mutate RuntimeEntries/EDIT catalog');

$instance->SetRoomFilter('wohnzimmer');
foreach (['Entries.values','EntriesEdit.values'] as $field) {
    idAssert(isset($instance->formUpdates[$field]),'Filter must refresh '.$field.' in place');
    $rows=json_decode($instance->formUpdates[$field],true,512,JSON_THROW_ON_ERROR);
    idAssert(count($rows)===2,'Room filter should preserve both target rows');
    idAssert(rowByKey($rows,'decke')['ipsInstanceIds']==='51001',
        'ID lookup must still work after filtering, no full form reload');
}
idAssert($GLOBALS['ipsTestLookups']>0,'Test must actually traverse IPS_GetObject parent chain');

echo "PASS: RoomsCatalog both tables quick-search real IPS object/instance IDs, no catalog mutation and in-place filters\n";
