<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning;

use Agovena\Modules\Provisioning\Http\Controllers\Api\ServiceApiController;
use Agovena\Modules\Provisioning\Http\Livewire\Admin\CustomerServices;
use Agovena\Modules\Provisioning\Http\Livewire\Admin\InstanceShow;
use Agovena\Modules\Provisioning\Http\Livewire\Admin\InstancesIndex;
use Agovena\Modules\Provisioning\Http\Livewire\Admin\Servers;
use Agovena\Modules\Provisioning\Http\Livewire\Customer\ServiceShow;
use Agovena\Modules\Provisioning\Http\Livewire\Customer\ServicesIndex;
use Agovena\Modules\Provisioning\Listeners\ApplyPlanChangeToService;
use Agovena\Modules\Provisioning\Listeners\AssertProvisioningStockBeforeOrderPlacing;
use Agovena\Modules\Provisioning\Listeners\CreateServiceInstancesWhenOrderPaid;
use Agovena\Modules\Provisioning\Listeners\PrepareProvisioningStock;
use Agovena\Modules\Provisioning\Listeners\ReleaseProvisioningCapacityWhenOrderCancelled;
use Agovena\Modules\Provisioning\Listeners\SnapshotProvisioningConfigurationWhenOrderCreated;
use Agovena\Modules\Provisioning\Listeners\SnapshotProvisioningConfigurationWhenOrderPlacing;
use Agovena\Modules\Provisioning\Listeners\SuspendServicesWhenSubscriptionEnded;
use Agovena\Modules\Provisioning\Models\ServiceInstance;
use Agovena\Modules\Subscriptions\Events\SubscriptionEnded;
use App\Agovena\Admin\CustomerDetailSection;
use App\Agovena\Admin\NavigationItem;
use App\Agovena\Catalog\Capabilities\ProductCapabilityDefinition;
use App\Agovena\Customer\AccountNavItem;
use App\Agovena\Customer\AccountOverviewCard;
use App\Agovena\Modules\Contracts\Module;
use App\Agovena\Modules\ModuleContext;
use App\Events\OrderCancelled;
use App\Events\OrderCreated;
use App\Events\OrderPaid;
use App\Events\OrderPlacing;
use App\Events\OrderPreflight;
use App\Events\PlanChangeApplied;
use App\Models\Customer;
use Illuminate\Support\Facades\Route;

final class ProvisioningModule implements Module
{
    public function id(): string
    {
        return 'provisioning';
    }

    public function register(ModuleContext $context): void
    {
        $context->capabilities()->register(new ProductCapabilityDefinition(
            key: 'provisionable',
            label: 'admin.products.capabilities.provisionable',
            description: 'admin.products.capabilities.provisionable_help',
            providedByModule: $this->id(),
        ));

        $context->admin()->permission('provisioning.view', 'admin.permissions.provisioning.view');
        $context->admin()->permission('provisioning.manage', 'admin.permissions.provisioning.manage');

        $context->admin()->navigation(new NavigationItem(
            id: 'provisioning',
            label: 'admin.nav.provisioning',
            group: 'admin.nav_groups.operations',
            href: '/admin/provisioning',
            icon: 'server',
            sort: 15,
            permission: 'provisioning.view',
        ));

        $context->customerAccountNav(new AccountNavItem(
            id: 'services',
            label: 'provisioning::customer.nav',
            route: 'customer.services',
            section: 'services',
            sort: 25,
            icon: 'server',
            group: AccountNavItem::GROUP_SERVICES,
        ));

        $context->customerAccountOverview(
            'services',
            static function (Customer $customer): AccountOverviewCard {
                $count = ServiceInstance::query()
                    ->where('customer_id', $customer->id)
                    ->where('status', 'active')
                    ->count();

                return new AccountOverviewCard(
                    id: 'services',
                    label: 'provisioning::customer.overview_label',
                    countOrValue: (string) $count,
                    routeName: 'customer.services',
                    sort: 10,
                    hint: 'provisioning::customer.overview_hint',
                );
            },
            10,
        );

        $context->listen(OrderPreflight::class, PrepareProvisioningStock::class);
        $context->listen(OrderPlacing::class, AssertProvisioningStockBeforeOrderPlacing::class);
        $context->listen(OrderPlacing::class, SnapshotProvisioningConfigurationWhenOrderPlacing::class);
        $context->listen(OrderCreated::class, SnapshotProvisioningConfigurationWhenOrderCreated::class);
        $context->listen(OrderCancelled::class, ReleaseProvisioningCapacityWhenOrderCancelled::class);
        $context->listen(OrderPaid::class, CreateServiceInstancesWhenOrderPaid::class);
        $context->listen(PlanChangeApplied::class, ApplyPlanChangeToService::class);
        if (class_exists(SubscriptionEnded::class)) {
            $context->listen(SubscriptionEnded::class, SuspendServicesWhenSubscriptionEnded::class);
        }

        $context->admin()->customerDetailSection(new CustomerDetailSection(
            id: 'provisioning-services',
            component: CustomerServices::class,
            sort: 20,
            permission: 'provisioning.view',
        ));

        $context->adminRoutes(function (): void {
            Route::get('/provisioning', InstancesIndex::class)->name('provisioning.index');
            Route::get('/provisioning/servers', Servers::class)->name('provisioning.servers');
            Route::get('/provisioning/{instance}', InstanceShow::class)->name('provisioning.show');
        });

        $context->customerRoutes(function (): void {
            Route::get('/services', ServicesIndex::class)->name('services');
            Route::get('/services/{instance}', ServiceShow::class)->name('services.show');
        });

        $context->apiRoutes(function (): void {
            Route::get('/services', [ServiceApiController::class, 'index'])->name('services.index');
            Route::get('/services/{instance}', [ServiceApiController::class, 'show'])->name('services.show');
        });
    }
}
