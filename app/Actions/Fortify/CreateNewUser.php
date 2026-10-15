<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\Team;
use App\Models\User;
use App\Services\TeamManagementService;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     * @throws Exception
     */
    public function create(array $input): User
    {
        $previousTeamId = getPermissionsTeamId();
        try {
            Validator::make($input, [
                'name' => ['required', 'string', 'max:255'],
                'email' => [
                    'required',
                    'string',
                    'email',
                    'max:255',
                    Rule::unique(User::class),
                ],
                'password' => $this->passwordRules(),
                'role' => ['required', 'string', Rule::in(['tenant', 'buyer', 'seller', 'landlord', 'contractor'])],
            ])->validate();

            $user = DB::transaction(fn () => tap(User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
            ]), function (User $user) use ($input): void {
                $team = $this->assignOrCreateTeam($user);
                $user->switchTeam($team);
                setPermissionsTeamId($team->id);
                $user->assignRole($input['role']);
            }));

            Log::info('User created successfully', [
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $input['role'],
            ]);

            return $user;
        } catch (ValidationException $e) {
            Log::error('User creation validation failed', [
                'errors' => $e->errors(),
                'fields' => array_keys($e->errors()),
            ]);
            throw $e;
        } catch (QueryException $e) {
            Log::error('Database error during user creation', [
                'code' => $e->getCode(),
            ]);
            throw new Exception($this->getDatabaseErrorMessage($e), (int) $e->getCode(), $e);
        } catch (RoleDoesNotExist $e) {
            Log::error('Invalid role specified during user creation', [
                'role' => $input['role'] ?? 'not provided',
            ]);
            throw new Exception('Invalid role specified. Please choose a valid role.', (int) $e->getCode(), $e);
        } catch (Exception $e) {
            Log::error('Unexpected error during user creation', [
                'exception_class' => $e::class,
            ]);
            throw new Exception('An unexpected error occurred. Please try again later.', (int) $e->getCode(), $e);
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }

    private function getDatabaseErrorMessage(QueryException $e): string
    {
        $errorCode = $e->getCode();
        $errorMessage = $e->getMessage();

        if (str_contains($errorMessage, 'Duplicate entry')) {
            return 'A user with this email already exists. Please use a different email address.';
        } elseif ($errorCode == 1045) {
            return 'Database access denied. Please contact the administrator.';
        } elseif ($errorCode == 2002) {
            return 'Unable to connect to the database. Please try again later.';
        } else {
            return 'A database error occurred. Please try again later. Error code: '.$errorCode;
        }
    }

    /**
     * Provision only the registering user's own company boundary.
     *
     * @throws Exception
     */
    protected function assignOrCreateTeam(User $user): Team
    {
        return app(TeamManagementService::class)->createPersonalTeamForUser($user);
    }
}
