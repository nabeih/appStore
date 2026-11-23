@php
    $assistantKnowledge = app(\App\Support\Guidance\GuidanceKnowledgeBase::class);
    $assistantPage = $assistantKnowledge->page(request()->route()?->getName());
    $assistantBranch = filter_var(request('branch_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: auth()->user()->branch_id;
    if ($assistantPage === 'readiness' && isset($branch) && $branch instanceof \App\Models\Branch) { $assistantBranch = $branch->id; }
    if (isset($state) && is_array($state) && isset($state['branch_id'])) { $assistantBranch = (int)$state['branch_id']; }
    if (!auth()->user()->can('sales.all_branches') && (int)$assistantBranch !== (int)auth()->user()->branch_id) { $assistantBranch = auth()->user()->branch_id; }
    $assistantConfig = ['endpoint' => route('assistant.ask', [], false), 'page' => $assistantPage, 'branch_id' => $assistantBranch ? (int)$assistantBranch : null];
    $assistantCode = request()->routeIs('assistant.index') ? request('code') : null;
    $assistantConfig['initial_code'] = is_string($assistantCode) && isset(app(\App\Support\Guidance\GuidanceResolver::class)->definitions()[$assistantCode]) ? $assistantCode : null;
    foreach (request()->route()?->parameters() ?? [] as $assistantDocument) {
        $assistantKind = match (true) {
            $assistantDocument instanceof \App\Models\OpeningStockEntry => 'opening',
            $assistantDocument instanceof \App\Models\PurchaseInvoice => 'purchase',
            $assistantDocument instanceof \App\Models\SaleInvoice => 'sale', default => null,
        };
        if ($assistantKind) { $assistantConfig += ['document_kind' => $assistantKind, 'document_id' => $assistantDocument->id]; $assistantConfig['branch_id'] = (int)$assistantDocument->branch_id; break; }
    }
@endphp
<aside id="store-guidance-notice" class="store-guidance-notice" hidden aria-live="polite"></aside>
<button type="button" id="store-assistant-launch" class="store-assistant-launch" aria-haspopup="dialog" aria-controls="store-assistant-dialog">مساعد المتجر <span aria-hidden="true">؟</span></button>
<dialog id="store-assistant-dialog" class="store-assistant-dialog" aria-labelledby="store-assistant-title" dir="rtl">
    <header><div><h2 id="store-assistant-title">مساعد المتجر</h2><p>اسألني عن خطوة في النظام، أو دعني أساعدك في فهم المشكلة.</p></div><button type="button" data-assistant-close aria-label="إغلاق المساعد">×</button></header>
    <div class="store-assistant-body">
        <p class="store-assistant-privacy">مساعد إرشادي محلي، دون AI خارجي. لا ينفذ عمليات ولا يغيّر بياناتك. لا تكتب كلمات مرور أو تفاصيل زبائن.</p>
        <div id="store-assistant-context"></div>
        <div class="store-assistant-shortcuts"><button type="button" data-assistant-current>اشرح لي هذه الصفحة</button><a href="{{ route('assistant.index') }}">كل مواضيع المساعدة</a></div>
        <section id="store-assistant-answer" aria-live="polite" aria-atomic="true"></section>
        <form id="store-assistant-form"><label for="store-assistant-question">كيف أقدر أساعدك؟</label><textarea data-validation-field="question" id="store-assistant-question" maxlength="500" rows="3" placeholder="مثال: كيف أضيف بضاعة جديدة؟"></textarea><button type="submit" class="btn btn-primary">اسأل المساعد</button></form>
        <p id="store-assistant-status" role="status"></p>
    </div>
</dialog>
<script id="store-assistant-config" type="application/json">{!! \Illuminate\Support\Js::encode($assistantConfig) !!}</script>
@if(session('user_guidance'))<script id="store-assistant-initial-guidance" type="application/json">{!! \Illuminate\Support\Js::encode(session('user_guidance')) !!}</script>@endif
