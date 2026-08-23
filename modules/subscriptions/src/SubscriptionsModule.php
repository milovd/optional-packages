<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions;

use Agovena\Modules\Subscriptions\Http\Controllers\Api\SubscriptionApiController;
use Agovena\Modules\Subscriptions\Http\Livewire\Admin\CustomerSubscriptions;
use Agovena\Modules\Subscriptions\Http\Livewire\Admin\SubscriptionShow;
use Agovena\Modules\Subscriptions\Http\Livewire\Admin\SubscriptionsIndex;
use Agovena\Modules\Subscriptions\Http\Livewire\Customer\SubscriptionShow as CustomerSubscriptionShow;
use Agovena\Modules\Subscriptions\Http\Livewire\Customer\SubscriptionsIndex as CustomerSubscriptionsIndex;
use Agovena\Modules\Subscriptions\Listeners\ApplyPlanChangeToSubscription;
use Agovena\Modules\Subscriptions\Listeners\CreateSubscriptionsWhenOrderPaid;
use Agovena\Modules\Subscriptions\Models\Subscription;
use App\Agovena\Admin\CustomerDetailSection;
use App\Agovena\Admin\NavigationItem;
use App\Agovena\Catalog\Capabilities\ProductCapabilityDefinition;
use App\Agovena\Customer\AccountNavItem;
use App\Agovena\Customer\AccountOverviewCard;
use App\Agovena\Modules\Contracts\Module;
use App\Agovena\Modules\ModuleContext;
use App\Events\OrderPaid;
use App\Events\PlanChangeApplied;
use App\Models\Customer;
use Illuminate\Support\Facades\Route;

final class SubscriptionsModule implements Module
{
    public function id(): string
    {
        return 'subscriptions';
    }

    public function register(ModuleContext $context): void
    {
        $context->capabilities()->register(new ProductCapabilityDefinition(
            key: 'subscribable',
            label: 'admin.products.capabilities.subscribable',
            description: 'admin.products.capabilities.subscribable_help',
            providedByModule: $this->id(),
        ));

        $context->admin()->permission('subscriptions.view', 'admin.permissions.subscriptions.view');
        $context->admin()->permission('subscriptions.manage', 'admin.permissions.subscriptions.manage');

        $context->admin()->navigation(new NavigationItem(
            id: 'subscriptions',
            label: 'admin.nav.subscriptions',
            group: 'admin.nav_groups.operations',
            href: '/admin/subscriptions',
            icon: 'package',
            sort: 16,
            permission: 'subscriptions.view',
        ));

        $context->admin()->navigation(new NavigationItem(
            id: 'plan-changes',
            label: 'admin.nav.plan_changes',
            group: 'admin.nav_groups.operations',
            href: '/admin/plan-changes',
            icon: 'repeat',
            sort: 18,
            permission: 'plan-changes.view',
        ));

        $context->customerAccountNav(new AccountNavItem(
            id: 'subscriptions',
            label: 'subscriptions::customer.nav',
            route: 'customer.subscriptions',
            section: 'subscriptions',
            sort: 20,
            icon: 'repeat',
            group: AccountNavItem::GROUP_ACCOUNT,
        ));

        $context->customerAccountOverview(
            'subscriptions',
            static function (Customer $customer): AccountOverviewCard {
                $count = Subscription::query()
                    ->where('customer_id', $customer->id)
                    ->where('status', 'active')
                    ->count();

                return new AccountOverviewCard(
                    id: 'subscriptions',
                    label: 'subscriptions::customer.overview_label',
                    countOrValue: (string) $count,
                    routeName: 'customer.subscriptions',
                    sort: 20,
                    hint: 'subscriptions::customer.overview_hint',
                );
            },
            20,
        );

        $context->listen(OrderPaid::class, CreateSubscriptionsWhenOrderPaid::class);
        $context->listen(PlanChangeApplied::class, ApplyPlanChangeToSubscription::class);

        $context->admin()->customerDetailSection(new CustomerDetailSection(
            id: 'subscriptions',
            component: CustomerSubscriptions::class,
            sort: 10,
            permission: 'subscriptions.view',
        ));

        $context->adminRoutes(function (): void {
            Route::get('/subscriptions', SubscriptionsIndex::class)->name('subscriptions.index');
            Route::get('/subscriptions/{subscription}', SubscriptionShow::class)->name('subscriptions.show');
        });

        $context->customerRoutes(function (): void {
            Route::get('/subscriptions', CustomerSubscriptionsIndex::class)->name('subscriptions');
            Route::get('/subscriptions/{subscription}', CustomerSubscriptionShow::class)->name('subscriptions.show');
        });

        $context->apiRoutes(function (): void {
            Route::get('/subscriptions', [SubscriptionApiController::class, 'index'])->name('subscriptions.index');
            Route::get('/subscriptions/{subscription}', [SubscriptionApiController::class, 'show'])->name('subscriptions.show');
        });
    }
}
