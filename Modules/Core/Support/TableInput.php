<?php

namespace Modules\Core\Support;

use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * يفحص مدخلات رابط الجدول (الفلاتر، والترتيب، ورقم الصفحة، وعدد الصفوف) قبل تنفيذ الاستعلام.
 * القيمة الخاطئة لا تكسر الصفحة ولا تُتجاهل بصمت: تُستبدل بالافتراضي وتُرسل رسالتها في `warnings`
 * (المفتاح هو اسم المدخل في الرابط، مثل `filter.created_at_from`)، فيعرضها DataTable بجانب اسم الفلتر.
 */
final class TableInput
{
    public const TEXT_MAX = 255;

    /** @var array<string, string> */
    public array $warnings = [];

    /** @var array<string, mixed> */
    public array $filter = [];

    public string $sort;

    public int $perPage;

    public int $page = 1;

    /**
     * @param  array<int, AllowedFilter>  $filters
     * @param  list<string>  $sorts  the names the table may be sorted by
     * @param  list<int>  $pageSizes
     */
    private function __construct(Request $request, array $filters, array $sorts, private readonly string $defaultSort, array $pageSizes)
    {
        $this->readFilters($request, $filters);
        $this->readSort($request, $sorts);
        $this->readPageSize($request, $pageSizes);
        $this->readPage($request);
    }

    /**
     * @param  array<int, AllowedFilter>  $filters
     * @param  list<string>  $sorts
     * @param  list<int>  $pageSizes
     */
    public static function read(Request $request, array $filters, array $sorts, string $defaultSort, array $pageSizes): self
    {
        return new self($request, $filters, $sorts, $defaultSort, $pageSizes);
    }

    /**
     * The checked input as a request for spatie/laravel-query-builder: nothing it would reject is left.
     */
    public function request(Request $request): Request
    {
        $query = array_filter([
            'filter' => $this->filter ?: null,
            // The default needs no permission to be asked for; sent explicitly it would have to be allowed.
            'sort' => $this->sort === $this->defaultSort ? null : $this->sort,
        ]);

        return $request->duplicate($query, []);
    }

    public function warn(string $key, string $message): void
    {
        $this->warnings[$key] ??= $message;
    }

    /**
     * @param  array<int, AllowedFilter>  $filters
     */
    private function readFilters(Request $request, array $filters): void
    {
        $input = $request->query('filter', []);

        if (! is_array($input)) {
            $this->warn('filter', __('The filters in the link are not valid and were ignored.'));

            return;
        }

        $allowed = [];

        foreach ($filters as $filter) {
            $allowed[$filter->getName()] = AdminFilter::typeOf($filter);
        }

        foreach ($input as $key => $value) {
            $key = (string) $key;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (! isset($allowed[$key])) {
                $this->warn("filter.{$key}", __('This filter is not available and was ignored.'));

                continue;
            }

            $problem = $this->problem($allowed[$key], $value);

            if ($problem !== null) {
                $this->warn("filter.{$key}", $problem);

                continue;
            }

            $this->filter[$key] = $value;
        }

        $this->checkRanges($allowed);
    }

    /**
     * Why a filter value cannot be used, or null when it can.
     */
    private function problem(string $type, mixed $value): ?string
    {
        if (is_array($value)) {
            // A list is only a list of plain values (an exact filter on several values).
            if ($type !== AdminFilter::VALUE || array_filter($value, fn ($item) => ! is_scalar($item)) !== []) {
                return __('The value is not valid.');
            }

            return array_filter($value, fn ($item) => mb_strlen((string) $item) > self::TEXT_MAX) === []
                ? null
                : __('The text is too long (:max characters at most).', ['max' => self::TEXT_MAX]);
        }

        if (! is_scalar($value)) {
            return __('The value is not valid.');
        }

        $value = trim((string) $value);

        return match ($type) {
            AdminFilter::DATE => AdminFilter::date($value) === null ? __('Enter a valid date.') : null,
            AdminFilter::NUMBER => is_numeric($value) ? null : __('Enter a valid number.'),
            AdminFilter::ID => ctype_digit($value) ? null : __('The value is not valid.'),
            AdminFilter::BOOLEAN => in_array(strtolower($value), ['0', '1', 'true', 'false'], true) ? null : __('The value is not valid.'),
            default => mb_strlen($value) > self::TEXT_MAX ? __('The text is too long (:max characters at most).', ['max' => self::TEXT_MAX]) : null,
        };
    }

    /**
     * A range whose start is after its end finds nothing: both ends are dropped and the user told why.
     *
     * @param  array<string, string>  $types
     */
    private function checkRanges(array $types): void
    {
        foreach (array_keys($this->filter) as $from) {
            if (! str_ends_with($from, '_from')) {
                continue;
            }

            $to = substr($from, 0, -5).'_to';
            $type = $types[$from];

            if (! isset($this->filter[$to]) || ! in_array($type, [AdminFilter::DATE, AdminFilter::NUMBER], true)) {
                continue;
            }

            $reversed = $type === AdminFilter::DATE
                ? strcmp((string) $this->filter[$from], (string) $this->filter[$to]) > 0
                : (float) $this->filter[$from] > (float) $this->filter[$to];

            if ($reversed) {
                $this->warn("filter.{$from}", __('The start of the range is after its end.'));
                unset($this->filter[$from], $this->filter[$to]);
            }
        }
    }

    /**
     * @param  list<string>  $sorts
     */
    private function readSort(Request $request, array $sorts): void
    {
        $this->sort = $this->defaultSort;
        $input = $request->query('sort');

        if ($input === null || $input === '') {
            return;
        }

        $allowed = [...$sorts, ...array_map(fn (string $part) => ltrim($part, '-'), explode(',', $this->defaultSort))];

        if (! is_string($input) || array_filter(explode(',', $input), fn (string $part) => ! in_array(ltrim(trim($part), '-'), $allowed, true)) !== []) {
            $this->warn('sort', __('This order is not available; the default order is shown.'));

            return;
        }

        $this->sort = $input;
    }

    /**
     * @param  list<int>  $sizes
     */
    private function readPageSize(Request $request, array $sizes): void
    {
        $this->perPage = $sizes[1] ?? $sizes[0];
        $input = $request->query('perPage');

        if ($input === null || $input === '') {
            return;
        }

        if (! is_string($input) || ! ctype_digit($input) || ! in_array((int) $input, $sizes, true)) {
            $this->warn('perPage', __('This page size is not available; :count rows per page are shown.', ['count' => $this->perPage]));

            return;
        }

        $this->perPage = (int) $input;
    }

    private function readPage(Request $request): void
    {
        $input = $request->query('page');

        if ($input === null || $input === '') {
            return;
        }

        if (! is_string($input) || ! ctype_digit($input) || (int) $input < 1) {
            $this->warn('page', __('The page number is not valid; the first page is shown.'));

            return;
        }

        $this->page = (int) $input;
    }
}
