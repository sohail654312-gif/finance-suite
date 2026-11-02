<?php

declare(strict_types=1);

namespace Liberu\Accounting\ChartOfAccountsApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Liberu\Accounting\ChartOfAccounts\Actions\ArchiveAccount;
use Liberu\Accounting\ChartOfAccounts\Actions\SaveAccount;
use Liberu\Accounting\ChartOfAccounts\Exceptions\InvalidAccount;
use Liberu\Accounting\ChartOfAccounts\Exceptions\InvalidAccountHierarchy;
use Liberu\Accounting\ChartOfAccounts\Models\Account;
use Liberu\Accounting\ChartOfAccountsApi\Http\Requests\StoreAccountRequest;
use Liberu\Accounting\ChartOfAccountsApi\Http\Requests\UpdateAccountRequest;
use Liberu\Accounting\ChartOfAccountsApi\Http\Resources\AccountResource;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Core\Support\TenantOwnership;

final class AccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Account::class);
        $teamId = TenantOwnership::currentTeamId($request->user());
        abort_if($teamId === null, 403);
        $query = Account::query()->where('team_id', $teamId)->when(! $request->boolean('include_archived'), fn ($q) => $q->where('is_active', true))->latest()->when($request->integer('legal_entity_id'), fn ($q, $id) => $q->where('legal_entity_id', $id))->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')));

        return AccountResource::collection($query->paginate(min($request->integer('per_page', 25), 100)))->response();
    }

    public function tree(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Account::class);
        $entityId = $request->integer('legal_entity_id');
        if ($entityId < 1) {
            throw ValidationException::withMessages(['legal_entity_id' => 'A legal entity is required.']);
        }

        $teamId = TenantOwnership::currentTeamId($request->user());
        abort_if($teamId === null, 403);
        LegalEntity::query()->whereKey($entityId)->where('team_id', $teamId)->firstOrFail();

        return response()->json(['data' => AccountResource::collection(Account::query()->where('team_id', $teamId)->where('legal_entity_id', $entityId)->whereNull('parent_id')->with('children.children.children')->orderBy('code')->get())]);
    }

    public function show(string $account): AccountResource
    {
        $model = Account::query()->findOrFail($account);
        Gate::authorize('view', $model);

        return new AccountResource($model);
    }

    public function store(StoreAccountRequest $request, SaveAccount $save): JsonResponse
    {
        Gate::authorize('create', Account::class);

        $data = $request->validated();
        $teamId = TenantOwnership::currentTeamId($request->user());
        abort_if($teamId === null, 403);
        $entity = LegalEntity::query()->whereKey($data['legal_entity_id'])->where('team_id', $teamId)->firstOrFail();
        $data['legal_entity_id'] = $entity->getKey();

        try {
            return (new AccountResource($save->handle($data)))->response()->setStatusCode(201);
        } catch (InvalidAccountHierarchy $exception) {
            $this->rejectHierarchy($exception);
        } catch (InvalidAccount $exception) {
            $this->rejectAccount($exception);
        }
    }

    public function update(UpdateAccountRequest $request, string $account, SaveAccount $save): AccountResource
    {
        $model = Account::query()->findOrFail($account);
        Gate::authorize('update', $model);

        try {
            return new AccountResource($save->handle($request->validated() + ['legal_entity_id' => $model->legal_entity_id], $model));
        } catch (InvalidAccountHierarchy $exception) {
            $this->rejectHierarchy($exception);
        } catch (InvalidAccount $exception) {
            $this->rejectAccount($exception);
        }
    }

    public function destroy(string $account, ArchiveAccount $archive): Response
    {
        $model = Account::query()->findOrFail($account);
        Gate::authorize('delete', $model);
        try {
            $archive->handle($model);
        } catch (InvalidAccountHierarchy $exception) {
            $this->rejectHierarchy($exception);
        } catch (InvalidAccount $exception) {
            $this->rejectAccount($exception);
        }

        return response()->noContent();
    }

    private function rejectHierarchy(InvalidAccountHierarchy $exception): never
    {
        throw ValidationException::withMessages(['parent_id' => $exception->getMessage()]);
    }

    private function rejectAccount(InvalidAccount $exception): never
    {
        throw ValidationException::withMessages(['account' => $exception->getMessage()]);
    }
}
