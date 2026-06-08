<?php

namespace App\Services;

use App\Jobs\SourceJob;
use App\Models\Source;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class SourceService
{
    public function getAllSources(Request $request, int $limit = 10): LengthAwarePaginator
    {
        $page = $request->input('page', 1);
        $key = "source_page_{$page}";
        $data = Cache::tags(['sources', $key])->remember($key, 60 * 60 * 24, function () use ($limit) {
            $sources = Source::query()
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->paginate();

            return [
                'data' => $sources->getCollection(),
                'total' => $sources->total(),
                'per_page' => $sources->perPage(),
                'current_page' => $sources->currentPage(),
            ];
        });

        return new LengthAwarePaginator(
            $data['data'],
            $data['total'],
            $data['per_page'],
            $data['current_page'],
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    public function createSource(array $data)
    {
        $source = Source::create($data);
        Cache::tags(['sources'])->flush();
        SourceJob::dispatchSync($source);

        return $source;
    }

    public function getSourceById(string $id)
    {
        return Source::find($id);
    }

    public function updateSource(Source $source, array $data)
    {
        $source->update($data);
        Cache::tags(['sources'])->flush();
        SourceJob::dispatchSync($source);

        return $source;
    }

    public function deleteSource(Source $source)
    {
        $source->delete();
        Cache::tags(['sources'])->flush();

        return true;
    }
}
