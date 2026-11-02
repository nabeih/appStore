<?php

namespace App\Actions\Branches;

use App\Models\Branch;

class CreateBranchAction
{
    /** @param array{name: string, address?: ?string, phone?: ?string} $data */
    public function execute(array $data): Branch
    {
        $branch = new Branch;
        $branch->forceFill(['name' => $data['name'], 'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null, 'type' => 'shop', 'is_active' => true])->save();

        return $branch;
    }
}
