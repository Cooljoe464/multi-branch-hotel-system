<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\SpaAppointment;
use App\Models\SpaService;
use App\Models\SpaTherapist;
use App\Models\SpaTreatmentRoom;
use App\Services\SpaBookingService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SpaController extends Controller
{
    use EnsuresBranchAccess;

    public function __construct(private SpaBookingService $spaService) {}

    public function index(Request $request, Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $appointments = SpaAppointment::where('branch_id', $branch->id)
            ->with(['service', 'treatmentRoom', 'therapist'])
            ->when($request->filled('date'), fn ($q) => $q->whereDate('scheduled_at', $request->string('date')->value()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->value()))
            ->orderByDesc('scheduled_at')
            ->paginate(20)
            ->withQueryString();

        $services = SpaService::forBranch($branch->id)->active()->get();
        $rooms = SpaTreatmentRoom::forBranch($branch->id)->active()->get();
        $therapists = SpaTherapist::forBranch($branch->id)->active()->get();

        return Inertia::render('spa/Appointments', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'appointments' => $appointments,
            'services' => $services,
            'rooms' => $rooms,
            'therapists' => $therapists,
            'filters' => $request->only(['date', 'status']),
        ]);
    }

    public function schedule(Request $request, Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $date = $request->filled('date')
            ? Carbon::parse($request->string('date')->value())
            : now();

        $therapists = SpaTherapist::forBranch($branch->id)
            ->active()
            ->with(['appointments' => fn ($q) => $q->whereDate('scheduled_at', $date->toDateString())->whereNotIn('status', ['cancelled'])->with(['service', 'treatmentRoom'])])
            ->get();

        $rooms = SpaTreatmentRoom::forBranch($branch->id)->active()->get();
        $services = SpaService::forBranch($branch->id)->active()->get();

        return Inertia::render('spa/Schedule', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'date' => $date->toDateString(),
            'therapists' => $therapists,
            'rooms' => $rooms,
            'services' => $services,
        ]);
    }

    public function book(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $data = $request->validate([
            'guest_name' => 'required|string|max:255',
            'spa_service_id' => 'required|exists:spa_services,id',
            'spa_treatment_room_id' => 'nullable|exists:spa_treatment_rooms,id',
            'spa_therapist_id' => 'nullable|exists:spa_therapists,id',
            'scheduled_at' => 'required|date',
            'reservation_id' => 'nullable|exists:reservations,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $this->spaService->book($branch, $data);

        return $this->flashSuccess('Spa appointment booked.');
    }

    public function reschedule(Request $request, Branch $branch, SpaAppointment $appointment): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($appointment->branch_id === $branch->id, 404);

        $request->validate([
            'scheduled_at' => 'required|date',
            'spa_therapist_id' => 'nullable|exists:spa_therapists,id',
        ]);

        $this->spaService->reschedule(
            $appointment,
            Carbon::parse($request->string('scheduled_at')->value()),
            $request->filled('spa_therapist_id') ? $request->integer('spa_therapist_id') : null,
        );

        return $this->flashSuccess('Appointment rescheduled.');
    }

    public function complete(Request $request, Branch $branch, SpaAppointment $appointment): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($appointment->branch_id === $branch->id, 404);

        $request->validate([
            'folio_id' => 'nullable|exists:folios,id',
        ]);

        $this->spaService->complete(
            $appointment,
            $request->filled('folio_id') ? $request->integer('folio_id') : null,
        );

        return $this->flashSuccess('Appointment marked as completed.');
    }

    public function cancel(Request $request, Branch $branch, SpaAppointment $appointment): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($appointment->branch_id === $branch->id, 404);

        $this->spaService->cancel($appointment);

        return $this->flashSuccess('Appointment cancelled.');
    }
}
