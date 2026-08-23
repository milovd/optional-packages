<?php

declare(strict_types=1);

namespace Agovena\Modules\Inventory\Http\Livewire\Admin;

use Agovena\Modules\Inventory\InventoryService;
use Agovena\Modules\Inventory\Models\InventoryStock;
use App\Agovena\Admin\AdminRegistrar;
use App\Models\Product;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

final class StocksIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $search = '';

    /** @var array<int, int|string> */
    public array $quantities = [];

    /** @var array<int, bool> */
    public array $trackStock = [];

    /** @var array<int, bool> */
    public array $allowOversell = [];

    public function mount(): void
    {
        $this->authorize('inventory.view');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function saveStock(int $productId, InventoryService $inventory): void
    {
        $this->authorize('inventory.manage');

        $quantity = (int) ($this->quantities[$productId] ?? 0);
        $track = (bool) ($this->trackStock[$productId] ?? true);
        $oversell = (bool) ($this->allowOversell[$productId] ?? false);

        $product = Product::query()->findOrFail($productId);
        $inventory->setQuantity($product, max(0, $quantity), $track, $oversell);

        session()->flash('status', __('inventory::admin.stock_saved'));
    }

    public function render(AdminRegistrar $admin)
    {
        $query = Product::query()
            ->whereHas('capabilities', static fn ($q) => $q->where('capability', 'inventory'))
            ->orderBy('name');

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', $term)
                    ->orWhere('sku', 'like', $term);
            });
        }

        $products = $query->paginate(20);
        $productIds = $products->getCollection()->pluck('id')->all();
        $stocks = InventoryStock::query()
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        foreach ($products as $product) {
            $stock = $stocks->get($product->id);
            if ($stock instanceof InventoryStock) {
                if (! array_key_exists($product->id, $this->quantities)) {
                    $this->quantities[$product->id] = $stock->quantity;
                }
                if (! array_key_exists($product->id, $this->trackStock)) {
                    $this->trackStock[$product->id] = $stock->track_stock;
                }
                if (! array_key_exists($product->id, $this->allowOversell)) {
                    $this->allowOversell[$product->id] = $stock->allow_oversell;
                }
            } else {
                if (! array_key_exists($product->id, $this->quantities)) {
                    $this->quantities[$product->id] = 0;
                }
                if (! array_key_exists($product->id, $this->trackStock)) {
                    $this->trackStock[$product->id] = true;
                }
                if (! array_key_exists($product->id, $this->allowOversell)) {
                    $this->allowOversell[$product->id] = false;
                }
            }
        }

        return view('livewire.admin.inventory.stocks-index', [
            'products' => $products,
            'stocks' => $stocks,
        ])->layout('layouts.admin', [
            'title' => __('inventory::admin.title'),
            'navigation' => $admin->navigationItems(),
        ]);
    }
}
