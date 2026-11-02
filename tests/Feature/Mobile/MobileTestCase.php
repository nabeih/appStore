<?php

namespace Tests\Feature\Mobile;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Sales\SalesTestCase;

abstract class MobileTestCase extends SalesTestCase
{
    protected string $deviceId;

    protected string $mobileToken;

    protected function mobileLogin(?User $actor = null, ?string $device = null): array
    {
        $actor ??= $this->owner;
        $actor->forceFill(['password' => 'MobilePass!123'])->save();
        Auth::forgetGuards();
        $response = $this->postJson('/api/v1/auth/login', ['username' => $actor->username, 'password' => 'MobilePass!123',
            'device_id' => $device ?? (string) Str::uuid(), 'device_name' => 'Isolated test phone'])->assertOk();
        $this->deviceId = $response->json('device_id');
        $this->mobileToken = $response->json('token');

        return $response->json();
    }

    protected function mobile(string $method, string $uri, array $data = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->json($method, '/api/v1/'.$uri, $data, ['Authorization' => 'Bearer '.$this->mobileToken]);
    }

    protected function operation(string $type, array $payload, array $dependencies = [], ?int $branch = null): array
    {
        return ['operation_uuid' => (string) Str::uuid(), 'operation_type' => $type, 'client_created_at' => now()->subSecond()->utc()->toISOString(),
            'branch_id' => $branch ?? $this->branchId, 'payload' => $payload, 'dependencies' => $dependencies];
    }

    protected function push(array $operations): TestResponse
    {
        return $this->mobile('POST', 'sync/push', ['schema_version' => 1, 'operations' => $operations])->assertOk();
    }

    protected function productPayload(): array
    {
        $variant = $this->stock('35', '20', 2, 'REFERENCE');

        return ['client_uuid' => (string) Str::uuid(), 'name' => 'منتج الهاتف', 'category_id' => $variant->product->category_id,
            'variants' => [['client_uuid' => (string) Str::uuid(), 'color_id' => $variant->color_id, 'size_id' => $variant->size_id,
                'selling_price' => '35.00', 'minimum_selling_price' => '22.00']]];
    }
}
