@props(['name', 'label', 'type' => 'text', 'value' => null, 'col' => 'col-6', 'help' => null, 'required' => false, 'mask' => null])
@php
    $key = trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $id = 'f-'.str_replace('.', '-', $key);
@endphp
<div class="field {{ $col }}">
    <label for="{{ $id }}">{{ $label }}@if ($required) <span aria-hidden="true">*</span>@endif</label>
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" @unless ($type === 'password') value="{{ old($key, $value) }}" @endunless
           class="input @error($key) is-invalid @enderror" @if ($required) required @endif @if ($mask) data-mask="{{ $mask }}" @endif {{ $attributes }}>
    @if ($help)<div class="help">{{ $help }}</div>@endif
    @error($key)<div class="field-error">{{ $message }}</div>@enderror
</div>
