@if ($debtsAvailable)
    <section class="card trade-panel">
        <h2>المديونية السابقة المعتمدة: {{ $opening }} ₪</h2>
        <p>الرصيد السابق مستقل عن المشتريات الجديدة. إجمالي المسدد والمتبقي أدناه يشملان الاثنين؛ المسودات والمستندات
            المعكوسة لا تُضاف إلى المستحق.</p>
        @can('finance.transition_review')
            @unless ($supplier->trashed())
                <a class="btn btn-outline" href="{{ route('suppliers.debts.create', $supplier) }}">إضافة مديونية سابقة</a>
            @endunless
        @endcan
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>المستند</th>
                        <th>الفرع</th>
                        <th>تاريخ القيد</th>
                        <th>المبلغ ₪</th>
                        <th>المسدد ₪</th>
                        <th>الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($settlements as $debt)
                        <tr>
                            <td><a href="{{ route('suppliers.debts.show', $debt) }}">{{ $debt->number }}</a></td>
                            <td>#{{ $debt->branch_id }}</td>
                            <td>{{ $debt->posting_date->format('Y-m-d') }}</td>
                            <td>{{ \App\Support\Sales\Money::format($debt->amount_minor) }}</td>
                            <td>{{ \App\Support\Sales\Money::format($debt->paid_minor) }}</td>
                            <td>{{ ['draft' => 'مسودة', 'approved' => 'معتمدة', 'reversed' => 'معكوسة'][$debt->status] }}</td>
                        </tr>
                    @empty<tr>
                            <td colspan="6">لا توجد مديونيات سابقة مسجلة.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div><x-pagination :paginator="$settlements" />
    </section>
@endif
