<?php

declare(strict_types=1);

namespace Agovena\Modules\Digital\Http\Livewire\Admin;

use Agovena\Modules\Digital\Models\DigitalAsset;
use App\Agovena\Admin\AdminRegistrar;
use App\Models\Product;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class AssetsIndex extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = [
        'pdf', 'zip', 'txt', 'epub', 'mp3', 'mp4', 'wav', 'ogg', 'csv',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'png', 'jpg', 'jpeg',
        'webp', 'gif', 'json', 'xml', 'otf', 'ttf', 'woff', 'woff2', 'rtf', 'md',
    ];

    public ?int $product_id = null;

    public string $label = '';

    public string $download_limit = '';

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public function mount(): void
    {
        $this->authorize('digital.view');
    }

    public function save(): void
    {
        $this->authorize('digital.manage');

        $data = $this->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'label' => ['required', 'string', 'max:120'],
            'download_limit' => ['nullable', 'integer', 'min:1'],
            // Private downloads only — never trust browser extensions alone; block executables/SVG.
            'file' => [
                'required',
                'file',
                'max:51200',
                'mimes:'.implode(',', self::ALLOWED_EXTENSIONS),
            ],
        ]);

        $product = Product::query()->with('capabilities')->findOrFail((int) $data['product_id']);
        if (! $product->hasCapability('digital')) {
            $this->addError('product_id', __('digital::errors.product_not_digital'));

            return;
        }

        /** @var TemporaryUploadedFile $upload */
        $upload = $this->file;
        $filename = $upload->getClientOriginalName();
        $extension = strtolower((string) ($upload->guessExtension() ?: $upload->getClientOriginalExtension()));
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $this->addError('file', __('digital::errors.invalid_file_type'));

            return;
        }

        $safeBase = Str::slug(pathinfo($filename, PATHINFO_FILENAME));
        if ($safeBase === '') {
            $safeBase = 'download';
        }

        $path = $upload->storeAs(
            'digital/'.$product->id,
            Str::uuid()->toString().'_'.$safeBase.'.'.$extension,
            'local',
        );

        DigitalAsset::query()->create([
            'product_id' => $product->id,
            'label' => $data['label'],
            'disk' => 'local',
            'path' => $path,
            'filename' => $filename,
            'download_limit' => $data['download_limit'] !== '' && $data['download_limit'] !== null
                ? (int) $data['download_limit']
                : null,
            'is_active' => true,
        ]);

        $this->reset(['label', 'download_limit', 'file', 'product_id']);
        session()->flash('status', __('digital::admin.saved'));
    }

    public function delete(int $id): void
    {
        $this->authorize('digital.manage');
        $asset = DigitalAsset::query()->findOrFail($id);
        Storage::disk($asset->disk)->delete($asset->path);
        $asset->delete();
        session()->flash('status', __('digital::admin.deleted'));
    }

    public function render(AdminRegistrar $admin)
    {
        return view('livewire.admin.digital.assets-index', [
            'assets' => DigitalAsset::query()->with('product')->orderByDesc('id')->get(),
            'products' => Product::query()
                ->whereHas('capabilities', static fn ($q) => $q->where('capability', 'digital'))
                ->orderBy('name')
                ->get(),
        ])->layout('layouts.admin', [
            'title' => __('digital::admin.title'),
            'navigation' => $admin->navigationItems(),
        ]);
    }
}
