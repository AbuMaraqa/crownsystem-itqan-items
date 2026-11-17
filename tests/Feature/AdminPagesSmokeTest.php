<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Throwable;

/**
 * فحص سريع لكل صفحات اللوحة: يفتح كل مسار GET بلا معاملات بمستخدم يملك كل
 * الصلاحيات، ويجمع كل صفحة ترجع خطأ خادم (5xx) مع رسالة الاستثناء.
 *
 * الغرض كشف أخطاء SQL الخاصة بمحرك قاعدة بيانات (مثل DATE_FORMAT على
 * PostgreSQL)، لذا يُشغَّل على كل محرك مدعوم (phpunit.mysql.xml وphpunit.pgsql.xml).
 */
class AdminPagesSmokeTest extends TestCase
{
    use RefreshDatabase;

    /** مسارات ليست صفحات لوحة. */
    private const SKIP_PREFIXES = ['_debugbar', '_inertia', '_ignition', 'telescope', 'pulse', 'horizon', 'sanctum', 'api/', 'docs', 'broadcasting'];

    public function test_every_admin_page_without_parameters_renders_without_server_error(): void
    {
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');

        $routes = $this->adminPageRoutes();
        $this->signInWithAllPermissions($routes);

        $failures = [];
        $redirector = app('redirect');
        $only = env('SMOKE_ONLY');
        $checked = 0;

        foreach ($routes as $route) {
            $uri = '/'.ltrim($route->uri(), '/');

            if ($only && ! preg_match('#'.$only.'#', $uri)) {
                continue;
            }

            // في الطلب الحقيقي يُبنى التطبيق من جديد، فهنا يُعاد مُعيد Laravel قبل كل طلب
            // حتى لا تترك صفحةٌ أثرها على التي بعدها.
            app()->instance('redirect', $redirector);
            $checked++;

            try {
                $level = ob_get_level();
                ob_start();
                $response = $this->get($uri);
                ob_end_clean();
                $status = $response->getStatusCode();

                if ($status >= 500) {
                    $error = $response->exception ? $this->describe($response->exception) : 'HTTP '.$status;
                    $failures[] = "{$uri} [".($route->getName() ?? '-')."] → {$error}";
                }
            } catch (Throwable $e) {
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
                $failures[] = "{$uri} [".($route->getName() ?? '-').'] → '.$this->describe($e);
            }
        }

        $this->assertGreaterThan(0, $checked, 'No admin GET routes were checked.');
        fwrite(STDERR, "
[smoke] checked {$checked} pages
");
        $this->assertSame([], $failures, count($failures).' of '.$checked." pages failed:\n".implode("\n", $failures));
    }

    /**
     * @return array<int, Route>
     */
    private function adminPageRoutes(): array
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => in_array('GET', $route->methods(), true))
            ->filter(fn (Route $route): bool => ! preg_match('/\{[^}?]+\}/', $route->uri()))
            ->filter(fn (Route $route): bool => collect($route->gatherMiddleware())->contains(
                fn ($middleware): bool => is_string($middleware) && ($middleware === 'auth' || str_starts_with($middleware, 'auth:') || str_contains($middleware, 'Middleware\\Authenticate'))
            ))
            ->reject(fn (Route $route): bool => collect(self::SKIP_PREFIXES)->contains(fn (string $prefix): bool => str_starts_with($route->uri(), $prefix)))
            ->reject(fn (Route $route): bool => str_contains($route->uri(), 'logout'))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, Route>  $routes
     */
    private function signInWithAllPermissions(array $routes): void
    {
        // كل صلاحية تطلبها المسارات تُنشأ إن لم تكن موجودة، ثم تُمنح كلها للمستخدم.
        foreach ($routes as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! preg_match('/(?:^can:|Authorize:|AuthorizeAnyPermission:)(.+)$/', $middleware, $match)) {
                    continue;
                }

                foreach (explode(',', $match[1]) as $name) {
                    if (trim($name) !== '' && ! str_contains($name, '\\')) {
                        Permission::findOrCreate(trim($name), 'web');
                    }
                }
            }
        }

        $user = User::factory()->create();
        $user->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());
        $this->actingAs($user);
    }

    private function describe(Throwable $e): string
    {
        $message = preg_replace('/\s+/', ' ', $e->getMessage());

        // أول موضع في كود المشروع (لا vendor) ليُعرف مكان الخطأ مباشرة.
        $where = collect([['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()])
            ->first(fn (array $frame): bool => isset($frame['file']) && ! str_contains($frame['file'], DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR));
        $location = $where ? ' @ '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $where['file']).':'.$where['line'] : '';

        return class_basename($e).': '.mb_substr((string) $message, 0, 400).$location;
    }
}
