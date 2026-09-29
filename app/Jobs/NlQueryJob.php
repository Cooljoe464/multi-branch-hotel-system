<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\NlQueryLog;
use App\Models\User;
use App\Services\NlReportingService;
use App\Services\Reporting\HeuristicDriver;
use App\Services\Reporting\SqlGuard;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Slow NL queries run here so the request never waits out the
 * 15s statement timeout. Same key, same cache entry as the sync
 * path — polling converges on identical results.
 */
class NlQueryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        private int $branchId,
        private ?int $userId,
        private string $question,
        private string $from,
        private string $to,
    ) {
        $this->onQueue('reports');
    }

    public function uniqueId(): string
    {
        return 'nlq:'.$this->branchId.':'.($this->userId ?? 0).':'.hash('sha256', $this->question.$this->from.$this->to);
    }

    public function handle(): void
    {
        $service = new NlReportingService;
        $branch = Branch::findOrFail($this->branchId);
        $user = $this->userId !== null ? User::find($this->userId) : null;

        $parsed = (new HeuristicDriver)->parse($this->question, $this->from, $this->to);

        if ($parsed === null) {
            return;
        }

        $bindings = $parsed['bindings'];
        $bindings[0] = $branch->id;

        SqlGuard::validate($parsed['sql']);

        $key = $service->queryKey($branch->id, $this->userId, $this->question, $this->from, $this->to);
        $log = NlQueryLog::where('query_key', $key)->firstOrFail();

        $service->run($branch, $user, $log, [
            'sql' => $parsed['sql'],
            'bindings' => $bindings,
            'columns' => $parsed['columns'],
            'kind' => $parsed['kind'],
            'title' => $parsed['title'],
        ], $key);

        Log::info('NL query completed async.', ['key' => $key]);
    }
}
