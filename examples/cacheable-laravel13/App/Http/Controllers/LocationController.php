<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\General\Location\StoreLocationRequest;
use App\Http\Requests\General\Location\UpdateLocationRequest;
use App\Models\Location;
use App\Repositories\LocationRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LocationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            $locations = LocationRepository::getByBranch($user->branch_id);
        } elseif ($request->filled('branch_id')) {
            $locations = LocationRepository::getByBranch((int) $request->branch_id);
        } else {
            $locations = LocationRepository::getAll();
        }

        return Inertia::render('locations/index', [
            'locations' => array_values($locations),
        ]);
    }

    public function store(StoreLocationRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();
        $data['is_active'] = true;
        $data['updated_by'] = $user->id;
        $data['branch_id'] = $user->isSuperAdmin()
            ? ($data['branch_id'] ?? $user->branch_id)
            : $user->branch_id;

        LocationRepository::manage($data);

        return redirect()->back()->with('success', 'Location created successfully.');
    }

    public function show(Request $request, string $id)
    {
        return response()->json([
            'success' => true,
            'data'    => $this->findAndAuthorize($request, (int) $id),
        ]);
    }

    public function update(UpdateLocationRequest $request, string $id)
    {
        $location = $this->findAndAuthorize($request, (int) $id);

        $data = $request->validated();
        $data['updated_by'] = $request->user()->id;

        LocationRepository::manage($data, $location->id);

        return redirect()->back()->with('success', 'Location updated successfully.');
    }

    public function destroy(Request $request, string $id)
    {
        $location = $this->findAndAuthorize($request, (int) $id);

        LocationRepository::delete($location->id, $request->user()->id);

        return redirect()->back()->with('success', 'Location deleted successfully.');
    }

    public function restore(Request $request, string $id)
    {
        $location = $this->findAndAuthorize($request, (int) $id, true);

        LocationRepository::restore($location->id, $request->user()->id);

        return redirect()->back()->with('success', 'Location restored successfully.');
    }

    public function toggleStatus(Request $request, string $id)
    {
        $location = $this->findAndAuthorize($request, (int) $id);

        LocationRepository::manage([
            'is_active'  => ! $location->is_active,
            'updated_by' => $request->user()->id,
        ], $location->id);

        return redirect()->back()->with('success', 'Location status updated.');
    }

    private function findAndAuthorize(Request $request, int $id, bool $withTrashed = false): Location
    {
        $location = $withTrashed
            ? LocationRepository::getByIdWithTrashed($id)
            : LocationRepository::getById($id);

        abort_if(! $location, 404);

        $user = $request->user();
        if (! $user->isSuperAdmin() && $location->branch_id !== $user->branch_id) {
            abort(403);
        }

        return $location;
    }
}