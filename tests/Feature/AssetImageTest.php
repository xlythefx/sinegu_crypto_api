<?php

namespace Tests\Feature;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;

/**
 * asset_image: an uploaded file is a path on the public disk; a site path
 * ("/litecoin-logo.jpg", a logo shipped in the frontend's public/ folder) or
 * an absolute URL passes through untouched.
 */
class AssetImageTest extends EngineTestCase
{
    private function asset(?string $image): Asset
    {
        $asset = Asset::create([
            'ticker' => 'LTCUSDT', 'broker' => 'Binance', 'side' => 'ALL',
            'base_size' => 5, 'max_increments' => 15, 'enabled' => 1,
        ]);
        DB::table('assets')->where('asset_id', $asset->asset_id)->update(['asset_image' => $image]);

        return $asset->fresh();
    }

    public function test_a_site_path_passes_through_for_the_spa_to_load(): void
    {
        $this->assertSame('/litecoin-logo.jpg', $this->asset('/litecoin-logo.jpg')->asset_image);
    }

    public function test_an_absolute_url_passes_through(): void
    {
        $this->assertSame('https://cdn.example.com/ltc.png', $this->asset('https://cdn.example.com/ltc.png')->asset_image);
    }

    public function test_an_uploaded_file_becomes_a_storage_url(): void
    {
        $this->assertStringEndsWith('/storage/asset-images/ltc.png', $this->asset('asset-images/ltc.png')->asset_image);
    }

    public function test_no_image_is_null(): void
    {
        $this->assertNull($this->asset(null)->asset_image);
    }
}
