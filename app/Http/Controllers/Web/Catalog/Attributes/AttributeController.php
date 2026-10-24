<?php

namespace App\Http\Controllers\Web\Catalog\Attributes;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Color;
use App\Models\Size;
use App\Support\Catalog\ColorPalette;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AttributeController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['tab' => ['nullable', Rule::in(['categories', 'colors', 'sizes'])], 'q' => ['nullable', 'string', 'max:100']]);
        $tab = $filters['tab'] ?? 'categories';
        $search = trim($filters['q'] ?? '');
        $query = match ($tab) {
            'colors' => Color::query()->withCount('variants')->latest('id'),
            'sizes' => Size::query()->withCount('variants')->orderBy('sort_order')->latest('id'),
            default => Category::query()->with('parent:id,name')->withCount('products')->latest('id'),
        };
        if ($search !== '') {
            $query->where(function ($query) use ($tab, $search) {
                $query->where('name', 'like', '%'.$search.'%');
                if ($tab !== 'categories') {
                    $query->orWhere('code', 'like', '%'.$search.'%');
                }
            });
        }
        $entries = $query->paginate(20)->withQueryString();
        $counts = ['categories' => Category::count(), 'colors' => Color::count(), 'sizes' => Size::count()];
        $parents = $tab === 'categories' ? Category::with('parent:id,name')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'parent_id']) : collect();
        $palette = ColorPalette::presets();

        return view('catalog.attributes.index', compact('tab', 'search', 'entries', 'counts', 'parents', 'palette'));
    }
}
