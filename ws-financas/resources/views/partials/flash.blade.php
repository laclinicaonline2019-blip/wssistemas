@if (session('ok'))<div class="banner good" role="status">{{ session('ok') }}</div>@endif
@if (session('error'))<div class="banner bad" role="alert">{{ session('error') }}</div>@endif
@if (isset($errors) && $errors->any())<div class="banner bad" role="alert"><span>{{ implode(' ', $errors->all()) }}</span></div>@endif
