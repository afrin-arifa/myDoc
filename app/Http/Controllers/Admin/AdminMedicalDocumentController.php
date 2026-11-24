<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MedicalDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdminMedicalDocumentController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', MedicalDocument::class);
        abort_unless($request->user()->role === 'admin', 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'in:'.implode(',', array_keys(MedicalDocument::CATEGORIES))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $search = $filters['search'] ?? null;

        $documents = MedicalDocument::query()
            ->with(['patient:id,name,email,phone', 'uploader:id,name', 'appointment.slot.doctorProfile.user:id,name'])
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->whereDate('created_at', '<=', $to))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $matches) => $matches
                ->where('title', 'like', "%{$search}%")
                ->orWhere('original_name', 'like', "%{$search}%")
                ->orWhereHas('patient', fn (Builder $patients) => $patients
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))
                ->orWhereHas('appointment.slot.doctorProfile.user', fn (Builder $doctors) => $doctors->where('name', 'like', "%{$search}%"))))
            ->latest()
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.medical-documents.index', compact('documents', 'filters'));
    }

    public function show(MedicalDocument $document): View
    {
        Gate::authorize('view', $document);
        abort_unless(request()->user()->role === 'admin', 403);

        $document->load([
            'patient:id,name,email,phone',
            'uploader:id,name',
            'appointment.slot.doctorProfile.user:id,name',
            'events' => fn ($events) => $events->with('actor:id,name,role')->latest()->latest('id')->limit(50),
        ]);

        return view('admin.medical-documents.show', compact('document'));
    }
}
