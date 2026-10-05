<?php

declare(strict_types=1);

use Agovena\Extensions\CPanel\HttpCPanelApi;
use Agovena\Modules\Provisioning\Support\ServerProviderException;
use App\Agovena\Extensions\ExtensionManager;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Modules\ModuleManager;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ServerApiSafetyTest extends TestCase
{
    /** @return array<string, array{class-string}> */
    public static function unverifiedProviders(): array
    {
        return [
            'cpanel' => ['Agovena\\Extensions\\CPanel\\HttpCPanelApi'],
            'convoy' => ['Agovena\\Extensions\\Convoy\\HttpConvoyApi'],
            'directadmin' => ['Agovena\\Extensions\\DirectAdmin\\HttpDirectAdminApi'],
            'enhance' => ['Agovena\\Extensions\\Enhance\\HttpEnhanceApi'],
            'plesk' => ['Agovena\\Extensions\\Plesk\\HttpPleskApi'],
            'virtfusion' => ['Agovena\\Extensions\\Virtfusion\\HttpVirtfusionApi'],
            'virtualizor' => ['Agovena\\Extensions\\Virtualizor\\HttpVirtualizorApi'],
        ];
    }

    #[DataProvider('unverifiedProviders')]
    public function test_unverified_lifecycle_never_sends_assumed_endpoint(string $apiClass): void
    {
        app(ModuleManager::class)->discover();
        app(ExtensionManager::class)->discover();
        Http::fake(['*' => Http::response(['id' => 42], 200)]);
        $api = new $apiClass(app(ExtensionSettingsRepository::class), [
            'api_url' => 'https://provider.example',
            'api_token' => 'test-token',
            'api_username' => 'test-user',
            'api_secret' => 'test-secret',
        ]);

        foreach ([
            fn () => $api->findServerByExternalId('agovena-1'),
            fn () => $api->getServer('123'),
            fn () => $api->createServer(['external_id' => 'agovena-1']),
            fn () => $api->suspend('123'),
            fn () => $api->unsuspend('123'),
            fn () => $api->terminate('123'),
            fn () => $api->changePlan('123', ['plan' => 'new']),
            fn () => $api->action('123', 'restart'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('An unverified lifecycle operation was accepted.');
            } catch (ServerProviderException $exception) {
                $this->assertSame('errors.action_unavailable', $exception->errorKey);
            }
        }

        Http::assertNothingSent();
    }

    public function test_unverified_health_checks_do_not_call_fabricated_api_health_routes(): void
    {
        app(ModuleManager::class)->discover();
        app(ExtensionManager::class)->discover();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        foreach (self::unverifiedProviders() as $provider => [$apiClass]) {
            if ($provider === 'cpanel') {
                continue;
            }
            $api = new $apiClass(app(ExtensionSettingsRepository::class), ['api_url' => 'https://provider.example']);
            try {
                $api->connectionTest();
                $this->fail("Unverified {$provider} health check was accepted.");
            } catch (ServerProviderException $exception) {
                $this->assertSame('errors.action_unavailable', $exception->errorKey);
            }
        }

        Http::assertNothingSent();
    }

    public function test_cpanel_connection_requires_whm_credentials_before_network(): void
    {
        app(ModuleManager::class)->discover();
        app(ExtensionManager::class)->discover();
        Http::fake();
        $api = new HttpCPanelApi(app(ExtensionSettingsRepository::class), [
            'api_url' => 'https://whm.example:2087',
            'api_token' => 'test-token',
        ]);

        try {
            $api->connectionTest();
            $this->fail('A WHM request was accepted without an API username.');
        } catch (ServerProviderException $exception) {
            $this->assertSame('errors.not_configured', $exception->errorKey);
        }
        Http::assertNothingSent();
    }

    public function test_cpanel_connection_rejects_failed_whm_metadata_despite_http_200(): void
    {
        app(ModuleManager::class)->discover();
        app(ExtensionManager::class)->discover();
        Http::fake(['*' => Http::response(['metadata' => ['command' => 'listaccts', 'result' => 0, 'reason' => 'Denied']], 200)]);
        $api = new HttpCPanelApi(app(ExtensionSettingsRepository::class), [
            'api_url' => 'https://whm.example:2087',
            'api_token' => 'test-token',
            'api_username' => 'root',
        ]);

        $this->expectException(ServerProviderException::class);
        $this->expectExceptionMessage('errors.provider_failed');
        $api->connectionTest();
    }

    public function test_cpanel_connection_uses_documented_whm_api_and_checks_metadata(): void
    {
        app(ModuleManager::class)->discover();
        app(ExtensionManager::class)->discover();
        Http::fake(['*' => Http::response([
            'metadata' => ['command' => 'listaccts', 'result' => 1],
            'data' => ['acct' => []],
        ], 200)]);
        $api = new HttpCPanelApi(app(ExtensionSettingsRepository::class), [
            'api_url' => 'https://whm.example:2087',
            'api_token' => 'test-token',
            'api_username' => 'root',
        ]);

        $this->assertSame(['metadata' => ['command' => 'listaccts', 'result' => 1], 'data' => ['acct' => []]], $api->connectionTest());
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://whm.example:2087/json-api/listaccts?api.version=1'
            && $request->hasHeader('Authorization', 'whm root:test-token'));
    }

    public function test_cpanel_connection_does_not_follow_unvalidated_redirects(): void
    {
        app(ModuleManager::class)->discover();
        app(ExtensionManager::class)->discover();
        $requestOptions = null;
        Http::fake(function ($request, array $options) use (&$requestOptions) {
            $requestOptions = $options;

            return Http::response([
                'metadata' => ['command' => 'listaccts', 'result' => 1],
                'data' => ['acct' => []],
            ], 200);
        });

        $api = new HttpCPanelApi(app(ExtensionSettingsRepository::class), [
            'api_url' => 'https://whm.example:2087',
            'api_username' => 'root',
            'api_token' => 'test-token',
        ]);

        $api->connectionTest();
        $this->assertSame(false, $requestOptions['allow_redirects'] ?? null);
        Http::assertSentCount(1);
    }
}
