<?php

namespace App\Http\Resources;

use App\Helpers\DateTimeHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        $data['updated_at'] = DateTimeHelper::formatDate($this->updated_at);
        $data['created_at'] = DateTimeHelper::formatDate($this->created_at);
        $data['last_login_at'] = $this->last_login_at
            ? DateTimeHelper::formatCompanyDate($this->last_login_at)
            : null;

        return $data;
    }
}
