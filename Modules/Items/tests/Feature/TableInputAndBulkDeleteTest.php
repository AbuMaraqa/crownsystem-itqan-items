<?php

namespace Modules\Items\tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
use Modules\Core\Support\BulkDelete;
use Modules\Items\Entities\Tag;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * البنية الموحّدة للجداول، مختبرة على جدول الوسوم:
 * - مدخلات الرابط الخاطئة تُستبدل بالافتراضي مع رسالة لكل مدخل (Modules\Core\Support\TableInput).
 * - الحذف الجماعي يفحص التحديد بصرامة ويُبلغ بما حُذف وما لم يُحذف ولماذا (Modules\Core\Support\BulkDelete).
 */
class TableInputAndBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');

        foreach (['Tag View List', 'Tag Update', 'Tag Delete'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    private function signIn(array $permissions = ['Tag View List', 'Tag Update', 'Tag Delete']): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    private function tag(string $name): Tag
    {
        $tag = new Tag(['slug' => Str::slug($name).'-'.Str::lower(Str::random(5))]);
        $tag->translateOrNew('ar')->name = $name;
        $tag->save();

        return $tag;
    }

    public function test_wrong_link_input_falls_back_to_the_default_with_a_warning_and_good_input_is_kept(): void
    {
        $this->signIn();
        $this->tag('أحمر');
        $this->tag('أزرق');

        $this->get(route('tags.index', [
            'filter' => ['name' => 'أحمر', 'no_such_filter' => '1', 'created_at_from' => '2026-13-40'],
            'sort' => '-no_such_column',
            'perPage' => '7',
            'page' => '0',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.query.filter', ['name' => 'أحمر'])
                ->where('table.query.sort', 'sort_order')
                ->where('table.meta.per_page', 25)
                ->where('table.meta.current_page', 1)
                ->where('table.meta.total', 1)
                ->where('table.warnings', fn ($warnings): bool => collect($warnings)->all() == [
                    'filter.no_such_filter' => __('This filter is not available and was ignored.'),
                    'filter.created_at_from' => __('Enter a valid date.'),
                    'sort' => __('This order is not available; the default order is shown.'),
                    'perPage' => __('This page size is not available; :count rows per page are shown.', ['count' => 25]),
                    'page' => __('The page number is not valid; the first page is shown.'),
                ])
            );
    }

    public function test_good_input_gives_no_warnings(): void
    {
        $this->signIn();
        $this->tag('أحمر');

        $this->get(route('tags.index', ['filter' => ['name' => 'أحمر', 'created_at_from' => '2020-01-01'], 'sort' => '-slug', 'perPage' => '50', 'page' => '1']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.warnings', [])
                ->where('table.query.sort', '-slug')
                ->where('table.meta.per_page', 50)
                ->where('table.meta.total', 1)
            );
    }

    public function test_a_range_whose_start_is_after_its_end_is_dropped_with_a_warning(): void
    {
        $this->signIn();
        $this->tag('أحمر');

        $this->get(route('tags.index', ['filter' => ['created_at_from' => '2030-12-01', 'created_at_to' => '2020-01-01']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.query.filter', [])
                ->where('table.meta.total', 1)
                ->where('table.warnings', fn ($warnings): bool => collect($warnings)->all() == ['filter.created_at_from' => __('The start of the range is after its end.')])
            );
    }

    public function test_a_page_past_the_end_shows_the_last_page_without_a_warning(): void
    {
        $this->signIn();
        $this->tag('أحمر');

        $this->get(route('tags.index', ['page' => '5']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.meta.current_page', 1)
                ->has('table.data', 1)
                ->where('table.warnings', [])
            );
    }

    public function test_bulk_delete_deletes_the_selected_rows(): void
    {
        $this->signIn();
        $first = $this->tag('أحمر');
        $second = $this->tag('أزرق');
        $kept = $this->tag('أخضر');

        $this->delete(route('tags.destroy-many'), ['ids' => [$first->id, $second->id]])
            ->assertRedirect()
            ->assertSessionHas('success', __('The selected rows were deleted (:count).', ['count' => 2]));

        $this->assertSame([$kept->id], Tag::query()->pluck('id')->all());
    }

    public function test_bulk_delete_refuses_a_selection_with_rows_that_no_longer_exist(): void
    {
        $this->signIn();
        $tag = $this->tag('أحمر');

        $this->delete(route('tags.destroy-many'), ['ids' => [$tag->id, 999999]])
            ->assertSessionHasErrors(['ids' => __('Some rows no longer exist. Refresh the page and try again.')]);

        $this->assertModelExists($tag);
    }

    public function test_bulk_delete_rejects_a_malformed_selection(): void
    {
        $this->signIn();
        $tag = $this->tag('أحمر');

        $this->delete(route('tags.destroy-many'), [])->assertSessionHasErrors(['ids' => __('Select at least one row.')]);
        $this->delete(route('tags.destroy-many'), ['ids' => 'all'])->assertSessionHasErrors('ids');
        $this->delete(route('tags.destroy-many'), ['ids' => ['abc']])->assertSessionHasErrors('ids.0');
        $this->delete(route('tags.destroy-many'), ['ids' => [$tag->id, $tag->id]])->assertSessionHasErrors('ids.0');
        $this->delete(route('tags.destroy-many'), ['ids' => range(1, BulkDelete::MAX + 1)])
            ->assertSessionHasErrors(['ids' => __('Select :max rows at most.', ['max' => BulkDelete::MAX])]);

        $this->assertModelExists($tag);
    }

    public function test_bulk_delete_needs_the_delete_permission(): void
    {
        $this->signIn(['Tag View List']);
        $tag = $this->tag('أحمر');

        $this->delete(route('tags.destroy-many'), ['ids' => [$tag->id]])->assertForbidden();

        $this->assertModelExists($tag);
    }

    public function test_rows_a_rule_keeps_are_reported_with_the_reason_and_the_rest_are_deleted(): void
    {
        $this->signIn();
        $deleted = $this->tag('أحمر');
        $kept = $this->tag('أزرق');

        $request = Request::create('/', 'DELETE', ['ids' => [$deleted->id, $kept->id]]);
        $request->setLaravelSession($this->app['session.store']);

        BulkDelete::handle($request, Tag::query(), function (Tag $tag): bool {
            if ($tag->translate('ar')->name === 'أزرق') {
                throw new DomainException('This tag is in use.');
            }

            return (bool) $tag->delete();
        });

        $this->assertSame(
            __(':deleted deleted, :skipped not deleted. :reason', ['deleted' => 1, 'skipped' => 1, 'reason' => 'This tag is in use.']),
            session('warning'),
        );
        $this->assertModelMissing($deleted);
        $this->assertModelExists($kept);
    }

    public function test_when_nothing_can_be_deleted_the_user_gets_an_error(): void
    {
        $this->signIn();
        $tag = $this->tag('أحمر');

        $request = Request::create('/', 'DELETE', ['ids' => [$tag->id]]);
        $request->setLaravelSession($this->app['session.store']);

        BulkDelete::handle($request, Tag::query(), fn (Tag $tag): bool => false);

        $this->assertStringStartsWith(__('Nothing was deleted. :reason', ['reason' => '']), (string) session('error'));
        $this->assertModelExists($tag);
    }

    public function test_a_parent_selected_with_its_children_is_deleted_after_them(): void
    {
        $this->signIn();
        $parent = $this->tag('أب');
        $child = $this->tag('ابن');

        $request = Request::create('/', 'DELETE', ['ids' => [$parent->id, $child->id]]);
        $request->setLaravelSession($this->app['session.store']);

        // The parent can go only once the child is gone: the first pass keeps it, the second deletes it.
        BulkDelete::handle($request, Tag::query()->orderBy('id'), function (Tag $tag) use ($child): bool {
            if ($tag->translate('ar')->name === 'أب' && Tag::query()->whereKey($child->id)->exists()) {
                throw new DomainException('It still has children.');
            }

            return (bool) $tag->delete();
        });

        $this->assertSame(__('The selected rows were deleted (:count).', ['count' => 2]), session('success'));
        $this->assertSame(0, Tag::query()->count());
    }
}
