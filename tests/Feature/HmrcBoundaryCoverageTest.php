<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\HmrcCorporationTaxSubmission;
use App\Models\HmrcPayeSubmission;
use App\Models\HmrcSubmission;
use App\Models\User;
use App\Services\HmrcAuthService;
use App\Services\TeamManagementService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

function hmrcBoundaryOwner(): User
{
    $user = User::factory()->create();
    app(TeamManagementService::class)->createPersonalTeamForUser($user);

    return $user->refresh();
}

function hmrcBoundaryResponse(string $suffix = 'A', int $expires = 3600): array
{
    return ['access_token' => 'synthetic-access-'.$suffix, 'refresh_token' => 'synthetic-refresh-'.$suffix, 'expires_in' => $expires];
}

beforeEach(function (): void {
    config(['cache.default' => 'array', 'hmrc.environment' => 'sandbox', 'hmrc.client_id' => 'synthetic-client',
        'hmrc.client_secret' => 'synthetic-client-secret', 'hmrc.callback_url' => 'https://example.test/hmrc/callback', 'hmrc.logging.enabled' => false]);
    Http::preventStrayRequests();
});

it('constructs HMRC consent URLs with encoded scope callback and unpredictable state', function (): void {
    $service = new HmrcAuthService();
    parse_str(parse_url($service->getAuthorizationUrl('read:vat write:vat', 'synthetic-state'), PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['scope' => 'read:vat write:vat', 'client_id' => 'synthetic-client', 'state' => 'synthetic-state',
        'response_type' => 'code', 'redirect_uri' => 'https://example.test/hmrc/callback']);
    parse_str(parse_url($service->getAuthorizationUrl('read:vat'), PHP_URL_QUERY), $first);
    parse_str(parse_url($service->getAuthorizationUrl('read:vat'), PHP_URL_QUERY), $second);
    expect($first['state'])->toHaveLength(32)->not->toBe($second['state']);
    Http::assertNothingSent();
});

it('isolates cached HMRC grants and logout between two independently owned users', function (): void {
    $owner = hmrcBoundaryOwner();
    $other = hmrcBoundaryOwner();
    Http::fakeSequence()->push(hmrcBoundaryResponse())->push(hmrcBoundaryResponse('B'));
    $this->actingAs($owner);
    $service = new HmrcAuthService();
    expect($service->isAuthorized())->toBeFalse();
    expect($service->exchangeCodeForToken('synthetic-code-A'))->toBe(hmrcBoundaryResponse())
        ->and($service->getAccessToken())->toBe('synthetic-access-A')->and($service->isAuthorized())->toBeTrue();
    Http::assertSent(fn ($request): bool => $request['grant_type'] === 'authorization_code' && $request['code'] === 'synthetic-code-A'
        && $request['redirect_uri'] === 'https://example.test/hmrc/callback');
    $this->actingAs($other);
    expect($service->isAuthorized())->toBeFalse();
    $service->exchangeCodeForToken('synthetic-code-B');
    expect($service->getAccessToken())->toBe('synthetic-access-B');
    $service->logout();
    expect($service->isAuthorized())->toBeFalse();
    $this->actingAs($owner);
    expect($service->getAccessToken())->toBe('synthetic-access-A');
    Http::assertSentCount(2);
});

it('does not reuse a grant across environments client identities or a changed persisted team selection', function (): void {
    $owner = hmrcBoundaryOwner();
    $this->actingAs($owner);
    Http::fakeSequence()->push(hmrcBoundaryResponse());
    $service = new HmrcAuthService();
    $service->exchangeCodeForToken('synthetic-code');
    config(['hmrc.environment' => 'production']);
    expect((new HmrcAuthService())->isAuthorized())->toBeFalse();
    config(['hmrc.environment' => 'sandbox', 'hmrc.client_id' => 'other-synthetic-client']);
    expect((new HmrcAuthService())->isAuthorized())->toBeFalse();
    User::whereKey($owner->id)->update(['current_team_id' => null]);
    expect(fn () => $service->getAccessToken())->toThrow(RuntimeException::class, 'owned team');
    Http::assertSentCount(1);
});

it('rejects guest or tenantless grant exchanges before any provider request', function (bool $authenticated): void {
    if ($authenticated) {
        $this->actingAs(User::factory()->create());
    }
    expect(fn () => (new HmrcAuthService())->exchangeCodeForToken('synthetic-code'))->toThrow(RuntimeException::class, 'owned team');
    Http::assertNothingSent();
})->with([false, true]);

it('refreshes an expired access token using the owned cached refresh token and respects short expiry', function (): void {
    $this->actingAs(hmrcBoundaryOwner());
    Http::fakeSequence()->push(hmrcBoundaryResponse('short', 5))->push(hmrcBoundaryResponse('rotated'));
    $service = new HmrcAuthService();
    $service->exchangeCodeForToken('synthetic-code');
    expect($service->getAccessToken())->toBe('synthetic-access-short');
    $this->travel(6)->seconds();
    expect($service->getAccessToken())->toBe('synthetic-access-rotated');
    Http::assertSent(fn ($request): bool => $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'synthetic-refresh-short');
    $service->logout();
    expect(fn () => $service->refreshAccessToken())->toThrow(Exception::class, 'No refresh token');
});

it('supports an explicit refresh token and retains authorization when the provider does not rotate it', function (): void {
    $this->actingAs(hmrcBoundaryOwner());
    Http::fakeSequence()->push(hmrcBoundaryResponse())->push(['access_token' => 'synthetic-new', 'expires_in' => 300]);
    $service = new HmrcAuthService();
    $service->exchangeCodeForToken('synthetic-code');
    expect($service->refreshAccessToken('synthetic-explicit')['access_token'])->toBe('synthetic-new')
        ->and($service->getAccessToken())->toBe('synthetic-new')->and($service->isAuthorized())->toBeTrue();
    Http::assertSent(fn ($request): bool => $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'synthetic-explicit');
});

it('rejects malformed successful HMRC token responses without caching false authorization', function (array $payload): void {
    $this->actingAs(hmrcBoundaryOwner());
    Http::fakeSequence()->push($payload);
    $service = new HmrcAuthService();
    expect(fn () => $service->exchangeCodeForToken('synthetic-code'))->toThrow(RuntimeException::class, 'invalid OAuth')
        ->and($service->isAuthorized())->toBeFalse();
})->with([
    'missing token' => [['expires_in' => 3600]], 'empty token' => [['access_token' => '', 'expires_in' => 3600]],
    'expired token' => [['access_token' => 'synthetic', 'expires_in' => 0]],
    'noninteger expiry' => [['access_token' => 'synthetic', 'expires_in' => '3600']],
    'invalid refresh' => [['access_token' => 'synthetic', 'expires_in' => 3600, 'refresh_token' => []]],
]);

it('logs only safe HTTP failure metadata and leaves authorization absent', function (string $operation): void {
    $this->actingAs(hmrcBoundaryOwner());
    config(['hmrc.logging.enabled' => true, 'hmrc.logging.channel' => 'stack']);
    $logger = Mockery::mock(LoggerInterface::class);
    Log::shouldReceive('channel')->with('stack')->andReturn($logger);
    $label = $operation === 'exchange' ? 'exchange' : 'refresh';
    $logger->shouldReceive('error')->once()->with('Token '.$label.' failed', ['status' => 400]);
    $logger->shouldReceive('error')->once()->with('Token '.$label.' error', ['exception_type' => Exception::class]);
    Http::fakeSequence()->push(['access_token' => 'synthetic-never-log', 'error_description' => 'synthetic-private-provider-detail'], 400);
    $service = new HmrcAuthService();
    expect(fn () => $operation === 'exchange' ? $service->exchangeCodeForToken('synthetic-code') : $service->refreshAccessToken('synthetic-refresh'))
        ->toThrow(Exception::class, 'Failed to');
    expect($service->isAuthorized())->toBeFalse();
})->with(['exchange', 'refresh']);

it('propagates provider transport failure without caching a grant', function (): void {
    $this->actingAs(hmrcBoundaryOwner());
    Http::fake(fn () => throw new ConnectionException('synthetic-transport-failure'));
    $service = new HmrcAuthService();
    expect(fn () => $service->exchangeCodeForToken('synthetic-code'))->toThrow(ConnectionException::class)
        ->and($service->isAuthorized())->toBeFalse();
});

it('preserves HMRC child submission lifecycle and calendar-day deadlines without inventing acknowledgements', function (string $status, bool $editable, bool $submitted): void {
    $company = Company::factory()->create();
    $record = HmrcSubmission::create(['company_id' => $company->company_id, 'submission_type' => 'paye_rti',
        'tax_period_from' => '2026-04-06', 'tax_period_to' => '2026-05-05', 'status' => $status]);
    $paye = HmrcPayeSubmission::create(['company_id' => $company->company_id, 'hmrc_submission_id' => $record->id,
        'tax_year' => '2026-27', 'tax_month' => 1, 'payment_date' => '2026-05-05']);
    $ct = HmrcCorporationTaxSubmission::create(['company_id' => $company->company_id, 'hmrc_submission_id' => $record->id,
        'accounting_period_start' => '2025-04-01', 'accounting_period_end' => '2026-03-31', 'filing_due_date' => '2026-05-05', 'payment_due_date' => '2026-05-05']);
    $this->travelTo(new DateTimeImmutable('2026-05-05 23:59:59'));
    expect($paye->status)->toBe($status)->and($paye->isEditable())->toBe($editable)->and($paye->isLate())->toBeFalse()
        ->and($ct->status)->toBe($status)->and($ct->isEditable())->toBe($editable)->and($ct->isOverdue())->toBeFalse()
        ->and($paye->company->company_id)->toBe($company->company_id)->and($ct->company->company_id)->toBe($company->company_id);
    $this->travel(1)->day();
    expect($paye->isLate())->toBeTrue()->and($ct->isOverdue())->toBe(! $submitted)
        ->and($record->fresh()->hmrc_reference)->toBeNull()->and($record->fresh()->accepted_at)->toBeNull();
    if (! $editable) {
        expect(fn () => $paye->calculateFromPayroll())->toThrow(DomainException::class, 'editable submission')
            ->and(fn () => $ct->calculateFromFinancials())->toThrow(DomainException::class, 'editable submission');
    }
})->with([['draft', true, false], ['rejected', true, false], ['pending', false, false], ['submitted', false, true], ['accepted', false, true]]);

it('does not recalculate unsupported PAYE or corporation tax schemas or mark drafts as filed', function (): void {
    $company = Company::factory()->create();
    $paye = HmrcPayeSubmission::create(['company_id' => $company->company_id, 'tax_year' => '2026-27', 'tax_month' => 1, 'payment_date' => '2026-05-05']);
    $ct = HmrcCorporationTaxSubmission::create(['company_id' => $company->company_id,
        'accounting_period_start' => '2025-04-01', 'accounting_period_end' => '2026-03-31', 'filing_due_date' => '2026-05-05', 'payment_due_date' => '2026-05-05']);
    expect($paye->status)->toBe('draft')->and($paye->isEditable())->toBeTrue()
        ->and($ct->status)->toBe('draft')->and($ct->isEditable())->toBeTrue();
    expect(fn () => $paye->calculateFromPayroll())->toThrow(DomainException::class, 'persisted company ownership')
        ->and(fn () => $ct->calculateFromFinancials())->toThrow(DomainException::class, 'authoritative company-owned');
    expect($paye->fresh()->hmrc_submission_id)->toBeNull()->and($ct->fresh()->hmrc_submission_id)->toBeNull();
    Http::assertNothingSent();
});

it('uses the actual submission calendar date when deciding whether a PAYE record was late', function (string $submittedAt, bool $late): void {
    $company = Company::factory()->create();
    $record = HmrcSubmission::create(['company_id' => $company->company_id, 'submission_type' => 'paye_rti',
        'tax_period_from' => '2026-04-06', 'tax_period_to' => '2026-05-05', 'status' => 'submitted', 'submitted_at' => $submittedAt]);
    $paye = HmrcPayeSubmission::create(['company_id' => $company->company_id, 'hmrc_submission_id' => $record->id,
        'tax_year' => '2026-27', 'tax_month' => 1, 'payment_date' => '2026-05-05']);
    expect($paye->isLate())->toBe($late);
})->with([['2026-05-05 23:59:59', false], ['2026-05-06 00:00:00', true], ['2026-05-04 12:00:00', false]]);
