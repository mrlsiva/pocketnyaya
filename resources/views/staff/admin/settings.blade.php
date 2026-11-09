@extends('layouts.app')
@section('title', 'Platform settings')

@section('content')
    <h1 class="h4 mb-3">Platform settings</h1>
    <div class="row g-3">
        <div class="col-lg-7"><div class="card shadow-sm"><div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Setting</th><th>Value</th></tr></thead>
                <tbody>
                    @forelse ($settings as $setting)
                        <tr><td>{{ $setting->key }}</td><td class="text-break">{{ json_decode($setting->value) }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="text-body-secondary">No settings yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div></div></div>
        <div class="col-lg-5"><div class="card shadow-sm"><div class="card-body">
            <h2 class="h6">Add or change a setting</h2>
            <form method="post" action="{{ route('staff.admin.settings.update') }}">@csrf @method('put')
                <input class="form-control form-control-sm mb-2" name="key" placeholder="setting.name" required aria-label="Setting name">
                <input class="form-control form-control-sm mb-2" name="value" placeholder="Value" required aria-label="Value">
                <div class="form-text mb-2">Don't store passwords or API keys here.</div>
                <button class="btn btn-primary btn-sm" type="submit">Save</button>
            </form>
        </div></div></div>
    </div>
@endsection
