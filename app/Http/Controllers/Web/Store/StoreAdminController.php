<?php

namespace App\Http\Controllers\Web\Store;

use App\Actions\Catalog\ProcessProductImageAction;
use App\Actions\Search\ModuleSearch;
use App\Actions\Search\SearchTerm;
use App\Actions\Store\ManageStoreContent;
use App\Actions\Store\StoreContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreContentRequest;
use App\Models\Branch;
use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\StoreSection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class StoreAdminController extends Controller
{
    public function index(Request $request, string $type, ManageStoreContent $content): View
    {
        abort_unless(app(StoreContext::class)->installed(), 503, 'يلزم اعتماد تطبيق ترحيل الموقع.');
        $model = $content->model($type);
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $query = $model->newQuery();
        if ($type === 'products') {
            $query->with('product')->whereHas('product', fn ($q) => app(ModuleSearch::class)->products($q, $input));
        } elseif (filled($input['q'] ?? null) && $type !== 'settings') {
            SearchTerm::apply($query, $input['q'], [$type === 'banners' ? 'title' : 'name']);
        }

        return view('store.admin.content.index', ['entries' => $query->orderBy('id')->paginate(20)->withQueryString(), 'type' => $type, 'title' => $content::TITLES[$type]]);
    }

    public function form(string $type, ManageStoreContent $content, ?int $record = null): View
    {
        abort_unless(app(StoreContext::class)->installed(), 503);
        $model = $content->model($type);
        $entry = $record ? $model->newQuery()->findOrFail($record) : ($type === 'settings' ? $model->newQuery()->find(1) ?? $model : $model);

        return view('store.admin.content.form', ['entry' => $entry, 'type' => $type, 'title' => $content::TITLES[$type],
            'products' => in_array($type, ['products', 'offers'], true) ? Product::orderBy('name')->get(['id', 'name', 'code']) : collect(),
            'sections' => StoreSection::orderBy('sort_order')->get(['id', 'name']),
            'zones' => DeliveryZone::orderBy('name')->get(['id', 'name']), 'branches' => Branch::where('is_active', true)->get(['id', 'name']),
            'categories' => Category::orderBy('name')->get(['id', 'name'])]);
    }

    public function save(StoreContentRequest $request, string $type, ManageStoreContent $content, ?int $record = null): RedirectResponse
    {
        $data = $request->validated();
        $paths = null;
        unset($data['image']);
        try {
            if ($request->hasFile('image')) {
                $paths = app(ProcessProductImageAction::class)->upload($request->file('image'));
                $data += $paths;
            }
            $content->save($type, $data, $request->user(), $record);
        } catch (\Throwable $error) {
            if ($paths) {
                Storage::disk('public')->delete(array_values($paths));
            }
            throw $error;
        }

        return redirect()->route('store.admin.content.index', $type)->with('success', 'حُفظت البيانات بنجاح؛ المستندات السابقة احتفظت بقيمها.');
    }
}
