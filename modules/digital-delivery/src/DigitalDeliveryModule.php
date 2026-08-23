<?php

declare(strict_types=1);

namespace Agovena\Modules\DigitalDelivery;

use Agovena\Modules\DigitalDelivery\Http\Controllers\Api\SecretApiController;
use Agovena\Modules\DigitalDelivery\Http\Livewire\Admin\CustomerSecrets;
use Agovena\Modules\DigitalDelivery\Http\Livewire\Admin\SecretsIndex;
use Agovena\Modules\DigitalDelivery\Http\Livewire\Customer\SecretsIndex as CustomerSecretsIndex;
use Agovena\Modules\DigitalDelivery\Listeners\AssertDigitalSecretsBeforeOrderPlacing;
use Agovena\Modules\DigitalDelivery\Listeners\FulfillDigitalSecretsWhenOrderPaid;
use Agovena\Modules\DigitalDelivery\Models\DigitalSecretDelivery;
use App\Agovena\Admin\CustomerDetailSection;
use App\Agovena\Admin\NavigationItem;
use App\Agovena\Catalog\Capabilities\ProductCapabilityDefinition;
use App\Agovena\Customer\AccountNavItem;
use App\Agovena\Customer\AccountOverviewCard;
use App\Agovena\Modules\Contracts\Module;
use App\Agovena\Modules\ModuleContext;
use App\Events\OrderPaid;
use App\Events\OrderPlacing;
use App\Models\Customer;
use Illuminate\Support\Facades\Route;

final class DigitalDeliveryModule implements Module
{
    public function id(): string
    {
        return 'digital-delivery';
    }

    public function register(ModuleContext $context): void
    {
        $context->capabilities()->register(new ProductCapabilityDefinition(
            key: DigitalSecretFulfillmentService::CAPABILITY,
            label: 'admin.products.capabilities.digital_secret',
            description: 'admin.products.capabilities.digital_secret_help',
            providedByModule: $this->id(),
        ));

        $context->admin()->permission('digital_delivery.view', 'admin.permissions.digital_delivery.view');
        $context->admin()->permission('digital_delivery.manage', 'admin.permissions.digital_delivery.manage');

        $context->admin()->navigation(new NavigationItem(
            id: 'digital-delivery-secrets',
            label: 'admin.nav.digital_delivery',
            group: 'admin.nav_groups.fulfillment',
            href: '/admin/digital-delivery/secrets',
            icon: 'key',
            sort: 16,
            permission: 'digital_delivery.view',
        ));

        $context->customerAccountNav(new AccountNavItem(
            id: 'digital-secrets',
            label: 'digital-delivery::customer.nav',
            route: 'customer.digital-secrets',
            section: 'secrets',
            sort: 34,
            icon: 'key',
            group: AccountNavItem::GROUP_SERVICES,
        ));

        $context->customerAccountOverview(
            'digital-secrets',
            static function (Customer $customer): AccountOverviewCard {
                $count = DigitalSecretDelivery::query()
                    ->where('customer_id', $customer->id)
                    ->where('status', DigitalSecretDelivery::STATUS_DELIVERED)
                    ->whereNull('revoked_at')
                    ->count();

                return new AccountOverviewCard(
                    id: 'digital-secrets',
                    label: 'digital-delivery::customer.overview_label',
                    countOrValue: (string) $count,
                    routeName: 'customer.digital-secrets',
                    sort: 29,
                    hint: 'digital-delivery::customer.overview_hint',
                );
            },
            29,
        );

        $context->listen(OrderPlacing::class, AssertDigitalSecretsBeforeOrderPlacing::class);
        $context->listen(OrderPaid::class, FulfillDigitalSecretsWhenOrderPaid::class);

        $context->admin()->customerDetailSection(new CustomerDetailSection(
            id: 'digital-delivery-secrets',
            component: CustomerSecrets::class,
            sort: 29,
            permission: 'digital_delivery.view',
        ));

        $context->adminRoutes(function (): void {
            Route::get('/digital-delivery/secrets', SecretsIndex::class)->name('digital-delivery.secrets');
        });

        $context->customerRoutes(function (): void {
            // Deliberately no reveal-by-URL route: a secret never travels in a path or query.
            Route::get('/digital-secrets', CustomerSecretsIndex::class)->name('digital-secrets');
        });

        $context->apiRoutes(function (): void {
            Route::get('/digital-secrets', [SecretApiController::class, 'index'])->name('digital-secrets.index');
        });
    }
}
