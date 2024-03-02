@extends('Admin.layout')
@section('pagetitle', __('trans.height'))
@section('content')
<form method="POST" action="{{ route('admin.heights.update',$Model) }}" enctype="multipart/form-data" >
    @csrf
    @method('PUT')
    <div class="text-center">
        <img src="{{ asset($Model->file ?? setting('logo')) }}" class="rounded mx-auto text-center" id="file"  height="200px">
    </div>
    <div class="row">
        <div class="col-md-6">
            <label for="title_ar">@lang('trans.title')</label>
            <input id="title_ar" type="text" name="title" required placeholder="@lang('trans.title')" class="form-control" value="{{ $Model['title'] }}">
        </div>
        <div class="col-12">
            <div class="button-group my-4">
                <button type="submit" class="main-btn btn-hover w-100 text-center">
                    {{ __('trans.Submit') }}
                </button>
            </div>
        </div>
    </div>
</form>
@endsection
