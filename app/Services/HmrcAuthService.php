<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HmrcAuthService
{
    private readonly string $baseUrl;

    private readonly string $clientId;

    private readonly string $clientSecret;

    public function __construct()
    {
        $environment = config('hmrc.environment');
        $this->baseUrl = config("hmrc.endpoints.{$environment}");
        $this->clientId = config('hmrc.client_id');
        $this->clientSecret = config('hmrc.client_secret');
    }

    /**
     * Get OAuth authorization URL for user to grant access.
     */
    public function getAuthorizationUrl(string $scope, ?string $state = null): string
    {
        $state ??= bin2hex(random_bytes(16));

        $query = http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $state,
            'redirect_uri' => config('hmrc.callback_url'),
        ]);

        return $this->baseUrl.'/oauth/authorize?'.$query;
    }

    /**
     * Exchange authorization code for access token.
     */
    public function exchangeCodeForToken(string $code): array
    {
        $this->tokenCacheKey('access');
        try {
            $response = Http::asForm()
                ->post($this->baseUrl.'/oauth/token', [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => config('hmrc.callback_url'),
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->validateTokenResponse($data);

                // Cache the access token
                $this->cacheAccessToken($data['access_token'], $data['expires_in']);

                if (isset($data['refresh_token'])) {
                    $this->cacheRefreshToken($data['refresh_token']);
                }

                return $data;
            }

            $this->logError('Token exchange failed', ['status' => $response->status()]);
            throw new \Exception('Failed to exchange authorization code for token');
        } catch (\Exception $e) {
            $this->logError('Token exchange error', ['exception_type' => $e::class]);
            throw $e;
        }
    }

    /**
     * Refresh access token using refresh token.
     */
    public function refreshAccessToken(?string $refreshToken = null): array
    {
        $this->tokenCacheKey('access');
        $refreshToken ??= $this->getCachedRefreshToken();

        if (! $refreshToken) {
            throw new \Exception('No refresh token available');
        }

        try {
            $response = Http::asForm()
                ->post($this->baseUrl.'/oauth/token', [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->validateTokenResponse($data);

                $this->cacheAccessToken($data['access_token'], $data['expires_in']);

                if (isset($data['refresh_token'])) {
                    $this->cacheRefreshToken($data['refresh_token']);
                }

                return $data;
            }

            $this->logError('Token refresh failed', ['status' => $response->status()]);
            throw new \Exception('Failed to refresh access token');
        } catch (\Exception $e) {
            $this->logError('Token refresh error', ['exception_type' => $e::class]);
            throw $e;
        }
    }

    /**
     * Get valid access token (refreshes if expired).
     */
    public function getAccessToken(): string
    {
        $token = $this->getCachedAccessToken();

        if (! $token) {
            // Try to refresh the token
            $data = $this->refreshAccessToken();

            return $data['access_token'];
        }

        return $token;
    }

    /**
     * Check if user has authorized the application.
     */
    public function isAuthorized(): bool
    {
        return Cache::has($this->tokenCacheKey('access')) || Cache::has($this->tokenCacheKey('refresh'));
    }

    /**
     * Cache access token.
     */
    private function cacheAccessToken(string $token, int $expiresIn): void
    {
        // Cache for slightly less than expiration time to be safe
        $ttl = max(1, $expiresIn - min(60, (int) floor($expiresIn / 10)));
        Cache::put($this->tokenCacheKey('access'), $token, $ttl);
    }

    /**
     * Cache refresh token.
     */
    private function cacheRefreshToken(string $token): void
    {
        // Refresh tokens typically last 18 months
        Cache::put($this->tokenCacheKey('refresh'), $token, 60 * 60 * 24 * 540); // 540 days
    }

    /**
     * Get cached access token.
     */
    private function getCachedAccessToken(): ?string
    {
        return Cache::get($this->tokenCacheKey('access'));
    }

    /**
     * Get cached refresh token.
     */
    private function getCachedRefreshToken(): ?string
    {
        return Cache::get($this->tokenCacheKey('refresh'));
    }

    /**
     * Revoke all tokens and clear cache.
     */
    public function logout(): void
    {
        Cache::forget($this->tokenCacheKey('access'));
        Cache::forget($this->tokenCacheKey('refresh'));
    }

    private function tokenCacheKey(string $kind): string
    {
        $identity = auth()->user();
        $user = $identity instanceof User ? $identity->fresh() : null;
        $team = $user?->currentTeam;
        if ($user === null || $team === null || ! $user->belongsToTeam($team)) {
            throw new \RuntimeException('An authenticated owned team is required for HMRC authorization.');
        }

        return 'hmrc.oauth.'.hash('sha256', implode('|', [$this->baseUrl, $this->clientId, (string) $user->getKey(), (string) $team->getKey()])).'.'.$kind;
    }

    private function validateTokenResponse(mixed $data): void
    {
        if (! is_array($data) || ! is_string($data['access_token'] ?? null) || trim($data['access_token']) === ''
            || ! is_int($data['expires_in'] ?? null) || $data['expires_in'] < 1
            || (isset($data['refresh_token']) && (! is_string($data['refresh_token']) || trim($data['refresh_token']) === ''))) {
            throw new \RuntimeException('HMRC returned an invalid OAuth token response.');
        }
    }

    /**
     * Log error if logging is enabled.
     */
    private function logError(string $message, array $context = []): void
    {
        if (config('hmrc.logging.enabled')) {
            Log::channel(config('hmrc.logging.channel'))->error($message, $context);
        }
    }
}
