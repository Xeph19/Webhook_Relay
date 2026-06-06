<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRequestSource;
use App\Http\Resources\SourceResource;
use App\Models\Source;
use App\Services\SourceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SourceController extends Controller
{
    protected $sourceService;

    public function __construct(SourceService $sourceService)
    {
        $this->sourceService = $sourceService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $sources = $this->sourceService->getAllSources($request);

        return SourceResource::collection($sources);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreRequestSource $request)
    {
        $source = $this->sourceService->createSource($request->validated());

        return new SourceResource($source);
    }

    /**
     * Display the specified resource.
     */
    public function show(Source $source)
    {
        $data = Cache::tags(['sources'])->rememberForever('source:'.$source->id, function () use ($source) {
            return $source;
        });

        return response()->json($data);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreRequestSource $request, Source $source)
    {
        $source = $this->sourceService->updateSource($source, $request->validated());

        return new SourceResource($source);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Source $source)
    {
        $this->sourceService->deleteSource($source);

        return response()->json([
            'message' => 'Source deleted successfully',
        ]);
    }
}

