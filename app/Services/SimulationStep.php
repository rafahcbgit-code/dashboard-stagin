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

    // Enhanced debug fields
    public ?array $variablesSet = null;
    public ?array $conditionResult = null;
    public ?array $validationRules = null;

    public function __construct(
        string $nodeId,
        string $nodeType,
        string $category,
        ?string $description = null,
        ?array $whatsapp = null,
        ?array $options = null,
        ?array $variablesSet = null,
        ?array $conditionResult = null,
        ?array $validationRules = null
    ) {
        $this->nodeId = $nodeId;
        $this->nodeType = $nodeType;
        $this->category = $category;
        $this->description = $description;
        $this->whatsapp = $whatsapp;
        $this->options = $options;
        $this->variablesSet = $variablesSet;
        $this->conditionResult = $conditionResult;
        $this->validationRules = $validationRules;
    }

    public function toArray(): array
    {
        $arr = [
            'node_id' => $this->nodeId,
            'node_type' => $this->nodeType,
            'category' => $this->category,
            'description' => $this->description,
            'whatsapp' => $this->whatsapp,
            'options' => $this->options,
        ];

        if ($this->variablesSet !== null) {
            $arr['variables_set'] = $this->variablesSet;
        }
        if ($this->conditionResult !== null) {
            $arr['condition_result'] = $this->conditionResult;
        }
        if ($this->validationRules !== null) {
            $arr['validation_rules'] = $this->validationRules;
        }

        return $arr;
    }
}