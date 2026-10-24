<?php

namespace App\Http\Requests\Inventory\OpeningStock;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreOpeningStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('payload')) {
            $payload = is_string($this->input('payload')) ? json_decode($this->input('payload'), true) : null;
            if (! is_array($payload) || ! is_array($payload['rows'] ?? null) || ! is_array($payload['image_rows'] ?? [])) {
                throw ValidationException::withMessages(['rows' => 'تعذر قراءة بيانات المستند. أعد المراجعة قبل الحفظ.']);
            }
            $files = [];
            $expected = $payload['image_rows'] ?? [];
            if (count($expected) !== count(array_unique($expected, SORT_REGULAR)) || ! is_array($this->file('images', [])) || count($expected) !== count($this->file('images', []))) {
                throw ValidationException::withMessages(['rows' => 'لم تصل كل الصور. قلل عددها أو حجمها ثم أعد المراجعة.']);
            }
            foreach ($expected as $index) {
                if (! is_int($index) || ! isset($payload['rows'][$index]) || ! $this->file('images.'.$index)) {
                    throw ValidationException::withMessages(['rows' => 'إحدى الصور لم تصل. أعد إرفاق الصور قبل المتابعة.']);
                }
                $files[$index] = ['image' => $this->file('images.'.$index)];
            }
            $this->merge(Arr::only($payload, ['client_uuid', 'branch_id', 'opening_date', 'notes', 'rows']));
            $this->files->set('rows', $files);
            $this->convertedFiles = null;
        }
        if (is_string($this->input('notes'))) {
            $this->merge(['notes' => filled(trim($this->input('notes'))) ? trim($this->input('notes')) : null]);
        }
        $rows = $this->input('rows');
        if (is_array($rows)) {
            foreach ($rows as &$row) {
                if (is_array($row)) {
                    foreach (['name', 'code', 'cost', 'price'] as $key) {
                        if (isset($row[$key]) && is_string($row[$key])) {
                            $row[$key] = trim($row[$key]);
                        }
                    }
                    if (isset($row['code']) && is_string($row['code'])) {
                        $row['code'] = filled($row['code']) ? strtoupper($row['code']) : null;
                    }
                }
            }
            unset($row);
            $this->merge(['rows' => $rows]);
        }
    }

    public function rules(): array
    {
        $money = ['required', 'numeric', 'min:0', 'max:9999999.99', 'regex:/^\d{1,7}(\.\d{1,2})?$/'];

        return [
            'client_uuid' => ['required', 'uuid'],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'opening_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'rows' => ['required', 'array', 'list', 'min:1', 'max:300'],
            'rows.*' => ['required', 'array:name,code,category_id,color_id,size_id,qty,cost,price,image'],
            'rows.*.name' => ['required', 'string', 'max:200'],
            'rows.*.code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/'],
            'rows.*.category_id' => ['required', 'integer', 'min:1'],
            'rows.*.color_id' => ['required', 'integer', 'min:1'],
            'rows.*.size_id' => ['required', 'integer', 'min:1'],
            'rows.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'rows.*.cost' => $money,
            'rows.*.price' => $money,
            'rows.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    public function attributes(): array
    {
        return ['client_uuid' => 'معرّف العملية', 'branch_id' => 'الفرع', 'opening_date' => 'تاريخ الرصيد', 'notes' => 'الملاحظات', 'rows' => 'الأصناف', 'rows.*.name' => 'اسم المنتج', 'rows.*.code' => 'كود المنتج', 'rows.*.qty' => 'الكمية', 'rows.*.cost' => 'تكلفة الوحدة', 'rows.*.price' => 'سعر البيع', 'rows.*.image' => 'صورة اللون', 'rows.*.category_id' => 'التصنيف', 'rows.*.color_id' => 'اللون', 'rows.*.size_id' => 'المقاس'];
    }

    public function messages(): array
    {
        return [
            'required' => 'حقل :attribute مطلوب.',
            'integer' => 'حقل :attribute يجب أن يكون عددًا صحيحًا.',
            'numeric' => 'حقل :attribute يجب أن يكون رقمًا.',
            'min.numeric' => 'حقل :attribute أقل من الحد المسموح.',
            'max.numeric' => 'حقل :attribute يتجاوز الحد المسموح.',
            'rows.max' => 'يمكن حفظ 300 سطر كحد أقصى في المستند الواحد.',
            'rows.*.code.regex' => 'كود المنتج يقبل أحرفًا إنجليزية وأرقامًا وشرطة أو شرطة سفلية.',
            'rows.*.cost.regex' => 'تكلفة الوحدة تقبل منزلتين عشريتين كحد أقصى.',
            'rows.*.price.regex' => 'سعر البيع يقبل منزلتين عشريتين كحد أقصى.',
            'opening_date.before_or_equal' => 'تاريخ الرصيد الافتتاحي لا يمكن أن يكون في المستقبل.',
            'opening_date.date_format' => 'أدخل تاريخًا صحيحًا للرصيد الافتتاحي.',
            'branch_id.exists' => 'اختر فرعًا موجودًا وفعالًا.',
            'rows.*.image.uploaded' => 'تعذر رفع الصورة. تحقق من حجمها وحدود الرفع على الخادم.',
            'rows.*.image.max' => 'حجم الصورة يجب ألا يزيد عن 4 ميجابايت.',
            'rows.*.image.image' => 'الملف المرفق يجب أن يكون صورة.',
            'rows.*.image.mimes' => 'الصيغ المسموحة للصورة: JPG وPNG وWebP.',
            'client_uuid.uuid' => 'معرّف العملية غير صالح. أعد فتح نافذة الإدخال.',
        ];
    }
}
