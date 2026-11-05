<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\ConciergeAction;
use App\Models\ConciergeKbEntry;
use App\Models\ConciergeMessage;
use App\Models\ConciergeThread;
use App\Services\Concierge\ConciergeReplySuggester;
use App\Services\ConciergeAiManager;
use App\Services\ConciergeService;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConciergeController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch, Request $request): Response
    {
        $this->ensureBranchAccess($branch);

        $status = $request->query('status');
        $allowedStatuses = [ConciergeThread::STATUS_ACTIVE, ConciergeThread::STATUS_HANDOFF, ConciergeThread::STATUS_CLOSED];
        $status = is_string($status) && in_array($status, $allowedStatuses, true) ? $status : null;

        $threads = ConciergeThread::forBranch($branch->id)
            ->with([
                'guest:id,first_name,last_name',
                'reservation:id,confirmation_number,room_id,check_in_date,check_out_date,status',
            ])
            ->withCount([
                'messages as unread_count' => fn (Builder $query) => $query
                    ->where('role', ConciergeMessage::ROLE_GUEST)
                    ->whereNull('read_at'),
            ])
            ->addSelect([
                'last_message_body' => ConciergeMessage::query()
                    ->select('body')
                    ->whereColumn('concierge_thread_id', 'concierge_threads.id')
                    ->orderByDesc('id')
                    ->limit(1),
            ])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByRaw('COALESCE(last_message_at, updated_at) DESC')
            ->limit(100)
            ->get()
            ->map(fn (ConciergeThread $thread) => [
                'id' => $thread->id,
                'guest_name' => $thread->displayName(),
                'wa_id' => $thread->whatsapp_wa_id,
                'status' => $thread->status,
                'unread_count' => is_numeric($thread->getAttribute('unread_count')) ? (int) $thread->getAttribute('unread_count') : 0,
                'last_message' => $thread->getAttribute('last_message_body'),
                'last_message_at' => $thread->last_message_at?->toIso8601String(),
                'handoff_at' => $thread->handoff_at?->toIso8601String(),
                'reservation' => $thread->reservation !== null ? [
                    'id' => $thread->reservation->id,
                    'confirmation_number' => $thread->reservation->confirmation_number,
                    'status' => $thread->reservation->status,
                    'check_in_date' => $thread->reservation->check_in_date->toDateString(),
                    'check_out_date' => $thread->reservation->check_out_date->toDateString(),
                ] : null,
            ])
            ->all();

        $selectedId = $request->integer('thread');
        $selectedId = $selectedId > 0 ? $selectedId : (int) ($threads[0]['id'] ?? 0);
        $selected = $selectedId > 0
            ? ConciergeThread::forBranch($branch->id)->with('guest:id,first_name,last_name')->find($selectedId)
            : null;

        $messages = [];
        $actions = [];

        if ($selected instanceof ConciergeThread) {
            (ConciergeService::make())->markRead($selected);

            $messages = $selected->messages()
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->reverse()
                ->values()
                ->map(fn (ConciergeMessage $message) => [
                    'id' => $message->id,
                    'role' => $message->role,
                    'body' => $message->body,
                    'tool_calls' => $message->tool_calls ?? [],
                    'created_at' => $message->created_at?->toIso8601String(),
                ])
                ->all();

            $actions = $selected->actions()
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (ConciergeAction $action) => [
                    'id' => $action->id,
                    'type' => $action->type,
                    'status' => $action->status,
                    'payload' => $action->payload ?? [],
                    'created_at' => $action->created_at?->toIso8601String(),
                ])
                ->all();
        }

        $user = $request->user();
        $whatsapp = new WhatsAppCloudApiService;

        return Inertia::render('concierge/Inbox', [
            'branch' => $branch->only(['id', 'name', 'currency_code']),
            'threads' => $threads,
            'selected' => $selected instanceof ConciergeThread ? [
                'id' => $selected->id,
                'guest_name' => $selected->displayName(),
                'wa_id' => $selected->whatsapp_wa_id,
                'status' => $selected->status,
                'reservation_id' => $selected->reservation_id,
                'assigned_to' => $selected->assigned_to,
                'messages' => $messages,
                'actions' => $actions,
            ] : null,
            'status' => $status,
            'whatsapp' => [
                'enabled' => $whatsapp->isEnabled($branch),
                'configured' => $whatsapp->isConfigured($branch),
            ],
            'kb' => ConciergeKbEntry::forBranch($branch->id)
                ->orderBy('topic')
                ->get(['id', 'topic', 'question', 'answer', 'keywords', 'active']),
            'can' => [
                'reply' => $user?->can('concierge.reply') ?? false,
                'manage_kb' => $user?->can('concierge.manage_kb') ?? false,
            ],
        ]);
    }

    public function reply(Branch $branch, ConciergeThread $thread, Request $request): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        $this->ensureThread($branch, $thread);

        $request->validate(['body' => 'required|string|max:4096']);

        $user = $request->user();
        abort_unless($user !== null, 401);

        (ConciergeService::make())->replyAsAgent($thread, $request->string('body')->value(), $user);

        return $this->backToThread($branch, $thread, 'Reply sent.');
    }

    public function suggest(Branch $branch, ConciergeThread $thread): JsonResponse
    {
        $this->ensureBranchAccess($branch);
        $this->ensureThread($branch, $thread);

        $manager = app(ConciergeAiManager::class);

        $suggestion = $manager->isConfigured()
            ? (new ConciergeReplySuggester($manager->provider()))->suggest($thread)
            : null;

        return response()->json([
            'suggestion' => $suggestion,
        ]);
    }

    public function takeOver(Branch $branch, ConciergeThread $thread, Request $request): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        $this->ensureThread($branch, $thread);

        $user = $request->user();
        abort_unless($user !== null, 401);

        (ConciergeService::make())->takeOver($thread, $user);

        return $this->backToThread($branch, $thread, 'You are now handling this conversation.');
    }

    public function handBack(Branch $branch, ConciergeThread $thread): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        $this->ensureThread($branch, $thread);

        (ConciergeService::make())->handBack($thread);

        return $this->backToThread($branch, $thread, 'AI assistant resumed.');
    }

    public function close(Branch $branch, ConciergeThread $thread): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        $this->ensureThread($branch, $thread);

        (ConciergeService::make())->close($thread);

        return $this->backToThread($branch, $thread, 'Conversation closed.');
    }

    public function applyAction(Branch $branch, ConciergeThread $thread, ConciergeAction $action, Request $request): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        $this->ensureThread($branch, $thread);
        abort_unless($action->concierge_thread_id === $thread->id, 404);

        $user = $request->user();
        abort_unless($user !== null, 401);

        (ConciergeService::make())->applyAction($action, $user);

        return $this->backToThread($branch, $thread, 'Draft applied.');
    }

    public function rejectAction(Branch $branch, ConciergeThread $thread, ConciergeAction $action): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        $this->ensureThread($branch, $thread);
        abort_unless($action->concierge_thread_id === $thread->id, 404);

        (ConciergeService::make())->rejectAction($action);

        return $this->backToThread($branch, $thread, 'Draft dismissed.');
    }

    public function updateSettings(Branch $branch, Request $request): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate(['enabled' => 'required|boolean']);

        $settings = $branch->settings ?? [];
        $settings['whatsapp'] = is_array($settings['whatsapp'] ?? null) ? $settings['whatsapp'] : [];
        $settings['whatsapp']['enabled'] = $request->boolean('enabled');

        $branch->update(['settings' => $settings]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $request->boolean('enabled') ? 'AI concierge enabled.' : 'AI concierge paused — human-only mode.',
        ]);
    }

    public function storeKb(Branch $branch, Request $request): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $data = $this->validateKb($request);

        ConciergeKbEntry::create([
            'branch_id' => $branch->id,
            'topic' => $data['topic'],
            'question' => $data['question'] ?? null,
            'answer' => $data['answer'],
            'keywords' => $data['keywords'],
            'active' => $data['active'],
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Knowledge base entry added.']);
    }

    public function updateKb(Branch $branch, ConciergeKbEntry $entry, Request $request): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($entry->branch_id === $branch->id, 404);

        $data = $this->validateKb($request);

        $entry->update([
            'topic' => $data['topic'],
            'question' => $data['question'] ?? null,
            'answer' => $data['answer'],
            'keywords' => $data['keywords'],
            'active' => $data['active'],
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Knowledge base entry updated.']);
    }

    public function destroyKb(Branch $branch, ConciergeKbEntry $entry): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($entry->branch_id === $branch->id, 404);

        $entry->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Knowledge base entry removed.']);
    }

    private function ensureThread(Branch $branch, ConciergeThread $thread): void
    {
        abort_unless($thread->branch_id === $branch->id, 404);
    }

    private function backToThread(Branch $branch, ConciergeThread $thread, string $message): RedirectResponse
    {
        return redirect()
            ->route('concierge.index', ['branch' => $branch->id, 'thread' => $thread->id])
            ->with('toast', ['type' => 'success', 'message' => $message]);
    }

    /**
     * @return array{topic: string, question: string|null, answer: string, keywords: list<string>, active: bool}
     */
    private function validateKb(Request $request): array
    {
        $request->validate([
            'topic' => 'required|string|max:128',
            'question' => 'nullable|string|max:2000',
            'answer' => 'required|string|max:5000',
            'keywords' => 'nullable',
            'active' => 'nullable|boolean',
        ]);

        $keywords = [];

        foreach ((array) $request->input('keywords', []) as $keyword) {
            if (is_string($keyword) && trim($keyword) !== '') {
                $keywords[] = mb_strtolower(trim($keyword));
            }
        }

        return [
            'topic' => $request->string('topic')->value(),
            'question' => $request->filled('question') ? $request->string('question')->value() : null,
            'answer' => $request->string('answer')->value(),
            'keywords' => array_values(array_unique($keywords)),
            'active' => $request->has('active') ? $request->boolean('active') : true,
        ];
    }
}
