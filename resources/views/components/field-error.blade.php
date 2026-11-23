@props(['name'])
@if($errors->has($name))
    <span class="validation-field-error" data-validation-message id="field-error-{{ str_replace(['.', '[', ']'], '-', $name) }}">
        @foreach($errors->get($name) as $message)<span>{{ $message }}</span>@endforeach
    </span>
@endif
