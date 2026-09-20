<?php

namespace App\Http\Controllers;

use App\Models\Folio;
use App\Models\Transaction;
use App\Services\FolioService;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class FolioController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $query = Folio::forBranch($branchId)
            ->with(['reservation', 'parentFolio', 'children'])
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $query->search($request->string('search')->value());
        }

        if ($request->filled('type') && $request->string('type') !== 'all') {
            $query->where('type', $request->string('type')->value());
        }

        if ($request->filled('status') && $request->string('status') !== 'all') {
            $query->where('status', $request->string('status')->value());
        }

        $folios = $query->paginate(25)->withQueryString();

        return Inertia::render('folios/Index', [
            'folios' => $folios,
            'filters' => $request->only(['search', 'type', 'status']),
        ]);
    }

    public function show(Folio $folio): Response
    {
        $this->ensureBranchAccess($folio->branch);

        $folio->load([
            'reservation.room',
            'reservation.roomType',
            'reservation.guest',
            'parentFolio',
            'children.transactions',
            'transactions.poster',
            'disputes.reporter',
            'disputes.resolver',
        ]);

        $transactions = $folio->transactions()
            ->with('poster')
            ->orderByDesc('created_at')
            ->get();

        $allChildFolios = $folio->children()->with('transactions')->get();

        return Inertia::render('folios/Show', [
            'folio' => $folio,
            'transactions' => $transactions,
            'childFolios' => $allChildFolios,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'type' => 'required|in:staff,non_guest',
            'guest_name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;
        $folioService = new FolioService;
        $type = $request->string('type')->value();
        $guestName = $request->string('guest_name')->value();
        $description = $request->string('description')->value();

        if ($type === 'staff') {
            $folio = $folioService->createStaffFolio(
                $branchId,
                $guestName,
                $description !== '' ? $description : null,
            );
        } else {
            $folio = $folioService->createNonGuestFolio(
                $branchId,
                $guestName,
                $description !== '' ? $description : null,
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Folio created successfully.']);

        return redirect()->route('folios.show', $folio);
    }

    public function search(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;
        $search = $request->string('q')->value();

        $folios = Folio::forBranch($branchId)
            ->with(['reservation', 'parentFolio'])
            ->search($search)
            ->orderByDesc('created_at')
            ->paginate(25);

        return Inertia::render('folios/Index', [
            'folios' => $folios,
            'filters' => ['search' => $search],
        ]);
    }

    public function postCharge(Folio $folio, Request $request): RedirectResponse
    {
        $this->ensureBranchAccess($folio->branch);

        $request->validate([
            'category' => 'required|string|in:room_rate,tax,minibar,restaurant,laundry,spa,parking,misc',
            'description' => 'required|string|max:255',
            'amount' => 'required|integer|min:1',
            'tax_rate_bps' => 'nullable|integer|min:0|max:10000',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $folioService = new FolioService;
        $folioService->postManualCharge(
            $folio,
            $request->string('category')->value(),
            $request->string('description')->value(),
            $request->integer('amount'),
            $request->filled('tax_rate_bps') ? $request->integer('tax_rate_bps') : null,
            $user->id,
        );

        return $this->flashSuccess('Charge posted successfully.');
    }

    public function recordPayment(Folio $folio, Request $request): RedirectResponse
    {
        $this->ensureBranchAccess($folio->branch);

        $request->validate([
            'amount' => 'required|integer|min:1',
            'method' => 'required|in:cash,card,online',
            'reference' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $folioService = new FolioService;
        $folioService->recordPayment(
            $folio,
            $request->integer('amount'),
            $request->string('method')->value(),
            $user->id,
            $request->string('reference')->value() !== '' ? $request->string('reference')->value() : null,
        );

        return $this->flashSuccess('Payment recorded successfully.');
    }

    public function checkout(Folio $folio, Request $request): RedirectResponse
    {
        $this->ensureBranchAccess($folio->branch);

        $folioService = new FolioService;

        try {
            $folioService->checkout(
                $folio,
                $folio->reservation_id,
            );

            return $this->flashSuccess('Folio settled and closed successfully.');
        } catch (\LogicException $e) {
            return back()->withErrors(['checkout' => $e->getMessage()]);
        }
    }

    public function bill(Folio $folio): Response
    {
        $this->ensureBranchAccess($folio->branch);

        $folioService = new FolioService;
        $billData = $folioService->generateBillData($folio);

        return Inertia::render('folios/Bill', $billData);
    }

    public function billPdf(Folio $folio): SymfonyResponse
    {
        $this->ensureBranchAccess($folio->branch);

        $folioService = new FolioService;
        $billData = $folioService->generateBillData($folio);

        $pdf = SnappyPdf::loadView('folios.bill', $billData)
            ->setOption('filename', "folio-{$folio->folio_number}.pdf")
            ->setOption('encoding', 'utf-8');

        return $pdf->inline();
    }

    public function createChild(Folio $folio, Request $request): RedirectResponse
    {
        $this->ensureBranchAccess($folio->branch);

        $request->validate([
            'description' => 'required|string|max:255',
            'reservation_id' => 'nullable|integer|exists:reservations,id',
        ]);

        $folioService = new FolioService;
        $childFolio = $folioService->createFolio(
            $folio->branch_id,
            $request->filled('reservation_id') ? $request->integer('reservation_id') : null,
            $folio->id,
            $request->string('description')->value(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Child folio created successfully.']);

        return redirect()->route('folios.show', $childFolio);
    }

    public function transferTransaction(Transaction $transaction, Request $request): RedirectResponse
    {
        $request->validate([
            'target_folio_id' => 'required|integer|exists:folios,id',
        ]);

        $targetFolio = Folio::findOrFail($request->integer('target_folio_id'));

        $this->ensureBranchAccess($transaction->folio->branch);
        $this->ensureBranchAccess($targetFolio->branch);

        if ($transaction->folio->branch_id !== $targetFolio->branch_id) {
            abort(403, 'Charges can only be transferred within the same property.');
        }

        if ($transaction->folio_id === $targetFolio->id) {
            return back()->withErrors([
                'target_folio_id' => 'Transaction is already on this folio.',
            ]);
        }

        $folioService = new FolioService;
        $folioService->transferCharge($transaction, $targetFolio);

        return $this->flashSuccess('Charge transferred successfully.');
    }
}
