@extends('layouts.app')
@section('title', 'حسابات القبض ورد الأموال')
@include('sales.partials.assets')
@section('content')
    <div class="sale-page">
        <header class="sale-header">
            <h1>حسابات القبض ورد الأموال</h1>
            @can('sales.view')@can('sales.sell')
            <a class="btn btn-outline" href="{{ route('sales.cashier') }}">الكاشير</a>
            @endcan @endcan
        </header>
        <p class="sale-notice">كل درج أو حساب بنك أو محفظة سجل مستقل. لا تُفترض أرصدة قديمة تلقائيًا، ولا تُسجّل التحويلات
            غير المؤكدة كمقبوض.</p>
        @include('finance.partials.notice')
        <section class="sale-panel">
            <h2>تعريف حساب استلام</h2>
            <form action="{{ route('finance.accounts.store') }}" data-simple-form>@include('sales.partials.feedback')<fieldset>
                    <div class="sale-grid"><label>الاسم الفعلي للحساب<input name="name" maxlength="150" required
                                placeholder="مثال: محفظة المتجر الرئيسية"></label><label>الفرع<select name="branch_id"
                                required>
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </label><label>النوع<select name="type">
                                <option value="cash">درج نقدي</option>
                                <option value="bank">حساب بنكي</option>
                                <option value="wallet">محفظة</option>
                            </select></label><label>رقم الحساب أو المحفظة (اختياري)<input name="reference"
                                maxlength="150"></label></div>
                    <details>
                        <summary class="sale-muted">رصيد افتتاحي موثّق لهذا الحساب</summary><label>الرصيد الافتتاحي ₪<input
                                type="text" inputmode="decimal" name="opening_balance" value="0"></label><label>سبب
                            ومصدر اعتماد الرصيد<input name="reason" maxlength="500"></label><label
                            class="sale-check"><input type="checkbox" name="confirm_opening" value="1"> أؤكد مطابقة
                            الرصيد الفعلي وأنه ليس إيراد مبيعات.</label>
                    </details><button class="btn btn-primary" type="submit">حفظ الحساب</button>
                </fieldset>
            </form>
        </section>
        <nav class="search-nav-links">
            @can('sales.customers')@can('sales.collect')
            <a class="btn btn-outline" href="{{ route('search.index', ['type' => 'receipts']) }}">بحث سندات القبض ورد
                الأموال</a>
            @endcan @endcan
        </nav>
        <section class="sale-panel" data-search-region="accounts">
            <h2>الحسابات المتاحة</h2>@include('search.partials.filters', [
                'searchKind' => 'accounts',
                'placeholder' => 'اسم الحساب أو المرجع',
            ])<table class="sale-table sale-mobile-table">
                <thead>
                    <tr>
                        <th>الحساب</th>
                        <th>النوع</th>
                        <th>المرجع</th>
                        <th>رصيد الدفتر</th>
                        <th>رصيد البداية</th>
                        <th>الحالة</th>
                        <th>إدارة الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $account)
                        <tr>
                            <td>{{ $account->name }} #{{ $account->id }}</td>
                            <td data-label="النوع">
                                {{ match ($account->type) {'cash' => 'درج نقدي','bank' => 'بنك',default => 'محفظة'} }}</td>
                            <td data-label="المرجع">{{ $account->reference ?: '—' }}</td>
                            <td data-label="الرصيد">
                                {{ \App\Support\Sales\Money::format((int) $account->debits_minor - (int) $account->credits_minor) }}
                                ₪</td>
                            <td class="sale-actions-cell">
                                @if (isset($accountOpenings[$account->id]) && $accountOpenings[$account->id]->count() === 1)
                                    <span>{{ \App\Support\Sales\Money::format((int) $accountOpenings[$account->id]->first()->lines->where('financial_account_id', $account->id)->sum('debit_minor')) }}
                                        ₪</span>
                                @elseif(!$account->is_active)
                                    <span class="sale-muted">فعّل الحساب قبل اعتماد رصيد البداية.</span>
                                @elseif(!$account->lines_count)
                                    <details>
                                        <summary>اعتماد رصيد بداية قبل أول حركة</summary>
                                        <form action="{{ route('finance.accounts.opening', $account) }}" data-simple-form>
                                            @include('sales.partials.feedback')<fieldset><label>رصيد البداية ₪<input
                                                        name="opening_balance" inputmode="decimal" type="text"
                                                        required></label><label>مصدر الرصيد وسبب اعتماده<input
                                                        name="reason" required minlength="3"
                                                        maxlength="500"></label><label class="sale-check"><input
                                                        name="confirm_opening" type="checkbox" value="1" required> أكدت
                                                    الرصيد الفعلي قبل أول حركة.</label><button class="btn btn-outline"
                                                    type="submit">اعتماد الرصيد</button></fieldset>
                                        </form>
                                </details>@else<span class="sale-muted">بدأت الحركات؛ لا يُغيّر رصيد البداية.</span>
                                @endif
                            </td>
                            <td data-label="الحالة">{{ $account->is_active ? 'نشط' : 'مجمّد' }}</td>
                            <td data-label="إدارة الحالة">
                                @can('sales.manage_accounts')
                                    @if($account->is_posting && $account->branch_id)
                                        <details>
                                            <summary class="btn btn-outline">{{ $account->is_active ? 'تجميد الحساب' : 'تفعيل الحساب' }}</summary>
                                            <p>التجميد يمنع الحركات المالية الجديدة، ويحافظ على الرصيد والقيود السابقة. يلزم التفعيل قبل أي حركة جديدة أو عكس قيد.</p>
                                            <form action="{{ route('finance.accounts.status', $account) }}" data-simple-form>
                                                @csrf
                                                @include('sales.partials.feedback')
                                                <fieldset>
                                                    <input type="hidden" name="is_active" value="{{ $account->is_active ? '0' : '1' }}">
                                                    <label>سبب الإجراء<input name="reason" required minlength="3" maxlength="500"></label>
                                                    <label class="sale-check"><input type="checkbox" name="confirmed" value="1" required> أؤكد {{ $account->is_active ? 'تجميد' : 'تفعيل' }} هذا الحساب.</label>
                                                    <button type="submit" class="btn btn-outline">{{ $account->is_active ? 'تجميد الحساب' : 'تفعيل الحساب' }}</button>
                                                </fieldset>
                                            </form>
                                        </details>
                                    @else
                                        <span class="sale-muted">حساب محمي</span>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table><x-pagination :paginator="$entries" />
        </section>
    </div>
@endsection
