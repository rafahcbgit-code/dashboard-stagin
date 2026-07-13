<?php

use Modules\FlowBuilder\Services\FlowMetadataParser;

test('single-edge non-interactive nodes still advance automatically', function () {
    $parser = new FlowMetadataParser(json_encode([
        'nodes' => [
            ['id' => '1', 'type' => 'start'],
            ['id' => '2', 'type' => 'text'],
        ],
        'edges' => [
            ['source' => '1', 'target' => '2'],
        ],
    ]));

    expect($parser->getNextNode('1')['id'])->toBe('2');
});

test('single-edge action nodes still advance automatically', function () {
    $parser = new FlowMetadataParser(json_encode([
        'nodes' => [
            ['id' => '1', 'type' => 'action'],
            ['id' => '2', 'type' => 'text'],
        ],
        'edges' => [
            ['source' => '1', 'target' => '2'],
        ],
    ]));

    expect($parser->getNextNode('1')['id'])->toBe('2');
});

test('single-edge button nodes only route when the user selected that button', function () {
    $parser = new FlowMetadataParser(json_encode([
        'nodes' => [
            [
                'id' => '3',
                'type' => 'buttons',
                'data' => [
                    'metadata' => [
                        'fields' => [
                            'type' => 'interactive buttons',
                            'buttons' => [
                                'button1' => 'Book',
                                'button2' => '',
                                'button3' => '',
                            ],
                        ],
                    ],
                ],
            ],
            ['id' => '5', 'type' => 'portal'],
        ],
        'edges' => [
            ['source' => '3', 'sourceHandle' => 'a', 'target' => '5'],
        ],
    ]));

    expect($parser->getNextNode('3', 'Dermatology'))->toBeNull()
        ->and($parser->getNextNode('3', 'Book')['id'])->toBe('5')
        ->and($parser->getNextNode('3', 'button1')['id'])->toBe('5');
});

test('single-edge list nodes only route when the user selected that row', function () {
    $parser = new FlowMetadataParser(json_encode([
        'nodes' => [
            [
                'id' => '3',
                'type' => 'list',
                'data' => [
                    'metadata' => [
                        'fields' => [
                            'type' => 'interactive list',
                            'sections' => [
                                [
                                    'title' => 'Section',
                                    'rows' => [
                                        ['title' => 'Location'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            ['id' => '5', 'type' => 'portal'],
        ],
        'edges' => [
            ['source' => '3', 'sourceHandle' => 'a00', 'target' => '5'],
        ],
    ]));

    expect($parser->getNextNode('3', 'Instagram'))->toBeNull()
        ->and($parser->getNextNode('3', 'Location')['id'])->toBe('5');
});
