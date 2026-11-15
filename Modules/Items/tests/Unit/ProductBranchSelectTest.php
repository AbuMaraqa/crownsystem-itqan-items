<?php

namespace Modules\Items\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * اسم الفرع في جدول الترجمات، فحقل "الفروع" في نموذج الصنف يعرض الفروع بأسمائها المترجمة
 * لا بعمود name غير الموجود في جدول الفروع.
 */
class ProductBranchSelectTest extends TestCase
{
    use RefreshDatabase;

    private function branch(string $name, int $userId): int
    {
        // المدينة والدولة غير مهمتين هنا.
        Schema::disableForeignKeyConstraints();

        $id = DB::table(config('branch.table_prefix').'branches')->insertGetId([
            'city_id' => 1,
            'country_id' => 1,
            'status' => 1,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::enableForeignKeyConstraints();

        DB::table(config('branch.table_prefix').'branch_translations')->insert([
            'branch_id' => $id,
            'locale' => 'ar',
            'name' => $name,
        ]);

        return $id;
    }

    public function test_branches_are_listed_by_their_translated_name(): void
    {
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('Product Create', 'web'));
        $this->actingAs($user);
        app()->setLocale('ar');

        $hebron = $this->branch('فرع الخليل', $user->id);
        $ramallah = $this->branch('فرع رام الله', $user->id);

        $this->get(route('products.add'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('options.branches', [
                ['value' => $hebron, 'label' => 'فرع الخليل'],
                ['value' => $ramallah, 'label' => 'فرع رام الله'],
            ]));
    }
}
