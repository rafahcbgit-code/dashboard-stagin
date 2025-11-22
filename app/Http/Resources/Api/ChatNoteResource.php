<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatNoteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        // Get creator information
        $creator = \App\Models\User::find($this->created_by);

        return [
            'uuid' => $this->uuid,
            'content' => $this->content,
            'created_by' => $creator ? [
                'id' => $creator->id,
                'uuid' => $creator->uuid,
                'first_name' => $creator->first_name,
                'last_name' => $creator->last_name,
                'full_name' => trim($creator->first_name . ' ' . $creator->last_name),
                'email' => $creator->email,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
