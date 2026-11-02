<?php

namespace App\Http\Requests\Mobile;

use App\Http\Requests\Auth\LoginRequest;

class MobileLoginRequest extends LoginRequest
{
    public function rules(): array
    {
        return parent::rules() + ['device_id' => ['required', 'uuid'], 'device_name' => ['required', 'string', 'max:80']];
    }
}
