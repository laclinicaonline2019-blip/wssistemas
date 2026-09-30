@extends('errors.layout')
@section('code', '403')
@section('title', 'Acesso não permitido')
@section('message', $exception->getMessage() ?: 'Você não tem permissão para acessar este recurso.')
