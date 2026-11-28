<?php

namespace Modules\Core\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\CustomerGroup;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\User;
use Modules\Core\Enums\UserRole;
use Modules\Core\Http\Controllers\Concerns\DeletesManyRecords;
use Modules\Core\Http\Requests\Admin\UserRequest;
use Modules\Core\Repositories\Contracts\RoleRepositoryInterface;
use Modules\Core\Repositories\Contracts\UserRepositoryInterface;
use Modules\Core\Services\UserService;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\RecordInfo;
use Modules\Items\Entities\PriceList;
use Nwidart\Modules\Facades\Module;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    use DeletesManyRecords;

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly RoleRepositoryInterface $roles,
        private readonly UserService $userService,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Core/Users/Index', [
            'table' => AdminTable::for($this->users->tableQuery())
                ->search()
                ->filters(
                    AdminFilter::relation('role', 'roles'),
                    AdminFilter::relation('branch', 'branch'),
                    AdminFilter::text('name'),
                    AdminFilter::text('email'),
                    AdminFilter::text('phone'),
                    ...AdminFilter::dateRange('created_at'),
                )
                ->sorts('name', 'email', 'created_at', 'updated_at', 'id')
                ->withCreator()
                ->rows(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => $user->avatar_url,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'roles' => $user->roles->map(fn (Role $role) => [
                        'name' => $role->name,
                        'label' => __($role->name),
                    ])->values(),
                    'branch' => $user->branch?->name,
                    'created_at' => $user->created_at?->format('Y-m-d H:i'),
                    'updated_at' => $user->updated_at?->format('Y-m-d H:i'),
                    'can_manage' => $this->userService->canManage($request->user(), $user),
                ]),
            'roleOptions' => $this->roleOptions(),
            'branchOptions' => $this->branchOptions(),
        ]);
    }

    public function add(Request $request): Response
    {
        return Inertia::render('Core/Users/Add', $this->formOptions($request->user()));
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $this->userService->create($request->validated(), $request->user());

        return to_route('users.index')->with('success', __('User saved successfully.'));
    }

    public function edit(Request $request, int $user): Response
    {
        $model = $this->users->findWithRoles($user);

        abort_unless($this->userService->canManage($request->user(), $model), 403);

        return Inertia::render('Core/Users/Edit', [
            'user' => [
                'id' => $model->id,
                'name' => $model->name,
                'email' => $model->email,
                'phone' => $model->phone,
                'roles' => $model->roles->pluck('id')->values(),
                'branch_id' => $model->branch_id,
                'customer_group_id' => $model->customer_group_id,
                'profit_margin' => $model->profit_margin,
                'use_last_price' => $model->use_last_price === null ? null : (int) $model->use_last_price,
                'price_list_id' => $model->price_list_id,
            ],
            ...$this->formOptions($request->user()),
        ]);
    }

    public function update(UserRequest $request, int $user): RedirectResponse
    {
        $model = $this->users->findWithRoles($user);

        abort_unless($this->userService->canManage($request->user(), $model), 403);

        $this->userService->update($model, $request->validated(), $request->user());

        return to_route('users.index')->with('success', __('User updated successfully.'));
    }

    public function destroy(Request $request, int $user): RedirectResponse
    {
        $model = $this->users->findWithRoles($user);

        abort_unless($this->userService->canManage($request->user(), $model), 403);

        try {
            $this->userService->delete($model, $request->user());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('User deleted successfully.'));
    }

    protected function bulkDeleteQuery(): Builder
    {
        return $this->users->tableQuery();
    }

    /**
     * Same rules as destroy(): a Super Admin (unless the actor is one), the signed-in user and the last
     * Super Admin throw a DomainException and stay.
     *
     * @param  User  $record
     */
    protected function deleteRecord(Model $record): bool
    {
        /** @var User $actor */
        $actor = request()->user();

        if (! $this->userService->canManage($actor, $record)) {
            throw new DomainException(__('Only a Super Admin can delete a Super Admin.'));
        }

        $this->userService->delete($record, $actor);

        return true;
    }

    public function info(int $user): JsonResponse
    {
        return response()->json(RecordInfo::of($this->users->findOrFail($user)));
    }

    /**
     * خيارات نموذج الاضافة والتعديل.
     *
     * @return array<string, mixed>
     */
    private function formOptions(User $actor): array
    {
        $roles = $this->roles->lookup();

        return [
            'roleOptions' => $this->assignableRoles($actor),
            'branchOptions' => $this->branchOptions(),
            'customerGroupOptions' => $this->options(CustomerGroup::options()),
            'priceListOptions' => $this->options(PriceList::options()),
            // اقسام التسعير تظهر حسب الدور المختار: الزبون، او الزبون والمورد.
            'customerRoleIds' => $roles->whereIn('name', [UserRole::CUSTOMER->value])->pluck('id')->values(),
            'partyRoleIds' => $roles->whereIn('name', [UserRole::CUSTOMER->value, 'Supplier'])->pluck('id')->values(),
        ];
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function roleOptions(): array
    {
        return $this->roles->lookup()
            ->map(fn (array $role) => ['value' => $role['id'], 'label' => __($role['name'])])
            ->values()
            ->all();
    }

    /**
     * دور Super Admin لا يُعرض الا على Super Admin.
     *
     * @return list<array{value: int, label: string}>
     */
    private function assignableRoles(User $actor): array
    {
        return $this->roles->lookup()
            ->reject(fn (array $role) => $role['name'] === UserRole::SUPER_ADMIN->value && ! $actor->hasRole(UserRole::SUPER_ADMIN->value))
            ->map(fn (array $role) => ['value' => $role['id'], 'label' => __($role['name'])])
            ->values()
            ->all();
    }

    /**
     * null حين تكون وحدة الفروع معطلة، فيُخفى الحقل.
     *
     * @return list<array{value: int, label: string}>|null
     */
    private function branchOptions(): ?array
    {
        if (! Module::isEnabled('Branch')) {
            return null;
        }

        return Branch::query()->with('translations')->orderBy('id')->get()
            ->map(fn (Branch $branch) => ['value' => $branch->id, 'label' => (string) $branch->name])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $options
     * @return list<array{value: int, label: string}>
     */
    private function options(array $options): array
    {
        return collect($options)->map(fn (string $label, int $value) => ['value' => $value, 'label' => $label])->values()->all();
    }
}
