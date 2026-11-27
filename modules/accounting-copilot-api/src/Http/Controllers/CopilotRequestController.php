<?php

declare(strict_types=1);

namespace Liberu\Accounting\CopilotApi\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Liberu\Accounting\Copilot\Actions\ConfirmCopilotRequest;
use Liberu\Accounting\Copilot\Actions\CreateCopilotRequest;
use Liberu\Accounting\Copilot\Models\CopilotRequest;
use Liberu\Accounting\Copilot\Queries\CopilotRequestQuery;

final class CopilotRequestController extends Controller
{
    public function index(Request $request, CopilotRequestQuery $query): mixed
    {
        return response()->json(['data' => $query->forTeam($this->teamId($request), $request->string('kind')->toString() ?: null)]);
    }

    public function store(Request $request, CreateCopilotRequest $action): mixed
    {
        $data = $request->validate(['kind' => ['required', 'in:search,explanation,summary,narrative,draft_transaction'], 'prompt' => ['required', 'string', 'max:10000'], 'result' => ['nullable', 'array'], 'confirmation_key' => ['required', 'string', 'max:160'], 'metadata' => ['nullable', 'array']]);

        return response()->json(['data' => $action->handle([...$data, 'team_id' => $this->teamId($request, write: true), 'actor_id' => $request->user()->getAuthIdentifier()])], 201);
    }

    public function confirm(Request $request, string $requestId, ConfirmCopilotRequest $action): mixed
    {
        $record = CopilotRequest::query()->where('team_id', $this->teamId($request, write: true))->findOrFail($requestId);
        $data = $request->validate(['confirmation_key' => ['required', 'string', 'max:160']]);

        return response()->json(['data' => $action->handle($record, $data['confirmation_key'])]);
    }

    private function teamId(Request $request, bool $write = false): int
    {
        $identity = $request->user();
        $user = $identity instanceof Model ? $identity->fresh() : null;
        abort_unless($user instanceof Model && method_exists($user, 'belongsToTeam'), 403, 'A current team membership is required.');

        $id = $user->getAttribute('current_team_id');
        abort_unless(is_numeric($id) && (int) $id > 0, 403, 'A team context is required.');

        $team = $user->getRelationValue('currentTeam');
        abort_unless($team instanceof Model && (int) $team->getKey() === (int) $id && $user->belongsToTeam($team), 403, 'A current team membership is required.');

        if ($write) {
            $owner = method_exists($user, 'ownsTeam') && $user->ownsTeam($team);
            abort_unless($owner || (method_exists($user, 'teams') && $user->teams()->whereKey($team->getKey())->wherePivotIn('role', ['admin', 'accountant'])->exists()), 403, 'An authorized accounting team role is required.');
        }

        return (int) $id;
    }
}
