<?php

declare(strict_types=1);

namespace Liberu\Accounting\JournalApprovalsApi\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Liberu\Accounting\JournalApprovals\Actions\AddEvidence;
use Liberu\Accounting\JournalApprovals\Actions\ConfigureThreshold;
use Liberu\Accounting\JournalApprovals\Actions\DecideJournal;
use Liberu\Accounting\JournalApprovals\Actions\SubmitJournal;
use Liberu\Accounting\JournalApprovals\Enums\ApprovalStatus;
use Liberu\Accounting\JournalApprovals\Exceptions\InvalidApproval;
use Liberu\Accounting\JournalApprovals\Models\JournalApproval;
use Liberu\Accounting\JournalApprovals\Queries\JournalApprovalQuery;
use Liberu\Accounting\JournalApprovalsApi\Http\Resources\JournalApprovalResource;

final class JournalApprovalsController extends Controller
{
    public function __construct(private readonly JournalApprovalQuery $query)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $teamId = $this->ownedTeamId($request, 'accounting.journal-approvals.read');
        abort_if($request->filled('team_id') && $request->integer('team_id') !== $teamId, 403);
        $status = $request->filled('status') ? ApprovalStatus::from($request->string('status')->toString()) : null;

        return JournalApprovalResource::collection($this->query->paginate($teamId, $status, $request->integer('per_page', 25)));
    }

    public function store(Request $request, SubmitJournal $action): JournalApprovalResource
    {
        $teamId = $this->ownedTeamId($request, 'accounting.journal-approvals.write');
        $attributes = $request->except('evidence', 'team_id', 'preparer_ref');
        $attributes['team_id'] = $teamId;
        $attributes['preparer_ref'] = (string) $request->user()?->getAuthIdentifier();

        return new JournalApprovalResource($action->handle($attributes, (array) $request->input('evidence', [])));
    }

    public function show(Request $request, JournalApproval $approval): JournalApprovalResource
    {
        $this->authorizeApproval($request, $approval, 'accounting.journal-approvals.read');

        return new JournalApprovalResource($approval->load(['decisions', 'evidence']));
    }

    public function decide(Request $request, JournalApproval $approval, DecideJournal $action): JournalApprovalResource
    {
        $this->authorizeApproval($request, $approval, 'accounting.journal-approvals.write');
        $data = $request->validate(['actor_ref' => ['prohibited'], 'approved' => ['required', 'boolean'], 'comment' => ['nullable', 'string', 'max:5000']]);

        // Resolve qualifications from the authenticated user's persisted membership
        // of this selected team. Never trust actor_ref or role claims from JSON.
        $user = $request->user();
        if (($user instanceof Model) === false) {
            abort(403);
        }
        $teamId = $this->ownedTeamId($request, 'accounting.journal-approvals.write');
        $actor = (string) $user->getAuthIdentifier();
        $membershipRole = DB::table('team_user')
            ->where('team_id', $teamId)
            ->where('user_id', $user->getKey())
            ->value('role');
        $roles = is_string($membershipRole) && $membershipRole !== '' ? [$membershipRole] : [];
        $team = $user->getRelationValue('currentTeam');
        if ($team instanceof Model && method_exists($user, 'ownsTeam') && $user->ownsTeam($team)) {
            $roles[] = 'owner';
        }

        try {
            return new JournalApprovalResource($action->handle($approval, $actor, (bool) $data['approved'], $data['comment'] ?? null, $roles));
        } catch (InvalidApproval $e) {
            throw ValidationException::withMessages(['approval' => $e->getMessage()]);
        }
    }

    public function post(Request $request, JournalApproval $approval): JournalApprovalResource
    {
        $this->authorizeApproval($request, $approval, 'accounting.journal-approvals.write');

        // This metadata-only action does not post a ledger entry. Exposing it via HTTP
        // could forge an audit lifecycle, including the emergency state. Ledger posting
        // must use the authorized GL action, which checks period and approval rules.
        abort(403, 'Approval-only posting is unavailable through the HTTP API.');
    }

    public function evidence(Request $request, JournalApproval $approval, AddEvidence $action): JournalApprovalResource
    {
        $this->authorizeApproval($request, $approval, 'accounting.journal-approvals.write');
        $data = $request->validate(['kind' => ['required', 'string', 'max:100'], 'file_ref' => ['nullable', 'string', 'max:500'], 'description' => ['nullable', 'string', 'max:5000'], 'checksum' => ['nullable', 'string', 'max:255'], 'metadata' => ['nullable', 'array']]);
        $action->handle($approval, $data);

        return new JournalApprovalResource($approval->load(['decisions', 'evidence']));
    }

    public function threshold(Request $request, ConfigureThreshold $action): JsonResponse
    {
        $teamId = $this->ownedTeamId($request, 'accounting.journal-approvals.write');
        $user = $request->user();
        if (($user instanceof Model) === false) {
            abort(403);
        }
        $team = $user->getRelationValue('currentTeam');
        abort_unless($team instanceof Model && method_exists($user, 'ownsTeam') && $user->ownsTeam($team), 403);
        abort_if($request->filled('team_id') && $request->integer('team_id') !== $teamId, 403);
        $data = $request->validate([
            'team_id' => ['nullable', 'integer'], 'journal_type' => ['required', 'string', 'max:100'],
            'minimum_amount' => ['required', 'numeric', 'min:0'], 'reviewer_role' => ['required', 'string', 'max:100'],
            'required_approvals' => ['sometimes', 'integer', 'min:1'], 'emergency_allowed' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'], 'metadata' => ['nullable', 'array'],
        ]);

        $data['team_id'] = $teamId;

        return response()->json(['data' => $action->handle($data)], 201);
    }

    private function ownedTeamId(Request $request, string $ability): int
    {
        $user = $request->user();
        if (($user instanceof Model) === false || method_exists($user, 'tokenCan') === false || $user->tokenCan($ability) === false) {
            abort(403);
        }
        $rawId = $user->getAttribute('current_team_id');
        $team = $user->getRelationValue('currentTeam');
        abort_unless(is_numeric($rawId) && (int) $rawId > 0
            && $team instanceof Model && (int) $team->getKey() === (int) $rawId
            && method_exists($user, 'belongsToTeam') && $user->belongsToTeam($team), 403);

        return (int) $rawId;
    }

    private function authorizeApproval(Request $request, JournalApproval $approval, string $ability): void
    {
        $teamId = $this->ownedTeamId($request, $ability);
        abort_unless(is_numeric($approval->team_id) && (int) $approval->team_id === $teamId, 403);
    }
}
