<section class="sale-panel">
    <h2>مراجعة إنهاء الإعداد واعتماد أرصدة البدء</h2>
    <p class="sale-notice">البيع ممنوع أثناء الإعداد. الإنهاء لا يضيف بضاعة أو دفعات أو قيودًا؛ يعتمد لقطة الأرصدة بعد المطابقة فقط. لا تعتمد رصيدًا مجهولًا كصفر.</p>
    @foreach($setupReports as $setup)
        <details @if(count($setupReports) === 1) open @endif>
            <summary>{{ $setup['branch'] }} — {{ $setup['setup_open'] ? 'الإعداد مفتوح والبيع ممنوع' : 'الإعداد مغلق' }}</summary>
            @if($setup['closure'])
                <p>تاريخ بدء التشغيل المعتمد: {{ $setup['closure']['start_date'] ?? 'غير موثق؛ راجع السجل' }}.
                    اعتمد المستخدم #{{ $setup['closure']['by'] }} الإنهاء في {{ $setup['closure']['at'] }}.
                    لقطة الأرصدة والأدلة محفوظة في سجل التدقيق.</p>
            @endif
            <h3>موانع إنهاء الإعداد</h3>
            @forelse($setup['blockers'] as $blocker)
                <p class="sale-errors">{{ $blocker['message'] }}</p>
            @empty
                <p>اجتازت الأرصدة المطابقة الداخلية؛ يلزم تأكيد شمولها والمطابقة الخارجية وتاريخ البدء.</p>
            @endforelse
            <a class="btn btn-outline" href="{{ route('inventory.review.export', ['branch_id'=>$setup['branch_id'], 'setup'=>1]) }}">تصدير تقرير الأرصدة والمطابقة والأدلة</a>
            <h3>المخزون والدفتر</h3>
            <div class="sale-table-wrap">
                <table class="sale-table sale-mobile-table">
                    <thead><tr><th>الحساب</th><th>قيمة البضاعة</th><th>قيمة الدفتر</th><th>الفرق</th></tr></thead>
                    <tbody>
                    @foreach($setup['reconciliation'] as $code => $match)
                        <tr>
                            <td>{{ $code === 'inventory' ? 'المخزون المتاح' : 'البضاعة قيد الفحص' }}</td>
                            <td data-label="قيمة البضاعة">{{ $match['stock_minor'] === null ? 'غير معتمدة بالكامل' : \App\Support\Sales\Money::format($match['stock_minor']).' ₪' }}</td>
                            <td data-label="الدفتر">{{ \App\Support\Sales\Money::format($match['ledger_minor']) }} ₪</td>
                            <td data-label="الفرق">{{ $match['difference_minor'] === null ? 'لا يمكن حسابه قبل اعتماد القيمة' : \App\Support\Sales\Money::format($match['difference_minor']).' ₪' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <details>
                <summary>مستندات أول المدة المعتمدة والمسودات ({{ count($setup['opening_documents']) }})</summary>
                @forelse($setup['opening_documents'] as $document)
                    <p><a href="{{ route('inventory.opening-stock.show', $document['id']) }}">{{ $document['number'] }}</a>
                        — {{ ['approved'=>'معتمد','draft'=>'مسودة','cancelled'=>'ملغى'][$document['status']] ?? $document['status'] }}
                        — {{ $document['date'] }} — كمية {{ $document['quantity'] }} — قيمة {{ $document['value'] }} ₪</p>
                @empty
                    <p>لا توجد مستندات أول مدة.</p>
                @endforelse
            </details>
            <details>
                <summary>انتقال المشتريات ودفعات الموردين ({{ count($setup['purchases']) }})</summary>
                <p class="sale-muted">حالة الترحيل مستقلة عن استلام البضاعة. يُراجع المستند المصدر؛ لا يُعاد استلامه أو تسجيل دفعاته لحل النقص المالي.</p>
                @foreach($setup['purchases'] as $purchase)
                    <p><a href="{{ route('purchases.invoices.show', $purchase['id']) }}">{{ $purchase['number'] }}</a>
                        — {{ $purchase['supplier'] }} — {{ $purchase['date'] }}
                        — إجمالي {{ \App\Support\Sales\Money::format($purchase['total_minor']) }}
                        — مدفوع {{ \App\Support\Sales\Money::format($purchase['paid_minor']) }}
                        — متبقٍ {{ \App\Support\Sales\Money::format($purchase['due_minor']) }}
                        — {{ $purchase['financial_posted'] ? 'الشراء مرحل' : 'الشراء غير مرحل ماليًا' }}</p>
                    @foreach($purchase['payments'] as $payment)
                        <p class="sale-muted">سند #{{ $payment['id'] }} — {{ \App\Support\Sales\Money::format($payment['amount_minor']) }} ₪
                            — {{ $payment['date'] }} — {{ $payment['financial_posted'] ? 'مرحل ماليًا' : 'غير مرحل؛ يحتاج تحديد الحساب الفعلي ودليل الدفع' }}</p>
                    @endforeach
                @endforeach
                @can('finance.transition_review')
                    <a href="{{ route('finance.transition.index') }}">كشف الانتقال والأدلة المطلوبة</a>
                @endcan
            </details>
            <h3>الصناديق والبنوك والمحافظ</h3>
            @forelse($setup['accounts'] as $account)
                <p>{{ $account['name'] }} — {{ ['cash'=>'صندوق','bank'=>'بنك','wallet'=>'محفظة'][$account['type']] }}
                    — رصيد الدفتر {{ \App\Support\Sales\Money::format($account['ledger_minor']) }} ₪
                    — {{ $account['opening_known'] ? 'يوجد اعتماد رصيد بداية؛ تأكد من مطابقته الخارجية' : 'رصيد البداية مجهول، حتى لو أظهر الدفتر صفرًا' }}</p>
            @empty
                <p class="sale-errors">لا توجد حسابات فعلية معرفة لهذا الفرع.</p>
            @endforelse
            @can('sales.manage_accounts')
                <a class="btn btn-outline" href="{{ route('finance.accounts.index', ['branch_id'=>$setup['branch_id']]) }}">مراجعة الحسابات واعتماد رصيد بداية موثق، بما فيه الصفر</a>
            @endcan
            <h3>ذمم البداية</h3>
            <p>ذمم العملاء في الدفتر: {{ \App\Support\Sales\Money::format($setup['receivables_minor']) }} ₪.
                أرصدة لصالح العملاء: {{ \App\Support\Sales\Money::format($setup['customer_credit_minor']) }} ₪.</p>
            <p class="sale-muted">هذه أرصدة مسجلة وليست تأكيدًا لشمول ذمم قديمة خارج النظام. الصفر لا يعتمد كذمة بداية إلا بعد تأكيد المالك أدناه.</p>
            @foreach($setup['suppliers'] as $supplier)
                <p>المورد #{{ $supplier['supplier_id'] }} — المستحق من الفواتير {{ \App\Support\Sales\Money::format($supplier['operational_minor']) }}
                    — الدفتر {{ \App\Support\Sales\Money::format($supplier['ledger_minor']) }}
                    — الفرق {{ \App\Support\Sales\Money::format($supplier['difference_minor']) }} ₪</p>
            @endforeach
            @if($setup['setup_open'])
                <form action="{{ route('inventory.review.close', $setup['branch_id']) }}" data-simple-form>
                    @include('sales.partials.feedback')
                    <fieldset @disabled(!$setup['can_close'])>
                        <input type="hidden" name="revision" value="{{ $setup['revision'] }}">
                        <label>تاريخ بدء التشغيل المعتمد<input name="start_date" type="date" value="{{ \App\Support\Sales\OperationContext::today() }}" required></label>
                        <label>سبب الإنهاء<input name="reason" minlength="3" maxlength="500" required></label>
                        <label>مراجع الجرد وكشوف الحسابات والذمم<textarea name="evidence" minlength="5" maxlength="2000" required></textarea></label>
                        <label class="sale-check"><input name="accounts_confirmed" type="checkbox" value="1" required> طابقت أرصدة النقد والبنك والمحافظ مع النقد والكشوف الفعلية.</label>
                        <label class="sale-check"><input name="account_scope_confirmed" type="checkbox" value="1" required> شملت المراجعة كل الحسابات؛ أي نوع غير معرف كالبنك أو المحفظة غير موجود فعلًا عند البدء.</label>
                        <label class="sale-check"><input name="parties_confirmed" type="checkbox" value="1" required> راجعت ذمم العملاء والموردين والأرصدة لصالح العملاء، بما فيها الصفر؛ لا توجد ذمم بداية مجهولة أو غير مسجلة.</label>
                        <label class="sale-check"><input name="confirmed" type="checkbox" value="1" required> أعتمد اللقطة المعروضة، وأفهم أن إنهاء الإعداد يقفل التصحيح المباشر لأول المدة ويسمح بالبيع وفق ضوابطه.</label>
                        <button class="btn sale-danger-button" type="submit">اعتماد أرصدة البدء وإنهاء الإعداد</button>
                    </fieldset>
                </form>
            @endif
        </details>
    @endforeach
</section>
