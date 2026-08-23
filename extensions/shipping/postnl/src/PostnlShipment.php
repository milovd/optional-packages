<?php

declare(strict_types=1);

namespace Agovena\Extensions\Postnl;

use Illuminate\Database\Eloquent\Model;

/**
 * Extension-owned mapping from Agovena orders to PostNL barcodes.
 *
 * @property int $id
 * @property int $order_id
 * @property string $barcode
 * @property string|null $product_code
 * @property string|null $label_path
 * @property string|null $provider_status
 */
final class PostnlShipment extends Model
{
    protected $table = 'postnl_shipments';

    protected $fillable = [
        'order_id',
        'barcode',
        'product_code',
        'label_path',
        'provider_status',
    ];
}
