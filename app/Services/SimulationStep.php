<?php

namespace App\Services;

class SimulationStep
{
    public string $nodeId;
    public string $nodeType;
    public string $category;
    public ?string $description;
    public ?array $whatsapp;
    public ?array $options;

    public function __construct(
        string $nodeId,
        string $nodeType,
        string $category,
        ?string $description = null,
        ?array $whatsapp = null,
        ?array $options = null
    ) {
        $this->nodeId = $nodeId;
        $this->nodeType = $nodeType;
        $this->category = $category;
        $this->description = $description;
        $this->whatsapp = $whatsapp;
        $this->options = $options;
    }

    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'node_type' => $this->nodeType,
            'category' => $this->category,
            'description' => $this->description,
            'whatsapp' => $this->whatsapp,
            'options' => $this->options,
        ];
    }
}