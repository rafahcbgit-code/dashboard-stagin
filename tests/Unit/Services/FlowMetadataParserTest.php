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

test('interactive list replies route by stable row id even when the visible title changes', function () {
    $parser = new FlowMetadataParser(json_encode([
        'nodes' => [
            [
                'id' => '7',
                'type' => 'list',
                'data' => [
                    'metadata' => [
                        'fields' => [
                            'type' => 'interactive list',
                            'sections' => [[
                                'rows' => [
                                    ['id' => '1', 'title' => 'خدمة في الصالون'],
                                    ['id' => '2', 'title' => 'خدمة منزلية'],
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
            ['id' => '8', 'type' => 'text'],
            ['id' => '9', 'type' => 'text'],
        ],
        'edges' => [
            ['source' => '7', 'sourceHandle' => 'a00', 'target' => '8'],
            ['source' => '7', 'sourceHandle' => 'a01', 'target' => '9'],
        ],
    ]));

    expect($parser->getNextNodeForInteractiveReply('7', '1', 'عنوان مختلف')['id'])->toBe('8')
        ->and($parser->getNextNodeForInteractiveReply('7', '2', '')['id'])->toBe('9');
});

test('interactive list rejects an unknown id even when its title matches a configured row', function () {
    $parser = new FlowMetadataParser(json_encode([
        'nodes' => [
            [
                'id' => '7',
                'type' => 'list',
                'data' => [
                    'metadata' => [
                        'fields' => [
                            'type' => 'interactive list',
                            'sections' => [[
                                'rows' => [['id' => '1', 'title' => 'خدمة في الصالون']],
                            ]],
                        ],
                    ],
                ],
            ],
            ['id' => '8', 'type' => 'text'],
        ],
        'edges' => [
            ['source' => '7', 'sourceHandle' => 'a00', 'target' => '8'],
        ],
    ]));

    expect($parser->getNextNodeForInteractiveReply('7', 'unknown', 'خدمة في الصالون'))->toBeNull();
});

test('legacy list nodes without row ids retain title based routing', function () {
    $parser = new FlowMetadataParser(json_encode([
        'nodes' => [
            [
                'id' => '7',
                'type' => 'list',
                'data' => [
                    'metadata' => [
                        'fields' => [
                            'type' => 'interactive list',
                            'sections' => [[
                                'rows' => [['title' => 'خدمة في الصالون']],
                            ]],
                        ],
                    ],
                ],
            ],
            ['id' => '8', 'type' => 'text'],
        ],
        'edges' => [
            ['source' => '7', 'sourceHandle' => 'a00', 'target' => '8'],
        ],
    ]));

    expect($parser->getNextNodeForInteractiveReply('7', 'legacy-payload', 'خدمة في الصالون')['id'])
        ->toBe('8');
});

test('interactive button replies route by button payload id', function () {
    $parser = new FlowMetadataParser(json_encode([
        'nodes' => [
            [
                'id' => '3',
                'type' => 'buttons',
                'data' => [
                    'metadata' => [
                        'fields' => [
                            'type' => 'interactive buttons',
                            'buttonType' => 'buttons',
                            'buttons' => [
                                'button1' => 'نعم',
                                'button2' => 'لا',
                                'button3' => '',
                            ],
                        ],
                    ],
                ],
            ],
            ['id' => '4', 'type' => 'text'],
            ['id' => '5', 'type' => 'text'],
        ],
        'edges' => [
            ['source' => '3', 'sourceHandle' => 'a', 'target' => '4'],
            ['source' => '3', 'sourceHandle' => 'b', 'target' => '5'],
        ],
    ]));

    expect($parser->getNextNodeForInteractiveReply('3', 'button1', 'عنوان متغير')['id'])->toBe('4')
        ->and($parser->getNextNodeForInteractiveReply('3', 'button2', '')['id'])->toBe('5');
});
