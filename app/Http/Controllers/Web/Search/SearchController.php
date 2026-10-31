<?php

namespace App\Http\Controllers\Web\Search;

use App\Actions\Search\GlobalSearchAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Search\SearchRequest;
use App\Support\Sales\SalesReports;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function index(SearchRequest $request, GlobalSearchAction $search): View
    {
        $filters = $request->validated();
        $results = $search->execute($request->user(), $filters);
        $branches = SalesReports::branches($request->user(), false);

        return view('search.index', compact('results', 'filters', 'branches'));
    }

    public function suggestions(SearchRequest $request, GlobalSearchAction $search): JsonResponse
    {
        $results = $search->execute($request->user(), $request->validated(), true);

        return response()->json(['q' => $results['q'], 'groups' => $results['groups']]);
    }
}
