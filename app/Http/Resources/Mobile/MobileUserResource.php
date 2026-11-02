<?php

namespace App\Http\Resources\Mobile;

use App\Support\Sales\SalesReports;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('role.permissions');

        return ['id' => $this->id, 'name' => $this->name, 'username' => $this->username,
            'role' => $this->role?->name, 'permissions' => $this->role?->permissions->pluck('name')->sort()->values()->all() ?? [],
            'branches' => SalesReports::branches($this->resource, false)->map(fn ($branch): array => ['id' => $branch->id, 'name' => $branch->name, 'is_active' => $branch->is_active])->all(),
            'schema_version' => 1];
    }
}
