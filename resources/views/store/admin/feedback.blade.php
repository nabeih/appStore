@if(session('success'))<p class="commerce-notice success" role="status">{{ session('success') }}</p>@endif
@if($errors->any())<div class="commerce-notice danger" role="alert">@foreach(collect($errors->messages())->except('minimum_quote')->flatten() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
