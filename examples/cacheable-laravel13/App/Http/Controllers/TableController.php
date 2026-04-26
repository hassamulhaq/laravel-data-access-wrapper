<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\General\Table\StoreTableRequest;
use App\Http\Requests\General\Table\UpdateTableRequest;
use App\Models\Table;
use App\Repositories\TableRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TableController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $branchId = $user->isSuperAdmin() ? null : (int) $user->branch_id;
        $data = TableRepository::getIndexFor($branchId);

        return Inertia::render('tables/index', [
            'tables' => $data['tables'],
            'locations' => $data['locations'],
        ]);
    }

    public function store(StoreTableRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();
        $data['is_active'] = true;
        $data['updated_by'] = $user->id;
        $data['branch_id'] = $user->isSuperAdmin()
            ? ($data['branch_id'] ?? $user->branch_id)
            : $user->branch_id;

        TableRepository::manage($data);

        return redirect()->back()->with('success', 'Table created successfully.');
    }

    public function update(UpdateTableRequest $request, string $id)
    {
        $table = $this->findAndAuthorize($request, (int) $id);

        $data = $request->validated();
        $data['updated_by'] = $request->user()->id;

        TableRepository::manage($data, $table->id);

        return redirect()->back()->with('success', 'Table updated successfully.');
    }

    public function destroy(Request $request, string $id)
    {
        $table = $this->findAndAuthorize($request, (int) $id);

        TableRepository::delete($table->id, $request->user()->id);

        return redirect()->back()->with('success', 'Table deleted successfully.');
    }

    public function restore(Request $request, string $id)
    {
        $table = $this->findAndAuthorize($request, (int) $id, true);

        TableRepository::restore($table->id, $request->user()->id);

        return redirect()->back()->with('success', 'Table restored successfully.');
    }

    public function toggleStatus(Request $request, string $id)
    {
        $table = $this->findAndAuthorize($request, (int) $id);

        TableRepository::manage([
            'is_active' => ! $table->is_active,
            'updated_by' => $request->user()->id,
        ], $table->id);

        return redirect()->back()->with('success', 'Table status updated.');
    }

    public function toggleReserved(Request $request, string $id)
    {
        $table = $this->findAndAuthorize($request, (int) $id);
        if (! $table->is_active) {
            return redirect()->back()->with('error', 'Cannot reserve an inactive table.');
        }
        $userId = $request->user()->id;
        if ($table->is_reserved) {
            TableRepository::release($table->id, $userId);

            return redirect()->back()->with('success', 'Table freed.');
        }
        TableRepository::reserve($table->id, $userId);

        return redirect()->back()->with('success', 'Table reserved.');
    }

    private function findAndAuthorize(Request $request, int $id, bool $withTrashed = false): Table
    {
        $table = $withTrashed
            ? TableRepository::getByIdWithTrashed($id)
            : TableRepository::getById($id);
        abort_if(! $table, 404);
        $user = $request->user();
        if (! $user->isSuperAdmin() && $table->branch_id !== $user->branch_id) {
            abort(403);
        }

        return $table;
    }
}
