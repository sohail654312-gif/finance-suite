<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class JournalEntryController extends Controller
{
    public function index(Request $request): LengthAwarePaginator
    {
        // Tenant-scope by team. Null current team -> -1 sentinel = empty result
        // (never IS NULL, which would leak unassigned rows).
        return JournalEntry::where('team_id', $request->user()->current_team_id ?? -1)
            ->with('lines')
            ->latest()
            ->paginate(25);
    }

    public function store(Request $request): JsonResponse
    {
        $teamId = $request->user()?->current_team_id;
        abort_unless(is_numeric($teamId) && (int) $teamId > 0, 403);

        $validated = $request->validate([
            'entry_date' => 'required|date',
            'entry_type' => 'sometimes|in:general,adjusting,closing,reversing',
            'reference_number' => 'sometimes|nullable|string',
            'memo' => 'sometimes|nullable|string',
            'lines' => 'sometimes|array',
            // Scope account references to the acting team (accounts.team_id) so a
            // caller can't post lines against another team's accounts (IDOR).
            'lines.*.account_id' => ['required', Rule::exists('accounts', 'id')->where('team_id', $request->user()->current_team_id)],
            'lines.*.debit_amount' => 'sometimes|numeric|min:0|decimal:0,2',
            'lines.*.credit_amount' => 'sometimes|numeric|min:0|decimal:0,2',
            'lines.*.description' => 'sometimes|nullable|string',
        ]);

        $lines = $validated['lines'] ?? [];
        unset($validated['lines']);

        // Never use floating-point array_sum for money: 0.10 + 0.20 must
        // equal 0.30 exactly, and a partially written journal is unacceptable.
        if ($lines !== []) {
            $debits = '0.00';
            $credits = '0.00';

            foreach ($lines as $line) {
                $debit = (string) ($line['debit_amount'] ?? '0');
                $credit = (string) ($line['credit_amount'] ?? '0');

                if ((bccomp($debit, '0.00', 2) > 0) === (bccomp($credit, '0.00', 2) > 0)) {
                    throw ValidationException::withMessages([
                        'lines' => 'Every journal line must have a positive debit or credit, but not both.',
                    ]);
                }

                $debits = bcadd($debits, $debit, 2);
                $credits = bcadd($credits, $credit, 2);
            }

            if (count($lines) < 2 || bccomp($debits, $credits, 2) !== 0) {
                throw ValidationException::withMessages([
                    'lines' => 'Journal entry lines must balance (total debits must equal total credits).',
                ]);
            }
        }

        // Commit parent and all lines together, or none of them. Draft journals
        // without lines remain supported for the existing authoring workflow.
        $entry = DB::transaction(function () use ($request, $validated, $lines, $teamId): JournalEntry {
            $entry = new JournalEntry($validated);
            $entry->user_id = $request->user()->getKey();
            $entry->team_id = (int) $teamId;
            $entry->save();

            foreach ($lines as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'debit_amount' => $line['debit_amount'] ?? 0,
                    'credit_amount' => $line['credit_amount'] ?? 0,
                    'description' => $line['description'] ?? null,
                ]);
            }

            return $entry;
        });

        return response()->json($entry->load('lines'), 201);
    }

    public function show(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        abort_unless($journalEntry->team_id === $request->user()->current_team_id, 403);

        return response()->json($journalEntry->load('lines'));
    }

    public function destroy(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        abort_unless($journalEntry->team_id === $request->user()->current_team_id, 403);
        abort_if($journalEntry->is_posted, 422, 'Posted entries cannot be deleted; reverse them instead.');

        $journalEntry->delete();

        return response()->json(['deleted' => true]);
    }
}
