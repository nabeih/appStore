<section class="sale-panel" data-period-profitability>
    <header class="sale-header"><h2>نتيجة الفترة التشغيلية</h2><a class="btn btn-outline" href="{{ route('finance.treasury.index', $periodFilters + ['export' => 1]) }}">تصدير تقرير الفترة JSON</a></header>
    <p class="sale-muted">من {{ $periodFilters['from'] }} إلى {{ $periodFilters['to'] }}، للفرع المحدد. التحصيل والسداد والتحويل وأموال المالك لا تنشئ إيرادًا أو مصروفًا جديدًا.</p>
    <div class="sale-summary">
        <div>صافي المبيعات<strong>{{ \App\Support\Sales\Money::format($period['net_sales_minor']) }} ₪</strong></div>
        <div>إيراد التوصيل ورسوم الرفض<strong>{{ \App\Support\Sales\Money::format($period['delivery_revenue_minor']) }} ₪</strong></div>
        <div>المصروفات التشغيلية<strong>{{ \App\Support\Sales\Money::format($period['expenses_minor']) }} ₪</strong></div>
        <div>فروق النقد المعتمدة<strong>{{ \App\Support\Sales\Money::format($period['variance_minor']) }} ₪</strong></div>
        @can('sales.view_cost')
        <div>تكلفة البضاعة المباعة<strong>{{ \App\Support\Sales\Money::format($period['cogs_minor']) }} ₪</strong></div>
        <div>مجمل الربح<strong>{{ $completeness['complete'] ? \App\Support\Sales\Money::format($period['gross_profit_minor']) .' ₪' : 'غير معتمد — بيانات غير مكتملة' }}</strong></div>
        <div>هامش مجمل الربح<strong>{{ ! $completeness['complete'] ? 'غير معتمد' : ($period['gross_margin_percent'] === null ? 'لا ينطبق: صافي المبيعات صفر' : $period['gross_margin_percent'].'%') }}</strong></div>
        <div>خسائر المخزون المسجلة<strong>{{ \App\Support\Sales\Money::format($period['inventory_loss_minor']) }} ₪</strong></div>
        <div>نتيجة الفترة التشغيلية<strong>{{ $completeness['complete'] ? \App\Support\Sales\Money::format($period['result_minor']).' ₪' : 'غير معتمدة — راجع الانتقال' }}</strong></div>
        <div>هامش النتيجة التشغيلية<strong>{{ ! $completeness['complete'] ? 'غير معتمد' : ($period['operating_margin_percent'] === null ? 'لا ينطبق: صافي المبيعات صفر' : $period['operating_margin_percent'].'%') }}</strong></div>
        @endcan
    </div>
    @can('sales.view_cost')
    <p class="sale-muted">هامش النتيجة محسوب على صافي المبيعات وإيراد التوصيل معًا. نتيجة العمليات المسجلة تشمل إيراد التوصيل والمصروفات وخسائر المخزون وفروق النقد المعتمدة. ليست صافي الربح المحاسبي الشامل؛ لا تثبت تضمين ضرائب أو إهلاك أو بنود غير مسجلة.</p>
    @if($period['net_sales_minor'] < 0)<p class="sale-notice">صافي المبيعات سالب بسبب حركة الفترة. هامش مجمل الربح محسوب على صافي المنتجات بإشارته، وهامش التشغيل على صافي المنتجات وإيراد التوصيل معًا؛ لا تمثل هذه الحركة هامش بيع اعتياديًا.</p>@endif
    @endcan
</section>
