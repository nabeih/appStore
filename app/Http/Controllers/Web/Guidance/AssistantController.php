<?php

namespace App\Http\Controllers\Web\Guidance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guidance\AskAssistantRequest;
use App\Support\Guidance\AssistantService;
use App\Support\Guidance\GuidanceKnowledgeBase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AssistantController extends Controller
{
    public function index(Request $request, GuidanceKnowledgeBase $knowledge): View
    {
        return view('guidance.index', ['topics' => $knowledge->allowed($request->user())]);
    }

    public function ask(AskAssistantRequest $request, AssistantService $assistant): JsonResponse
    {
        return response()->json($assistant->answer($request->user(), $request->validated()))->header('Cache-Control', 'no-store, private');
    }
}
