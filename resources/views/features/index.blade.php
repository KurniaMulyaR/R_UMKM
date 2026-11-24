@extends('layouts.app')
@section('title', 'Fitur dalam PRD')
@section('crumb', 'Fitur dalam PRD')
@section('content')
<div id="feature-catalog"></div>
<script id="feature-bootstrap" type="application/json">@json($modules)</script>
@endsection
