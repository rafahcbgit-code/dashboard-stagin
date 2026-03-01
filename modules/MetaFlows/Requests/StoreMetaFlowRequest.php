<?php

namespace Modules\MetaFlows\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMetaFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => 'required|string|max:255',
            'categories'   => 'required|array|min:1',
            'categories.*' => 'string|in:SIGN_UP,SIGN_IN,APPOINTMENT_BOOKING,LEAD_GENERATION,CONTACT_US,CUSTOMER_SUPPORT,SURVEY,OTHER',
            'endpoint_uri' => 'nullable|url|max:2048',
        ];
    }
}
