@if(session('status'))<div class="b-message b-success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="b-message b-error" role="alert"><strong>لم تُحفظ العملية؛ راجع التالي:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
