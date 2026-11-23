<?php

namespace App\Support\Guidance;

use Illuminate\Validation\Validator;

class ArabicValidator extends Validator
{
    /** Resolve labels without exposing internal field identifiers. */
    public function getDisplayableAttribute($attribute): string
    {
        $label = parent::getDisplayableAttribute($attribute);

        return preg_match('/[\x{0600}-\x{06FF}]/u', $label) ? $label : 'هذا الحقل';
    }
}
