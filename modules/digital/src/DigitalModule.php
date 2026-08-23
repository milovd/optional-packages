<?php

declare(strict_types=1);

namespace Agovena\Modules\Digital;

use Agovena\Modules\Digital\Http\Controllers\Api\DownloadApiController;
use Agovena\Modules\Digital\Http\Controllers\DownloadController;
use Agovena\Modules\Digital\Http\Livewire\Admin\AssetsIndex;
use Agovena\Modules\Digital\Http\Livewire\Admin\CustomerDownloads;
use Agovena\Modules\Digital\Http\Livewire\Customer\DownloadsIndex;
use Agovena\Modules\Digital\Listeners\GrantDigitalEntitlementsWhenOrderPaid;
use Agovena\Modules\Digital\Models\DigitalEntitlement;
use App\Agovena\Admin\CustomerDetailSection;
use App\Agovena\Admin\NavigationItem;
use App\Agovena\Catalog\Capabilities\ProductCapabilityDefinition;
use App\Agovena\Customer\AccountNavItem;
use App\Agovena\Customer\AccountOverviewCard;
use App\Agovena\Modules\Contracts\Module;
use App\Agovena\Modules\ModuleContext;
use App\Events\OrderPaid;
use App\Models\Customer;
use Illuminate\Support\Facades\Route;

final class DigitalModule implements Module
{
    public function id(): string
    {
        return 'digital';
    }

    public function register(ModuleContext $context): void
    {
        $context->capabilities()->register(new ProductCapabilityDefinition(
            key: 'digital',
            label: 'admin.products.capabilities.digital',
            description: 'admin.products.capabilities.digital_help',
            providedByModule: $this->id(),
        ));

        $context->admin()->permission('digital.view', 'admin.permissions.digital.view');
        $context->admin()->permission('digital.manage', 'admin.permissions.digital.manage');

        $context->admin()->navigation(new NavigationItem(
            id: 'digital-assets',
            label: 'admin.nav.digital',
            group: 'admin.nav_groups.fulfillment',
            href: '/admin/digital/assets',
            icon: 'download',
            sort: 17,
            permission: 'digital.view',
        ));

        $context->customerAccountNav(new AccountNavItem(
            id: 'digital-downloads',
            label: 'digital::customer.nav',
            route: 'customer.downloads',
            section: 'downloads',
            sort: 35,
            icon: 'download',
            group: AccountNavItem::GROUP_SERVICES,
        ));

        $context->customerAccountOverview(
            'digital-downloads',
            static function (Customer $customer): AccountOverviewCard {
                $count = DigitalEntitlement::query()
                    ->where('customer_id', $customer->id)
                    ->whereNull('revoked_at')
                    ->count();

                return new AccountOverviewCard(
                    id: 'digital-downloads',
                    label: 'digital::customer.overview_label',
                    countOrValue: (string) $count,
                    routeName: 'customer.downloads',
                    sort: 30,
                    hint: 'digital::customer.overview_hint',
                );
            },
            30,
        );

        $context->listen(OrderPaid::class, GrantDigitalEntitlementsWhenOrderPaid::class);

        $context->admin()->customerDetailSection(new CustomerDetailSection(
            id: 'digital-downloads',
            component: CustomerDownloads::class,
            sort: 30,
            permission: 'digital.view',
        ));

        $context->adminRoutes(function (): void {
            Route::get('/digital/assets', AssetsIndex::class)->name('digital.assets');
        });

        $context->customerRoutes(function (): void {
            Route::get('/downloads', DownloadsIndex::class)->name('downloads');
            Route::get('/downloads/{token}', DownloadController::class)->name('downloads.file');
        });

        $context->apiRoutes(function (): void {
            Route::get('/downloads', [DownloadApiController::class, 'index'])->name('downloads.index');
            Route::middleware('throttle:api-sensitive')->get('/downloads/{token}', [DownloadApiController::class, 'file'])->name('downloads.file');
        });
    }
}
