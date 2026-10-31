@if(session('status'))<p class="sale-notice" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div class="sale-errors" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
