<?php

namespace Tests\Feature;

use App\Support\Guidance\ArabicValidator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class ValidationExperienceTest extends TestCase
{
    public function test_common_validator_uses_arabic_labels_including_related_dates_and_nested_rows(): void
    {
        $validator = Validator::make(['original_date' => '2026-11-22', 'tracking_started_on' => '2026-01-01', 'rows' => [['quantity' => 'abc', 'unit_cost' => '-1']]], [
            'original_date' => 'before:tracking_started_on', 'rows.*.quantity' => 'integer', 'rows.*.unit_cost' => 'numeric|min:0', 'unknown_internal_column' => 'required',
        ]);
        $this->assertInstanceOf(ArabicValidator::class, $validator);
        $this->assertTrue($validator->fails());
        $messages = implode(' ', $validator->errors()->all());
        $this->assertStringContainsString('تاريخ نشوء الدين الحقيقي', $messages);
        $this->assertStringContainsString('بداية تتبع التزامات الموردين', $messages);
        $this->assertStringContainsString('عدد قطع الصنف', $messages);
        $this->assertStringNotContainsString('original_date', $messages);
        $this->assertStringNotContainsString('tracking_started_on', $messages);
        $this->assertStringNotContainsString('unknown_internal_column', $messages);
    }

    public function test_custom_arabic_labels_and_validation_rules_are_preserved(): void
    {
        $validator = Validator::make(['amount' => '-1'], ['amount' => 'numeric|min:0'], [], ['amount' => 'مبلغ الإيجار']);
        $this->assertFalse($validator->passes());
        $this->assertStringContainsString('مبلغ الإيجار', $validator->errors()->first());
        $this->assertTrue(Validator::make(['amount' => '0'], ['amount' => 'numeric|min:0'])->passes());
    }

    public function test_shared_component_escapes_payload_and_field_component_renders_all_messages(): void
    {
        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag(['name' => ['أدخل الاسم', 'راجع الاسم </script><script>alert(1)</script>']]));
        view()->share('errors', $errors);
        $html = Blade::render('<x-validation-feedback /><x-field-error name="name" />', compact('errors'));
        $this->assertStringContainsString('data-validation-errors', $html);
        $this->assertStringContainsString('validation.js', $html);
        $this->assertStringContainsString('أدخل الاسم', $html);
        $this->assertStringNotContainsString('</script><script>alert', $html);
        $this->assertSame(1, substr_count($html, 'data-validation-message'));
        $this->assertSame(1, substr_count($html, 'id="field-error-name"'));
    }

    public function test_422_response_keeps_all_field_keys_and_arabic_messages(): void
    {
        $this->withoutMiddleware();
        Route::post('/_validation_test', fn () => request()->validate(['name' => 'required', 'rows.*.quantity' => 'integer']));
        $response = $this->postJson('/_validation_test', ['rows' => [['quantity' => 'bad']]]);
        $response->assertUnprocessable()->assertJsonValidationErrors(['name', 'rows.0.quantity']);
        $this->assertStringContainsString('الاسم', $response->json('errors.name.0'));
        $this->assertStringContainsString('عدد قطع الصنف', $response->json('errors')['rows.0.quantity'][0]);
    }

    public function test_authentication_authorization_and_explicit_http_responses_keep_their_statuses(): void
    {
        $this->withoutMiddleware();
        Route::post('/_validation_auth', fn () => throw new AuthenticationException);
        Route::post('/_validation_forbidden', fn () => throw new AuthorizationException);
        Route::post('/_validation_explicit', fn () => throw new HttpResponseException(response()->json(['message' => 'تعارض حالة المستند'], 409)));
        $this->postJson('/_validation_auth')->assertUnauthorized();
        $this->postJson('/_validation_forbidden')->assertForbidden();
        $this->postJson('/_validation_explicit')->assertConflict()->assertJsonPath('message', 'تعارض حالة المستند');
    }

    public function test_unexpected_exception_does_not_expose_database_or_class_details(): void
    {
        $this->withoutMiddleware();
        Route::post('/_validation_failure_test', fn () => throw new \RuntimeException('SQLSTATE secret supplier_debt_settlements App\\InternalClass'));
        $this->postJson('/_validation_failure_test')->assertStatus(500)->assertJsonPath('message', 'تعذر إكمال العملية حاليًا. احتفظ بالبيانات المدخلة وأعد المحاولة؛ إذا استمرت المشكلة تواصل مع مسؤول النظام.')->assertDontSee('SQLSTATE')->assertDontSee('InternalClass');
    }
}
