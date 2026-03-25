<?php

namespace Modules\MetaFlows\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MetaFlow extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'meta_flows';

    protected $guarded = [];

    protected $casts = [
        'categories'           => 'array',
        'google_sheet_mapping' => 'array',
        'google_sheet_enabled' => 'boolean',
    ];

    // A flow can receive many responses when contacts complete it
    public function responses()
    {
        return $this->hasMany(MetaFlowResponse::class, 'meta_flow_id', 'id');
    }

    // Parse the flow's asset_content JSON and return all form field definitions.
    // Traverses screens[].layout.children[], unwraps Form containers, and returns
    // [{name, label, type}] for the input component types used in WhatsApp Flows.
    public function extractFlowFields(): array
    {
        if (!$this->asset_content) {
            return [];
        }

        $inputTypes = ['TextInput', 'TextArea', 'Dropdown', 'CheckboxGroup', 'RadioButtonsGroup', 'DatePicker'];
        $fields     = [];

        try {
            $json    = json_decode($this->asset_content, true);
            $screens = $json['screens'] ?? [];

            foreach ($screens as $screen) {
                $children = $screen['layout']['children'] ?? [];

                foreach ($children as $child) {
                    // Unwrap Form containers
                    $items = ($child['type'] === 'Form') ? ($child['children'] ?? []) : [$child];

                    foreach ($items as $item) {
                        if (in_array($item['type'] ?? '', $inputTypes)) {
                            $fields[] = [
                                'name'  => $item['name'] ?? '',
                                'label' => $item['label'] ?? ($item['name'] ?? ''),
                                'type'  => $item['type'],
                            ];
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Malformed JSON — return empty
        }

        return $fields;
    }

    // Return a paginated list for the given organization, optionally filtered by name
    public function listAll(int $organizationId, ?string $searchTerm)
    {
        return $this->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->when($searchTerm, function ($query) use ($searchTerm) {
                $query->where('name', 'like', '%' . $searchTerm . '%');
            })
            ->withCount(['responses' => fn($q) => $q->whereNotNull('response_json')])
            ->latest()
            ->paginate(10);
    }
}
