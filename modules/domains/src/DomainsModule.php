<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains;

use Agovena\Modules\Domains\Http\Livewire\Admin\RegistrationsIndex as AdminRegistrationsIndex;
use Agovena\Modules\Domains\Http\Livewire\Customer\DomainShow;
use Agovena\Modules\Domains\Http\Livewire\Customer\RegistrationsIndex as CustomerRegistrationsIndex;
use Agovena\Modules\Domains\Http\Livewire\Storefront\DomainSearch;
use Agovena\Modules\Domains\Listeners\CreateDomainRegistrationsWhenOrderPaid;
use Agovena\Modules\Domains\Listeners\FulfillDomainRegistrationsWhenOrderPaid;
use Agovena\Modules\Domains\Listeners\ValidateDomainOrderPreflight;
use Agovena\Modules\Domains\Models\DomainRegistration;
use Agovena\Modules\Domains\Pricing\DomainProductPriceResolver;
use App\Agovena\Admin\NavigationItem;
use App\Agovena\Catalog\Capabilities\ProductCapabilityDefinition;
use App\Agovena\Customer\AccountNavItem;
use App\Agovena\Customer\AccountOverviewCard;
use App\Agovena\Modules\Contracts\Module;
use App\Agovena\Modules\ModuleContext;
use App\Events\OrderPaid;
use App\Events\OrderPreflight;
use App\Models\Customer;
use Illuminate\Support\Facades\Route;

final class DomainsModule implements Module
{
    public function id(): string
    {
        return 'domains';
    }

    public function register(ModuleContext $context): void
    {
        $context->capabilities()->register(new ProductCapabilityDefinition(
            key: 'domain_registration',
            label: 'domains::admin.capabilities.domain_registration',
            description: 'domains::admin.capabilities.domain_registration_help',
            providedByModule: $this->id(),
            availability: static fn (array $config): bool => app(DomainRegistrarRegistry::class)
                ->get(trim((string) ($config['registrar_key'] ?? '')) ?: 'demo-registrar') !== null,
        ));
        $context->productPricing()->register(app(DomainProductPriceResolver::class));

        $context->admin()->permission('domains.view', 'admin.permissions.domains.view');
        $context->admin()->permission('domains.manage', 'admin.permissions.domains.manage');
        $context->admin()->navigation(new NavigationItem(
            id: 'domains',
            label: 'domains::admin.title',
            group: 'admin.nav_groups.operations',
            href: '/admin/domains',
            icon: 'globe',
            sort: 16,
            permission: 'domains.view',
        ));

        $context->customerAccountNav(new AccountNavItem(
            id: 'domains',
            label: 'domains::customer.nav',
            route: 'customer.domains',
            section: 'domains',
            sort: 27,
            icon: 'globe',
            group: AccountNavItem::GROUP_SERVICES,
        ));
        $context->customerAccountOverview(
            'domains',
            static function (Customer $customer): AccountOverviewCard {
                $count = DomainRegistration::query()
                    ->where(function ($query) use ($customer): void {
                        $query->where('customer_id', $customer->id);
                    })
                    ->where('status', 'active')
                    ->count();

                return new AccountOverviewCard(
                    id: 'domains',
                    label: 'domains::customer.nav',
                    countOrValue: (string) $count,
                    routeName: 'customer.domains',
                    sort: 15,
                    hint: 'domains::customer.lede',
                );
            },
            15,
        );

        $context->listen(OrderPaid::class, CreateDomainRegistrationsWhenOrderPaid::class);
        $context->listen(OrderPaid::class, FulfillDomainRegistrationsWhenOrderPaid::class);
        $context->listen(OrderPreflight::class, ValidateDomainOrderPreflight::class);

        $context->storefrontRoutes(function (): void {
            Route::get('/domains', DomainSearch::class)->name('domains');
            Route::get('/domains/products/{slug}/configure', DomainSearch::class)->name('domains.product.configure');
        });

        $context->adminRoutes(function (): void {
            Route::get('/domains', AdminRegistrationsIndex::class)->name('domains.index');
        });
        $context->customerRoutes(function (): void {
            Route::get('/domains', CustomerRegistrationsIndex::class)->name('domains');
            Route::get('/domains/{registration}', DomainShow::class)->name('domains.show');
        });
    }
}
