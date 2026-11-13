@extends('install.layout')
@section('content')
<div class="card"><h2>Use HTTPS</h2>
  <p class="mt0">Por segurança o instalador só funciona com conexão segura. No cPanel, abra <b>SSL/TLS Status</b> e rode o <b>AutoSSL</b> para o domínio; depois acesse:</p>
  <p><a href="{{ $url }}">{{ $url }}</a></p></div>
@endsection
