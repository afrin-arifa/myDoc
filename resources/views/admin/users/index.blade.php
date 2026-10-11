@extends('layouts.app')
@section('title', 'Users | myDoc')
@section('content')
<div class="container py-5"><h1 class="h2 mb-4">Users</h1>
<form class="row g-3 mb-4"><div class="col-md-5"><label class="form-label" for="search">Search</label><input class="form-control" id="search" name="search" value="{{ request('search') }}"></div><div class="col-md-3"><label class="form-label" for="role">Role</label><select class="form-select" id="role" name="role"><option value="">All roles</option>@foreach(['patient','doctor','admin'] as $role)<option value="{{ $role }}" @selected(request('role') === $role)>{{ ucfirst($role) }}</option>@endforeach</select></div><div class="col-md-auto align-self-end"><button class="btn btn-primary">Filter</button></div></form>
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Joined</th><th></th></tr></thead><tbody>@forelse($users as $user)<tr><td>{{ $user->name }}</td><td>{{ $user->email }}</td><td>{{ ucfirst($user->role) }}</td><td>{{ $user->created_at->toDateString() }}</td><td><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.users.show', $user) }}">Manage</a></td></tr>@empty<tr><td colspan="5">No users found.</td></tr>@endforelse</tbody></table></div>{{ $users->links() }}</div>
@endsection
