<?php

declare(strict_types=1);

use Zvizvi\Villament\Support\SchemaSerializer;

/**
 * The drift hash follows what the table IS and ignores how it LOOKS: the page
 * and the data endpoint build the schema in two different requests, and
 * anything decided by the request (a colour, the wording) must not read as a
 * deploy, or the island reloads the page on every fetch.
 */
function versionOf(array $schema): string
{
    return (new SchemaSerializer)->version($schema);
}

function sampleSchema(): array
{
    return [
        'columns' => [
            ['key' => 'name', 'label' => 'Name', 'sortable' => true],
            ['key' => 'email', 'label' => 'Email', 'sortable' => false],
        ],
        'pagination' => ['mode' => 'offset', 'pageOptions' => [10, 25], 'defaultPerPage' => 10],
        'actions' => [
            'record' => [['type' => 'action', 'name' => 'edit']],
            'header' => [
                ['type' => 'action', 'name' => 'create', 'label' => 'New', 'class' => 'fi-btn fi-bg-color-600', 'style' => null, 'iconHtml' => '<svg/>'],
                ['type' => 'group', 'label' => 'More', 'actions' => [
                    ['type' => 'action', 'name' => 'export', 'label' => 'Export', 'class' => 'fi-color-gray', 'tooltip' => 'Export'],
                ]],
            ],
        ],
        'header' => ['heading' => 'Users', 'description' => null, 'actionsPosition' => 'Adaptive'],
        'extraAttributes' => [],
        'strings' => ['nextLabel' => 'Next'],
        'icons' => ['next' => '<svg/>'],
    ];
}

it('ignores colours, classes, wording and icons at any depth', function (): void {
    $restyled = sampleSchema();
    $restyled['actions']['header'][0]['class'] = 'fi-btn fi-bg-color-400';
    $restyled['actions']['header'][0]['label'] = 'חדש';
    $restyled['actions']['header'][1]['actions'][0]['class'] = 'fi-color-primary';
    $restyled['columns'][0]['label'] = 'שם';
    $restyled['header']['heading'] = 'משתמשים';
    $restyled['extraAttributes'] = ['class' => 'fi-ta-density-compact'];
    $restyled['strings']['nextLabel'] = 'הבא';
    $restyled['icons']['next'] = '<svg class="flipped"/>';

    expect(versionOf($restyled))->toBe(versionOf(sampleSchema()));
});

it('moves when the structure moves', function (array $changed): void {
    expect(versionOf($changed))->not->toBe(versionOf(sampleSchema()));
})->with([
    'a column added' => function (): array {
        $schema = sampleSchema();
        $schema['columns'][] = ['key' => 'phone', 'label' => 'Phone', 'sortable' => false];

        return $schema;
    },
    'a column made sortable' => fn (): array => array_replace_recursive(sampleSchema(), ['columns' => [1 => ['sortable' => true]]]),
    'a header action removed' => function (): array {
        $schema = sampleSchema();
        array_shift($schema['actions']['header']);

        return $schema;
    },
    'an action inside a group renamed' => function (): array {
        $schema = sampleSchema();
        $schema['actions']['header'][1]['actions'][0]['name'] = 'download';

        return $schema;
    },
    'the page sizes changed' => function (): array {
        $schema = sampleSchema();
        $schema['pagination']['pageOptions'] = [10, 50];

        return $schema;
    },
]);
