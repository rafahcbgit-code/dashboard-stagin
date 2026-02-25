<?php

namespace Modules\MetaFlows\Resources;

use App\Helpers\DateTimeHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MetaFlowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        // Convert timestamps to the organization's configured timezone
        $updatedAt = DateTimeHelper::convertToOrganizationTimezone($this->updated_at);
        $data['updated_at'] = DateTimeHelper::formatDate($updatedAt);

        $createdAt = DateTimeHelper::convertToOrganizationTimezone($this->created_at);
        $data['created_at'] = DateTimeHelper::formatDate($createdAt);

        // Include the response count if it was eager-loaded via withCount()
        $data['responses_count'] = $this->responses_count ?? 0;

        return $data;
    }
}
