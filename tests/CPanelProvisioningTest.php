<?php

declare(strict_types=1);

use Agovena\Extensions\CPanel\CPanelProvisioner;
use Agovena\Modules\Provisioning\EloquentProvisionedServiceResolver;
use Agovena\Modules\Provisioning\Enums\ServiceInstanceStatus;
use Agovena\Modules\Provisioning\Models\ServiceInstance;
use App\Agovena\Extensions\ExtensionManager;
use App\Agovena\Modules\ModuleManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CPanelProvisioningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->discover();
        app(ExtensionManager::class)->discover();
        installAndEnableModule('provisioning');
        installAndEnableExtension('cpanel');
    }

    public function test_hand_written_mapping_cannot_reconcile_without_trusted_binding(): void
    {
        $instance = $this->makeServiceInstance();
        $originalMeta = $instance->meta;
        Http::fake();

        try {
            app(CPanelProvisioner::class)->syncStatus(EloquentProvisionedServiceResolver::info($instance));
            $this->fail('Unverified mapping was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('instance', $exception->errors());
        }

        $this->assertSame(ServiceInstanceStatus::Provisioning, $instance->fresh()->status);
        $this->assertSame('legacy-source-reference', $instance->fresh()->external_ref);
        $this->assertSame($originalMeta, $instance->fresh()->meta);
        Http::assertNothingSent();
    }

    public function test_duplicate_account_claims_are_rejected_without_network_or_mutation(): void
    {
        $first = $this->makeServiceInstance('SVC-CPANEL-01');
        $second = $this->makeServiceInstance('SVC-CPANEL-02');
        Http::fake();

        foreach ([$first, $second] as $instance) {
            try {
                app(CPanelProvisioner::class)->poll(EloquentProvisionedServiceResolver::info($instance));
                $this->fail('Duplicate unverified account claim was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('instance', $exception->errors());
            }
            $this->assertSame(ServiceInstanceStatus::Provisioning, $instance->fresh()->status);
            $this->assertSame('legacy-source-reference', $instance->fresh()->external_ref);
        }
        Http::assertNothingSent();
    }

    public function test_unmapped_account_is_rejected_before_network(): void
    {
        $instance = $this->makeServiceInstance();
        $meta = $instance->meta;
        $meta['provider_mapping'] = ['provider_id' => 'cpuser01'];
        $instance->update(['meta' => $meta]);
        Http::fake();

        foreach (['en', 'nl'] as $locale) {
            app()->setLocale($locale);
            try {
                app(CPanelProvisioner::class)->syncStatus(EloquentProvisionedServiceResolver::info($instance));
                $this->fail('Unmapped cPanel account was accepted.');
            } catch (ValidationException $exception) {
                $message = $exception->errors()['instance'][0];
                $this->assertNotSame('cpanel::messages.errors.invalid_mapping', $message);
                $this->assertNotSame('', trim($message));
            }
        }
        Http::assertNothingSent();
        $this->assertSame($meta, $instance->fresh()->meta);
        $this->assertSame('legacy-source-reference', $instance->fresh()->external_ref);
    }

    public function test_invalid_tls_setting_does_not_blame_account_mapping(): void
    {
        Http::fake();
        foreach (['en' => 'connection', 'nl' => 'verbinding'] as $locale => $expected) {
            app()->setLocale($locale);
            $result = app(CPanelProvisioner::class)->testServer([
                'api_url' => 'https://whm.example.test:2087',
                'api_username' => 'root',
                'api_token' => 'test-token',
                'verify_tls' => 'invalid',
            ]);
            $this->assertFalse($result->ok);
            $this->assertStringContainsString($expected, strtolower($result->message));
        }
        Http::assertNothingSent();
    }

    private function makeServiceInstance(string $number = 'SVC-CPANEL-01'): ServiceInstance
    {
        return ServiceInstance::query()->create([
            'number' => $number,
            'status' => ServiceInstanceStatus::Provisioning,
            'provider_key' => 'cpanel',
            'external_ref' => 'legacy-source-reference',
            'customer_email' => $number.'@example.test',
            'meta' => [
                'provider_mapping' => ['provider_id' => 'cpuser01', 'domain' => 'shop.example.test'],
                'provider_settings' => ['domain' => 'shop.example.test', 'username' => 'cpuser01', 'package' => 'starter'],
            ],
        ]);
    }
}
