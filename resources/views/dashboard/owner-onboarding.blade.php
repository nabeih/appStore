@if($onboarding)
    @push('styles')
        <link rel="stylesheet" href="{{ asset('assets/admin/css/pages/store/owner-onboarding.css') }}">
    @endpush
    <section class="commerce-card owner-onboarding" dir="rtl" aria-labelledby="owner-onboarding-title" data-owner-onboarding>
        <header class="commerce-header">
            <div>
                <h2 id="owner-onboarding-title">{{ $onboarding['summary'] ? 'ملخص جاهزية متجرك' : 'أهلًا بك! جهّز متجرك لأول بيع' }}</h2>
                <p>الفرع: {{ $onboarding['branch'] }} — {{ match($onboarding['state']) { 'full' => 'تشغيل كامل', 'progressive' => 'تشغيل تدريجي', default => 'إعداد فقط' } }}</p>
            </div>
            <a class="btn btn-outline" href="{{ $onboarding['help_url'] }}">اسأل مساعد المتجر</a>
        </header>
        <label for="owner-onboarding-progress">{{ $onboarding['complete'] }} من {{ $onboarding['total'] }} خطوات أساسية — {{ $onboarding['percent'] }}٪</label>
        <progress id="owner-onboarding-progress" max="100" value="{{ $onboarding['percent'] }}">{{ $onboarding['percent'] }}٪</progress>
        <p class="commerce-muted">التقدم محسوب من البيانات الفعلية للفرع؛ بيانات الموقع والموردون اختياريان لبدء بيع البضاعة الافتتاحية. لا تُنشئ هذه اللوحة أرصدة أو حركات.</p>
        @if($onboarding['ready'])
            <p class="commerce-notice">الكاشير جاهز للأصناف وحسابات القبض التي اجتازت الفحص؛ يُعاد التحقق عند كل بيع.</p>
        @else
            <div class="commerce-notice" role="status"><strong>ما الذي ينقصك قبل البيع؟</strong>
                <ul>@foreach($onboarding['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>
            </div>
        @endif
        @if($onboarding['next'])
            <p><strong>الخطوة التالية: {{ $onboarding['next']['title'] }}</strong>
                @if($onboarding['next']['url'])<a href="{{ $onboarding['next']['url'] }}">افتح الشاشة ←</a>@endif
            </p>
        @endif
        <details @if(!$onboarding['summary']) open @endif>
            <summary>{{ $onboarding['summary'] ? 'مراجعة خطوات التجهيز' : 'خطوات البداية بالترتيب' }}</summary>
            <ol class="owner-onboarding-steps">
                @foreach($onboarding['steps'] as $step)
                    <li data-onboarding-step="{{ $step['key'] }}">
                        <div><strong>{{ $step['title'] }}</strong>
                            <span class="owner-onboarding-state">{{ $step['complete'] ? 'مكتمل' : ($step['optional'] ? 'اختياري' : 'بحاجة لإكمال') }}</span>
                        </div>
                        <p>{{ $step['description'] }}</p>
                        @if($step['url'])<a href="{{ $step['url'] }}">{{ $step['complete'] ? 'مراجعة' : 'افتح الشاشة' }} ←</a>
                        @else<span class="commerce-muted">{{ $step['key'] === 'sale' && !$onboarding['ready'] ? 'أكمل الجاهزية أولًا.' : 'الرابط غير متاح وفق صلاحياتك.' }}</span>@endif
                    </li>
                @endforeach
            </ol>
        </details>
    </section>
@endif
