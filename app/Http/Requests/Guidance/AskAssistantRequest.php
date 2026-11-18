<?php

namespace App\Http\Requests\Guidance;

use App\Support\Guidance\GuidanceKnowledgeBase;
use App\Support\Guidance\GuidanceResolver;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AskAssistantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'question' => ['nullable', 'string', 'max:500'],
            'page' => ['nullable', Rule::in(array_keys(app(GuidanceKnowledgeBase::class)->definitions()))],
            'code' => ['nullable', Rule::in(array_keys(app(GuidanceResolver::class)->definitions()))],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'document_kind' => ['nullable', 'required_with:document_id', 'in:opening,purchase,sale'],
            'document_id' => ['nullable', 'required_with:document_kind', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return ['question.max' => 'اختصر السؤال إلى 500 حرف، دون بيانات زبائن أو كلمات مرور.',
            '*.integer' => 'اختر الفرع أو المستند من الشاشة بدل إدخال نص في المعرّف.',
            '*.in' => 'موضوع المساعدة غير معروف؛ اختر موضوعًا من مساعد المتجر.',
            '*.required_with' => 'اختر نوع المستند ومعرّفه معًا لطلب مساعدة تخصه.'];
    }
}
