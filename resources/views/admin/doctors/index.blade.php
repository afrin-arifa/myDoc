@extends('layouts.app')
@section('title', 'Doctors | myDoc')
@section('content')
<div class="container py-5">
	<h1 class="h2 mb-4">Doctor profiles</h1>
	<form class="mb-4" method="GET">
		<label class="form-label" for="search">Search doctors</label>
		<div class="input-group">
			<input class="form-control" id="search" name="search" value="{{ request('search') }}">
			<button class="btn btn-primary" type="submit">Search</button>
		</div>
	</form>
	@forelse ($doctors as $doctor)
		<article class="card border-0 shadow-sm mb-3">
			<div class="card-body d-flex flex-column flex-md-row gap-3 justify-content-between">
				<div>
					<h2 class="h5">{{ $doctor->user->name }}</h2>
					<p class="mb-1">{{ $doctor->professional_title }} · License {{ $doctor->license_number }}</p>
					<p class="text-secondary mb-2">{{ $doctor->is_accepting_patients ? 'Accepting patients' : 'Not accepting patients' }}</p>
					@foreach ($doctor->specialties as $specialty)
						<span class="badge text-bg-light me-1">{{ $specialty->name }}</span>
					@endforeach
				</div>
				<a class="btn btn-sm btn-outline-primary align-self-start" href="{{ route('admin.doctors.show', $doctor) }}">Manage</a>
			</div>
		</article>
	@empty
		<p class="text-secondary">No doctor profiles found.</p>
	@endforelse
	{{ $doctors->links() }}
</div>
@endsection
