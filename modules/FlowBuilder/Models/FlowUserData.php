<?php

namespace Modules\FlowBuilder\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FlowUserData extends Model
{
    use HasFactory;

    protected $guarded = [];
    public $timestamps = true;

    protected $casts = [
        'variables' => 'array',
    ];

    public function incrementStep()
    {
        $this->current_step += 1;
        $this->save();
    }

    public function setVariable(string $key, mixed $value): void
    {
        $vars = $this->variables ?? [];
        $vars[$key] = $value;
        $this->variables = $vars;
        $this->save();
    }

    public function getVariable(string $key, mixed $default = null): mixed
    {
        return ($this->variables ?? [])[$key] ?? $default;
    }
}